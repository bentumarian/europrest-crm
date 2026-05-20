<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();
if (!$isAdmin) {
    header("Location: calendar.php");
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────

function dash_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dash_table_exists(PDO $pdo, string $table): bool
{
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $s->execute([$table]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

function dash_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $s->execute([$table, $column]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

function dash_rows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { error_log('dashboard: ' . $e->getMessage()); return []; }
}

function dash_value(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? $default : $v;
    } catch (Throwable $e) { return $default; }
}

function dash_money($amount): string { return number_format((float)$amount, 0, ',', '.'); }
function dash_decimal($value, int $p = 1): string { return number_format((float)$value, $p, ',', '.'); }

function dash_percent_delta(float $current, float $previous): ?float
{
    if (abs($previous) < 0.00001) return abs($current) < 0.00001 ? 0.0 : null;
    return round((($current - $previous) / $previous) * 100, 1);
}

function dash_time(?string $time): string { return $time ? substr((string)$time, 0, 5) : '--:--'; }

function dash_status_label(string $status): string
{
    return ['neconfirmata'=>'Neconfirmată','confirmata'=>'Confirmată','in_lucru'=>'În lucru',
            'finalizata'=>'Finalizată','anulata'=>'Anulată','de_programat'=>'De programat',
            'contactat'=>'Contactat','amanat'=>'Amânat','programat'=>'Programat'][$status] ?? $status;
}

function dash_month_label(string $date): string
{
    return ['01'=>'Ian','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mai','06'=>'Iun',
            '07'=>'Iul','08'=>'Aug','09'=>'Sep','10'=>'Oct','11'=>'Noi','12'=>'Dec'][date('m', strtotime($date))] ?? '';
}

function dash_date_ro(string $date): string
{
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : $date;
}

function dash_short_date(string $date): string
{
    $ts = strtotime($date);
    return $ts ? date('d.m', $ts) : $date;
}

function dash_days_ago(string $date): int
{
    return (int)floor((strtotime(date('Y-m-d')) - strtotime($date)) / 86400);
}

function dash_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $l = '';
    foreach (array_slice($parts, 0, 2) as $p) $l .= mb_substr($p, 0, 1, 'UTF-8');
    return mb_strtoupper($l ?: 'E', 'UTF-8');
}

function dash_safe_hex(?string $color, string $fallback = '#2563EB'): string
{
    $color = trim((string)$color);
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : $fallback;
}

function dash_line_chart(array $rows, string $key, int $width = 540, int $height = 80, int $pad = 4): array
{
    $values = array_map(static fn($r) => max(0.0, (float)($r[$key] ?? 0)), $rows);
    if (!$values) { $values = [0.0]; $rows = [[$key => 0, 'label' => '']]; }
    $count = count($values);
    $max = max(1.0, max($values));
    $uw = max(1, $width - $pad * 2);
    $uh = max(1, $height - $pad * 2);
    $baseY = $height - $pad;
    $pts = [];
    foreach ($values as $i => $v) {
        $x = $pad + ($count > 1 ? ($uw * $i / ($count - 1)) : $uw / 2);
        $y = $baseY - (($v / $max) * $uh);
        $pts[] = [round($x, 2), round($y, 2), $v, (string)($rows[$i]['label'] ?? '')];
    }
    $coords = array_map(static fn($p) => $p[0] . ' ' . $p[1], $pts);
    $line = 'M ' . implode(' L ', $coords);
    $area = 'M ' . $pts[0][0] . ' ' . $baseY . ' L ' . implode(' L ', $coords) . ' L ' . $pts[count($pts)-1][0] . ' ' . $baseY . ' Z';
    return ['line' => $line, 'area' => $area, 'points' => $pts, 'labels' => array_column($rows, 'label'), 'max' => $max];
}

function dash_trend(PDO $pdo, bool $hasApps, bool $hasBilling, string $start, string $end, string $group): array
{
    $trend = [];
    $cursor = strtotime($start);
    $last = strtotime($end);
    if ($group === 'month') {
        $cursor = strtotime(date('Y-m-01', $cursor));
        $last   = strtotime(date('Y-m-01', $last));
        while ($cursor <= $last) {
            $k = date('Y-m', $cursor);
            $trend[$k] = ['key'=>$k,'label'=>dash_month_label(date('Y-m-01',$cursor)),'total'=>0,'completed'=>0,'value'=>0.0];
            $cursor = strtotime('+1 month', $cursor);
        }
        $grp = "DATE_FORMAT(appointment_date,'%Y-%m')";
    } else {
        while ($cursor <= $last) {
            $k = date('Y-m-d', $cursor);
            $trend[$k] = ['key'=>$k,'label'=>dash_short_date($k),'total'=>0,'completed'=>0,'value'=>0.0];
            $cursor = strtotime('+1 day', $cursor);
        }
        $grp = 'appointment_date';
    }
    if (!$hasApps) return array_values($trend);
    $valExpr = $hasBilling ? "COALESCE(SUM(CASE WHEN billing_status!='nu_se_factureaza' THEN billing_amount ELSE 0 END),0) AS value_total" : "0 AS value_total";
    $rows = dash_rows($pdo, "SELECT {$grp} AS pk, COUNT(*) AS total, SUM(CASE WHEN status='finalizata' THEN 1 ELSE 0 END) AS completed, {$valExpr} FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status!='anulata' GROUP BY pk ORDER BY pk ASC", [$start, $end]);
    foreach ($rows as $r) {
        $k = (string)($r['pk'] ?? '');
        if (!isset($trend[$k])) continue;
        $trend[$k]['total']     = (int)($r['total'] ?? 0);
        $trend[$k]['completed'] = (int)($r['completed'] ?? 0);
        $trend[$k]['value']     = (float)($r['value_total'] ?? 0);
    }
    return array_values($trend);
}

// ── Date constants ────────────────────────────────────────────────────────

$today        = date('Y-m-d');
$nowTime      = date('H:i:s');
$monthStart   = date('Y-m-01');
$monthEnd     = date('Y-m-t');
$sixMonthsStart = date('Y-m-01', strtotime('-5 months'));

// ── Table & column checks ─────────────────────────────────────────────────

$hasAppointments      = dash_table_exists($pdo, 'appointments');
$hasTasks             = dash_table_exists($pdo, 'tasks');
$hasClients           = dash_table_exists($pdo, 'clients');
$hasTeamMembers       = dash_table_exists($pdo, 'team_members');
$hasAppointmentTeams  = dash_table_exists($pdo, 'appointment_teams');
$hasDocuments         = dash_table_exists($pdo, 'documents');
$hasStockProducts     = dash_table_exists($pdo, 'stock_products');
$hasStockReceipts     = dash_table_exists($pdo, 'stock_receipts');
$hasStockMovements    = dash_table_exists($pdo, 'stock_movements');
$hasSmartbillInvoices = dash_table_exists($pdo, 'smartbill_invoices');
$hasSmartbillPayments = dash_table_exists($pdo, 'smartbill_invoice_payments');
$hasBillingColumns    = $hasAppointments && dash_column_exists($pdo, 'appointments', 'billing_amount') && dash_column_exists($pdo, 'appointments', 'billing_status');
$hasTaskStopped       = $hasTasks && dash_column_exists($pdo, 'tasks', 'recurrence_stopped');
$taskActiveWhere      = $hasTaskStopped ? "AND recurrence_stopped = 0" : "";

// ── Chart data ────────────────────────────────────────────────────────────

$chartPeriods = [
    '7d'  => ['label'=>'7 zile',  'rows'=>dash_trend($pdo,$hasAppointments,$hasBillingColumns,date('Y-m-d',strtotime('-6 days')),$today,'day')],
    '30d' => ['label'=>'30 zile', 'rows'=>dash_trend($pdo,$hasAppointments,$hasBillingColumns,date('Y-m-d',strtotime('-29 days')),$today,'day')],
    '6m'  => ['label'=>'6 luni',  'rows'=>dash_trend($pdo,$hasAppointments,$hasBillingColumns,$sixMonthsStart,$monthEnd,'month')],
    '12m' => ['label'=>'12 luni', 'rows'=>dash_trend($pdo,$hasAppointments,$hasBillingColumns,date('Y-m-01',strtotime('-11 months')),$monthEnd,'month')],
];
$chartMetrics = [
    'total'     => ['label'=>'Lucrări',   'unit'=>'lucrări'],
    'completed' => ['label'=>'Finalizate','unit'=>'finalizate'],
    'value'     => ['label'=>'Valoare',   'unit'=>'lei'],
];
$chartPayload = [];
foreach ($chartMetrics as $mk => $mi) {
    foreach ($chartPeriods as $pk => $pi) {
        $c = dash_line_chart($pi['rows'], $mk, 540, 80);
        $vals = array_map(static fn($r) => (float)($r[$mk] ?? 0), $pi['rows']);
        $last = $vals ? (float)$vals[count($vals)-1] : 0.0;
        $prev = count($vals) > 1 ? (float)$vals[count($vals)-2] : 0.0;
        $chartPayload[$mk][$pk] = ['line'=>$c['line'],'area'=>$c['area'],'points'=>$c['points'],'labels'=>$c['labels'],'value'=>$last,'delta'=>dash_percent_delta($last,$prev),'unit'=>$mi['unit'],'metricLabel'=>$mi['label'],'periodLabel'=>$pi['label']];
    }
}

$sixMonthRows  = $chartPeriods['6m']['rows'];
$currentMonth  = $sixMonthRows[count($sixMonthRows)-1] ?? ['total'=>0,'completed'=>0,'value'=>0,'label'=>''];
$previousMonth = $sixMonthRows[count($sixMonthRows)-2] ?? ['total'=>0,'completed'=>0,'value'=>0];
$monthTotal    = (int)$currentMonth['total'];
$monthCompleted = (int)$currentMonth['completed'];
$monthValue    = (float)$currentMonth['value'];
$mainChart     = $chartPayload['total']['6m'];

// ── Tasks ─────────────────────────────────────────────────────────────────

$tasksOverdue  = $hasTasks ? (int)dash_value($pdo,"SELECT COUNT(*) FROM tasks WHERE due_date<? AND status IN('de_programat','contactat','amanat') {$taskActiveWhere}",[$today],0) : 0;
$tasksDueSoon  = $hasTasks ? (int)dash_value($pdo,"SELECT COUNT(*) FROM tasks WHERE due_date>=? AND due_date<DATE_ADD(?,INTERVAL 7 DAY) AND status IN('de_programat','contactat','amanat') {$taskActiveWhere}",[$today,$today],0) : 0;
$tasksLater    = $hasTasks ? (int)dash_value($pdo,"SELECT COUNT(*) FROM tasks WHERE due_date>=DATE_ADD(?,INTERVAL 7 DAY) AND status IN('de_programat','contactat','amanat') {$taskActiveWhere}",[$today],0) : 0;
$tasksTotal    = $tasksOverdue + $tasksDueSoon + $tasksLater;

$overdueTasksList = [];
if ($hasTasks) {
    $hasTaskClientId = dash_column_exists($pdo,'tasks','client_id');
    $joinClause = ($hasTaskClientId && $hasClients) ? "LEFT JOIN clients c ON c.id = t.client_id" : "";
    $clientCol  = ($hasTaskClientId && $hasClients) ? "c.name AS client_name" : "NULL AS client_name";
    $overdueTasksList = dash_rows($pdo,"SELECT t.id, t.title, t.due_date, {$clientCol} FROM tasks t {$joinClause} WHERE t.due_date < ? AND t.status IN('de_programat','contactat','amanat') {$taskActiveWhere} ORDER BY t.due_date ASC LIMIT 3",[$today]);
}
$tasksToSchedule = $hasTasks ? (int)dash_value($pdo,"SELECT COUNT(*) FROM tasks WHERE status='de_programat' {$taskActiveWhere}",[],0) : 0;

// ── Billing ───────────────────────────────────────────────────────────────

$billingDue = $billingDueAmount = $billingBilled = $billingBilledAmount = 0;
$billingDueList = [];
if ($hasBillingColumns) {
    $r = dash_rows($pdo,"SELECT COUNT(*) AS c, COALESCE(SUM(billing_amount),0) AS s FROM appointments WHERE status='finalizata' AND billing_status='de_facturat' LIMIT 1");
    $billingDue       = (int)($r[0]['c'] ?? 0);
    $billingDueAmount = (float)($r[0]['s'] ?? 0);
    $r = dash_rows($pdo,"SELECT COUNT(*) AS c, COALESCE(SUM(billing_amount),0) AS s FROM appointments WHERE status='finalizata' AND billing_status='facturata' AND appointment_date BETWEEN ? AND ? LIMIT 1",[$monthStart,$monthEnd]);
    $billingBilled       = (int)($r[0]['c'] ?? 0);
    $billingBilledAmount = (float)($r[0]['s'] ?? 0);

    if ($hasClients) {
        $billingDueList = dash_rows($pdo,"SELECT a.id, a.appointment_date, a.service_type, a.billing_amount, c.name AS client_name FROM appointments a LEFT JOIN clients c ON c.id=a.client_id WHERE a.status='finalizata' AND a.billing_status='de_facturat' ORDER BY a.appointment_date ASC LIMIT 3");
    }
}

// ── SmartBill ─────────────────────────────────────────────────────────────

$sbIssuedAmount = $hasSmartbillInvoices ? (float)dash_value($pdo,"SELECT COALESCE(SUM(gross_amount),0) FROM smartbill_invoices WHERE invoice_date BETWEEN ? AND ?",[$monthStart,$monthEnd],0) : 0.0;
$sbPaidAmount   = $hasSmartbillPayments ? (float)dash_value($pdo,"SELECT COALESCE(SUM(amount),0) FROM smartbill_invoice_payments WHERE payment_date BETWEEN ? AND ?",[$monthStart,$monthEnd],0) : 0.0;
$sbSold         = $sbIssuedAmount - $sbPaidAmount;

// Restanțe (facturi emise, termen depășit, cu sold neachitat)
// Schema reală: smartbill_invoices.due_date + smartbill_invoice_payments (fără coloană payment_status)
$restanteList           = [];
$restanteTotalClients   = 0;
$restanteTotalAmount    = 0.0;
if ($hasSmartbillInvoices && dash_column_exists($pdo,'smartbill_invoices','due_date') && dash_column_exists($pdo,'smartbill_invoices','client_name')) {
    $paymentsJoin = $hasSmartbillPayments
        ? "LEFT JOIN (SELECT smartbill_invoice_id, SUM(amount) AS paid FROM smartbill_invoice_payments WHERE COALESCE(smartbill_status,'') NOT IN ('error','deleted') GROUP BY smartbill_invoice_id) p ON p.smartbill_invoice_id = i.id"
        : "";
    $paidExpr = $hasSmartbillPayments ? "GREATEST(0, i.gross_amount - COALESCE(p.paid, 0))" : "i.gross_amount";
    $allRestante = dash_rows($pdo, "
        SELECT i.client_name,
               SUM({$paidExpr}) AS remaining_amount,
               MIN(i.due_date) AS oldest_due
        FROM smartbill_invoices i
        {$paymentsJoin}
        WHERE i.due_date < ?
          AND i.due_date IS NOT NULL
          AND i.source_type <> 'receipt'
          AND TRIM(COALESCE(i.smartbill_number, '')) <> ''
        GROUP BY i.client_name
        HAVING remaining_amount > 0.01
        ORDER BY remaining_amount DESC
        LIMIT 20
    ", [$today]);
    $restanteTotalClients = count($allRestante);
    $restanteTotalAmount  = array_sum(array_column($allRestante, 'remaining_amount'));
    $restanteList = array_slice($allRestante, 0, 3);
}

// ── Stock alerts ──────────────────────────────────────────────────────────

$lowStock = $expiringLots = 0;
if ($hasStockProducts && $hasStockReceipts && $hasStockMovements
    && dash_column_exists($pdo,'stock_products','min_qty')
    && dash_column_exists($pdo,'stock_products','is_active')) {
    $lowStock = (int)dash_value($pdo,"SELECT COUNT(*) FROM (SELECT p.id, p.min_qty, (COALESCE(r.in_qty,0)+COALESCE(m.plus_qty,0)-COALESCE(m.minus_qty,0)) AS current_qty FROM stock_products p LEFT JOIN (SELECT product_id, SUM(qty) AS in_qty FROM stock_receipts GROUP BY product_id) r ON r.product_id=p.id LEFT JOIN (SELECT product_id, SUM(CASE WHEN movement_type IN('adjust_plus','return') THEN qty ELSE 0 END) AS plus_qty, SUM(CASE WHEN movement_type IN('consume','adjust_minus','loss','expired') THEN qty ELSE 0 END) AS minus_qty FROM stock_movements GROUP BY product_id) m ON m.product_id=p.id WHERE p.is_active=1) x WHERE x.min_qty>0 AND x.current_qty<=x.min_qty",[],0);
}
if ($hasStockReceipts && dash_column_exists($pdo,'stock_receipts','expires_at')) {
    $expiringLots = (int)dash_value($pdo,"SELECT COUNT(*) FROM stock_receipts WHERE expires_at IS NOT NULL AND expires_at>=? AND expires_at<=DATE_ADD(?,INTERVAL 30 DAY)",[$today,$today],0);
}

// ── PV lipsă ──────────────────────────────────────────────────────────────

$pvMissing = 0;
if ($hasAppointments && $hasDocuments) {
    $pvMissing = (int)dash_value($pdo,"SELECT COUNT(*) FROM appointments a WHERE a.status='finalizata' AND a.appointment_date>=DATE_SUB(?,INTERVAL 90 DAY) AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.appointment_id=a.id AND d.document_type='proces_verbal' AND d.status!='cancelled')",[$today],0);
}

// ── Agenda & team ─────────────────────────────────────────────────────────

$todayAppointments = $hasAppointments ? dash_rows($pdo,"SELECT a.id, a.start_time, a.end_time, a.service_type, a.status, c.name AS client_name, tm.name AS team_name FROM appointments a LEFT JOIN clients c ON c.id=a.client_id LEFT JOIN team_members tm ON tm.id=a.team_member_id WHERE a.appointment_date=? AND a.status!='anulata' ORDER BY a.start_time ASC, a.id ASC LIMIT 8",[$today]) : [];

$tomorrowDate = date('Y-m-d', strtotime('+1 day'));
$tomorrowAppointments = $hasAppointments ? dash_rows($pdo,"SELECT a.id, a.start_time, a.service_type, a.status, c.name AS client_name, tm.name AS team_name FROM appointments a LEFT JOIN clients c ON c.id=a.client_id LEFT JOIN team_members tm ON tm.id=a.team_member_id WHERE a.appointment_date=? AND a.status!='anulata' ORDER BY a.start_time ASC, a.id ASC LIMIT 4",[$tomorrowDate]) : [];

$teamRows = [];
if ($hasTeamMembers && $hasAppointments) {
    $teamJoin = $hasAppointmentTeams
        ? "LEFT JOIN appointment_teams at2 ON at2.team_id=tm.id LEFT JOIN appointments a ON a.id=at2.appointment_id AND a.appointment_date=? AND a.status!='anulata'"
        : "LEFT JOIN appointments a ON a.team_member_id=tm.id AND a.appointment_date=? AND a.status!='anulata'";
    $teamRows = dash_rows($pdo,"SELECT tm.id, tm.name, tm.color, COUNT(a.id) AS jobs_total, SUM(CASE WHEN a.status='finalizata' THEN 1 ELSE 0 END) AS jobs_done, COALESCE(SUM(CASE WHEN a.start_time IS NOT NULL AND a.end_time IS NOT NULL THEN GREATEST(0,TIME_TO_SEC(a.end_time)-TIME_TO_SEC(a.start_time))/3600 ELSE 0 END),0) AS hours_booked FROM team_members tm {$teamJoin} WHERE tm.active=1 GROUP BY tm.id, tm.name, tm.color ORDER BY hours_booked DESC, tm.name ASC",[$today]);
}
$activeTechnicians  = $hasTeamMembers ? (int)dash_value($pdo,"SELECT COUNT(*) FROM team_members WHERE active=1",[],0) : 0;
$techWithJobsToday  = count(array_filter($teamRows, fn($r) => (int)($r['jobs_total'] ?? 0) > 0));
$techOccupancyPct   = $activeTechnicians > 0 ? round(($techWithJobsToday / $activeTechnicians) * 100) : 0;

// ── Top servicii ──────────────────────────────────────────────────────────

$topServices = $hasAppointments ? dash_rows($pdo,"SELECT COALESCE(NULLIF(service_type,''),'Fără serviciu') AS service_name, COUNT(*) AS total FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status!='anulata' GROUP BY service_name ORDER BY total DESC LIMIT 3",[$monthStart,$monthEnd]) : [];
$topServiceMax = max(1, ...array_map(static fn($r) => (int)($r['total'] ?? 0), $topServices ?: [['total'=>1]]));

// ── Misc ──────────────────────────────────────────────────────────────────

$userName = function_exists('current_user_name') ? current_user_name() : 'Utilizator';

// Topbar global — citit de render_sidebar()
$pz_page_title       = 'Dashboard';
$pz_page_breadcrumbs = [];
$pz_topbar_opts      = ['placeholder' => 'Caută client...'];

$dayNames = ['Sunday'=>'Duminică','Monday'=>'Luni','Tuesday'=>'Marți','Wednesday'=>'Miercuri','Thursday'=>'Joi','Friday'=>'Vineri','Saturday'=>'Sâmbătă'];
$monthNames = ['January'=>'ianuarie','February'=>'februarie','March'=>'martie','April'=>'aprilie','May'=>'mai','June'=>'iunie','July'=>'iulie','August'=>'august','September'=>'septembrie','October'=>'octombrie','November'=>'noiembrie','December'=>'decembrie'];
$todayLabel = ($dayNames[date('l')] ?? date('l')) . ' · ' . date('d') . ' ' . ($monthNames[date('F')] ?? date('F')) . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Dashboard · PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php app_theme_css(); ?>
<style>
/* ── Design tokens (ui_template.php) ─────────────────────────── */
:root {
    --pz-bg:       #F8FAFC;
    --pz-surf:     #FFFFFF;
    --pz-line:     #E2E8F0;
    --pz-lines:    #F1F5F9;
    --pz-title:    #0F172A;
    --pz-text:     #334155;
    --pz-mu:       #64748B;
    --pz-fa:       #94A3B8;
    --pz-bl:       #2563EB;
    --pz-bld:      #1E3A8A;
    --pz-bls:      #EFF6FF;
    --pz-blb:      #BFDBFE;
    --pz-gr:       #166534;
    --pz-grs:      #F0FDF4;
    --pz-grb:      #BBF7D0;
    --pz-or:       #9A3412;
    --pz-ors:      #FFF7ED;
    --pz-orb:      #FED7AA;
    --pz-re:       #991B1B;
    --pz-res:      #FEF2F2;
    --pz-reb:      #FECACA;
    --pz-r:        8px;
    --pz-rs:       4px;
    --pz-gap:      12px;
}

/* ── Base ────────────────────────────────────────────────────── */
.pz-dash *, .pz-dash *::before, .pz-dash *::after { box-sizing: border-box; margin: 0; padding: 0; }
.pz-dash {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    font-size: 12.5px;
    color: var(--pz-text);
    background: var(--pz-bg);
    display: grid;
    gap: var(--pz-gap);
    padding: 14px;
    max-width: 1680px;
    margin: 0 auto;
}

/* ── Page header ─────────────────────────────────────────────── */
.pz-ph { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.pz-ph-name { font-size: 16px; font-weight: 600; color: var(--pz-title); line-height: 1.2; }
.pz-ph-date { font-size: 11.5px; color: var(--pz-mu); margin-top: 1px; }
.pz-live { display: flex; align-items: center; gap: 5px; padding: 4px 9px; border: 1px solid var(--pz-grb); border-radius: var(--pz-rs); background: var(--pz-grs); color: var(--pz-gr); font-size: 11px; font-weight: 600; white-space: nowrap; }
.pz-ldot { width: 6px; height: 6px; border-radius: 50%; background: #22C55E; flex-shrink: 0; }

/* ── Section label ───────────────────────────────────────────── */
.pz-slbl { font-size: 10px; font-weight: 700; color: var(--pz-fa); text-transform: uppercase; letter-spacing: .5px; display: flex; align-items: center; gap: 8px; padding: 2px 0 0; }
.pz-slbl::after { content: ''; flex: 1; height: 1px; background: var(--pz-line); }

/* ── Badges & pills ──────────────────────────────────────────── */
.pz-badge { display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: 700; }
.pz-badge.re { background: var(--pz-res); color: var(--pz-re); border: 1px solid var(--pz-reb); }
.pz-badge.or { background: var(--pz-ors); color: var(--pz-or); border: 1px solid var(--pz-orb); }
.pz-badge.gr { background: var(--pz-grs); color: var(--pz-gr); border: 1px solid var(--pz-grb); }
.pz-badge.bl { background: var(--pz-bls); color: var(--pz-bld); border: 1px solid var(--pz-blb); }
.pz-cbadge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 10px; white-space: nowrap; }
.pz-cbadge.re { background: var(--pz-res); color: var(--pz-re); border: 1px solid var(--pz-reb); }
.pz-cbadge.or { background: var(--pz-ors); color: var(--pz-or); border: 1px solid var(--pz-orb); }
.pz-cbadge.bl { background: var(--pz-bls); color: var(--pz-bld); border: 1px solid var(--pz-blb); }
.pz-cbadge.gr { background: var(--pz-grs); color: var(--pz-gr); border: 1px solid var(--pz-grb); }

/* ── KPI row ─────────────────────────────────────────────────── */
.pz-kpi-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pz-gap); }
.pz-kc {
    background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r);
    padding: 12px 13px 13px; border-left-width: 3px; border-left-style: solid;
    display: flex; flex-direction: column;
}
.pz-kc.bl { border-left-color: var(--pz-bl); }
.pz-kc.or { border-left-color: #F97316; }
.pz-kc.re { border-left-color: #EF4444; }
.pz-kc.gr { border-left-color: #22C55E; }
.pz-kc-drag { display: flex; justify-content: flex-end; margin-bottom: 4px; }
.pz-kc-lbl { font-size: 10.5px; font-weight: 600; color: var(--pz-mu); text-transform: uppercase; letter-spacing: .3px; }
.pz-kc-val { font-size: 22px; font-weight: 700; color: var(--pz-title); line-height: 1.15; margin-top: 5px; }
.pz-kc-val span { font-size: 13px; font-weight: 500; color: var(--pz-mu); }
.pz-kc-sub { font-size: 11px; color: var(--pz-mu); margin-top: auto; padding-top: 6px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }

/* ── Panel (card with header) ────────────────────────────────── */
.pz-pnl { background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); overflow: hidden; display: flex; flex-direction: column; }
.pz-ph2 { padding: 9px 12px; border-bottom: 1px solid var(--pz-lines); display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.pz-phc { flex: 1; display: flex; align-items: center; justify-content: space-between; gap: 8px; min-width: 0; }
.pz-pttl { font-size: 12.5px; font-weight: 600; color: var(--pz-title); }
.pz-pmeta { font-size: 11px; color: var(--pz-mu); margin-top: 1px; }
.pz-pbody { padding: 12px; flex: 1; display: flex; flex-direction: column; }

/* ── Drag handle ─────────────────────────────────────────────── */
.drag-handle { color: var(--pz-fa); cursor: grab; font-size: 14px; flex-shrink: 0; display: flex; align-items: center; user-select: none; -webkit-user-select: none; transition: color .15s; }
.drag-handle:hover { color: var(--pz-mu); }
.drag-handle:active { cursor: grabbing; }
.sortable-ghost { opacity: .2 !important; border: 1.5px dashed var(--pz-bl) !important; background: var(--pz-bls) !important; border-radius: var(--pz-r); }
.sortable-chosen { outline: 2px solid var(--pz-blb); outline-offset: 1px; }

/* ── Alert panels ────────────────────────────────────────────── */
.pz-alerts-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pz-gap); }
.pz-ap-ttl { font-size: 12px; font-weight: 600; color: var(--pz-title); display: flex; align-items: center; gap: 6px; }
.pz-ap-ico { width: 20px; height: 20px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; }
.pz-ap-ico.re { background: var(--pz-res); color: var(--pz-re); }
.pz-ap-ico.or { background: var(--pz-ors); color: var(--pz-or); }
.pz-ap-ico.bl { background: var(--pz-bls); color: var(--pz-bld); }
.pz-ai-wrap { flex: 1; display: flex; flex-direction: column; }
.pz-ai { padding: 8px 12px; border-bottom: 1px solid var(--pz-lines); display: flex; align-items: flex-start; justify-content: space-between; gap: 6px; }
.pz-ai:last-child { border-bottom: 0; }
.pz-ai.warn { background: var(--pz-ors); }
.pz-ai-n { font-size: 11.5px; font-weight: 600; color: var(--pz-title); line-height: 1.25; }
.pz-ai-n.or { color: var(--pz-or); }
.pz-ai-s { font-size: 10.5px; color: var(--pz-mu); margin-top: 1px; }
.pz-ai-v { font-size: 11.5px; font-weight: 700; white-space: nowrap; margin-top: 1px; }
.pz-ai-v.re { color: var(--pz-re); }
.pz-ai-v.or { color: var(--pz-or); }
.pz-ai-v.bl { color: var(--pz-bl); }
.pz-ai-spacer { flex: 1; }
.pz-amore { padding: 8px 12px; font-size: 11px; font-weight: 600; color: var(--pz-bl); background: var(--pz-bls); text-align: center; cursor: pointer; flex-shrink: 0; border-top: 1px solid var(--pz-blb); text-decoration: none; display: block; }
.pz-amore:hover { background: var(--pz-blb); }

/* ── Mid row ─────────────────────────────────────────────────── */
.pz-mid-row { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: var(--pz-gap); }

/* ── Chart ───────────────────────────────────────────────────── */
.pz-ctabs { display: flex; gap: 2px; }
.pz-ctab { padding: 3px 7px; border-radius: 3px; font-size: 10.5px; font-weight: 600; color: var(--pz-mu); cursor: pointer; border: 0; background: transparent; font-family: inherit; }
.pz-ctab.a, .pz-ctab:hover { background: var(--pz-bls); color: var(--pz-bld); }
.pz-chart-stat { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid var(--pz-lines); padding-top: 9px; margin-top: 9px; }
.pz-cs { text-align: center; }
.pz-cs + .pz-cs { border-left: 1px solid var(--pz-lines); }
.pz-cs-l { font-size: 9.5px; font-weight: 600; color: var(--pz-mu); text-transform: uppercase; display: block; }
.pz-cs-v { font-size: 15px; font-weight: 700; display: block; margin-top: 2px; }

/* ── Agenda ──────────────────────────────────────────────────── */
.pz-ag-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px 12px; color: var(--pz-fa); font-size: 12px; text-align: center; }
.pz-ag-slbl { font-size: 10px; font-weight: 700; color: var(--pz-mu); text-transform: uppercase; padding: 8px 12px 4px; border-top: 1px solid var(--pz-lines); flex-shrink: 0; }
.pz-ag-item { padding: 6px 12px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; text-decoration: none; }
.pz-ag-item:hover { background: var(--pz-lines); }
.pz-ag-t { font-size: 10.5px; font-weight: 600; color: var(--pz-mu); min-width: 36px; }
.pz-ag-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.pz-ag-n { font-size: 11.5px; font-weight: 600; color: var(--pz-title); }
.pz-ag-s { font-size: 10.5px; color: var(--pz-mu); }

