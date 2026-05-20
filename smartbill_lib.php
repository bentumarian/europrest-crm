<?php
require_once __DIR__ . '/settings_lib.php';

if (!function_exists('pz_smartbill_defaults')) {
    function pz_smartbill_defaults(): array
    {
        $company = function_exists('pz_company_defaults') ? pz_company_defaults() : [];

        return [
            'smartbill.enabled' => '0',
            'smartbill.api_email' => '',
            'smartbill.api_token' => '',
            'smartbill.company_vat_code' => (string)($company['company.cui'] ?? ''),
            'smartbill.invoice_series' => '',
            'smartbill.receipt_series' => '',
            'smartbill.default_vat_code' => '21',
            'smartbill.allowed_vat_codes' => '21,11,0,0_invers,0_intracomunitar',
            'smartbill.payment_due_days' => '15',
            'smartbill.email_from_crm' => '1',
            'smartbill.efactura_auto_check' => '1',
            'smartbill.issue_only_on_confirmation' => '1',
        ];
    }
}

if (!function_exists('pz_smartbill_vat_options')) {
    function pz_smartbill_vat_options(): array
    {
        return [
            '21' => '21%',
            '11' => '11%',
            '0' => '0%',
            '0_invers' => '0% - Taxare inversa',
            '0_intracomunitar' => '0% - Taxare intracomunițară',
            '0_tva_inclus' => '0% - TVA inclus',
            '0_sdd' => '0% - SDD',
            '0_sfdd' => '0% - SFDD',
            '19' => '19%',
            '9' => '9%',
            '5' => '5%',
        ];
    }
}

if (!function_exists('pz_smartbill_settings')) {
    function pz_smartbill_settings(?PDO $pdoArg = null): array
    {
        if (!$pdoArg instanceof PDO) {
            global $pdo;
            $pdoArg = $pdo ?? null;
        }

        $defaults = pz_smartbill_defaults();
        if (!$pdoArg instanceof PDO) {
            return $defaults;
        }

        $settings = pz_settings_get_all($pdoArg, $defaults);
        if (trim((string)($settings['smartbill.company_vat_code'] ?? '')) === '' && function_exists('pz_company_settings')) {
            $company = pz_company_settings($pdoArg);
            $settings['smartbill.company_vat_code'] = (string)($company['company.cui'] ?? '');
        }

        return $settings;
    }
}

if (!function_exists('pz_smartbill_allowed_vat_codes')) {
    function pz_smartbill_allowed_vat_codes(array $settings): array
    {
        $raw = (string)($settings['smartbill.allowed_vat_codes'] ?? '');
        $codes = array_values(array_filter(array_map('trim', explode(',', $raw)), static function ($v) {
            return $v !== '';
        }));
        $valid = array_keys(pz_smartbill_vat_options());
        $codes = array_values(array_intersect($codes, $valid));
        return $codes ?: ['21'];
    }
}

if (!function_exists('pz_smartbill_save_settings')) {
    function pz_smartbill_save_settings(PDO $pdo, array $post): void
    {
        $current = pz_smartbill_settings($pdo);
        $vatOptions = pz_smartbill_vat_options();
        $allowed = array_values(array_intersect((array)($post['smartbill_allowed_vat_codes'] ?? []), array_keys($vatOptions)));
        if (!$allowed) {
            $allowed = ['21'];
        }

        $defaultVat = trim((string)($post['smartbill_default_vat_code'] ?? '21'));
        if (!isset($vatOptions[$defaultVat])) {
            $defaultVat = '21';
        }
        if (!in_array($defaultVat, $allowed, true)) {
            $allowed[] = $defaultVat;
        }

        $apiToken = trim((string)($post['smartbill_api_token'] ?? ''));
        if ($apiToken === '********') {
            $apiToken = (string)($current['smartbill.api_token'] ?? '');
        }

        $dueDays = (int)($post['smartbill_payment_due_days'] ?? 15);
        $dueDays = max(0, min(365, $dueDays));

        pz_settings_set_many($pdo, [
            'smartbill.enabled' => !empty($post['smartbill_enabled']) ? '1' : '0',
            'smartbill.api_email' => trim((string)($post['smartbill_api_email'] ?? '')),
            'smartbill.api_token' => $apiToken,
            'smartbill.company_vat_code' => trim((string)($post['smartbill_company_vat_code'] ?? '')),
            'smartbill.invoice_series' => trim((string)($post['smartbill_invoice_series'] ?? '')),
            'smartbill.receipt_series' => trim((string)($post['smartbill_receipt_series'] ?? '')),
            'smartbill.default_vat_code' => $defaultVat,
            'smartbill.allowed_vat_codes' => implode(',', array_values(array_unique($allowed))),
            'smartbill.payment_due_days' => (string)$dueDays,
            'smartbill.email_from_crm' => !empty($post['smartbill_email_from_crm']) ? '1' : '0',
            'smartbill.efactura_auto_check' => !empty($post['smartbill_efactura_auto_check']) ? '1' : '0',
            'smartbill.issue_only_on_confirmation' => '1',
        ]);
    }
}

