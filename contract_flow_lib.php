<?php

/*
|--------------------------------------------------------------------------
| Contract -> Task -> Calendar flow helpers
|--------------------------------------------------------------------------
| Contractul emis rămâne document juridic, iar aceste functii creeaza partea
| operationala: contract_services si tasks. Cod UTF-8, cu diacritice pastrate.
*/

if (!function_exists('pz_flow_column_exists')) {
    function pz_flow_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) > 0;
    }
}

if (!function_exists('pz_flow_index_exists')) {
    function pz_flow_index_exists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $stmt->execute([$table, $index]);
        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) > 0;
    }
}

if (!function_exists('pz_flow_table_exists')) {
    function pz_flow_table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) > 0;
    }
}

if (!function_exists('pz_flow_add_column')) {
    function pz_flow_add_column(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (pz_flow_column_exists($pdo, $table, $column)) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            error_log('PestZone flow add column error: ' . $table . '.' . $column . ' - ' . $e->getMessage());
        }
    }
}

if (!function_exists('pz_flow_add_index')) {
    function pz_flow_add_index(PDO $pdo, string $table, string $index, string $sql): void
    {
        if (pz_flow_index_exists($pdo, $table, $index)) {
            return;
        }
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('PestZone flow add index error: ' . $table . '.' . $index . ' - ' . $e->getMessage());
        }
    }
}

