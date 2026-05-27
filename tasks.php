<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'task_recurrence.php';

ensure_task_recurrence_schema($pdo);

$isAdmin = is_admin();

if (!$isAdmin) {
    header("Location: calendar.php");
    exit;
}

// Helper-i extracși în tasks_helpers.php (15 funcții).
require_once __DIR__ . '/tasks_helpers.php';

// h() este definit global în app_helpers.php (inclus prin app_ui.php).

/*
|--------------------------------------------------------------------------
| Tabele necesare
|--------------------------------------------------------------------------
*/
$pdo->exec("
    CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_type VARCHAR(20) NOT NULL DEFAULT 'company',
        name VARCHAR(180) NOT NULL,
        fiscal_code VARCHAR(30) NULL,
        registry_number VARCHAR(100) NULL,
        registered_address VARCHAR(255) NULL,
        legal_representative_name VARCHAR(180) NULL,
        legal_representative_role VARCHAR(120) NULL,
        bank_name VARCHAR(160) NULL,
        bank_account VARCHAR(80) NULL,
        phone VARCHAR(60) NULL,
        email VARCHAR(160) NULL,
        address VARCHAR(255) NULL,
        notes TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

ensure_column_tasks($pdo, 'clients', 'client_type', "VARCHAR(20) NOT NULL DEFAULT 'company'");
ensure_column_tasks($pdo, 'clients', 'registered_address', "VARCHAR(255) NULL");
ensure_column_tasks($pdo, 'clients', 'legal_representative_name', "VARCHAR(180) NULL");
ensure_column_tasks($pdo, 'clients', 'address', "VARCHAR(255) NULL");
ensure_column_tasks($pdo, 'clients', 'phone', "VARCHAR(60) NULL");
ensure_column_tasks($pdo, 'clients', 'email', "VARCHAR(160) NULL");
ensure_column_tasks($pdo, 'clients', 'active', "TINYINT(1) NOT NULL DEFAULT 1");

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

ensure_column_tasks($pdo, 'client_locations', 'client_id', "INT NOT NULL");
ensure_column_tasks($pdo, 'client_locations', 'location_name', "VARCHAR(180) NOT NULL DEFAULT 'Punct de lucru'");
ensure_column_tasks($pdo, 'client_locations', 'address', "VARCHAR(255) NULL");
ensure_column_tasks($pdo, 'client_locations', 'contact_person', "VARCHAR(180) NULL");
ensure_column_tasks($pdo, 'client_locations', 'phone', "VARCHAR(60) NULL");
ensure_column_tasks($pdo, 'client_locations', 'notes', "TEXT NULL");
ensure_column_tasks($pdo, 'client_locations', 'active', "TINYINT(1) NOT NULL DEFAULT 1");
ensure_column_tasks($pdo, 'client_locations', 'sort_order', "INT NOT NULL DEFAULT 0");
ensure_column_tasks($pdo, 'client_locations', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

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
        recurrence_index INT NOT NULL DEFAULT 1,
        generated_from_task_id INT NULL,
        generated_next_task_id INT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'de_programat',
        appointment_id INT NULL,
        notes TEXT NULL,
        skipped_at DATETIME NULL,
        skipped_by INT NULL,
        skipped_reason TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

ensure_column_tasks($pdo, 'tasks', 'client_id', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'client_location_id', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'title', "VARCHAR(255) NULL");
ensure_column_tasks($pdo, 'tasks', 'service_type', "VARCHAR(150) NULL");
ensure_column_tasks($pdo, 'tasks', 'address', "VARCHAR(255) NULL");
ensure_column_tasks($pdo, 'tasks', 'contact_person', "VARCHAR(180) NULL");
ensure_column_tasks($pdo, 'tasks', 'contact_phone', "VARCHAR(60) NULL");
ensure_column_tasks($pdo, 'tasks', 'due_date', "DATE NOT NULL");
ensure_column_tasks($pdo, 'tasks', 'recurrence_type', "VARCHAR(40) NOT NULL DEFAULT 'none'");
ensure_column_tasks($pdo, 'tasks', 'recurrence_days', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'recurrence_group', "VARCHAR(80) NULL");
ensure_column_tasks($pdo, 'tasks', 'recurrence_total', "INT NOT NULL DEFAULT 1");
ensure_column_tasks($pdo, 'tasks', 'recurrence_remaining', "INT NOT NULL DEFAULT 1");
ensure_column_tasks($pdo, 'tasks', 'recurrence_stopped', "TINYINT(1) NOT NULL DEFAULT 0");
ensure_column_tasks($pdo, 'tasks', 'recurrence_index', "INT NOT NULL DEFAULT 1");
ensure_column_tasks($pdo, 'tasks', 'generated_from_task_id', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'generated_next_task_id', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'status', "VARCHAR(40) NOT NULL DEFAULT 'de_programat'");
ensure_column_tasks($pdo, 'tasks', 'appointment_id', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'contract_id', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'contract_service_id', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'service_id', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'location_name', "VARCHAR(220) NULL");
ensure_column_tasks($pdo, 'tasks', 'surface_value', "DECIMAL(14,3) NULL");
ensure_column_tasks($pdo, 'tasks', 'surface_unit', "VARCHAR(30) NULL");
ensure_column_tasks($pdo, 'tasks', 'billing_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
ensure_column_tasks($pdo, 'tasks', 'currency', "VARCHAR(10) NOT NULL DEFAULT 'RON'");
ensure_column_tasks($pdo, 'tasks', 'document_id', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'document_item_id', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'notes', "TEXT NULL");
ensure_column_tasks($pdo, 'tasks', 'skipped_at', "DATETIME NULL");
ensure_column_tasks($pdo, 'tasks', 'skipped_by', "INT NULL");
ensure_column_tasks($pdo, 'tasks', 'skipped_reason', "TEXT NULL");
ensure_column_tasks($pdo, 'tasks', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

/*
|--------------------------------------------------------------------------
| Servicii active
|--------------------------------------------------------------------------
*/
$activeServices = [];

if (table_exists_tasks($pdo, 'services')) {
    $activeServices = $pdo->query("
        SELECT id, name, default_duration
        FROM services
        WHERE active = 1
        ORDER BY sort_order ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if (!$activeServices) {
    $activeServices = [
        ['id' => 0, 'name' => 'Deratizare', 'default_duration' => 60],
        ['id' => 0, 'name' => 'Dezinsectie', 'default_duration' => 60],
        ['id' => 0, 'name' => 'Dezinfectie', 'default_duration' => 60],
        ['id' => 0, 'name' => 'Monitorizare capcane', 'default_duration' => 30],
        ['id' => 0, 'name' => 'Tratament plosnite', 'default_duration' => 120],
        ['id' => 0, 'name' => 'Alt serviciu', 'default_duration' => 60],
    ];
}

/*
|--------------------------------------------------------------------------
| Clienți si locații
|--------------------------------------------------------------------------
*/
$clients = $pdo->query("
    SELECT id, client_type, name, legal_representative_name, phone, email, address, registered_address,
           billing_country, billing_county, billing_city, billing_address_line, billing_postal_code, active
    FROM clients
    WHERE active = 1
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$clientsById = [];

foreach ($clients as $client) {
    $client['effective_address'] = task_client_address($client);
    $client['contact_person'] = task_client_contact_person($client);
    $client['contact_phone'] = task_client_contact_phone($client);
    $clientsById[(int)$client['id']] = $client;
}

$clientLocations = $pdo->query("
    SELECT id, client_id, location_name, address, contact_person, phone, notes, active, sort_order
    FROM client_locations
    WHERE active = 1
    ORDER BY client_id ASC, sort_order ASC, location_name ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$locationsByClient = [];
$locationsById = [];

foreach ($clientLocations as $location) {
    $clientId = (int)$location['client_id'];
    $locationId = (int)$location['id'];

    if (!isset($locationsByClient[$clientId])) {
        $locationsByClient[$clientId] = [];
    }

    $locationsByClient[$clientId][] = $location;
    $locationsById[$locationId] = $location;
}


// POST handler extras în tasks_post_handler.php (CRUD task-uri).
require __DIR__ . '/tasks_post_handler.php';

/*
|--------------------------------------------------------------------------
| Month range - luna trecută / luna curentă / luna următoare
|--------------------------------------------------------------------------
*/
$prefillClientId = max(0, (int)($_GET['client_id'] ?? 0));
$autoOpenCreate = (($_GET['open_create'] ?? '') === '1');
$returnTo = ($_GET['return_to'] ?? '');

$todayMonthObj = new DateTime(date('Y-m-01'));
$monthButtons = [
    'prev' => [
        'label' => 'Lună trecută',
        'date' => (clone $todayMonthObj)->modify('-1 month'),
    ],
    'current' => [
        'label' => 'Lună curentă',
        'date' => clone $todayMonthObj,
    ],
    'next' => [
        'label' => 'Lună următoare',
        'date' => (clone $todayMonthObj)->modify('+1 month'),
    ],
];

$selectedMonthKey = 'current';
$requestedMonth = trim($_GET['month'] ?? '');
$requestedDate = safe_date_tasks($_GET['date'] ?? date('Y-m-d'));
$requestedDateObj = new DateTime($requestedDate);
$requestedDateMonth = $requestedDateObj->format('Y-m');

foreach ($monthButtons as $key => $button) {
    $buttonMonth = $button['date']->format('Y-m');

    if ($requestedMonth !== '' && $requestedMonth === $buttonMonth) {
        $selectedMonthKey = $key;
        break;
    }

    if ($requestedMonth === '' && $requestedDateMonth === $buttonMonth) {
        $selectedMonthKey = $key;
    }
}

$selectedMonthObj = clone $monthButtons[$selectedMonthKey]['date'];
$rangeStartObj = new DateTime($selectedMonthObj->format('Y-m-01'));
$rangeEndObj = (clone $rangeStartObj)->modify('last day of this month');

$rangeStart = $rangeStartObj->format('Y-m-d');
$rangeEnd = $rangeEndObj->format('Y-m-d');
$currentDate = $selectedMonthKey === 'current' ? date('Y-m-d') : $rangeStart;
$calendarInitialDate = $rangeStart;

$taskFilter = trim((string)($_GET['filter'] ?? 'all'));
if (!in_array($taskFilter, ['all', 'overdue', 'today', 'future', 'skipped'], true)) {
    $taskFilter = 'all';
}
$taskSearch = trim((string)($_GET['q'] ?? ''));
$taskService = trim((string)($_GET['service'] ?? ''));
$taskBaseQuery = [
    'month' => $selectedMonthObj->format('Y-m'),
    'filter' => $taskFilter !== 'all' ? $taskFilter : '',
    'q' => $taskSearch,
    'service' => $taskService,
];

/*
|--------------------------------------------------------------------------
| Query sarcini
|--------------------------------------------------------------------------
*/
$taskWhere = [
    "t.due_date BETWEEN ? AND ?",
    "t.status IN ('de_programat', 'contactat', 'amanat', 'skipped')",
    "t.recurrence_stopped = 0",
];
$taskParams = [$rangeStart, $rangeEnd];

if ($taskFilter === 'overdue') {
    $taskWhere[] = "t.status != 'skipped'";
    $taskWhere[] = "t.due_date < ?";
    $taskParams[] = date('Y-m-d');
} elseif ($taskFilter === 'today') {
    $taskWhere[] = "t.status != 'skipped'";
    $taskWhere[] = "t.due_date = ?";
    $taskParams[] = date('Y-m-d');
} elseif ($taskFilter === 'future') {
    $taskWhere[] = "t.status != 'skipped'";
    $taskWhere[] = "t.due_date > ?";
    $taskParams[] = date('Y-m-d');
} elseif ($taskFilter === 'skipped') {
    $taskWhere[] = "t.status = 'skipped'";
}

if ($taskSearch !== '') {
    $taskWhere[] = "(c.name LIKE ? OR t.service_type LIKE ? OR t.address LIKE ? OR l.location_name LIKE ? OR t.contact_person LIKE ? OR t.contact_phone LIKE ?)";
    $like = '%' . $taskSearch . '%';
    array_push($taskParams, $like, $like, $like, $like, $like, $like);
}

if ($taskService !== '') {
    $taskWhere[] = "t.service_type = ?";
    $taskParams[] = $taskService;
}

$hasContractsTable = table_exists_tasks($pdo, 'contracts');
$hasContractServicesTable = table_exists_tasks($pdo, 'contract_services');
$hasContractTitleColumn = $hasContractsTable && column_exists_tasks($pdo, 'contracts', 'title');
$hasContractServiceLocationColumn = $hasContractServicesTable && column_exists_tasks($pdo, 'contract_services', 'location_name');
$hasContractServiceSurfaceValueColumn = $hasContractServicesTable && column_exists_tasks($pdo, 'contract_services', 'surface_value');
$hasContractServiceSurfaceUnitColumn = $hasContractServicesTable && column_exists_tasks($pdo, 'contract_services', 'surface_unit');

$contractSelect = $hasContractsTable
    ? "ct.contract_number,\n        " . ($hasContractTitleColumn ? "ct.title" : "NULL") . " AS contract_title"
    : "NULL AS contract_number,\n        NULL AS contract_title";

$contractServiceSelect = $hasContractServicesTable
    ? "cs.service_name AS contract_service_name,\n        cs.price AS contract_service_price,\n        cs.currency AS contract_service_currency,\n        " . ($hasContractServiceLocationColumn ? "cs.location_name" : "NULL") . " AS contract_location_name,\n        " . ($hasContractServiceSurfaceValueColumn ? "cs.surface_value" : "NULL") . " AS contract_surface_value,\n        " . ($hasContractServiceSurfaceUnitColumn ? "cs.surface_unit" : "NULL") . " AS contract_surface_unit"
    : "NULL AS contract_service_name,\n        NULL AS contract_service_price,\n        NULL AS contract_service_currency,\n        NULL AS contract_location_name,\n        NULL AS contract_surface_value,\n        NULL AS contract_surface_unit";

$contractJoin = $hasContractsTable
    ? "LEFT JOIN contracts ct ON ct.id = t.contract_id"
    : "";

$contractServiceJoin = $hasContractServicesTable
    ? "LEFT JOIN contract_services cs ON cs.id = t.contract_service_id"
    : "";

$stmt = $pdo->prepare("
    SELECT
        t.*,
        c.name AS client_name,
        c.phone AS client_phone,
        c.email AS client_email,
        c.address AS client_old_address,
        c.registered_address AS client_registered_address,
        c.client_type AS client_type,
        c.legal_representative_name AS client_legal_representative_name,
        l.location_name,
        l.address AS location_address,
        l.contact_person AS location_contact_person,
        l.phone AS location_phone,
        l.notes AS location_notes,
        {$contractSelect},
        {$contractServiceSelect}
    FROM tasks t
    LEFT JOIN clients c ON c.id = t.client_id
    LEFT JOIN client_locations l ON l.id = t.client_location_id
    {$contractJoin}
    {$contractServiceJoin}
    WHERE " . implode("\n      AND ", $taskWhere) . "
    ORDER BY t.due_date ASC, t.id ASC
");
$stmt->execute($taskParams);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalTasks = count($tasks);

$overdueTasks = 0;
$todayTasks = 0;
$skippedTasks = 0;
$activeTasks = 0;

foreach ($tasks as $task) {
    if (($task['status'] ?? '') === 'skipped') {
        $skippedTasks++;
        continue;
    }

    $activeTasks++;

    if ($task['due_date'] < date('Y-m-d')) {
        $overdueTasks++;
    }

    if ($task['due_date'] === date('Y-m-d')) {
        $todayTasks++;
    }
}

/*
|--------------------------------------------------------------------------
| FullCalendar events
|--------------------------------------------------------------------------
*/
$calendarEvents = [];
$tasksForJs = [];

foreach ($tasks as $task) {
    $isSkipped = (($task['status'] ?? '') === 'skipped');
    $isOverdue = !$isSkipped && $task['due_date'] < date('Y-m-d');
    $isToday = !$isSkipped && $task['due_date'] === date('Y-m-d');

    $color = '#163B63';
    $statusGroup = 'future';

    if ($isOverdue) {
        $color = '#D24726';
        $statusGroup = 'overdue';
    }

    if ($isToday) {
        $color = '#1160B7';
        $statusGroup = 'today';
    }

    if ($isSkipped) {
        $color = '#9AA3AF';
        $statusGroup = 'skipped';
    }

    $eventTitle = trim(($task['client_name'] ?: 'Client') . ' - ' . ($task['service_type'] ?: 'Sarcină'));

    if ($isSkipped) {
        $eventTitle = 'SARITA - ' . $eventTitle;
    }
    $serviceForUrl = $task['service_type'] ?? '';
    $taskLocationId = (int)($task['client_location_id'] ?? 0);
    $clientAddress = trim((string)($task['client_registered_address'] ?? '')) ?: trim((string)($task['client_old_address'] ?? ''));
    $locationLabel = $taskLocationId > 0
        ? ($task['location_name'] ?: 'Punct de lucru')
        : 'Sediu social / domiciliu';

    $clientFallbackContact = (($task['client_type'] ?? 'company') === 'individual')
        ? trim((string)($task['client_name'] ?? ''))
        : (trim((string)($task['client_legal_representative_name'] ?? '')) ?: trim((string)($task['client_name'] ?? '')));

    $effectiveContactPerson = trim((string)($task['contact_person'] ?? ''))
        ?: (trim((string)($task['location_contact_person'] ?? '')) ?: $clientFallbackContact);

    $effectiveContactPhone = trim((string)($task['contact_phone'] ?? ''))
        ?: (trim((string)($task['location_phone'] ?? '')) ?: trim((string)($task['client_phone'] ?? '')));

    $scheduleUrl = 'calendar.php?client_id=' . (int)($task['client_id'] ?? 0) .
        '&task_id=' . (int)$task['id'] .
        '&service_type=' . urlencode($serviceForUrl) .
        '&open_create=1';

    if ($taskLocationId > 0) {
        $scheduleUrl .= '&client_location_id=' . $taskLocationId;
    }

    $calendarEvents[] = [
        'id' => (int)$task['id'],
        'title' => $eventTitle,
        'start' => $task['due_date'],
        'allDay' => true,
        'backgroundColor' => $color,
        'borderColor' => $color,
        'extendedProps' => [
            'status_group' => $statusGroup,
            'client' => $task['client_name'] ?: 'Client',
            'service' => $task['service_type'] ?: 'Sarcină',
        ],
    ];

    $tasksForJs[(int)$task['id']] = [
        'id' => (int)$task['id'],
        'client_id' => (int)($task['client_id'] ?? 0),
        'client_location_id' => $taskLocationId,
        'client_name' => $task['client_name'] ?? '',
        'client_phone' => $task['client_phone'] ?? '',
        'client_address' => $clientAddress,
        'location_name' => $locationLabel,
        'location_address' => $task['location_address'] ?? '',
        'service_type' => $task['service_type'] ?? '',
        'address' => $task['address'] ?? '',
        'contact_person' => $effectiveContactPerson,
        'contact_phone' => $effectiveContactPhone,
        'due_date' => $task['due_date'] ?? '',
        'status' => $task['status'] ?? 'de_programat',
        'status_label' => (($task['status'] ?? '') === 'skipped') ? 'Omisa' : 'De programat',
        'skipped_at' => $task['skipped_at'] ?? '',
        'skipped_reason' => $task['skipped_reason'] ?? '',
        'notes' => $task['notes'] ?? '',
        'recurrence_type' => $task['recurrence_type'] ?? 'none',
        'recurrence_days' => $task['recurrence_days'] ? (int)$task['recurrence_days'] : '',
        'recurrence_total' => (int)($task['recurrence_total'] ?? 1),
        'recurrence_remaining' => (int)($task['recurrence_remaining'] ?? 1),
        'recurrence_index' => (int)($task['recurrence_index'] ?? 1),
        'recurrence_label' => recurrence_label_tasks(
            $task['recurrence_type'] ?? 'none',
            $task['recurrence_days'] ? (int)$task['recurrence_days'] : null
        ),
        'contract_id' => (int)($task['contract_id'] ?? 0),
        'contract_service_id' => (int)($task['contract_service_id'] ?? 0),
        'contract_number' => $task['contract_number'] ?? '',
        'contract_title' => $task['contract_title'] ?? '',
        'contract_service_name' => ($task['contract_service_name'] ?? '') ?: ($task['service_type'] ?? ''),
        'contract_location_name' => ($task['contract_location_name'] ?? '') ?: ($task['location_name'] ?? ''),
        'surface_value' => ($task['surface_value'] ?? '') ?: ($task['contract_surface_value'] ?? ''),
        'surface_unit' => ($task['surface_unit'] ?? '') ?: ($task['contract_surface_unit'] ?? ''),
        'billing_amount' => ($task['billing_amount'] ?? '') ?: ($task['contract_service_price'] ?? ''),
        'currency' => ($task['currency'] ?? '') ?: (($task['contract_service_currency'] ?? '') ?: 'RON'),
        'document_id' => (int)($task['document_id'] ?? 0),
        'document_item_id' => (int)($task['document_item_id'] ?? 0),
        'has_contract' => !empty($task['contract_id']) || !empty($task['contract_service_id']),
        'can_extend' => (($task['recurrence_type'] ?? 'none') !== 'none' && !$isSkipped),
        'can_skip' => !$isSkipped,
        'can_schedule' => !$isSkipped,
        'schedule_url' => $scheduleUrl,
    ];
}

?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Sarcini</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<?php app_theme_css(); ?>

<style>
.tasks-topbar {
    align-items: center;
    padding: 12px 20px;
}

.tasks-toolbar {
    width: 100%;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: nowrap;
}

.tasks-view-switcher {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 0 0 auto;
}

.tasks-action-line {
    margin-left: auto;
    display: flex;
    align-items: center;
}

.task-view-btn.active {
    background: var(--accent) !important;
    border-color: var(--accent) !important;
    color: #ffffff !important;
}

.task-view-btn.active:hover {
    background: var(--accent-strong) !important;
    border-color: var(--accent-strong) !important;
    color: #ffffff !important;
}

.tasks-hero {
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at 88% 12%, rgba(177, 214, 240, .78), transparent 30%),
        radial-gradient(circle at 40% 110%, rgba(17, 96, 183, .14), transparent 40%),
        linear-gradient(135deg, rgba(255,255,255,.90), rgba(177,214,240,.30));
    color: #002050;
    border: 1px solid rgba(177, 214, 240, .78);
    border-radius: 24px;
    padding: 32px 34px;
    box-shadow: 0 20px 46px rgba(0,32,80,.13), inset 0 1px 0 rgba(255,255,255,.86);
    margin-bottom: 22px;
    display: flex;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
    align-items: center;
    backdrop-filter: blur(18px) saturate(135%);
    -webkit-backdrop-filter: blur(18px) saturate(135%);
}

.tasks-hero::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        linear-gradient(120deg, rgba(255,255,255,.72), rgba(255,255,255,0) 38%),
        linear-gradient(140deg, transparent 56%, rgba(255,255,255,.45) 57%, transparent 72%);
    pointer-events: none;
}

.tasks-hero::after {
    content: "";
    position: absolute;
    left: 32px;
    top: 30px;
    bottom: 30px;
    width: 4px;
    border-radius: 999px;
    background: linear-gradient(180deg, #1160B7, #B1D6F0);
    box-shadow: 0 0 22px rgba(17, 96, 183, .30);
}

.tasks-hero > * {
    position: relative;
    z-index: 1;
}

.tasks-hero-copy {
    padding-left: 38px;
}

.tasks-hero h1 {
    font-size: 34px;
    font-weight: 900;
    letter-spacing: -.045em;
    margin: 0;
    color: #002050;
}

.tasks-hero p {
    color: rgba(0,32,80,.74);
    margin: 7px 0 0;
    max-width: 780px;
    font-size: 15px;
    line-height: 1.45;
    font-weight: 750;
}

.stats {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.stat-pill {
    min-height: 52px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,.56);
    border: 1px solid rgba(177, 214, 240, .78);
    border-radius: 18px;
    padding: 10px 15px;
    color: #002050;
    font-weight: 900;
    font-size: 14px;
    box-shadow: 0 12px 28px rgba(0,32,80,.10), inset 0 1px 0 rgba(255,255,255,.80);
    backdrop-filter: blur(14px) saturate(130%);
    -webkit-backdrop-filter: blur(14px) saturate(130%);
}

.stat-pill .task-kpi-icon {
    width: 18px;
    height: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    border: 0;
    font-size: 0;
    line-height: 1;
    flex: 0 0 18px;
}

.stat-pill .task-kpi-icon .nav-icon,
.stat-pill .task-kpi-icon svg {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
    stroke-width: 1.9;
    color: currentColor;
}

.stat-pill .task-kpi-icon .nav-icon {
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    padding: 0 !important;
    margin: 0 !important;
}

.stat-pill .task-kpi-icon .nav-icon svg {
    display: block;
}

.stat-pill.stat-active .task-kpi-icon,
.stat-pill.stat-today .task-kpi-icon {
    color: #1160B7;
}

.stat-pill.stat-overdue .task-kpi-icon {
    color: #D24726;
}

.tasks-calendar-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 16px;
    overflow: auto;
}

.fc {
    font-family: var(--font) !important;
}

.fc-event {
    border-radius: 8px !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    padding: 2px 4px !important;
    cursor: pointer !important;
}

.fc .fc-toolbar-title {
    font-size: 18px !important;
    font-weight: 900 !important;
}

.task-details-grid {
    display: grid;
    gap: 10px;
    margin-bottom: 16px;
}

.task-details-row {
    display: grid;
    grid-template-columns: 145px 1fr;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border2);
}

.task-details-row:last-child {
    border-bottom: none;
}

.task-details-section {
    margin: 4px 0 0;
    padding: 9px 11px;
    border: 1px solid rgba(177, 214, 240, .70);
    border-radius: 12px;
    background: rgba(177, 214, 240, .20);
    color: #002050;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.task-details-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.task-details-value {
    color: var(--text);
    font-weight: 700;
}

.recurrence-days-wrap {
    display: none;
}

.recurrence-days-wrap.visible {
    display: block;
}

.extend-summary {
    margin-bottom: 16px;
}

.location-helper {
    margin-top: 5px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 750;
}

@media(max-width: 860px) {
    .tasks-topbar {
        padding: 8px 10px;
    }

    .tasks-toolbar {
        flex-direction: column;
        align-items: stretch;
        gap: 7px;
    }

    .tasks-view-switcher {
        display: grid;
        grid-template-columns: 1fr;
        gap: 7px;
        width: 100%;
    }

    .tasks-view-switcher .btn {
        width: 100%;
        height: 40px;
        min-height: 40px;
        padding: 0 8px;
        font-size: 12px;
    }

    .tasks-action-line {
        margin-left: 0;
        width: 100%;
    }

    .tasks-action-line .btn {
        width: 100%;
        height: 42px;
        min-height: 42px;
        font-size: 12px;
    }

    .tasks-hero {
        padding: 22px 18px;
    }

    .tasks-hero::after {
        left: 18px;
        top: 22px;
        bottom: 22px;
    }

    .tasks-hero-copy {
        padding-left: 24px;
    }

    .tasks-hero h1 {
        font-size: 27px;
    }

    .stats {
        width: 100%;
        justify-content: stretch;
    }

    .stat-pill {
        flex: 1 1 100%;
    }
}

@media(max-width: 620px) {
    .task-details-row {
        grid-template-columns: 1fr;
        gap: 3px;
    }
}

/* === AUTOCOMPLETE smart pentru client === */
.pz-autocomplete { position:relative; }
.pz-autocomplete-input { width:100%; padding:10px 38px 10px 38px; border:1px solid var(--accent-soft-2); border-radius:12px; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748B' stroke-width='2.2' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='m21 21-4.3-4.3'/%3E%3C/svg%3E") no-repeat 12px center; font-size:13px; color:var(--text); outline:none; transition:border-color .14s ease, box-shadow .14s ease; }
.pz-autocomplete-input:hover { border-color:var(--accent); }
.pz-autocomplete-input:focus { border-color:var(--accent); box-shadow:var(--focus-ring); }
.pz-autocomplete-results { display:none; position:absolute; left:0; right:0; top:calc(100% + 4px); max-height:320px; overflow-y:auto; background:#fff; border:1px solid var(--border); border-radius:12px; box-shadow:var(--shadow-lg); z-index:300; padding:4px; }
.pz-autocomplete.is-open .pz-autocomplete-results { display:block; }
.pz-autocomplete-result { padding:9px 11px; border-radius:8px; cursor:pointer; transition:background .12s ease; }
.pz-autocomplete-result:hover, .pz-autocomplete-result.is-active { background:var(--accent-soft); }
.pz-autocomplete-result .ar-name { font-size:13px; font-weight:700; color:var(--text); }
.pz-autocomplete-result .ar-meta { font-size:11px; color:var(--muted); margin-top:2px; }
.pz-autocomplete-result mark { background:rgba(79,70,229,.18); color:var(--accent-strong); padding:0 1px; border-radius:2px; }
.pz-autocomplete-empty { padding:14px 12px; text-align:center; color:var(--muted); font-size:12px; }
.pz-autocomplete-selected { display:none; align-items:center; gap:10px; padding:9px 11px 9px 12px; background:var(--accent-soft); border:1px solid var(--accent-soft-2); border-radius:12px; color:var(--text); font-size:13px; font-weight:700; }
.pz-autocomplete.has-value .pz-autocomplete-selected { display:flex; }
.pz-autocomplete.has-value .pz-autocomplete-input { display:none; }
.pz-autocomplete-selected .ps-meta { color:var(--muted); font-weight:500; font-size:11.5px; margin-top:2px; }
.pz-autocomplete-selected .ps-clear { margin-left:auto; width:26px; height:26px; border-radius:8px; border:0; background:#fff; color:var(--muted); cursor:pointer; font-size:14px; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
.pz-autocomplete-selected .ps-clear:hover { background:var(--tone-danger-soft); color:var(--tone-danger); }

/* Mobile compact alignment for task navigation and task KPI pills */
@media (max-width: 720px) {
    .tasks-topbar {
        padding: 12px 14px;
    }

    .tasks-toolbar {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .tasks-view-switcher {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 7px;
        align-items: center;
        justify-content: center;
    }

    .tasks-view-switcher .task-view-btn {
        width: 100%;
        min-width: 0;
        min-height: 42px;
        height: 42px;
        padding: 0 8px;
        border-radius: 999px;
        font-size: 12px;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        white-space: nowrap;
    }

    .tasks-action-line {
        width: 100%;
        margin-left: 0;
        justify-content: center;
    }

    .tasks-action-line .btn {
        width: min(72%, 280px);
        min-height: 42px;
        height: 42px;
        border-radius: 999px;
        justify-content: center;
        text-align: center;
    }

    .tasks-hero {
        padding: 24px 18px 24px 22px;
        gap: 20px;
    }

    .tasks-hero-copy {
        width: 100%;
        padding-left: 28px;
    }

    .tasks-hero h1 {
        font-size: 28px;
    }

    .tasks-hero p {
        font-size: 14px;
        max-width: 100%;
    }

    .stats {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 7px;
        justify-content: center;
        align-items: center;
    }

    .stat-pill {
        width: 100%;
        min-width: 0;
        min-height: 42px;
        height: 42px;
        padding: 6px 5px;
        border-radius: 999px;
        gap: 5px;
        justify-content: center;
        text-align: center;
        font-size: 11.5px;
        line-height: 1;
        white-space: nowrap;
    }

    .stat-pill .task-kpi-icon {
        width: 16px;
        height: 16px;
        flex: 0 0 16px;
    }

    .stat-pill .task-kpi-icon .nav-icon,
    .stat-pill .task-kpi-icon svg {
        width: 16px;
        height: 16px;
        flex-basis: 16px;
    }
}

@media (max-width: 380px) {
    .tasks-view-switcher {
        gap: 5px;
    }

    .tasks-view-switcher .task-view-btn {
        font-size: 11px;
        padding: 0 4px;
    }

    .stats {
        gap: 5px;
    }

    .stat-pill {
        font-size: 10.5px;
        gap: 3px;
        padding: 5px 3px;
    }

    .stat-pill .task-kpi-icon {
        width: 16px;
        height: 16px;
        flex-basis: 16px;
    }

    .stat-pill .task-kpi-icon .nav-icon,
    .stat-pill .task-kpi-icon svg {
        width: 16px;
        height: 16px;
        flex-basis: 16px;
    }
}


/* Final mobile alignment fix: center compact task controls and keep KPI pills away from left accent line */
@media (max-width: 720px) {
    .tasks-toolbar {
        align-items: center !important;
        justify-content: center !important;
        width: 100%;
    }

    .tasks-view-switcher {
        width: min(420px, calc(100% - 18px)) !important;
        margin: 0 auto !important;
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 8px !important;
        justify-content: center !important;
        align-items: center !important;
    }

    .tasks-view-switcher .task-view-btn {
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        height: 42px !important;
        min-height: 42px !important;
        padding: 0 6px !important;
        border-radius: 999px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        font-size: 12px !important;
        font-weight: 900 !important;
        line-height: 1 !important;
        white-space: nowrap !important;
    }

    .tasks-action-line {
        width: 100% !important;
        justify-content: center !important;
        margin-left: 0 !important;
    }

    .tasks-action-line .btn {
        width: min(280px, calc(100% - 110px)) !important;
        min-width: 220px !important;
        height: 42px !important;
        min-height: 42px !important;
        border-radius: 999px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        white-space: nowrap !important;
    }

    .tasks-hero {
        align-items: flex-start !important;
    }

    .stats {
        width: min(390px, calc(100% - 58px)) !important;
        margin: 2px 0 0 36px !important;
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 7px !important;
        justify-content: center !important;
        align-items: center !important;
    }

    .stat-pill {
        width: 100% !important;
        min-width: 0 !important;
        height: 42px !important;
        min-height: 42px !important;
        padding: 6px 5px !important;
        border-radius: 999px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 5px !important;
        text-align: center !important;
        font-size: 11.5px !important;
        font-weight: 900 !important;
        line-height: 1 !important;
        white-space: nowrap !important;
    }

    .stat-pill .task-kpi-icon {
        width: 16px !important;
        height: 16px !important;
        flex: 0 0 16px !important;
        font-size: 0 !important;
    }

    .stat-pill .task-kpi-icon .nav-icon,
    .stat-pill .task-kpi-icon svg {
        width: 16px !important;
        height: 16px !important;
        flex-basis: 16px !important;
    }
}

@media (max-width: 430px) {
    .tasks-view-switcher {
        width: min(405px, calc(100% - 10px)) !important;
        gap: 6px !important;
    }

    .tasks-view-switcher .task-view-btn {
        font-size: 11px !important;
        padding: 0 4px !important;
    }

    .tasks-action-line .btn {
        width: min(280px, calc(100% - 90px)) !important;
        min-width: 210px !important;
    }

    .stats {
        width: min(374px, calc(100% - 54px)) !important;
        margin-left: 34px !important;
        gap: 5px !important;
    }

    .stat-pill {
        font-size: 10.8px !important;
        gap: 4px !important;
        padding: 5px 3px !important;
    }

    .stat-pill .task-kpi-icon {
        width: 15px !important;
        height: 15px !important;
        flex-basis: 15px !important;
        font-size: 0 !important;
    }

    .stat-pill .task-kpi-icon .nav-icon,
    .stat-pill .task-kpi-icon svg {
        width: 15px !important;
        height: 15px !important;
        flex-basis: 15px !important;
    }
}


/* Final fix 14.05: mobile centering for Tasks top controls and KPI chips */
@media (max-width: 720px) {
    .tasks-topbar {
        padding: 12px 0 !important;
    }

    .tasks-toolbar {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 10px !important;
        margin: 0 auto !important;
    }

    .tasks-view-switcher {
        width: min(390px, calc(100% - 48px)) !important;
        max-width: 390px !important;
        margin: 0 auto !important;
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 8px !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .tasks-view-switcher .task-view-btn {
        width: 100% !important;
        min-width: 0 !important;
        height: 42px !important;
        min-height: 42px !important;
        padding: 0 6px !important;
        border-radius: 999px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        white-space: nowrap !important;
        font-size: 11.5px !important;
        line-height: 1 !important;
        font-weight: 900 !important;
    }

    .tasks-action-line {
        width: 100% !important;
        margin-left: 0 !important;
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
    }

    .tasks-action-line .btn {
        width: min(280px, 58vw) !important;
        min-width: 0 !important;
        max-width: 280px !important;
        height: 42px !important;
        min-height: 42px !important;
        margin: 0 auto !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        border-radius: 999px !important;
        white-space: nowrap !important;
    }

    .tasks-hero {
        display: block !important;
        padding: 22px 18px !important;
    }

    .tasks-hero-copy {
        padding-left: 24px !important;
    }

    .stats {
        width: calc(100% - 60px) !important;
        max-width: 390px !important;
        margin: 18px 0 0 42px !important;
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 7px !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .stat-pill {
        width: 100% !important;
        min-width: 0 !important;
        height: 42px !important;
        min-height: 42px !important;
        padding: 6px 5px !important;
        border-radius: 999px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 5px !important;
        text-align: center !important;
        white-space: nowrap !important;
        font-size: 11.5px !important;
        font-weight: 900 !important;
        line-height: 1 !important;
    }

    .stat-pill .task-kpi-icon {
        width: 16px !important;
        height: 16px !important;
        flex: 0 0 16px !important;
        font-size: 0 !important;
    }

    .stat-pill .task-kpi-icon .nav-icon,
    .stat-pill .task-kpi-icon svg {
        width: 16px !important;
        height: 16px !important;
        flex-basis: 16px !important;
    }
}

@media (max-width: 430px) {
    .tasks-view-switcher {
        width: min(370px, calc(100% - 34px)) !important;
        gap: 6px !important;
    }

    .tasks-view-switcher .task-view-btn {
        font-size: 10.8px !important;
        padding: 0 4px !important;
    }

    .tasks-action-line .btn {
        width: min(270px, 62vw) !important;
    }

    .stats {
        width: calc(100% - 58px) !important;
        margin-left: 40px !important;
        gap: 5px !important;
    }

    .stat-pill {
        font-size: 10.5px !important;
        gap: 4px !important;
        padding: 5px 3px !important;
    }

    .stat-pill .task-kpi-icon {
        width: 15px !important;
        height: 15px !important;
        flex-basis: 15px !important;
        font-size: 0 !important;
    }

    .stat-pill .task-kpi-icon .nav-icon,
    .stat-pill .task-kpi-icon svg {
        width: 15px !important;
        height: 15px !important;
        flex-basis: 15px !important;
    }
}

/* Compact operational refresh */
.tasks-topbar { overflow: visible; z-index: 500; }
.tasks-toolbar {
    display: grid;
    grid-template-columns: auto minmax(280px, 1fr) auto;
    gap: 8px;
    align-items: center;
}
.tasks-view-switcher .task-view-btn,
.tasks-action-line .btn,
.tasks-filter-line .btn,
.tasks-filter-line select,
.tasks-filter-line input {
    min-height: 40px;
    height: 40px;
    border-radius: 999px !important;
}
.tasks-view-switcher .task-view-btn {
    padding: 0 16px;
    font-size: 13px;
    font-weight: 800;
}
.tasks-filter-line {
    display: grid;
    grid-template-columns: 120px minmax(180px, 1fr) auto auto;
    gap: 7px;
    min-width: 0;
    align-items: center;
}
.tasks-filter-line select,
.tasks-filter-line input {
    width: 100%;
    min-width: 0;
    border: 1.5px solid rgba(29,110,193,.34);
    background: #fff;
    color: #002050;
    font-size: 12px;
    font-weight: 750;
    padding: 0 13px;
    box-shadow: 0 8px 18px rgba(29,110,193,.06), inset 0 1px 0 rgba(255,255,255,.86);
}
.tasks-filter-line input::placeholder { color: #7A8796; font-weight: 650; }
.tasks-hero {
    min-height: auto;
    padding: 18px 22px;
    border-radius: 18px;
    margin-bottom: 14px;
}
.tasks-hero::after { left: 22px; top: 20px; bottom: 20px; }
.tasks-hero-copy { padding-left: 28px; }
.tasks-hero-copy { text-align: center; }
.tasks-hero h1 { font-size: 25px; letter-spacing: -.03em; }
.tasks-hero p { font-size: 13px; margin-top: 5px; }
.stat-pill { min-height: 38px; padding: 7px 12px; border-radius: 999px; font-size: 12px; }
.tasks-calendar-card {
    border-radius: 16px;
    padding: 12px;
    box-shadow: 0 12px 28px -24px rgba(0,32,80,.32), inset 0 1px 0 rgba(255,255,255,.72);
}
.tasks-status-legend {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin: 0 0 10px;
}
.tasks-status-legend span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 25px;
    padding: 3px 9px;
    border-radius: 999px;
    border: 1px solid rgba(0,32,80,.10);
    background: #fff;
    color: #002050;
    font-size: 11.5px;
    font-weight: 750;
}
.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    display: inline-block;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.38);
}
.legend-dot.overdue { background: #D24726; }
.legend-dot.today { background: #1160B7; }
.legend-dot.future { background: #163B63; }
.legend-dot.skipped { background: #9AA3AF; }
.fc .fc-daygrid-day-number {
    color: #002050;
    font-size: 12px;
    font-weight: 850;
}
.fc .fc-day-today .fc-daygrid-day-frame {
    background: color-mix(in srgb,#1160B7 7%,#fff) !important;
    box-shadow: inset 0 0 0 2px rgba(17,96,183,.36);
}
.fc .fc-daygrid-day-events {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 2px;
    padding: 1px 5px 5px;
}
.fc .fc-daygrid-event-harness { margin: 0 !important; }
.fc-event.fc-task-event {
    width: 10px !important;
    min-width: 10px !important;
    max-width: 10px !important;
    height: 10px !important;
    min-height: 10px !important;
    padding: 0 !important;
    border-radius: 999px !important;
    border-width: 1px !important;
    box-shadow: 0 2px 6px rgba(0,32,80,.14), inset 0 1px 0 rgba(255,255,255,.36) !important;
    overflow: hidden !important;
}
.fc-event.fc-task-event .fc-event-main,
.fc-event.fc-task-event .fc-event-main-frame { min-height: 8px !important; height: 8px !important; }
.fc-event.fc-task-event .fc-event-title,
.fc-event.fc-task-event .fc-event-time { display: none !important; }
.fc-event.fc-task-skipped { opacity: .58; }
@media(max-width:1180px){
    .tasks-toolbar { grid-template-columns: 1fr; align-items: stretch; }
    .tasks-action-line { margin-left: 0; justify-content: stretch; }
    .tasks-action-line .btn { width: 100%; justify-content: center; }
}
@media(max-width:760px){
    .tasks-topbar { padding: 10px 12px !important; }
    .tasks-toolbar { gap: 8px; }
    .tasks-view-switcher {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 7px;
        width: 100%;
    }
    .tasks-view-switcher .task-view-btn {
        min-height: 34px;
        height: 34px;
        border-radius: 8px !important;
        padding: 0 8px;
        font-size: 12px;
    }
    .tasks-filter-line {
        display: none;
    }
    .tasks-action-line { width: 100%; margin: 0; }
    .tasks-action-line .btn {
        width: 100%;
        min-height: 38px;
        height: 38px;
        border-radius: 8px !important;
        font-size: 13px;
    }
    .tasks-hero {
        padding: 15px 12px 14px;
        border-radius: 8px;
        margin-bottom: 10px;
        background: #fff;
        box-shadow: none;
        display: grid;
        gap: 13px;
        align-items: center;
    }
    .tasks-hero::before,
    .tasks-hero::after { display: none; }
    .tasks-hero-copy { padding-left: 0; }
    .tasks-hero-copy {
        display: flex;
        justify-content: center;
        width: 100%;
    }
    .tasks-hero h1 {
        font-size: 22px;
        letter-spacing: 0;
        text-align: center;
        margin: 0;
    }
    .tasks-hero p {
        display: none;
    }
    .stats {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 7px;
        width: 100%;
        justify-content: stretch !important;
    }
    .stat-pill {
        width: 100% !important;
        min-height: 44px;
        border-radius: 8px;
        padding: 8px 6px;
        justify-content: center;
        gap: 5px;
        font-size: 12px;
        box-shadow: none;
        background: var(--surface-soft);
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
    }
    .stat-pill .task-kpi-icon,
    .stat-pill .task-kpi-icon .nav-icon,
    .stat-pill .task-kpi-icon svg {
        width: 15px;
        height: 15px;
        flex-basis: 15px;
    }
    .tasks-calendar-card {
        border-radius: 8px;
        padding: 10px;
        box-shadow: none;
    }
    .tasks-status-legend {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 6px;
        margin-bottom: 8px;
    }
    .tasks-status-legend span {
        min-height: 28px;
        border-radius: 8px;
        padding: 3px 6px;
        justify-content: center;
        gap: 4px;
        font-size: 10.5px;
    }
    .legend-dot {
        width: 8px;
        height: 8px;
    }
    .fc .fc-daygrid-day-number {
        font-size: 10.5px;
        padding: 3px 4px;
    }
    .fc .fc-col-header-cell-cushion {
        font-size: 10px;
        padding: 4px 0;
    }
    .fc .fc-daygrid-day-frame {
        min-height: 64px;
    }
    .fc .fc-daygrid-day-events {
        gap: 1px;
        padding: 0 3px 3px;
    }
    .fc-event.fc-task-event {
        width: 8px !important;
        min-width: 8px !important;
        max-width: 8px !important;
        height: 8px !important;
        min-height: 8px !important;
    }
}

@media(max-width:760px){
    .tasks-hero .stats {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        width: 100% !important;
        max-width: none !important;
        margin: 8px auto 0 !important;
        gap: 6px !important;
        align-items: center !important;
    }
    .tasks-hero .stats .stat-pill {
        width: 100% !important;
        min-width: 0 !important;
        max-width: none !important;
        height: 38px !important;
        min-height: 38px !important;
        flex: 0 1 auto !important;
        border-radius: 8px !important;
        padding: 5px 3px !important;
        font-size: 10.5px !important;
        line-height: 1 !important;
        white-space: nowrap !important;
    }
    .tasks-toolbar .tasks-action-line {
        width: 100% !important;
        max-width: none !important;
    }
    .tasks-toolbar .tasks-action-line .btn {
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
    }
    .tasks-hero .tasks-hero-copy h1 {
        font-size: 20px !important;
        line-height: 1.15 !important;
        margin: 0 !important;
        letter-spacing: 0 !important;
    }
}
/* ══ Design System v2.4 fixes ══ */
* { font-family: 'Satoshi', 'Inter', system-ui, -apple-system, sans-serif !important; }

/* Hero: fără gradient, fără shadow, simplu și clar */
.tasks-hero {
    background: var(--pz-surf) !important;
    border: 1px solid var(--pz-line) !important;
    border-left: 4px solid var(--pz-bl) !important;
    border-radius: var(--pz-r) !important;
    box-shadow: none !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    color: var(--pz-title) !important;
    padding: 16px 20px !important;
}
.tasks-hero::before, .tasks-hero::after { display: none !important; }
.tasks-hero-copy { padding-left: 0 !important; }
.tasks-hero h1 { font-size: 22px !important; font-weight: 700 !important; letter-spacing: -.01em !important; color: var(--pz-title) !important; }
.tasks-hero p  { font-size: 13px !important; color: var(--pz-mu) !important; margin-top: 5px !important; }

/* Stat pills: fără glas/blur */
.stat-pill {
    background: var(--pz-soft) !important;
    border: 1px solid var(--pz-line) !important;
    box-shadow: none !important;
    backdrop-filter: none !important;
    color: var(--pz-text) !important;
    border-radius: var(--pz-rs) !important;
}
.stat-pill.stat-overdue { background: var(--pz-res) !important; border-color: var(--pz-reb) !important; color: var(--pz-re) !important; }
.stat-pill.stat-today   { background: var(--pz-bls) !important; border-color: var(--pz-blb) !important; color: var(--pz-bld) !important; }
.stat-pill.stat-active  { background: var(--pz-grs) !important; border-color: var(--pz-grb) !important; color: var(--pz-gr) !important; }

/* Calendar card: fără shadow */
.tasks-calendar-card { box-shadow: none !important; border-radius: var(--pz-r) !important; }

/* Filter select: conform DS */
.tasks-filter-line select, .tasks-filter-line input {
    border: 1px solid var(--pz-line) !important;
    border-radius: var(--pz-rs) !important;
    background: var(--pz-surf) !important;
    color: var(--pz-text) !important;
    box-shadow: none !important;
    font-size: 12.5px !important;
    font-weight: 500 !important;
}

/* Border radii */
.pz-autocomplete-results, .pz-autocomplete-input, .pz-autocomplete-selected { border-radius: var(--pz-rs) !important; }
.pz-autocomplete-result { border-radius: var(--pz-rs) !important; }
.tasks-status-legend span { border-radius: var(--pz-rs) !important; }
.task-view-btn { border-radius: var(--pz-rs) !important; }
.tasks-action-line .btn { border-radius: var(--pz-rs) !important; }
.tasks-filter-line .btn { border-radius: var(--pz-rs) !important; }
.task-details-section { border-radius: var(--pz-rs) !important; background: var(--pz-soft) !important; border: 1px solid var(--pz-lines) !important; color: var(--pz-mu) !important; }

/* Font weights max 700 */
.tasks-hero h1, .tasks-view-switcher .task-view-btn, .stat-pill,
.fc .fc-toolbar-title, .fc-event { font-weight: 700 !important; }
.fc .fc-daygrid-day-number { font-weight: 600 !important; }

/* Culori hardcodate → tokeni */
.tasks-filter-line select, .tasks-filter-line input { color: var(--pz-text) !important; }
</style>
</head>

<body>
<div class="layout">

    <?php render_sidebar('tasks', $isAdmin); ?>

    <main class="main">

<?php /* Topbar vechi eliminat — înlocuit cu pz_page_header + pz-fb mai jos. */ ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="notice notice-success">Sarcină a fost adăugată.</div>
        <?php endif; ?>

        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success">Sarcină a fost actualizată.</div>
        <?php endif; ?>

        <?php if (isset($_GET['extended'])): ?>
            <div class="notice notice-success">Recurența a fost prelungita cu succes.</div>
        <?php endif; ?>

        <?php if (isset($_GET['extend_error'])): ?>
            <div class="notice notice-warning">Recurența nu a putut fi prelungita. Verifica dacă sarcina este recurenta si activa.</div>
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
            <div class="notice notice-warning">Sarcină a fost ștearsă.</div>
        <?php endif; ?>

        <?php if (isset($_GET['stopped'])): ?>
            <div class="notice notice-warning">Recurența a fost oprita si sarcinile viitoare au fost șterse.</div>
        <?php endif; ?>

        <?php if (isset($_GET['skipped'])): ?>
            <div class="notice notice-warning">Sarcină a fost omisa.</div>
        <?php endif; ?>

        <?php if (isset($_GET['skip_error'])): ?>
            <div class="notice notice-danger">Sarcină nu a putut fi omisa.</div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notice notice-danger">Completează clientul, serviciul si data sarcinii.</div>
        <?php endif; ?>

        <div class="content">

            <?php
                /*
                |------------------------------------------------------------
                | Header unificat PestZone + filter bar pz-fb.
                | Înlocuiește vechea zonă topbar + tasks-hero.
                | Tabs = Lună trecută / curentă / următoare (păstrează filtrele).
                | KPI inline = active / întârziate / azi.
                | Toolbar = select filter (status sarcină) + select service + reset.
                | Actions = Sarcină nouă (primary).
                |------------------------------------------------------------
                */
                $tasksTabs = [];
                foreach ($monthButtons as $monthKey => $monthButton) {
                    $buttonMonth = $monthButton['date']->format('Y-m');
                    $tasksTabs[] = [
                        'label'  => $monthButton['label'],
                        'href'   => task_page_url(array_merge($taskBaseQuery, ['month' => $buttonMonth])),
                        'active' => ($selectedMonthKey === $monthKey),
                    ];
                }

                $tasksActiveFilters = 0;
                if ($taskFilter !== 'all') $tasksActiveFilters++;
                if ($taskService !== '')    $tasksActiveFilters++;

                ob_start();
                ?>
                <form method="get" id="tasksFilterForm" class="pz-fb">
                    <input type="hidden" name="month" value="<?= h($selectedMonthObj->format('Y-m')) ?>">

                    <div class="pz-fb-spacer"></div>

                    <?php if ($taskFilter !== 'all' || $taskSearch !== '' || $taskService !== ''): ?>
                        <a class="pz-fb-nav-btn" href="<?= h(task_page_url(['month' => $selectedMonthObj->format('Y-m')])) ?>" title="Resetare filtre">↻</a>
                    <?php endif; ?>

                    <div class="pz-fb-popover-wrap">
                        <button type="button" class="pz-fb-filter-btn" id="tasksFiltersToggle" aria-haspopup="true" aria-expanded="false">
                            <i class="ti ti-adjustments-horizontal" aria-hidden="true"></i>
                            Filtre
                            <?php if ($tasksActiveFilters > 0): ?>
                                <span class="badge"><?= (int)$tasksActiveFilters ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="pz-fb-popover" id="tasksFiltersPopover" role="dialog" aria-label="Filtre suplimentare sarcini">
                            <div class="pf-row">
                                <label for="tasksFilterSelect">Status sarcini</label>
                                <select id="tasksFilterSelect" name="filter">
                                    <option value="all"     <?= $taskFilter === 'all'     ? 'selected' : '' ?>>Toate</option>
                                    <option value="overdue" <?= $taskFilter === 'overdue' ? 'selected' : '' ?>>Întârziate</option>
                                    <option value="today"   <?= $taskFilter === 'today'   ? 'selected' : '' ?>>Azi</option>
                                    <option value="future"  <?= $taskFilter === 'future'  ? 'selected' : '' ?>>Viitoare</option>
                                    <option value="skipped" <?= $taskFilter === 'skipped' ? 'selected' : '' ?>>Omise</option>
                                </select>
                            </div>
                            <div class="pf-row">
                                <label for="tasksServiceSelect">Serviciu</label>
                                <select id="tasksServiceSelect" name="service">
                                    <option value="">Toate serviciile</option>
                                    <?php foreach ($activeServices as $service):
                                        $serviceName = (string)$service['name'];
                                    ?>
                                        <option value="<?= h($serviceName) ?>" <?= $taskService === $serviceName ? 'selected' : '' ?>><?= h($serviceName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pf-actions">
                                <button type="button" class="pz-ph-btn ghost" onclick="document.getElementById('tasksFiltersPopover').classList.remove('is-open'); document.getElementById('tasksFiltersToggle').setAttribute('aria-expanded','false');">Anulează</button>
                                <button type="submit" class="pz-ph-btn primary">Aplică</button>
                            </div>
                        </div>
                    </div>
                </form>
                <?php
                $tasksToolbarHtml = ob_get_clean();

                pz_page_header([
                    'kicker'   => 'OPERAȚIONAL',
                    'title'    => 'Sarcini',
                    'subtitle' => 'Priorități de lucru pentru ' . h(mb_strtolower($monthButtons[$selectedMonthKey]['label'])),
                    'actions'  => [[
                        'label'   => 'Sarcină nouă',
                        'icon'    => 'ti-plus',
                        'variant' => 'primary',
                        'type'    => 'button',
                        'onclick' => "openCreateTaskModal('" . h($currentDate) . "')",
                        'iconOnly' => true,
                    ]],
                    'tabs'     => $tasksTabs,
                    'kpis'     => [
                        ['label' => 'Active',     'value' => (int)$activeTasks,  'tone' => 'info',    'icon' => 'ti-list-check',     'sublabel' => 'sarcini deschise'],
                        ['label' => 'Întârziate', 'value' => (int)$overdueTasks, 'tone' => 'danger',  'icon' => 'ti-alert-triangle', 'sublabel' => 'de rezolvat acum'],
                        ['label' => 'Azi',        'value' => (int)$todayTasks,   'tone' => 'warning', 'icon' => 'ti-calendar-event', 'sublabel' => 'programate azi'],
                    ],
                    'toolbar'  => $tasksToolbarHtml,
                ]);
            ?>
            <script>
            (function() {
                // Popover filtre sarcini — toggle + close click-outside + ESC
                var btn = document.getElementById('tasksFiltersToggle');
                var pop = document.getElementById('tasksFiltersPopover');
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

            <section class="tasks-calendar-card">
                <div class="tasks-status-legend" aria-label="Legenda sarcini">
                    <span><i class="legend-dot overdue"></i>Întârziate</span>
                    <span><i class="legend-dot today"></i>Azi</span>
                    <span><i class="legend-dot future"></i>Viitoare</span>
                    <span><i class="legend-dot skipped"></i>Omise</span>
                </div>
                <div id="tasksCalendar"></div>
            </section>

        </div>
    </main>
</div>

<div class="modal" id="createTaskModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Sarcină nouă</h2>
            <button class="modal-close" type="button" onclick="closeModal('createTaskModal')">&times;</button>
        </div>

        <form method="post" id="createTaskForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="return_to" value="<?= h($returnTo === 'client' ? 'client' : '') ?>">

            <div class="form-grid">
                <div>
                    <label>Client *</label>
                    <input type="hidden" name="client_id" id="create_client_id" required value="<?= (int)$prefillClientId ?>">
                    <div class="pz-autocomplete" id="create_clientAutocomplete" data-prefix="create" data-onchange="handleTaskClientChange">
                        <input type="text" class="pz-autocomplete-input" id="create_clientSearchInput" placeholder="Caută după nume, CUI, telefon, reprezentant..." autocomplete="off">
                        <div class="pz-autocomplete-selected" id="create_clientSelectedBox">
                            <div>
                                <div class="ps-name"></div>
                                <div class="ps-meta"></div>
                            </div>
                            <button type="button" class="ps-clear" onclick="pzClientClearAuto('create')" title="Schimba clientul">&times;</button>
                        </div>
                        <div class="pz-autocomplete-results" id="create_clientResults" role="listbox"></div>
                    </div>
                </div>

                <div>
                    <label>Locație / punct de lucru</label>
                    <select name="client_location_id" id="create_client_location_id" onchange="handleTaskLocationChange('create')" disabled>
                        <option value="">Alege clientul mai intai</option>
                    </select>
                    <div class="location-helper" id="create_location_helper"></div>
                </div>

                <div>
                    <label>Persoană contact</label>
                    <input type="text" name="contact_person" id="create_contact_person" placeholder="Se preia automat din client / locație">
                </div>

                <div>
                    <label>Telefon contact</label>
                    <input type="tel" name="contact_phone" id="create_contact_phone" placeholder="Telefon pentru contactare">
                </div>

                <div>
                    <label>Serviciu *</label>
                    <select name="service_type" required>
                        <option value="">Alege serviciul</option>
                        <?php foreach ($activeServices as $service): ?>
                            <option value="<?= h($service['name']) ?>">
                                <?= h($service['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Data sarcinii *</label>
                    <input type="date" name="due_date" id="create_due_date" required value="<?= h($currentDate) ?>">
                </div>

                <div class="form-group full">
                    <label>Adresa intervenție</label>
                    <input type="text" name="address" id="create_address" placeholder="Se salveaza ca adresa exacta a acestei intervenții">
                </div>

                <div>
                    <label>Recurența</label>
                    <select name="recurrence_type" id="create_recurrence_type" onchange="toggleRecurrenceDays('create')">
                        <option value="none">Fara recurenta</option>
                        <option value="days">La un numar de zile</option>
                        <option value="weekly">Saptamanal</option>
                        <option value="monthly">Lunar</option>
                        <option value="three_months">Trimestrial</option>
                        <option value="six_months">Semestrial</option>
                    </select>
                </div>

                <div class="recurrence-days-wrap" id="create_recurrence_days_wrap">
                    <label>Numar zile</label>
                    <input type="number" name="recurrence_days" min="1" value="30">
                </div>

                <div>
                    <label>Cicluri</label>
                    <input type="number" name="recurrence_total" min="1" value="1">
                </div>

                <div class="form-group full">
                    <label>Observații</label>
                    <textarea name="notes" placeholder="Detalii pentru birou, persoană de contact, preferinte client..."></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('createTaskModal')">Renunță</button>
                    <button class="btn accent" type="submit">Salvează sarcina</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="taskDetailsModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Detalii sarcina</h2>
            <button class="modal-close" type="button" onclick="closeModal('taskDetailsModal')">&times;</button>
        </div>

        <div class="task-details-grid" id="taskDetailsContent"></div>

        <div class="actions-row">
            <div class="actions-left">
                <button class="btn danger" type="button" onclick="deleteCurrentTask()">Șterge</button>
                <button class="btn" type="button" id="skipTaskBtn" onclick="openSkipTaskModal()">Omite</button>
                <button class="btn" type="button" id="stopFutureTaskBtn" onclick="stopCurrentFutureTasks()">Opreste recurenta</button>
                <button class="btn" type="button" id="extendRecurrenceBtn" onclick="openExtendRecurrenceModal()">Prelungeste recurenta</button>
            </div>

            <div class="actions-right">
                <button class="btn" type="button" id="editTaskBtn" onclick="openEditTaskFromDetails()">Editează</button>
                <a class="btn accent" id="scheduleTaskLink" href="#">Programeaza</a>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="skipTaskModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Omite sarcina</h2>
            <button class="modal-close" type="button" onclick="closeModal('skipTaskModal')">&times;</button>
        </div>

        <div class="readonly-box extend-summary" id="skipTaskSummary">
            Se incarca datele sarcinii...
        </div>

        <form method="post" id="skipTaskForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="skip_task">
            <input type="hidden" name="task_id" id="skip_task_id">

            <div class="form-grid">
                <div class="form-group full">
                    <label>Motiv *</label>
                    <select name="skip_reason_preset" id="skip_reason_preset" required>
                        <option value="Clientul nu doreste intervenția luna aceasta">Clientul nu doreste intervenția luna aceasta</option>
                        <option value="Locatia este inchisa">Locatia este inchisa</option>
                        <option value="Interventia a fost amanata de client">Interventia a fost amanata de client</option>
                        <option value="Alt motiv">Alt motiv</option>
                    </select>
                </div>

                <div class="form-group full">
                    <label>Observații motiv</label>
                    <textarea name="skip_reason_custom" id="skip_reason_custom" placeholder="Completează doar dacă vrei un motiv diferit sau mai detaliat."></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('skipTaskModal')">Renunță</button>
                    <button class="btn accent" type="submit">Confirma omitere</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="extendRecurrenceModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Prelungeste recurenta</h2>
            <button class="modal-close" type="button" onclick="closeModal('extendRecurrenceModal')">&times;</button>
        </div>

        <div class="readonly-box extend-summary" id="extendTaskSummary">
            Se incarca datele sarcinii...
        </div>

        <form method="post" id="extendRecurrenceForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="extend_recurrence">
            <input type="hidden" name="task_id" id="extend_task_id">

            <div class="form-grid">
                <div>
                    <label>Cicluri de adaugat *</label>
                    <input type="number" name="extra_cycles" id="extend_extra_cycles" min="1" value="12" required>
                </div>

                <div class="form-group full">
                    <label>Observații prelungire</label>
                    <textarea name="extension_note" id="extension_note" placeholder="Ex: Contract prelungit cu 12 luni."></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('extendRecurrenceModal')">Renunță</button>
                    <button class="btn accent" type="submit">Salvează prelungirea</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="editTaskModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Editează sarcina</h2>
            <button class="modal-close" type="button" onclick="closeModal('editTaskModal')">&times;</button>
        </div>

        <form method="post" id="editTaskForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="task_id" id="edit_task_id">

            <div class="form-grid">
                <div>
                    <label>Client *</label>
                    <input type="hidden" name="client_id" id="edit_client_id" required value="">
                    <div class="pz-autocomplete" id="edit_clientAutocomplete" data-prefix="edit" data-onchange="handleTaskClientChange">
                        <input type="text" class="pz-autocomplete-input" id="edit_clientSearchInput" placeholder="Caută după nume, CUI, telefon, reprezentant..." autocomplete="off">
                        <div class="pz-autocomplete-selected" id="edit_clientSelectedBox">
                            <div>
                                <div class="ps-name"></div>
                                <div class="ps-meta"></div>
                            </div>
                            <button type="button" class="ps-clear" onclick="pzClientClearAuto('edit')" title="Schimba clientul">&times;</button>
                        </div>
                        <div class="pz-autocomplete-results" id="edit_clientResults" role="listbox"></div>
                    </div>
                </div>

                <div>
                    <label>Locație / punct de lucru</label>
                    <select name="client_location_id" id="edit_client_location_id" onchange="handleTaskLocationChange('edit')" disabled>
                        <option value="">Alege clientul mai intai</option>
                    </select>
                    <div class="location-helper" id="edit_location_helper"></div>
                </div>

                <div>
                    <label>Persoană contact</label>
                    <input type="text" name="contact_person" id="edit_contact_person">
                </div>

                <div>
                    <label>Telefon contact</label>
                    <input type="tel" name="contact_phone" id="edit_contact_phone">
                </div>

                <div>
                    <label>Serviciu *</label>
                    <select name="service_type" id="edit_service_type" required>
                        <option value="">Alege serviciul</option>
                        <?php foreach ($activeServices as $service): ?>
                            <option value="<?= h($service['name']) ?>">
                                <?= h($service['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Data sarcinii *</label>
                    <input type="date" name="due_date" id="edit_due_date" required>
                </div>

                <div class="form-group full">
                    <label>Adresa intervenție</label>
                    <input type="text" name="address" id="edit_address">
                </div>

                <div>
                    <label>Recurența</label>
                    <select name="recurrence_type" id="edit_recurrence_type" onchange="toggleRecurrenceDays('edit')">
                        <option value="none">Fara recurenta</option>
                        <option value="days">La un numar de zile</option>
                        <option value="weekly">Saptamanal</option>
                        <option value="monthly">Lunar</option>
                        <option value="three_months">Trimestrial</option>
                        <option value="six_months">Semestrial</option>
                    </select>
                </div>

                <div class="recurrence-days-wrap" id="edit_recurrence_days_wrap">
                    <label>Numar zile</label>
                    <input type="number" name="recurrence_days" id="edit_recurrence_days" min="1" value="30">
                </div>

                <div>
                    <label>Cicluri totale</label>
                    <input type="number" name="recurrence_total" id="edit_recurrence_total" min="1" value="1">
                </div>

                <div class="form-group full">
                    <label>Observații</label>
                    <textarea name="notes" id="edit_notes"></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('editTaskModal')">Renunță</button>
                    <button class="btn accent" type="submit">Salvează modificarile</button>
                </div>
            </div>
        </form>
    </div>
</div>

<form method="post" id="deleteTaskForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="task_id" id="delete_task_id">
</form>

<form method="post" id="stopFutureTaskForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="stop_future">
    <input type="hidden" name="task_id" id="stop_future_task_id">
</form>

<script>
const tasksData = <?= json_encode($tasksForJs, JSON_UNESCAPED_UNICODE) ?>;
const clientsData = <?= json_encode($clientsById, JSON_UNESCAPED_UNICODE) ?>;
const locationsByClient = <?= json_encode($locationsByClient, JSON_UNESCAPED_UNICODE) ?>;
let currentTaskId = null;

function openModal(id) {
    document.getElementById(id)?.classList.add('open');
}

function closeModal(id) {
    document.getElementById(id)?.classList.remove('open');
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function getClientAddress(client) {
    if (!client) {
        return '';
    }

    return client.effective_address || client.registered_address || client.address || '';
}

function getClientContactPerson(client) {
    if (!client) {
        return '';
    }

    return client.contact_person || client.legal_representative_name || client.name || '';
}

function getClientContactPhone(client) {
    if (!client) {
        return '';
    }

    return client.contact_phone || client.phone || '';
}

function setField(id, value) {
    const field = document.getElementById(id);
    if (field) field.value = value || '';
}

function populateLocationSelect(prefix, clientId, selectedLocationId = '') {
    const select = document.getElementById(prefix + '_client_location_id');
    const helper = document.getElementById(prefix + '_location_helper');

    if (!select) {
        return;
    }

    select.innerHTML = '';

    if (!clientId || !clientsData[clientId]) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'Alege clientul mai intai';
        select.appendChild(option);
        select.disabled = true;
        if (helper) helper.textContent = '';
        return;
    }

    const client = clientsData[clientId];
    const clientAddress = getClientAddress(client);
    const clientType = client.client_type === 'individual' ? 'domiciliu' : 'sediu social';

    const headquartersOption = document.createElement('option');
    headquartersOption.value = '';
    headquartersOption.textContent = 'Sediu social / domiciliu';
    headquartersOption.dataset.address = clientAddress;
    headquartersOption.dataset.contactPerson = getClientContactPerson(client);
    headquartersOption.dataset.contactPhone = getClientContactPhone(client);
    select.appendChild(headquartersOption);

    const locations = locationsByClient[clientId] || [];

    locations.forEach(location => {
        const option = document.createElement('option');
        option.value = String(location.id);
        option.textContent = (location.location_name || 'Punct de lucru') + (location.address ? ' - ' + location.address : '');
        option.dataset.address = location.address || '';
        option.dataset.contactPerson = location.contact_person || getClientContactPerson(client);
        option.dataset.contactPhone = location.phone || getClientContactPhone(client);
        select.appendChild(option);
    });

    select.disabled = false;

    if (selectedLocationId && Array.from(select.options).some(option => option.value === String(selectedLocationId))) {
        select.value = String(selectedLocationId);
    } else {
        select.value = '';
    }

    if (helper) {
        helper.textContent = locations.length > 0
            ? 'Alege sediul sau unul dintre punctele de lucru ale clientului.'
            : 'Clientul nu are puncte de lucru. Sarcină se face pe ' + clientType + '.';
    }

    handleTaskLocationChange(prefix);
}

function handleTaskClientChange(prefix, selectedLocationId = '') {
    const clientSelect = document.getElementById(prefix + '_client_id');

    if (!clientSelect) {
        return;
    }

    const clientId = clientSelect.value;
    populateLocationSelect(prefix, clientId, selectedLocationId);
}

function handleTaskLocationChange(prefix) {
    const locationSelect = document.getElementById(prefix + '_client_location_id');
    const addressInput = document.getElementById(prefix + '_address');
    const contactPersonInput = document.getElementById(prefix + '_contact_person');
    const contactPhoneInput = document.getElementById(prefix + '_contact_phone');
    const clientSelect = document.getElementById(prefix + '_client_id');

    if (!locationSelect || !clientSelect) {
        return;
    }

    const selected = locationSelect.options[locationSelect.selectedIndex];
    const client = clientsData[clientSelect.value];

    if (selected) {
        if (addressInput) addressInput.value = selected.dataset.address || getClientAddress(client);
        if (contactPersonInput) contactPersonInput.value = selected.dataset.contactPerson || getClientContactPerson(client);
        if (contactPhoneInput) contactPhoneInput.value = selected.dataset.contactPhone || getClientContactPhone(client);
    }
}

function openCreateTaskModal(date) {
    const form = document.getElementById('createTaskForm');

    if (form) {
        form.reset();
    }

    document.getElementById('create_due_date').value = date || '<?= h($currentDate) ?>';
    populateLocationSelect('create', '', '');
    toggleRecurrenceDays('create');
    openModal('createTaskModal');
    setTimeout(() => {
        const clientInput = document.getElementById('create_clientSearchInput');
        const clientHidden = document.getElementById('create_client_id');
        if (clientInput && !clientHidden?.value) {
            clientInput.focus();
        }
    }, 80);
}

function toggleRecurrenceDays(prefix) {
    const type = document.getElementById(prefix + '_recurrence_type')?.value;
    const wrap = document.getElementById(prefix + '_recurrence_days_wrap');

    if (!wrap) {
        return;
    }

    if (type === 'days') {
        wrap.classList.add('visible');
    } else {
        wrap.classList.remove('visible');
    }
}

function openTaskDetails(id) {
    const task = tasksData[id];

    if (!task) {
        alert('Sarcină nu a fost gasita.');
        return;
    }

    currentTaskId = id;

    const scheduleLink = document.getElementById('scheduleTaskLink');
    const editBtn = document.getElementById('editTaskBtn');
    const skipBtn = document.getElementById('skipTaskBtn');
    const stopFutureBtn = document.getElementById('stopFutureTaskBtn');
    const extendBtn = document.getElementById('extendRecurrenceBtn');

    if (scheduleLink) {
        scheduleLink.href = task.schedule_url || '#';
        scheduleLink.style.display = task.can_schedule ? 'inline-flex' : 'none';
    }

    if (editBtn) {
        editBtn.style.display = task.can_skip ? 'inline-flex' : 'none';
    }

    if (skipBtn) {
        skipBtn.style.display = task.can_skip ? 'inline-flex' : 'none';
    }

    if (stopFutureBtn) {
        stopFutureBtn.style.display = task.can_skip ? 'inline-flex' : 'none';
    }

    if (extendBtn) {
        extendBtn.style.display = task.can_extend ? 'inline-flex' : 'none';
    }

    const skippedRows = task.status === 'skipped'
        ? `
            <div class="task-details-row">
                <div class="task-details-label">Motiv omitere</div>
                <div class="task-details-value">${escHtml(task.skipped_reason || '-')}</div>
            </div>

            <div class="task-details-row">
                <div class="task-details-label">Data omitere</div>
                <div class="task-details-value">${escHtml(task.skipped_at || '-')}</div>
            </div>
        `
        : '';

    const contractLabel = task.contract_number || task.contract_title || (task.contract_id ? '#' + task.contract_id : '-');
    const surfaceText = [task.surface_value, task.surface_unit].filter(Boolean).join(' ') || '-';
    const amountValue = parseFloat(task.billing_amount || '0');
    const amountText = amountValue > 0
        ? amountValue.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (task.currency || 'RON')
        : '-';
    const documentText = task.document_id
        ? 'Document #' + task.document_id + (task.document_item_id ? ' / Item #' + task.document_item_id : '')
        : '-';
    const contractRows = task.has_contract
        ? `
            <div class="task-details-section">Date contract</div>

            <div class="task-details-row">
                <div class="task-details-label">Contract</div>
                <div class="task-details-value">${escHtml(contractLabel)}</div>
            </div>

            <div class="task-details-row">
                <div class="task-details-label">Serviciu contractat</div>
                <div class="task-details-value">${escHtml(task.contract_service_name || '-')}</div>
            </div>

            <div class="task-details-row">
                <div class="task-details-label">Locație contract</div>
                <div class="task-details-value">${escHtml(task.contract_location_name || '-')}</div>
            </div>

            <div class="task-details-row">
                <div class="task-details-label">Suprafață</div>
                <div class="task-details-value">${escHtml(surfaceText)}</div>
            </div>

            <div class="task-details-row">
                <div class="task-details-label">Valoare</div>
                <div class="task-details-value">${escHtml(amountText)}</div>
            </div>

            <div class="task-details-row">
                <div class="task-details-label">Document</div>
                <div class="task-details-value">${escHtml(documentText)}</div>
            </div>
        `
        : '';

    document.getElementById('taskDetailsContent').innerHTML = `
        <div class="task-details-row">
            <div class="task-details-label">Status</div>
            <div class="task-details-value">${escHtml(task.status_label || '-')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Client</div>
            <div class="task-details-value">${escHtml(task.client_name || 'Client')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Locație</div>
            <div class="task-details-value">${escHtml(task.location_name || 'Sediu social / domiciliu')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Contact</div>
            <div class="task-details-value">${escHtml(task.contact_person || '-')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Telefon contact</div>
            <div class="task-details-value">${escHtml(task.contact_phone || '-')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Serviciu</div>
            <div class="task-details-value">${escHtml(task.service_type || '-')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Data sarcinii</div>
            <div class="task-details-value">${escHtml(task.due_date || '-')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Adresa intervenție</div>
            <div class="task-details-value">${escHtml(task.address || '-')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Recurența</div>
            <div class="task-details-value">${escHtml(task.recurrence_label || '-')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Cicl curent</div>
            <div class="task-details-value">${escHtml(task.recurrence_index || '1')} / ${escHtml(task.recurrence_total || '1')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Cicluri ramase</div>
            <div class="task-details-value">${escHtml(task.recurrence_remaining || '1')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Observații</div>
            <div class="task-details-value">${escHtml(task.notes || '-').replace(/\n/g, '<br>')}</div>
        </div>

        ${contractRows}

        ${skippedRows}
    `;

    openModal('taskDetailsModal');
}

function openSkipTaskModal() {
    if (!currentTaskId || !tasksData[currentTaskId]) {
        alert('Sarcină nu a fost identificata.');
        return;
    }

    const task = tasksData[currentTaskId];

    if (!task.can_skip) {
        alert('Aceasta sarcina este deja inchisa sau omisa.');
        return;
    }

    document.getElementById('skip_task_id').value = task.id || '';
    document.getElementById('skip_reason_preset').value = 'Clientul nu doreste intervenția luna aceasta';
    document.getElementById('skip_reason_custom').value = '';

    document.getElementById('skipTaskSummary').innerHTML = `
        <strong>Client:</strong> ${escHtml(task.client_name || '-')}<br>
        <strong>Locație:</strong> ${escHtml(task.location_name || 'Sediu social / domiciliu')}<br>
        <strong>Serviciu:</strong> ${escHtml(task.service_type || '-')}<br>
        <strong>Data sarcinii:</strong> ${escHtml(task.due_date || '-')}
    `;

    closeModal('taskDetailsModal');
    openModal('skipTaskModal');
}

function openExtendRecurrenceModal() {
    if (!currentTaskId || !tasksData[currentTaskId]) {
        alert('Sarcină nu a fost identificata.');
        return;
    }

    const task = tasksData[currentTaskId];

    if (!task.can_extend) {
        alert('Aceasta sarcina nu are recurenta si nu poate fi prelungita.');
        return;
    }

    document.getElementById('extend_task_id').value = task.id || '';
    document.getElementById('extend_extra_cycles').value = 12;
    document.getElementById('extension_note').value = '';

    document.getElementById('extendTaskSummary').innerHTML = `
        <strong>Client:</strong> ${escHtml(task.client_name || '-')}<br>
        <strong>Locație:</strong> ${escHtml(task.location_name || 'Sediu social / domiciliu')}<br>
        <strong>Contact:</strong> ${escHtml(task.contact_person || '-')}<br>
        <strong>Telefon:</strong> ${escHtml(task.contact_phone || '-')}<br>
        <strong>Serviciu:</strong> ${escHtml(task.service_type || '-')}<br>
        <strong>Recurența:</strong> ${escHtml(task.recurrence_label || '-')}<br>
        <strong>Cicl curent:</strong> ${escHtml(task.recurrence_index || '1')} / ${escHtml(task.recurrence_total || '1')}<br>
        <strong>Cicluri ramase acum:</strong> ${escHtml(task.recurrence_remaining || '1')}
    `;

    closeModal('taskDetailsModal');
    openModal('extendRecurrenceModal');
}

function openEditTaskFromDetails() {
    if (!currentTaskId || !tasksData[currentTaskId]) {
        return;
    }

    const task = tasksData[currentTaskId];

    document.getElementById('edit_task_id').value = task.id || '';
    // Setam clientul prin noul autocomplete (sau clear dacă task-ul nu are client)
    if (task.client_id && clientsData[task.client_id]) {
        pzClientSetAuto('edit', clientsData[task.client_id]);
    } else {
        pzClientClearAuto('edit');
    }
    populateLocationSelect('edit', String(task.client_id || ''), String(task.client_location_id || ''));
    document.getElementById('edit_service_type').value = task.service_type || '';
    document.getElementById('edit_address').value = task.address || '';
    document.getElementById('edit_contact_person').value = task.contact_person || '';
    document.getElementById('edit_contact_phone').value = task.contact_phone || '';
    document.getElementById('edit_due_date').value = task.due_date || '';
    document.getElementById('edit_recurrence_type').value = task.recurrence_type || 'none';
    document.getElementById('edit_recurrence_days').value = task.recurrence_days || 30;
    document.getElementById('edit_recurrence_total').value = task.recurrence_total || 1;
    document.getElementById('edit_notes').value = task.notes || '';

    toggleRecurrenceDays('edit');

    closeModal('taskDetailsModal');
    openModal('editTaskModal');
}

function deleteCurrentTask() {
    if (!currentTaskId) {
        return;
    }

    if (confirm('Sigur vrei sa stergi aceasta sarcina?')) {
        document.getElementById('delete_task_id').value = currentTaskId;
        document.getElementById('deleteTaskForm').submit();
    }
}

function stopCurrentFutureTasks() {
    if (!currentTaskId) {
        return;
    }

    if (confirm('Sigur vrei sa opresti recurenta? Se sterg doar sarcinile viitoare neprogramate. Programările deja create raman neschimbate.')) {
        document.getElementById('stop_future_task_id').value = currentTaskId;
        document.getElementById('stopFutureTaskForm').submit();
    }
}


function applyPrefillClientToCreateTask() {
    const clientId = '<?= (int)$prefillClientId ?>';
    if (!clientId || clientId === '0') {
        return;
    }

    // Setam clientul in noul autocomplete (declanseaza handleTaskClientChange automat)
    if (clientsData[clientId]) {
        pzClientSetAuto('create', clientsData[clientId]);
    }
    return;
}

<?php if ($autoOpenCreate && $prefillClientId > 0): ?>
document.addEventListener('DOMContentLoaded', function () {
    openCreateTaskModal('<?= h($currentDate) ?>');
    applyPrefillClientToCreateTask();
});
<?php endif; ?>

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', event => {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.open').forEach(modal => modal.classList.remove('open'));
    }
});

/* === AUTOCOMPLETE smart pentru client (prefix create / edit) === */
const pzAutocompleteState = {};
function pzNormalize(s) { return String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }
function pzEscHtml(s) { return String(s||'').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }
function pzHighlight(text, q) {
    if (!q) return pzEscHtml(text);
    const norm = pzNormalize(text), qn = pzNormalize(q), idx = norm.indexOf(qn);
    if (idx < 0) return pzEscHtml(text);
    return pzEscHtml(text.slice(0, idx)) + '<mark>' + pzEscHtml(text.slice(idx, idx + q.length)) + '</mark>' + pzEscHtml(text.slice(idx + q.length));
}
function pzClientSearchAll(q) {
    const qn = pzNormalize(q);
    if (qn.length < 2) return [];
    const results = [];
    for (const id in clientsData) {
        const c = clientsData[id];
        if (!c) continue;
        const haystack = pzNormalize((c.name||'') + ' ' + (c.fiscal_code||'') + ' ' + (c.phone||'') + ' ' + (c.legal_representative_name||'') + ' ' + (c.email||''));
        if (haystack.indexOf(qn) >= 0) {
            results.push(c);
            if (results.length >= 30) break;
        }
    }
    results.sort((a,b) => {
        const aS = pzNormalize(a.name).startsWith(qn) ? 0 : 1;
        const bS = pzNormalize(b.name).startsWith(qn) ? 0 : 1;
        if (aS !== bS) return aS - bS;
        return pzNormalize(a.name).localeCompare(pzNormalize(b.name));
    });
    return results;
}
function pzRenderResults(prefix, results, q) {
    const cont = document.getElementById(prefix + '_clientResults');
    if (!cont) return;
    pzAutocompleteState[prefix] = pzAutocompleteState[prefix] || {};
    pzAutocompleteState[prefix].results = results;
    pzAutocompleteState[prefix].activeIdx = -1;
    if (!results.length) {
        cont.innerHTML = '<div class="pz-autocomplete-empty">Niciun client gasit pentru "' + pzEscHtml(q) + '"</div>';
        return;
    }
    cont.innerHTML = results.map((c, i) => {
        const meta = [c.fiscal_code ? 'CUI ' + pzEscHtml(c.fiscal_code) : '',
                      c.legal_representative_name ? pzEscHtml(c.legal_representative_name) : '',
                      c.phone ? pzEscHtml(c.phone) : ''].filter(Boolean).join(' - ');
        return '<div class="pz-autocomplete-result" data-index="' + i + '" onclick="pzClientPickAuto(\'' + prefix + '\',' + i + ')">'
            + '<div class="ar-name">' + pzHighlight(c.name||'', q) + '</div>'
            + (meta ? '<div class="ar-meta">' + meta + '</div>' : '')
            + '</div>';
    }).join('');
}
function pzClientPickAuto(prefix, idx) {
    const state = pzAutocompleteState[prefix];
    if (!state || !state.results || !state.results[idx]) return;
    pzClientSetAuto(prefix, state.results[idx]);
}
function pzClientSetAuto(prefix, client) {
    const wrap = document.getElementById(prefix + '_clientAutocomplete');
    const hidden = document.getElementById(prefix + '_client_id');
    const box = document.getElementById(prefix + '_clientSelectedBox');
    if (!wrap || !hidden || !box) return;
    hidden.value = String(client.id);
    wrap.classList.add('has-value');
    wrap.classList.remove('is-open');
    box.querySelector('.ps-name').textContent = client.name || 'Client';
    const meta = [];
    if (client.fiscal_code) meta.push('CUI ' + client.fiscal_code);
    if (client.legal_representative_name) meta.push(client.legal_representative_name);
    if (client.phone) meta.push(client.phone);
    box.querySelector('.ps-meta').textContent = meta.join(' - ');
    // Triggereaza handler-ul existent (handleTaskClientChange)
    const onChangeName = wrap.dataset.onchange;
    if (onChangeName && typeof window[onChangeName] === 'function') {
        window[onChangeName](prefix);
    }
}
function pzClientClearAuto(prefix) {
    const wrap = document.getElementById(prefix + '_clientAutocomplete');
    const hidden = document.getElementById(prefix + '_client_id');
    const input = document.getElementById(prefix + '_clientSearchInput');
    if (!wrap || !hidden) return;
    hidden.value = '';
    wrap.classList.remove('has-value');
    if (input) { input.value = ''; input.focus(); }
    document.getElementById(prefix + '_clientResults').innerHTML = '';
    const onChangeName = wrap.dataset.onchange;
    if (onChangeName && typeof window[onChangeName] === 'function') {
        window[onChangeName](prefix);
    }
}
function pzInitAutocomplete(prefix) {
    const wrap = document.getElementById(prefix + '_clientAutocomplete');
    const input = document.getElementById(prefix + '_clientSearchInput');
    const hidden = document.getElementById(prefix + '_client_id');
    if (!wrap || !input || !hidden) return;
    // Dacă avem deja o valoare (la edit sau prefill), o setam vizual
    const initialId = String(hidden.value || '');
    if (initialId && initialId !== '0' && clientsData[initialId]) {
        pzClientSetAuto(prefix, clientsData[initialId]);
    }
    let dt;
    input.addEventListener('input', () => {
        clearTimeout(dt);
        dt = setTimeout(() => {
            const q = input.value.trim();
            if (q.length < 2) {
                wrap.classList.remove('is-open');
                document.getElementById(prefix + '_clientResults').innerHTML = '';
                return;
            }
            pzRenderResults(prefix, pzClientSearchAll(q), q);
            wrap.classList.add('is-open');
        }, 150);
    });
    input.addEventListener('keydown', (e) => {
        const state = pzAutocompleteState[prefix] || {results: [], activeIdx: -1};
        const results = state.results || [];
        if (e.key === 'ArrowDown') { e.preventDefault(); state.activeIdx = Math.min((state.activeIdx ?? -1) + 1, results.length - 1); pzHighlightAuto(prefix); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); state.activeIdx = Math.max((state.activeIdx ?? -1) - 1, 0); pzHighlightAuto(prefix); }
        else if (e.key === 'Enter') { if ((state.activeIdx ?? -1) >= 0) { e.preventDefault(); pzClientPickAuto(prefix, state.activeIdx); } }
        else if (e.key === 'Escape') { wrap.classList.remove('is-open'); }
    });
}
function pzHighlightAuto(prefix) {
    const cont = document.getElementById(prefix + '_clientResults');
    const state = pzAutocompleteState[prefix];
    if (!cont || !state) return;
    cont.querySelectorAll('.pz-autocomplete-result').forEach(el => el.classList.remove('is-active'));
    const target = cont.querySelector('[data-index="' + state.activeIdx + '"]');
    if (target) { target.classList.add('is-active'); target.scrollIntoView({block: 'nearest'}); }
}
// Click outside inchide toate
document.addEventListener('click', (e) => {
    document.querySelectorAll('.pz-autocomplete.is-open').forEach(w => {
        if (!w.contains(e.target)) w.classList.remove('is-open');
    });
});

/* Init pentru ambele modale + sync valoare initiala */
document.addEventListener('DOMContentLoaded', () => {
    pzInitAutocomplete('create');
    pzInitAutocomplete('edit');
});

document.addEventListener('DOMContentLoaded', () => {
    toggleRecurrenceDays('create');
    populateLocationSelect('create', '', '');

    const el = document.getElementById('tasksCalendar');

    if (!el) {
        return;
    }

    const calendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        initialDate: '<?= h($calendarInitialDate) ?>',
        locale: 'ro',
        firstDay: 1,
        height: 'auto',
        headerToolbar: false,
        dayMaxEvents: 12,
        moreLinkContent: args => '+' + args.num,
        events: <?= json_encode($calendarEvents, JSON_UNESCAPED_UNICODE) ?>,
        eventClassNames: info => {
            const group = info.event.extendedProps.status_group || 'future';
            return ['fc-task-event', 'fc-task-' + group];
        },
        eventDidMount: info => {
            info.el.title = [info.event.extendedProps.client || 'Client', info.event.extendedProps.service || 'Sarcină'].filter(Boolean).join(' - ');
        },
        eventClick: info => {
            openTaskDetails(info.event.id);
        },
        dateClick: info => {
            openCreateTaskModal(info.dateStr.substring(0, 10));
        }
    });

    calendar.render();
});
</script>
</body>
</ht