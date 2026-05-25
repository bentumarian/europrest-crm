<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/document_core.php';
require_once __DIR__ . '/document_tokens.php';
require_once __DIR__ . '/contract_flow_lib.php';

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
} catch (Throwable $e) {
    error_log('PestZone addenda init error: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Helpers locale pentru pagina Acte adiționale
|--------------------------------------------------------------------------
*/
function pz_addendum_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pz_addendum_str($value, int $max = 0): string
{
    $value = trim((string)$value);
    if ($max > 0) {
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $max, 'UTF-8');
        } else {
            $value = substr($value, 0, $max);
        }
    }
    return $value;
}

function pz_addendum_decimal($value, float $default = 0.0): float
{
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_string($value)) {
        $value = str_replace([' ', ','], ['', '.'], $value);
    }
    return is_numeric($value) ? (float)$value : $default;
}

function pz_addendum_money($value, string $currency = 'RON'): string
{
    return number_format((float)$value, 2, ',', '.') . ' ' . $currency;
}

function pz_addendum_date_ro(?string $date): string
{
    if (!$date) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : '-';
}

function pz_addendum_status_label(string $status): string
{
    return [
        'draft' => 'Draft',
        'issued' => 'Emis',
        'cancelled' => 'Anulat',
    ][$status] ?? $status;
}

function pz_addendum_status_class(string $status): string
{
    return [
        'draft' => 'draft',
        'issued' => 'issued',
        'cancelled' => 'cancelled',
    ][$status] ?? 'draft';
}

