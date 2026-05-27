<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once __DIR__ . '/lib/revenue_lib.php';

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

function dash_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $letters .= mb_substr($p, 0, 1, 'UTF-8');
    }
    return mb_strtoupper($letters ?: 'E', 'UTF-8');
}

function dash_safe_hex(?string $color, string $fallback = '#0F6E56'): string {
    $color = trim((string)$color);
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : $fallback;
}

/**
 * Întoarce [start, end, label] pentru o perioadă predefinită.
 */
function dash_period_range(string $period): array {
    $today = date('Y-m-d');
    switch ($period) {
        case 'today':       return [$today, $today, 'Azi'];
        case 'week':        return [date('Y-m-d', strtotime('-6 days')), $today, 'Ultimele 7 zile'];
        case 'last_month':  return [date('Y-m-01', strtotime('first day of previous month')), date('Y-m-t', strtotime('last day of previous month')), 'Luna trecută'];
        case '3months':     return [date('Y-m-d', strtotime('-89 days')), $today, 'Ultimele 3 luni'];
        case '6months':     return [date('Y-m-d', strtotime('-179 days')), $today, 'Ultimele 6 luni'];
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
        '3months'    => 'Ultimele 3 luni',
        '6months'    => 'Ultimele 6 luni',
        'year'       => 'Anul curent',
    ];
}

/**
 * Întoarce [start, end] pentru perioada IMEDIAT ANTERIOARĂ cu aceeași durată.
 * Folosit pentru comparație (delta) pe cardul Operațional.
 */
function dash_period_range_prev(string $period): array {
    [$start, $end] = dash_period_range($period);
    $startTs = strtotime($start);
    $endTs   = strtotime($end);
    if ($period === 'last_month') {
        // Comparăm cu luna dinainte de luna trecută
        $prevStart = date('Y-m-01', strtotime('first day of -2 months'));
        $prevEnd   = date('Y-m-t', strtotime('last day of -2 months'));
        return [$prevStart, $prevEnd];
    }
    if ($period === 'month') {
        $prevStart = date('Y-m-01', strtotime('first day of previous month'));
        $prevEnd   = date('Y-m-t', strtotime('last day of previous month'));
        return [$prevStart, $prevEnd];
    }
    if ($period === 'year') {
        $prevStart = date('Y-01-01', strtotime('-1 year'));
        $prevEnd   = date('Y-12-31', strtotime('-1 year'));
        return [$prevStart, $prevEnd];
    }
    // Default: aceeași durată, imediat înainte
    $duration = ($endTs - $startTs) / 86400 + 1;
    $prevEnd   = date('Y-m-d', strtotime($start . ' -1 day'));
    $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($duration - 1) . ' days'));
    return [$prevStart, $prevEnd];
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
$hasServices          = dash_table_exists($pdo, 'services');
$hasTeamMembers       = dash_table_exists($pdo, 'team_members');
$hasAppointmentTeams  = dash_table_exists($pdo, 'appointment_teams');
$hasDocuments         = dash_table_exists($pdo, 'documents');
$hasSmartbillInvoices = dash_table_exists($pdo, 'smartbill_invoices');
$hasSmartbillPayments = dash_table_exists($pdo, 'smartbill_invoice_payments');
$hasBillingItems      = dash_table_exists($pdo, 'billing_items');
$hasBillingColumns    = $hasAppointments && dash_column_exists($pdo, 'appointments', 'billing_amount') && dash_column_exists($pdo, 'appointments', 'billing_status');
$hasStockProducts     = dash_table_exists($pdo, 'stock_products');
$hasStockReceipts     = dash_table_exists($pdo, 'stock_receipts');
$hasStockMovements    = dash_table_exists($pdo, 'stock_movements');
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

// Comparație cu perioada anterioară (delta procentual)
[$opPrevStart, $opPrevEnd] = dash_period_range_prev($periodOp);
$opPrevTotal = 0;
if ($hasAppointments) {
    $opPrevTotal = (int)dash_value($pdo, "SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status!='anulata'", [$opPrevStart, $opPrevEnd]);
}
$opDelta = null; // procentual; null = N/A
if ($opPrevTotal > 0) {
    $opDelta = round((($opTotal - $opPrevTotal) / $opPrevTotal) * 100);
} elseif ($opTotal > 0) {
    $opDelta = 100; // nu exista date in trecut, dar acum exista lucrari → +100% sau 'nou'
}

// Mini-trend (bare în interiorul cardului). Adaptiv la durata perioadei.
$opTrendBars = []; // [['label' => 'L', 'count' => N], ...]
if ($hasAppointments) {
    $rangeDays = max(1, ((int)(strtotime($opEnd) - strtotime($opStart)) / 86400) + 1);
    // Grupare adaptivă
    if ($rangeDays <= 1) {
        // Azi → ore (8 sloturi a câte 3h)
        $rows = dash_rows($pdo, "SELECT HOUR(start_time) AS h, COUNT(*) AS c FROM appointments WHERE appointment_date = ? AND status!='anulata' GROUP BY h", [$opStart]);
        $buckets = array_fill(0, 8, 0);
        foreach ($rows as $r) {
            $hr = (int)$r['h'];
            $idx = min(7, max(0, intdiv($hr, 3)));
            $buckets[$idx] += (int)$r['c'];
        }
        foreach ($buckets as $i => $c) {
            $opTrendBars[] = ['label' => sprintf('%02d', $i * 3), 'count' => (int)$c];
        }
    } elseif ($rangeDays <= 31) {
        // Săpt / Lună / Luna trecută → pe zi
        $rows = dash_rows($pdo, "SELECT appointment_date AS d, COUNT(*) AS c FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status!='anulata' GROUP BY d", [$opStart, $opEnd]);
        $byDay = [];
        foreach ($rows as $r) { $byDay[(string)$r['d']] = (int)$r['c']; }
        $cursor = strtotime($opStart);
        $endTs = strtotime($opEnd);
        while ($cursor <= $endTs) {
            $key = date('Y-m-d', $cursor);
            $opTrendBars[] = ['label' => date('d', $cursor), 'count' => (int)($byDay[$key] ?? 0)];
            $cursor += 86400;
        }
    } elseif ($rangeDays <= 100) {
        // 3 luni → pe săptămână
        $rows = dash_rows($pdo, "SELECT YEARWEEK(appointment_date, 3) AS w, MIN(appointment_date) AS first_d, COUNT(*) AS c FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status!='anulata' GROUP BY w ORDER BY w ASC", [$opStart, $opEnd]);
        foreach ($rows as $r) {
            $opTrendBars[] = ['label' => date('d.m', strtotime($r['first_d'])), 'count' => (int)$r['c']];
        }
    } else {
        // 6 luni / An → pe lună
        $rows = dash_rows($pdo, "SELECT DATE_FORMAT(appointment_date,'%Y-%m') AS m, COUNT(*) AS c FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status!='anulata' GROUP BY m ORDER BY m ASC", [$opStart, $opEnd]);
        $byMonth = [];
        foreach ($rows as $r) { $byMonth[(string)$r['m']] = (int)$r['c']; }
        $monthCursor = strtotime(date('Y-m-01', strtotime($opStart)));
        $endMonthTs  = strtotime(date('Y-m-01', strtotime($opEnd)));
        $monthLabels = ['01'=>'Ian','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mai','06'=>'Iun','07'=>'Iul','08'=>'Aug','09'=>'Sep','10'=>'Oct','11'=>'Noi','12'=>'Dec'];
        while ($monthCursor <= $endMonthTs) {
            $mk = date('Y-m', $monthCursor);
            $opTrendBars[] = ['label' => $monthLabels[date('m', $monthCursor)] ?? date('m', $monthCursor), 'count' => (int)($byMonth[$mk] ?? 0)];
            $monthCursor = strtotime('+1 month', $monthCursor);
        }
    }
}
$opTrendMax = max(1, max(array_column($opTrendBars ?: [['count' => 1]], 'count')));

$tasksToSchedule = $hasTasks ? (int)dash_value($pdo, "SELECT COUNT(*) FROM tasks WHERE status='de_programat' {$taskActiveWhere}") : 0;

/* ────────────────────────────────────────────────────────────────────────
   DATE - FINANCIAR (de facturat + emis + încasat per perioadă)
   ──────────────────────────────────────────────────────────────────────── */

