<?php

/*
|--------------------------------------------------------------------------
| lib/billing/billing_lib.php — flux financiar nou (post-reset)
|--------------------------------------------------------------------------
| Singura sursă de adevăr pentru pozițiile „de facturat" (tabela billing_items).
| Apelată din: calendar.php, work_billing.php, invoice.php, invoices.php.
|
| Reguli:
| - smartbill_lib.php rămâne provider extern; este apelat doar din
|   pz_billing_issue_invoice() pentru transmiterea către API.
| - Coloanele appointments.billing_* nu mai sunt citite de fluxul nou;
|   rămân pe loc, dar nu mai sunt sursă de adevăr.
| - Toate funcțiile sunt încadrate în function_exists pentru a permite
|   includere multiplă fără erori.
|--------------------------------------------------------------------------
*/

// Helper categorii venituri (linii de business).
if (file_exists(__DIR__ . '/../revenue_lib.php')) {
    require_once __DIR__ . '/../revenue_lib.php';
}

if (!function_exists('pz_billing_money')) {
    /**
     * Conversie sigură la float, cu suport pentru formatul RO (virgulă zecimală).
     * Wrapper subțire peste pz_smartbill_money pentru consistență.
     */
    function pz_billing_money($value): float
    {
        if (function_exists('pz_smartbill_money')) {
            return pz_smartbill_money($value);
        }
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], trim($value));
        }
        return is_numeric($value) ? max(0, round((float)$value, 2)) : 0.0;
    }
}

if (!function_exists('pz_billing_status_label')) {
    function pz_billing_status_label(string $status): string
    {
        $map = [
            'to_review'    => 'De verificat',
            'to_invoice'   => 'De facturat',
            'invoiced'     => 'Facturată',
            'not_billable' => 'Nefacturabilă',
            'cancelled'    => 'Anulată',
        ];
        return $map[$status] ?? 'De verificat';
    }
}

if (!function_exists('pz_billing_status_class')) {
    function pz_billing_status_class(string $status): string
    {
        $map = [
            'to_review'    => 'tone-warning',
            'to_invoice'   => 'tone-info',
            'invoiced'     => 'tone-success',
            'not_billable' => 'tone-danger',
            'cancelled'    => 'tone-danger',
        ];
        return $map[$status] ?? 'tone-neutral';
    }
}

