<?php
/**
 * Diagnostic SMS v2 — search adanc dupa codul care trimite SMS-uri.
 *
 * Cum se foloseste:
 *   1. Incarca pe server langa config.php
 *   2. Deschide ca admin: https://app.pestzone.ro/diag_sms_v2.php
 *   3. Trimite-mi screenshot complet (TOATE sectiunile)
 *   4. Sterge fisierul dupa
 */

require_once __DIR__ . '/config.php';

if (function_exists('require_login')) require_login();
if (function_exists('is_admin') && !is_admin()) { http_response_code(403); exit('Doar admin.'); }

$ROOT = __DIR__;

// 1) TOATE setarile sms_* / smslink_* din DB
$smsSettings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE '%sms%' OR setting_key LIKE '%smslink%' ORDER BY setting_key");
    $smsSettings = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {}

// 2) Search recursiv pe TOT proiectul dupa "smslink" / "smslink.ro" / urls
function deepSearch(string $root, array $patterns, int $maxDepth = 5): array {
    $results = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $name = $file->getFilename();
        if (!preg_match('/\.(php|inc)$/i', $name)) continue;
        // Skip backups si scriptul curent
        if (preg_match('/\.bak\./i', $name)) continue;
        if ($name === basename(__FILE__)) continue;
        if ($name === 'diag_sms.php') continue;

        $content = @file_get_contents($file->getPathname());
        if ($content === false || $content === '') continue;

        foreach ($patterns as $patternName => $regex) {
            if (preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $offset = $match[1];
                    $lineNum = substr_count(substr($content, 0, $offset), "\n") + 1;
                    // Extrage linia
                    $lineStart = strrpos(substr($content, 0, $offset), "\n");
                    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                    $lineEnd = strpos($content, "\n", $offset);
                    $lineEnd = $lineEnd === false ? strlen($content) : $lineEnd;
                    $line = trim(substr($content, $lineStart, $lineEnd - $lineStart));
                    $results[] = [
                        'file' => str_replace($root . '/', '', $file->getPathname()),
                        'line' => $lineNum,
                        'pattern' => $patternName,
                        'snippet' => mb_strimwidth($line, 0, 140, '...'),
                    ];
                }
            }
        }
    }
    return $results;
}

$patterns = [
    'smslink_domain'     => '/smslink\.ro/i',
    'smslink_setting'    => '/smslink_enabled/i',
    'function_def'       => '/function\s+pz_smslink_send_sms\b/i',
    'function_call'      => '/pz_smslink_send_sms\s*\(/i',
    'send_appointment'   => '/pz_send_appointment_(confirmation|reminder|created)_sms\s*\(/i',
    'send_task'          => '/pz_send_task_expiring_7_sms\s*\(/i',
    'http_post'          => '/(curl_init|wp_remote_post|wp_remote_get|Guzzle|http_post|http_get)\s*\([^)]*sms/i',
];

$hits = deepSearch($ROOT, $patterns);

// Grupeaza pe pattern
$byPattern = [];
foreach ($hits as $h) {
    $byPattern[$h['pattern']][] = $h;
}

// 3) Numara cate definitii ale functiei pz_smslink_send_sms exista
$funcDefs = $byPattern['function_def'] ?? [];

// 4) Cron-uri din DB-ul cPanel — nu putem citi crontab dar afisam fisierele cron_*.php
$cronFiles = [];
foreach (glob($ROOT . '/cron_*.php') as $f) {
    $cronFiles[] = basename($f);
}

// 5) Ultimele 20 SMS din log
$recentLogs = [];
try {
    $stmt = $pdo->query("SELECT id, recipient, status, http_code, created_at, SUBSTRING(provider_response, 1, 100) AS resp FROM notification_logs WHERE channel = 'sms' ORDER BY id DESC LIMIT 20");
    $recentLogs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {}

// 6) Verifica daca exista notification_lib.php in alta locatie (alta versiune)
$notifFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getFilename() === 'notification_lib.php') {
        $relPath = str_replace($ROOT . '/', '', $f->getPathname());
        $notifFiles[] = [
            'path' => $relPath,
            'size' => $f->getSize(),
            'mtime' => date('Y-m-d H:i:s', $f->getMTime()),
            'hasGlobalCheck' => strpos(@file_get_contents($f->getPathname()), "Trimiterea SMS este dezactivat") !== false,
        ];
    }
}

