<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/document_core.php';
require_once __DIR__ . '/document_tokens.php';
require_once __DIR__ . '/document_engine.php';

if (file_exists(__DIR__ . '/lib/notification_lib.php')) {
    require_once __DIR__ . '/lib/notification_lib.php';
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

$isAdmin = is_admin();
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

try {
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    pzdoc_require_schema($pdo);
    if (function_exists('pz_notify_init')) {
        pz_notify_init();
    }
} catch (Throwable $e) {
    error_log('Emma document email init error: ' . $e->getMessage());
}

function pzdoc_email_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pzdoc_email_str($value, int $max = 0): string
{
    $value = trim((string)($value ?? ''));
    if ($max > 0) {
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $max, 'UTF-8');
        } else {
            $value = substr($value, 0, $max);
        }
    }
    return $value;
}

function pzdoc_email_is_valid(string $email): bool
{
    $email = trim($email);
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function pzdoc_email_parse_list(string $value): array
{
    $parts = preg_split('/[,;\s]+/', trim($value));
    $out = [];
    foreach ($parts ?: [] as $email) {
        $email = trim((string)$email);
        if ($email !== '') {
            $out[] = $email;
        }
    }
    return array_values(array_unique($out));
}

function pzdoc_email_invalid_list(string $value): array
{
    $bad = [];
    foreach (pzdoc_email_parse_list($value) as $email) {
        if (!pzdoc_email_is_valid($email)) {
            $bad[] = $email;
        }
    }
    return $bad;
}

function pzdoc_email_setting(string $key, string $default = ''): string
{
    if (function_exists('pz_setting_get')) {
        return trim((string)pz_setting_get($key, $default));
    }
    return $default;
}

function pzdoc_email_sendgrid_status(): array
{
    $apiKey = pzdoc_email_setting('sendgrid_api_key', '');
    $fromEmail = pzdoc_email_setting('email_from_address', '');
    $fromName = pzdoc_email_setting('email_from_name', pz_company_name());

    if ($apiKey === '' || $apiKey === '********') {
        $apiKey = defined('SENDGRID_API_KEY') ? trim((string)SENDGRID_API_KEY) : trim((string)(getenv('SENDGRID_API_KEY') ?: ''));
    }
    if ($fromEmail === '') {
        $fromEmail = defined('SENDGRID_FROM_EMAIL') ? trim((string)SENDGRID_FROM_EMAIL) : trim((string)(getenv('SENDGRID_FROM_EMAIL') ?: ''));
    }

    return [
        'ready' => ($apiKey !== '' && pzdoc_email_is_valid($fromEmail) && function_exists('curl_init') && function_exists('pz_sendgrid_send_email')),
        'has_key' => $apiKey !== '',
        'from_email' => $fromEmail,
        'from_name' => $fromName ?: pz_company_name(),
        'curl' => function_exists('curl_init'),
        'lib' => function_exists('pz_sendgrid_send_email'),
    ];
}

function pzdoc_email_base_url(): string
{
    if (defined('APP_URL') && APP_URL) {
        return rtrim((string)APP_URL, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    return rtrim($scheme . '://' . $host . ($dir === '' || $dir === '/' ? '' : $dir), '/');
}

function pzdoc_email_document_url(int $documentId): string
{
    return pzdoc_email_base_url() . '/document_view.php?id=' . (int)$documentId;
}

function pzdoc_email_default_subject(array $document): string
{
    $type = pzdoc_normalize_document_type((string)($document['document_type'] ?? ''));
    $number = trim((string)($document['document_number'] ?? '')) ?: ('ID ' . (int)($document['id'] ?? 0));
    $client = trim((string)($document['client_name_snapshot'] ?? ''));

    if ($type === 'oferta') {
        $subject = 'Oferta ' . $number;
    } elseif ($type === 'contract') {
        $subject = 'Contract ' . $number;
    } elseif ($type === 'proces_verbal') {
        $subject = 'Proces verbal ' . $number;
    } else {
        $subject = pzdoc_document_type_label($type) . ' ' . $number;
    }

    if ($client !== '') {
        $subject .= ' - ' . $client;
    }
    return $subject;
}

function pzdoc_email_default_body(array $document): string
{
    $type = pzdoc_normalize_document_type((string)($document['document_type'] ?? 'document'));
    $number = trim((string)($document['document_number'] ?? '')) ?: 'documentul emis';

    if ($type === 'oferta') {
        return "Bună ziua,\n\nVa transmitem oferta comerciala {$number}, atașata acestui email.\n\nPentru orice detalii sau clarificari, va stam la dispozitie.\n\nCu stima,\n{{company_name}}\n{{company_phone}}\n{{company_email}}";
    }

    if ($type === 'contract') {
        return "Bună ziua,\n\nVa transmitem contractul {$number}, atașat acestui email.\n\nPentru orice detalii sau clarificari, va stam la dispozitie.\n\nCu stima,\n{{company_name}}\n{{company_phone}}\n{{company_email}}";
    }

    if ($type === 'proces_verbal') {
        return "Bună ziua,\n\nVa transmitem procesul verbal {$number}, atașat acestui email.\n\nPentru orice detalii sau clarificari, va stam la dispozitie.\n\nCu stima,\n{{company_name}}\n{{company_phone}}\n{{company_email}}";
    }

    $label = pzdoc_document_type_label($type);
    return "Bună ziua,\n\nVa transmitem {$label} {$number}, atașat acestui email.\n\nPentru orice detalii sau clarificari, va stam la dispozitie.\n\nCu stima,\n{{company_name}}\n{{company_phone}}\n{{company_email}}";
}

function pzdoc_email_body_to_html(string $body): string
{
    $body = trim($body);
    if ($body === '') {
        return '';
    }
    if (stripos($body, '<p') !== false || stripos($body, '<br') !== false || stripos($body, '<div') !== false) {
        return $body;
    }
    return '<p>' . nl2br(pzdoc_email_h($body)) . '</p>';
}

function pzdoc_email_append_public_link_html(string $html, string $url): string
{
    $urlEsc = pzdoc_email_h($url);
    return $html . "\n" .
        '<div style="margin:18px 0;padding:16px;border:1px solid #dbe3ef;border-radius:12px;background:#f8fafc;">' .
        '<p style="margin:0 0 12px;font-weight:700;color:#10243e;">Documentul este disponibil aici:</p>' .
        '<p style="margin:0 0 12px;"><a href="' . $urlEsc . '" style="display:inline-block;background:#10243e;color:#ffffff;text-decoration:none;padding:10px 14px;border-radius:10px;font-weight:700;">Deschide documentul</a></p>' .
        '<p style="margin:0;color:#64748b;font-size:13px;line-height:1.45;">Din pagina deschisa, documentul poate fi printat sau salvat ca PDF. Pagina pastreaza exact aspectul documentului din CRM.</p>' .
        '</div>';
}

function pzdoc_email_append_public_link_text(string $text, string $url): string
{
    return trim($text) . "\n\nDocument: " . $url;
}

function pzdoc_email_log(PDO $pdo, int $documentId, string $recipient, string $cc, string $subject, string $body, string $attachmentPath, string $status, string $provider, string $providerResponse): void
{
    try {
        $stmt = $pdo->prepare("\n            INSERT INTO document_email_logs\n                (document_id, recipient, cc, subject, body, attachment_path, status, provider, provider_response, sent_by, sent_at)\n            VALUES\n                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n        ");
        $stmt->execute([
            $documentId,
            pzdoc_email_str($recipient, 255),
            pzdoc_email_str($cc, 255),
            pzdoc_email_str($subject, 255),
            $body,
            pzdoc_email_str($attachmentPath, 255),
            pzdoc_email_str($status, 40),
            pzdoc_email_str($provider, 80),
            $providerResponse,
            function_exists('current_user_id') ? current_user_id() : null,
        ]);
    } catch (Throwable $e) {
        error_log('Emma document email log error: ' . $e->getMessage());
    }
}

function pzdoc_email_relative_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $base = str_replace('\\', '/', __DIR__);
    if (str_starts_with($path, $base . '/')) {
        return substr($path, strlen($base) + 1);
    }
    return $path;
}

function pzdoc_email_send_one(string $email, string $subject, string $html, string $text, array $attachments, int $documentId): array
{
    if (!function_exists('pz_sendgrid_send_email')) {
        return ['ok' => false, 'error' => 'Functia pz_sendgrid_send_email nu există. Verifica notification_lib.php.'];
    }
    return pz_sendgrid_send_email($email, $subject, $html, $text, $attachments, 'document', $documentId);
}

/* Helper: asigura folderul tmp/document_emails (creeaza dacă lipseste) */
if (!function_exists('pzdoc_ensure_email_tmp_dir')) {
    function pzdoc_ensure_email_tmp_dir(): string {
        $dir = __DIR__ . '/tmp/document_emails';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Nu pot crea folderul ' . $dir . '. Verifica permisiunile pe folderul tmp/.');
            }
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Folderul ' . $dir . ' nu e writable. Setati permisiuni 775.');
        }
        return $dir;
    }
}