if (!function_exists('pz_smartbill_ensure_schema')) {
    function pz_smartbill_ensure_schema(PDO $pdo): void
    {
        pz_settings_ensure_schema($pdo);

        $pdo->exec("CREATE TABLE IF NOT EXISTS smartbill_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            appointment_id INT NULL,
            client_id INT NULL,
            client_location_id INT NULL,
            document_id INT NULL,
            smartbill_series VARCHAR(40) NULL,
            smartbill_number VARCHAR(80) NULL,
            smartbill_id VARCHAR(120) NULL,
            smartbill_url VARCHAR(255) NULL,
            pdf_path VARCHAR(255) NULL,
            invoice_date DATE NULL,
            due_date DATE NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'RON',
            net_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            vat_code VARCHAR(40) NOT NULL DEFAULT '21',
            vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            smartbill_status VARCHAR(40) NOT NULL DEFAULT 'draft',
            efactura_status VARCHAR(60) NULL,
            efactura_message TEXT NULL,
            last_status_check_at DATETIME NULL,
            email_sent_at DATETIME NULL,
            email_sent_to VARCHAR(190) NULL,
            request_json LONGTEXT NULL,
            response_json LONGTEXT NULL,
            error_message TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_smartbill_appointment (appointment_id),
            KEY idx_smartbill_client (client_id),
            KEY idx_smartbill_status (smartbill_status),
            KEY idx_smartbill_efactura (efactura_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $invoiceColumns = [
            'source_type' => "VARCHAR(30) NOT NULL DEFAULT 'appointment'",
            'client_name' => "VARCHAR(255) NULL",
            'client_fiscal_code' => "VARCHAR(80) NULL",
            'client_reg_com' => "VARCHAR(120) NULL",
            'client_contact' => "VARCHAR(180) NULL",
            'client_email' => "VARCHAR(190) NULL",
            'client_phone' => "VARCHAR(80) NULL",
            'client_bank' => "VARCHAR(180) NULL",
            'client_iban' => "VARCHAR(120) NULL",
            'client_country' => "VARCHAR(80) NULL",
            'client_county' => "VARCHAR(120) NULL",
            'client_city' => "VARCHAR(120) NULL",
            'client_address' => "VARCHAR(255) NULL",
            'invoice_language' => "VARCHAR(10) NOT NULL DEFAULT 'RO'",
            'mentions' => "TEXT NULL",
            'observations' => "TEXT NULL",
            'notes' => "TEXT NULL",
            'smartbill_paid_amount' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
            'smartbill_unpaid_amount' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
            'efactura_xml_path' => "VARCHAR(255) NULL",
            'efactura_pdf_path' => "VARCHAR(255) NULL",
            // Pentru facturi de storno: pointer la factura originala anulata.
            'reverses_invoice_id' => "INT NULL",
        ];
        foreach ($invoiceColumns as $column => $definition) {
            if (!pz_smartbill_column_exists($pdo, 'smartbill_invoices', $column)) {
                try {
                    $pdo->exec("ALTER TABLE smartbill_invoices ADD COLUMN {$column} {$definition}");
                } catch (Throwable $e) {
                    error_log('SmartBill invoices add column error: ' . $column . ' - ' . $e->getMessage());
                }
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS smartbill_invoice_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            smartbill_invoice_id INT NULL,
            appointment_id INT NULL,
            action VARCHAR(80) NOT NULL,
            status VARCHAR(60) NULL,
            message TEXT NULL,
            request_json LONGTEXT NULL,
            response_json LONGTEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_smartbill_log_invoice (smartbill_invoice_id),
            KEY idx_smartbill_log_appointment (appointment_id),
            KEY idx_smartbill_log_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS smartbill_invoice_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            smartbill_invoice_id INT NOT NULL,
            appointment_id INT NULL,
            service_id INT NULL,
            product_code VARCHAR(80) NULL,
            description VARCHAR(255) NOT NULL,
            product_description TEXT NULL,
            quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000,
            unit_name VARCHAR(40) NOT NULL DEFAULT 'buc',
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            vat_code VARCHAR(40) NOT NULL DEFAULT '21',
            is_tax_included TINYINT(1) NOT NULL DEFAULT 0,
            is_service TINYINT(1) NOT NULL DEFAULT 1,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_smartbill_item_invoice (smartbill_invoice_id),
            KEY idx_smartbill_item_appointment (appointment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $itemColumns = [
            'product_code' => "VARCHAR(80) NULL",
            'product_description' => "TEXT NULL",
            'is_tax_included' => "TINYINT(1) NOT NULL DEFAULT 0",
            'is_service' => "TINYINT(1) NOT NULL DEFAULT 1",
        ];
        foreach ($itemColumns as $column => $definition) {
            if (!pz_smartbill_column_exists($pdo, 'smartbill_invoice_items', $column)) {
                try {
                    $pdo->exec("ALTER TABLE smartbill_invoice_items ADD COLUMN {$column} {$definition}");
                } catch (Throwable $e) {
                    error_log('SmartBill items add column error: ' . $column . ' - ' . $e->getMessage());
                }
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS smartbill_invoice_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            smartbill_invoice_id INT NOT NULL,
            payment_type VARCHAR(40) NOT NULL DEFAULT 'op',
            payment_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) NOT NULL DEFAULT 'RON',
            document_series VARCHAR(40) NULL,
            document_number VARCHAR(80) NULL,
            bank_name VARCHAR(160) NULL,
            bank_account VARCHAR(120) NULL,
            notes TEXT NULL,
            smartbill_status VARCHAR(40) NULL,
            request_json LONGTEXT NULL,
            response_json LONGTEXT NULL,
            error_message TEXT NULL,
            synced_at DATETIME NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_smartbill_payment_invoice (smartbill_invoice_id),
            KEY idx_smartbill_payment_type (payment_type),
            KEY idx_smartbill_payment_date (payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $paymentColumns = [
            'request_json' => "LONGTEXT NULL",
            'response_json' => "LONGTEXT NULL",
            'error_message' => "TEXT NULL",
            'synced_at' => "DATETIME NULL",
        ];
        foreach ($paymentColumns as $column => $definition) {
            if (!pz_smartbill_column_exists($pdo, 'smartbill_invoice_payments', $column)) {
                try {
                    $pdo->exec("ALTER TABLE smartbill_invoice_payments ADD COLUMN {$column} {$definition}");
                } catch (Throwable $e) {
                    error_log('SmartBill payments add column error: ' . $column . ' - ' . $e->getMessage());
                }
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS smartbill_recurring_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_invoice_id INT NOT NULL,
            client_id INT NULL,
            title VARCHAR(180) NOT NULL DEFAULT 'Factura recurenta',
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            frequency VARCHAR(30) NOT NULL DEFAULT 'monthly',
            interval_value INT NOT NULL DEFAULT 1,
            day_of_month INT NULL,
            start_date DATE NOT NULL,
            next_issue_date DATE NOT NULL,
            end_date DATE NULL,
            auto_issue TINYINT(1) NOT NULL DEFAULT 0,
            auto_email TINYINT(1) NOT NULL DEFAULT 0,
            last_generated_invoice_id INT NULL,
            last_generated_at DATETIME NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_smartbill_rec_status_date (status, next_issue_date),
            KEY idx_smartbill_rec_template (template_invoice_id),
            KEY idx_smartbill_rec_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS smartbill_supplier_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_name VARCHAR(255) NOT NULL,
            supplier_fiscal_code VARCHAR(80) NULL,
            supplier_country VARCHAR(80) NULL,
            supplier_county VARCHAR(120) NULL,
            supplier_city VARCHAR(120) NULL,
            supplier_address VARCHAR(255) NULL,
            document_series VARCHAR(40) NULL,
            document_number VARCHAR(80) NULL,
            issue_date DATE NULL,
            due_date DATE NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'RON',
            net_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            efactura_status VARCHAR(60) NOT NULL DEFAULT 'nesincronizat',
            source VARCHAR(40) NOT NULL DEFAULT 'manual',
            xml_path VARCHAR(255) NULL,
            pdf_path VARCHAR(255) NULL,
            notes TEXT NULL,
            request_json LONGTEXT NULL,
            response_json LONGTEXT NULL,
            created_by INT NULL,
            imported_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_supplier_invoice_date (issue_date),
            KEY idx_supplier_invoice_status (efactura_status),
            KEY idx_supplier_invoice_supplier (supplier_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('pz_smartbill_column_exists')) {
    function pz_smartbill_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('pz_smartbill_money')) {
    function pz_smartbill_money($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], trim($value));
        }
        return is_numeric($value) ? max(0, round((float)$value, 2)) : 0.0;
    }
}

if (!function_exists('pz_smartbill_payment_types')) {
    function pz_smartbill_payment_types(): array
    {
        return [
            'chitanta' => 'Chitanța',
            'op' => 'Ordin de plata',
            'transfer_bancar' => 'Transfer bancar',
            'card' => 'Card',
            'card_online' => 'Card online',
            'numerar_alt' => 'Numerar / alta încasare',
            'ramburs' => 'Ramburs',
            'cec' => 'CEC',
            'bilet_ordin' => 'Bilet la ordin',
            'alta' => 'Alta încasare',
        ];
    }
}

if (!function_exists('pz_smartbill_payment_label')) {
    function pz_smartbill_payment_label(string $type): string
    {
        $types = pz_smartbill_payment_types();
        return $types[$type] ?? $types['alta'];
    }
}

if (!function_exists('pz_smartbill_payment_api_type')) {
    function pz_smartbill_payment_api_type(string $type): string
    {
        $map = [
            'chitanta' => 'Chitanta',
            'op' => 'Ordin plata',
            'transfer_bancar' => 'Extras de cont',
            'card' => 'Card',
            'card_online' => 'Card online',
            'numerar_alt' => 'Alta incasare',
            'ramburs' => 'Ramburs',
            'cec' => 'CEC',
            'bilet_ordin' => 'Bilet ordin',
            'alta' => 'Alta incasare',
        ];
        return $map[$type] ?? 'Alta incasare';
    }
}

if (!function_exists('pz_smartbill_payment_is_cash')) {
    function pz_smartbill_payment_is_cash(string $type): bool
    {
        return in_array($type, ['chitanta', 'numerar_alt'], true);
    }
}

if (!function_exists('pz_smartbill_tax_meta')) {
    function pz_smartbill_tax_meta(string $vatCode): array
    {
        $vatCode = trim($vatCode);
        if ($vatCode === '21') return ['taxName' => 'Normala', 'taxPercentage' => 21];
        if ($vatCode === '11') return ['taxName' => 'Redusa', 'taxPercentage' => 11];
        if ($vatCode === '19') return ['taxName' => 'Veche', 'taxPercentage' => 19];
        if ($vatCode === '9') return ['taxName' => 'Veche', 'taxPercentage' => 9];
        if ($vatCode === '5') return ['taxName' => 'Veche', 'taxPercentage' => 5];
        if ($vatCode === '0_invers') return ['taxName' => 'Taxare inversa', 'taxPercentage' => 0];
        if ($vatCode === '0_intracomunitar') return ['taxName' => 'Taxare intracomunițară', 'taxPercentage' => 0];
        if ($vatCode === '0_tva_inclus') return ['taxName' => 'TVA inclus', 'taxPercentage' => 0];
        if ($vatCode === '0_sdd') return ['taxName' => 'SDD', 'taxPercentage' => 0];
        if ($vatCode === '0_sfdd') return ['taxName' => 'SFDD', 'taxPercentage' => 0];
        return ['taxName' => 'Scutita', 'taxPercentage' => 0];
    }
}

if (!function_exists('pz_smartbill_response_value')) {
    function pz_smartbill_response_value(array $response, string $key): string
    {
        if (isset($response[$key])) {
            return (string)$response[$key];
        }
        foreach (['sbcResponse', 'sbcInvoicePaymentStatusResponse'] as $wrapper) {
            if (isset($response[$wrapper]) && is_array($response[$wrapper]) && isset($response[$wrapper][$key])) {
                return (string)$response[$wrapper][$key];
            }
        }
        return '';
    }
}

if (!function_exists('pz_smartbill_response_status_error')) {
    function pz_smartbill_response_status_error(array $response): string
    {
        $errorText = trim(pz_smartbill_response_value($response, 'errorText'));
        if ($errorText !== '') {
            return $errorText;
        }
        $status = $response['Response']['status'] ?? $response['status'] ?? null;
        if (is_array($status)) {
            $code = (int)($status['code'] ?? 0);
            if ($code !== 0) {
                return trim((string)($status['message'] ?? 'SmartBill a respins cererea.'));
            }
        }
        return '';
    }
}

if (!function_exists('pz_smartbill_fetch_invoice')) {
    function pz_smartbill_fetch_invoice(PDO $pdo, int $invoiceId): ?array
    {
        pz_smartbill_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT * FROM smartbill_invoices WHERE id = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM smartbill_invoice_items WHERE smartbill_invoice_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$invoiceId]);
        $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT * FROM smartbill_invoice_payments WHERE smartbill_invoice_id = ? ORDER BY payment_date ASC, id ASC");
        $stmt->execute([$invoiceId]);
        $invoice['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $invoice;
    }
}

if (!function_exists('pz_smartbill_paid_amount')) {
    function pz_smartbill_paid_amount(array $invoice): float
    {
        $total = 0.0;
        foreach (($invoice['payments'] ?? []) as $payment) {
            if (in_array((string)($payment['smartbill_status'] ?? ''), ['error', 'deleted'], true)) {
                continue;
            }
            $total += pz_smartbill_money($payment['amount'] ?? 0);
        }
        $smartbillPaid = pz_smartbill_money($invoice['smartbill_paid_amount'] ?? 0);
        return round(max($total, $smartbillPaid), 2);
    }
}

if (!function_exists('pz_smartbill_payment_status')) {
    function pz_smartbill_payment_status(array $invoice): string
    {
        $gross = pz_smartbill_money($invoice['gross_amount'] ?? 0);
        $paid = pz_smartbill_paid_amount($invoice);
        if ($gross <= 0 || $paid <= 0) {
            return 'neincasata';
        }
        if ($paid + 0.005 >= $gross) {
            return 'incasata';
        }
        return 'partial';
    }
}

if (!function_exists('pz_smartbill_payment_status_label')) {
    function pz_smartbill_payment_status_label(string $status): string
    {
        if ($status === 'incasata') return 'Incasata';
        if ($status === 'partial') return 'Partial incasata';
        return 'Neincasata';
    }
}

if (!function_exists('pz_smartbill_supplier_invoice_statuses')) {
    function pz_smartbill_supplier_invoice_statuses(): array
    {
        return [
            'nesincronizat' => 'Nesincronizat',
            'primit' => 'Primit',
            'validat' => 'Validat',
            'eroare' => 'Eroare',
            'arhivat' => 'Arhivat',
        ];
    }
}

if (!function_exists('pz_smartbill_save_supplier_invoice')) {
    function pz_smartbill_save_supplier_invoice(PDO $pdo, array $data): array
    {
        pz_smartbill_ensure_schema($pdo);
        $supplierName = trim((string)($data['supplier_name'] ?? ''));
        $grossAmount = pz_smartbill_money($data['gross_amount'] ?? 0);
        if ($supplierName === '') {
            return ['ok' => false, 'error' => 'Completează furnizorul.'];
        }
        if ($grossAmount <= 0) {
            return ['ok' => false, 'error' => 'Completează valoarea facturii primite.'];
        }

        $status = trim((string)($data['efactura_status'] ?? 'primit'));
        $statuses = pz_smartbill_supplier_invoice_statuses();
        if (!isset($statuses[$status])) {
            $status = 'primit';
        }

        $issueDate = trim((string)($data['issue_date'] ?? date('Y-m-d')));
        $issueObj = DateTime::createFromFormat('Y-m-d', $issueDate);
        if (!$issueObj || $issueObj->format('Y-m-d') !== $issueDate) {
            $issueDate = date('Y-m-d');
        }
        $dueDate = trim((string)($data['due_date'] ?? ''));
        $dueObj = $dueDate !== '' ? DateTime::createFromFormat('Y-m-d', $dueDate) : false;
        if (!$dueObj || $dueObj->format('Y-m-d') !== $dueDate) {
            $dueDate = null;
        }

        $stmt = $pdo->prepare("
            INSERT INTO smartbill_supplier_invoices
                (supplier_name, supplier_fiscal_code, supplier_country, supplier_county, supplier_city, supplier_address,
                 document_series, document_number, issue_date, due_date, currency, net_amount, vat_amount, gross_amount,
                 efactura_status, source, notes, imported_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([
            $supplierName,
            trim((string)($data['supplier_fiscal_code'] ?? '')) ?: null,
            trim((string)($data['supplier_country'] ?? 'Romania')) ?: 'Romania',
            trim((string)($data['supplier_county'] ?? '')) ?: null,
            trim((string)($data['supplier_city'] ?? '')) ?: null,
            trim((string)($data['supplier_address'] ?? '')) ?: null,
            trim((string)($data['document_series'] ?? '')) ?: null,
            trim((string)($data['document_number'] ?? '')) ?: null,
            $issueDate,
            $dueDate,
            trim((string)($data['currency'] ?? 'RON')) ?: 'RON',
            pz_smartbill_money($data['net_amount'] ?? 0),
            pz_smartbill_money($data['vat_amount'] ?? 0),
            $grossAmount,
            $status,
            trim((string)($data['source'] ?? 'manual')) ?: 'manual',
            trim((string)($data['notes'] ?? '')) ?: null,
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        return ['ok' => true, 'supplier_invoice_id' => (int)$pdo->lastInsertId()];
    }
}

if (!function_exists('pz_smartbill_add_payment')) {
    function pz_smartbill_add_payment(PDO $pdo, int $invoiceId, array $data): array
    {
        pz_smartbill_ensure_schema($pdo);
        $invoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Factura nu există.'];
        }

        $types = pz_smartbill_payment_types();
        $type = (string)($data['payment_type'] ?? 'op');
        if (!isset($types[$type])) {
            $type = 'alta';
        }
        $amount = pz_smartbill_money($data['amount'] ?? 0);
        $gross = pz_smartbill_money($invoice['gross_amount'] ?? 0);
        $paid = pz_smartbill_paid_amount($invoice);
        $remaining = max(0, round($gross - $paid, 2));
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'Completează suma incasata.'];
        }
        if ($remaining > 0 && $amount > $remaining + 0.005) {
            return ['ok' => false, 'error' => 'Suma depaseste soldul ramas al facturii.'];
        }

        $paymentDate = trim((string)($data['payment_date'] ?? date('Y-m-d')));
        $dateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $paymentDate) {
            $paymentDate = date('Y-m-d');
        }

        $stmt = $pdo->prepare("
            INSERT INTO smartbill_invoice_payments
                (smartbill_invoice_id, payment_type, payment_date, amount, currency, document_series, document_number,
                 bank_name, bank_account, notes, smartbill_status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoiceId,
            $type,
            $paymentDate,
            $amount,
            trim((string)($data['currency'] ?? ($invoice['currency'] ?? 'RON'))) ?: 'RON',
            trim((string)($data['document_series'] ?? '')) ?: null,
            trim((string)($data['document_number'] ?? '')) ?: null,
            trim((string)($data['bank_name'] ?? '')) ?: null,
            trim((string)($data['bank_account'] ?? '')) ?: null,
            trim((string)($data['notes'] ?? '')) ?: null,
            trim((string)($data['smartbill_status'] ?? 'manual')) ?: 'manual',
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        $paymentId = (int)$pdo->lastInsertId();
        $updatedInvoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
        $status = pz_smartbill_payment_status($updatedInvoice ?: $invoice);

        $pdo->prepare("
            INSERT INTO smartbill_invoice_logs
                (smartbill_invoice_id, appointment_id, action, status, message, created_by)
            VALUES (?, ?, 'payment_added', ?, ?, ?)
        ")->execute([
            $invoiceId,
            !empty($invoice['appointment_id']) ? (int)$invoice['appointment_id'] : null,
            $status,
            'Încasare adăugată: ' . pz_smartbill_payment_label($type) . ' ' . number_format($amount, 2, '.', '') . ' ' . (string)($invoice['currency'] ?? 'RON'),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        return ['ok' => true, 'payment_id' => $paymentId, 'status' => $status];
    }
}

if (!function_exists('pz_smartbill_validate_invoice')) {
    function pz_smartbill_validate_invoice(array $invoice, array $settings): array
    {
        $errors = [];
        foreach ([
            'smartbill.api_email' => 'Email API SmartBill lipsa.',
            'smartbill.api_token' => 'Token API SmartBill lipsa.',
            'smartbill.company_vat_code' => 'CIF firma SmartBill lipsa.',
            'smartbill.invoice_series' => 'Serie factura SmartBill lipsa.',
        ] as $key => $message) {
            if (trim((string)($settings[$key] ?? '')) === '') {
                $errors[] = $message;
            }
        }
        foreach ([
            'client_name' => 'Nume client lipsa.',
            'client_fiscal_code' => 'CUI/CNP client lipsa.',
            'client_country' => 'Țară client lipsa.',
            'client_county' => 'Județ client lipsa.',
            'client_city' => 'Oraș/localitate client lipsa.',
            'client_address' => 'Adresa client lipsa.',
            'invoice_date' => 'Data emitere lipsa.',
            'due_date' => 'Scadenta lipsa.',
        ] as $key => $message) {
            if (trim((string)($invoice[$key] ?? '')) === '') {
                $errors[] = $message;
            }
        }
        if (empty($invoice['items']) || !is_array($invoice['items'])) {
            $errors[] = 'Factura nu are pozitii.';
        } else {
            foreach ($invoice['items'] as $idx => $item) {
                if (trim((string)($item['description'] ?? '')) === '') {
                    $errors[] = 'Pozitia ' . ($idx + 1) . ' nu are descriere.';
                }
                if (pz_smartbill_money($item['unit_price'] ?? 0) <= 0) {
                    $errors[] = 'Pozitia ' . ($idx + 1) . ' nu are pret.';
                }
            }
        }
        return $errors;
    }
}

if (!function_exists('pz_smartbill_invoice_payload')) {
    function pz_smartbill_invoice_payload(array $invoice, array $settings, bool $isDraft = false): array
    {
        $products = [];
        foreach (($invoice['items'] ?? []) as $item) {
            $tax = pz_smartbill_tax_meta((string)($item['vat_code'] ?? ($invoice['vat_code'] ?? '21')));
            $products[] = [
                'name' => (string)($item['description'] ?? 'Servicii'),
                'code' => (string)($item['product_code'] ?? ''),
                'productDescription' => (string)($item['product_description'] ?? ''),
                'isDiscount' => false,
                'measuringUnitName' => (string)($item['unit_name'] ?? 'buc') ?: 'buc',
                'currency' => (string)($invoice['currency'] ?? 'RON') ?: 'RON',
                'quantity' => (float)($item['quantity'] ?? 1),
                'price' => pz_smartbill_money($item['unit_price'] ?? 0),
                'isTaxIncluded' => !empty($item['is_tax_included']),
                'taxName' => $tax['taxName'],
                'taxPercentage' => $tax['taxPercentage'],
                'isService' => !array_key_exists('is_service', $item) || !empty($item['is_service']),
                'saveToDb' => false,
            ];
        }

        return [
            'companyVatCode' => trim((string)($settings['smartbill.company_vat_code'] ?? '')),
            'client' => [
                'name' => (string)($invoice['client_name'] ?? ''),
                'vatCode' => (string)($invoice['client_fiscal_code'] ?? ''),
                'address' => (string)($invoice['client_address'] ?? ''),
                'regCom' => (string)($invoice['client_reg_com'] ?? ''),
                'isTaxPayer' => true,
                'contact' => (string)($invoice['client_contact'] ?? ''),
                'phone' => (string)($invoice['client_phone'] ?? ''),
                'city' => (string)($invoice['client_city'] ?? ''),
                'county' => (string)($invoice['client_county'] ?? ''),
                'country' => (string)($invoice['client_country'] ?? 'Romania'),
                'email' => (string)($invoice['client_email'] ?? ''),
                'bank' => (string)($invoice['client_bank'] ?? ''),
                'iban' => (string)($invoice['client_iban'] ?? ''),
                'saveToDb' => true,
            ],
            'issueDate' => (string)($invoice['invoice_date'] ?? date('Y-m-d')),
            'seriesName' => trim((string)($settings['smartbill.invoice_series'] ?? '')),
            'isDraft' => $isDraft,
            'dueDate' => (string)($invoice['due_date'] ?? date('Y-m-d')),
            'deliveryDate' => (string)($invoice['invoice_date'] ?? date('Y-m-d')),
            'language' => (string)($invoice['invoice_language'] ?? 'RO') ?: 'RO',
            'mentions' => (string)($invoice['mentions'] ?? ''),
            'observations' => (string)($invoice['observations'] ?? ($invoice['notes'] ?? '')),
            'precision' => 2,
            'products' => $products,
        ];
    }
}

if (!function_exists('pz_smartbill_api_post')) {
    function pz_smartbill_api_post(array $settings, string $path, array $payload): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'response' => null, 'error' => 'cURL nu este disponibil pe server.'];
        }

        $url = 'https://ws.smartbill.ro/SBORO/api' . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERPWD => trim((string)($settings['smartbill.api_email'] ?? '')) . ':' . trim((string)($settings['smartbill.api_token'] ?? '')),
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            $decoded = ['raw' => (string)$body];
        }

        $apiError = pz_smartbill_response_status_error($decoded);
        $ok = ($curlError === '' && $httpCode >= 200 && $httpCode < 300 && $apiError === '');

        return [
            'ok' => $ok,
            'http_code' => $httpCode,
            'response' => $decoded,
            'error' => $curlError ?: ($apiError ?: ($ok ? '' : 'SmartBill a respins cererea.')),
        ];
    }
}

if (!function_exists('pz_smartbill_api_get_binary')) {
    function pz_smartbill_api_get_binary(array $settings, string $path, array $query): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'cURL nu este disponibil pe server.'];
        }

        $url = 'https://ws.smartbill.ro/SBORO/api' . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream', 'Accept: application/json'],
            CURLOPT_USERPWD => trim((string)($settings['smartbill.api_email'] ?? '')) . ':' . trim((string)($settings['smartbill.api_token'] ?? '')),
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $isPdf = is_string($body) && strncmp($body, '%PDF', 4) === 0;
        $error = $curlError;
        if ($error === '' && !$isPdf) {
            $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
            if (is_array($decoded)) {
                $error = pz_smartbill_response_status_error($decoded) ?: 'SmartBill nu a returnat PDF-ul.';
            } else {
                $error = 'SmartBill nu a returnat PDF-ul.';
            }
        }

        return [
            'ok' => ($curlError === '' && $httpCode >= 200 && $httpCode < 300 && $isPdf),
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }
}

if (!function_exists('pz_smartbill_api_get')) {
    function pz_smartbill_api_get(array $settings, string $path, array $query): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'response' => null, 'error' => 'cURL nu este disponibil pe server.'];
        }

        $url = 'https://ws.smartbill.ro/SBORO/api' . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERPWD => trim((string)($settings['smartbill.api_email'] ?? '')) . ':' . trim((string)($settings['smartbill.api_token'] ?? '')),
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            $decoded = ['raw' => (string)$body];
        }

        $apiError = trim((string)($decoded['errorText'] ?? ($decoded['sbcInvoicePaymentStatusResponse']['errorText'] ?? '')));
        $ok = ($curlError === '' && $httpCode >= 200 && $httpCode < 300 && $apiError === '');

        return [
            'ok' => $ok,
            'http_code' => $httpCode,
            'response' => $decoded,
            'error' => $curlError ?: ($apiError ?: ($ok ? '' : 'SmartBill a respins cererea.')),
        ];
    }
}

