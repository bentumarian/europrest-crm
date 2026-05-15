<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();

if (!$isAdmin) {
    header("Location: calendar.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function r_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reports_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0) > 0;
}

function reports_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0) > 0;
}

function reports_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!reports_column_exists($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Nu blocam pagina daca ALTER nu poate rula pe hosting.
        }
    }
}

function reports_safe_date(?string $date, ?string $fallback = null): string {
    $fallback = $fallback ?: date('Y-m-d');

    if (!$date) {
        return $fallback;
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);

    return ($d && $d->format('Y-m-d') === $date) ? $date : $fallback;
}

function reports_status_label(string $status): string {
    return [
        'neconfirmata' => 'Neconfirmata',
        'confirmata'   => 'Confirmata',
        'in_lucru'     => 'In lucru',
        'finalizata'   => 'Finalizata',
        'anulata'      => 'Anulata',
    ][$status] ?? $status;
}

function reports_percent(int $value, int $total): int {
    if ($total <= 0) {
        return 0;
    }

    return (int)round(($value / $total) * 100);
}

function reports_effective_contact_person(array $appointment): string {
    $appointmentContact = trim((string)($appointment['contact_person'] ?? ''));
    if ($appointmentContact !== '') {
        return $appointmentContact;
    }

    $locationContact = trim((string)($appointment['location_contact_person'] ?? ''));
    if ($locationContact !== '') {
        return $locationContact;
    }

    $clientType = (string)($appointment['client_type'] ?? 'company');
    $clientName = trim((string)($appointment['client_name'] ?? ''));
    $legalRepresentative = trim((string)($appointment['client_legal_representative_name'] ?? ''));

    if ($clientType === 'individual') {
        return $clientName;
    }

    return $legalRepresentative !== '' ? $legalRepresentative : $clientName;
}

function reports_effective_contact_phone(array $appointment): string {
    $appointmentPhone = trim((string)($appointment['contact_phone'] ?? ''));
    if ($appointmentPhone !== '') {
        return $appointmentPhone;
    }

    $locationPhone = trim((string)($appointment['location_phone'] ?? ''));
    if ($locationPhone !== '') {
        return $locationPhone;
    }

    return trim((string)($appointment['client_phone'] ?? ''));
}

function reports_location_label(array $appointment): string {
    $locationName = trim((string)($appointment['location_name'] ?? ''));

    return $locationName !== '' ? $locationName : 'Sediu social / domiciliu';
}

function reports_effective_address(array $appointment): string {
    return trim((string)($appointment['address'] ?? ''))
        ?: (trim((string)($appointment['location_address'] ?? ''))
        ?: (trim((string)($appointment['client_registered_address'] ?? '')) ?: trim((string)($appointment['client_old_address'] ?? ''))));
}

/*
|--------------------------------------------------------------------------
| Schema minima
|--------------------------------------------------------------------------
*/
$pdo->exec("
    CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_type VARCHAR(20) NOT NULL DEFAULT 'company',
        name VARCHAR(180) NOT NULL,
        phone VARCHAR(60) NULL,
        email VARCHAR(160) NULL,
        address VARCHAR(255) NULL,
        registered_address VARCHAR(255) NULL,
        legal_representative_name VARCHAR(180) NULL,
        notes TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
reports_ensure_column($pdo, 'clients', 'client_type', "VARCHAR(20) NOT NULL DEFAULT 'company'");
reports_ensure_column($pdo, 'clients', 'phone', "VARCHAR(60) NULL");
reports_ensure_column($pdo, 'clients', 'email', "VARCHAR(160) NULL");
reports_ensure_column($pdo, 'clients', 'address', "VARCHAR(255) NULL");
reports_ensure_column($pdo, 'clients', 'registered_address', "VARCHAR(255) NULL");
reports_ensure_column($pdo, 'clients', 'legal_representative_name', "VARCHAR(180) NULL");
reports_ensure_column($pdo, 'clients', 'active', "TINYINT(1) NOT NULL DEFAULT 1");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS client_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        location_name VARCHAR(180) NOT NULL DEFAULT 'Punct de lucru',
        address VARCHAR(255) NULL,
        contact_person VARCHAR(180) NULL,
        phone VARCHAR(60) NULL,
        notes TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_locations_client_id (client_id),
        INDEX idx_client_locations_active (active)
    )