$finDueCount = $finDueAmount = 0;
$finIssued = $finPaid = 0;
$finIssuedCount = 0;
// Preferăm billing_items (sursa folosită de Lista lucrări). Fallback la appointments.billing_status pentru instalări vechi.
if ($hasBillingItems) {
    $r = dash_rows($pdo, "SELECT COUNT(*) AS c, COALESCE(SUM(total_net),0) AS s FROM billing_items WHERE status='to_invoice' AND work_date BETWEEN ? AND ?", [$finStart, $finEnd]);
    $finDueCount  = (int)($r[0]['c'] ?? 0);
    $finDueAmount = (float)($r[0]['s'] ?? 0);
} elseif ($hasBillingColumns) {
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

// Comparație cu perioada anterioară pentru FACTURATE (cifra de business)
[$finPrevStart, $finPrevEnd] = dash_period_range_prev($periodFin);
$finPrevIssued = 0.0;
if ($hasSmartbillInvoices) {
    $finPrevIssued = (float)dash_value($pdo, "SELECT COALESCE(SUM(gross_amount),0) FROM smartbill_invoices WHERE invoice_date BETWEEN ? AND ? AND TRIM(COALESCE(smartbill_number,'')) <> ''", [$finPrevStart, $finPrevEnd]);
}
$finDelta = null;
if ($finPrevIssued > 0.01) {
    $finDelta = round((($finIssued - $finPrevIssued) / $finPrevIssued) * 100);
} elseif ($finIssued > 0.01) {
    $finDelta = 100;
}

// Mini-trend financiar: facturate (gross) pe bucket-uri adaptate la durată
$finTrendBars = []; // [['label', 'amount'], ...]
if ($hasSmartbillInvoices) {
    $finRangeDays = max(1, ((int)(strtotime($finEnd) - strtotime($finStart)) / 86400) + 1);
    if ($finRangeDays <= 1) {
        // Azi: nu prea avem ce arăta pe ore (puține facturi/zi); afișăm un singur bar mare
        $r = dash_rows($pdo, "SELECT COALESCE(SUM(gross_amount),0) AS s FROM smartbill_invoices WHERE invoice_date = ? AND TRIM(COALESCE(smartbill_number,'')) <> ''", [$finStart]);
        $finTrendBars[] = ['label' => 'azi', 'amount' => (float)($r[0]['s'] ?? 0)];
    } elseif ($finRangeDays <= 31) {
        $rows = dash_rows($pdo, "SELECT invoice_date AS d, COALESCE(SUM(gross_amount),0) AS s FROM smartbill_invoices WHERE invoice_date BETWEEN ? AND ? AND TRIM(COALESCE(smartbill_number,'')) <> '' GROUP BY d", [$finStart, $finEnd]);
        $byDay = [];
        foreach ($rows as $r) { $byDay[(string)$r['d']] = (float)$r['s']; }
        $cursor = strtotime($finStart);
        $endTs = strtotime($finEnd);
        while ($cursor <= $endTs) {
            $key = date('Y-m-d', $cursor);
            $finTrendBars[] = ['label' => date('d', $cursor), 'amount' => (float)($byDay[$key] ?? 0)];
            $cursor += 86400;
        }
    } elseif ($finRangeDays <= 100) {
        $rows = dash_rows($pdo, "SELECT YEARWEEK(invoice_date, 3) AS w, MIN(invoice_date) AS first_d, COALESCE(SUM(gross_amount),0) AS s FROM smartbill_invoices WHERE invoice_date BETWEEN ? AND ? AND TRIM(COALESCE(smartbill_number,'')) <> '' GROUP BY w ORDER BY w ASC", [$finStart, $finEnd]);
        foreach ($rows as $r) {
            $finTrendBars[] = ['label' => date('d.m', strtotime($r['first_d'])), 'amount' => (float)$r['s']];
        }
    } else {
        $rows = dash_rows($pdo, "SELECT DATE_FORMAT(invoice_date,'%Y-%m') AS m, COALESCE(SUM(gross_amount),0) AS s FROM smartbill_invoices WHERE invoice_date BETWEEN ? AND ? AND TRIM(COALESCE(smartbill_number,'')) <> '' GROUP BY m ORDER BY m ASC", [$finStart, $finEnd]);
        $byMonth = [];
        foreach ($rows as $r) { $byMonth[(string)$r['m']] = (float)$r['s']; }
        $monthCursor = strtotime(date('Y-m-01', strtotime($finStart)));
        $endMonthTs  = strtotime(date('Y-m-01', strtotime($finEnd)));
        $monthLabels = ['01'=>'Ian','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mai','06'=>'Iun','07'=>'Iul','08'=>'Aug','09'=>'Sep','10'=>'Oct','11'=>'Noi','12'=>'Dec'];
        while ($monthCursor <= $endMonthTs) {
            $mk = date('Y-m', $monthCursor);
            $finTrendBars[] = ['label' => $monthLabels[date('m', $monthCursor)] ?? date('m', $monthCursor), 'amount' => (float)($byMonth[$mk] ?? 0)];
            $monthCursor = strtotime('+1 month', $monthCursor);
        }
    }
}
$finTrendMax = max(1.0, max(array_column($finTrendBars ?: [['amount' => 1.0]], 'amount')));

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
   VENITURI PE LINIE DE BUSINESS (per perioadă financiar)
   ──────────────────────────────────────────────────────────────────────── */

$revenueByCategory = [];
$revenueTotal = 0.0;
$revenueHasData = false;
$revenueHasColumn = $hasSmartbillInvoices && dash_column_exists($pdo, 'smartbill_invoices', 'revenue_category');

if ($revenueHasColumn) {
    $rowsRev = dash_rows($pdo, "
        SELECT COALESCE(NULLIF(TRIM(revenue_category), ''), 'ddd') AS cat,
               COALESCE(SUM(gross_amount), 0) AS total,
               COUNT(*) AS cnt
        FROM smartbill_invoices
        WHERE invoice_date BETWEEN ? AND ?
          AND source_type <> 'receipt'
          AND TRIM(COALESCE(smartbill_number, '')) <> ''
        GROUP BY cat
    ", [$finStart, $finEnd]);
    $revenueMap = [];
    foreach ($rowsRev as $r) {
        $code = pz_revenue_category_normalize((string)$r['cat'], 'altele');
        $revenueMap[$code] = [
            'amount' => (float)$r['total'],
            'count'  => (int)$r['cnt'],
        ];
    }
    foreach (pz_revenue_categories() as $code => $info) {
        $amt = (float)($revenueMap[$code]['amount'] ?? 0);
        $cnt = (int)($revenueMap[$code]['count'] ?? 0);
        $revenueByCategory[$code] = [
            'code'   => $code,
            'label'  => $info['label'],
            'color'  => $info['color'],
            'bg'     => $info['bg'],
            'border' => $info['border'],
            'amount' => $amt,
            'count'  => $cnt,
        ];
        $revenueTotal += $amt;
        if ($amt > 0 || $cnt > 0) $revenueHasData = true;
    }
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
    $hasTeamColor = dash_column_exists($pdo, 'team_members', 'color');
    $colorCol = $hasTeamColor ? "tm.color" : "NULL AS color";
    $teamList = dash_rows($pdo, "
        SELECT tm.id, tm.name, {$colorCol},
               COUNT(a.id) AS jobs_total,
               SUM(CASE WHEN a.status='finalizata' THEN 1 ELSE 0 END) AS jobs_done
        FROM team_members tm
        LEFT JOIN appointments a ON a.team_member_id=tm.id AND a.appointment_date BETWEEN ? AND ? AND a.status!='anulata'
        WHERE tm.active=1
        GROUP BY tm.id, tm.name, {$colorCol} HAVING jobs_total > 0
        ORDER BY jobs_total DESC LIMIT 5
    ", [$teamStart, $teamEnd]);
}
$teamListMax = max(1, max(array_column($teamList ?: [['jobs_total'=>1]], 'jobs_total')));

/* ────────────────────────────────────────────────────────────────────────
   AGENDA AZI
   ──────────────────────────────────────────────────────────────────────── */

$todayCountTotal = $hasAppointments ? (int)dash_value($pdo, "SELECT COUNT(*) FROM appointments WHERE appointment_date=? AND status!='anulata'", [$today]) : 0;
$todayDoneCountTotal = $hasAppointments ? (int)dash_value($pdo, "SELECT COUNT(*) FROM appointments WHERE appointment_date=? AND status='finalizata'", [$today]) : 0;
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
   MINI CARDURI (linie cu 4 statistici compacte)
   ──────────────────────────────────────────────────────────────────────── */

// Stocuri: produse sub stoc minim + loturi expirate cu stoc
$stockLowCount = 0;
$stockExpiredCount = 0;
if ($hasStockProducts && $hasStockReceipts && $hasStockMovements
    && dash_column_exists($pdo, 'stock_products', 'min_qty')
    && dash_column_exists($pdo, 'stock_products', 'is_active')) {
    $stockLowCount = (int)dash_value($pdo, "
        SELECT COUNT(*) FROM (
            SELECT p.id, p.min_qty,
                (COALESCE(r.in_qty,0) + COALESCE(m.plus_qty,0) - COALESCE(m.minus_qty,0)) AS current_qty
            FROM stock_products p
            LEFT JOIN (SELECT product_id, SUM(qty) AS in_qty FROM stock_receipts GROUP BY product_id) r ON r.product_id = p.id
            LEFT JOIN (
                SELECT product_id,
                    SUM(CASE WHEN movement_type IN ('adjust_plus','return') THEN qty ELSE 0 END) AS plus_qty,
                    SUM(CASE WHEN movement_type IN ('consume','adjust_minus','loss','expired') THEN qty ELSE 0 END) AS minus_qty
                FROM stock_movements GROUP BY product_id
            ) m ON m.product_id = p.id
            WHERE p.is_active = 1
        ) x WHERE x.min_qty > 0 AND x.current_qty <= x.min_qty
    ");
}
if ($hasStockReceipts && dash_column_exists($pdo, 'stock_receipts', 'expires_at') && dash_column_exists($pdo, 'stock_receipts', 'cancelled_at')) {
    $stockExpiredCount = (int)dash_value($pdo, "
        SELECT COUNT(*) FROM stock_receipts r
        WHERE r.cancelled_at IS NULL AND r.expires_at IS NOT NULL AND r.expires_at < ?
    ", [$today]);
}
$stockAlertsTotal = $stockLowCount + $stockExpiredCount;

// Documente emise în perioada Operațional (oferte / contracte / PV / act adițional)
$docsIssuedCount = 0;
$docsByType = ['oferta' => 0, 'contract' => 0, 'proces_verbal' => 0, 'act_aditional' => 0];
if ($hasDocuments && dash_column_exists($pdo, 'documents', 'document_type') && dash_column_exists($pdo, 'documents', 'document_date')) {
    $rows = dash_rows($pdo, "SELECT document_type, COUNT(*) AS c FROM documents WHERE status='issued' AND document_date BETWEEN ? AND ? GROUP BY document_type", [$opStart, $opEnd]);
    foreach ($rows as $r) {
        $type = (string)$r['document_type'];
        $cnt = (int)$r['c'];
        $docsIssuedCount += $cnt;
        if (isset($docsByType[$type])) $docsByType[$type] = $cnt;
    }
}

// PV emise în alb (consum amânat)
$deferredPvCount = 0;
if ($hasDocuments && dash_column_exists($pdo, 'documents', 'payload_json')) {
    $deferredPvCount = (int)dash_value($pdo, "
        SELECT COUNT(*) FROM documents
        WHERE document_type = 'proces_verbal'
          AND status = 'issued'
          AND payload_json LIKE '%\"stock_consumption_deferred\":\"1\"%'
    ");
}

// Clienți activi (cu programări în perioada Operațional)
$activeClients = 0;
if ($hasAppointments && $hasClients) {
    $activeClients = (int)dash_value($pdo, "
        SELECT COUNT(DISTINCT client_id) FROM appointments
        WHERE appointment_date BETWEEN ? AND ? AND status != 'anulata' AND client_id IS NOT NULL
    ", [$opStart, $opEnd]);
}
$totalClients = $hasClients ? (int)dash_value($pdo, "SELECT COUNT(*) FROM clients") : 0;

/* ────────────────────────────────────────────────────────────────────────
   DISTRIBUȚIE STATUS PROGRAMĂRI (donut chart, în perioada Operațional)
   ──────────────────────────────────────────────────────────────────────── */

$statusBreakdown = [
    'finalizata'   => ['label' => 'Finalizate',   'count' => 0, 'color' => '#22C55E'],
    'in_lucru'     => ['label' => 'În lucru',     'count' => 0, 'color' => '#FF7A3D'],
    'confirmata'   => ['label' => 'Confirmate',   'count' => 0, 'color' => '#061142'],
    'neconfirmata' => ['label' => 'Neconfirmate', 'count' => 0, 'color' => '#94A3B8'],
    'anulata'      => ['label' => 'Anulate',      'count' => 0, 'color' => '#DC2626'],
];
if ($hasAppointments) {
    $rows = dash_rows($pdo, "SELECT status, COUNT(*) AS c FROM appointments WHERE appointment_date BETWEEN ? AND ? GROUP BY status", [$opStart, $opEnd]);
    foreach ($rows as $r) {
        $st = (string)$r['status'];
        if (isset($statusBreakdown[$st])) $statusBreakdown[$st]['count'] = (int)$r['c'];
    }
}
$statusTotal = array_sum(array_map(fn($s) => $s['count'], $statusBreakdown));

/* ────────────────────────────────────────────────────────────────────────
   TOP SERVICII (bar chart, în perioada Operațional)
   ──────────────────────────────────────────────────────────────────────── */

$topServices = [];
if ($hasAppointments) {
    $topServices = dash_rows($pdo, "
        SELECT COALESCE(NULLIF(service_type,''),'Fără serviciu') AS service_name, COUNT(*) AS total
        FROM appointments
        WHERE appointment_date BETWEEN ? AND ? AND status != 'anulata'
        GROUP BY service_name ORDER BY total DESC LIMIT 5
    ", [$opStart, $opEnd]);
}
$topServiceMax = max(1, ...array_map(fn($r) => (int)($r['total'] ?? 0), $topServices ?: [['total' => 1]]));

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

/* ────────────────────────────────────────────────────────────────────────
   TOP CLIENȚI (după venituri emise în perioada financiară)
   ──────────────────────────────────────────────────────────────────────── */

$topClients = [];
$topClientMax = 1.0;
if ($hasSmartbillInvoices) {
    $topClients = dash_rows($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(i.client_name), ''), c.name, '-') AS name,
            COALESCE(SUM(i.gross_amount), 0) AS amount,
            COUNT(i.id) AS invoices_count
        FROM smartbill_invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.invoice_date BETWEEN ? AND ?
          AND i.source_type <> 'receipt'
          AND TRIM(COALESCE(i.smartbill_number, '')) <> ''
        GROUP BY COALESCE(NULLIF(TRIM(i.client_name), ''), c.name, '-')
        ORDER BY amount DESC
        LIMIT 5
    ", [$finStart, $finEnd]);

    if (!empty($topClients)) {
        $topClientMax = max(1.0, max(array_map(fn($r) => (float)$r['amount'], $topClients)));
    }
}

/* ────────────────────────────────────────────────────────────────────────
   TREND VENITURI + INCASARI — respecta perioada selectata (period_fin)
   Agregare adaptiva: pe zi / pe saptamana / pe luna in functie de range.
   ──────────────────────────────────────────────────────────────────────── */
$revTrend = []; // [['label' => '01.05', 'issued' => 47230, 'paid' => 42100], ...]
if ($hasSmartbillInvoices) {
    $rangeDays = max(1, ((int)(strtotime($finEnd) - strtotime($finStart)) / 86400) + 1);
    $monthShort = ['01'=>'Ian','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mai','06'=>'Iun','07'=>'Iul','08'=>'Aug','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dec'];

    /* Issued: agregare adaptiva pe zi / saptamana / luna */
    $byKeyIssued = [];
    if ($rangeDays <= 31) {
        $rowsIssued = dash_rows($pdo, "SELECT DATE(invoice_date) AS k, COALESCE(SUM(gross_amount),0) AS s FROM smartbill_invoices WHERE invoice_date BETWEEN ? AND ? AND TRIM(COALESCE(smartbill_number,'')) <> '' GROUP BY DATE(invoice_date)", [$finStart, $finEnd]);
        foreach ($rowsIssued as $r) { $byKeyIssued[(string)$r['k']] = (float)$r['s']; }
    } elseif ($rangeDays <= 100) {
        $rowsIssued = dash_rows($pdo, "SELECT YEARWEEK(invoice_date, 3) AS w, MIN(invoice_date) AS first_d, COALESCE(SUM(gross_amount),0) AS s FROM smartbill_invoices WHERE invoice_date BETWEEN ? AND ? AND TRIM(COALESCE(smartbill_number,'')) <> '' GROUP BY w ORDER BY w ASC", [$finStart, $finEnd]);
        foreach ($rowsIssued as $r) { $byKeyIssued[(string)$r['first_d']] = (float)$r['s']; }
    } else {
        $rowsIssued = dash_rows($pdo, "SELECT DATE_FORMAT(invoice_date,'%Y-%m') AS m, COALESCE(SUM(gross_amount),0) AS s FROM smartbill_invoices WHERE invoice_date BETWEEN ? AND ? AND TRIM(COALESCE(smartbill_number,'')) <> '' GROUP BY m ORDER BY m ASC", [$finStart, $finEnd]);
        foreach ($rowsIssued as $r) { $byKeyIssued[(string)$r['m']] = (float)$r['s']; }
    }

    /* Paid: aceeasi agregare */
    $byKeyPaid = [];
    if ($hasSmartbillPayments) {
        if ($rangeDays <= 31) {
            $rowsPaid = dash_rows($pdo, "SELECT DATE(payment_date) AS k, COALESCE(SUM(amount),0) AS s FROM smartbill_invoice_payments WHERE payment_date BETWEEN ? AND ? AND COALESCE(smartbill_status,'') NOT IN ('error','deleted') GROUP BY DATE(payment_date)", [$finStart, $finEnd]);
            foreach ($rowsPaid as $r) { $byKeyPaid[(string)$r['k']] = (float)$r['s']; }
        } elseif ($rangeDays <= 100) {
            $rowsPaid = dash_rows($pdo, "SELECT YEARWEEK(payment_date, 3) AS w, MIN(payment_date) AS first_d, COALESCE(SUM(amount),0) AS s FROM smartbill_invoice_payments WHERE payment_date BETWEEN ? AND ? AND COALESCE(smartbill_status,'') NOT IN ('error','deleted') GROUP BY w ORDER BY w ASC", [$finStart, $finEnd]);
            foreach ($rowsPaid as $r) { $byKeyPaid[(string)$r['first_d']] = (float)$r['s']; }
        } else {
            $rowsPaid = dash_rows($pdo, "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS m, COALESCE(SUM(amount),0) AS s FROM smartbill_invoice_payments WHERE payment_date BETWEEN ? AND ? AND COALESCE(smartbill_status,'') NOT IN ('error','deleted') GROUP BY m ORDER BY m ASC", [$finStart, $finEnd]);
            foreach ($rowsPaid as $r) { $byKeyPaid[(string)$r['m']] = (float)$r['s']; }
        }
    }

    /* Construire serie completa cu zerouri */
    if ($rangeDays <= 31) {
        $cursor = strtotime($finStart);
        $endTs = strtotime($finEnd);
        while ($cursor <= $endTs) {
            $key = date('Y-m-d', $cursor);
            $revTrend[] = [
                'label'  => date('d.m', $cursor),
                'issued' => round((float)($byKeyIssued[$key] ?? 0), 2),
                'paid'   => round((float)($byKeyPaid[$key] ?? 0), 2),
            ];
            $cursor += 86400;
        }
    } elseif ($rangeDays <= 100) {
        /* Pe saptamani: iterez prin keys-urile combinate (issued + paid) */
        $allKeys = array_unique(array_merge(array_keys($byKeyIssued), array_keys($byKeyPaid)));
        sort($allKeys);
        foreach ($allKeys as $k) {
            $revTrend[] = [
                'label'  => date('d.m', strtotime($k)),
                'issued' => round((float)($byKeyIssued[$k] ?? 0), 2),
                'paid'   => round((float)($byKeyPaid[$k] ?? 0), 2),
            ];
        }
    } else {
        $monthCursor = strtotime(date('Y-m-01', strtotime($finStart)));
        $endMonthTs  = strtotime(date('Y-m-01', strtotime($finEnd)));
        while ($monthCursor <= $endMonthTs) {
            $mk = date('Y-m', $monthCursor);
            $mm = date('m', $monthCursor);
            $revTrend[] = [
                'label'  => $monthShort[$mm] ?? date('M', $monthCursor),
                'issued' => round((float)($byKeyIssued[$mk] ?? 0), 2),
                'paid'   => round((float)($byKeyPaid[$mk] ?? 0), 2),
            ];
            $monthCursor = strtotime('+1 month', $monthCursor);
        }
    }
}

/* ────────────────────────────────────────────────────────────────────────
   SARCINI ACTIVE (în perioada financiară + restante)
   Filtrul vizibil din header (period_fin) controlează intervalul afișat;
   sarcinile cu due_date < azi rămân vizibile chiar dacă perioada selectată
   nu le acoperă, pentru ca utilizatorul să nu rateze ce e restant.
   ──────────────────────────────────────────────────────────────────────── */

$tasksDash       = [];
$tasksOverdue    = 0;
$tasksTodayCount = 0;
$tasksInPeriod   = 0;
$tasksFuture     = 0;
if ($hasTasks) {
    $taskClientJoin = $hasClients
        ? "LEFT JOIN clients c ON c.id = t.client_id"
        : "";
    $taskClientName = $hasClients
        ? "COALESCE(NULLIF(TRIM(c.name), ''), t.title, t.service_type, 'Sarcină') AS client_name"
        : "COALESCE(NULLIF(TRIM(t.title), ''), t.service_type, 'Sarcină') AS client_name";

    $tasksDash = dash_rows($pdo, "
        SELECT
            t.id, t.due_date, t.title, t.service_type, t.status,
            $taskClientName
        FROM tasks t
        $taskClientJoin
        WHERE t.status NOT IN ('skipped', 'done', 'finalizata')
          $taskActiveWhere
          AND (
                t.due_date BETWEEN ? AND ?
             OR t.due_date < CURDATE()
          )
        ORDER BY t.due_date ASC, t.id ASC
        LIMIT 5
    ", [$finStart, $finEnd]);

    $tasksOverdue    = (int)dash_value($pdo, "SELECT COUNT(*) FROM tasks WHERE status NOT IN ('skipped','done','finalizata') $taskActiveWhere AND due_date < CURDATE()");
    $tasksTodayCount = (int)dash_value($pdo, "SELECT COUNT(*) FROM tasks WHERE status NOT IN ('skipped','done','finalizata') $taskActiveWhere AND due_date = CURDATE()");
    $tasksInPeriod   = (int)dash_value($pdo, "SELECT COUNT(*) FROM tasks WHERE status NOT IN ('skipped','done','finalizata') $taskActiveWhere AND due_date BETWEEN ? AND ?", [$finStart, $finEnd]);
    $tasksFuture     = (int)dash_value($pdo, "SELECT COUNT(*) FROM tasks WHERE status NOT IN ('skipped','done','finalizata') $taskActiveWhere AND due_date > CURDATE() AND due_date BETWEEN ? AND ?", [$finStart, $finEnd]);
}

/* ────────────────────────────────────────────────────────────────────────
   REMINDERS (în perioada financiară + restante)
   ──────────────────────────────────────────────────────────────────────── */

$hasReminders = dash_table_exists($pdo, 'reminders');

$remindersDash       = [];
$remindersOverdue    = 0;
$remindersTodayCount = 0;
$remindersInPeriod   = 0;
$remindersFuture     = 0;
if ($hasReminders) {
    $remindersDash = dash_rows($pdo, "
        SELECT id, title, category, remind_date, remind_time, status
        FROM reminders
        WHERE status = 'pending'
          AND (
                remind_date BETWEEN ? AND ?
             OR remind_date < CURDATE()
          )
        ORDER BY remind_date ASC, COALESCE(remind_time, '00:00:00') ASC, id ASC
        LIMIT 5
    ", [$finStart, $finEnd]);

    $remindersOverdue    = (int)dash_value($pdo, "SELECT COUNT(*) FROM reminders WHERE status = 'pending' AND remind_date < CURDATE()");
    $remindersTodayCount = (int)dash_value($pdo, "SELECT COUNT(*) FROM reminders WHERE status = 'pending' AND remind_date = CURDATE()");
    $remindersInPeriod   = (int)dash_value($pdo, "SELECT COUNT(*) FROM reminders WHERE status = 'pending' AND remind_date BETWEEN ? AND ?", [$finStart, $finEnd]);
    $remindersFuture     = (int)dash_value($pdo, "SELECT COUNT(*) FROM reminders WHERE status = 'pending' AND remind_date > CURDATE() AND remind_date BETWEEN ? AND ?", [$finStart, $finEnd]);
}

/* ────────────────────────────────────────────────────────────────────────
   SPARKLINE DATE — venituri zilnice în perioada financiară
   Pentru graficul mic din KPI „Venituri".
   ──────────────────────────────────────────────────────────────────────── */

$dailyRevenue = []; // [['d' => 'Y-m-d', 'v' => float], ...] (complet, cu zile zero)
if ($hasSmartbillInvoices) {
    $rowsDaily = dash_rows($pdo, "
        SELECT DATE(invoice_date) AS d, COALESCE(SUM(gross_amount), 0) AS s
        FROM smartbill_invoices
        WHERE invoice_date BETWEEN ? AND ?
          AND TRIM(COALESCE(smartbill_number, '')) <> ''
        GROUP BY DATE(invoice_date)
        ORDER BY d ASC
    ", [$finStart, $finEnd]);
    $byDay = [];
    foreach ($rowsDaily as $r) { $byDay[(string)$r['d']] = (float)$r['s']; }

    $cursor = strtotime($finStart);
    $endTs  = strtotime($finEnd);
    while ($cursor <= $endTs) {
        $key = date('Y-m-d', $cursor);
        $dailyRevenue[] = ['d' => $key, 'v' => (float)($byDay[$key] ?? 0.0)];
        $cursor = strtotime('+1 day', $cursor);
    }
}

/**
 * Construiește un path SVG (linie + zonă) dintr-o serie de valori,
 * normalizat la viewBox 200x38. Întoarce ['line' => 'M...', 'area' => 'M...', 'last' => [x,y]]
 * sau null dacă seria nu are date.
 */
function dash_sparkline_paths(array $values, float $w = 200.0, float $h = 38.0, float $padTop = 4.0, float $padBottom = 2.0): ?array {
    $n = count($values);
    if ($n < 1) return null;
    $max = (float)max(0.0001, max($values));
    $usable = $h - $padTop - $padBottom;
    $denomX = ($n > 1) ? ($n - 1) : 1;

    $linePts = [];
    foreach ($values as $i => $val) {
        $x = ($n === 1) ? ($w / 2) : ($i * $w / $denomX);
        $y = $padTop + ($usable - ($val / $max) * $usable);
        $linePts[] = round($x, 2) . ',' . round($y, 2);
    }
    $line = 'M' . implode(' L', $linePts);
    $area = $line . ' L' . round(($n === 1 ? $w / 2 : $w), 2) . ',' . round($h, 2) . ' L0,' . round($h, 2) . ' Z';

    $lastParts = explode(',', end($linePts));
    return [
        'line' => $line,
        'area' => $area,
        'last' => [(float)$lastParts[0], (float)$lastParts[1]],
    ];
}

$revenueValues = array_map(fn($r) => (float)$r['v'], $dailyRevenue);
$revenueSpark  = dash_sparkline_paths($revenueValues);

// Iconuri pe categorie de reminder (folosesc set-ul Tabler deja încărcat de pagină)
$remCategoryIcon = [
    'comercial'      => 'ti-mail',
    'administrativ'  => 'ti-phone',
    'financiar'      => 'ti-cash',
    'documente'      => 'ti-file-text',
    'conformitate'   => 'ti-shield-check',
    'tehnic'         => 'ti-tool',
    'other'          => 'ti-bell',
];

/* ────────────────────────────────────────────────────────────────────────
   LAYOUT PERSONALIZABIL (drag & drop)
   Ordinea cardurilor se salvează per utilizator în user_dashboard_layout.
   ──────────────────────────────────────────────────────────────────────── */

$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_dashboard_layout (
        user_id INT NOT NULL PRIMARY KEY,
        layout_json TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$dashKpiDefaultOrder = ['kpi-revenue', 'kpi-invoices', 'kpi-today', 'kpi-due'];
$dashBigDefaultOrder = [
    'card-revchart', 'card-statusdonut',
    'card-todayappts', 'card-topclients',
    'card-tasks', 'card-reminders',
];

$dashKpiOrder = $dashKpiDefaultOrder;
$dashBigOrder = $dashBigDefaultOrder;

$dashUserId = function_exists('current_user_id') ? (int)current_user_id() : 0;
if ($dashUserId > 0) {
    $savedJson = (string)dash_value($pdo, "SELECT layout_json FROM user_dashboard_layout WHERE user_id = ?", [$dashUserId], '');
    if ($savedJson !== '') {
        $saved = json_decode($savedJson, true);
        if (is_array($saved)) {
            $maybeKpis = $saved['kpis']     ?? [];
            $maybeBigs = $saved['bigCards'] ?? [];
            // Validăm strict: aceleași seturi, fără duplicate, exact aceeași dimensiune.
            $validKpis = is_array($maybeKpis)
                && count($maybeKpis) === count($dashKpiDefaultOrder)
                && count(array_diff($maybeKpis, $dashKpiDefaultOrder)) === 0
                && count(array_unique($maybeKpis)) === count($dashKpiDefaultOrder);
            $validBigs = is_array($maybeBigs)
                && count($maybeBigs) === count($dashBigDefaultOrder)
                && count(array_diff($maybeBigs, $dashBigDefaultOrder)) === 0
                && count(array_unique($maybeBigs)) === count($dashBigDefaultOrder);
            if ($validKpis) $dashKpiOrder = array_values($maybeKpis);
            if ($validBigs) $dashBigOrder = array_values($maybeBigs);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Dashboard · <?= h(pz_app_name()) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css" rel="stylesheet">
<?php app_theme_css(); ?>
<style>
/* ============================================================
   PZ Dashboard — layout nou (post audit, conform mockup)
   Folosește variabilele globale --pz-* din app_theme_css.php
   ============================================================ */

.pz-dash {
    font-family: 'Satoshi', 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--pz-text);
    background: var(--pz-bg);
    padding: 18px 20px;
    display: grid;
    gap: 14px;
    max-width: 1680px;
    margin: 0 auto;
    /* Fix: previne overflow horizontal pe mobile */
    width: 100%;
    min-width: 0;
    overflow-x: hidden;
}
.pz-dash * { box-sizing: border-box; }
/* Fix critic anti-overflow: toate elementele grid trebuie sa poata sa se micsoreze */
.pz-dash > *,
.pz-kpi-grid > *,
.pz-row-2 > *,
.pz-row-2-bottom > *,
.pz-big-grid > * { min-width: 0; }
.pz-dash a { text-decoration: none; color: inherit; }
.pz-dash .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }

/* Welcome header */
.pz-head {
    display: flex; align-items: flex-end; justify-content: space-between;
    flex-wrap: wrap; gap: 14px; margin-bottom: 2px;
}
.pz-head .pz-kicker { font-size: 11px; font-weight: 600; color: var(--pz-mu); letter-spacing: .08em; text-transform: uppercase; margin: 0 0 6px; line-height: 1; }
.pz-head .pz-greet { font-size: 13px; color: var(--pz-mu); margin: 0 0 4px; }
.pz-head .pz-title { font-size: 22px; font-weight: 700; color: var(--pz-title); margin: 0; letter-spacing: -.005em; }
.pz-head .pz-date  { font-size: 12px; color: var(--pz-fa); margin: 4px 0 0; }

.pz-head-actions { display: flex; gap: 8px; align-items: center; }
.pz-period {
    display: inline-flex; padding: 3px;
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    background: var(--pz-surf);
}
.pz-period a {
    padding: 5px 12px; font-size: 12px; border-radius: 6px;
    color: var(--pz-mu); font-weight: 400; transition: all .15s;
}
.pz-period a:hover { color: var(--pz-title); }
.pz-period a.current {
    background: var(--pz-soft);
    color: var(--pz-title); font-weight: 500;
}
.pz-btn-ghost {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 11px; font-size: 12px;
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    color: var(--pz-text); cursor: pointer;
    transition: all .15s;
}
.pz-btn-ghost:hover { background: var(--pz-soft); border-color: var(--pz-blb); color: var(--pz-bld); }
.pz-btn-ghost i { font-size: 14px; }

/* KPI Grid */
.pz-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}
.pz-kpi {
    position: relative;
    overflow: hidden;
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    padding: 13px 15px;
    transition: border-color .15s;
}
.pz-kpi:hover { border-color: var(--pz-blb); }
/* Accent-bar 3px stânga conform DESIGN_LINE.md §3. Modifier: .bl/.gr/.or/.re/.mu */
.pz-kpi::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--pz-fa, #94A3B8); }
.pz-kpi.bl::before { background: var(--pz-bl); }
.pz-kpi.gr::before { background: var(--pz-gr-acc, #16A34A); }
.pz-kpi.or::before { background: var(--pz-or-acc, #9A3412); }
.pz-kpi.re::before { background: var(--pz-re-acc, #DC2626); }
.pz-kpi.mu::before { background: var(--pz-fa, #94A3B8); }
.pz-kpi .pz-kpi-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 6px;
}
.pz-kpi .pz-kpi-label { font-size: 11.5px; color: var(--pz-mu); }
.pz-kpi .pz-kpi-badge {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 10.5px; font-weight: 500;
    padding: 2px 7px; border-radius: 11px;
}
.pz-kpi .pz-kpi-badge i { font-size: 11px; }
.pz-kpi .pz-kpi-badge.up    { color: var(--pz-gr); background: var(--pz-grs); }
.pz-kpi .pz-kpi-badge.down  { color: var(--pz-re); background: var(--pz-res); }
.pz-kpi .pz-kpi-badge.flat  { color: var(--pz-mu); background: var(--pz-soft); }
.pz-kpi .pz-kpi-badge.info  { color: var(--pz-bld); background: var(--pz-bls); }
.pz-kpi .pz-kpi-badge.warn  { color: var(--pz-or); background: var(--pz-ors); }
.pz-kpi .pz-kpi-value {
    font-size: 22px; font-weight: 500; color: var(--pz-title);
    line-height: 1.2; font-variant-numeric: tabular-nums;
}
.pz-kpi .pz-kpi-value .unit { font-size: 12px; color: var(--pz-fa); margin-left: 4px; font-weight: 400; }
.pz-kpi .pz-kpi-foot { font-size: 11px; color: var(--pz-fa); margin-top: 4px; }
.pz-kpi .pz-kpi-bar {
    display: flex; height: 4px;
    background: var(--pz-soft);
    border-radius: 3px; margin-top: 8px;
    overflow: hidden;
}
.pz-kpi .pz-kpi-bar > span { display: block; height: 100%; }
.pz-kpi .pz-spark {
    display: block;
    width: 100%;
    height: 32px;
    margin-top: 8px;
    overflow: visible;
}

/* Status facturi — layout donut + bare orizontale */
.pz-status-split {
    display: grid;
    grid-template-columns: minmax(0, 140px) minmax(0, 1fr);
    gap: 16px;
    align-items: center;
    margin-top: 4px;
}
.pz-status-donut {
    position: relative;
    width: 100%;
    min-width: 0;
}
.pz-status-donut .pz-chart-wrap.donut {
    height: 140px;
    width: 100%;
}
.pz-status-donut-total {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}
.pz-status-donut-total .num {
    font-size: 18px; font-weight: 500; color: var(--pz-title);
    line-height: 1; font-variant-numeric: tabular-nums;
}
.pz-status-donut-total .lbl {
    font-size: 10px; color: var(--pz-fa); margin-top: 2px;
}

.pz-amount-bars { min-width: 0; }
.pz-amount-bars .pz-amount-bars-title {
    font-size: 10.5px; color: var(--pz-fa);
    margin: 0 0 8px; text-transform: uppercase; letter-spacing: .04em;
}
.pz-amount-bars .row { margin-bottom: 9px; }
.pz-amount-bars .row:last-child { margin-bottom: 0; }
.pz-amount-bars .head {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 11px; margin-bottom: 3px; gap: 6px;
}
.pz-amount-bars .head .label {
    display: inline-flex; align-items: center; gap: 5px;
    color: var(--pz-text);
    white-space: nowrap;
}
.pz-amount-bars .head .label .dot { width: 7px; height: 7px; border-radius: 2px; display: inline-block; }
.pz-amount-bars .head .value {
    color: var(--pz-title); font-weight: 500;
    font-variant-numeric: tabular-nums; white-space: nowrap;
    overflow: hidden; text-overflow: ellipsis;
}
.pz-amount-bars .bar {
    height: 6px; background: var(--pz-lines);
    border-radius: 3px; overflow: hidden;
}
.pz-amount-bars .bar > span { display: block; height: 100%; transition: width .3s ease; }

/* Counts (sub split) — variantă compactă pe 3 coloane */
.pz-donut-legend.pz-status-counts {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0;
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid var(--pz-lines);
}
.pz-donut-legend.pz-status-counts .row {
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    gap: 2px;
}
.pz-donut-legend.pz-status-counts .row .label {
    font-size: 10.5px; color: var(--pz-mu);
}
.pz-donut-legend.pz-status-counts .row .value {
    font-size: 14px; font-weight: 500; color: var(--pz-title);
}

/* Charts row */
.pz-row-2 { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 10px; }
.pz-row-2-bottom { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }

/* Grid unificat pentru cele 6 carduri mari (drag & drop cross-row) */
.pz-big-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}
.pz-big-grid > * { min-width: 0; }

/* Period selector — orizontal scroll dacă nu încape */
.pz-period {
    max-width: 100%;
    overflow-x: auto;
    flex-wrap: nowrap;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.pz-period::-webkit-scrollbar { display: none; }
.pz-period a { white-space: nowrap; flex-shrink: 0; }

.pz-card {
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    padding: 14px 16px;
}
.pz-card-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 10px; gap: 10px;
}
.pz-card-head .pz-card-title-sm { font-size: 11.5px; color: var(--pz-mu); margin: 0; }
.pz-card-head .pz-card-title { font-size: 14px; font-weight: 500; color: var(--pz-title); margin: 2px 0 0; }
.pz-card-link {
    font-size: 11px; color: var(--pz-bl);
    display: inline-flex; align-items: center; gap: 3px;
    transition: color .15s;
}
.pz-card-link:hover { color: var(--pz-bld); }
.pz-card-link i { font-size: 12px; }

.pz-legend { display: flex; gap: 14px; font-size: 11px; color: var(--pz-mu); flex-wrap: wrap; }
.pz-legend span { display: inline-flex; align-items: center; gap: 4px; }
.pz-legend .dot { width: 8px; height: 8px; border-radius: 2px; display: inline-block; }

.pz-chart-wrap {
    position: relative;
    height: 220px;
    width: 100%;
    min-width: 0;
    overflow: hidden;
}
.pz-chart-wrap.donut { height: 160px; }
.pz-chart-wrap canvas {
    max-width: 100% !important;
}

/* Donut legend (jos sub donut) */
.pz-donut-legend { display: flex; flex-direction: column; gap: 5px; font-size: 12px; margin-top: 8px; }
.pz-donut-legend .row { display: flex; align-items: center; justify-content: space-between; }
.pz-donut-legend .row .label { display: inline-flex; align-items: center; gap: 6px; color: var(--pz-text); }
.pz-donut-legend .row .label .dot { width: 8px; height: 8px; border-radius: 2px; }
.pz-donut-legend .row .value { font-weight: 500; color: var(--pz-title); font-variant-numeric: tabular-nums; }

/* Lista programări azi */
.pz-appt-list { display: flex; flex-direction: column; gap: 4px; }
.pz-appt-row {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 8px; border-radius: 6px;
    transition: background .15s;
}
.pz-appt-row:hover { background: var(--pz-soft); }
.pz-appt-row.active { background: var(--pz-bls); }
.pz-appt-time { width: 38px; text-align: center; font-size: 12px; font-weight: 500; color: var(--pz-mu); flex-shrink: 0; font-variant-numeric: tabular-nums; }
.pz-appt-info { flex: 1; min-width: 0; }
.pz-appt-info .name { font-size: 12.5px; font-weight: 500; color: var(--pz-title); margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pz-appt-info .tech { font-size: 10.5px; color: var(--pz-fa); margin: 1px 0 0; }
.pz-appt-status { font-size: 10px; font-weight: 500; padding: 2px 7px; border-radius: 10px; flex-shrink: 0; }
.pz-appt-status.in-progress { color: var(--pz-bld); background: var(--pz-bls); }
.pz-appt-status.done { color: var(--pz-gr); background: var(--pz-grs); }
.pz-appt-status.pending { color: var(--pz-mu); background: var(--pz-soft); }
/* Modificatori folosiți de cardurile Sarcini & Reminders */
.pz-appt-status.overdue { color: var(--pz-re); background: var(--pz-res); }
.pz-appt-status.today   { color: var(--pz-bld); background: var(--pz-bls); }
.pz-appt-status.future  { color: var(--pz-mu); background: var(--pz-soft); }
.pz-appt-row.is-overdue,
.pz-appt-row.is-overdue:hover { background: var(--pz-res); }
.pz-appt-row.is-today,
.pz-appt-row.is-today:hover   { background: var(--pz-bls); }
.pz-appt-row.is-overdue .pz-appt-time { color: var(--pz-re); }
.pz-appt-row.is-today   .pz-appt-time { color: var(--pz-bld); }
/* Înălțime egală garantată în rândurile cu 2 carduri */
.pz-row-2-bottom > .pz-card,
.pz-big-grid > .pz-card { display: flex; flex-direction: column; }
.pz-row-2-bottom > .pz-card > .pz-appt-list,
.pz-row-2-bottom > .pz-card > .pz-clients-list,
.pz-big-grid > .pz-card > .pz-appt-list,
.pz-big-grid > .pz-card > .pz-clients-list { flex: 1; }

/* ── Drag & Drop ─────────────────────────────────────────── */
.pz-kpi-grid > .pz-kpi,
.pz-big-grid > .pz-card { position: relative; }

.pz-card-grip {
    position: absolute;
    top: 6px;
    left: 6px;
    width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--pz-fa);
    background: transparent;
    border-radius: 4px;
    cursor: grab;
    opacity: 0.45;
    transition: opacity .15s, background .15s, color .15s;
    z-index: 3;
    user-select: none;
    -webkit-user-select: none;
}
.pz-card-grip i { font-size: 14px; line-height: 1; pointer-events: none; }
.pz-kpi:hover  .pz-card-grip,
.pz-card:hover .pz-card-grip { opacity: 1; color: var(--pz-mu); background: var(--pz-soft); }
.pz-card-grip:active { cursor: grabbing; background: var(--pz-bls); color: var(--pz-bld); }

/* Offset minim ca handle-ul să nu suprapună eticheta */
.pz-kpi .pz-kpi-head { padding-left: 18px; }
.pz-card .pz-card-head { padding-left: 18px; }

/* Stări vizuale Sortable */
.pz-drag-ghost {
    opacity: 0.35;
    background: var(--pz-bls) !important;
    border-color: var(--pz-blb) !important;
}
.pz-drag-chosen {
    cursor: grabbing;
}
.pz-drag-active {
    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.18);
    transform: scale(1.01);
    transition: transform .12s;
    z-index: 10;
}
.pz-kpi-grid.is-sorting,
.pz-big-grid.is-sorting { background: linear-gradient(transparent, transparent); }
.pz-appt-empty {
    text-align: center; padding: 20px 12px;
    font-size: 12px; color: var(--pz-fa);
}

/* Top clienți */
.pz-clients-list { display: flex; flex-direction: column; gap: 10px; }
.pz-client-row { display: flex; align-items: center; gap: 10px; }
.pz-client-avatar {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 10.5px; font-weight: 500; flex-shrink: 0;
    background: var(--pz-bls); color: var(--pz-bld);
}
.pz-client-row:nth-child(2) .pz-client-avatar { background: var(--pz-grs); color: var(--pz-gr); }
.pz-client-row:nth-child(3) .pz-client-avatar { background: var(--pz-ors); color: var(--pz-or); }
.pz-client-row:nth-child(4) .pz-client-avatar { background: #FEF3C7; color: #92400E; }
.pz-client-row:nth-child(5) .pz-client-avatar { background: #F3E8FF; color: #6D28D9; }
.pz-client-body { flex: 1; min-width: 0; }
.pz-client-name { font-size: 12.5px; font-weight: 500; color: var(--pz-title); margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pz-client-bar { height: 3px; background: var(--pz-soft); border-radius: 2px; margin-top: 4px; overflow: hidden; }
.pz-client-bar > span { display: block; height: 100%; background: var(--pz-bl); }
.pz-client-row:nth-child(2) .pz-client-bar > span { background: var(--pz-gr); }
.pz-client-row:nth-child(3) .pz-client-bar > span { background: var(--pz-or); }
.pz-client-row:nth-child(4) .pz-client-bar > span { background: #D97706; }
.pz-client-row:nth-child(5) .pz-client-bar > span { background: #7C3AED; }
.pz-client-amount { font-size: 11.5px; font-weight: 500; color: var(--pz-title); flex-shrink: 0; font-variant-numeric: tabular-nums; }
.pz-client-amount .unit { font-size: 10px; color: var(--pz-fa); font-weight: 400; margin-left: 2px; }
.pz-empty {
    text-align: center; padding: 28px 12px;
    font-size: 12px; color: var(--pz-fa);
}

/* ============================================================
   Responsive — adaptare pe orice device
   Breakpoints:
   - 1100px: tablet landscape (4 KPI rămân, charts split menținut)
   - 900px:  tablet portrait (KPI 2 coloane, row-2 stacked)
   - 640px:  phone landscape (compactare valori)
   - 480px:  phone portrait (1 coloană totală)
   - 360px:  ultra-narrow (font + padding mai mic)
   ============================================================ */

@media (max-width: 1100px) {
    .pz-row-2 { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 900px) {
    .pz-dash { padding: 14px 14px; gap: 12px; }
    .pz-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .pz-row-2-bottom { grid-template-columns: minmax(0, 1fr); }
    .pz-big-grid { grid-template-columns: minmax(0, 1fr); }
    .pz-status-split { grid-template-columns: minmax(0, 1fr); gap: 14px; }
    .pz-status-donut { max-width: 200px; margin: 0 auto; }
    .pz-head { gap: 10px; }
    .pz-head-actions { width: 100%; }
    .pz-period { width: 100%; }
    .pz-period a { flex: 1; text-align: center; }
    .pz-chart-wrap { height: 200px; }
}

@media (max-width: 640px) {
    .pz-dash { padding: 12px 10px; gap: 10px; }
    .pz-head .pz-title { font-size: 19px; }
    .pz-head .pz-date { font-size: 11px; }
    .pz-kpi { padding: 11px 12px; }
    .pz-kpi .pz-kpi-value { font-size: 19px; }
    .pz-kpi .pz-kpi-value .unit { font-size: 11px; }
    .pz-kpi .pz-kpi-label { font-size: 11px; }
    .pz-kpi .pz-kpi-badge { font-size: 10px; padding: 1px 6px; }
    .pz-card { padding: 12px 13px; }
    .pz-card-head .pz-card-title { font-size: 13px; }
    .pz-legend { gap: 10px; font-size: 10.5px; }
    .pz-chart-wrap { height: 180px; }
    .pz-chart-wrap.donut { height: 140px; }
    .pz-period a { padding: 5px 8px; font-size: 11px; }
    .pz-appt-time { width: 34px; font-size: 11px; }
    .pz-appt-info .name { font-size: 12px; }
    .pz-appt-info .tech { font-size: 10px; }
    .pz-client-name { font-size: 12px; }
    .pz-client-amount { font-size: 11px; }
}

@media (max-width: 480px) {
    .pz-kpi-grid { grid-template-columns: minmax(0, 1fr); gap: 8px; }
    .pz-kpi { display: flex; flex-direction: column; }
    .pz-kpi .pz-kpi-value { font-size: 22px; }
    .pz-period {
        padding: 2px;
        border-radius: 6px;
    }
    .pz-period a {
        padding: 5px 6px;
        font-size: 10.5px;
        min-width: 0;
    }
    /* Ascunde subiconul/badge-ul „urgent" text dar pastreaza culoarea */
    .pz-kpi .pz-kpi-badge {
        gap: 2px;
    }
    /* Top clients - ascunde bar-ul de progres, lasa doar nume + suma */
    .pz-client-bar { display: none; }
    .pz-client-row { gap: 8px; }
    .pz-client-avatar { width: 26px; height: 26px; }
    /* Programari - compactare */
    .pz-appt-row { gap: 8px; padding: 6px; }
    .pz-appt-status { font-size: 9.5px; padding: 1px 5px; }
}

@media (max-width: 360px) {
    .pz-dash { padding: 10px 8px; }
    .pz-kpi { padding: 10px 11px; }
    .pz-kpi .pz-kpi-value { font-size: 19px; }
    .pz-head .pz-title { font-size: 17px; }
    /* Pe ultra-narrow ascund data si reduc padding */
    .pz-head .pz-date { display: none; }
    .pz-chart-wrap { height: 160px; }
    .pz-chart-wrap.donut { height: 130px; }
}
</style>
<script>
(function() {
    /* Persistență perioadă financiar — citește din localStorage la load dacă URL e gol */
    try {
        var params = new URLSearchParams(window.location.search);
        if (!params.has("period_fin")) {
            var saved = localStorage.getItem("pz_dash_period_fin");
            if (saved && ["today","week","month","last_month","3months","6months","year"].indexOf(saved) >= 0) {
                params.set("period_fin", saved);
                window.location.replace("dashboard.php?" + params.toString());
                return;
            }
        }
    } catch (e) { /* fallback silent */ }
})();
</script>
</head>
<body>
<div class="layout">
    <?php render_sidebar('dashboard', $isAdmin); ?>
    <main class="main">
        <div class="content pz-dash">

            <!-- Welcome / period header -->
            <div class="pz-head">
                <div>
                    <p class="pz-greet">Bună, <?= dash_h($userName) ?></p>
                    <p class="pz-kicker">OPERAȚIONAL</p>
                    <h1 class="pz-title">Dashboard</h1>
                    <p class="pz-date"><?= dash_h($todayLabel) ?></p>
                </div>
                <div class="pz-head-actions">
                    <div class="pz-period" role="group" aria-label="Perioadă">
                        <?php foreach (dash_period_options() as $key => $label): ?>
                            <a href="<?= dash_h(dash_period_url('period_fin', $key)) ?>" data-period-key="period_fin" data-period-value="<?= dash_h($key) ?>"
                               class="<?= $periodFin === $key ? 'current' : '' ?>"
                               title="<?= dash_h($label) ?>">
                               <?= dash_h($label) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 4 KPI cards (drag & drop între ele) -->
            <?php
                // Pre-calcule comune
                $kpiIssuedTotal = max(1, (int)$finIssuedCount);
                $kpiPaidPct     = $finIssued > 0 ? min(100, round(($finPaid / $finIssued) * 100)) : 0;
                $kpiRestPctSum  = $finIssued > 0 ? min(100, round(($restanteAmount / $finIssued) * 100)) : 0;
                $kpiPendingPct  = max(0, 100 - $kpiPaidPct - $kpiRestPctSum);

                $todayCount = $todayCountTotal;
                $todayDoneCount = $todayDoneCountTotal;
                $todayPct = $todayCount > 0 ? round(($todayDoneCount / $todayCount) * 100) : 0;

                $kpiCards = [];

                // ---- KPI 1: Venituri perioada
                ob_start(); ?>
                <div class="pz-kpi bl" data-card-id="kpi-revenue">
                    <span class="pz-card-grip" aria-label="Mută card" title="Trage pentru a repoziționa"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>
                    <div class="pz-kpi-head">
                        <span class="pz-kpi-label">Venituri <?= dash_h(strtolower($finLabel)) ?></span>
                        <?php if ($finDelta !== null): ?>
                            <?php $deltaCls = $finDelta > 0 ? 'up' : ($finDelta < 0 ? 'down' : 'flat'); ?>
                            <?php $deltaIco = $finDelta > 0 ? 'ti-trending-up' : ($finDelta < 0 ? 'ti-trending-down' : 'ti-minus'); ?>
                            <span class="pz-kpi-badge <?= $deltaCls ?>">
                                <i class="ti <?= $deltaIco ?>" aria-hidden="true"></i>
                                <?= $finDelta > 0 ? '+' : '' ?><?= (int)$finDelta ?>%
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="pz-kpi-value"><?= dash_money($finIssued) ?><span class="unit">lei</span></div>

                    <?php if ($revenueSpark !== null): ?>
                        <svg class="pz-spark" viewBox="0 0 200 38" preserveAspectRatio="none"
                             role="img" aria-label="Trend venituri zilnice <?= dash_h($finLabel) ?>">
                            <defs>
                                <linearGradient id="pzSparkRevGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="var(--pz-bl)" stop-opacity="0.18"/>
                                    <stop offset="100%" stop-color="var(--pz-bl)" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <path d="<?= dash_h($revenueSpark['area']) ?>" fill="url(#pzSparkRevGrad)"/>
                            <path d="<?= dash_h($revenueSpark['line']) ?>" fill="none" stroke="var(--pz-bl)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="<?= $revenueSpark['last'][0] ?>" cy="<?= $revenueSpark['last'][1] ?>" r="2.2" fill="var(--pz-bl)"/>
                        </svg>
                    <?php endif; ?>

                    <div class="pz-kpi-foot">
                        <?php if ($finPrevIssued > 0): ?>
                            <?= dash_money($finPrevIssued) ?> lei perioada anterioară
                        <?php else: ?>
                            <?= (int)$finIssuedCount ?> facturi emise
                        <?php endif; ?>
                    </div>
                </div>
                <?php $kpiCards['kpi-revenue'] = ob_get_clean();

                // ---- KPI 2: Facturi emise
                ob_start(); ?>
                <div class="pz-kpi gr" data-card-id="kpi-invoices">
                    <span class="pz-card-grip" aria-label="Mută card" title="Trage pentru a repoziționa"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>
                    <div class="pz-kpi-head">
                        <span class="pz-kpi-label">Facturi emise</span>
                        <span class="pz-kpi-badge info">
                            <i class="ti ti-file-invoice" aria-hidden="true"></i><?= (int)$finIssuedCount ?>
                        </span>
                    </div>
                    <div class="pz-kpi-value">
                        <?= $kpiPaidPct ?><span class="unit">% încasate</span>
                    </div>
                    <div class="pz-kpi-bar" aria-label="Distribuție facturi">
                        <span style="width: <?= $kpiPaidPct ?>%; background: var(--pz-gr);"></span>
                        <span style="width: <?= $kpiPendingPct ?>%; background: var(--pz-or);"></span>
                        <span style="width: <?= $kpiRestPctSum ?>%; background: var(--pz-re);"></span>
                    </div>
                </div>
                <?php $kpiCards['kpi-invoices'] = ob_get_clean();

                // ---- KPI 3: Programări azi
                ob_start(); ?>
                <div class="pz-kpi bl" data-card-id="kpi-today">
                    <span class="pz-card-grip" aria-label="Mută card" title="Trage pentru a repoziționa"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>
                    <div class="pz-kpi-head">
                        <span class="pz-kpi-label">Programări azi</span>
                        <span class="pz-kpi-badge info">
                            <i class="ti ti-calendar" aria-hidden="true"></i><?= $todayPct ?>%
                        </span>
                    </div>
                    <div class="pz-kpi-value">
                        <?= $todayDoneCount ?><span class="unit">/ <?= $todayCount ?> finalizate</span>
                    </div>
                    <div class="pz-kpi-bar" aria-label="Progres programări">
                        <span style="width: <?= $todayPct ?>%; background: var(--pz-bl);"></span>
                    </div>
                </div>
                <?php $kpiCards['kpi-today'] = ob_get_clean();

                // ---- KPI 4: De facturat
                ob_start(); ?>
                <div class="pz-kpi or" data-card-id="kpi-due">
                    <span class="pz-card-grip" aria-label="Mută card" title="Trage pentru a repoziționa"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>
                    <div class="pz-kpi-head">
                        <span class="pz-kpi-label">De facturat</span>
                        <?php if ($finDueCount > 0): ?>
                            <span class="pz-kpi-badge warn">
                                <i class="ti ti-alert-circle" aria-hidden="true"></i>urgent
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="pz-kpi-value"><?= (int)$finDueCount ?><span class="unit">poziții</span></div>
                    <div class="pz-kpi-foot"><?= dash_money($finDueAmount) ?> lei în așteptare</div>
                </div>
                <?php $kpiCards['kpi-due'] = ob_get_clean();
            ?>
            <div class="pz-kpi-grid" data-pz-sortable="kpis">
                <?php foreach ($dashKpiOrder as $kpiId): ?>
                    <?= $kpiCards[$kpiId] ?? '' ?>
                <?php endforeach; ?>
            </div>

            <!-- 6 carduri mari — un singur grid drag-able (cross-row) -->
            <?php
                $stPaid    = (int)round(($finIssued > 0 ? ($finPaid / $finIssued) * $finIssuedCount : 0));
                $stRestNum = (int)$restanteCount;
                $stPending = max(0, (int)$finIssuedCount - $stPaid - $stRestNum);

                $bigCards = [];

                // ---- Card: Chart venituri/încasări 6 luni
                ob_start(); ?>
                <div class="pz-card" data-card-id="card-revchart">
                    <span class="pz-card-grip" aria-label="Mută card" title="Trage pentru a repoziționa"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>
                    <div class="pz-card-head">
                        <div>
                            <p class="pz-card-title-sm">Venituri și încasări</p>
                            <p class="pz-card-title"><?= dash_h($finLabel) ?></p>
                        </div>
                        <div class="pz-legend">
                            <span><span class="dot" style="background: var(--pz-gr);"></span>Venituri</span>
                            <span><span class="dot" style="background: var(--pz-or);"></span>Încasări</span>
                        </div>
                    </div>
                    <div class="pz-chart-wrap">
                        <canvas id="pzRevenueChart" role="img" aria-label="Trend venituri și încasări"></canvas>
                    </div>
                </div>
                <?php $bigCards['card-revchart'] = ob_get_clean();

                // ---- Card: Donut status facturi + bare orizontale pe sume
                $amtPaid    = (float)$finPaid;
                $amtOverdue = (float)$restanteAmount;
                $amtPending = max(0.0, (float)$finIssued - $amtPaid - $amtOverdue);
                $amtTotal   = max(0.0001, (float)$finIssued); // pentru % (evităm /0)
                $pctPaid    = round(($amtPaid    / $amtTotal) * 100);
                $pctPending = round(($amtPending / $amtTotal) * 100);
                $pctOverdue = round(($amtOverdue / $amtTotal) * 100);

                ob_start(); ?>
                <div class="pz-card" data-card-id="card-statusdonut">
                    <span class="pz-card-grip" aria-label="Mută card" title="Trage pentru a repoziționa"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>
                    <div class="pz-card-head" style="margin-bottom: 6px;">
                        <div>
                            <p class="pz-card-title-sm">Status facturi</p>
                            <p class="pz-card-title"><?= dash_h($finLabel) ?></p>
                        </div>
                    </div>

                    <div class="pz-status-split">
                        <div class="pz-status-donut">
                            <div class="pz-chart-wrap donut">
                                <canvas id="pzStatusChart" role="img" aria-label="Distribuție status facturi pe număr"></canvas>
                            </div>
                            <div class="pz-status-donut-total">
                                <span class="num"><?= (int)$finIssuedCount ?></span>
                                <span class="lbl">facturi</span>
                            </div>
                        </div>

                        <div class="pz-amount-bars">
                            <p class="pz-amount-bars-title">Distribuție pe sume</p>

                            <div class="row">
                                <div class="head">
                                    <span class="label"><span class="dot" style="background: var(--pz-gr);"></span>Încasate</span>
                                    <span class="value"><?= dash_money($amtPaid) ?> lei · <?= (int)$pctPaid ?>%</span>
                                </div>
                                <div class="bar"><span style="width: <?= (int)$pctPaid ?>%; background: var(--pz-gr);"></span></div>
                            </div>

                            <div class="row">
                                <div class="head">
                                    <span class="label"><span class="dot" style="background: var(--pz-or);"></span>În termen</span>
                                    <span class="value"><?= dash_money($amtPending) ?> lei · <?= (int)$pctPending ?>%</span>
                                </div>
                                <div class="bar"><span style="width: <?= (int)$pctPending ?>%; background: var(--pz-or);"></span></div>
                            </div>

                            <div class="row">
                                <div class="head">
                                    <span class="label"><span class="dot" style="background: var(--pz-re);"></span>Restante</span>
                                    <span class="value"><?= dash_money($amtOverdue) ?> lei · <?= (int)$pctOverdue ?>%</span>
                                </div>
                                <div class="bar"><span style="width: <?= (int)$pctOverdue ?>%; background: var(--pz-re);"></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="pz-donut-legend pz-status-counts">
                        <div class="row"><span class="label">Încasate</span><span class="value"><?= $stPaid ?></span></div>
                        <div class="row"><span class="label">În termen</span><span class="value"><?= $stPending ?></span></div>
                        <div class="row"><span class="label">Restante</span><span class="value"><?= $stRestNum ?></span></div>
                    </div>
                </div>
                <?php $bigCards['card-statusdonut'] = ob_get_clean();

                // ---- Card: Programări azi
                ob_start(); ?>
                <div class="pz-card" data-card-id="card-todayappts">
                    <span class="pz-card-grip" aria-label="Mută card" title="Trage pentru a repoziționa"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>
                    <div class="pz-card-head">
                        <div>
                            <p class="pz-card-title-sm">Programări astăzi</p>
                            <p class="pz-card-title"><?= $todayDoneCount ?> finalizate · <?= max(0, $todayCount - $todayDoneCount) ?> rămase</p>
                        </div>
                        <a href="calendar.php" class="pz-card-link">Calendar<i class="ti ti-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    <div class="pz-appt-list">
                        <?php if (!empty($todayAppointments)): ?>
                            <?php foreach (array_slice($todayAppointments, 0, 5) as $apt):
                                $status = (string)($apt['status'] ?? '');
                                $statusCls = 'pending';
                                $statusLbl = '·';
                                if ($status === 'finalizata') { $statusCls = 'done'; $statusLbl = 'gata'; }
                                elseif ($status === 'in_lucru' || $status === 'inceput') { $statusCls = 'in-progress'; $statusLbl = 'curs'; }
                                $rowCls = ($statusCls === 'in-progress') ? ' active' : '';
                                $clientName = trim((string)($apt['client_name'] ?? ''));
                                if ($clientName === '') { $clientName = 'Programare'; }
                                $teamName = trim((string)($apt['team_member_name'] ?? ''));
                            ?>
                                <div class="pz-appt-row<?= $rowCls ?>">
                                    <div class="pz-appt-time"><?= dash_h(dash_time($apt['start_time'] ?? null)) ?></div>
                                    <div class="pz-appt-info">
                                        <p class="name"><?= dash_h($clientName) ?></p>
                                        <?php if ($teamName !== ''): ?>
                                            <p class="tech"><?= dash_h($teamName) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <span class="pz-appt-status <?= $statusCls ?>"><?= dash_h($statusLbl) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="pz-appt-empty">Nu există programări astăzi.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $bigCards['card-todayappts'] = ob_get_clean();

                // ---- Card: Top clienți
                ob_start(); ?>
                <div class="pz-card" data-card-id="card-topclients">
                    <span class="pz-card-grip" aria-label="Mută card" title="Trage pentru a repoziționa"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>
                    <div class="pz-card-head">
                        <div>
                            <p class="pz-card-title-sm">Top clienți</p>
                            <p class="pz-card-title"><?= dash_h($finLabel) ?></p>
                        </div>
                        <a href="clients.php" class="pz-card-link">Toți<i class="ti ti-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    <div class="pz-clients-list">
                        <?php if (!empty($topClients)): ?>
                            <?php foreach ($topClients as $idx => $client):
                                $name = trim((string)$client['name']);
                                $amount = (float)$client['amount'];
                                $pct = $topClientMax > 0 ? min(100, round(($amount / $topClientMax) * 100)) : 0;
                                $initials = dash_initials($name);
                            ?>
                                <div class="pz-client-row">
                                    <div class="pz-client-avatar"><?= dash_h($initials) ?></div>
                                    <div class="pz-client-body">
                                        <p class="pz-client-name"><?= dash_h($name) ?></p>
                                        <div class="pz-client-bar"><span style="width: <?= $pct ?>%;"></span></div>
                                    </div>
                                    <div class="pz-client-amount"><?= dash_money($amount) ?><span class="unit">lei</span></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="pz-empty">Nu există facturi emise în perioada selectată.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $bigCards['card-topclients'] = ob_get_clean();

                // ---- Card: Sarcini active
                ob_start(); ?>
                <div class="pz-card" data-card-id="card-tasks">
                    <span class="pz-card-grip" aria-label="Mută card" title="Trage pentru a repoziționa"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>
                    <div class="pz-card-head">
                        <div>
                            <p class="pz-card-title-sm">Sarcini active</p>
                            <p class="pz-card-title">
                                <?= (int)$tasksOverdue ?> restante · <?= (int)$tasksTodayCount ?> azi · <?= (int)$tasksInPeriod ?> în <?= dash_h(strtolower($finLabel)) ?>
                            </p>
                        </div>
                        <a href="tasks.php" class="pz-card-link">Toate<i class="ti ti-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    <div class="pz-appt-list">
                        <?php if (!empty($tasksDash)): ?>
                            <?php foreach ($tasksDash as $task):
                                $due = (string)($task['due_date'] ?? '');
                                $today = date('Y-m-d');
                                if ($due === '') {
                                    $statusCls = 'pending'; $statusLbl = '·'; $rowCls = ''; $dateBox = '--';
                                } elseif ($due < $today) {
                                    $statusCls = 'overdue'; $statusLbl = 'restant'; $rowCls = ' is-overdue';
                                    $dateBox = date('d.m', strtotime($due));
                                } elseif ($due === $today) {
                                    $statusCls = 'today'; $statusLbl = 'azi'; $rowCls = ' is-today';
                                    $dateBox = 'azi';
                                } else {
                                    $statusCls = 'future'; $statusLbl = 'viitor'; $rowCls = '';
                                    $dateBox = date('d.m', strtotime($due));
                                }
                                $taskName = trim((string)($task['client_name'] ?? '')) ?: 'Sarcină';
                                $svc      = trim((string)($task['service_type'] ?? ''));
                            ?>
                                <a href="tasks.php#task-<?= (int)$task['id'] ?>" class="pz-appt-row<?= $rowCls ?>">
                                    <div class="pz-appt-time"><?= dash_h($dateBox) ?></div>
                                    <div class="pz-appt-info">
                                        <p class="name"><?= dash_h($taskName) ?></p>
                                        <?php if ($svc !== ''): ?>
                                            <p class="tech"><?= dash_h($svc) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <span class="pz-appt-status <?= $statusCls ?>"><?= dash_h($statusLbl) ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="pz-appt-empty">Nu există sarcini active în perioada selectată.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $bigCards['card-tasks'] = ob_get_clean();

                // ---- Card: Reminders
                ob_start(); ?>
                <div class="pz-card" data-card-id="card-reminders">
                    <span class="pz-card-grip" aria-label="Mută card" title="Trage pentru a repoziționa"><i class="ti ti-grip-vertical" aria-hidden="true"></i></span>
                    <div class="pz-card-head">
                        <div>
                            <p class="pz-card-title-sm">Reminders</p>
                            <p class="pz-card-title">
                                <?= (int)$remindersOverdue ?> expirate · <?= (int)$remindersTodayCount ?> azi · <?= (int)$remindersInPeriod ?> în <?= dash_h(strtolower($finLabel)) ?>
                            </p>
                        </div>
                        <a href="reminders.php" class="pz-card-link">Toate<i class="ti ti-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    <div class="pz-appt-list">
                        <?php if (!empty($remindersDash)): ?>
                            <?php foreach ($remindersDash as $rem):
                                $rdate = (string)($rem['remind_date'] ?? '');
                                $rtime = (string)($rem['remind_time'] ?? '');
                                $today = date('Y-m-d');
                                if ($rdate === '') {
                                    $statusCls = 'pending'; $statusLbl = '·'; $rowCls = ''; $dateBox = '--';
                                } elseif ($rdate < $today) {
                                    $statusCls = 'overdue'; $statusLbl = 'expirat'; $rowCls = ' is-overdue';
                                    $dateBox = date('d.m', strtotime($rdate));
                                } elseif ($rdate === $today) {
                                    $statusCls = 'today'; $statusLbl = 'azi'; $rowCls = ' is-today';
                                    $dateBox = $rtime !== '' ? dash_time($rtime) : 'azi';
                                } else {
                                    $statusCls = 'future'; $statusLbl = 'viitor'; $rowCls = '';
                                    $dateBox = date('d.m', strtotime($rdate));
                                }
                                $rTitle = trim((string)($rem['title'] ?? '')) ?: 'Reminder';
                                $rCat   = strtolower(trim((string)($rem['category'] ?? 'other')));
                                $rIcon  = $remCategoryIcon[$rCat] ?? 'ti-bell';
                                $catLbl = $rCat !== '' && $rCat !== 'other' ? ucfirst($rCat) : 'Reminder';
                                $timeLbl = ($rtime !== '' && $rdate !== '' && $rdate !== $today) ? ' · ' . dash_time($rtime) : '';
                            ?>
                                <a href="reminders.php#rem-<?= (int)$rem['id'] ?>" class="pz-appt-row<?= $rowCls ?>">
                                    <div class="pz-appt-time"><?= dash_h($dateBox) ?></div>
                                    <div class="pz-appt-info">
                                        <p class="name"><?= dash_h($rTitle) ?></p>
                                        <p class="tech"><i class="ti <?= dash_h($rIcon) ?>" aria-hidden="true" style="font-size: 11px; vertical-align: -1px;"></i> <?= dash_h($catLbl . $timeLbl) ?></p>
                                    </div>
                                    <span class="pz-appt-status <?= $statusCls ?>"><?= dash_h($statusLbl) ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="pz-appt-empty">Nu există reminders pending în perioada selectată.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $bigCards['card-reminders'] = ob_get_clean();
            ?>

            <div class="pz-big-grid" data-pz-sortable="big-cards">
                <?php foreach ($dashBigOrder as $bigId): ?>
                    <?= $bigCards[$bigId] ?? '' ?>
                <?php endforeach; ?>
            </div>

        </div>
    </main>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;

    var REV_DATA = <?= json_encode($revTrend ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var labels  = REV_DATA.map(function (r) { return r.label; });
    var issued  = REV_DATA.map(function (r) { return Math.round(Number(r.issued)  || 0); });
    var paid    = REV_DATA.map(function (r) { return Math.round(Number(r.paid)    || 0); });

    var canvasRev = document.getElementById('pzRevenueChart');
    if (canvasRev && REV_DATA.length > 0) {
        new Chart(canvasRev, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Venituri',
                        data: issued,
                        borderColor: '#16A34A',
                        backgroundColor: 'rgba(22, 163, 74, 0.12)',
                        tension: 0.35, fill: true, borderWidth: 2,
                        pointRadius: 3, pointHoverRadius: 5,
                        pointBackgroundColor: '#16A34A'
                    },
                    {
                        label: 'Încasări',
                        data: paid,
                        borderColor: '#FF7A3D',
                        backgroundColor: 'rgba(255, 122, 61, 0.12)',
                        tension: 0.35, fill: true, borderWidth: 2,
                        borderDash: [5, 3],
                        pointRadius: 3, pointHoverRadius: 5,
                        pointBackgroundColor: '#FF7A3D'
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString('ro-RO') + ' lei';
                        }
                    }
                }},
                scales: {
                    y: {
                        grid: { color: 'rgba(0,0,0,0.06)', drawBorder: false },
                        ticks: {
                            color: '#3E4C8F', font: { size: 10 },
                            callback: function (v) { return Math.round(v/1000) + 'k'; }
                        }
                    },
                    x: { grid: { display: false }, ticks: { color: '#3E4C8F', font: { size: 10 } } }
                }
            }
        });
    }

    var statusData = {
        paid:    <?= (int)$stPaid ?>,
        pending: <?= (int)$stPending ?>,
        restant: <?= (int)$stRestNum ?>
    };

    var canvasSt = document.getElementById('pzStatusChart');
    if (canvasSt) {
        var totalSt = statusData.paid + statusData.pending + statusData.restant;
        if (totalSt > 0) {
            new Chart(canvasSt, {
                type: 'doughnut',
                data: {
                    labels: ['Încasate', 'În termen', 'Restante'],
                    datasets: [{
                        data: [statusData.paid, statusData.pending, statusData.restant],
                        backgroundColor: ['#061142', '#FF7A3D', '#DC2626'],
                        borderWidth: 0, spacing: 2
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: { legend: { display: false }, tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var pct = totalSt > 0 ? Math.round((ctx.parsed / totalSt) * 100) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }}
                }
            });
        } else {
            // Fără date — desenez un cerc gri ca placeholder
            var ctx = canvasSt.getContext('2d');
            ctx.clearRect(0, 0, canvasSt.width, canvasSt.height);
        }
    }
})();
</script>

<!-- Drag & drop carduri dashboard -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function () {
    if (typeof Sortable === 'undefined') return;

    var SAVE_URL = 'dashboard_layout_save.php';
    var saveTimer = null;
    var lastSent = null;

    function collectOrder(container) {
        return Array.prototype.slice
            .call(container.querySelectorAll(':scope > [data-card-id]'))
            .map(function (el) { return el.getAttribute('data-card-id'); });
    }

    function snapshot() {
        var kpiGrid = document.querySelector('[data-pz-sortable="kpis"]');
        var bigGrid = document.querySelector('[data-pz-sortable="big-cards"]');
        return {
            kpis:     kpiGrid ? collectOrder(kpiGrid) : [],
            bigCards: bigGrid ? collectOrder(bigGrid) : []
        };
    }

    function saveLayout() {
        var payload = snapshot();
        var serialized = JSON.stringify(payload);
        if (serialized === lastSent) return;
        lastSent = serialized;

        if (typeof fetch !== 'function') return;

        fetch(SAVE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: serialized
        }).then(function (r) {
            if (!r.ok) console.warn('[dashboard layout] save failed:', r.status);
        }).catch(function (e) {
            console.warn('[dashboard layout] save error:', e);
        });
    }

    function scheduleSave() {
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(saveLayout, 200);
    }

    function initSortable(selector, opts) {
        var el = document.querySelector(selector);
        if (!el) return;
        Sortable.create(el, Object.assign({
            handle: '.pz-card-grip',
            animation: 160,
            ghostClass: 'pz-drag-ghost',
            chosenClass: 'pz-drag-chosen',
            dragClass:   'pz-drag-active',
            forceFallback: false,
            onStart: function () { el.classList.add('is-sorting'); },
            onEnd:   function () { el.classList.remove('is-sorting'); scheduleSave(); }
        }, opts || {}));
    }

    function init() {
        // Memorez snapshotul inițial ca să nu trimit save inutil pe load.
        lastSent = JSON.stringify(snapshot());

        initSortable('[data-pz-sortable="kpis"]', { group: 'pz-kpis' });
        initSortable('[data-pz-sortable="big-cards"]', { group: 'pz-big-cards' });

        // Previn navigarea când utilizatorul apasă pe grip-ul unui link/anchor
        document.addEventListener('click', function (e) {
            var grip = e.target.closest && e.target.closest('.pz-card-grip');
            if (grip) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
<script>
/* Persistență perioadă financiar — salvează în localStorage la click pe selector */
(function() {
    document.querySelectorAll("a[data-period-key]").forEach(function(a) {
        a.addEventListener("click", function() {
            try {
                var key = a.getAttribute("data-period-key");
                var val = a.getAttribute("data-period-value");
                if (key && val) localStorage.setItem("pz_dash_" + key, val);
            } catch (e) { /* silent */ }
        });
    });
})();
</script>
</body>
</html>
