<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/document_core.php';
require_once __DIR__ . '/document_tokens.php';
require_once __DIR__ . '/document_access.php';
require_once __DIR__ . '/document_engine.php';
if (file_exists(__DIR__ . '/lib/contract_flow_lib.php')) {
    require_once __DIR__ . '/lib/contract_flow_lib.php';
}

pzdoc_require_schema($pdo);

function dview_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dview_doc_id(): int
{
    return max(0, (int)($_GET['id'] ?? $_POST['document_id'] ?? 0));
}

function dview_type_active_key(string $type): string
{
    $type = pzdoc_normalize_document_type($type);
    if ($type === 'contract') {
        return 'contracts';
    }
    if ($type === 'proces_verbal') {
        return 'procese_verbale';
    }
    return 'oferte';
}

function dview_back_url(string $type): string
{
    $type = pzdoc_normalize_document_type($type);
    if ($type === 'contract') {
        return 'contracts.php';
    }
    if ($type === 'proces_verbal') {
        return 'service-reports';
    }
    return 'offers';
}

function dview_edit_url(array $document): string
{
    $id = (int)($document['id'] ?? 0);
    $type = pzdoc_normalize_document_type((string)($document['document_type'] ?? 'oferta'));
    if ($type === 'contract') {
        return 'contracts.php?edit=' . $id;
    }
    if ($type === 'proces_verbal') {
        return 'service-reports?edit=' . $id;
    }
    return 'offers?edit=' . $id;
}


function dview_print_cache_key(?array $document, ?array $template = null): string
{
    $parts = [];
    if ($document) {
        foreach (['updated_at', 'issued_at', 'created_at', 'document_date', 'document_time'] as $key) {
            if (!empty($document[$key])) {
                $parts[] = (string)$document[$key];
            }
        }
        $parts[] = (string)($document['id'] ?? '');
        $parts[] = (string)($document['document_number'] ?? '');
        $parts[] = (string)($document['status'] ?? '');
        $parts[] = (string)($document['apply_company_stamp'] ?? '');
    }
    if ($template) {
        foreach (['updated_at', 'created_at', 'id'] as $key) {
            if (!empty($template[$key])) {
                $parts[] = (string)$template[$key];
            }
        }
    }
    $parts[] = (string)time(); // garanteaza pagina proaspata după refresh, fara cache vechi in browser
    return substr(sha1(implode('|', $parts)), 0, 12);
}

function dview_print_url(array $document, string $cacheKey, bool $autoPrint = false, bool $embed = false): string
{
    $id = (int)($document['id'] ?? 0);
    $url = 'document_print.php?id=' . $id . '&v=' . rawurlencode($cacheKey);
    if ($autoPrint) {
        $url .= '&print=1';
    }
    if ($embed) {
        $url .= '&embed=1';
    }
    return $url;
}

function dview_status_class(string $status): string
{
    if ($status === 'issued') {
        return 'issued';
    }
    if ($status === 'cancelled') {
        return 'cancelled';
    }
    return 'draft';
}

function dview_money($value, string $currency = 'RON'): string
{
    return number_format((float)($value ?? 0), 2, ',', '.') . ' ' . dview_h($currency ?: 'RON');
}

function dview_date_ro($value): string
{
    if (!$value) {
        return '-';
    }
    $ts = strtotime((string)$value);
    return $ts ? date('d.m.Y', $ts) : dview_h($value);
}

function dview_time_ro($value): string
{
    if (!$value) {
        return '-';
    }
    $ts = strtotime((string)$value);
    return $ts ? date('H:i', $ts) : dview_h($value);
}

function dview_count_items(array $document): int
{
    return count($document['items'] ?? []);
}

function dview_count_materials(array $document): int
{
    return count($document['materials'] ?? []);
}

function dview_email_info(array $document): string
{
    $count = (int)($document['email_sent_count'] ?? 0);
    if ($count <= 0) {
        return 'netrimis';
    }

    $to = trim((string)($document['email_sent_to'] ?? ''));
    $date = trim((string)($document['email_sent_at'] ?? ''));
    $text = 'trimis de ' . $count . ' ori';
    if ($to !== '') {
        $text .= ' catre ' . $to;
    }
    if ($date !== '') {
        $ts = strtotime($date);
        $text .= ' la ' . ($ts ? date('d.m.Y H:i', $ts) : $date);
    }
    return $text;
}

function dview_is_valid_email(string $email): bool
{
    $email = trim($email);
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function dview_resolve_client_email(PDO $pdo, array $document): string
{
    $snapshot = trim((string)($document['client_email_snapshot'] ?? ''));
    if (dview_is_valid_email($snapshot)) {
        return $snapshot;
    }

    $clientId = (int)($document['client_id'] ?? 0);
    if ($clientId <= 0) {
        return '';
    }

    try {
        $stmt = $pdo->prepare("SELECT email FROM clients WHERE id = ? LIMIT 1");
        $stmt->execute([$clientId]);
        $live = trim((string)$stmt->fetchColumn());
        if (!dview_is_valid_email($live)) {
            return '';
        }
        try {
            $upd = $pdo->prepare("UPDATE documents SET client_email_snapshot = ? WHERE id = ?");
            $upd->execute([$live, (int)($document['id'] ?? 0)]);
        } catch (Throwable $e) {
            error_log('Emma document_view email snapshot update error: ' . $e->getMessage());
        }
        return $live;
    } catch (Throwable $e) {
        error_log('Emma document_view resolve client email error: ' . $e->getMessage());
        return '';
    }
}


function dview_safe_relative_path($path): string
{
    $path = str_replace('\\', '/', trim((string)($path ?? '')));
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false || preg_match('#(^|/)[.](/|$)#', $path)) {
        return '';
    }
    return $path;
}

