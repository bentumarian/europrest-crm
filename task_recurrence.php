<?php

/*
|--------------------------------------------------------------------------
| Task Recurrence Engine - Emma
|--------------------------------------------------------------------------
| Motor centralizat pentru sarcini recurente.
| Compatibil cu MariaDB / cPanel.
|
| V4:
| - pastreaza client_location_id la sarcinile recurente
| - pastreaza address, contact_person si contact_phone
| - urmatoarea sarcina generata rămâne pe aceeași locație / punct de lucru
| - dacă sarcina este pe sediu social / domiciliu, client_location_id rămâne NULL
|--------------------------------------------------------------------------
*/

if (!function_exists('task_recurrence_column_exists')) {
    function task_recurrence_column_exists(PDO $pdo, string $table, string $column): bool
    {
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
}

if (!function_exists('task_recurrence_index_exists')) {
    function task_recurrence_index_exists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ");

        $stmt->execute([$table, $index]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('ensure_task_recurrence_schema')) {
    function ensure_task_recurrence_schema(PDO $pdo): void
    {
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
                contract_id INT NULL,
                contract_service_id INT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $columns = [
            'client_id'              => "INT NULL",
            'client_location_id'     => "INT NULL",
            'title'                  => "VARCHAR(255) NULL",
            'service_type'           => "VARCHAR(150) NULL",
            'address'                => "VARCHAR(255) NULL",
            'contact_person'         => "VARCHAR(180) NULL",
            'contact_phone'          => "VARCHAR(60) NULL",
            'due_date'               => "DATE NOT NULL",
            'recurrence_type'        => "VARCHAR(40) NOT NULL DEFAULT 'none'",
            'recurrence_days'        => "INT NULL",
            'recurrence_group'       => "VARCHAR(80) NULL",
            'recurrence_total'       => "INT NOT NULL DEFAULT 1",
            'recurrence_remaining'   => "INT NOT NULL DEFAULT 1",
            'recurrence_stopped'     => "TINYINT(1) NOT NULL DEFAULT 0",
            'recurrence_index'       => "INT NOT NULL DEFAULT 1",
            'generated_from_task_id' => "INT NULL",
            'generated_next_task_id' => "INT NULL",
            'status'                 => "VARCHAR(40) NOT NULL DEFAULT 'de_programat'",
            'appointment_id'         => "INT NULL",
            'contract_id'            => "INT NULL",
            'contract_service_id'    => "INT NULL",
            'service_id'              => "INT NULL",
            'location_name'          => "VARCHAR(220) NULL",
            'surface_value'          => "DECIMAL(14,3) NULL",
            'surface_unit'           => "VARCHAR(30) NULL",
            'billing_amount'         => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
            'currency'               => "VARCHAR(10) NOT NULL DEFAULT 'RON'",
            'document_id'            => "INT NULL",
            'document_item_id'       => "INT NULL",
            'notes'                  => "TEXT NULL",
            'created_at'             => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        ];

        foreach ($columns as $column => $definition) {
            if (!task_recurrence_column_exists($pdo, 'tasks', $column)) {
                try {
                    $pdo->exec("ALTER TABLE tasks ADD COLUMN {$column} {$definition}");
                } catch (Throwable $e) {
                    // Nu blocam aplicatia dacă o coloana există deja sau hostingul refuza comanda.
                }
            }
        }

        $indexes = [
            'idx_tasks_recurrence_group'          => "CREATE INDEX idx_tasks_recurrence_group ON tasks (recurrence_group)",
            'idx_tasks_generated_from'            => "CREATE INDEX idx_tasks_generated_from ON tasks (generated_from_task_id)",
            'idx_tasks_generated_next'            => "CREATE INDEX idx_tasks_generated_next ON tasks (generated_next_task_id)",
            'idx_tasks_due_status'                => "CREATE INDEX idx_tasks_due_status ON tasks (due_date, status)",
            'idx_tasks_recurrence_index'          => "CREATE INDEX idx_tasks_recurrence_index ON tasks (recurrence_group, recurrence_index)",
            'idx_tasks_client_location'           => "CREATE INDEX idx_tasks_client_location ON tasks (client_id, client_location_id)",
            'idx_tasks_contract'                  => "CREATE INDEX idx_tasks_contract ON tasks (contract_id, contract_service_id)",
            'idx_tasks_client_location_contact'   => "CREATE INDEX idx_tasks_client_location_contact ON tasks (client_id, client_location_id, contact_phone)",
        ];

        foreach ($indexes as $indexName => $sql) {
            if (!task_recurrence_index_exists($pdo, 'tasks', $indexName)) {
                try {
                    $pdo->exec($sql);
                } catch (Throwable $e) {
                    // Nu blocam aplicatia dacă indexul există deja sau nu poate fi creat.
                }
            }
        }
    }
}

if (!function_exists('make_task_recurrence_group')) {
    function make_task_recurrence_group(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            return uniqid('task_', true);
        }
    }
}

if (!function_exists('next_task_due_date')) {
    function next_task_due_date(string $date, string $type, ?int $days): ?string
    {
        if ($type === 'none' || $type === '') {
            return null;
        }

        $d = DateTime::createFromFormat('Y-m-d', $date);

        if (!$d) {
            return null;
        }

        if ($type === 'days') {
            $d->modify('+' . max(1, (int)$days) . ' days');
        } elseif ($type === 'weekly') {
            $d->modify('+1 week');
        } elseif ($type === 'monthly') {
            $d->modify('+1 month');
        } elseif ($type === 'three_months') {
            $d->modify('+3 months');
        } elseif ($type === 'six_months') {
            $d->modify('+6 months');
        } else {
            return null;
        }

        return $d->format('Y-m-d');
    }
}

if (!function_exists('calculate_remaining_after_task_update')) {
    function calculate_remaining_after_task_update(array $existingTask, int $newTotal, string $newRecurrenceType): int
    {
        if ($newRecurrenceType === 'none') {
            return 1;
        }

        $oldTotal = max(1, (int)($existingTask['recurrence_total'] ?? 1));
        $oldRemaining = max(1, (int)($existingTask['recurrence_remaining'] ?? 1));

        $alreadyConsumed = max(0, $oldTotal - $oldRemaining);

        return max(1, $newTotal - $alreadyConsumed);
    }
}

if (!function_exists('generate_next_task_after_scheduling')) {
    function generate_next_task_after_scheduling(PDO $pdo, int $taskId, int $appointmentId): ?int
    {
        if ($taskId <= 0 || $appointmentId <= 0) {
            return null;
        }

        ensure_task_recurrence_schema($pdo);

        $startedTransaction = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTransaction = true;
            }

            $stmt = $pdo->prepare("
                SELECT *
                FROM tasks
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$task) {
                if ($startedTransaction) {
                    $pdo->commit();
                }

                return null;
            }

            if (!empty($task['generated_next_task_id'])) {
                if ((string)$task['status'] !== 'programat') {
                    $stmt = $pdo->prepare("
                        UPDATE tasks
                        SET status = 'programat',
                            appointment_id = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$appointmentId, $taskId]);
                }

                if ($startedTransaction) {
                    $pdo->commit();
                }

                return (int)$task['generated_next_task_id'];
            }

            if (
                (string)$task['status'] === 'programat' &&
                !empty($task['appointment_id']) &&
                (int)$task['appointment_id'] !== $appointmentId
            ) {
                if ($startedTransaction) {
                    $pdo->commit();
                }

                return null;
            }

            $recurrenceGroup = $task['recurrence_group'] ?: make_task_recurrence_group();
            $recurrenceType = $task['recurrence_type'] ?: 'none';
            $recurrenceDays = !empty($task['recurrence_days']) ? (int)$task['recurrence_days'] : null;

            $total = max(1, (int)($task['recurrence_total'] ?? 1));
            $remainingBefore = max(1, (int)($task['recurrence_remaining'] ?? 1));
            $remainingAfter = max(0, $remainingBefore - 1);

            $currentIndex = max(1, (int)($task['recurrence_index'] ?? 1));
            $nextIndex = $currentIndex + 1;

            $stmt = $pdo->prepare("
                UPDATE tasks
                SET status = 'programat',
                    appointment_id = ?,
                    recurrence_group = ?,
                    recurrence_remaining = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $appointmentId,
                $recurrenceGroup,
                $remainingAfter,
                $taskId
            ]);

            if (
                $recurrenceType === 'none' ||
                (int)($task['recurrence_stopped'] ?? 0) === 1 ||
                $remainingAfter <= 0
            ) {
                if ($startedTransaction) {
                    $pdo->commit();
                }

                return null;
            }

            $nextDate = next_task_due_date(
                (string)$task['due_date'],
                $recurrenceType,
                $recurrenceDays
            );

            if (!$nextDate) {
                if ($startedTransaction) {
                    $pdo->commit();
                }

                return null;
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM tasks
                WHERE generated_from_task_id = ?
                LIMIT 1
            ");
            $stmt->execute([$taskId]);
            $existingNext = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingNext) {
                $nextTaskId = (int)$existingNext['id'];

                $stmt = $pdo->prepare("
                    UPDATE tasks
                    SET generated_next_task_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nextTaskId, $taskId]);

                if ($startedTransaction) {
                    $pdo->commit();
                }

                return $nextTaskId;
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM tasks
                WHERE recurrence_group = ?
                  AND recurrence_index = ?
                LIMIT 1
            ");
            $stmt->execute([
                $recurrenceGroup,
                $nextIndex
            ]);
            $existingByIndex = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingByIndex) {
                $nextTaskId = (int)$existingByIndex['id'];

                $stmt = $pdo->prepare("
                    UPDATE tasks
                    SET generated_next_task_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nextTaskId, $taskId]);

                if ($startedTransaction) {
                    $pdo->commit();
                }

                return $nextTaskId;
            }

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
                    contract_id,
                    contract_service_id,
                    service_id,
                    location_name,
                    surface_value,
                    surface_unit,
                    billing_amount,
                    currency,
                    document_id,
                    document_item_id,
                    notes
                )
                VALUES
                (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, 0, ?,
                    ?, NULL, 'de_programat', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $task['client_id'],
                $task['client_location_id'] ?? null,
                $task['title'],
                $task['service_type'],
                $task['address'],
                $task['contact_person'] ?? null,
                $task['contact_phone'] ?? null,
                $nextDate,
                $recurrenceType,
                $recurrenceDays,
                $recurrenceGroup,
                $total,
                $remainingAfter,
                $nextIndex,
                $taskId,
                $task['contract_id'] ?? null,
                $task['contract_service_id'] ?? null,
                $task['service_id'] ?? null,
                $task['location_name'] ?? null,
                $task['surface_value'] ?? null,
                $task['surface_unit'] ?? null,
                $task['billing_amount'] ?? 0,
                $task['currency'] ?? 'RON',
                $task['document_id'] ?? null,
                $task['document_item_id'] ?? null,
                $task['notes']
            ]);

            $nextTaskId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("
                UPDATE tasks
                SET generated_next_task_id = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $nextTaskId,
                $taskId
            ]);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return $nextTaskId;

        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return null;
        }
    }
}