/* Helper: rezolva email-ul clientului cu fallback (snapshot -> live din clients.email) */
if (!function_exists('pzdoc_resolve_client_email')) {
    function pzdoc_resolve_client_email(PDO $pdo, array $document): array {
        $snapshot = trim((string)($document['client_email_snapshot'] ?? ''));
        if ($snapshot !== '' && filter_var($snapshot, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $snapshot, 'source' => 'snapshot', 'updated' => false];
        }
        $clientId = (int)($document['client_id'] ?? 0);
        if ($clientId <= 0) {
            return ['email' => '', 'source' => 'none', 'updated' => false];
        }
        try {
            $stmt = $pdo->prepare("SELECT email FROM clients WHERE id = ? LIMIT 1");
            $stmt->execute([$clientId]);
            $live = trim((string)$stmt->fetchColumn());
            if ($live === '' || !filter_var($live, FILTER_VALIDATE_EMAIL)) {
                return ['email' => '', 'source' => 'none', 'updated' => false];
            }
            $upd = $pdo->prepare("UPDATE documents SET client_email_snapshot = ? WHERE id = ?");
            $upd->execute([$live, (int)$document['id']]);
            return ['email' => $live, 'source' => 'live', 'updated' => true];
        } catch (Throwable $e) {
            error_log('pzdoc_resolve_client_email error: ' . $e->getMessage());
            return ['email' => '', 'source' => 'none', 'updated' => false];
        }
    }
}

