<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

// Fortam afișarea paginii si conexiunea la baza de date pe UTF-8.
// Ajuta la textele romanesti si reduce problemele de tip mojibake.
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
} catch (Throwable $e) {
    // Nu blocam pagina dacă serverul MySQL nu accepta explicit collation-ul.
}

$isAdmin = is_admin();

if (!$isAdmin) {
    header("Location: calendar.php");
    exit;
}

// Helper-i extracși în module separate (text/DB/view + ANAF lookup).
require_once __DIR__ . '/clients_helpers.php';
require_once __DIR__ . '/clients_anaf_lib.php';

/*
|--------------------------------------------------------------------------
| AJAX ANAF
|--------------------------------------------------------------------------
*/
if (isset($_GET['ajax']) && $_GET['ajax'] === 'anaf_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(c_anaf_lookup($_GET['cui'] ?? ''), JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| Schema
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
        billing_country VARCHAR(80) NULL,
        billing_county VARCHAR(120) NULL,
        billing_city VARCHAR(120) NULL,
        billing_sector VARCHAR(80) NULL,
        billing_address_line VARCHAR(255) NULL,
        billing_postal_code VARCHAR(20) NULL,
        bank_name VARCHAR(160) NULL,
        bank_account VARCHAR(80) NULL,
        phone VARCHAR(60) NULL,
        email VARCHAR(160) NULL,
        address VARCHAR(255) NULL,
        legal_representative_name VARCHAR(180) NULL,
        legal_representative_role VARCHAR(120) NULL,
        anaf_last_lookup_at DATETIME NULL,
        anaf_raw_response LONGTEXT NULL,
        notes TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$clientColumns = [
    'client_type' => "VARCHAR(20) NOT NULL DEFAULT 'company'",
    'name' => "VARCHAR(180) NOT NULL",
    'fiscal_code' => "VARCHAR(30) NULL",
    'registry_number' => "VARCHAR(100) NULL",
    'registered_address' => "VARCHAR(255) NULL",
    'billing_country' => "VARCHAR(80) NULL",
    'billing_county' => "VARCHAR(120) NULL",
    'billing_city' => "VARCHAR(120) NULL",
    'billing_sector' => "VARCHAR(80) NULL",
    'billing_address_line' => "VARCHAR(255) NULL",
    'billing_postal_code' => "VARCHAR(20) NULL",
    'registered_surface_value' => "DECIMAL(12,2) NULL",
    'registered_surface_unit' => "VARCHAR(20) NOT NULL DEFAULT 'mp'",
    'bank_name' => "VARCHAR(160) NULL",
    'bank_account' => "VARCHAR(80) NULL",
    'phone' => "VARCHAR(60) NULL",
    'email' => "VARCHAR(160) NULL",
    'address' => "VARCHAR(255) NULL",
    'legal_representative_name' => "VARCHAR(180) NULL",
    'legal_representative_role' => "VARCHAR(120) NULL",
    'anaf_last_lookup_at' => "DATETIME NULL",
    'anaf_raw_response' => "LONGTEXT NULL",
    'notes' => "TEXT NULL",
    'sms_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
    'sms_opt_out_reason' => "VARCHAR(255) NULL",
    'sms_opt_out_at' => "DATETIME NULL",
    'active' => "TINYINT(1) NOT NULL DEFAULT 1",
    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
];

foreach ($clientColumns as $column => $definition) {
    c_ensure_column($pdo, 'clients', $column, $definition);
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS client_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        location_name VARCHAR(180) NOT NULL DEFAULT 'Punct de lucru',
        address VARCHAR(255) NULL,
        surface_value DECIMAL(12,2) NULL,
        surface_unit VARCHAR(20) NOT NULL DEFAULT 'mp',
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

$locationColumns = [
    'client_id' => "INT NOT NULL",
    'location_name' => "VARCHAR(180) NOT NULL DEFAULT 'Punct de lucru'",
    'address' => "VARCHAR(255) NULL",
    'surface_value' => "DECIMAL(12,2) NULL",
    'surface_unit' => "VARCHAR(20) NOT NULL DEFAULT 'mp'",
    'contact_person' => "VARCHAR(180) NULL",
    'phone' => "VARCHAR(60) NULL",
    'notes' => "TEXT NULL",
    'active' => "TINYINT(1) NOT NULL DEFAULT 1",
    'sort_order' => "INT NOT NULL DEFAULT 0",
    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
];

foreach ($locationColumns as $column => $definition) {
    c_ensure_column($pdo, 'client_locations', $column, $definition);
}

/*
|--------------------------------------------------------------------------
| POST handlers
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_sms') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $smsEnabled = (int)($_POST['sms_enabled'] ?? 1) === 1 ? 1 : 0;

        if ($clientId > 0) {
            $stmt = $pdo->prepare("
                UPDATE clients
                SET sms_enabled = ?,
                    sms_opt_out_reason = NULL,
                    sms_opt_out_at = CASE WHEN ? = 0 THEN NOW() ELSE NULL END
                WHERE id = ?
            ");
            $stmt->execute([$smsEnabled, $smsEnabled, $clientId]);
        }

        header('Location: clients.php?client_id=' . urlencode((string)$clientId) . '&sms_updated=1');
        exit;
    }

    if ($action === 'create' || $action === 'update') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $clientType = ($_POST['client_type'] ?? 'company') === 'individual' ? 'individual' : 'company';
        $name = c_clean_text($_POST['name'] ?? '');
        $fiscalCode = c_clean_fiscal_code($_POST['fiscal_code'] ?? '');
        $registryNumber = c_clean_text($_POST['registry_number'] ?? '');
        $billingCountry = c_clean_text($_POST['billing_country'] ?? 'Romania') ?: 'Romania';
        $billingCounty = c_clean_text($_POST['billing_county'] ?? '');
        $billingCity = c_clean_text($_POST['billing_city'] ?? '');
        $billingSector = c_clean_text($_POST['billing_sector'] ?? '');
        $billingAddressLine = c_clean_text($_POST['billing_address_line'] ?? '');
        $billingPostalCode = c_clean_text($_POST['billing_postal_code'] ?? '');
        $registeredAddress = c_clean_text($_POST['registered_address'] ?? '');
        $builtBillingAddress = c_build_billing_address([
            'billing_country' => $billingCountry,
            'billing_county' => $billingCounty,
            'billing_city' => $billingCity,
            'billing_sector' => $billingSector,
            'billing_address_line' => $billingAddressLine,
            'billing_postal_code' => $billingPostalCode,
        ]);
        if ($registeredAddress === '') {
            $registeredAddress = $builtBillingAddress;
        }
        $registeredSurfaceValue = null;
        $registeredSurfaceUnit = 'mp';
        $bankName = c_clean_text($_POST['bank_name'] ?? '');
        $bankAccount = c_clean_text($_POST['bank_account'] ?? '');
        $phone = c_clean_phone($_POST['phone'] ?? '');
        $email = trim((string)($_POST['email'] ?? ''));
        $legalRepresentativeName = c_clean_text($_POST['legal_representative_name'] ?? '');
        $legalRepresentativeRole = c_clean_text($_POST['legal_representative_role'] ?? '');
        $notes = trim((string)($_POST['notes'] ?? ''));
        $anafRawResponse = trim((string)($_POST['anaf_raw_response'] ?? ''));
        $anafLastLookupAt = trim((string)($_POST['anaf_last_lookup_at'] ?? ''));
        // Status activ/inactiv din toggle (default 1 = activ pentru clienții noi)
        $clientActive = isset($_POST['active']) && (string)$_POST['active'] === '1' ? 1 : 0;
        // SMS activ/inactiv din toggle (default 1 = SMS activate pentru clienții noi)
        $smsEnabledFromForm = isset($_POST['sms_enabled']) && (string)$_POST['sms_enabled'] === '1' ? 1 : 0;

        if ($clientType === 'individual') {
            $legalRepresentativeName = '';
            $legalRepresentativeRole = '';
        }

        if ($clientType === 'company' && $legalRepresentativeRole === '') {
            $legalRepresentativeRole = 'Administrator';
        }

        $locationIds = $_POST['location_id'] ?? [];
        $locationNames = $_POST['location_name'] ?? [];
        $locationAddresses = $_POST['location_address'] ?? [];
        $locationSurfaces = $_POST['location_surface_value'] ?? [];
        $locationSurfaceUnits = $_POST['location_surface_unit'] ?? [];
        $locationContacts = $_POST['location_contact_person'] ?? [];
        $locationPhones = $_POST['location_phone'] ?? [];
        $locationNotes = $_POST['location_notes'] ?? [];
        $locationActive = $_POST['location_active'] ?? [];

        if (!is_array($locationIds)) $locationIds = [];
        if (!is_array($locationNames)) $locationNames = [];
        if (!is_array($locationAddresses)) $locationAddresses = [];
        if (!is_array($locationSurfaces)) $locationSurfaces = [];
        if (!is_array($locationSurfaceUnits)) $locationSurfaceUnits = [];
        if (!is_array($locationContacts)) $locationContacts = [];
        if (!is_array($locationPhones)) $locationPhones = [];
        if (!is_array($locationNotes)) $locationNotes = [];
        if (!is_array($locationActive)) $locationActive = [];

        $maxRows = max(
            count($locationIds),
            count($locationNames),
            count($locationAddresses),
            count($locationSurfaces),
            count($locationSurfaceUnits),
            count($locationContacts),
            count($locationPhones),
            count($locationNotes),
            count($locationActive)
        );

        $locationsToSave = [];
        $activeLocationCount = 0;

        for ($i = 0; $i < $maxRows; $i++) {
            $locId = (int)($locationIds[$i] ?? 0);
            $locActive = (int)($locationActive[$i] ?? 1) === 1 ? 1 : 0;
            $locName = c_clean_text($locationNames[$i] ?? '');
            $locAddress = c_clean_text($locationAddresses[$i] ?? '');
            $locSurfaceValue = c_decimal_nullable($locationSurfaces[$i] ?? '');
            $locSurfaceUnit = c_clean_surface_unit($locationSurfaceUnits[$i] ?? 'mp');
            $locContact = c_clean_text($locationContacts[$i] ?? '');
            $locPhone = c_clean_phone($locationPhones[$i] ?? '');
            $locNotes = trim((string)($locationNotes[$i] ?? ''));

            if ($locId <= 0 && $locActive === 0) {
                continue;
            }

            if ($locActive === 1) {
                $activeLocationCount++;

                if ($locName === '' || $locAddress === '' || $locContact === '' || $locPhone === '' || $locSurfaceValue === null) {
                    header('Location: clients.php?error=missing_location_required');
                    exit;
                }
            }

            $locationsToSave[] = [
                'id' => $locId,
                'active' => $locActive,
                'name' => $locName,
                'address' => $locAddress,
                'surface_value' => $locSurfaceValue,
                'surface_unit' => $locSurfaceUnit ?: 'mp',
                'contact' => $locContact,
                'phone' => $locPhone,
                'notes' => $locNotes,
            ];
        }

        if ($name === '') {
            header('Location: clients.php?error=missing_name');
            exit;
        }

        if ($fiscalCode === '') {
            header('Location: clients.php?error=missing_fiscal_code');
            exit;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header('Location: clients.php?error=missing_email');
            exit;
        }

        if ($phone === '') {
            header('Location: clients.php?error=missing_phone');
            exit;
        }

        if ($billingCountry === '' || $billingCounty === '' || $billingCity === '' || $billingAddressLine === '' || $registeredAddress === '') {
            header('Location: clients.php?error=missing_registered_address');
            exit;
        }

        if ($clientType === 'company' && ($legalRepresentativeName === '' || $legalRepresentativeRole === '')) {
            header('Location: clients.php?error=missing_rep');
            exit;
        }

        if ($activeLocationCount < 1) {
            header('Location: clients.php?error=missing_location');
            exit;
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare("
                INSERT INTO clients
                (
                    client_type,
                    name,
                    fiscal_code,
                    registry_number,
                    registered_address,
                    billing_country,
                    billing_county,
                    billing_city,
                    billing_sector,
                    billing_address_line,
                    billing_postal_code,
                    registered_surface_value,
                    registered_surface_unit,
                    bank_name,
                    bank_account,
                    phone,
                    email,
                    address,
                    legal_representative_name,
                    legal_representative_role,
                    anaf_last_lookup_at,
                    anaf_raw_response,
                    notes,
                    active,
                    sms_enabled
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $clientType,
                $name,
                $fiscalCode ?: null,
                $registryNumber ?: null,
                $registeredAddress ?: null,
                $billingCountry ?: null,
                $billingCounty ?: null,
                $billingCity ?: null,
                $billingSector ?: null,
                $billingAddressLine ?: null,
                $billingPostalCode ?: null,
                $registeredSurfaceValue,
                $registeredSurfaceUnit,
                $bankName ?: null,
                $bankAccount ?: null,
                $phone ?: null,
                $email ?: null,
                $registeredAddress ?: null,
                $legalRepresentativeName ?: null,
                $legalRepresentativeRole ?: null,
                $anafLastLookupAt ?: null,
                $anafRawResponse ?: null,
                $notes ?: null,
                $clientActive,
                $smsEnabledFromForm,
            ]);

            $clientId = (int)$pdo->lastInsertId();
            $redirectParam = 'created=1';
        } else {
            if ($clientId <= 0) {
                header('Location: clients.php?error=invalid_client');
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE clients
                SET client_type = ?,
                    name = ?,
                    fiscal_code = ?,
                    registry_number = ?,
                    registered_address = ?,
                    billing_country = ?,
                    billing_county = ?,
                    billing_city = ?,
                    billing_sector = ?,
                    billing_address_line = ?,
                    billing_postal_code = ?,
                    registered_surface_value = ?,
                    registered_surface_unit = ?,
                    bank_name = ?,
                    bank_account = ?,
                    phone = ?,
                    email = ?,
                    address = ?,
                    legal_representative_name = ?,
                    legal_representative_role = ?,
                    anaf_last_lookup_at = ?,
                    anaf_raw_response = ?,
                    notes = ?,
                    active = ?,
                    sms_enabled = ?,
                    sms_opt_out_reason = NULL,
                    sms_opt_out_at = CASE WHEN ? = 0 THEN NOW() ELSE NULL END
                WHERE id = ?
            ");

            $stmt->execute([
                $clientType,
                $name,
                $fiscalCode ?: null,
                $registryNumber ?: null,
                $registeredAddress ?: null,
                $billingCountry ?: null,
                $billingCounty ?: null,
                $billingCity ?: null,
                $billingSector ?: null,
                $billingAddressLine ?: null,
                $billingPostalCode ?: null,
                $registeredSurfaceValue,
                $registeredSurfaceUnit,
                $bankName ?: null,
                $bankAccount ?: null,
                $phone ?: null,
                $email ?: null,
                $registeredAddress ?: null,
                $legalRepresentativeName ?: null,
                $legalRepresentativeRole ?: null,
                $anafLastLookupAt ?: null,
                $anafRawResponse ?: null,
                $notes ?: null,
                $clientActive,
                $smsEnabledFromForm,
                $smsEnabledFromForm,
                $clientId,
            ]);

            $redirectParam = 'updated=1';
        }

        foreach ($locationsToSave as $i => $locationToSave) {
            if ((int)$locationToSave['id'] > 0) {
                $stmt = $pdo->prepare("
                    UPDATE client_locations
                    SET location_name = ?,
                        address = ?,
                        surface_value = ?,
                        surface_unit = ?,
                        contact_person = ?,
                        phone = ?,
                        notes = ?,
                        active = ?,
                        sort_order = ?
                    WHERE id = ?
                      AND client_id = ?
                ");
                $stmt->execute([
                    $locationToSave['name'] !== '' ? $locationToSave['name'] : 'Punct de lucru',
                    $locationToSave['address'] ?: null,
                    $locationToSave['surface_value'],
                    $locationToSave['surface_unit'],
                    $locationToSave['contact'] ?: null,
                    $locationToSave['phone'] ?: null,
                    $locationToSave['notes'] ?: null,
                    $locationToSave['active'],
                    $i,
                    $locationToSave['id'],
                    $clientId,
                ]);
            } else {
                if ((int)$locationToSave['active'] === 0) {
                    continue;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO client_locations
                    (
                        client_id,
                        location_name,
                        address,
                        surface_value,
                        surface_unit,
                        contact_person,
                        phone,
                        notes,
                        active,
                        sort_order
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                ");
                $stmt->execute([
                    $clientId,
                    $locationToSave['name'] !== '' ? $locationToSave['name'] : 'Punct de lucru',
                    $locationToSave['address'] ?: null,
                    $locationToSave['surface_value'],
                    $locationToSave['surface_unit'],
                    $locationToSave['contact'] ?: null,
                    $locationToSave['phone'] ?: null,
                    $locationToSave['notes'] ?: null,
                    $i,
                ]);
            }
        }

        header('Location: clients.php?client_id=' . urlencode((string)$clientId) . '&' . $redirectParam);
        exit;
    }

    if ($action === 'permanent_delete') {
        $clientId = (int)($_POST['client_id'] ?? 0);

        if ($clientId <= 0) {
            header('Location: clients.php?error=invalid_client');
            exit;
        }

        $appointmentsCount = 0;
        $tasksCount = 0;

        if (c_table_exists($pdo, 'appointments')) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM appointments WHERE client_id = ?");
            $stmt->execute([$clientId]);
            $appointmentsCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        }

        if (c_table_exists($pdo, 'tasks')) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM tasks WHERE client_id = ?");
            $stmt->execute([$clientId]);
            $tasksCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        }

        if ($appointmentsCount > 0 || $tasksCount > 0) {
            header('Location: clients.php?client_id=' . urlencode((string)$clientId) . '&delete_blocked=1');
            exit;
        }

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("DELETE FROM client_locations WHERE client_id = ?");
            $stmt->execute([$clientId]);

            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);

            $pdo->commit();

            header('Location: clients.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            header('Location: clients.php?client_id=' . urlencode((string)$clientId) . '&delete_error=1');
            exit;
        }
    }

    if ($action === 'deactivate') {
        $clientId = (int)($_POST['client_id'] ?? 0);

        if ($clientId > 0) {
            $stmt = $pdo->prepare("UPDATE clients SET active = 0 WHERE id = ?");
            $stmt->execute([$clientId]);
        }

        header('Location: clients.php?deactivated=1');
        exit;
    }

    if ($action === 'reactivate') {
        $clientId = (int)($_POST['client_id'] ?? 0);

        if ($clientId > 0) {
            $stmt = $pdo->prepare("UPDATE clients SET active = 1 WHERE id = ?");
            $stmt->execute([$clientId]);
        }

        header('Location: clients.php?client_id=' . urlencode((string)$clientId) . '&reactivated=1');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Date pagina
|--------------------------------------------------------------------------
*/
$search = trim((string)($_GET['q'] ?? ''));
$legacyShowInactive = (int)($_GET['inactive'] ?? 0) === 1;
$statusFilter = (string)($_GET['status'] ?? ($legacyShowInactive ? 'all' : 'active'));
$typeFilter = (string)($_GET['type'] ?? 'all');
$selectedClientId = (int)($_GET['client_id'] ?? 0);

$allowedStatuses = ['active', 'inactive', 'all'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'active';
}

$allowedTypes = ['all', 'company', 'individual'];
if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = 'all';
}

$allowedPerPage = [20, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 20;
}

$page = max(1, (int)($_GET['page'] ?? 1));

$whereParts = ['1=1'];
$params = [];

if ($statusFilter === 'active') {
    $whereParts[] = 'c.active = 1';
} elseif ($statusFilter === 'inactive') {
    $whereParts[] = 'c.active = 0';
}

if ($typeFilter === 'company') {
    $whereParts[] = "c.client_type <> 'individual'";
} elseif ($typeFilter === 'individual') {
    $whereParts[] = "c.client_type = 'individual'";
}

if ($search !== '') {
    $whereParts[] = "(
        c.name LIKE ?
        OR c.legal_representative_name LIKE ?
        OR EXISTS (
            SELECT 1
            FROM client_locations cl_search
            WHERE cl_search.client_id = c.id
              AND cl_search.contact_person LIKE ?
        )
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where = 'WHERE ' . implode(' AND ', $whereParts);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c {$where}");
$countStmt->execute($params);
$totalClients = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalClients / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$contractsCountSql = c_table_exists($pdo, 'contracts')
    ? "(SELECT COUNT(*) FROM contracts cc WHERE cc.client_id = c.id)"
    : "0";

$stmt = $pdo->prepare("
    SELECT
        c.*,
        COUNT(DISTINCT CASE WHEN l.active = 1 THEN l.id END) AS locations_count,
        COUNT(DISTINCT CASE WHEN t.status IN ('de_programat', 'contactat', 'amanat') AND t.recurrence_stopped = 0 THEN t.id END) AS active_tasks_count,
        COUNT(DISTINCT a.id) AS appointments_count,
        COUNT(DISTINCT CASE WHEN a.status = 'finalizata' THEN a.id END) AS completed_appointments_count,
        {$contractsCountSql} AS contracts_count
    FROM clients c
    LEFT JOIN client_locations l ON l.client_id = c.id
    LEFT JOIN tasks t ON t.client_id = c.id
    LEFT JOIN appointments a ON a.client_id = c.id
    {$where}
    GROUP BY c.id
    ORDER BY c.active DESC, c.name ASC, c.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$showInactive = $statusFilter !== 'active';
$listBaseQuery = [
    'q' => $search,
    'status' => $statusFilter,
    'type' => $typeFilter,
    'per_page' => $perPage,
];
$listBaseQuery = array_filter($listBaseQuery, static function ($v) {
    return $v !== '' && $v !== null;
});
$fromResult = $totalClients > 0 ? $offset + 1 : 0;
$toResult = min($offset + count($clients), $totalClients);

$selectedClient = null;
$selectedLocations = [];
$selectedAppointments = [];
$selectedTasks = [];

if ($selectedClientId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$selectedClientId]);
    $selectedClient = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedClient) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM client_locations
            WHERE client_id = ?
            ORDER BY active DESC, sort_order ASC, location_name ASC, id ASC
        ");
        $stmt->execute([$selectedClientId]);
        $selectedLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (c_table_exists($pdo, 'appointments')) {
            $stmt = $pdo->prepare("
                SELECT
                    a.*,
                    l.location_name,
                    tm.name AS team_name
                FROM appointments a
                LEFT JOIN client_locations l ON l.id = a.client_location_id
                LEFT JOIN team_members tm ON tm.id = a.team_member_id
                WHERE a.client_id = ?
                ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC
                LIMIT 8
            ");
            $stmt->execute([$selectedClientId]);
            $selectedAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (c_table_exists($pdo, 'tasks')) {
            $stmt = $pdo->prepare("
                SELECT
                    t.*,
                    l.location_name
                FROM tasks t
                LEFT JOIN client_locations l ON l.id = t.client_location_id
                WHERE t.client_id = ?
                  AND t.status IN ('de_programat', 'contactat', 'amanat')
                  AND t.recurrence_stopped = 0
                ORDER BY t.due_date ASC, t.id ASC
                LIMIT 8
            ");
            $stmt->execute([$selectedClientId]);
            $selectedTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Mapper unic pentru forma datelor de client folosite în JS (clientsData).
// Folosit atât pentru lista paginată, cât și pentru clientul țintit prin
// ?client_id=… (necesar ca să poată fi editat chiar dacă nu apare pe pagina curentă
// din cauza paginării / filtrelor).
$c_map_client_for_js = function (array $client, array $locations): array {
    return [
        'id' => (int)$client['id'],
        'client_type' => $client['client_type'] ?? 'company',
        'name' => $client['name'] ?? '',
        'fiscal_code' => $client['fiscal_code'] ?? '',
        'registry_number' => $client['registry_number'] ?? '',
        'registered_address' => c_client_address($client),
        'billing_country' => $client['billing_country'] ?? 'Romania',
        'billing_county' => $client['billing_county'] ?? '',
        'billing_city' => $client['billing_city'] ?? '',
        'billing_sector' => $client['billing_sector'] ?? '',
        'billing_address_line' => $client['billing_address_line'] ?? '',
        'billing_postal_code' => $client['billing_postal_code'] ?? '',
        'registered_surface_value' => $client['registered_surface_value'] ?? '',
        'registered_surface_unit' => $client['registered_surface_unit'] ?? 'mp',
        'bank_name' => $client['bank_name'] ?? '',
        'bank_account' => $client['bank_account'] ?? '',
        'phone' => $client['phone'] ?? '',
        'email' => $client['email'] ?? '',
        'legal_representative_name' => $client['legal_representative_name'] ?? '',
        'legal_representative_role' => $client['legal_representative_role'] ?? '',
        'anaf_last_lookup_at' => $client['anaf_last_lookup_at'] ?? '',
        'anaf_raw_response' => $client['anaf_raw_response'] ?? '',
        'notes' => $client['notes'] ?? '',
        'active' => (int)($client['active'] ?? 1),
        'client_status' => $client['client_status'] ?? '',
        'sms_enabled' => (int)($client['sms_enabled'] ?? 1),
        'locations_count' => (int)($client['locations_count'] ?? 0),
        'contracts_count' => (int)($client['contracts_count'] ?? 0),
        'appointments_count' => (int)($client['appointments_count'] ?? 0),
        'completed_appointments_count' => (int)($client['completed_appointments_count'] ?? 0),
        'active_tasks_count' => (int)($client['active_tasks_count'] ?? 0),
        'locations' => array_map(function ($location) {
            return [
                'id' => (int)$location['id'],
                'location_name' => $location['location_name'] ?? '',
                'address' => $location['address'] ?? '',
                'surface_value' => $location['surface_value'] ?? '',
                'surface_unit' => $location['surface_unit'] ?? 'mp',
                'contact_person' => $location['contact_person'] ?? '',
                'phone' => $location['phone'] ?? '',
                'notes' => $location['notes'] ?? '',
                'active' => (int)($location['active'] ?? 1),
            ];
        }, $locations),
    ];
};

$clientsForJs = [];
foreach ($clients as $client) {
    $clientId = (int)$client['id'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM client_locations
        WHERE client_id = ?
        ORDER BY active DESC, sort_order ASC, location_name ASC, id ASC
    ");
    $stmt->execute([$clientId]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clientsForJs[$clientId] = $c_map_client_for_js($client, $locations);
}

// Injectează clientul țintit prin ?client_id=… în clientsData, chiar dacă nu apare
// în lista paginată (pentru ca modalul de Editează să poată fi deschis de oriunde).
if ($selectedClient && $selectedClientId > 0 && !isset($clientsForJs[$selectedClientId])) {
    $clientsForJs[$selectedClientId] = $c_map_client_for_js($selectedClient, $selectedLocations);
}

$shouldOpenCreate = isset($_GET['open_create']) && $_GET['open_create'] === '1';
$shouldOpenEditClientId = (isset($_GET['open_edit']) && $_GET['open_edit'] === '1' && $selectedClientId > 0) ? $selectedClientId : 0;
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Clienți - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

<style>
.clients-topbar { align-items: center; padding: 12px 20px; }
.clients-toolbar { width: 100%; display: grid; grid-template-columns: 96px 132px 132px auto minmax(220px, 1fr) auto auto; gap: 8px; align-items: center; }
.clients-search { width: 100%; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; }
.clients-search input { height: 42px; min-width: 0; }
.clients-search .btn, .clients-toolbar > .btn { height: 42px; justify-content: center; white-space: nowrap; }
.clients-page-title { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.clients-page-title h1 { margin: 0; font-size: 20px; line-height: 1.2; font-weight: 800; color: var(--text); letter-spacing: 0; }
.clients-count-pill { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 30px; padding: 0 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); font-size: 13px; font-weight: 800; }
.clients-layout { display: grid; grid-template-columns: <?= $selectedClient ? 'minmax(280px, .75fr) minmax(0, 1.25fr)' : '1fr' ?>; gap: 14px; align-items: start; }
.clients-list-card, .client-profile-card, .panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow); overflow: hidden; }
.card-head { padding: 15px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.card-title { color: var(--text); font-size: 16px; font-weight: 900; }
.card-subtitle { color: var(--muted); font-size: 12px; font-weight: 750; margin-top: 2px; }
.client-list { display: grid; gap: 0; }
.client-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 14px 16px; border-bottom: 1px solid var(--border2); text-decoration: none; color: inherit; background: var(--surface); transition: background .12s; }
.client-row:last-child { border-bottom: none; }
.client-row:hover { background: var(--surface-soft); }
.client-row.active { background: var(--accent-soft); }
.client-main { min-width: 0; }
.client-title-line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.client-name { color: var(--text); font-size: 15px; font-weight: 900; overflow-wrap: anywhere; }
.type-pill, .status-pill { display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 999px; background: var(--surface-soft); border: 1px solid var(--border2); color: var(--text); font-size: 11px; font-weight: 900; }
.status-pill.inactive { color: #7a8796; }
.client-meta { margin-top: 5px; color: var(--muted); font-size: 12px; font-weight: 750; line-height: 1.45; overflow-wrap: anywhere; }
.client-actions { display: flex; align-items: center; gap: 7px; }
.client-actions .btn { min-height: 34px; font-size: 12px; padding: 6px 10px; }
.profile-header { padding: 18px; border-bottom: 1px solid var(--border); background: var(--surface-soft); }
.profile-title-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.profile-title { color: var(--text); font-size: 22px; font-weight: 900; letter-spacing: -.03em; margin: 0; }
.profile-sub { color: var(--muted); font-size: 13px; font-weight: 750; margin-top: 4px; }
.profile-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.profile-actions .sms-toggle-form { display: contents; margin: 0; }
.profile-body { padding: 16px; display: grid; gap: 14px; }
.info-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.info-box { border: 1px solid var(--border2); border-radius: 14px; padding: 12px; background: var(--surface); min-width: 0; }
.info-label { color: var(--muted); font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: .05em; }
.info-value { color: var(--text); font-size: 14px; font-weight: 800; margin-top: 4px; line-height: 1.35; overflow-wrap: anywhere; }
.section-title { color: var(--text); font-size: 15px; font-weight: 900; margin-bottom: 10px; }
.location-list, .history-list { display: grid; gap: 9px; }
.location-item, .history-item { border: 1px solid var(--border2); border-radius: 14px; padding: 12px; background: var(--surface); }
.location-top, .history-top { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
.location-name, .history-title { color: var(--text); font-weight: 900; font-size: 14px; }
.location-meta, .history-meta { color: var(--muted); font-size: 12px; font-weight: 750; line-height: 1.45; margin-top: 4px; overflow-wrap: anywhere; }
.empty-state { padding: 26px 16px; text-align: center; color: var(--muted); font-weight: 800; }
.form-section {
    background: #fff;
    border: 1px solid var(--border);
    border-left: 3px solid var(--accent);
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 16px;
    box-shadow: var(--shadow);
    transition: box-shadow .15s ease, border-color .15s ease;
}
.form-section:hover { box-shadow: var(--shadow-md); }
.form-section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text);
    font-size: 15px;
    font-weight: 800;
    letter-spacing: -.01em;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border2);
}
.form-section-title::before {
    content: '';
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--accent);
    flex-shrink: 0;
}

/* === Toggle switch Activ/Inactiv === */
.status-toggle-row {
    display: flex;
    align-items: center;
    gap: 14px;
}
.status-toggle {
    position: relative;
    display: inline-block;
    width: 52px;
    height: 28px;
    flex-shrink: 0;
}
.status-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}
.status-toggle .slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background: var(--border);
    border-radius: 999px;
    transition: background .18s ease;
}
.status-toggle .slider::before {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 22px;
    height: 22px;
    background: #fff;
    border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,.18);
    transition: transform .18s ease;
}
.status-toggle input:checked + .slider {
    background: var(--tone-success);
}
.status-toggle input:checked + .slider::before {
    transform: translateX(24px);
}
.status-toggle-label {
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
}
.status-toggle-meta {
    font-size: 12px;
    font-weight: 500;
    color: var(--muted);
    margin-top: 2px;
}
.status-toggle-state {
    margin-left: auto;
    padding: 4px 14px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    background: var(--tone-success-soft);
    color: var(--tone-success);
    border: 1px solid rgba(4,120,87,.22);
    white-space: nowrap;
    flex-shrink: 0;
    min-width: 70px;
    text-align: center;
}
.status-toggle-state.is-inactive {
    background: var(--surface-soft);
    color: var(--muted);
    border-color: var(--border);
}
.type-switch { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
.type-option { border: 1px solid var(--border); border-radius: 14px; padding: 10px; cursor: pointer; background: var(--surface); color: var(--text); font-weight: 900; text-align: center; }
.type-option.active { background: var(--accent); border-color: var(--accent); color: #fff; }
.location-form-list { display: grid; gap: 10px; }
.location-form-row { border: 1px solid var(--border2); border-radius: 16px; padding: 12px; background: var(--surface-soft); }
.location-row-head { display: flex; justify-content: space-between; gap: 8px; align-items: center; margin-bottom: 10px; }
.location-row-title { color: var(--text); font-weight: 900; font-size: 13px; }
.anaf-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: end; }
.anaf-message { margin-top: 8px; font-size: 12px; font-weight: 800; color: var(--muted); }
.anaf-message.ok { color: #166534; }
.anaf-message.bad { color: #991b1b; }
.client-danger-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.client-danger-actions form { margin: 0; }
.client-danger-actions .btn { min-height: 42px; }
@media(max-width: 1100px) { .clients-layout { grid-template-columns: 1fr; } .info-grid { grid-template-columns: 1fr; } }
@media(max-width: 860px) {
    body { overflow-x: hidden !important; }
    .clients-topbar { width: 100% !important; max-width: 100vw !important; padding: 8px 10px 12px !important; overflow-x: hidden !important; display: block !important; position: relative !important; top: auto !important; }
    .clients-toolbar { grid-template-columns: 1fr !important; gap: 8px !important; }
    .clients-search { grid-template-columns: 1fr !important; }
    .clients-search .btn, .clients-toolbar > .btn { width: 100%; min-width: 0; }
    .content { width: 100% !important; max-width: 100vw !important; overflow-x: hidden !important; }
    .clients-hero { padding: 18px; }
    .client-row { grid-template-columns: 1fr; }
    .client-actions { display: grid; grid-template-columns: 1fr 1fr; width: 100%; }
    .client-actions .btn { width: 100%; justify-content: center; }
    .profile-actions { display: grid; grid-template-columns: 1fr; width: 100%; }
    .profile-actions .btn { width: 100%; justify-content: center; }
    .anaf-row { grid-template-columns: 1fr; }
    .type-switch { grid-template-columns: 1fr; }
}

/* Lista contacte - tabel compact */
.clients-layout { grid-template-columns: 1fr !important; }
.clients-topbar { align-items: center; padding: 12px 20px; }
.clients-toolbar { width: 100%; display: grid; grid-template-columns: 96px 132px 132px auto minmax(220px, 1fr) auto auto; gap: 8px; align-items: center; }
.clients-toolbar input, .clients-toolbar select { height: 34px; min-height: 34px; min-width: 0; border-radius: 4px; font-size: 12.5px; }
.clients-toolbar .btn { min-height: 34px; height: 34px; border-radius: 4px; justify-content: center; white-space: nowrap; font-size: 12.5px; padding: 0 12px; }
.clients-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.clients-table { width: 100%; border-collapse: collapse; table-layout: fixed; min-width: 1120px; }
.clients-table th, .clients-table td { padding: 8px 9px; border-bottom: 1px solid var(--border2); vertical-align: middle; text-align: left; }
.clients-table th { background: #F8FAFC; color: var(--muted); font-size: 11px; font-weight: 800; text-transform: none; letter-spacing: 0; white-space: nowrap; }
.clients-table td { color: var(--text); font-size: 12.5px; font-weight: 600; line-height: 1.28; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.clients-table tbody tr { cursor: default; }
.clients-table tbody tr:hover td { background: rgba(37, 99, 235, .035); }
.clients-table th:nth-child(1), .clients-table td:nth-child(1) { width: 74px; text-align: right; color: var(--muted); }
.clients-table th:nth-child(2), .clients-table td:nth-child(2) { width: 25%; }
.clients-table th:nth-child(3), .clients-table td:nth-child(3) { width: 62px; text-align: center; }
.clients-table th:nth-child(4), .clients-table td:nth-child(4) { width: 118px; }
.clients-table th:nth-child(5), .clients-table td:nth-child(5) { width: 15%; }
.clients-table th:nth-child(6), .clients-table td:nth-child(6) { width: 116px; }
.clients-table th:nth-child(7), .clients-table td:nth-child(7) { width: 18%; }
.clients-table th:nth-child(8), .clients-table td:nth-child(8) { width: 120px; }
.clients-table th:nth-child(9), .clients-table td:nth-child(9) { width: 70px; text-align: center; }
.client-cell-title { font-weight: 750; color: var(--text); font-size: 12.8px; line-height: 1.25; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.client-id-cell { font-family: "DM Mono", ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; color: var(--muted); }
.type-pill, .status-pill { font-size: 10px; padding: 3px 7px; }
.client-status-badge { display: inline-flex; align-items: center; justify-content: center; padding: 3px 8px; border-radius: 999px; font-size: 10px; font-weight: 900; border: 1px solid #bbf7d0; background: #ecfdf5; color: #047857; white-space: nowrap; }
.client-status-badge.inactive { border-color: #e5e7eb; background: #f8fafc; color: #64748b; }
.client-status-badge.season { border-color: #fde68a; background: #fffbeb; color: #92400e; }
.client-row-actions { display: flex; align-items: center; justify-content: center; gap: 5px; flex-wrap: nowrap; white-space: nowrap; }
.icon-action { flex: 0 0 28px; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border2); border-radius: 4px; background: #fff; color: #64748b; text-decoration: none; cursor: pointer; transition: .18s ease; padding: 0; }
.icon-action:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); }
.icon-action.is-primary { background: var(--accent-soft); border-color: rgba(37, 99, 235, .28); color: var(--accent); }
.icon-action.is-primary:hover { background: var(--accent); border-color: var(--accent); color: #fff; }
.icon-action svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.icon-action:focus { outline: none; box-shadow: var(--focus-ring); }

/* === Kebab menu (...) - meniu cu actiunile secundare === */
.row-menu { position: relative; display: inline-block; }
.row-menu-trigger { width: 30px; height: 30px; border-radius: 9px; border: 1px solid var(--border2); background: #fff; color: #64748b; cursor: pointer; padding: 0; display: inline-flex; align-items: center; justify-content: center; transition: .14s ease; }
.row-menu-trigger:hover { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
.row-menu-trigger svg { width: 14px; height: 14px; fill: currentColor; }
.row-menu.is-open .row-menu-trigger { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
.row-menu-dropdown { display: none; position: absolute; top: calc(100% + 4px); right: 0; min-width: 200px; background: #fff; border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow-lg); padding: 4px; z-index: 100; flex-direction: column; gap: 1px; animation: rowMenuIn .12s ease; }
.row-menu.is-open .row-menu-dropdown { display: flex; }
@keyframes rowMenuIn { from { opacity: 0; transform: translateY(-3px); } to { opacity: 1; transform: translateY(0); } }
.row-menu-item { display: grid; grid-template-columns: 26px 1fr; gap: 9px; align-items: center; padding: 8px 10px; border-radius: 7px; color: var(--text); text-decoration: none; font-size: 12.5px; font-weight: 600; cursor: pointer; border: 0; background: transparent; text-align: left; transition: background .12s ease; width: 100%; font-family: inherit; }
.row-menu-item:hover { background: var(--accent-soft); }
.row-menu-item svg { width: 14px; height: 14px; color: var(--muted); fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.row-menu-item:hover svg { color: var(--accent); }
.clients-pagination { padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; color: var(--muted); font-size: 13px; font-weight: 800; }
.page-buttons { display: flex; gap: 6px; align-items: center; }
.page-btn { min-width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 10px; text-decoration: none; color: var(--text); background: var(--surface); font-weight: 900; }
.page-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }
.page-btn.disabled { pointer-events: none; opacity: .45; }

@media(max-width: 1220px) {
    .clients-table { min-width: 1040px; }
    .clients-table th, .clients-table td { padding: 8px 7px; }
    .clients-table td { font-size: 11.5px; }
    .client-cell-title { font-size: 11.8px; }
    .icon-action { flex-basis: 28px; width: 28px; height: 28px; }
    .icon-action svg { width: 13.5px; height: 13.5px; }
}

@media(max-width: 980px) {
    .clients-toolbar { grid-template-columns: 1fr 1fr; }
    .clients-toolbar .search-input { grid-column: 1 / -1; }
}

@media(max-width: 760px) {
    .clients-topbar { padding: 10px 12px !important; }
    .clients-toolbar { grid-template-columns: 1fr 1fr !important; gap: 8px; }
    .clients-toolbar .search-input,
    .clients-toolbar .add-client-btn { grid-column: 1 / -1; }
    .clients-toolbar input, .clients-toolbar select,
    .clients-toolbar .btn { height: 40px; min-height: 40px; font-size: 13px; }
    .clients-page-title { padding: 18px 16px 10px; }
    .clients-page-title h1 { font-size: 28px; }
    .clients-list-card { border-radius: 8px; margin: 0 12px; }
    .clients-list-card .card-head { padding: 14px 16px; }
    .clients-list-card .card-title { font-size: 22px; }
    .clients-table-wrap { overflow-x: visible; }
    .clients-table { min-width: 0; width: 100%; border-collapse: separate; border-spacing: 0 8px; table-layout: auto; }
    .clients-table thead { display: none; }
    .clients-table tbody { display: grid; gap: 8px; padding: 10px; }
    .clients-table tr {
        position: relative;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px 12px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 8px;
        box-shadow: none;
        padding: 12px 12px 54px;
    }
    .clients-table td { display: block; width: auto !important; border-bottom: 0; padding: 0; font-size: 12.5px; line-height: 1.22; white-space: normal; overflow: visible; text-overflow: clip; text-align: left !important; font-weight: 800; }
    .clients-table td::before { content: attr(data-label); display: block; margin-bottom: 2px; color: var(--muted); font-size: 8px; font-weight: 900; text-transform: uppercase; letter-spacing: .06em; }
    .clients-table td:nth-child(1) { position: absolute; top: 11px; right: 12px; width: auto !important; text-align: right; }
    .clients-table td:nth-child(1)::before { display: none; }
    .clients-table td:nth-child(2) { grid-column: 1 / -1; padding-right: 46px; margin-bottom: 1px; }
    .clients-table td:nth-child(3), .clients-table td:nth-child(4),
    .clients-table td:nth-child(5), .clients-table td:nth-child(6),
    .clients-table td:nth-child(8) { min-width: 0; }
    .clients-table td:nth-child(7) { grid-column: 1 / -1; }
    .clients-table td:nth-child(9) { position: absolute; right: 12px; bottom: 12px; width: auto !important; padding: 0; }
    .clients-table td:nth-child(9)::before { display: none; }
    .client-cell-title { font-size: 15px; line-height: 1.16; font-weight: 900; white-space: normal; overflow-wrap: anywhere; }
    .client-id-cell { display: inline-flex; min-width: 28px; height: 28px; align-items: center; justify-content: center; border-radius: 6px; background: var(--surface-soft); color: var(--muted); font-size: 11px; }
    .type-pill { border-radius: 6px; }
    .client-row-actions { justify-content: flex-end; gap: 7px; overflow: visible; padding-bottom: 0; }
    .icon-action { flex: 0 0 38px; width: 38px; height: 38px; border-radius: 8px; }
    .icon-action svg { width: 16px; height: 16px; }
}




/* === Fișa rapida client - pop-up / panou rapid === */
.client-quick-modal { z-index: 1200; }
.client-quick-box { width: min(980px, calc(100vw - 32px)); max-height: calc(100vh - 44px); overflow: hidden; display: flex; flex-direction: column; }
.client-quick-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 18px 20px; border-bottom: 1px solid var(--border); background: linear-gradient(135deg, #ffffff, #f8fafc); }
.client-quick-title { margin: 0; font-size: 22px; font-weight: 900; letter-spacing: -.035em; color: var(--text); }
.client-quick-subtitle { margin-top: 4px; color: var(--muted); font-size: 13px; font-weight: 750; line-height: 1.35; }
.client-quick-body { padding: 18px 20px 20px; overflow: auto; }
.client-quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.client-quick-actions .btn { min-height: 38px; font-size: 12px; padding: 8px 11px; }
.client-quick-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); gap: 14px; }
.client-quick-card { border: 1px solid var(--border); border-radius: 16px; background: #fff; box-shadow: 0 8px 22px rgba(15,23,42,.04); overflow: hidden; }
.client-quick-card-head { padding: 13px 14px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.client-quick-card-title { font-size: 14px; font-weight: 900; color: var(--text); }
.client-quick-card-body { padding: 14px; }
.client-quick-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 12px; }
.quick-field { min-width: 0; }
.quick-label { color: var(--muted); font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 3px; }
.quick-value { color: var(--text); font-size: 12.5px; font-weight: 750; line-height: 1.35; overflow-wrap: anywhere; }
.quick-value.is-muted { color: var(--muted); font-weight: 700; }
.quick-kpi-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.quick-kpi { border: 1px solid var(--border2); border-radius: 14px; background: var(--surface-soft); padding: 11px 12px; }
.quick-kpi-value { font-size: 18px; font-weight: 900; color: var(--text); line-height: 1; }
.quick-kpi-label { margin-top: 4px; font-size: 10px; font-weight: 900; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
.quick-locations-list { display: grid; gap: 9px; }
.quick-location { border: 1px solid var(--border2); border-radius: 14px; padding: 11px 12px; background: #fff; }
.quick-location-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 6px; }
.quick-location-name { font-size: 13px; font-weight: 900; color: var(--text); line-height: 1.25; }
.quick-location-meta { color: var(--muted); font-size: 12px; font-weight: 700; line-height: 1.4; overflow-wrap: anywhere; }
.quick-empty { border: 1px dashed var(--border2); border-radius: 14px; background: var(--surface-soft); padding: 14px; color: var(--muted); font-weight: 750; font-size: 12.5px; }
@media (max-width: 820px) {
    .client-quick-grid { grid-template-columns: 1fr; }
    .client-quick-details { grid-template-columns: 1fr; }
    .client-quick-box { width: calc(100vw - 20px); max-height: calc(100vh - 20px); }
    .client-quick-header { padding: 15px; }
    .client-quick-body { padding: 14px; }
}


/* === Compactare formular client nou / editare client === */
#clientModal .modal-box {
    width: min(900px, calc(100vw - 28px));
    max-height: calc(100vh - 28px);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

#clientModal .modal-header {
    padding: 14px 18px;
    flex-shrink: 0;
}

#clientModal .modal-header h2 {
    font-size: 18px;
    line-height: 1.2;
}

#clientModal form {
    overflow: auto;
    padding: 12px 18px 16px;
}

#clientModal .form-section {
    padding: 12px 14px;
    margin-bottom: 10px;
    border-radius: 14px;
}

#clientModal .form-section-title {
    font-size: 14px;
    margin-bottom: 9px;
    padding-bottom: 8px;
}

#clientModal .type-switch {
    margin-bottom: 0;
}

#clientModal .type-option {
    padding: 8px 10px;
    min-height: 38px;
}

#clientModal .form-grid {
    gap: 9px 12px;
}

#clientModal input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
#clientModal select {
    min-height: 38px;
    height: 38px;
    padding-top: 0;
    padding-bottom: 0;
}

#clientModal textarea {
    min-height: 58px;
    padding-top: 10px;
    padding-bottom: 10px;
}

#clientModal #notes {
    min-height: 72px;
}

#clientModal .anaf-row {
    gap: 8px;
}

#clientModal .anaf-row .btn {
    min-height: 38px;
    height: 38px;
}

