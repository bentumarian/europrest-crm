<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();
$isTeamUser = function_exists('is_team_user') ? is_team_user() : false;

// Pentru tehnicieni, dashboard-ul rămâne calendarul lor
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
        'in_lucru' => 'În lucru', 'finalizata' => 'Finalizată', 'anulata' => 'Anulată',
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

function dash_percent_change(float $current, float $previous): ?float {
    if (abs($previous) < 0.00001) {
        return abs($current) < 0.00001 ? 0.0 : null;
    }
    return round((($current - $previous) / $previous) * 100, 1);
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

// Săptămâna curenta = luni → duminica
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
// Săptămâna trecuta
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
        LEFT JOIN appointment_teams at
            ON at.team_id = tm.id
        LEFT JOIN appointments a
            ON a.id = at.appointment_id
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
| ZONA 1.5: EFICIENTA tehnicieni (săptămâna + luna, cu trend)
|--------------------------------------------------------------------------
| Capacitate FIXA per tehnician:
|   - Săptămâna: 40 ore (inclusiv weekend dacă lucreaza)
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
            LEFT JOIN appointment_teams at
                ON at.team_id = tm.id
            LEFT JOIN appointments a
                ON a.id = at.appointment_id
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

// Capacitati FIXE per tehnician
$capacityWeek  = 40.0;   // 40 ore / săptămâna
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
| ZONA 2: BACKLOG - sarcini de programat (întârziate + azi)
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
    error_log('dashboard intervenții facturare error: ' . $e->getMessage());
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
| Rezumat executiv + trenduri
|--------------------------------------------------------------------------
*/
$completedToday = 0;
$completedMonth = 0;
$monthAppointments = 0;
$lastMonthAppointments = 0;
$monthRevenue = 0.0;
$lastMonthRevenue = 0.0;
$monthRevenueBilled = 0.0;
$pvMissingCount = 0;
$topServices = [];
$dailyTrend = [];
$revenueTrend = [];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status = 'finalizata'");
    $stmt->execute([$today]);
    $completedToday = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status = 'finalizata'");
    $stmt->execute([$monthStart, $monthEnd]);
    $completedMonth = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status != 'anulata'");
    $stmt->execute([$monthStart, $monthEnd]);
    $monthAppointments = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status != 'anulata'");
    $stmt->execute([$lastMonthStart, $lastMonthEnd]);
    $lastMonthAppointments = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN billing_status != 'nu_se_factureaza' THEN billing_amount ELSE 0 END), 0)
        FROM appointments
        WHERE appointment_date BETWEEN ? AND ?
          AND status != 'anulata'
    ");
    $stmt->execute([$monthStart, $monthEnd]);
    $monthRevenue = (float)$stmt->fetchColumn();

    $stmt->execute([$lastMonthStart, $lastMonthEnd]);
    $lastMonthRevenue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(billing_amount), 0)
        FROM appointments
        WHERE appointment_date BETWEEN ? AND ?
          AND status = 'finalizata'
          AND billing_status = 'facturata'
    ");
    $stmt->execute([$monthStart, $monthEnd]);
    $monthRevenueBilled = (float)$stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('dashboard executive stats error: ' . $e->getMessage());
}

try {
    if (dash_table_exists($pdo, 'documents')) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM appointments a
            WHERE a.status = 'finalizata'
              AND NOT EXISTS (
                  SELECT 1
                  FROM documents d
                  WHERE d.appointment_id = a.id
                    AND d.document_type = 'proces_verbal'
                    AND d.status != 'cancelled'
              )
        ");
        $stmt->execute();
        $pvMissingCount = (int)$stmt->fetchColumn();
    }
} catch (Throwable $e) {
    error_log('dashboard pv missing error: ' . $e->getMessage());
}

try {
    $trendStart = date('Y-m-d', strtotime('-29 days'));
    $stmt = $pdo->prepare("
        SELECT appointment_date, COUNT(*) AS total
        FROM appointments
        WHERE appointment_date BETWEEN ? AND ?
          AND status != 'anulata'
        GROUP BY appointment_date
        ORDER BY appointment_date ASC
    ");
    $stmt->execute([$trendStart, $today]);
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDate[(string)$row['appointment_date']] = (int)$row['total'];
    }
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $dailyTrend[] = [
            'label' => date('d.m', strtotime($date)),
            'value' => $byDate[$date] ?? 0,
        ];
    }
} catch (Throwable $e) {
    error_log('dashboard daily trend error: ' . $e->getMessage());
}

