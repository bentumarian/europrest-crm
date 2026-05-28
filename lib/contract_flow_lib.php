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
            error_log('Emma flow add column error: ' . $table . '.' . $column . ' - ' . $e->getMessage());
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
            error_log('Emma flow add index error: ' . $table . '.' . $index . ' - ' . $e->getMessage());
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
    /**
     * Calculează tipul de recurență și numărul total de sarcini generate pentru un
     * serviciu contractat. Rezultatul ține cont de start_date si end_date al
     * contractului:
     *   - daca avem start si end: calculam numarul de luni intre ele si impartim
     *     la intervalul frecventei (1 / 3 / 6);
     *   - daca lipseste end_date (contract pe durata nedeterminata): plafon de 12
     *     luni inainte (lunar=12, trimestrial=4, semestrial=2);
     *   - plafon de siguranta de 120 luni (10 ani lunar) ca sa nu generam un numar
     *     accidental urias daca cineva introduce date gresite.
     */
    function pz_flow_guess_recurrence(string $frequency, ?string $startDate = null, ?string $endDate = null): array
    {
        $key = pz_flow_normalize_frequency($frequency);

        if ($key === 'int_unica') {
            return ['none', null, 1];
        }

        // Plafon implicit cand nu avem end_date (contract pe durata nedeterminata).
        $months = 12;

        $startTs = $startDate ? strtotime($startDate) : false;
        $endTs = $endDate ? strtotime($endDate) : false;
        if ($startTs && $endTs && $endTs >= $startTs) {
            try {
                $startDt = (new DateTime())->setTimestamp($startTs);
                $endDt = (new DateTime())->setTimestamp($endTs);
                $diff = $startDt->diff($endDt);
                $months = ($diff->y * 12) + $diff->m + ($diff->d > 0 ? 1 : 0);
            } catch (Throwable $e) {
                $months = 12;
            }
        }

        // Plafon de siguranta: maxim 120 luni (10 ani lunar) ca sa evitam recurente
        // explozive in caz de date eronate.
        $months = max(1, min($months, 120));

        if ($key === 'lunar') {
            return ['monthly', null, $months];
        }
        if ($key === 'trimestrial') {
            return ['three_months', null, max(1, (int)ceil($months / 3))];
        }
        if ($key === 'semestrial') {
            return ['six_months', null, max(1, (int)ceil($months / 6))];
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
            if (($item['item_type'] ?? '') !== 'contract_service') {
                continue;
            }
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
                $upd = $pdo->prepare('UPDATE contract_services SET contract_id = ?, client_id = ?, client_location_id = ?, service_id = ?, service_name = ?, frequency = ?, planned_date = ?, price = ?, currency = ?, location_name = ?, location_address = ?, location_contact_person = ?, location_contact_phone = ?, surface_value = ?, surface_unit = ?, recurrence_type = ?, recurrence_days = ?, recurrence_total = ?, status = IF(status IN (\'programat\', \'finalizat\', \'executat\'), status, \'neprogramat\') WHERE id = ?');
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
            error_log('Emma flow document contract link error: ' . $e->getMessage());
        }

        return $contractId;
        } catch (Throwable $e) {
            $GLOBALS['pz_flow_last_sync']['error'] = $e->getMessage();
            error_log('Emma contract flow sync error: document ' . $documentId . ' - ' . $e->getMessage());
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

        $stmt = $pdo->prepare('SELECT id, status, recurrence_total, recurrence_remaining FROM tasks WHERE contract_service_id = ? AND generated_from_task_id IS NULL LIMIT 1');
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
            $oldTotal = max(1, (int)($existing['recurrence_total'] ?? 1));
            $oldRemaining = max(1, (int)($existing['recurrence_remaining'] ?? 1));
            $alreadyConsumed = max(0, $oldTotal - $oldRemaining);
            $newRemaining = $recurrenceType === 'none' ? 1 : max(1, $recurrenceTotal - $alreadyConsumed);

            // Recurența trebuie sincronizată inclusiv dacă sarcina a fost deja programată.
            // Altfel un contract reemis / actualizat poate rămâne afișat ca 1/1 în task.
            $updRecurrence = $pdo->prepare('UPDATE tasks SET document_id = ?, document_item_id = ?, contract_id = ?, recurrence_type = ?, recurrence_days = ?, recurrence_total = ?, recurrence_remaining = ?, notes = COALESCE(notes, ?) WHERE id = ?');
            $updRecurrence->execute([
                !empty($data['document_id']) ? (int)$data['document_id'] : null,
                !empty($data['document_item_id']) ? (int)$data['document_item_id'] : null,
                $contractId,
                $recurrenceType,
                $recurrenceDays,
                $recurrenceTotal,
                $newRemaining,
                $notes,
                $taskId,
            ]);

            $upd = $pdo->prepare('UPDATE tasks SET client_id = ?, client_location_id = ?, title = ?, service_type = ?, address = ?, contact_person = ?, contact_phone = ?, location_name = ?, service_id = ?, surface_value = ?, surface_unit = ?, billing_amount = ?, currency = ?, due_date = ? WHERE id = ? AND status != \'programat\'');
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
                $dueDate,
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

        $updService = $pdo->prepare('UPDATE contract_services SET task_id = ?, status = IF(status IN (\'programat\', \'finalizat\', \'executat\'), status, \'neprogramat\') WHERE id = ?');
        $updService->execute([$taskId, $contractServiceId]);

        return $taskId;
    }
}