function pz_addendum_current_url(array $extra = []): string
{
    $params = $_GET;
    foreach ($extra as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'addenda.php' . ($params ? '?' . http_build_query($params) : '');
}

/**
 * Lista contractelor emise care pot fi prelungite printr-un act adițional.
 * Returnam doar contracte cu status `issued`.
 */
function pz_addendum_fetch_parent_contracts(PDO $pdo): array
{
    $sql = "
        SELECT id, document_number, document_date, title, client_id,
               client_name_snapshot, client_identifier_snapshot,
               client_location_id, location_name_snapshot, location_address_snapshot,
               subtotal, total_amount, currency, payload_json
        FROM documents
        WHERE document_type = 'contract'
          AND status = 'issued'
        ORDER BY document_date DESC, id DESC
        LIMIT 1000
    ";
    $stmt = $pdo->query($sql);
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function pz_addendum_fetch_services(PDO $pdo): array
{
    if (!pzdoc_table_exists($pdo, 'services')) {
        return [];
    }
    $stmt = $pdo->query("
        SELECT id, name, description, active, sort_order
        FROM services
        WHERE COALESCE(active, 1) = 1
        ORDER BY sort_order ASC, name ASC
        LIMIT 500
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_addendum_fetch_templates(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT id, name, is_default
        FROM document_templates
        WHERE document_type = 'act_aditional'
          AND is_active = 1
        ORDER BY is_default DESC, name ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_addendum_frequency_options(): array
{
    return [
        'lunar' => 'Lunar',
        'trimestrial' => 'Trimestrial',
        'semestrial' => 'Semestrial',
        'int_unica' => 'Int. unica',
    ];
}

function pz_addendum_normalize_frequency($value): string
{
    if (function_exists('pz_flow_normalize_frequency')) {
        return pz_flow_normalize_frequency((string)$value);
    }
    $v = strtolower(trim((string)$value));
    return $v ?: 'int_unica';
}

function pz_addendum_build_items_from_post(array $postItems, float $vatPercent, string $currency): array
{
    $items = [];
    $sort = 0;

    foreach ($postItems as $row) {
        if (!is_array($row)) {
            continue;
        }

        $serviceId = !empty($row['service_id']) ? (int)$row['service_id'] : null;
        $serviceName = pz_addendum_str($row['service_name'] ?? '', 220);
        $description = pz_addendum_str($row['description'] ?? '');
        $rawFrequency = trim((string)($row['frequency_text'] ?? ''));
        $frequency = $rawFrequency === '' ? '' : pz_addendum_frequency_options()[pz_addendum_normalize_frequency($rawFrequency)] ?? '';
        $unitPrice = max(0, pz_addendum_decimal($row['unit_price'] ?? 0, 0));
        $locationId = !empty($row['client_location_id']) ? (int)$row['client_location_id'] : null;
        $locationName = pz_addendum_str($row['location_name'] ?? '', 220);
        $locationAddress = pz_addendum_str($row['location_address'] ?? '');

        if (!$serviceId && $serviceName === '' && $description === '' && $unitPrice <= 0 && !$locationId) {
            continue;
        }

        $surface = max(0, pz_addendum_decimal($row['quantity'] ?? 0, 0));
        $unit = pz_addendum_str($row['unit'] ?? 'mp', 30) ?: 'mp';
        $totalPrice = $unitPrice;

        $items[] = [
            'item_type' => 'addendum_service',
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'description' => $description,
            'client_location_id' => $locationId,
            'location_name' => $locationName,
            'location_address' => $locationAddress,
            'quantity' => $surface,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'vat_percent' => $vatPercent,
            'total_price' => $totalPrice,
            'currency' => $currency,
            'frequency_text' => $frequency,
            'planned_date' => !empty($row['planned_date']) ? pzdoc_date($row['planned_date']) : null,
            'sort_order' => $sort,
        ];
        $sort++;
    }

    return $items;
}

/**
 * Construieste lista de itemi pentru un act adițional nou, preluata din
 * contractul-mama. Preturile, suprafata si frecventa sunt preluate ca atare;
 * utilizatorul poate sa le modifice in formular daca prețul se schimbă.
 */
function pz_addendum_items_from_parent_contract(array $parentDocument): array
{
    $sourceItems = is_array($parentDocument['items'] ?? null) ? $parentDocument['items'] : [];
    $items = [];
    foreach ($sourceItems as $item) {
        $items[] = [
            'service_id' => !empty($item['service_id']) ? (int)$item['service_id'] : '',
            'service_name' => (string)($item['service_name'] ?? ''),
            'description' => (string)($item['description'] ?? ''),
            'client_location_id' => !empty($item['client_location_id']) ? (int)$item['client_location_id'] : '',
            'location_name' => (string)($item['location_name'] ?? ''),
            'location_address' => (string)($item['location_address'] ?? ''),
            'quantity' => $item['quantity'] ?? 0,
            'unit' => $item['unit'] ?? 'mp',
            'unit_price' => $item['unit_price'] ?? 0,
            'total_price' => $item['total_price'] ?? ($item['unit_price'] ?? 0),
            'frequency_text' => (string)($item['frequency_text'] ?? ''),
            'planned_date' => '',
        ];
    }
    return $items;
}

function pz_addendum_redirect_with_error(string $message, int $editId = 0, ?int $parent = null): void
{
    $_SESSION['pz_addendum_error'] = $message;
    $url = 'addenda.php';
    if ($editId > 0) {
        $url .= '?edit=' . (int)$editId;
    } elseif ($parent) {
        $url .= '?new=1&parent=' . (int)$parent;
    } else {
        $url .= '?new=1';
    }
    header('Location: ' . $url);
    exit;
}

$parentContracts = pz_addendum_fetch_parent_contracts($pdo);
$parentContractsById = [];
foreach ($parentContracts as $contract) {
    $parentContractsById[(int)$contract['id']] = $contract;
}
$services = pz_addendum_fetch_services($pdo);
$templates = pz_addendum_fetch_templates($pdo);

/*
|--------------------------------------------------------------------------
| POST: salvare / emitere act adițional
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? '';
    if (in_array($action, ['save_draft', 'issue'], true)) {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $parentDocumentId = (int)($_POST['parent_document_id'] ?? 0);

        if ($parentDocumentId <= 0) {
            pz_addendum_redirect_with_error('Selectează contractul pentru care emiți actul adițional.', $documentId);
        }

        $parentDocument = pzdoc_get_document($pdo, $parentDocumentId, true);
        if (!$parentDocument || ($parentDocument['document_type'] ?? '') !== 'contract') {
            pz_addendum_redirect_with_error('Contractul selectat nu este valid.', $documentId);
        }
        if (($parentDocument['status'] ?? '') !== 'issued') {
            pz_addendum_redirect_with_error('Poti emite act adițional doar pentru contracte emise.', $documentId);
        }

        $clientId = (int)($parentDocument['client_id'] ?? 0);
        $locationId = !empty($parentDocument['client_location_id']) ? (int)$parentDocument['client_location_id'] : null;
        $currency = pz_addendum_str($parentDocument['currency'] ?? 'RON', 10) ?: 'RON';
        $vatPercent = 0.0;

        // Act adițional = doar document descriptiv. Conținutul liber este în câmpul
        // `notes` (Obiectul actului adițional). NU se folosesc items, dates, sau
        // alte side-effects pe contract / contract_services / tasks.
        $scopeText = trim((string)($_POST['notes'] ?? ''));
        if ($scopeText === '') {
            pz_addendum_redirect_with_error('Completează obiectul actului adițional.', $documentId, $parentDocumentId);
        }

        $parentPayload = pzdoc_json_decode($parentDocument['payload_json'] ?? null);

        $payload = [
            'parent_document_id' => $parentDocumentId,
            'parent_contract_number' => pz_addendum_str($parentDocument['document_number'] ?? '', 120),
            'parent_contract_date' => pz_addendum_str($parentDocument['document_date'] ?? '', 40),
            'parent_contract_start_date' => pz_addendum_str($parentPayload['contract_start_date'] ?? '', 40),
            'parent_contract_end_date' => pz_addendum_str($parentPayload['contract_end_date'] ?? '', 40),
            'notes_internal' => pz_addendum_str($_POST['internal_notes'] ?? ''),
        ];

        // Ștampila firmei: bifare opțională în formular.
        $applyStamp = !empty($_POST['apply_company_stamp']) ? 1 : 0;

        $data = [
            'template_id' => !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null,
            'document_date' => $_POST['document_date'] ?? date('Y-m-d'),
            'document_time' => null,
            'title' => pz_addendum_str($_POST['title'] ?? 'Act adițional contract ' . ($parentDocument['document_number'] ?: $parentDocumentId), 220),
            'client_id' => $clientId,
            'client_location_id' => $locationId,
            'source_document_id' => $parentDocumentId,
            'contract_id' => !empty($parentDocument['contract_id']) ? (int)$parentDocument['contract_id'] : null,
            'vat_percent' => $vatPercent,
            'currency' => $currency,
            'notes' => $scopeText,
            'internal_notes' => pz_addendum_str($_POST['internal_notes'] ?? ''),
            'apply_company_stamp' => $applyStamp,
            'payload_json' => $payload,
            'items' => [],  // Niciodată items pentru act adițional — fără atingerea contract_services / tasks
        ];

        try {
            if ($documentId > 0) {
                $existing = pzdoc_get_document($pdo, $documentId, false);
                if (!$existing || ($existing['document_type'] ?? '') !== 'act_aditional') {
                    throw new RuntimeException('Act adițional inexistent.');
                }
                pzdoc_update_document($pdo, $documentId, $data);
            } else {
                $documentId = pzdoc_create_document($pdo, 'act_aditional', $data);
            }

            if ($action === 'issue') {
                pzdoc_issue_document($pdo, $documentId);
                // IMPORTANT: NU se apelează pz_flow_sync_issued_addendum.
                // Actul adițional rămâne strict descriptiv; nu modifică
                // contract_services, nu extinde end_date pe contract, nu creează task-uri.
                header('Location: document_view.php?id=' . (int)$documentId . '&issued=1');
                exit;
            }

            header('Location: document_view.php?id=' . (int)$documentId . '&saved=1');
            exit;
        } catch (Throwable $e) {
            error_log('PestZone addendum save error: ' . $e->getMessage());
            pz_addendum_redirect_with_error('Actul adițional nu a putut fi salvat: ' . $e->getMessage(), $documentId, $parentDocumentId);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Pregatire editare / lista
|--------------------------------------------------------------------------
*/
$errorMessage = $_SESSION['pz_addendum_error'] ?? '';
unset($_SESSION['pz_addendum_error']);

$editId = (int)($_GET['edit'] ?? 0);
$editingDocument = null;
$editingItems = [];
$editingPayload = [];
$parentDocument = null;
$parentPayload = [];

if ($editId > 0) {
    $editingDocument = pzdoc_get_document($pdo, $editId, true);
    if (!$editingDocument || ($editingDocument['document_type'] ?? '') !== 'act_aditional') {
        $errorMessage = 'Actul adițional solicitat nu există.';
        $editingDocument = null;
    } elseif (($editingDocument['status'] ?? '') !== 'draft') {
        header('Location: document_view.php?id=' . $editId);
        exit;
    } else {
        $editingItems = $editingDocument['items'] ?? [];
        $editingPayload = pzdoc_json_decode($editingDocument['payload_json'] ?? null);
        $parentDocumentId = (int)($editingPayload['parent_document_id'] ?? ($editingDocument['source_document_id'] ?? 0));
        if ($parentDocumentId > 0) {
            $parentDocument = pzdoc_get_document($pdo, $parentDocumentId, true);
            if ($parentDocument) {
                $parentPayload = pzdoc_json_decode($parentDocument['payload_json'] ?? null);
            }
        }
    }
}

$selectedParentId = (int)($_GET['parent'] ?? 0);
if (!$editingDocument && $selectedParentId > 0 && isset($parentContractsById[$selectedParentId])) {
    $parentDocument = pzdoc_get_document($pdo, $selectedParentId, true);
    if ($parentDocument) {
        $parentPayload = pzdoc_json_decode($parentDocument['payload_json'] ?? null);
        $editingItems = pz_addendum_items_from_parent_contract($parentDocument);
    }
}

$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'client_id' => max(0, (int)($_GET['client_id'] ?? 0)),
];
$perPage = (int)($_GET['per_page'] ?? 20);
$perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalRows = pzdoc_count_documents($pdo, 'act_aditional', $filters);
$documents = pzdoc_list_documents($pdo, 'act_aditional', $filters, $perPage, $offset);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$formDocument = $editingDocument ?: [
    'id' => 0,
    'template_id' => null,
    'document_date' => date('Y-m-d'),
    'title' => '',
    'client_id' => null,
    'client_location_id' => null,
    'vat_percent' => 0,
    'currency' => 'RON',
    'notes' => '',
    'internal_notes' => '',
];

$formPayload = $editingPayload ?: [];

// Sugestie pentru perioada prelungirii: 1 an dupa data sfarsitului din contractul-mama
$suggestedStart = '';
$suggestedEnd = '';
if ($parentDocument) {
    $parentEnd = $parentPayload['contract_end_date'] ?? '';
    $parentStart = $parentPayload['contract_start_date'] ?? '';
    if ($parentEnd && strtotime($parentEnd)) {
        $suggestedStart = date('Y-m-d', strtotime($parentEnd . ' +1 day'));
        $suggestedEnd = date('Y-m-d', strtotime($parentEnd . ' +1 year'));
    } elseif ($parentStart && strtotime($parentStart)) {
        $suggestedStart = date('Y-m-d');
        $suggestedEnd = date('Y-m-d', strtotime('+1 year'));
    }
}
$formPayload['addendum_start_date'] = $formPayload['addendum_start_date'] ?? $suggestedStart ?: date('Y-m-d');
$formPayload['addendum_end_date'] = $formPayload['addendum_end_date'] ?? $suggestedEnd ?: date('Y-m-d', strtotime('+1 year'));
$formPayload['price_mode'] = $formPayload['price_mode'] ?? 'same';

if (!$editingItems && !$parentDocument) {
    $editingItems = [];
}

$showForm = !empty($_GET['new']) || $editingDocument;
$needsParentSelection = $showForm && !$parentDocument && !$editingDocument;
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Acte adiționale - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.addendum-topbar { align-items:center; padding:12px 20px; }
.panel { background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:var(--pz-r); box-shadow:none; margin-bottom:12px; }
.panel-head { padding:14px 16px; border-bottom:1px solid var(--border2); display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; }
.panel-title { font-size:16px; font-weight:900; color:var(--text); }
.panel-subtitle { font-size:12px; color:var(--muted); margin-top:2px; }
.panel-body { padding:14px 16px; }
.alert { border-radius:var(--pz-rs); padding:10px 13px; margin-bottom:12px; font-weight:600; font-size:12.5px; }
.alert.error   { background:var(--pz-res); color:var(--pz-re); border:1px solid var(--pz-reb); }
.alert.success { background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); }
.alert.info    { background:var(--accent-soft); color:var(--accent-deep); border:1px solid var(--accent-soft-2); }
.filter-form { display:grid; grid-template-columns:minmax(220px,1fr) minmax(150px,.45fr) minmax(130px,.35fr) auto; gap:10px; align-items:end; }
.addendum-form-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
.field label { display:block; font-size:12px; font-weight:850; color:var(--muted); margin-bottom:5px; }
.field input, .field select, .field textarea { width:100%; border:1px solid var(--pz-line); border-radius:var(--pz-rs); background:#fff; color:var(--pz-text); padding:7px 10px; font-size:12.5px; outline:none; transition:border-color .14s; }
.field input:focus, .field select:focus, .field textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }
.field.full { grid-column:1 / -1; }
.field.span2 { grid-column:span 2; }
.parent-info { background:var(--surface-soft); border:1px solid var(--border2); border-radius:12px; padding:10px 12px; font-size:12.5px; color:var(--text); line-height:1.45; }

/* === Secțiuni numerotate (aliniat cu Contracte/Oferte) === */
.contract-section { margin-bottom:22px; }
.contract-section:last-child { margin-bottom:0; }
.contract-section-head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px; }
.contract-section-titlewrap { display:flex; align-items:center; gap:10px; min-width:0; }
.contract-step-num { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:var(--pz-soft); border:1px solid var(--pz-line); color:var(--pz-fa); font-size:12px; font-weight:600; flex:0 0 22px; }
.contract-section-title { font-size:14px; font-weight:600; color:var(--pz-title); margin:0; }
.contract-section-hint { font-size:12px; color:var(--pz-mu); margin-left:6px; }
.parent-info b { color:var(--accent-deep); }
.checkline { display:flex; align-items:center; gap:8px; min-height:42px; padding:9px 11px; border:1px solid var(--border); border-radius:12px; background:#fff; }
.checkline input { width:auto; }
.items-wrap { overflow-x:auto; }
.items-table { width:100%; border-collapse:separate; border-spacing:0 8px; min-width:980px; }
.items-table th { text-align:left; font-size:11px; color:var(--muted); font-weight:900; padding:0 6px; text-transform:uppercase; letter-spacing:.04em; }
.items-table td { background:var(--surface-soft); border-top:1px solid var(--border2); border-bottom:1px solid var(--border2); padding:7px 6px; vertical-align:top; }
.items-table td:first-child { border-left:1px solid var(--border2); border-radius:12px 0 0 12px; }
.items-table td:last-child { border-right:1px solid var(--border2); border-radius:0 12px 12px 0; }
.items-table input, .items-table select { width:100%; border:1px solid var(--border); border-radius:10px; padding:8px 9px; background:#fff; font-size:12px; }
.items-table textarea { width:100%; min-height:39px; border:1px solid var(--border); border-radius:10px; padding:8px 9px; background:#fff; font-size:12px; resize:vertical; }
.row-total { font-weight:900; color:var(--text); text-align:right; padding-top:8px; white-space:nowrap; }
.form-actions { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; margin-top:14px; }
.form-actions .right { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:34px; border-radius:var(--pz-rs); padding:0 11px; border:1px solid var(--border); background:#fff; color:var(--text); font-size:12.5px; font-weight:600; text-decoration:none; cursor:pointer; white-space:nowrap; box-shadow:none; }
.btn:hover { border-color:var(--accent); color:var(--accent-deep); }
.btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn.primary:hover { background:var(--accent-strong); color:#fff; }
.btn.danger { color:var(--danger); border-color:rgba(180,35,24,.28); background:#fff; }
.btn.small { min-height:32px; padding:0 10px; font-size:12px; border-radius:10px; }
.docs-list { display:grid; gap:10px; }
.doc-row { background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:var(--pz-r); box-shadow:none; padding:12px 14px; display:grid; grid-template-columns:minmax(260px,1.2fr) minmax(150px,.45fr) minmax(150px,.45fr) minmax(120px,.35fr) auto; gap:12px; align-items:center; }
.doc-title { font-size:14px; font-weight:600; color:var(--pz-title); overflow-wrap:anywhere; }
.doc-meta { color:var(--muted); font-size:12px; margin-top:4px; line-height:1.35; }
.badge { display:inline-flex; align-items:center; justify-content:center; border-radius:var(--pz-rs); padding:3px 8px; font-size:11px; font-weight:600; border:1px solid var(--pz-line); background:var(--pz-soft); color:var(--pz-mu); white-space:nowrap; }
.badge.draft   { background:var(--pz-ors); color:var(--pz-or); border-color:var(--pz-orb); }
.badge.issued  { background:var(--pz-grs); color:var(--pz-gr); border-color:var(--pz-grb); }
.badge.cancelled { background:var(--pz-res); color:var(--pz-re); border-color:var(--pz-reb); }
.doc-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
.empty-state { padding:22px; text-align:center; color:var(--pz-mu); font-weight:600; border:1px dashed var(--pz-line); border-radius:var(--pz-r); background:var(--pz-soft); }
.pagination { display:flex; gap:6px; justify-content:flex-end; align-items:center; flex-wrap:wrap; margin-top:12px; }
.addendum-scope-textarea { width:100%; min-height:200px; padding:10px 12px; border:1px solid var(--border); border-radius:8px; background:#fff; font-family:inherit; font-size:13px; color:var(--text); resize:vertical; line-height:1.55; }
.addendum-scope-textarea:focus { outline:none; border-color:var(--pz-bl); box-shadow:0 0 0 3px rgba(37,99,235,.10); }
@media (max-width: 980px) {
    .filter-form, .addendum-form-grid { grid-template-columns:1fr; }
    .field.span2 { grid-column:1; }
    .doc-row { grid-template-columns:1fr; }
    .doc-actions { justify-content:flex-start; }
}
</style>
<?php render_search_preview_assets(); ?>
</head>
<body>
<div class="layout">
    <?php render_sidebar('addenda', $isAdmin); ?>

    <main class="main">
        <div class="content">
            <?php if ($errorMessage): ?>
                <div class="alert error"><?= pz_addendum_h($errorMessage) ?></div>
            <?php endif; ?>

            <?php if (!empty($_GET['created']) || !empty($_GET['saved'])): ?>
                <div class="alert success">Actul adițional a fost salvat.</div>
            <?php endif; ?>

            <?php if ($needsParentSelection): ?>
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title">Selectează contractul de prelungit</div>
                            <div class="panel-subtitle">Actul adițional se emite intotdeauna pentru un contract emis.</div>
                        </div>
                        <a class="btn small" href="addenda.php">Inchide</a>
                    </div>
                    <div class="panel-body">
                        <?php if (!$parentContracts): ?>
                            <div class="empty-state">Nu există contracte emise pentru care sa se poata emite un act adițional.</div>
                        <?php else: ?>
                            <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
                                <input type="hidden" name="new" value="1">
                                <div class="field" style="flex:1; min-width:280px;">
                                    <label>Contract</label>
                                    <select name="parent" required>
                                        <option value="">Alege contractul...</option>
                                        <?php foreach ($parentContracts as $contract): ?>
                                            <option value="<?= (int)$contract['id'] ?>">
                                                <?= pz_addendum_h(($contract['document_number'] ?: 'DOC-' . $contract['id'])) ?>
                                                — <?= pz_addendum_h($contract['client_name_snapshot'] ?: ('Client #' . $contract['client_id'])) ?>
                                                (<?= pz_addendum_h(pz_addendum_date_ro($contract['document_date'])) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn primary">Continuă</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($showForm && $parentDocument): ?>
                <?php $parentClient = $parentDocument['client_name_snapshot'] ?? ''; ?>
                <section class="panel" id="addendumFormPanel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title"><?= $editingDocument ? 'Editează act adițional draft' : 'Act adițional nou' ?></div>
                            <div class="panel-subtitle">Prelungire pentru <?= pz_addendum_h($parentDocument['document_number'] ?: ('DOC-' . $parentDocument['id'])) ?></div>
                        </div>
                        <a class="btn small" href="addenda.php"><i style="font-style:normal;margin-right:4px;">×</i>Închide formularul</a>
                    </div>
                    <div class="panel-body">
                        <div class="parent-info" style="margin-bottom:14px;">
                            <b>Contract sursa:</b> <?= pz_addendum_h($parentDocument['document_number'] ?: ('DOC-' . $parentDocument['id'])) ?>
                            din <?= pz_addendum_h(pz_addendum_date_ro($parentDocument['document_date'])) ?><br>
                            <b>Client:</b> <?= pz_addendum_h($parentClient) ?>
                            <?php if (!empty($parentDocument['client_identifier_snapshot'])): ?>
                                — CUI <?= pz_addendum_h($parentDocument['client_identifier_snapshot']) ?>
                            <?php endif; ?><br>
                            <?php if (!empty($parentPayload['contract_start_date']) || !empty($parentPayload['contract_end_date'])): ?>
                                <b>Perioada contract:</b>
                                <?= pz_addendum_h(pz_addendum_date_ro($parentPayload['contract_start_date'] ?? null)) ?>
                                —
                                <?= pz_addendum_h(pz_addendum_date_ro($parentPayload['contract_end_date'] ?? null)) ?>
                            <?php endif; ?>
                        </div>

                        <form method="post" id="addendumForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="document_id" value="<?= (int)($formDocument['id'] ?? 0) ?>">
                            <input type="hidden" name="parent_document_id" value="<?= (int)$parentDocument['id'] ?>">
                            <input type="hidden" name="title" value="<?= pz_addendum_h($formDocument['title'] ?? ('Act adițional contract ' . ($parentDocument['document_number'] ?? $parentDocument['id']))) ?>">

                            <div class="contract-section-head">
                                <div class="contract-section-titlewrap">
                                    <span class="contract-step-num">1</span>
                                    <h3 class="contract-section-title">Detalii act</h3>
                                </div>
                            </div>
                            <div class="addendum-form-grid">
                                <div class="field">
                                    <label>Data act adițional</label>
                                    <input type="date" name="document_date" value="<?= pz_addendum_h($formDocument['document_date'] ?? date('Y-m-d')) ?>">
                                </div>

                                <div class="field">
                                    <label>Șablon *</label>
                                    <select name="template_id" required>
                                        <option value="" disabled <?= empty($formDocument['template_id']) ? 'selected' : '' ?>>Alege șablon...</option>
                                        <?php foreach ($templates as $template): ?>
                                            <option value="<?= (int)$template['id'] ?>" <?= (int)($formDocument['template_id'] ?? 0) === (int)$template['id'] ? 'selected' : '' ?>>
                                                <?= pz_addendum_h($template['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="panel" style="margin-top:14px; box-shadow:none;">
                                <div class="panel-head">
                                    <div>
                                        <div class="panel-title" style="display:flex;align-items:center;gap:10px;"><span class="contract-step-num">2</span><span>Obiectul actului adițional</span></div>
                                        <div class="panel-subtitle">Descrie liber ce se modifică: prelungirea perioadei de valabilitate, modificarea prețului, schimbarea termenelor, completarea lucrărilor sau orice altă modificare. Textul intră în PDF prin <code>{{document_object}}</code> (token universal, funcționează și în contracte standard).</div>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <textarea name="notes" id="addendumScopeTextarea" class="addendum-scope-textarea" rows="10" required placeholder="Ex: Părțile convin prelungirea perioadei de valabilitate a contractului până la data de 31.12.2027, restul clauzelor rămânând neschimbate."><?= pz_addendum_h($formDocument['notes'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <?php
                                // Ștampila firmei: opțională la emiterea actului adițional.
                                $stampChecked = isset($formDocument['apply_company_stamp'])
                                    ? !empty($formDocument['apply_company_stamp'])
                                    : true;  // implicit bifat (uniformizare cu PV)
                            ?>
                            <div style="margin:14px 0;padding:12px 14px;border:1px solid var(--border);border-radius:12px;background:#f8fafc;">
                                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:700;color:var(--text);">
                                    <input type="checkbox" name="apply_company_stamp" value="1" <?= $stampChecked ? 'checked' : '' ?> style="width:18px;height:18px;">
                                    <span>Aplică ștampila firmei pe acest act adițional</span>
                                </label>
                                <div style="margin-top:6px;font-size:12px;color:var(--muted);padding-left:28px;">
                                    Ștampila încărcată în <em>Setări → Design documente</em> apare lângă semnătura Executantului prin tokenul <code>{{company_stamp}}</code> din șablon.
                                </div>
                            </div>

                            <div class="form-actions">
                                <div>
                                    <?php if (!empty($formDocument['id'])): ?>
                                        <a class="btn" href="document_view.php?id=<?= (int)$formDocument['id'] ?>">Vezi document</a>
                                    <?php endif; ?>
                                </div>
                                <div class="right">
                                    <button type="submit" name="action" value="save_draft" class="btn">Salvează draft</button>
                                    <button type="submit" name="action" value="issue" class="btn primary" onclick="return confirm('Emiti actul adițional si aloci numar? După emitere documentul se blocheaza.')">Emite act adițional</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!$showForm): ?>
            <?php
                /*
                |------------------------------------------------------------
                | Header unificat PestZone — înlocuiește panel-head + filter
                | form vechi pentru lista acte adiționale.
                | Tabs principale = 5 sub-pagini Documente.
                | Toolbar = search + popover (Status + Rânduri/pagină).
                | Actions = Act adițional nou (primary).
                |------------------------------------------------------------
                */
                $addendaTabs = [
                    ['label' => 'Procese verbale',  'href' => 'service-reports'],
                    ['label' => 'Contracte',        'href' => 'contracts.php'],
                    ['label' => 'Oferte',           'href' => 'oferte.php'],
                    ['label' => 'Acte adiționale',  'href' => 'addenda.php', 'active' => true],
                    ['label' => 'Arhivă documente', 'href' => 'documents'],
                ];

                $addendaActiveFilters = 0;
                if (!empty($filters['status'])) $addendaActiveFilters++;
                if ($perPage !== 20)            $addendaActiveFilters++;

                $addendaSubtitle = (int)($totalDocs ?? count($documents)) . ' acte adiționale';
                if (!empty($filters['q'])) {
                    $addendaSubtitle .= ' · căutare: „' . pz_addendum_h($filters['q']) . '"';
                }

                ob_start();
                ?>
                <form method="get" id="addendaFilterForm" class="pz-fb">
                    <div class="pz-fb-search">
                        <i class="ti ti-search" aria-hidden="true"></i>
                        <input type="text" id="addendaSearchInput" name="q" value="<?= pz_addendum_h($filters['q']) ?>" placeholder="Caută" autocomplete="off">
                        <div class="pz-search-preview"></div>
                    </div>

                    <div class="pz-fb-spacer"></div>

                    <a class="pz-fb-nav-btn" href="addenda.php" title="Resetare filtre">↻</a>

                    <div class="pz-fb-popover-wrap">
                        <button type="button" class="pz-fb-filter-btn" id="addendaFiltersToggle" aria-haspopup="true" aria-expanded="false">
                            <i class="ti ti-adjustments-horizontal" aria-hidden="true"></i>
                            Filtre
                            <?php if ($addendaActiveFilters > 0): ?>
                                <span class="badge"><?= (int)$addendaActiveFilters ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="pz-fb-popover" id="addendaFiltersPopover" role="dialog" aria-label="Filtre suplimentare acte adiționale">
                            <div class="pf-row">
                                <label for="addendaStatusSelect">Status</label>
                                <select id="addendaStatusSelect" name="status">
                                    <option value="">Toate</option>
                                    <?php foreach (['draft' => 'Draft', 'issued' => 'Emise', 'cancelled' => 'Anulate'] as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pf-row">
                                <label for="addendaPerPageSelect">Rânduri pe pagină</label>
                                <select id="addendaPerPageSelect" name="per_page">
                                    <?php foreach ([20, 50, 100] as $nr): ?>
                                        <option value="<?= $nr ?>" <?= $perPage === $nr ? 'selected' : '' ?>><?= $nr ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pf-actions">
                                <button type="button" class="pz-ph-btn ghost" onclick="document.getElementById('addendaFiltersPopover').classList.remove('is-open'); document.getElementById('addendaFiltersToggle').setAttribute('aria-expanded','false');">Anulează</button>
                                <button type="submit" class="pz-ph-btn primary">Aplică</button>
                            </div>
                        </div>
                    </div>
                </form>
                <?php
                $addendaToolbarHtml = ob_get_clean();

                pz_page_header([
                    'kicker'   => 'Documente',
                    'title'    => 'Acte adiționale',
                    'subtitle' => $addendaSubtitle,
                    'actions'  => [[
                        'label'   => 'Act adițional nou',
                        'href'    => 'addenda.php?new=1',
                        'variant' => 'primary',
                        'icon'    => 'ti-plus',
                    ]],
                    'tabs'     => $addendaTabs,
                    'toolbar'  => $addendaToolbarHtml,
                ]);
                ?>
                <script>
                (function() {
                    var btn = document.getElementById('addendaFiltersToggle');
                    var pop = document.getElementById('addendaFiltersPopover');
                    if (!btn || !pop) return;
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var open = pop.classList.toggle('is-open');
                        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });
                    document.addEventListener('click', function(e) {
                        if (!pop.classList.contains('is-open')) return;
                        if (pop.contains(e.target) || btn.contains(e.target)) return;
                        pop.classList.remove('is-open');
                        btn.setAttribute('aria-expanded', 'false');
                    });
                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape' && pop.classList.contains('is-open')) {
                            pop.classList.remove('is-open');
                            btn.setAttribute('aria-expanded', 'false');
                            btn.focus();
                        }
                    });
                })();
                </script>

            <section class="panel">
                <div class="panel-body">
                    <div class="docs-list" style="margin-top:12px;">
                        <?php if (!$documents): ?>
                            <div class="empty-state">Nu există acte adiționale inca.</div>
                        <?php else: ?>
                            <?php foreach ($documents as $doc): ?>
                                <?php $docPayload = pzdoc_json_decode($doc['payload_json'] ?? null); ?>
                                <div class="doc-row">
                                    <div>
                                        <div class="doc-title">
                                            <?= pz_addendum_h(($doc['document_number'] ?: 'Draft') . ' - ' . ($doc['client_name_snapshot'] ?: $doc['title'])) ?>
                                        </div>
                                        <div class="doc-meta">
                                            Data emitere: <?= pz_addendum_h(pz_addendum_date_ro($doc['document_date'] ?? null)) ?>
                                            <?php if (!empty($docPayload['addendum_start_date'])): ?>
                                                | Prelungire: <?= pz_addendum_h(pz_addendum_date_ro($docPayload['addendum_start_date'])) ?> - <?= pz_addendum_h(pz_addendum_date_ro($docPayload['addendum_end_date'] ?? null)) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($docPayload['parent_contract_number'])): ?>
                                                | la contract <?= pz_addendum_h($docPayload['parent_contract_number']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="badge <?= pz_addendum_h(pz_addendum_status_class($doc['status'])) ?>"><?= pz_addendum_h(pz_addendum_status_label($doc['status'])) ?></span>
                                    </div>
                                    <div class="doc-meta">
                                        <?= pz_addendum_h($doc['client_identifier_snapshot'] ?: '') ?>
                                    </div>
                                    <div style="font-weight:950; text-align:right;">
                                        <?= pz_addendum_money($doc['total_amount'] ?? 0, $doc['currency'] ?? 'RON') ?>
                                    </div>
                                    <div class="doc-actions">
                                        <a class="btn small" href="document_view.php?id=<?= (int)$doc['id'] ?>">Vezi</a>
                                        <?php if (($doc['status'] ?? '') === 'draft'): ?>
                                            <a class="btn small" href="addenda.php?edit=<?= (int)$doc['id'] ?>">Editează</a>
                                        <?php endif; ?>
                                        <a class="btn small" target="_blank" href="document_pdf.php?id=<?= (int)$doc['id'] ?>&mode=inline">PDF</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a class="btn small" href="<?= pz_addendum_h(pz_addendum_current_url(['page' => $page - 1])) ?>">&lt;</a>
                            <?php endif; ?>
                            <span class="badge">Pagina <?= (int)$page ?> / <?= (int)$totalPages ?></span>
                            <?php if ($page < $totalPages): ?>
                                <a class="btn small" href="<?= pz_addendum_h(pz_addendum_current_url(['page' => $page + 1])) ?>">&gt;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </main>
</div>
<script>
// Auto-focus pe textarea când form-ul se deschide gol.
document.addEventListener('DOMContentLoaded', function () {
    var ta = document.getElementById('addendumScopeTextarea');
    if (ta && !ta.value.trim()) {
        setTimeout(function () { ta.focus(); }, 120);
    }
});
</script>

<?php
// Preview live pentru bara „Caută act adițional".
$previewAddendaList = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'contract_addenda'")->fetch()) {
        $stmtPrev = $pdo->query("
            SELECT a.id, a.addendum_number, c.name AS client_name, c.fiscal_code
            FROM contract_addenda a
            LEFT JOIN contracts con ON con.id = a.contract_id
            LEFT JOIN clients c ON c.id = con.client_id
            ORDER BY a.id DESC LIMIT 2000
        ");
        while ($r = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
            $nm  = html_entity_decode((string)($r['client_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cf  = html_entity_decode((string)($r['fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $num = trim((string)($r['addendum_number'] ?? ''));
            $title = ($num !== '' ? ($num . ' · ') : '') . ($nm !== '' ? $nm : ('Act #' . (int)$r['id']));
            $previewAddendaList[] = [
                'title'  => $title,
                'url'    => 'addenda.php?q=' . urlencode($num !== '' ? $num : $nm),
                'type'   => 'addenda',
                'search' => $num . ' ' . $nm . ' ' . $cf,
            ];
        }
    }
} catch (Throwable $e) { error_log('addenda.php preview: ' . $e->getMessage()); }
?>
<script>
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('addendaSearchInput',
            <?= json_encode($previewAddendaList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
    };
    go();
})();
</script>