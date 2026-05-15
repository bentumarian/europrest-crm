<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

function ib_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ib_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) > 0;
}

function ib_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) > 0;
}

function ib_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!ib_column_exists($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            error_log('interventii_facturare add column error: ' . $e->getMessage());
        }
    }
}

function ib_safe_date(?string $date, ?string $fallback = null): string {
    $fallback = $fallback ?: date('Y-m-d');
    if (!$date) return $fallback;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $date : $fallback;
}

function ib_status_label(string $status): string {
    return [
        'de_facturat' => 'De facturat',
        'facturata' => 'Facturata',
        'nu_se_factureaza' => 'Nu se factureaza',
    ][$status] ?? 'De facturat';
}

function ib_status_class(string $status): string {
    return [
        'de_facturat' => 'is-due',
        'facturata' => 'is-billed',
        'nu_se_factureaza' => 'is-no-bill',
    ][$status] ?? 'is-due';
}

function ib_current_url(array $extra = []): string {
    $params = $_GET;
    unset($params['saved'], $params['error'], $params['export']);
    foreach ($extra as $key => $value) {
        if ($value === null) unset($params[$key]); else $params[$key] = $value;
    }
    return 'interventii_facturare.php' . ($params ? '?' . http_build_query($params) : '');
}

function ib_redirect(array $extra = []): void {
    header('Location: ' . ib_current_url($extra));
    exit;
}

function ib_effective_location(array $row): string {
    $locationName = trim((string)($row['location_name'] ?? ''));
    if ($locationName !== '') return $locationName;
    $address = trim((string)($row['address'] ?? ''));
    if ($address !== '') return $address;
    return 'Sediu / domiciliu';
}

function ib_service_label(array $row): string {
    $service = trim((string)($row['service_type'] ?? ''));
    if ($service !== '') return $service;
    $title = trim((string)($row['title'] ?? ''));
    return $title !== '' ? $title : '-';
}

function ib_pv_label(array $row): string {
    $pvNumber = trim((string)($row['pv_number'] ?? ''));
    if ($pvNumber !== '') return $pvNumber;
    $pvId = (int)($row['pv_id'] ?? 0);
    return $pvId > 0 ? 'PV #' . $pvId : 'Fara PV';
}

function ib_money_value($value): float {
    if ($value === null || $value === '') return 0.0;
    if (is_string($value)) {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
    }
    if (!is_numeric($value)) return 0.0;
    return max(0, round((float)$value, 2));
}

function ib_money_label($value): string {
    return number_format(ib_money_value($value), 2, ',', '.') . ' lei';
}

function ib_money_input($value): string {
    return number_format(ib_money_value($value), 2, '.', '');
}

if (!ib_table_exists($pdo, 'appointments')) {
    die('Tabelul appointments lipseste.');
}

