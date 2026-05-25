<?php

/*
|--------------------------------------------------------------------------
| tasks_helpers.php
|--------------------------------------------------------------------------
| Helper-i pentru pagina Sarcini (tasks.php).
| 15 funcții grupate pe categorii:
|   - URL/format: task_page_url, recurrence_label_tasks
|   - Schemă DB: table_exists_tasks, column_exists_tasks, ensure_column_tasks
|   - Date safe: safe_date_tasks
|   - Recurență: task_add_months, task_next_due_date
|   - Client/adresă: task_client_address, task_client_contact_person,
|                    task_client_contact_phone, task_get_location
|   - Snapshot la creare task: task_snapshot_address,
|                    task_snapshot_contact_person, task_snapshot_contact_phone
|
| Dependențe: PDO global ($pdo), config.php (h() din app_helpers.php).
|--------------------------------------------------------------------------
*/

function safe_task_view(string $view): string {
    return in_array($view, ['month', 'year'], true) ? $view : 'month';
}

function task_page_url(array $params): string {
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $clean[$key] = $value;
    }
    return 'tasks.php' . ($clean ? '?' . http_build_query($clean) : '');
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
            // Nu blocam pagina dacă ALTER-ul nu poate rula.
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