if (!function_exists('pz_billing_ensure_schema')) {
    /**
     * Idempotent. Creează tabela billing_items, ajustează smartbill_invoices.
     * Sigur de apelat la fiecare load de pagină din modulul de billing.
     */
    function pz_billing_ensure_schema(PDO $pdo): void
    {
        static $applied = false;
        if ($applied) {
            return;
        }

        // 1) Tabel principal pentru pozițiile de facturat.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS billing_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                appointment_id INT NULL,
                pv_document_id INT NULL,
                client_id INT NOT NULL,
                client_location_id INT NULL,
                contract_id INT NULL,
                contract_service_id INT NULL,
                service_id INT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                work_date DATE NOT NULL,
                quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000,
                unit VARCHAR(30) NOT NULL DEFAULT 'buc',
                unit_price_net DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                vat_code VARCHAR(40) NOT NULL DEFAULT '21',
                total_net DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(10) NOT NULL DEFAULT 'RON',
                source VARCHAR(20) NOT NULL DEFAULT 'appointment',
                status VARCHAR(20) NOT NULL DEFAULT 'to_review',
                not_billable_reason VARCHAR(255) NULL,
                smartbill_invoice_id INT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_billing_items_client_status (client_id, status),
                INDEX idx_billing_items_status_date (status, work_date),
                INDEX idx_billing_items_appointment (appointment_id),
                INDEX idx_billing_items_invoice (smartbill_invoice_id),
                INDEX idx_billing_items_pv (pv_document_id),
                INDEX idx_billing_items_contract (contract_id, contract_service_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2) Elimină UNIQUE de pe smartbill_invoices.appointment_id, dacă există.
        try {
            $exists = $pdo->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'smartbill_invoices'
                  AND INDEX_NAME = 'uq_smartbill_appointment'
            ")->fetchColumn();
            if ((int)$exists > 0) {
                $pdo->exec("ALTER TABLE smartbill_invoices DROP INDEX uq_smartbill_appointment");
            }
        } catch (Throwable $e) {
            error_log('pz_billing_ensure_schema drop unique: ' . $e->getMessage());
        }

        // 3) Adaugă index simplu pe smartbill_invoices.appointment_id, dacă lipsește.
        try {
            $tblExists = $pdo->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'smartbill_invoices'
            ")->fetchColumn();
            if ((int)$tblExists > 0) {
                $idxExists = $pdo->query("
                    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'smartbill_invoices'
                      AND INDEX_NAME = 'idx_smartbill_appointment'
                ")->fetchColumn();
                if ((int)$idxExists === 0) {
                    $pdo->exec("ALTER TABLE smartbill_invoices ADD INDEX idx_smartbill_appointment (appointment_id)");
                }
            }
        } catch (Throwable $e) {
            error_log('pz_billing_ensure_schema add index: ' . $e->getMessage());
        }

        // 4) Categoria veniturilor (linie de business) pe billing_items + smartbill_invoices.
        if (function_exists('pz_revenue_ensure_column')) {
            pz_revenue_ensure_column($pdo, 'billing_items', 'ddd');
            pz_revenue_ensure_column($pdo, 'smartbill_invoices', 'ddd');
        }

        $applied = true;
    }
}

if (!function_exists('pz_billing_default_vat_code')) {
    /**
     * Internal helper: citește codul TVA implicit din setări (fallback '21').
     */
    function pz_billing_default_vat_code(PDO $pdo): string
    {
        if (function_exists('pz_smartbill_settings')) {
            $settings = pz_smartbill_settings($pdo);
            $code = trim((string)($settings['smartbill.default_vat_code'] ?? '21'));
            return $code !== '' ? $code : '21';
        }
        return '21';
    }
}

if (!function_exists('pz_billing_appointment_billing_data')) {
    /**
     * Internal helper: încarcă datele de bază pentru o programare,
     * cu join pe client, location, contract_service, service.
     * Returnează null dacă programarea nu există.
     */
    function pz_billing_appointment_billing_data(PDO $pdo, int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                a.id AS appointment_id,
                a.client_id,
                a.client_location_id,
                a.contract_id,
                a.contract_service_id,
                a.service_id,
                a.appointment_date,
                a.service_type,
                a.title AS appointment_title,
                a.billing_amount AS apt_billing_amount,
                a.billing_vat_code AS apt_billing_vat_code,
                a.billing_status AS apt_billing_status,
                a.billing_note AS apt_billing_note,
                a.surface_value,
                a.surface_unit,
                a.currency,
                a.team_member_id,
                COALESCE(cs.price, 0) AS contract_service_price,
                cs.currency AS contract_service_currency,
                COALESCE(s.revenue_category, 'ddd') AS service_revenue_category
            FROM appointments a
            LEFT JOIN contract_services cs ON cs.id = a.contract_service_id
            LEFT JOIN services s ON s.id = a.service_id
            WHERE a.id = ?
            LIMIT 1
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$appointmentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('pz_billing_appointment_billing_data: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('pz_billing_get_item_by_appointment')) {
    function pz_billing_get_item_by_appointment(PDO $pdo, int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM billing_items WHERE appointment_id = ? ORDER BY id ASC LIMIT 1");
            $stmt->execute([$appointmentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('pz_billing_get_item_by_appointment: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('pz_billing_ensure_item_for_appointment')) {
    /**
     * Idempotent. Verifică dacă programarea are deja un billing_item.
     * Dacă nu, îl creează folosind:
     *   - billing_amount manual (prioritate 1)
     *   - contract_services.price (prioritate 2)
     *   - 0 (cu status='to_review' — utilizatorul completează ulterior)
     *
     * Dacă appointments.billing_status='nefacturabil', creează cu status='not_billable'.
     *
     * @return array{ok: bool, item_id: int, created: bool, error: ?string}
     */
    function pz_billing_ensure_item_for_appointment(PDO $pdo, int $appointmentId): array
    {
        if ($appointmentId <= 0) {
            return ['ok' => false, 'item_id' => 0, 'created' => false, 'error' => 'appointment_id invalid'];
        }

        pz_billing_ensure_schema($pdo);

        // Verifică dacă există deja.
        $existing = pz_billing_get_item_by_appointment($pdo, $appointmentId);
        if ($existing) {
            return [
                'ok'      => true,
                'item_id' => (int)$existing['id'],
                'created' => false,
                'error'   => null,
            ];
        }

        $data = pz_billing_appointment_billing_data($pdo, $appointmentId);
        if (!$data) {
            return ['ok' => false, 'item_id' => 0, 'created' => false, 'error' => 'Programarea nu a fost găsită.'];
        }

        $clientId = (int)($data['client_id'] ?? 0);
        if ($clientId <= 0) {
            return ['ok' => false, 'item_id' => 0, 'created' => false, 'error' => 'Programarea nu are client asociat.'];
        }

        // Determină valoarea.
        $manualAmount = pz_billing_money($data['apt_billing_amount'] ?? 0);
        $contractPrice = pz_billing_money($data['contract_service_price'] ?? 0);
        $unitPrice = $manualAmount > 0 ? $manualAmount : $contractPrice;

        // VAT.
        $vatCode = trim((string)($data['apt_billing_vat_code'] ?? '')) !== ''
            ? (string)$data['apt_billing_vat_code']
            : pz_billing_default_vat_code($pdo);

        // Currency.
        $currency = trim((string)($data['currency'] ?? '')) !== '' ? (string)$data['currency'] : 'RON';

        // Status inițial.
        $aptBillingStatus = strtolower(trim((string)($data['apt_billing_status'] ?? '')));
        $aptBillingNote = trim((string)($data['apt_billing_note'] ?? ''));
        if ($aptBillingStatus === 'nefacturabil') {
            $status = 'not_billable';
            $notBillableReason = $aptBillingNote !== '' ? $aptBillingNote : 'Marcat nefacturabil din calendar';
        } else {
            $status = 'to_review';
            $notBillableReason = null;
        }

        // Description: service_type sau title.
        $description = trim((string)($data['service_type'] ?? ''));
        if ($description === '') {
            $description = trim((string)($data['appointment_title'] ?? ''));
        }
        if ($description === '') {
            $description = 'Lucrare';
        }
        if (function_exists('mb_substr')) {
            $description = mb_substr($description, 0, 255, 'UTF-8');
        } else {
            $description = substr($description, 0, 255);
        }

        $workDate = (string)($data['appointment_date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $workDate = date('Y-m-d');
        }

        $quantity = 1.000;
        $unit = 'buc';
        $totalNet = round($unitPrice * $quantity, 2);

        $createdBy = function_exists('current_user_id') ? (int)current_user_id() : null;

        // Categoria veniturilor — snapshot din service la momentul creării.
        $revenueCategory = 'ddd';
        if (function_exists('pz_revenue_category_normalize')) {
            $revenueCategory = pz_revenue_category_normalize(
                (string)($data['service_revenue_category'] ?? 'ddd'),
                'ddd'
            );
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO billing_items (
                    appointment_id, client_id, client_location_id,
                    contract_id, contract_service_id, service_id,
                    description, work_date,
                    quantity, unit, unit_price_net, vat_code, total_net, currency,
                    source, status, not_billable_reason, created_by, revenue_category
                ) VALUES (
                    :appointment_id, :client_id, :client_location_id,
                    :contract_id, :contract_service_id, :service_id,
                    :description, :work_date,
                    :quantity, :unit, :unit_price_net, :vat_code, :total_net, :currency,
                    'appointment', :status, :not_billable_reason, :created_by, :revenue_category
                )
            ");
            $stmt->execute([
                ':appointment_id'      => $appointmentId,
                ':client_id'           => $clientId,
                ':client_location_id'  => $data['client_location_id'] !== null ? (int)$data['client_location_id'] : null,
                ':contract_id'         => $data['contract_id'] !== null ? (int)$data['contract_id'] : null,
                ':contract_service_id' => $data['contract_service_id'] !== null ? (int)$data['contract_service_id'] : null,
                ':service_id'          => $data['service_id'] !== null ? (int)$data['service_id'] : null,
                ':description'         => $description,
                ':work_date'           => $workDate,
                ':quantity'            => $quantity,
                ':unit'                => $unit,
                ':unit_price_net'      => $unitPrice,
                ':vat_code'            => $vatCode,
                ':total_net'           => $totalNet,
                ':currency'            => $currency,
                ':status'              => $status,
                ':not_billable_reason' => $notBillableReason,
                ':created_by'          => $createdBy,
                ':revenue_category'    => $revenueCategory,
            ]);

            return [
                'ok'      => true,
                'item_id' => (int)$pdo->lastInsertId(),
                'created' => true,
                'error'   => null,
            ];
        } catch (PDOException $e) {
            // SQLSTATE 23000 = integrity constraint violation (UNIQUE pe appointment_id).
            // Înseamnă că un alt request concurent a creat deja rândul între SELECT-ul
            // de la începutul funcției și INSERT-ul de aici. Re-citim rândul existent
            // și-l returnăm ca succes (idempotent, fără excepție vizibilă utilizatorului).
            if ($e->getCode() === '23000') {
                $existing = pz_billing_get_item_by_appointment($pdo, $appointmentId);
                if ($existing) {
                    return [
                        'ok'      => true,
                        'item_id' => (int)$existing['id'],
                        'created' => false,
                        'error'   => null,
                    ];
                }
            }
            error_log('pz_billing_ensure_item_for_appointment INSERT: ' . $e->getMessage());
            return ['ok' => false, 'item_id' => 0, 'created' => false, 'error' => 'Eroare la creare poziție: ' . $e->getMessage()];
        } catch (Throwable $e) {
            error_log('pz_billing_ensure_item_for_appointment INSERT: ' . $e->getMessage());
            return ['ok' => false, 'item_id' => 0, 'created' => false, 'error' => 'Eroare la creare poziție: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('pz_billing_update_amount')) {
    /**
     * Actualizează unit_price_net + vat_code pentru o poziție.
     * Refuză dacă itemul e invoiced sau cancelled.
     *
     * @return array{ok: bool, error: ?string}
     */
    function pz_billing_update_amount(PDO $pdo, int $itemId, $amount, string $vatCode): array
    {
        if ($itemId <= 0) {
            return ['ok' => false, 'error' => 'item_id invalid'];
        }

        $unitPrice = pz_billing_money($amount);
        $vatCode = trim($vatCode) !== '' ? trim($vatCode) : pz_billing_default_vat_code($pdo);

        try {
            $stmt = $pdo->prepare("SELECT id, quantity, status FROM billing_items WHERE id = ? LIMIT 1");
            $stmt->execute([$itemId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['ok' => false, 'error' => 'Poziția nu există.'];
            }
            if (in_array((string)$row['status'], ['invoiced', 'cancelled'], true)) {
                return ['ok' => false, 'error' => 'Poziție deja facturată/anulată — nu se mai poate edita.'];
            }

            $quantity = (float)$row['quantity'];
            if ($quantity <= 0) {
                $quantity = 1.0;
            }
            $totalNet = round($unitPrice * $quantity, 2);

            $upd = $pdo->prepare("
                UPDATE billing_items
                SET unit_price_net = ?, vat_code = ?, total_net = ?
                WHERE id = ?
            ");
            $upd->execute([$unitPrice, $vatCode, $totalNet, $itemId]);

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            error_log('pz_billing_update_amount: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Eroare la salvare: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('pz_billing_mark_to_invoice')) {
    /**
     * Trece itemul în starea „de facturat".
     * Cere ca valoarea să fie > 0. Refuză din invoiced/cancelled.
     */
    function pz_billing_mark_to_invoice(PDO $pdo, int $itemId): array
    {
        if ($itemId <= 0) {
            return ['ok' => false, 'error' => 'item_id invalid'];
        }
        try {
            $stmt = $pdo->prepare("SELECT id, status, total_net FROM billing_items WHERE id = ? LIMIT 1");
            $stmt->execute([$itemId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['ok' => false, 'error' => 'Poziția nu există.'];
            }
            if (in_array((string)$row['status'], ['invoiced', 'cancelled'], true)) {
                return ['ok' => false, 'error' => 'Poziție deja facturată/anulată.'];
            }
            if (pz_billing_money($row['total_net']) <= 0) {
                return ['ok' => false, 'error' => 'Completează valoarea înainte de a marca poziția „de facturat".'];
            }

            $upd = $pdo->prepare("
                UPDATE billing_items
                SET status = 'to_invoice', not_billable_reason = NULL
                WHERE id = ?
            ");
            $upd->execute([$itemId]);

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            error_log('pz_billing_mark_to_invoice: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Eroare: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('pz_billing_mark_to_review')) {
    /**
     * Trece itemul înapoi în starea „de verificat".
     * Util pentru a reveni dintr-o stare „de facturat" sau „nefacturabil" greșite.
     */
    function pz_billing_mark_to_review(PDO $pdo, int $itemId): array
    {
        if ($itemId <= 0) {
            return ['ok' => false, 'error' => 'item_id invalid'];
        }
        try {
            $stmt = $pdo->prepare("SELECT id, status FROM billing_items WHERE id = ? LIMIT 1");
            $stmt->execute([$itemId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['ok' => false, 'error' => 'Poziția nu există.'];
            }
            if (in_array((string)$row['status'], ['invoiced', 'cancelled'], true)) {
                return ['ok' => false, 'error' => 'Poziție deja facturată/anulată.'];
            }

            $upd = $pdo->prepare("
                UPDATE billing_items
                SET status = 'to_review', not_billable_reason = NULL
                WHERE id = ?
            ");
            $upd->execute([$itemId]);

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            error_log('pz_billing_mark_to_review: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Eroare: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('pz_billing_mark_not_billable')) {
    /**
     * Marchează itemul nefacturabil. Motiv obligatoriu (validat de caller).
     * Refuză din invoiced/cancelled.
     */
    function pz_billing_mark_not_billable(PDO $pdo, int $itemId, string $reason): array
    {
        if ($itemId <= 0) {
            return ['ok' => false, 'error' => 'item_id invalid'];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'Motivul este obligatoriu.'];
        }
        if (function_exists('mb_substr')) {
            $reason = mb_substr($reason, 0, 255, 'UTF-8');
        } else {
            $reason = substr($reason, 0, 255);
        }

        try {
            $stmt = $pdo->prepare("SELECT id, status FROM billing_items WHERE id = ? LIMIT 1");
            $stmt->execute([$itemId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['ok' => false, 'error' => 'Poziția nu există.'];
            }
            if (in_array((string)$row['status'], ['invoiced', 'cancelled'], true)) {
                return ['ok' => false, 'error' => 'Poziție deja facturată/anulată.'];
            }

            $upd = $pdo->prepare("
                UPDATE billing_items
                SET status = 'not_billable', not_billable_reason = ?
                WHERE id = ?
            ");
            $upd->execute([$reason, $itemId]);

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            error_log('pz_billing_mark_not_billable: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Eroare: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('pz_billing_mark_invoiced')) {
    /**
     * Bulk update: marchează un set de items ca facturate
     * și le leagă de o factură SmartBill.
     * Folosit de orchestratorul pz_billing_issue_invoice().
     */
    function pz_billing_mark_invoiced(PDO $pdo, array $itemIds, int $smartbillInvoiceId): void
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn($v) => $v > 0));
        if (!$itemIds || $smartbillInvoiceId <= 0) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params = array_merge([$smartbillInvoiceId], $itemIds);
        try {
            $stmt = $pdo->prepare("
                UPDATE billing_items
                SET status = 'invoiced', smartbill_invoice_id = ?
                WHERE id IN ($placeholders)
            ");
            $stmt->execute($params);
        } catch (Throwable $e) {
            error_log('pz_billing_mark_invoiced: ' . $e->getMessage());
        }
    }
}

if (!function_exists('pz_billing_validate_invoice_selection')) {
    /**
     * Verifică selecția de items pentru emitere factură.
     * Returnează ['ok'=>bool, 'client_id'=>int, 'items'=>array, 'error'=>string|null].
     */
    function pz_billing_validate_invoice_selection(PDO $pdo, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn($v) => $v > 0)));
        if (!$itemIds) {
            return ['ok' => false, 'client_id' => 0, 'items' => [], 'error' => 'Nicio poziție selectată.'];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        try {
            $stmt = $pdo->prepare("
                SELECT bi.*, c.name AS client_name
                FROM billing_items bi
                LEFT JOIN clients c ON c.id = bi.client_id
                WHERE bi.id IN ($placeholders)
                ORDER BY bi.work_date ASC, bi.id ASC
            ");
            $stmt->execute($itemIds);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('pz_billing_validate_invoice_selection: ' . $e->getMessage());
            return ['ok' => false, 'client_id' => 0, 'items' => [], 'error' => 'Eroare la citire poziții.'];
        }

        if (count($items) !== count($itemIds)) {
            return ['ok' => false, 'client_id' => 0, 'items' => $items, 'error' => 'Una sau mai multe poziții nu au fost găsite.'];
        }

        $clientIds = [];
        $blockingStatuses = ['invoiced', 'cancelled', 'not_billable'];
        foreach ($items as $item) {
            $clientIds[] = (int)$item['client_id'];
            if (in_array((string)$item['status'], $blockingStatuses, true)) {
                $label = pz_billing_status_label((string)$item['status']);
                return [
                    'ok'        => false,
                    'client_id' => 0,
                    'items'     => $items,
                    'error'     => 'Poziția #' . (int)$item['id'] . ' are status „' . $label . '" și nu poate fi facturată.',
                ];
            }
            if (pz_billing_money($item['total_net']) <= 0) {
                return [
                    'ok'        => false,
                    'client_id' => 0,
                    'items'     => $items,
                    'error'     => 'Poziția #' . (int)$item['id'] . ' are valoare zero — completează valoarea înainte de facturare.',
                ];
            }
        }

        $uniqueClients = array_values(array_unique($clientIds));
        if (count($uniqueClients) !== 1) {
            return [
                'ok'        => false,
                'client_id' => 0,
                'items'     => $items,
                'error'     => 'Pozițiile selectate aparțin mai multor clienți. Selectează poziții pentru același client.',
            ];
        }

        return [
            'ok'        => true,
            'client_id' => (int)$uniqueClients[0],
            'items'     => $items,
            'error'     => null,
        ];
    }
}

if (!function_exists('pz_billing_calculate_totals')) {
    /**
     * Calculează totaluri pentru un set de billing_items.
     * Returnează ['net'=>float, 'vat'=>float, 'gross'=>float, 'lines'=>[...]].
     */
    function pz_billing_calculate_totals(array $items, string $defaultVatCode = '21'): array
    {
        $net = 0.0;
        $vat = 0.0;
        $lines = [];

        foreach ($items as $item) {
            $quantity = (float)($item['quantity'] ?? 1);
            if ($quantity <= 0) {
                $quantity = 1.0;
            }
            $unitPrice = pz_billing_money($item['unit_price_net'] ?? 0);
            $vatCode = trim((string)($item['vat_code'] ?? $defaultVatCode));
            if ($vatCode === '') {
                $vatCode = $defaultVatCode;
            }

            $lineNet = round($unitPrice * $quantity, 2);
            $vatPercent = 0.0;
            if (function_exists('pz_smartbill_tax_meta')) {
                $meta = pz_smartbill_tax_meta($vatCode);
                // pz_smartbill_tax_meta() returneaza 'taxPercentage', NU 'percentage'
                // (vechi bug: cauzat TVA = 0 in totaluri si suma neta in chitanta SmartBill)
                $vatPercent = (float)($meta['taxPercentage'] ?? $meta['percentage'] ?? 0);
            } else {
                // Fallback simplu: '21' => 21, '11' => 11, alte coduri => 0.
                if (preg_match('/^(\d+(?:\.\d+)?)/', $vatCode, $m)) {
                    $vatPercent = (float)$m[1];
                }
            }
            $lineVat = round($lineNet * $vatPercent / 100, 2);
            $lineGross = round($lineNet + $lineVat, 2);

            $net += $lineNet;
            $vat += $lineVat;

            $lines[] = [
                'item_id'      => (int)($item['id'] ?? 0),
                'description'  => (string)($item['description'] ?? ''),
                'quantity'     => $quantity,
                'unit'         => (string)($item['unit'] ?? 'buc'),
                'unit_price'   => $unitPrice,
                'vat_code'     => $vatCode,
                'vat_percent'  => $vatPercent,
                'line_net'     => $lineNet,
                'line_vat'     => $lineVat,
                'line_gross'   => $lineGross,
            ];
        }

        $net = round($net, 2);
        $vat = round($vat, 2);
        $gross = round($net + $vat, 2);

        return [
            'net'   => $net,
            'vat'   => $vat,
            'gross' => $gross,
            'lines' => $lines,
        ];
    }
}

if (!function_exists('pz_billing_collect_client_snapshot')) {
    /**
     * Citește datele clientului pentru a popula snapshot-ul din factura nouă.
     * Mapează coloanele billing_* (adresa de facturare) cu fallback la adresa principală.
     */
    function pz_billing_collect_client_snapshot(PDO $pdo, int $clientId): array
    {
        $snapshot = [
            'client_id'          => $clientId,
            'client_name'        => '',
            'client_fiscal_code' => '',
            'client_reg_com'     => '',
            'client_contact'     => '',
            'client_email'       => '',
            'client_phone'       => '',
            'client_bank'        => '',
            'client_iban'        => '',
            'client_country'     => 'Romania',
            'client_county'      => '',
            'client_city'        => '',
            'client_address'     => '',
        ];
        if ($clientId <= 0) {
            return $snapshot;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('pz_billing_collect_client_snapshot: ' . $e->getMessage());
            return $snapshot;
        }
        if (!$client) {
            return $snapshot;
        }

        $snapshot['client_name']        = (string)($client['name'] ?? '');
        $snapshot['client_fiscal_code'] = (string)($client['fiscal_code'] ?? '');
        $snapshot['client_reg_com']     = (string)($client['registry_number'] ?? '');
        $snapshot['client_contact']     = (string)($client['contact_person'] ?? ($client['legal_representative_name'] ?? ''));
        $snapshot['client_email']       = (string)($client['email'] ?? '');
        $snapshot['client_phone']       = (string)($client['phone'] ?? '');
        $snapshot['client_bank']        = (string)($client['bank_name'] ?? '');
        $snapshot['client_iban']        = (string)($client['bank_account'] ?? '');

        // Adresa de facturare (prioritate) — fallback la adresa principală.
        $billingCountry = trim((string)($client['billing_country'] ?? ''));
        $billingCounty  = trim((string)($client['billing_county'] ?? ''));
        $billingCity    = trim((string)($client['billing_city'] ?? ''));
        $billingAddress = trim((string)($client['billing_address_line'] ?? ''));

        $snapshot['client_country'] = $billingCountry !== '' ? $billingCountry : 'Romania';
        $snapshot['client_county']  = $billingCounty;
        $snapshot['client_city']    = $billingCity !== '' ? $billingCity : trim((string)($client['city'] ?? ''));
        $snapshot['client_address'] = $billingAddress !== ''
            ? $billingAddress
            : trim((string)($client['registered_address'] ?? ($client['address'] ?? '')));

        return $snapshot;
    }
}

if (!function_exists('pz_billing_get_invoice_payment_summary')) {
    /**
     * Calculează status plată pentru o factură SmartBill.
     * Returnează ['gross'=>float, 'paid'=>float, 'remaining'=>float, 'status'=>string].
     * status ∈ {'unpaid', 'partially_paid', 'paid'}
     */
    function pz_billing_get_invoice_payment_summary(PDO $pdo, int $invoiceId): array
    {
        $summary = [
            'gross'     => 0.0,
            'paid'      => 0.0,
            'remaining' => 0.0,
            'status'    => 'unpaid',
        ];
        if ($invoiceId <= 0) {
            return $summary;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT gross_amount, COALESCE(smartbill_paid_amount, 0) AS sb_paid
                FROM smartbill_invoices
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$invoiceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $summary;
            }
            $gross = pz_billing_money($row['gross_amount'] ?? 0);
            $sbPaid = pz_billing_money($row['sb_paid'] ?? 0);

            // Sumarul plăților locale (excluzând cele cu status error/deleted).
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total_paid
                FROM smartbill_invoice_payments
                WHERE smartbill_invoice_id = ?
                  AND (smartbill_status IS NULL OR smartbill_status NOT IN ('error', 'deleted'))
            ");
            $stmt->execute([$invoiceId]);
            $localPaid = pz_billing_money($stmt->fetchColumn() ?: 0);

            $paid = round(max($localPaid, $sbPaid), 2);
            $remaining = round(max(0.0, $gross - $paid), 2);

            if ($gross <= 0 || $paid <= 0) {
                $status = 'unpaid';
            } elseif ($paid + 0.005 >= $gross) {
                $status = 'paid';
            } else {
                $status = 'partially_paid';
            }

            return [
                'gross'     => $gross,
                'paid'      => $paid,
                'remaining' => $remaining,
                'status'    => $status,
            ];
        } catch (Throwable $e) {
            error_log('pz_billing_get_invoice_payment_summary: ' . $e->getMessage());
            return $summary;
        }
    }
}

if (!function_exists('pz_billing_payment_status')) {
    /**
     * Helper alternativ — primește direct rândul (cu eventual 'payments' atașate).
     * Folosit unde nu vrem un round-trip suplimentar la BD.
     */
    function pz_billing_payment_status(array $smartbillInvoice): string
    {
        $gross = pz_billing_money($smartbillInvoice['gross_amount'] ?? 0);
        $paid = 0.0;
        if (isset($smartbillInvoice['payments']) && is_array($smartbillInvoice['payments'])) {
            foreach ($smartbillInvoice['payments'] as $payment) {
                $st = (string)($payment['smartbill_status'] ?? '');
                if (in_array($st, ['error', 'deleted'], true)) {
                    continue;
                }
                $paid += pz_billing_money($payment['amount'] ?? 0);
            }
        }
        $sbPaid = pz_billing_money($smartbillInvoice['smartbill_paid_amount'] ?? 0);
        $paid = round(max($paid, $sbPaid), 2);

        if ($gross <= 0 || $paid <= 0) {
            return 'unpaid';
        }
        if ($paid + 0.005 >= $gross) {
            return 'paid';
        }
        return 'partially_paid';
    }
}

if (!function_exists('pz_billing_issue_invoice')) {
    /**
     * Orchestrator emitere factură pentru un set de billing_items.
     *
     * Pași:
     *   1. validate_invoice_selection
     *   2. calculate_totals
     *   3. INSERT smartbill_invoices (status='draft', source_type='manual')
     *   4. INSERT smartbill_invoice_items (câte o linie per billing_item)
     *   5. Dacă SmartBill activ + opts['send_to_smartbill']==true:
     *        apel pz_smartbill_issue_invoice — pe succes mark_invoiced
     *   6. Altfel: factura rămâne 'draft', dar items se marchează 'invoiced'
     *      (au smartbill_invoice_id setat, pot fi emise ulterior din pagina facturii).
     *
     * @return array{ok: bool, invoice_id: int, draft: bool, error: ?string}
     */
    function pz_billing_issue_invoice(PDO $pdo, array $billingItemIds, array $options = []): array
    {
        pz_billing_ensure_schema($pdo);

        $validation = pz_billing_validate_invoice_selection($pdo, $billingItemIds);
        if (empty($validation['ok'])) {
            return ['ok' => false, 'invoice_id' => 0, 'draft' => false, 'error' => (string)($validation['error'] ?? 'Selecție invalidă.')];
        }

        $items = $validation['items'];
        $clientId = (int)$validation['client_id'];
        $totals = pz_billing_calculate_totals($items, pz_billing_default_vat_code($pdo));

        // Snapshot client + setări.
        $clientSnapshot = pz_billing_collect_client_snapshot($pdo, $clientId);
        $settings = function_exists('pz_smartbill_settings') ? pz_smartbill_settings($pdo) : [];

        // Date facturare.
        $invoiceDate = trim((string)($options['invoice_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
            $invoiceDate = date('Y-m-d');
        }
        $dueDays = max(0, (int)($settings['smartbill.payment_due_days'] ?? 15));
        $dueDate = trim((string)($options['due_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $dueDate = date('Y-m-d', strtotime($invoiceDate . ' +' . $dueDays . ' days'));
        }

        $appointmentRef = 0;
        $locationRef = 0;
        $currency = 'RON';
        foreach ($items as $item) {
            if ($appointmentRef <= 0 && (int)($item['appointment_id'] ?? 0) > 0) {
                $appointmentRef = (int)$item['appointment_id'];
            }
            if ($locationRef <= 0 && (int)($item['client_location_id'] ?? 0) > 0) {
                $locationRef = (int)$item['client_location_id'];
            }
            if (!empty($item['currency'])) {
                $currency = (string)$item['currency'];
            }
        }

        $primaryVatCode = trim((string)($items[0]['vat_code'] ?? pz_billing_default_vat_code($pdo)));
        $createdBy = function_exists('current_user_id') ? (int)current_user_id() : null;

        // Categoria veniturilor: dacă toate item-urile au aceeași categorie, o folosim;
        // altfel cădem pe 'altele' (factură mixtă). Override manual e disponibil ulterior.
        $invoiceRevenueCategory = 'ddd';
        if (function_exists('pz_revenue_category_normalize')) {
            $categoriesSeen = [];
            foreach ($items as $item) {
                $categoriesSeen[] = pz_revenue_category_normalize(
                    (string)($item['revenue_category'] ?? 'ddd'),
                    'ddd'
                );
            }
            $unique = array_values(array_unique($categoriesSeen));
            if (!empty($options['revenue_category'])) {
                $invoiceRevenueCategory = pz_revenue_category_normalize(
                    (string)$options['revenue_category'],
                    'ddd'
                );
            } elseif (count($unique) === 1) {
                $invoiceRevenueCategory = $unique[0];
            } else {
                $invoiceRevenueCategory = 'altele';
            }
        }

        $pdo->beginTransaction();
        try {
            // INSERT smartbill_invoices.
            $invoiceData = [
                ':appointment_id'      => $appointmentRef > 0 ? $appointmentRef : null,
                ':client_id'           => $clientId,
                ':client_location_id'  => $locationRef > 0 ? $locationRef : null,
                ':invoice_date'        => $invoiceDate,
                ':due_date'            => $dueDate,
                ':currency'            => $currency,
                ':net_amount'          => $totals['net'],
                ':vat_code'            => $primaryVatCode,
                ':vat_amount'          => $totals['vat'],
                ':gross_amount'        => $totals['gross'],
                ':source_type'         => 'manual',
                ':client_name'         => $clientSnapshot['client_name'],
                ':client_fiscal_code'  => $clientSnapshot['client_fiscal_code'],
                ':client_reg_com'      => $clientSnapshot['client_reg_com'],
                ':client_contact'      => $clientSnapshot['client_contact'],
                ':client_email'        => $clientSnapshot['client_email'],
                ':client_phone'        => $clientSnapshot['client_phone'],
                ':client_bank'         => $clientSnapshot['client_bank'],
                ':client_iban'         => $clientSnapshot['client_iban'],
                ':client_country'      => $clientSnapshot['client_country'],
                ':client_county'       => $clientSnapshot['client_county'],
                ':client_city'         => $clientSnapshot['client_city'],
                ':client_address'      => $clientSnapshot['client_address'],
                ':invoice_language'    => 'RO',
                ':mentions'            => (string)($options['mentions'] ?? ''),
                ':observations'        => (string)($options['observations'] ?? ''),
                ':notes'               => (string)($options['notes'] ?? ''),
                ':created_by'          => $createdBy,
                ':revenue_category'    => $invoiceRevenueCategory,
            ];

            $stmt = $pdo->prepare("
                INSERT INTO smartbill_invoices (
                    appointment_id, client_id, client_location_id,
                    invoice_date, due_date, currency,
                    net_amount, vat_code, vat_amount, gross_amount,
                    smartbill_status, source_type,
                    client_name, client_fiscal_code, client_reg_com,
                    client_contact, client_email, client_phone,
                    client_bank, client_iban,
                    client_country, client_county, client_city, client_address,
                    invoice_language, mentions, observations, notes, created_by,
                    revenue_category
                ) VALUES (
                    :appointment_id, :client_id, :client_location_id,
                    :invoice_date, :due_date, :currency,
                    :net_amount, :vat_code, :vat_amount, :gross_amount,
                    'draft', :source_type,
                    :client_name, :client_fiscal_code, :client_reg_com,
                    :client_contact, :client_email, :client_phone,
                    :client_bank, :client_iban,
                    :client_country, :client_county, :client_city, :client_address,
                    :invoice_language, :mentions, :observations, :notes, :created_by,
                    :revenue_category
                )
            ");
            $stmt->execute($invoiceData);
            $newInvoiceId = (int)$pdo->lastInsertId();

            // INSERT smartbill_invoice_items — câte o linie per billing_item.
            $insertItemStmt = $pdo->prepare("
                INSERT INTO smartbill_invoice_items (
                    smartbill_invoice_id, appointment_id, service_id,
                    description, quantity, unit_name, unit_price,
                    vat_code, is_tax_included, is_service, line_total
                ) VALUES (
                    :invoice_id, :appointment_id, :service_id,
                    :description, :quantity, :unit_name, :unit_price,
                    :vat_code, 0, 1, :line_total
                )
            ");
            foreach ($items as $item) {
                $quantity = (float)($item['quantity'] ?? 1);
                if ($quantity <= 0) {
                    $quantity = 1.0;
                }
                $unitPrice = pz_billing_money($item['unit_price_net'] ?? 0);
                $lineTotal = round($unitPrice * $quantity, 2);

                $insertItemStmt->execute([
                    ':invoice_id'     => $newInvoiceId,
                    ':appointment_id' => $item['appointment_id'] !== null ? (int)$item['appointment_id'] : null,
                    ':service_id'     => $item['service_id'] !== null ? (int)$item['service_id'] : null,
                    ':description'    => (string)($item['description'] ?? ''),
                    ':quantity'       => $quantity,
                    ':unit_name'      => (string)($item['unit'] ?? 'buc'),
                    ':unit_price'     => $unitPrice,
                    ':vat_code'       => (string)($item['vat_code'] ?? $primaryVatCode),
                    ':line_total'     => $lineTotal,
                ]);
            }

            // Marchează billing_items ca facturate (link la factură).
            $itemIds = array_values(array_map(static fn($i) => (int)$i['id'], $items));
            pz_billing_mark_invoiced($pdo, $itemIds, $newInvoiceId);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('pz_billing_issue_invoice INSERT: ' . $e->getMessage());
            return ['ok' => false, 'invoice_id' => 0, 'draft' => false, 'error' => 'Eroare la salvare factură: ' . $e->getMessage()];
        }

        // Pasul opțional: transmite la SmartBill dacă e activ și utilizatorul a cerut-o.
        $sendToSmartbill = !empty($options['send_to_smartbill']);
        $smartbillEnabled = ((string)($settings['smartbill.enabled'] ?? '0') === '1');

        if ($sendToSmartbill && $smartbillEnabled && function_exists('pz_smartbill_issue_invoice')) {
            $result = pz_smartbill_issue_invoice($pdo, $newInvoiceId);
            if (empty($result['ok'])) {
                // Factura locală există (draft). Lăsăm utilizatorul să reîncerce.
                return [
                    'ok'         => false,
                    'invoice_id' => $newInvoiceId,
                    'draft'      => true,
                    'error'      => (string)($result['error'] ?? 'Eroare la transmiterea către SmartBill.'),
                ];
            }
            return ['ok' => true, 'invoice_id' => $newInvoiceId, 'draft' => false, 'error' => null];
        }

        // Rămâne ca draft local (utilizatorul poate emite ulterior din invoice.php).
        return ['ok' => true, 'invoice_id' => $newInvoiceId, 'draft' => true, 'error' => null];
    }
}
