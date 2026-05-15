<?php
/**
 * Diagnostic SMS — verifica de ce nu se opreste trimiterea SMS.
 *
 * Cum se foloseste:
 *   1. Incarca pe server langa config.php
 *   2. Deschide ca admin: https://app.pestzone.ro/diag_sms.php
 *   3. Trimite-mi screenshot cu rezultatele
 *   4. Sterge fisierul dupa
 */

require_once __DIR__ . '/config.php';

if (function_exists('require_login')) require_login();
if (function_exists('is_admin') && !is_admin()) { http_response_code(403); exit('Doar admin.'); }

$ROOT = __DIR__;

// 1) Citeste valoarea EXACTA din DB
$dbValue = null;
$dbError = null;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'smslink_enabled' LIMIT 1");
    $stmt->execute();
    $dbValue = $stmt->fetchColumn();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// 2) Citeste prin pz_setting_get (cum o citeste codul aplicatiei)
$appValue = function_exists('pz_setting_get') ? pz_setting_get('smslink_enabled', '1') : 'pz_setting_get nu exista';
$appCast  = function_exists('pz_setting_get') ? (int)pz_setting_get('smslink_enabled', '1') : null;
$wouldSkip = ($appCast === 0);

// 3) Verifica daca notification_lib.php are check-ul global
$notifPath = $ROOT . '/notification_lib.php';
$notifExists = is_file($notifPath);
$notifContent = $notifExists ? @file_get_contents($notifPath) : '';
$hasGlobalCheck = $notifExists && strpos($notifContent, "pz_setting_get('smslink_enabled'") !== false;
$hasSkipReturn  = $notifExists && strpos($notifContent, 'Trimiterea SMS este dezactivat') !== false;

// 4) Cauta TOATE locurile din proiect care trimit SMS direct (curl spre smslink)
$directSenders = [];
foreach (glob($ROOT . '/*.php') as $file) {
    $name = basename($file);
    if ($name === basename(__FILE__)) continue;
    $content = @file_get_contents($file);
    if ($content === false) continue;
    // Cauta apeluri directe la SMSLink (curl, file_get_contents) ocolind functia centralizata
    if (preg_match('/(curl_init|file_get_contents|fopen)\s*\([^)]*smslink/i', $content)
        || preg_match('/secure\.smslink\.ro/i', $content)) {
        // Excludem fisierul oficial care chiar trebuie sa o faca
        if ($name !== 'notification_lib.php') {
            $directSenders[] = $name;
        }
    }
}

// 5) Ultimele 10 inregistrari din notification_logs ca sa vedem daca SMS-urile sunt 'sent' sau 'skipped'
$recentLogs = [];
try {
    $stmt = $pdo->query("SELECT id, channel, recipient, status, created_at, SUBSTRING(provider_response, 1, 100) AS response_short FROM notification_logs WHERE channel = 'sms' ORDER BY id DESC LIMIT 10");
    $recentLogs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    // tabelul ar putea sa nu existe
}

// 6) Versiunea functiei pz_smslink_send_sms — verifica daca semnatura are guard
$sigInfo = '';
if ($notifExists && preg_match('/function\s+pz_smslink_send_sms\s*\([^)]*\)/s', $notifContent, $m)) {
    $sigInfo = trim(preg_replace('/\s+/', ' ', $m[0]));
}

