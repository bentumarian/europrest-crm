<?php
/*
|--------------------------------------------------------------------------
| deploy.php — GitHub webhook auto-deploy
|--------------------------------------------------------------------------
| La fiecare git push pe main, GitHub apeleaza acest endpoint, care:
|   1. Verifica semnatura HMAC-SHA256 (cu secret-ul partajat cu GitHub)
|   2. Filtreaza evenimentul (doar push pe main)
|   3. Ruleaza `git pull origin main` in repo-ul clonat de cPanel
|   4. Copiaza *.php si .htaccess in document root
|   5. Logheaza tot in deploy.log (in afara doc root)
|
| Configurare unica:
|   - Pe server, in config.local.php, adauga:
|       'github_webhook_secret' => 'STRING_RANDOM_PUTERNIC',
|   - In GitHub: Settings -> Webhooks -> Add webhook
|       Payload URL:  https://app.pestzone.ro/deploy.php
|       Content type: application/json
|       Secret:       acelasi STRING_RANDOM_PUTERNIC
|       Events:       Just the push event
|--------------------------------------------------------------------------
*/

// Nu trimitem niciun output PHP la erori (sa nu trimit semnale catre atacatori)
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// === Cai fixe ===
// $HOME pe Romarg = /home/r132680pest
$home       = '/home/r132680pest';
$repoPath   = $home . '/repositories/europrest-crm';
$deployPath = $home . '/app.pestzone.ro';
$logFile    = $home . '/deploy.log'; // in afara doc root, blocat oricum de .htaccess prin pattern *.log

// === Helper de log ===
$startTime = microtime(true);
$deploy_log = function (string $msg) use ($logFile) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

// === Citeste secret-ul din config.local.php (de pe server, NU din repo) ===
$localConfigPath = $deployPath . '/config.local.php';
if (!is_readable($localConfigPath)) {
    http_response_code(500);
    $deploy_log('FAIL: nu pot citi ' . $localConfigPath);
    exit('Config indisponibil.');
}
$config = @include $localConfigPath;
$secret = is_array($config) ? (string)($config['github_webhook_secret'] ?? '') : '';

if ($secret === '') {
    http_response_code(500);
    $deploy_log('FAIL: github_webhook_secret lipseste din config.local.php');
    exit('Secret neconfigurat.');
}

// === Citeste payload-ul brut ===
$payload = file_get_contents('php://input');
if ($payload === false || $payload === '') {
    http_response_code(400);
    $deploy_log('FAIL: payload gol');
    exit('Empty payload.');
}

// === Verifica semnatura HMAC ===
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (strpos($signatureHeader, 'sha256=') !== 0) {
    http_response_code(403);
    $deploy_log('REJECT: header X-Hub-Signature-256 lipsa sau invalid');
    exit('Signature missing.');
}
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signatureHeader)) {
    http_response_code(403);
    $deploy_log('REJECT: signature mismatch');
    exit('Signature mismatch.');
}

// === Tratare evenimente ===
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

// Ping = test conexiune din GitHub. Raspundem cu OK fara sa pornim deploy.
if ($event === 'ping') {
    $deploy_log('PING received');
    echo "pong\n";
    exit;
}

if ($event !== 'push') {
    http_response_code(200);
    $deploy_log('IGNORE event: ' . $event);
    echo "Ignored event: $event\n";
    exit;
}

// === Filtrare branch (doar main) ===
$data = json_decode($payload, true);
$ref = is_array($data) ? (string)($data['ref'] ?? '') : '';
if ($ref !== 'refs/heads/main') {
    $deploy_log('IGNORE push pe ref: ' . $ref);
    echo "Ignored push on $ref\n";
    exit;
}

$commit = is_array($data) ? (string)($data['after'] ?? '') : '';
$pusher = is_array($data) && isset($data['pusher']['name']) ? $data['pusher']['name'] : 'unknown';
$deploy_log("DEPLOY start | commit=$commit | pusher=$pusher");

// === Verifica directoare ===
if (!is_dir($repoPath) || !is_dir($repoPath . '/.git')) {
    http_response_code(500);
    $deploy_log('FAIL: repo path invalid (nu exista sau nu e repo git)');
    exit('Repo invalid.');
}
if (!is_dir($deployPath) || !is_writable($deployPath)) {
    http_response_code(500);
    $deploy_log('FAIL: deploy path invalid sau read-only');
    exit('Deploy path invalid.');
}

// === Comenzi de deploy ===
$repoQ = escapeshellarg($repoPath);
$deployQ = escapeshellarg($deployPath);
$cmds = [
    "cd $repoQ && git fetch --all 2>&1",
    "cd $repoQ && git reset --hard origin/main 2>&1",
    "cd $repoQ && cp -fR ./*.php $deployQ/ 2>&1",
    // /lib/ contine helperele require-uite din root (notification_lib, smartbill_lib, etc).
    // Trebuie copiat recursiv ca sa fie disponibil dupa deploy.
    "cd $repoQ && [ -d lib ] && cp -fR ./lib $deployQ/ 2>&1 || true",
    // /assets/ contine logo-urile (brand-emma-*) si iconurile statice.
    // Versionate in git incepand cu rebrandingul Emma — vezi PLAN_SAAS_EMMA.md §4.3.
    "cd $repoQ && [ -d assets ] && cp -fR ./assets $deployQ/ 2>&1 || true",
    // /migrations/ contine scripturile SQL versionate. Util ca admin sa le poata
    // rula direct de pe server, fara sa descarce manual din GitHub.
    "cd $repoQ && [ -d migrations ] && cp -fR ./migrations $deployQ/ 2>&1 || true",
    // .htaccess este versionat in repo; il copiem doar daca exista
    "cd $repoQ && [ -f .htaccess ] && cp -f .htaccess $deployQ/ 2>&1 || true",
];

$allOk = true;
foreach ($cmds as $cmd) {
    $output = @shell_exec($cmd);
    $deploy_log(">> $cmd");
    $deploy_log("   " . trim((string)$output));
    if ($output === null) {
        $allOk = false;
        $deploy_log("   (shell_exec returned null - probabil dezactivat)");
    }
}

$duration = round(microtime(true) - $startTime, 2);
$status = $allOk ? 'OK' : 'PARTIAL';
$deploy_log("DEPLOY $status in ${duration}s | commit=$commit");

http_response_code(200);
echo "Deploy $status in ${duration}s\n";
echo "Commit: $commit\n";
echo "Pusher: $pusher\n";