/* ── Bottom row ──────────────────────────────────────────────── */
.pz-bot-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pz-gap); }

/* ── Team rows ───────────────────────────────────────────────── */
.pz-tr { padding: 7px 12px; border-bottom: 1px solid var(--pz-lines); display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.pz-tr:last-child { border-bottom: 0; }
.pz-tav { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 700; flex-shrink: 0; color: #fff; }
.pz-tn { font-size: 11.5px; font-weight: 600; color: var(--pz-title); min-width: 52px; white-space: nowrap; }
.pz-tbw { flex: 1; height: 4px; background: var(--pz-lines); border-radius: 10px; overflow: hidden; }
.pz-tb2 { height: 100%; border-radius: 10px; }
.pz-tpct { font-size: 10.5px; font-weight: 700; min-width: 30px; text-align: right; }
.pz-ttsk { font-size: 10.5px; color: var(--pz-mu); white-space: nowrap; }

/* ── Finance rows ────────────────────────────────────────────── */
.pz-fr { padding: 8px 12px; border-bottom: 1px solid var(--pz-lines); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.pz-fr:last-child { border-bottom: 0; }
.pz-fr.warn { background: var(--pz-ors); }
.pz-fr.danger { background: var(--pz-res); }
.pz-fl { font-size: 11.5px; color: var(--pz-mu); font-weight: 500; }
.pz-fl.em { font-weight: 600; color: var(--pz-title); }
.pz-fv { font-size: 12.5px; font-weight: 700; }
.pz-fv.bl { color: var(--pz-bld); }
.pz-fv.gr { color: var(--pz-gr); }
.pz-fv.or { color: var(--pz-or); }
.pz-fv.re { color: var(--pz-re); }
.pz-fv.big { font-size: 15px; color: var(--pz-title); }
.pz-svc-section { padding: 10px 12px; border-top: 1px solid var(--pz-lines); flex: 1; }
.pz-svc-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; }
.pz-svc-bar { height: 3px; background: var(--pz-lines); border-radius: 10px; margin-bottom: 10px; }
.pz-svc-fill { height: 100%; border-radius: 10px; }

/* ── Status dot colors for agenda ───────────────────────────── */
.dot-neconfirmata { background: var(--pz-fa); }
.dot-confirmata   { background: var(--pz-bl); }
.dot-in_lucru     { background: #F97316; }
.dot-finalizata   { background: #22C55E; }
.dot-programat    { background: var(--pz-bl); }
.dot-default      { background: var(--pz-fa); }

/* ── Link reset ──────────────────────────────────────────────── */
.pz-dash a { text-decoration: none; }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 1100px) {
    .pz-alerts-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .pz-mid-row { grid-template-columns: 1fr; }
    .pz-bot-row { grid-template-columns: 1fr; }
}
@media (max-width: 680px) {
    .pz-kpi-row { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
    .pz-kc { padding: 8px 8px 10px; }
    .pz-kc-drag { display: none; }
    .pz-kc-lbl { font-size: 9px; letter-spacing: .2px; }
    .pz-kc-val { font-size: 16px; margin-top: 3px; }
    .pz-kc-val span { font-size: 11px; }
    .pz-kc-sub { font-size: 9.5px; gap: 3px; padding-top: 4px; }
    .pz-badge { font-size: 9px; padding: 1px 4px; }
    .pz-alerts-row, .pz-mid-row, .pz-bot-row { grid-template-columns: 1fr; }
    .pz-dash { padding: 8px; gap: 8px; }
    .pz-ph-name { font-size: 14px; }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('dashboard', $isAdmin); ?>
    <main class="main">

        <div class="content pz-dash">

            <!-- ── Page header ─────────────────────────────────── -->
            <div class="pz-ph">
                <div>
                    <div class="pz-ph-name">Bună, <?= dash_h($userName) ?></div>
                    <div class="pz-ph-date"><?= dash_h($todayLabel) ?></div>
                </div>
                <div class="pz-live">
                    <div class="pz-ldot"></div>
                    Date live din platformă
                </div>
            </div>

            <!-- ── KPI strip ──────────────────────────────────── -->
            <div class="pz-slbl">KPI-uri principale</div>
            <div class="pz-kpi-row" id="row-kpi">

                <div class="pz-kc bl" data-card-id="kpi-lucrari">
                    <div class="pz-kc-drag"><i class="ti ti-grip-horizontal drag-handle" aria-hidden="true" style="font-size:12px"></i></div>
                    <div class="pz-kc-lbl">Lucrări luna</div>
                    <div class="pz-kc-val"><?= (int)$monthTotal ?></div>
                    <div class="pz-kc-sub">
                        <?= (int)$monthCompleted ?> finalizate
                        <?php if ($monthTotal > 0): ?>
                            <span class="pz-badge bl"><?= dash_decimal($monthCompleted > 0 ? ($monthCompleted / $monthTotal * 100) : 0, 0) ?>%</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pz-kc or" data-card-id="kpi-facturat">
                    <div class="pz-kc-drag"><i class="ti ti-grip-horizontal drag-handle" aria-hidden="true" style="font-size:12px"></i></div>
                    <div class="pz-kc-lbl">De facturat</div>
                    <div class="pz-kc-val"><?= (int)$billingDue ?></div>
                    <div class="pz-kc-sub">
                        intervenții
                        <?php if ($billingDueAmount > 0): ?>
                            <span class="pz-badge or"><?= dash_money($billingDueAmount) ?> lei</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pz-kc re" data-card-id="kpi-restante">
                    <div class="pz-kc-drag"><i class="ti ti-grip-horizontal drag-handle" aria-hidden="true" style="font-size:12px"></i></div>
                    <div class="pz-kc-lbl">Restanțe</div>
                    <div class="pz-kc-val"><?= $restanteTotalClients > 0 ? (int)$restanteTotalClients : (int)$tasksOverdue ?></div>
                    <div class="pz-kc-sub">
                        <?= $restanteTotalClients > 0 ? 'beneficiari' : 'sarcini întârziate' ?>
                        <?php if ($restanteTotalAmount > 0): ?>
                            <span class="pz-badge re"><?= dash_money($restanteTotalAmount) ?> lei</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pz-kc gr" data-card-id="kpi-echipa">
                    <div class="pz-kc-drag"><i class="ti ti-grip-horizontal drag-handle" aria-hidden="true" style="font-size:12px"></i></div>
                    <div class="pz-kc-lbl">Echipă azi</div>
                    <div class="pz-kc-val"><?= (int)$techWithJobsToday ?> <span>/ <?= (int)$activeTechnicians ?></span></div>
                    <div class="pz-kc-sub">
                        tehnicieni activi
                        <span class="pz-badge gr"><?= (int)$techOccupancyPct ?>%</span>
                    </div>
                </div>

            </div>

            <!-- ── Alert panels ───────────────────────────────── -->
            <div class="pz-slbl">Alerte operaționale</div>
            <div class="pz-alerts-row" id="row-alerts">

                <!-- Sarcini urgente -->
                <div class="pz-pnl" data-card-id="alert-sarcini">
                    <div class="pz-ph2">
                        <i class="ti ti-grip-vertical drag-handle" aria-hidden="true"></i>
                        <div class="pz-phc">
                            <div class="pz-ap-ttl">
                                <div class="pz-ap-ico re"><i class="ti ti-alert-triangle" aria-hidden="true"></i></div>
                                Sarcini urgente
                            </div>
                            <span class="pz-cbadge <?= $tasksOverdue > 0 ? 're' : 'or' ?>"><?= (int)$tasksOverdue ?> întârziate</span>
                        </div>
                    </div>
                    <div class="pz-ai-wrap">
                        <?php if ($overdueTasksList): ?>
                            <?php foreach ($overdueTasksList as $task): ?>
                                <div class="pz-ai">
                                    <div>
                                        <div class="pz-ai-n"><?= dash_h($task['client_name'] ?: $task['title'] ?: 'Sarcină') ?></div>
                                        <div class="pz-ai-s"><?= dash_h($task['title'] ?: '') ?></div>
                                    </div>
                                    <div class="pz-ai-v re">−<?= (int)dash_days_ago($task['due_date']) ?>z</div>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($tasksOverdue === 0): ?>
                            <div class="pz-ai">
                                <div>
                                    <div class="pz-ai-n" style="color:var(--pz-gr)">Fără sarcini întârziate</div>
                                    <div class="pz-ai-s">Totul este la zi</div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($tasksToSchedule > 0): ?>
                            <div class="pz-ai warn">
                                <div>
                                    <div class="pz-ai-n or"><?= (int)$tasksToSchedule ?> lucrări de programat</div>
                                    <div class="pz-ai-s">Fără dată atribuită</div>
                                </div>
                                <div class="pz-ai-v or">→</div>
                            </div>
                        <?php endif; ?>
                        <div class="pz-ai-spacer"></div>
                    </div>
                    <a href="tasks.php" class="pz-amore">
                        <i class="ti ti-arrow-right" style="font-size:11px;vertical-align:-1px;margin-right:4px" aria-hidden="true"></i>
                        Deschide sarcini · <?= (int)$tasksTotal ?> active
                    </a>
                </div>

                <!-- Intervenții de facturat -->
                <div class="pz-pnl" data-card-id="alert-facturat">
                    <div class="pz-ph2">
                        <i class="ti ti-grip-vertical drag-handle" aria-hidden="true"></i>
                        <div class="pz-phc">
                            <div class="pz-ap-ttl">
                                <div class="pz-ap-ico or"><i class="ti ti-receipt" aria-hidden="true"></i></div>
                                De facturat
                            </div>
                            <span class="pz-cbadge <?= $billingDue > 0 ? 'or' : 'gr' ?>"><?= (int)$billingDue ?> intervenții</span>
                        </div>
                    </div>
                    <div class="pz-ai-wrap">
                        <?php if ($billingDueList): ?>
                            <?php foreach ($billingDueList as $item): ?>
                                <div class="pz-ai">
                                    <div>
                                        <div class="pz-ai-n"><?= dash_h($item['client_name'] ?: 'Client') ?></div>
                                        <div class="pz-ai-s"><?= dash_h(($item['service_type'] ?: 'Serviciu') . ' · ' . dash_short_date((string)$item['appointment_date'])) ?></div>
                                    </div>
                                    <div class="pz-ai-v or"><?= dash_money((float)$item['billing_amount']) ?> lei</div>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($billingDue === 0): ?>
                            <div class="pz-ai">
                                <div>
                                    <div class="pz-ai-n" style="color:var(--pz-gr)">Nimic de facturat</div>
                                    <div class="pz-ai-s">Toate intervențiile sunt facturate</div>
                                </div>
                            </div>
                        <?php elseif (!$hasBillingColumns): ?>
                            <div class="pz-ai">
                                <div class="pz-ai-n" style="color:var(--pz-mu)">Modul facturare inactiv</div>
                            </div>
                        <?php endif; ?>
                        <div class="pz-ai-spacer"></div>
                    </div>
                    <a href="work_billing.php?billing_status=de_facturat" class="pz-amore">
                        <i class="ti ti-arrow-right" style="font-size:11px;vertical-align:-1px;margin-right:4px" aria-hidden="true"></i>
                        <?php if ($billingDue > 3): ?>
                            + <?= (int)($billingDue - count($billingDueList)) ?> intervenții · <?= dash_money($billingDueAmount) ?> lei
                        <?php else: ?>
                            Deschide facturare intervenții
                        <?php endif; ?>
                    </a>
                </div>

                <!-- Sume restante -->
                <div class="pz-pnl" data-card-id="alert-restante">
                    <div class="pz-ph2">
                        <i class="ti ti-grip-vertical drag-handle" aria-hidden="true"></i>
                        <div class="pz-phc">
                            <div class="pz-ap-ttl">
                                <div class="pz-ap-ico re"><i class="ti ti-clock-exclamation" aria-hidden="true"></i></div>
                                Sume restante
                            </div>
                            <span class="pz-cbadge <?= $restanteTotalClients > 0 ? 're' : 'gr' ?>"><?= (int)$restanteTotalClients ?> bef.</span>
                        </div>
                    </div>
                    <div class="pz-ai-wrap">
                        <?php if ($restanteList): ?>
                            <?php foreach ($restanteList as $r): ?>
                                <div class="pz-ai">
                                    <div>
                                        <div class="pz-ai-n"><?= dash_h($r['client_name'] ?? 'Client') ?></div>
                                        <div class="pz-ai-s">Scad. <?= dash_short_date((string)($r['oldest_due'] ?? $today)) ?> · <?= (int)dash_days_ago((string)($r['oldest_due'] ?? $today)) ?> zile dep.</div>
                                    </div>
                                    <div class="pz-ai-v re"><?= dash_money((float)$r['remaining_amount']) ?> lei</div>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($restanteTotalClients === 0 && $hasSmartbillInvoices): ?>
                            <div class="pz-ai">
                                <div>
                                    <div class="pz-ai-n" style="color:var(--pz-gr)">Fără restanțe scadente</div>
                                    <div class="pz-ai-s">Toate facturile sunt la zi</div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="pz-ai">
                                <div class="pz-ai-n" style="color:var(--pz-mu)">Date SmartBill indisponibile</div>
                            </div>
                        <?php endif; ?>
                        <div class="pz-ai-spacer"></div>
                    </div>
                    <a href="facturi.php" class="pz-amore">
                        <i class="ti ti-arrow-right" style="font-size:11px;vertical-align:-1px;margin-right:4px" aria-hidden="true"></i>
                        <?php if ($restanteTotalClients > 3): ?>
                            + <?= (int)($restanteTotalClients - 3) ?> beneficiari · <?= dash_money($restanteTotalAmount) ?> lei total
                        <?php else: ?>
                            Deschide facturi emise
                        <?php endif; ?>
                    </a>
                </div>

            </div>

            <!-- ── Activitate & planificare ───────────────────── -->
            <div class="pz-slbl">Activitate & planificare</div>
            <div class="pz-mid-row" id="row-mid">

                <!-- Grafic lucrări -->
                <div class="pz-pnl" data-card-id="mid-chart">
                    <div class="pz-ph2">
                        <i class="ti ti-grip-vertical drag-handle" aria-hidden="true"></i>
                        <div class="pz-phc">
                            <div>
                                <div class="pz-pttl">Lucrări executate</div>
                                <div class="pz-pmeta">Trend pe 6 luni</div>
                            </div>
                            <div class="pz-ctabs" role="tablist" aria-label="Perioadă grafic">
                                <button class="pz-ctab" data-chart-period="7d">7 z</button>
                                <button class="pz-ctab" data-chart-period="30d">30 z</button>
                                <button class="pz-ctab a" data-chart-period="6m">6 luni</button>
                                <button class="pz-ctab" data-chart-period="12m">12 luni</button>
                            </div>
                        </div>
                    </div>
                    <div class="pz-pbody">
                        <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:8px">
                            <span id="chartValMain" style="font-size:24px;font-weight:700;color:var(--pz-title)"><?= (int)$monthTotal ?></span>
                            <span id="chartValSub" style="font-size:11.5px;color:var(--pz-mu)">lucrări · 6 luni</span>
                            <span id="chartDeltaBadge" class="pz-badge <?= (dash_percent_delta((float)$monthTotal, (float)($previousMonth['total'] ?? 0)) ?? 0) >= 0 ? 'gr' : 're' ?>" style="margin-left:auto">
                                <?php
                                    $d = dash_percent_delta((float)$monthTotal, (float)($previousMonth['total'] ?? 0));
                                    echo $d === null ? 'fără comp.' : (($d >= 0 ? '+' : '') . dash_decimal($d, 1) . '% vs luna prec.');
                                ?>
                            </span>
                        </div>
                        <div id="pzChartWrap" style="width:100%">
                            <svg id="pzChartSvg" viewBox="0 0 540 80" width="100%" height="80" preserveAspectRatio="none" aria-label="Trend lucrări">
                                <defs>
                                    <linearGradient id="pzGrad" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#2563EB" stop-opacity=".14"/>
                                        <stop offset="100%" stop-color="#2563EB" stop-opacity="0"/>
                                    </linearGradient>
                                </defs>
                                <line x1="0" y1="0"  x2="540" y2="0"  stroke="#F1F5F9" stroke-width="1"/>
                                <line x1="0" y1="26" x2="540" y2="26" stroke="#F1F5F9" stroke-width="1"/>
                                <line x1="0" y1="52" x2="540" y2="52" stroke="#F1F5F9" stroke-width="1"/>
                                <line x1="0" y1="76" x2="540" y2="76" stroke="#F1F5F9" stroke-width="1"/>
                                <path id="pzArea" d="<?= dash_h($mainChart['area']) ?>" fill="url(#pzGrad)"/>
                                <path id="pzLine" d="<?= dash_h($mainChart['line']) ?>" fill="none" stroke="#2563EB" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
                                <g id="pzDots">
                                    <?php foreach ($mainChart['points'] as $pt): ?>
                                        <circle cx="<?= dash_h(number_format((float)$pt[0],2,'.','')); ?>" cy="<?= dash_h(number_format((float)$pt[1],2,'.','')); ?>" r="3.5" fill="#fff" stroke="#2563EB" stroke-width="1.5"/>
                                    <?php endforeach; ?>
                                </g>
                            </svg>
                        </div>
                        <div id="pzAxis" style="display:flex;justify-content:space-between;margin-top:3px">
                            <?php foreach ($mainChart['labels'] as $lbl): ?>
                                <span style="font-size:9.5px;color:var(--pz-fa)"><?= dash_h($lbl) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <!-- Metric tabs -->
                        <div style="display:flex;gap:4px;margin-top:10px;padding-top:10px;border-top:1px solid var(--pz-lines)">
                            <button class="pz-ctab a" data-chart-metric="total" style="flex:1">Lucrări</button>
                            <button class="pz-ctab" data-chart-metric="completed" style="flex:1">Finalizate</button>
                            <button class="pz-ctab" data-chart-metric="value" style="flex:1">Valoare</button>
                        </div>
                        <div class="pz-chart-stat" style="margin-top:8px">
                            <div class="pz-cs"><span class="pz-cs-l">Luna</span><span class="pz-cs-v" style="color:var(--pz-title)"><?= (int)$monthTotal ?></span></div>
                            <div class="pz-cs"><span class="pz-cs-l">Finalizate</span><span class="pz-cs-v" style="color:var(--pz-bld)"><?= (int)$monthCompleted ?></span></div>
                            <div class="pz-cs"><span class="pz-cs-l">Valoare</span><span class="pz-cs-v" style="color:var(--pz-gr)"><?= dash_money($monthValue) ?></span></div>
                        </div>
                    </div>
                </div>

                <!-- Agenda zilei -->
                <div class="pz-pnl" data-card-id="mid-agenda">
                    <div class="pz-ph2">
                        <i class="ti ti-grip-vertical drag-handle" aria-hidden="true"></i>
                        <div class="pz-phc">
                            <div>
                                <div class="pz-pttl">Agenda zilei</div>
                                <div class="pz-pmeta"><?= dash_h($todayLabel) ?></div>
                            </div>
                            <a href="calendar.php?date=<?= dash_h($today) ?>&view=day" style="font-size:11px;font-weight:600;color:var(--pz-bl)">Calendar →</a>
                        </div>
                    </div>

                    <?php if ($todayAppointments): ?>
                        <?php foreach ($todayAppointments as $ap): ?>
                            <?php
                                $dotClass = 'dot-' . ($ap['status'] ?? 'default');
                                if (!in_array($ap['status'], ['neconfirmata','confirmata','in_lucru','finalizata','programat'])) $dotClass = 'dot-default';
                            ?>
                            <a href="calendar.php?date=<?= dash_h($today) ?>&view=day" class="pz-ag-item">
                                <div class="pz-ag-t"><?= dash_h(dash_time($ap['start_time'] ?? null)) ?></div>
                                <div class="pz-ag-dot <?= $dotClass ?>"></div>
                                <div>
                                    <div class="pz-ag-n"><?= dash_h($ap['client_name'] ?: 'Client') ?></div>
                                    <div class="pz-ag-s"><?= dash_h(($ap['service_type'] ?: 'Serviciu') . ($ap['team_name'] ? ' · ' . $ap['team_name'] : '')) ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="pz-ag-empty">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="display:block;margin-bottom:6px;color:var(--pz-line)" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Nu există programări astăzi
                        </div>
                    <?php endif; ?>

                    <?php if ($tomorrowAppointments): ?>
                        <div class="pz-ag-slbl">Mâine — <?= dash_h(dash_short_date($tomorrowDate)) ?></div>
                        <?php foreach ($tomorrowAppointments as $ap): ?>
                            <?php $dotClass = in_array($ap['status'], ['neconfirmata','confirmata','in_lucru','finalizata','programat']) ? 'dot-' . $ap['status'] : 'dot-default'; ?>
                            <a href="calendar.php?date=<?= dash_h($tomorrowDate) ?>&view=day" class="pz-ag-item">
                                <div class="pz-ag-t"><?= dash_h(dash_time($ap['start_time'] ?? null)) ?></div>
                                <div class="pz-ag-dot <?= $dotClass ?>"></div>
                                <div>
                                    <div class="pz-ag-n"><?= dash_h($ap['client_name'] ?: 'Client') ?></div>
                                    <div class="pz-ag-s"><?= dash_h(($ap['service_type'] ?: 'Serviciu') . ($ap['team_name'] ? ' · ' . $ap['team_name'] : '')) ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ── Echipă & financiar ─────────────────────────── -->
            <div class="pz-slbl">Echipă & financiar</div>
            <div class="pz-bot-row" id="row-bot">

                <!-- Echipa azi -->
                <div class="pz-pnl" data-card-id="bot-echipa">
                    <div class="pz-ph2">
                        <i class="ti ti-grip-vertical drag-handle" aria-hidden="true"></i>
                        <div class="pz-phc">
                            <div>
                                <div class="pz-pttl">Echipa azi</div>
                                <div class="pz-pmeta">Grad ocupare · <?= dash_h(dash_short_date($today)) ?></div>
                            </div>
                            <span class="pz-cbadge bl"><?= (int)$techWithJobsToday ?> activi</span>
                        </div>
                    </div>
                    <?php if ($teamRows): ?>
                        <?php foreach ($teamRows as $tm):
                            $color  = dash_safe_hex($tm['color'] ?? null);
                            $hours  = (float)($tm['hours_booked'] ?? 0);
                            $jobs   = (int)($tm['jobs_total'] ?? 0);
                            $pct    = min(100, $jobs > 0 ? max(8, ($hours / 8) * 100) : ($jobs > 0 ? 30 : 0));
                            $pctInt = (int)round($pct);
                            // bar color based on occupancy
                            $barColor = $pct >= 90 ? '#22C55E' : ($pct >= 50 ? '#2563EB' : ($pct > 0 ? '#F97316' : '#E2E8F0'));
                            $pctColor = $pct >= 90 ? 'var(--pz-gr)' : ($pct >= 50 ? 'var(--pz-bl)' : ($pct > 0 ? 'var(--pz-or)' : 'var(--pz-fa)'));
                        ?>
                            <div class="pz-tr">
                                <div class="pz-tav" style="background:<?= dash_h($color) ?>"><?= dash_h(dash_initials((string)$tm['name'])) ?></div>
                                <div class="pz-tn"><?= dash_h($tm['name']) ?></div>
                                <div class="pz-tbw"><div class="pz-tb2" style="width:<?= $pctInt ?>%;background:<?= dash_h($barColor) ?>"></div></div>
                                <div class="pz-tpct" style="color:<?= $pctColor ?>"><?= $jobs > 0 ? $pctInt . '%' : '—' ?></div>
                                <div class="pz-ttsk"><?= $jobs ?> lucrări</div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:16px;color:var(--pz-fa);font-size:12px;text-align:center">Nu există tehnicieni activi</div>
                    <?php endif; ?>
                </div>

                <!-- Facturare lunii -->
                <div class="pz-pnl" data-card-id="bot-financiar">
                    <div class="pz-ph2">
                        <i class="ti ti-grip-vertical drag-handle" aria-hidden="true"></i>
                        <div class="pz-phc">
                            <div>
                                <div class="pz-pttl">Facturare lunii</div>
                                <div class="pz-pmeta"><?= dash_h(date('F Y', strtotime($monthStart))) ?></div>
                            </div>
                            <span class="pz-cbadge gr">Sincronizat</span>
                        </div>
                    </div>
                    <div class="pz-fr">
                        <div class="pz-fl">Valoare lucrări</div>
                        <div class="pz-fv bl"><?= dash_money($monthValue) ?> lei</div>
                    </div>
                    <div class="pz-fr">
                        <div class="pz-fl">Facturat (<?= (int)$billingBilled ?> facturi)</div>
                        <div class="pz-fv gr"><?= dash_money($billingBilledAmount) ?> lei</div>
                    </div>
                    <?php if ($billingDue > 0): ?>
                        <div class="pz-fr warn">
                            <div class="pz-fl" style="color:var(--pz-or);font-weight:600">De facturat</div>
                            <div class="pz-fv or"><?= (int)$billingDue ?> · <?= dash_money($billingDueAmount) ?> lei</div>
                        </div>
                    <?php endif; ?>
                    <?php if ($restanteTotalAmount > 0): ?>
                        <div class="pz-fr danger">
                            <div class="pz-fl" style="color:var(--pz-re);font-weight:600">Restanțe scadente</div>
                            <div class="pz-fv re"><?= (int)$restanteTotalClients ?> · <?= dash_money($restanteTotalAmount) ?> lei</div>
                        </div>
                    <?php endif; ?>
                    <div class="pz-fr" style="border-top:2px solid var(--pz-line)">
                        <div class="pz-fl em">SmartBill emis</div>
                        <div class="pz-fv big"><?= dash_money($sbIssuedAmount) ?> lei</div>
                    </div>
                    <?php if ($topServices): ?>
                        <div class="pz-svc-section">
                            <div style="font-size:10px;font-weight:700;color:var(--pz-mu);text-transform:uppercase;margin-bottom:8px">Top servicii luna</div>
                            <?php foreach ($topServices as $svc):
                                $w = max(8, min(100, ((int)$svc['total'] / $topServiceMax) * 100));
                            ?>
                                <div class="pz-svc-row">
                                    <span style="font-size:11.5px;font-weight:500;color:var(--pz-text)"><?= dash_h($svc['service_name']) ?></span>
                                    <span class="pz-badge bl"><?= (int)$svc['total'] ?></span>
                                </div>
                                <div class="pz-svc-bar"><div class="pz-svc-fill" style="width:<?= round($w) ?>%;background:var(--pz-bl)"></div></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </div><!-- .pz-dash -->
    </main>
</div><!-- .layout -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
// ── Chart data from PHP ──────────────────────────────────────────────────
const PZ_CHART = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

// ── Chart renderer ───────────────────────────────────────────────────────
(function () {
    let metric = 'total', period = '6m';

    const area   = document.getElementById('pzArea');
    const line   = document.getElementById('pzLine');
    const dots   = document.getElementById('pzDots');
    const axis   = document.getElementById('pzAxis');
    const valMain  = document.getElementById('chartValMain');
    const valSub   = document.getElementById('chartValSub');
    const deltaBadge = document.getElementById('chartDeltaBadge');

    const fmtNum = (v, m) => {
        const n = Number(v || 0);
        return m === 'value'
            ? new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(n) + ' lei'
            : new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 }).format(n);
    };

    const fmtDelta = (d) => {
        if (d === null || d === undefined) return 'fără comp.';
        const sign = Number(d) >= 0 ? '+' : '';
        return sign + new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 1 }).format(d) + '% vs luna prec.';
    };

    const render = () => {
        const d = PZ_CHART?.[metric]?.[period];
        if (!d) return;

        area.setAttribute('d', d.area);
        line.setAttribute('d', d.line);

        dots.innerHTML = '';
        d.points.forEach(p => {
            const c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            c.setAttribute('cx', p[0]);
            c.setAttribute('cy', p[1]);
            c.setAttribute('r', '3.5');
            c.setAttribute('fill', '#fff');
            c.setAttribute('stroke', '#2563EB');
            c.setAttribute('stroke-width', '1.5');
            dots.appendChild(c);
        });

        valMain.textContent = fmtNum(d.value, metric);
        valSub.textContent  = d.unit + ' · ' + d.periodLabel;
        deltaBadge.textContent = fmtDelta(d.delta);
        const isPos = Number(d.delta) >= 0;
        deltaBadge.className = 'pz-badge ' + (d.delta === null ? 'bl' : isPos ? 'gr' : 're');

        axis.innerHTML = '';
        const labels = d.labels || [];
        const step = labels.length > 12 ? Math.ceil(labels.length / 6) : 1;
        labels.forEach((lbl, i) => {
            if (i !== 0 && i !== labels.length - 1 && i % step !== 0) return;
            const s = document.createElement('span');
            s.style.cssText = 'font-size:9.5px;color:var(--pz-fa)';
            s.textContent = lbl;
            axis.appendChild(s);
        });
    };

    document.querySelectorAll('[data-chart-metric]').forEach(btn => {
        btn.addEventListener('click', () => {
            metric = btn.dataset.chartMetric;
            document.querySelectorAll('[data-chart-metric]').forEach(b => b.classList.toggle('a', b === btn));
            render();
        });
    });

    document.querySelectorAll('[data-chart-period]').forEach(btn => {
        btn.addEventListener('click', () => {
            period = btn.dataset.chartPeriod;
            document.querySelectorAll('[data-chart-period]').forEach(b => b.classList.toggle('a', b === btn));
            render();
        });
    });
})();

// ── Drag & drop with order persistence ──────────────────────────────────
(function initDrag() {
    if (typeof Sortable === 'undefined') { setTimeout(initDrag, 100); return; }

    const STORE_KEY = 'pz_dash_order_v1';

    function loadOrder(rowId) {
        try { return JSON.parse(localStorage.getItem(STORE_KEY + '_' + rowId) || 'null'); }
        catch (e) { return null; }
    }

    function saveOrder(rowId, order) {
        try { localStorage.setItem(STORE_KEY + '_' + rowId, JSON.stringify(order)); }
        catch (e) {}
    }

    function applyOrder(rowEl) {
        const order = loadOrder(rowEl.id);
        if (!order || !order.length) return;
        const children = Array.from(rowEl.children);
        order.forEach(cardId => {
            const el = children.find(c => c.dataset.cardId === cardId);
            if (el) rowEl.appendChild(el);
        });
    }

    ['row-kpi', 'row-alerts', 'row-mid', 'row-bot'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        applyOrder(el);
        new Sortable(el, {
            animation: 180,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            easing: 'cubic-bezier(.2,1,.1,1)',
            onEnd: function () {
                const order = Array.from(el.children).map(c => c.dataset.cardId).filter(Boolean);
                saveOrder(id, order);
            }
        });
    });
})();
</script>
</body>
</html>
