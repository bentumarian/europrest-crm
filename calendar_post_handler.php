<?php

/*
|--------------------------------------------------------------------------
| calendar_post_handler.php
|--------------------------------------------------------------------------
| Handler-ul de POST pentru pagina Calendar (programări).
| Procesează acțiuni: create, update, delete, drag-update, mark_done, etc.
| Termină cu redirect + exit dacă $_SERVER['REQUEST_METHOD'] === 'POST'.
|
| ATENȚIE: NU e o funcție — se include prin require, rulează în scope-ul
| paginii părinte (acces direct la $pdo, $isAdmin, $isTeamUser, etc.).
|
| Dependențe (variabile din scope-ul părinte):
|   $pdo, $_POST, $_SERVER, $_GET, plus variabile contextuale
|   pre-calculate în calendar.php (servicii, clienți, teams, etc).
|
| Helper-i folosiți (din calendar_helpers.php):
|   safe_date, safe_view, calendar_get_client, calendar_get_location,
|   calendar_find_team_time_conflicts, calendar_snapshot_*,
|   calendar_clean_team_ids, calendar_sync_appointment_teams, etc.
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

        // Creează / actualizează poziția de facturat.
        if (function_exists('pz_billing_ensure_item_for_appointment')) {
            pz_billing_ensure_item_for_appointment($pdo, $appointmentId);
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

        // Creează / actualizează poziția de facturat (la finalizare din birou).
        if (function_exists('pz_billing_ensure_item_for_appointment')) {
            pz_billing_ensure_item_for_appointment($pdo, $appointmentId);
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
        $billingVatCode = trim((string)($_POST['billing_vat_code'] ?? $smartbillDefaultVatCode));
        if (!isset($smartbillVatOptions[$billingVatCode]) || !in_array($billingVatCode, $smartbillAllowedVatCodes, true)) {
            $billingVatCode = $smartbillDefaultVatCode;
        }
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
            // Valoarea canonică pentru „Nu se facturează" este `nu_se_factureaza`.
            // Vechiul 'nefacturabil' este eliminat — billing_lib îl normalizează oricum la citire.
            $billingStatus = $notInvoiceable ? 'nu_se_factureaza' : 'de_facturat';
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
                            billing_vat_code = ?,
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
                        $billingVatCode,
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
                    // La modificari de data/ora, utilizatorul trimite SMS manual din fișa programării.
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
                        billing_vat_code,
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
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmata', ?, ?, ?, ?, NULL, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                    $billingVatCode,
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

