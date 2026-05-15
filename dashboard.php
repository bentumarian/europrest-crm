<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();
$isTeamUser = function_exists('is_team_user') ? is_team_user() : false;

// Pentru echipe, dashboard-ul ramane calendarul lor
if (!$isAdmin) {
    header("Location: calendar.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function dash_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dash_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) > 0;
}

function dash_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) > 0;
}

function dash_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!dash_column_exists($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            error_log('dashboard add column error: ' . $e->getMessage());
        }
    }
}

function dash_status_label(string $status): string {
    return [
        'neconfirmata' => 'Neconfirmata', 'confirmata' => 'Confirmata',
        'in_lucru' => 'In lucru', 'finalizata' => 'Finalizata', 'anulata' => 'Anulata',
        'de_programat' => 'De programat', 'contactat' => 'Contactat',
        'amanat' => 'Amanat', 'programat' => 'Programat',
    ][$status] ?? $status;
}

function dash_status_tone(string $status): string {
    return [
        'confirmata' => 'info', 'in_lucru' => 'info-strong',
        'finalizata' => 'success', 'anulata' => 'neutral',
        'neconfirmata' => 'warning', 'de_programat' => 'warning',
        'contactat' => 'info', 'amanat' => 'warning', 'programat' => 'info',
    ][$status] ?? 'neutral';
}

function dash_time(?string $time): string {
    return $time ? substr((string)$time, 0, 5) : '--:--';
}

function dash_format_money(float $amount): string {
    return number_format($amount, 0, ',', '.');
}

function dash_days_diff(string $from, string $to): int {
    $a = strtotime($from); $b = strtotime($to);
    return ($a === false || $b === false) ? 0 : (int)floor(($b - $a) / 86400);
}

/*
|--------------------------------------------------------------------------
| Date calendaristice
|--------------------------------------------------------------------------
*/
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd   = date('Y-m-t', strtotime('last day of last month'));

// Saptamana curenta = luni → duminica
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
// Saptamana trecuta
$lastWeekStart = date('Y-m-d', strtotime('monday last week'));
$lastWeekEnd   = date('Y-m-d', strtotime('sunday last week'));

/*
|--------------------------------------------------------------------------
| Helper: numara zile lucratoare luni-sambata intre 2 date (inclusiv)
|--------------------------------------------------------------------------
*/
function dash_working_days(string $start, string $end): int {
    $a = strtotime($start);
    $b = strtotime($end);
    if ($a === false || $b === false || $a > $b) return 0;
    // Cap la azi - nu numaram zile viitoare ca "perioada efectiva"
    $today = strtotime(date('Y-m-d'));
    $b = min($b, $today);
    $count = 0;
    for ($t = $a; $t <= $b; $t += 86400) {
        $dow = (int)date('N', $t); // 1=Mon, 7=Sun
        if ($dow >= 1 && $dow <= 6) $count++; // luni-sambata
    }
    return $count;
}

/*
|--------------------------------------------------------------------------
| ZONA 1: ECHIPE - capacitate pe ore (8h = 100%)
|--------------------------------------------------------------------------
*/
$teamCapacityHours = 8.0;
$teamStats = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            tm.id, tm.name, tm.color,
            COUNT(a.id) AS jobs_total,
            SUM(CASE WHEN a.status = 'finalizata' THEN 1 ELSE 0 END) AS jobs_done,
            SUM(CASE WHEN a.status = 'in_lucru'   THEN 1 ELSE 0 END) AS jobs_active,
            COALESCE(SUM(
                CASE WHEN a.start_time IS NOT NULL AND a.end_time IS NOT NULL
                     THEN GREATEST(0, TIME_TO_SEC(a.end_time) - TIME_TO_SEC(a.start_time)) / 3600
                     ELSE 0 END
            ), 0) AS hours_booked,
            MIN(CASE WHEN a.status NOT IN ('finalizata','anulata') THEN a.start_time END) AS next_start
        FROM team_members tm
        LEFT JOIN appointments a
            ON a.team_member_id = tm.id
           AND a.appointment_date = ?
           AND a.status != 'anulata'
        WHERE tm.active = 1
        GROUP BY tm.id, tm.name, tm.color
        ORDER BY hours_booked DESC, tm.name ASC
    ");
    $stmt->execute([$today]);
    $teamStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('dashboard team stats error: ' . $e->getMessage());
}

$teamsTotal = count($teamStats);
$teamsFree = 0; $teamsBusy = 0;
$todayBookedHours = 0.0;
foreach ($teamStats as $t) {
    $todayBookedHours += (float)($t['hours_booked'] ?? 0);
    if ((float)$t['hours_booked'] <= 0.01) $teamsFree++;
    else $teamsBusy++;
}

