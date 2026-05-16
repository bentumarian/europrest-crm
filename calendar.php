<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'task_recurrence.php';
require_once __DIR__ . '/notification_lib.php';

if (function_exists('ensure_task_recurrence_schema')) {
    ensure_task_recurrence_schema($pdo);
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function hcal($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function calendar_table_exists(PDO $pdo, string $table): bool {
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

function calendar_column_exists(PDO $pdo, string $table, string $column): bool {
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

function calendar_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!calendar_column_exists($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Nu blocam pagina daca ALTER nu poate rula.
        }
    }
}

function safe_date(?string $date): string {
    $date = (string)$date;
    $d = DateTime::createFromFormat('Y-m-d', $date);

    return ($d && $d->format('Y-m-d') === $date) ? $date : date('Y-m-d');
}

function safe_view(?string $view): string {
    $view = (string)$view;

    return in_array($view, ['day', 'week', 'month'], true) ? $view : 'day';
}

function calendar_normalize_half_hour_time(?string $time): ?string {
    $time = trim((string)$time);

    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
        return null;
    }

    $hour = (int)$m[1];
    $minute = (int)$m[2];

    if ($hour < 0 || $hour > 23 || !in_array($minute, [0, 30], true)) {
        return null;
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

function mb_first_letter(?string $text): string {
    $text = trim((string)$text);

    if ($text === '') {
        return '?';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 1, 'UTF-8');
    }

    return substr($text, 0, 1);
}

function calendar_initials(?string $text): string {
    $text = trim((string)$text);

    if ($text === '') {
        return '?';
    }

    $parts = preg_split('/\s+/', $text) ?: [];
    $initials = '';

    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') {
            continue;
        }

        if (function_exists('mb_substr')) {
            $initials .= mb_substr($part, 0, 1, 'UTF-8');
        } else {
            $initials .= substr($part, 0, 1);
        }

        if (strlen($initials) >= 2) {
            break;
        }
    }

    if ($initials === '') {
        $initials = function_exists('mb_substr') ? mb_substr($text, 0, 2, 'UTF-8') : substr($text, 0, 2);
    }

    return function_exists('mb_strtoupper') ? mb_strtoupper($initials, 'UTF-8') : strtoupper($initials);
}

function ro_date_label(string $date): string {
    $obj = new DateTime($date);

    $days = [
        'Monday'    => 'Luni',
        'Tuesday'   => 'Marti',
        'Wednesday' => 'Miercuri',
        'Thursday'  => 'Joi',
        'Friday'    => 'Vineri',
        'Saturday'  => 'Sambata',
        'Sunday'    => 'Duminica',
    ];

    $months = [
        'January'   => 'Ianuarie',
        'February'  => 'Februarie',
        'March'     => 'Martie',
        'April'     => 'Aprilie',
        'May'       => 'Mai',
        'June'      => 'Iunie',
        'July'      => 'Iulie',
        'August'    => 'August',
        'September' => 'Septembrie',
        'October'   => 'Octombrie',
        'November'  => 'Noiembrie',
        'December'  => 'Decembrie',
    ];

    $day = $days[$obj->format('l')] ?? $obj->format('l');
    $month = $months[$obj->format('F')] ?? $obj->format('F');

    return $day . ', ' . $obj->format('d') . ' ' . $month . ' ' . $obj->format('Y');
}

function slot_index(?string $time, int $startHour = 6): int {
    if (!$time || strpos($time, ':') === false) {
        return 1;
    }

    $parts = explode(':', substr($time, 0, 5));

    if (count($parts) < 2) {
        return 1;
    }

    [$h, $m] = array_map('intval', $parts);
    $minutes = $h * 60 + $m - $startHour * 60;

    return max(1, (int)floor($minutes / 30) + 1);
}

function duration_span(?string $start, ?string $end): int {
    if (!$start || strpos($start, ':') === false) {
        return 2;
    }

    if (!$end || strpos($end, ':') === false) {
        return 2;
    }

    [$sh, $sm] = array_map('intval', explode(':', substr($start, 0, 5)));
    [$eh, $em] = array_map('intval', explode(':', substr($end, 0, 5)));

    $diff = max(30, ($eh * 60 + $em) - ($sh * 60 + $sm));

    return max(1, (int)ceil($diff / 30));
}

function calendar_clean_hex_color(?string $color, string $fallback = '#163B63'): string {
    $color = trim((string)$color);

    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return $color;
    }

    return $fallback;
}

function calendar_lighten_hex(string $hex, float $amount = 0.82): string {
    $hex = calendar_clean_hex_color($hex);
    $amount = max(0, min(1, $amount));

    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));

    $r = (int)round($r + (255 - $r) * $amount);
    $g = (int)round($g + (255 - $g) * $amount);
    $b = (int)round($b + (255 - $b) * $amount);

    return sprintf('#%02X%02X%02X', $r, $g, $b);
}

function calendar_money_value($value): float {
    if ($value === null || $value === '') {
        return 0.0;
    }

    if (is_string($value)) {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
    }

    if (!is_numeric($value)) {
        return 0.0;
    }

    return max(0, round((float)$value, 2));
}

function calendar_money_input($value): string {
    $amount = calendar_money_value($value);
    return number_format($amount, 2, '.', '');
}

function calendar_money_label($value): string {
    return number_format(calendar_money_value($value), 2, ',', '.') . ' lei';
}

function calendar_clean_team_ids(array $teamIds, ?int $excludeTeamId = null): array {
    $clean = [];

    foreach ($teamIds as $teamId) {
        $id = (int)$teamId;
        if ($id <= 0) {
            continue;
        }
        if ($excludeTeamId !== null && $id === (int)$excludeTeamId) {
            continue;
        }
        $clean[$id] = $id;
    }

    return array_values($clean);
}

function calendar_post_support_team_ids(?int $primaryTeamId = null): array {
    $raw = $_POST['support_team_ids'] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }

    return calendar_clean_team_ids($raw, $primaryTeamId);
}

function calendar_sync_appointment_teams(PDO $pdo, int $appointmentId, ?int $primaryTeamId, array $supportTeamIds = []): void {
    if ($appointmentId <= 0 || !$primaryTeamId || $primaryTeamId <= 0 || !calendar_table_exists($pdo, 'appointment_teams')) {
        return;
    }

    $supportTeamIds = calendar_clean_team_ids($supportTeamIds, $primaryTeamId);

    $pdo->prepare("DELETE FROM appointment_teams WHERE appointment_id = ?")->execute([$appointmentId]);

    $stmt = $pdo->prepare("INSERT IGNORE INTO appointment_teams (appointment_id, team_id, is_primary) VALUES (?, ?, ?)");
    $stmt->execute([$appointmentId, (int)$primaryTeamId, 1]);

    foreach ($supportTeamIds as $teamId) {
        $stmt->execute([$appointmentId, (int)$teamId, 0]);
    }
}

