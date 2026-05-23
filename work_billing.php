<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once __DIR__ . '/smartbill_lib.php';
require_once __DIR__ . '/lib/billing/billing_lib.php';

$isAdmin = is_admin();
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

// Plasă de siguranță — schema „oficială" se aplică din migration_billing.sql.
pz_billing_ensure_schema($pdo);

function ib_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ib_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) > 0;
}

function ib_safe_date(?string $date, ?string $fallback = null): string {
    $fallback = $fallback ?: date('Y-m-d');
    if (!$date) return $fallback;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $date : $fallback;
}

function ib_current_url(array $extra = []): string {
    $params = $_GET;
    unset($params['saved'], $params['error'], $params['export']);
    foreach ($extra as $key => $value) {
        if ($value === null) unset($params[$key]); else $params[$key] = $value;
    }
    return 'work_billing.php' . ($params ? '?' . http_build_query($params) : '');
}

function ib_redirect(array $extra = []): void {
    header('Location: ' . ib_current_url($extra));
    exit;
}

function ib_effective_location(array $row): string {
    $locationName = trim((string)($row['location_name'] ?? ''));
    if ($locationName !== '') return $locationName;
    $address = trim((string)($row['location_address'] ?? '')) ?: trim((string)($row['address'] ?? ''));
    if ($address !== '') return $address;
    return 'Sediu / domiciliu';
}

function ib_service_label(array $row): string {
    $service = trim((string)($row['service_type'] ?? ''));
    if ($service !== '') return $service;
    $description = trim((string)($row['description'] ?? ''));
    return $description !== '' ? $description : '-';
}

function ib_pv_label(array $row): string {
    $pvNumber = trim((string)($row['pv_number'] ?? ''));
    if ($pvNumber !== '') return $pvNumber;
    $pvId = (int)($row['pv_id'] ?? 0);
    return $pvId > 0 ? 'PV #' . $pvId : 'Fără PV';
}

function ib_money_value($value): float {
    return pz_billing_money($value);
}

function ib_money_label($value): string {
    return number_format(ib_money_value($value), 2, ',', '.') . ' lei';
}

function ib_money_input($value): string {
    return number_format(ib_money_value($value), 2, '.', '');
}

/* ============================================================
 * Handlere POST — toate operează pe billing_items.id
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');
    $itemId = (int)($_POST['item_id'] ?? 0);

    if ($itemId <= 0 && $action !== 'bulk_invoice') {
        ib_redirect(['error' => 'invalid']);
    }

    if ($action === 'save_amount') {
        $amount = pz_billing_money($_POST['billing_amount'] ?? 0);
        $vatCode = trim((string)($_POST['billing_vat_code'] ?? '21'));
        $result = pz_billing_update_amount($pdo, $itemId, $amount, $vatCode);
        ib_redirect($result['ok'] ? ['saved' => '1'] : ['error' => 'amount']);
    }

    if ($action === 'mark_to_invoice') {
        $result = pz_billing_mark_to_invoice($pdo, $itemId);
        ib_redirect($result['ok'] ? ['saved' => '1'] : ['error' => 'value']);
    }

    if ($action === 'mark_to_review') {
        $result = pz_billing_mark_to_review($pdo, $itemId);
        ib_redirect($result['ok'] ? ['saved' => '1'] : ['error' => 'state']);
    }

    if ($action === 'mark_not_billable') {
        $reason = trim((string)($_POST['billing_note'] ?? ''));
        if ($reason === '') ib_redirect(['error' => 'note']);
        $result = pz_billing_mark_not_billable($pdo, $itemId, $reason);
        ib_redirect($result['ok'] ? ['saved' => '1'] : ['error' => 'note']);
    }

    if ($action === 'bulk_invoice') {
        $ids = array_map('intval', (array)($_POST['billing_item_ids'] ?? []));
        $ids = array_values(array_unique(array_filter($ids, static fn($v) => $v > 0)));
        if (!$ids) ib_redirect(['error' => 'select']);

        // Validare rapidă: același client. Redirect către invoice.php cu lista.
        $validation = pz_billing_validate_invoice_selection($pdo, $ids);
        if (!$validation['ok']) {
            ib_redirect(['error' => 'multi_client']);
        }
        $qs = http_build_query(['billing_item_ids' => $ids]);
        header('Location: invoice.php?' . $qs);
        exit;
    }

    ib_redirect(['error' => 'action']);
}

/* ============================================================
 * Filtre + parametrii GET
 * ============================================================ */
$today = date('Y-m-d');
$smartbillSettings = function_exists('pz_smartbill_settings') ? pz_smartbill_settings($pdo) : [];
$smartbillVatOptions = function_exists('pz_smartbill_vat_options') ? pz_smartbill_vat_options() : ['21' => '21%'];
$smartbillAllowedVatCodes = function_exists('pz_smartbill_allowed_vat_codes') ? pz_smartbill_allowed_vat_codes($smartbillSettings) : ['21'];
$smartbillDefaultVatCode = (string)($smartbillSettings['smartbill.default_vat_code'] ?? '21');
if (!isset($smartbillVatOptions[$smartbillDefaultVatCode])) $smartbillDefaultVatCode = '21';
if (!in_array($smartbillDefaultVatCode, $smartbillAllowedVatCodes, true)) {
    $smartbillAllowedVatCodes[] = $smartbillDefaultVatCode;
}

$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');
$prevMonthStart = date('Y-m-01', strtotime('first day of previous month'));
$prevMonthEnd = date('Y-m-t', strtotime('last day of previous month'));

$dateFrom = ib_safe_date($_GET['date_from'] ?? null, $currentMonthStart);
$dateTo = ib_safe_date($_GET['date_to'] ?? null, $currentMonthEnd);
if (strtotime($dateFrom) > strtotime($dateTo)) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

$q = trim((string)($_GET['q'] ?? ''));

$allowedStatuses = ['active', 'to_review', 'to_invoice', 'invoiced', 'not_billable', 'all'];
$selectedStatus = (string)($_GET['status'] ?? 'active');
if (!in_array($selectedStatus, $allowedStatuses, true)) $selectedStatus = 'active';

$selectedPv = (string)($_GET['pv'] ?? 'all');
if (!in_array($selectedPv, ['all', 'with_pv', 'without_pv'], true)) $selectedPv = 'all';

