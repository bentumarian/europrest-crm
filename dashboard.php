<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();
if (!$isAdmin) {
    header("Location: calendar.php");
    exit;
}

/* ────────────────────────────────────────────────────────────────────────
   HELPERS
   ──────────────────────────────────────────────────────────────────────── */

function dash_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function dash_table_exists(PDO $pdo, string $table): bool {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $s->execute([$table]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

function dash_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $s->execute([$table, $column]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

function dash_value(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? $default : $v;
    } catch (Throwable $e) { return $default; }
}

function dash_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { error_log('dashboard: ' . $e->getMessage()); return []; }
}

function dash_money($amount): string { return number_format((float)$amount, 0, ',', '.'); }
function dash_time(?string $time): string { return $time ? substr((string)$time, 0, 5) : '--:--'; }

/**
 * Întoarce [start, end, label] pentru o perioadă predefinită.
 */
function dash_period_range(string $period): array {
    $today = date('Y-m-d');
    switch ($period) {
        case 'today':       return [$today, $today, 'Azi'];
        case 'week':        return [date('Y-m-d', strtotime('-6 days')), $today, 'Ultimele 7 zile'];
        case 'last_month':  return [date('Y-m-01', strtotime('first day of previous month')), date('Y-m-t', strtotime('last day of previous month')), 'Luna trecută'];
        case 'year':        return [date('Y-01-01'), date('Y-12-31'), 'Anul curent'];
        case 'month':
        default:            return [date('Y-m-01'), date('Y-m-t'), 'Luna curentă'];
    }
}

function dash_period_options(): array {
    return [
        'today'      => 'Azi',
        'week'       => 'Ultimele 7 zile',
        'month'      => 'Luna curentă',
        'last_month' => 'Luna trecută',
        'year'       => 'Anul curent',
    ];
}

/* ────────────────────────────────────────────────────────────────────────
   PARAMETRII PERIOADĂ (URL)
   ──────────────────────────────────────────────────────────────────────── */

$validPeriods = array_keys(dash_period_options());
$periodOp   = in_array($_GET['period_op']   ?? '', $validPeriods, true) ? $_GET['period_op']   : 'month';
$periodFin  = in_array($_GET['period_fin']  ?? '', $validPeriods, true) ? $_GET['period_fin']  : 'month';
$periodTeam = in_array($_GET['period_team'] ?? '', $validPeriods, true) ? $_GET['period_team'] : 'today';

[$opStart,   $opEnd,   $opLabel]   = dash_period_range($periodOp);
[$finStart,  $finEnd,  $finLabel]  = dash_period_range($periodFin);
[$teamStart, $teamEnd, $teamLabel] = dash_period_range($periodTeam);

/* ────────────────────────────────────────────────────────────────────────
   TABLE / COLUMN CHECKS
   ──────────────────────────────────────────────────────────────────────── */

$today = date('Y-m-d');
$hasAppointments      = dash_table_exists($pdo, 'appointments');
$hasTasks             = dash_table_exists($pdo, 'tasks');
$hasClients           = dash_table_exists($pdo, 'clients');
$hasTeamMembers       = dash_table_exists($pdo, 'team_members');
$hasAppointmentTeams  = dash_table_exists($pdo, 'appointment_teams');
$hasDocuments         = dash_table_exists($pdo, 'documents');
$hasSmartbillInvoices = dash_table_exists($pdo, 'smartbill_invoices');
$hasSmartbillPayments = dash_table_exists($pdo, 'smartbill_invoice_payments');
$hasBillingColumns    = $hasAppointments && dash_column_exists($pdo, 'appointments', 'billing_amount') && dash_column_exists($pdo, 'appointments', 'billing_status');
$hasTaskStopped       = $hasTasks && dash_column_exists($pdo, 'tasks', 'recurrence_stopped');
$taskActiveWhere      = $hasTaskStopped ? "AND recurrence_stopped = 0" : "";

/* ────────────────────────────────────────────────────────────────────────
   DATE - OPERAȚIONAL (lucrări per perioadă)
   ──────────────────────────────────────────────────────────────────────── */

$opTotal = $opCompleted = 0;
if ($hasAppointments) {
    $r = dash_rows($pdo, "SELECT COUNT(*) AS total, SUM(CASE WHEN status='finalizata' THEN 1 ELSE 0 END) AS completed FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status!='anulata'", [$opStart, $opEnd]);
    $opTotal     = (int)($r[0]['total'] ?? 0);
    $opCompleted = (int)($r[0]['completed'] ?? 0);
}
$opCompletePct = $opTotal > 0 ? round(($opCompleted / $opTotal) * 100) : 0;

$tasksToSchedule = $hasTasks ? (int)dash_value($pdo, "SELECT COUNT(*) FROM tasks WHERE status='de_programat' {$taskActiveWhere}") : 0;

/* ────────────────────────────────────────────────────────────────────────
   DATE - FINANCIAR (de facturat + emis + încasat per perioadă)
   ──────────────────────────────────────────────────────────────────────── */

$finDueCount = $finDueAmount = 0;
$finIssued = $finPaid = 0;
$finIssuedCount = 0;
if ($hasBillingColumns) {
    $r = dash_rows($pdo, "SELECT COUNT(*) AS c, COALESCE(SUM(billing_amount),0) AS s FROM appointments WHERE status='finalizata' AND billing_status='de_facturat' AND appointment_date BETWEEN ? AND ?", [$finStart, $finEnd]);
    $finDueCount  = (int)($r[0]['c'] ?? 0);
    $finDueAmount = (float)($r[0]['s'] ?? 0);
}
if ($hasSmartbillInvoices) {
    $r = dash_rows($pdo, "SELECT COUNT(*) AS c, COALESCE(SUM(gross_amount),0) AS s FROM smartbill_invoices WHERE invoice_date BETWEEN ? AND ? AND TRIM(COALESCE(smartbill_number,'')) <> ''", [$finStart, $finEnd]);
    $finIssuedCount = (int)($r[0]['c'] ?? 0);
    $finIssued      = (float)($r[0]['s'] ?? 0);
}
if ($hasSmartbillPayments) {
    $finPaid = (float)dash_value($pdo, "SELECT COALESCE(SUM(amount),0) FROM smartbill_invoice_payments WHERE payment_date BETWEEN ? AND ? AND COALESCE(smartbill_status,'') NOT IN ('error','deleted')", [$finStart, $finEnd]);
}
$finPaidPct = $finIssued > 0 ? min(100, round(($finPaid / $finIssued) * 100)) : 0;

// Restanțe (globale, indep. de perioadă)
$restanteCount = 0; $restanteAmount = 0.0; $restanteList = [];
if ($hasSmartbillInvoices && dash_column_exists($pdo, 'smartbill_invoices', 'due_date') && dash_column_exists($pdo, 'smartbill_invoices', 'client_name')) {
    $paymentsJoin = $hasSmartbillPayments
        ? "LEFT JOIN (SELECT smartbill_invoice_id, SUM(amount) AS paid FROM smartbill_invoice_payments WHERE COALESCE(smartbill_status,'') NOT IN ('error','deleted') GROUP BY smartbill_invoice_id) p ON p.smartbill_invoice_id = i.id"
        : "";
    $paidExpr = $hasSmartbillPayments ? "GREATEST(0, i.gross_amount - COALESCE(p.paid, 0))" : "i.gross_amount";
    $allRestante = dash_rows($pdo, "
        SELECT i.client_name, SUM({$paidExpr}) AS remaining_amount, MIN(i.due_date) AS oldest_due
        FROM smartbill_invoices i {$paymentsJoin}
        WHERE i.due_date < ? AND i.due_date IS NOT NULL
          AND i.source_type <> 'receipt'
          AND TRIM(COALESCE(i.smartbill_number, '')) <> ''
        GROUP BY i.client_name
        HAVING remaining_amount > 0.01
        ORDER BY remaining_amount DESC LIMIT 20
    ", [$today]);
    $restanteCount  = count($allRestante);
    $restanteAmount = array_sum(array_column($allRestante, 'remaining_amount'));
    $restanteList   = array_slice($allRestante, 0, 3);
}

/* ────────────────────────────────────────────────────────────────────────
   DATE - ECHIPĂ (per perioadă)
   ──────────────────────────────────────────────────────────────────────── */

$teamTotal = $hasTeamMembers ? (int)dash_value($pdo, "SELECT COUNT(*) FROM team_members WHERE active=1") : 0;
$teamActive = 0;
if ($hasTeamMembers && $hasAppointments) {
    $teamActive = (int)dash_value($pdo, "
        SELECT COUNT(DISTINCT tm.id) FROM team_members tm
        LEFT JOIN appointments a ON a.team_member_id = tm.id AND a.appointment_date BETWEEN ? AND ? AND a.status != 'anulata'
        WHERE tm.active = 1 AND a.id IS NOT NULL
    ", [$teamStart, $teamEnd]);
}
$teamPct = $teamTotal > 0 ? round(($teamActive / $teamTotal) * 100) : 0;

$teamList = [];
if ($hasTeamMembers && $hasAppointments) {
    $teamList = dash_rows($pdo, "
        SELECT tm.id, tm.name, COUNT(a.id) AS jobs_total, SUM(CASE WHEN a.status='finalizata' THEN 1 ELSE 0 END) AS jobs_done
        FROM team_members tm
        LEFT JOIN appointments a ON a.team_member_id=tm.id AND a.appointment_date BETWEEN ? AND ? AND a.status!='anulata'
        WHERE tm.active=1
        GROUP BY tm.id, tm.name HAVING jobs_total > 0
        ORDER BY jobs_total DESC LIMIT 6
    ", [$teamStart, $teamEnd]);
}

/* ────────────────────────────────────────────────────────────────────────
   AGENDA AZI
   ──────────────────────────────────────────────────────────────────────── */

$todayAppointments = $hasAppointments ? dash_rows($pdo,
    "SELECT a.id, a.start_time, a.service_type, a.status, c.name AS client_name, tm.name AS team_name
     FROM appointments a
     LEFT JOIN clients c ON c.id=a.client_id
     LEFT JOIN team_members tm ON tm.id=a.team_member_id
     WHERE a.appointment_date=? AND a.status!='anulata'
     ORDER BY a.start_time ASC, a.id ASC LIMIT 6", [$today]) : [];

/* ────────────────────────────────────────────────────────────────────────
   ALERTE
   ──────────────────────────────────────────────────────────────────────── */

$tasksOverdue = $hasTasks ? (int)dash_value($pdo, "SELECT COUNT(*) FROM tasks WHERE due_date<? AND status IN('de_programat','contactat','amanat') {$taskActiveWhere}", [$today]) : 0;
$urgentTask = null;
if ($hasTasks && $tasksOverdue > 0) {
    $hasTaskClientId = dash_column_exists($pdo, 'tasks', 'client_id');
    $joinClause = ($hasTaskClientId && $hasClients) ? "LEFT JOIN clients c ON c.id = t.client_id" : "";
    $clientCol  = ($hasTaskClientId && $hasClients) ? "c.name AS client_name" : "NULL AS client_name";
    $rows = dash_rows($pdo, "SELECT t.id, t.title, t.due_date, {$clientCol} FROM tasks t {$joinClause} WHERE t.due_date < ? AND t.status IN('de_programat','contactat','amanat') {$taskActiveWhere} ORDER BY t.due_date ASC LIMIT 1", [$today]);
    if ($rows) {
        $urgentTask = $rows[0];
        $urgentTask['days_late'] = max(0, (int)floor((strtotime($today) - strtotime((string)$urgentTask['due_date'])) / 86400));
    }
}

/* ────────────────────────────────────────────────────────────────────────
   TREND 6 LUNI (pentru banner navy)
   ──────────────────────────────────────────────────────────────────────── */

$monthly = []; $monthlyDelta = null;
if ($hasAppointments) {
    $sixStart = date('Y-m-01', strtotime('-5 months'));
    $rows = dash_rows($pdo, "SELECT DATE_FORMAT(appointment_date,'%Y-%m') AS pk, COUNT(*) AS total FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status!='anulata' GROUP BY pk ORDER BY pk ASC", [$sixStart, date('Y-m-t')]);
    $byKey = [];
    foreach ($rows as $r) { $byKey[(string)$r['pk']] = (int)$r['total']; }
    $cursor = strtotime($sixStart);
    while ($cursor <= strtotime(date('Y-m-01'))) {
        $k = date('Y-m', $cursor);
        $monthly[] = ['k' => $k, 'v' => (int)($byKey[$k] ?? 0)];
        $cursor = strtotime('+1 month', $cursor);
    }
    $n = count($monthly);
    if ($n >= 2) {
        $last = $monthly[$n - 1]['v']; $prev = $monthly[$n - 2]['v'];
        $monthlyDelta = $prev > 0 ? round((($last - $prev) / $prev) * 100) : ($last > 0 ? 100 : 0);
    }
}

$userName = function_exists('current_user_name') ? current_user_name() : 'Utilizator';

$pz_page_title = 'Dashboard';
$pz_page_breadcrumbs = [];
$pz_topbar_opts = ['placeholder' => 'Caută client...'];

$dayNames = ['Sunday'=>'Duminică','Monday'=>'Luni','Tuesday'=>'Marți','Wednesday'=>'Miercuri','Thursday'=>'Joi','Friday'=>'Vineri','Saturday'=>'Sâmbătă'];
$monthNames = ['January'=>'ianuarie','February'=>'februarie','March'=>'martie','April'=>'aprilie','May'=>'mai','June'=>'iunie','July'=>'iulie','August'=>'august','September'=>'septembrie','October'=>'octombrie','November'=>'noiembrie','December'=>'decembrie'];
$todayLabel = ($dayNames[date('l')] ?? date('l')) . ' · ' . date('d') . ' ' . ($monthNames[date('F')] ?? date('F')) . ' ' . date('Y');

// Helper pentru URL cu un singur param schimbat (folosit la setări)
function dash_period_url(string $paramName, string $value): string {
    $params = $_GET;
    $params[$paramName] = $value;
    return 'dashboard.php?' . http_build_query($params);
}

// Pre-calcul SVG arc pentru fiecare ring (procent → stroke-dashoffset)
function dash_ring_offset(float $pct, float $circumference = 326.7): float {
    return round($circumference * (1 - max(0, min(100, $pct)) / 100), 1);
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Dashboard · PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php app_theme_css(); ?>
<style>
.mc-dash {
    --mc-navy: #12345A;
    --mc-line: #E5E7EB;
    --mc-line-soft: #F1F5F9;
    --mc-text: #0F172A;
    --mc-muted: #64748B;
    --mc-faint: #94A3B8;
    --mc-bg: #F8FAFC;
    --mc-surf: #FFFFFF;
    --mc-bl: #185FA5;  --mc-bl-soft: #EFF6FF;  --mc-bl-border: #BFDBFE;  --mc-bl-track: #DBEAFE;
    --mc-or: #F97316;  --mc-or-deep: #9A3412;  --mc-or-soft: #FFF7ED;  --mc-or-border: #FED7AA;  --mc-or-track: #FFEDD5;
    --mc-gr: #0F6E56;  --mc-gr-deep: #166534;  --mc-gr-soft: #F0FDF4;  --mc-gr-border: #BBF7D0;  --mc-gr-track: #DCFCE7;
    --mc-re: #DC2626;  --mc-re-deep: #991B1B;  --mc-re-soft: #FEF2F2;  --mc-re-border: #FECACA;
    --mc-pu: #534AB7;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--mc-text);
    background: var(--mc-bg);
    padding: 16px;
    display: grid;
    gap: 12px;
    max-width: 1680px;
    margin: 0 auto;
}
.mc-dash * { box-sizing: border-box; }

.mc-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
.mc-head .small { font-size: 11.5px; color: var(--mc-muted); letter-spacing: .04em; text-transform: uppercase; font-weight: 500; }
.mc-head .title { font-size: 20px; font-weight: 500; color: var(--mc-navy); margin-top: 2px; }
.mc-head .pills { display: flex; gap: 6px; }
.mc-head .pill { font-size: 11px; padding: 3px 10px; border-radius: 999px; font-weight: 500; }
.mc-head .pill.ok { background: var(--mc-gr-soft); color: var(--mc-gr-deep); }
.mc-head .pill.alert { background: var(--mc-re-soft); color: var(--mc-re-deep); }

.mc-rings { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.mc-ring-card { background: var(--mc-surf); border-radius: 12px; padding: 16px; border: 0.5px solid var(--mc-line); position: relative; display: flex; flex-direction: column; }
.mc-ring-card.op { background: var(--mc-bl-soft); border-color: var(--mc-bl-border); }
.mc-ring-card.fin { background: var(--mc-or-soft); border-color: var(--mc-or-border); }
.mc-ring-card.team { background: var(--mc-gr-soft); border-color: var(--mc-gr-border); }
.mc-ring-card .head { display: flex; justify-content: space-between; align-items: flex-start; }
.mc-ring-card .head .label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 500; }
.mc-ring-card .head .sub { font-size: 13px; opacity: 0.75; margin-top: 2px; }
.mc-ring-card.op .head .label, .mc-ring-card.op .head .sub { color: #1E40AF; }
.mc-ring-card.fin .head .label, .mc-ring-card.fin .head .sub { color: var(--mc-or-deep); }
.mc-ring-card.team .head .label, .mc-ring-card.team .head .sub { color: var(--mc-gr-deep); }
.mc-ring-card .head .ico { font-size: 20px; opacity: 0.9; }
.mc-ring-card.op .head .ico { color: #1E40AF; }
.mc-ring-card.fin .head .ico { color: var(--mc-or-deep); }
.mc-ring-card.team .head .ico { color: var(--mc-gr-deep); }

.mc-ring-wrap { margin-top: 16px; display: flex; align-items: center; justify-content: center; padding: 8px 0; }
.mc-ring-foot { display: flex; justify-content: space-between; font-size: 12px; padding-top: 10px; margin-top: auto; }
.mc-ring-card.op .mc-ring-foot { border-top: 0.5px solid var(--mc-bl-border); }
.mc-ring-card.fin .mc-ring-foot { border-top: 0.5px solid var(--mc-or-border); }
.mc-ring-card.team .mc-ring-foot { border-top: 0.5px solid var(--mc-gr-border); }
.mc-ring-foot .key { opacity: 0.7; }
.mc-ring-foot strong { font-weight: 500; }
.mc-ring-foot .ok { color: var(--mc-gr-deep); }
.mc-ring-foot .navy { color: var(--mc-navy); }

/* Setări (cog) - dropdown per card */
.mc-cog {
    position: absolute; top: 12px; right: 12px;
    width: 28px; height: 28px; border-radius: 6px;
    border: 0.5px solid transparent;
    background: rgba(255,255,255,0.5);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 14px; color: inherit; opacity: 0.6;
    transition: opacity 0.15s, background 0.15s;
}
.mc-cog:hover { opacity: 1; background: rgba(255,255,255,0.9); }
.mc-cog.active { opacity: 1; background: #FFF; border-color: var(--mc-line); }
.mc-cog-menu {
    position: absolute; top: 44px; right: 12px;
    background: var(--mc-surf); border: 0.5px solid var(--mc-line);
    border-radius: 8px; padding: 6px;
    min-width: 180px; z-index: 50;
    display: none;
}
.mc-cog-menu.open { display: block; }
.mc-cog-menu .group-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--mc-muted); padding: 6px 10px 4px; font-weight: 600; }
.mc-cog-menu a {
    display: flex; justify-content: space-between; align-items: center;
    padding: 7px 10px; font-size: 12.5px; color: var(--mc-text);
    border-radius: 4px; text-decoration: none;
}
.mc-cog-menu a:hover { background: var(--mc-bg); }
.mc-cog-menu a.current { background: var(--mc-bl-soft); color: var(--mc-bl); font-weight: 500; }
.mc-cog-menu a.current::after { content: '✓'; }

.mc-secondary { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.mc-card { background: var(--mc-surf); border: 0.5px solid var(--mc-line); border-radius: 12px; padding: 16px; position: relative; }
.mc-card.danger { background: var(--mc-re-soft); border-color: var(--mc-re-border); }
.mc-card .head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.mc-card .head .title { font-size: 13px; font-weight: 500; }
.mc-card.danger .head .title { color: var(--mc-re-deep); }
.mc-card .head .meta { font-size: 11px; color: var(--mc-muted); }
.mc-card.danger .head .meta { background: var(--mc-re-deep); color: #FFF; padding: 2px 8px; border-radius: 999px; font-weight: 500; }
.mc-card .body { font-size: 12.5px; }

.mc-urgent-name { font-size: 13px; color: var(--mc-re-deep); font-weight: 500; }
.mc-urgent-sub { font-size: 12px; color: var(--mc-re-deep); opacity: 0.85; margin-top: 2px; }
.mc-urgent-foot { margin-top: 12px; padding-top: 10px; border-top: 0.5px solid var(--mc-re-border); font-size: 12px; color: var(--mc-re-deep); }

.mc-agenda-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; padding: 4px 0; }
.mc-agenda-row .time { font-weight: 500; font-variant-numeric: tabular-nums; min-width: 38px; color: var(--mc-navy); }
.mc-agenda-row .dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.mc-agenda-row .who { flex: 1; color: var(--mc-text); }
.mc-agenda-row .tech { font-size: 11px; color: var(--mc-muted); }
.mc-agenda-empty { font-size: 12px; color: var(--mc-faint); text-align: center; padding: 16px 0; }

.mc-banner {
    background: var(--mc-navy); color: #FFF; border-radius: 12px;
    padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; gap: 16px;
}
.mc-banner .label { font-size: 11px; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500; }
.mc-banner .value { font-size: 22px; font-weight: 500; margin-top: 4px; }
.mc-banner .value.pos { color: #86EFAC; }
.mc-banner .value.neg { color: #FCA5A5; }
.mc-banner .sub { font-size: 12px; opacity: 0.7; margin-top: 2px; }

@media (max-width: 980px) {
    .mc-rings { grid-template-columns: 1fr; }
    .mc-secondary { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('dashboard', $isAdmin); ?>
    <main class="main">
        <div class="content mc-dash">

            <!-- Header -->
            <div class="mc-head">
                <div>
                    <div class="small"><?= dash_h($todayLabel) ?></div>
                    <div class="title">Bună, <?= dash_h($userName) ?></div>
                </div>
                <div class="pills">
                    <div class="pill ok"><?= count($todayAppointments) ?> active azi</div>
                    <?php if ($tasksOverdue > 0): ?>
                        <div class="pill alert"><?= (int)$tasksOverdue ?> alerte</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3 ringuri -->
            <div class="mc-rings">

                <!-- Operațional -->
                <div class="mc-ring-card op">
                    <div class="head">
                        <div>
                            <div class="label">Operațional</div>
                            <div class="sub"><?= dash_h(strtolower($opLabel)) ?> · <?= $opTotal ?> lucr<?= $opTotal === 1 ? 'are' : 'ări' ?></div>
                        </div>
                        <i class="ti ti-route ico" aria-hidden="true"></i>
                    </div>
                    <button type="button" class="mc-cog" aria-label="Setări operațional" data-cog="op"><i class="ti ti-settings"></i></button>
                    <div class="mc-cog-menu" id="cogmenu-op">
                        <div class="group-label">Perioadă</div>
                        <?php foreach (dash_period_options() as $key => $label): ?>
                            <a href="<?= dash_h(dash_period_url('period_op', $key)) ?>" class="<?= $periodOp === $key ? 'current' : '' ?>"><?= dash_h($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <div class="mc-ring-wrap">
                        <svg viewBox="0 0 130 130" width="130" height="130" role="img" aria-label="Lucrări <?= dash_h($opLabel) ?>">
                            <circle cx="65" cy="65" r="52" fill="none" stroke="var(--mc-bl-track)" stroke-width="12"/>
                            <circle cx="65" cy="65" r="52" fill="none" stroke="var(--mc-bl)" stroke-width="12" stroke-dasharray="326.7" stroke-dashoffset="<?= dash_ring_offset($opCompletePct) ?>" transform="rotate(-90 65 65)" stroke-linecap="round"/>
                            <text x="65" y="60" text-anchor="middle" font-size="32" font-weight="500" fill="var(--mc-navy)"><?= $opTotal ?></text>
                            <text x="65" y="80" text-anchor="middle" font-size="11" fill="#1E40AF"><?= $opCompletePct ?>% finalizate</text>
                        </svg>
                    </div>
                    <div class="mc-ring-foot">
                        <div><span class="key">finalizate</span> <strong class="ok"><?= $opCompleted ?></strong></div>
                        <div><span class="key">de programat</span> <strong class="navy"><?= $tasksToSchedule ?></strong></div>
                    </div>
                </div>

                <!-- Financiar -->
                <div class="mc-ring-card fin">
                    <div class="head">
                        <div>
                            <div class="label">Financiar</div>
                            <div class="sub"><?= dash_h(strtolower($finLabel)) ?> · de facturat</div>
                        </div>
                        <i class="ti ti-cash ico" aria-hidden="true"></i>
                    </div>
                    <button type="button" class="mc-cog" aria-label="Setări financiar" data-cog="fin"><i class="ti ti-settings"></i></button>
                    <div class="mc-cog-menu" id="cogmenu-fin">
                        <div class="group-label">Perioadă</div>
                        <?php foreach (dash_period_options() as $key => $label): ?>
                            <a href="<?= dash_h(dash_period_url('period_fin', $key)) ?>" class="<?= $periodFin === $key ? 'current' : '' ?>"><?= dash_h($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <div class="mc-ring-wrap">
                        <svg viewBox="0 0 130 130" width="130" height="130" role="img" aria-label="Financiar <?= dash_h($finLabel) ?>">
                            <circle cx="65" cy="65" r="52" fill="none" stroke="var(--mc-or-track)" stroke-width="12"/>
                            <circle cx="65" cy="65" r="52" fill="none" stroke="var(--mc-or)" stroke-width="12" stroke-dasharray="<?= round(326.7 * min(1, $finIssued > 0 ? $finDueAmount / max(1, $finIssued + $finDueAmount) : 0.5), 1) ?> 326.7" stroke-dashoffset="0" transform="rotate(-90 65 65)" stroke-linecap="round"/>
                            <text x="65" y="58" text-anchor="middle" font-size="22" font-weight="500" fill="var(--mc-or-deep)"><?= dash_money($finDueAmount) ?></text>
                            <text x="65" y="72" text-anchor="middle" font-size="10" fill="var(--mc-or-deep)">lei cu TVA</text>
                            <text x="65" y="86" text-anchor="middle" font-size="11" font-weight="500" fill="var(--mc-or)"><?= $finDueCount ?> interven<?= $finDueCount === 1 ? 'ție' : 'ții' ?></text>
                        </svg>
                    </div>
                    <div class="mc-ring-foot">
                        <div><span class="key">facturate</span> <strong class="ok"><?= $finIssuedCount ?></strong></div>
                        <div><span class="key">restanțe</span> <strong class="<?= $restanteCount > 0 ? '' : 'ok' ?>" style="<?= $restanteCount > 0 ? 'color:var(--mc-re-deep)' : '' ?>"><?= $restanteCount ?></strong></div>
                    </div>
                </div>

                <!-- Echipă -->
                <div class="mc-ring-card team">
                    <div class="head">
                        <div>
                            <div class="label">Echipă</div>
                            <div class="sub"><?= dash_h(strtolower($teamLabel)) ?> · activi</div>
                        </div>
                        <i class="ti ti-users ico" aria-hidden="true"></i>
                    </div>
                    <button type="button" class="mc-cog" aria-label="Setări echipă" data-cog="team"><i class="ti ti-settings"></i></button>
                    <div class="mc-cog-menu" id="cogmenu-team">
                        <div class="group-label">Perioadă</div>
                        <?php foreach (dash_period_options() as $key => $label): ?>
                            <a href="<?= dash_h(dash_period_url('period_team', $key)) ?>" class="<?= $periodTeam === $key ? 'current' : '' ?>"><?= dash_h($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <div class="mc-ring-wrap">
                        <svg viewBox="0 0 130 130" width="130" height="130" role="img" aria-label="Echipă <?= dash_h($teamLabel) ?>">
                            <circle cx="65" cy="65" r="52" fill="none" stroke="var(--mc-gr-track)" stroke-width="12"/>
                            <circle cx="65" cy="65" r="52" fill="none" stroke="var(--mc-gr)" stroke-width="12" stroke-dasharray="326.7" stroke-dashoffset="<?= dash_ring_offset($teamPct) ?>" transform="rotate(-90 65 65)" stroke-linecap="round"/>
                            <text x="65" y="62" text-anchor="middle" font-size="30" font-weight="500" fill="var(--mc-gr-deep)"><?= $teamActive ?><tspan font-size="20" fill="#86EFAC">/<?= $teamTotal ?></tspan></text>
                            <text x="65" y="82" text-anchor="middle" font-size="11" fill="var(--mc-gr-deep)">capacitate <?= $teamPct ?>%</text>
                        </svg>
                    </div>
                    <div class="mc-ring-foot" style="font-size:11.5px;">
                        <?php if ($teamList): ?>
                            <div><span class="key"><?= dash_h(strtolower($teamLabel)) ?></span> <strong><?= dash_h(implode(', ', array_slice(array_map(fn($t)=>$t['name'], $teamList), 0, 3))) ?><?= count($teamList) > 3 ? '…' : '' ?></strong></div>
                        <?php else: ?>
                            <div><span class="key">niciun tehnician activ</span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Secundar: alertă + agenda -->
            <div class="mc-secondary">

                <!-- Urgent alert / sarcină întârziată -->
                <?php if ($urgentTask): ?>
                <div class="mc-card danger">
                    <div class="head">
                        <div class="title"><i class="ti ti-alert-triangle" style="font-size:14px;vertical-align:-2px;margin-right:4px"></i>Sarcină urgentă</div>
                        <div class="meta">-<?= (int)$urgentTask['days_late'] ?>z</div>
                    </div>
                    <div class="body">
                        <div class="mc-urgent-name"><?= dash_h($urgentTask['client_name'] ?? 'Client necunoscut') ?></div>
                        <div class="mc-urgent-sub"><?= dash_h($urgentTask['title'] ?? '-') ?></div>
                        <div class="mc-urgent-foot"><?= $tasksToSchedule ?> sarcini de programat · <?= $tasksOverdue ?> întârziate</div>
                    </div>
                </div>
                <?php else: ?>
                <div class="mc-card">
                    <div class="head">
                        <div class="title" style="color:var(--mc-gr-deep);"><i class="ti ti-circle-check" style="font-size:14px;vertical-align:-2px;margin-right:4px"></i>Fără sarcini urgente</div>
                        <div class="meta">la zi</div>
                    </div>
                    <div class="body" style="color:var(--mc-muted);">
                        Toate sarcinile sunt în termen. <?= $tasksToSchedule ?> sarcini de programat · 0 întârziate.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Agenda azi -->
                <div class="mc-card">
                    <div class="head">
                        <div class="title"><i class="ti ti-clock" style="font-size:14px;vertical-align:-2px;margin-right:4px"></i>Agenda</div>
                        <div class="meta"><?= count($todayAppointments) ?> programări</div>
                    </div>
                    <div class="body">
                        <?php if (!$todayAppointments): ?>
                            <div class="mc-agenda-empty">Niciun program astăzi.</div>
                        <?php else:
                            $dotColors = ['neconfirmata'=>'#94A3B8','confirmata'=>'#185FA5','in_lucru'=>'#F97316','finalizata'=>'#22C55E','programat'=>'#185FA5'];
                            foreach ($todayAppointments as $ap):
                                $dotColor = $dotColors[$ap['status']] ?? '#94A3B8';
                        ?>
                            <div class="mc-agenda-row">
                                <span class="time"><?= dash_h(dash_time($ap['start_time'] ?? null)) ?></span>
                                <span class="dot" style="background:<?= $dotColor ?>"></span>
                                <span class="who"><strong style="font-weight:500"><?= dash_h($ap['client_name'] ?? 'Client') ?></strong> · <?= dash_h($ap['service_type'] ?? '-') ?></span>
                                <?php if (!empty($ap['team_name'])): ?>
                                    <span class="tech"><?= dash_h($ap['team_name']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <!-- Banner navy: trend -->
            <?php
                $bannerLabel = 'Trend 6 luni';
                $bannerValue = '—';
                $bannerSub = 'date insuficiente';
                $bannerClass = '';
                if ($monthlyDelta !== null) {
                    $bannerValue = ($monthlyDelta > 0 ? '+' : '') . $monthlyDelta . '%';
                    $bannerSub = 'creștere lucrări vs luna anterioară';
                    $bannerClass = $monthlyDelta >= 0 ? 'pos' : 'neg';
                    if ($monthlyDelta < 0) { $bannerSub = 'scădere lucrări vs luna anterioară'; }
                }
                $maxV = max(1, max(array_column($monthly ?: [['v'=>0]], 'v')));
                $points = [];
                foreach ($monthly as $i => $m) {
                    $x = 5 + ($i * 190 / max(1, count($monthly) - 1));
                    $y = 52 - (($m['v'] / $maxV) * 44);
                    $points[] = round($x, 1) . ',' . round($y, 1);
                }
                $polyline = implode(' ', $points);
                $polygon = $points ? $points[0] . ' ' . $polyline . ' ' . end($points) : '';
                if ($polygon !== '') {
                    $lastIdx = count($points) - 1;
                    $polygon = "5,58 {$polyline} " . explode(',', $points[$lastIdx])[0] . ",58";
                }
            ?>
            <div class="mc-banner">
                <div>
                    <div class="label"><?= dash_h($bannerLabel) ?></div>
                    <div class="value <?= $bannerClass ?>"><?= dash_h($bannerValue) ?></div>
                    <div class="sub"><?= dash_h($bannerSub) ?></div>
                </div>
                <?php if ($monthly && count($monthly) > 1): ?>
                <svg viewBox="0 0 200 60" width="200" height="60" role="img" aria-label="Trend creștere">
                    <polyline points="<?= dash_h($polyline) ?>" fill="none" stroke="#86EFAC" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <polygon points="<?= dash_h($polygon) ?>" fill="rgba(134,239,172,0.15)" stroke="none"/>
                    <?php if ($points): $last = end($points); [$lx, $ly] = explode(',', $last); ?>
                        <circle cx="<?= dash_h($lx) ?>" cy="<?= dash_h($ly) ?>" r="4" fill="#22C55E" stroke="#FFF" stroke-width="1"/>
                    <?php endif; ?>
                </svg>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>
<script>
(function () {
    // Toggle cog menus + click-outside-to-close
    document.querySelectorAll('.mc-cog').forEach(function (btn) {
        var key = btn.getAttribute('data-cog');
        var menu = document.getElementById('cogmenu-' + key);
        if (!menu) return;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            // close other menus first
            document.querySelectorAll('.mc-cog-menu.open').forEach(function (m) {
                if (m !== menu) m.classList.remove('open');
            });
            document.querySelectorAll('.mc-cog.active').forEach(function (b) {
                if (b !== btn) b.classList.remove('active');
            });
            menu.classList.toggle('open');
            btn.classList.toggle('active');
        });
    });
    document.addEventListener('click', function (e) {
        if (e.target.closest('.mc-cog-menu') || e.target.closest('.mc-cog')) return;
        document.querySelectorAll('.mc-cog-menu.open').forEach(function (m) { m.classList.remove('open'); });
        document.querySelectorAll('.mc-cog.active').forEach(function (b) { b.classList.remove('active'); });
    });
})();
</script>
</body>
</html>
