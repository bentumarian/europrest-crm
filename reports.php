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
            // Nu blocam pagina dacă ALTER nu poate rula pe hosting.
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
        'neconfirmata' => 'Neconfirmată',
        'confirmata'   => 'Confirmată',
        'in_lucru'     => 'În lucru',
        'finalizata'   => 'Finalizată',
        'anulata'      => 'Anulată',
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
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
| Query programări
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

    $teamName = $appointment['team_name'] ?: 'Fără tehnician';
    if (!isset($teamCounts[$teamName])) {
        $teamCounts[$teamName] = 0;
    }
    $teamCounts[$teamName]++;

    $serviceName = $appointment['service_type'] ?: 'Fără serviciu';
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

$teamChoices = [['value' => 'all', 'label' => 'Toți tehnicienii']];
foreach ($teams as $team) {
    $teamChoices[] = ['value' => (string)$team['id'], 'label' => (string)$team['name']];
}

$serviceChoices = [['value' => 'all', 'label' => 'Toate serviciile']];
foreach ($services as $service) {
    $serviceChoices[] = ['value' => (string)$service['name'], 'label' => reports_short_service_label((string)$service['name'])];
}

$statusChoices = [
    ['value' => 'all', 'label' => 'Toate statusurile'],
    ['value' => 'confirmata', 'label' => 'Confirmată'],
    ['value' => 'finalizata', 'label' => 'Finalizată'],
    ['value' => 'anulata', 'label' => 'Anulată'],
    ['value' => 'neconfirmata', 'label' => 'Neconfirmată'],
];

function reports_choice_label(array $choices, string $selected): string {
    foreach ($choices as $choice) {
        if ((string)$choice['value'] === $selected) {
            return (string)$choice['label'];
        }
    }

    return (string)($choices[0]['label'] ?? '');
}