function calendar_get_appointment_teams(PDO $pdo, int $appointmentId): array {
    if ($appointmentId <= 0 || !calendar_table_exists($pdo, 'appointment_teams')) {
        return [];
    }

    $stmt = $pdo->prepare("\n        SELECT at.team_id, at.is_primary, tm.name\n        FROM appointment_teams at\n        INNER JOIN team_members tm ON tm.id = at.team_id\n        WHERE at.appointment_id = ?\n        ORDER BY at.is_primary DESC, tm.name ASC\n    ");
    $stmt->execute([$appointmentId]);


    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function calendar_find_team_time_conflicts(PDO $pdo, array $teamIds, string $appointmentDate, string $startTime, string $endTime, ?int $excludeAppointmentId = null): array {
    $teamIds = calendar_clean_team_ids($teamIds);
    if (!$teamIds || $appointmentDate === '' || $startTime === '' || $endTime === '') {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $params = array_values($teamIds);

    if (calendar_table_exists($pdo, 'appointment_teams')) {
        $sql = "
            SELECT DISTINCT
                tm.id AS team_id,
                tm.name AS team_name,
                a.id AS appointment_id,
                a.start_time,
                a.end_time,
                COALESCE(c.name, a.title, 'Programare') AS client_name
            FROM team_members tm
            INNER JOIN appointments a
                ON a.team_member_id = tm.id
                OR EXISTS (
                    SELECT 1
                    FROM appointment_teams atx
                    WHERE atx.appointment_id = a.id
                      AND atx.team_id = tm.id
                )
            LEFT JOIN clients c ON c.id = a.client_id
            WHERE tm.id IN ($placeholders)
              AND a.appointment_date = ?
              AND COALESCE(a.status, '') <> 'anulata'
              AND a.start_time < ?
              AND a.end_time > ?
        ";
    } else {
        $sql = "
            SELECT DISTINCT
                tm.id AS team_id,
                tm.name AS team_name,
                a.id AS appointment_id,
                a.start_time,
                a.end_time,
                COALESCE(c.name, a.title, 'Programare') AS client_name
            FROM appointments a
            INNER JOIN team_members tm ON tm.id = a.team_member_id
            LEFT JOIN clients c ON c.id = a.client_id
            WHERE tm.id IN ($placeholders)
              AND a.appointment_date = ?
              AND COALESCE(a.status, '') <> 'anulata'
              AND a.start_time < ?
              AND a.end_time > ?
        ";
    }

    $params[] = $appointmentDate;
    $params[] = $endTime;
    $params[] = $startTime;

    if ($excludeAppointmentId !== null && $excludeAppointmentId > 0) {
        $sql .= " AND a.id <> ?";
        $params[] = (int)$excludeAppointmentId;
    }

    $sql .= " ORDER BY tm.name ASC, a.start_time ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function calendar_conflict_message(array $conflicts): string {
    if (!$conflicts) {
        return '';
    }

    $parts = [];
    foreach ($conflicts as $conflict) {
        $teamName = trim((string)($conflict['team_name'] ?? 'Tehnician'));
        $clientName = trim((string)($conflict['client_name'] ?? 'programare'));
        $start = substr((string)($conflict['start_time'] ?? ''), 0, 5);
        $end = substr((string)($conflict['end_time'] ?? ''), 0, 5);
        $parts[] = $teamName . ' este deja alocat la ' . $clientName . ' intre ' . $start . ' - ' . $end;
    }

    return implode('; ', array_slice($parts, 0, 3));
}

function calendar_get_client(PDO $pdo, int $clientId): ?array {
    if ($clientId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    return $client ?: null;
}

function calendar_client_address(array $client): string {
    return trim((string)($client['registered_address'] ?? '')) ?: trim((string)($client['address'] ?? ''));
}

function calendar_client_contact_person(array $client): string {
    $clientType = (string)($client['client_type'] ?? 'company');
    $name = trim((string)($client['name'] ?? ''));
    $representative = trim((string)($client['legal_representative_name'] ?? ''));

    if ($clientType === 'individual') {
        return $name;
    }

    return $representative !== '' ? $representative : $name;
}

function calendar_client_contact_phone(array $client): string {
    return trim((string)($client['phone'] ?? ''));
}

function calendar_get_location(PDO $pdo, int $locationId, ?int $clientId = null): ?array {
    if ($locationId <= 0) {
        return null;
    }

    if ($clientId !== null && $clientId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM client_locations WHERE id = ? AND client_id = ? LIMIT 1");
        $stmt->execute([$locationId, $clientId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM client_locations WHERE id = ? LIMIT 1");
        $stmt->execute([$locationId]);
    }

    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    return $location ?: null;
}

function calendar_find_contract_service(PDO $pdo, int $clientId, ?int $clientLocationId = null, string $serviceType = '', ?int $contractServiceId = null): ?array {
    if ($clientId <= 0 || !calendar_table_exists($pdo, 'contract_services') || !calendar_table_exists($pdo, 'contracts')) {
        return null;
    }

    $params = [$clientId];
    $sql = "
        SELECT
            cs.id AS contract_service_id,
            cs.contract_id,
            cs.client_id,
            cs.client_location_id,
            cs.service_id,
            cs.service_name,
            cs.price,
            cs.surface_value,
            cs.surface_unit,
            cs.currency,
            cs.document_id,
            cs.document_item_id,
            cs.status AS contract_service_status,
            c.contract_number,
            c.status AS contract_status,
            c.start_date,
            c.end_date
        FROM contract_services cs
        INNER JOIN contracts c ON c.id = cs.contract_id
        WHERE cs.client_id = ?
          AND LOWER(COALESCE(c.status, 'activ')) IN ('activ', 'active', 'emis', 'issued')
    ";

    if ($contractServiceId !== null && $contractServiceId > 0) {
        $sql .= " AND cs.id = ?";
        $params[] = $contractServiceId;
    }

    if ($clientLocationId !== null && $clientLocationId > 0) {
        $sql .= " AND cs.client_location_id = ?";
        $params[] = $clientLocationId;
    }

    $serviceType = trim($serviceType);
    if ($serviceType !== '') {
        $sql .= " AND LOWER(TRIM(cs.service_name)) = LOWER(TRIM(?))";
        $params[] = $serviceType;
    }

    $sql .= " ORDER BY c.start_date DESC, c.id DESC, cs.id ASC LIMIT 1";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('PestZone contract service lookup error: ' . $e->getMessage());
        return null;
    }
}

function calendar_snapshot_address(array $client, ?array $location, string $postedAddress): string {
    $postedAddress = trim($postedAddress);

    if ($postedAddress !== '') {
        return $postedAddress;
    }

    if ($location && trim((string)($location['address'] ?? '')) !== '') {
        return trim((string)$location['address']);
    }

    return '';
}

function calendar_snapshot_contact_person(array $client, ?array $location, string $postedContact): string {
    $postedContact = trim($postedContact);

    if ($postedContact !== '') {
        return $postedContact;
    }

    if ($location && trim((string)($location['contact_person'] ?? '')) !== '') {
        return trim((string)$location['contact_person']);
    }

    return calendar_client_contact_person($client);
}

function calendar_snapshot_contact_phone(array $client, ?array $location, string $postedPhone): string {
    $postedPhone = trim($postedPhone);

    if ($postedPhone !== '') {
        return $postedPhone;
    }

    if ($location && trim((string)($location['phone'] ?? '')) !== '') {
        return trim((string)$location['phone']);
    }

    return calendar_client_contact_phone($client);
}


function calendar_fetch_pv_for_appointment(PDO $pdo, int $appointmentId): ?array {
    if ($appointmentId <= 0 || !calendar_table_exists($pdo, 'documents')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT id, status, document_number, client_email_snapshot, email_sent_at, email_sent_to, email_sent_count\n            FROM documents\n            WHERE document_type = 'proces_verbal'\n              AND appointment_id = ?\n              AND COALESCE(status, 'draft') <> 'cancelled'\n            ORDER BY CASE WHEN status = 'issued' THEN 1 WHEN status = 'draft' THEN 2 ELSE 3 END ASC, id DESC\n            LIMIT 1\n        ");
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('PestZone calendar PV lookup error: ' . $e->getMessage());
        return null;
    }
}

function calendar_attach_pv_meta(PDO $pdo, array $row): array {
    $pv = calendar_fetch_pv_for_appointment($pdo, (int)($row['id'] ?? 0));
    $row['pv_id'] = $pv ? (int)$pv['id'] : 0;
    $row['pv_status'] = $pv ? (string)($pv['status'] ?? '') : '';
    $row['pv_number'] = $pv ? (string)($pv['document_number'] ?? '') : '';
    $row['pv_client_email'] = $pv ? (string)($pv['client_email_snapshot'] ?? '') : '';
    $row['pv_email_sent_at'] = $pv ? (string)($pv['email_sent_at'] ?? '') : '';
    $row['pv_email_sent_to'] = $pv ? (string)($pv['email_sent_to'] ?? '') : '';
    $row['pv_email_sent_count'] = $pv ? (int)($pv['email_sent_count'] ?? 0) : 0;
    return $row;
}

/*
|--------------------------------------------------------------------------
| Context autentificare
|--------------------------------------------------------------------------
*/
$isAdmin = is_admin();
$isTeamUser = is_team_user();
$currentTeamId = current_team_id();

/*
|--------------------------------------------------------------------------
| Tabele si coloane minime necesare
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

calendar_ensure_column($pdo, 'clients', 'client_type', "VARCHAR(20) NOT NULL DEFAULT 'company'");
calendar_ensure_column($pdo, 'clients', 'name', "VARCHAR(180) NOT NULL");
calendar_ensure_column($pdo, 'clients', 'phone', "VARCHAR(60) NULL");
calendar_ensure_column($pdo, 'clients', 'email', "VARCHAR(160) NULL");
calendar_ensure_column($pdo, 'clients', 'address', "VARCHAR(255) NULL");
calendar_ensure_column($pdo, 'clients', 'registered_address', "VARCHAR(255) NULL");
calendar_ensure_column($pdo, 'clients', 'legal_representative_name', "VARCHAR(180) NULL");
calendar_ensure_column($pdo, 'clients', 'notes', "TEXT NULL");
calendar_ensure_column($pdo, 'clients', 'active', "TINYINT(1) NOT NULL DEFAULT 1");
calendar_ensure_column($pdo, 'clients', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

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

calendar_ensure_column($pdo, 'client_locations', 'client_id', "INT NOT NULL");
calendar_ensure_column($pdo, 'client_locations', 'location_name', "VARCHAR(180) NOT NULL DEFAULT 'Punct de lucru'");
calendar_ensure_column($pdo, 'client_locations', 'address', "VARCHAR(255) NULL");
calendar_ensure_column($pdo, 'client_locations', 'contact_person', "VARCHAR(180) NULL");
calendar_ensure_column($pdo, 'client_locations', 'phone', "VARCHAR(60) NULL");
calendar_ensure_column($pdo, 'client_locations', 'notes', "TEXT NULL");
calendar_ensure_column($pdo, 'client_locations', 'active', "TINYINT(1) NOT NULL DEFAULT 1");
calendar_ensure_column($pdo, 'client_locations', 'sort_order', "INT NOT NULL DEFAULT 0");
calendar_ensure_column($pdo, 'client_locations', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

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

calendar_ensure_column($pdo, 'team_members', 'name', "VARCHAR(160) NOT NULL");
calendar_ensure_column($pdo, 'team_members', 'phone', "VARCHAR(60) NULL");
calendar_ensure_column($pdo, 'team_members', 'email', "VARCHAR(160) NULL");
calendar_ensure_column($pdo, 'team_members', 'username', "VARCHAR(120) NULL");
calendar_ensure_column($pdo, 'team_members', 'password_hash', "VARCHAR(255) NULL");
calendar_ensure_column($pdo, 'team_members', 'color', "VARCHAR(20) NOT NULL DEFAULT '#163B63'");
calendar_ensure_column($pdo, 'team_members', 'active', "TINYINT(1) NOT NULL DEFAULT 1");
calendar_ensure_column($pdo, 'team_members', 'notes', "TEXT NULL");
calendar_ensure_column($pdo, 'team_members', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

$countTeams = (int)($pdo->query("SELECT COUNT(*) AS total FROM team_members")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

if ($countTeams === 0) {
    $stmt = $pdo->prepare("INSERT INTO team_members (name, color, active) VALUES (?, ?, ?)");

    foreach ([
        ['Echipa 1', '#163B63', 1],
        ['Echipa 2', '#315B7D', 1],
        ['Echipa 3', '#64748B', 1],
    ] as $team) {
        $stmt->execute($team);
    }
}

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

calendar_ensure_column($pdo, 'appointments', 'client_id', "INT NULL");
calendar_ensure_column($pdo, 'appointments', 'client_location_id', "INT NULL");
calendar_ensure_column($pdo, 'appointments', 'team_member_id', "INT NULL");
calendar_ensure_column($pdo, 'appointments', 'title', "VARCHAR(255) NULL");
calendar_ensure_column($pdo, 'appointments', 'service_type', "VARCHAR(150) NULL");
calendar_ensure_column($pdo, 'appointments', 'appointment_date', "DATE NOT NULL");
calendar_ensure_column($pdo, 'appointments', 'start_time', "TIME NULL");
calendar_ensure_column($pdo, 'appointments', 'end_time', "TIME NULL");
calendar_ensure_column($pdo, 'appointments', 'status', "VARCHAR(30) NOT NULL DEFAULT 'confirmata'");
calendar_ensure_column($pdo, 'appointments', 'address', "VARCHAR(255) NULL");
calendar_ensure_column($pdo, 'appointments', 'contact_person', "VARCHAR(180) NULL");
calendar_ensure_column($pdo, 'appointments', 'contact_phone', "VARCHAR(60) NULL");
calendar_ensure_column($pdo, 'appointments', 'notes', "TEXT NULL");
calendar_ensure_column($pdo, 'appointments', 'completion_notes', "TEXT NULL");
calendar_ensure_column($pdo, 'appointments', 'billing_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
calendar_ensure_column($pdo, 'appointments', 'billing_status', "VARCHAR(30) NOT NULL DEFAULT 'de_facturat'");
calendar_ensure_column($pdo, 'appointments', 'billing_note', "TEXT NULL");
calendar_ensure_column($pdo, 'appointments', 'billing_updated_at', "DATETIME NULL");
calendar_ensure_column($pdo, 'appointments', 'billing_updated_by', "INT NULL");
calendar_ensure_column($pdo, 'appointments', 'contract_id', "INT NULL");
calendar_ensure_column($pdo, 'appointments', 'contract_service_id', "INT NULL");
calendar_ensure_column($pdo, 'appointments', 'task_id', "INT NULL");
calendar_ensure_column($pdo, 'appointments', 'service_id', "INT NULL");
calendar_ensure_column($pdo, 'appointments', 'surface_value', "DECIMAL(14,3) NULL");
calendar_ensure_column($pdo, 'appointments', 'surface_unit', "VARCHAR(30) NULL");
calendar_ensure_column($pdo, 'appointments', 'currency', "VARCHAR(10) NOT NULL DEFAULT 'RON'");
calendar_ensure_column($pdo, 'appointments', 'document_id', "INT NULL");
calendar_ensure_column($pdo, 'appointments', 'document_item_id', "INT NULL");
calendar_ensure_column($pdo, 'appointments', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");


$pdo->exec("
    CREATE TABLE IF NOT EXISTS appointment_teams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id INT NOT NULL,
        team_id INT NOT NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_appointment_team (appointment_id, team_id),
        KEY idx_appointment_teams_team (team_id),
        KEY idx_appointment_teams_appointment (appointment_id)
    )
");
calendar_ensure_column($pdo, 'appointment_teams', 'appointment_id', "INT NOT NULL");
calendar_ensure_column($pdo, 'appointment_teams', 'team_id', "INT NOT NULL");
calendar_ensure_column($pdo, 'appointment_teams', 'is_primary', "TINYINT(1) NOT NULL DEFAULT 0");
calendar_ensure_column($pdo, 'appointment_teams', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

try {
    $pdo->exec("
        INSERT IGNORE INTO appointment_teams (appointment_id, team_id, is_primary)
        SELECT a.id, a.team_member_id, 1
        FROM appointments a
        WHERE a.team_member_id IS NOT NULL
          AND a.team_member_id > 0
    ");
} catch (Throwable $e) {
    error_log('PestZone appointment teams backfill error: ' . $e->getMessage());
}

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

calendar_ensure_column($pdo, 'services', 'name', "VARCHAR(150) NULL");
calendar_ensure_column($pdo, 'services', 'description', "TEXT NULL");
calendar_ensure_column($pdo, 'services', 'default_duration', "INT NOT NULL DEFAULT 60");
calendar_ensure_column($pdo, 'services', 'active', "TINYINT(1) NOT NULL DEFAULT 1");
calendar_ensure_column($pdo, 'services', 'sort_order', "INT NOT NULL DEFAULT 0");
calendar_ensure_column($pdo, 'services', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

$countServices = (int)($pdo->query("SELECT COUNT(*) AS total FROM services")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

if ($countServices === 0) {
    $stmt = $pdo->prepare("
        INSERT INTO services (name, description, default_duration, active, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ([
        ['Deratizare', 'Servicii de combatere rozatoare', 60, 1, 1],
        ['Dezinsectie', 'Servicii de combatere insecte', 60, 1, 2],
        ['Dezinfectie', 'Servicii de dezinfectie spatii', 60, 1, 3],
        ['Monitorizare capcane', 'Verificare si monitorizare capcane', 30, 1, 4],
        ['Tratament plosnite', 'Tratament impotriva plosnitelor', 120, 1, 5],
        ['Alt serviciu', 'Serviciu personalizat', 60, 1, 99],
    ] as $service) {
        $stmt->execute($service);
    }
}

if (calendar_table_exists($pdo, 'tasks')) {
    calendar_ensure_column($pdo, 'tasks', 'client_location_id', "INT NULL");
    calendar_ensure_column($pdo, 'tasks', 'contact_person', "VARCHAR(180) NULL");
    calendar_ensure_column($pdo, 'tasks', 'contact_phone', "VARCHAR(60) NULL");
    calendar_ensure_column($pdo, 'tasks', 'contract_id', "INT NULL");
    calendar_ensure_column($pdo, 'tasks', 'contract_service_id', "INT NULL");
    calendar_ensure_column($pdo, 'tasks', 'service_id', "INT NULL");
    calendar_ensure_column($pdo, 'tasks', 'location_name', "VARCHAR(220) NULL");
    calendar_ensure_column($pdo, 'tasks', 'surface_value', "DECIMAL(14,3) NULL");
    calendar_ensure_column($pdo, 'tasks', 'surface_unit', "VARCHAR(30) NULL");
    calendar_ensure_column($pdo, 'tasks', 'billing_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    calendar_ensure_column($pdo, 'tasks', 'currency', "VARCHAR(10) NOT NULL DEFAULT 'RON'");
    calendar_ensure_column($pdo, 'tasks', 'document_id', "INT NULL");
    calendar_ensure_column($pdo, 'tasks', 'document_item_id', "INT NULL");
}

/*
|--------------------------------------------------------------------------
| Parametri request
|--------------------------------------------------------------------------
*/
$currentDate = safe_date($_GET['date'] ?? date('Y-m-d'));
$currentDateObj = new DateTime($currentDate);
$view = safe_view($_GET['view'] ?? 'day');
$selectedTeam = $isTeamUser ? (string)$currentTeamId : ($_GET['team'] ?? 'all');

/*
|--------------------------------------------------------------------------
| Mesaj pentru echipa logata
|--------------------------------------------------------------------------
*/
$teamTodayJobs = 0;
$teamGreetingName = '';

if ($isTeamUser) {
    $teamGreetingName = $_SESSION['team_member_name'] ?? 'echipa ta';

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT a.id) AS total
        FROM appointments a
        INNER JOIN appointment_teams at ON at.appointment_id = a.id
        WHERE at.team_id = ?
          AND a.appointment_date = ?
          AND a.status != 'anulata'
    ");
    $stmt->execute([(int)$currentTeamId, date('Y-m-d')]);
    $teamTodayJobs = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Servicii active
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT id, name, default_duration
    FROM services
    WHERE active = 1
    ORDER BY sort_order ASC, name ASC
");
$activeServices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$serviceDurations = [];
foreach ($activeServices as $service) {
    $serviceDurations[$service['name']] = (int)$service['default_duration'];
}

$durationOptions = [
    30  => '30 minute',
    60  => '1 ora',
    90  => '1h 30min',
    120 => '2 ore',
    180 => '3 ore',
    240 => '4 ore',
];

$appointmentTimeOptions = [];
for ($h = 0; $h < 24; $h++) {
    $appointmentTimeOptions[] = sprintf('%02d:00', $h);
    $appointmentTimeOptions[] = sprintf('%02d:30', $h);
}

/*
|--------------------------------------------------------------------------
| Clienti si locatii pentru formular
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT
        id,
        client_type,
        name,
        phone,
        email,
        address,
        registered_address,
        legal_representative_name,
        active
    FROM clients
    WHERE active = 1
    ORDER BY name ASC, id ASC
");
$activeClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("
    SELECT
        id,
        client_id,
        location_name,
        address,
        contact_person,
        phone,
        notes,
        active,
        sort_order
    FROM client_locations
    WHERE active = 1
    ORDER BY client_id ASC, sort_order ASC, location_name ASC, id ASC
");
$activeLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$clientsForJs = [];
foreach ($activeClients as $client) {
    $effectiveAddress = calendar_client_address($client);
    $contactPerson = calendar_client_contact_person($client);
    $contactPhone = calendar_client_contact_phone($client);

    $clientsForJs[(int)$client['id']] = [
        'id' => (int)$client['id'],
        'client_type' => $client['client_type'] ?? 'company',
        'name' => $client['name'] ?? '',
        'phone' => $client['phone'] ?? '',
        'email' => $client['email'] ?? '',
        'address' => $client['address'] ?? '',
        'registered_address' => $client['registered_address'] ?? '',
        'effective_address' => $effectiveAddress,
        'legal_representative_name' => $client['legal_representative_name'] ?? '',
        'contact_person' => $contactPerson,
        'contact_phone' => $contactPhone,
    ];
}

$locationsByClientForJs = [];
foreach ($activeLocations as $location) {
    $clientId = (int)$location['client_id'];

    if (!isset($locationsByClientForJs[$clientId])) {
        $locationsByClientForJs[$clientId] = [];
    }

    $locationsByClientForJs[$clientId][] = [
        'id' => (int)$location['id'],
        'client_id' => $clientId,
        'location_name' => $location['location_name'] ?? '',
        'address' => $location['address'] ?? '',
        'contact_person' => $location['contact_person'] ?? '',
        'phone' => $location['phone'] ?? '',
        'notes' => $location['notes'] ?? '',
    ];
}


$contractServicesByClientLocationForJs = [];
if (calendar_table_exists($pdo, 'contract_services') && calendar_table_exists($pdo, 'contracts')) {
    try {
        $stmt = $pdo->query("
            SELECT
                cs.id AS contract_service_id,
                cs.contract_id,
                cs.client_id,
                cs.client_location_id,
                cs.service_id,
                cs.service_name,
                cs.price,
                cs.surface_value,
                cs.surface_unit,
                cs.currency,
                cs.document_id,
                cs.document_item_id,
                c.contract_number
            FROM contract_services cs
            INNER JOIN contracts c ON c.id = cs.contract_id
            WHERE cs.client_id IS NOT NULL
              AND cs.client_location_id IS NOT NULL
              AND cs.client_location_id > 0
              AND LOWER(COALESCE(c.status, 'activ')) IN ('activ', 'active', 'emis', 'issued')
            ORDER BY c.start_date DESC, c.id DESC, cs.id ASC
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clientId = (int)($row['client_id'] ?? 0);
            $locationId = (int)($row['client_location_id'] ?? 0);
            if ($clientId <= 0 || $locationId <= 0) {
                continue;
            }
            if (!isset($contractServicesByClientLocationForJs[$clientId])) {
                $contractServicesByClientLocationForJs[$clientId] = [];
            }
            if (!isset($contractServicesByClientLocationForJs[$clientId][$locationId])) {
                $contractServicesByClientLocationForJs[$clientId][$locationId] = [];
            }
            $contractServicesByClientLocationForJs[$clientId][$locationId][] = [
                'contract_service_id' => (int)($row['contract_service_id'] ?? 0),
                'contract_id' => (int)($row['contract_id'] ?? 0),
                'service_name' => (string)($row['service_name'] ?? ''),
                'price' => calendar_money_input($row['price'] ?? 0),
                'service_id' => (int)($row['service_id'] ?? 0),
                'surface_value' => (string)($row['surface_value'] ?? ''),
                'surface_unit' => (string)($row['surface_unit'] ?? ''),
                'currency' => (string)($row['currency'] ?? 'RON'),
                'document_id' => (int)($row['document_id'] ?? 0),
                'document_item_id' => (int)($row['document_item_id'] ?? 0),
                'contract_number' => (string)($row['contract_number'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log('PestZone contract services JS data error: ' . $e->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| Prefill client / task
|--------------------------------------------------------------------------
*/
$prefillClient = null;
$prefillLocationId = 0;
$shouldOpenCreateModal = false;
$prefillTaskId = 0;
$prefillServiceType = trim($_GET['service_type'] ?? '');
$prefillAddress = '';
$prefillContactPerson = '';
$prefillContactPhone = '';
$prefillBillingAmount = '0.00';
$prefillContractId = 0;
$prefillContractServiceId = 0;
$prefillServiceId = 0;
$prefillSurfaceValue = '';
$prefillSurfaceUnit = '';
$prefillDocumentId = 0;
$prefillDocumentItemId = 0;

if ($isAdmin && !empty($_GET['client_id'])) {
    $clientId = (int)$_GET['client_id'];
    $client = calendar_get_client($pdo, $clientId);

    if ($client) {
        $prefillClient = [
            'id' => (int)$client['id'],
            'client_type' => $client['client_type'] ?? 'company',
            'name' => $client['name'] ?? '',
            'phone' => $client['phone'] ?? '',
            'address' => $client['address'] ?? '',
            'registered_address' => $client['registered_address'] ?? '',
            'effective_address' => calendar_client_address($client),
            'legal_representative_name' => $client['legal_representative_name'] ?? '',
            'contact_person' => calendar_client_contact_person($client),
            'contact_phone' => calendar_client_contact_phone($client),
        ];

        $prefillContactPerson = calendar_client_contact_person($client);
        $prefillContactPhone = calendar_client_contact_phone($client);
    }

    if (!empty($_GET['client_location_id'])) {
        $prefillLocationId = (int)$_GET['client_location_id'];
        $location = calendar_get_location($pdo, $prefillLocationId, $clientId);

        if ($location) {
            $prefillAddress = $location['address'] ?? '';
            $prefillContactPerson = trim((string)($location['contact_person'] ?? '')) ?: $prefillContactPerson;
            $prefillContactPhone = trim((string)($location['phone'] ?? '')) ?: $prefillContactPhone;
        } else {
            $prefillLocationId = 0;
        }
    }

    if ($prefillClient && ($_GET['open_create'] ?? '') === '1') {
        $shouldOpenCreateModal = true;
    }
}

if ($isAdmin && !empty($_GET['task_id']) && calendar_table_exists($pdo, 'tasks')) {
    $prefillTaskId = (int)$_GET['task_id'];

    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.client_id,
            t.client_location_id,
            t.service_type,
            t.address AS task_address,
            t.contact_person AS task_contact_person,
            t.contact_phone AS task_contact_phone,
            t.contract_id,
            t.contract_service_id,
            t.service_id,
            t.surface_value,
            t.surface_unit,
            t.billing_amount,
            t.currency,
            t.document_id,
            t.document_item_id,
            t.due_date,
            c.name AS client_name,
            c.phone AS client_phone,
            c.address AS client_old_address,
            c.registered_address AS client_registered_address,
            c.client_type AS client_type,
            c.legal_representative_name AS client_legal_representative_name,
            l.location_name,
            l.address AS location_address,
            l.contact_person AS location_contact_person,
            l.phone AS location_phone
        FROM tasks t
        LEFT JOIN clients c ON c.id = t.client_id
        LEFT JOIN client_locations l ON l.id = t.client_location_id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->execute([$prefillTaskId]);
    $prefillTask = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($prefillTask) {
        if ($prefillServiceType === '') {
            $prefillServiceType = $prefillTask['service_type'] ?? '';
        }

        $prefillLocationId = (int)($prefillTask['client_location_id'] ?? 0);
        $clientFallbackAddress = trim((string)($prefillTask['client_registered_address'] ?? '')) ?: trim((string)($prefillTask['client_old_address'] ?? ''));
        $clientFallbackContact = (trim((string)($prefillTask['client_type'] ?? 'company')) === 'individual')
            ? trim((string)($prefillTask['client_name'] ?? ''))
            : (trim((string)($prefillTask['client_legal_representative_name'] ?? '')) ?: trim((string)($prefillTask['client_name'] ?? '')));

        $prefillAddress = $prefillTask['task_address'] ?: ($prefillTask['location_address'] ?: $clientFallbackAddress);
        $prefillContactPerson = $prefillTask['task_contact_person'] ?: ($prefillTask['location_contact_person'] ?: $clientFallbackContact);
        $prefillContactPhone = $prefillTask['task_contact_phone'] ?: ($prefillTask['location_phone'] ?: ($prefillTask['client_phone'] ?? ''));
        $prefillBillingAmount = number_format((float)($prefillTask['billing_amount'] ?? 0), 2, '.', '');
        $prefillContractId = (int)($prefillTask['contract_id'] ?? 0);
        $prefillContractServiceId = (int)($prefillTask['contract_service_id'] ?? 0);
        $prefillServiceId = (int)($prefillTask['service_id'] ?? 0);
        $prefillSurfaceValue = (string)($prefillTask['surface_value'] ?? '');
        $prefillSurfaceUnit = (string)($prefillTask['surface_unit'] ?? '');
        $prefillDocumentId = (int)($prefillTask['document_id'] ?? 0);
        $prefillDocumentItemId = (int)($prefillTask['document_item_id'] ?? 0);

        if (!$prefillClient && !empty($prefillTask['client_id'])) {
            $prefillClient = [
                'id' => (int)$prefillTask['client_id'],
                'client_type' => $prefillTask['client_type'] ?? 'company',
                'name' => $prefillTask['client_name'] ?? '',
                'phone' => $prefillTask['client_phone'] ?? '',
                'address' => $prefillTask['client_old_address'] ?? '',
                'registered_address' => $prefillTask['client_registered_address'] ?? '',
                'effective_address' => $clientFallbackAddress,
                'legal_representative_name' => $prefillTask['client_legal_representative_name'] ?? '',
                'contact_person' => $clientFallbackContact,
                'contact_phone' => $prefillTask['client_phone'] ?? '',
            ];
        }

        if (($_GET['open_create'] ?? '') === '1') {
            $shouldOpenCreateModal = true;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Navigare date
|--------------------------------------------------------------------------
*/
$prevDateObj = clone $currentDateObj;
$nextDateObj = clone $currentDateObj;

$modifiers = [
    'day'   => '1 day',
    'week'  => '7 days',
    'month' => '1 month',
    'year'  => '1 year',
];

$prevDateObj->modify('-' . $modifiers[$view]);
$nextDateObj->modify('+' . $modifiers[$view]);

$prevDate = $prevDateObj->format('Y-m-d');
$nextDate = $nextDateObj->format('Y-m-d');

/*
|--------------------------------------------------------------------------
| AJAX programare individuala
|--------------------------------------------------------------------------
*/
if (isset($_GET['appointment_id'])) {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int)$_GET['appointment_id'];

    $sql = "
        SELECT
            a.*,
            c.name AS client_name,
            c.phone AS client_phone,
            c.address AS client_old_address,
            c.registered_address AS client_registered_address,
            c.client_type AS client_type,
            c.legal_representative_name AS client_legal_representative_name,
            t.name AS team_name,
            access_at.is_primary AS access_is_primary,
            l.location_name,
            l.address AS location_address,
            l.contact_person AS location_contact_person,
            l.phone AS location_phone
        FROM appointments a
        LEFT JOIN clients c ON c.id = a.client_id
        LEFT JOIN team_members t ON t.id = a.team_member_id
        LEFT JOIN appointment_teams access_at ON access_at.appointment_id = a.id
        LEFT JOIN client_locations l ON l.id = a.client_location_id
        WHERE a.id = ?
    ";

    $params = [$id];

    if ($isTeamUser) {
        $sql .= " AND access_at.team_id = ? ";
        $params[] = (int)$currentTeamId;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($row) {
        $row['client_address'] = trim((string)($row['client_registered_address'] ?? '')) ?: trim((string)($row['client_old_address'] ?? ''));
        $fallbackContact = (($row['client_type'] ?? 'company') === 'individual')
            ? trim((string)($row['client_name'] ?? ''))
            : (trim((string)($row['client_legal_representative_name'] ?? '')) ?: trim((string)($row['client_name'] ?? '')));
        $row['effective_contact_person'] = trim((string)($row['contact_person'] ?? '')) ?: (trim((string)($row['location_contact_person'] ?? '')) ?: $fallbackContact);
        $row['effective_contact_phone'] = trim((string)($row['contact_phone'] ?? '')) ?: (trim((string)($row['location_phone'] ?? '')) ?: trim((string)($row['client_phone'] ?? '')));
        $appointmentTeams = calendar_get_appointment_teams($pdo, (int)$row['id']);
        $row['support_team_ids'] = [];
        $row['support_team_names'] = [];
        foreach ($appointmentTeams as $apptTeam) {
            if ((int)($apptTeam['is_primary'] ?? 0) === 1) {
                continue;
            }
            $row['support_team_ids'][] = (int)$apptTeam['team_id'];
            $row['support_team_names'][] = (string)($apptTeam['name'] ?? '');
        }
        $row['is_support_only'] = $isTeamUser && ((int)($row['team_member_id'] ?? 0) !== (int)$currentTeamId);
        $row['primary_team_id'] = (int)($row['team_member_id'] ?? 0);
        $row['primary_team_name'] = (string)($row['team_name'] ?? '');
        $row = calendar_attach_pv_meta($pdo, $row);

        if (!$isAdmin) {
            unset(
                $row['billing_amount'],
                $row['billing_status'],
                $row['billing_note'],
                $row['billing_updated_at'],
                $row['billing_updated_by']
            );
        }
    }

    echo json_encode($row, JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| POST handler
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? 'create';
    $redirectDate = safe_date($_POST['redirect_date'] ?? date('Y-m-d'));
    $redirectView = safe_view($_POST['redirect_view'] ?? 'day');
    $redirectTeam = $isTeamUser ? (string)$currentTeamId : ($_POST['redirect_team'] ?? 'all');

    $baseRedirect = 'calendar.php?date=' . urlencode($redirectDate) . '&view=' . urlencode($redirectView);

    if ($isAdmin) {
        $baseRedirect .= '&team=' . urlencode($redirectTeam);
    }

    if ($action === 'team_update') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $completionNotes = trim($_POST['completion_notes'] ?? '');

        if (!$isTeamUser || $appointmentId <= 0) {
            header('Location: ' . $baseRedirect . '&error=1');
            exit;
        }

        if ($completionNotes === '') {
            header('Location: ' . $baseRedirect . '&finish_error=1');
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE appointments
            SET status = 'finalizata',
                completion_notes = ?
            WHERE id = ?
              AND team_member_id = ?
        ");
        $stmt->execute([$completionNotes, $appointmentId, (int)$currentTeamId]);

        if (calendar_table_exists($pdo, 'contract_services')) {
            $pdo->prepare("
                UPDATE contract_services cs
                INNER JOIN appointments a ON a.contract_service_id = cs.id
                SET cs.status = 'executat'
                WHERE a.id = ?
                  AND a.team_member_id = ?
            ")->execute([$appointmentId, (int)$currentTeamId]);
        }

        header('Location: ' . $baseRedirect . '&finished=1');
        exit;
    }

    if ($action === 'admin_finalize') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);

        if (!$isAdmin || $appointmentId <= 0) {
            header('Location: ' . $baseRedirect . '&error=1');
            exit;
        }

        $pdo->prepare("
            UPDATE appointments
            SET status = 'finalizata',
                completion_notes = COALESCE(NULLIF(completion_notes, ''), 'Finalizata din birou')
            WHERE id = ?
        ")->execute([$appointmentId]);

        if (calendar_table_exists($pdo, 'contract_services')) {
            $pdo->prepare("
                UPDATE contract_services cs
                INNER JOIN appointments a ON a.contract_service_id = cs.id
                SET cs.status = 'executat'
                WHERE a.id = ?
            ")->execute([$appointmentId]);
        }

        header('Location: ' . $baseRedirect . '&finished=1');
        exit;
    }

    if ($action === 'delete') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);

        if ($isAdmin && $appointmentId > 0) {
            $stmt = $pdo->prepare("SELECT contract_service_id FROM appointments WHERE id = ? LIMIT 1");
            $stmt->execute([$appointmentId]);
            $deletedAppointment = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $deletedContractServiceId = (int)($deletedAppointment['contract_service_id'] ?? 0);

            if (calendar_table_exists($pdo, 'appointment_teams')) {
                $pdo->prepare('DELETE FROM appointment_teams WHERE appointment_id = ?')->execute([$appointmentId]);
            }

            $pdo->prepare('DELETE FROM appointments WHERE id = ?')->execute([$appointmentId]);

            if ($deletedContractServiceId > 0 && calendar_table_exists($pdo, 'contract_services')) {
                $pdo->prepare("
                    UPDATE contract_services
                    SET status = 'neprogramat', appointment_id = NULL
                    WHERE id = ?
                      AND status = 'programat'
                ")->execute([$deletedContractServiceId]);
            }

            if (calendar_table_exists($pdo, 'tasks')) {
                $pdo->prepare("
                    UPDATE tasks
                    SET status = 'de_programat', appointment_id = NULL
                    WHERE appointment_id = ?
                      AND status = 'programat'
                ")->execute([$appointmentId]);
            }
        }

        header('Location: ' . $baseRedirect . '&deleted=1');
        exit;
    }


    if ($action === 'check_availability') {
        header('Content-Type: application/json; charset=utf-8');

        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'message' => 'Nu ai dreptul sa verifici disponibilitatea.']);
            exit;
        }

        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $teamMemberId = !empty($_POST['team_member_id']) ? (int)$_POST['team_member_id'] : null;
        $supportTeamIds = calendar_post_support_team_ids($teamMemberId);
        $appointmentDate = safe_date($_POST['appointment_date'] ?? $redirectDate);
        $startTime = calendar_normalize_half_hour_time($_POST['start_time'] ?? '');
        $duration = max(30, (int)($_POST['duration'] ?? 60));
        if ($duration % 30 !== 0) {
            $duration = max(30, (int)(round($duration / 30) * 30));
        }

        if (!$teamMemberId || $appointmentDate === '' || $startTime === null) {
            echo json_encode(['ok' => true, 'message' => '']);
            exit;
        }

        try {
            $startDT = new DateTime($appointmentDate . ' ' . $startTime);
            $endDT = clone $startDT;
            $endDT->modify('+' . $duration . ' minutes');

            $conflicts = calendar_find_team_time_conflicts(
                $pdo,
                array_merge([(int)$teamMemberId], $supportTeamIds),
                $appointmentDate,
                $startDT->format('H:i:s'),
                $endDT->format('H:i:s'),
                $appointmentId > 0 ? $appointmentId : null
            );

            if ($conflicts) {
                echo json_encode([
                    'ok' => false,
                    'message' => calendar_conflict_message($conflicts),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode(['ok' => true, 'message' => '']);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'message' => 'Nu s-a putut verifica disponibilitatea.']);
            exit;
        }
    }

    if ($isAdmin) {
        $clientIdFromForm = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $clientLocationIdFromForm = !empty($_POST['client_location_id']) ? (int)$_POST['client_location_id'] : null;
        $taskIdFromForm = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : 0;

        $address = trim($_POST['address'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $serviceType = trim($_POST['service_type'] ?? '');
        $teamMemberId = !empty($_POST['team_member_id']) ? (int)$_POST['team_member_id'] : null;
        $supportTeamIds = calendar_post_support_team_ids($teamMemberId);
        $appointmentDate = safe_date($_POST['appointment_date'] ?? $redirectDate);
        $startTime = calendar_normalize_half_hour_time($_POST['start_time'] ?? '');
        $duration = max(30, (int)($_POST['duration'] ?? 60));
        if ($duration % 30 !== 0) {
            $duration = max(30, (int)(round($duration / 30) * 30));
        }
        $notes = trim($_POST['notes'] ?? '');
        $billingAmount = calendar_money_value($_POST['billing_amount'] ?? 0);
        $notInvoiceable = !empty($_POST['not_invoiceable']);
        $billingNote = trim($_POST['billing_note'] ?? '');
        $postedContractId = !empty($_POST['contract_id']) ? (int)$_POST['contract_id'] : null;
        $postedContractServiceId = !empty($_POST['contract_service_id']) ? (int)$_POST['contract_service_id'] : null;
        $serviceIdForAppointment = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
        $surfaceValueForAppointment = ($_POST['surface_value'] ?? '') !== '' && is_numeric($_POST['surface_value']) ? (float)$_POST['surface_value'] : null;
        $surfaceUnitForAppointment = trim($_POST['surface_unit'] ?? '');
        $currencyForAppointment = trim($_POST['currency'] ?? 'RON') ?: 'RON';
        $documentIdForAppointment = !empty($_POST['document_id']) ? (int)$_POST['document_id'] : null;
        $documentItemIdForAppointment = !empty($_POST['document_item_id']) ? (int)$_POST['document_item_id'] : null;

        $client = $clientIdFromForm > 0 ? calendar_get_client($pdo, $clientIdFromForm) : null;

        $billingIsValid = $notInvoiceable ? ($billingNote !== '') : true;

        if ($client && $serviceType && $appointmentDate && $startTime !== null && $teamMemberId && $billingIsValid) {
            $startDT = new DateTime($appointmentDate . ' ' . $startTime);
            $endDT = clone $startDT;
            $endDT->modify('+' . $duration . ' minutes');

            $clientId = (int)$client['id'];
            $clientName = trim((string)($client['name'] ?? 'Client'));
            $title = $serviceType . ' - ' . $clientName;
            $clientLocationId = null;
            $selectedLocation = null;

            if ($clientLocationIdFromForm) {
                $selectedLocation = calendar_get_location($pdo, $clientLocationIdFromForm, $clientId);

                if ($selectedLocation) {
                    $clientLocationId = (int)$selectedLocation['id'];
                }
            }

            $address = calendar_snapshot_address($client, $selectedLocation, $address);
            $contactPerson = calendar_snapshot_contact_person($client, $selectedLocation, $contactPerson);
            $contactPhone = calendar_snapshot_contact_phone($client, $selectedLocation, $contactPhone);

            $contractIdForAppointment = null;
            $contractServiceIdForAppointment = null;

            if ($taskIdFromForm > 0 && calendar_table_exists($pdo, 'tasks')) {
                $stmt = $pdo->prepare("
                    SELECT contract_id, contract_service_id, service_id, surface_value, surface_unit, billing_amount, currency, document_id, document_item_id
                    FROM tasks
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$taskIdFromForm]);
                $taskContractData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                if (!empty($taskContractData['contract_id'])) {
                    $contractIdForAppointment = (int)$taskContractData['contract_id'];
                }

                if (!empty($taskContractData['contract_service_id'])) {
                    $contractServiceIdForAppointment = (int)$taskContractData['contract_service_id'];
                }
                if (!empty($taskContractData['service_id'])) {
                    $serviceIdForAppointment = (int)$taskContractData['service_id'];
                }
                if (($taskContractData['surface_value'] ?? '') !== '' && is_numeric($taskContractData['surface_value'])) {
                    $surfaceValueForAppointment = (float)$taskContractData['surface_value'];
                }
                if (!empty($taskContractData['surface_unit'])) {
                    $surfaceUnitForAppointment = (string)$taskContractData['surface_unit'];
                }
                if (!$notInvoiceable && $billingAmount <= 0 && isset($taskContractData['billing_amount'])) {
                    $billingAmount = calendar_money_value($taskContractData['billing_amount']);
                }
                if (!empty($taskContractData['currency'])) {
                    $currencyForAppointment = (string)$taskContractData['currency'];
                }
                if (!empty($taskContractData['document_id'])) {
                    $documentIdForAppointment = (int)$taskContractData['document_id'];
                }
                if (!empty($taskContractData['document_item_id'])) {
                    $documentItemIdForAppointment = (int)$taskContractData['document_item_id'];
                }
            }


            $matchedContractService = calendar_find_contract_service(
                $pdo,
                $clientId,
                $clientLocationId ?: null,
                $serviceType,
                $postedContractServiceId
            );

            if (!$matchedContractService) {
                $matchedContractService = calendar_find_contract_service(
                    $pdo,
                    $clientId,
                    $clientLocationId ?: null,
                    $serviceType,
                    null
                );
            }

            if ($matchedContractService) {
                $contractIdForAppointment = (int)($matchedContractService['contract_id'] ?? 0) ?: ($postedContractId ?: null);
                $contractServiceIdForAppointment = (int)($matchedContractService['contract_service_id'] ?? 0) ?: null;
                if (!$notInvoiceable && $billingAmount <= 0) {
                    $billingAmount = calendar_money_value($matchedContractService['price'] ?? 0);
                }
                if (!empty($matchedContractService['service_id'])) {
                    $serviceIdForAppointment = (int)$matchedContractService['service_id'];
                }
                if (($matchedContractService['surface_value'] ?? '') !== '' && is_numeric($matchedContractService['surface_value'])) {
                    $surfaceValueForAppointment = (float)$matchedContractService['surface_value'];
                }
                if (!empty($matchedContractService['surface_unit'])) {
                    $surfaceUnitForAppointment = (string)$matchedContractService['surface_unit'];
                }
                if (!empty($matchedContractService['currency'])) {
                    $currencyForAppointment = (string)$matchedContractService['currency'];
                }
                if (!empty($matchedContractService['document_id'])) {
                    $documentIdForAppointment = (int)$matchedContractService['document_id'];
                }
                if (!empty($matchedContractService['document_item_id'])) {
                    $documentItemIdForAppointment = (int)$matchedContractService['document_item_id'];
                }
            } elseif ($postedContractId) {
                $contractIdForAppointment = $postedContractId;
            }

            if ($notInvoiceable) {
                $billingAmount = 0.0;
            }
            $billingStatus = $notInvoiceable ? 'nefacturabil' : 'de_facturat';
            $billingNoteForDb = $notInvoiceable ? $billingNote : null;

            if (!$notInvoiceable && $billingAmount <= 0) {
                header('Location: ' . $baseRedirect . '&error=1');
                exit;
            }

            if ($action === 'update') {
                $appointmentId = (int)($_POST['appointment_id'] ?? 0);

                if ($appointmentId > 0) {
                    $conflicts = calendar_find_team_time_conflicts(
                        $pdo,
                        array_merge([(int)$teamMemberId], $supportTeamIds),
                        $appointmentDate,
                        $startDT->format('H:i:s'),
                        $endDT->format('H:i:s'),
                        $appointmentId
                    );

                    if ($conflicts) {
                        $message = calendar_conflict_message($conflicts);
                        header('Location: calendar.php?date=' . urlencode($appointmentDate) . '&view=' . urlencode($redirectView) . '&team=' . urlencode($redirectTeam) . '&conflict=1&conflict_msg=' . urlencode($message));
                        exit;
                    }

                    $oldStmt = $pdo->prepare("SELECT appointment_date, start_time, end_time FROM appointments WHERE id = ? LIMIT 1");
                    $oldStmt->execute([$appointmentId]);
                    $oldAppointment = $oldStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                    $pdo->prepare("
                        UPDATE appointments
                        SET client_id = ?,
                            client_location_id = ?,
                            team_member_id = ?,
                            title = ?,
                            service_type = ?,
                            appointment_date = ?,
                            start_time = ?,
                            end_time = ?,
                            address = ?,
                            contact_person = ?,
                            contact_phone = ?,
                            notes = ?,
                            billing_amount = ?,
                            billing_status = ?,
                            billing_note = ?,
                            billing_updated_at = NOW(),
                            billing_updated_by = ?,
                            contract_id = ?,
                            contract_service_id = ?,
                            task_id = ?,
                            service_id = ?,
                            surface_value = ?,
                            surface_unit = ?,
                            currency = ?,
                            document_id = ?,
                            document_item_id = ?
                        WHERE id = ?
                    ")->execute([
                        $clientId,
                        $clientLocationId ?: null,
                        $teamMemberId,
                        $title,
                        $serviceType,
                        $appointmentDate,
                        $startDT->format('H:i:s'),
                        $endDT->format('H:i:s'),
                        $address ?: null,
                        $contactPerson ?: null,
                        $contactPhone ?: null,
                        $notes ?: null,
                        $billingAmount,
                        $billingStatus,
                        $billingNoteForDb,
                        current_user_id(),
                        $contractIdForAppointment,
                        $contractServiceIdForAppointment,
                        $taskIdFromForm ?: null,
                        $serviceIdForAppointment,
                        $surfaceValueForAppointment,
                        $surfaceUnitForAppointment ?: null,
                        $currencyForAppointment,
                        $documentIdForAppointment,
                        $documentItemIdForAppointment,
                        $appointmentId,
                    ]);

                    calendar_sync_appointment_teams($pdo, $appointmentId, $teamMemberId, $supportTeamIds);

                    $oldDate = (string)($oldAppointment['appointment_date'] ?? '');
                    $oldStart = substr((string)($oldAppointment['start_time'] ?? ''), 0, 5);
                    $oldEnd = substr((string)($oldAppointment['end_time'] ?? ''), 0, 5);
                    $newStart = $startDT->format('H:i');
                    $newEnd = $endDT->format('H:i');
                    $timeOrDateChanged = ($oldDate !== $appointmentDate || $oldStart !== $newStart || $oldEnd !== $newEnd);

                    // Regula noua: SMS-ul automat se trimite doar la programarea initiala.
                    // La modificari de data/ora, utilizatorul trimite SMS manual din fisa programarii.
                    $updateRedirectParam = $timeOrDateChanged ? '&updated_time_changed=1' : '&updated=1';

                    header('Location: calendar.php?date=' . urlencode($appointmentDate) . '&view=' . urlencode($redirectView) . '&team=' . urlencode($redirectTeam) . $updateRedirectParam);
                    exit;
                }
            }

            if ($action === 'create') {
                $conflicts = calendar_find_team_time_conflicts(
                    $pdo,
                    array_merge([(int)$teamMemberId], $supportTeamIds),
                    $appointmentDate,
                    $startDT->format('H:i:s'),
                    $endDT->format('H:i:s'),
                    null
                );

                if ($conflicts) {
                    $message = calendar_conflict_message($conflicts);
                    header('Location: calendar.php?date=' . urlencode($appointmentDate) . '&view=' . urlencode($redirectView) . '&team=' . urlencode($redirectTeam) . '&conflict=1&conflict_msg=' . urlencode($message));
                    exit;
                }

                $pdo->prepare("
                    INSERT INTO appointments
                    (
                        client_id,
                        client_location_id,
                        team_member_id,
                        title,
                        service_type,
                        appointment_date,
                        start_time,
                        end_time,
                        status,
                        address,
                        contact_person,
                        contact_phone,
                        notes,
                        completion_notes,
                        billing_amount,
                        billing_status,
                        billing_note,
                        billing_updated_at,
                        billing_updated_by,
                        contract_id,
                        contract_service_id,
                        task_id,
                        service_id,
                        surface_value,
                        surface_unit,
                        currency,
                        document_id,
                        document_item_id
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmata', ?, ?, ?, ?, NULL, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $clientId,
                    $clientLocationId ?: null,
                    $teamMemberId,
                    $title,
                    $serviceType,
                    $appointmentDate,
                    $startDT->format('H:i:s'),
                    $endDT->format('H:i:s'),
                    $address ?: null,
                    $contactPerson ?: null,
                    $contactPhone ?: null,
                    $notes ?: null,
                    $billingAmount,
                    $billingStatus,
                    $billingNoteForDb,
                    current_user_id(),
                    $contractIdForAppointment,
                    $contractServiceIdForAppointment,
                    $taskIdFromForm ?: null,
                    $serviceIdForAppointment,
                    $surfaceValueForAppointment,
                    $surfaceUnitForAppointment ?: null,
                    $currencyForAppointment,
                    $documentIdForAppointment,
                    $documentItemIdForAppointment,
                ]);

                $appointmentId = (int)$pdo->lastInsertId();

                calendar_sync_appointment_teams($pdo, $appointmentId, $teamMemberId, $supportTeamIds);

                if ($taskIdFromForm > 0 && function_exists('generate_next_task_after_scheduling')) {
                    generate_next_task_after_scheduling($pdo, $taskIdFromForm, $appointmentId);
                }

                if ($contractServiceIdForAppointment && calendar_table_exists($pdo, 'contract_services')) {
                    $pdo->prepare("
                        UPDATE contract_services
                        SET status = 'programat',
                            appointment_id = ?
                        WHERE id = ?
                    ")->execute([$appointmentId, $contractServiceIdForAppointment]);
                }

                $smsRedirectParam = '';

                if (function_exists('pz_send_appointment_confirmation_sms')) {
                    try {
                        $smsResult = pz_send_appointment_confirmation_sms($appointmentId);

                        if (!empty($smsResult['ok'])) {
                            $smsRedirectParam = '&sms_sent=1';
                        } elseif (!empty($smsResult['skipped'])) {
                            $smsRedirectParam = '&sms_skipped=1';
                        } else {
                            $smsRedirectParam = '&sms_error=1';
                            error_log('PestZone SMS programare esuat: ' . ($smsResult['error'] ?? 'eroare necunoscuta'));
                        }
                    } catch (Throwable $e) {
                        $smsRedirectParam = '&sms_error=1';
                        error_log('PestZone SMS programare exception: ' . $e->getMessage());
                    }
                }

                header('Location: calendar.php?date=' . urlencode($appointmentDate) . '&view=' . urlencode($redirectView) . '&team=' . urlencode($redirectTeam) . '&success=1' . $smsRedirectParam);
                exit;
            }
        }
    }

    header('Location: ' . $baseRedirect . '&error=1');
    exit;
}

/*
|--------------------------------------------------------------------------
| Echipe
|--------------------------------------------------------------------------
*/
if ($isTeamUser) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM team_members
        WHERE active = 1
          AND id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([(int)$currentTeamId]);
    $allTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $allTeams = $pdo->query("
        SELECT *
        FROM team_members
        WHERE active = 1
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$teams = $allTeams;

if (!$isTeamUser && $selectedTeam !== 'all') {
    $filtered = array_values(array_filter($allTeams, function($team) use ($selectedTeam) {
        return (int)$team['id'] === (int)$selectedTeam;
    }));

    if ($filtered) {
        $teams = $filtered;
    } else {
        $teams = $allTeams;
        $selectedTeam = 'all';
    }
}

$teamColumn = [];
foreach ($teams as $index => $team) {
    $teamColumn[(int)$team['id']] = $index + 2;
}

$defaultTeamId = (int)($teams[0]['id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Interval calendar
|--------------------------------------------------------------------------
*/
$rangeStartObj = clone $currentDateObj;
$rangeEndObj = clone $currentDateObj;

if ($view === 'week') {
    $rangeStartObj->modify('monday this week');
    $rangeEndObj = (clone $rangeStartObj)->modify('+6 days');
} elseif ($view === 'month') {
    $rangeStartObj = new DateTime($currentDateObj->format('Y-m-01'));
    $rangeEndObj = (clone $rangeStartObj)->modify('last day of this month');
} elseif ($view === 'year') {
    $rangeStartObj = new DateTime($currentDateObj->format('Y-01-01'));
    $rangeEndObj = new DateTime($currentDateObj->format('Y-12-31'));
}

$rangeStart = $rangeStartObj->format('Y-m-d');
$rangeEnd = $rangeEndObj->format('Y-m-d');

/*
|--------------------------------------------------------------------------
| Programari
|--------------------------------------------------------------------------
*/
$params = [$rangeStart, $rangeEnd];
$whereTeam = '';

if ($isTeamUser) {
    $whereTeam = ' AND at.team_id = ? ';
    $params[] = (int)$currentTeamId;
} elseif ($selectedTeam !== 'all') {
    $whereTeam = ' AND at.team_id = ? ';
    $params[] = (int)$selectedTeam;
}

$stmt = $pdo->prepare("
    SELECT
        a.*,
        at.team_id AS display_team_member_id,
        at.is_primary AS appointment_team_is_primary,
        c.name AS client_name,
        c.phone AS client_phone,
        c.address AS client_old_address,
        c.registered_address AS client_registered_address,
        t.name AS team_name,
        t.color AS team_color,
        dt.name AS display_team_name,
        dt.color AS display_team_color,
        l.location_name,
        l.address AS location_address,
        l.contact_person AS location_contact_person,
        l.phone AS location_phone
    FROM appointments a
    INNER JOIN appointment_teams at ON at.appointment_id = a.id
    LEFT JOIN clients c ON c.id = a.client_id
    LEFT JOIN team_members t ON t.id = a.team_member_id
    LEFT JOIN team_members dt ON dt.id = at.team_id
    LEFT JOIN client_locations l ON l.id = a.client_location_id
    WHERE a.appointment_date BETWEEN ? AND ?
    {$whereTeam}
    ORDER BY a.appointment_date ASC, a.start_time ASC, a.id ASC, at.is_primary DESC
");
$stmt->execute($params);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Evenimente FullCalendar
|--------------------------------------------------------------------------
*/
$calendarEvents = [];

foreach ($appointments as $appt) {
    $eventStartTime = $appt['start_time'] ?: '09:00:00';
    $eventEndTime = $appt['end_time'] ?: null;
    $teamColor = calendar_clean_hex_color($appt['display_team_color'] ?? $appt['team_color'] ?? null);
    $color = $teamColor;
    $textColor = '#ffffff';

    $borderColor = $color;

    if (($appt['status'] ?? '') === 'finalizata') {
        $color = calendar_lighten_hex($teamColor, 0.84);
        $borderColor = calendar_lighten_hex($teamColor, 0.45);
        $textColor = '#002050';
    } elseif (($appt['status'] ?? '') === 'in_lucru') {
        $color = '#64748B';
        $borderColor = '#64748B';
    }

    $calendarEvents[] = [
        'id'              => (int)$appt['id'],
        'title'           => trim(($appt['client_name'] ?: 'Client') . ' - ' . ($appt['service_type'] ?: 'Lucrare')),
        'start'           => $appt['appointment_date'] . 'T' . $eventStartTime,
        'end'             => $eventEndTime ? $appt['appointment_date'] . 'T' . $eventEndTime : null,
        'backgroundColor' => $color,
        'borderColor'     => $borderColor,
        'textColor'       => $textColor,
        'extendedProps'   => [
            'team'     => $appt['display_team_name'] ?? $appt['team_name'],
            'primary_team' => $appt['team_name'] ?? '',
            'is_support' => ((int)($appt['appointment_team_is_primary'] ?? 1) === 0),
            'status'   => $appt['status'],
            'client'   => $appt['client_name'] ?: 'Client',
            'service'  => $appt['service_type'] ?: 'Lucrare',
            'address'  => $appt['address'],
            'phone'    => $appt['contact_phone'] ?: $appt['location_phone'] ?: $appt['client_phone'],
            'location' => $appt['location_name'] ?: 'Sediu social / domiciliu',
        ],
    ];
}

/*
|--------------------------------------------------------------------------
| Sloturi zi
|--------------------------------------------------------------------------
*/
$startHour = 6;
$endHour = 24;
$slots = [];

for ($h = $startHour; $h < $endHour; $h++) {
    $slots[] = sprintf('%02d:00', $h);
    $slots[] = sprintf('%02d:30', $h);
}

/*
|--------------------------------------------------------------------------
| Etichete si dimensiuni
|--------------------------------------------------------------------------
*/
$prettyDate = ro_date_label($currentDate);
$teamCount = max(1, count($teams));

$viewLabels = [
    'day'   => 'Zi',
    'week'  => 'Saptamana',
    'month' => 'Luna',
];

$fullCalendarView = [
    'week'  => 'timeGridWeek',
    'month' => 'dayGridMonth',
][$view] ?? 'timeGridWeek';

$desktopMinTeamWidth = 220;
$tabletMinTeamWidth = 112;
$mobileMinTeamWidth = 52;
$smallMobileMinTeamWidth = 46;

$desktopGridWidth = 76 + ($teamCount * $desktopMinTeamWidth);
$tabletGridWidth = 54 + ($teamCount * $tabletMinTeamWidth);
$mobileGridWidth = 42 + ($teamCount * $mobileMinTeamWidth);
$smallMobileGridWidth = 40 + ($teamCount * $smallMobileMinTeamWidth);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Calendar echipe - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<?php app_theme_css(); ?>

<style>
.calendar-topbar { align-items: center; padding: 12px 20px; overflow-x: hidden; }
.calendar-toolbar { width: 100%; min-width: 0; display: grid; grid-template-columns: auto minmax(220px, 420px) auto; align-items: center; gap: 8px; }
.calendar-line { min-width: 0; }
.calendar-date-line { display: grid; grid-template-columns: 66px 42px 42px 155px; gap: 8px; align-items: center; }
.calendar-date-line .btn, .calendar-action-line .btn { width: 100%; min-width: 0; justify-content: center; }
.calendar-date-line .nav-today-btn { border-color: #D24726 !important; background: linear-gradient(180deg, #D24726 0%, #B83B1F 100%) !important; color: #fff !important; box-shadow: 0 10px 22px rgba(210, 71, 38, .22) !important; }
.calendar-date-line .nav-today-btn:hover { background: #B83B1F !important; color: #fff !important; transform: translateY(-1px); }
.calendar-date-form { min-width: 0; }
.calendar-date-form .date-input { width: 100%; min-width: 0 !important; height: 42px; padding: 0 12px; font-size: 13px; font-weight: 750; text-align: center; border-radius: 12px; box-sizing: border-box; border: 1px solid #1160B7 !important; background: linear-gradient(180deg, #1160B7 0%, #0D4F98 100%) !important; color: #fff !important; box-shadow: 0 10px 22px rgba(17, 96, 183, .22); color-scheme: dark; }
.calendar-date-form .date-input::-webkit-calendar-picker-indicator { filter: brightness(0) invert(1); opacity: .95; cursor: pointer; }
.calendar-date-form .date-input:focus { outline: 3px solid rgba(177, 214, 240, .65) !important; outline-offset: 2px; }
.calendar-filter-line { width: 100%; min-width: 0; display: grid; grid-template-columns: 118px minmax(120px, 1fr); gap: 8px; align-items: center; }
.calendar-filter-line .select { width: 100%; min-width: 0; height: 42px; font-weight: 650; text-align: center; padding-left: 8px; padding-right: 8px; }
.calendar-filter-line .view-select {
    border: 1.5px solid rgba(29, 110, 193, .45) !important;
    box-shadow: 0 10px 24px rgba(29, 110, 193, .08), inset 0 1px 0 rgba(255,255,255,.86) !important;
}
.calendar-filter-line .view-select:focus {
    border-color: rgba(29, 110, 193, .75) !important;
    box-shadow: 0 0 0 3px rgba(29, 110, 193, .16), 0 10px 24px rgba(29, 110, 193, .10) !important;
    outline: none !important;
}
.calendar-action-line { min-width: 0; display: flex; justify-content: flex-end; }
.calendar-action-line .btn { min-width: 168px; }
.team-greeting { padding: 16px 20px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); }
.team-greeting-title { font-size: 18px; font-weight: 650; color: var(--text); }
.team-greeting-text { font-size: 14px; color: var(--muted); margin-top: 2px; }
.team-greeting-count { background: var(--accent); color: #fff; border-radius: 999px; padding: 8px 14px; font-weight: 650; font-size: 14px; }
.day-badge, .calendar-mobile-header { display: none !important; }
.day-header { display: none !important; margin: 0 !important; }
.day-text h1 { font-size: 22px; font-weight: 650; color: var(--text); letter-spacing: -.015em; margin: 0; }
.day-text p { font-size: 13px; color: var(--muted); margin: 3px 0 0; }
.schedule-scroll { width: 100%; max-width: 100%; overflow: auto; -webkit-overflow-scrolling: touch; max-height: calc(100vh - 155px); border: 1px solid rgba(0,32,80,.28); border-radius: var(--radius-lg); background: var(--surface); box-shadow: 0 16px 34px rgba(0,32,80,.06); }
.schedule-grid { width: max(100%, <?= (int)$desktopGridWidth ?>px); min-width: max(100%, <?= (int)$desktopGridWidth ?>px); display: grid; grid-template-columns: 76px repeat(<?= (int)$teamCount ?>, minmax(<?= (int)$desktopMinTeamWidth ?>px, 1fr)); grid-template-rows: 68px repeat(<?= count($slots) ?>, 34px); position: relative; }
.time-head { position: sticky; top: 0; left: 0; z-index: 25; background: rgba(255,255,255,.92); border-bottom: 1px solid rgba(0,32,80,.34); border-top-left-radius: var(--radius-lg); backdrop-filter: blur(14px); }
.team-head { position: sticky; top: 0; z-index: 20; background: rgba(255,255,255,.92); border-bottom: 1px solid rgba(0,32,80,.34); border-left: 1px solid rgba(0,32,80,.24); padding: 7px 8px; min-width: 0; backdrop-filter: blur(14px); }
.team-head-card { position: relative; width: 100%; height: 100%; display: flex; align-items: center; gap: 12px; padding: 7px 14px; overflow: hidden; border-radius: 14px; border: 1px solid color-mix(in srgb, var(--team-color) 28%, rgba(0,32,80,.16)); background: linear-gradient(135deg, color-mix(in srgb, var(--team-color) 16%, #ffffff) 0%, rgba(255,255,255,.90) 56%, color-mix(in srgb, var(--team-color) 20%, #DFE2E8) 100%); box-shadow: 0 10px 22px rgba(0,32,80,.08), inset 0 1px 0 rgba(255,255,255,.88); }
.team-head-card::after { content: ''; position: absolute; right: -22px; bottom: -24px; width: 138px; height: 58px; border-radius: 999px; background: color-mix(in srgb, var(--team-color) 16%, transparent); transform: rotate(-12deg); pointer-events: none; }
.team-dot { width: 38px; height: 38px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 13px; font-weight: 900; letter-spacing: .02em; flex-shrink: 0; background: var(--team-color); box-shadow: 0 10px 20px color-mix(in srgb, var(--team-color) 34%, transparent), inset 0 1px 0 rgba(255,255,255,.35); position: relative; z-index: 1; }
.team-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #002050; font-size: 16px; font-weight: 850; letter-spacing: -.01em; position: relative; z-index: 1; }
.time-cell { position: sticky; left: 0; z-index: 10; background: var(--surface); border-bottom: 1px solid rgba(0,32,80,.24); border-right: 1px solid rgba(0,32,80,.30); padding: 0 10px; color: var(--muted); font-weight: 500; font-size: 11px; font-family: var(--mono); display: flex; align-items: center; justify-content: flex-end; }
.time-cell.hour { color: var(--text); font-size: 12px; font-weight: 650; }
.slot-cell { border-bottom: 1px solid rgba(0,32,80,.20); border-left: 1px solid rgba(0,32,80,.24); background: #fff; transition: background .1s; min-width: 0; }
.slot-cell.work-hours { background: #fff; border-bottom-color: rgba(0,32,80,.20); border-left-color: rgba(0,32,80,.24); }
.slot-cell.off-hours { background: #DFE2E8; border-bottom-color: rgba(0,32,80,.28); border-left-color: rgba(0,32,80,.30); box-shadow: inset 0 1px 0 rgba(255,255,255,.38); }
.time-cell.work-hours { background: #fff; border-bottom-color: rgba(0,32,80,.20); border-right-color: rgba(0,32,80,.30); }
.time-cell.off-hours { background: #DFE2E8; color: #002050; border-bottom-color: rgba(0,32,80,.28); border-right-color: rgba(0,32,80,.34); }
.slot-cell:hover { background: var(--accent-soft); }
.slot-cell.off-hours:hover { background: #D4D9E2; }
.slot-cell.hour-line, .time-cell.hour-line { border-top: 1px solid rgba(0,32,80,.34) !important; }
.slot-cell.hour-line { box-shadow: inset 0 1px 0 rgba(0,32,80,.06); }
.slot-cell.drag-over { background: rgba(14, 116, 144, .16); outline: 2px dashed var(--accent); outline-offset: -3px; }
.event { position: relative; z-index: 15; margin: 3px 5px; border-radius: 10px; padding: 6px 8px; color: #fff; overflow: hidden; cursor: pointer; font-size: 12px; line-height: 1.3; border: none; box-shadow: 0 8px 20px rgba(16, 32, 21, .18); transition: transform .1s, box-shadow .1s, opacity .1s; min-width: 0; }
.event[draggable="true"] { cursor: grab; }
.event.dragging { opacity: .55; cursor: grabbing; }
.event:hover { transform: translateY(-1px); box-shadow: 0 12px 28px rgba(16, 32, 21, .24); }
.event strong { display: block; font-size: 12px; font-weight: 650; margin-bottom: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.event small { display: block; opacity: .94; margin-bottom: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.event.done { color: #002050 !important; border: 1px solid rgba(0,32,80,.18) !important; box-shadow: inset 0 1px 0 rgba(255,255,255,.75), 0 6px 14px rgba(0,32,80,.08) !important; padding-right: 72px !important; }
.event.done strong, .event.done small { color: #002050 !important; opacity: 1 !important; }
.event-done-badge { position:absolute; top:5px; right:6px; display:inline-flex; align-items:center; justify-content:center; width:auto; max-width:64px; height:16px; padding:0 6px; border-radius:999px; background:rgba(255,255,255,.82); border:1px solid rgba(0,32,80,.14); color:#002050; font-size:8px; font-weight:800; line-height:1; letter-spacing:.01em; text-transform:uppercase; white-space:nowrap; box-shadow:0 4px 10px rgba(0,32,80,.08); pointer-events:none; }
.fc-event-finalizata { position: relative !important; box-shadow: inset 0 1px 0 rgba(255,255,255,.75), 0 6px 14px rgba(0,32,80,.08) !important; padding-right: 66px !important; }
.fc-event-finalizata .event-done-badge { top:3px; right:4px; height:14px; max-width:58px; font-size:7px; padding:0 5px; }
@media (max-width: 720px) {
    .event.done { padding-right: 28px !important; }
    .event-done-badge { width:16px; max-width:16px; height:16px; padding:0; font-size:0; }
    .event-done-badge::before { content:'✓'; font-size:10px; font-weight:900; line-height:1; }
}
.fc-card { padding: 16px; overflow: auto; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); }
.fc { font-family: var(--font) !important; }
.fc .fc-toolbar-title { font-size: 18px !important; font-weight: 650 !important; }
.fc-event { border-radius: 8px !important; font-size: 12px !important; font-weight: 550 !important; padding: 2px 4px !important; cursor: pointer !important; }
.fc-event-month-box { min-height: 14px !important; height: 14px !important; padding: 0 !important; border-width: 1px !important; border-style: solid !important; border-radius: 8px !important; overflow: hidden !important; box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 2px 6px rgba(0,32,80,.10) !important; }
.fc-event-month-box .fc-event-main { min-height: 12px !important; height: 12px !important; padding: 0 !important; }
.fc-event-month-box.fc-event-finalizata { padding-right: 0 !important; }
.fc-event-month-box .event-done-badge { display: none !important; }
.fc-event-month-box .fc-event-main-frame { min-height: 12px !important; }
.readonly-box { background: var(--surface-soft, #F8FAFC); border: 1px solid var(--border); border-radius: 14px; padding: 12px 14px; margin-bottom: 14px; color: var(--text); font-size: 14px; line-height: 1.5; }
.office-note-box { background: #F3F6FA; border: 1px solid var(--border2); border-radius: 14px; padding: 12px 14px; margin-bottom: 14px; color: var(--muted); font-size: 14px; line-height: 1.5; }
.office-note-box strong { display:block; color: var(--text); margin-bottom: 4px; }
.location-hint { margin-top: 5px; color: var(--muted); font-size: 12px; font-weight: 450; }
.support-teams-box { margin-top: 0; }
.support-teams-list { display: grid; gap: 7px; margin-bottom: 7px; }
.support-teams-list:empty { display: none; margin: 0; }
.support-team-row { display: grid; grid-template-columns: minmax(0, 1fr) 38px; gap: 8px; align-items: center; }
.support-team-select { width: 100%; min-height: 38px; height: 38px; border-radius: 12px; }
.support-add-btn, .support-remove-btn {
    height: 38px;
    min-height: 38px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: #fff;
    color: var(--text);
    font-size: 12px;
    font-weight: 650;
    cursor: pointer;
    box-shadow: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}
.support-add-btn { width: 100%; padding: 0 12px; }
.support-add-btn:hover { border-color: var(--accent); background: #F8FAFC; }
.support-remove-btn { color: #B42318; border-color: rgba(244,63,94,.30); padding: 0; }
.support-remove-btn:hover { background: #FFF1F2; }
@media(min-width: 861px) and (max-width: 1350px) {
    body.calendar-page .calendar-topbar { padding: 12px 18px !important; overflow-x: hidden !important; }
    .calendar-toolbar { width: 100% !important; max-width: 100% !important; display: grid !important; grid-template-columns: 1fr !important; gap: 10px !important; align-items: stretch !important; }
    .calendar-date-line { width: 100% !important; display: grid !important; grid-template-columns: 84px 54px 54px minmax(180px, 1fr) !important; gap: 10px !important; }
    .calendar-filter-line { width: 100% !important; display: grid !important; grid-template-columns: 130px minmax(0, 1fr) !important; gap: 10px !important; }
    .calendar-action-line { width: 100% !important; margin: 0 !important; display: block !important; }
    .calendar-action-line .btn { width: 100% !important; min-width: 0 !important; max-width: 100% !important; }
    .calendar-date-line .btn, .calendar-date-form, .calendar-date-form .date-input, .calendar-filter-line .select { width: 100% !important; min-width: 0 !important; max-width: 100% !important; }
}
@media(min-width: 861px) and (max-width: 1180px) {
    .schedule-scroll { overflow-x: auto !important; overflow-y: auto !important; }
    .schedule-grid { width: max(100%, <?= (int)$tabletGridWidth ?>px) !important; min-width: max(100%, <?= (int)$tabletGridWidth ?>px) !important; max-width: none !important; grid-template-columns: 54px repeat(<?= (int)$teamCount ?>, minmax(<?= (int)$tabletMinTeamWidth ?>px, 1fr)) !important; grid-template-rows: 60px repeat(<?= count($slots) ?>, 32px) !important; }
    .team-head { padding: 0 8px; font-size: 12px; }
    .time-cell { padding: 0 6px; font-size: 10px; }
}
@media(max-width: 860px) {
    body.calendar-page { overflow-x: hidden !important; }
    body.calendar-page .calendar-topbar { padding: 8px 10px 12px 10px !important; overflow-x: hidden !important; display: block !important; position: relative !important; top: auto !important; }
    .calendar-toolbar { width: 100% !important; max-width: 100% !important; min-width: 0 !important; display: grid !important; grid-template-columns: 1fr !important; gap: 7px !important; align-items: stretch !important; justify-items: stretch !important; }
    .calendar-date-line { width: 100% !important; max-width: 100% !important; min-width: 0 !important; display: grid !important; grid-template-columns: 44px 38px 38px minmax(0, 1fr) !important; gap: 6px !important; margin: 0 !important; }
    .calendar-date-line .btn { width: 100% !important; min-width: 0 !important; max-width: 100% !important; height: 40px !important; padding: 0 5px !important; font-size: 12px !important; border-radius: 12px !important; }
    .calendar-date-form, .calendar-date-form .date-input { width: 100% !important; min-width: 0 !important; max-width: 100% !important; }
    .calendar-date-form .date-input { height: 40px !important; padding-left: 4px !important; padding-right: 4px !important; font-size: 12px !important; text-align: center !important; }
    .calendar-filter-line { width: 100% !important; max-width: 100% !important; min-width: 0 !important; display: grid !important; grid-template-columns: 86px minmax(0, 1fr) !important; gap: 7px !important; margin: 0 !important; }
    .calendar-filter-line .select { width: 100% !important; max-width: 100% !important; min-width: 0 !important; height: 40px !important; padding-left: 7px !important; padding-right: 7px !important; font-size: 12px !important; border-radius: 12px !important; text-align: center !important; }
    .calendar-action-line { width: 100% !important; max-width: 100% !important; min-width: 0 !important; margin: 0 !important; }
    .calendar-action-line .btn { width: 100% !important; max-width: 100% !important; min-width: 0 !important; height: 42px !important; padding-left: 8px !important; padding-right: 8px !important; font-size: 13px !important; border-radius: 13px !important; justify-content: center !important; text-align: center !important; white-space: normal !important; }
    .content { width: 100% !important; max-width: 100vw !important; overflow-x: hidden !important; }
    .team-greeting { padding: 14px; margin-bottom: 12px; }
    .schedule-scroll { width: 100% !important; max-width: 100% !important; overflow-x: auto !important; overflow-y: auto !important; -webkit-overflow-scrolling: touch !important; overscroll-behavior-x: contain; max-height: none !important; border-radius: 16px !important; }
    .day-header { margin-bottom: 12px; }
    .day-text p { display: none; }
    .day-text h1 { font-size: 20px; line-height: 1.15; }
    .schedule-grid { width: max(100%, <?= (int)$mobileGridWidth ?>px) !important; min-width: max(100%, <?= (int)$mobileGridWidth ?>px) !important; max-width: none !important; grid-template-columns: 42px repeat(<?= (int)$teamCount ?>, minmax(<?= (int)$mobileMinTeamWidth ?>px, 1fr)) !important; grid-template-rows: 54px repeat(<?= count($slots) ?>, 24px) !important; }
    .time-head { width: 42px; }
    .time-cell { padding: 0 4px; font-size: 9px; justify-content: center; }
    .time-cell.hour { font-size: 10px; }
    .team-head { min-width: <?= (int)$mobileMinTeamWidth ?>px !important; max-width: none !important; padding: 0 !important; justify-content: center; font-size: 0; gap: 0; }
    .team-dot { width: 24px; height: 24px; font-size: 10px; margin: 0 auto; }
    .slot-cell { min-width: <?= (int)$mobileMinTeamWidth ?>px !important; max-width: none !important; }
    .event { margin: 2px; padding: 0; border-radius: 8px; min-height: 30px; box-shadow: none; max-width: 100% !important; }
    .event strong, .event small { display: none !important; }
    .event:hover { transform: none; box-shadow: none; }
}
@media(max-width: 520px) {
    .calendar-date-line { grid-template-columns: 40px 34px 34px minmax(0, 1fr) !important; gap: 5px !important; }
    .calendar-date-line .btn { height: 38px !important; font-size: 11px !important; padding-left: 3px !important; padding-right: 3px !important; }
    .calendar-date-form .date-input { height: 38px !important; font-size: 11px !important; }
    .calendar-filter-line { grid-template-columns: 78px minmax(0, 1fr) !important; gap: 6px !important; }
    .calendar-filter-line .select { height: 38px !important; font-size: 11px !important; }
    .calendar-action-line .btn { height: 40px !important; font-size: 12px !important; }
    .schedule-grid { width: max(100%, <?= (int)$smallMobileGridWidth ?>px) !important; min-width: max(100%, <?= (int)$smallMobileGridWidth ?>px) !important; grid-template-columns: 40px repeat(<?= (int)$teamCount ?>, minmax(<?= (int)$smallMobileMinTeamWidth ?>px, 1fr)) !important; grid-template-rows: 52px repeat(<?= count($slots) ?>, 22px) !important; }
    .time-cell { font-size: 8px; }
    .time-cell.hour { font-size: 9px; }
    .team-head { min-width: <?= (int)$smallMobileMinTeamWidth ?>px !important; max-width: none !important; }
    .team-dot { width: 22px; height: 22px; font-size: 9px; }
    .slot-cell { min-width: <?= (int)$smallMobileMinTeamWidth ?>px !important; max-width: none !important; }
    .event { margin: 2px; border-radius: 7px; min-height: 28px; }
}


/* Modernizare vizuala - font mai fin, aliniat cu app_ui.php */

@media (max-width: 760px) {
    .team-head { padding: 5px 5px !important; }
    .team-head-card { padding: 5px 8px !important; gap: 7px !important; border-radius: 12px !important; }
    .team-dot { width: 30px !important; height: 30px !important; font-size: 10px !important; }
    .team-name { font-size: 13px !important; }
}

.calendar-page,
.calendar-page input,
.calendar-page select,
.calendar-page textarea,
.calendar-page button,
.calendar-page .btn {
    font-family: var(--font, Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif) !important;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
.calendar-page .btn.pv-aero-btn {
    background: rgba(255,255,255,.82) !important;
    color: #123a7a !important;
    border: 1.5px solid rgba(59,130,246,.32) !important;
    border-radius: 999px !important;
    box-shadow: 0 10px 24px rgba(37, 99, 235, .10), inset 0 1px 0 rgba(255,255,255,.85) !important;
}
.calendar-page .btn.pv-aero-btn:hover {
    background: rgba(239,246,255,.96) !important;
    border-color: rgba(37,99,235,.50) !important;
    box-shadow: 0 14px 30px rgba(37, 99, 235, .14), inset 0 1px 0 rgba(255,255,255,.92) !important;
}
.calendar-page .btn.pv-aero-btn:focus-visible {
    outline: 2px solid rgba(59,130,246,.30) !important;
    outline-offset: 2px !important;
}
.calendar-date-form .date-input,
.calendar-filter-line .select {
    font-weight: 500 !important;
    letter-spacing: -0.01em;
}
.team-greeting-title,
.day-text h1,
.team-head,
.event strong,
.fc .fc-toolbar-title {
    font-weight: 650 !important;
}
.team-greeting-count,
.team-dot,
.time-cell.hour,
.location-hint,
.fc-event {
    font-weight: 550 !important;
}
.time-cell,
.team-greeting-text,
.day-text p,
.event small,
.readonly-box,
.office-note-box {
    font-weight: 400 !important;
}
.team-greeting,
.schedule-scroll,
.fc-card,
.readonly-box,
.office-note-box {
    box-shadow: var(--shadow-sm, 0 1px 2px rgba(15, 23, 42, .04));
}
.event {
    box-shadow: 0 6px 16px rgba(16, 32, 21, .14);
}
.event:hover {
    box-shadow: 0 10px 24px rgba(16, 32, 21, .18);
}


/* Compactare fisa editare programare - modul birou */
.calendar-page #editModal .office-edit-modal-box { max-width: 760px; }
.calendar-page #editModal .office-edit-modal-box .modal-header { padding-bottom: 10px; margin-bottom: 10px; }
.calendar-page #editModal .office-edit-modal-box .form-grid { gap: 10px 12px; }
.calendar-page #editModal .office-edit-modal-box label { margin-bottom: 5px; font-size: 10px; letter-spacing: .045em; }
.calendar-page #editModal .office-edit-modal-box input,
.calendar-page #editModal .office-edit-modal-box select { min-height: 38px; height: 38px; padding-top: 7px; padding-bottom: 7px; }
.calendar-page #editModal .office-edit-modal-box textarea { min-height: 70px; }
.calendar-page #editModal .office-edit-modal-box .pz-autocomplete-selected { padding-top: 7px; padding-bottom: 7px; min-height: 38px; }
.calendar-page #editModal .office-edit-modal-box .location-hint { display: none; }
.calendar-page #editModal .office-edit-modal-box .actions-row { margin-top: 10px; gap: 10px; }
.calendar-page #editModal .office-edit-modal-box .actions-left,
.calendar-page #editModal .office-edit-modal-box .actions-right { gap: 8px; }
.calendar-page #editModal .office-edit-modal-box .edit-actions-row { display: flex; align-items: center; justify-content: flex-start; }
.calendar-page #editModal .office-edit-modal-box .edit-actions-line { display: flex; align-items: center; justify-content: flex-start; gap: 8px; flex-wrap: nowrap; width: 100%; }
.calendar-page #editModal .office-edit-modal-box .btn { min-height: 38px; padding-top: 0; padding-bottom: 0; white-space: nowrap; }
.calendar-page #editModal .office-edit-modal-box .edit-actions-line .btn { padding-left: 15px; padding-right: 15px; }
.calendar-page #editModal .btn:disabled { opacity: .48; cursor: not-allowed; box-shadow: none !important; }
.calendar-page #editModal .office-edit-modal-box .billing-inline-box { grid-column: auto / span 1; }
.calendar-page #editModal .office-edit-modal-box .billing-inline-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: center; }
.calendar-page #editModal .office-edit-modal-box .billing-inline-row input[type="number"] { width: 100%; }
.calendar-page #editModal .office-edit-modal-box .billing-inline-check { min-height: 38px; height: 38px; padding: 8px 10px; margin: 0; white-space: nowrap; }
.calendar-page #editModal .office-edit-modal-box #edit_billing_note { margin-top: 8px; width: 100%; }
@media(max-width: 920px) {
    .calendar-page #editModal .office-edit-modal-box .edit-actions-line { flex-wrap: wrap; }
}
@media(max-width: 760px) {
    .calendar-page #editModal .office-edit-modal-box { width: calc(100vw - 24px); }
    .calendar-page #editModal .office-edit-modal-box .form-grid { gap: 9px; }
    .calendar-page #editModal .office-edit-modal-box .actions-row,
    .calendar-page #editModal .office-edit-modal-box .actions-left,
    .calendar-page #editModal .office-edit-modal-box .actions-right { display: grid; grid-template-columns: 1fr; width: 100%; }
    .calendar-page #editModal .office-edit-modal-box .edit-actions-line { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .calendar-page #editModal .office-edit-modal-box .btn { width: 100%; justify-content: center; }
    .calendar-page #editModal .office-edit-modal-box .billing-inline-row { grid-template-columns: 1fr; }
    .calendar-page #editModal .office-edit-modal-box .billing-inline-check { justify-content: center; }
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

.billing-toggle-box {
    display: flex;
    flex-direction: column;
    gap: 8px;
    justify-content: flex-end;
}
.mini-check {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    padding: 10px 12px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: #fff;
    font-size: 12px;
    font-weight: 800;
    color: var(--ink);
    text-transform: none;
    letter-spacing: 0;
}
.mini-check input {
    width: 16px;
    height: 16px;
}

</style>
</head>

<body class="calendar-page">
<div class="layout">

    <?php render_sidebar('calendar', $isAdmin); ?>

    <main class="main">

        <div class="topbar calendar-topbar">
            <div class="calendar-toolbar">

                <div class="calendar-line calendar-date-line">
                    <a class="btn nav-today-btn" href="calendar.php?date=<?= urlencode(date('Y-m-d')) ?>&view=day&team=<?= urlencode($selectedTeam) ?>">Azi</a>
                    <a class="btn nav-arrow" href="calendar.php?date=<?= urlencode($prevDate) ?>&view=<?= urlencode($view) ?>&team=<?= urlencode($selectedTeam) ?>">&lsaquo;</a>
                    <a class="btn nav-arrow" href="calendar.php?date=<?= urlencode($nextDate) ?>&view=<?= urlencode($view) ?>&team=<?= urlencode($selectedTeam) ?>">&rsaquo;</a>

                    <form method="get" class="calendar-date-form">
                        <input type="hidden" name="view" value="<?= hcal($view) ?>">
                        <?php if ($isAdmin): ?><input type="hidden" name="team" value="<?= hcal($selectedTeam) ?>"><?php endif; ?>
                        <input class="date-input" type="date" name="date" value="<?= hcal($currentDate) ?>" onchange="this.form.submit()">
                    </form>
                </div>

                <form method="get" id="filterForm" class="calendar-line calendar-filter-line">
                    <input type="hidden" name="date" value="<?= hcal($currentDate) ?>">
                    <select class="select view-select" name="view" onchange="this.form.submit()">
                        <?php foreach ($viewLabels as $v => $label): ?>
                            <option value="<?= hcal($v) ?>" <?= $view === $v ? 'selected' : '' ?>><?= hcal($label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($isAdmin): ?>
                        <select class="select" name="team" onchange="this.form.submit()">
                            <option value="all" <?= $selectedTeam === 'all' ? 'selected' : '' ?>>Toate echipele</option>
                            <?php foreach ($allTeams as $team): ?>
                                <option value="<?= (int)$team['id'] ?>" <?= (string)$selectedTeam === (string)$team['id'] ? 'selected' : '' ?>><?= hcal($team['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </form>

                <?php if ($isAdmin): ?>
                    <div class="calendar-action-line">
                        <button class="btn accent" type="button" onclick="openCreateModal('<?= hcal($currentDate) ?>', '09:00', '<?= (int)$defaultTeamId ?>')">+ Programare noua</button>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <?php if (isset($_GET['success'])): ?><div class="notice notice-success">Programarea a fost adaugata cu succes.</div><?php endif; ?>
        <?php if (isset($_GET['sms_sent'])): ?><div class="notice notice-success">SMS-ul de confirmare a fost trimis.</div><?php endif; ?>
        <?php if (isset($_GET['sms_skipped'])): ?><div class="notice notice-warning">SMS-ul nu a fost trimis deoarece clientul are notificarile SMS oprite.</div><?php endif; ?>
        <?php if (isset($_GET['sms_error'])): ?><div class="notice notice-warning">Programarea a fost salvata, dar SMS-ul nu a putut fi trimis. Verifica logurile din Comunicare.</div><?php endif; ?>
        <?php if (isset($_GET['drag_time_changed'])): ?><div class="notice notice-success">Programarea a fost mutata. SMS-ul nu se mai trimite automat la modificari; il poti trimite manual din fisa programarii.</div><?php endif; ?>
        <?php if (isset($_GET['drag_no_sms'])): ?><div class="notice notice-success">Programarea a fost mutata. SMS netrimis automat.</div><?php endif; ?>
        <?php if (isset($_GET['updated_time_changed'])): ?><div class="notice notice-success">Programarea a fost actualizata. SMS-ul nu se mai trimite automat la modificari; il poti trimite manual din fisa programarii.</div><?php endif; ?>
        <?php if (isset($_GET['updated'])): ?><div class="notice notice-success">Programarea a fost actualizata.</div><?php endif; ?>
        <?php if (isset($_GET['finished'])): ?><div class="notice notice-success">Lucrarea a fost finalizata. Acum poti emite PV, daca este necesar.</div><?php endif; ?>
        <?php if (isset($_GET['pv_email_sent'])): ?><div class="notice notice-success">Emailul cu PV a fost trimis.</div><?php endif; ?>
        <?php if (isset($_GET['pv_email_error'])): ?><div class="notice notice-warning">Emailul cu PV nu a putut fi trimis.</div><?php endif; ?>
        <?php if (isset($_GET['finish_error'])): ?><div class="notice notice-warning">Pentru finalizarea lucrarii trebuie sa completezi mentiunile de finalizare.</div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="notice notice-warning">Programarea a fost stearsa definitiv.</div><?php endif; ?>
        <?php if (isset($_GET['conflict'])): ?><div class="notice notice-warning">Programarea nu a fost salvata. <?= hcal((string)($_GET['conflict_msg'] ?? 'Un operator este deja alocat in intervalul selectat.')) ?></div><?php endif; ?>
        <?php if (isset($_GET['error'])): ?><div class="notice notice-warning">Actiunea nu a putut fi procesata. Verifica datele introduse.</div><?php endif; ?>

        <div class="content">

            <?php if ($isTeamUser): ?>
                <div class="team-greeting">
                    <div>
                        <div class="team-greeting-title">Salutare, <?= hcal($teamGreetingName) ?>!</div>
                        <div class="team-greeting-text">Astazi ai programate <?= (int)$teamTodayJobs ?> <?= (int)$teamTodayJobs === 1 ? 'lucrare' : 'lucrari' ?>.</div>
                    </div>
                    <div class="team-greeting-count"><?= (int)$teamTodayJobs ?> azi</div>
                </div>
            <?php endif; ?>

            <div class="day-header">
                <div class="day-text">
                    <h1><?= hcal($prettyDate) ?></h1>
                    <?php if ($view === 'day'): ?>
                        <p><?= $isAdmin ? 'Calendar zi - echipe pe coloane' : 'Programarile tale de astazi' ?></p>
                    <?php else: ?>
                        <p>Vizualizare <?= hcal($viewLabels[$view]) ?>: <?= hcal(ro_date_label($rangeStart)) ?> - <?= hcal(ro_date_label($rangeEnd)) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($view === 'day'): ?>
                <div class="schedule-scroll">
                    <div class="schedule-grid">
                        <div class="time-head" style="grid-column:1;grid-row:1;"></div>

                        <?php foreach ($teams as $i => $team): ?>
                            <?php $teamColor = calendar_clean_hex_color($team['color'] ?? null); ?>
                            <div class="team-head" style="grid-column:<?= $i + 2 ?>;grid-row:1;--team-color:<?= hcal($teamColor) ?>;">
                                <div class="team-head-card">
                                    <span class="team-dot"><?= hcal(calendar_initials($team['name'])) ?></span>
                                    <span class="team-name"><?= hcal($team['name']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach ($slots as $slotIndex => $slot): ?>
                            <?php $row = $slotIndex + 2; $isHour = substr($slot, -3) === ':00'; $isOffHours = ($slot < '08:00' || $slot >= '17:00'); $slotToneClass = $isOffHours ? ' off-hours' : ' work-hours'; ?>
                            <div class="time-cell<?= $isHour ? ' hour hour-line' : '' ?><?= $slotToneClass ?>" style="grid-column:1;grid-row:<?= $row ?>;"><?= $isHour ? hcal($slot) : '' ?></div>
                            <?php foreach ($teams as $i => $team): ?>
                                <div class="slot-cell<?= $isHour ? ' hour-line' : '' ?><?= $slotToneClass ?>" style="grid-column:<?= $i + 2 ?>;grid-row:<?= $row ?>;" data-drop-date="<?= hcal($currentDate) ?>" data-drop-time="<?= hcal($slot) ?>" data-drop-team="<?= (int)$team['id'] ?>" <?php if ($isAdmin): ?>onclick="openCreateModal('<?= hcal($currentDate) ?>', '<?= hcal($slot) ?>', '<?= (int)$team['id'] ?>')" ondragover="handleSlotDragOver(event)" ondragleave="handleSlotDragLeave(event)" ondrop="handleSlotDrop(event)"<?php endif; ?>></div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>

                        <?php foreach ($appointments as $appt): ?>
                            <?php
                                $teamId = (int)($appt['display_team_member_id'] ?? $appt['team_member_id'] ?? 0);
                                if (!isset($teamColumn[$teamId])) { continue; }
                                $apptStartTime = $appt['start_time'] ?: '09:00:00';
                                $apptEndTime = $appt['end_time'] ?: null;
                                $gridColumn = $teamColumn[$teamId];
                                $gridRow = slot_index($apptStartTime, $startHour) + 1;
                                $span = duration_span($apptStartTime, $apptEndTime);
                                $teamColor = calendar_clean_hex_color($appt['display_team_color'] ?? $appt['team_color'] ?? null);
                                $color = $teamColor;
                                $borderColor = $teamColor;
                                $eventTextColor = '#ffffff';
                                $isFinalizedEvent = (($appt['status'] ?? '') === 'finalizata');
                                if ($isFinalizedEvent) {
                                    $color = calendar_lighten_hex($teamColor, 0.84);
                                    $borderColor = calendar_lighten_hex($teamColor, 0.45);
                                    $eventTextColor = '#002050';
                                } elseif (($appt['status'] ?? '') === 'in_lucru') {
                                    $color = '#64748B';
                                    $borderColor = '#64748B';
                                }
                                $cls = 'event' . ($isFinalizedEvent ? ' done' : '');
                                $isPrimaryAssignment = ((int)($appt['appointment_team_is_primary'] ?? 1) === 1);
                                $eventTitle = ($appt['client_name'] ?: 'Client') . ' - ' . substr($apptStartTime, 0, 5) . ' - ' . ($appt['service_type'] ?: 'Lucrare');
                            ?>
                            <div class="<?= hcal($cls) ?>" style="grid-column:<?= $gridColumn ?>;grid-row:<?= $gridRow ?>/span <?= $span ?>;background:<?= hcal($color) ?>;border-color:<?= hcal($borderColor) ?>;color:<?= hcal($eventTextColor) ?>;" <?php if ($isAdmin && !$isFinalizedEvent && $isPrimaryAssignment): ?>draggable="true" data-appointment-id="<?= (int)$appt['id'] ?>" ondragstart="handleAppointmentDragStart(event)" ondragend="handleAppointmentDragEnd(event)"<?php endif; ?> onclick="event.stopPropagation(); if (!window.pzAppointmentWasDragged) loadAppointment(<?= (int)$appt['id'] ?>)" title="<?= hcal($eventTitle) ?>">
                                <strong><?= hcal($appt['client_name'] ?: 'Client') ?></strong>
                                <small><?= hcal($appt['service_type'] ?: 'Lucrare') ?></small>
                                <?php if ($isFinalizedEvent): ?><span class="event-done-badge">Finalizata</span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="fc-card"><div id="visualCalendar"></div></div>
            <?php endif; ?>

        </div>
    </main>
</div>

<?php if ($isAdmin): ?>
<div class="modal" id="createModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Programare noua</h2>
            <button class="modal-close" type="button" onclick="closeModal('createModal')">&times;</button>
        </div>

        <form method="post" id="createForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="task_id" id="create_task_id" value="<?= (int)$prefillTaskId ?>">
            <input type="hidden" name="contract_id" id="create_contract_id" value="">
            <input type="hidden" name="contract_service_id" id="create_contract_service_id" value="">
            <input type="hidden" name="service_id" id="create_service_id" value="">
            <input type="hidden" name="surface_value" id="create_surface_value" value="">
            <input type="hidden" name="surface_unit" id="create_surface_unit" value="">
            <input type="hidden" name="currency" id="create_currency" value="RON">
            <input type="hidden" name="document_id" id="create_document_id" value="">
            <input type="hidden" name="document_item_id" id="create_document_item_id" value="">
            <input type="hidden" name="redirect_date" value="<?= hcal($currentDate) ?>">
            <input type="hidden" name="redirect_view" value="<?= hcal($view) ?>">
            <input type="hidden" name="redirect_team" value="<?= hcal($selectedTeam) ?>">

            <div class="notice notice-warning appointment-form-error" id="create_availability_error" style="display:none;"></div>

            <div class="form-grid">
                <div class="form-group full">
                    <label>Client *</label>
                    <input type="hidden" name="client_id" id="create_existing_client_id" required value="">
                    <div class="pz-autocomplete" id="create_clientAutocomplete" data-prefix="create" data-onchange="handleClientChange">
                        <input type="text" class="pz-autocomplete-input" id="create_clientSearchInput" placeholder="Cauta dupa nume, CUI, telefon, reprezentant…" autocomplete="off" autofocus>
                        <div class="pz-autocomplete-selected" id="create_clientSelectedBox">
                            <div>
                                <div class="ps-name"></div>
                                <div class="ps-meta"></div>
                            </div>
                            <button type="button" class="ps-clear" onclick="pzClientClearAuto('create')" title="Schimba clientul">&times;</button>
                        </div>
                        <div class="pz-autocomplete-results" id="create_clientResults" role="listbox"></div>
                    </div>
                    <div class="location-hint">Clientii noi se adauga din modulul Clienti, pentru fisa completa.</div>
                </div>

                <div class="form-group full">
                    <label>Locatie</label>
                    <select name="client_location_id" id="create_client_location_id" onchange="handleLocationChange('create')" disabled>
                        <option value="">Alege mai intai clientul</option>
                    </select>
                    <div class="location-hint" id="create_location_hint"></div>
                </div>

                <div><label>Contact</label><input type="text" name="contact_person" id="create_contact_person" placeholder="Se preia automat din client / locatie"></div>
                <div><label>Telefon</label><input type="tel" name="contact_phone" id="create_contact_phone" placeholder="Telefon pentru echipa de teren"></div>
                <div><label>Adresa</label><input type="text" name="address" id="create_address" placeholder="Adresa completa / sediu / punct de lucru"></div>

                <div>
                    <label>Serviciu *</label>
                    <select name="service_type" id="create_service_type" required onchange="applyServiceDuration('create_service_type', 'create_duration'); applyContractServiceForSelection('create', false)">
                        <option value="">Alege lucrarea</option>
                        <?php foreach ($activeServices as $service): ?>
                            <option value="<?= hcal($service['name']) ?>" data-duration="<?= (int)$service['default_duration'] ?>"><?= hcal($service['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Tehnician *</label>
                    <select name="team_member_id" id="create_team_member_id" required>
                        <option value="">Alege tehnician</option>
                        <?php foreach ($allTeams as $team): ?>
                            <option value="<?= (int)$team['id'] ?>"><?= hcal($team['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="support-teams-box">
                    <label>Tehnician suplimentar</label>
                    <div id="create_support_teams" class="support-teams-list"></div>
                    <button class="support-add-btn" type="button" onclick="addSupportTeamRow('create')">+ Adauga tehnician</button>
                </div>

                <div><label>Data *</label><input type="date" name="appointment_date" id="create_date" required value="<?= hcal($currentDate) ?>"></div>
                <div>
                    <label>Ora *</label>
                    <select name="start_time" id="create_time" required>
                        <option value="">Alege ora</option>
                        <?php foreach ($appointmentTimeOptions as $timeOption): ?><option value="<?= hcal($timeOption) ?>"><?= hcal($timeOption) ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Durata</label>
                    <select name="duration" id="create_duration">
                        <?php foreach ($durationOptions as $minutes => $label): ?>
                            <option value="<?= (int)$minutes ?>" <?= (int)$minutes === 60 ? 'selected' : '' ?>><?= hcal($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Valoare fara TVA</label>
                    <input type="number" name="billing_amount" id="create_billing_amount" step="0.01" min="0" value="0.00" inputmode="decimal" placeholder="0.00">
                </div>

                <div class="billing-toggle-box">
                    <label class="mini-check"><input type="checkbox" name="not_invoiceable" id="create_not_invoiceable" value="1" onchange="toggleNotInvoiceable('create')"> Nu se factureaza</label>
                    <input type="text" name="billing_note" id="create_billing_note" placeholder="Motiv nefacturare" style="display:none;">
                </div>

                <div class="form-group full">
                    <label>Observatii pentru echipa</label>
                    <textarea name="notes" placeholder="Ex: Sunati clientul cu 30 minute inainte, acces prin spate, chei la receptie..."></textarea>
                </div>
            </div>

            <div class="actions-row"><div></div><div class="actions-right"><button class="btn" type="button" onclick="closeModal('createModal')">Renunta</button><button class="btn accent" type="submit">Salveaza programarea</button></div></div>
        </form>
    </div>
</div>

<div class="modal" id="editModal">
    <div class="modal-box office-edit-modal-box">
        <div class="modal-header">
            <h2>Editeaza programare</h2>
            <button class="modal-close" type="button" onclick="closeModal('editModal')">&times;</button>
        </div>

        <form method="post" id="editForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="appointment_id" id="edit_appointment_id">
            <input type="hidden" name="contract_id" id="edit_contract_id" value="">
            <input type="hidden" name="contract_service_id" id="edit_contract_service_id" value="">
            <input type="hidden" name="service_id" id="edit_service_id" value="">
            <input type="hidden" name="surface_value" id="edit_surface_value" value="">
            <input type="hidden" name="surface_unit" id="edit_surface_unit" value="">
            <input type="hidden" name="currency" id="edit_currency" value="RON">
            <input type="hidden" name="document_id" id="edit_document_id" value="">
            <input type="hidden" name="document_item_id" id="edit_document_item_id" value="">
            <input type="hidden" name="redirect_date" value="<?= hcal($currentDate) ?>">
            <input type="hidden" name="redirect_view" value="<?= hcal($view) ?>">
            <input type="hidden" name="redirect_team" value="<?= hcal($selectedTeam) ?>">

            <div class="notice notice-warning appointment-form-error" id="edit_availability_error" style="display:none;"></div>

            <div class="form-grid">
                <div class="form-group full">
                    <label>Client *</label>
                    <input type="hidden" name="client_id" id="edit_existing_client_id" required value="">
                    <div class="pz-autocomplete" id="edit_clientAutocomplete" data-prefix="edit" data-onchange="handleClientChange">
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

                <div class="form-group full">
                    <label>Locatie</label>
                    <select name="client_location_id" id="edit_client_location_id" onchange="handleLocationChange('edit')" disabled><option value="">Alege mai intai clientul</option></select>
                    <div class="location-hint" id="edit_location_hint"></div>
                </div>

                <div><label>Contact</label><input type="text" name="contact_person" id="edit_contact_person"></div>
                <div><label>Telefon</label><input type="tel" name="contact_phone" id="edit_contact_phone"></div>
                <div><label>Adresa</label><input type="text" name="address" id="edit_address"></div>

                <div>
                    <label>Serviciu *</label>
                    <select name="service_type" id="edit_service_type" required onchange="applyServiceDuration('edit_service_type', 'edit_duration'); applyContractServiceForSelection('edit', false)">
                        <option value="">Alege lucrarea</option>
                        <?php foreach ($activeServices as $service): ?>
                            <option value="<?= hcal($service['name']) ?>" data-duration="<?= (int)$service['default_duration'] ?>"><?= hcal($service['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Tehnician *</label>
                    <select name="team_member_id" id="edit_team_member_id" required>
                        <option value="">Alege tehnician</option>
                        <?php foreach ($allTeams as $team): ?><option value="<?= (int)$team['id'] ?>"><?= hcal($team['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="support-teams-box">
                    <label>Tehnician suplimentar</label>
                    <div id="edit_support_teams" class="support-teams-list"></div>
                    <button class="support-add-btn" type="button" onclick="addSupportTeamRow('edit')">+ Adauga tehnician</button>
                </div>

                <div><label>Data *</label><input type="date" name="appointment_date" id="edit_appointment_date" required></div>
                <div>
                    <label>Ora *</label>
                    <select name="start_time" id="edit_start_time" required>
                        <option value="">Alege ora</option>
                        <?php foreach ($appointmentTimeOptions as $timeOption): ?><option value="<?= hcal($timeOption) ?>"><?= hcal($timeOption) ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Durata</label>
                    <select name="duration" id="edit_duration">
                        <?php foreach ($durationOptions as $minutes => $label): ?><option value="<?= (int)$minutes ?>"><?= hcal($label) ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="billing-inline-box">
                    <label>Valoare fara TVA</label>
                    <div class="billing-inline-row">
                        <input type="number" name="billing_amount" id="edit_billing_amount" step="0.01" min="0" value="0.00" inputmode="decimal" placeholder="0.00">
                        <label class="mini-check billing-inline-check"><input type="checkbox" name="not_invoiceable" id="edit_not_invoiceable" value="1" onchange="toggleNotInvoiceable('edit')"> Nu se factureaza</label>
                    </div>
                    <input type="text" name="billing_note" id="edit_billing_note" placeholder="Motiv nefacturare" style="display:none;">
                </div>

                <div class="form-group full"><label>Observatii pentru echipa</label><textarea name="notes" id="edit_notes"></textarea></div>
            </div>

            <div class="actions-row edit-actions-row">
                <div class="actions-left edit-actions-line">
                    <button class="btn danger" type="button" onclick="deleteAppointment()">Sterge</button>
                    <button class="btn" type="button" id="edit_send_sms_btn" onclick="sendAppointmentSmsFromEdit()">Trimite SMS</button>
                    <button class="btn accent" type="button" id="edit_finalize_btn" onclick="finalizeAppointmentFromEdit()">Finalizeaza</button>
                    <button class="btn" type="button" id="edit_pv_btn" onclick="openPvFromEdit()" title="Emite PV din birou.">Emite PV</button>
                    <button class="btn" type="button" id="edit_pv_email_btn" onclick="sendPvEmailFromEdit(this)" style="display:none;" disabled>Trimite email</button>
                    <button class="btn accent" type="submit">Salveaza</button>
                </div>
            </div>
        </form>
    </div>
</div>

<form method="post" id="deleteForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="appointment_id" id="delete_appointment_id">
    <input type="hidden" name="redirect_date" value="<?= hcal($currentDate) ?>">
    <input type="hidden" name="redirect_view" value="<?= hcal($view) ?>">
    <input type="hidden" name="redirect_team" value="<?= hcal($selectedTeam) ?>">
</form>

<form method="post" id="adminFinalizeForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="admin_finalize">
    <input type="hidden" name="appointment_id" id="admin_finalize_appointment_id">
    <input type="hidden" name="redirect_date" value="<?= hcal($currentDate) ?>">
    <input type="hidden" name="redirect_view" value="<?= hcal($view) ?>">
    <input type="hidden" name="redirect_team" value="<?= hcal($selectedTeam) ?>">
</form>
<?php else: ?>
<div class="modal" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="teamModalTitle">Finalizeaza lucrarea</h2>
            <button class="modal-close" type="button" onclick="closeModal('editModal')">&times;</button>
        </div>

        <div class="readonly-box" id="teamReadonlyDetails"></div>
        <div class="office-note-box" id="teamOfficeNotesBox" style="display:none;"></div>
        <div class="readonly-box" id="teamCompletionReadonly" style="display:none;"></div>
        <div class="readonly-box" id="teamPvActions" style="display:none;"></div>

        <form method="post" id="teamUpdateForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="team_update">
            <input type="hidden" name="appointment_id" id="team_appointment_id">
            <input type="hidden" name="redirect_date" value="<?= hcal($currentDate) ?>">
            <input type="hidden" name="redirect_view" value="<?= hcal($view) ?>">

            <div class="form-grid">
                <div class="form-group full">
                    <label>Mentiuni finalizare lucrare *</label>
                    <textarea name="completion_notes" id="team_completion_notes" required placeholder="Scrie ce s-a constatat si ce s-a executat la lucrare..."></textarea>
                </div>
            </div>

            <div class="actions-row"><div></div><div class="actions-right"><button class="btn" type="button" onclick="closeModal('editModal')">Renunta</button><button class="btn accent" type="submit">Finalizeaza lucrarea</button></div></div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
const currentDate = '<?= hcal($currentDate) ?>';
const currentView = '<?= hcal($view) ?>';
const defaultTeamId = '<?= (int)$defaultTeamId ?>';
const calendarEvents = <?= json_encode($calendarEvents, JSON_UNESCAPED_UNICODE) ?>;
const serviceDurations = <?= json_encode($serviceDurations, JSON_UNESCAPED_UNICODE) ?>;
const allTeamsData = <?= json_encode(array_map(function($team) { return ['id' => (int)$team['id'], 'name' => (string)$team['name']]; }, $allTeams), JSON_UNESCAPED_UNICODE) ?>;
const clientsData = <?= json_encode($clientsForJs, JSON_UNESCAPED_UNICODE) ?>;
const locationsByClient = <?= json_encode($locationsByClientForJs, JSON_UNESCAPED_UNICODE) ?>;
const contractServicesByClientLocation = <?= json_encode($contractServicesByClientLocationForJs, JSON_UNESCAPED_UNICODE) ?>;
const shouldOpenCreateModal = <?= $shouldOpenCreateModal ? 'true' : 'false' ?>;
const prefillClient = <?= json_encode($prefillClient, JSON_UNESCAPED_UNICODE) ?>;
const prefillTaskId = '<?= (int)$prefillTaskId ?>';
const prefillServiceType = <?= json_encode($prefillServiceType, JSON_UNESCAPED_UNICODE) ?>;
const prefillAddress = <?= json_encode($prefillAddress, JSON_UNESCAPED_UNICODE) ?>;
const prefillContactPerson = <?= json_encode($prefillContactPerson, JSON_UNESCAPED_UNICODE) ?>;
const prefillContactPhone = <?= json_encode($prefillContactPhone, JSON_UNESCAPED_UNICODE) ?>;
const prefillLocationId = '<?= (int)$prefillLocationId ?>';
const prefillBillingAmount = '<?= hcal($prefillBillingAmount) ?>';
const prefillContractId = '<?= (int)$prefillContractId ?>';
const prefillContractServiceId = '<?= (int)$prefillContractServiceId ?>';
const prefillServiceId = '<?= (int)$prefillServiceId ?>';
const prefillSurfaceValue = <?= json_encode($prefillSurfaceValue, JSON_UNESCAPED_UNICODE) ?>;
const prefillSurfaceUnit = <?= json_encode($prefillSurfaceUnit, JSON_UNESCAPED_UNICODE) ?>;
const prefillDocumentId = '<?= (int)$prefillDocumentId ?>';
const prefillDocumentItemId = '<?= (int)$prefillDocumentItemId ?>';
const csrfToken = '<?= hcal(csrf_token()) ?>';
let draggedAppointmentId = '';
let currentLoadedAppointment = null;
window.pzAppointmentWasDragged = false;

function openModal(id) { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }
function setField(id, value) { const field = document.getElementById(id); if (field) field.value = value || ''; }
function normalizeHalfHourTime(value) {
    const match = String(value || '').match(/^(\d{1,2}):(\d{2})/);
    if (!match) return '';
    const hour = Math.max(0, Math.min(23, Number(match[1]) || 0));
    const minuteRaw = Number(match[2]) || 0;
    let minute = minuteRaw < 15 ? 0 : (minuteRaw < 45 ? 30 : 0);
    let finalHour = hour;
    if (minuteRaw >= 45) finalHour = Math.min(23, hour + 1);
    return String(finalHour).padStart(2, '0') + ':' + String(minute).padStart(2, '0');
}
function setTimeField(id, value) {
    const field = document.getElementById(id);
    if (!field) return;
    const normalized = normalizeHalfHourTime(value);
    field.value = normalized || '';
}
function setChecked(id, checked) { const field = document.getElementById(id); if (field) field.checked = !!checked; }
function moneyInput(value) {
    const n = Number(String(value ?? '').replace(',', '.'));
    return Number.isFinite(n) && n > 0 ? n.toFixed(2) : '0.00';
}
function supportTeamSelectName(prefix) {
    return 'support_team_ids[]';
}
function resetSupportTeams(prefix) {
    const list = document.getElementById(prefix + '_support_teams');
    if (list) list.innerHTML = '';
}
function addSupportTeamRow(prefix, selectedTeamId = '') {
    const list = document.getElementById(prefix + '_support_teams');
    if (!list) return;

    const row = document.createElement('div');
    row.className = 'support-team-row';

    const select = document.createElement('select');
    select.name = supportTeamSelectName(prefix);
    select.className = 'support-team-select';

    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = 'Alege tehnician suplimentar';
    select.appendChild(empty);

    (allTeamsData || []).forEach(team => {
        const option = document.createElement('option');
        option.value = String(team.id || '');
        option.textContent = team.name || ('Tehnician #' + team.id);
        select.appendChild(option);
    });

    if (selectedTeamId) select.value = String(selectedTeamId);

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'support-remove-btn';
    remove.textContent = 'X';
    remove.onclick = () => row.remove();

    row.appendChild(select);
    row.appendChild(remove);
    list.appendChild(row);
}
function setSupportTeams(prefix, teamIds) {
    resetSupportTeams(prefix);
    const ids = (teamIds || []).filter(teamId => String(teamId || '') !== '');
    if (!ids.length) {
        addSupportTeamRow(prefix, '');
        return;
    }
    ids.forEach(teamId => addSupportTeamRow(prefix, teamId));
}
function toggleNotInvoiceable(prefix) {
    const check = document.getElementById(prefix + '_not_invoiceable');
    const amount = document.getElementById(prefix + '_billing_amount');
    const note = document.getElementById(prefix + '_billing_note');
    const checked = !!check?.checked;
    if (amount) {
        amount.required = !checked;
        if (checked) amount.value = '0.00';
    }
    if (note) {
        note.style.display = checked ? 'block' : 'none';
        note.required = checked;
        if (!checked) note.value = '';
    }
}
function getContractServicesForSelection(prefix) {
    const clientId = document.getElementById(prefix + '_existing_client_id')?.value || '';
    const locationId = document.getElementById(prefix + '_client_location_id')?.value || '';
    if (!clientId || !locationId) return [];
    return (((contractServicesByClientLocation[clientId] || {})[locationId]) || []);
}
function findContractServiceMatch(prefix, preferCurrentService = true) {
    const list = getContractServicesForSelection(prefix);
    if (!list.length) return null;
    const serviceSelect = document.getElementById(prefix + '_service_type');
    const selectedService = String(serviceSelect?.value || '').trim().toLowerCase();
    if (preferCurrentService && selectedService) {
        const found = list.find(item => String(item.service_name || '').trim().toLowerCase() === selectedService);
        if (found) return found;
    }
    return list[0] || null;
}
function applyContractServiceForSelection(prefix, overwriteService = true) {
    const item = findContractServiceMatch(prefix, !overwriteService);
    const contractId = document.getElementById(prefix + '_contract_id');
    const contractServiceId = document.getElementById(prefix + '_contract_service_id');
    if (!item) {
        if (contractId) contractId.value = '';
        if (contractServiceId) contractServiceId.value = '';
        setField(prefix + '_service_id', '');
        setField(prefix + '_surface_value', '');
        setField(prefix + '_surface_unit', '');
        setField(prefix + '_currency', 'RON');
        setField(prefix + '_document_id', '');
        setField(prefix + '_document_item_id', '');
        const amount = document.getElementById(prefix + '_billing_amount');
        if (amount && !document.getElementById(prefix + '_not_invoiceable')?.checked) amount.required = true;
        return;
    }
    if (contractId) contractId.value = item.contract_id || '';
    if (contractServiceId) contractServiceId.value = item.contract_service_id || '';
    setField(prefix + '_service_id', item.service_id || '');
    setField(prefix + '_surface_value', item.surface_value || '');
    setField(prefix + '_surface_unit', item.surface_unit || '');
    setField(prefix + '_currency', item.currency || 'RON');
    setField(prefix + '_document_id', item.document_id || '');
    setField(prefix + '_document_item_id', item.document_item_id || '');
    if (overwriteService && item.service_name) {
        setSelectValueWithFallback(prefix + '_service_type', item.service_name);
        applyServiceDuration(prefix + '_service_type', prefix + '_duration');
    }
    if (!document.getElementById(prefix + '_not_invoiceable')?.checked) {
        setField(prefix + '_billing_amount', moneyInput(item.price));
        const amount = document.getElementById(prefix + '_billing_amount');
        if (amount) amount.required = true;
    }
}
function applyServiceDuration(selectId, durationId) {
    const select = document.getElementById(selectId);
    const duration = document.getElementById(durationId);
    if (!select || !duration) return;
    const option = select.options[select.selectedIndex];
    const minutes = option ? option.getAttribute('data-duration') : '';
    if (minutes) setDurationValue(durationId, Number(minutes));
}
function setDurationValue(selectId, minutes) {
    const select = document.getElementById(selectId);
    if (!select) return;
    const normalizedMinutes = Math.max(30, Math.round(Number(minutes || 60) / 30) * 30);
    const value = String(normalizedMinutes || 60);
    const exists = Array.from(select.options).some(option => option.value === value);
    if (!exists) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value + ' minute';
        select.appendChild(option);
    }
    select.value = value;
}
function setSelectValueWithFallback(selectId, value) {
    const select = document.getElementById(selectId);
    if (!select) return;
    if (!value) { select.value = ''; return; }
    const exists = Array.from(select.options).some(option => option.value === value);
    if (!exists) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value + ' (existent)';
        option.setAttribute('data-duration', serviceDurations[value] || 60);
        select.appendChild(option);
    }
    select.value = value;
}
function getClientAddress(client) { return client ? (client.effective_address || client.registered_address || client.address || '') : ''; }
function getClientContactPerson(client) { return client ? (client.contact_person || client.legal_representative_name || client.name || '') : ''; }
function getClientContactPhone(client) { return client ? (client.contact_phone || client.phone || '') : ''; }
function populateLocations(prefix, clientId, selectedLocationId = '') {
    const select = document.getElementById(prefix + '_client_location_id');
    const hint = document.getElementById(prefix + '_location_hint');
    if (!select) return;
    select.innerHTML = '';
    if (!clientId || !clientsData[clientId]) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'Alege mai intai clientul';
        select.appendChild(option);
        select.disabled = true;
        if (hint) hint.textContent = '';
        return;
    }

    const locations = locationsByClient[clientId] || [];
    if (locations.length === 0) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'Nu exista puncte de lucru salvate';
        select.appendChild(option);
        select.disabled = true;
        if (hint) hint.textContent = 'Adauga punctul de lucru in fisa clientului pentru programare corecta.';
        handleLocationChange(prefix);
        return;
    }

    if (locations.length > 1) {
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = 'Alege punctul de lucru';
        select.appendChild(emptyOption);
    }

    locations.forEach(location => {
        const option = document.createElement('option');
        option.value = String(location.id);
        option.textContent = (location.location_name || 'Punct de lucru') + (location.address ? ' - ' + location.address : '');
        option.dataset.address = location.address || '';
        option.dataset.contactPerson = location.contact_person || '';
        option.dataset.contactPhone = location.phone || '';
        select.appendChild(option);
    });
    select.disabled = false;

    const hasRequestedLocation = selectedLocationId && Array.from(select.options).some(option => option.value === String(selectedLocationId));
    if (hasRequestedLocation) {
        select.value = String(selectedLocationId);
    } else if (locations.length === 1) {
        select.value = String(locations[0].id);
    } else {
        select.value = '';
    }

    if (hint) {
        hint.textContent = locations.length === 1
            ? 'Punctul de lucru a fost preluat automat.'
            : 'Alege punctul de lucru unde se presteaza serviciul.';
    }
    handleLocationChange(prefix);
}
function handleClientChange(prefix, selectedLocationId = '') {
    const clientSelect = document.getElementById(prefix + '_existing_client_id');
    if (!clientSelect) return;
    const clientId = clientSelect.value;
    if (!clientsData[clientId]) {
        populateLocations(prefix, '', '');
        setField(prefix + '_address', '');
        setField(prefix + '_contact_person', '');
        setField(prefix + '_contact_phone', '');
        return;
    }
    populateLocations(prefix, clientId, selectedLocationId);
}
function handleLocationChange(prefix) {
    const locationSelect = document.getElementById(prefix + '_client_location_id');
    const clientSelect = document.getElementById(prefix + '_existing_client_id');
    if (!locationSelect || !clientSelect) return;
    const selected = locationSelect.options[locationSelect.selectedIndex];
    if (selected && selected.value) {
        setField(prefix + '_address', selected.dataset.address || '');
        setField(prefix + '_contact_person', selected.dataset.contactPerson || '');
        setField(prefix + '_contact_phone', selected.dataset.contactPhone || '');
    } else {
        setField(prefix + '_address', '');
        setField(prefix + '_contact_person', '');
        setField(prefix + '_contact_phone', '');
    }
    applyContractServiceForSelection(prefix, true);
}
function fillCreateClient(client, addressOverride = '', locationId = '', contactPersonOverride = '', contactPhoneOverride = '') {
    if (!client) return;
    if (!clientsData[client.id]) clientsData[client.id] = client;
    // Folosim noul autocomplete in loc de select.value
    if (typeof pzClientSetAuto === 'function') {
        pzClientSetAuto('create', clientsData[client.id]);
    } else {
        const hidden = document.getElementById('create_existing_client_id');
        if (hidden) hidden.value = client.id || '';
    }
    // handleClientChange e apelat automat de pzClientSetAuto, dar trebuie sa pasam locationId
    if (locationId) handleClientChange('create', locationId);
    if (addressOverride) setField('create_address', addressOverride);
    if (contactPersonOverride) setField('create_contact_person', contactPersonOverride);
    if (contactPhoneOverride) setField('create_contact_phone', contactPhoneOverride);
}
function openCreateModal(date, time, teamId, client = null, taskId = '', serviceType = '', addressOverride = '', locationId = '', contactPersonOverride = '', contactPhoneOverride = '') {
    const modal = document.getElementById('createModal');
    if (!modal) return;
    const form = document.getElementById('createForm');
    if (form) form.reset();
    setField('create_billing_amount', '0.00');
    setField('create_billing_note', '');
    setField('create_contract_id', '');
    setField('create_contract_service_id', '');
    setField('create_service_id', '');
    setField('create_surface_value', '');
    setField('create_surface_unit', '');
    setField('create_currency', 'RON');
    setField('create_document_id', '');
    setField('create_document_item_id', '');
    setChecked('create_not_invoiceable', false);
    toggleNotInvoiceable('create');
    setSupportTeams('create', []);
    populateLocations('create', '', '');
    setField('create_task_id', '');
    const fromTask = taskId !== '';
    setField('create_date', fromTask ? '' : (date || currentDate));
    setTimeField('create_time', fromTask ? '' : (time || '09:00'));
    const teamSelect = document.getElementById('create_team_member_id');
    if (teamSelect) teamSelect.value = fromTask ? '' : (teamId || '');
    if (client) fillCreateClient(client, addressOverride, locationId, contactPersonOverride, contactPhoneOverride);
    if (serviceType) { setSelectValueWithFallback('create_service_type', serviceType); applyServiceDuration('create_service_type', 'create_duration'); }
    if (taskId) setField('create_task_id', taskId);
    openModal('createModal');
    setTimeout(() => {
        const clientInput = document.getElementById('create_clientSearchInput');
        if (clientInput && !document.getElementById('create_existing_client_id')?.value) clientInput.focus();
    }, 80);
}
function pvFormUrlForAppointment(id) {
    return 'procese_verbale.php?new=1&appointment_id=' + encodeURIComponent(id);
}
function pvMainUrlFromData(data) {
    if (!data || !Number(data.pv_id || 0)) {
        return pvFormUrlForAppointment(data ? data.id : '');
    }
    if ((data.pv_status || '') === 'draft') {
        return 'procese_verbale.php?edit=' + encodeURIComponent(data.pv_id);
    }
    return 'document_view.php?id=' + encodeURIComponent(data.pv_id);
}
function pvPdfUrlFromData(data) {
    if (!data || !Number(data.pv_id || 0)) return '#';
    return 'document_pdf.php?id=' + encodeURIComponent(data.pv_id) + '&mode=inline';
}
function configurePvButtons(data) {
    currentLoadedAppointment = data || null;
    const isFinalized = (data?.status || '') === 'finalizata';
    const hasPv = Number(data?.pv_id || 0) > 0;
    const editFinalizeBtn = document.getElementById('edit_finalize_btn');
    const editPvBtn = document.getElementById('edit_pv_btn');
    const editEmailBtn = document.getElementById('edit_pv_email_btn');

    if (editFinalizeBtn) {
        editFinalizeBtn.style.display = isFinalized ? 'none' : 'inline-flex';
        editFinalizeBtn.disabled = isFinalized;
    }

    if (editPvBtn) {
        editPvBtn.textContent = hasPv ? ((data.pv_status === 'draft') ? 'Editeaza PV' : 'Vezi PV') : 'Emite PV';
        editPvBtn.disabled = false;
        editPvBtn.style.display = 'inline-flex';
        editPvBtn.title = hasPv ? 'PV existent pentru aceasta programare.' : 'Emite PV din birou.';
    }

    if (editEmailBtn) {
        const canEmail = hasPv && data?.pv_status === 'issued';
        editEmailBtn.style.display = canEmail ? 'inline-flex' : 'none';
        editEmailBtn.disabled = !canEmail || !(data?.pv_client_email || '').trim();
        editEmailBtn.title = editEmailBtn.disabled && canEmail ? 'Clientul nu are email salvat.' : '';
    }
}
function finalizeAppointmentFromEdit() {
    if (!currentLoadedAppointment?.id) { alert('Programarea nu a fost identificata.'); return; }
    if ((currentLoadedAppointment.status || '') === 'finalizata') { return; }
    const form = document.getElementById('adminFinalizeForm');
    const idInput = document.getElementById('admin_finalize_appointment_id');
    if (!form || !idInput) { alert('Nu am putut pregati finalizarea.'); return; }
    idInput.value = currentLoadedAppointment.id;
    form.submit();
}
function openPvFromEdit() {
    if (!currentLoadedAppointment?.id) { alert('Programarea nu a fost identificata.'); return; }
    window.location.href = pvMainUrlFromData(currentLoadedAppointment);
}
function openPvFromTeam() {
    if (!currentLoadedAppointment?.id) { alert('Programarea nu a fost identificata.'); return; }
    window.location.href = pvMainUrlFromData(currentLoadedAppointment);
}
async function sendPvEmail(documentId, btn) {
    if (!documentId) { alert('PV-ul nu a fost identificat.'); return; }
    const originalText = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Se trimite...'; }
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('document_id', documentId);
    try {
        const res = await fetch('document_send_quick.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        const data = await res.json().catch(() => null);
        if (res.ok && data && data.ok) {
            alert('Email trimis cu succes.');
        } else {
            alert((data && data.error) ? data.error : 'Emailul nu a putut fi trimis.');
        }
    } catch (err) {
        console.error('send PV email error:', err);
        alert('Eroare la trimiterea emailului. Reincearca.');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = originalText || 'Trimite email'; }
    }
}
function sendPvEmailFromEdit(btn) {
    if (!currentLoadedAppointment?.pv_id) { alert('Nu exista PV emis pentru aceasta programare.'); return; }
    sendPvEmail(currentLoadedAppointment.pv_id, btn);
}
function sendPvEmailFromTeam(btn) {
    if (!currentLoadedAppointment?.pv_id) { alert('Nu exista PV emis pentru aceasta programare.'); return; }
    sendPvEmail(currentLoadedAppointment.pv_id, btn);
}
function renderTeamPvActions(data) {
    const box = document.getElementById('teamPvActions');
    if (data && data.pv_actions_locked) {
        if (box) box.innerHTML = '<div class="readonly-box">Lucrare alocata ca suport. Responsabil: ' + escHtml(data.primary_team_name || '-') + '.</div>';
        return;
    }
    if (!box) return;
    if (!data || data.status !== 'finalizata') {
        box.style.display = 'none';
        box.innerHTML = '';
        return;
    }
    const hasPv = Number(data.pv_id || 0) > 0;
    const pvLabel = hasPv ? ((data.pv_status === 'draft') ? 'Editeaza PV' : 'Vezi PV') : 'Emite PV';
    const emailEnabled = hasPv && data.pv_status === 'issued' && String(data.pv_client_email || '').trim() !== '';
    const emailDisabled = hasPv && data.pv_status === 'issued' && !emailEnabled;
    const pdfButton = hasPv && data.pv_status === 'issued'
        ? `<a class="btn" target="_blank" href="${escHtml(pvPdfUrlFromData(data))}">PDF</a>`
        : '';
    const emailButton = hasPv && data.pv_status === 'issued'
        ? `<button class="btn accent" type="button" onclick="sendPvEmailFromTeam(this)" ${emailEnabled ? '' : 'disabled'}>${emailDisabled ? 'Email lipsa' : 'Trimite email'}</button>`
        : '';
    box.innerHTML = `
        <div style="display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;">
            <button class="btn pv-aero-btn" type="button" onclick="openPvFromTeam()">${pvLabel}</button>
            ${pdfButton}
            ${emailButton}
        </div>
    `;
    box.style.display = 'block';
}
function deleteAppointment() {
    const id = document.getElementById('edit_appointment_id')?.value;
    if (!id) { alert('Programarea nu a fost identificata.'); return; }
    if (confirm('Sigur vrei sa stergi definitiv aceasta programare?')) {
        document.getElementById('delete_appointment_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}
async function sendAppointmentSmsFromEdit() {
    const id = document.getElementById('edit_appointment_id')?.value;
    const btn = document.getElementById('edit_send_sms_btn');
    if (!id) { alert('Programarea nu a fost identificata.'); return; }
    if (!confirm('Trimiti SMS clientului pentru aceasta programare?')) return;

    const originalText = btn ? btn.textContent : '';
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Se trimite...';
    }

    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('appointment_id', id);

    try {
        const res = await fetch('appointment_sms_send.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        const data = await res.json().catch(() => null);
        if (res.ok && data && data.ok) {
            alert('SMS trimis cu succes.');
        } else {
            alert((data && data.error) ? data.error : 'SMS-ul nu a putut fi trimis. Verifica setarile SMS si logurile din Comunicare.');
        }
    } catch (err) {
        console.error('send appointment sms error:', err);
        alert('Eroare la trimiterea SMS-ului. Reincearca.');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText || 'Trimite SMS';
        }
    }
}
function calcDuration(start, end) {
    if (!start || !end) return 60;
    const [sh, sm] = start.substring(0, 5).split(':').map(Number);
    const [eh, em] = end.substring(0, 5).split(':').map(Number);
    const diff = (eh * 60 + em) - (sh * 60 + sm);
    if (diff <= 0) return 60;
    return Math.max(30, Math.round(diff / 30) * 30);
}
function escHtml(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
async function loadAppointment(id) {
    try {
        const res = await fetch('calendar.php?appointment_id=' + encodeURIComponent(id));
        const data = await res.json();
        if (!data?.id) { alert('Nu am putut incarca programarea.'); return; }
        currentLoadedAppointment = data;
        configurePvButtons(data);
        if (isAdmin) {
            setField('edit_appointment_id', data.id);
            // Setam clientul prin noul autocomplete
            const editClientId = String(data.client_id || '');
            if (editClientId && clientsData[editClientId]) {
                pzClientSetAuto('edit', clientsData[editClientId]);
                handleClientChange('edit', data.client_location_id || '');
            } else {
                pzClientClearAuto('edit');
            }
            setField('edit_address', data.address || data.location_address || data.client_address || '');
            setField('edit_contact_person', data.effective_contact_person || data.contact_person || data.location_contact_person || '');
            setField('edit_contact_phone', data.effective_contact_phone || data.contact_phone || data.location_phone || data.client_phone || '');
            setSelectValueWithFallback('edit_service_type', data.service_type || '');
            setField('edit_team_member_id', data.team_member_id || '');
            setSupportTeams('edit', data.support_team_ids || []);
            setField('edit_appointment_date', data.appointment_date || '');
            setTimeField('edit_start_time', data.start_time || '');
            setField('edit_notes', data.notes || '');
            setField('edit_billing_amount', Number(data.billing_amount || 0).toFixed(2));
            setField('edit_billing_note', data.billing_note || '');
            setField('edit_contract_id', data.contract_id || '');
            setField('edit_contract_service_id', data.contract_service_id || '');
            setField('edit_service_id', data.service_id || '');
            setField('edit_surface_value', data.surface_value || '');
            setField('edit_surface_unit', data.surface_unit || '');
            setField('edit_currency', data.currency || 'RON');
            setField('edit_document_id', data.document_id || '');
            setField('edit_document_item_id', data.document_item_id || '');
            setChecked('edit_not_invoiceable', (data.billing_status || '') === 'nefacturabil');
            toggleNotInvoiceable('edit');
            setDurationValue('edit_duration', calcDuration(data.start_time, data.end_time));
        } else {
            setField('team_appointment_id', data.id);
            const isFinalized = data.status === 'finalizata';
            const isSupportOnly = !!data.is_support_only;
            const existingCompletion = isFinalized ? (data.completion_notes || '') : '';
            setField('team_completion_notes', existingCompletion);
            const teamForm = document.getElementById('teamUpdateForm');
            const completionReadonly = document.getElementById('teamCompletionReadonly');
            const teamModalTitle = document.getElementById('teamModalTitle');
            if (teamModalTitle) {
                teamModalTitle.textContent = isSupportOnly
                    ? ('Responsabil: ' + (data.primary_team_name || '-'))
                    : 'Finalizeaza lucrarea';
            }
            if (teamForm) teamForm.style.display = (isFinalized || isSupportOnly) ? 'none' : 'block';
            if (completionReadonly) {
                if (isFinalized) {
                    completionReadonly.style.display = 'block';
                    completionReadonly.innerHTML = `<strong>Mentiuni finalizare:</strong><br>${escHtml(existingCompletion || '-').replace(/\n/g, '<br>')}`;
                } else {
                    completionReadonly.style.display = 'none';
                    completionReadonly.innerHTML = '';
                }
            }
            renderTeamPvActions(isSupportOnly ? {...data, pv_actions_locked: true} : data);
            document.getElementById('teamReadonlyDetails').innerHTML = `
                <div><strong>Client:</strong> ${escHtml(data.client_name || '-')}</div>
                <div><strong>Locatie:</strong> ${escHtml(data.location_name || '-')}</div>
                <div><strong>Contact:</strong> ${escHtml(data.effective_contact_person || '-')}</div>
                <div><strong>Telefon contact:</strong> ${escHtml(data.effective_contact_phone || '-')}</div>
                <div><strong>Serviciu:</strong> ${escHtml(data.service_type || '-')}</div>
                <div><strong>Status:</strong> ${escHtml(data.status || '-')}</div>
                <div><strong>Data:</strong> ${escHtml(data.appointment_date || '-')}</div>
                <div><strong>Ora:</strong> ${escHtml((data.start_time || '').substring(0, 5))}</div>
                <div><strong>Adresa:</strong> ${escHtml(data.address || data.location_address || data.client_address || '-')}</div>
            `;
            const officeBox = document.getElementById('teamOfficeNotesBox');
            if (officeBox) {
                if ((data.notes || '').trim() !== '') {
                    officeBox.style.display = 'block';
                    officeBox.innerHTML = `<strong>Mentiuni birou / instructiuni:</strong>${escHtml(data.notes).replace(/\n/g, '<br>')}`;
                } else {
                    officeBox.style.display = 'none';
                    officeBox.innerHTML = '';
                }
            }
        }
        openModal('editModal');
    } catch (err) {
        console.error('loadAppointment error:', err);
        alert('Eroare la incarcarea programarii.');
    }
}


function handleAppointmentDragStart(event) {
    if (!isAdmin) return;
    const target = event.currentTarget;
    draggedAppointmentId = target?.dataset?.appointmentId || '';
    if (!draggedAppointmentId) return;
    window.pzAppointmentWasDragged = true;
    target.classList.add('dragging');
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', draggedAppointmentId);
    }
}
function handleAppointmentDragEnd(event) {
    event.currentTarget?.classList.remove('dragging');
    document.querySelectorAll('.slot-cell.drag-over').forEach(cell => cell.classList.remove('drag-over'));
    draggedAppointmentId = '';
    setTimeout(() => { window.pzAppointmentWasDragged = false; }, 250);
}
function handleSlotDragOver(event) {
    if (!isAdmin || !draggedAppointmentId) return;
    event.preventDefault();
    event.currentTarget.classList.add('drag-over');
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
}
function handleSlotDragLeave(event) {
    event.currentTarget.classList.remove('drag-over');
}
async function handleSlotDrop(event) {
    if (!isAdmin) return;
    event.preventDefault();
    event.stopPropagation();
    const slot = event.currentTarget;
    slot.classList.remove('drag-over');
    const appointmentId = draggedAppointmentId || event.dataTransfer?.getData('text/plain') || '';
    if (!appointmentId) return;

    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('appointment_id', appointmentId);
    formData.append('new_team_id', slot.dataset.dropTeam || '');
    formData.append('new_date', slot.dataset.dropDate || currentDate);
    formData.append('new_start_time', slot.dataset.dropTime || '09:00');

    try {
        const res = await fetch('appointment_drag_update.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.ok) {
            alert((data && data.error) ? data.error : 'Programarea nu a putut fi mutata.');
            return;
        }
        const params = new URLSearchParams(window.location.search);
        params.set('date', data.new_date || slot.dataset.dropDate || currentDate);
        params.set('view', currentView || 'day');
        if (data.time_or_date_changed) params.set('drag_time_changed', '1');
        else params.set('drag_no_sms', '1');
        window.location.href = 'calendar.php?' + params.toString();
    } catch (err) {
        console.error('appointment drag update error:', err);
        alert('Eroare la mutarea programarii. Reincearca.');
    }
}

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', event => { if (event.target === modal) modal.classList.remove('open'); });
});
document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.modal.open').forEach(modal => modal.classList.remove('open')); });

function validateBillingBeforeSubmit(prefix) {
    const notInvoiceable = !!document.getElementById(prefix + '_not_invoiceable')?.checked;
    const amount = Number(document.getElementById(prefix + '_billing_amount')?.value || 0);
    const note = String(document.getElementById(prefix + '_billing_note')?.value || '').trim();
    if (notInvoiceable) {
        if (!note) {
            alert('Completeaza motivul pentru care lucrarea nu se factureaza.');
            document.getElementById(prefix + '_billing_note')?.focus();
            return false;
        }
        return true;
    }
    if (!amount || amount <= 0) {
        alert('Completeaza valoarea lucrarii fara TVA sau bifeaza Nu se factureaza si trece motivul.');
        document.getElementById(prefix + '_billing_amount')?.focus();
        return false;
    }
    return true;
}

/* === AUTOCOMPLETE smart pentru client (in calendar, hidden input e {prefix}_existing_client_id) === */
const pzAutocompleteState = {};
function pzNormalize(s) { return String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,''); }
function pzEscHtml(s) { return String(s||'').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }
function pzHighlight(text, q) {
    if (!q) return pzEscHtml(text);
    const norm = pzNormalize(text), qn = pzNormalize(q), idx = norm.indexOf(qn);
    if (idx < 0) return pzEscHtml(text);
    return pzEscHtml(text.slice(0, idx)) + '<mark>' + pzEscHtml(text.slice(idx, idx + q.length)) + '</mark>' + pzEscHtml(text.slice(idx + q.length));
}
function pzClientHiddenId(prefix) {
    // In calendar: create_existing_client_id / edit_existing_client_id
    return prefix + '_existing_client_id';
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
    const hidden = document.getElementById(pzClientHiddenId(prefix));
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
    const onChangeName = wrap.dataset.onchange;
    if (onChangeName && typeof window[onChangeName] === 'function') {
        window[onChangeName](prefix);
    }
}
function pzClientClearAuto(prefix) {
    const wrap = document.getElementById(prefix + '_clientAutocomplete');
    const hidden = document.getElementById(pzClientHiddenId(prefix));
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
    const hidden = document.getElementById(pzClientHiddenId(prefix));
    if (!wrap || !input || !hidden) return;
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
document.addEventListener('click', (e) => {
    document.querySelectorAll('.pz-autocomplete.is-open').forEach(w => {
        if (!w.contains(e.target)) w.classList.remove('is-open');
    });
});

function setAppointmentAvailabilityMessage(prefix, message) {
    const box = document.getElementById(prefix + '_availability_error');
    if (!box) return;
    const msg = String(message || '').trim();
    if (!msg) {
        box.style.display = 'none';
        box.textContent = '';
        return;
    }
    box.textContent = msg;
    box.style.display = 'block';
    try { box.scrollIntoView({behavior: 'smooth', block: 'center'}); } catch (e) {}
}
async function checkAppointmentAvailabilityBeforeSave(prefix) {
    const form = document.getElementById(prefix + 'Form');
    if (!form) return true;

    setAppointmentAvailabilityMessage(prefix, '');

    const fd = new FormData(form);
    fd.set('action', 'check_availability');

    const res = await fetch('calendar.php', {
        method: 'POST',
        body: fd,
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    });

    let data = null;
    try { data = await res.json(); } catch (e) {}

    if (!res.ok || !data) {
        setAppointmentAvailabilityMessage(prefix, 'Nu s-a putut verifica disponibilitatea operatorilor. Incearca din nou.');
        return false;
    }

    if (!data.ok) {
        setAppointmentAvailabilityMessage(prefix, data.message || 'Un operator este deja alocat in intervalul selectat.');
        return false;
    }

    return true;
}
function bindAppointmentFormSubmit(prefix) {
    const form = document.getElementById(prefix + 'Form');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        if (form.dataset.forceSubmit === '1') {
            form.dataset.forceSubmit = '0';
            return;
        }

        e.preventDefault();

        if (!validateBillingBeforeSubmit(prefix)) {
            return;
        }

        const submitBtn = e.submitter || form.querySelector('button[type="submit"]');
        const oldText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Verific...';
        }

        const ok = await checkAppointmentAvailabilityBeforeSave(prefix);

        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = oldText;
        }

        if (!ok) {
            return;
        }

        form.dataset.forceSubmit = '1';
        form.requestSubmit ? form.requestSubmit() : form.submit();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    pzInitAutocomplete('create');
    pzInitAutocomplete('edit');
    bindAppointmentFormSubmit('create');
    bindAppointmentFormSubmit('edit');
});

document.addEventListener('DOMContentLoaded', () => {
    populateLocations('create', '', '');
    if (shouldOpenCreateModal) {
        openCreateModal('', '', '', prefillClient, prefillTaskId, prefillServiceType, prefillAddress, prefillLocationId, prefillContactPerson, prefillContactPhone);
        setField('create_billing_amount', prefillBillingAmount || '0.00');
        setField('create_contract_id', prefillContractId || '');
        setField('create_contract_service_id', prefillContractServiceId || '');
        setField('create_service_id', prefillServiceId || '');
        setField('create_surface_value', prefillSurfaceValue || '');
        setField('create_surface_unit', prefillSurfaceUnit || '');
        setField('create_document_id', prefillDocumentId || '');
        setField('create_document_item_id', prefillDocumentItemId || '');
    }
    const visualCalendar = document.getElementById('visualCalendar');
    if (visualCalendar) {
        const calendar = new FullCalendar.Calendar(visualCalendar, {
            initialView: '<?= hcal($fullCalendarView) ?>',
            initialDate: currentDate,
            locale: 'ro',
            firstDay: 1,
            height: 'auto',
            headerToolbar: false,
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            slotDuration: '00:30:00',
            snapDuration: '00:30:00',
            events: calendarEvents,
            eventClassNames: info => {
                const classes = [];
                const currentView = '<?= hcal($view) ?>';
                if (currentView === 'month') {
                    classes.push('fc-event-month-box');
                }
                if (info.event.extendedProps.status === 'finalizata') {
                    classes.push('fc-event-finalizata');
                }
                return classes;
            },
            eventContent: info => {
                const wrap = document.createElement('div');
                wrap.style.display = 'flex';
                wrap.style.flexDirection = 'column';
                wrap.style.gap = '2px';
                const title = document.createElement('div');
                const currentView = '<?= hcal($view) ?>';
                if (currentView === 'month') {
                    title.textContent = '';
                    title.style.height = '12px';
                    title.style.minHeight = '12px';
                    title.style.fontSize = '0';
                    title.setAttribute('aria-label', info.event.extendedProps.client || info.event.title || 'Programare');
                    wrap.style.minHeight = '12px';
                } else if (currentView === 'week') {
                    // Vizualizare saptamana: bloc compact, fara nume client.
                    // Fisa completa ramane disponibila la click pe bloc.
                    title.textContent = '';
                    title.style.height = '100%';
                    title.style.minHeight = '14px';
                    title.style.borderRadius = '7px';
                    title.style.fontSize = '0';
                    title.style.lineHeight = '0';
                    title.style.opacity = '1';
                    title.setAttribute('aria-label', info.event.extendedProps.client || info.event.title || 'Programare');
                    title.setAttribute('title', (info.event.extendedProps.client || 'Client') + ' - ' + (info.event.extendedProps.service || 'Lucrare') + ' - ' + (info.event.extendedProps.team || 'Tehnician'));
                    wrap.style.height = '100%';
                    wrap.style.minHeight = '14px';
                } else {
                    title.textContent = info.event.title;
                }
                title.style.overflow = 'hidden';
                title.style.textOverflow = 'ellipsis';
                title.style.whiteSpace = 'nowrap';
                wrap.appendChild(title);
                return { domNodes: [wrap] };
            },
            eventClick: info => loadAppointment(info.event.id),
            dateClick: info => { if (isAdmin) openCreateModal(info.dateStr.substring(0, 10), '09:00', defaultTeamId); }
        });
        calendar.render();
    }
});
</script>
</body>
</html>
