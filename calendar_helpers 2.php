<?php

/*
|--------------------------------------------------------------------------
| calendar_helpers.php
|--------------------------------------------------------------------------
| Helper-i pentru pagina Calendar (calendar.php).
| 36 funcții grupate pe categorii:
|   - Escape + date safe: hcal, safe_date, safe_view
|   - Schemă DB: calendar_table_exists, calendar_column_exists,
|                calendar_ensure_column
|   - Echipe (team): calendar_request_team_ids, calendar_team_csv,
|                calendar_clean_team_ids, calendar_post_support_team_ids,
|                calendar_sync_appointment_teams, calendar_get_appointment_teams
|   - Time + slot: calendar_normalize_half_hour_time, slot_index, duration_span
|   - Color: calendar_clean_hex_color, calendar_lighten_hex
|   - Money: calendar_money_value, calendar_money_input, calendar_money_label
|   - String/format: mb_first_letter, calendar_initials, ro_date_label
|   - Conflict programări: calendar_find_team_time_conflicts,
|                calendar_conflict_message
|   - Client/locație: calendar_get_client, calendar_client_address,
|                calendar_client_contact_person, calendar_client_contact_phone,
|                calendar_get_location
|   - Contract: calendar_find_contract_service
|   - Snapshot la creare: calendar_snapshot_address,
|                calendar_snapshot_contact_person, calendar_snapshot_contact_phone
|   - Proces verbal: calendar_fetch_pv_for_appointment,
|                calendar_attach_pv_meta
|
| Dependențe: PDO global ($pdo), config.php.
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
            // Nu blocam pagina dacă ALTER nu poate rula.
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

    return in_array($view, ['day', 'week', 'month'], true) ? $view : 'week';
}

function calendar_request_team_ids(): array {
    $ids = [];
    $rawTeam = array_key_exists('team', $_GET) ? trim((string)$_GET['team']) : null;

    if ($rawTeam !== null && ($rawTeam === '' || strcasecmp($rawTeam, 'all') === 0)) {
        return [];
    }

    $rawIds = $_GET['team_ids'] ?? null;
    if (is_array($rawIds)) {
        foreach ($rawIds as $raw) {
            $id = (int)$raw;
            if ($id > 0) { $ids[$id] = $id; }
        }
        return array_values($ids);
    }

    foreach (explode(',', (string)($rawTeam ?? 'all')) as $part) {
        $id = (int)trim($part);
        if ($id > 0) { $ids[$id] = $id; }
    }
    return array_values($ids);
}

function calendar_team_csv(array $teamIds): string {
    if (!$teamIds) { return 'all'; }
    $clean = [];
    foreach ($teamIds as $id) {
        $id = (int)$id;
        if ($id > 0) { $clean[$id] = $id; }
    }
    return $clean ? implode(',', array_values($clean)) : 'all';
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
    $line = trim((string)($client['billing_address_line'] ?? ''));
    $county = trim((string)($client['billing_county'] ?? ''));
    $city = trim((string)($client['billing_city'] ?? ''));
    $country = trim((string)($client['billing_country'] ?? ''));
    $postal = trim((string)($client['billing_postal_code'] ?? ''));
    $address = trim(implode(', ', array_filter([$line, $county, $city, $country], static fn($value) => $value !== '')));
    if ($postal !== '') {
        $address .= ($address !== '' ? ', ' : '') . 'CP ' . $postal;
    }
    if ($address !== '') {
        return $address;
    }

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