function reports_short_service_label(string $name): string {
    $name = trim($name);

    foreach (['(', ' - ', ' – ', ':'] as $separator) {
        $position = strpos($name, $separator);
        if ($position !== false && $position > 0) {
            return trim(substr($name, 0, $position));
        }
    }

    return $name;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Rapoarte - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

<style>
.reports-hero { background: var(--pz-brand) !important; color: #fff !important; border: none !important; box-shadow: none !important; border-radius: var(--pz-r) !important; }
.reports-hero h1, .reports-hero p { font-weight: 700 !important; }
.reports-hero p { color: rgba(255,255,255,.75) !important; }
.reports-choice-menu { box-shadow: none !important; border: 1px solid var(--pz-line) !important; border-radius: var(--pz-rs) !important; }
.kpi-card, .report-card, .table-card { box-shadow: none !important; border-radius: var(--pz-r) !important; }
.status-pill { border-radius: var(--pz-rs) !important; font-weight: 600 !important; }
.kpi-value { font-size: 26px !important; font-weight: 700 !important; }
.kpi-label, .report-card h2, .report-table th, .report-table td { font-weight: 700 !important; }
.report-table th { font-weight: 700 !important; font-size: 11px !important; }
.reports-choice-toggle { font-weight: 600 !important; border-radius: var(--pz-rs) !important; }
.reports-choice-option { font-weight: 600 !important; border-radius: var(--pz-rs) !important; }
* { font-family: 'Inter', system-ui, -apple-system, sans-serif !important; }
.reports-toolbar { width: 100%; min-width: 0; display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; position: relative; z-index: 81; overflow: visible; }
.reports-filters { width: 100%; min-width: 0; display: grid; grid-template-columns: 140px 140px minmax(130px, 1fr) minmax(150px, 1fr) minmax(130px, 1fr) auto; gap: 8px; align-items: center; position: relative; z-index: 82; overflow: visible; }
.reports-filters input, .reports-filters select { height: 42px; min-width: 0; font-weight: 800; }
.reports-filters select {
    appearance: none;
    -webkit-appearance: none;
    color: var(--text);
    border: 1px solid var(--border);
    background-color: var(--surface);
    background-image:
        linear-gradient(45deg, transparent 50%, var(--muted) 50%),
        linear-gradient(135deg, var(--muted) 50%, transparent 50%);
    background-position:
        calc(100% - 16px) 50%,
        calc(100% - 11px) 50%;
    background-size: 5px 5px, 5px 5px;
    background-repeat: no-repeat;
    padding-right: 28px;
    box-shadow: none;
}
.reports-filters select option {
    background: #FFFFFF;
    color: var(--text);
}
.reports-choice {
    position: relative;
    min-width: 0;
    z-index: 83;
}
.reports-choice.open { z-index: 100; }
.reports-choice-input {
    display: none;
}
.reports-choice-toggle {
    width: 100%;
    height: 42px;
    min-width: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 0 28px 0 10px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--surface);
    color: var(--text);
    box-shadow: none;
    font-size: 13px;
    font-weight: 800;
    line-height: 1;
    text-align: center;
}
.reports-choice-toggle::after {
    content: "";
    position: absolute;
    right: 12px;
    top: 50%;
    width: 8px;
    height: 8px;
    border-right: 2px solid var(--muted);
    border-bottom: 2px solid var(--muted);
    transform: translateY(-65%) rotate(45deg);
    pointer-events: none;
}
.reports-choice.open .reports-choice-toggle {
    border-color: var(--accent-pale);
    box-shadow: var(--focus-ring);
}
.reports-choice-menu {
    position: absolute;
    z-index: 120;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    display: none;
    max-height: 230px;
    overflow-y: auto;
    padding: 4px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    box-shadow: 0 14px 32px rgba(15, 23, 42, .14);
}
.reports-choice.open .reports-choice-menu {
    display: block;
}
.reports-choice-option {
    width: 100%;
    min-height: 32px;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding: 7px 9px;
    border: 0;
    border-radius: 4px;
    background: transparent;
    color: var(--text);
    box-shadow: none;
    font-size: 12px;
    font-weight: 800;
    text-align: left;
}
.reports-choice-option:hover,
.reports-choice-option.active {
    background: var(--surface-soft);
    color: var(--accent);
}
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
    .reports-topbar { width: 100% !important; max-width: 100vw !important; padding: 8px 10px 14px 10px !important; overflow: visible !important; display: block !important; position: relative !important; top: auto !important; z-index: 80 !important; }
    .reports-toolbar { width: 100% !important; max-width: 100% !important; min-width: 0 !important; display: block !important; overflow: visible !important; position: relative !important; z-index: 81 !important; }
    form.reports-filters, .reports-filters { width: 100% !important; max-width: 100% !important; min-width: 0 !important; display: grid !important; grid-template-columns: 1fr !important; gap: 8px !important; align-items: stretch !important; justify-items: stretch !important; margin: 0 auto !important; padding: 0 !important; overflow: visible !important; position: relative !important; z-index: 82 !important; }
    .reports-filters input, .reports-filters select, .reports-filters button, .reports-filters .btn, .reports-choice, .reports-choice-toggle { width: 100% !important; max-width: 100% !important; min-width: 0 !important; height: 42px !important; box-sizing: border-box !important; margin: 0 !important; text-align: center !important; justify-content: center !important; }
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
@media(max-width: 760px) {
    .reports-topbar { padding: 8px 10px 12px !important; }
    form.reports-filters, .reports-filters {
        grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
        gap: 6px !important;
    }
    .reports-filters input[type="date"] {
        grid-column: span 3;
    }
    .reports-choice {
        grid-column: span 2;
    }
    .reports-filters .btn {
        grid-column: span 6;
    }
    .reports-filters input,
    .reports-filters select,
    .reports-filters button,
    .reports-filters .btn,
    .reports-choice-toggle {
        height: 34px !important;
        min-height: 34px !important;
        border-radius: 4px !important;
        font-size: 11.5px !important;
        line-height: 1 !important;
        padding: 0 8px !important;
    }
    .reports-choice {
        height: auto !important;
    }
    .reports-choice-menu {
        position: absolute !important;
        top: calc(100% + 4px) !important;
        left: 0 !important;
        right: 0 !important;
        margin-top: 4px !important;
        max-height: 220px !important;
        box-shadow: 0 14px 32px rgba(15, 23, 42, .14) !important;
    }
    .reports-choice-option {
        height: auto !important;
        min-height: 32px !important;
        justify-content: flex-start !important;
        text-align: left !important;
    }
    .content {
        padding-left: 10px !important;
        padding-right: 10px !important;
    }
    .reports-hero {
        display: block !important;
        padding: 14px !important;
        margin-bottom: 10px !important;
        border-radius: 8px !important;
        background: var(--surface) !important;
        color: var(--text) !important;
        border: 1px solid var(--border) !important;
        box-shadow: none !important;
    }
    .reports-hero h1 {
        font-size: 21px !important;
        line-height: 1.1 !important;
        letter-spacing: 0 !important;
    }
    .reports-hero p {
        margin-top: 5px !important;
        font-size: 11.5px !important;
        line-height: 1.35 !important;
        color: var(--muted) !important;
    }
    .quick-range {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 6px !important;
        margin-bottom: 10px !important;
    }
    .quick-range .btn {
        height: 34px !important;
        min-height: 34px !important;
        border-radius: 4px !important;
        font-size: 11.5px !important;
        padding: 0 8px !important;
    }
    .kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px !important;
        margin-bottom: 10px !important;
    }
    .kpi-card {
        min-height: 82px !important;
        padding: 10px !important;
        border-radius: 8px !important;
        box-shadow: none !important;
    }
    .kpi-label {
        font-size: 9.5px !important;
        letter-spacing: 0 !important;
        line-height: 1.15 !important;
    }
    .kpi-value {
        margin-top: 4px !important;
        font-size: 23px !important;
        line-height: 1 !important;
        letter-spacing: 0 !important;
    }
    .kpi-sub {
        margin-top: 4px !important;
        font-size: 10.5px !important;
        line-height: 1.25 !important;
    }
    .report-grid {
        gap: 10px !important;
        margin-bottom: 10px !important;
    }
    .report-card {
        padding: 12px !important;
        border-radius: 8px !important;
        box-shadow: none !important;
    }
    .report-card h2 {
        font-size: 14px !important;
        margin-bottom: 10px !important;
    }
    .bar-row {
        grid-template-columns: 88px 1fr 28px !important;
        gap: 7px !important;
        margin-bottom: 7px !important;
    }
    .bar-label,
    .bar-value {
        font-size: 10.5px !important;
    }
    .bar-track {
        height: 7px !important;
    }
    .table-card {
        border-radius: 8px !important;
        box-shadow: none !important;
    }
    .report-table {
        min-width: 760px !important;
    }
    .report-table th {
        font-size: 9.5px !important;
        letter-spacing: 0 !important;
        padding: 8px !important;
    }
    .report-table td {
        font-size: 10.5px !important;
        padding: 8px !important;
    }
    .cell-muted,
    .note-cell {
        font-size: 10px !important;
        line-height: 1.25 !important;
    }
    .status-pill {
        border-radius: 4px !important;
        padding: 4px 6px !important;
        font-size: 10px !important;
    }
}
</style>
</head>

