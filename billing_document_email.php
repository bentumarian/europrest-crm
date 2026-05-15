<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/billing_lib.php';
require_once __DIR__ . '/notification_lib.php';

if (!is_admin()) {
    header('Location: calendar.php');
    exit;
}

bill_ensure_schema($pdo);
if (function_exists('pz_notify_init')) {
    pz_notify_init();
}

function bde_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bde_valid_email(string $email): bool
{
    $email = trim($email);
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function bde_email_list(string $value): array
{
    $parts = preg_split('/[,;\s]+/', trim($value));
    $out = [];
    foreach ($parts ?: [] as $email) {
        $email = trim((string)$email);
        if ($email !== '') $out[] = $email;
    }
    return array_values(array_unique($out));
}

function bde_invalid_emails(string $value): array
{
    $bad = [];
    foreach (bde_email_list($value) as $email) {
        if (!bde_valid_email($email)) $bad[] = $email;
    }
    return $bad;
}

function bde_setting(string $key, string $default = ''): string
{
    if (function_exists('pz_setting_get')) {
        return trim((string)pz_setting_get($key, $default));
    }
    return $default;
}

function bde_sendgrid_ready(): array
{
    $apiKey = bde_setting('sendgrid_api_key', '');
    $fromEmail = bde_setting('email_from_address', '');
    $fromName = bde_setting('email_from_name', 'PestZone');

    if ($apiKey === '' || $apiKey === '********') {
        $apiKey = defined('SENDGRID_API_KEY') ? trim((string)SENDGRID_API_KEY) : trim((string)(getenv('SENDGRID_API_KEY') ?: ''));
    }
    if ($fromEmail === '') {
        $fromEmail = defined('SENDGRID_FROM_EMAIL') ? trim((string)SENDGRID_FROM_EMAIL) : trim((string)(getenv('SENDGRID_FROM_EMAIL') ?: ''));
    }
    if ($fromName === '') {
        $fromName = defined('SENDGRID_FROM_NAME') ? trim((string)SENDGRID_FROM_NAME) : trim((string)(getenv('SENDGRID_FROM_NAME') ?: 'PestZone'));
    }

    return [
        'ready' => ($apiKey !== '' && bde_valid_email($fromEmail) && function_exists('curl_init') && function_exists('pz_sendgrid_send_email')),
        'has_key' => $apiKey !== '',
        'from_email' => $fromEmail,
        'from_name' => $fromName ?: 'PestZone',
        'curl' => function_exists('curl_init'),
        'lib' => function_exists('pz_sendgrid_send_email'),
    ];
}

function bde_doc_type_label(string $type): string
{
    $type = strtolower(trim($type));
    return match ($type) {
        'invoice' => 'Factura',
        'proforma' => 'Proforma',
        'receipt' => 'Chitanta',
        'collect' => 'Incasare',
        'notice' => 'Aviz',
        default => 'Document',
    };
}

function bde_doc_label(array $doc): string
{
    $type = bde_doc_type_label((string)($doc['oblio_type'] ?? 'document'));
    $series = trim((string)($doc['oblio_series'] ?? ''));
    $number = trim((string)($doc['oblio_number'] ?? ''));
    return trim($type . ' ' . $series . ' ' . $number);
}

function bde_safe_filename(array $doc): string
{
    $type = strtolower((string)($doc['oblio_type'] ?? 'document'));
    $series = (string)($doc['oblio_series'] ?? 'doc');
    $number = (string)($doc['oblio_number'] ?? ($doc['id'] ?? time()));
    $name = strtolower($type . '_' . $series . '_' . $number);
    $name = preg_replace('/[^a-z0-9_\-]+/i', '_', $name);
    $name = trim((string)$name, '_');
    return ($name !== '' ? $name : ('document_' . (int)($doc['id'] ?? 0))) . '.pdf';
}

function bde_base_url(): string
{
    if (defined('APP_URL') && APP_URL) return rtrim((string)APP_URL, '/');

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    return rtrim($scheme . '://' . $host . ($dir === '' || $dir === '/' ? '' : $dir), '/');
}

function bde_url(string $path): string
{
    return bde_base_url() . '/' . ltrim($path, '/');
}

function bde_oblio_link(array $doc): string
{
    $link = trim((string)($doc['link'] ?? ''));
    if ($link !== '') return $link;

    if (!empty($doc['raw_json'])) {
        $raw = json_decode((string)$doc['raw_json'], true);
        if (is_array($raw) && !empty($raw['link'])) return trim((string)$raw['link']);
    }

    return '';
}

function bde_existing_pdf(array $doc): ?array
{
    $pdfPath = trim((string)($doc['pdf_path'] ?? ''));
    if ($pdfPath === '' || preg_match('~^https?://~i', $pdfPath)) return null;

    $clean = str_replace('\\', '/', $pdfPath);
    $candidates = [];

    if (str_starts_with($clean, '/')) {
        $candidates[] = $clean;
    } else {
        $clean = ltrim($clean, '/');
        if (strpos($clean, '..') === false) {
            $candidates[] = __DIR__ . '/' . $clean;
            $candidates[] = __DIR__ . '/storage/' . $clean;
            $candidates[] = __DIR__ . '/uploads/' . $clean;
        }
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate) && filesize($candidate) > 100) {
            return [
                'path' => $candidate,
                'filename' => basename($candidate) ?: bde_safe_filename($doc),
                'mime' => 'application/pdf',
            ];
        }
    }

    return null;
}