if (!function_exists('pz_smartbill_api_delete')) {
    function pz_smartbill_api_delete(array $settings, string $path, array $query): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'response' => null, 'error' => 'cURL nu este disponibil pe server.'];
        }

        $url = 'https://ws.smartbill.ro/SBORO/api' . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERPWD => trim((string)($settings['smartbill.api_email'] ?? '')) . ':' . trim((string)($settings['smartbill.api_token'] ?? '')),
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            $decoded = ['raw' => (string)$body];
        }

        $apiError = pz_smartbill_response_status_error($decoded);
        $ok = ($curlError === '' && $httpCode >= 200 && $httpCode < 300 && $apiError === '');

        return [
            'ok' => $ok,
            'http_code' => $httpCode,
            'response' => $decoded,
            'error' => $curlError ?: ($apiError ?: ($ok ? '' : 'SmartBill a respins cererea.')),
        ];
    }
}

if (!function_exists('pz_smartbill_payment_payload')) {
    function pz_smartbill_payment_payload(array $invoice, array $settings, array $data, float $amount, string $paymentDate, string $type): array
    {
        $apiType = pz_smartbill_payment_api_type($type);
        $hasInvoiceRef = trim((string)($invoice['smartbill_series'] ?? '')) !== ''
            && trim((string)($invoice['smartbill_number'] ?? '')) !== '';

        // Payload minimal când avem o factură existentă în SmartBill.
        // SmartBill preia automat datele clientului de pe factura referită
        // în invoicesList, fără să mai valideze client.* trimis aici.
        // Eroarea „Ciful clientului de pe factura difera de ciful clientului incasarii"
        // apare când trimitem și client.* — SmartBill compară strict cu factura
        // și respinge orice diferență de format (RO prefix, spații, majuscule).
        $payload = [
            'companyVatCode' => trim((string)($settings['smartbill.company_vat_code'] ?? '')),
            'issueDate' => $paymentDate,
            'currency' => (string)($invoice['currency'] ?? 'RON') ?: 'RON',
            'language' => 'RO',
            'exchangeRate' => 1,
            'precision' => 2,
            'value' => $amount,
            'type' => $apiType,
            'isCash' => pz_smartbill_payment_is_cash($type),
            'text' => trim((string)($data['payment_text'] ?? 'Contravaloare factura')),
            'translatedText' => '',
            'isDraft' => false,
            'observation' => trim((string)($data['notes'] ?? '')),
            'useInvoiceDetails' => true,
            'invoicesList' => $hasInvoiceRef ? [[
                'seriesName' => (string)($invoice['smartbill_series'] ?? ''),
                'number' => (string)($invoice['smartbill_number'] ?? ''),
            ]] : [],
        ];

        // Doar dacă nu avem factura SmartBill (caz edge), adăugăm client.*
        // ca să poată fi creată o încasare standalone.
        if (!$hasInvoiceRef) {
            $invoiceVatCode = preg_replace('/^\s*ro\s*/i', '', trim((string)($invoice['client_fiscal_code'] ?? '')));
            $payload['client'] = [
                'name' => (string)($invoice['client_name'] ?? ''),
                'vatCode' => (string)$invoiceVatCode,
                'address' => (string)($invoice['client_address'] ?? ''),
                'isTaxPayer' => true,
                'city' => (string)($invoice['client_city'] ?? ''),
                'county' => (string)($invoice['client_county'] ?? ''),
                'country' => (string)($invoice['client_country'] ?? 'Romania'),
                'email' => (string)($invoice['client_email'] ?? ''),
                'saveToDb' => true,
            ];
            $payload['useInvoiceDetails'] = false;
        }

        if ($type === 'chitanta') {
            $receiptSeries = trim((string)($data['document_series'] ?? ''));
            if ($receiptSeries === '') {
                $receiptSeries = trim((string)($settings['smartbill.receipt_series'] ?? ''));
            }
            if ($receiptSeries !== '') {
                $payload['seriesName'] = $receiptSeries;
            }
        }

        return $payload;
    }
}

