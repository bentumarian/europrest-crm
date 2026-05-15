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

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists_tasks(PDO $pdo, string $table): bool {
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

function column_exists_tasks(PDO $pdo, string $table, string $column): bool {
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

function ensure_column_tasks(PDO $pdo, string $table, string $column, string $definition): void {
    if (!column_exists_tasks($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Nu blocam pagina daca ALTER-ul nu poate rula.
        }
    }
}

function safe_date_tasks(?string $date, ?string $fallback = null): string {
    $fallback = $fallback ?: date('Y-m-d');

    if (!$date) {
        return $fallback;
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);

    return ($d && $d->format('Y-m-d') === $date) ? $date : $fallback;
}

function safe_task_view(string $view): string {
    return in_array($view, ['month', 'year'], true) ? $view : 'month';
}

function recurrence_label_tasks(string $type, ?int $days = null): string {
    return [
        'none'         => 'Fara recurenta',
        'days'         => 'La ' . max(1, (int)$days) . ' zile',
        'weekly'       => 'Saptamanal',
        'monthly'      => 'Lunar',
        'three_months' => 'Trimestrial',
        'six_months'   => 'Semestrial',
    ][$type] ?? 'Fara recurenta';
}

function task_add_months(DateTime $date, int $months): DateTime {
    $day = (int)$date->format('d');
    $result = (clone $date)->modify('first day of this month')->modify('+' . $months . ' months');
    $lastDay = (int)$result->format('t');
    $result->setDate((int)$result->format('Y'), (int)$result->format('m'), min($day, $lastDay));

    return $result;
}

function task_next_due_date(string $dueDate, string $recurrenceType, ?int $recurrenceDays = null): string {
    $date = DateTime::createFromFormat('Y-m-d', $dueDate) ?: new DateTime($dueDate ?: 'now');

    if ($recurrenceType === 'days') {
        return $date->modify('+' . max(1, (int)$recurrenceDays) . ' days')->format('Y-m-d');
    }

    if ($recurrenceType === 'weekly') {
        return $date->modify('+7 days')->format('Y-m-d');
    }

    if ($recurrenceType === 'monthly') {
        return task_add_months($date, 1)->format('Y-m-d');
    }

    if ($recurrenceType === 'three_months') {
        return task_add_months($date, 3)->format('Y-m-d');
    }

    if ($recurrenceType === 'six_months') {
        return task_add_months($date, 6)->format('Y-m-d');
    }

    return $date->format('Y-m-d');
}

function task_client_address(array $client): string {
    return trim((string)($client['registered_address'] ?? '')) ?: trim((string)($client['address'] ?? ''));
}

function task_client_contact_person(array $client): string {
    $clientType = (string)($client['client_type'] ?? 'company');
    $name = trim((string)($client['name'] ?? ''));
    $representative = trim((string)($client['legal_representative_name'] ?? ''));

    if ($clientType === 'individual') {
        return $name;
    }

    return $representative !== '' ? $representative : $name;
}

function task_client_contact_phone(array $client): string {
    return trim((string)($client['phone'] ?? ''));
}

function task_get_location(PDO $pdo, int $clientId, int $locationId): ?array {
    if ($clientId <= 0 || $locationId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, client_id, location_name, address, contact_person, phone, notes, active
        FROM client_locations
        WHERE id = ?
          AND client_id = ?
        LIMIT 1
    ");
    $stmt->execute([$locationId, $clientId]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    return $location ?: null;
}

function task_snapshot_address(array $client, ?array $location, string $postedAddress): string {
    $postedAddress = trim($postedAddress);

    if ($postedAddress !== '') {
        return $postedAddress;
    }

    if ($location && trim((string)($location['address'] ?? '')) !== '') {
        return trim((string)$location['address']);
    }

    return task_client_address($client);
}

function task_snapshot_contact_person(array $client, ?array $location, string $postedContact): string {
    $postedContact = trim($postedContact);

    if ($postedContact !== '') {
        return $postedContact;
    }

    if ($location && trim((string)($location['contact_person'] ?? '')) !== '') {
        return trim((string)$location['contact_person']);
    }

    return task_client_contact_person($client);
}

function task_snapshot_contact_phone(array $client, ?array $location, string $postedPhone): string {
    $postedPhone = trim($postedPhone);

    if ($postedPhone !== '') {
        return $postedPhone;
    }

    if ($location && trim((string)($location['phone'] ?? '')) !== '') {
        return trim((string)$location['phone']);
    }

    return task_client_contact_phone($client);
}

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
    )
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
    )
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
| Clienti si locatii
|--------------------------------------------------------------------------
*/
$clients = $pdo->query("
    SELECT id, client_type, name, legal_representative_name, phone, email, address, registered_address, active
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

/*
|--------------------------------------------------------------------------
| POST handler
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        $clientLocationId = !empty($_POST['client_location_id']) ? (int)$_POST['client_location_id'] : null;
        $serviceType = trim($_POST['service_type'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $dueDate = safe_date_tasks($_POST['due_date'] ?? null);
        $recurrenceType = $_POST['recurrence_type'] ?? 'none';
        $recurrenceDays = !empty($_POST['recurrence_days']) ? max(1, (int)$_POST['recurrence_days']) : null;
        $recurrenceTotal = max(1, (int)($_POST['recurrence_total'] ?? 1));
        $notes = trim($_POST['notes'] ?? '');
        $returnToPost = $_POST['return_to'] ?? '';

        if (!in_array($recurrenceType, ['none', 'days', 'weekly', 'monthly', 'three_months', 'six_months'], true)) {
            $recurrenceType = 'none';
        }

        if ($recurrenceType !== 'days') {
            $recurrenceDays = null;
        }

        if ($recurrenceType === 'none') {
            $recurrenceTotal = 1;
        }

        $client = $clientsById[$clientId] ?? null;
        $location = null;

        if ($clientLocationId) {
            $location = task_get_location($pdo, $clientId, $clientLocationId);

            if (!$location) {
                $clientLocationId = null;
            }
        }

        $clientName = $client['name'] ?? '';
        $title = trim($serviceType . ' - ' . $clientName);

        if ($clientId > 0 && $client && $serviceType !== '' && $dueDate !== '') {
            $snapshotAddress = task_snapshot_address($client, $location, $address);
            $snapshotContactPerson = task_snapshot_contact_person($client, $location, $contactPerson);
            $snapshotContactPhone = task_snapshot_contact_phone($client, $location, $contactPhone);

            if ($action === 'create') {
                $recurrenceGroup = make_task_recurrence_group();

                $stmt = $pdo->prepare("
                    INSERT INTO tasks
                    (
                        client_id,
                        client_location_id,
                        title,
                        service_type,
                        address,
                        contact_person,
                        contact_phone,
                        due_date,
                        recurrence_type,
                        recurrence_days,
                        recurrence_group,
                        recurrence_total,
                        recurrence_remaining,
                        recurrence_stopped,
                        recurrence_index,
                        generated_from_task_id,
                        generated_next_task_id,
                        status,
                        appointment_id,
                        notes
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, 0, 1,
                        NULL, NULL, 'de_programat', NULL, ?
                    )
                ");

                $stmt->execute([
                    $clientId,
                    $clientLocationId ?: null,
                    $title,
                    $serviceType,
                    $snapshotAddress ?: null,
                    $snapshotContactPerson ?: null,
                    $snapshotContactPhone ?: null,
                    $dueDate,
                    $recurrenceType,
                    $recurrenceDays,
                    $recurrenceGroup,
                    $recurrenceTotal,
                    $recurrenceTotal,
                    $notes ?: null
                ]);

                if ($returnToPost === 'client') {
                    header("Location: clients.php?client_id=" . (int)$clientId . "&task_added=1#sarcini-client");
                    exit;
                }

                header("Location: tasks.php?success=1&date=" . urlencode($dueDate));
                exit;
            }

            if ($action === 'update' && $taskId > 0) {
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM tasks
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$taskId]);
                $existingTask = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingTask && $existingTask['status'] !== 'programat') {
                    $recurrenceGroup = $existingTask['recurrence_group'] ?: make_task_recurrence_group();

                    $newRemaining = calculate_remaining_after_task_update(
                        $existingTask,
                        $recurrenceTotal,
                        $recurrenceType
                    );

                    $stmt = $pdo->prepare("
                        UPDATE tasks
                        SET client_id = ?,
                            client_location_id = ?,
                            title = ?,
                            service_type = ?,
                            address = ?,
                            contact_person = ?,
                            contact_phone = ?,
                            due_date = ?,
                            recurrence_type = ?,
                            recurrence_days = ?,
                            recurrence_group = ?,
                            recurrence_total = ?,
                            recurrence_remaining = ?,
                            notes = ?
                        WHERE id = ?
                          AND status != 'programat'
                    ");

                    $stmt->execute([
                        $clientId,
                        $clientLocationId ?: null,
                        $title,
                        $serviceType,
                        $snapshotAddress ?: null,
                        $snapshotContactPerson ?: null,
                        $snapshotContactPhone ?: null,
                        $dueDate,
                        $recurrenceType,
                        $recurrenceDays,
                        $recurrenceGroup,
                        $recurrenceTotal,
                        $newRemaining,
                        $notes ?: null,
                        $taskId
                    ]);

                    header("Location: tasks.php?updated=1&date=" . urlencode($dueDate));
                    exit;
                }
            }
        }

        header("Location: tasks.php?error=1");
        exit;
    }

    if ($action === 'extend_recurrence') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $extraCycles = max(1, (int)($_POST['extra_cycles'] ?? 0));
        $extensionNote = trim($_POST['extension_note'] ?? '');

        if ($taskId > 0 && $extraCycles > 0) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM tasks
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $task &&
                in_array($task['status'], ['de_programat', 'contactat', 'amanat'], true) &&
                (int)($task['recurrence_stopped'] ?? 0) === 0 &&
                ($task['recurrence_type'] ?? 'none') !== 'none'
            ) {
                $oldTotal = max(1, (int)($task['recurrence_total'] ?? 1));
                $oldRemaining = max(0, (int)($task['recurrence_remaining'] ?? 0));

                $newTotal = $oldTotal + $extraCycles;
                $newRemaining = $oldRemaining + $extraCycles;

                $appendNote = "Prelungire recurenta: +" . $extraCycles . " cicluri";
                $appendNote .= " (" . date('d.m.Y H:i') . ")";

                if ($extensionNote !== '') {
                    $appendNote .= "\nObservatii prelungire: " . $extensionNote;
                }

                $oldNotes = trim((string)($task['notes'] ?? ''));
                $newNotes = $oldNotes !== ''
                    ? $oldNotes . "\n\n" . $appendNote
                    : $appendNote;

                $stmt = $pdo->prepare("
                    UPDATE tasks
                    SET recurrence_total = ?,
                        recurrence_remaining = ?,
                        notes = ?
                    WHERE id = ?
                      AND status IN ('de_programat', 'contactat', 'amanat')
                      AND recurrence_type != 'none'
                      AND recurrence_stopped = 0
                ");
                $stmt->execute([
                    $newTotal,
                    $newRemaining,
                    $newNotes,
                    $taskId
                ]);

                header("Location: tasks.php?extended=1&date=" . urlencode($task['due_date']));
                exit;
            }
        }

        header("Location: tasks.php?extend_error=1");
        exit;
    }

    if ($action === 'skip_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $reasonPreset = trim($_POST['skip_reason_preset'] ?? '');
        $reasonCustom = trim($_POST['skip_reason_custom'] ?? '');
        $skipReason = $reasonCustom !== '' ? $reasonCustom : $reasonPreset;

        if ($skipReason === '') {
            $skipReason = 'Clientul nu doreste interventia luna aceasta';
        }

        if ($taskId > 0) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM tasks
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($task && in_array($task['status'], ['de_programat', 'contactat', 'amanat'], true)) {
                $oldNotes = trim((string)($task['notes'] ?? ''));
                $skipNote = "Sarcina sarita peste: " . $skipReason . " (" . date('d.m.Y H:i') . ")";
                $newNotes = $oldNotes !== '' ? $oldNotes . "

" . $skipNote : $skipNote;

                $stmt = $pdo->prepare("
                    UPDATE tasks
                    SET status = 'skipped',
                        skipped_at = NOW(),
                        skipped_reason = ?,
                        notes = ?
                    WHERE id = ?
                      AND status IN ('de_programat', 'contactat', 'amanat')
                ");
                $stmt->execute([$skipReason, $newNotes, $taskId]);

                $recurrenceType = $task['recurrence_type'] ?? 'none';
                $recurrenceRemaining = max(1, (int)($task['recurrence_remaining'] ?? 1));

                if (
                    $recurrenceType !== 'none' &&
                    (int)($task['recurrence_stopped'] ?? 0) === 0 &&
                    $recurrenceRemaining > 1
                ) {
                    $recurrenceGroup = $task['recurrence_group'] ?: make_task_recurrence_group();

                    if (!$task['recurrence_group']) {
                        $stmt = $pdo->prepare("
                            UPDATE tasks
                            SET recurrence_group = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$recurrenceGroup, $taskId]);
                    }

                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM tasks
                        WHERE recurrence_group = ?
                          AND id != ?
                          AND due_date > ?
                        ORDER BY due_date ASC, id ASC
                        LIMIT 1
                    ");
                    $stmt->execute([$recurrenceGroup, $taskId, $task['due_date']]);
                    $futureTask = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$futureTask && empty($task['generated_next_task_id'])) {
                        $nextDueDate = task_next_due_date(
                            $task['due_date'],
                            $recurrenceType,
                            $task['recurrence_days'] ? (int)$task['recurrence_days'] : null
                        );

                        if ($nextDueDate > $task['due_date']) {
                            $nextRemaining = max(1, $recurrenceRemaining - 1);
                            $nextIndex = max(1, (int)($task['recurrence_index'] ?? 1)) + 1;

                            $stmt = $pdo->prepare("
                                INSERT INTO tasks
                                (
                                    client_id,
                                    client_location_id,
                                    title,
                                    service_type,
                                    address,
                                    contact_person,
                                    contact_phone,
                                    due_date,
                                    recurrence_type,
                                    recurrence_days,
                                    recurrence_group,
                                    recurrence_total,
                                    recurrence_remaining,
                                    recurrence_stopped,
                                    recurrence_index,
                                    generated_from_task_id,
                                    generated_next_task_id,
                                    status,
                                    appointment_id,
                                    notes
                                )
                                VALUES
                                (
                                    ?, ?, ?, ?, ?,
                                    ?, ?, ?, ?, ?,
                                    ?, ?, ?, 0, ?,
                                    ?, NULL, 'de_programat', NULL, ?
                                )
                            ");
                            $stmt->execute([
                                $task['client_id'] ?: null,
                                $task['client_location_id'] ?: null,
                                $task['title'] ?: null,
                                $task['service_type'] ?: null,
                                $task['address'] ?: null,
                                $task['contact_person'] ?: null,
                                $task['contact_phone'] ?: null,
                                $nextDueDate,
                                $recurrenceType,
                                $task['recurrence_days'] ? (int)$task['recurrence_days'] : null,
                                $recurrenceGroup,
                                max(1, (int)($task['recurrence_total'] ?? 1)),
                                $nextRemaining,
                                $nextIndex,
                                $taskId,
                                $oldNotes !== '' ? $oldNotes : null
                            ]);

                            $nextTaskId = (int)$pdo->lastInsertId();

                            $stmt = $pdo->prepare("
                                UPDATE tasks
                                SET generated_next_task_id = ?,
                                    recurrence_remaining = 1
                                WHERE id = ?
                            ");
                            $stmt->execute([$nextTaskId, $taskId]);
                        }
                    }
                }

                header("Location: tasks.php?skipped=1&date=" . urlencode($task['due_date']));
                exit;
            }
        }

        header("Location: tasks.php?skip_error=1");
        exit;
    }

    if ($action === 'delete') {
        $taskId = (int)($_POST['task_id'] ?? 0);

        if ($taskId > 0) {
            $stmt = $pdo->prepare("
                DELETE FROM tasks
                WHERE id = ?
                  AND status != 'programat'
            ");
            $stmt->execute([$taskId]);

            header("Location: tasks.php?deleted=1");
            exit;
        }

        header("Location: tasks.php?error=1");
        exit;
    }

    if ($action === 'stop_future') {
        $taskId = (int)($_POST['task_id'] ?? 0);

        if ($taskId > 0) {
            $stmt = $pdo->prepare("
                SELECT id, recurrence_group, due_date
                FROM tasks
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($task) {
                $recurrenceGroup = $task['recurrence_group'] ?: make_task_recurrence_group();

                if (!$task['recurrence_group']) {
                    $stmt = $pdo->prepare("
                        UPDATE tasks
                        SET recurrence_group = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$recurrenceGroup, $taskId]);
                }

                $stmt = $pdo->prepare("
                    DELETE FROM tasks
                    WHERE recurrence_group = ?
                      AND id != ?
                      AND due_date >= ?
                      AND status IN ('de_programat', 'contactat', 'amanat')
                ");
                $stmt->execute([
                    $recurrenceGroup,
                    $taskId,
                    $task['due_date']
                ]);

                $stmt = $pdo->prepare("
                    UPDATE tasks
                    SET recurrence_stopped = 1,
                        recurrence_remaining = 1
                    WHERE recurrence_group = ?
                ");
                $stmt->execute([$recurrenceGroup]);

                header("Location: tasks.php?stopped=1&date=" . urlencode($task['due_date']));
                exit;
            }
        }

        header("Location: tasks.php?error=1");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Month range - luna trecuta / luna curenta / luna urmatoare
|--------------------------------------------------------------------------
*/
$prefillClientId = max(0, (int)($_GET['client_id'] ?? 0));
$autoOpenCreate = (($_GET['open_create'] ?? '') === '1');
$returnTo = ($_GET['return_to'] ?? '');

$todayMonthObj = new DateTime(date('Y-m-01'));
$monthButtons = [
    'prev' => [
        'label' => 'Luna trecuta',
        'date' => (clone $todayMonthObj)->modify('-1 month'),
    ],
    'current' => [
        'label' => 'Luna curenta',
        'date' => clone $todayMonthObj,
    ],
    'next' => [
        'label' => 'Luna urmatoare',
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

/*
|--------------------------------------------------------------------------
| Query sarcini
|--------------------------------------------------------------------------
*/
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
        l.notes AS location_notes
    FROM tasks t
    LEFT JOIN clients c ON c.id = t.client_id
    LEFT JOIN client_locations l ON l.id = t.client_location_id
    WHERE t.due_date BETWEEN ? AND ?
      AND t.status IN ('de_programat', 'contactat', 'amanat', 'skipped')
      AND t.recurrence_stopped = 0
    ORDER BY t.due_date ASC, t.id ASC
");
$stmt->execute([$rangeStart, $rangeEnd]);
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

    $color = '#163b63';

    if ($isOverdue) {
        $color = '#7a8796';
    }

    if ($isToday) {
        $color = '#102d4a';
    }

    if ($isSkipped) {
        $color = '#9aa3af';
    }

    $eventTitle = trim(($task['client_name'] ?: 'Client') . ' - ' . ($task['service_type'] ?: 'Sarcina'));

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
        'status_label' => (($task['status'] ?? '') === 'skipped') ? 'Sarita peste' : 'De programat',
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
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

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

.stat-pill .stat-icon {
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    border: 1px solid currentColor;
    font-size: 15px;
    line-height: 1;
}

.stat-pill.stat-active .stat-icon,
.stat-pill.stat-today .stat-icon {
    color: #1160B7;
}

.stat-pill.stat-overdue .stat-icon {
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

    .stat-pill .stat-icon {
        width: 22px;
        height: 22px;
        font-size: 12px;
        flex: 0 0 22px;
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

    .stat-pill .stat-icon {
        width: 20px;
        height: 20px;
        font-size: 11px;
        flex-basis: 20px;
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

    .stat-pill .stat-icon {
        width: 22px !important;
        height: 22px !important;
        flex: 0 0 22px !important;
        font-size: 12px !important;
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

    .stat-pill .stat-icon {
        width: 20px !important;
        height: 20px !important;
        flex-basis: 20px !important;
        font-size: 11px !important;
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

    .stat-pill .stat-icon {
        width: 22px !important;
        height: 22px !important;
        flex: 0 0 22px !important;
        font-size: 12px !important;
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

    .stat-pill .stat-icon {
        width: 20px !important;
        height: 20px !important;
        flex-basis: 20px !important;
        font-size: 11px !important;
    }
}

</style>
</head>

<body>
<div class="layout">

    <?php render_sidebar('tasks', $isAdmin); ?>

    <main class="main">

        <div class="topbar tasks-topbar">
            <div class="tasks-toolbar">

                <div class="tasks-view-switcher">
                    <?php foreach ($monthButtons as $monthKey => $monthButton): ?>
                        <?php $buttonMonth = $monthButton['date']->format('Y-m'); ?>
                        <a 
                            class="btn task-view-btn <?= $selectedMonthKey === $monthKey ? 'active' : '' ?>" 
                            href="tasks.php?month=<?= urlencode($buttonMonth) ?>"
                        >
                            <?= h($monthButton['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="tasks-action-line">
                    <button class="btn accent" type="button" onclick="openCreateTaskModal('<?= h($currentDate) ?>')">
                        + Sarcina noua
                    </button>
                </div>

            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="notice notice-success">Sarcina a fost adaugata.</div>
        <?php endif; ?>

        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success">Sarcina a fost actualizata.</div>
        <?php endif; ?>

        <?php if (isset($_GET['extended'])): ?>
            <div class="notice notice-success">Recurenta a fost prelungita cu succes.</div>
        <?php endif; ?>

        <?php if (isset($_GET['extend_error'])): ?>
            <div class="notice notice-warning">Recurenta nu a putut fi prelungita. Verifica daca sarcina este recurenta si activa.</div>
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
            <div class="notice notice-warning">Sarcina a fost stearsa.</div>
        <?php endif; ?>

        <?php if (isset($_GET['stopped'])): ?>
            <div class="notice notice-warning">Recurenta a fost oprita si sarcinile viitoare au fost sterse.</div>
        <?php endif; ?>

        <?php if (isset($_GET['skipped'])): ?>
            <div class="notice notice-warning">Sarcina a fost marcata ca sarita peste.</div>
        <?php endif; ?>

        <?php if (isset($_GET['skip_error'])): ?>
            <div class="notice notice-danger">Sarcina nu a putut fi sarita peste.</div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notice notice-danger">Completeaza clientul, serviciul si data sarcinii.</div>
        <?php endif; ?>

        <div class="content">

            <section class="tasks-hero">
                <div class="tasks-hero-copy">
                    <h1>Sarcini birou</h1>
                    <p>Fiecare sarcina notata inseamna un client mai bine gestionat.</p>
                </div>

                <div class="stats" aria-label="Indicatori sarcini">
                    <span class="stat-pill stat-active">
                        <span class="stat-icon">✓</span>
                        <?= (int)$activeTasks ?> active
                    </span>
                    <span class="stat-pill stat-overdue">
                        <span class="stat-icon">!</span>
                        <?= (int)$overdueTasks ?> intarziate
                    </span>
                    <span class="stat-pill stat-today">
                        <span class="stat-icon">▦</span>
                        <?= (int)$todayTasks ?> astazi
                    </span>
                </div>
            </section>

            <section class="tasks-calendar-card">
                <div id="tasksCalendar"></div>
            </section>

        </div>
    </main>
</div>

<div class="modal" id="createTaskModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Sarcina noua</h2>
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
                        <input type="text" class="pz-autocomplete-input" id="create_clientSearchInput" placeholder="Cauta dupa nume, CUI, telefon, reprezentant…" autocomplete="off">
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
                    <label>Locatie / punct de lucru</label>
                    <select name="client_location_id" id="create_client_location_id" onchange="handleTaskLocationChange('create')" disabled>
                        <option value="">Alege clientul mai intai</option>
                    </select>
                    <div class="location-helper" id="create_location_helper"></div>
                </div>

                <div>
                    <label>Persoana contact</label>
                    <input type="text" name="contact_person" id="create_contact_person" placeholder="Se preia automat din client / locatie">
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
                    <label>Adresa interventie</label>
                    <input type="text" name="address" id="create_address" placeholder="Se salveaza ca adresa exacta a acestei interventii">
                </div>

                <div>
                    <label>Recurenta</label>
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
                    <label>Observatii</label>
                    <textarea name="notes" placeholder="Detalii pentru birou, persoana de contact, preferinte client..."></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('createTaskModal')">Renunta</button>
                    <button class="btn accent" type="submit">Salveaza sarcina</button>
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
                <button class="btn danger" type="button" onclick="deleteCurrentTask()">Sterge</button>
                <button class="btn" type="button" id="skipTaskBtn" onclick="openSkipTaskModal()">Sari peste</button>
                <button class="btn" type="button" id="stopFutureTaskBtn" onclick="stopCurrentFutureTasks()">Opreste viitoarele</button>
                <button class="btn" type="button" id="extendRecurrenceBtn" onclick="openExtendRecurrenceModal()">Prelungeste recurenta</button>
            </div>

            <div class="actions-right">
                <button class="btn" type="button" id="editTaskBtn" onclick="openEditTaskFromDetails()">Editeaza</button>
                <a class="btn accent" id="scheduleTaskLink" href="#">Programeaza</a>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="skipTaskModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Sari peste sarcina</h2>
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
                        <option value="Clientul nu doreste interventia luna aceasta">Clientul nu doreste interventia luna aceasta</option>
                        <option value="Locatia este inchisa">Locatia este inchisa</option>
                        <option value="Interventia a fost amanata de client">Interventia a fost amanata de client</option>
                        <option value="Alt motiv">Alt motiv</option>
                    </select>
                </div>

                <div class="form-group full">
                    <label>Observatii motiv</label>
                    <textarea name="skip_reason_custom" id="skip_reason_custom" placeholder="Completeaza doar daca vrei un motiv diferit sau mai detaliat."></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('skipTaskModal')">Renunta</button>
                    <button class="btn accent" type="submit">Confirma sari peste</button>
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
                    <label>Observatii prelungire</label>
                    <textarea name="extension_note" id="extension_note" placeholder="Ex: Contract prelungit cu 12 luni."></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('extendRecurrenceModal')">Renunta</button>
                    <button class="btn accent" type="submit">Salveaza prelungirea</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="editTaskModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Editeaza sarcina</h2>
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
                        <input type="text" class="pz-autocomplete-input" id="edit_clientSearchInput" placeholder="Cauta dupa nume, CUI, telefon, reprezentant…" autocomplete="off">
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
                    <label>Locatie / punct de lucru</label>
                    <select name="client_location_id" id="edit_client_location_id" onchange="handleTaskLocationChange('edit')" disabled>
                        <option value="">Alege clientul mai intai</option>
                    </select>
                    <div class="location-helper" id="edit_location_helper"></div>
                </div>

                <div>
                    <label>Persoana contact</label>
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
                    <label>Adresa interventie</label>
                    <input type="text" name="address" id="edit_address">
                </div>

                <div>
                    <label>Recurenta</label>
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
                    <label>Observatii</label>
                    <textarea name="notes" id="edit_notes"></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('editTaskModal')">Renunta</button>
                    <button class="btn accent" type="submit">Salveaza modificarile</button>
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
            : 'Clientul nu are puncte de lucru. Sarcina se face pe ' + clientType + '.';
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
        alert('Sarcina nu a fost gasita.');
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
                <div class="task-details-label">Motiv sari peste</div>
                <div class="task-details-value">${escHtml(task.skipped_reason || '-')}</div>
            </div>

            <div class="task-details-row">
                <div class="task-details-label">Data sari peste</div>
                <div class="task-details-value">${escHtml(task.skipped_at || '-')}</div>
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
            <div class="task-details-label">Locatie</div>
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
            <div class="task-details-label">Adresa interventie</div>
            <div class="task-details-value">${escHtml(task.address || '-')}</div>
        </div>

        <div class="task-details-row">
            <div class="task-details-label">Recurenta</div>
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
            <div class="task-details-label">Observatii</div>
            <div class="task-details-value">${escHtml(task.notes || '-').replace(/\n/g, '<br>')}</div>
        </div>
    `;

    openModal('taskDetailsModal');
}

function openSkipTaskModal() {
    if (!currentTaskId || !tasksData[currentTaskId]) {
        alert('Sarcina nu a fost identificata.');
        return;
    }

    const task = tasksData[currentTaskId];

    if (!task.can_skip) {
        alert('Aceasta sarcina este deja inchisa sau sarita peste.');
        return;
    }

    document.getElementById('skip_task_id').value = task.id || '';
    document.getElementById('skip_reason_preset').value = 'Clientul nu doreste interventia luna aceasta';
    document.getElementById('skip_reason_custom').value = '';

    document.getElementById('skipTaskSummary').innerHTML = `
        <strong>Client:</strong> ${escHtml(task.client_name || '-')}<br>
        <strong>Locatie:</strong> ${escHtml(task.location_name || 'Sediu social / domiciliu')}<br>
        <strong>Serviciu:</strong> ${escHtml(task.service_type || '-')}<br>
        <strong>Data sarcinii:</strong> ${escHtml(task.due_date || '-')}
    `;

    closeModal('taskDetailsModal');
    openModal('skipTaskModal');
}

function openExtendRecurrenceModal() {
    if (!currentTaskId || !tasksData[currentTaskId]) {
        alert('Sarcina nu a fost identificata.');
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
        <strong>Locatie:</strong> ${escHtml(task.location_name || 'Sediu social / domiciliu')}<br>
        <strong>Contact:</strong> ${escHtml(task.contact_person || '-')}<br>
        <strong>Telefon:</strong> ${escHtml(task.contact_phone || '-')}<br>
        <strong>Serviciu:</strong> ${escHtml(task.service_type || '-')}<br>
        <strong>Recurenta:</strong> ${escHtml(task.recurrence_label || '-')}<br>
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
    // Setam clientul prin noul autocomplete (sau clear daca task-ul nu are client)
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

    if (confirm('Sigur vrei sa opresti recurenta si sa stergi sarcinile viitoare?')) {
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
function pzNormalize(s) { return String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,''); }
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
                      c.phone ? pzEscHtml(c.phone) : ''].filter(Boolean).join(' · ');
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
    box.querySelector('.ps-meta').textContent = meta.join(' · ');
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
    // Daca avem deja o valoare (la edit sau prefill), o setam vizual
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
        events: <?= json_encode($calendarEvents, JSON_UNESCAPED_UNICODE) ?>,
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
</html>