#clientModal .location-form-list {
    gap: 8px;
}

#clientModal .location-form-row {
    padding: 10px 12px;
    border-radius: 14px;
}

#clientModal .location-row-head {
    margin-bottom: 8px;
}

#clientModal .location-row-head .btn {
    min-height: 34px;
    height: 34px;
    padding: 0 12px;
    font-size: 12px;
}

#clientModal .client-add-location-action {
    display: flex;
    justify-content: flex-start;
    padding-top: 13px;
    margin-top: 13px;
    border-top: 1px solid var(--border2);
}

#clientModal .client-add-location-action .btn {
    min-height: 38px;
    height: 38px;
    padding: 0 17px;
}

#clientModal .status-toggle-row {
    gap: 10px;
}

#clientModal .status-toggle-row + .status-toggle-row {
    margin-top: 10px !important;
}

#clientModal .actions-row {
    padding-top: 4px;
    margin-top: 0;
}

#clientModal .actions-right .btn {
    min-height: 40px;
    height: 40px;
}

@media (max-width: 760px) {
    #clientModal .modal-box {
        width: calc(100vw - 18px);
        max-height: calc(100vh - 18px);
    }

    #clientModal .modal-header {
        padding: 12px 14px;
    }

    #clientModal form {
        padding: 10px 14px 14px;
    }

    #clientModal .form-section {
        padding: 11px 12px;
        margin-bottom: 9px;
    }

    #clientModal .form-grid {
        gap: 8px;
    }

    #clientModal .client-add-location-action {
        justify-content: center;
    }

    #clientModal .client-add-location-action .btn {
        width: 100%;
    }
}