$documentId = max(0, (int)($_GET['id'] ?? $_POST['document_id'] ?? 0));
$document = null;
$logs = [];
$errorMessage = '';
$successMessage = '';

try {
    if ($documentId > 0) {
        $document = pzdoc_get_document($pdo, $documentId, true);
        if ($document) {
            $stmt = $pdo->prepare("SELECT * FROM document_email_logs WHERE document_id = ? ORDER BY sent_at DESC, id DESC LIMIT 30");
            $stmt->execute([$documentId]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
} catch (Throwable $e) {
    $errorMessage = 'Nu am putut incarca documentul: ' . $e->getMessage();
}

if (!$document && $documentId > 0 && $errorMessage === '') {
    $errorMessage = 'Document inexistent.';
}

$sg = pzdoc_email_sendgrid_status();
$tokens = $document ? pzdoc_build_tokens($pdo, $document) : [];
// Default destinatar: snapshot, dar fallback live din clients.email dacă snapshot e gol
$defaultTo = '';
if ($document) {
    $resolved = pzdoc_resolve_client_email($pdo, $document);
    $defaultTo = $resolved['email'];
    // Reincarcam document-ul dacă s-a actualizat snapshot-ul
    if ($resolved['updated']) {
        $document = pzdoc_get_document($pdo, $documentId, true) ?: $document;
    }
}
$defaultSubject = $document ? pzdoc_email_default_subject($document) : '';
$defaultBody = $document ? pzdoc_email_default_body($document) : '';
$defaultBodyRendered = $document ? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", pzdoc_apply_tokens($defaultBody, $tokens))) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $document) {
    try {
        csrf_require();

        if (($document['status'] ?? '') !== 'issued') {
            throw new RuntimeException('Documentul trebuie emis înainte de trimiterea pe email.');
        }
        if (!$sg['ready']) {
            throw new RuntimeException('SendGrid nu este configurat complet. Verifica Setări > Comunicare / Integrări.');
        }

        $toRaw = pzdoc_email_str($_POST['to_email'] ?? '', 255);
        $ccRaw = pzdoc_email_str($_POST['cc_email'] ?? '', 255);
        $subjectRaw = pzdoc_email_str($_POST['subject'] ?? '', 255);
        $bodyRaw = trim((string)($_POST['body'] ?? ''));

        $toEmails = pzdoc_email_parse_list($toRaw);
        $ccEmails = pzdoc_email_parse_list($ccRaw);
        $bad = array_merge(pzdoc_email_invalid_list($toRaw), pzdoc_email_invalid_list($ccRaw));
        if (!$toEmails) {
            throw new RuntimeException('Completează cel puțin un destinatar valid.');
        }
        if ($bad) {
            throw new RuntimeException('Adrese email invalide: ' . implode(', ', $bad));
        }
        if ($subjectRaw === '') {
            throw new RuntimeException('Completează subiectul emailului.');
        }
        if ($bodyRaw === '') {
            throw new RuntimeException('Completează mesajul emailului.');
        }

        $tokens = pzdoc_build_tokens($pdo, $document);
        $subject = trim(strip_tags(pzdoc_apply_tokens($subjectRaw, $tokens)));
        $bodyApplied = pzdoc_apply_tokens($bodyRaw, $tokens);
        $htmlBody = pzdoc_email_body_to_html($bodyApplied);
        // Generam PDF-ul real prin mPDF si il atasam la email.
        $tmpDir = __DIR__ . '/tmp/document_emails';
        if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0755, true); }
        if (!is_writable($tmpDir)) { throw new RuntimeException('Folderul tmp/document_emails nu este scriibil.'); }
        $pdfTmpPath = $tmpDir . '/doc_' . $documentId . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $pdfFilename = pzdoc_engine_pdf_to_file($pdo, $documentId, $pdfTmpPath);
        $textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "
", $htmlBody)), ENT_QUOTES, 'UTF-8'));
        // (PDF-ul e atașat direct la email, nu mai trimitem link public)

        $attachments = [[
            'path' => $pdfTmpPath,
            'mime' => 'application/pdf',
            'filename' => $pdfFilename,
        ]];

        $sent = 0;
        $failed = 0;
        $messages = [];
        $attachmentRelative = 'mpdf:' . basename($pdfTmpPath);

        foreach ($toEmails as $email) {
            $result = pzdoc_email_send_one($email, $subject, $htmlBody, $textBody, $attachments, $documentId);
            $ok = !empty($result['ok']);
            $ok ? $sent++ : $failed++;
            $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            pzdoc_email_log($pdo, $documentId, $email, $ccRaw, $subject, $bodyRaw, $attachmentRelative, $ok ? 'sent' : 'failed', 'sendgrid', $response ?: '');
            if (!$ok) {
                $messages[] = $email . ': ' . ($result['error'] ?? $result['response'] ?? 'eroare necunoscuta');
            }
        }

        foreach ($ccEmails as $email) {
            $ccSubject = '[Copie] ' . $subject;
            $result = pzdoc_email_send_one($email, $ccSubject, $htmlBody, $textBody, $attachments, $documentId);
            $ok = !empty($result['ok']);
            $ok ? $sent++ : $failed++;
            $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            pzdoc_email_log($pdo, $documentId, $email, '', $ccSubject, $bodyRaw, $attachmentRelative, $ok ? 'sent' : 'failed', 'sendgrid', $response ?: '');
            if (!$ok) {
                $messages[] = $email . ': ' . ($result['error'] ?? $result['response'] ?? 'eroare necunoscuta');
            }
        }

        if ($sent > 0) {
            $update = $pdo->prepare("\n                UPDATE documents\n                SET email_sent_at = NOW(), email_sent_to = ?, email_sent_count = COALESCE(email_sent_count, 0) + ?\n                WHERE id = ?\n            ");
            $update->execute([implode(', ', array_merge($toEmails, $ccEmails)), $sent, $documentId]);
        }

        if ($failed > 0) {
            $errorMessage = 'Email trimis partial. Reusite: ' . $sent . ', esuate: ' . $failed . '. ' . implode(' | ', $messages);
        } else {
            $successMessage = 'Email trimis cu succes catre ' . $sent . ' destinatar(i), cu link de document printabil.';
        }

        $document = pzdoc_get_document($pdo, $documentId, true);
        $stmt = $pdo->prepare("SELECT * FROM document_email_logs WHERE document_id = ? ORDER BY sent_at DESC, id DESC LIMIT 30");
        $stmt->execute([$documentId]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$type = $document ? pzdoc_normalize_document_type((string)$document['document_type']) : '';
$backUrl = 'document_view.php?id=' . (int)$documentId;
if ($type === 'oferta') $listUrl = 'offers';
elseif ($type === 'contract') $listUrl = 'contracts.php';
elseif ($type === 'proces_verbal') $listUrl = 'service-reports';
else $listUrl = 'dashboard.php';

$toValue = $_POST['to_email'] ?? $defaultTo;
$ccValue = $_POST['cc_email'] ?? '';
$subjectValue = $_POST['subject'] ?? $defaultSubject;
$bodyValue = $_POST['body'] ?? $defaultBodyRendered;
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Trimite document email - <?= h(pz_app_name()) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.email-topbar { align-items:center; padding:12px 20px; }
.email-toolbar { width:100%; display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap; }
.email-hero { background:linear-gradient(135deg,#10243E,#163B63); color:#fff; border-radius:var(--radius-lg); padding:22px 24px; box-shadow:var(--shadow-lg); margin-bottom:14px; display:flex; justify-content:space-between; gap:18px; flex-wrap:wrap; align-items:center; }
.email-hero h1 { font-size:24px; font-weight:950; letter-spacing:-.03em; margin:0; }
.email-hero p { color:rgba(255,255,255,.72); margin:4px 0 0; }
.panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); box-shadow:var(--shadow); margin-bottom:12px; }
.panel-head { padding:14px 16px; border-bottom:1px solid var(--border2); display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; }
.panel-title { font-size:16px; font-weight:900; color:var(--text); }
.panel-subtitle { font-size:12px; color:var(--muted); margin-top:2px; }
.panel-body { padding:14px 16px; }
.alert { border-radius:14px; padding:11px 13px; margin-bottom:12px; font-weight:800; font-size:13px; }
.alert.error { background:var(--danger-soft); color:var(--danger); border:1px solid rgba(180,35,24,.16); }
.alert.success { background:var(--success-soft); color:var(--success); border:1px solid rgba(31,111,84,.16); }
.alert.warn { background:var(--warning-soft); color:var(--warning); border:1px solid rgba(154,103,0,.18); }
.grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.field.full { grid-column:1 / -1; }
.field label { display:block; font-size:12px; font-weight:850; color:var(--muted); margin-bottom:5px; }
.field input, .field textarea { width:100%; border:1px solid var(--border); border-radius:12px; background:#fff; color:var(--text); padding:10px 11px; font-size:13px; outline:none; }
.field textarea { min-height:190px; resize:vertical; line-height:1.45; }
.field input:focus, .field textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }
.help { margin-top:6px; color:var(--muted); font-size:12px; line-height:1.4; }
.meta-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:12px; }
.meta-card { background:#fff; border:1px solid var(--border); border-radius:16px; padding:12px 13px; box-shadow:var(--shadow); }
.meta-label { font-size:11px; color:var(--muted); font-weight:900; text-transform:uppercase; letter-spacing:.04em; }
.meta-value { margin-top:4px; font-size:14px; font-weight:900; color:var(--text); overflow-wrap:anywhere; }
.btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:38px; border-radius:12px; padding:0 13px; border:1px solid var(--border); background:#fff; color:var(--text); font-size:13px; font-weight:900; text-decoration:none; cursor:pointer; white-space:nowrap; }
.btn:hover { border-color:var(--accent); color:var(--accent-deep); }
.btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn.primary:hover { background:var(--accent-strong); color:#fff; }
.btn.dark { background:#10243E; border-color:#10243E; color:#fff; }
.btn.small { min-height:32px; padding:0 10px; font-size:12px; border-radius:10px; }
.form-actions { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; margin-top:14px; }
.form-actions .right { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.logs { display:grid; gap:8px; }
.log-row { display:grid; grid-template-columns:minmax(180px,1fr) minmax(90px,.3fr) minmax(160px,.5fr); gap:10px; align-items:start; padding:11px 12px; border:1px solid var(--border); border-radius:14px; background:#fff; }
.badge { display:inline-flex; align-items:center; justify-content:center; border-radius:999px; padding:6px 9px; font-size:11px; font-weight:900; border:1px solid var(--border2); background:var(--surface-soft); color:var(--muted); white-space:nowrap; }
.badge.sent { background:var(--success-soft); color:var(--success); border-color:rgba(31,111,84,.18); }
.badge.failed { background:var(--danger-soft); color:var(--danger); border-color:rgba(180,35,24,.16); }
.empty-state { padding:18px; text-align:center; color:var(--muted); font-weight:800; border:1px dashed var(--border); border-radius:16px; background:var(--surface-soft); }
@media(max-width:980px){ .grid,.meta-grid,.log-row{grid-template-columns:1fr;} .email-hero{padding:18px;} .form-actions{display:grid;} .form-actions .right,.form-actions .btn,.form-actions button{width:100%;} }
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('documente', $isAdmin); ?>
    <main class="main">
        <header class="topbar email-topbar">
            <div class="email-toolbar">
                <a class="btn" href="<?= pzdoc_email_h($listUrl) ?>">Lista documente</a>
                <?php if ($document): ?>
                    <a class="btn" href="document_view.php?id=<?= (int)$documentId ?>">Vezi document</a>
                    <a class="btn" target="_blank" href="document_pdf.php?id=<?= (int)$documentId ?>&mode=download">Descarcă PDF</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="content">
            <section class="email-hero">
                <div>
                    <h1>Trimite document pe email</h1>
                    <p>Trimitere prin SendGrid cu PDF-ul documentului atașat automat.</p>
                </div>
            </section>

            <?php if ($errorMessage !== ''): ?><div class="alert error"><?= pzdoc_email_h($errorMessage) ?></div><?php endif; ?>
            <?php if ($successMessage !== ''): ?><div class="alert success"><?= pzdoc_email_h($successMessage) ?></div><?php endif; ?>

            <?php if (!$document): ?>
                <section class="panel"><div class="panel-body"><div class="empty-state">Documentul nu a fost gasit.</div></div></section>
            <?php else: ?>
                <section class="meta-grid">
                    <div class="meta-card"><div class="meta-label">Tip</div><div class="meta-value"><?= pzdoc_email_h(pzdoc_document_type_label($type)) ?></div></div>
                    <div class="meta-card"><div class="meta-label">Numar</div><div class="meta-value"><?= pzdoc_email_h($document['document_number'] ?: 'Draft') ?></div></div>
                    <div class="meta-card"><div class="meta-label">Client</div><div class="meta-value"><?= pzdoc_email_h($document['client_name_snapshot'] ?: '-') ?></div></div>
                    <div class="meta-card"><div class="meta-label">Emailuri trimise</div><div class="meta-value"><?= (int)($document['email_sent_count'] ?? 0) ?></div></div>
                </section>

                <?php if (($document['status'] ?? '') !== 'issued'): ?>
                    <div class="alert warn">Documentul trebuie emis înainte de trimiterea pe email. Intra in vizualizare si apasa Emite document.</div>
                <?php endif; ?>
                <?php if (!$sg['ready']): ?>
                    <div class="alert warn">SendGrid nu este complet pregătit. Cheie: <?= $sg['has_key'] ? 'OK' : 'lipsa' ?> | Expeditor: <?= pzdoc_email_h($sg['from_email'] ?: 'lipsa') ?> | cURL: <?= $sg['curl'] ? 'OK' : 'lipsa' ?> | librarie: <?= $sg['lib'] ? 'OK' : 'lipsa' ?>. Verifica Setări &gt; Comunicare / Integrări.</div>
                <?php endif; ?>

                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title">Mesaj email</div>
                            <div class="panel-subtitle">Poti folosi variabile precum {{client_name}}, {{document_number}}, {{document_date}}, {{company_name}}.</div>
                        </div>
                    </div>
                    <div class="panel-body">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="document_id" value="<?= (int)$documentId ?>">
                            <div class="grid">
                                <div class="field">
                                    <label>Catre</label>
                                    <input type="text" name="to_email" value="<?= pzdoc_email_h($toValue) ?>" placeholder="client@email.ro">
                                    <div class="help">Poti introduce mai multe adrese separate prin virgula sau punct si virgula.</div>
                                </div>
                                <div class="field">
                                    <label>CC / copie</label>
                                    <input type="text" name="cc_email" value="<?= pzdoc_email_h($ccValue) ?>" placeholder="optional">
                                </div>
                                <div class="field full">
                                    <label>Subiect</label>
                                    <input type="text" name="subject" value="<?= pzdoc_email_h($subjectValue) ?>">
                                </div>
                                <div class="field full">
                                    <label>Mesaj</label>
                                    <textarea name="body"><?= pzdoc_email_h($bodyValue) ?></textarea>
                                    <div class="help">Emailul va contine PDF-ul documentului atașat automat. Textul poate fi editat înainte de trimitere.</div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <a class="btn" href="document_view.php?id=<?= (int)$documentId ?>">Înapoi</a>
                                <div class="right">
                                    <a class="btn" target="_blank" href="document_pdf.php?id=<?= (int)$documentId ?>">Verifica PDF</a>
                                    <button class="btn primary" type="submit" <?= (($document['status'] ?? '') !== 'issued' || !$sg['ready']) ? 'disabled' : '' ?> onclick="return confirm('Trimiti emailul cu PDF atașat catre destinatar?');">Trimite email</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title">Istoric trimitere</div>
                            <div class="panel-subtitle">Ultimele 30 de incercari pentru acest document.</div>
                        </div>
                    </div>
                    <div class="panel-body">
                        <?php if (!$logs): ?>
                            <div class="empty-state">Nu există trimiteri inregistrate pentru acest document.</div>
                        <?php else: ?>
                            <div class="logs">
                                <?php foreach ($logs as $log): ?>
                                    <div class="log-row">
                                        <div>
                                            <strong><?= pzdoc_email_h($log['recipient'] ?? '') ?></strong><br>
                                            <span class="help"><?= pzdoc_email_h($log['subject'] ?? '') ?></span>
                                        </div>
                                        <div><span class="badge <?= pzdoc_email_h(($log['status'] ?? '') === 'sent' ? 'sent' : 'failed') ?>"><?= pzdoc_email_h($log['status'] ?? '-') ?></span></div>
                                        <div class="help"><?= pzdoc_email_h($log['sent_at'] ?? '') ?><br><?= pzdoc_email_h($log['provider'] ?? '') ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
(function(){
    var btn = document.querySelector('.mobile-menu-button');
    var sidebar = document.querySelector('.sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (!btn || !sidebar || !overlay) return;
    btn.addEventListener('click', function(){
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
        document.body.classList.toggle('app-sidebar-open');
    });
    overlay.addEventListener('click', function(){
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
        document.body.classList.remove('app-sidebar-open');
    });
})();
</script>
</body>
</html>