if (!function_exists('generate_next_task_after_completion')) {
    /**
     * Apelat când o programare este finalizată (din birou sau de tehnician).
     * Marchează sarcina curentă ca 'finalizat' și, dacă nu a fost deja generată
     * sarcina următoare la momentul programării (`generated_next_task_id` IS NULL),
     * o generează acum în baza regulilor de recurență.
     *
     * Protecție anti-dublură: dacă `generated_next_task_id` e deja setat,
     * sarcina următoare nu se mai creează a doua oară.
     *
     * @return int|null id-ul sarcinii următoare dacă există/a fost generată; null altfel
     */
    function generate_next_task_after_completion(PDO $pdo, int $appointmentId): ?int
    {
        if ($appointmentId <= 0) {
            return null;
        }

        ensure_task_recurrence_schema($pdo);

        $startedTransaction = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTransaction = true;
            }

            // Găsim sarcina legată de această programare.
            $stmt = $pdo->prepare("
                SELECT *
                FROM tasks
                WHERE appointment_id = ?
                  AND status = 'programat'
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$appointmentId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$task) {
                if ($startedTransaction) {
                    $pdo->commit();
                }

                return null;
            }

            $taskId = (int)$task['id'];

            // Pas 1: marcăm sarcina curentă ca finalizată.
            $stmt = $pdo->prepare("
                UPDATE tasks
                SET status = 'finalizat'
                WHERE id = ?
                  AND status = 'programat'
            ");
            $stmt->execute([$taskId]);

            // Dacă sarcina următoare a fost deja generată la programare, ne oprim aici.
            if (!empty($task['generated_next_task_id'])) {
                if ($startedTransaction) {
                    $pdo->commit();
                }

                return (int)$task['generated_next_task_id'];
            }

            $recurrenceType = $task['recurrence_type'] ?: 'none';
            $recurrenceDays = !empty($task['recurrence_days']) ? (int)$task['recurrence_days'] : null;
            $remainingAfter = max(0, (int)($task['recurrence_remaining'] ?? 0));

            // Fără recurență, oprit sau nu mai sunt rate rămase — nu generăm nimic.
            if (
                $recurrenceType === 'none' ||
                (int)($task['recurrence_stopped'] ?? 0) === 1 ||
                $remainingAfter <= 0
            ) {
                if ($startedTransaction) {
                    $pdo->commit();
                }

                return null;
            }

            $recurrenceGroup = $task['recurrence_group'] ?: make_task_recurrence_group();
            $total = max(1, (int)($task['recurrence_total'] ?? 1));
            $currentIndex = max(1, (int)($task['recurrence_index'] ?? 1));
            $nextIndex = $currentIndex + 1;

            $nextDate = next_task_due_date(
                (string)$task['due_date'],
                $recurrenceType,
                $recurrenceDays
            );

            if (!$nextDate) {
                if ($startedTransaction) {
                    $pdo->commit();
                }

                return null;
            }

            // Dacă există deja o sarcină generată din asta (paranoia — generated_from_task_id),
            // doar legăm pointer-ul, fără să creăm dublu.
            $stmt = $pdo->prepare("
                SELECT id
                FROM tasks
                WHERE generated_from_task_id = ?
                LIMIT 1
            ");
            $stmt->execute([$taskId]);
            $existingNext = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingNext) {
                $nextTaskId = (int)$existingNext['id'];

                $stmt = $pdo->prepare("
                    UPDATE tasks
                    SET generated_next_task_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nextTaskId, $taskId]);

                if ($startedTransaction) {
                    $pdo->commit();
                }

                return $nextTaskId;
            }

            // Verificăm și pe (recurrence_group, recurrence_index) — protecție suplimentară.
            $stmt = $pdo->prepare("
                SELECT id
                FROM tasks
                WHERE recurrence_group = ?
                  AND recurrence_index = ?
                LIMIT 1
            ");
            $stmt->execute([$recurrenceGroup, $nextIndex]);
            $existingByIndex = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingByIndex) {
                $nextTaskId = (int)$existingByIndex['id'];

                $stmt = $pdo->prepare("
                    UPDATE tasks
                    SET generated_next_task_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nextTaskId, $taskId]);

                if ($startedTransaction) {
                    $pdo->commit();
                }

                return $nextTaskId;
            }

            // Creăm sarcina următoare.
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
                    contract_id,
                    contract_service_id,
                    service_id,
                    location_name,
                    surface_value,
                    surface_unit,
                    billing_amount,
                    currency,
                    document_id,
                    document_item_id,
                    notes
                )
                VALUES
                (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, 0, ?,
                    ?, NULL, 'de_programat', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $task['client_id'],
                $task['client_location_id'] ?? null,
                $task['title'],
                $task['service_type'],
                $task['address'],
                $task['contact_person'] ?? null,
                $task['contact_phone'] ?? null,
                $nextDate,
                $recurrenceType,
                $recurrenceDays,
                $recurrenceGroup,
                $total,
                $remainingAfter,
                $nextIndex,
                $taskId,
                $task['contract_id'] ?? null,
                $task['contract_service_id'] ?? null,
                $task['service_id'] ?? null,
                $task['location_name'] ?? null,
                $task['surface_value'] ?? null,
                $task['surface_unit'] ?? null,
                $task['billing_amount'] ?? 0,
                $task['currency'] ?? 'RON',
                $task['document_id'] ?? null,
                $task['document_item_id'] ?? null,
                $task['notes'] ?? null
            ]);

            $nextTaskId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("
                UPDATE tasks
                SET generated_next_task_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$nextTaskId, $taskId]);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return $nextTaskId;

        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return null;
        }
    }
}