/* ══ Design System v2.4 fixes ══ */
* { font-family: 'Inter', system-ui, -apple-system, sans-serif !important; }

/* Radius — max 8px conform DS */
.info-box, .location-item, .history-item,
.location-form-row, .type-option, .location-form-row,
.quick-location, .quick-kpi, .quick-empty, .client-quick-card {
    border-radius: var(--pz-r) !important;
}
.row-menu-dropdown, .client-status-badge, .type-pill,
.status-pill, .clients-count-pill, .page-btn { border-radius: var(--pz-rs) !important; }
.form-section { border-radius: 0 var(--pz-r) var(--pz-r) 0 !important; }
.status-toggle .slider { border-radius: 99px !important; }

/* Font-weight — max 700 */
.client-name, .card-title, .profile-title, .clients-page-title h1,
.info-label, .info-value, .section-title, .location-name, .history-title,
.client-cell-title, .form-section-title, .status-toggle-label,
.client-quick-title, .client-quick-card-title, .quick-kpi-value,
.quick-kpi-label, .quick-label, .quick-value, .quick-location-name,
.location-row-title, .clients-table th, .clients-table td,
.card-subtitle, .location-meta, .history-meta, .client-meta,
.quick-location-meta, .profile-sub, .clients-pagination {
    font-weight: 700 !important;
}
.clients-table td, .client-cell-title { font-weight: 600 !important; }
.clients-table th, .info-label, .quick-label, .quick-kpi-label { font-weight: 700 !important; }
.profile-title, .clients-page-title h1, .client-quick-title { font-weight: 700 !important; font-size: inherit; }