if (!function_exists('pz_flow_ensure_schema')) {
    function pz_flow_ensure_schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS contracts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            contract_number VARCHAR(120) NOT NULL DEFAULT '',
            contract_date DATE NOT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'activ',
            estimated_value DECIMAL(12,2) NULL,
            total_value DECIMAL(12,2) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_contracts_client (client_id),
            INDEX idx_contracts_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        pz_flow_add_column($pdo, 'contracts', 'source_document_id', 'INT NULL');
        pz_flow_add_column($pdo, 'contracts', 'document_series_id', 'INT NULL');
        pz_flow_add_column($pdo, 'contracts', 'document_number_id', 'INT NULL');
        pz_flow_add_column($pdo, 'contracts', 'issued_at', 'DATETIME NULL');
        pz_flow_add_column($pdo, 'contracts', 'issued_by', 'INT NULL');
        pz_flow_add_column($pdo, 'contracts', 'title', 'VARCHAR(180) NULL');
        pz_flow_add_column($pdo, 'contracts', 'auto_renewal', 'TINYINT(1) NOT NULL DEFAULT 0');
        pz_flow_add_index($pdo, 'contracts', 'idx_contracts_source_document', 'CREATE INDEX idx_contracts_source_document ON contracts (source_document_id)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS contract_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contract_id INT NOT NULL,
            client_id INT NOT NULL,
            client_location_id INT NULL,
            service_id INT NULL,
            service_name VARCHAR(255) NOT NULL,
            frequency VARCHAR(255) NULL,
            planned_date DATE NULL,
            price DECIMAL(12,2) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'neprogramat',
            task_id INT NULL,
            appointment_id INT NULL,
            notes TEXT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'RON',
            recurrence_type VARCHAR(40) NOT NULL DEFAULT 'none',
            recurrence_days INT NULL,
            recurrence_total INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_contract_services_contract (contract_id),
            INDEX idx_contract_services_client_location (client_id, client_location_id),
            INDEX idx_contract_services_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $contractServiceColumns = [
            'document_id' => 'INT NULL',
            'document_item_id' => 'INT NULL',
            'location_name' => 'VARCHAR(220) NULL',
            'location_address' => 'TEXT NULL',
            'location_contact_person' => 'VARCHAR(220) NULL',
            'location_contact_phone' => 'VARCHAR(80) NULL',
            'surface_value' => 'DECIMAL(14,3) NULL',
            'surface_unit' => 'VARCHAR(30) NULL',
            'frequency_type' => 'VARCHAR(60) NULL',
            'frequency_value' => 'INT NULL',
            'planned_time' => 'TIME NULL',
            'estimated_duration_minutes' => 'INT NULL',
            'task_generation_mode' => "VARCHAR(40) NOT NULL DEFAULT 'auto_task'",
        ];
        foreach ($contractServiceColumns as $column => $definition) {
            pz_flow_add_column($pdo, 'contract_services', $column, $definition);
        }
        pz_flow_add_index($pdo, 'contract_services', 'idx_contract_services_document_item', 'CREATE INDEX idx_contract_services_document_item ON contract_services (document_id, document_item_id)');

        if (pz_flow_table_exists($pdo, 'tasks')) {
            $taskColumns = [
                'client_location_id' => 'INT NULL',
                'service_type' => "VARCHAR(150) NOT NULL DEFAULT ''",
                'address' => 'VARCHAR(255) NULL',
                'contact_person' => 'VARCHAR(180) NULL',
                'contact_phone' => 'VARCHAR(60) NULL',
                'due_date' => 'DATE NULL',
                'recurrence_type' => "VARCHAR(30) NOT NULL DEFAULT 'none'",
                'recurrence_days' => 'INT NULL',
                'status' => "VARCHAR(30) NOT NULL DEFAULT 'de_programat'",
                'notes' => 'MEDIUMTEXT NULL',
                'appointment_id' => 'INT NULL',
                'recurrence_group' => 'VARCHAR(80) NULL',
                'recurrence_total' => 'INT NOT NULL DEFAULT 1',
                'recurrence_remaining' => 'INT NOT NULL DEFAULT 1',
                'recurrence_stopped' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'recurrence_index' => 'INT NOT NULL DEFAULT 1',
                'generated_from_task_id' => 'INT NULL',
                'generated_next_task_id' => 'INT NULL',
                'contract_id' => 'INT NULL',
                'contract_service_id' => 'INT NULL',
                'service_id' => 'INT NULL',
                'location_name' => 'VARCHAR(220) NULL',
                'surface_value' => 'DECIMAL(14,3) NULL',
                'surface_unit' => 'VARCHAR(30) NULL',
                'billing_amount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                'currency' => "VARCHAR(10) NOT NULL DEFAULT 'RON'",
                'document_id' => 'INT NULL',
                'document_item_id' => 'INT NULL',
            ];
            foreach ($taskColumns as $column => $definition) {
                pz_flow_add_column($pdo, 'tasks', $column, $definition);
            }
            pz_flow_add_index($pdo, 'tasks', 'idx_tasks_contract_flow', 'CREATE INDEX idx_tasks_contract_flow ON tasks (contract_id, contract_service_id)');
        }

        if (pz_flow_table_exists($pdo, 'appointments')) {
            $appointmentColumns = [
                'task_id' => 'INT NULL',
                'service_id' => 'INT NULL',
                'surface_value' => 'DECIMAL(14,3) NULL',
                'surface_unit' => 'VARCHAR(30) NULL',
                'currency' => "VARCHAR(10) NOT NULL DEFAULT 'RON'",
                'document_id' => 'INT NULL',
                'document_item_id' => 'INT NULL',
            ];
            foreach ($appointmentColumns as $column => $definition) {
                pz_flow_add_column($pdo, 'appointments', $column, $definition);
            }
        }
    }
}

if (!function_exists('pz_flow_str')) {
    function pz_flow_str($value, int $max = 0): string
    {
        $value = trim((string)$value);
        if ($max > 0 && strlen($value) > $max) {
            $value = substr($value, 0, $max);
        }
        return $value;
    }
}