");
reports_ensure_column($pdo, 'client_locations', 'client_id', "INT NOT NULL");
reports_ensure_column($pdo, 'client_locations', 'location_name', "VARCHAR(180) NOT NULL DEFAULT 'Punct de lucru'");
reports_ensure_column($pdo, 'client_locations', 'address', "VARCHAR(255) NULL");
reports_ensure_column($pdo, 'client_locations', 'contact_person', "VARCHAR(180) NULL");
reports_ensure_column($pdo, 'client_locations', 'phone', "VARCHAR(60) NULL");
reports_ensure_column($pdo, 'client_locations', 'notes', "TEXT NULL");
reports_ensure_column($pdo, 'client_locations', 'active', "TINYINT(1) NOT NULL DEFAULT 1");
reports_ensure_column($pdo, 'client_locations', 'sort_order', "INT NOT NULL DEFAULT 0");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS team_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        phone VARCHAR(60) NULL,
        email VARCHAR(160) NULL,
        username VARCHAR(120) NULL,
        password_hash VARCHAR(255) NULL,
        color VARCHAR(20) NOT NULL DEFAULT '#163B63',
        active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
reports_ensure_column($pdo, 'team_members', 'color', "VARCHAR(20) NOT NULL DEFAULT '#163B63'");
reports_ensure_column($pdo, 'team_members', 'active', "TINYINT(1) NOT NULL DEFAULT 1");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        description TEXT NULL,
        default_duration INT NOT NULL DEFAULT 60,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
reports_ensure_column($pdo, 'services', 'active', "TINYINT(1) NOT NULL DEFAULT 1");
reports_ensure_column($pdo, 'services', 'sort_order', "INT NOT NULL DEFAULT 0");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NULL,
        client_location_id INT NULL,
        team_member_id INT NULL,
        title VARCHAR(255) NULL,
        service_type VARCHAR(150) NULL,
        appointment_date DATE NOT NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'confirmata',
        address VARCHAR(255) NULL,
        contact_person VARCHAR(180) NULL,
        contact_phone VARCHAR(60) NULL,
        notes TEXT NULL,
        completion_notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
reports_ensure_column($pdo, 'appointments', 'client_id', "INT NULL");
reports_ensure_column($pdo, 'appointments', 'client_location_id', "INT NULL");
reports_ensure_column($pdo, 'appointments', 'team_member_id', "INT NULL");
reports_ensure_column($pdo, 'appointments', 'title', "VARCHAR(255) NULL");
reports_ensure_column($pdo, 'appointments', 'service_type', "VARCHAR(150) NULL");
reports_ensure_column($pdo, 'appointments', 'appointment_date', "DATE NOT NULL");
reports_ensure_column($pdo, 'appointments', 'start_time', "TIME NULL");
reports_ensure_column($pdo, 'appointments', 'end_time', "TIME NULL");
reports_ensure_column($pdo, 'appointments', 'status', "VARCHAR(30) NOT NULL DEFAULT 'confirmata'");
reports_ensure_column($pdo, 'appointments', 'address', "VARCHAR(255) NULL");
reports_ensure_column($pdo, 'appointments', 'contact_person', "VARCHAR(180) NULL");
reports_ensure_column($pdo, 'appointments', 'contact_phone', "VARCHAR(60) NULL");
reports_ensure_column($pdo, 'appointments', 'notes', "TEXT NULL");
reports_ensure_column($pdo, 'appointments', 'completion_notes', "TEXT NULL AFTER notes");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NULL,
        client_location_id INT NULL,
        title VARCHAR(255) NULL,
        service_type VARCHAR(150) NULL,
        address VARCHAR(255) NULL,
        contact_person VARCHAR(180) NULL,
        contact_phone VARCHAR(60) NULL,
        due_date DATE NOT NULL,
        recurrence_type VARCHAR(40) NOT NULL DEFAULT 'none',
        recurrence_days INT NULL,
        recurrence_group VARCHAR(80) NULL,
        recurrence_total INT NOT NULL DEFAULT 1,
        recurrence_remaining INT NOT NULL DEFAULT 1,
        recurrence_stopped TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT 'de_programat',
        appointment_id INT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