function v($s, $bad = []) {
    $s = (string)$s;
    $bg = '#dcfce7'; $col = '#166534';
    foreach ($bad as $b) if ($s === (string)$b) { $bg = '#fee2e2'; $col = '#991b1b'; break; }
    return '<span style="background:'.$bg.';color:'.$col.';padding:2px 8px;border-radius:6px;font-family:ui-monospace,Menlo,monospace;font-size:12px;font-weight:700;">'.htmlspecialchars($s).'</span>';
}
function ok($b) { return $b ? '<span style="color:#047857;font-weight:700;">DA</span>' : '<span style="color:#b91c1c;font-weight:700;">NU</span>'; }
?>
<!doctype html>
<html lang="ro"><head><meta charset="utf-8"><title>Diagnostic SMS</title>
<style>
body{font-family:-apple-system,Arial;max-width:980px;margin:24px auto;padding:0 18px;background:#f8fafc;color:#0f172a}
h1{font-size:22px;margin:0 0 6px}
h2{font-size:16px;margin:18px 0 8px;color:#334155}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin-bottom:14px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px 10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}
th{font-size:11px;text-transform:uppercase;color:#475569;letter-spacing:.04em}
.muted{color:#64748b;font-size:12px}
.warn{background:#fffbeb;border:1px solid #fcd34d;color:#78350f;padding:12px 14px;border-radius:10px;margin:12px 0;font-size:14px}
.ok{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:12px 14px;border-radius:10px;margin:12px 0;font-size:14px}
.bad{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;padding:12px 14px;border-radius:10px;margin:12px 0;font-size:14px}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-family:ui-monospace,Menlo,monospace;font-size:12px}
pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;font-size:12px;max-height:160px}
</style></head><body>

<h1>Diagnostic SMS</h1>
<p class="muted">Verifica de ce nu se opreste trimiterea SMS cand dezactivezi global. Trimite-mi screenshot cu rezultatele.</p>

<div class="card">
    <h2>1) Setarea <code>smslink_enabled</code></h2>
    <table>
        <tr><th>Sursa</th><th>Valoare</th><th>Concluzie</th></tr>
        <tr>
            <td>Direct din DB (<code>app_settings.setting_value</code>)</td>
            <td><?= $dbError ? '<em>EROARE: '.htmlspecialchars($dbError).'</em>' : ($dbValue === null ? '<em>nu exista cheia</em>' : v($dbValue, ['1'])) ?></td>
            <td><?= $dbValue === '0' ? 'DEZACTIVAT in DB' : 'ACTIV in DB' ?></td>
        </tr>
        <tr>
            <td>Prin <code>pz_setting_get()</code></td>
            <td><?= v($appValue, ['1']) ?></td>
            <td><?= $appCast === 0 ? 'codul VEDE 0 → ar trebui sa skip-uie' : 'codul VEDE 1 → trimite' ?></td>
        </tr>
        <tr>
            <td>Cast la (int)</td>
            <td><?= v((string)$appCast, ['1']) ?></td>
            <td><?= $wouldSkip ? '<strong style="color:#047857">if (!$enabled) → skip</strong>' : '<strong style="color:#b91c1c">if (!$enabled) → continua, trimite</strong>' ?></td>
        </tr>
    </table>

    <?php if ($dbValue !== '0' && $appCast !== 0): ?>
        <div class="bad"><strong>Problema:</strong> Ai zis ca ai dezactivat SMS, dar valoarea in DB nu e "0". Inseamna ca dropdown-ul din comm settings <em>nu salveaza</em> valoarea corect, sau a salvat-o sub alta cheie.</div>
    <?php elseif ($dbValue === '0'): ?>
        <div class="ok">DB-ul are valoarea "0" corect. Daca SMS-ul tot se trimite, verifica sectiunea 2 si 3 mai jos.</div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>2) Codul din <code>notification_lib.php</code></h2>
    <table>
        <tr><th>Verificare</th><th>Rezultat</th></tr>
        <tr><td>Fisierul exista</td><td><?= ok($notifExists) ?></td></tr>
        <tr><td>Are check global <code>pz_setting_get('smslink_enabled')</code></td><td><?= ok($hasGlobalCheck) ?></td></tr>
        <tr><td>Are mesaj skip <em>"Trimiterea SMS este dezactivata global"</em></td><td><?= ok($hasSkipReturn) ?></td></tr>
        <tr><td>Semnatura functiei</td><td><code><?= htmlspecialchars($sigInfo ?: '— nu am gasit functia —') ?></code></td></tr>
    </table>

    <?php if ($notifExists && !$hasGlobalCheck): ?>
        <div class="bad"><strong>CAUZA PROBABILA:</strong> <code>notification_lib.php</code> de pe server este versiune mai veche, fara check-ul global. Trebuie inlocuit cu versiunea care are check-ul.</div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>3) Alte fisiere care trimit SMS direct (ocolesc functia centralizata)</h2>
    <?php if (!$directSenders): ?>
        <p class="muted">Nu am gasit fisiere care sa apeleze direct SMSLink (curl/file_get_contents catre smslink.ro). OK — toate trimiterile trec prin <code>pz_smslink_send_sms</code>.</p>
    <?php else: ?>
        <div class="bad"><strong>ATENTIE:</strong> Aceste fisiere apeleaza SMSLink direct (ocolesc functia centralizata si nu verifica toggle-ul):</div>
        <ul>
            <?php foreach ($directSenders as $f): ?>
                <li><code><?= htmlspecialchars($f) ?></code></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="card">
    <h2>4) Ultimele 10 SMS din <code>notification_logs</code></h2>
    <?php if (!$recentLogs): ?>
        <p class="muted">Nu am gasit log-uri SMS sau tabelul nu exista.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>ID</th><th>Data</th><th>Destinatar</th><th>Status</th><th>Raspuns</th></tr></thead>
            <tbody>
            <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td><?= (int)$log['id'] ?></td>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars($log['recipient']) ?></td>
                    <td><?= v($log['status'], ['sent']) ?></td>
                    <td style="font-size:11px;color:#64748b"><?= htmlspecialchars($log['response_short'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted" style="margin-top:8px">Daca dupa ce ai dezactivat SMS-ul vezi tot status <strong>sent</strong>, inseamna ca codul ocoleste check-ul. Daca vezi <strong>skipped</strong> cu mesajul "dezactivat global", e perfect.</p>
    <?php endif; ?>
</div>

<p class="muted" style="margin-top:18px"><strong>Dupa diagnostic:</strong> sterge acest fisier de pe server.</p>

</body></html>