try {
    for ($i = 5; $i >= 0; $i--) {
        $start = date('Y-m-01', strtotime("-{$i} months"));
        $end = date('Y-m-t', strtotime($start));
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN billing_status != 'nu_se_factureaza' THEN billing_amount ELSE 0 END), 0)
            FROM appointments
            WHERE appointment_date BETWEEN ? AND ?
              AND status != 'anulata'
        ");
        $stmt->execute([$start, $end]);
        $revenueTrend[] = [
            'label' => date('m.Y', strtotime($start)),
            'value' => (float)$stmt->fetchColumn(),
        ];
    }
} catch (Throwable $e) {
    error_log('dashboard revenue trend error: ' . $e->getMessage());
}

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(service_type, ''), 'Fără serviciu') AS service_name,
               COUNT(*) AS total,
               COALESCE(SUM(CASE WHEN billing_status != 'nu_se_factureaza' THEN billing_amount ELSE 0 END), 0) AS amount_total
        FROM appointments
        WHERE appointment_date BETWEEN ? AND ?
          AND status != 'anulata'
        GROUP BY service_name
        ORDER BY total DESC, amount_total DESC
        LIMIT 5
    ");
    $stmt->execute([$monthStart, $monthEnd]);
    $topServices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('dashboard top services error: ' . $e->getMessage());
}

$appointmentTrend = dash_percent_change((float)$monthAppointments, (float)$lastMonthAppointments);
$revenueTrendPercent = dash_percent_change($monthRevenue, $lastMonthRevenue);
$firstAppointment = $todayAppointments[0] ?? null;
$lastAppointment = $todayAppointments ? $todayAppointments[count($todayAppointments) - 1] : null;

$attentionItems = [];
if ($tasksOverdueCount > 0) {
    $attentionItems[] = ['tone' => 'danger', 'label' => 'Sarcini întârziate', 'value' => $tasksOverdueCount, 'href' => 'tasks.php'];
}
if ($tasksTodayCount > 0) {
    $attentionItems[] = ['tone' => 'warning', 'label' => 'Sarcini cu termen azi', 'value' => $tasksTodayCount, 'href' => 'tasks.php'];
}
if ($pvMissingCount > 0) {
    $attentionItems[] = ['tone' => 'warning', 'label' => 'Lucrări finalizate fără PV', 'value' => $pvMissingCount, 'href' => 'procese_verbale.php'];
}
if ($ibDue > 0) {
    $attentionItems[] = ['tone' => 'danger', 'label' => 'Intervenții de facturat', 'value' => $ibDue, 'href' => 'interventii_facturare.php?billing_status=de_facturat'];
}
if (!$attentionItems) {
    $attentionItems[] = ['tone' => 'success', 'label' => 'Nu sunt urgente operaționale', 'value' => 0, 'href' => 'calendar.php'];
}

$statusTone = 'success';
$statusTitle = 'Zi sub control';
$statusText = 'Fluxul operațional arată curat.';
if ($tasksOverdueCount > 0 || $pvMissingCount > 0 || $ibDue >= 10) {
    $statusTone = 'warning';
    $statusTitle = 'Necesită atenție';
    $statusText = 'Există câteva lucruri de închis înainte să se adune.';
}
if ($tasksOverdueCount >= 3 || $pvMissingCount >= 5 || $ibDue >= 20) {
    $statusTone = 'danger';
    $statusTitle = 'Presiune operațională';
    $statusText = 'Prioritatea este să închidem restanțele vizibile.';
}

/*
|--------------------------------------------------------------------------
| Briefing inteligent (chip-uri sus)
|--------------------------------------------------------------------------
*/
$hour = (int)date('H');
$greeting = $hour < 11 ? 'Buna dimineata' : ($hour < 18 ? 'Bună ziua' : 'Buna seara');
$dashboardUserName = function_exists('current_user_name') ? current_user_name() : 'Utilizator';