ib_ensure_column($pdo, 'appointments', 'billing_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
ib_ensure_column($pdo, 'appointments', 'billing_status', "VARCHAR(30) NOT NULL DEFAULT 'de_facturat'");
ib_ensure_column($pdo, 'appointments', 'billing_note', "TEXT NULL");
ib_ensure_column($pdo, 'appointments', 'billing_updated_at', "DATETIME NULL");
ib_ensure_column($pdo, 'appointments', 'billing_updated_by', "INT NULL");

try {
    $pdo->exec("UPDATE appointments SET billing_status = 'de_facturat' WHERE billing_status IS NULL OR billing_status = '' OR billing_status NOT IN ('de_facturat','facturata','nu_se_factureaza')");
} catch (Throwable $e) {
    error_log('interventii_facturare normalize status error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);

    if ($appointmentId <= 0) {
        ib_redirect(['error' => 'invalid']);
    }

    if ($action === 'save_amount') {
        $amount = ib_money_value($_POST['billing_amount'] ?? 0);
        $stmt = $pdo->prepare("
            UPDATE appointments
            SET billing_amount = ?,
                billing_updated_at = NOW(),
                billing_updated_by = ?
            WHERE id = ? AND status = 'finalizata'
        ");
        $stmt->execute([$amount, current_user_id(), $appointmentId]);
        ib_redirect(['saved' => '1']);
    }

    if ($action === 'mark_billed') {
        $stmt = $pdo->prepare("\n            UPDATE appointments\n            SET billing_status = 'facturata',\n                billing_note = NULL,\n                billing_updated_at = NOW(),\n                billing_updated_by = ?\n            WHERE id = ? AND status = 'finalizata'\n        ");
        $stmt->execute([current_user_id(), $appointmentId]);
        ib_redirect(['saved' => '1']);
    }

    if ($action === 'mark_not_billable') {
        $note = trim((string)($_POST['billing_note'] ?? ''));
        if ($note === '') {
            ib_redirect(['error' => 'note']);
        }
        $stmt = $pdo->prepare("\n            UPDATE appointments\n            SET billing_status = 'nu_se_factureaza',\n                billing_note = ?,\n                billing_updated_at = NOW(),\n                billing_updated_by = ?\n            WHERE id = ? AND status = 'finalizata'\n        ");
        $stmt->execute([$note, current_user_id(), $appointmentId]);
        ib_redirect(['saved' => '1']);
    }

    if ($action === 'reset_due') {
        $stmt = $pdo->prepare("\n            UPDATE appointments\n            SET billing_status = 'de_facturat',\n                billing_note = NULL,\n                billing_updated_at = NOW(),\n                billing_updated_by = ?\n            WHERE id = ? AND status = 'finalizata'\n        ");
        $stmt->execute([current_user_id(), $appointmentId]);
        ib_redirect(['saved' => '1']);
    }

    ib_redirect(['error' => 'action']);
}

$today = date('Y-m-d');
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');
$prevMonthStart = date('Y-m-01', strtotime('first day of previous month'));
$prevMonthEnd = date('Y-m-t', strtotime('last day of previous month'));

$dateFrom = ib_safe_date($_GET['date_from'] ?? null, $currentMonthStart);
$dateTo = ib_safe_date($_GET['date_to'] ?? null, $currentMonthEnd);
if (strtotime($dateFrom) > strtotime($dateTo)) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$q = trim((string)($_GET['q'] ?? ''));
$selectedStatus = (string)($_GET['billing_status'] ?? 'de_facturat');
if (!in_array($selectedStatus, ['all', 'de_facturat', 'facturata', 'nu_se_factureaza'], true)) {
    $selectedStatus = 'de_facturat';
}
$selectedService = (string)($_GET['service'] ?? 'all');
$selectedPv = (string)($_GET['pv'] ?? 'all');
if (!in_array($selectedPv, ['all', 'with_pv', 'without_pv'], true)) {
    $selectedPv = 'all';
}

$services = [];
if (ib_table_exists($pdo, 'services')) {
    try {
        $services = $pdo->query("SELECT name FROM services WHERE active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $services = [];
    }
}

$hasDocuments = ib_table_exists($pdo, 'documents');
$pvJoin = '';
$pvSelect = "NULL AS pv_id, NULL AS pv_number, NULL AS pv_status";
if ($hasDocuments) {
    $pvJoin = "\n        LEFT JOIN (\n            SELECT appointment_id, MAX(id) AS pv_id\n            FROM documents\n            WHERE document_type = 'proces_verbal'\n              AND appointment_id IS NOT NULL\n              AND status <> 'cancelled'\n            GROUP BY appointment_id\n        ) pvx ON pvx.appointment_id = a.id\n        LEFT JOIN documents pv ON pv.id = pvx.pv_id\n    ";
    $pvSelect = "pv.id AS pv_id, pv.document_number AS pv_number, pv.status AS pv_status";
}

$where = "WHERE a.status = 'finalizata' AND a.appointment_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($q !== '') {
    $where .= " AND (\n        c.name LIKE ? OR\n        c.fiscal_code LIKE ? OR\n        l.location_name LIKE ? OR\n        l.address LIKE ? OR\n        a.address LIKE ? OR\n        a.service_type LIKE ?" . ($hasDocuments ? " OR pv.document_number LIKE ?" : "") . "\n    )";
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    if ($hasDocuments) $params[] = $like;
}

if ($selectedService !== 'all') {
    $where .= " AND a.service_type = ?";
    $params[] = $selectedService;
}

if ($selectedPv === 'with_pv') {
    $where .= $hasDocuments ? " AND pv.id IS NOT NULL" : " AND 1 = 0";
} elseif ($selectedPv === 'without_pv') {
    $where .= $hasDocuments ? " AND pv.id IS NULL" : "";
}

$statusWhere = $where;
$statusParams = $params;
if ($selectedStatus !== 'all') {
    $statusWhere .= " AND a.billing_status = ?";
    $statusParams[] = $selectedStatus;
}

$baseSelect = "\n    SELECT\n        a.id, a.client_id, a.client_location_id, a.title, a.service_type,\n        a.appointment_date, a.start_time, a.end_time, a.address, a.notes, a.completion_notes,\n        a.billing_amount, a.billing_status, a.billing_note, a.billing_updated_at,\n        c.name AS client_name, c.phone AS client_phone, c.fiscal_code AS client_fiscal_code,\n        l.location_name, l.address AS location_address,\n        {$pvSelect}\n    FROM appointments a\n    LEFT JOIN clients c ON c.id = a.client_id\n    LEFT JOIN client_locations l ON l.id = a.client_location_id\n    {$pvJoin}\n    {$statusWhere}\n    ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC\n    LIMIT 1000\n";
$stmt = $pdo->prepare($baseSelect);
$stmt->execute($statusParams);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'de_facturat' => ['count' => 0, 'amount' => 0.0],
    'facturata' => ['count' => 0, 'amount' => 0.0],
    'nu_se_factureaza' => ['count' => 0, 'amount' => 0.0],
];
try {
    $summarySql = "
        SELECT a.billing_status, COUNT(*) AS total, COALESCE(SUM(a.billing_amount), 0) AS amount_total
        FROM appointments a
        LEFT JOIN clients c ON c.id = a.client_id
        LEFT JOIN client_locations l ON l.id = a.client_location_id
        {$pvJoin}
        {$where}
        GROUP BY a.billing_status
    ";
    $stmt = $pdo->prepare($summarySql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $srow) {
        $key = (string)($srow['billing_status'] ?? 'de_facturat');
        if (isset($summary[$key])) {
            $summary[$key]['count'] = (int)($srow['total'] ?? 0);
            $summary[$key]['amount'] = (float)($srow['amount_total'] ?? 0);
        }
    }
} catch (Throwable $e) {
    error_log('interventii_facturare summary error: ' . $e->getMessage());
}

$totalAllStatuses = array_sum(array_column($summary, 'count'));
$totalAllAmount = array_sum(array_column($summary, 'amount'));

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fileName = 'interventii_facturare_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Data', 'Ora', 'Client', 'Locatie', 'Servicii', 'PV', 'Valoare fara TVA', 'Status facturare', 'Observatii']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['appointment_date'] ?? '',
            substr((string)($row['start_time'] ?? ''), 0, 5),
            $row['client_name'] ?? '',
            ib_effective_location($row),
            ib_service_label($row),
            ib_pv_label($row),
            ib_money_input($row['billing_amount'] ?? 0),
            ib_status_label((string)($row['billing_status'] ?? 'de_facturat')),
            $row['billing_note'] ?? '',
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
<title>Facturare</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<?php app_theme_css(); ?>
<style>
.ib-topbar { align-items:center; padding:12px 20px; }
.ib-toolbar { width:100%; min-width:0; display:flex; align-items:center; gap:8px; flex-wrap:nowrap; }
.ib-filters { width:100%; min-width:0; display:grid; grid-template-columns:132px 132px minmax(160px,1fr) minmax(150px,1fr) minmax(145px,1fr) minmax(150px,1fr) auto auto; gap:8px; align-items:center; }
.ib-filters input, .ib-filters select { height:42px; min-width:0; font-weight:800; }
.ib-filters .btn { height:42px; white-space:nowrap; }
.ib-hero { background:var(--surface); color:var(--text); border:1px solid var(--border); border-radius:var(--radius-lg); padding:16px 18px; box-shadow:var(--shadow); margin-bottom:16px; display:flex; justify-content:space-between; gap:18px; flex-wrap:wrap; align-items:center; }
.ib-hero h1 { font-size:24px; font-weight:900; letter-spacing:-.03em; margin:0; }
.ib-hero p { color:var(--muted); margin:4px 0 0; max-width:820px; }
.ib-hero .hero-actions { display:flex; gap:8px; flex-wrap:wrap; }
.quick-range { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.kpi-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:16px; }
.kpi-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); box-shadow:var(--shadow); padding:16px; }
.kpi-label { font-size:12px; color:var(--muted); font-weight:900; text-transform:uppercase; letter-spacing:.05em; }
.kpi-value { margin-top:8px; font-size:30px; font-weight:900; color:var(--text); letter-spacing:-.04em; }
.kpi-sub { margin-top:4px; color:var(--muted); font-size:13px; font-weight:700; }
.notice { border-radius:14px; padding:12px 14px; margin-bottom:14px; font-weight:800; border:1px solid var(--border); background:var(--surface); }
.notice-success { border-color:rgba(4,120,87,.24); background:var(--tone-success-bg); color:var(--tone-success); }
.notice-warning { border-color:rgba(180,83,9,.24); background:var(--tone-warning-bg); color:var(--tone-warning); }
.table-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); box-shadow:var(--shadow); overflow:hidden; }
.table-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:14px 16px; border-bottom:1px solid var(--border); background:var(--surface-soft); }
.table-title { font-weight:900; color:var(--text); }
.table-subtitle { color:var(--muted); font-size:13px; font-weight:700; margin-top:2px; }
.table-scroll { width:100%; max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
.ib-table { width:100%; min-width:1280px; border-collapse:collapse; }
.ib-table th { text-align:left; font-size:12px; color:var(--muted); font-weight:900; text-transform:uppercase; letter-spacing:.04em; padding:13px 16px; border-bottom:1px solid var(--border); background:var(--surface-soft); }
.ib-table td { padding:13px 16px; border-bottom:1px solid var(--border2); color:var(--text); font-size:14px; vertical-align:top; }
.ib-table tr:last-child td { border-bottom:none; }
.cell-title { font-weight:900; color:var(--text); }
.cell-muted { color:var(--muted); font-size:13px; margin-top:3px; line-height:1.35; }
.status-pill { display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px; border:1px solid var(--border2); font-size:12px; font-weight:900; white-space:nowrap; }
.status-pill.is-due { background:var(--tone-warning-bg); color:var(--tone-warning); border-color:rgba(180,83,9,.22); }
.status-pill.is-billed { background:var(--tone-success-bg); color:var(--tone-success); border-color:rgba(4,120,87,.22); }
.status-pill.is-no-bill { background:var(--surface-soft); color:var(--muted); border-color:var(--border); }
.pv-link { font-weight:900; color:var(--accent); text-decoration:none; }
.pv-empty { color:var(--muted); font-weight:800; }
.note-cell { max-width:260px; font-size:13px; line-height:1.4; }
.note-cell.empty { color:var(--muted); }
.amount-form { display:flex; gap:6px; align-items:center; min-width:160px; }
.amount-form input { width:104px; height:34px; border:1px solid var(--border); border-radius:10px; padding:0 8px; font-weight:900; font-family:var(--mono); }
.amount-save { min-width:34px; width:34px; padding:0; }
.amount-hint { margin-top:4px; color:var(--muted); font-size:11px; font-weight:800; }
.actions-stack { display:flex; gap:7px; flex-wrap:wrap; align-items:flex-start; }
.inline-form { margin:0; display:inline-flex; gap:6px; align-items:center; }
.ib-small-btn { border:1px solid var(--border); background:var(--surface); color:var(--text); min-height:34px; border-radius:10px; padding:7px 10px; font-weight:900; font-size:12px; cursor:pointer; font-family:inherit; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
.ib-small-btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.ib-small-btn.muted { background:var(--surface-soft); color:var(--muted); }
.no-bill-box { width:100%; min-width:250px; margin-top:7px; display:flex; gap:6px; align-items:center; }
.no-bill-box input { height:34px; min-width:190px; border:1px solid var(--border); border-radius:10px; padding:0 10px; font-weight:700; }
.empty-state { padding:34px; text-align:center; color:var(--muted); font-weight:800; }
@media(max-width:1280px){ .ib-filters { grid-template-columns:repeat(4,minmax(0,1fr)); } .kpi-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media(max-width:860px){ .ib-topbar { width:100%!important; max-width:100vw!important; padding:8px 10px 14px!important; display:block!important; position:relative!important; top:auto!important; } .ib-toolbar { display:block!important; } .ib-filters { grid-template-columns:1fr!important; } .ib-filters input,.ib-filters select,.ib-filters .btn,.ib-filters button { width:100%!important; max-width:100%!important; } .content { width:100%!important; max-width:100vw!important; overflow-x:hidden!important; } .quick-range { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); } .quick-range .btn { width:100%; } .ib-hero { padding:18px; } .kpi-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('interventii_facturare', $isAdmin); ?>
    <main class="main">
        <div class="topbar ib-topbar">
            <div class="ib-toolbar">
                <form method="get" class="ib-filters">
                    <input type="date" name="date_from" value="<?= ib_h($dateFrom) ?>" aria-label="Data inceput">
                    <input type="date" name="date_to" value="<?= ib_h($dateTo) ?>" aria-label="Data final">
                    <input type="search" name="q" value="<?= ib_h($q) ?>" placeholder="Client, locatie, PV" aria-label="Cautare">
                    <select name="billing_status" aria-label="Status facturare">
                        <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>Toate statusurile</option>
                        <option value="de_facturat" <?= $selectedStatus === 'de_facturat' ? 'selected' : '' ?>>De facturat</option>
                        <option value="facturata" <?= $selectedStatus === 'facturata' ? 'selected' : '' ?>>Facturata</option>
                        <option value="nu_se_factureaza" <?= $selectedStatus === 'nu_se_factureaza' ? 'selected' : '' ?>>Nu se factureaza</option>
                    </select>
                    <select name="service" aria-label="Serviciu">
                        <option value="all" <?= $selectedService === 'all' ? 'selected' : '' ?>>Toate serviciile</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?= ib_h($service['name']) ?>" <?= $selectedService === $service['name'] ? 'selected' : '' ?>><?= ib_h($service['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="pv" aria-label="PV">
                        <option value="all" <?= $selectedPv === 'all' ? 'selected' : '' ?>>Cu sau fara PV</option>
                        <option value="with_pv" <?= $selectedPv === 'with_pv' ? 'selected' : '' ?>>Doar cu PV</option>
                        <option value="without_pv" <?= $selectedPv === 'without_pv' ? 'selected' : '' ?>>Doar fara PV</option>
                    </select>
                    <button class="btn accent" type="submit">Aplica</button>
                    <a class="btn" href="interventii_facturare.php">Reseteaza</a>
                </form>
            </div>
        </div>

        <div class="content">
            <?php if (isset($_GET['saved'])): ?><div class="notice notice-success">Statusul de facturare a fost actualizat.</div><?php endif; ?>
            <?php if (($_GET['error'] ?? '') === 'note'): ?><div class="notice notice-warning">Pentru "Nu se factureaza" trebuie completat motivul.</div><?php endif; ?>

            <section class="ib-hero">
                <div>
                    <h1>Checklist facturare interventii</h1>
                    <p>Lista lucrarilor finalizate. Biroul marcheaza rapid daca interventia este de facturat, facturata sau nu se factureaza.</p>
                </div>
                <div class="hero-actions">
                    <a class="btn light" href="<?= ib_h(ib_current_url(['export' => 'csv'])) ?>">Export CSV</a>
                </div>
            </section>

            <div class="quick-range">
                <a class="btn" href="interventii_facturare.php?date_from=<?= ib_h($today) ?>&date_to=<?= ib_h($today) ?>&billing_status=de_facturat&service=all&pv=all">Azi</a>
                <a class="btn" href="interventii_facturare.php?date_from=<?= ib_h($currentMonthStart) ?>&date_to=<?= ib_h($currentMonthEnd) ?>&billing_status=de_facturat&service=all&pv=all">Luna curenta</a>
                <a class="btn" href="interventii_facturare.php?date_from=<?= ib_h($prevMonthStart) ?>&date_to=<?= ib_h($prevMonthEnd) ?>&billing_status=de_facturat&service=all&pv=all">Luna trecuta</a>
                <a class="btn" href="interventii_facturare.php?date_from=<?= ib_h($dateFrom) ?>&date_to=<?= ib_h($dateTo) ?>&billing_status=all&service=all&pv=all">Toate statusurile</a>
            </div>

            <section class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Total interventii</div>
                    <div class="kpi-value"><?= (int)$totalAllStatuses ?></div>
                    <div class="kpi-sub"><?= ib_h(ib_money_label($totalAllAmount)) ?> fara TVA</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">De facturat</div>
                    <div class="kpi-value"><?= (int)$summary['de_facturat']['count'] ?></div>
                    <div class="kpi-sub"><?= ib_h(ib_money_label($summary['de_facturat']['amount'])) ?> fara TVA</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Facturate</div>
                    <div class="kpi-value"><?= (int)$summary['facturata']['count'] ?></div>
                    <div class="kpi-sub"><?= ib_h(ib_money_label($summary['facturata']['amount'])) ?> fara TVA</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Nu se factureaza</div>
                    <div class="kpi-value"><?= (int)$summary['nu_se_factureaza']['count'] ?></div>
                    <div class="kpi-sub"><?= ib_h(ib_money_label($summary['nu_se_factureaza']['amount'])) ?> potential</div>
                </div>
            </section>

            <section class="table-card">
                <div class="table-head">
                    <div>
                        <div class="table-title">Interventii</div>
                        <div class="table-subtitle"><?= count($rows) ?> rezultate afisate. Implicit se vad doar lucrarile "De facturat".</div>
                    </div>
                </div>

                <?php if (!$rows): ?>
                    <div class="empty-state">Nu exista interventii pentru filtrele selectate.</div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="ib-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Client</th>
                                    <th>Locatie</th>
                                    <th>Servicii</th>
                                    <th>PV</th>
                                    <th>Valoare</th>
                                    <th>Status facturare</th>
                                    <th>Observatii</th>
                                    <th>Actiuni</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                    $status = (string)($row['billing_status'] ?? 'de_facturat');
                                    $pvId = (int)($row['pv_id'] ?? 0);
                                    $note = trim((string)($row['billing_note'] ?? ''));
                                ?>
                                <tr>
                                    <td>
                                        <div class="cell-title"><?= ib_h($row['appointment_date'] ?? '-') ?></div>
                                        <div class="cell-muted"><?= ib_h(substr((string)($row['start_time'] ?? ''), 0, 5)) ?></div>
                                    </td>
                                    <td>
                                        <div class="cell-title"><?= ib_h($row['client_name'] ?: 'Client') ?></div>
                                        <?php if (!empty($row['client_fiscal_code'])): ?><div class="cell-muted">CUI/CNP: <?= ib_h($row['client_fiscal_code']) ?></div><?php endif; ?>
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
                                            <span class="pv-empty">Fara PV</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" class="amount-form" action="<?= ib_h(ib_current_url()) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="save_amount">
                                            <input type="hidden" name="appointment_id" value="<?= (int)$row['id'] ?>">
                                            <input type="number" name="billing_amount" step="0.01" min="0" value="<?= ib_h(ib_money_input($row['billing_amount'] ?? 0)) ?>" aria-label="Valoare lucrare fara TVA">
                                            <button class="ib-small-btn muted amount-save" type="submit" title="Salveaza valoarea">✓</button>
                                        </form>
                                        <div class="amount-hint">fara TVA</div>
                                    </td>
                                    <td><span class="status-pill <?= ib_h(ib_status_class($status)) ?>"><?= ib_h(ib_status_label($status)) ?></span></td>
                                    <td><div class="note-cell <?= $note === '' ? 'empty' : '' ?>"><?= $note !== '' ? nl2br(ib_h($note)) : '-' ?></div></td>
                                    <td>
                                        <div class="actions-stack">
                                            <a class="ib-small-btn muted" href="calendar.php?date=<?= ib_h($row['appointment_date'] ?? date('Y-m-d')) ?>&view=day">Vezi lucrarea</a>
                                            <?php if ($status !== 'facturata'): ?>
                                                <form method="post" class="inline-form" action="<?= ib_h(ib_current_url()) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="mark_billed">
                                                    <input type="hidden" name="appointment_id" value="<?= (int)$row['id'] ?>">
                                                    <button class="ib-small-btn primary" type="submit">Facturata</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($status !== 'nu_se_factureaza'): ?>
                                                <form method="post" class="inline-form no-bill-box" action="<?= ib_h(ib_current_url()) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="mark_not_billable">
                                                    <input type="hidden" name="appointment_id" value="<?= (int)$row['id'] ?>">
                                                    <input type="text" name="billing_note" placeholder="Motiv nefacturare" required>
                                                    <button class="ib-small-btn" type="submit">Nu se factureaza</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($status !== 'de_facturat'): ?>
                                                <form method="post" class="inline-form" action="<?= ib_h(ib_current_url()) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="reset_due">
                                                    <input type="hidden" name="appointment_id" value="<?= (int)$row['id'] ?>">
                                                    <button class="ib-small-btn muted" type="submit">Revenire</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