function bde_download_pdf(PDO $pdo, array $doc): ?array
{
    $link = bde_oblio_link($doc);
    if ($link === '') return null;

    $type = (string)($doc['oblio_type'] ?? 'document');
    $series = (string)($doc['oblio_series'] ?? 'doc');
    $number = (string)($doc['oblio_number'] ?? ($doc['id'] ?? time()));
    $filename = bde_safe_filename($doc);
    $relative = 'storage/oblio_pdfs/' . $filename;
    $dir = __DIR__ . '/storage/oblio_pdfs';
    $absolute = __DIR__ . '/' . $relative;

    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $urls = [$link];
    if (strpos($link, 'preload=1') !== false) {
        $urls[] = str_replace(['&preload=1', '?preload=1'], ['', ''], $link);
    }
    if (strpos($link, 'api=1') === false) {
        $urls[] = $link . (strpos($link, '?') !== false ? '&api=1' : '?api=1');
    }

    foreach (array_unique($urls) as $url) {
        if (!function_exists('curl_init')) break;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'PestZone CRM PDF Email Downloader',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/pdf,application/octet-stream,*/*'],
        ]);
        $data = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($data === false || $data === '' || $http < 200 || $http >= 300) continue;
        $isPdf = substr((string)$data, 0, 4) === '%PDF' || stripos($contentType, 'pdf') !== false;
        if (!$isPdf) continue;
        if (@file_put_contents($absolute, $data) === false) continue;

        try {
            $stmt = $pdo->prepare("UPDATE billing_oblio_documents SET pdf_path = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$relative, (int)($doc['id'] ?? 0)]);
        } catch (Throwable $e) {}

        return ['path' => $absolute, 'filename' => $filename, 'mime' => 'application/pdf'];
    }

    // fallback pe functia existenta din billing_lib.php, daca exista
    if (function_exists('bill_download_pdf')) {
        try {
            $base = $type . '_' . $series . '_' . $number;
            $path = bill_download_pdf($link, $base);
            if ($path !== '') {
                $candidate = __DIR__ . '/' . ltrim(str_replace('\\', '/', $path), '/');
                if (is_file($candidate) && is_readable($candidate) && filesize($candidate) > 100) {
                    try {
                        $stmt = $pdo->prepare("UPDATE billing_oblio_documents SET pdf_path = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$path, (int)($doc['id'] ?? 0)]);
                    } catch (Throwable $e) {}
                    return ['path' => $candidate, 'filename' => basename($candidate), 'mime' => 'application/pdf'];
                }
            }
        } catch (Throwable $e) {}
    }

    return null;
}

function bde_pdf(PDO $pdo, array $doc): ?array
{
    return bde_existing_pdf($doc) ?: bde_download_pdf($pdo, $doc);
}

function bde_find_client_email(PDO $pdo, array $doc): string
{
    foreach (['client_email', 'email'] as $key) {
        if (!empty($doc[$key]) && bde_valid_email((string)$doc[$key])) return trim((string)$doc[$key]);
    }

    $clientId = (int)($doc['client_id'] ?? 0);
    if ($clientId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT email FROM clients WHERE id = ? LIMIT 1");
            $stmt->execute([$clientId]);
            $email = trim((string)($stmt->fetchColumn() ?: ''));
            if (bde_valid_email($email)) return $email;
        } catch (Throwable $e) {}
    }

    $fiscal = preg_replace('/^RO/i', '', strtoupper(trim((string)($doc['client_fiscal_code'] ?? ''))));
    if ($fiscal !== '') {
        try {
            $stmt = $pdo->prepare("SELECT email FROM clients WHERE REPLACE(UPPER(COALESCE(fiscal_code, '')), 'RO', '') = ? AND email IS NOT NULL AND email <> '' LIMIT 1");
            $stmt->execute([$fiscal]);
            $email = trim((string)($stmt->fetchColumn() ?: ''));
            if (bde_valid_email($email)) return $email;
        } catch (Throwable $e) {}
    }

    $name = trim((string)($doc['client_name'] ?? ''));
    if ($name !== '') {
        try {
            $stmt = $pdo->prepare("SELECT email FROM clients WHERE name = ? AND email IS NOT NULL AND email <> '' LIMIT 1");
            $stmt->execute([$name]);
            $email = trim((string)($stmt->fetchColumn() ?: ''));
            if (bde_valid_email($email)) return $email;
        } catch (Throwable $e) {}
    }

    return '';
}

function bde_make_html(string $body): string
{
    return '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#111827;font-size:14px;line-height:1.6;">' . nl2br(bde_h($body)) . '</body></html>';
}

function bde_clean_email_body(string $body): string
{
    // Nu trimitem linkuri CRM/Oblio in email; documentul merge ca atasament PDF.
    $lines = preg_split('/\r\n|\r|\n/', $body);
    $clean = [];
    foreach ($lines ?: [] as $line) {
        $trim = trim((string)$line);
        if (preg_match('/^Link document (CRM|Oblio)\s*:/iu', $trim)) continue;
        if (preg_match('~https?://(app\.pestzone\.ro|www\.oblio\.eu)/~i', $trim)) continue;
        $clean[] = (string)$line;
    }
    $body = implode("\n", $clean);
    $body = preg_replace("/\n{3,}/", "\n\n", $body);
    return trim((string)$body);
}

function bde_send_invoice_email(string $to, string $cc, string $subject, string $body, array $attachments, int $docId): array
{
    if (!function_exists('pz_sendgrid_send_email')) {
        return ['ok' => false, 'error' => 'Functia pz_sendgrid_send_email nu exista. Verifica notification_lib.php.'];
    }

    $html = bde_make_html($body);
    $result = pz_sendgrid_send_email($to, $subject, $html, $body, $attachments, 'billing_document', $docId);

    if (empty($result['ok'])) {
        return [
            'ok' => false,
            'error' => 'SendGrid nu a trimis emailul: ' . ($result['error'] ?? $result['response'] ?? 'eroare necunoscuta'),
            'debug' => $result,
        ];
    }

    $ccSent = 0;
    foreach (bde_email_list($cc) as $ccEmail) {
        if (!bde_valid_email($ccEmail)) continue;
        $ccResult = pz_sendgrid_send_email($ccEmail, '[Copie] ' . $subject, $html, $body, $attachments, 'billing_document_cc', $docId);
        if (!empty($ccResult['ok'])) $ccSent++;
    }

    return [
        'ok' => true,
        'message' => 'Email trimis prin SendGrid' . ($attachments ? ' cu PDF atasat' : '') . ($ccSent ? ' + ' . $ccSent . ' copie CC.' : '.')
    ];
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$doc = $id > 0 ? bill_document($pdo, $id) : null;
if (!$doc) {
    http_response_code(404);
    exit('Document inexistent.');
}

$docLabel = bde_doc_label($doc);
$pdf = bde_pdf($pdo, $doc);
$pdfCrmUrl = bde_url('billing_document_pdf.php?id=' . (int)$doc['id']);
$oblioLink = bde_oblio_link($doc);
$clientName = trim((string)($doc['client_name'] ?? '')) ?: 'client';
$sg = bde_sendgrid_ready();

$defaultTo = bde_find_client_email($pdo, $doc);
$defaultSubject = $docLabel . ' - PestZone';
$defaultBody = "Buna ziua,\n\nVa transmitem atasat documentul " . $docLabel . ".\n\nVa multumim,\nPestZone";

$error = '';
$success = '';
$debug = '';
$to = $defaultTo;
$cc = '';
$subject = $defaultSubject;
$body = $defaultBody;
$attachPdf = $pdf ? 1 : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) csrf_require();

    $to = trim((string)($_POST['to'] ?? ''));
    $cc = trim((string)($_POST['cc'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body = bde_clean_email_body(trim((string)($_POST['body'] ?? '')));
    $attachPdf = !empty($_POST['attach_pdf']) ? 1 : 0;

    $badCc = bde_invalid_emails($cc);

    if (!$sg['ready']) {
        $error = 'SendGrid nu este pregatit pentru acest modul. Verifica API Key, email expeditor si cURL.';
    } elseif (!bde_valid_email($to)) {
        $error = 'Adresa destinatarului nu este valida.';
    } elseif ($badCc) {
        $error = 'Adrese CC invalide: ' . implode(', ', $badCc);
    } elseif ($subject === '') {
        $error = 'Completeaza subiectul.';
    } elseif ($body === '') {
        $error = 'Completeaza mesajul.';
    } else {
        $attachments = [];
        if ($attachPdf) {
            $pdf = bde_pdf($pdo, $doc);
            if (!$pdf) {
                $error = 'Nu am gasit PDF-ul pentru atasare. Apasa Sincronizeaza / vezi PDF CRM, apoi reincearca trimiterea.';
            } else {
                $attachments[] = $pdf;
            }
        }

        if ($error === '') {
            $res = bde_send_invoice_email($to, $cc, $subject, $body, $attachments, (int)$doc['id']);
            if (!empty($res['ok'])) {
                $success = $res['message'] ?? 'Email trimis.';
            } else {
                $error = $res['error'] ?? 'Emailul nu a putut fi trimis.';
                if (!empty($res['debug'])) $debug = print_r($res['debug'], true);
            }
        }
    }
}
?>
<!doctype html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Trimite email document - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.page{display:grid;gap:14px}.hero,.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow)}.hero{padding:18px 20px}.hero h1{margin:0 0 5px;font-size:25px;font-weight:650;letter-spacing:-.035em}.hero p{margin:0;color:var(--muted)}.card{overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid var(--border2);display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.card-head h2{margin:0;font-size:16px;font-weight:650}.card-body{padding:16px;display:grid;gap:14px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.form-row{display:grid;gap:5px}.form-row.full{grid-column:1/-1}label{color:var(--muted);font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.04em}input,textarea{width:100%;border:1px solid var(--border);border-radius:12px;padding:10px 11px;min-height:42px;background:#fff;color:var(--text);font:inherit;outline:none}textarea{min-height:210px;resize:vertical}.doc-box{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.doc-mini{border:1px solid var(--border2);border-radius:14px;padding:12px;background:var(--surface-soft)}.doc-mini span{display:block;color:var(--muted);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}.doc-mini strong{display:block;font-size:14px}.checkline{display:flex;align-items:center;gap:8px;font-weight:700;color:var(--text);text-transform:none;letter-spacing:0;font-size:13px}.checkline input{width:auto;min-height:auto}.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.notice{margin:0}.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:14px;padding:12px 14px}.okline{background:#ecfdf5;border:1px solid #bbf7d0;color:#047857;border-radius:14px;padding:12px 14px}.debug{white-space:pre-wrap;background:#0f172a;color:#e5e7eb;padding:12px;border-radius:12px;overflow:auto;font-size:12px}@media(max-width:900px){.form-grid,.doc-box{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('billing_documents', true); ?>
<main class="main">
<div class="topbar"><strong>Trimite email document</strong></div>
<div class="content page">

<section class="hero">
<h1>Trimite document pe email</h1>
<p>Document: <?= bde_h($docLabel) ?> pentru <?= bde_h($clientName) ?></p>
</section>

<?php if (!$sg['ready']): ?>
<div class="warn"><strong>SendGrid nu este complet pregatit.</strong><br>Cheie: <?= $sg['has_key'] ? 'OK' : 'lipsa' ?> | Expeditor: <?= bde_h($sg['from_email'] ?: 'lipsa') ?> | cURL: <?= $sg['curl'] ? 'OK' : 'lipsa' ?> | librarie: <?= $sg['lib'] ? 'OK' : 'lipsa' ?>. Verifica Setari &gt; Comunicare / Integrari.</div>
<?php else: ?>
<div class="okline"><strong>SendGrid este configurat.</strong> Expeditor: <?= bde_h($sg['from_email']) ?>. Trimiterea foloseste aceeasi functie ca emailul de test din CRM.</div>
<?php endif; ?>

<?php if ($success): ?><div class="notice notice-success"><?= bde_h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-danger"><?= bde_h($error) ?></div><?php endif; ?>
<?php if ($debug): ?><div class="debug"><?= bde_h($debug) ?></div><?php endif; ?>

<section class="card">
<div class="card-head"><h2>Detalii document</h2><a class="btn" href="billing_documents.php">Inapoi la documente</a></div>
<div class="card-body">
    <div class="doc-box">
        <div class="doc-mini"><span>Document</span><strong><?= bde_h($docLabel) ?></strong></div>
        <div class="doc-mini"><span>Client</span><strong><?= bde_h($clientName) ?></strong></div>
        <div class="doc-mini"><span>Total</span><strong><?= bill_money($doc['total'] ?? 0, $doc['currency'] ?? 'RON') ?></strong></div>
        <div class="doc-mini"><span>PDF atasabil</span><strong><?= $pdf ? 'Disponibil' : 'Indisponibil' ?></strong></div>
    </div>
</div>
</section>

<section class="card">
<div class="card-head"><h2>Email</h2></div>
<form method="post" class="card-body">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
    <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">

    <div class="form-grid">
        <div class="form-row">
            <label>Catre</label>
            <input type="email" name="to" value="<?= bde_h($to) ?>" placeholder="client@email.ro" required>
        </div>
        <div class="form-row">
            <label>CC optional</label>
            <input type="text" name="cc" value="<?= bde_h($cc) ?>" placeholder="email1@firma.ro, email2@firma.ro">
        </div>
        <div class="form-row full">
            <label>Subiect</label>
            <input type="text" name="subject" value="<?= bde_h($subject) ?>" required>
        </div>
        <div class="form-row full">
            <label>Mesaj</label>
            <textarea name="body" required><?= bde_h($body) ?></textarea>
            <small style="color:var(--muted);font-weight:600;">PDF-ul se trimite ca atasament. Linkurile CRM/Oblio nu sunt incluse in email.</small>
        </div>
        <div class="form-row full">
            <label class="checkline">
                <input type="checkbox" name="attach_pdf" value="1" <?= $attachPdf && $pdf ? 'checked' : '' ?> <?= !$pdf ? 'disabled' : '' ?>>
                Ataseaza PDF factura/proforma <?= !$pdf ? '(PDF indisponibil momentan)' : '' ?>
            </label>
        </div>
    </div>

    <div class="actions">
        <button class="btn accent" type="submit" <?= !$sg['ready'] ? 'disabled' : '' ?>>Trimite email</button>
        <?php if (!empty($doc['pdf_path'])): ?><a class="btn" target="_blank" href="billing_documents.php?pdf=<?= (int)$doc['id'] ?>">Vezi PDF local</a><?php endif; ?>
        <a class="btn" target="_blank" href="billing_document_pdf.php?id=<?= (int)$doc['id'] ?>&sync=1">Sincronizeaza / vezi PDF CRM</a>
        <a class="btn" href="communication_settings.php">Setari SendGrid</a>
    </div>
</form>
</section>

</div>
</main>
</div>
</body>
</html>