if (!function_exists('pz_flow_sync_issued_addendum')) {
    /**
     * Sincronizare la emiterea unui act adițional:
     *   1. Extinde contracts.end_date la addendum_end_date (daca e mai mare).
     *   2. Pentru fiecare serviciu din actul adițional:
     *      a. Identifica contract_service-ul existent (match dupa location_id + service_id).
     *      b. Calculeaza cate intervale noi incap in perioada actului (lunar, trim., sem.).
     *      c. Bumpeaza recurrence_total pe contract_services si pe toate sarcinile din serie.
     *      d. Daca ultima sarcina din serie este inca "vie" (status de_programat / programat) →
     *         mareste recurrence_remaining pe ea, astfel incat mecanismul rolling sa genereze
     *         sarcinile noi pe rand la finalizare (varianta aleasa de utilizator).
     *      e. Daca seria este complet finalizata/anulata → creeaza o sarcina noua
     *         de_programat cu due_date = addendum_start_date, legata de ultima prin
     *         generated_from_task_id.
     */
    function pz_flow_sync_issued_addendum(PDO $pdo, int $documentId, bool $throwErrors = false): ?int
    {
        $GLOBALS['pz_flow_last_addendum_sync'] = [
            'document_id' => $documentId,
            'extended_services' => 0,
            'updated_tasks' => 0,
            'new_tasks' => 0,
            'error' => '',
        ];

        try {
            if ($documentId <= 0 || !function_exists('pzdoc_get_document')) {
                return null;
            }

            pz_flow_ensure_schema($pdo);

            $document = pzdoc_get_document($pdo, $documentId, true);
            if (!$document || ($document['document_type'] ?? '') !== 'act_aditional') {
                return null;
            }

            $payload = function_exists('pzdoc_json_decode') ? pzdoc_json_decode($document['payload_json'] ?? null) : [];
            $parentDocumentId = (int)($document['source_document_id'] ?? ($payload['parent_document_id'] ?? 0));
            if ($parentDocumentId <= 0) {
                return null;
            }

            $addendumStart = pz_flow_date_or_null($payload['addendum_start_date'] ?? null);
            $addendumEnd = pz_flow_date_or_null($payload['addendum_end_date'] ?? null);
            if (!$addendumStart || !$addendumEnd) {
                return null;
            }

            // Identific contract_id operational (tabela `contracts`) legat de documentul-mama.
            $stmt = $pdo->prepare('SELECT id, end_date, client_id FROM contracts WHERE source_document_id = ? LIMIT 1');
            $stmt->execute([$parentDocumentId]);
            $contractRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$contractRow) {
                return null;
            }
            $contractId = (int)$contractRow['id'];
            $clientId = (int)($contractRow['client_id'] ?? ($document['client_id'] ?? 0));

            // 1) Extindem end_date la addendum_end_date, dar nu micsoram daca e deja mai mare.
            $newEndDate = $addendumEnd;
            if (!empty($contractRow['end_date']) && $contractRow['end_date'] > $addendumEnd) {
                $newEndDate = $contractRow['end_date'];
            }
            $updEnd = $pdo->prepare('UPDATE contracts SET end_date = ? WHERE id = ?');
            $updEnd->execute([$newEndDate, $contractId]);

            // 2) Iteram serviciile actului adițional
            $items = is_array($document['items'] ?? null) ? $document['items'] : [];
            foreach ($items as $item) {
                $locationId = !empty($item['client_location_id']) ? (int)$item['client_location_id'] : null;
                $serviceId = !empty($item['service_id']) ? (int)$item['service_id'] : null;
                $serviceName = pz_flow_str($item['service_name'] ?? '', 255);
                $frequency = pz_flow_str($item['frequency_text'] ?? 'int_unica', 120);
                $frequencyKey = pz_flow_normalize_frequency($frequency);

                [$recurrenceType, $recurrenceDays, $additionalTotal] = pz_flow_guess_recurrence($frequencyKey, $addendumStart, $addendumEnd);
                if ($additionalTotal < 1) {
                    continue;
                }

                // 2a) Identific contract_service - match pe (contractId, locationId, serviceId).
                // Fallback: daca service_id e null, match doar pe location_id si nume serviciu.
                if ($serviceId) {
                    $svcStmt = $pdo->prepare('SELECT id, recurrence_total FROM contract_services WHERE contract_id = ? AND client_location_id <=> ? AND service_id <=> ? ORDER BY id ASC LIMIT 1');
                    $svcStmt->execute([$contractId, $locationId, $serviceId]);
                } else {
                    $svcStmt = $pdo->prepare('SELECT id, recurrence_total FROM contract_services WHERE contract_id = ? AND client_location_id <=> ? AND service_name = ? ORDER BY id ASC LIMIT 1');
                    $svcStmt->execute([$contractId, $locationId, $serviceName]);
                }
                $contractService = $svcStmt->fetch(PDO::FETCH_ASSOC);
                if (!$contractService) {
                    continue;
                }
                $contractServiceId = (int)$contractService['id'];

                // Date economice / cantitative din actul adițional, care trebuie
                // propagate pe sarcinile viitoare (acopera cazul "Preț actualizat").
                $itemPrice = is_numeric($item['unit_price'] ?? null) ? (float)$item['unit_price'] : 0.0;
                $itemSurface = is_numeric($item['quantity'] ?? null) ? (float)$item['quantity'] : null;
                $itemSurfaceUnit = pz_flow_str($item['unit'] ?? 'mp', 30) ?: 'mp';
                $itemCurrency = pz_flow_str($item['currency'] ?? ($document['currency'] ?? 'RON'), 10) ?: 'RON';

                // 2b) Bumpez recurrence_total pe contract_services + sincronizez pret/cantitate.
                $newTotal = (int)$contractService['recurrence_total'] + $additionalTotal;
                $updCs = $pdo->prepare('UPDATE contract_services SET recurrence_total = ?, price = ?, currency = ?, surface_value = ?, surface_unit = ?, status = IF(status = \'finalizat\', \'neprogramat\', status) WHERE id = ?');
                $updCs->execute([$newTotal, $itemPrice, $itemCurrency, $itemSurface, $itemSurfaceUnit, $contractServiceId]);

                // 2c) Bumpez recurrence_total pe TOATE sarcinile din serie (istoric + viitor).
                $updAllTasks = $pdo->prepare('UPDATE tasks SET recurrence_total = ? WHERE contract_service_id = ?');
                $updAllTasks->execute([$newTotal, $contractServiceId]);

                // 2c-bis) Actualizez pret / cantitate DOAR pe sarcinile vii (nu atingem
                // istoricul facturat pe sarcinile finalizate / anulate). Rolling-ul va
                // copia aceste valori in sarcinile noi generate la finalizare.
                $updLiveTasks = $pdo->prepare('UPDATE tasks SET billing_amount = ?, currency = ?, surface_value = ?, surface_unit = ? WHERE contract_service_id = ? AND status NOT IN (\'finalizat\', \'anulat\')');
                $updLiveTasks->execute([$itemPrice, $itemCurrency, $itemSurface, $itemSurfaceUnit, $contractServiceId]);

                // 2d/2e) Caut ultima sarcina din serie
                $taskStmt = $pdo->prepare('SELECT id, status, recurrence_index, recurrence_remaining, due_date FROM tasks WHERE contract_service_id = ? ORDER BY recurrence_index DESC, id DESC LIMIT 1');
                $taskStmt->execute([$contractServiceId]);
                $lastTask = $taskStmt->fetch(PDO::FETCH_ASSOC);

                $closedStatuses = ['finalizat', 'anulat'];
                if ($lastTask && !in_array((string)$lastTask['status'], $closedStatuses, true)) {
                    // 2d) Sarcina inca vie - bumpez remaining + asigur recurrence_type valid
                    $newRemaining = max(0, (int)$lastTask['recurrence_remaining']) + $additionalTotal;
                    $updLast = $pdo->prepare('UPDATE tasks SET recurrence_remaining = ?, recurrence_type = ?, recurrence_days = ?, recurrence_stopped = 0 WHERE id = ?');
                    $updLast->execute([$newRemaining, $recurrenceType, $recurrenceDays, (int)$lastTask['id']]);
                    $GLOBALS['pz_flow_last_addendum_sync']['updated_tasks']++;
                } else {
                    // 2e) Seria e inchisa sau nu exista - cream o sarcina noua "vie"
                    $group = function_exists('make_task_recurrence_group') ? make_task_recurrence_group() : uniqid('task_', true);
                    $previousId = $lastTask ? (int)$lastTask['id'] : null;
                    $nextIndex = $lastTask ? ((int)$lastTask['recurrence_index'] + 1) : 1;
                    $locationName = pz_flow_str($item['location_name'] ?? '', 220);
                    $locationAddress = pz_flow_str($item['location_address'] ?? '');

                    // Preluam contact_person / contact_phone din client_locations (la fel
                    // ca pz_flow_sync_issued_contract). Daca lipseste, lasam NULL.
                    $locSnapshot = pz_flow_location_snapshot($pdo, $locationId);
                    $contactPerson = pz_flow_str($locSnapshot['contact_person'] ?? '', 220);
                    $contactPhone = pz_flow_str($locSnapshot['phone'] ?? '', 80);
                    if ($locationName === '') {
                        $locationName = pz_flow_str($locSnapshot['location_name'] ?? '', 220);
                    }
                    if ($locationAddress === '') {
                        $locationAddress = pz_flow_str($locSnapshot['address'] ?? '');
                    }

                    $title = pz_flow_str(($serviceName ?: 'Serviciu contractat') . ' - act adițional', 255);
                    $notes = 'Generata din act adițional. Frecvență: ' . pz_flow_frequency_label($frequencyKey);

                    $ins = $pdo->prepare('INSERT INTO tasks (client_id, client_location_id, title, service_type, address, contact_person, contact_phone, location_name, service_id, surface_value, surface_unit, billing_amount, currency, document_id, document_item_id, due_date, recurrence_type, recurrence_days, recurrence_group, recurrence_total, recurrence_remaining, recurrence_stopped, recurrence_index, generated_from_task_id, generated_next_task_id, status, appointment_id, contract_id, contract_service_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NULL, \'de_programat\', NULL, ?, ?, ?)');
                    $ins->execute([
                        $clientId,
                        $locationId,
                        $title,
                        $serviceName ?: 'Serviciu contractat',
                        $locationAddress ?: null,
                        $contactPerson ?: null,
                        $contactPhone ?: null,
                        $locationName ?: null,
                        $serviceId,
                        $itemSurface,
                        $itemSurfaceUnit,
                        $itemPrice,
                        $itemCurrency,
                        $documentId,
                        !empty($item['id']) ? (int)$item['id'] : null,
                        $addendumStart,
                        $recurrenceType,
                        $recurrenceDays,
                        $group,
                        $additionalTotal,
                        $additionalTotal,
                        $nextIndex,
                        $previousId,
                        $contractId,
                        $contractServiceId,
                        $notes,
                    ]);
                    $newTaskId = (int)$pdo->lastInsertId();

                    // Daca exista sarcina anterioara, leag-o de cea noua pentru lant.
                    if ($previousId) {
                        $linkPrev = $pdo->prepare('UPDATE tasks SET generated_next_task_id = ? WHERE id = ?');
                        $linkPrev->execute([$newTaskId, $previousId]);
                    }

                    // Updatez contract_services.task_id daca era gol sau pointa la o sarcina finalizata
                    $updSvcLink = $pdo->prepare('UPDATE contract_services SET task_id = ?, status = \'neprogramat\' WHERE id = ? AND (task_id IS NULL OR task_id = ?)');
                    $updSvcLink->execute([$newTaskId, $contractServiceId, $previousId ?: 0]);

                    $GLOBALS['pz_flow_last_addendum_sync']['new_tasks']++;
                }

                $GLOBALS['pz_flow_last_addendum_sync']['extended_services']++;
            }

            // Link documentul act adițional la contract_id pentru rapoarte/filtre
            try {
                $linkUpd = $pdo->prepare('UPDATE documents SET contract_id = ? WHERE id = ?');
                $linkUpd->execute([$contractId, $documentId]);
            } catch (Throwable $e) {
                error_log('Emma addendum document link error: ' . $e->getMessage());
            }

            return $contractId;
        } catch (Throwable $e) {
            $GLOBALS['pz_flow_last_addendum_sync']['error'] = $e->getMessage();
            error_log('Emma addendum sync error: document ' . $documentId . ' - ' . $e->getMessage());
            if ($throwErrors) {
                throw $e;
            }
            return null;
        }
    }
}

if (!function_exists('pz_flow_last_addendum_sync_stats')) {
    function pz_flow_last_addendum_sync_stats(): array
    {
        return is_array($GLOBALS['pz_flow_last_addendum_sync'] ?? null) ? $GLOBALS['pz_flow_last_addendum_sync'] : [];
    }
}