function pre($s) { return '<pre style="background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px;font-size:12px;overflow:auto;max-height:280px">'.htmlspecialchars((string)$s).'</pre>'; }
function badge($s, $bad=false) {
    $bg = $bad ? '#fee2e2' : '#dcfce7';
    $col = $bad ? '#991b1b' : '#166534';
    return '<span style="background:'.$bg.';color:'.$col.';padding:2px 8px;border-radius:6px;font-family:ui-monospace,Menlo,monospace;font-size:12px;font-weight:700;">'.htmlspecialchars($s).'</span>';
}
?>
<!doctype html>
<html lang="ro"><head><meta charset="utf-8"><title>Diagnostic SMS v2</title>
<style>
body{font-family:-apple-system,Arial;max-width:1080px;margin:24px auto;padding:0 18px;background:#f8fafc;color:#0f172a}
h1{font-size:22px;margin:0 0 6px}
h2{font-size:16px;margin:18px 0 8px;color:#334155}
h3{font-size:14px;margin:14px 0 6px;color:#475569}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin-bottom:14px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:6px 8px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}
th{font-size:11px;text-transform:uppercase;color:#475569;letter-spacing:.04em;background:#f8fafc}
.muted{color:#64748b;font-size:12px}
.bad{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;padding:10px 14px;border-radius:10px;margin:10px 0;font-size:13px;font-weight:600}
.ok{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:10px 14px;border-radius:10px;margin:10px 0;font-size:13px;font-weight:600}
.warn{background:#fffbeb;border:1px solid #fcd34d;color:#78350f;padding:10px 14px;border-radius:10px;margin:10px 0;font-size:13px;font-weight:600}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-family:ui-monospace,Menlo,monospace;font-size:12px}
.snippet{font-family:ui-monospace,Menlo,monospace;font-size:11px;color:#0f172a;background:#f8fafc;padding:4px 6px;border-radius:4px;display:block;white-space:pre-wrap;word-break:break-all}
</style></head><body>

<h1>Diagnostic SMS v2 — Search Adânc</h1>
<p class="muted">Caută recursiv în TOT proiectul după codul care trimite SMS-uri.</p>

<div class="card">
    <h2>1) Setari SMS din baza de date</h2>
    <?php if (!$smsSettings): ?>
        <p class="muted">Nu am gasit setari cu nume care contine "sms".</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Cheie</th><th>Valoare</th></tr></thead>
            <tbody>
            <?php foreach ($smsSettings as $s): ?>
                <tr>
                    <td><code><?= htmlspecialchars($s['setting_key']) ?></code></td>
                    <td><?= badge($s['setting_value'] ?? '(empty)', $s['setting_key'] === 'smslink_enabled' && $s['setting_value'] !== '0') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php $current = '1'; foreach ($smsSettings as $s) if ($s['setting_key']==='smslink_enabled') $current = $s['setting_value']; ?>
        <?php if ($current !== '0'): ?>
            <div class="bad">smslink_enabled = "<?= htmlspecialchars($current) ?>" — adica ACTIV. SMS-urile <strong>vor pleca</strong>. Dezactiveaza si salveaza in Setari → Comunicare/Integrari.</div>
        <?php else: ?>
            <div class="ok">smslink_enabled = "0" — DEZACTIVAT. Codul ar trebui sa nu trimita. Daca tot trimite, problema e in cod (sectiunile urmatoare).</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h2>2) Fisiere <code>notification_lib.php</code> gasite in proiect</h2>
    <?php if (count($notifFiles) > 1): ?>
        <div class="bad">ATENTIE: <?= count($notifFiles) ?> copii diferite ale <code>notification_lib.php</code>. Doar UNA e cea reala — restul pot fi vechi si pot dubla functia.</div>
    <?php endif; ?>
    <table>
        <thead><tr><th>Cale</th><th>Marime</th><th>Modificat</th><th>Are check global</th></tr></thead>
        <tbody>
        <?php foreach ($notifFiles as $n): ?>
            <tr>
                <td><code><?= htmlspecialchars($n['path']) ?></code></td>
                <td><?= number_format($n['size']) ?> B</td>
                <td><?= $n['mtime'] ?></td>
                <td><?= $n['hasGlobalCheck'] ? badge('DA') : badge('NU', true) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>3) Toate definitiile functiei <code>pz_smslink_send_sms</code></h2>
    <?php if (!$funcDefs): ?>
        <div class="bad">Nu am gasit nicio definitie a functiei! Asta e ciudat.</div>
    <?php elseif (count($funcDefs) > 1): ?>
        <div class="bad">CAUZA POSIBILA: <?= count($funcDefs) ?> definitii ale functiei. PHP ia prima incarcata si o ignora pe a doua (sau viceversa). Una din ele e vechea, fara check-ul global.</div>
    <?php else: ?>
        <div class="ok">O singura definitie — OK.</div>
    <?php endif; ?>
    <table>
        <thead><tr><th>Fisier</th><th>Linie</th><th>Cod</th></tr></thead>
        <tbody>
        <?php foreach ($funcDefs as $d): ?>
            <tr><td><code><?= htmlspecialchars($d['file']) ?></code></td><td><?= $d['line'] ?></td><td><span class="snippet"><?= htmlspecialchars($d['snippet']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>4) Toate locurile care apeleaza SMSLink / mentioneaza smslink</h2>
    <?php foreach (['smslink_domain'=>'Domeniu smslink.ro','function_call'=>'Apel pz_smslink_send_sms()','send_appointment'=>'Apel pz_send_appointment_*_sms()','send_task'=>'Apel pz_send_task_expiring_7_sms()','smslink_setting'=>'Referinta smslink_enabled','http_post'=>'HTTP request cu sms in URL'] as $key => $title): ?>
        <h3><?= htmlspecialchars($title) ?> (<?= count($byPattern[$key] ?? []) ?>)</h3>
        <?php if (!empty($byPattern[$key])): ?>
            <table>
                <thead><tr><th style="width:240px">Fisier</th><th style="width:50px">Linie</th><th>Cod</th></tr></thead>
                <tbody>
                <?php foreach ($byPattern[$key] as $h): ?>
                    <tr><td><code><?= htmlspecialchars($h['file']) ?></code></td><td><?= $h['line'] ?></td><td><span class="snippet"><?= htmlspecialchars($h['snippet']) ?></span></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">— niciun rezultat —</p>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<div class="card">
    <h2>5) Cron-uri care pot trimite SMS automat</h2>
    <p class="muted">Fisierele cron_*.php din proiect (nu pot vedea ce e setat in cPanel, dar fisierele astea pot fi apelate de cron):</p>
    <ul>
        <?php foreach ($cronFiles as $c): ?>
            <li><code><?= htmlspecialchars($c) ?></code></li>
        <?php endforeach; ?>
    </ul>
    <p class="muted">Daca un cron ruleaza periodic prin PHP CLI si include un <code>notification_lib.php</code> mai vechi, va trimite SMS ignorand UI-ul.</p>
</div>

<div class="card">
    <h2>6) Ultimele 20 inregistrari din <code>notification_logs</code></h2>
    <?php if (!$recentLogs): ?>
        <p class="muted">Niciun log gasit.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>ID</th><th>Data</th><th>Telefon</th><th>Status</th><th>HTTP</th><th>Raspuns</th></tr></thead>
            <tbody>
            <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td><?= (int)$log['id'] ?></td>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars($log['recipient']) ?></td>
                    <td><?= badge($log['status'], $log['status'] === 'sent') ?></td>
                    <td><?= $log['http_code'] ?: '-' ?></td>
                    <td style="font-size:11px;color:#64748b;max-width:380px"><?= htmlspecialchars($log['resp'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted" style="margin-top:8px">Dupa ce ai dezactivat global, orice rand cu status <code>sent</code> = SMS care a ocolit check-ul. Trimite-mi screenshot dupa ce stii ora la care ai dezactivat.</p>
    <?php endif; ?>
</div>

<p class="muted" style="margin-top:18px"><strong>Trimite-mi screenshot cu TOATE sectiunile.</strong> Pe baza datelor pot identifica EXACT unde se pierde check-ul. Apoi sterge fisierul.</p>

</body></html>
