<?php

/*
|--------------------------------------------------------------------------
| tasks_post_handler.php
|--------------------------------------------------------------------------
| Handler-ul de POST pentru pagina Sarcini.
| Procesează acțiunile: create, update, delete, skip, extend_recurrence.
| Termină cu redirect + exit dacă $_SERVER['REQUEST_METHOD'] === 'POST'.
|
| ATENȚIE: NU e o funcție — se include prin require, rulează în scope-ul
| paginii părinte (acces direct la $pdo, $clientsById, $locationsById, etc.).
|
| Dependențe (variabile din scope-ul părinte):
|   $pdo, $clientsById, $locationsByClient, $locationsById,
|   $_POST, $_SERVER, $_GET
| Helper-i folosiți (din tasks_helpers.php):
|   csrf_require(), safe_date_tasks(), task_get_location(),
|   task_client_address(), task_client_contact_person(),
|   task_client_contact_phone(), task_snapshot_*, task_next_due_date()
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
                    $appendNote .= "\nObservații prelungire: " . $extensionNote;
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
            $skipReason = 'Clientul nu doreste intervenția luna aceasta';
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
                $skipNote = "Sarcină omisa: " . $skipReason . " (" . date('d.m.Y H:i') . ")";
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