$briefingChips = [];
if ($teamsFree > 0 && $teamsTotal > 0) {
    $briefingChips[] = [
        'tone' => $teamsFree === $teamsTotal ? 'danger' : 'warning',
        'icon' => 'team',
        'text' => $teamsFree . ' ' . ($teamsFree === 1 ? 'tehnician liber' : 'tehnicieni liberi') . ' azi',
    ];
}
if ($backlogTotal > 0) {
    $briefingChips[] = [
        'tone' => $tasksOverdueCount > 0 ? 'danger' : 'warning',
        'icon' => 'tasks',
        'text' => $backlogTotal . ' de programat' . ($tasksOverdueCount > 0 ? ' (' . $tasksOverdueCount . ' întârziate)' : ''),
    ];
}
if ($ibDue > 0) {
    $briefingChips[] = [
        'tone' => 'warning',
        'icon' => 'invoice',
        'text' => $ibDue . ' intervenții de facturat',
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
    margin-bottom: 16px;
    padding: 18px 22px 18px;
    border-radius: 18px;
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
.dash-greeting h1, .dash-greeting h1 span { font-size: 23px; }
.dash-greeting h1 span { font-weight: 850; color: #002050; }
.dash-greeting .sub { margin-top: 7px; color: #39506f; font-size: 13px; font-weight: 650; line-height: 1.38; }
.dash-hero-status {
    min-width: 146px;
    padding: 8px 10px;
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
    margin-top: 14px;
}
.dash-kpi-card {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    gap: 9px;
    align-items: center;
    min-height: 70px;
    padding: 10px 12px;
    border-radius: 14px;
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
    width: 34px;
    height: 34px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--surface-soft);
    color: var(--accent);
}
.dash-kpi-icon svg { width: 17px; height: 17px; stroke-width: 2.1; fill: none; stroke: currentColor; }
.dash-kpi-label {
    display: block;
    color: var(--muted);
    font-size: 10.5px;
    font-weight: 850;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: 2px;
}
.dash-kpi-value {
    display: block;
    font-family: var(--mono);
    font-size: 21px;
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
    margin-top: 4px;
    font-size: 11px;
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
    margin-top: 12px;
}
.dash-hero-action {
    /* Identic ca geometrie cu butoanele din tasks.php: pill, 42px, text centrat */
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-width: 154px;
    height: 38px;
    min-height: 38px;
    padding: 0 18px;
    border-radius: 999px !important;
    background: rgba(255, 255, 255, .72) !important;
    text-decoration: none;
    font-size: 13px;
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
    font-size: 18px;
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
    /* Mobil: cele 3 actiuni principale raman obligatoriu pe aceeași linie */
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
    border-radius: 16px;
    box-shadow: 0 14px 28px -24px rgba(0,32,80,.28), inset 0 1px 0 rgba(255,255,255,.70);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: 390px;
    backdrop-filter: blur(12px) saturate(1.15);
    -webkit-backdrop-filter: blur(12px) saturate(1.15);
}
.panel-head { padding: 12px 16px; border-bottom: 1px solid var(--border2); display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-shrink: 0; }
.panel-title { font-size: 14px; font-weight: 800; color: var(--text); letter-spacing: -.01em; display: flex; align-items: center; gap: 8px; }
.panel-title .badge-count { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; padding: 0 7px; border-radius: 999px; background: var(--accent); color: #fff; font-size: 11px; font-weight: 800; }
.panel-title .badge-count.danger { background: var(--tone-danger); }
.panel-subtitle { font-size: 11.5px; color: var(--muted); font-weight: 600; margin-top: 2px; }
.panel-link { display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 9px; border: 1px solid var(--border); background: #fff; color: var(--text); font-size: 12px; font-weight: 700; text-decoration: none; transition: background .14s ease; }
.panel-link:hover { background: var(--surface-soft); border-color: var(--accent-soft-2); }
.panel-body { padding: 12px 16px; overflow-y: auto; flex: 1 1 auto; min-height: 0; }
.panel-body.tight { padding: 8px 10px; }
/* Scrollbar discret in interiorul cardurilor */
.panel-body::-webkit-scrollbar { width: 8px; }
.panel-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 999px; }
.panel-body::-webkit-scrollbar-thumb:hover { background: var(--accent-soft-2); }
.panel-body::-webkit-scrollbar-track { background: transparent; }

/* === ZONA ECHIPE - card mare full width cu tile-uri === */
.team-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 10px;
}
.team-tile {
    position: relative;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 11px;
    transition: border-color .14s ease, transform .12s ease;
}
.team-tile:hover { transform: translateY(-2px); border-color: var(--accent-soft-2); }
.team-tile.is-free   { border-color: rgba(180,83,9,.30); background: var(--tone-warning-bg); }
.team-tile.is-full   { border-color: rgba(4,120,87,.30); background: var(--tone-success-bg); }
.team-tile .tile-head { display: flex; align-items: center; gap: 9px; margin-bottom: 8px; }
.team-tile .tile-avatar { width: 29px; height: 29px; border-radius: 10px; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; flex-shrink: 0; }
.team-tile .tile-name { font-size: 14px; font-weight: 800; color: var(--text); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.team-tile .tile-status {
    font-size: 10.5px; font-weight: 800; padding: 3px 9px; border-radius: 999px;
    background: var(--surface-soft); color: var(--muted); white-space: nowrap;
    text-transform: uppercase; letter-spacing: .04em;
}
.team-tile.is-free .tile-status { background: var(--tone-warning); color: #fff; }
.team-tile.is-full .tile-status { background: var(--tone-success); color: #fff; }
.team-tile .tile-hours { font-size: 12px; font-weight: 700; color: var(--text); margin-bottom: 7px; }
.team-tile .tile-hours .hours-num { font-family: var(--mono); font-size: 17px; font-weight: 800; letter-spacing: -.02em; }
.team-tile .tile-hours .hours-cap { color: var(--muted); font-size: 12px; font-weight: 600; }
.team-tile .tile-bar { height: 7px; background: var(--surface-muted); border-radius: 999px; overflow: hidden; margin-bottom: 8px; }
.team-tile .tile-bar > span { display: block; height: 100%; background: var(--accent); border-radius: 999px; transition: width .25s ease; }
.team-tile.is-full .tile-bar > span { background: var(--tone-success); }
.team-tile.is-free .tile-bar > span { background: var(--tone-warning); }
.team-tile .tile-meta { font-size: 11px; color: var(--muted); font-weight: 600; margin-bottom: 8px; }
.team-tile .tile-meta strong { color: var(--text); font-weight: 800; }
.team-tile .tile-action { display: block; text-align: center; padding: 7px 11px; border-radius: 9px; background: var(--accent); color: #fff; font-size: 12px; font-weight: 700; text-decoration: none; transition: filter .14s ease; }
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

/* === EFICIENTA tehnicieni === */
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
        0 1px 0 rgba(0,32,80,.025),
        0 12px 26px -22px rgba(0,32,80,.34),
        0 6px 14px -14px rgba(15,23,42,.20) !important;
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
        0 2px 0 rgba(0,32,80,.035),
        0 18px 34px -26px rgba(0,32,80,.42),
        0 9px 18px -18px rgba(15,23,42,.24) !important;
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

/* === Dashboard executiv nou === */
.exec-dashboard {
    display: grid;
    gap: 16px;
}
.exec-hero {
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(177,214,240,.55);
    border-radius: 22px;
    padding: 22px;
    background:
        radial-gradient(circle at 88% 10%, rgba(177,214,240,.72), transparent 30%),
        linear-gradient(135deg, rgba(255,255,255,.95), rgba(236,244,251,.82));
    box-shadow: 0 26px 56px -38px rgba(0,32,80,.42), inset 0 1px 0 rgba(255,255,255,.86);
}
.exec-hero-top {
    display: flex;
    justify-content: space-between;
    gap: 18px;
    align-items: flex-start;
}
.exec-kicker {
    color: var(--accent);
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.exec-hero h1 {
    margin: 0;
    color: var(--text);
    font-size: 30px;
    line-height: 1.08;
    letter-spacing: 0;
    font-weight: 850;
}
.exec-hero p {
    margin: 8px 0 0;
    max-width: 720px;
    color: #40546f;
    font-size: 14px;
    line-height: 1.45;
    font-weight: 650;
}
.exec-status {
    min-width: 190px;
    border-radius: 16px;
    border: 1px solid rgba(148,163,184,.35);
    background: rgba(255,255,255,.78);
    padding: 12px 14px;
    text-align: right;
    box-shadow: 0 10px 24px -20px rgba(15,23,42,.35);
}
.exec-status strong {
    display: block;
    color: var(--text);
    font-size: 14px;
    font-weight: 900;
}
.exec-status span {
    display: block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
}
.exec-status::before {
    content: "";
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 999px;
    margin-right: 7px;
    background: var(--tone-success);
    box-shadow: 0 0 0 5px rgba(4,120,87,.12);
}
.exec-status.tone-warning::before { background: var(--tone-warning); box-shadow: 0 0 0 5px rgba(180,83,9,.13); }
.exec-status.tone-danger::before { background: var(--tone-danger); box-shadow: 0 0 0 5px rgba(220,38,38,.13); }
.exec-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 18px;
}
.exec-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 0 18px;
    border-radius: 999px;
    border: 1px solid rgba(0,32,80,.20);
    background: rgba(255,255,255,.78);
    color: var(--text);
    text-decoration: none;
    font-size: 13px;
    font-weight: 850;
}
.exec-action.primary {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}
.exec-kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
    margin-top: 18px;
}
.exec-kpi {
    min-height: 108px;
    border: 1px solid rgba(148,163,184,.26);
    border-radius: 16px;
    background: rgba(255,255,255,.84);
    padding: 14px;
    text-decoration: none;
    color: inherit;
    box-shadow: 0 14px 28px -24px rgba(0,32,80,.30);
}
.exec-kpi .label {
    color: var(--muted);
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .05em;
    text-transform: uppercase;
}
.exec-kpi .value {
    display: block;
    margin-top: 8px;
    color: var(--text);
    font-family: var(--mono);
    font-size: 27px;
    line-height: 1;
    font-weight: 900;
}
.exec-kpi .meta {
    display: block;
    margin-top: 8px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.3;
    font-weight: 700;
}
.exec-kpi.warning .value { color: var(--tone-warning); }
.exec-kpi.danger .value { color: var(--tone-danger); }
.exec-kpi.success .value { color: var(--tone-success); }
.exec-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 14px;
}
.exec-card {
    grid-column: span 6;
    border: 1px solid rgba(177,214,240,.45);
    border-radius: 18px;
    background: rgba(255,255,255,.90);
    box-shadow: 0 18px 38px -30px rgba(0,32,80,.34), inset 0 1px 0 rgba(255,255,255,.78);
    overflow: hidden;
}
.exec-card.wide { grid-column: span 12; }
.exec-card.third { grid-column: span 4; }
.exec-card-head {
    padding: 15px 16px 11px;
    border-bottom: 1px solid var(--border2);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.exec-card-title {
    margin: 0;
    color: var(--text);
    font-size: 16px;
    font-weight: 900;
    letter-spacing: 0;
}
.exec-card-sub {
    margin-top: 3px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 650;
}
.exec-card-link {
    white-space: nowrap;
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 7px 12px;
    background: #fff;
    color: var(--text);
    text-decoration: none;
    font-size: 12px;
    font-weight: 850;
}
.exec-card-body { padding: 14px 16px 16px; }
.today-brief {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}
.brief-item {
    border: 1px solid var(--border2);
    border-radius: 14px;
    background: var(--surface-soft);
    padding: 12px;
}
.brief-item .num {
    display: block;
    font-family: var(--mono);
    font-size: 24px;
    font-weight: 900;
    color: var(--text);
    line-height: 1;
}
.brief-item .txt {
    display: block;
    margin-top: 6px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 750;
}
.attention-list, .agenda-compact, .service-list {
    display: grid;
    gap: 8px;
}
.attention-row, .agenda-compact-row, .service-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
    border: 1px solid var(--border2);
    border-radius: 13px;
    background: #fff;
    padding: 11px 12px;
    text-decoration: none;
    color: inherit;
}
.attention-row strong, .agenda-compact-row strong, .service-row strong {
    color: var(--text);
    font-size: 13px;
    font-weight: 850;
}
.attention-row span, .agenda-compact-row span, .service-row span {
    display: block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 650;
}
.attention-value {
    min-width: 38px;
    height: 30px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--surface-soft);
    color: var(--text);
    font-family: var(--mono);
    font-weight: 900;
}
.attention-row.tone-danger .attention-value { background: var(--tone-danger-soft); color: var(--tone-danger); }
.attention-row.tone-warning .attention-value { background: var(--tone-warning-soft); color: var(--tone-warning); }
.attention-row.tone-success .attention-value { background: var(--tone-success-soft); color: var(--tone-success); }
.mini-chart {
    height: 210px;
    display: flex;
    align-items: flex-end;
    gap: 5px;
    padding: 8px 0 0;
}
.mini-bar {
    flex: 1;
    min-width: 4px;
    border-radius: 999px 999px 4px 4px;
    background: linear-gradient(180deg, #2f66c7, #8fb7f4);
    min-height: 6px;
}
.mini-bar.revenue { background: linear-gradient(180deg, #0f8c72, #9fe1d2); }
.chart-footer {
    display: flex;
    justify-content: space-between;
    margin-top: 8px;
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
}
.trend-chip {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    background: var(--surface-soft);
    color: var(--muted);
}
.trend-chip.up { background: var(--tone-success-soft); color: var(--tone-success); }
.trend-chip.down { background: var(--tone-danger-soft); color: var(--tone-danger); }
.agenda-time {
    font-family: var(--mono);
    font-size: 14px;
    font-weight: 900;
    color: var(--accent);
}
.empty-soft {
    border: 1px dashed var(--border);
    border-radius: 14px;
    padding: 18px;
    color: var(--muted);
    background: var(--surface-soft);
    font-size: 13px;
    font-weight: 700;
}
@media (max-width: 1180px) {
    .exec-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .exec-card, .exec-card.third { grid-column: span 12; }
    .today-brief { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 720px) {
    .exec-dashboard { gap: 10px; }
    .exec-hero {
        padding: 10px;
        border-radius: 8px;
        background: #fff;
        box-shadow: none;
    }
    .exec-hero-top {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
    }
    .exec-kicker { display: none; }
    .exec-hero h1 { font-size: 20px; line-height: 1.1; }
    .exec-hero p { display: none; }
    .exec-status {
        width: 100%;
        min-width: 0;
        text-align: left;
        border-radius: 8px;
        padding: 8px 9px;
        box-shadow: none;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 2px 8px;
        align-items: center;
    }
    .exec-status::before { display: none; }
    .exec-status strong { font-size: 12px; }
    .exec-status span { margin: 0; font-size: 11px; }
    .exec-status span:last-child { grid-column: 2; grid-row: 1 / span 2; align-self: center; }
    .exec-kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 7px;
        margin-top: 10px;
    }
    .exec-kpi {
        min-height: 66px;
        border-radius: 8px;
        padding: 9px;
        box-shadow: none;
    }
    .exec-kpi .label { font-size: 8.5px; letter-spacing: .045em; }
    .exec-kpi .value { margin-top: 5px; font-size: 19px; }
    .exec-kpi .meta { margin-top: 4px; font-size: 10px; line-height: 1.18; }
    .exec-kpi:last-child {
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 8px;
        min-height: 52px;
    }
    .exec-kpi:last-child .value { grid-column: 2; grid-row: 1 / span 2; margin: 0; font-size: 24px; }
    .exec-kpi:last-child .meta { margin-top: 2px; }
    .exec-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 7px;
        margin-top: 10px;
    }
    .exec-action {
        min-height: 32px;
        border-radius: 8px;
        padding: 0 10px;
        font-size: 12px;
    }
    .exec-action.primary { grid-column: 1 / -1; }
    .exec-grid { gap: 10px; }
    .exec-card {
        border-radius: 8px;
        box-shadow: none;
    }
    .exec-card-head { padding: 10px 11px 8px; gap: 8px; }
    .exec-card-title { font-size: 14px; }
    .exec-card-sub { font-size: 10.5px; }
    .exec-card-link {
        border-radius: 6px;
        padding: 6px 9px;
        font-size: 11px;
    }
    .exec-card-body { padding: 9px 11px 11px; }
    .today-brief {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 7px;
    }
    .brief-item {
        border-radius: 8px;
        padding: 9px;
    }
    .brief-item .num { font-size: 18px; }
    .brief-item .txt { margin-top: 4px; font-size: 10.5px; }
    .attention-list, .agenda-compact, .service-list { gap: 7px; }
    .attention-row, .agenda-compact-row, .service-row {
        border-radius: 8px;
        padding: 9px 10px;
    }
    .attention-row strong, .agenda-compact-row strong, .service-row strong { font-size: 12px; }
    .attention-row span, .agenda-compact-row span, .service-row span { font-size: 10.5px; }
    .attention-value {
        min-width: 26px;
        width: auto;
        height: 26px;
        border-radius: 7px;
        font-size: 11px;
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        margin: 0 !important;
        padding: 0 7px;
        line-height: 1;
    }
    .attention-row .attention-value {
        justify-self: end;
    }
    .mini-chart { height: 150px; gap: 4px; }
    .trend-chip { border-radius: 6px; padding: 4px 7px; font-size: 10.5px; }
    .empty-soft { border-radius: 8px; padding: 12px; font-size: 11.5px; }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('dashboard', $isAdmin); ?>
    <main class="main">
        <div class="content exec-dashboard">
            <section class="exec-hero">
                <div class="exec-hero-top">
                    <div>
                        <div class="exec-kicker">Dashboard operațional</div>
                        <h1><?= dash_h($greeting) ?>, <?= dash_h($dashboardUserName) ?></h1>
                        <p>Rezumat rapid pentru zi, lună și direcția firmei: ce merge bine, ce crește și ce trebuie închis înainte să se adune.</p>
                    </div>
                    <div class="exec-status tone-<?= dash_h($statusTone) ?>">
                        <strong><?= dash_h($statusTitle) ?></strong>
                        <span><?= dash_h($statusText) ?></span>
                        <span><?= date('d.m.Y') ?></span>
                    </div>
                </div>

                <div class="exec-kpis">
                    <a class="exec-kpi" href="calendar.php?date=<?= dash_h($today) ?>&view=day">
                        <span class="label">Programări azi</span>
                        <strong class="value"><?= (int)$appointmentsToday ?></strong>
                        <span class="meta"><?= (int)$completedToday ?> finalizate · <?= dash_h(number_format((float)$todayBookedHours, 1, ',', '.')) ?>h programate</span>
                    </a>
                    <a class="exec-kpi <?= $appointmentTrend !== null && $appointmentTrend >= 0 ? 'success' : 'warning' ?>" href="reports.php">
                        <span class="label">Programări lună</span>
                        <strong class="value"><?= (int)$monthAppointments ?></strong>
                        <span class="meta">
                            <?php if ($appointmentTrend === null): ?>
                                Fără termen de comparație
                            <?php else: ?>
                                <?= $appointmentTrend >= 0 ? '+' : '' ?><?= dash_h(number_format($appointmentTrend, 1, ',', '.')) ?>% față de luna trecută
                            <?php endif; ?>
                        </span>
                    </a>
                    <a class="exec-kpi <?= $revenueTrendPercent !== null && $revenueTrendPercent >= 0 ? 'success' : 'warning' ?>" href="interventii_facturare.php">
                        <span class="label">Valoare lunară</span>
                        <strong class="value"><?= dash_h(dash_format_money($monthRevenue)) ?></strong>
                        <span class="meta">
                            lei fără TVA ·
                            <?php if ($revenueTrendPercent === null): ?>
                                lună nouă
                            <?php else: ?>
                                <?= $revenueTrendPercent >= 0 ? '+' : '' ?><?= dash_h(number_format($revenueTrendPercent, 1, ',', '.')) ?>%
                            <?php endif; ?>
                        </span>
                    </a>
                    <a class="exec-kpi <?= $ibDue > 0 ? 'danger' : 'success' ?>" href="interventii_facturare.php?billing_status=de_facturat">
                        <span class="label">De facturat</span>
                        <strong class="value"><?= (int)$ibDue ?></strong>
                        <span class="meta"><?= dash_h(dash_format_money($ibDueAmount)) ?> lei în așteptare</span>
                    </a>
                    <a class="exec-kpi <?= $backlogTotal > 0 ? 'warning' : 'success' ?>" href="tasks.php">
                        <span class="label">De programat</span>
                        <strong class="value"><?= (int)$backlogTotal ?></strong>
                        <span class="meta"><?= (int)$tasksOverdueCount ?> întârziate · <?= (int)$tasksTodayCount ?> azi</span>
                    </a>
                </div>

                <div class="exec-actions">
                    <a class="exec-action primary" href="calendar.php?date=<?= dash_h($today) ?>&view=day&open_create=1">+ Programare</a>
                    <a class="exec-action" href="clients.php?open_create=1">+ Client</a>
                    <a class="exec-action" href="tasks.php?open_create=1">+ Sarcină</a>
                </div>
            </section>

            <section class="exec-grid">
                <article class="exec-card wide">
                    <div class="exec-card-head">
                        <div>
                            <h2 class="exec-card-title">Azi, pe scurt</h2>
                            <div class="exec-card-sub">Imaginea zilei fără să intri în calendar.</div>
                        </div>
                        <a class="exec-card-link" href="calendar.php?date=<?= dash_h($today) ?>&view=day">Calendar zi</a>
                    </div>
                    <div class="exec-card-body">
                        <div class="today-brief">
                            <div class="brief-item"><span class="num"><?= (int)$appointmentsToday ?></span><span class="txt">programări azi</span></div>
                            <div class="brief-item"><span class="num"><?= (int)$completedToday ?></span><span class="txt">lucrări finalizate</span></div>
                            <div class="brief-item"><span class="num"><?= $firstAppointment ? dash_h(dash_time($firstAppointment['start_time'] ?? null)) : '-' ?></span><span class="txt">prima programare</span></div>
                            <div class="brief-item"><span class="num"><?= $lastAppointment ? dash_h(dash_time($lastAppointment['start_time'] ?? null)) : '-' ?></span><span class="txt">ultima programare</span></div>
                        </div>
                    </div>
                </article>

                <article class="exec-card third">
                    <div class="exec-card-head">
                        <div>
                            <h2 class="exec-card-title">Ce necesită atenție</h2>
                            <div class="exec-card-sub">Lucrurile care merită închise primele.</div>
                        </div>
                    </div>
                    <div class="exec-card-body">
                        <div class="attention-list">
                            <?php foreach ($attentionItems as $item): ?>
                                <a class="attention-row tone-<?= dash_h($item['tone']) ?>" href="<?= dash_h($item['href']) ?>">
                                    <div>
                                        <strong><?= dash_h($item['label']) ?></strong>
                                        <span><?= $item['tone'] === 'success' ? 'Totul arată curat aici.' : 'Deschide lista și rezolvă punctual.' ?></span>
                                    </div>
                                    <span class="attention-value"><?= (int)$item['value'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </article>

                <article class="exec-card">
                    <div class="exec-card-head">
                        <div>
                            <h2 class="exec-card-title">Programări în ultimele 30 zile</h2>
                            <div class="exec-card-sub">Trend scurt pentru ritmul operațional.</div>
                        </div>
                        <?php
                            $chipClass = $appointmentTrend !== null && $appointmentTrend >= 0 ? 'up' : 'down';
                            $chipText = $appointmentTrend === null ? 'Nou' : (($appointmentTrend >= 0 ? '+' : '') . number_format($appointmentTrend, 1, ',', '.') . '%');
                        ?>
                        <span class="trend-chip <?= dash_h($chipClass) ?>"><?= dash_h($chipText) ?></span>
                    </div>
                    <div class="exec-card-body">
                        <?php $maxDaily = max(1, ...array_map(static fn($r) => (int)$r['value'], $dailyTrend)); ?>
                        <div class="mini-chart" aria-label="Programări ultimele 30 zile">
                            <?php foreach ($dailyTrend as $point): ?>
                                <?php $height = max(4, ((int)$point['value'] / $maxDaily) * 100); ?>
                                <span class="mini-bar" title="<?= dash_h($point['label']) ?>: <?= (int)$point['value'] ?>" style="height: <?= number_format($height, 2, '.', '') ?>%"></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="chart-footer"><span><?= dash_h($dailyTrend[0]['label'] ?? '') ?></span><span><?= dash_h($dailyTrend[count($dailyTrend)-1]['label'] ?? '') ?></span></div>
                    </div>
                </article>

                <article class="exec-card">
                    <div class="exec-card-head">
                        <div>
                            <h2 class="exec-card-title">Valoare lucrări</h2>
                            <div class="exec-card-sub">Estimare lunară pe baza valorilor din programări.</div>
                        </div>
                        <?php
                            $revChipClass = $revenueTrendPercent !== null && $revenueTrendPercent >= 0 ? 'up' : 'down';
                            $revChipText = $revenueTrendPercent === null ? 'Nou' : (($revenueTrendPercent >= 0 ? '+' : '') . number_format($revenueTrendPercent, 1, ',', '.') . '%');
                        ?>
                        <span class="trend-chip <?= dash_h($revChipClass) ?>"><?= dash_h($revChipText) ?></span>
                    </div>
                    <div class="exec-card-body">
                        <?php $maxRevenue = max(1, ...array_map(static fn($r) => (float)$r['value'], $revenueTrend)); ?>
                        <div class="mini-chart" aria-label="Valoare lucrări pe luni">
                            <?php foreach ($revenueTrend as $point): ?>
                                <?php $height = max(4, ((float)$point['value'] / $maxRevenue) * 100); ?>
                                <span class="mini-bar revenue" title="<?= dash_h($point['label']) ?>: <?= dash_h(dash_format_money((float)$point['value'])) ?> lei" style="height: <?= number_format($height, 2, '.', '') ?>%"></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="chart-footer"><span><?= dash_h($revenueTrend[0]['label'] ?? '') ?></span><span><?= dash_h($revenueTrend[count($revenueTrend)-1]['label'] ?? '') ?></span></div>
                    </div>
                </article>


                <article class="exec-card third">
                    <div class="exec-card-head">
                        <div>
                            <h2 class="exec-card-title">Top servicii luna asta</h2>
                            <div class="exec-card-sub">Ce tipuri de lucrări mișcă cel mai mult activitatea.</div>
                        </div>
                        <a class="exec-card-link" href="reports.php">Rapoarte</a>
                    </div>
                    <div class="exec-card-body">
                        <?php if (!$topServices): ?>
                            <div class="empty-soft">Încă nu există servicii programate luna aceasta.</div>
                        <?php else: ?>
                            <div class="service-list">
                                <?php foreach ($topServices as $service): ?>
                                    <div class="service-row">
                                        <div>
                                            <strong><?= dash_h($service['service_name'] ?? 'Serviciu') ?></strong>
                                            <span><?= (int)($service['total'] ?? 0) ?> lucrări · <?= dash_h(dash_format_money((float)($service['amount_total'] ?? 0))) ?> lei</span>
                                        </div>
                                        <span class="attention-value"><?= (int)($service['total'] ?? 0) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="exec-card wide">
                    <div class="exec-card-head">
                        <div>
                            <h2 class="exec-card-title">Agenda zilei</h2>
                            <div class="exec-card-sub">Programările importante de azi, în ordine cronologică.</div>
                        </div>
                        <a class="exec-card-link" href="calendar.php?date=<?= dash_h($today) ?>&view=day">Vezi calendar</a>
                    </div>
                    <div class="exec-card-body">
                        <?php if (!$todayAppointments): ?>
                            <div class="empty-soft">Nu există programări astăzi. Zi bună pentru recuperat restanțe și planificat lucrările următoare.</div>
                        <?php else: ?>
                            <div class="agenda-compact">
                                <?php foreach ($todayAppointments as $a): ?>
                                    <?php
                                        $startTime = dash_time($a['start_time'] ?? null);
                                        $status = (string)($a['status'] ?? 'confirmata');
                                    ?>
                                    <a class="agenda-compact-row" href="calendar.php?date=<?= dash_h($today) ?>&view=day">
                                        <div>
                                            <strong><span class="agenda-time"><?= dash_h($startTime) ?></span> · <?= dash_h($a['client_name'] ?: 'Client') ?></strong>
                                            <span><?= !empty($a['service_type']) ? dash_h($a['service_type']) . ' · ' : '' ?><?= dash_h($a['team_name'] ?? 'Fără tehnician') ?></span>
                                        </div>
                                        <span class="trend-chip"><?= dash_h(dash_status_label($status)) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            </section>


        </div>
    </main>
</div>
</body>
</html>