if (!function_exists('pz_smartbill_issue_payment')) {
    function pz_smartbill_issue_payment(PDO $pdo, int $invoiceId, array $data): array
    {
        pz_smartbill_ensure_schema($pdo);
        $settings = pz_smartbill_settings($pdo);
        $invoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Factura nu există.'];
        }
        if (trim((string)($invoice['smartbill_series'] ?? '')) === '' || trim((string)($invoice['smartbill_number'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'Factura trebuie emisa în SmartBill înainte de încasare.'];
        }
        foreach ([
            'smartbill.api_email' => 'Email API SmartBill lipsa.',
            'smartbill.api_token' => 'Token API SmartBill lipsa.',
            'smartbill.company_vat_code' => 'CIF firma SmartBill lipsa.',
        ] as $key => $message) {
            if (trim((string)($settings[$key] ?? '')) === '') {
                return ['ok' => false, 'error' => $message];
            }
        }

        $types = pz_smartbill_payment_types();
        $type = (string)($data['payment_type'] ?? 'op');
        if (!isset($types[$type])) {
            $type = 'alta';
        }

        $amount = pz_smartbill_money($data['amount'] ?? 0);
        $gross = pz_smartbill_money($invoice['gross_amount'] ?? 0);
        $paid = pz_smartbill_paid_amount($invoice);
        $remaining = max(0, round($gross - $paid, 2));
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'Completează suma incasata.'];
        }
        if ($remaining > 0 && $amount > $remaining + 0.005) {
            return ['ok' => false, 'error' => 'Suma depaseste soldul ramas al facturii.'];
        }

        $paymentDate = trim((string)($data['payment_date'] ?? date('Y-m-d')));
        $dateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $paymentDate) {
            $paymentDate = date('Y-m-d');
        }

        $payload = pz_smartbill_payment_payload($invoice, $settings, $data, $amount, $paymentDate, $type);
        $result = pz_smartbill_api_post($settings, '/payment', $payload);
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $ok = !empty($result['ok']);
        $error = (string)($result['error'] ?? '');
        $series = pz_smartbill_response_value($response, 'series');
        $number = pz_smartbill_response_value($response, 'number');

        $stmt = $pdo->prepare("
            INSERT INTO smartbill_invoice_payments
                (smartbill_invoice_id, payment_type, payment_date, amount, currency, document_series, document_number,
                 bank_name, bank_account, notes, smartbill_status, request_json, response_json, error_message, synced_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoiceId,
            $type,
            $paymentDate,
            $amount,
            trim((string)($data['currency'] ?? ($invoice['currency'] ?? 'RON'))) ?: 'RON',
            $series ?: (trim((string)($data['document_series'] ?? '')) ?: null),
            $number ?: (trim((string)($data['document_number'] ?? '')) ?: null),
            trim((string)($data['bank_name'] ?? '')) ?: null,
            trim((string)($data['bank_account'] ?? '')) ?: null,
            trim((string)($data['notes'] ?? '')) ?: null,
            $ok ? 'issued' : 'error',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $error ?: null,
            $ok ? date('Y-m-d H:i:s') : null,
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        $paymentId = (int)$pdo->lastInsertId();
        $updatedInvoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
        $status = pz_smartbill_payment_status($updatedInvoice ?: $invoice);

        $pdo->prepare("
            INSERT INTO smartbill_invoice_logs
                (smartbill_invoice_id, appointment_id, action, status, message, request_json, response_json, created_by)
            VALUES (?, ?, 'issue_payment', ?, ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            !empty($invoice['appointment_id']) ? (int)$invoice['appointment_id'] : null,
            $ok ? $status : 'error',
            $ok ? ('Încasare emisa în SmartBill: ' . pz_smartbill_payment_label($type) . ' ' . number_format($amount, 2, '.', '') . ' ' . (string)($invoice['currency'] ?? 'RON')) : ($error ?: 'Încasarea nu a putut fi emisă.'),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        return [
            'ok' => $ok,
            'payment_id' => $paymentId,
            'status' => $status,
            'series' => $series,
            'number' => $number,
            'error' => $error,
            'response' => $response,
            'payload' => $payload,
        ];
    }
}

if (!function_exists('pz_smartbill_delete_receipt')) {
    function pz_smartbill_delete_receipt(PDO $pdo, int $paymentId): array
    {
        pz_smartbill_ensure_schema($pdo);
        $settings = pz_smartbill_settings($pdo);
        $stmt = $pdo->prepare("
            SELECT p.*, i.appointment_id
            FROM smartbill_invoice_payments p
            LEFT JOIN smartbill_invoices i ON i.id = p.smartbill_invoice_id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            return ['ok' => false, 'error' => 'Chitanța nu există.'];
        }
        if ((string)($payment['payment_type'] ?? '') !== 'chitanta') {
            return ['ok' => false, 'error' => 'Doar chitantele se sterg prin acest flux.'];
        }
        if ((string)($payment['smartbill_status'] ?? '') === 'deleted') {
            return ['ok' => true, 'message' => 'Chitanța era deja ștearsă.'];
        }
        $series = trim((string)($payment['document_series'] ?? ''));
        $number = trim((string)($payment['document_number'] ?? ''));
        if ($series === '' || $number === '') {
            return ['ok' => false, 'error' => 'Lipseste seria sau numarul chitantei.'];
        }

        foreach ([
            'smartbill.api_email' => 'Email API SmartBill lipsa.',
            'smartbill.api_token' => 'Token API SmartBill lipsa.',
            'smartbill.company_vat_code' => 'CIF firma SmartBill lipsa.',
        ] as $key => $message) {
            if (trim((string)($settings[$key] ?? '')) === '') {
                return ['ok' => false, 'error' => $message];
            }
        }

        $query = [
            'cif' => trim((string)($settings['smartbill.company_vat_code'] ?? '')),
            'seriesName' => $series,
            'number' => $number,
        ];
        $result = pz_smartbill_api_delete($settings, '/payment/chitanța', $query);
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $ok = !empty($result['ok']);
        if ($ok) {
            $pdo->prepare("
                UPDATE smartbill_invoice_payments
                SET smartbill_status = 'deleted',
                    response_json = ?,
                    error_message = NULL,
                    synced_at = NOW()
                WHERE id = ?
            ")->execute([
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $paymentId,
            ]);
        }

        $pdo->prepare("
            INSERT INTO smartbill_invoice_logs
                (smartbill_invoice_id, appointment_id, action, status, message, request_json, response_json, created_by)
            VALUES (?, ?, 'delete_receipt', ?, ?, ?, ?, ?)
        ")->execute([
            (int)$payment['smartbill_invoice_id'],
            !empty($payment['appointment_id']) ? (int)$payment['appointment_id'] : null,
            $ok ? 'ok' : 'error',
            $ok ? ('Chitanța ' . $series . ' ' . $number . ' ștearsă dîn SmartBill.') : (string)($result['error'] ?? 'Chitanța nu a putut fi ștearsă.'),
            json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        return [
            'ok' => $ok,
            'error' => (string)($result['error'] ?? ''),
            'response' => $response,
        ];
    }
}

if (!function_exists('pz_smartbill_standalone_receipt_payload')) {
    function pz_smartbill_standalone_receipt_payload(array $settings, array $data, float $amount, string $paymentDate): array
    {
        $type = (string)($data['payment_type'] ?? 'chitanta');
        $types = pz_smartbill_payment_types();
        if (!isset($types[$type])) {
            $type = 'chitanta';
        }
        $payload = [
            'companyVatCode' => trim((string)($settings['smartbill.company_vat_code'] ?? '')),
            'client' => [
                'name' => trim((string)($data['client_name'] ?? '')),
                'vatCode' => trim((string)($data['client_fiscal_code'] ?? '')),
                'regCom' => trim((string)($data['client_reg_com'] ?? '')),
                'address' => trim((string)($data['client_address'] ?? '')),
                'isTaxPayer' => true,
                'city' => trim((string)($data['client_city'] ?? '')),
                'county' => trim((string)($data['client_county'] ?? '')),
                'country' => trim((string)($data['client_country'] ?? 'Romania')) ?: 'Romania',
                'email' => trim((string)($data['client_email'] ?? '')),
                'saveToDb' => true,
            ],
            'issueDate' => $paymentDate,
            'currency' => trim((string)($data['currency'] ?? 'RON')) ?: 'RON',
            'language' => 'RO',
            'exchangeRate' => 1,
            'precision' => 2,
            'value' => $amount,
            'type' => pz_smartbill_payment_api_type($type),
            'isCash' => pz_smartbill_payment_is_cash($type),
            'text' => trim((string)($data['payment_text'] ?? 'Încasare fără factură')) ?: 'Încasare fără factură',
            'translatedText' => '',
            'isDraft' => false,
            'observation' => trim((string)($data['notes'] ?? '')),
        ];

        if ($type === 'chitanta') {
            $receiptSeries = trim((string)($data['document_series'] ?? ''));
            if ($receiptSeries === '') {
                $receiptSeries = trim((string)($settings['smartbill.receipt_series'] ?? ''));
            }
            if ($receiptSeries !== '') {
                $payload['seriesName'] = $receiptSeries;
            }
        }

        return $payload;
    }
}

if (!function_exists('pz_smartbill_issue_standalone_receipt')) {
    function pz_smartbill_issue_standalone_receipt(PDO $pdo, array $data): array
    {
        pz_smartbill_ensure_schema($pdo);
        $settings = pz_smartbill_settings($pdo);

        foreach ([
            'smartbill.api_email' => 'Email API SmartBill lipsa.',
            'smartbill.api_token' => 'Token API SmartBill lipsa.',
            'smartbill.company_vat_code' => 'CIF firma SmartBill lipsa.',
        ] as $key => $message) {
            if (trim((string)($settings[$key] ?? '')) === '') {
                return ['ok' => false, 'error' => $message];
            }
        }

        $amount = pz_smartbill_money($data['amount'] ?? 0);
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'Completează suma incasata.'];
        }

        $clientName = trim((string)($data['client_name'] ?? ''));
        $clientFiscalCode = trim((string)($data['client_fiscal_code'] ?? ''));
        $clientCountry = trim((string)($data['client_country'] ?? 'Romania')) ?: 'Romania';
        $clientCounty = trim((string)($data['client_county'] ?? ''));
        $clientCity = trim((string)($data['client_city'] ?? ''));
        $clientAddress = trim((string)($data['client_address'] ?? ''));
        if ($clientName === '' || $clientFiscalCode === '' || $clientCountry === '' || $clientCounty === '' || $clientCity === '' || $clientAddress === '') {
            return ['ok' => false, 'error' => 'Completează datele clientului pentru incasare.'];
        }

        $type = (string)($data['payment_type'] ?? 'chitanta');
        $types = pz_smartbill_payment_types();
        if (!isset($types[$type])) {
            $type = 'chitanta';
        }

        $paymentDate = trim((string)($data['payment_date'] ?? date('Y-m-d')));
        $dateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $paymentDate) {
            $paymentDate = date('Y-m-d');
        }

        $payload = pz_smartbill_standalone_receipt_payload($settings, $data, $amount, $paymentDate);
        $result = pz_smartbill_api_post($settings, '/payment', $payload);
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $ok = !empty($result['ok']);
        $error = (string)($result['error'] ?? '');
        $series = pz_smartbill_response_value($response, 'series');
        $number = pz_smartbill_response_value($response, 'number');

        $pdo->beginTransaction();
        try {
            $invoiceInsert = [
                'source_type' => 'receipt',
                'appointment_id' => null,
                'client_id' => max(0, (int)($data['client_id'] ?? 0)) ?: null,
                'client_location_id' => null,
                'client_name' => $clientName,
                'client_fiscal_code' => $clientFiscalCode,
                'client_reg_com' => trim((string)($data['client_reg_com'] ?? '')) ?: null,
                'client_contact' => trim((string)($data['client_contact'] ?? '')) ?: null,
                'client_email' => trim((string)($data['client_email'] ?? '')) ?: null,
                'client_phone' => trim((string)($data['client_phone'] ?? '')) ?: null,
                'client_country' => $clientCountry,
                'client_county' => $clientCounty,
                'client_city' => $clientCity,
                'client_address' => $clientAddress,
                'invoice_date' => $paymentDate,
                'due_date' => $paymentDate,
                'currency' => trim((string)($data['currency'] ?? 'RON')) ?: 'RON',
                'net_amount' => $amount,
                'vat_code' => '0',
                'vat_amount' => 0.00,
                'gross_amount' => $amount,
                'smartbill_status' => $ok ? 'receipt_only' : 'error',
                'notes' => trim((string)($data['notes'] ?? '')) ?: null,
                'request_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_message' => $error ?: null,
                'created_by' => function_exists('current_user_id') ? current_user_id() : null,
            ];
            $invoiceColumns = array_keys($invoiceInsert);
            $stmt = $pdo->prepare("
                INSERT INTO smartbill_invoices (`" . implode('`, `', $invoiceColumns) . "`)
                VALUES (" . implode(', ', array_fill(0, count($invoiceColumns), '?')) . ")
            ");
            $stmt->execute(array_values($invoiceInsert));
            $invoiceId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO smartbill_invoice_payments
                    (smartbill_invoice_id, payment_type, payment_date, amount, currency, document_series,
                     document_number, notes, smartbill_status, request_json, response_json, error_message, synced_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $invoiceId,
                $type,
                $paymentDate,
                $amount,
                trim((string)($data['currency'] ?? 'RON')) ?: 'RON',
                $series ?: (trim((string)($data['document_series'] ?? '')) ?: null),
                $number ?: null,
                trim((string)($data['notes'] ?? '')) ?: null,
                $ok ? 'issued' : 'error',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $error ?: null,
                $ok ? date('Y-m-d H:i:s') : null,
                function_exists('current_user_id') ? current_user_id() : null,
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO smartbill_invoice_logs
                    (smartbill_invoice_id, appointment_id, action, status, message, request_json, response_json, created_by)
                VALUES (?, NULL, 'issue_standalone_receipt', ?, ?, ?, ?, ?)
            ")->execute([
                $invoiceId,
                $ok ? 'ok' : 'error',
                $ok ? (pz_smartbill_payment_label($type) . ' emisa in SmartBill: ' . number_format($amount, 2, '.', '') . ' ' . (trim((string)($data['currency'] ?? 'RON')) ?: 'RON')) : ($error ?: 'Incasarea nu a putut fi emisa.'),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                function_exists('current_user_id') ? current_user_id() : null,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => $ok,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'series' => $series,
            'number' => $number,
            'error' => $error,
            'response' => $response,
            'payload' => $payload,
        ];
    }
}

if (!function_exists('pz_smartbill_invoice_pdf')) {
    function pz_smartbill_invoice_pdf(PDO $pdo, int $invoiceId): array
    {
        pz_smartbill_ensure_schema($pdo);
        $settings = pz_smartbill_settings($pdo);
        $invoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Factura nu există.'];
        }
        if (trim((string)($invoice['smartbill_series'] ?? '')) === '' || trim((string)($invoice['smartbill_number'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'Factura trebuie emisa în SmartBill înainte de PDF.'];
        }
        $query = [
            'cif' => trim((string)($settings['smartbill.company_vat_code'] ?? '')),
            'seriesname' => (string)$invoice['smartbill_series'],
            'number' => (string)$invoice['smartbill_number'],
        ];
        $result = pz_smartbill_api_get_binary($settings, '/invoice/pdf', $query);
        $pdo->prepare("
            INSERT INTO smartbill_invoice_logs
                (smartbill_invoice_id, appointment_id, action, status, message, request_json, created_by)
            VALUES (?, ?, 'invoice_pdf', ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            !empty($invoice['appointment_id']) ? (int)$invoice['appointment_id'] : null,
            !empty($result['ok']) ? 'ok' : 'error',
            !empty($result['ok']) ? 'PDF factura descarcat dîn SmartBill.' : (string)($result['error'] ?? 'PDF-ul nu a putut fi descarcat.'),
            json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);
        return $result + ['invoice' => $invoice];
    }
}

if (!function_exists('pz_smartbill_send_invoice_email')) {
    function pz_smartbill_send_invoice_email(PDO $pdo, int $invoiceId, string $to = ''): array
    {
        pz_smartbill_ensure_schema($pdo);
        $settings = pz_smartbill_settings($pdo);
        $invoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Factura nu există.'];
        }
        if (trim((string)($invoice['smartbill_series'] ?? '')) === '' || trim((string)($invoice['smartbill_number'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'Factura trebuie emisa în SmartBill înainte de email.'];
        }
        $to = trim($to) ?: trim((string)($invoice['client_email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email client lipsa sau invalid.'];
        }

        $subject = 'Factura ' . trim((string)(($invoice['smartbill_series'] ?? '') . ' ' . ($invoice['smartbill_number'] ?? '')));
        $body = "Bună ziua,<br><br>Atasat vă transmitem factura emisa pentru serviciile prestate.<br><br>Mulțumim pentru colaborare!";
        $payload = [
            'companyVatCode' => trim((string)($settings['smartbill.company_vat_code'] ?? '')),
            'seriesName' => (string)$invoice['smartbill_series'],
            'number' => (string)$invoice['smartbill_number'],
            'type' => 'factura',
            'subject' => base64_encode($subject),
            'to' => $to,
            'bodyText' => base64_encode($body),
        ];

        $result = pz_smartbill_api_post($settings, '/document/send', $payload);
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        if (!empty($result['ok'])) {
            $pdo->prepare("
                UPDATE smartbill_invoices
                SET email_sent_at = NOW(),
                    email_sent_to = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$to, $invoiceId]);
        }

        $pdo->prepare("
            INSERT INTO smartbill_invoice_logs
                (smartbill_invoice_id, appointment_id, action, status, message, request_json, response_json, created_by)
            VALUES (?, ?, 'send_invoice_email', ?, ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            !empty($invoice['appointment_id']) ? (int)$invoice['appointment_id'] : null,
            !empty($result['ok']) ? 'ok' : 'error',
            !empty($result['ok']) ? ('Factura trimisă pe email catre ' . $to . '.') : (string)($result['error'] ?? 'Emailul nu a putut fi trimis.'),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        return [
            'ok' => !empty($result['ok']),
            'error' => (string)($result['error'] ?? ''),
            'response' => $response,
            'payload' => $payload,
        ];
    }
}

if (!function_exists('pz_smartbill_recurring_next_date')) {
    function pz_smartbill_recurring_next_date(string $currentDate, string $frequency, int $intervalValue, ?int $dayOfMonth = null): string
    {
        $intervalValue = max(1, $intervalValue);
        $date = DateTime::createFromFormat('Y-m-d', $currentDate) ?: new DateTime();
        if ($frequency === 'weekly') {
            $date->modify('+' . $intervalValue . ' week');
        } elseif ($frequency === 'quarterly') {
            $date->modify('+' . (3 * $intervalValue) . ' month');
        } elseif ($frequency === 'yearly') {
            $date->modify('+' . $intervalValue . ' year');
        } else {
            $date->modify('+' . $intervalValue . ' month');
        }

        if ($dayOfMonth !== null && $dayOfMonth > 0 && $frequency !== 'weekly') {
            $lastDay = (int)$date->format('t');
            $date->setDate((int)$date->format('Y'), (int)$date->format('m'), min($dayOfMonth, $lastDay));
        }
        return $date->format('Y-m-d');
    }
}

if (!function_exists('pz_smartbill_create_recurring_schedule')) {
    function pz_smartbill_create_recurring_schedule(PDO $pdo, int $templateInvoiceId, array $data): array
    {
        pz_smartbill_ensure_schema($pdo);
        $invoice = pz_smartbill_fetch_invoice($pdo, $templateInvoiceId);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Factura model nu există.'];
        }

        $frequency = (string)($data['frequency'] ?? 'monthly');
        if (!in_array($frequency, ['weekly', 'monthly', 'quarterly', 'yearly'], true)) {
            $frequency = 'monthly';
        }
        $startDate = trim((string)($data['start_date'] ?? date('Y-m-d')));
        $dateObj = DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $startDate) {
            $startDate = date('Y-m-d');
        }
        $endDate = trim((string)($data['end_date'] ?? ''));
        $endObj = $endDate !== '' ? DateTime::createFromFormat('Y-m-d', $endDate) : false;
        if (!$endObj || $endObj->format('Y-m-d') !== $endDate) {
            $endDate = null;
        }

        $stmt = $pdo->prepare("
            INSERT INTO smartbill_recurring_invoices
                (template_invoice_id, client_id, title, frequency, interval_value, day_of_month, start_date,
                 next_issue_date, end_date, auto_issue, auto_email, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $templateInvoiceId,
            !empty($invoice['client_id']) ? (int)$invoice['client_id'] : null,
            trim((string)($data['title'] ?? '')) ?: 'Factura recurenta',
            $frequency,
            max(1, (int)($data['interval_value'] ?? 1)),
            ($frequency === 'weekly') ? null : max(1, min(31, (int)($data['day_of_month'] ?? (int)date('d', strtotime($startDate))))),
            $startDate,
            $startDate,
            $endDate,
            !empty($data['auto_issue']) ? 1 : 0,
            !empty($data['auto_email']) ? 1 : 0,
            trim((string)($data['notes'] ?? '')) ?: null,
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        return ['ok' => true, 'recurring_id' => (int)$pdo->lastInsertId()];
    }
}

if (!function_exists('pz_smartbill_clone_invoice_as_draft')) {
    function pz_smartbill_clone_invoice_as_draft(PDO $pdo, int $templateInvoiceId, string $invoiceDate): array
    {
        pz_smartbill_ensure_schema($pdo);
        $template = pz_smartbill_fetch_invoice($pdo, $templateInvoiceId);
        if (!$template) {
            return ['ok' => false, 'error' => 'Factura model nu există.'];
        }
        $settings = pz_smartbill_settings($pdo);
        $dueDays = max(0, (int)($settings['smartbill.payment_due_days'] ?? 15));
        $dueDate = date('Y-m-d', strtotime($invoiceDate . ' +' . $dueDays . ' days'));

        $stmt = $pdo->prepare("
            INSERT INTO smartbill_invoices
                (source_type, appointment_id, client_id, client_location_id, client_name, client_fiscal_code, client_reg_com,
                 client_contact, client_email, client_phone, client_bank, client_iban, client_country, client_county,
                 client_city, client_address, invoice_date, due_date, currency, net_amount, vat_code, vat_amount,
                 gross_amount, invoice_language, mentions, observations, smartbill_status, notes, created_by)
            VALUES ('recurring', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
        ");
        $stmt->execute([
            !empty($template['client_id']) ? (int)$template['client_id'] : null,
            !empty($template['client_location_id']) ? (int)$template['client_location_id'] : null,
            (string)($template['client_name'] ?? ''),
            (string)($template['client_fiscal_code'] ?? ''),
            (string)($template['client_reg_com'] ?? ''),
            (string)($template['client_contact'] ?? ''),
            (string)($template['client_email'] ?? ''),
            (string)($template['client_phone'] ?? ''),
            (string)($template['client_bank'] ?? ''),
            (string)($template['client_iban'] ?? ''),
            (string)($template['client_country'] ?? 'Romania'),
            (string)($template['client_county'] ?? ''),
            (string)($template['client_city'] ?? ''),
            (string)($template['client_address'] ?? ''),
            $invoiceDate,
            $dueDate,
            (string)($template['currency'] ?? 'RON'),
            pz_smartbill_money($template['net_amount'] ?? 0),
            (string)($template['vat_code'] ?? '21'),
            pz_smartbill_money($template['vat_amount'] ?? 0),
            pz_smartbill_money($template['gross_amount'] ?? 0),
            (string)($template['invoice_language'] ?? 'RO'),
            (string)($template['mentions'] ?? ''),
            (string)($template['observations'] ?? ''),
            (string)($template['notes'] ?? ''),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);
        $newInvoiceId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
            INSERT INTO smartbill_invoice_items
                (smartbill_invoice_id, appointment_id, service_id, product_code, description, product_description,
                 quantity, unit_name, unit_price, vat_code, is_tax_included, is_service, line_total, sort_order)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach (($template['items'] ?? []) as $item) {
            $itemStmt->execute([
                $newInvoiceId,
                !empty($item['service_id']) ? (int)$item['service_id'] : null,
                (string)($item['product_code'] ?? ''),
                (string)($item['description'] ?? 'Servicii'),
                (string)($item['product_description'] ?? ''),
                (float)($item['quantity'] ?? 1),
                (string)($item['unit_name'] ?? 'buc'),
                pz_smartbill_money($item['unit_price'] ?? 0),
                (string)($item['vat_code'] ?? ($template['vat_code'] ?? '21')),
                !empty($item['is_tax_included']) ? 1 : 0,
                !array_key_exists('is_service', $item) || !empty($item['is_service']) ? 1 : 0,
                pz_smartbill_money($item['line_total'] ?? 0),
                (int)($item['sort_order'] ?? 0),
            ]);
        }

        return ['ok' => true, 'invoice_id' => $newInvoiceId];
    }
}

if (!function_exists('pz_smartbill_generate_recurring_invoice')) {
    function pz_smartbill_generate_recurring_invoice(PDO $pdo, int $recurringId): array
    {
        pz_smartbill_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT * FROM smartbill_recurring_invoices WHERE id = ? LIMIT 1");
        $stmt->execute([$recurringId]);
        $recurring = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$recurring) {
            return ['ok' => false, 'error' => 'Recurența nu există.'];
        }
        if ((string)($recurring['status'] ?? '') !== 'active') {
            return ['ok' => false, 'error' => 'Recurența nu este activa.'];
        }
        $invoiceDate = (string)($recurring['next_issue_date'] ?? date('Y-m-d'));
        if (!empty($recurring['end_date']) && strtotime($invoiceDate) > strtotime((string)$recurring['end_date'])) {
            $pdo->prepare("UPDATE smartbill_recurring_invoices SET status = 'ended', updated_at = NOW() WHERE id = ?")->execute([$recurringId]);
            return ['ok' => false, 'error' => 'Recurența a ajuns la data de final.'];
        }

        $clone = pz_smartbill_clone_invoice_as_draft($pdo, (int)$recurring['template_invoice_id'], $invoiceDate);
        if (empty($clone['ok'])) {
            return $clone;
        }
        $invoiceId = (int)$clone['invoice_id'];
        $issueResult = ['ok' => false];
        if (!empty($recurring['auto_issue'])) {
            $issueResult = pz_smartbill_issue_invoice($pdo, $invoiceId);
            if (!empty($issueResult['ok']) && !empty($recurring['auto_email'])) {
                pz_smartbill_send_invoice_email($pdo, $invoiceId);
            }
        }

        $nextDate = pz_smartbill_recurring_next_date(
            $invoiceDate,
            (string)($recurring['frequency'] ?? 'monthly'),
            (int)($recurring['interval_value'] ?? 1),
            isset($recurring['day_of_month']) ? (int)$recurring['day_of_month'] : null
        );
        $pdo->prepare("
            UPDATE smartbill_recurring_invoices
            SET next_issue_date = ?,
                last_generated_invoice_id = ?,
                last_generated_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$nextDate, $invoiceId, $recurringId]);

        return ['ok' => true, 'invoice_id' => $invoiceId, 'issued' => !empty($issueResult['ok']), 'next_issue_date' => $nextDate];
    }
}

if (!function_exists('pz_smartbill_generate_due_recurring_invoices')) {
    function pz_smartbill_generate_due_recurring_invoices(PDO $pdo, ?string $date = null, int $limit = 20): array
    {
        pz_smartbill_ensure_schema($pdo);
        $date = $date ?: date('Y-m-d');
        $limit = max(1, min(100, $limit));
        $stmt = $pdo->prepare("
            SELECT id
            FROM smartbill_recurring_invoices
            WHERE status = 'active'
              AND next_issue_date <= ?
              AND (end_date IS NULL OR next_issue_date <= end_date)
            ORDER BY next_issue_date ASC, id ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$date]);

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $results[] = ['recurring_id' => $id] + pz_smartbill_generate_recurring_invoice($pdo, $id);
        }
        return $results;
    }
}

if (!function_exists('pz_smartbill_sync_invoice_payment_status')) {
    function pz_smartbill_sync_invoice_payment_status(PDO $pdo, int $invoiceId): array
    {
        pz_smartbill_ensure_schema($pdo);
        $settings = pz_smartbill_settings($pdo);
        $invoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Factura nu există.'];
        }
        if (trim((string)($invoice['smartbill_series'] ?? '')) === '' || trim((string)($invoice['smartbill_number'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'Factura trebuie emisa în SmartBill înainte de verificare.'];
        }

        $query = [
            'cif' => trim((string)($settings['smartbill.company_vat_code'] ?? '')),
            'seriesname' => (string)$invoice['smartbill_series'],
            'number' => (string)$invoice['smartbill_number'],
        ];
        $result = pz_smartbill_api_get($settings, '/invoice/paymentstatus', $query);
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $body = $response['sbcInvoicePaymentStatusResponse'] ?? $response;
        if (!is_array($body)) {
            $body = [];
        }

        $paid = pz_smartbill_money($body['paidAmount'] ?? 0);
        $unpaid = pz_smartbill_money($body['unpaidAmount'] ?? 0);
        $status = $paid <= 0 ? 'neincasata' : ($unpaid <= 0.005 ? 'incasata' : 'partial');
        $error = (string)($result['error'] ?? '');

        if (!empty($result['ok'])) {
            $pdo->prepare("
                UPDATE smartbill_invoices
                SET smartbill_paid_amount = ?,
                    smartbill_unpaid_amount = ?,
                    last_status_check_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$paid, $unpaid, $invoiceId]);
        }

        $pdo->prepare("
            INSERT INTO smartbill_invoice_logs
                (smartbill_invoice_id, appointment_id, action, status, message, request_json, response_json, created_by)
            VALUES (?, ?, 'payment_status_check', ?, ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            !empty($invoice['appointment_id']) ? (int)$invoice['appointment_id'] : null,
            !empty($result['ok']) ? $status : 'error',
            !empty($result['ok']) ? ('Status SmartBill: incasat ' . number_format($paid, 2, '.', '') . ', rest ' . number_format($unpaid, 2, '.', '')) : ($error ?: 'Statusul nu a putut fi verificat.'),
            json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        return [
            'ok' => !empty($result['ok']),
            'status' => $status,
            'paid' => $paid,
            'unpaid' => $unpaid,
            'error' => $error,
            'response' => $response,
        ];
    }
}

if (!function_exists('pz_smartbill_issue_invoice')) {
    function pz_smartbill_issue_invoice(PDO $pdo, int $invoiceId): array
    {
        pz_smartbill_ensure_schema($pdo);
        $settings = pz_smartbill_settings($pdo);
        $invoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Factura nu există.'];
        }
        if (trim((string)($invoice['smartbill_number'] ?? '')) !== '') {
            return ['ok' => false, 'error' => 'Factura este deja emisă.'];
        }

        $payload = pz_smartbill_invoice_payload($invoice, $settings, false);
        $errors = pz_smartbill_validate_invoice($invoice, $settings);
        if ($errors) {
            return ['ok' => false, 'error' => implode(' ', $errors), 'payload' => $payload];
        }

        $result = pz_smartbill_api_post($settings, '/invoice', $payload);
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $status = !empty($result['ok']) ? 'issued' : 'error';
        $series = pz_smartbill_response_value($response, 'series');
        $number = pz_smartbill_response_value($response, 'number');
        $url = pz_smartbill_response_value($response, 'url');
        $error = (string)($result['error'] ?? '');

        $stmt = $pdo->prepare("
            UPDATE smartbill_invoices
            SET smartbill_status = ?,
                smartbill_series = ?,
                smartbill_number = ?,
                smartbill_url = ?,
                request_json = ?,
                response_json = ?,
                error_message = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $status,
            $series ?: null,
            $number ?: null,
            $url ?: null,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $error ?: null,
            $invoiceId,
        ]);

        $pdo->prepare("
            INSERT INTO smartbill_invoice_logs
                (smartbill_invoice_id, appointment_id, action, status, message, request_json, response_json, created_by)
            VALUES (?, ?, 'issue_invoice', ?, ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            !empty($invoice['appointment_id']) ? (int)$invoice['appointment_id'] : null,
            $status,
            $error ?: 'Factura emisă în SmartBill.',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        // NOTĂ: Nu mai modificăm `appointments.billing_status` aici.
        // Marcajul „facturat" se face acum centralizat pe `billing_items`
        // prin orchestratorul `pz_billing_issue_invoice()` → `pz_billing_mark_invoiced()`.

        return [
            'ok' => !empty($result['ok']),
            'error' => $error,
            'series' => $series,
            'number' => $number,
            'response' => $response,
            'payload' => $payload,
        ];
    }
}

if (!function_exists('pz_smartbill_reverse_invoice')) {
    function pz_smartbill_reverse_invoice(PDO $pdo, int $invoiceId, ?string $issueDate = null): array
    {
        pz_smartbill_ensure_schema($pdo);
        $settings = pz_smartbill_settings($pdo);
        $invoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Factura nu exista.'];
        }
        $series = trim((string)($invoice['smartbill_series'] ?? ''));
        $number = trim((string)($invoice['smartbill_number'] ?? ''));
        if ($series === '' || $number === '') {
            return ['ok' => false, 'error' => 'Factura trebuie emisa in SmartBill inainte de storno.'];
        }
        $date = $issueDate ?: date('Y-m-d');
        $payload = [
            'companyVatCode' => trim((string)($settings['smartbill.company_vat_code'] ?? '')),
            'seriesName' => $series,
            'number' => $number,
            'issueDate' => $date,
        ];
        if ($payload['companyVatCode'] === '') {
            return ['ok' => false, 'error' => 'CIF firma SmartBill lipsa.'];
        }

        $result = pz_smartbill_api_post($settings, '/invoice/reverse', $payload);
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $error = (string)($result['error'] ?? '');
        $status = !empty($result['ok']) ? 'storno' : 'error';
        $stornoSeries = pz_smartbill_response_value($response, 'series');
        $stornoNumber = pz_smartbill_response_value($response, 'number');
        $stornoUrl    = pz_smartbill_response_value($response, 'url');
        $newInvoiceId = null;

        // Daca storno-ul a fost emis cu succes in SmartBill, salvam un rand nou
        // in smartbill_invoices ca factura storno sa apara si in lista din CRM.
        if (!empty($result['ok'])) {
            try {
                $netNeg   = -pz_smartbill_money($invoice['net_amount']   ?? 0);
                $vatNeg   = -pz_smartbill_money($invoice['vat_amount']   ?? 0);
                $grossNeg = -pz_smartbill_money($invoice['gross_amount'] ?? 0);
                $origRef  = trim($series . ' ' . $number);

                $stmtIns = $pdo->prepare("
                    INSERT INTO smartbill_invoices
                        (source_type, appointment_id, client_id, client_location_id,
                         client_name, client_fiscal_code, client_reg_com, client_contact,
                         client_email, client_phone, client_bank, client_iban,
                         client_country, client_county, client_city, client_address,
                         invoice_date, due_date, currency,
                         net_amount, vat_code, vat_amount, gross_amount,
                         smartbill_series, smartbill_number, smartbill_url, smartbill_status,
                         reverses_invoice_id, invoice_language, mentions, observations, notes,
                         request_json, response_json, created_by, created_at)
                    VALUES
                        ('storno', ?, ?, ?,
                         ?, ?, ?, ?,
                         ?, ?, ?, ?,
                         ?, ?, ?, ?,
                         ?, ?, ?,
                         ?, ?, ?, ?,
                         ?, ?, ?, 'storno',
                         ?, ?, ?, ?, ?,
                         ?, ?, ?, NOW())
                ");
                $stmtIns->execute([
                    !empty($invoice['appointment_id'])      ? (int)$invoice['appointment_id']      : null,
                    !empty($invoice['client_id'])           ? (int)$invoice['client_id']           : null,
                    !empty($invoice['client_location_id']) ? (int)$invoice['client_location_id'] : null,
                    (string)($invoice['client_name']        ?? ''),
                    (string)($invoice['client_fiscal_code'] ?? ''),
                    (string)($invoice['client_reg_com']     ?? ''),
                    (string)($invoice['client_contact']     ?? ''),
                    (string)($invoice['client_email']       ?? ''),
                    (string)($invoice['client_phone']       ?? ''),
                    (string)($invoice['client_bank']        ?? ''),
                    (string)($invoice['client_iban']        ?? ''),
                    (string)($invoice['client_country']     ?? 'Romania'),
                    (string)($invoice['client_county']      ?? ''),
                    (string)($invoice['client_city']        ?? ''),
                    (string)($invoice['client_address']     ?? ''),
                    $date,
                    $date,
                    (string)($invoice['currency'] ?? 'RON'),
                    $netNeg,
                    (string)($invoice['vat_code'] ?? '21'),
                    $vatNeg,
                    $grossNeg,
                    $stornoSeries ?: null,
                    $stornoNumber ?: null,
                    $stornoUrl    ?: null,
                    $invoiceId,
                    (string)($invoice['invoice_language'] ?? 'RO'),
                    (string)($invoice['mentions']    ?? ''),
                    'Factura de stornare pentru ' . $origRef,
                    'Stornare factura ' . $origRef . ' din ' . (string)($invoice['invoice_date'] ?? ''),
                    json_encode($payload,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    function_exists('current_user_id') ? current_user_id() : null,
                ]);
                $newInvoiceId = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                // Storno-ul a fost emis cu succes in SmartBill, dar insert-ul local a esuat.
                // Logam si continuam - storno-ul ramane valid contabil chiar daca lipseste in CRM.
                error_log('SmartBill storno insert local error: ' . $e->getMessage());
            }
        }

        $pdo->prepare("
            INSERT INTO smartbill_invoice_logs
                (smartbill_invoice_id, appointment_id, action, status, message, request_json, response_json, created_by)
            VALUES (?, ?, 'reverse_invoice', ?, ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            !empty($invoice['appointment_id']) ? (int)$invoice['appointment_id'] : null,
            $status,
            $error ?: ('Factura storno a fost adaugata in SmartBill' . ($newInvoiceId ? ' si in CRM (#' . $newInvoiceId . ').' : '.')),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        return [
            'ok'                => !empty($result['ok']),
            'error'             => $error,
            'response'          => $response,
            'payload'           => $payload,
            'storno_invoice_id' => $newInvoiceId,
        ];
    }
}

if (!function_exists('pz_smartbill_issue_invoice_from_estimate')) {
    function pz_smartbill_issue_invoice_from_estimate(PDO $pdo, int $invoiceId, string $estimateSeries, string $estimateNumber): array
    {
        pz_smartbill_ensure_schema($pdo);
        $settings = pz_smartbill_settings($pdo);
        $invoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Factura draft nu exista.'];
        }
        if (trim((string)($invoice['smartbill_number'] ?? '')) !== '') {
            return ['ok' => false, 'error' => 'Factura este deja emisa.'];
        }
        $estimateSeries = trim($estimateSeries);
        $estimateNumber = trim($estimateNumber);
        if ($estimateSeries === '' || $estimateNumber === '') {
            return ['ok' => false, 'error' => 'Completeaza seria si numarul proformei.'];
        }

        $payload = pz_smartbill_invoice_payload($invoice, $settings, false);
        $payload['useEstimateDetails'] = true;
        $payload['useStock'] = false;
        $payload['estimate'] = [
            'seriesName' => $estimateSeries,
            'number' => $estimateNumber,
        ];
        unset($payload['products']);

        $errors = [];
        foreach ([
            'smartbill.api_email' => 'Email API SmartBill lipsa.',
            'smartbill.api_token' => 'Token API SmartBill lipsa.',
            'smartbill.company_vat_code' => 'CIF firma SmartBill lipsa.',
            'smartbill.invoice_series' => 'Serie factura SmartBill lipsa.',
        ] as $key => $message) {
            if (trim((string)($settings[$key] ?? '')) === '') {
                $errors[] = $message;
            }
        }
        if ($errors) {
            return ['ok' => false, 'error' => implode(' ', $errors), 'payload' => $payload];
        }

        $result = pz_smartbill_api_post($settings, '/invoice', $payload);
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $status = !empty($result['ok']) ? 'issued' : 'error';
        $series = pz_smartbill_response_value($response, 'series');
        $number = pz_smartbill_response_value($response, 'number');
        $url = pz_smartbill_response_value($response, 'url');
        $error = (string)($result['error'] ?? '');

        $pdo->prepare("
            UPDATE smartbill_invoices
            SET smartbill_status = ?,
                smartbill_series = ?,
                smartbill_number = ?,
                smartbill_url = ?,
                request_json = ?,
                response_json = ?,
                error_message = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $status,
            $series ?: null,
            $number ?: null,
            $url ?: null,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $error ?: null,
            $invoiceId,
        ]);

        $pdo->prepare("
            INSERT INTO smartbill_invoice_logs
                (smartbill_invoice_id, appointment_id, action, status, message, request_json, response_json, created_by)
            VALUES (?, ?, 'issue_from_estimate', ?, ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            !empty($invoice['appointment_id']) ? (int)$invoice['appointment_id'] : null,
            $status,
            $error ?: 'Factura a fost emisa in SmartBill pe baza proformei ' . $estimateSeries . ' ' . $estimateNumber . '.',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);

        return [
            'ok' => !empty($result['ok']),
            'error' => $error,
            'series' => $series,
            'number' => $number,
            'response' => $response,
            'payload' => $payload,
        ];
    }
}