if (!function_exists('pz_flow_date_or_null')) {
    function pz_flow_date_or_null($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}

if (!function_exists('pz_flow_normalize_frequency')) {
    function pz_flow_normalize_frequency(string $frequency): string
    {
        $f = strtolower(trim($frequency));
        $f = str_replace(['_', '-'], ' ', $f);

        if ($f === '') {
            return 'int_unica';
        }
        if (strpos($f, 'lunar') !== false || strpos($f, 'luna') !== false || strpos($f, 'month') !== false) {
            return 'lunar';
        }
        if (strpos($f, 'trimes') !== false || strpos($f, '3 luni') !== false || strpos($f, 'trei luni') !== false || strpos($f, 'quarter') !== false) {
            return 'trimestrial';
        }
        if (strpos($f, 'semes') !== false || strpos($f, '6 luni') !== false || strpos($f, 'sase luni') !== false) {
            return 'semestrial';
        }
        if (strpos($f, 'unic') !== false || strpos($f, 'singur') !== false || strpos($f, 'o singura') !== false || strpos($f, 'int') !== false || strpos($f, 'one') !== false) {
            return 'int_unica';
        }

        return 'int_unica';
    }
}

if (!function_exists('pz_flow_frequency_label')) {
    function pz_flow_frequency_label(string $frequency): string
    {
        $key = pz_flow_normalize_frequency($frequency);
        if ($key === 'lunar') {
            return 'Lunar';
        }
        if ($key === 'trimestrial') {
            return 'Trimestrial';
        }
        if ($key === 'semestrial') {
            return 'Semestrial';
        }
        return 'Int. unica';
    }
}

if (!function_exists('pz_flow_guess_recurrence')) {
    function pz_flow_guess_recurrence(string $frequency, ?string $startDate = null, ?string $endDate = null): array
    {
        $key = pz_flow_normalize_frequency($frequency);

        if ($key === 'lunar') {
            return ['monthly', null, 12];
        }
        if ($key === 'trimestrial') {
            return ['three_months', null, 4];
        }
        if ($key === 'semestrial') {
            return ['six_months', null, 2];
        }

        return ['none', null, 1];
    }
}

if (!function_exists('pz_flow_location_snapshot')) {
    function pz_flow_location_snapshot(PDO $pdo, ?int $locationId): array
    {
        if (!$locationId || !pz_flow_table_exists($pdo, 'client_locations')) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM client_locations WHERE id = ? LIMIT 1');
        $stmt->execute([$locationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('pz_flow_sync_issued_contract')) {
    function pz_flow_sync_issued_contract(PDO $pdo, int $documentId, bool $throwErrors = false): ?int
    {
        $GLOBALS['pz_flow_last_sync'] = [
            'document_id' => $documentId,
            'items' => 0,
            'services' => 0,
            'tasks' => 0,
            'error' => '',
        ];

        try {
            if ($documentId <= 0 || !function_exists('pzdoc_get_document')) {
                return null;
            }

            pz_flow_ensure_schema($pdo);

            $document = pzdoc_get_document($pdo, $documentId, true);
        if (!$document || ($document['document_type'] ?? '') !== 'contract') {
            return null;
        }

        $payload = function_exists('pzdoc_json_decode') ? pzdoc_json_decode($document['payload_json'] ?? null) : [];
        $clientId = (int)($document['client_id'] ?? 0);
        if ($clientId <= 0) {
            return null;
        }

        $contractNumber = pz_flow_str(($document['document_number'] ?? '') ?: ('DOC-' . $documentId), 120);
        $contractDate = pz_flow_date_or_null($document['document_date'] ?? null) ?: date('Y-m-d');
        $startDate = pz_flow_date_or_null($payload['contract_start_date'] ?? null) ?: $contractDate;
        $endDate = pz_flow_date_or_null($payload['contract_end_date'] ?? null);
        $totalValue = (float)($document['subtotal'] ?? $document['total_amount'] ?? 0);
        $title = pz_flow_str($document['title'] ?? 'Contract DDD', 180);
        $autoRenewal = !empty($payload['auto_renewal']) ? 1 : 0;

        $stmt = $pdo->prepare('SELECT id FROM contracts WHERE source_document_id = ? LIMIT 1');
        $stmt->execute([$documentId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $contractId = (int)$existing['id'];
            $upd = $pdo->prepare('UPDATE contracts SET client_id = ?, contract_number = ?, contract_date = ?, start_date = ?, end_date = ?, status = ?, estimated_value = ?, total_value = ?, document_series_id = ?, document_number_id = ?, issued_at = ?, issued_by = ?, title = ?, auto_renewal = ? WHERE id = ?');
            $upd->execute([
                $clientId,
                $contractNumber,
                $contractDate,
                $startDate,
                $endDate,
                'activ',
                $totalValue,
                $totalValue,
                !empty($document['document_series_id']) ? (int)$document['document_series_id'] : null,
                !empty($document['document_number_id']) ? (int)$document['document_number_id'] : null,
                $document['issued_at'] ?? date('Y-m-d H:i:s'),
                !empty($document['issued_by']) ? (int)$document['issued_by'] : null,
                $title,
                $autoRenewal,
                $contractId,
            ]);
        } else {
            $ins = $pdo->prepare('INSERT INTO contracts (client_id, contract_number, contract_date, start_date, end_date, status, estimated_value, total_value, document_series_id, document_number_id, issued_at, issued_by, title, auto_renewal, source_document_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([
                $clientId,
                $contractNumber,
                $contractDate,
                $startDate,
                $endDate,
                'activ',
                $totalValue,
                $totalValue,
                !empty($document['document_series_id']) ? (int)$document['document_series_id'] : null,
                !empty($document['document_number_id']) ? (int)$document['document_number_id'] : null,
                $document['issued_at'] ?? date('Y-m-d H:i:s'),
                !empty($document['issued_by']) ? (int)$document['issued_by'] : null,
                $title,
                $autoRenewal,
                $documentId,
            ]);
            $contractId = (int)$pdo->lastInsertId();
        }

        $items = is_array($document['items'] ?? null) ? $document['items'] : [];
        if (!$items && function_exists('pzdoc_get_document_items')) {
            $items = pzdoc_get_document_items($pdo, $documentId);
        }
        $GLOBALS['pz_flow_last_sync']['items'] = count($items);
        foreach ($items as $item) {
            $itemId = (int)($item['id'] ?? 0);
            $serviceName = pz_flow_str($item['service_name'] ?? '', 255);
            if ($serviceName === '') {
                continue;
            }

            $locationId = !empty($item['client_location_id']) ? (int)$item['client_location_id'] : null;
            $location = pz_flow_location_snapshot($pdo, $locationId);
            $locationName = pz_flow_str(($item['location_name'] ?? '') ?: ($location['location_name'] ?? ''), 220);
            $locationAddress = pz_flow_str(($item['location_address'] ?? '') ?: ($location['address'] ?? ''));
            $locationContact = pz_flow_str($location['contact_person'] ?? '', 220);
            $locationPhone = pz_flow_str($location['phone'] ?? '', 80);
            $surfaceValue = is_numeric($item['quantity'] ?? null) ? (float)$item['quantity'] : null;
            $surfaceUnit = pz_flow_str($item['unit'] ?? 'mp', 30) ?: 'mp';
            $price = is_numeric($item['unit_price'] ?? null) ? (float)$item['unit_price'] : 0.0;
            $currency = pz_flow_str($item['currency'] ?? ($document['currency'] ?? 'RON'), 10) ?: 'RON';
            $frequencyKey = pz_flow_normalize_frequency(pz_flow_str($item['frequency_text'] ?? 'int_unica', 120));
            $frequency = pz_flow_frequency_label($frequencyKey);
            $plannedDate = pz_flow_date_or_null($item['planned_date'] ?? null) ?: $startDate;
            [$recurrenceType, $recurrenceDays, $recurrenceTotal] = pz_flow_guess_recurrence($frequencyKey, $plannedDate, $endDate ?: null);

            $stmt = $pdo->prepare('SELECT id, task_id FROM contract_services WHERE document_id = ? AND document_item_id = ? LIMIT 1');
            $stmt->execute([$documentId, $itemId]);
            $existingService = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingService) {
                $contractServiceId = (int)$existingService['id'];
                $upd = $pdo->prepare('UPDATE contract_services SET contract_id = ?, client_id = ?, client_location_id = ?, service_id = ?, service_name = ?, frequency = ?, planned_date = ?, price = ?, currency = ?, location_name = ?, location_address = ?, location_contact_person = ?, location_contact_phone = ?, surface_value = ?, surface_unit = ?, recurrence_type = ?, recurrence_days = ?, recurrence_total = ?, status = IF(status IN (\'programat\', \'finalizat\'), status, \'neprogramat\') WHERE id = ?');
                $upd->execute([
                    $contractId,
                    $clientId,
                    $locationId,
                    !empty($item['service_id']) ? (int)$item['service_id'] : null,
                    $serviceName,
                    $frequency ?: null,
                    $plannedDate,
                    $price,
                    $currency,
                    $locationName ?: null,
                    $locationAddress ?: null,
                    $locationContact ?: null,
                    $locationPhone ?: null,
                    $surfaceValue,
                    $surfaceUnit,
                    $recurrenceType,
                    $recurrenceDays,
                    $recurrenceTotal,
                    $contractServiceId,
                ]);
            } else {
                $ins = $pdo->prepare('INSERT INTO contract_services (contract_id, client_id, client_location_id, service_id, service_name, frequency, planned_date, price, currency, document_id, document_item_id, location_name, location_address, location_contact_person, location_contact_phone, surface_value, surface_unit, recurrence_type, recurrence_days, recurrence_total, task_generation_mode, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'auto_task\', \'neprogramat\')');
                $ins->execute([
                    $contractId,
                    $clientId,
                    $locationId,
                    !empty($item['service_id']) ? (int)$item['service_id'] : null,
                    $serviceName,
                    $frequency ?: null,
                    $plannedDate,
                    $price,
                    $currency,
                    $documentId,
                    $itemId ?: null,
                    $locationName ?: null,
                    $locationAddress ?: null,
                    $locationContact ?: null,
                    $locationPhone ?: null,
                    $surfaceValue,
                    $surfaceUnit,
                    $recurrenceType,
                    $recurrenceDays,
                    $recurrenceTotal,
                ]);
                $contractServiceId = (int)$pdo->lastInsertId();
            }

            $GLOBALS['pz_flow_last_sync']['services']++;
            $createdTaskId = pz_flow_ensure_task_for_contract_service($pdo, $contractServiceId, $contractId, $clientId, $locationId, [
                'service_id' => !empty($item['service_id']) ? (int)$item['service_id'] : null,
                'service_name' => $serviceName,
                'location_name' => $locationName,
                'address' => $locationAddress,
                'contact_person' => $locationContact,
                'contact_phone' => $locationPhone,
                'surface_value' => $surfaceValue,
                'surface_unit' => $surfaceUnit,
                'billing_amount' => $price,
                'currency' => $currency,
                'document_id' => $documentId,
                'document_item_id' => $itemId ?: null,
                'due_date' => $plannedDate,
                'recurrence_type' => $recurrenceType,
                'recurrence_days' => $recurrenceDays,
                'recurrence_total' => $recurrenceTotal,
                'frequency' => $frequency,
            ]);
            if ($createdTaskId) {
                $GLOBALS['pz_flow_last_sync']['tasks']++;
            }
        }

        try {
            $updDoc = $pdo->prepare('UPDATE documents SET contract_id = ? WHERE id = ?');
            $updDoc->execute([$contractId, $documentId]);
        } catch (Throwable $e) {
            error_log('PestZone flow document contract link error: ' . $e->getMessage());
        }

        return $contractId;
        } catch (Throwable $e) {
            $GLOBALS['pz_flow_last_sync']['error'] = $e->getMessage();
            error_log('PestZone contract flow sync error: document ' . $documentId . ' - ' . $e->getMessage());
            if ($throwErrors) {
                throw $e;
            }
            return null;
        }
    }
}


if (!function_exists('pz_flow_last_sync_stats')) {
    function pz_flow_last_sync_stats(): array
    {
        return is_array($GLOBALS['pz_flow_last_sync'] ?? null) ? $GLOBALS['pz_flow_last_sync'] : [];
    }
}

if (!function_exists('pz_flow_ensure_task_for_contract_service')) {
    function pz_flow_ensure_task_for_contract_service(PDO $pdo, int $contractServiceId, int $contractId, int $clientId, ?int $locationId, array $data): ?int
    {
        if ($contractServiceId <= 0 || $contractId <= 0 || $clientId <= 0 || !pz_flow_table_exists($pdo, 'tasks')) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id FROM tasks WHERE contract_service_id = ? AND generated_from_task_id IS NULL LIMIT 1');
        $stmt->execute([$contractServiceId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $serviceName = pz_flow_str($data['service_name'] ?? '', 150);
        if ($serviceName === '') {
            $serviceName = 'Serviciu contractat';
        }
        $title = pz_flow_str($serviceName . ' - contract', 255);
        $dueDate = pz_flow_date_or_null($data['due_date'] ?? null) ?: date('Y-m-d');
        $recurrenceType = pz_flow_str($data['recurrence_type'] ?? 'none', 40) ?: 'none';
        $recurrenceDays = !empty($data['recurrence_days']) ? (int)$data['recurrence_days'] : null;
        $recurrenceTotal = max(1, (int)($data['recurrence_total'] ?? 1));
        $notes = pz_flow_str('Generata din contract. Frecvență: ' . (($data['frequency'] ?? '') ?: '-'));

        if ($existing) {
            $taskId = (int)$existing['id'];
            $upd = $pdo->prepare('UPDATE tasks SET client_id = ?, client_location_id = ?, title = ?, service_type = ?, address = ?, contact_person = ?, contact_phone = ?, location_name = ?, service_id = ?, surface_value = ?, surface_unit = ?, billing_amount = ?, currency = ?, document_id = ?, document_item_id = ?, contract_id = ?, due_date = ?, recurrence_type = ?, recurrence_days = ?, recurrence_total = ?, recurrence_remaining = GREATEST(recurrence_remaining, ?), notes = COALESCE(notes, ?) WHERE id = ? AND status != \'programat\'');
            $upd->execute([
                $clientId,
                $locationId,
                $title,
                $serviceName,
                pz_flow_str($data['address'] ?? '') ?: null,
                pz_flow_str($data['contact_person'] ?? '') ?: null,
                pz_flow_str($data['contact_phone'] ?? '') ?: null,
                pz_flow_str($data['location_name'] ?? '', 220) ?: null,
                !empty($data['service_id']) ? (int)$data['service_id'] : null,
                $data['surface_value'] ?? null,
                pz_flow_str($data['surface_unit'] ?? 'mp', 30) ?: 'mp',
                is_numeric($data['billing_amount'] ?? null) ? (float)$data['billing_amount'] : 0.0,
                pz_flow_str($data['currency'] ?? 'RON', 10) ?: 'RON',
                !empty($data['document_id']) ? (int)$data['document_id'] : null,
                !empty($data['document_item_id']) ? (int)$data['document_item_id'] : null,
                $contractId,
                $dueDate,
                $recurrenceType,
                $recurrenceDays,
                $recurrenceTotal,
                $recurrenceTotal,
                $notes,
                $taskId,
            ]);
        } else {
            $group = function_exists('make_task_recurrence_group') ? make_task_recurrence_group() : uniqid('task_', true);
            $ins = $pdo->prepare('INSERT INTO tasks (client_id, client_location_id, title, service_type, address, contact_person, contact_phone, location_name, service_id, surface_value, surface_unit, billing_amount, currency, document_id, document_item_id, due_date, recurrence_type, recurrence_days, recurrence_group, recurrence_total, recurrence_remaining, recurrence_stopped, recurrence_index, generated_from_task_id, generated_next_task_id, status, appointment_id, contract_id, contract_service_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NULL, NULL, \'de_programat\', NULL, ?, ?, ?)');
            $ins->execute([
                $clientId,
                $locationId,
                $title,
                $serviceName,
                pz_flow_str($data['address'] ?? '') ?: null,
                pz_flow_str($data['contact_person'] ?? '') ?: null,
                pz_flow_str($data['contact_phone'] ?? '') ?: null,
                pz_flow_str($data['location_name'] ?? '', 220) ?: null,
                !empty($data['service_id']) ? (int)$data['service_id'] : null,
                $data['surface_value'] ?? null,
                pz_flow_str($data['surface_unit'] ?? 'mp', 30) ?: 'mp',
                is_numeric($data['billing_amount'] ?? null) ? (float)$data['billing_amount'] : 0.0,
                pz_flow_str($data['currency'] ?? 'RON', 10) ?: 'RON',
                !empty($data['document_id']) ? (int)$data['document_id'] : null,
                !empty($data['document_item_id']) ? (int)$data['document_item_id'] : null,
                $dueDate,
                $recurrenceType,
                $recurrenceDays,
                $group,
                $recurrenceTotal,
                $recurrenceTotal,
                $contractId,
                $contractServiceId,
                $notes,
            ]);
            $taskId = (int)$pdo->lastInsertId();
        }

        $updService = $pdo->prepare('UPDATE contract_services SET task_id = ?, status = IF(status IN (\'programat\', \'finalizat\'), status, \'neprogramat\') WHERE id = ?');
        $updService->execute([$taskId, $contractServiceId]);

        return $taskId;
    }
}
