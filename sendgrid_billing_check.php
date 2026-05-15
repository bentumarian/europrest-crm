<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/notification_lib.php';
require_once __DIR__ . '/billing_lib.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Acces permis doar administratorului.');
}

pz_notify_init();
bill_ensure_schema($pdo);

function sbc_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sbc_ok(bool $ok): string { return $ok ? '<span class="ok">OK</span>' : '<span class="bad">ATENTIE</span>'; }

$apiKey = trim((string)pz_setting_get('sendgrid_api_key', ''));
$region = trim((string)pz_setting_get('sendgrid_region', 'global'));
$fromEmail = trim((string)pz_setting_get('email_from_address', ''));
$fromName = trim((string)pz_setting_get('email_from_name', 'PestZone'));
$replyTo = trim((string)pz_setting_get('email_reply_to', ''));
$storageDir = __DIR__ . '/storage/oblio_pdfs';
if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);

$docsWithPdf = 0;
$docsMissingPdf = 0;
try {
    $docsWithPdf = (int)$pdo->query("SELECT COUNT(*) FROM billing_oblio_documents WHERE pdf_path IS NOT NULL AND pdf_path <> ''")->fetchColumn();
    $docsMissingPdf = (int)$pdo->query("SELECT COUNT(*) FROM billing_oblio_documents WHERE link IS NOT NULL AND link <> '' AND (pdf_path IS NULL OR pdf_path = '')")->fetchColumn();
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificare SendGrid facturi</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f7fa;margin:0;padding:24px;color:#111827}.card{max-width:880px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;box-shadow:0 12px 28px rgba(15,23,42,.08)}h1{margin-top:0;font-size:22px}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #eef2f7;padding:10px;text-align:left}.ok{color:#047857;font-weight:800}.bad{color:#b91c1c;font-weight:800}.btn{display:inline-flex;padding:10px 13px;border:1px solid #d1d5db;border-radius:10px;text-decoration:none;color:#111827;margin-right:6px}.primary{background:#0f766e;color:#fff;border-color:#0f766e}
</style>
</head>
<body>
<div class="card">
<h1>Verificare trimitere facturi prin SendGrid</h1>
<table>
<tr><th>Element</th><th>Status</th><th>Detalii</th></tr>
<tr><td>SendGrid API Key</td><td><?= sbc_ok($apiKey !== '' && $apiKey !== '********') ?></td><td><?= $apiKey !== '' ? 'Cheie salvata in setari.' : 'Lipseste cheia.' ?></td></tr>
<tr><td>Regiune SendGrid</td><td><?= sbc_ok(in_array($region, ['global','eu'], true)) ?></td><td><?= sbc_h($region ?: 'global') ?></td></tr>
<tr><td>Email expeditor</td><td><?= sbc_ok(filter_var($fromEmail, FILTER_VALIDATE_EMAIL) !== false) ?></td><td><?= sbc_h($fromEmail ?: 'lipsa') ?></td></tr>
<tr><td>Nume expeditor</td><td><?= sbc_ok($fromName !== '') ?></td><td><?= sbc_h($fromName ?: 'PestZone') ?></td></tr>
<tr><td>Reply-To</td><td><?= sbc_ok($replyTo === '' || filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) ?></td><td><?= sbc_h($replyTo ?: 'necompletat') ?></td></tr>
<tr><td>cURL PHP</td><td><?= sbc_ok(function_exists('curl_init')) ?></td><td><?= function_exists('curl_init') ? 'Activ' : 'Inactiv - SendGrid nu poate functiona fara cURL.' ?></td></tr>
<tr><td>Folder PDF-uri</td><td><?= sbc_ok(is_dir($storageDir) && is_writable($storageDir)) ?></td><td><?= sbc_h($storageDir) ?></td></tr>
<tr><td>Documente cu PDF local</td><td><?= sbc_ok(true) ?></td><td><?= (int)$docsWithPdf ?></td></tr>
<tr><td>Documente cu link Oblio, dar fara PDF local</td><td><?= sbc_ok(true) ?></td><td><?= (int)$docsMissingPdf ?> - se vor descarca automat la trimitere sau prin cron_oblio_pdf_sync.php</td></tr>
</table>
<p>
<a class="btn primary" href="communication_settings.php">Setari SendGrid</a>
<a class="btn" href="billing_documents.php">Documente Oblio</a>
</p>
</div>
</body>
</html>