/*
|--------------------------------------------------------------------------
| ZONA 1.5: EFICIENTA echipe (saptamana + luna, cu trend)
|--------------------------------------------------------------------------
| Capacitate FIXA per echipa:
|   - Saptamana: 40 ore (inclusiv weekend daca lucreaza)
|   - Luna:     160 ore
| Eficienta = ore_lucrate / capacitate_fixa
| Nota (1-10) = procent / 10, capped la 10
*/
function dash_team_efficiency(PDO $pdo, string $start, string $end, float $capacityPerTeam): array {
    if ($capacityPerTeam <= 0) $capacityPerTeam = 40.0; // fallback

    try {
        $stmt = $pdo->prepare("
            SELECT
                tm.id, tm.name, tm.color,
                COALESCE(SUM(
                    CASE WHEN a.start_time IS NOT NULL AND a.end_time IS NOT NULL
                         THEN GREATEST(0, TIME_TO_SEC(a.end_time) - TIME_TO_SEC(a.start_time)) / 3600
                         ELSE 0 END
                ), 0) AS hours_worked,
                COUNT(a.id) AS jobs_total
            FROM team_members tm
            LEFT JOIN appointments a
                ON a.team_member_id = tm.id
               AND a.appointment_date BETWEEN ? AND ?
               AND a.status != 'anulata'
            WHERE tm.active = 1
            GROUP BY tm.id, tm.name, tm.color
            ORDER BY hours_worked DESC, tm.name ASC
        ");
        $stmt->execute([$start, $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('dashboard efficiency error: ' . $e->getMessage());
        $rows = [];
    }

    $teams = [];
    $totalHours = 0;
    foreach ($rows as $r) {
        $hours = (float)$r['hours_worked'];
        $percent = $capacityPerTeam > 0 ? min(150, ($hours / $capacityPerTeam) * 100) : 0;
        $grade = round($percent / 10, 1);
        if ($grade > 10) $grade = 10;
        $teams[] = [
            'id'         => (int)$r['id'],
            'name'       => $r['name'],
            'color'      => $r['color'],
            'hours'      => $hours,
            'capacity'   => $capacityPerTeam,
            'percent'    => $percent,
            'grade'      => $grade,
            'jobs_total' => (int)$r['jobs_total'],
        ];
        $totalHours += $hours;
    }

    $teamCount = max(1, count($teams));
    $avgHours = $totalHours / $teamCount;
    $avgPercent = $capacityPerTeam > 0 ? min(150, ($avgHours / $capacityPerTeam) * 100) : 0;
    $avgGrade = round($avgPercent / 10, 1);
    if ($avgGrade > 10) $avgGrade = 10;

    return [
        'teams'         => $teams,
        'capacity_team' => $capacityPerTeam,
        'total_hours'   => $totalHours,
        'avg_hours'     => $avgHours,
        'avg_percent'   => $avgPercent,
        'avg_grade'     => $avgGrade,
        'team_count'    => count($teams),
    ];
}

// Capacitati FIXE per echipa
$capacityWeek  = 40.0;   // 40 ore / saptamana
$capacityMonth = 160.0;  // 160 ore / luna

$effWeek      = dash_team_efficiency($pdo, $weekStart,     $weekEnd,     $capacityWeek);
$effMonth     = dash_team_efficiency($pdo, $monthStart,    $monthEnd,    $capacityMonth);
$effLastWeek  = dash_team_efficiency($pdo, $lastWeekStart, $lastWeekEnd, $capacityWeek);
$effLastMonth = dash_team_efficiency($pdo, $lastMonthStart, $lastMonthEnd, $capacityMonth);

// Trend = diferenta procentuala intre media curenta si cea precedenta
$weekTrend  = $effLastWeek['avg_percent']  > 0 ? round($effWeek['avg_percent']  - $effLastWeek['avg_percent'], 1)  : 0;
$monthTrend = $effLastMonth['avg_percent'] > 0 ? round($effMonth['avg_percent'] - $effLastMonth['avg_percent'], 1) : 0;

/*
|--------------------------------------------------------------------------
| ZONA 2: BACKLOG - sarcini de programat (intarziate + azi)
|--------------------------------------------------------------------------
*/
$tasksOverdueCount = 0;
$tasksTodayCount = 0;
$backlogList = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            tk.id, tk.client_id, tk.client_location_id, tk.service_type, tk.due_date,
            tk.contact_person AS task_contact_person, tk.contact_phone AS task_contact_phone,
            c.name AS client_name, c.phone AS client_phone
        FROM tasks tk
        LEFT JOIN clients c ON c.id = tk.client_id
        WHERE tk.due_date <= ?
          AND tk.status IN ('de_programat', 'contactat', 'amanat')
          AND tk.recurrence_stopped = 0
        ORDER BY tk.due_date ASC, tk.id ASC
        LIMIT 12
    ");
    $stmt->execute([$today]);
    $backlogList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('dashboard backlog error: ' . $e->getMessage());
}

try {
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE due_date < ? AND status IN ('de_programat','contactat','amanat') AND recurrence_stopped = 0");
    $cStmt->execute([$today]);
    $tasksOverdueCount = (int)$cStmt->fetchColumn();

    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE due_date = ? AND status IN ('de_programat','contactat','amanat') AND recurrence_stopped = 0");
    $cStmt->execute([$today]);
    $tasksTodayCount = (int)$cStmt->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

$backlogTotal = $tasksOverdueCount + $tasksTodayCount;

/*
|--------------------------------------------------------------------------
| ZONA 3: CHECKLIST FACTURARE INTERVENTII
|--------------------------------------------------------------------------
*/
$ibDue = 0;
$ibDueAmount = 0.0;
$ibBilledMonth = 0;
$ibBilledMonthAmount = 0.0;
$ibNoBillMonth = 0;
$ibNoBillMonthAmount = 0.0;
$ibOldestList = [];

try {
    if (dash_table_exists($pdo, 'appointments')) {
        dash_ensure_column($pdo, 'appointments', 'billing_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        dash_ensure_column($pdo, 'appointments', 'billing_status', "VARCHAR(30) NOT NULL DEFAULT 'de_facturat'");
        dash_ensure_column($pdo, 'appointments', 'billing_note', "TEXT NULL");
        dash_ensure_column($pdo, 'appointments', 'billing_updated_at', "DATETIME NULL");
        dash_ensure_column($pdo, 'appointments', 'billing_updated_by', "INT NULL");
        $pdo->exec("UPDATE appointments SET billing_status = 'de_facturat' WHERE billing_status IS NULL OR billing_status = '' OR billing_status NOT IN ('de_facturat','facturata','nu_se_factureaza')");

        $stmt = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(billing_amount), 0) AS amount_total FROM appointments WHERE status = 'finalizata' AND billing_status = 'de_facturat'");
        $ibDueRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $ibDue = (int)($ibDueRow['total'] ?? 0);
        $ibDueAmount = (float)($ibDueRow['amount_total'] ?? 0);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(billing_amount), 0) AS amount_total FROM appointments WHERE status = 'finalizata' AND billing_status = 'facturata' AND appointment_date BETWEEN ? AND ?");
        $stmt->execute([$monthStart, $monthEnd]);
        $ibBilledRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $ibBilledMonth = (int)($ibBilledRow['total'] ?? 0);
        $ibBilledMonthAmount = (float)($ibBilledRow['amount_total'] ?? 0);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(billing_amount), 0) AS amount_total FROM appointments WHERE status = 'finalizata' AND billing_status = 'nu_se_factureaza' AND appointment_date BETWEEN ? AND ?");
        $stmt->execute([$monthStart, $monthEnd]);
        $ibNoBillRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $ibNoBillMonth = (int)($ibNoBillRow['total'] ?? 0);
        $ibNoBillMonthAmount = (float)($ibNoBillRow['amount_total'] ?? 0);

        $stmt = $pdo->query("
            SELECT a.id, a.appointment_date, a.start_time, a.service_type, a.billing_amount, c.name AS client_name
            FROM appointments a
            LEFT JOIN clients c ON c.id = a.client_id
            WHERE a.status = 'finalizata'
              AND a.billing_status = 'de_facturat'
            ORDER BY a.appointment_date ASC, a.start_time ASC, a.id ASC
            LIMIT 5
        ");
        $ibOldestList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('dashboard interventii facturare error: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| ZONA 4: AGENDA ZILEI
|--------------------------------------------------------------------------
*/
$todayAppointments = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            a.id, a.appointment_date, a.start_time, a.end_time, a.service_type, a.status,
            c.name AS client_name,
            t.name AS team_name, t.color AS team_color
        FROM appointments a
        LEFT JOIN clients c ON c.id = a.client_id
        LEFT JOIN team_members t ON t.id = a.team_member_id
        WHERE a.appointment_date = ?
          AND a.status != 'anulata'
        ORDER BY a.start_time ASC, a.id ASC
        LIMIT 12
    ");
    $stmt->execute([$today]);
    $todayAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('dashboard today appointments error: ' . $e->getMessage());
}

$appointmentsToday = count($todayAppointments);

/*
|--------------------------------------------------------------------------
| Briefing inteligent (chip-uri sus)
|--------------------------------------------------------------------------
*/
$hour = (int)date('H');
$greeting = $hour < 11 ? 'Buna dimineata' : ($hour < 18 ? 'Buna ziua' : 'Buna seara');
$dashboardUserName = function_exists('current_user_name') ? current_user_name() : 'Utilizator';

$briefingChips = [];
if ($teamsFree > 0 && $teamsTotal > 0) {
    $briefingChips[] = [
        'tone' => $teamsFree === $teamsTotal ? 'danger' : 'warning',
        'icon' => 'team',
        'text' => $teamsFree . ' ' . ($teamsFree === 1 ? 'echipa libera' : 'echipe libere') . ' azi',
    ];
}
if ($backlogTotal > 0) {
    $briefingChips[] = [
        'tone' => $tasksOverdueCount > 0 ? 'danger' : 'warning',
        'icon' => 'tasks',
        'text' => $backlogTotal . ' de programat' . ($tasksOverdueCount > 0 ? ' (' . $tasksOverdueCount . ' intarziate)' : ''),
    ];
}
if ($ibDue > 0) {
    $briefingChips[] = [
        'tone' => 'warning',
        'icon' => 'invoice',
        'text' => $ibDue . ' interventii de facturat',
    ];
}
if (!$briefingChips) {
    $briefingChips[] = [
        'tone' => 'success',
        'icon' => 'check',
        'text' => 'Totul e sub control. Zi calma.',
    ];
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<?php app_theme_css(); ?>
<style>
/* === GREETING + BRIEFING PREMIUM === */
.dash-greeting {
    position: relative;
    overflow: hidden;
    margin-bottom: 22px;
    padding: 24px 28px 22px;
    border-radius: 22px;
    border: 1px solid rgba(177, 214, 240, .58);
    background:
        radial-gradient(circle at 92% 14%, rgba(177, 214, 240, .58), transparent 32%),
        linear-gradient(135deg, rgba(255,255,255,.88), rgba(223,226,232,.50));
    box-shadow: 0 22px 46px -30px rgba(0, 32, 80, .36), inset 0 1px 0 rgba(255,255,255,.78);
    backdrop-filter: blur(16px) saturate(1.25);
    -webkit-backdrop-filter: blur(16px) saturate(1.25);
}
.dash-greeting::before {
    content: "";
    position: absolute;
    inset: -30% -10% auto auto;
    width: 48%;
    height: 120%;
    transform: rotate(18deg);
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.40), transparent);
    pointer-events: none;
}
.dash-hero-top {
    position: relative;
    display: flex;
    justify-content: space-between;
    gap: 18px;
    align-items: flex-start;
}
.dash-hero-copy { min-width: 0; }
.dash-overline {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 6px;
    color: var(--accent);
    font-size: 11px;
    font-weight: 850;
    letter-spacing: .09em;
    text-transform: uppercase;
}
.dash-greeting h1 { margin: 0; font-size: 25px; font-weight: 650; letter-spacing: -.035em; color: var(--text); }
.dash-greeting h1 span { font-size: 25px; font-weight: 850; color: #002050; }
.dash-greeting .sub { margin-top: 9px; color: #39506f; font-size: 13.5px; font-weight: 650; line-height: 1.45; }
.dash-hero-status {
    min-width: 155px;
    padding: 9px 11px;
    border: 1px solid rgba(148,163,184,.32);
    border-radius: 14px;
    background: rgba(255,255,255,.75);
    color: var(--text);
    font-size: 12px;
    font-weight: 800;
    text-align: right;
    box-shadow: 0 1px 2px rgba(15,23,42,.04);
}
.dash-hero-status small {
    display: block;
    margin-top: 2px;
    color: var(--muted);
    font-size: 11px;
    font-weight: 650;
}
.dash-status-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    margin-right: 6px;
    background: var(--tone-success);
    box-shadow: 0 0 0 4px rgba(4,120,87,.12);
}
.dash-hero-kpis {
    position: relative;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 16px;
}
.dash-kpi-card {
    display: grid;
    grid-template-columns: 38px minmax(0, 1fr);
    gap: 10px;
    align-items: center;
    min-height: 82px;
    padding: 12px;
    border-radius: 16px;
    border: 1px solid rgba(148,163,184,.30);
    background: rgba(255,255,255,.82);
    text-decoration: none;
    color: inherit;
    transition: transform .14s ease, border-color .14s ease, box-shadow .14s ease;
}
.dash-kpi-card:hover {
    transform: translateY(-1px);
    border-color: var(--accent-soft-2);
    box-shadow: 0 10px 22px -18px rgba(15,23,42,.45);
}
.dash-kpi-icon {
    width: 38px;
    height: 38px;
    border-radius: 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--surface-soft);
    color: var(--accent);
}
.dash-kpi-icon svg { width: 18px; height: 18px; stroke-width: 2.1; fill: none; stroke: currentColor; }
.dash-kpi-label {
    display: block;
    color: var(--muted);
    font-size: 11px;
    font-weight: 850;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: 3px;
}
.dash-kpi-value {
    display: block;
    font-family: var(--mono);
    font-size: 23px;
    font-weight: 850;
    line-height: 1;
    color: var(--text);
}
.dash-kpi-value .unit {
    font-family: var(--font, inherit);
    font-size: 12px;
    font-weight: 750;
    color: var(--muted);
    margin-left: 3px;
}
.dash-kpi-meta {
    display: block;
    margin-top: 5px;
    font-size: 11.5px;
    color: var(--muted);
    font-weight: 700;
    line-height: 1.25;
}
.dash-kpi-card.tone-danger .dash-kpi-icon { background: var(--tone-danger-soft); color: var(--tone-danger); }
.dash-kpi-card.tone-danger .dash-kpi-value { color: var(--tone-danger); }
.dash-kpi-card.tone-warning .dash-kpi-icon { background: var(--tone-warning-soft); color: var(--tone-warning); }
.dash-kpi-card.tone-warning .dash-kpi-value { color: var(--tone-warning); }
.dash-kpi-card.tone-info .dash-kpi-icon { background: var(--tone-info-soft); color: var(--tone-info); }
.dash-kpi-card.tone-info .dash-kpi-value { color: var(--tone-info); }
.dash-hero-actions {
    position: relative;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
}
.dash-hero-action {
    /* Identic ca geometrie cu butoanele din tasks.php: pill, 42px, text centrat */
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-width: 172px;
    height: 42px;
    min-height: 42px;
    padding: 0 22px;
    border-radius: 999px !important;
    background: rgba(255, 255, 255, .72) !important;
    text-decoration: none;
    font-size: 14px;
    font-weight: 850;
    line-height: 1;
    letter-spacing: -.012em;
    box-shadow:
        0 10px 22px rgba(0, 32, 80, .06),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
    backdrop-filter: blur(12px) saturate(130%);
    -webkit-backdrop-filter: blur(12px) saturate(130%);
    transition: transform .14s ease, box-shadow .14s ease, filter .14s ease, background .14s ease, border-color .14s ease;
}
.dash-hero-action:hover {
    transform: translateY(-1px);
    background: rgba(255, 255, 255, .86) !important;
    filter: brightness(1.02);
    box-shadow:
        0 14px 26px rgba(0, 32, 80, .10),
        inset 0 1px 0 rgba(255,255,255,.92) !important;
}
.dash-hero-action.action-client {
    color: #1160B7 !important;
    border: 1px solid rgba(17, 96, 183, .72) !important;
}
.dash-hero-action.action-programare {
    color: #002050 !important;
    border: 1px solid rgba(0, 32, 80, .72) !important;
}
.dash-hero-action.action-sarcina {
    color: #D24726 !important;
    border: 1px solid rgba(210, 71, 38, .78) !important;
}
.dash-hero-action .plus {
    font-size: 20px;
    line-height: 1;
    font-weight: 650;
    margin-top: -1px;
}
.dash-hero-action svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.1; }
.dash-layout-help {
    margin-left: auto;
    color: var(--muted);
    font-size: 11.5px;
    font-weight: 650;
}
@media (max-width: 980px) {
    .dash-hero-kpis { grid-template-columns: 1fr; }
    .dash-layout-help { width: 100%; margin-left: 0; }
}
@media (max-width: 700px) {
    .dash-greeting { padding: 15px; border-radius: 18px; }
    .dash-hero-top { flex-direction: column; }
    .dash-hero-status { width: 100%; text-align: left; }
}

@media (max-width: 700px) {
    /* Mobil: cele 3 actiuni principale raman obligatoriu pe aceeasi linie */
    .dash-hero-actions {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 7px;
        width: 100%;
        margin-top: 12px;
        align-items: stretch;
    }
    .dash-hero-action {
        min-width: 0 !important;
        width: 100%;
        height: 42px;
        min-height: 42px;
        padding: 0 8px;
        border-radius: 999px !important;
        font-size: clamp(11px, 2.8vw, 13px);
        font-weight: 850;
        gap: 5px;
        white-space: nowrap;
        letter-spacing: -.02em;
    }
    .dash-hero-action .plus {
        font-size: 17px;
        margin-top: -1px;
        flex: 0 0 auto;
    }
}
@media (max-width: 380px) {
    .dash-hero-actions { gap: 5px; }
    .dash-hero-action {
        height: 40px;
        min-height: 40px;
        padding: 0 5px;
        font-size: 10.8px;
        gap: 4px;
    }
    .dash-hero-action .plus { font-size: 15px; }
}

/* === PANEL bazic === */
/* Inaltime maxima ~10cm (380px continut + ~60px head) cu scroll intern pe body */
.panel {
    background: rgba(255,255,255,.84);
    border: 1px solid rgba(177,214,240,.42);
    border-radius: 18px;
    box-shadow: 0 18px 36px -30px rgba(0,32,80,.32), inset 0 1px 0 rgba(255,255,255,.70);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: 440px;
    backdrop-filter: blur(12px) saturate(1.15);
    -webkit-backdrop-filter: blur(12px) saturate(1.15);
}
.panel-head { padding: 14px 16px; border-bottom: 1px solid var(--border2); display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-shrink: 0; }
.panel-title { font-size: 14px; font-weight: 800; color: var(--text); letter-spacing: -.01em; display: flex; align-items: center; gap: 8px; }
.panel-title .badge-count { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; padding: 0 7px; border-radius: 999px; background: var(--accent); color: #fff; font-size: 11px; font-weight: 800; }
.panel-title .badge-count.danger { background: var(--tone-danger); }
.panel-subtitle { font-size: 11.5px; color: var(--muted); font-weight: 600; margin-top: 2px; }
.panel-link { display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 9px; border: 1px solid var(--border); background: #fff; color: var(--text); font-size: 12px; font-weight: 700; text-decoration: none; transition: background .14s ease; }
.panel-link:hover { background: var(--surface-soft); border-color: var(--accent-soft-2); }
.panel-body { padding: 14px 16px; overflow-y: auto; flex: 1 1 auto; min-height: 0; }
.panel-body.tight { padding: 8px 10px; }
/* Scrollbar discret in interiorul cardurilor */
.panel-body::-webkit-scrollbar { width: 8px; }
.panel-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 999px; }
.panel-body::-webkit-scrollbar-thumb:hover { background: var(--accent-soft-2); }
.panel-body::-webkit-scrollbar-track { background: transparent; }

/* === ZONA ECHIPE - card mare full width cu tile-uri === */
.team-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
}
.team-tile {
    position: relative;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px;
    transition: border-color .14s ease, transform .12s ease;
}
.team-tile:hover { transform: translateY(-2px); border-color: var(--accent-soft-2); }
.team-tile.is-free   { border-color: rgba(180,83,9,.30); background: var(--tone-warning-bg); }
.team-tile.is-full   { border-color: rgba(4,120,87,.30); background: var(--tone-success-bg); }
.team-tile .tile-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.team-tile .tile-avatar { width: 32px; height: 32px; border-radius: 10px; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; flex-shrink: 0; }
.team-tile .tile-name { font-size: 14px; font-weight: 800; color: var(--text); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.team-tile .tile-status {
    font-size: 10.5px; font-weight: 800; padding: 3px 9px; border-radius: 999px;
    background: var(--surface-soft); color: var(--muted); white-space: nowrap;
    text-transform: uppercase; letter-spacing: .04em;
}
.team-tile.is-free .tile-status { background: var(--tone-warning); color: #fff; }
.team-tile.is-full .tile-status { background: var(--tone-success); color: #fff; }
.team-tile .tile-hours { font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.team-tile .tile-hours .hours-num { font-family: var(--mono); font-size: 18px; font-weight: 800; letter-spacing: -.02em; }
.team-tile .tile-hours .hours-cap { color: var(--muted); font-size: 12px; font-weight: 600; }
.team-tile .tile-bar { height: 8px; background: var(--surface-muted); border-radius: 999px; overflow: hidden; margin-bottom: 10px; }
.team-tile .tile-bar > span { display: block; height: 100%; background: var(--accent); border-radius: 999px; transition: width .25s ease; }
.team-tile.is-full .tile-bar > span { background: var(--tone-success); }
.team-tile.is-free .tile-bar > span { background: var(--tone-warning); }
.team-tile .tile-meta { font-size: 11.5px; color: var(--muted); font-weight: 600; margin-bottom: 10px; }
.team-tile .tile-meta strong { color: var(--text); font-weight: 800; }
.team-tile .tile-action { display: block; text-align: center; padding: 8px 12px; border-radius: 9px; background: var(--accent); color: #fff; font-size: 12px; font-weight: 700; text-decoration: none; transition: filter .14s ease; }
.team-tile .tile-action:hover { filter: brightness(1.08); }
.team-tile .tile-action.secondary { background: #fff; color: var(--accent); border: 1px solid var(--accent-soft-2); }
.team-tile .tile-action.secondary:hover { background: var(--accent-soft); }

/* === GRID rearanjabil — DOAR full (12) sau jumatate (6) pentru simetrie === */
.dash-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 14px;
    align-items: start;
}
.dash-grid > [data-size="12"] { grid-column: span 12; }
.dash-grid > [data-size="6"]  { grid-column: span 6; }
@media (max-width: 1100px) {
    .dash-grid > .panel { grid-column: span 12 !important; }
}

/* === Drag handles si toolbar pe fiecare card === */
.panel-head .card-tools { display: inline-flex; align-items: center; gap: 4px; opacity: 0; transition: opacity .14s ease; }
.panel:hover .panel-head .card-tools, .panel.is-dragging .card-tools { opacity: 1; }
.card-handle {
    cursor: grab; color: var(--muted); padding: 5px 6px; border-radius: 7px;
    background: transparent; border: 1px solid transparent;
    transition: background .14s ease, color .14s ease, border-color .14s ease;
    display: inline-flex; align-items: center; justify-content: center;
    user-select: none; touch-action: none;
}
.card-handle:hover { background: var(--accent-soft); color: var(--accent); border-color: var(--accent-soft-2); }
.card-handle:active { cursor: grabbing; }
.card-handle svg { width: 14px; height: 14px; fill: currentColor; }
.card-size-btn {
    cursor: pointer; color: var(--muted); padding: 5px 8px; border-radius: 7px;
    background: transparent; border: 1px solid transparent; font-size: 11px; font-weight: 800;
    transition: background .14s ease, color .14s ease, border-color .14s ease;
    font-family: var(--mono);
}
.card-size-btn:hover { background: var(--accent-soft); color: var(--accent); border-color: var(--accent-soft-2); }

/* === Efecte vizuale drag === */
.panel.is-dragging  { opacity: .55; cursor: grabbing; }
.panel.is-ghost     { background: var(--accent-soft) !important; border: 2px dashed var(--accent) !important; }
.panel.is-ghost > * { visibility: hidden; }
.panel.is-chosen    { box-shadow: 0 12px 28px -8px rgba(15, 23, 42, .25), 0 4px 10px rgba(15, 23, 42, .12); }

/* === Reset button in hero === */
.dash-layout-reset {
    display: inline-flex; align-items: center; justify-content: center; gap: 7px;
    min-height: 34px;
    padding: 8px 12px;
    border-radius: 11px;
    background: rgba(255,255,255,.72);
    color: var(--muted);
    border: 1px solid var(--border);
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    transition: background .14s ease, color .14s ease, border-color .14s ease, transform .14s ease;
    font-family: inherit;
}
.dash-layout-reset:hover { transform: translateY(-1px); background: var(--accent-soft); color: var(--accent); border-color: var(--accent-soft-2); }
.dash-layout-reset svg { width: 14px; height: 14px; fill: none; stroke: currentColor; }
.dash-layout-tip { display: none; }

/* === BACKLOG list === */
.backlog-list { display: grid; gap: 8px; }
.backlog-row {
    display: grid; grid-template-columns: 56px minmax(0, 1fr) auto;
    gap: 11px; align-items: center;
    padding: 10px 12px; border: 1px solid var(--border2); border-radius: 12px;
    background: #fff; transition: border-color .14s ease, background .14s ease;
}
.backlog-row:hover { border-color: var(--accent-soft-2); background: var(--surface-soft); }
.backlog-row.is-overdue { border-color: rgba(220,38,38,.25); background: var(--tone-danger-bg); }
.backlog-row .row-date {
    text-align: center; padding: 6px 4px; border-radius: 9px;
    background: #fff; border: 1px solid var(--border2);
    font-family: var(--mono); font-weight: 800; font-size: 11px; color: var(--text);
    line-height: 1.1;
}
.backlog-row.is-overdue .row-date { background: var(--tone-danger); color: #fff; border-color: var(--tone-danger); }
.backlog-row .row-date .row-day { font-size: 14px; }
.backlog-row .row-date .row-mon { font-size: 9px; text-transform: uppercase; opacity: .75; }
.backlog-row .row-info { min-width: 0; }
.backlog-row .row-client { font-size: 13.5px; font-weight: 800; color: var(--text); }
.backlog-row .row-meta { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
.backlog-row .row-meta strong { color: var(--tone-danger); }
.backlog-row .row-action {
    padding: 7px 13px; border-radius: 9px; background: var(--accent); color: #fff;
    font-size: 12px; font-weight: 800; text-decoration: none; white-space: nowrap;
    transition: filter .14s ease;
}
.backlog-row .row-action:hover { filter: brightness(1.08); }

/* === FINANCIAR === */
.fin-mock-banner {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 3px 9px; border-radius: 6px;
    background: var(--tone-warning-soft); color: var(--tone-warning);
    border: 1px solid rgba(180,83,9,.22);
    font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    margin-left: 8px;
}
.fin-grid { display: grid; gap: 10px; }
.fin-card {
    padding: 12px 14px; border-radius: 12px; background: var(--surface-soft);
    border: 1px solid var(--border2);
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.fin-card.primary { background: var(--tone-danger-bg); border-color: rgba(220,38,38,.22); }
.fin-card .fin-label { font-size: 11px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
.fin-card.primary .fin-label { color: var(--tone-danger); }
.fin-card .fin-value { font-size: 22px; font-weight: 800; color: var(--text); letter-spacing: -.025em; font-family: var(--mono); }
.fin-card .fin-meta { margin-top: 2px; font-size: 12px; font-weight: 800; color: var(--muted); font-family: var(--mono); }
.fin-card.primary .fin-value { color: var(--tone-danger); }
.fin-card .fin-currency { font-size: 12px; font-weight: 700; color: var(--muted); margin-left: 4px; }
.fin-card .fin-trend {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 800;
    background: var(--tone-success-soft); color: var(--tone-success);
}
.fin-card .fin-trend.down { background: var(--tone-danger-soft); color: var(--tone-danger); }
.fin-unpaid-title { font-size: 11px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin: 16px 0 8px; }
.fin-unpaid-row {
    display: grid; grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 10px; align-items: center;
    padding: 9px 11px; border: 1px solid var(--border2); border-radius: 10px;
    background: #fff; margin-bottom: 6px;
}
.fin-unpaid-row .uf-client { font-size: 12.5px; font-weight: 700; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fin-unpaid-row .uf-meta { font-size: 10.5px; color: var(--muted); margin-top: 2px; }
.fin-unpaid-row .uf-amount { font-family: var(--mono); font-size: 13px; font-weight: 800; color: var(--text); white-space: nowrap; }
.fin-unpaid-row .uf-days {
    padding: 3px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 800; white-space: nowrap;
    background: var(--surface-soft); color: var(--muted);
}
.fin-unpaid-row .uf-days.warn   { background: var(--tone-warning-soft); color: var(--tone-warning); }
.fin-unpaid-row .uf-days.danger { background: var(--tone-danger-soft);  color: var(--tone-danger); }

/* === AGENDA === */
.agenda-list { display: grid; gap: 6px; }
.agenda-row {
    display: grid; grid-template-columns: 60px minmax(0, 1fr) auto auto;
    gap: 12px; align-items: center;
    padding: 10px 12px; border: 1px solid var(--border2); border-radius: 12px;
    background: #fff;
    transition: border-color .14s ease, background .14s ease;
}
.agenda-row:hover { border-color: var(--accent-soft-2); background: var(--surface-soft); }
.agenda-row .ag-time {
    text-align: center; font-family: var(--mono);
    font-size: 13px; font-weight: 800; color: var(--text);
    padding: 5px 8px; background: var(--surface-soft); border-radius: 8px;
}
.agenda-row .ag-info { min-width: 0; }
.agenda-row .ag-client { font-size: 13.5px; font-weight: 800; color: var(--text); overflow-wrap: anywhere; }
.agenda-row .ag-meta { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
.agenda-row .ag-meta strong { color: var(--text); font-weight: 800; }
.agenda-row .ag-team-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 5px; vertical-align: middle; }
.agenda-row .ag-status {
    padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 800;
    background: var(--surface-soft); color: var(--muted); white-space: nowrap;
}
.agenda-row .ag-status.tone-info { background: var(--tone-info-soft); color: var(--tone-info); }
.agenda-row .ag-status.tone-info-strong { background: var(--tone-info); color: #fff; }
.agenda-row .ag-status.tone-success { background: var(--tone-success-soft); color: var(--tone-success); }
.agenda-row .ag-status.tone-warning { background: var(--tone-warning-soft); color: var(--tone-warning); }
.agenda-row .ag-icons { display: inline-flex; gap: 4px; }
.agenda-row .ag-icons a {
    width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
    border-radius: 8px; border: 1px solid var(--border2); background: #fff; color: var(--muted);
    transition: background .14s ease, color .14s ease;
}
.agenda-row .ag-icons a:hover { background: var(--accent-soft); color: var(--accent); border-color: var(--accent-soft-2); }
.agenda-row .ag-icons a svg { width: 13px; height: 13px; stroke-width: 2; fill: none; stroke: currentColor; }

/* === Empty states === */
.empty-state { padding: 22px 12px; text-align: center; color: var(--muted); font-size: 13px; font-weight: 600; }
.empty-state .es-title { font-weight: 800; color: var(--text); font-size: 14px; margin-bottom: 4px; }

/* === Spacing principal === */
.dash-section { margin-bottom: 14px; }

/* === EFICIENTA echipe === */
.eff-tabs { display: inline-flex; gap: 4px; padding: 3px; background: var(--surface-soft); border-radius: 10px; border: 1px solid var(--border2); }
.eff-tab { padding: 6px 14px; border-radius: 8px; border: 0; background: transparent; color: var(--muted); font-size: 12px; font-weight: 700; cursor: pointer; transition: background .14s ease, color .14s ease; font-family: inherit; }
.eff-tab:hover { color: var(--text); }
.eff-tab.is-active { background: #fff; color: var(--accent); box-shadow: 0 1px 2px rgba(0,0,0,.04); }
.eff-pane { display: none; }
.eff-pane.is-active { display: block; animation: effFadeIn .15s ease; }
@keyframes effFadeIn { from { opacity: 0; } to { opacity: 1; } }

.eff-grade-pill {
    display: inline-flex; align-items: center; padding: 3px 10px;
    border-radius: 999px; font-size: 11.5px; font-weight: 800;
    background: var(--tone-success-soft); color: var(--tone-success);
    border: 1px solid rgba(4,120,87,.22); margin-left: 8px; font-family: var(--mono);
}

.eff-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
.eff-summary-card { background: var(--surface-soft); border: 1px solid var(--border2); border-radius: 12px; padding: 12px 14px; }
.eff-summary-card.eff-summary-grade.tone-success { background: var(--tone-success-bg); border-color: rgba(4,120,87,.20); }
.eff-summary-card.eff-summary-grade.tone-warning { background: var(--tone-warning-bg); border-color: rgba(180,83,9,.20); }
.eff-summary-card.eff-summary-grade.tone-danger  { background: var(--tone-danger-bg);  border-color: rgba(220,38,38,.20); }
.eff-sum-label { font-size: 11px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
.eff-sum-value { font-size: 26px; font-weight: 800; color: var(--text); letter-spacing: -.025em; font-family: var(--mono); line-height: 1; }
.eff-summary-card.tone-success .eff-sum-value { color: var(--tone-success); }
.eff-summary-card.tone-warning .eff-sum-value { color: var(--tone-warning); }
.eff-summary-card.tone-danger  .eff-sum-value { color: var(--tone-danger); }
.eff-sum-suffix { font-size: 13px; font-weight: 700; color: var(--muted); margin-left: 2px; }
.eff-sum-trend { margin-top: 6px; font-size: 11px; font-weight: 700; color: var(--muted); }
.eff-sum-trend.up   { color: var(--tone-success); }
.eff-sum-trend.down { color: var(--tone-danger); }

.eff-list { display: grid; gap: 10px; }
.eff-row { padding: 10px 12px; border: 1px solid var(--border2); border-radius: 12px; background: #fff; }
.eff-row-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.eff-avatar { width: 30px; height: 30px; border-radius: 9px; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; flex-shrink: 0; }
.eff-row-name { flex: 1; min-width: 0; font-size: 13.5px; font-weight: 800; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.eff-row-grade {
    font-family: var(--mono); font-size: 16px; font-weight: 800;
    padding: 4px 12px; border-radius: 8px; min-width: 60px; text-align: center;
    background: var(--surface-soft); color: var(--text);
}
.eff-row-grade.tone-success { background: var(--tone-success-soft); color: var(--tone-success); border: 1px solid rgba(4,120,87,.22); }
.eff-row-grade.tone-warning { background: var(--tone-warning-soft); color: var(--tone-warning); border: 1px solid rgba(180,83,9,.22); }
.eff-row-grade.tone-danger  { background: var(--tone-danger-soft);  color: var(--tone-danger);  border: 1px solid rgba(220,38,38,.22); }

.eff-row-bar { height: 7px; background: var(--surface-muted); border-radius: 999px; overflow: hidden; margin-bottom: 7px; }
.eff-row-bar > span { display: block; height: 100%; background: var(--accent); border-radius: 999px; transition: width .25s ease; }
.eff-row.is-success .eff-row-bar > span { background: var(--tone-success); }
.eff-row.is-warning .eff-row-bar > span { background: var(--tone-warning); }
.eff-row.is-danger  .eff-row-bar > span { background: var(--tone-danger); }
.eff-row-meta { font-size: 11.5px; color: var(--muted); font-weight: 600; }
.eff-row-meta strong { color: var(--text); font-weight: 800; }
.eff-row-pct { font-family: var(--mono); font-weight: 800; color: var(--accent); }

@media (max-width: 700px) {
    .eff-summary { grid-template-columns: 1fr; }
}


/* === Dashboard: efect 3D discret pe carduri === */
.dash-greeting,
.dash-kpi-card,
.panel,
.team-tile,
.backlog-row,
.agenda-row,
.fin-unpaid-row,
.eff-summary-card,
.eff-row {
    position: relative;
    transform: translateZ(0);
    box-shadow:
        0 1px 0 rgba(255,255,255,.86) inset,
        0 2px 0 rgba(0,32,80,.035),
        0 18px 36px -28px rgba(0,32,80,.42),
        0 8px 18px -16px rgba(15,23,42,.26) !important;
}

.dash-greeting::after,
.dash-kpi-card::after,
.panel::after,
.team-tile::after,
.backlog-row::after,
.agenda-row::after,
.fin-unpaid-row::after,
.eff-summary-card::after,
.eff-row::after {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    border-radius: inherit;
    background:
        linear-gradient(180deg, rgba(255,255,255,.50), rgba(255,255,255,0) 42%),
        radial-gradient(circle at 18% 0%, rgba(177,214,240,.22), transparent 38%);
    opacity: .72;
    mix-blend-mode: normal;
}

.dash-kpi-card,
.panel,
.team-tile,
.backlog-row,
.agenda-row,
.fin-unpaid-row,
.eff-summary-card,
.eff-row {
    transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease, filter .16s ease;
}

.dash-kpi-card:hover,
.panel:hover,
.team-tile:hover,
.backlog-row:hover,
.agenda-row:hover,
.fin-unpaid-row:hover,
.eff-summary-card:hover,
.eff-row:hover {
    transform: translateY(-2px) translateZ(0);
    filter: saturate(1.02);
    box-shadow:
        0 1px 0 rgba(255,255,255,.92) inset,
        0 3px 0 rgba(0,32,80,.045),
        0 24px 44px -28px rgba(0,32,80,.52),
        0 12px 24px -18px rgba(15,23,42,.30) !important;
}

.panel-head,
.panel-body,
.dash-kpi-card > *,
.team-tile > *,
.backlog-row > *,
.agenda-row > *,
.fin-unpaid-row > *,
.eff-summary-card > *,
.eff-row > * {
    position: relative;
    z-index: 1;
}

@media (max-width: 700px) {
    .dash-greeting,
    .dash-kpi-card,
    .panel,
    .team-tile,
    .backlog-row,
    .agenda-row,
    .fin-unpaid-row,
    .eff-summary-card,
    .eff-row {
        box-shadow:
            0 1px 0 rgba(255,255,255,.86) inset,
            0 1px 0 rgba(0,32,80,.03),
            0 14px 28px -24px rgba(0,32,80,.38) !important;
    }
    .dash-kpi-card:hover,
    .panel:hover,
    .team-tile:hover,
    .backlog-row:hover,
    .agenda-row:hover,
    .fin-unpaid-row:hover,
    .eff-summary-card:hover,
    .eff-row:hover {
        transform: none;
    }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('dashboard', $isAdmin); ?>
    <main class="main">
        <div class="content">

            <!-- ============================================
                 GREETING + BRIEFING (3 secunde de orientare)
                 ============================================ -->
            <div class="dash-greeting">
                <div class="dash-hero-top">
                    <div class="dash-hero-copy">
                        <h1><?= dash_h($greeting) ?>, <span><?= dash_h($dashboardUserName) ?></span></h1>
                        <div class="sub">Fiecare lucrare facuta bine inseamna incredere castigata, clienti protejati si respect pentru munca noastra.</div>
                    </div>
                    <div class="dash-hero-status">
                        <span class="dash-status-dot"></span>Operational azi
                        <small><?= date('d.m.Y') ?></small>
                    </div>
                </div>

                <div class="dash-hero-kpis">
                    <a class="dash-kpi-card tone-<?= $tasksOverdueCount > 0 ? 'danger' : 'warning' ?>" href="tasks.php">
                        <span class="dash-kpi-icon"><?= app_icon_svg('tasks') ?></span>
                        <span>
                            <span class="dash-kpi-label">De programat</span>
                            <strong class="dash-kpi-value"><?= (int)$backlogTotal ?></strong>
                            <span class="dash-kpi-meta"><?= (int)$tasksOverdueCount ?> intarziate · <?= (int)$tasksTodayCount ?> azi</span>
                        </span>
                    </a>

                    <a class="dash-kpi-card tone-warning" href="interventii_facturare.php?billing_status=de_facturat">
                        <span class="dash-kpi-icon"><?= app_icon_svg('invoice') ?></span>
                        <span>
                            <span class="dash-kpi-label">De facturat</span>
                            <strong class="dash-kpi-value"><?= (int)$ibDue ?></strong>
                            <span class="dash-kpi-meta"><?= dash_h(number_format((float)$ibDueAmount, 0, ',', '.')) ?> lei fara TVA</span>
                        </span>
                    </a>

                    <a class="dash-kpi-card tone-info" href="calendar.php?date=<?= dash_h($today) ?>&view=day">
                        <span class="dash-kpi-icon"><?= app_icon_svg('team') ?></span>
                        <span>
                            <span class="dash-kpi-label">Echipe active</span>
                            <strong class="dash-kpi-value"><?= (int)$teamsBusy ?><span class="unit">/ <?= (int)$teamsTotal ?></span></strong>
                            <span class="dash-kpi-meta"><?= dash_h(number_format((float)$todayBookedHours, 1, ',', '.')) ?>h programate azi</span>
                        </span>
                    </a>
                </div>

                <div class="dash-hero-actions">
                    <a class="dash-hero-action action-client" href="clients.php?open_create=1"><span class="plus">+</span> Client</a>
                    <a class="dash-hero-action action-programare" href="calendar.php?date=<?= dash_h($today) ?>&view=day&open_create=1"><span class="plus">+</span> Programare</a>
                    <a class="dash-hero-action action-sarcina" href="tasks.php?open_create=1"><span class="plus">+</span> Sarcina</a>
                </div>
            </div>

            <div class="dash-grid" id="dashGrid">

            <!-- ZONA 1 - ECHIPE (capacitate ore) -->
            <section class="panel" data-card-id="echipe" data-size="12">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">
                            Echipe astazi
                            <?php if ($teamsFree > 0): ?>
                                <span class="badge-count danger"><?= (int)$teamsFree ?> libere</span>
                            <?php endif; ?>
                        </div>
                        <div class="panel-subtitle">Capacitate calculata pe ore lucrate (8h = ziua plina).</div>
                    </div>
                    <div style="display:inline-flex; align-items:center; gap:8px;">
                        <a class="panel-link" href="calendar.php?date=<?= dash_h($today) ?>&view=day">Vezi calendarul →</a>
                        <span class="card-tools">
                            <button type="button" class="card-size-btn" onclick="dashToggleSize('echipe')" title="Comuta latimea: full ↔ jumatate">▢</button>
                            <span class="card-handle" title="Trage pentru rearanjare"><svg viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg></span>
                        </span>
                    </div>
                </div>
                <div class="panel-body">
                    <?php if (!$teamStats): ?>
                        <div class="empty-state">
                            <div class="es-title">Nicio echipa activa.</div>
                            Adauga membri din Setari → Echipe teren.
                        </div>
                    <?php else: ?>
                        <div class="team-grid">
                            <?php foreach ($teamStats as $tm):
                                $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$tm['color']) ? $tm['color'] : '#163B63';
                                $hours = (float)$tm['hours_booked'];
                                $jobsTotal = (int)$tm['jobs_total'];
                                $jobsDone = (int)$tm['jobs_done'];
                                $percent = min(100, max(0, ($hours / $teamCapacityHours) * 100));
                                $isFree = ($hours <= 0.01);
                                $isFull = ($percent >= 87.5);
                                $statusLabel = $isFree ? 'Libera' : ($isFull ? 'Plina' : 'In lucru');
                                $cls = $isFree ? 'is-free' : ($isFull ? 'is-full' : '');
                                $initial = mb_strtoupper(mb_substr((string)$tm['name'], 0, 1));
                            ?>
                                <div class="team-tile <?= $cls ?>">
                                    <div class="tile-head">
                                        <span class="tile-avatar" style="background: <?= dash_h($color) ?>;"><?= dash_h($initial) ?></span>
                                        <span class="tile-name"><?= dash_h($tm['name']) ?></span>
                                        <span class="tile-status"><?= dash_h($statusLabel) ?></span>
                                    </div>
                                    <div class="tile-hours">
                                        <span class="hours-num"><?= number_format($hours, 1, ',', '') ?>h</span>
                                        <span class="hours-cap">/ 8h</span>
                                    </div>
                                    <div class="tile-bar"><span style="width: <?= number_format($percent, 1, '.', '') ?>%;"></span></div>
                                    <div class="tile-meta">
                                        <strong><?= $jobsTotal ?></strong> <?= $jobsTotal === 1 ? 'lucrare' : 'lucrari' ?>
                                        <?php if ($jobsDone > 0): ?> · <strong><?= $jobsDone ?></strong> finalizate<?php endif; ?>
                                    </div>
                                    <?php if ($isFree): ?>
                                        <a class="tile-action" href="tasks.php">Aloca lucrare</a>
                                    <?php else: ?>
                                        <a class="tile-action secondary" href="calendar.php?date=<?= dash_h($today) ?>&view=day&team=<?= (int)$tm['id'] ?>">Vezi programul</a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ZONA 1.5 - EFICIENTA echipe -->
            <section class="panel" data-card-id="eficienta" data-size="12">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">
                            Eficiență echipe
                            <span class="eff-grade-pill" id="effAvgGradePill">--</span>
                        </div>
                        <div class="panel-subtitle" id="effSubtitle">Notă calculată din ore lucrate / țintă (40h săptămână, 160h lună).</div>
                    </div>
                    <div style="display:inline-flex; align-items:center; gap:8px;">
                        <div class="eff-tabs" role="tablist">
                            <button type="button" class="eff-tab is-active" data-period="week" onclick="effSwitchPeriod('week')">Săptămâna</button>
                            <button type="button" class="eff-tab" data-period="month" onclick="effSwitchPeriod('month')">Luna</button>
                        </div>
                        <span class="card-tools">
                            <button type="button" class="card-size-btn" onclick="dashToggleSize('eficienta')" title="Comuta latimea: full ↔ jumatate">▢</button>
                            <span class="card-handle" title="Trage pentru rearanjare"><svg viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg></span>
                        </span>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="eff-pane is-active" data-pane="week">
                        <?php renderEffPane($effWeek, $weekTrend, 'aceasta saptamana', 'saptamana trecuta'); ?>
                    </div>
                    <div class="eff-pane" data-pane="month">
                        <?php renderEffPane($effMonth, $monthTrend, 'aceasta luna', 'luna trecuta'); ?>
                    </div>
                </div>
            </section>

            <?php
            // Helper de render pentru eficienta (e definit aici ca sa avem inchidere
            // de PHP si HTML in acelasi fisier)
            function renderEffPane(array $eff, float $trend, string $periodLabel, string $lastLabel): void {
                $teams = $eff['teams'];
                $avgGrade = $eff['avg_grade'];
                $avgPercent = $eff['avg_percent'];
                $capacityTeam = $eff['capacity_team'];
                $totalHours = $eff['total_hours'];
                $teamCount = max(1, count($teams));
                $totalTarget = $capacityTeam * $teamCount;

                $gradeTone = $avgGrade >= 8 ? 'tone-success' : ($avgGrade >= 5 ? 'tone-warning' : 'tone-danger');
                $trendTone = $trend >= 0 ? 'up' : 'down';
                ?>
                <div class="eff-summary">
                    <div class="eff-summary-card eff-summary-grade <?= $gradeTone ?>">
                        <div class="eff-sum-label">Notă medie echipă</div>
                        <div class="eff-sum-value"><?= number_format($avgGrade, 1, ',', '') ?><span class="eff-sum-suffix">/10</span></div>
                        <?php if ($trend != 0): ?>
                            <div class="eff-sum-trend <?= $trendTone ?>"><?= $trend > 0 ? '+' : '' ?><?= number_format($trend, 1, ',', '') ?>% vs <?= dash_h($lastLabel) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="eff-summary-card">
                        <div class="eff-sum-label">Ore lucrate total</div>
                        <div class="eff-sum-value"><?= number_format($totalHours, 1, ',', '') ?><span class="eff-sum-suffix">h</span></div>
                        <div class="eff-sum-trend">din <?= number_format($totalTarget, 0, ',', '') ?>h tinta (<?= $teamCount ?> echipe)</div>
                    </div>
                    <div class="eff-summary-card">
                        <div class="eff-sum-label">Țintă per echipă</div>
                        <div class="eff-sum-value"><?= number_format($capacityTeam, 0, ',', '') ?><span class="eff-sum-suffix">h</span></div>
                        <div class="eff-sum-trend"><?= dash_h($periodLabel) ?></div>
                    </div>
                </div>

                <?php if (!$teams): ?>
                    <div class="empty-state">
                        <div class="es-title">Nicio echipa activa.</div>
                    </div>
                <?php else: ?>
                    <div class="eff-list">
                        <?php foreach ($teams as $tm):
                            $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$tm['color']) ? $tm['color'] : '#163B63';
                            $grade = $tm['grade'];
                            $percent = $tm['percent'];
                            $tone = $grade >= 8 ? 'tone-success' : ($grade >= 5 ? 'tone-warning' : 'tone-danger');
                            $initial = mb_strtoupper(mb_substr((string)$tm['name'], 0, 1));
                        ?>
                            <div class="eff-row">
                                <div class="eff-row-head">
                                    <span class="eff-avatar" style="background: <?= dash_h($color) ?>"><?= dash_h($initial) ?></span>
                                    <span class="eff-row-name"><?= dash_h($tm['name']) ?></span>
                                    <span class="eff-row-grade <?= $tone ?>"><?= number_format($grade, 1, ',', '') ?></span>
                                </div>
                                <div class="eff-row-bar">
                                    <span style="width: <?= number_format(min(100, $percent), 1, '.', '') ?>%"></span>
                                </div>
                                <div class="eff-row-meta">
                                    <strong><?= number_format($tm['hours'], 1, ',', '') ?>h</strong> lucrate
                                    din <?= number_format($tm['capacity'], 0, ',', '') ?>h
                                    · <?= $tm['jobs_total'] ?> <?= $tm['jobs_total'] === 1 ? 'lucrare' : 'lucrari' ?>
                                    · <span class="eff-row-pct"><?= number_format($percent, 0, ',', '') ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php
            }
            ?>

            <!-- ZONA 2 - BACKLOG -->
            <section class="panel" data-card-id="backlog" data-size="6">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">
                            De programat
                            <?php if ($backlogTotal > 0): ?>
                                <span class="badge-count <?= $tasksOverdueCount > 0 ? 'danger' : '' ?>"><?= (int)$backlogTotal ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="panel-subtitle">
                            <?php if ($tasksOverdueCount > 0): ?>
                                <?= $tasksOverdueCount ?> intarziate · <?= $tasksTodayCount ?> azi
                            <?php elseif ($tasksTodayCount > 0): ?>
                                <?= $tasksTodayCount ?> sarcini cu termen azi
                            <?php else: ?>
                                Nimic urgent
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:inline-flex; align-items:center; gap:8px;">
                        <a class="panel-link" href="tasks.php">Toate sarcinile →</a>
                        <span class="card-tools">
                            <button type="button" class="card-size-btn" onclick="dashToggleSize('backlog')" title="Comuta latimea: full ↔ jumatate">▢</button>
                            <span class="card-handle" title="Trage pentru rearanjare"><svg viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg></span>
                        </span>
                    </div>
                </div>
                    <div class="panel-body">
                        <?php if (!$backlogList): ?>
                            <div class="empty-state">
                                <div class="es-title">Backlog gol.</div>
                                Buna treaba — toate sarcinile sunt programate.
                            </div>
                        <?php else: ?>
                            <div class="backlog-list">
                                <?php foreach ($backlogList as $row):
                                    $dueDate = (string)$row['due_date'];
                                    $isOverdue = $dueDate < $today;
                                    $daysOverdue = $isOverdue ? abs(dash_days_diff($dueDate, $today)) : 0;
                                    $dayNum = (int)date('d', strtotime($dueDate));
                                    $monthLabels = ['ian','feb','mar','apr','mai','iun','iul','aug','sep','oct','noi','dec'];
                                    $monShort = $monthLabels[(int)date('n', strtotime($dueDate)) - 1] ?? '';
                                    $clientId = (int)($row['client_id'] ?? 0);
                                    $taskId = (int)($row['id'] ?? 0);
                                    $service = trim((string)($row['service_type'] ?? ''));
                                ?>
                                    <article class="backlog-row <?= $isOverdue ? 'is-overdue' : '' ?>">
                                        <div class="row-date">
                                            <div class="row-day"><?= $dayNum ?></div>
                                            <div class="row-mon"><?= dash_h($monShort) ?></div>
                                        </div>
                                        <div class="row-info">
                                            <div class="row-client"><?= dash_h($row['client_name'] ?: 'Client') ?></div>
                                            <div class="row-meta">
                                                <?= $service !== '' ? dash_h($service) . ' · ' : '' ?>
                                                <?php if ($isOverdue): ?>
                                                    <strong><?= $daysOverdue ?> <?= $daysOverdue === 1 ? 'zi intarziere' : 'zile intarziere' ?></strong>
                                                <?php else: ?>
                                                    Termen azi
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <a class="row-action" href="calendar.php?client_id=<?= $clientId ?>&task_id=<?= $taskId ?>&service_type=<?= urlencode($service) ?>&open_create=1">Programeaza</a>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

            <!-- ZONA 3 - CHECKLIST FACTURARE INTERVENTII -->
            <section class="panel" data-card-id="facturare_interventii" data-size="6">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Interventii de facturat</div>
                        <div class="panel-subtitle">Checklist simplu pentru birou, fara integrare de facturare.</div>
                    </div>
                    <div style="display:inline-flex; align-items:center; gap:8px;">
                        <a class="panel-link" href="interventii_facturare.php">Deschide →</a>
                        <span class="card-tools">
                            <button type="button" class="card-size-btn" onclick="dashToggleSize('facturare_interventii')" title="Comuta latimea: full ↔ jumatate">▢</button>
                            <span class="card-handle" title="Trage pentru rearanjare"><svg viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg></span>
                        </span>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="fin-grid">
                        <div class="fin-card primary">
                            <div>
                                <div class="fin-label">De facturat</div>
                                <div class="fin-value"><?= (int)$ibDue ?></div>
                                <div class="fin-meta"><?= dash_h(dash_format_money($ibDueAmount)) ?> lei</div>
                            </div>
                        </div>

                        <div class="fin-card">
                            <div>
                                <div class="fin-label">Facturate luna asta</div>
                                <div class="fin-value"><?= (int)$ibBilledMonth ?></div>
                                <div class="fin-meta"><?= dash_h(dash_format_money($ibBilledMonthAmount)) ?> lei</div>
                            </div>
                        </div>

                        <div class="fin-card">
                            <div>
                                <div class="fin-label">Nu se factureaza luna asta</div>
                                <div class="fin-value"><?= (int)$ibNoBillMonth ?></div>
                                <div class="fin-meta"><?= dash_h(dash_format_money($ibNoBillMonthAmount)) ?> lei</div>
                            </div>
                        </div>
                    </div>

                    <div class="fin-unpaid-title">Cele mai vechi de facturat</div>
                    <?php if (!$ibOldestList): ?>
                        <div class="empty-state mini">Nu exista interventii restante la facturare.</div>
                    <?php else: ?>
                        <?php foreach ($ibOldestList as $u): ?>
                            <div class="fin-unpaid-row">
                                <div>
                                    <div class="uf-client"><?= dash_h($u['client_name'] ?: 'Client') ?></div>
                                    <div class="uf-meta"><?= dash_h($u['appointment_date']) ?><?= !empty($u['start_time']) ? ' · ' . dash_h(substr((string)$u['start_time'], 0, 5)) : '' ?><?= !empty($u['service_type']) ? ' · ' . dash_h($u['service_type']) : '' ?> · <?= dash_h(dash_format_money((float)($u['billing_amount'] ?? 0))) ?> lei</div>
                                </div>
                                <a class="panel-link" href="interventii_facturare.php?billing_status=de_facturat">Verifica</a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ZONA 4 - AGENDA ZILEI -->
            <section class="panel" data-card-id="agenda" data-size="12">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">
                            Agenda zilei
                            <?php if ($appointmentsToday > 0): ?>
                                <span class="badge-count"><?= (int)$appointmentsToday ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="panel-subtitle">Programari de azi in ordine cronologica.</div>
                    </div>
                    <div style="display:inline-flex; align-items:center; gap:8px;">
                        <a class="panel-link" href="calendar.php?date=<?= dash_h($today) ?>&view=day">Calendar zi →</a>
                        <span class="card-tools">
                            <button type="button" class="card-size-btn" onclick="dashToggleSize('agenda')" title="Comuta latimea: full ↔ jumatate">▢</button>
                            <span class="card-handle" title="Trage pentru rearanjare"><svg viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg></span>
                        </span>
                    </div>
                </div>
                <div class="panel-body">
                    <?php if (!$todayAppointments): ?>
                        <div class="empty-state">
                            <div class="es-title">Nicio programare astazi.</div>
                            <a class="panel-link" style="margin-top:10px; display:inline-flex;" href="calendar.php?date=<?= dash_h($today) ?>&view=day&open_create=1">+ Adauga o programare</a>
                        </div>
                    <?php else: ?>
                        <div class="agenda-list">
                            <?php foreach ($todayAppointments as $a):
                                $startTime = dash_time($a['start_time'] ?? null);
                                $status = (string)($a['status'] ?? 'confirmata');
                                $statusTone = 'tone-' . dash_status_tone($status);
                                $teamColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($a['team_color'] ?? '')) ? $a['team_color'] : '#163B63';
                                $apptId = (int)$a['id'];
                            ?>
                                <article class="agenda-row">
                                    <div class="ag-time"><?= dash_h($startTime) ?></div>
                                    <div class="ag-info">
                                        <div class="ag-client">
                                            <?= dash_h($a['client_name'] ?: 'Client') ?>
                                            <?php if (!empty($a['service_type'])): ?> · <?= dash_h($a['service_type']) ?><?php endif; ?>
                                        </div>
                                        <div class="ag-meta">
                                            <span class="ag-team-dot" style="background: <?= dash_h($teamColor) ?>"></span>
                                            <strong><?= dash_h($a['team_name'] ?? 'Fara echipa') ?></strong>
                                        </div>
                                    </div>
                                    <span class="ag-status <?= $statusTone ?>"><?= dash_h(dash_status_label($status)) ?></span>
                                    <div class="ag-icons">
                                        <a href="calendar.php?date=<?= dash_h($today) ?>&view=day" title="Vezi"><?= app_icon_svg('eye') ?></a>
                                        <a href="procese_verbale.php?new=1&appointment_id=<?= $apptId ?>" title="Emite PV"><?= app_icon_svg('processes') ?></a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            </div><!-- /.dash-grid -->

        </div>
    </main>
</div>

<!-- SortableJS pentru drag & drop -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
/* ============================================================
   DASHBOARD GRID - drag & drop + persistenta + resize
   ============================================================ */
// v2 - layout simetric (doar full sau jumatate)
const DASH_STORAGE_KEY  = 'pz_dashboard_layout_v2';
const DASH_VALID_SIZES  = [6, 12]; // doar full (12) sau jumatate (6) pentru simetrie
const DASH_SIZE_CYCLE   = { 12: 6, 6: 12 }; // toggle binar full <-> half

function dashSaveLayout() {
    const grid = document.getElementById('dashGrid');
    if (!grid) return;
    const layout = [...grid.querySelectorAll('[data-card-id]')].map(el => ({
        id: el.dataset.cardId,
        size: parseInt(el.dataset.size, 10) || 12
    }));
    try { localStorage.setItem(DASH_STORAGE_KEY, JSON.stringify(layout)); } catch(e) {}
}

function dashLoadLayout() {
    const grid = document.getElementById('dashGrid');
    if (!grid) return;
    let layout;
    try { layout = JSON.parse(localStorage.getItem(DASH_STORAGE_KEY) || 'null'); } catch(e) { layout = null; }
    if (!Array.isArray(layout)) return;
    // Aplica ordinea
    layout.forEach(item => {
        const card = grid.querySelector('[data-card-id="' + item.id + '"]');
        if (card) {
            // Aplica size-ul salvat (daca e valid)
            if (DASH_VALID_SIZES.includes(item.size)) {
                card.dataset.size = String(item.size);
            }
            grid.appendChild(card); // re-attach in noua ordine
        }
    });
}

function dashToggleSize(cardId) {
    const card = document.querySelector('[data-card-id="' + cardId + '"]');
    if (!card) return;
    const cur = parseInt(card.dataset.size, 10) || 12;
    const next = DASH_SIZE_CYCLE[cur] || 12;
    card.dataset.size = String(next);
    dashSaveLayout();
}

function dashResetLayout() {
    if (!confirm('Resetezi layout-ul cardurilor la aspectul implicit?')) return;
    try { localStorage.removeItem(DASH_STORAGE_KEY); } catch(e) {}
    location.reload();
}

document.addEventListener('DOMContentLoaded', () => {
    dashLoadLayout();
    const grid = document.getElementById('dashGrid');
    if (grid && typeof Sortable !== 'undefined') {
        new Sortable(grid, {
            animation: 180,
            handle: '.card-handle',
            draggable: '[data-card-id]',
            ghostClass: 'is-ghost',
            chosenClass: 'is-chosen',
            dragClass: 'is-dragging',
            onEnd: dashSaveLayout
        });
    }
});

/* === Toggle perioada Eficienta echipe === */
const effData = {
    week:  { grade: <?= number_format($effWeek['avg_grade'], 1, '.', '') ?>,  label: 'aceasta saptamana', target: 40 },
    month: { grade: <?= number_format($effMonth['avg_grade'], 1, '.', '') ?>, label: 'aceasta luna', target: 160 }
};

function effSwitchPeriod(period) {
    document.querySelectorAll('.eff-tab').forEach(t => t.classList.toggle('is-active', t.dataset.period === period));
    document.querySelectorAll('.eff-pane').forEach(p => p.classList.toggle('is-active', p.dataset.pane === period));
    effUpdateGradePill(period);
}

function effUpdateGradePill(period) {
    const pill = document.getElementById('effAvgGradePill');
    const sub = document.getElementById('effSubtitle');
    if (!pill) return;
    const d = effData[period];
    pill.textContent = String(d.grade).replace('.', ',') + '/10';
    pill.className = 'eff-grade-pill';
    if (d.grade >= 8)      pill.style.cssText = 'background:var(--tone-success-soft); color:var(--tone-success); border-color:rgba(4,120,87,.22);';
    else if (d.grade >= 5) pill.style.cssText = 'background:var(--tone-warning-soft); color:var(--tone-warning); border-color:rgba(180,83,9,.22);';
    else                   pill.style.cssText = 'background:var(--tone-danger-soft); color:var(--tone-danger); border-color:rgba(220,38,38,.22);';
    if (sub) sub.textContent = 'Notă medie pentru ' + d.label + ' (țintă: ' + d.target + 'h / echipă).';
}

document.addEventListener('DOMContentLoaded', () => effUpdateGradePill('week'));
</script>

</body>
</html>