$selectedService = (string)($_GET['service'] ?? 'all');

$services = [];
if (ib_table_exists($pdo, 'services')) {
    try {
        $services = $pdo->query("SELECT name FROM services WHERE active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $services = [];
    }
}

$hasDocuments = ib_table_exists($pdo, 'documents');

/* ============================================================
 * SELECT principal — sursa: billing_items
 * ============================================================ */
$where = "bi.work_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= " AND (c.name LIKE ? OR c.fiscal_code LIKE ? OR l.location_name LIKE ? OR l.address LIKE ? OR bi.description LIKE ?";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
    if ($hasDocuments) {
        $where .= " OR pv.document_number LIKE ?";
        $params[] = $like;
    }
    $where .= ")";
}

if ($selectedService !== 'all') {
    $where .= " AND (a.service_type = ? OR bi.description = ?)";
    $params[] = $selectedService;
    $params[] = $selectedService;
}

if ($selectedPv === 'with_pv') {
    $where .= " AND bi.pv_document_id IS NOT NULL";
} elseif ($selectedPv === 'without_pv') {
    $where .= " AND bi.pv_document_id IS NULL";
}

$statusFilter = '';
$statusParams = [];
if ($selectedStatus === 'active') {
    $statusFilter = " AND bi.status IN ('to_review','to_invoice')";
} elseif ($selectedStatus === 'all') {
    $statusFilter = " AND bi.status <> 'cancelled'";
} elseif (in_array($selectedStatus, ['to_review', 'to_invoice', 'invoiced', 'not_billable'], true)) {
    $statusFilter = " AND bi.status = ?";
    $statusParams[] = $selectedStatus;
}

$pvJoin = $hasDocuments ? " LEFT JOIN documents pv ON pv.id = bi.pv_document_id " : "";
$pvSelect = $hasDocuments ? "pv.document_number AS pv_number, pv.id AS pv_id" : "NULL AS pv_number, NULL AS pv_id";

$select = "
    SELECT
        bi.*,
        c.name AS client_name,
        c.fiscal_code AS client_fiscal_code,
        l.location_name,
        l.address AS location_address,
        a.appointment_date,
        a.start_time,
        a.address,
        a.service_type,
        a.completion_notes,
        si.smartbill_series, si.smartbill_number, si.smartbill_status AS invoice_status,
        {$pvSelect}
    FROM billing_items bi
    LEFT JOIN clients c ON c.id = bi.client_id
    LEFT JOIN client_locations l ON l.id = bi.client_location_id
    LEFT JOIN appointments a ON a.id = bi.appointment_id
    LEFT JOIN smartbill_invoices si ON si.id = bi.smartbill_invoice_id
    {$pvJoin}
    WHERE {$where}{$statusFilter}
    ORDER BY bi.work_date DESC, bi.id DESC
    LIMIT 1000
";
try {
    $stmt = $pdo->prepare($select);
    $stmt->execute(array_merge($params, $statusParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('work_billing list error: ' . $e->getMessage());
    $rows = [];
}

/* ============================================================
 * Sumar pe statusuri pentru perioada selectată
 * ============================================================ */
$summary = [
    'to_review'    => ['count' => 0, 'amount' => 0.0],
    'to_invoice'   => ['count' => 0, 'amount' => 0.0],
    'invoiced'     => ['count' => 0, 'amount' => 0.0],
    'not_billable' => ['count' => 0, 'amount' => 0.0],
];
try {
    $sumSql = "
        SELECT bi.status, COUNT(*) AS total, COALESCE(SUM(bi.total_net), 0) AS amount_total
        FROM billing_items bi
        LEFT JOIN clients c ON c.id = bi.client_id
        LEFT JOIN client_locations l ON l.id = bi.client_location_id
        LEFT JOIN appointments a ON a.id = bi.appointment_id
        {$pvJoin}
        WHERE {$where}
          AND bi.status <> 'cancelled'
        GROUP BY bi.status
    ";
    $stmt = $pdo->prepare($sumSql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $srow) {
        $key = (string)$srow['status'];
        if (isset($summary[$key])) {
            $summary[$key]['count'] = (int)$srow['total'];
            $summary[$key]['amount'] = (float)$srow['amount_total'];
        }
    }
} catch (Throwable $e) {
    error_log('work_billing summary error: ' . $e->getMessage());
}

$totalCount = array_sum(array_column($summary, 'count'));
$totalAmount = array_sum(array_column($summary, 'amount'));

/* ============================================================
 * Export CSV
 * ============================================================ */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fileName = 'de_facturat_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Data', 'Client', 'Locație', 'Servicii', 'PV', 'Valoare', 'TVA', 'Status', 'Motiv nefacturabil']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['work_date'] ?? '',
            $row['client_name'] ?? '',
            ib_effective_location($row),
            ib_service_label($row),
            ib_pv_label($row),
            ib_money_input($row['total_net'] ?? 0),
            $row['vat_code'] ?? '',
            pz_billing_status_label((string)($row['status'] ?? 'to_review')),
            $row['not_billable_reason'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>De facturat</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">
<?php app_theme_css(); ?>
<style>
.ib-topbar { align-items:center; padding:10px 20px; background:transparent; border-bottom:0; }
.ib-toolbar { width:100%; min-width:0; display:flex; align-items:center; gap:8px; flex-wrap:nowrap; }
.ib-filters { width:100%; min-width:0; display:grid; grid-template-columns:132px 132px minmax(160px,1fr) minmax(150px,1fr) minmax(145px,1fr) minmax(150px,1fr) auto auto; gap:8px; align-items:center; background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:10px; }
.ib-filters input, .ib-filters select { height:32px; min-width:0; font-weight:800; border-radius:4px; font-size:12px; }
.ib-filters .btn { height:32px; white-space:nowrap; border-radius:4px; font-size:12px; }
.ib-hero { background:transparent; color:var(--text); border:0; border-radius:0; padding:4px 0 2px; box-shadow:none; margin-bottom:10px; display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; }
.ib-hero h1 { font-size:22px; font-weight:700; letter-spacing:0; margin:0; display:flex; align-items:center; gap:8px; }
.count-badge { display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:22px; border:1px solid var(--border); border-radius:6px; background:#fff; color:var(--text); font-size:12px; font-weight:850; }
.ib-hero p { color:var(--muted); margin:4px 0 0; max-width:820px; font-size:12px; font-weight:750; }
.ib-hero .hero-actions { display:flex; gap:8px; flex-wrap:wrap; }
.quick-range { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
.quick-range .btn { min-height:30px; padding:5px 9px; font-size:12px; border-radius:4px; }
.kpi-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; margin-bottom:10px; }
.kpi-card { background:var(--surface-soft); border:1px solid var(--border); border-radius:var(--pz-r); box-shadow:none; padding:9px; }
.kpi-label { font-size:9px; color:var(--muted); font-weight:950; text-transform:uppercase; letter-spacing:.025em; }
.kpi-value { margin-top:3px; font-size:18px; font-weight:700; color:var(--text); letter-spacing:0; }
.kpi-sub { margin-top:2px; color:var(--muted); font-size:11px; font-weight:750; }
.notice { border-radius:14px; padding:12px 14px; margin-bottom:14px; font-weight:800; border:1px solid var(--border); background:var(--surface); }
.notice-success { border-color:rgba(4,120,87,.24); background:var(--tone-success-bg); color:var(--tone-success); }
.notice-warning { border-color:rgba(180,83,9,.24); background:var(--tone-warning-bg); color:var(--tone-warning); }
.table-card { background:var(--surface); border:1px solid var(--border); border-radius:6px; box-shadow:none; overflow:hidden; }
.table-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:10px; border-bottom:1px solid var(--border); background:var(--surface); }
.table-title { font-weight:900; color:var(--text); }
.table-subtitle { color:var(--muted); font-size:12px; font-weight:700; margin-top:2px; }
.table-scroll { display:block; width:100%; max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
.ib-table { width:100%; min-width:1280px; border-collapse:collapse; }
.ib-table th { text-align:left; font-size:10px; color:var(--pz-mu); font-weight:700; text-transform:uppercase; letter-spacing:0; padding:7px 8px; border-bottom:1px solid var(--border); background:var(--pz-soft); }
.ib-table td { padding:7px 8px; border-bottom:1px solid var(--border); color:var(--text); font-size:12px; vertical-align:top; }
.ib-table tbody tr:hover { background:var(--pz-soft); }
.ib-table tr:last-child td { border-bottom:none; }
.work-list { display:none; gap:12px; padding:14px; }
.work-card { border:1px solid var(--border); border-radius:var(--pz-r); background:#fff; padding:14px; box-shadow:none; display:grid; gap:12px; }
.work-card:hover { border-color:var(--pz-blb); box-shadow:none; }
.work-main { display:grid; grid-template-columns:minmax(0,1.4fr) minmax(190px,.55fr); gap:14px; align-items:start; }
.work-title { font-size:15px; font-weight:950; color:var(--text); line-height:1.25; overflow-wrap:anywhere; }
.work-meta { margin-top:4px; color:var(--muted); font-size:13px; font-weight:750; line-height:1.35; }
.work-amount { border:1px solid var(--border); border-radius:14px; background:var(--surface-soft); padding:10px; }
.work-amount-label { color:var(--muted); font-size:11px; font-weight:950; text-transform:uppercase; letter-spacing:.04em; }
.work-amount-value { margin-top:4px; font-size:20px; font-weight:950; color:var(--text); }
.work-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; }
.work-box { border:1px solid var(--border); border-radius:var(--pz-rs); background:var(--surface-soft); padding:9px; min-width:0; }
.work-box span { display:block; color:var(--muted); font-size:10.5px; font-weight:950; text-transform:uppercase; letter-spacing:.04em; }
.work-box strong { display:block; margin-top:4px; color:var(--text); font-size:12.5px; line-height:1.3; overflow-wrap:anywhere; }
.work-actions { display:flex; gap:7px; flex-wrap:wrap; align-items:center; padding-top:2px; }
.work-actions form { margin:0; }
.cell-title { font-weight:900; color:var(--text); }
.cell-muted { color:var(--muted); font-size:13px; margin-top:3px; line-height:1.35; }
.status-pill { display:inline-flex; align-items:center; padding:3px 7px; min-width:70px; justify-content:center; border-radius:2px; border:1px solid var(--border2); font-size:10px; font-weight:950; white-space:nowrap; }
.status-pill.is-review { background:var(--pz-soft); color:var(--pz-mu); border-color:var(--pz-line); }
.status-pill.is-due { background:var(--pz-ors); color:var(--pz-or); border-color:var(--pz-orb); }
.status-pill.is-billed { background:var(--pz-grs); color:var(--pz-gr); border-color:var(--pz-grb); }
.status-pill.is-no-bill { background:var(--pz-soft); color:var(--pz-mu); border-color:var(--pz-line); }
.status-pill.is-cancelled { background:var(--pz-soft); color:var(--pz-mu); border-color:var(--pz-line); }
/* Select stilizat ca pill - inlocuieste cele doua butoane (Facturează / Nu se facturează) */
select.status-select { appearance:none; -webkit-appearance:none; -moz-appearance:none; padding:3px 22px 3px 9px; height:auto; min-height:0; min-width:115px; cursor:pointer; background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'><path fill='currentColor' d='M5 7L1.5 3.5h7z'/></svg>"); background-repeat:no-repeat; background-position:right 6px center; background-size:8px; }
select.status-select:hover { filter:brightness(0.97); }
select.status-select:focus { outline:2px solid rgba(37,99,235,.35); outline-offset:1px; }
.pv-link { font-weight:900; color:var(--accent); text-decoration:none; }
.pv-empty { color:var(--muted); font-weight:800; }
.note-cell { max-width:260px; font-size:13px; line-height:1.4; }
.note-cell.empty { color:var(--muted); }
.amount-form { display:flex; gap:6px; align-items:center; min-width:250px; flex-wrap:wrap; }
.amount-form input { width:104px; height:34px; border:1px solid var(--border); border-radius:var(--pz-rs); padding:0 8px; font-weight:900; font-family:var(--mono); }
.amount-form select { width:104px; height:34px; border:1px solid var(--border); border-radius:var(--pz-rs); padding:0 8px; font-weight:900; background:#fff; color:var(--text); }
.amount-save { min-width:34px; width:34px; padding:0; }
.actions-stack { display:flex; gap:7px; flex-wrap:wrap; align-items:flex-start; }
.inline-form { margin:0; display:inline-flex; gap:6px; align-items:center; }
.ib-small-btn { border:1px solid var(--border); background:var(--surface); color:var(--text); min-height:28px; border-radius:4px; padding:5px 8px; font-weight:900; font-size:11px; cursor:pointer; font-family:inherit; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
.ib-small-btn.success { background:var(--pz-grs); border-color:var(--pz-grb); color:var(--pz-gr); cursor:default; }
.ib-small-btn.warning { background:var(--pz-ors); border-color:var(--pz-orb); color:var(--pz-or); }
.ib-small-btn.warning:hover { background:var(--pz-or); color:#fff; }
.ib-small-btn.danger { background:var(--pz-res); border-color:var(--pz-reb); color:var(--pz-re); }
.ib-small-btn.danger:hover { background:var(--pz-re); color:#fff; }
.ib-small-btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.ib-small-btn.primary:hover { background:var(--accent-deep); border-color:var(--accent-deep); color:#fff; }
.ib-small-btn.muted { background:var(--surface-soft); color:var(--muted); }
.ib-small-btn.is-disabled, .ib-small-btn[disabled], .ib-small-btn:disabled { opacity:0.45; cursor:not-allowed; pointer-events:none; }
.skip-inline { display:none; gap:6px; align-items:center; }
.skip-inline.is-open { display:flex; }
.skip-inline input { flex:1; min-width:140px; max-width:240px; height:28px; border:1px solid var(--pz-orb); border-radius:4px; padding:0 8px; font-size:12px; background:#fff; }
.skip-inline input:focus { outline:2px solid rgba(180,83,9,.25); }
/* Cand form-ul motiv e deschis, inputul ia locul textului '-' (ascund [data-note-display]). */
.is-editing-note [data-note-display] { display:none; }
.empty-state { padding:34px; text-align:center; color:var(--muted); font-weight:800; }
.bulk-bar { display:flex; align-items:center; gap:10px; padding:8px 10px; background:var(--surface-soft); border:1px solid var(--border); border-radius:6px; margin-bottom:10px; flex-wrap:wrap; }
.bulk-bar .bulk-count { font-weight:900; }
.bulk-bar .bulk-spacer { flex:1; }
@media(max-width:1280px){ .ib-filters { grid-template-columns:repeat(4,minmax(0,1fr)); } .kpi-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media(max-width:980px){ .work-main,.work-grid { grid-template-columns:1fr; } }
@media(max-width:860px){ .ib-topbar { width:100%; max-width:100vw; padding:8px 10px 14px; display:block; position:relative; top:auto; } .ib-toolbar { display:block; } .ib-filters { grid-template-columns:1fr; } .ib-filters input,.ib-filters select,.ib-filters .btn,.ib-filters button { width:100%; max-width:100%; } .content { width:100%; max-width:100vw; overflow-x:hidden; } .quick-range { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); } .quick-range .btn { width:100%; } .ib-hero { padding:4px 0; } .kpi-grid { grid-template-columns:1fr; } .table-scroll { display:none; } .work-list { display:grid; padding:10px; } }
/* Resizable columns */
.ib-table.js-resizable { table-layout:fixed; }
.ib-table.js-resizable th { position:relative; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ib-table.js-resizable td { overflow:hidden; word-wrap:break-word; overflow-wrap:break-word; }
.col-resize-handle { position:absolute; top:0; right:0; width:8px; height:100%; cursor:col-resize; user-select:none; z-index:2; background:linear-gradient(to right, transparent 0%, transparent 40%, rgba(99,102,241,.35) 50%, transparent 60%, transparent 100%); }
.col-resize-handle:hover { background:rgba(37,99,235,.45); }
.col-resize-handle.is-active { background:rgba(37,99,235,.7); }
</style>
<?php render_search_preview_assets(); ?>
</head>
<body>
<div class="layout">
    <?php render_sidebar('interventii_facturare', $isAdmin); ?>
    <main class="main">
        <div class="topbar ib-topbar">
            <div class="ib-toolbar">
                <form method="get" class="ib-filters">
                    <input type="date" name="date_from" value="<?= ib_h($dateFrom) ?>" aria-label="Data început">
                    <input type="date" name="date_to" value="<?= ib_h($dateTo) ?>" aria-label="Data final">
                    <div class="pz-search-wrap">
                        <input type="search" id="workBillingSearchInput" name="q" value="<?= ib_h($q) ?>" placeholder="Caută" aria-label="Căutare" autocomplete="off">
                        <div class="pz-search-preview"></div>
                    </div>
                    <select name="status" aria-label="Status">
                        <option value="active"       <?= $selectedStatus === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="to_review"    <?= $selectedStatus === 'to_review' ? 'selected' : '' ?>>De verificat</option>
                        <option value="to_invoice"   <?= $selectedStatus === 'to_invoice' ? 'selected' : '' ?>>De facturat</option>
                        <option value="invoiced"     <?= $selectedStatus === 'invoiced' ? 'selected' : '' ?>>Facturate</option>
                        <option value="not_billable" <?= $selectedStatus === 'not_billable' ? 'selected' : '' ?>>Nefacturabile</option>
                        <option value="all"          <?= $selectedStatus === 'all' ? 'selected' : '' ?>>Toate</option>
                    </select>
                    <select name="service" aria-label="Serviciu">
                        <option value="all" <?= $selectedService === 'all' ? 'selected' : '' ?>>Toate serviciile</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?= ib_h($service['name']) ?>" <?= $selectedService === $service['name'] ? 'selected' : '' ?>><?= ib_h($service['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn accent" type="submit">Aplică</button>
                    <a class="btn" href="work_billing.php">Resetează</a>
                </form>
            </div>
        </div>

        <div class="content">
            <?php render_billing_module_nav('interventii_facturare'); ?>
            <?php if (isset($_GET['saved'])): ?><div class="notice notice-success">Poziția a fost actualizată.</div><?php endif; ?>
            <?php
                $errMessages = [
                    'invalid'      => 'Acțiune invalidă.',
                    'note'         => 'Pentru „Nu se facturează" trebuie completat motivul.',
                    'amount'       => 'Valoarea nu a putut fi salvată.',
                    'value'        => 'Poziția nu are valoare. Completează valoarea înainte de „De facturat".',
                    'state'        => 'Poziția nu mai poate fi modificată.',
                    'select'       => 'Selectează cel puțin o poziție pentru facturare.',
                    'multi_client' => 'Nu poți emite o singură factură pentru clienți diferiți.',
                    'action'       => 'Acțiune necunoscută.',
                ];
                $errCode = (string)($_GET['error'] ?? '');
                if ($errCode !== '' && isset($errMessages[$errCode])):
            ?>
                <div class="notice notice-warning"><?= ib_h($errMessages[$errCode]) ?></div>
            <?php endif; ?>

            <section class="ib-hero">
                <div>
                    <div class="pz-page-eyebrow">Financiar</div>
                    <h1>De facturat <span class="count-badge"><?= (int)count($rows) ?></span></h1>
                    <p>Poziții generate din programări finalizate. Selectează una sau mai multe poziții ale aceluiași client și emite factura.</p>
                </div>
                <div class="hero-actions">
                    <a class="btn light" href="<?= ib_h(ib_current_url(['export' => 'csv'])) ?>">Export CSV</a>
                </div>
            </section>

            <div class="quick-range">
                <a class="btn" href="work_billing.php?date_from=<?= ib_h($today) ?>&date_to=<?= ib_h($today) ?>&status=active&service=all&pv=all">Azi</a>
                <a class="btn" href="work_billing.php?date_from=<?= ib_h($currentMonthStart) ?>&date_to=<?= ib_h($currentMonthEnd) ?>&status=active&service=all&pv=all">Luna curentă</a>
                <a class="btn" href="work_billing.php?date_from=<?= ib_h($prevMonthStart) ?>&date_to=<?= ib_h($prevMonthEnd) ?>&status=active&service=all&pv=all">Luna trecută</a>
                <a class="btn" href="work_billing.php?date_from=<?= ib_h($dateFrom) ?>&date_to=<?= ib_h($dateTo) ?>&status=all&service=all&pv=all">Toate statusurile</a>
            </div>

            <section class="pz-kpi-grid">
                <div class="pz-kpi-card mu">
                    <div class="pz-kpi-label">Total poziții</div>
                    <div class="pz-kpi-value"><?= (int)$totalCount ?></div>
                    <div class="pz-kpi-sub mu"><?= ib_h(ib_money_label($totalAmount)) ?> fără TVA</div>
                </div>
                <div class="pz-kpi-card or">
                    <div class="pz-kpi-label">De verificat</div>
                    <div class="pz-kpi-value"><?= (int)$summary['to_review']['count'] ?></div>
                    <div class="pz-kpi-sub mu"><?= ib_h(ib_money_label($summary['to_review']['amount'])) ?> fără TVA</div>
                </div>
                <div class="pz-kpi-card bl">
                    <div class="pz-kpi-label">De facturat</div>
                    <div class="pz-kpi-value"><?= (int)$summary['to_invoice']['count'] ?></div>
                    <div class="pz-kpi-sub mu"><?= ib_h(ib_money_label($summary['to_invoice']['amount'])) ?> fără TVA</div>
                </div>
                <div class="pz-kpi-card gr">
                    <div class="pz-kpi-label">Facturate</div>
                    <div class="pz-kpi-value"><?= (int)$summary['invoiced']['count'] ?></div>
                    <div class="pz-kpi-sub mu"><?= ib_h(ib_money_label($summary['invoiced']['amount'])) ?> fără TVA</div>
                </div>
            </section>

            <form method="post" id="bulkForm" action="<?= ib_h(ib_current_url()) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="bulk_invoice">

                <div class="bulk-bar">
                    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:800;">
                        <input type="checkbox" id="bulkAll"> Selectează tot
                    </label>
                    <span class="bulk-count"><span id="bulkCount">0</span> selectate</span>
                    <span class="bulk-spacer"></span>
                    <button class="btn accent" type="submit">Facturează selecția</button>
                </div>

                <section class="table-card">
                    <div class="table-head">
                        <div>
                            <div class="table-title">Poziții de facturat</div>
                            <div class="table-subtitle"><?= count($rows) ?> rezultate. Selectează poziții ale aceluiași client pentru o factură combinată.</div>
                        </div>
                    </div>

                    <?php if (!$rows): ?>
                        <div class="empty-state">Nicio poziție pentru filtrele selectate.</div>
                    <?php else: ?>
                        <div class="work-list">
                        <?php foreach ($rows as $row):
                            $status = (string)($row['status'] ?? 'to_review');
                            $isInvoiced = $status === 'invoiced';
                            $isNotBillable = $status === 'not_billable';
                            $isDone = $isInvoiced || $isNotBillable;
                            $pvId = (int)($row['pv_id'] ?? 0);
                            $note = trim((string)($row['not_billable_reason'] ?? ''));
                            $invoiceLabel = trim((string)($row['smartbill_number'] ?? '')) !== ''
                                ? trim((string)(($row['smartbill_series'] ?? '') . ' ' . ($row['smartbill_number'] ?? '')))
                                : 'Draft factură';
                            $canSelect = !$isDone;
                        ?>
                            <article class="work-card">
                                <div class="work-main">
                                    <div style="display:flex;gap:10px;align-items:flex-start;">
                                        <?php if ($canSelect): ?>
                                            <input type="checkbox" name="billing_item_ids[]" value="<?= (int)$row['id'] ?>" class="js-bulk-check" data-client="<?= (int)$row['client_id'] ?>" style="margin-top:4px;width:18px;height:18px;">
                                        <?php endif; ?>
                                        <div>
                                            <div class="work-title"><?= ib_h($row['client_name'] ?: 'Client') ?></div>
                                            <div class="work-meta"><?= ib_h($row['work_date'] ?? '-') ?> · <?= ib_h(ib_service_label($row)) ?></div>
                                            <div class="work-meta"><?= ib_h(ib_effective_location($row)) ?></div>
                                        </div>
                                    </div>
                                    <div class="work-amount">
                                        <div class="work-amount-label">Valoare</div>
                                        <div class="work-amount-value"><?= ib_h(ib_money_label($row['total_net'] ?? 0)) ?></div>
                                        <?php if (!$isDone): ?>
                                            <form method="post" class="amount-form js-amount-autosave" action="<?= ib_h(ib_current_url()) ?>" style="margin-top:9px">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="save_amount">
                                                <input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="billing_vat_code" value="<?= ib_h((string)$row['vat_code'] ?: $smartbillDefaultVatCode) ?>">
                                                <input type="number" name="billing_amount" step="0.01" min="0" value="<?= ib_h(ib_money_input($row['total_net'] ?? 0)) ?>" aria-label="Valoare fără TVA" placeholder="0,00">
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="work-grid">
                                    <div class="work-box">
                                        <span>Status</span>
                                        <strong>
                                            <?php if ($isInvoiced): ?>
                                                <span class="status-pill <?= ib_h(pz_billing_status_class($status)) ?>"><?= ib_h(pz_billing_status_label($status)) ?></span>
                                            <?php else: ?>
                                                <select class="status-select status-pill <?= ib_h(pz_billing_status_class($status)) ?> js-status-select"
                                                        data-item-id="<?= (int)$row['id'] ?>"
                                                        data-original="<?= ib_h($status) ?>">
                                                    <option value="to_review"    <?= $status === 'to_review' ? 'selected' : '' ?>>De verificat</option>
                                                    <option value="to_invoice"   <?= $status === 'to_invoice' ? 'selected' : '' ?>>De facturat</option>
                                                    <option value="not_billable" <?= $status === 'not_billable' ? 'selected' : '' ?>>Nu se facturează</option>
                                                </select>
                                            <?php endif; ?>
                                        </strong>
                                    </div>
                                    <div class="work-box">
                                        <span>PV</span>
                                        <strong>
                                            <?php if ($pvId > 0): ?>
                                                <a class="pv-link" href="document_view.php?id=<?= (int)$pvId ?>" target="_blank" rel="noopener"><?= ib_h(ib_pv_label($row)) ?></a>
                                            <?php else: ?>
                                                <span class="pv-empty">Fără PV</span>
                                            <?php endif; ?>
                                        </strong>
                                    </div>
                                    <div class="work-box">
                                        <span>Factură</span>
                                        <strong>
                                            <?php if (!empty($row['smartbill_invoice_id'])): ?>
                                                <a class="pv-link" href="invoice.php?id=<?= (int)$row['smartbill_invoice_id'] ?>"><?= ib_h($invoiceLabel) ?></a>
                                            <?php else: ?>
                                                Nepornită
                                            <?php endif; ?>
                                        </strong>
                                    </div>
                                    <div class="work-box">
                                        <span>Motiv / Observații</span>
                                        <strong data-note-display><?= $note !== '' ? ib_h($note) : '-' ?></strong>
                                        <?php if (!$isInvoiced): ?>
                                            <form method="post" class="skip-inline" data-skip-form="<?= (int)$row['id'] ?>" action="<?= ib_h(ib_current_url()) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_not_billable">
                                                <input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>">
                                                <input type="text" name="billing_note" placeholder="Motiv obligatoriu" required>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="work-actions">
                                    <?php if ($isInvoiced && !empty($row['smartbill_invoice_id'])): ?>
                                        <a class="ib-small-btn muted" href="invoice.php?id=<?= (int)$row['smartbill_invoice_id'] ?>" title="Deschide factura"><?= ib_h($invoiceLabel) ?></a>
                                    <?php elseif ($isNotBillable): ?>
                                        <span class="ib-small-btn muted is-disabled" aria-disabled="true">Marcată ca nefacturabilă</span>
                                    <?php else: ?>
                                        <a class="ib-small-btn primary" href="invoice.php?<?= http_build_query(['billing_item_ids' => [(int)$row['id']]]) ?>">Facturează</a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        </div>
                        <div class="table-scroll">
                            <table class="ib-table js-resizable">
                                <thead>
                                    <tr>
                                        <th style="width:34px;"></th>
                                        <th data-col="data">Data</th>
                                        <th data-col="client">Client</th>
                                        <th data-col="locatie">Locație</th>
                                        <th data-col="servicii">Servicii</th>
                                        <th data-col="pv">PV</th>
                                        <th data-col="valoare">Valoare</th>
                                        <th data-col="status">Status</th>
                                        <th data-col="observatii">Motiv / Observații</th>
                                        <th data-col="actiuni">Acțiuni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row):
                                    $status = (string)($row['status'] ?? 'to_review');
                                    $isInvoiced = $status === 'invoiced';
                                    $isNotBillable = $status === 'not_billable';
                                    $isDone = $isInvoiced || $isNotBillable;
                                    $pvId = (int)($row['pv_id'] ?? 0);
                                    $note = trim((string)($row['not_billable_reason'] ?? ''));
                                    $invoiceLabel = trim((string)($row['smartbill_number'] ?? '')) !== ''
                                        ? trim((string)(($row['smartbill_series'] ?? '') . ' ' . ($row['smartbill_number'] ?? '')))
                                        : 'Draft factură';
                                    $canSelect = !$isDone;
                                ?>
                                    <tr>
                                        <td>
                                            <?php if ($canSelect): ?>
                                                <input type="checkbox" name="billing_item_ids[]" value="<?= (int)$row['id'] ?>" class="js-bulk-check" data-client="<?= (int)$row['client_id'] ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="cell-title"><?= ib_h($row['work_date'] ?? '-') ?></div>
                                            <?php if (!empty($row['start_time'])): ?><div class="cell-muted"><?= ib_h(substr((string)$row['start_time'], 0, 5)) ?></div><?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="cell-title"><?= ib_h($row['client_name'] ?: 'Client') ?></div>
                                        </td>
                                        <td>
                                            <div class="cell-title"><?= ib_h(ib_effective_location($row)) ?></div>
                                            <?php if (!empty($row['location_address']) || !empty($row['address'])): ?><div class="cell-muted"><?= ib_h($row['location_address'] ?: $row['address']) ?></div><?php endif; ?>
                                        </td>
                                        <td><?= ib_h(ib_service_label($row)) ?></td>
                                        <td>
                                            <?php if ($pvId > 0): ?>
                                                <a class="pv-link" href="document_view.php?id=<?= (int)$pvId ?>" target="_blank" rel="noopener"><?= ib_h(ib_pv_label($row)) ?></a>
                                            <?php else: ?>
                                                <span class="pv-empty">Fără PV</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isDone): ?>
                                                <strong><?= ib_h(ib_money_label($row['total_net'] ?? 0)) ?></strong>
                                            <?php else: ?>
                                                <form method="post" class="amount-form js-amount-autosave" action="<?= ib_h(ib_current_url()) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="save_amount">
                                                    <input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>">
                                                    <input type="hidden" name="billing_vat_code" value="<?= ib_h((string)$row['vat_code'] ?: $smartbillDefaultVatCode) ?>">
                                                    <input type="number" name="billing_amount" step="0.01" min="0" value="<?= ib_h(ib_money_input($row['total_net'] ?? 0)) ?>" aria-label="Valoare fără TVA" placeholder="0,00">
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isInvoiced): ?>
                                                <span class="status-pill <?= ib_h(pz_billing_status_class($status)) ?>"><?= ib_h(pz_billing_status_label($status)) ?></span>
                                            <?php else: ?>
                                                <select class="status-select status-pill <?= ib_h(pz_billing_status_class($status)) ?> js-status-select"
                                                        data-item-id="<?= (int)$row['id'] ?>"
                                                        data-original="<?= ib_h($status) ?>">
                                                    <option value="to_review"    <?= $status === 'to_review' ? 'selected' : '' ?>>De verificat</option>
                                                    <option value="to_invoice"   <?= $status === 'to_invoice' ? 'selected' : '' ?>>De facturat</option>
                                                    <option value="not_billable" <?= $status === 'not_billable' ? 'selected' : '' ?>>Nu se facturează</option>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="note-cell <?= $note === '' ? 'empty' : '' ?>" data-note-display><?= $note !== '' ? nl2br(ib_h($note)) : '-' ?></div>
                                            <?php if (!$isInvoiced): ?>
                                                <form method="post" class="skip-inline" data-skip-form="<?= (int)$row['id'] ?>" action="<?= ib_h(ib_current_url()) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="mark_not_billable">
                                                    <input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>">
                                                    <input type="text" name="billing_note" placeholder="Motiv obligatoriu" required>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isInvoiced && !empty($row['smartbill_invoice_id'])): ?>
                                                <a class="ib-small-btn muted" href="invoice.php?id=<?= (int)$row['smartbill_invoice_id'] ?>"><?= ib_h($invoiceLabel) ?></a>
                                            <?php elseif ($isNotBillable): ?>
                                                <span class="ib-small-btn muted is-disabled" aria-disabled="true">Nefacturabilă</span>
                                            <?php else: ?>
                                                <a class="ib-small-btn primary" href="invoice.php?<?= http_build_query(['billing_item_ids' => [(int)$row['id']]]) ?>">Facturează</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </form>
        </div>
    </main>
</div>
<script>
(function () {
    // Helper: construieste si trimite un form POST cu actiune + item_id (+ optional billing_note)
    function submitStatusChange(itemId, action, extraField) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.pathname + window.location.search;
        form.style.display = 'none';

        var csrf = document.querySelector('input[name="csrf_token"]');
        if (csrf) {
            var csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = csrf.value;
            form.appendChild(csrfInput);
        }

        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = action;
        form.appendChild(actionInput);

        var idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'item_id';
        idInput.value = itemId;
        form.appendChild(idInput);

        if (extraField) {
            var extra = document.createElement('input');
            extra.type = 'hidden';
            extra.name = extraField.name;
            extra.value = extraField.value;
            form.appendChild(extra);
        }

        document.body.appendChild(form);
        form.submit();
    }

    // Schimbare status din selector inline (inlocuieste cele doua butoane vechi).
    // Pentru 'Nu se factureaza': in loc de prompt, deschide input-ul din coloana
    // Motiv si focus la el. Submit la Enter / blur cu valoare; revert la Esc / gol.
    function findSkipForms(itemId) {
        return document.querySelectorAll('form[data-skip-form="' + itemId + '"]');
    }

    document.querySelectorAll('.js-status-select').forEach(function (select) {
        select.addEventListener('change', function () {
            var newStatus = select.value;
            var original = select.dataset.original;
            var itemId = select.dataset.itemId;
            if (newStatus === original) return;

            if (newStatus === 'not_billable') {
                // Deschide form-ul din coloana Motiv (atat tabel cat si card).
                var forms = findSkipForms(itemId);
                if (forms.length === 0) { select.value = original; return; }
                forms.forEach(function (form) {
                    form.classList.add('is-open');
                    // Marcheaza celula ca fiind in edit -> ascunde [data-note-display]
                    var cell = form.parentElement;
                    if (cell) cell.classList.add('is-editing-note');
                });
                // Focus la primul input vizibil
                var firstVisibleInput = null;
                forms.forEach(function (form) {
                    if (form.offsetParent !== null && !firstVisibleInput) {
                        firstVisibleInput = form.querySelector('input[name="billing_note"]');
                    }
                });
                if (firstVisibleInput) firstVisibleInput.focus();
                return;
            }
            if (newStatus === 'to_invoice') {
                submitStatusChange(itemId, 'mark_to_invoice');
                return;
            }
            if (newStatus === 'to_review') {
                submitStatusChange(itemId, 'mark_to_review');
                return;
            }
            select.value = original;
        });
    });

    // Handle Enter / Esc / blur in input-urile de motiv din skip-inline.
    document.querySelectorAll('form[data-skip-form] input[name="billing_note"]').forEach(function (input) {
        var form = input.closest('form');
        var itemId = form.getAttribute('data-skip-form');

        function revertSelect() {
            document.querySelectorAll('.js-status-select').forEach(function (sel) {
                if (sel.dataset.itemId === itemId) {
                    sel.value = sel.dataset.original;
                }
            });
            form.classList.remove('is-open');
            var cell = form.parentElement;
            if (cell) cell.classList.remove('is-editing-note');
        }

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (input.value.trim() === '') {
                    input.focus();
                    return;
                }
                form.submit();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                input.value = '';
                revertSelect();
            }
        });
        input.addEventListener('blur', function () {
            // Lasam 200ms ca sa nu se inchida cand dai click pe Submit-ul invizibil sau alt control.
            setTimeout(function () {
                if (document.activeElement === input) return;
                var val = input.value.trim();
                if (val === '') {
                    revertSelect();
                } else {
                    form.submit();
                }
            }, 150);
        });
    });

    // Auto-save pe blur sau Enter pentru valoarea de facturat (am scos butonul OK).
    // Memoreaza valoarea initiala; daca s-a schimbat la blur/Enter, submit form-ul.
    document.querySelectorAll('.js-amount-autosave input[name="billing_amount"]').forEach(function (input) {
        var initial = input.value;
        input.addEventListener('focus', function () { initial = input.value; });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                input.blur();
            }
        });
        input.addEventListener('blur', function () {
            if (input.value === initial) return;
            if (input.value === '' || isNaN(parseFloat(input.value))) {
                input.value = initial;
                return;
            }
            input.closest('form').submit();
        });
    });

    // Selecție în masă: validare client unic + actualizare contor
    var bulkAll = document.getElementById('bulkAll');
    var checks = document.querySelectorAll('.js-bulk-check');
    var counter = document.getElementById('bulkCount');
    var bulkForm = document.getElementById('bulkForm');

    function updateCount() {
        var selected = document.querySelectorAll('.js-bulk-check:checked');
        if (counter) counter.textContent = String(selected.length);
        var clients = new Set();
        selected.forEach(function (c) { clients.add(c.getAttribute('data-client')); });
        // Marchează vizual conflictele dacă există mai mulți clienți
        document.querySelectorAll('.js-bulk-check').forEach(function (c) {
            c.style.outline = '';
        });
        if (clients.size > 1) {
            selected.forEach(function (c) { c.style.outline = '2px solid #e11d48'; });
        }
    }

    if (bulkAll) {
        bulkAll.addEventListener('change', function () {
            checks.forEach(function (c) { c.checked = bulkAll.checked; });
            updateCount();
        });
    }
    checks.forEach(function (c) {
        c.addEventListener('change', updateCount);
    });

    if (bulkForm) {
        bulkForm.addEventListener('submit', function (e) {
            var selected = document.querySelectorAll('.js-bulk-check:checked');
            if (selected.length === 0) {
                e.preventDefault();
                alert('Selectează cel puțin o poziție.');
                return;
            }
            var clients = new Set();
            selected.forEach(function (c) { clients.add(c.getAttribute('data-client')); });
            if (clients.size > 1) {
                e.preventDefault();
                alert('Nu poți emite o singură factură pentru clienți diferiți.');
            }
        });
    }
})();
</script>

<?php
// Preview live pentru bara „Caută" din pagina De facturat.
$previewWorkItems = [];
try {
    $stmtPrev = $pdo->query("
        SELECT bi.id, bi.work_date, c.name AS client_name, c.fiscal_code
        FROM billing_items bi
        LEFT JOIN clients c ON c.id = bi.client_id
        WHERE bi.status <> 'cancelled'
        ORDER BY bi.work_date DESC, bi.id DESC
        LIMIT 2000
    ");
    while ($r = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
        $nm = html_entity_decode((string)($r['client_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cf = html_entity_decode((string)($r['fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $previewWorkItems[] = [
            'title'  => ((string)$r['work_date']) . ' · ' . $nm,
            'url'    => 'work_billing.php?q=' . urlencode($nm),
            'type'   => 'invoice',
            'search' => $nm . ' ' . $cf,
        ];
    }
} catch (Throwable $e) { error_log('work_billing.php preview: ' . $e->getMessage()); }
?>
<script>
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('workBillingSearchInput',
            <?= json_encode($previewWorkItems, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
    };
    go();
})();
</script>
<script>
// Coloane redimensionabile pentru tabelul Lista lucrări
// - drag pe marginea dreaptă a fiecărui <th> pentru redimensionare
// - dublu-click pe handle: reset la auto pentru acea coloană
// - lățimile preferate salvate în localStorage pe utilizator
(function () {
    function initResizable() {
        var table = document.querySelector('.ib-table.js-resizable');
        if (!table) return;
        var STORAGE_KEY = 'workBillingColWidths_v1';
        var saved = {};
        try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {}; } catch (e) { saved = {}; }

        var ths = Array.prototype.slice.call(table.querySelectorAll('thead th'));

        // 1) Îngheață lățimile actuale (auto -> px) pentru toate coloanele,
        //    ca să avem o referință stabilă când trecem la table-layout:fixed.
        ths.forEach(function (th) {
            var key = th.getAttribute('data-col');
            if (key && saved[key] && saved[key] > 40) {
                th.style.width = saved[key] + 'px';
            } else if (!th.style.width) {
                th.style.width = th.offsetWidth + 'px';
            }
        });

        // 2) Adaugă handle de redimensionare pentru fiecare th cu data-col
        ths.forEach(function (th) {
            var key = th.getAttribute('data-col');
            if (!key) return;

            var handle = document.createElement('div');
            handle.className = 'col-resize-handle';
            handle.title = 'Trage pentru a redimensiona. Dublu-click pentru reset.';
            th.appendChild(handle);

            var startX = 0;
            var startWidth = 0;

            handle.addEventListener('mousedown', function (e) {
                startX = e.clientX;
                startWidth = th.offsetWidth;
                handle.classList.add('is-active');
                document.body.style.userSelect = 'none';
                document.body.style.cursor = 'col-resize';

                function onMove(ev) {
                    var delta = ev.clientX - startX;
                    var newWidth = Math.max(60, startWidth + delta);
                    th.style.width = newWidth + 'px';
                }
                function onUp() {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    handle.classList.remove('is-active');
                    document.body.style.userSelect = '';
                    document.body.style.cursor = '';
                    saved[key] = th.offsetWidth;
                    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(saved)); } catch (e) {}
                }
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
                e.preventDefault();
                e.stopPropagation();
            });

            handle.addEventListener('dblclick', function (e) {
                e.preventDefault();
                e.stopPropagation();
                delete saved[key];
                try { localStorage.setItem(STORAGE_KEY, JSON.stringify(saved)); } catch (err) {}
                // reload pentru a recalcula lățimea naturală
                window.location.reload();
            });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initResizable);
    } else {
        initResizable();
    }
})();
</script>