function dview_payload(array $document): array
{
    if (is_array($document['payload'] ?? null)) {
        return $document['payload'];
    }
    if (function_exists('pzdoc_json_decode')) {
        return pzdoc_json_decode($document['payload_json'] ?? null);
    }
    $decoded = json_decode((string)($document['payload_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function dview_signature_path(array $document): string
{
    $payload = dview_payload($document);
    $path = dview_safe_relative_path($payload['client_signature_path'] ?? '');
    if ($path === '') {
        return '';
    }
    return is_file(__DIR__ . '/' . $path) ? $path : '';
}

function dview_signature_saved_at(array $document): string
{
    $payload = dview_payload($document);
    $value = trim((string)($payload['client_signature_at'] ?? ''));
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('d.m.Y H:i', $ts) : $value;
}

function dview_document_appointment_id(array $document): int
{
    $appointmentId = (int)($document['appointment_id'] ?? 0);
    if ($appointmentId > 0) {
        return $appointmentId;
    }
    $payload = dview_payload($document);
    return (int)($payload['appointment_id'] ?? 0);
}

$documentId = dview_doc_id();
$templateId = !empty($_GET['template_id']) ? (int)$_GET['template_id'] : null;
$error = '';
$success = '';

try {
    if ($documentId <= 0) {
        throw new RuntimeException('Documentul nu a fost specificat.');
    }

    if (isset($_GET['pdf'])) {
        $download = (string)($_GET['pdf'] ?? '') === 'download';
        pzdoc_pdf_stream_document($pdo, $documentId, $templateId, $download);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $action = (string)($_POST['action'] ?? '');
        $actionDocument = pzdoc_get_document($pdo, $documentId, false);
        if (!$actionDocument || !pzdoc_user_can_access_document($pdo, $actionDocument)) {
            throw new RuntimeException('Nu ai drepturi pentru aceasta actiune.');
        }

        if ($action === 'issue') {
            $issuedDate = trim((string)($_POST['issued_date'] ?? ''));
            pzdoc_issue_document($pdo, $documentId, [
                'issued_date' => $issuedDate !== '' ? $issuedDate : date('Y-m-d'),
            ]);
            if (function_exists('pz_flow_sync_issued_contract')) {
                $issuedDocument = pzdoc_get_document($pdo, $documentId, false);
                if ($issuedDocument && ($issuedDocument['document_type'] ?? '') === 'contract') {
                    pz_flow_sync_issued_contract($pdo, $documentId, true);
                    $syncStats = function_exists('pz_flow_last_sync_stats') ? pz_flow_last_sync_stats() : [];
                    if ((int)($syncStats['items'] ?? 0) > 0 && (int)($syncStats['tasks'] ?? 0) <= 0) {
                        throw new RuntimeException('Contractul a fost emis, dar nu s-au generat sarcini. Verifica dacă serviciile din contract au locație si serviciu completat.');
                    }
                }
            }
            header('Location: document_view.php?id=' . $documentId . '&ok=issued&contract_sync=1');
            exit;
        }

        if ($action === 'delete_draft') {
            if (!is_admin()) {
                throw new RuntimeException('Actiune disponibila doar pentru administrator.');
            }
            $document = pzdoc_get_document($pdo, $documentId, false);
            $backUrl = $document ? dview_back_url((string)$document['document_type']) : 'dashboard.php';
            pzdoc_delete_draft($pdo, $documentId);
            header('Location: ' . $backUrl . '?ok=deleted');
            exit;
        }

        if ($action === 'cancel') {
            if (!is_admin()) {
                throw new RuntimeException('Actiune disponibila doar pentru administrator.');
            }
            $reason = trim((string)($_POST['cancel_reason'] ?? ''));
            pzdoc_cancel_document($pdo, $documentId, $reason !== '' ? $reason : null);
            header('Location: document_view.php?id=' . $documentId . '&ok=cancelled');
            exit;
        }

        if ($action === 'toggle_stamp') {
            if (!is_admin()) {
                throw new RuntimeException('Doar administratorul poate adauga / scoate ștampila pe document.');
            }
            $stampType = pzdoc_normalize_document_type((string)($actionDocument['document_type'] ?? ''));
            if (!in_array($stampType, ['oferta', 'contract', 'proces_verbal'], true)) {
                throw new RuntimeException('Ștampila se aplica doar pe oferte, contracte si procese verbale.');
            }
            $newValue = empty($actionDocument['apply_company_stamp']) ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE documents SET apply_company_stamp = ? WHERE id = ?");
            $stmt->execute([$newValue, $documentId]);
            header('Location: document_view.php?id=' . $documentId . '&ok=' . ($newValue ? 'stamp_on' : 'stamp_off'));
            exit;
        }
    }

    if (!empty($_GET['ok'])) {
        $ok = (string)$_GET['ok'];
        if ($ok === 'issued') {
            $success = 'Documentul a fost emis si blocat.';
            if (!empty($_GET['contract_sync'])) {
                $success .= ' Sarcinile din contract au fost verificate.';
            }
        } elseif ($ok === 'cancelled') {
            $success = 'Documentul a fost anulat.';
        } elseif ($ok === 'email') {
            $success = 'Emailul a fost trimis.';
        } elseif ($ok === 'stamp_on') {
            $success = 'Ștampila firmei a fost aplicata pe document. pagina de print se va actualiza cu ștampila.';
        } elseif ($ok === 'stamp_off') {
            $success = 'Ștampila firmei a fost scoasa de pe document.';
        } elseif ($ok === 'signature') {
            // Confirmarea este afișata in cardul de semnătura; evitam mesaj dublat.
        }
    }

    $preview = pzdoc_render_document_preview($pdo, $documentId, $templateId);
    $document = $preview['document'];
    if (!$document || !pzdoc_user_can_access_document($pdo, $document)) {
        throw new RuntimeException('Nu ai drepturi pentru accesarea documentului.');
    }
    if (function_exists('pz_flow_sync_issued_contract') && ($document['document_type'] ?? '') === 'contract' && ($document['status'] ?? '') === 'issued') {
        try {
            pz_flow_sync_issued_contract($pdo, $documentId);
        } catch (Throwable $flowErr) {
            error_log('Emma contract flow sync on view error: ' . $flowErr->getMessage());
        }
    }
    $template = $preview['template'];
    try { $previewHtml = pzdoc_engine_preview_html($pdo, $documentId); } catch (Throwable $pvErr) { $previewHtml = '<p style="padding:20px;color:#b91c1c;font-family:Arial;">Eroare preview: ' . dview_h($pvErr->getMessage()) . '</p>'; }
} catch (Throwable $e) {
    $document = null;
    $template = null;
    $previewHtml = '';
    $error = $e->getMessage();
}

$type = $document ? pzdoc_normalize_document_type((string)$document['document_type']) : 'oferta';
$status = $document ? (string)($document['status'] ?? 'draft') : 'draft';
$isDraft = $status === 'draft';
$isIssued = $status === 'issued';
$isCancelled = $status === 'cancelled';
$activeKey = $document ? dview_type_active_key($type) : 'oferte';
$backUrl = $document ? dview_back_url($type) : 'dashboard.php';
$editUrl = $document ? dview_edit_url($document) : '#';
$currency = $document ? (string)($document['currency'] ?? 'RON') : 'RON';
$clientEmail = $document ? dview_resolve_client_email($pdo, $document) : '';
$hasClientEmail = dview_is_valid_email($clientEmail);
$isAdmin = is_admin();
$isTeamUser = is_team_user();
$signaturePath = $document ? dview_signature_path($document) : '';
$documentPayload = $document ? dview_payload($document) : [];
$stockConsumptionDeferred = $document && ($type === 'proces_verbal') && (($documentPayload['stock_consumption_deferred'] ?? '') === '1');
$hasClientSignature = $signaturePath !== '';
$signatureSavedAt = $document ? dview_signature_saved_at($document) : '';
$appointmentIdForSignature = $document ? dview_document_appointment_id($document) : 0;
$pvIssuedByOffice = $document && $isTeamUser && $type === 'proces_verbal' && $isIssued && (int)($document['issued_by'] ?? 0) > 0;
$canClientSign = false;
if ($document && $isTeamUser && $type === 'proces_verbal' && $isIssued && !$pvIssuedByOffice && $appointmentIdForSignature > 0) {
    $canClientSign = pzdoc_user_can_access_appointment_for_pv($pdo, $appointmentIdForSignature, true);
}
$teamEmailBlockedBySignature = $isTeamUser && $type === 'proces_verbal' && $isIssued && !$pvIssuedByOffice && !$hasClientSignature;
$printCacheKey = $document ? dview_print_cache_key($document, $template) : (string)time();
if ($isTeamUser && $type === 'proces_verbal') {
    $backUrl = 'calendar.php';
}
$fieldPvCompact = (!$isAdmin && $isTeamUser && $type === 'proces_verbal');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Vizualizare document - <?= h(pz_app_name()) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.pz-kicker-inline { font-size: 11px; font-weight: 600; color: var(--pz-mu); letter-spacing: .08em; text-transform: uppercase; margin: 0 0 8px; line-height: 1; }
.document-page { display: grid; gap: 14px; }
.document-topbar { width: 100% !important; padding: 8px 14px !important; display: flex !important; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
.document-toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.document-hero,
.document-card,
.document-alert { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow); }
.document-hero { padding: 18px 20px; display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; }
.document-hero h1 { margin: 0 0 6px 0; font-size: 25px; line-height: 1.1; letter-spacing: -0.035em; }
.document-hero p { margin: 0; color: var(--muted); font-size: 13px; }
.document-alert { padding: 12px 14px; font-weight: 750; }
.document-alert.success { border-color: rgba(31,111,84,.22); background: var(--success-soft); color: var(--success); }
.document-alert.error { border-color: rgba(180,35,24,.22); background: var(--danger-soft); color: var(--danger); }
.document-alert.warning { border-color: rgba(154,103,0,.22); background: var(--warning-soft); color: var(--warning); }
.meta-grid { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)); gap: 10px; }
.meta-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 13px; box-shadow: var(--shadow); min-width: 0; }
.meta-label { color: var(--muted); font-size: 11px; font-weight: 850; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px; }
.meta-value { font-size: 14px; font-weight: 850; color: var(--text); overflow-wrap: anywhere; }
.badge { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 6px 10px; font-size: 11px; font-weight: 900; border: 1px solid var(--border2); background: var(--surface-soft); color: var(--muted); white-space: nowrap; }
.badge.draft { background: var(--warning-soft); color: var(--warning); border-color: rgba(154,103,0,.18); }
.badge.issued { background: var(--success-soft); color: var(--success); border-color: rgba(31,111,84,.18); }
.badge.cancelled { background: var(--danger-soft); color: var(--danger); border-color: rgba(180,35,24,.18); }
.badge.email { background: var(--accent-soft); color: var(--accent-deep); border-color: rgba(30,91,140,.18); }
.actions-card { padding: 14px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.actions-card form { margin: 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.actions-card input[type="date"],
.actions-card input[type="text"] { border: 1px solid var(--border); border-radius: 12px; padding: 9px 10px; min-height: 38px; background: #fff; color: var(--text); }
.preview-card { overflow: hidden; }
.preview-head { padding: 13px 16px; border-bottom: 1px solid var(--border2); display: flex; justify-content: space-between; gap: 10px; align-items: center; flex-wrap: wrap; }
.preview-head h2 { margin: 0; font-size: 16px; font-weight: 900; }
.preview-head p { margin: 2px 0 0; color: var(--muted); font-size: 12px; }
.preview-body { padding: 14px; }
.summary-list { display: grid; gap: 7px; color: var(--muted); font-size: 12.5px; }
.summary-list strong { color: var(--text); }
.link-muted { color: var(--accent); font-weight: 800; }
.btn.danger { background: var(--danger); border-color: var(--danger); color: #fff; }
.btn.warning { background: var(--warning); border-color: var(--warning); color: #fff; }
.btn.disabled,
button.btn:disabled { opacity: .45; pointer-events: none; }
.signature-card { padding: 14px; display: grid; gap: 12px; }
.signature-title { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; }
.signature-title h2 { margin: 0; font-size: 16px; font-weight: 950; }
.signature-title p { margin: 3px 0 0; color: var(--muted); font-size: 12.5px; }
.signature-pad-wrap { border: 1px solid var(--border2); border-radius: 16px; background: #fff; padding: 8px; width: min(100%, 420px); aspect-ratio: 1 / 1; }
.signature-pad-wrap:focus-within { border-color: #1d4ed8; box-shadow: 0 0 0 4px rgba(29,78,216,.16); }
.signature-pad { width: 100%; height: 100%; display: block; background: #fff; border-radius: 12px; touch-action: none; outline: none; }
.signature-pad:focus { box-shadow: inset 0 0 0 2px rgba(29,78,216,.38); }
.signature-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.signature-existing { display: grid; gap: 8px; }
.signature-existing img { display: block; max-width: 260px; max-height: 110px; background: #fff; border: 1px solid var(--border2); border-radius: 14px; padding: 8px; }
.signature-help { font-size: 12px; color: var(--muted); }
.signature-pad-section, .signature-saved-section { display: grid; gap: 10px; }
.signature-actions { display: grid; gap: 8px; }
.signature-actions-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
/* Toate butoanele din cardul de semnătură au aceeași înălțime, full width pe row-ul lor. */
.signature-action-btn { min-height: 48px !important; font-size: 14px; font-weight: 800; width: 100%; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; }
.primary-email-cta { box-shadow: 0 4px 12px rgba(37, 99, 235, .22); }
/* Buton „Calendar" — albastru distinct, link spre pagina de calendar. */
.signature-calendar-btn { background: #2563EB !important; border-color: #2563EB !important; color: #FFF !important; }
.signature-calendar-btn:hover { background: #1D4ED8 !important; border-color: #1D4ED8 !important; color: #FFF !important; }
.signature-feedback { padding: 10px 12px; border-radius: 12px; font-size: 13px; font-weight: 700; margin-top: 4px; display: flex; align-items: center; gap: 8px; }
.signature-feedback.success { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
.signature-feedback.error { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
.signature-feedback.info { background: var(--pz-bls, #EFF6FF); color: var(--pz-bld, #1E40AF); border: 1px solid var(--pz-blb, #BFDBFE); }

.print-preview-frame { width: 100%; min-height: 820px; border: 0; border-radius: 18px; background: #eef2f7; display:block; }
.field-compact .document-hero { padding: 16px; }
.field-compact .document-hero h1 { font-size: 22px; }
.field-compact .document-hero p { font-size: 12.5px; line-height: 1.35; }
.field-compact .signature-card { padding: 12px; gap: 10px; }
.field-compact .signature-title h2 { font-size: 15px; }
.field-compact .signature-title p,
.field-compact .signature-help { display: none; }
.field-compact .signature-pad-wrap {
    width: 100%;
    max-width: 100%;
    margin: 0 auto;
    border-radius: 14px;
    /* Compact: mai lat decât înalt — semnătura e naturală orizontal */
    aspect-ratio: 16 / 9;
    max-height: 220px;
}
.field-compact .signature-existing img { max-width: 120px; max-height: 120px; width: 38mm; height: 38mm; object-fit: contain; }
.field-compact .signature-actions { display: grid; grid-template-columns: 1fr; gap: 8px; }
.field-compact .signature-actions .btn { width: 100%; justify-content: center; }
@media(max-width: 860px) { .print-preview-frame { min-height: 620px; } }
@media(max-width: 1050px) { .meta-grid { grid-template-columns: repeat(2, minmax(150px, 1fr)); } }
@media(max-width: 860px) {
    .document-topbar { display: block !important; padding: 8px 10px 14px 10px !important; }
    .document-toolbar { display: grid !important; grid-template-columns: 1fr !important; gap: 8px !important; }
    .document-toolbar .btn,
    .document-toolbar form,
    .document-toolbar button { width: 100% !important; }
    .document-hero { display: grid; padding: 17px; }
    .document-hero h1 { font-size: 22px; }
    .meta-grid { grid-template-columns: 1fr; }
    .actions-card { display: grid; }
    .actions-card .btn,
    .actions-card form,
    .actions-card button,
    .actions-card input { width: 100%; }
}
/* DS v2.4 */
.actions-card input[type="date"],.actions-card input[type="text"] { border-radius:var(--pz-rs) !important; }
.signature-pad-wrap,.signature-existing img { border-radius:var(--pz-r) !important; }
.print-preview-frame { border-radius:var(--pz-r) !important; }
.badge { border-radius:var(--pz-rs) !important; font-weight:600 !important; }
.badge.draft    { background:var(--pz-ors) !important; color:var(--pz-or) !important; border-color:var(--pz-orb) !important; }
.badge.issued   { background:var(--pz-grs) !important; color:var(--pz-gr) !important; border-color:var(--pz-grb) !important; }
.badge.cancelled{ background:var(--pz-res) !important; color:var(--pz-re) !important; border-color:var(--pz-reb) !important; }
.badge.email    { background:var(--pz-bls) !important; color:var(--pz-bld) !important; border-color:var(--pz-blb) !important; }
.document-alert.success { background:var(--pz-grs) !important; color:var(--pz-gr) !important; }
.document-alert.error   { background:var(--pz-res) !important; color:var(--pz-re) !important; }
.document-alert.warning { background:var(--pz-ors) !important; color:var(--pz-or) !important; }
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar($activeKey, $isAdmin); ?>

    <main class="main">
        <div class="topbar document-topbar">
            <?php if ($fieldPvCompact): ?>
                <?php if ($canClientSign): ?>
                    <!-- Topbar gol pe team mode când există cardul de semnătură: toate acțiunile sunt în card jos. -->
                <?php else: ?>
                    <!-- Fallback când nu există cardul de semnătură (ex: PV emis de birou, sau tehnicianul nu poate semna). -->
                    <div class="document-toolbar">
                        <a class="btn" href="<?= dview_h($backUrl) ?>">Înapoi la lista</a>
                    </div>
                    <?php if ($document): ?>
                        <div class="document-toolbar">
                            <a class="btn accent" target="_blank" href="document_pdf.php?id=<?= (int)$document['id'] ?>&mode=download">Descarcă PDF</a>
                            <?php if ($isIssued && ($hasClientSignature || $pvIssuedByOffice)): ?>
                                <?php if ($hasClientEmail): ?>
                                    <button class="btn accent" type="button" onclick="sendQuickDocumentEmail(<?= (int)$document['id'] ?>, this, '<?= dview_h($clientEmail) ?>')">Trimite email</button>
                                <?php else: ?>
                                    <span class="btn disabled">Email lipsa</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <div class="document-toolbar">
                    <a class="btn" href="<?= dview_h($backUrl) ?>">Înapoi la lista</a>
                    <?php if ($document && $isDraft): ?>
                        <a class="btn" href="<?= dview_h($editUrl) ?>">Editează</a>
                    <?php elseif ($document): ?>
                        <span class="btn disabled">Document blocat</span>
                    <?php endif; ?>
                </div>

                <?php if ($document): ?>
                    <div class="document-toolbar">
                        <a class="btn accent" target="_blank" href="document_pdf.php?id=<?= (int)$document['id'] ?>&mode=download">Descarcă PDF</a>
                        <?php if ($isAdmin && in_array($type, ['oferta', 'contract', 'proces_verbal'], true)): ?>
                            <?php $hasStamp = !empty($document['apply_company_stamp']); ?>
                            <form method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_stamp">
                                <?php if ($hasStamp): ?>
                                    <button class="btn" type="submit" title="Scoate ștampila firmei de pe acest document">Scoate ștampila</button>
                                <?php else: ?>
                                    <button class="btn accent" type="submit" title="Aplica ștampila firmei pe acest document">Adaugă ștampila</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                        <?php if ($isIssued): ?>
                            <?php if (!$hasClientEmail): ?>
                                <?php if ($isAdmin): ?>
                                    <a class="btn" href="document_send_email.php?id=<?= (int)$document['id'] ?>" title="Clientul nu are email salvat - completeaza din pagina de email">Trimite email (fara destinatar)</a>
                                <?php else: ?>
                                    <span class="btn disabled">Email lipsa</span>
                                <?php endif; ?>
                            <?php elseif (!$isAdmin && $teamEmailBlockedBySignature): ?>
                                <span class="btn disabled">Email după semnătura</span>
                            <?php else: ?>
                                <button class="btn accent" type="button" onclick="sendQuickDocumentEmail(<?= (int)$document['id'] ?>, this, '<?= dview_h($clientEmail) ?>')">Trimite email</button>
                                <?php if ($isAdmin): ?>
                                    <a class="link-muted" href="document_send_email.php?id=<?= (int)$document['id'] ?>" style="font-size:12px;align-self:center;text-decoration:none;">Editează &rarr;</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php elseif ($isDraft): ?>
                            <span class="btn disabled">Email după emitere</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="content document-page<?= $fieldPvCompact ? ' field-compact' : '' ?>">
            <?php if ($success): ?>
                <div class="document-alert success"><?= dview_h($success) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="document-alert error">Eroare: <?= dview_h($error) ?></div>
            <?php endif; ?>

            <?php if (!$document): ?>
                <p class="pz-kicker-inline">DOCUMENTE</p>
                <section class="document-hero">
                    <div>
                        <h1>Document negasit</h1>
                        <p>Verifica linkul sau intoarce-te in lista de documente.</p>
                    </div>
                </section>
            <?php else: ?>
                <p class="pz-kicker-inline">DOCUMENTE</p>
                <section class="document-hero">
                    <div>
                        <h1><?= dview_h(pzdoc_document_type_label($type)) ?> <?= dview_h($document['document_number'] ?: 'Draft') ?></h1>
                        <?php if ($fieldPvCompact): ?>
                            <p><?= dview_h($document['client_name_snapshot'] ?: '-') ?><?= !empty($document['location_name_snapshot']) ? ' - ' . dview_h($document['location_name_snapshot']) : '' ?><?= !empty($document['document_date']) ? ' - ' . dview_date_ro($document['document_date']) : '' ?><?= !empty($document['document_time']) ? ' / ' . dview_time_ro($document['document_time']) : '' ?></p>
                        <?php else: ?>
                            <p><?= dview_h($document['title'] ?? 'Document') ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="badge <?= dview_h(dview_status_class($status)) ?>"><?= dview_h(pzdoc_status_label($status)) ?></span>
                </section>

                <?php if (!$fieldPvCompact): ?>
                    <?php if ($isDraft): ?>
                        <div class="document-alert warning">
                            Documentul este in draft. Numarul se aloca doar cand apesi Emitere document.
                        </div>
                    <?php elseif ($isIssued): ?>
                        <div class="document-alert success">
                            Document emis si blocat. Pentru modificari importante, creeaza un document nou sau anuleaza documentul emis.
                        </div>
                    <?php elseif ($isCancelled): ?>
                        <div class="document-alert error">
                            Document anulat. Numarul rămâne in registru ca anulat.
                        </div>
                    <?php endif; ?>
                    <?php if ($stockConsumptionDeferred): ?>
                        <div class="document-alert warning">
                            Consum stoc neînchis. PV-ul este emis, dar cantitatea nu a fost scăzută din gestiune și nu apare în registrul de consum.
                            <?php if (function_exists('is_admin') && is_admin()): ?>
                                <a href="stock_deferred_pvs.php?id=<?= (int)$document['id'] ?>" style="font-weight:700;text-decoration:underline;margin-left:6px;">Finalizează consumul acum →</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$fieldPvCompact): ?>
                <section class="meta-grid">
                    <div class="meta-card">
                        <div class="meta-label">Client</div>
                        <div class="meta-value"><?= dview_h($document['client_name_snapshot'] ?: '-') ?></div>
                    </div>
                    <div class="meta-card">
                        <div class="meta-label">Locație</div>
                        <div class="meta-value"><?= dview_h($document['location_name_snapshot'] ?: '-') ?></div>
                    </div>
                    <div class="meta-card">
                        <div class="meta-label">Data / ora</div>
                        <div class="meta-value"><?= dview_date_ro($document['document_date'] ?? null) ?><?= !empty($document['document_time']) ? ' / ' . dview_time_ro($document['document_time']) : '' ?></div>
                    </div>
                    <div class="meta-card">
                        <div class="meta-label"><?= $type === 'oferta' ? 'Total fără TVA' : 'Total' ?></div>
                        <div class="meta-value"><?= dview_money($document['total_amount'] ?? 0, $currency) ?><?= $type === 'oferta' ? ' fără TVA' : '' ?></div>
                    </div>
                </section>

                <section class="document-card actions-card">
                    <div class="summary-list">
                        <div><strong>Șablon:</strong> <?= dview_h($template['name'] ?? 'Șablon implicit') ?></div>
                        <div><strong>Linii servicii:</strong> <?= (int)dview_count_items($document) ?> | <strong>Materiale / biocide:</strong> <?= (int)dview_count_materials($document) ?></div>
                        <div><strong>Creat:</strong> <?= dview_date_ro($document['created_at'] ?? null) ?> | <strong>Emis:</strong> <?= !empty($document['issued_at']) ? dview_date_ro($document['issued_at']) : '-' ?></div>
                        <div><strong>Email:</strong> <?= dview_h(dview_email_info($document)) ?></div>
                    </div>

                    <div class="document-toolbar">
                        <?php if ($isDraft): ?>
                            <form method="post" onsubmit="return confirm('Emiti documentul? După emitere va primi numar si va fi blocat.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="issue">
                                <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                                <input type="date" name="issued_date" value="<?= dview_h(date('Y-m-d')) ?>">
                                <button class="btn accent" type="submit">Emite document</button>
                            </form>

                            <?php if ($isAdmin): ?>
                                <form method="post" onsubmit="return confirm('Stergi definitiv draftul?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_draft">
                                    <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                                    <button class="btn danger" type="submit">Șterge draft</button>
                                </form>
                            <?php endif; ?>
                        <?php elseif ($isIssued && $isAdmin): ?>
                            <form method="post" onsubmit="return confirm('Anulezi documentul emis? Numarul va rămâne in registru ca anulat.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                                <input type="text" name="cancel_reason" placeholder="Motiv anulare (opțional)">
                                <button class="btn danger" type="submit">Anulează documentul</button>
                            </form>
                        <?php else: ?>
                            <span class="badge cancelled">Fara actiuni disponibile</span>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($canClientSign): ?>
                    <?php
                        // Butonul „Trimite PV pe email" din card e disponibil dacă tehnicianul
                        // are email valid pentru client. Vizibilitatea reală e controlată de
                        // savedSection (vizibil doar după save semnătură).
                        $emailFromCardEnabled = $hasClientEmail;
                    ?>
                    <section class="document-card signature-card" id="clientSignatureCard">
                        <div class="signature-title">
                            <div>
                                <h2>Semnătura beneficiar</h2>
                                <?php if (!$fieldPvCompact): ?>
                                    <p>Semnătura se salveaza doar din modul Angajat / Teren si intra automat in PDF.</p>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= $hasClientSignature ? 'issued' : 'draft' ?>" id="signatureStatusBadge">
                                <?php if ($hasClientSignature): ?>Semnătura salvata<?= $signatureSavedAt ? ' - ' . dview_h($signatureSavedAt) : '' ?><?php else: ?>Nesemnat<?php endif; ?>
                            </span>
                        </div>

                        <!-- Pad de semnare: vizibil când nu există semnătură, sau dacă utilizatorul face „Refă" -->
                        <div class="signature-pad-section" id="signaturePadSection" style="display:<?= $hasClientSignature ? 'none' : 'block' ?>;">
                            <?php if (!$fieldPvCompact): ?>
                                <div class="signature-help">Clientul semneaza cu degetul in chenarul de mai jos.</div>
                            <?php endif; ?>
                            <div class="signature-pad-wrap">
                                <canvas id="clientSignaturePad" class="signature-pad" tabindex="0" aria-label="Semnătura client"></canvas>
                            </div>
                            <div class="signature-actions signature-actions-row">
                                <button class="btn signature-action-btn" type="button" id="clearClientSignature">Șterge</button>
                                <button class="btn accent signature-action-btn" type="button" id="saveClientSignature">Salvează semnătura</button>
                            </div>
                        </div>

                        <!-- Vedere semnătură salvată: vizibilă după save (in-place, fără reload) -->
                        <div class="signature-saved-section" id="signatureSavedSection" style="display:<?= $hasClientSignature ? 'block' : 'none' ?>;">
                            <div class="signature-existing">
                                <img id="signatureSavedImage" src="<?= $hasClientSignature ? dview_h($signaturePath) . '?v=' . time() : '' ?>" alt="Semnătura beneficiar"<?= !$hasClientSignature ? ' style="display:none;"' : '' ?>>
                            </div>
                            <div class="signature-actions">
                                <button class="btn signature-action-btn" type="button" id="redoSignatureBtn">Refă semnătura</button>
                                <div class="signature-actions-row">
                                    <a class="btn accent signature-action-btn" target="_blank" href="document_pdf.php?id=<?= (int)$document['id'] ?>&mode=download">Descarcă PDF</a>
                                    <?php if ($emailFromCardEnabled): ?>
                                        <button class="btn accent signature-action-btn primary-email-cta" type="button" id="sendEmailFromSignatureCard" data-recipient="<?= dview_h($clientEmail) ?>">Trimite PV pe email</button>
                                    <?php else: ?>
                                        <span class="btn disabled signature-action-btn">Email lipsă</span>
                                    <?php endif; ?>
                                </div>
                                <a class="btn signature-action-btn signature-calendar-btn" href="calendar.php">Calendar</a>
                            </div>
                            <div class="signature-feedback" id="signatureFeedback" style="display:none;"></div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!$fieldPvCompact): ?>
                <section class="document-card preview-card">
                    <div class="preview-head">
                        <div>
                            <h2>Previzualizare document</h2>
                            <p>Aceasta este pagina A4 reala folosita si pentru printare / salvare PDF.</p>
                        </div>
                        <a class="link-muted" target="_blank" href="document_pdf.php?id=<?= (int)$document['id'] ?>">Deschide PDF</a>
                    </div>
                    <div class="preview-body">
                        <iframe class="print-preview-frame" srcdoc="<?= dview_h($previewHtml) ?>" title="Previzualizare document A4"></iframe>
                    </div>
                </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
const csrfToken = '<?= dview_h(csrf_token()) ?>';
const currentDocumentId = <?= (int)($document['id'] ?? 0) ?>;
/*
|--------------------------------------------------------------------------
| Semnătură client + email — fără alert/confirm/reload
|--------------------------------------------------------------------------
| Toate acțiunile se desfășoară în-place în cardul de semnătură:
|  - Salvare semnătură: AJAX → update DOM (canvas → imagine + badge)
|  - Refă semnătura: toggle pad ↔ saved
|  - Trimite email: direct (fără confirm), feedback inline
*/
let pvSignatureHasDrawn = false;

function pvSignatureFeedback(type, message) {
    const feedback = document.getElementById('signatureFeedback');
    if (!feedback) return;
    const prefix = type === 'success' ? '✓ ' : (type === 'error' ? '✕ ' : '');
    feedback.textContent = prefix + message;
    feedback.className = 'signature-feedback ' + type;
    feedback.style.display = 'flex';
    if (type === 'success') {
        // Auto-dismiss feedback de succes după 6s (timpul rămâne vizibil destul cât utilizatorul să-l vadă).
        setTimeout(() => { if (feedback) feedback.style.display = 'none'; }, 6000);
    }
}

function pvSignatureShowSaved(imageSrc, savedAtLabel) {
    const padSection = document.getElementById('signaturePadSection');
    const savedSection = document.getElementById('signatureSavedSection');
    const savedImage = document.getElementById('signatureSavedImage');
    const badge = document.getElementById('signatureStatusBadge');
    if (padSection) padSection.style.display = 'none';
    if (savedSection) savedSection.style.display = 'block';
    if (savedImage && imageSrc) {
        savedImage.src = imageSrc;
        savedImage.style.display = 'block';
    }
    if (badge) {
        badge.classList.remove('draft');
        badge.classList.add('issued');
        badge.textContent = 'Semnătura salvata' + (savedAtLabel ? ' - ' + savedAtLabel : '');
    }
}

function pvSignatureShowPad() {
    const padSection = document.getElementById('signaturePadSection');
    const savedSection = document.getElementById('signatureSavedSection');
    const canvas = document.getElementById('clientSignaturePad');
    const feedback = document.getElementById('signatureFeedback');
    if (savedSection) savedSection.style.display = 'none';
    if (padSection) padSection.style.display = 'block';
    if (feedback) feedback.style.display = 'none';
    if (canvas && canvas.__pvClear) canvas.__pvClear();
    if (canvas && canvas.__pvResize) {
        // Canvas era hidden — redimensionăm acum că e vizibil.
        setTimeout(canvas.__pvResize, 50);
    }
}

(function initClientSignaturePad(){
    const canvas = document.getElementById('clientSignaturePad');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const clearBtn = document.getElementById('clearClientSignature');
    const saveBtn = document.getElementById('saveClientSignature');
    const redoBtn = document.getElementById('redoSignatureBtn');
    let drawing = false;
    let last = null;

    function resizeCanvas() {
        const rect = canvas.getBoundingClientRect();
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const previous = ctx.getImageData(0, 0, Math.max(1, canvas.width), Math.max(1, canvas.height));
        const cssSize = Math.max(280, Math.floor(Math.min(rect.width || 360, rect.height || rect.width || 360)));
        canvas.width = Math.max(560, Math.floor(cssSize * ratio));
        canvas.height = Math.max(560, Math.floor(cssSize * ratio));
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.lineWidth = 3.2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#1d4ed8';
        if (pvSignatureHasDrawn && previous && previous.width > 1 && previous.height > 1) {
            try { ctx.putImageData(previous, 0, 0); } catch(e) {}
        }
    }

    function pointFromEvent(ev) {
        const rect = canvas.getBoundingClientRect();
        const e = ev.touches && ev.touches.length ? ev.touches[0] : ev;
        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    function startDraw(ev) {
        ev.preventDefault();
        drawing = true;
        pvSignatureHasDrawn = true;
        last = pointFromEvent(ev);
    }

    function moveDraw(ev) {
        if (!drawing) return;
        ev.preventDefault();
        const p = pointFromEvent(ev);
        ctx.beginPath();
        ctx.moveTo(last.x, last.y);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        last = p;
    }

    function endDraw(ev) {
        if (!drawing) return;
        ev.preventDefault();
        drawing = false;
        last = null;
    }

    // Expun pe canvas funcții utile pentru flow-ul Refă / save.
    canvas.__pvResize = resizeCanvas;
    canvas.__pvClear = function() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        pvSignatureHasDrawn = false;
    };

    resizeCanvas();
    // Focus pe canvas doar dacă pad-ul e vizibil (nu blocăm cu autofocus dacă semnătura e deja salvată).
    const padSection = document.getElementById('signaturePadSection');
    if (padSection && padSection.style.display !== 'none') {
        setTimeout(function(){ try { canvas.focus({preventScroll:true}); } catch(e) { try { canvas.focus(); } catch(_e) {} } }, 80);
    }
    window.addEventListener('resize', resizeCanvas);
    canvas.addEventListener('pointerdown', startDraw);
    canvas.addEventListener('pointermove', moveDraw);
    canvas.addEventListener('pointerup', endDraw);
    canvas.addEventListener('pointercancel', endDraw);
    canvas.addEventListener('touchstart', startDraw, { passive:false });
    canvas.addEventListener('touchmove', moveDraw, { passive:false });
    canvas.addEventListener('touchend', endDraw, { passive:false });

    if (clearBtn) {
        clearBtn.addEventListener('click', function(){
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            pvSignatureHasDrawn = false;
        });
    }

    if (redoBtn) {
        redoBtn.addEventListener('click', function() {
            pvSignatureShowPad();
        });
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', async function(){
            if (!pvSignatureHasDrawn) {
                pvSignatureFeedback('info', 'Trasează semnătura în chenar înainte de salvare.');
                return;
            }
            saveBtn.disabled = true;
            const oldText = saveBtn.textContent;
            saveBtn.textContent = 'Se salveaza...';
            const dataURL = canvas.toDataURL('image/png');
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('document_id', currentDocumentId);
            formData.append('signature_data', dataURL);
            try {
                const res = await fetch('document_signature_save.php', { method:'POST', body:formData, credentials:'same-origin', headers:{'Accept':'application/json'} });
                const data = await res.json().catch(() => null);
                if (res.ok && data && data.ok) {
                    // Format ora curentă pentru badge (DD.MM.YYYY HH:mm)
                    const now = new Date();
                    const pad = n => String(n).padStart(2, '0');
                    const savedAt = pad(now.getDate()) + '.' + pad(now.getMonth() + 1) + '.' + now.getFullYear() + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes());
                    pvSignatureShowSaved(dataURL, savedAt);
                } else {
                    pvSignatureFeedback('error', (data && data.error) ? data.error : 'Semnătura nu a putut fi salvata.');
                }
            } catch (err) {
                console.error('signature save error:', err);
                pvSignatureFeedback('error', 'Eroare la salvarea semnaturii. Reincearca.');
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = oldText || 'Salvează semnătura';
            }
        });
    }

    // Buton „Trimite PV pe email" în cardul semnătură (apare după save sau dacă semnătura era deja salvată la load).
    const cardEmailBtn = document.getElementById('sendEmailFromSignatureCard');
    if (cardEmailBtn) {
        cardEmailBtn.addEventListener('click', function() {
            const recipient = cardEmailBtn.dataset.recipient || '';
            sendQuickDocumentEmail(currentDocumentId, cardEmailBtn, recipient);
        });
    }
})();

async function sendQuickDocumentEmail(documentId, btn, recipientEmail) {
    if (!documentId) {
        if (document.getElementById('signatureFeedback')) {
            pvSignatureFeedback('error', 'Documentul nu a fost identificat.');
        } else {
            alert('Documentul nu a fost identificat.');
        }
        return;
    }
    // Fără confirm — butonul cu textul „Trimite PV pe email" e explicit; click direct trimite.
    const useInlineFeedback = !!document.getElementById('signatureFeedback');
    const originalText = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Se trimite...'; }
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('document_id', documentId);
    try {
        const res = await fetch('document_send_quick.php', { method:'POST', body:formData, credentials:'same-origin', headers:{'Accept':'application/json'} });
        const data = await res.json().catch(() => null);
        if (res.ok && data && data.ok) {
            const successMsg = recipientEmail ? ('Email trimis la ' + recipientEmail) : 'Email trimis cu succes.';
            if (useInlineFeedback) {
                pvSignatureFeedback('success', successMsg);
                if (btn) btn.style.display = 'none';
            } else {
                alert(successMsg);
                if (btn) { btn.disabled = false; btn.textContent = originalText || 'Trimite email'; }
            }
        } else {
            const errMsg = (data && data.error) ? data.error : 'Emailul nu a putut fi trimis.';
            if (useInlineFeedback) pvSignatureFeedback('error', errMsg);
            else alert(errMsg);
            if (btn) { btn.disabled = false; btn.textContent = originalText || 'Trimite email'; }
        }
    } catch (err) {
        console.error('quick document email error:', err);
        const errMsg = 'Eroare la trimiterea emailului. Reincearca.';
        if (useInlineFeedback) pvSignatureFeedback('error', errMsg);
        else alert(errMsg);
        if (btn) { btn.disabled = false; btn.textContent = originalText || 'Trimite email'; }
    }
}
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