<body>
<div class="layout">

    <?php render_sidebar('reports', $isAdmin); ?>

    <main class="main">

        <div class="topbar reports-topbar">
            <div class="reports-toolbar">
                <form method="get" class="reports-filters">
                    <input type="date" name="date_from" value="<?= r_h($dateFrom) ?>" aria-label="Data început">
                    <input type="date" name="date_to" value="<?= r_h($dateTo) ?>" aria-label="Data final">

                    <div class="reports-choice" data-choice>
                        <input class="reports-choice-input" type="hidden" name="team" value="<?= r_h((string)$selectedTeam) ?>">
                        <button class="reports-choice-toggle" type="button" aria-haspopup="listbox" aria-expanded="false" data-static-label="1">
                            <span>Tehnicieni</span>
                        </button>
                        <div class="reports-choice-menu" role="listbox">
                            <?php foreach ($teamChoices as $choice): ?>
                                <button class="reports-choice-option <?= (string)$choice['value'] === (string)$selectedTeam ? 'active' : '' ?>" type="button" data-value="<?= r_h($choice['value']) ?>" data-label="<?= r_h($choice['label']) ?>">
                                    <?= r_h($choice['label']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="reports-choice" data-choice>
                        <input class="reports-choice-input" type="hidden" name="service" value="<?= r_h((string)$selectedService) ?>">
                        <button class="reports-choice-toggle" type="button" aria-haspopup="listbox" aria-expanded="false" data-static-label="1">
                            <span>Servicii</span>
                        </button>
                        <div class="reports-choice-menu" role="listbox">
                            <?php foreach ($serviceChoices as $choice): ?>
                                <button class="reports-choice-option <?= (string)$choice['value'] === (string)$selectedService ? 'active' : '' ?>" type="button" data-value="<?= r_h($choice['value']) ?>" data-label="<?= r_h($choice['label']) ?>">
                                    <?= r_h($choice['label']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="reports-choice" data-choice>
                        <input class="reports-choice-input" type="hidden" name="status" value="<?= r_h((string)$selectedStatus) ?>">
                        <button class="reports-choice-toggle" type="button" aria-haspopup="listbox" aria-expanded="false" data-static-label="1">
                            <span>Status</span>
                        </button>
                        <div class="reports-choice-menu" role="listbox">
                            <?php foreach ($statusChoices as $choice): ?>
                                <button class="reports-choice-option <?= (string)$choice['value'] === (string)$selectedStatus ? 'active' : '' ?>" type="button" data-value="<?= r_h($choice['value']) ?>" data-label="<?= r_h($choice['label']) ?>">
                                    <?= r_h($choice['label']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button class="btn accent" type="submit">Aplică</button>
                </form>
            </div>
        </div>

        <div class="content">

            <section class="reports-hero">
                <div>
                    <div class="pz-page-eyebrow">Rapoarte</div>
                    <h1>Rapoarte</h1>
                    <p>Analiză rapidă pentru programări, tehnicieni, servicii și sarcini.</p>
                </div>
            </section>

            <div class="quick-range">
                <a class="btn" href="reports.php?date_from=<?= r_h($today) ?>&date_to=<?= r_h($today) ?>&team=all&service=all&status=all">Azi</a>
                <a class="btn" href="reports.php?date_from=<?= r_h($currentMonthStart) ?>&date_to=<?= r_h($currentMonthEnd) ?>&team=all&service=all&status=all">Luna curentă</a>
                <a class="btn" href="reports.php?date_from=<?= r_h($prevMonthStart) ?>&date_to=<?= r_h($prevMonthEnd) ?>&team=all&service=all&status=all">Luna trecută</a>
                <a class="btn" href="reports.php?date_from=<?= r_h($currentYearStart) ?>&date_to=<?= r_h($currentYearEnd) ?>&team=all&service=all&status=all">An curent</a>
            </div>

            <section class="pz-kpi-grid">
                <div class="pz-kpi-card bl">
                    <div class="pz-kpi-label">Programări</div>
                    <div class="pz-kpi-value"><?= (int)$totalAppointments ?></div>
                    <div class="pz-kpi-sub mu">În perioada selectată</div>
                </div>
                <div class="pz-kpi-card gr">
                    <div class="pz-kpi-label">Finalizate</div>
                    <div class="pz-kpi-value"><?= (int)$completedAppointments ?></div>
                    <div class="pz-kpi-sub mu"><?= (int)$completionRate ?>% rată finalizare</div>
                </div>
                <div class="pz-kpi-card mu">
                    <div class="pz-kpi-label">Mențiuni finalizare</div>
                    <div class="pz-kpi-value"><?= (int)$withCompletionNotes ?></div>
                    <div class="pz-kpi-sub mu"><?= (int)$completionNotesRate ?>% din lucrări finalizate</div>
                </div>
                <div class="pz-kpi-card or">
                    <div class="pz-kpi-label">Sarcini active</div>
                    <div class="pz-kpi-value"><?= (int)$tasksTotal ?></div>
                    <div class="pz-kpi-sub mu"><?= (int)$tasksOverdue ?> întârziate</div>
                </div>
            </section>

            <section class="report-grid">
                <div class="report-card">
                    <h2>Programări pe tehnicieni</h2>
                    <?php if (!$teamCounts): ?>
                        <div class="empty-state">Nu există date.</div>
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
                    <h2>Programări pe servicii</h2>
                    <?php if (!$serviceCounts): ?>
                        <div class="empty-state">Nu există date.</div>
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
                    <h2>Status programări</h2>
                    <?php if (!$statusCounts): ?>
                        <div class="empty-state">Nu există date.</div>
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
                        <div class="bar-label">În perioada</div>
                        <div class="bar-track"><div class="bar-fill" style="width:<?= reports_percent($tasksThisPeriod, max(1, $tasksTotal)) ?>%"></div></div>
                        <div class="bar-value"><?= (int)$tasksThisPeriod ?></div>
                    </div>
                    <div class="bar-row">
                        <div class="bar-label">Întârziate</div>
                        <div class="bar-track"><div class="bar-fill" style="width:<?= reports_percent($tasksOverdue, max(1, $tasksTotal)) ?>%"></div></div>
                        <div class="bar-value"><?= (int)$tasksOverdue ?></div>
                    </div>
                </div>
            </section>

            <section class="table-card">
                <?php if (!$appointments): ?>
                    <div class="empty-state">Nu există programări pentru perioada selectată.</div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Ora</th>
                                    <th>Client</th>
                                    <th>Locație</th>
                                    <th>Contact</th>
                                    <th>Serviciu</th>
                                    <th>Tehnician</th>
                                    <th>Status</th>
                                    <th>Adresa</th>
                                    <th>Mențiuni birou</th>
                                    <th>Mențiuni finalizare</th>
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
<script>
document.querySelectorAll('[data-choice]').forEach((choice) => {
    const toggle = choice.querySelector('.reports-choice-toggle');
    const label = toggle ? toggle.querySelector('span') : null;
    const input = choice.querySelector('.reports-choice-input');
    const options = choice.querySelectorAll('.reports-choice-option');

    if (!toggle || !label || !input) {
        return;
    }

    toggle.addEventListener('click', () => {
        const isOpen = choice.classList.toggle('open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        document.querySelectorAll('[data-choice].open').forEach((other) => {
            if (other !== choice) {
                other.classList.remove('open');
                const otherToggle = other.querySelector('.reports-choice-toggle');
                if (otherToggle) {
                    otherToggle.setAttribute('aria-expanded', 'false');
                }
            }
        });
    });

    options.forEach((option) => {
        option.addEventListener('click', () => {
            input.value = option.dataset.value || '';
            if (!toggle.dataset.staticLabel) {
                label.textContent = option.dataset.label || option.textContent.trim();
            }
            options.forEach((item) => item.classList.remove('active'));
            option.classList.add('active');
            choice.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });
});

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-choice]')) {
        return;
    }
    document.querySelectorAll('[data-choice].open').forEach((choice) => {
        choice.classList.remove('open');
        const toggle = choice.querySelector('.reports-choice-toggle');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }
    });
});
</script>
</body>
</html>