/* Culori corecte cu tokeni pz */
.client-status-badge { background: var(--pz-grs) !important; border-color: var(--pz-grb) !important; color: var(--pz-gr) !important; }
.client-status-badge.inactive { background: var(--pz-soft) !important; border-color: var(--pz-line) !important; color: var(--pz-mu) !important; }
.client-status-badge.season { background: var(--pz-ors) !important; border-color: var(--pz-orb) !important; color: var(--pz-or) !important; }

/* Status toggle: verde = --pz-gr-acc */
.status-toggle input:checked + .slider { background: var(--pz-gr-acc) !important; }

/* Fără gradient, fără shadow */
.client-quick-header { background: var(--pz-soft) !important; }
.client-quick-card { box-shadow: none !important; }
.row-menu-dropdown { box-shadow: none !important; border: 1px solid var(--pz-line) !important; }

/* Font ID coloană — monospace system */
.client-id-cell { font-family: 'Courier New', ui-monospace, monospace !important; font-weight: 500 !important; }

/* Icon actions — conform DS */
.icon-action { background: var(--pz-surf) !important; border-color: var(--pz-line) !important; color: var(--pz-mu) !important; }
.icon-action:hover { background: var(--pz-bls) !important; border-color: var(--pz-blb) !important; color: var(--pz-bl) !important; }
.icon-action.is-primary { background: var(--pz-bls) !important; border-color: var(--pz-blb) !important; color: var(--pz-bl) !important; }
.icon-action.is-primary:hover { background: var(--pz-bl) !important; border-color: var(--pz-bl) !important; color: #fff !important; }

/* Search preview wrap în clients-toolbar (grid layout) */
.clients-toolbar .pz-search-wrap { width: 100%; min-width: 0; }
.clients-toolbar .pz-search-wrap input { width: 100%; }
@media(max-width: 980px) {
    .clients-toolbar .pz-search-wrap { grid-column: 1 / -1; }
}
@media(max-width: 760px) {
    .clients-toolbar .pz-search-wrap { grid-column: 1 / -1; }
}
</style>
<?php render_search_preview_assets(); ?>
</head>

<body>
        <div class="layout">
    <?php render_sidebar('clients', $isAdmin); ?>

    <main class="main">
        <div class="topbar clients-topbar">
            <form method="get" class="clients-toolbar">
                <select name="per_page" aria-label="Rânduri pe pagină">
                    <?php foreach ([20, 50, 100] as $pp): ?>
                        <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="status" aria-label="Status client">
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Activ</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactiv</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Toate</option>
                </select>

                <select name="type" aria-label="Tip client">
                    <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>PJ + PF</option>
                    <option value="company" <?= $typeFilter === 'company' ? 'selected' : '' ?>>Doar PJ</option>
                    <option value="individual" <?= $typeFilter === 'individual' ? 'selected' : '' ?>>Doar PF</option>
                </select>

                <button class="btn" type="submit">Filtrează</button>
                <div class="pz-search-wrap">
                    <input class="search-input" type="text" id="clientsSearchInput" name="q" value="<?= c_h($search) ?>" placeholder="Caută client" autocomplete="off">
                    <div class="pz-search-preview"></div>
                </div>
                <a class="btn" href="clients.php" title="Resetare filtre" aria-label="Resetare filtre">↻</a>
                <a class="btn" href="clients_dedupe.php" title="Corelează telefonul și emailul între firme cu același reprezentant legal">🔗 Corelare reprezentanți</a>
            </form>
        </div>

        <?php if (isset($_GET['created'])): ?><div class="notice notice-success">Clientul a fost adaugat.</div><?php endif; ?>
        <?php if (isset($_GET['updated'])): ?><div class="notice notice-success">Fișa clientului a fost actualizată.</div><?php endif; ?>
        <?php if (isset($_GET['task_added'])): ?><div class="notice notice-success">Sarcină a fost adăugată clientului.</div><?php endif; ?>
        <?php if (isset($_GET['sms_updated'])): ?><div class="notice notice-success">Setarea pentru notificări SMS a fost actualizată.</div><?php endif; ?>
        <?php if (isset($_GET['deactivated'])): ?><div class="notice notice-warning">Clientul a fost dezactivat.</div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="notice notice-warning">Clientul a fost șters definitiv.</div><?php endif; ?>
        <?php if (isset($_GET['delete_blocked'])): ?><div class="notice notice-danger">Clientul nu poate fi șters definitiv deoarece are programări sau sarcini. Il poți dezactiva pentru a pastra istoricul.</div><?php endif; ?>
        <?php if (isset($_GET['delete_error'])): ?><div class="notice notice-danger">Clientul nu a putut fi șters. Verifica baza de date sau incearca dezactivarea.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'missing_name'): ?><div class="notice notice-danger">Completează denumirea / numele clientului.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'missing_rep'): ?><div class="notice notice-danger">Pentru persoană juridică trebuie completat reprezentantul legal.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'missing_fiscal_code'): ?><div class="notice notice-danger">Completează CUI / CNP.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'missing_email'): ?><div class="notice notice-danger">Completează o adresa de email valida.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'missing_phone'): ?><div class="notice notice-danger">Completează telefonul general.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'missing_registered_address'): ?><div class="notice notice-danger">Completează adresa fiscală: țară, județ, oraș/localitate si adresa.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'missing_location'): ?><div class="notice notice-danger">Adaugă cel puțin un punct de lucru activ.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'missing_location_required'): ?><div class="notice notice-danger">Fiecare locație activa trebuie sa aiba nume, adresa, persoană de contact, telefon si suprafata.</div><?php endif; ?>

        <div class="content">
            <section class="clients-page-title">
                <div>
                    <div class="pz-page-eyebrow">Clienți</div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <h1 style="margin:0;">Clienți</h1>
                        <span class="clients-count-pill"><?= (int)$totalClients ?></span>
                    </div>
                </div>
                <button class="pz-icon-btn primary lg" type="button" title="Adaugă client" aria-label="Adaugă client" onclick="openClientModal()"><?= app_icon_svg('plus') ?></button>
            </section>

            <section class="clients-layout">
                <div class="clients-list-card">
                    <div class="card-head">
                        <div>
                            <div class="card-title">Listă clienți</div>
                            <div class="card-subtitle">Afișare <?= (int)$fromResult ?>-<?= (int)$toResult ?> din <?= (int)$totalClients ?></div>
                        </div>
                    </div>

                    <?php if (!$clients): ?>
                        <div class="empty-state">Nu există clienți pentru filtrul selectat.</div>
                    <?php else: ?>
                        <div class="clients-table-wrap">
                            <table class="clients-table">
                                <thead>
                                    <tr>
                                        <th>ID client</th>
                                        <th>Denumire</th>
                                        <th>Tip</th>
                                        <th>CUI / CNP</th>
                                        <th>Reprezentant</th>
                                        <th>Telefon</th>
                                        <th>Email</th>
                                        <th>Oraș</th>
                                        <th>Vezi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clients as $client): ?>
                                        <?php
                                            $cid = (int)$client['id'];
                                            $contactPerson = c_client_contact_person($client);
                                        ?>
                                        <tr>
                                            <td data-label="ID client"><span class="client-id-cell"><?= $cid ?></span></td>
                                            <td data-label="Denumire">
                                                <div class="client-cell-title"><?= c_h_raw($client['name']) ?></div>
                                            </td>
                                            <td data-label="Tip"><span class="type-pill"><?= c_h(c_client_type_label($client['client_type'] ?? 'company')) ?></span></td>
                                            <td data-label="CUI / CNP"><?= c_h($client['fiscal_code'] ?: '-') ?></td>
                                            <td data-label="Reprezentant"><?= c_h($contactPerson ?: '-') ?></td>
                                            <td data-label="Telefon"><?= c_h($client['phone'] ?: '-') ?></td>
                                            <td data-label="Email"><?= c_h($client['email'] ?: '-') ?></td>
                                            <td data-label="Oraș"><?= c_h($client['billing_city'] ?: '-') ?></td>
                                            <td data-label="Vezi">
                                                <div class="client-row-actions">
                                                    <a class="icon-action is-primary" href="client.php?id=<?= $cid ?>" title="Vezi client" aria-label="Vezi client">
                                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="clients-pagination">
                            <div>Pagina <?= (int)$page ?> din <?= (int)$totalPages ?></div>
                            <div class="page-buttons">
                                <?php
                                    $prevQuery = $listBaseQuery;
                                    $prevQuery['page'] = max(1, $page - 1);
                                    $nextQuery = $listBaseQuery;
                                    $nextQuery['page'] = min($totalPages, $page + 1);
                                ?>
                                <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="clients.php?<?= c_h(http_build_query($prevQuery)) ?>">‹</a>
                                <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);
                                    for ($p = $startPage; $p <= $endPage; $p++):
                                        $pageQuery = $listBaseQuery;
                                        $pageQuery['page'] = $p;
                                ?>
                                    <a class="page-btn <?= $p === $page ? 'active' : '' ?>" href="clients.php?<?= c_h(http_build_query($pageQuery)) ?>"><?= (int)$p ?></a>
                                <?php endfor; ?>
                                <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="clients.php?<?= c_h(http_build_query($nextQuery)) ?>">›</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($selectedClient): ?>
                    <div class="client-profile-card">
                        <div class="profile-header">
                            <div class="profile-title-row">
                                <div>
                                    <h2 class="profile-title"><?= c_h_raw($selectedClient['name']) ?></h2>
                                    <div class="profile-sub">
                                        <?= c_h(c_client_type_label($selectedClient['client_type'] ?? 'company')) ?>
                                        <?php if (!empty($selectedClient['fiscal_code'])): ?> · <?= c_h($selectedClient['fiscal_code']) ?><?php endif; ?>
                                        <?php if ((int)($selectedClient['active'] ?? 1) === 0): ?> · Inactiv<?php endif; ?>
                                        <?php if ((int)($selectedClient['sms_enabled'] ?? 1) === 0): ?> · SMS oprit<?php else: ?> · SMS activ<?php endif; ?>
                                    </div>
                                </div>
                                <div class="profile-actions">
                                    <button class="btn" type="button" onclick="openClientModal(<?= (int)$selectedClient['id'] ?>)">Editează fișa</button>
                                    <a class="btn" href="contract_create.php?client_id=<?= (int)$selectedClient['id'] ?>">Emite contract</a>
                                    <a class="btn" href="tasks.php?client_id=<?= (int)$selectedClient['id'] ?>&open_create=1&return_to=client">Adaugă sarcina</a>
                                    <a class="btn accent" href="calendar.php?client_id=<?= (int)$selectedClient['id'] ?>&open_create=1">Programare</a>
                                    <form method="post" action="clients.php" class="sms-toggle-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_sms">
                                        <input type="hidden" name="client_id" value="<?= (int)$selectedClient['id'] ?>">
                                        <?php if ((int)($selectedClient['sms_enabled'] ?? 1) === 1): ?>
                                            <input type="hidden" name="sms_enabled" value="0">
                                            <button class="btn" type="submit" onclick="return confirm('Opresti notificările SMS pentru acest client?')">Opreste notificări SMS</button>
                                        <?php else: ?>
                                            <input type="hidden" name="sms_enabled" value="1">
                                            <button class="btn accent" type="submit">Porneste notificări SMS</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="profile-body">
                            <div class="panel">
                                <div class="card-head">
                                    <div>
                                        <div class="card-title">Date identificare</div>
                                        <div class="card-subtitle">Date generale client</div>
                                    </div>
                                </div>
                                <div style="padding:16px;">
                                    <div class="info-grid">
                                        <div class="info-box"><div class="info-label">Denumire / nume</div><div class="info-value"><?= c_h_raw($selectedClient['name'] ?? '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">CUI / CNP</div><div class="info-value"><?= c_h($selectedClient['fiscal_code'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Reg. Com. / Serie CI</div><div class="info-value"><?= c_h($selectedClient['registry_number'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Telefon</div><div class="info-value"><?= c_h($selectedClient['phone'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Email</div><div class="info-value"><?= c_h($selectedClient['email'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Reprezentant</div><div class="info-value"><?= c_h(c_client_contact_person($selectedClient) ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Banca</div><div class="info-value"><?= c_h($selectedClient['bank_name'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Cont bancar</div><div class="info-value"><?= c_h($selectedClient['bank_account'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Țară facturare</div><div class="info-value"><?= c_h($selectedClient['billing_country'] ?: 'Romania') ?></div></div>
                                        <div class="info-box"><div class="info-label">Județ facturare</div><div class="info-value"><?= c_h($selectedClient['billing_county'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Oraș / sector</div><div class="info-value"><?= c_h($selectedClient['billing_city'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Cod postal</div><div class="info-value"><?= c_h($selectedClient['billing_postal_code'] ?: '-') ?></div></div>
                                        <div class="info-box" style="grid-column:1/-1;"><div class="info-label">Adresa fiscală</div><div class="info-value"><?= c_h(c_client_address($selectedClient) ?: '-') ?></div></div>
                                        <?php if (!empty($selectedClient['anaf_last_lookup_at'])): ?>
                                            <div class="info-box" style="grid-column:1/-1;"><div class="info-label">Ultima interogare ANAF</div><div class="info-value"><?= c_h($selectedClient['anaf_last_lookup_at']) ?></div></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="panel">
                                <div class="card-head">
                                    <div>
                                        <div class="card-title">Locații / puncte de lucru</div>
                                        <div class="card-subtitle">Dacă nu există punct de lucru, prestarea se face pe sediu / domiciliu</div>
                                    </div>
                                </div>
                                <div style="padding:16px;">
                                    <?php if (!$selectedLocations): ?>
                                        <div class="empty-state">Nu există puncte de lucru adaugate.</div>
                                    <?php else: ?>
                                        <div class="location-list">
                                            <?php foreach ($selectedLocations as $location): ?>
                                                <div class="location-item">
                                                    <div class="location-top">
                                                        <div>
                                                            <div class="location-name"><?= c_h($location['location_name'] ?: 'Punct de lucru') ?></div>
                                                            <div class="location-meta">
                                                                <?= c_h($location['address'] ?: '-') ?><?php if (!empty($location['surface_value'])): ?><br>Suprafață: <?= c_h(rtrim(rtrim(number_format((float)$location['surface_value'], 2, '.', ''), '0'), '.')) ?> <?= c_h($location['surface_unit'] ?: 'mp') ?><?php endif; ?><br>
                                                                Contact: <?= c_h($location['contact_person'] ?: '-') ?><?= !empty($location['phone']) ? ' / ' . c_h($location['phone']) : '' ?>
                                                                <?php if (!empty($location['notes'])): ?><br><?= nl2br(c_h($location['notes'])) ?><?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <span class="status-pill <?= (int)$location['active'] === 1 ? '' : 'inactive' ?>"><?= (int)$location['active'] === 1 ? 'Activ' : 'Inactiv' ?></span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="panel" id="sarcini-client">
                                <div class="card-head">
                                    <div>
                                        <div class="card-title">Sarcini active</div>
                                        <div class="card-subtitle">Sarcini neprogramate pentru acest client</div>
                                    </div>
                                    <a class="btn" href="tasks.php?client_id=<?= (int)$selectedClient['id'] ?>&open_create=1&return_to=client">Adaugă sarcina</a>
                                </div>
                                <div style="padding:16px;">
                                    <?php if (!$selectedTasks): ?>
                                        <div class="empty-state">Nu există sarcini active pentru acest client. Apasa „Adaugă sarcina” pentru a crea una.</div>
                                    <?php else: ?>
                                        <div class="history-list">
                                            <?php foreach ($selectedTasks as $task): ?>
                                                <div class="history-item">
                                                    <div class="history-top">
                                                        <div>
                                                            <div class="history-title"><?= c_h($task['service_type'] ?: 'Sarcină') ?></div>
                                                            <div class="history-meta">
                                                                Scadenta: <?= c_h($task['due_date']) ?> · <?= c_h($task['location_name'] ?: 'Sediu / domiciliu') ?>
                                                            </div>
                                                        </div>
                                                        <a class="btn" href="calendar.php?client_id=<?= (int)$selectedClient['id'] ?>&task_id=<?= (int)$task['id'] ?>&service_type=<?= urlencode((string)($task['service_type'] ?? '')) ?><?= !empty($task['client_location_id']) ? '&client_location_id=' . (int)$task['client_location_id'] : '' ?>&open_create=1">Programeaza</a>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="panel">
                                <div class="card-head">
                                    <div>
                                        <div class="card-title">Istoric programări</div>
                                        <div class="card-subtitle">Ultimele programări ale clientului</div>
                                    </div>
                                </div>
                                <div style="padding:16px;">
                                    <?php if (!$selectedAppointments): ?>
                                        <div class="empty-state">Nu există programări.</div>
                                    <?php else: ?>
                                        <div class="history-list">
                                            <?php foreach ($selectedAppointments as $appointment): ?>
                                                <div class="history-item">
                                                    <div class="history-top">
                                                        <div>
                                                            <div class="history-title"><?= c_h($appointment['service_type'] ?: 'Lucrare') ?></div>
                                                            <div class="history-meta">
                                                                <?= c_h($appointment['appointment_date']) ?> · <?= c_h(substr((string)$appointment['start_time'], 0, 5)) ?> · <?= c_h($appointment['team_name'] ?: '-') ?><br>
                                                                <?= c_h($appointment['location_name'] ?: 'Sediu / domiciliu') ?> · <?= c_h($appointment['status'] ?: '-') ?>
                                                            </div>
                                                        </div>
                                                        <span class="status-pill"><?= c_h($appointment['status'] ?: '-') ?></span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="client-danger-actions">
                                <?php if ((int)($selectedClient['active'] ?? 1) === 1): ?>
                                    <form method="post" onsubmit="return confirm('Sigur vrei sa dezactivezi acest client? Nu stergem istoricul, doar il ascundem din listele active.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="client_id" value="<?= (int)$selectedClient['id'] ?>">
                                        <button class="btn danger" type="submit">Dezactiveaza clientul</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reactivate">
                                        <input type="hidden" name="client_id" value="<?= (int)$selectedClient['id'] ?>">
                                        <button class="btn accent" type="submit">Reactiveaza clientul</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" onsubmit="return confirm('Ștergerea definitiva este permisa doar dacă acest client nu are programări sau sarcini. Dacă are istoric, sistemul va bloca stergerea. Continui?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="permanent_delete">
                                    <input type="hidden" name="client_id" value="<?= (int)$selectedClient['id'] ?>">
                                    <button class="btn danger" type="submit">Șterge definitiv</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<div class="modal" id="clientModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="clientModalTitle">Client nou</h2>
            <button class="modal-close" type="button" onclick="closeClientModal()">&times;</button>
        </div>

        <form method="post" id="clientForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="form_action" value="create">
            <input type="hidden" name="client_id" id="client_id" value="">
            <input type="hidden" name="anaf_raw_response" id="anaf_raw_response" value="">
            <input type="hidden" name="anaf_last_lookup_at" id="anaf_last_lookup_at" value="">

            <div class="form-section">
                <div class="form-section-title">Tip client</div>
                <input type="hidden" name="client_type" id="client_type" value="company">
                <div class="type-switch">
                    <button type="button" class="type-option active" id="type_company" onclick="setClientType('company')">Persoană juridică</button>
                    <button type="button" class="type-option" id="type_individual" onclick="setClientType('individual')">Persoană fizică</button>
                </div>
            </div>

            <div class="form-section" id="anaf_section">
                <div class="form-section-title">Preluare date ANAF</div>
                <div class="anaf-row">
                    <div>
                        <label>CUI firma</label>
                        <input type="text" id="anaf_cui" placeholder="Ex: 14837428">
                    </div>
                    <button class="btn accent" type="button" onclick="lookupAnaf()">Caută ANAF</button>
                </div>
                <div class="anaf-message" id="anaf_message"></div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Zona 1 - Date client</div>
                <div class="form-grid">
                    <div>
                        <label>Denumire / nume *</label>
                        <input type="text" name="name" id="name" required>
                    </div>
                    <div>
                        <label>CUI / CNP *</label>
                        <input type="text" name="fiscal_code" id="fiscal_code" required>
                    </div>
                    <div>
                        <label>Nr. Reg. Com. / Serie CI</label>
                        <input type="text" name="registry_number" id="registry_number">
                    </div>
                    <div>
                        <label>Email *</label>
                        <input type="email" name="email" id="email" required>
                    </div>
                    <div>
                        <label>Telefon general *</label>
                        <input type="tel" name="phone" id="phone" required>
                    </div>
                    <div id="rep_name_wrap">
                        <label>Reprezentant legal *</label>
                        <input type="text" name="legal_representative_name" id="legal_representative_name">
                    </div>
                    <div id="rep_role_wrap">
                        <label>Calitate reprezentant *</label>
                        <input type="text" name="legal_representative_role" id="legal_representative_role" value="Administrator">
                    </div>
                    <div>
                        <label>Banca</label>
                        <input type="text" name="bank_name" id="bank_name">
                    </div>
                    <div>
                        <label>Cont bancar</label>
                        <input type="text" name="bank_account" id="bank_account">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Adresa e-Factura</div>
                <input type="hidden" name="registered_address" id="registered_address" value="">
                <input type="hidden" name="billing_sector" id="billing_sector" value="">
                <div class="form-grid">
                    <div>
                        <label>Țară *</label>
                        <input type="text" name="billing_country" id="billing_country" value="Romania" required>
                    </div>
                    <div>
                        <label>Județ *</label>
                        <input type="text" name="billing_county" id="billing_county" placeholder="Constanta / Bucuresti" required>
                    </div>
                    <div>
                        <label>Oraș / sector *</label>
                        <input type="text" name="billing_city" id="billing_city" placeholder="Constanta / Sector 3" required>
                    </div>
                    <div>
                        <label>Cod postal</label>
                        <input type="text" name="billing_postal_code" id="billing_postal_code">
                    </div>
                    <div class="form-group full">
                        <label>Strada / adresa *</label>
                        <input type="text" name="billing_address_line" id="billing_address_line" placeholder="Strada, numar, bloc, scara, etaj, apartament" required>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Zona 2 - Locații</div>
                <div class="location-form-list" id="locationsFormList"></div>
                <div class="client-add-location-action">
                    <button class="btn" type="button" onclick="addLocationRow()">+ Adaugă punct de lucru</button>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Observații client</div>
                <textarea name="notes" id="notes" placeholder="Observații generale despre client..."></textarea>
            </div>

            <div class="form-section">
                <div class="form-section-title">Status client</div>
                <div class="status-toggle-row">
                    <label class="status-toggle" title="Activeaza / dezactiveaza clientul">
                        <input type="checkbox" name="active" id="client_active" value="1" checked onchange="updateClientStatusLabel(this)">
                        <span class="slider"></span>
                    </label>
                    <div>
                        <div class="status-toggle-label">Client activ</div>
                        <div class="status-toggle-meta">Clienții inactivi nu apar in cautari si nu primesc programări.</div>
                    </div>
                    <span class="status-toggle-state" id="status_state">Activ</span>
                </div>

                <div class="status-toggle-row" style="margin-top:14px;">
                    <label class="status-toggle" title="Activeaza / dezactiveaza trimiterea de SMS-uri catre client">
                        <input type="checkbox" name="sms_enabled" id="client_sms_enabled" value="1" checked onchange="updateClientSmsLabel(this)">
                        <span class="slider"></span>
                    </label>
                    <div>
                        <div class="status-toggle-label">Trimite SMS-uri catre client</div>
                        <div class="status-toggle-meta">Cand e oprit, NU se trimit SMS-uri automate (programări, scadente) sau manuale catre acest client.</div>
                    </div>
                    <span class="status-toggle-state" id="sms_state">Pornit</span>
                </div>
            </div>

            <div class="actions-row">
                <div></div>
                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeClientModal()">Renunță</button>
                    <button class="btn accent" type="submit">Salvează clientul</button>
                </div>
            </div>
        </form>
    </div>
</div>


<div class="modal client-quick-modal" id="clientQuickModal">
    <div class="modal-box client-quick-box">
        <div class="client-quick-header">
            <div>
                <h2 class="client-quick-title" id="quickClientTitle">Fișa rapida client</h2>
                <div class="client-quick-subtitle" id="quickClientSubtitle">Date client, locații si activitate.</div>
            </div>
            <button class="modal-close" type="button" onclick="closeClientQuickView()">&times;</button>
        </div>
        <div class="client-quick-body" id="quickClientBody"></div>
    </div>
</div>

<script>
const clientsData = <?= json_encode(c_fix_encoding_issues_recursive($clientsForJs), JSON_UNESCAPED_UNICODE) ?>;
const shouldOpenCreate = <?= $shouldOpenCreate ? 'true' : 'false' ?>;
const shouldOpenEditClientId = <?= (int)$shouldOpenEditClientId ?>;
let locationIndex = 0;

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function cleanText(value) {
    return String(value || '')
        .replace(/[aa]/g, 'a')
        .replace(/[AA]/g, 'A')
        .replace(/[i]/g, 'i')
        .replace(/[I]/g, 'I')
        .replace(/[ss]/g, 's')
        .replace(/[SS]/g, 'S')
        .replace(/[tt]/g, 't')
        .replace(/[TT]/g, 'T')
        .replace(/\s+/g, ' ')
        .trim();
}

function quickUrl(path, params = {}) {
    const query = new URLSearchParams();
    Object.keys(params).forEach(key => {
        const value = params[key];
        if (value !== undefined && value !== null && String(value) !== '') query.set(key, value);
    });
    const qs = query.toString();
    return path + (qs ? '?' + qs : '');
}

function quickClientTypeLabel(type) {
    return type === 'individual' ? 'PF' : 'PJ';
}

function quickStatusLabel(client) {
    if (Number(client.active) !== 1) return 'Inactiv';
    if (client.client_status === 'season') return 'Sezon';
    return 'Activ';
}

function quickField(label, value) {
    const safeValue = value && String(value).trim() !== '' ? escHtml(value) : '<span class="is-muted">-</span>';
    return `<div class="quick-field"><div class="quick-label">${escHtml(label)}</div><div class="quick-value">${safeValue}</div></div>`;
}

function quickKpi(value, label) {
    return `<div class="quick-kpi"><div class="quick-kpi-value">${escHtml(value)}</div><div class="quick-kpi-label">${escHtml(label)}</div></div>`;
}

function formatLocationSurface(location) {
    const value = String(location.surface_value || '').trim();
    if (!value) return '';
    return `Suprafață: ${escHtml(value)} ${escHtml(location.surface_unit || 'mp')}`;
}

function renderQuickLocations(client) {
    const locations = client.locations || [];
    if (!locations.length) {
        return `<div class="quick-empty">Clientul nu are locații/puncte de lucru salvate. Pentru documente si servicii, adauga cel puțin o locație.</div>`;
    }

    return `<div class="quick-locations-list">${locations.map(location => {
        const contactBits = [];
        if (location.contact_person) contactBits.push('Contact: ' + escHtml(location.contact_person));
        if (location.phone) contactBits.push('Tel: ' + escHtml(location.phone));
        const surface = formatLocationSurface(location);
        const status = Number(location.active) === 1 ? 'Activ' : 'Inactiv';
        const statusClass = Number(location.active) === 1 ? '' : 'inactive';
        return `
            <div class="quick-location">
                <div class="quick-location-top">
                    <div class="quick-location-name">${escHtml(location.location_name || 'Punct de lucru')}</div>
                    <span class="client-status-badge ${statusClass}">${status}</span>
                </div>
                <div class="quick-location-meta">
                    ${escHtml(location.address || '-')}
                    ${contactBits.length ? '<br>' + contactBits.join(' | ') : ''}
                    ${surface ? '<br>' + surface : ''}
                    ${location.notes ? '<br>' + escHtml(location.notes) : ''}
                </div>
            </div>`;
    }).join('')}</div>`;
}

function openClientQuickView(clientId) {
    const client = clientsData[clientId];
    if (!client) {
        window.location.href = quickUrl('clients.php', {client_id: clientId});
        return;
    }

    const title = document.getElementById('quickClientTitle');
    const subtitle = document.getElementById('quickClientSubtitle');
    const body = document.getElementById('quickClientBody');
    const clientName = client.name || 'Client';
    const cid = encodeURIComponent(client.id);

    title.textContent = clientName;
    subtitle.textContent = `${quickClientTypeLabel(client.client_type)} · ${client.fiscal_code || 'fara CUI/CNP'} · ${quickStatusLabel(client)}`;

    body.innerHTML = `
        <div class="client-quick-actions">
            <button class="btn accent" type="button" onclick="closeClientQuickView(); openClientModal(${Number(client.id)});">Editează client</button>
        </div>
        <div class="client-quick-grid">
            <div class="client-quick-card">
                <div class="client-quick-card-head">
                    <div class="client-quick-card-title">Date client</div>
                    <span class="client-status-badge ${Number(client.active) === 1 ? '' : 'inactive'}">${escHtml(quickStatusLabel(client))}</span>
                </div>
                <div class="client-quick-card-body">
                    <div class="client-quick-details">
                        ${quickField('Tip', quickClientTypeLabel(client.client_type))}
                        ${quickField('CUI / CNP', client.fiscal_code)}
                        ${quickField('Reg. Com. / Serie CI', client.registry_number)}
                        ${quickField('Reprezentant', client.legal_representative_name)}
                        ${quickField('Calitate reprezentant', client.legal_representative_role)}
                        ${quickField('Telefon', client.phone)}
                        ${quickField('Email', client.email)}
                        ${quickField('Țară facturare', client.billing_country || 'Romania')}
                        ${quickField('Județ facturare', client.billing_county)}
                        ${quickField('Oraș / sector', client.billing_city)}
                        ${quickField('Cod postal', client.billing_postal_code)}
                        ${quickField('Adresa fiscală', client.registered_address)}
                        ${quickField('Banca', client.bank_name)}
                        ${quickField('Cont bancar', client.bank_account)}
                    </div>
                    ${client.notes ? `<div style="margin-top:12px;">${quickField('Observații client', client.notes)}</div>` : ''}
                </div>
            </div>
            <div class="client-quick-card">
                <div class="client-quick-card-head"><div class="client-quick-card-title">Informatii activitate</div></div>
                <div class="client-quick-card-body">
                    <div class="quick-kpi-grid">
                        ${quickKpi(Number(client.contracts_count || 0) > 0 ? 'Da' : 'Nu', 'Are contract')}
                        ${quickKpi(client.contracts_count || 0, 'Contracte')}
                        ${quickKpi(client.completed_appointments_count || 0, 'Interventii finalizate')}
                        ${quickKpi(client.appointments_count || 0, 'Programări totale')}
                        ${quickKpi(client.active_tasks_count || 0, 'Sarcini active')}
                        ${quickKpi(client.locations_count || 0, 'Locații')}
                    </div>
                </div>
            </div>
        </div>
        <div class="client-quick-card" style="margin-top:14px;">
            <div class="client-quick-card-head"><div class="client-quick-card-title">Locații / puncte de lucru</div></div>
            <div class="client-quick-card-body">${renderQuickLocations(client)}</div>
        </div>
    `;

    document.getElementById('clientQuickModal').classList.add('open');
}

function closeClientQuickView() {
    document.getElementById('clientQuickModal').classList.remove('open');
}

function setField(id, value) {
    const field = document.getElementById(id);
    if (field) field.value = value || '';
}

function buildBillingAddressFromFields() {
    const parts = [
        (document.getElementById('billing_address_line')?.value || '').trim(),
        (document.getElementById('billing_county')?.value || '').trim(),
        (document.getElementById('billing_city')?.value || '').trim(),
        (document.getElementById('billing_sector')?.value || '').trim(),
        (document.getElementById('billing_country')?.value || '').trim(),
    ].filter(Boolean);
    const postal = (document.getElementById('billing_postal_code')?.value || '').trim();
    let address = parts.join(', ');
    if (postal) address += (address ? ', ' : '') + 'CP ' + postal;
    return address;
}

function refreshRegisteredAddressFromBilling(force = false) {
    const target = document.getElementById('registered_address');
    if (!target) return;
    const built = buildBillingAddressFromFields();
    if (force || !target.value.trim()) {
        target.value = built;
    }
}

function setClientType(type) {
    const isIndividual = type === 'individual';
    document.getElementById('client_type').value = isIndividual ? 'individual' : 'company';
    document.getElementById('type_company').classList.toggle('active', !isIndividual);
    document.getElementById('type_individual').classList.toggle('active', isIndividual);
    document.getElementById('anaf_section').style.display = isIndividual ? 'none' : 'block';
    document.getElementById('rep_name_wrap').style.display = isIndividual ? 'none' : 'block';
    document.getElementById('rep_role_wrap').style.display = isIndividual ? 'none' : 'block';

    const repName = document.getElementById('legal_representative_name');
    if (repName) repName.required = !isIndividual;
    const repRole = document.getElementById('legal_representative_role');
    if (repRole) repRole.required = !isIndividual;

    if (isIndividual) {
        setField('legal_representative_name', '');
        setField('legal_representative_role', '');
    } else if (!document.getElementById('legal_representative_role').value) {
        setField('legal_representative_role', 'Administrator');
    }
}

function resetClientForm() {
    document.getElementById('clientForm').reset();
    setField('form_action', 'create');
    setField('client_id', '');
    setField('anaf_raw_response', '');
    setField('anaf_last_lookup_at', '');
    setField('anaf_cui', '');
    setField('billing_country', 'Romania');
    setField('billing_county', '');
    setField('billing_city', '');
    setField('billing_sector', '');
    setField('billing_address_line', '');
    setField('billing_postal_code', '');
    document.getElementById('anaf_message').className = 'anaf-message';
    document.getElementById('anaf_message').textContent = '';
    document.getElementById('locationsFormList').innerHTML = '';
    locationIndex = 0;
    setClientType('company');
}

function openClientModal(clientId = null) {
    resetClientForm();

    if (clientId && clientsData[clientId]) {
        const client = clientsData[clientId];
        document.getElementById('clientModalTitle').textContent = 'Editează client';
        setField('form_action', 'update');
        setField('client_id', client.id);
        setClientType(client.client_type || 'company');
        setField('name', client.name);
        setField('fiscal_code', client.fiscal_code);
        setField('registry_number', client.registry_number);
        setField('registered_address', client.registered_address);
        setField('billing_country', client.billing_country || 'Romania');
        setField('billing_county', client.billing_county);
        setField('billing_city', client.billing_city);
        setField('billing_sector', client.billing_sector);
        setField('billing_address_line', client.billing_address_line);
        setField('billing_postal_code', client.billing_postal_code);
        setField('bank_name', client.bank_name);
        setField('bank_account', client.bank_account);
        setField('phone', client.phone);
        setField('email', client.email);
        setField('legal_representative_name', client.legal_representative_name);
        setField('legal_representative_role', client.legal_representative_role || (client.client_type === 'company' ? 'Administrator' : ''));
        setField('anaf_raw_response', client.anaf_raw_response);
        setField('anaf_last_lookup_at', client.anaf_last_lookup_at);
        setField('notes', client.notes);

        // Setam toggle Activ/Inactiv conform datelor existente
        const activeCheckbox = document.getElementById('client_active');
        if (activeCheckbox) {
            activeCheckbox.checked = (Number(client.active) === 1);
            updateClientStatusLabel(activeCheckbox);
        }
        // Setam toggle SMS conform datelor existente (default = activat dacă lipseste)
        const smsCheckbox = document.getElementById('client_sms_enabled');
        if (smsCheckbox) {
            smsCheckbox.checked = (client.sms_enabled === undefined || client.sms_enabled === null) ? true : (Number(client.sms_enabled) === 1);
            updateClientSmsLabel(smsCheckbox);
        }

        (client.locations || []).forEach(location => addLocationRow(location));
        if (!client.locations || client.locations.length === 0) {
            addLocationRow();
        }
    } else {
        document.getElementById('clientModalTitle').textContent = 'Client nou';
        // Pentru client nou, default e activ
        const activeCheckbox = document.getElementById('client_active');
        if (activeCheckbox) {
            activeCheckbox.checked = true;
            updateClientStatusLabel(activeCheckbox);
        }
        const smsCheckbox = document.getElementById('client_sms_enabled');
        if (smsCheckbox) {
            smsCheckbox.checked = true;
            updateClientSmsLabel(smsCheckbox);
        }
        addLocationRow();
    }

    document.getElementById('clientModal').classList.add('open');

    if (!clientId) {
        setTimeout(() => {
            const focusTarget = document.getElementById('client_type')?.value === 'company'
                ? document.getElementById('anaf_cui')
                : document.getElementById('name');
            focusTarget?.focus();
        }, 80);
    }
}

/* === Toggle label dinamic Activ/Inactiv === */
function updateClientStatusLabel(checkbox) {
    const stateEl = document.getElementById('status_state');
    if (!stateEl) return;
    if (checkbox.checked) {
        stateEl.textContent = 'Activ';
        stateEl.classList.remove('is-inactive');
    } else {
        stateEl.textContent = 'Inactiv';
        stateEl.classList.add('is-inactive');
    }
}

/* === Toggle label dinamic SMS pornit/oprit === */
function updateClientSmsLabel(checkbox) {
    const stateEl = document.getElementById('sms_state');
    if (!stateEl) return;
    if (checkbox.checked) {
        stateEl.textContent = 'Pornit';
        stateEl.classList.remove('is-inactive');
    } else {
        stateEl.textContent = 'Oprit';
        stateEl.classList.add('is-inactive');
    }
}

function closeClientModal() {
    document.getElementById('clientModal').classList.remove('open');
}

function getClientLocationContactDefaults() {
    const type = document.getElementById('client_type').value;
    const clientName = (document.getElementById('name').value || '').trim();
    const repName = (document.getElementById('legal_representative_name').value || '').trim();
    const phone = (document.getElementById('phone').value || '').trim();
    const contact = type === 'individual' ? clientName : (repName || clientName);

    return {
        contact_person: contact,
        phone,
    };
}

function setFieldIfEmpty(id, value) {
    const field = document.getElementById(id);
    if (field && !field.value.trim() && value) {
        field.value = value;
    }
}

function syncFirstLocationFromClient() {
    const firstRow = document.querySelector('#locationsFormList .location-form-row');
    if (!firstRow) return;

    const idx = firstRow.dataset.idx;
    refreshRegisteredAddressFromBilling(false);
    const registeredAddress = (document.getElementById('registered_address').value || '').trim();
    setFieldIfEmpty('location_address_' + idx, registeredAddress);
}

function addLocationRow(location = {}) {
    const list = document.getElementById('locationsFormList');
    const idx = locationIndex++;
    refreshRegisteredAddressFromBilling(false);
    const registeredAddress = (document.getElementById('registered_address').value || '').trim();
    const isFirstNewBlankRow = list.children.length === 0
        && !location.id
        && !location.address
        && !location.location_name
        && !location.contact_person
        && !location.phone;
    if (isFirstNewBlankRow && registeredAddress) {
        location = { ...location, address: registeredAddress };
    }

    const row = document.createElement('div');
    row.className = 'location-form-row';
    row.dataset.idx = idx;

    row.innerHTML = `
        <input type="hidden" name="location_id[]" value="${escHtml(location.id || '')}">
        <input type="hidden" name="location_active[]" id="location_active_${idx}" value="${location.active === 0 ? '0' : '1'}">
        <div class="location-row-head">
            <div class="location-row-title">Punct de lucru</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="btn" type="button" onclick="copyClientContactToLocation(${idx})">Preia date</button>
                <button class="btn danger" type="button" onclick="removeLocationRow(this, ${idx}, ${location.id ? 'true' : 'false'})">Șterge</button>
            </div>
        </div>
        <div class="form-grid">
            <div>
                <label>Nume punct de lucru *</label>
                <input type="text" name="location_name[]" id="location_name_${idx}" value="${escHtml(location.location_name || '')}" placeholder="Ex: Magazin Tomis Mall" required>
            </div>
            <div>
                <label>Persoană contact locație *</label>
                <input type="text" name="location_contact_person[]" id="location_contact_person_${idx}" value="${escHtml(location.contact_person || '')}" required>
            </div>
            <div>
                <label>Telefon contact locație *</label>
                <input type="tel" name="location_phone[]" id="location_phone_${idx}" value="${escHtml(location.phone || '')}" required>
            </div>
            <div class="form-group full">
                <label>Adresa punctului de lucru *</label>
                <input type="text" name="location_address[]" id="location_address_${idx}" value="${escHtml(location.address || '')}" required>
            </div>
            <div>
                <label>Suprafață locație (mp) *</label>
                <input type="text" name="location_surface_value[]" id="location_surface_value_${idx}" value="${escHtml(location.surface_value || '')}" placeholder="Ex: 120" required>
                <input type="hidden" name="location_surface_unit[]" id="location_surface_unit_${idx}" value="mp">
            </div>
            <div class="form-group full">
                <label>Notite locație / particularitati</label>
                <textarea name="location_notes[]" id="location_notes_${idx}">${escHtml(location.notes || '')}</textarea>
            </div>
        </div>
    `;

    if (location.active === 0) {
        row.style.opacity = '.55';
        row.querySelectorAll('input, textarea, select').forEach(field => {
            if (field.type !== 'hidden') field.required = false;
        });
    }

    list.appendChild(row);
}

function removeLocationRow(button, idx, existing) {
    const row = button.closest('.location-form-row');
    if (!row) return;

    if (existing) {
        document.getElementById('location_active_' + idx).value = '0';
        row.querySelectorAll('input, textarea, select').forEach(field => {
            if (field.type !== 'hidden') field.required = false;
        });
        row.style.display = 'none';
    } else {
        row.remove();
    }
}

function copyClientContactToLocation(idx) {
    const defaults = getClientLocationContactDefaults();

    setField('location_contact_person_' + idx, defaults.contact_person);
    setField('location_phone_' + idx, defaults.phone);
}

async function lookupAnaf() {
    const cui = document.getElementById('anaf_cui').value || document.getElementById('fiscal_code').value;
    const message = document.getElementById('anaf_message');

    if (!cui.trim()) {
        message.className = 'anaf-message bad';
        message.textContent = 'Introdu CUI-ul firmei.';
        return;
    }

    message.className = 'anaf-message';
    message.textContent = 'Se interogheaza ANAF...';

    try {
        const res = await fetch('clients.php?ajax=anaf_lookup&cui=' + encodeURIComponent(cui));
        const json = await res.json();

        if (!json.success || !json.data) {
            message.className = 'anaf-message bad';
            message.textContent = json.message || 'Firma nu a fost gasita la ANAF.';
            console.warn('ANAF debug:', json.debug || null);
            return;
        }

        const data = json.data;
        setClientType('company');
        setField('name', cleanText(data.name || ''));
        setField('fiscal_code', data.fiscal_code || '');
        setField('registry_number', cleanText(data.registry_number || ''));
        setField('billing_country', cleanText(data.billing_country || 'Romania'));
        setField('billing_county', cleanText(data.billing_county || ''));
        setField('billing_city', cleanText(data.billing_city || ''));
        setField('billing_sector', cleanText(data.billing_sector || ''));
        setField('billing_address_line', cleanText(data.billing_address_line || data.registered_address || ''));
        setField('billing_postal_code', cleanText(data.billing_postal_code || ''));
        refreshRegisteredAddressFromBilling(true);
        setField('bank_account', cleanText(data.bank_account || ''));
        setField('anaf_last_lookup_at', data.anaf_last_lookup_at || '');
        setField('anaf_raw_response', data.anaf_raw_response || '');
        syncFirstLocationFromClient();

        message.className = 'anaf-message ok';
        message.textContent = 'Datele au fost preluate de la ANAF.';
    } catch (err) {
        console.error(err);
        message.className = 'anaf-message bad';
        message.textContent = 'Eroare la interogarea ANAF.';
    }
}

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', event => {
        if (event.target === modal) modal.classList.remove('open');
    });
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') document.querySelectorAll('.modal.open').forEach(modal => modal.classList.remove('open'));
});

document.addEventListener('DOMContentLoaded', () => {
    ['billing_country', 'billing_county', 'billing_city', 'billing_sector', 'billing_address_line', 'billing_postal_code'].forEach(id => {
        const field = document.getElementById(id);
        if (field) {
            field.addEventListener('input', () => refreshRegisteredAddressFromBilling(true));
        }
    });

    if (shouldOpenCreate) {
        openClientModal();
    } else if (shouldOpenEditClientId > 0) {
        openClientModal(shouldOpenEditClientId);
    }
});

document.getElementById('clientForm')?.addEventListener('submit', () => {
    refreshRegisteredAddressFromBilling(true);
});

/* === Kebab menu toggle pentru row actions === */
function rowMenuCloseAll() {
    document.querySelectorAll('.row-menu.is-open').forEach(m => m.classList.remove('is-open'));
}
function rowMenuToggle(triggerEl) {
    const menu = triggerEl.closest('.row-menu');
    if (!menu) return;
    const wasOpen = menu.classList.contains('is-open');
    rowMenuCloseAll();
    if (!wasOpen) menu.classList.add('is-open');
}
document.addEventListener('click', (e) => {
    if (!e.target.closest('.row-menu')) rowMenuCloseAll();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') rowMenuCloseAll();
});
</script>

<?php
// Preview live pentru bara „Caută client".
$previewClientsList = [];
try {
    $stmtPrev = $pdo->query("SELECT id, name, fiscal_code FROM clients ORDER BY name ASC LIMIT 2000");
    while ($cli = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
        $nm = html_entity_decode((string)($cli['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cf = html_entity_decode((string)($cli['fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $previewClientsList[] = [
            'title'  => $nm,
            'url'    => 'client.php?id=' . (int)$cli['id'],
            'type'   => 'client',
            'search' => $nm . ' ' . $cf,
        ];
    }
} catch (Throwable $e) { error_log('clients.php preview: ' . $e->getMessage()); }
?>
<script>
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('clientsSearchInput',
            <?= json_encode($previewClientsList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
    };
    go();
})();
</script>