reports_ensure_column($pdo, 'tasks', 'client_id', "INT NULL");
reports_ensure_column($pdo, 'tasks', 'client_location_id', "INT NULL");
reports_ensure_column($pdo, 'tasks', 'address', "VARCHAR(255) NULL");
reports_ensure_column($pdo, 'tasks', 'contact_person', "VARCHAR(180) NULL");
reports_ensure_column($pdo, 'tasks', 'contact_phone', "VARCHAR(60) NULL");
reports_ensure_column($pdo, 'tasks', 'recurrence_stopped', "TINYINT(1) NOT NULL DEFAULT 0");

/*
|--------------------------------------------------------------------------
| Filtre
|--------------------------------------------------------------------------
*/
$firstDayCurrentMonth = date('Y-m-01');
$lastDayCurrentMonth = date('Y-m-t');

$dateFrom = reports_safe_date($_GET['date_from'] ?? $firstDayCurrentMonth, $firstDayCurrentMonth);
$dateTo = reports_safe_date($_GET['date_to'] ?? $lastDayCurrentMonth, $lastDayCurrentMonth);

if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$selectedTeam = $_GET['team'] ?? 'all';
$selectedService = $_GET['service'] ?? 'all';
$selectedStatus = $_GET['status'] ?? 'all';

/*
|--------------------------------------------------------------------------
| Liste filtre
|--------------------------------------------------------------------------
*/
$teams = $pdo->query("
    SELECT id, name, color, active
    FROM team_members
    ORDER BY active DESC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$services = $pdo->query("
    SELECT id, name, active
    FROM services
    ORDER BY sort_order ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Query programari
|--------------------------------------------------------------------------
*/
$where = "
    WHERE a.appointment_date BETWEEN ? AND ?
";
$params = [$dateFrom, $dateTo];

if ($selectedTeam !== 'all' && ctype_digit((string)$selectedTeam)) {
    $where .= " AND a.team_member_id = ? ";
    $params[] = (int)$selectedTeam;
}

if ($selectedService !== 'all') {
    $where .= " AND a.service_type = ? ";
    $params[] = $selectedService;
}

if ($selectedStatus !== 'all') {
    $where .= " AND a.status = ? ";
    $params[] = $selectedStatus;
}

$stmt = $pdo->prepare("
    SELECT
        a.*,
        c.name AS client_name,
        c.phone AS client_phone,
        c.client_type AS client_type,
        c.registered_address AS client_registered_address,
        c.address AS client_old_address,
        c.legal_representative_name AS client_legal_representative_name,
        l.location_name,
        l.address AS location_address,
        l.contact_person AS location_contact_person,
        l.phone AS location_phone,
        t.name AS team_name,
        t.color AS team_color
    FROM appointments a
    LEFT JOIN clients c ON c.id = a.client_id
    LEFT JOIN client_locations l ON l.id = a.client_location_id
    LEFT JOIN team_members t ON t.id = a.team_member_id
    {$where}
    ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC
");
$stmt->execute($params);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Statistici
|--------------------------------------------------------------------------
*/
$totalAppointments = count($appointments);
$completedAppointments = 0;
$cancelledAppointments = 0;
$activeAppointments = 0;
$withCompletionNotes = 0;
$uniqueClients = [];
$statusCounts = [];
$teamCounts = [];
$serviceCounts = [];

foreach ($appointments as $appointment) {
    $status = $appointment['status'] ?? 'confirmata';

    if (!isset($statusCounts[$status])) {
        $statusCounts[$status] = 0;
    }
    $statusCounts[$status]++;

    if ($status === 'finalizata') {
        $completedAppointments++;
    } elseif ($status === 'anulata') {
        $cancelledAppointments++;
    } else {
        $activeAppointments++;
    }

    if (trim((string)($appointment['completion_notes'] ?? '')) !== '') {
        $withCompletionNotes++;
    }

    if (!empty($appointment['client_id'])) {
        $uniqueClients[(int)$appointment['client_id']] = true;
    }

    $teamName = $appointment['team_name'] ?: 'Fara echipa';
    if (!isset($teamCounts[$teamName])) {
        $teamCounts[$teamName] = 0;
    }
    $teamCounts[$teamName]++;

    $serviceName = $appointment['service_type'] ?: 'Fara serviciu';
    if (!isset($serviceCounts[$serviceName])) {
        $serviceCounts[$serviceName] = 0;
    }
    $serviceCounts[$serviceName]++;
}

arsort($teamCounts);
arsort($serviceCounts);
arsort($statusCounts);

$completionRate = reports_percent($completedAppointments, $totalAppointments);
$completionNotesRate = reports_percent($withCompletionNotes, max(1, $completedAppointments));

$tasksTotal = 0;
$tasksOverdue = 0;
$tasksThisPeriod = 0;

if (reports_table_exists($pdo, 'tasks')) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM tasks
        WHERE status IN ('de_programat', 'contactat', 'amanat')
          AND recurrence_stopped = 0
    ");
    $stmt->execute();
    $tasksTotal = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM tasks
        WHERE status IN ('de_programat', 'contactat', 'amanat')
          AND recurrence_stopped = 0
          AND due_date < ?
    ");
    $stmt->execute([date('Y-m-d')]);
    $tasksOverdue = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM tasks
        WHERE status IN ('de_programat', 'contactat', 'amanat')
          AND recurrence_stopped = 0
          AND due_date BETWEEN ? AND ?
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $tasksThisPeriod = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Linkuri rapide
|--------------------------------------------------------------------------
*/
$today = date('Y-m-d');
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');
$prevMonthStart = date('Y-m-01', strtotime('first day of previous month'));
$prevMonthEnd = date('Y-m-t', strtotime('last day of previous month'));
$currentYearStart = date('Y-01-01');
$currentYearEnd = date('Y-12-31');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Rapoarte - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

<style>
.reports-topbar { align-items: center; padding: 12px 20px; }
.reports-toolbar { width: 100%; min-width: 0; display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; }
.reports-filters { width: 100%; min-width: 0; display: grid; grid-template-columns: 140px 140px minmax(130px, 1fr) minmax(150px, 1fr) minmax(130px, 1fr) auto; gap: 8px; align-items: center; }
.reports-filters input, .reports-filters select { height: 42px; min-width: 0; font-weight: 800; }
.reports-filters .btn { height: 42px; white-space: nowrap; }
.reports-hero { background: linear-gradient(135deg, #10243E, #163B63); color: #fff; border-radius: var(--radius-lg); padding: 22px 24px; box-shadow: var(--shadow-lg); margin-bottom: 16px; display: flex; justify-content: space-between; gap: 18px; flex-wrap: wrap; align-items: center; }
.reports-hero h1 { font-size: 24px; font-weight: 900; letter-spacing: -.03em; margin: 0; }
.reports-hero p { color: rgba(255, 255, 255, .72); margin: 4px 0 0; max-width: 780px; }
.quick-range { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
.kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 16px; }
.kpi-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow); padding: 16px; }
.kpi-label { font-size: 12px; color: var(--muted); font-weight: 900; text-transform: uppercase; letter-spacing: .05em; }
.kpi-value { margin-top: 8px; font-size: 30px; font-weight: 900; color: var(--text); letter-spacing: -.04em; }
.kpi-sub { margin-top: 4px; color: var(--muted); font-size: 13px; font-weight: 700; }
.report-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
.report-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow); padding: 16px; }
.report-card h2 { font-size: 17px; font-weight: 900; color: var(--text); margin: 0 0 14px; }
.bar-row { display: grid; grid-template-columns: 150px 1fr 46px; align-items: center; gap: 10px; margin-bottom: 10px; }
.bar-label { font-size: 13px; color: var(--text); font-weight: 800; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.bar-track { height: 10px; background: var(--surface-soft); border: 1px solid var(--border2); border-radius: 999px; overflow: hidden; }
.bar-fill { height: 100%; background: var(--accent); border-radius: 999px; }
.bar-value { font-size: 13px; color: var(--muted); font-weight: 900; text-align: right; }
.table-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow); overflow: hidden; }
.table-scroll { width: 100%; max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.report-table { width: 100%; min-width: 1320px; border-collapse: collapse; }
.report-table th { text-align: left; font-size: 12px; color: var(--muted); font-weight: 900; text-transform: uppercase; letter-spacing: .04em; padding: 14px 16px; border-bottom: 1px solid var(--border); background: var(--surface-soft); }
.report-table td { padding: 13px 16px; border-bottom: 1px solid var(--border2); color: var(--text); font-size: 14px; vertical-align: top; }
.report-table tr:last-child td { border-bottom: none; }
.status-pill { display: inline-flex; align-items: center; padding: 5px 9px; border-radius: 999px; background: var(--surface-soft); border: 1px solid var(--border2); color: var(--text); font-size: 12px; font-weight: 900; }
.cell-muted { color: var(--muted); font-size: 13px; margin-top: 3px; line-height: 1.35; }
.note-cell { max-width: 260px; color: var(--text); font-size: 13px; line-height: 1.4; }
.note-cell.empty { color: var(--muted); }
.empty-state { padding: 34px; text-align: center; color: var(--muted); font-weight: 800; }
@media(max-width: 1100px) { .reports-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); } .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media(max-width: 860px) {
    body { overflow-x: hidden !important; }
    .reports-topbar { width: 100% !important; max-width: 100vw !important; padding: 8px 10px 14px 10px !important; overflow-x: hidden !important; display: block !important; position: relative !important; top: auto !important; }
    .reports-toolbar { width: 100% !important; max-width: 100% !important; min-width: 0 !important; display: block !important; overflow-x: hidden !important; }
    form.reports-filters, .reports-filters { width: 100% !important; max-width: 100% !important; min-width: 0 !important; display: grid !important; grid-template-columns: 1fr !important; gap: 8px !important; align-items: stretch !important; justify-items: stretch !important; margin: 0 auto !important; padding: 0 !important; overflow-x: hidden !important; }
    .reports-filters input, .reports-filters select, .reports-filters button, .reports-filters .btn { width: 100% !important; max-width: 100% !important; min-width: 0 !important; height: 42px !important; box-sizing: border-box !important; margin: 0 !important; text-align: center !important; justify-content: center !important; }
    .reports-filters input[type="date"] { display: block !important; appearance: none !important; -webkit-appearance: none !important; inline-size: 100% !important; min-inline-size: 0 !important; max-inline-size: 100% !important; padding-left: 12px !important; padding-right: 12px !important; overflow: hidden !important; text-align: center !important; background: var(--surface) !important; }
    .reports-filters input[type="date"]::-webkit-date-and-time-value { text-align: center !important; width: 100% !important; margin: 0 auto !important; }
    .reports-filters input[type="date"]::-webkit-calendar-picker-indicator { display: none !important; opacity: 0 !important; }
    .content { width: 100% !important; max-width: 100vw !important; overflow-x: hidden !important; }
    .reports-hero { padding: 18px; }
    .quick-range { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .quick-range .btn { width: 100%; min-width: 0; }
    .report-grid { grid-template-columns: 1fr; }
}
@media(max-width: 620px) { .kpi-grid { grid-template-columns: 1fr; } .quick-range { grid-template-columns: repeat(2, minmax(0, 1fr)); } .bar-row { grid-template-columns: 110px 1fr 38px; } }
@media(max-width: 420px) { .quick-range { grid-template-columns: 1fr 1fr; } .quick-range .btn { font-size: 13px; padding-left: 8px; padding-right: 8px; } }
</style>
</head>

<body>
<div class="layout">

    <?php render_sidebar('reports', $isAdmin); ?>

    <main class="main">

        <div class="topbar reports-topbar">
            <div class="reports-toolbar">
                <form method="get" class="reports-filters">
                    <input type="date" name="date_from" value="<?= r_h($dateFrom) ?>" aria-label="Data inceput">
                    <input type="date" name="date_to" value="<?= r_h($dateTo) ?>" aria-label="Data final">

                    <select name="team" aria-label="Echipa">
                        <option value="all" <?= $selectedTeam === 'all' ? 'selected' : '' ?>>Toate echipele</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= (int)$team['id'] ?>" <?= (string)$selectedTeam === (string)$team['id'] ? 'selected' : '' ?>><?= r_h($team['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="service" aria-label="Serviciu">
                        <option value="all" <?= $selectedService === 'all' ? 'selected' : '' ?>>Toate serviciile</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?= r_h($service['name']) ?>" <?= $selectedService === $service['name'] ? 'selected' : '' ?>><?= r_h($service['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status" aria-label="Status">
                        <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>Toate statusurile</option>
                        <option value="confirmata" <?= $selectedStatus === 'confirmata' ? 'selected' : '' ?>>Confirmata</option>
                        <option value="finalizata" <?= $selectedStatus === 'finalizata' ? 'selected' : '' ?>>Finalizata</option>
                        <option value="anulata" <?= $selectedStatus === 'anulata' ? 'selected' : '' ?>>Anulata</option>
                        <option value="neconfirmata" <?= $selectedStatus === 'neconfirmata' ? 'selected' : '' ?>>Neconfirmata</option>
                    </select>

                    <button class="btn accent" type="submit">Aplica</button>
                </form>
            </div>
        </div>

        <div class="content">

            <section class="reports-hero">
                <div>
                    <h1>Rapoarte</h1>
                    <p>Analiza programarilor, echipelor, serviciilor, locatiilor, instructiunilor biroului si mentiunilor de finalizare.</p>
                </div>
            </section>

            <div class="quick-range">
                <a class="btn" href="reports.php?date_from=<?= r_h($today) ?>&date_to=<?= r_h($today) ?>&team=all&service=all&status=all">Azi</a>
                <a class="btn" href="reports.php?date_from=<?= r_h($currentMonthStart) ?>&date_to=<?= r_h($currentMonthEnd) ?>&team=all&service=all&status=all">Luna curenta</a>
                <a class="btn" href="reports.php?date_from=<?= r_h($prevMonthStart) ?>&date_to=<?= r_h($prevMonthEnd) ?>&team=all&service=all&status=all">Luna trecuta</a>
                <a class="btn" href="reports.php?date_from=<?= r_h($currentYearStart) ?>&date_to=<?= r_h($currentYearEnd) ?>&team=all&service=all&status=all">An curent</a>
            </div>

            <section class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Programari</div>
                    <div class="kpi-value"><?= (int)$totalAppointments ?></div>
                    <div class="kpi-sub">In perioada selectata</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Finalizate</div>
                    <div class="kpi-value"><?= (int)$completedAppointments ?></div>
                    <div class="kpi-sub"><?= (int)$completionRate ?>% rata finalizare</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Mentiuni finalizare</div>
                    <div class="kpi-value"><?= (int)$withCompletionNotes ?></div>
                    <div class="kpi-sub"><?= (int)$completionNotesRate ?>% din lucrari finalizate</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Sarcini active</div>
                    <div class="kpi-value"><?= (int)$tasksTotal ?></div>
                    <div class="kpi-sub"><?= (int)$tasksOverdue ?> intarziate</div>
                </div>
            </section>

            <section class="report-grid">
                <div class="report-card">
                    <h2>Programari pe echipe</h2>
                    <?php if (!$teamCounts): ?>
                        <div class="empty-state">Nu exista date.</div>
                    <?php else: ?>
                        <?php foreach ($teamCounts as $label => $count): ?>
                            <?php $pct = reports_percent((int)$count, $totalAppointments); ?>
                            <div class="bar-row">
                                <div class="bar-label"><?= r_h($label) ?></div>
                                <div class="bar-track"><div class="bar-fill" style="width:<?= (int)$pct ?>%"></div></div>
                                <div class="bar-value"><?= (int)$count ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="report-card">
                    <h2>Programari pe servicii</h2>
                    <?php if (!$serviceCounts): ?>
                        <div class="empty-state">Nu exista date.</div>
                    <?php else: ?>
                        <?php foreach ($serviceCounts as $label => $count): ?>
                            <?php $pct = reports_percent((int)$count, $totalAppointments); ?>
                            <div class="bar-row">
                                <div class="bar-label"><?= r_h($label) ?></div>
                                <div class="bar-track"><div class="bar-fill" style="width:<?= (int)$pct ?>%"></div></div>
                                <div class="bar-value"><?= (int)$count ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="report-card">
                    <h2>Status programari</h2>
                    <?php if (!$statusCounts): ?>
                        <div class="empty-state">Nu exista date.</div>
                    <?php else: ?>
                        <?php foreach ($statusCounts as $label => $count): ?>
                            <?php $pct = reports_percent((int)$count, $totalAppointments); ?>
                            <div class="bar-row">
                                <div class="bar-label"><?= r_h(reports_status_label($label)) ?></div>
                                <div class="bar-track"><div class="bar-fill" style="width:<?= (int)$pct ?>%"></div></div>
                                <div class="bar-value"><?= (int)$count ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="report-card">
                    <h2>Sarcini birou</h2>
                    <div class="bar-row">
                        <div class="bar-label">Active total</div>
                        <div class="bar-track"><div class="bar-fill" style="width:100%"></div></div>
                        <div class="bar-value"><?= (int)$tasksTotal ?></div>
                    </div>
                    <div class="bar-row">
                        <div class="bar-label">In perioada</div>
                        <div class="bar-track"><div class="bar-fill" style="width:<?= reports_percent($tasksThisPeriod, max(1, $tasksTotal)) ?>%"></div></div>
                        <div class="bar-value"><?= (int)$tasksThisPeriod ?></div>
                    </div>
                    <div class="bar-row">
                        <div class="bar-label">Intarziate</div>
                        <div class="bar-track"><div class="bar-fill" style="width:<?= reports_percent($tasksOverdue, max(1, $tasksTotal)) ?>%"></div></div>
                        <div class="bar-value"><?= (int)$tasksOverdue ?></div>
                    </div>
                </div>
            </section>

            <section class="table-card">
                <?php if (!$appointments): ?>
                    <div class="empty-state">Nu exista programari pentru perioada selectata.</div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Ora</th>
                                    <th>Client</th>
                                    <th>Locatie</th>
                                    <th>Contact</th>
                                    <th>Serviciu</th>
                                    <th>Echipa</th>
                                    <th>Status</th>
                                    <th>Adresa</th>
                                    <th>Mentiuni birou</th>
                                    <th>Mentiuni finalizare</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($appointments as $appointment): ?>
                                    <?php
                                        $contactPerson = reports_effective_contact_person($appointment);
                                        $contactPhone = reports_effective_contact_phone($appointment);
                                        $locationName = reports_location_label($appointment);
                                        $address = reports_effective_address($appointment);
                                        $officeNotes = trim((string)($appointment['notes'] ?? ''));
                                        $completionNotes = trim((string)($appointment['completion_notes'] ?? ''));
                                    ?>
                                    <tr>
                                        <td><?= r_h($appointment['appointment_date']) ?></td>
                                        <td><?= r_h(substr((string)$appointment['start_time'], 0, 5)) ?></td>
                                        <td>
                                            <strong><?= r_h($appointment['client_name'] ?: 'Client') ?></strong>
                                            <?php if (!empty($appointment['client_phone'])): ?>
                                                <div class="cell-muted">Tel general: <?= r_h($appointment['client_phone']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= r_h($locationName) ?></strong>
                                            <?php if (!empty($appointment['client_location_id'])): ?>
                                                <div class="cell-muted">Punct de lucru</div>
                                            <?php else: ?>
                                                <div class="cell-muted">Sediu / domiciliu</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= r_h($contactPerson ?: '-') ?></strong>
                                            <?php if ($contactPhone !== ''): ?>
                                                <div class="cell-muted"><?= r_h($contactPhone) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= r_h($appointment['service_type'] ?: '-') ?></td>
                                        <td><?= r_h($appointment['team_name'] ?: '-') ?></td>
                                        <td>
                                            <span class="status-pill"><?= r_h(reports_status_label($appointment['status'] ?? 'confirmata')) ?></span>
                                        </td>
                                        <td><?= r_h($address ?: '-') ?></td>
                                        <td>
                                            <div class="note-cell <?= $officeNotes === '' ? 'empty' : '' ?>">
                                                <?= $officeNotes !== '' ? nl2br(r_h($officeNotes)) : '-' ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="note-cell <?= $completionNotes === '' ? 'empty' : '' ?>">
                                                <?= $completionNotes !== '' ? nl2br(r_h($completionNotes)) : '-' ?>
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
