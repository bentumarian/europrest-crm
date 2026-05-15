<?php
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| PestZone - document schema
|--------------------------------------------------------------------------
| Fisier central pentru noul motor de documente:
| - oferte
| - contracte
| - procese verbale
|
| Reguli:
| - nu sterge date vechi
| - nu foloseste SHOW ... LIKE, pentru compatibilitate cu MariaDB/cPanel
| - adauga doar tabele/coloane/indexuri lipsa
| - foloseste is_active pentru sabloane
|--------------------------------------------------------------------------
*/

if (!function_exists('pzdoc_valid_identifier')) {
    function pzdoc_valid_identifier(string $name): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_]+$/', $name);
    }
}

if (!function_exists('pzdoc_table_exists')) {
    function pzdoc_table_exists(PDO $pdo, string $table): bool
    {
        if (!pzdoc_valid_identifier($table)) {
            return false;
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*) AS total\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n        ");
        $stmt->execute([$table]);

        return (int)($stmt->fetchColumn() ?: 0) > 0;
    }
}

if (!function_exists('pzdoc_column_exists')) {
    function pzdoc_column_exists(PDO $pdo, string $table, string $column): bool
    {
        if (!pzdoc_valid_identifier($table) || !pzdoc_valid_identifier($column)) {
            return false;
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*) AS total\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n              AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);

        return (int)($stmt->fetchColumn() ?: 0) > 0;
    }
}

if (!function_exists('pzdoc_index_exists')) {
    function pzdoc_index_exists(PDO $pdo, string $table, string $index): bool
    {
        if (!pzdoc_valid_identifier($table) || !pzdoc_valid_identifier($index)) {
            return false;
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*) AS total\n            FROM INFORMATION_SCHEMA.STATISTICS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n              AND INDEX_NAME = ?\n        ");
        $stmt->execute([$table, $index]);

        return (int)($stmt->fetchColumn() ?: 0) > 0;
    }
}

if (!function_exists('pzdoc_add_column_if_missing')) {
    function pzdoc_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!pzdoc_valid_identifier($table) || !pzdoc_valid_identifier($column)) {
            return;
        }

        if (!pzdoc_table_exists($pdo, $table)) {
            return;
        }

        if (pzdoc_column_exists($pdo, $table, $column)) {
            return;
        }

        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (Throwable $e) {
            error_log('PestZone document schema column error: ' . $table . '.' . $column . ' - ' . $e->getMessage());
        }
    }
}

if (!function_exists('pzdoc_add_index_if_missing')) {
    function pzdoc_add_index_if_missing(PDO $pdo, string $table, string $index, string $sql): void
    {
        if (!pzdoc_valid_identifier($table) || !pzdoc_valid_identifier($index)) {
            return;
        }

        if (!pzdoc_table_exists($pdo, $table)) {
            return;
        }

        if (pzdoc_index_exists($pdo, $table, $index)) {
            return;
        }

        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('PestZone document schema index error: ' . $table . '.' . $index . ' - ' . $e->getMessage());
        }
    }
}

if (!function_exists('pzdoc_default_series_code')) {
    function pzdoc_default_series_code(string $documentType): string
    {
        $map = [
            'oferta' => 'OF',
            'contract' => 'CTR',
            'proces_verbal' => 'PV',
        ];

        return $map[$documentType] ?? 'DOC';
    }
}

if (!function_exists('pzdoc_default_series_name')) {
    function pzdoc_default_series_name(string $documentType): string
    {
        $map = [
            'oferta' => 'Oferte',
            'contract' => 'Contracte',
            'proces_verbal' => 'Procese verbale',
        ];

        return $map[$documentType] ?? 'Documente';
    }
}

if (!function_exists('pzdoc_default_template_content')) {
    function pzdoc_default_template_content(string $documentType): string
    {
        if ($documentType === 'oferta') {
            return '<h1 style="text-align:center;">{{document_title}}</h1>\n'
                . '<p style="text-align:center;"><strong>Oferta nr. {{document_number}} / {{document_date}}</strong><br>Valabila {{valid_days}} zile de la data emiterii</p>\n'
                . '<p><strong>Catre:</strong><br>{{client_block}}</p>\n'
                . '<p>{{offer_intro}}</p>\n'
                . '{{items_table}}\n'
                . '<p><strong>Subtotal servicii:</strong> {{subtotal_without_vat}}</p>\n'
                . '{{discount_block}}\n'
                . '<p><strong>Total oferta:</strong> {{total_without_vat}}</p>\n'
                . '<p><em>{{prices_without_vat_note}}</em></p>\n'
                . '<p><strong>Conditii de plata:</strong><br>{{payment_terms}}</p>\n'
                . '<p><strong>Observatii:</strong><br>{{notes}}</p>\n'
                . '<p>{{offer_footer}}</p>\n'
                . '<table width="100%" cellspacing="0" cellpadding="0" style="margin-top:25px;"><tr>'
                . '<td width="50%"><strong>Prestator,</strong><br>{{company_name}}<br>{{company_representative}}<br>{{company_stamp}}</td>'
                . '<td width="50%"><strong>Beneficiar,</strong><br>{{client_name}}<br>{{client_representative}}</td>'
                . '</tr></table>';
        }


        if ($documentType === 'contract') {
            return '<h1 style="text-align:center;">CONTRACT DE PRESTARI SERVICII DDD</h1>\n'
                . '<p><strong>Nr. contract:</strong> {{document_number}} din {{document_date}}</p>\n'
                . '<p><strong>Prestator:</strong> {{company_block}}</p>\n'
                . '<p><strong>Beneficiar:</strong> {{client_block}}</p>\n'
                . '<h2>Obiectul contractului</h2>\n'
                . '<p>Prestatorul se obliga sa execute servicii de dezinsectie, dezinfectie, deratizare, monitorizare si alte servicii conexe, prin mijloace tehnice, chimice sau fizice alese de prestator, conform cerintelor beneficiarului si legislatiei aplicabile.</p>\n'
                . '<h2>Locatii si servicii</h2>\n'
                . '{{items_table}}\n'
                . '<h2>Valoare contract</h2>\n'
                . '<p>{{document_total}} {{currency}}</p>\n'
                . '<h2>Observatii</h2>\n'
                . '<p>{{notes}}</p>\n'
                . '<table width="100%" cellspacing="0" cellpadding="0" style="margin-top:25px;"><tr>'
                . '<td width="50%"><strong>Prestator,</strong><br>{{company_name}}<br>{{company_representative}}<br>{{company_stamp}}</td>'
                . '<td width="50%"><strong>Beneficiar,</strong><br>{{client_name}}<br>{{client_representative}}</td>'
                . '</tr></table>';
        }

        return '<h1 style="text-align:center;">PROCES VERBAL DE EXECUTIE</h1>\n'
            . '<p><strong>Nr. PV:</strong> {{document_number}} din {{document_date}}, ora {{document_time}}</p>\n'
            . '<p><strong>Prestator:</strong> {{company_block}}</p>\n'
            . '<p><strong>Beneficiar:</strong> {{client_block}}</p>\n'
            . '<p><strong>Locatie interventie:</strong> {{location_block}}</p>\n'
            . '<h2>Servicii prestate</h2>\n'
            . '{{items_table}}\n'
            . '<h2>Biocide / materiale utilizate</h2>\n'
            . '{{materials_table}}\n'
            . '<h2>Observatii executant</h2>\n'
            . '<p>{{executor_notes}}</p>\n'
            . '<h2>Recomandari beneficiar</h2>\n'
            . '<p>{{recommendations}}</p>\n'
            . '<h2>Observatii beneficiar</h2>\n'
            . '<p>{{client_notes}}</p>';
    }
}

if (!function_exists('pzdoc_ensure_document_schema')) {
    function pzdoc_ensure_document_schema(PDO $pdo): void
    {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        /* Sabloane */
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS document_templates (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                document_type VARCHAR(50) NOT NULL,\n                name VARCHAR(180) NOT NULL,\n                slug VARCHAR(180) NULL,\n                description TEXT NULL,\n                content_html LONGTEXT NULL,\n                is_default TINYINT(1) NOT NULL DEFAULT 0,\n                is_active TINYINT(1) NOT NULL DEFAULT 1,\n                created_by INT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,\n                INDEX idx_document_templates_type_active (document_type, is_active, is_default),\n                INDEX idx_document_templates_slug (slug)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");

        $templateColumns = [
            'document_type' => "VARCHAR(50) NOT NULL",
            'name' => "VARCHAR(180) NOT NULL",
            'slug' => "VARCHAR(180) NULL",
            'description' => "TEXT NULL",
            'content_html' => "LONGTEXT NULL",
            'is_default' => "TINYINT(1) NOT NULL DEFAULT 0",
            'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
            'created_by' => "INT NULL",
            'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
        ];

        foreach ($templateColumns as $column => $definition) {
            pzdoc_add_column_if_missing($pdo, 'document_templates', $column, $definition);
        }

        if (pzdoc_column_exists($pdo, 'document_templates', 'active') && pzdoc_column_exists($pdo, 'document_templates', 'is_active')) {
            try {
                $pdo->exec("UPDATE document_templates SET is_active = active WHERE is_active IS NULL");
            } catch (Throwable $e) {
                error_log('PestZone document template active sync error: ' . $e->getMessage());
            }
        }

        pzdoc_add_index_if_missing($pdo, 'document_templates', 'idx_document_templates_type_active', 'CREATE INDEX idx_document_templates_type_active ON document_templates (document_type, is_active, is_default)');
        pzdoc_add_index_if_missing($pdo, 'document_templates', 'idx_document_templates_slug', 'CREATE INDEX idx_document_templates_slug ON document_templates (slug)');

        /* Documente generate */
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS documents (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                document_type VARCHAR(50) NOT NULL,\n                status VARCHAR(40) NOT NULL DEFAULT 'draft',\n                template_id INT NULL,\n                document_series_id INT NULL,\n                document_number_id INT NULL,\n                document_number VARCHAR(120) NULL,\n                document_date DATE NOT NULL,\n                document_time TIME NULL,\n                title VARCHAR(220) NOT NULL DEFAULT 'Document',\n                client_id INT NULL,\n                client_location_id INT NULL,\n                contract_id INT NULL,\n                appointment_id INT NULL,\n                source_document_id INT NULL,\n                client_name_snapshot VARCHAR(220) NULL,\n                client_identifier_snapshot VARCHAR(80) NULL,\n                client_registry_snapshot VARCHAR(120) NULL,\n                client_address_snapshot TEXT NULL,\n                client_representative_snapshot VARCHAR(220) NULL,\n                client_email_snapshot VARCHAR(180) NULL,\n                client_phone_snapshot VARCHAR(80) NULL,\n                location_name_snapshot VARCHAR(220) NULL,\n                location_address_snapshot TEXT NULL,\n                location_contact_snapshot VARCHAR(220) NULL,\n                location_phone_snapshot VARCHAR(80) NULL,\n                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n                vat_percent DECIMAL(7,2) NOT NULL DEFAULT 0.00,\n                vat_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n                total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n                currency VARCHAR(10) NOT NULL DEFAULT 'RON',\n                content_html LONGTEXT NULL,\n                payload_json LONGTEXT NULL,\n                notes TEXT NULL,\n                executor_notes TEXT NULL,\n                recommendations TEXT NULL,\n                client_notes TEXT NULL,\n                internal_notes TEXT NULL,\n                email_sent_at DATETIME NULL,\n                email_sent_to VARCHAR(255) NULL,\n                email_sent_count INT NOT NULL DEFAULT 0,\n                created_by INT NULL,\n                issued_by INT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,\n                issued_at DATETIME NULL,\n                locked_at DATETIME NULL,\n                cancelled_at DATETIME NULL,\n                INDEX idx_documents_type_date (document_type, document_date),\n                INDEX idx_documents_status (status),\n                INDEX idx_documents_client (client_id),\n                INDEX idx_documents_location (client_location_id),\n                INDEX idx_documents_number (document_number)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");

        $documentColumns = [
            'document_type' => "VARCHAR(50) NOT NULL",
            'status' => "VARCHAR(40) NOT NULL DEFAULT 'draft'",
            'template_id' => "INT NULL",
            'document_series_id' => "INT NULL",
            'document_number_id' => "INT NULL",
            'document_number' => "VARCHAR(120) NULL",
            'document_date' => "DATE NOT NULL",
            'document_time' => "TIME NULL",
            'title' => "VARCHAR(220) NOT NULL DEFAULT 'Document'",
            'client_id' => "INT NULL",
            'client_location_id' => "INT NULL",
            'contract_id' => "INT NULL",
            'appointment_id' => "INT NULL",
            'source_document_id' => "INT NULL",
            'client_name_snapshot' => "VARCHAR(220) NULL",
            'client_identifier_snapshot' => "VARCHAR(80) NULL",
            'client_registry_snapshot' => "VARCHAR(120) NULL",
            'client_address_snapshot' => "TEXT NULL",
            'client_representative_snapshot' => "VARCHAR(220) NULL",
            'client_email_snapshot' => "VARCHAR(180) NULL",
            'client_phone_snapshot' => "VARCHAR(80) NULL",
            'location_name_snapshot' => "VARCHAR(220) NULL",
            'location_address_snapshot' => "TEXT NULL",
            'location_contact_snapshot' => "VARCHAR(220) NULL",
            'location_phone_snapshot' => "VARCHAR(80) NULL",
            'subtotal' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00",
            'vat_percent' => "DECIMAL(7,2) NOT NULL DEFAULT 0.00",
            'vat_amount' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00",
            'total_amount' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00",
            'currency' => "VARCHAR(10) NOT NULL DEFAULT 'RON'",
            'content_html' => "LONGTEXT NULL",
            'payload_json' => "LONGTEXT NULL",
            'notes' => "TEXT NULL",
            'executor_notes' => "TEXT NULL",
            'recommendations' => "TEXT NULL",
            'client_notes' => "TEXT NULL",
            'internal_notes' => "TEXT NULL",
            'email_sent_at' => "DATETIME NULL",
            'email_sent_to' => "VARCHAR(255) NULL",
            'email_sent_count' => "INT NOT NULL DEFAULT 0",
            'created_by' => "INT NULL",
            'issued_by' => "INT NULL",
            'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
            'issued_at' => "DATETIME NULL",
            'locked_at' => "DATETIME NULL",
            'cancelled_at' => "DATETIME NULL",
            'apply_company_stamp' => "TINYINT(1) NOT NULL DEFAULT 0",
        ];

        foreach ($documentColumns as $column => $definition) {
            pzdoc_add_column_if_missing($pdo, 'documents', $column, $definition);
        }

        pzdoc_add_index_if_missing($pdo, 'documents', 'idx_documents_type_date', 'CREATE INDEX idx_documents_type_date ON documents (document_type, document_date)');
        pzdoc_add_index_if_missing($pdo, 'documents', 'idx_documents_status', 'CREATE INDEX idx_documents_status ON documents (status)');
        pzdoc_add_index_if_missing($pdo, 'documents', 'idx_documents_client', 'CREATE INDEX idx_documents_client ON documents (client_id)');
        pzdoc_add_index_if_missing($pdo, 'documents', 'idx_documents_location', 'CREATE INDEX idx_documents_location ON documents (client_location_id)');
        pzdoc_add_index_if_missing($pdo, 'documents', 'idx_documents_number', 'CREATE INDEX idx_documents_number ON documents (document_number)');

        /* Randuri document: servicii, linii oferta, linii contract, servicii PV */
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS document_items (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                document_id INT NOT NULL,\n                item_type VARCHAR(40) NOT NULL DEFAULT 'service',\n                service_id INT NULL,\n                service_name VARCHAR(220) NOT NULL,\n                description TEXT NULL,\n                client_location_id INT NULL,\n                location_name VARCHAR(220) NULL,\n                location_address TEXT NULL,\n                quantity DECIMAL(14,3) NOT NULL DEFAULT 1.000,\n                unit VARCHAR(30) NULL,\n                unit_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n                vat_percent DECIMAL(7,2) NOT NULL DEFAULT 0.00,\n                total_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n                currency VARCHAR(10) NOT NULL DEFAULT 'RON',\n                frequency_text VARCHAR(255) NULL,\n                planned_date DATE NULL,\n                sort_order INT NOT NULL DEFAULT 0,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_document_items_document (document_id),\n                INDEX idx_document_items_service (service_id),\n                INDEX idx_document_items_location (client_location_id)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");

        $itemColumns = [
            'document_id' => "INT NOT NULL",
            'item_type' => "VARCHAR(40) NOT NULL DEFAULT 'service'",
            'service_id' => "INT NULL",
            'service_name' => "VARCHAR(220) NOT NULL",
            'description' => "TEXT NULL",
            'client_location_id' => "INT NULL",
            'location_name' => "VARCHAR(220) NULL",
            'location_address' => "TEXT NULL",
            'quantity' => "DECIMAL(14,3) NOT NULL DEFAULT 1.000",
            'unit' => "VARCHAR(30) NULL",
            'unit_price' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00",
            'vat_percent' => "DECIMAL(7,2) NOT NULL DEFAULT 0.00",
            'total_price' => "DECIMAL(14,2) NOT NULL DEFAULT 0.00",
            'currency' => "VARCHAR(10) NOT NULL DEFAULT 'RON'",
            'frequency_text' => "VARCHAR(255) NULL",
            'planned_date' => "DATE NULL",
            'sort_order' => "INT NOT NULL DEFAULT 0",
            'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        ];

        foreach ($itemColumns as $column => $definition) {
            pzdoc_add_column_if_missing($pdo, 'document_items', $column, $definition);
        }

        pzdoc_add_index_if_missing($pdo, 'document_items', 'idx_document_items_document', 'CREATE INDEX idx_document_items_document ON document_items (document_id)');
        pzdoc_add_index_if_missing($pdo, 'document_items', 'idx_document_items_service', 'CREATE INDEX idx_document_items_service ON document_items (service_id)');
        pzdoc_add_index_if_missing($pdo, 'document_items', 'idx_document_items_location', 'CREATE INDEX idx_document_items_location ON document_items (client_location_id)');

        /* Materiale / biocide pentru PV */
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS document_materials (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                document_id INT NOT NULL,\n                document_item_id INT NULL,\n                stock_product_id INT NULL,\n                stock_receipt_id INT NULL,\n                material_name VARCHAR(255) NOT NULL,\n                product_group VARCHAR(50) NULL,\n                aviz_no VARCHAR(120) NULL,\n                quantity DECIMAL(14,3) NOT NULL DEFAULT 0.000,\n                unit VARCHAR(30) NULL,\n                lot_number VARCHAR(120) NULL,\n                expiry_date DATE NULL,\n                application_method VARCHAR(160) NULL,\n                application_method_custom VARCHAR(255) NULL,\n                application_area VARCHAR(160) NULL,\n                work_concentration VARCHAR(120) NULL,\n                safety_measures TEXT NULL,\n                notes TEXT NULL,\n                sort_order INT NOT NULL DEFAULT 0,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_document_materials_document (document_id),\n                INDEX idx_document_materials_product (stock_product_id),\n                INDEX idx_document_materials_receipt (stock_receipt_id)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");

        $materialColumns = [
            'document_id' => "INT NOT NULL",
            'document_item_id' => "INT NULL",
            'stock_product_id' => "INT NULL",
            'stock_receipt_id' => "INT NULL",
            'material_name' => "VARCHAR(255) NOT NULL",
            'product_group' => "VARCHAR(50) NULL",
            'aviz_no' => "VARCHAR(120) NULL",
            'quantity' => "DECIMAL(14,3) NOT NULL DEFAULT 0.000",
            'unit' => "VARCHAR(30) NULL",
            'lot_number' => "VARCHAR(120) NULL",
            'expiry_date' => "DATE NULL",
            'application_method' => "VARCHAR(160) NULL",
            'application_method_custom' => "VARCHAR(255) NULL",
            'application_area' => "VARCHAR(160) NULL",
            'work_concentration' => "VARCHAR(120) NULL",
            'safety_measures' => "TEXT NULL",
            'notes' => "TEXT NULL",
            'sort_order' => "INT NOT NULL DEFAULT 0",
            'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        ];

        foreach ($materialColumns as $column => $definition) {
            pzdoc_add_column_if_missing($pdo, 'document_materials', $column, $definition);
        }

        pzdoc_add_index_if_missing($pdo, 'document_materials', 'idx_document_materials_document', 'CREATE INDEX idx_document_materials_document ON document_materials (document_id)');
        pzdoc_add_index_if_missing($pdo, 'document_materials', 'idx_document_materials_product', 'CREATE INDEX idx_document_materials_product ON document_materials (stock_product_id)');
        pzdoc_add_index_if_missing($pdo, 'document_materials', 'idx_document_materials_receipt', 'CREATE INDEX idx_document_materials_receipt ON document_materials (stock_receipt_id)');

        /* Legaturi intre documente: oferta -> contract, contract -> PV etc. */
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS document_links (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                from_document_id INT NOT NULL,\n                to_document_id INT NOT NULL,\n                link_type VARCHAR(60) NOT NULL,\n                notes TEXT NULL,\n                created_by INT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_document_links_from (from_document_id),\n                INDEX idx_document_links_to (to_document_id),\n                INDEX idx_document_links_type (link_type)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");

        foreach ([
            'from_document_id' => "INT NOT NULL",
            'to_document_id' => "INT NOT NULL",
            'link_type' => "VARCHAR(60) NOT NULL",
            'notes' => "TEXT NULL",
            'created_by' => "INT NULL",
            'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        ] as $column => $definition) {
            pzdoc_add_column_if_missing($pdo, 'document_links', $column, $definition);
        }

        pzdoc_add_index_if_missing($pdo, 'document_links', 'idx_document_links_from', 'CREATE INDEX idx_document_links_from ON document_links (from_document_id)');
        pzdoc_add_index_if_missing($pdo, 'document_links', 'idx_document_links_to', 'CREATE INDEX idx_document_links_to ON document_links (to_document_id)');
        pzdoc_add_index_if_missing($pdo, 'document_links', 'idx_document_links_type', 'CREATE INDEX idx_document_links_type ON document_links (link_type)');

        /* Log email pentru documente */
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS document_email_logs (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                document_id INT NOT NULL,\n                recipient VARCHAR(255) NOT NULL,\n                cc VARCHAR(255) NULL,\n                subject VARCHAR(255) NULL,\n                body MEDIUMTEXT NULL,\n                attachment_path VARCHAR(255) NULL,\n                status VARCHAR(40) NOT NULL DEFAULT 'sent',\n                provider VARCHAR(80) NULL,\n                provider_response MEDIUMTEXT NULL,\n                sent_by INT NULL,\n                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_document_email_logs_document (document_id),\n                INDEX idx_document_email_logs_status (status),\n                INDEX idx_document_email_logs_sent_at (sent_at)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");

        foreach ([
            'document_id' => "INT NOT NULL",
            'recipient' => "VARCHAR(255) NOT NULL",
            'cc' => "VARCHAR(255) NULL",
            'subject' => "VARCHAR(255) NULL",
            'body' => "MEDIUMTEXT NULL",
            'attachment_path' => "VARCHAR(255) NULL",
            'status' => "VARCHAR(40) NOT NULL DEFAULT 'sent'",
            'provider' => "VARCHAR(80) NULL",
            'provider_response' => "MEDIUMTEXT NULL",
            'sent_by' => "INT NULL",
            'sent_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        ] as $column => $definition) {
            pzdoc_add_column_if_missing($pdo, 'document_email_logs', $column, $definition);
        }

        pzdoc_add_index_if_missing($pdo, 'document_email_logs', 'idx_document_email_logs_document', 'CREATE INDEX idx_document_email_logs_document ON document_email_logs (document_id)');
        pzdoc_add_index_if_missing($pdo, 'document_email_logs', 'idx_document_email_logs_status', 'CREATE INDEX idx_document_email_logs_status ON document_email_logs (status)');
        pzdoc_add_index_if_missing($pdo, 'document_email_logs', 'idx_document_email_logs_sent_at', 'CREATE INDEX idx_document_email_logs_sent_at ON document_email_logs (sent_at)');

        /* Serii si numere - pastram compatibilitatea cu document_series.php */
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS document_series (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                document_type VARCHAR(50) NOT NULL,\n                name VARCHAR(150) NOT NULL,\n                series_code VARCHAR(50) NOT NULL,\n                format_pattern VARCHAR(120) NOT NULL DEFAULT '{N}/{DD}.{MM}.{YYYY}',\n                year INT NULL,\n                next_number INT NOT NULL DEFAULT 1,\n                padding INT NOT NULL DEFAULT 1,\n                reset_yearly TINYINT(1) NOT NULL DEFAULT 0,\n                is_default TINYINT(1) NOT NULL DEFAULT 0,\n                active TINYINT(1) NOT NULL DEFAULT 1,\n                notes TEXT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,\n                INDEX idx_document_series_type_active (document_type, active, is_default),\n                INDEX idx_document_series_code (series_code)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");

        foreach ([
            'document_type' => "VARCHAR(50) NOT NULL",
            'name' => "VARCHAR(150) NOT NULL",
            'series_code' => "VARCHAR(50) NOT NULL",
            'format_pattern' => "VARCHAR(120) NOT NULL DEFAULT '{N}/{DD}.{MM}.{YYYY}'",
            'year' => "INT NULL",
            'next_number' => "INT NOT NULL DEFAULT 1",
            'padding' => "INT NOT NULL DEFAULT 1",
            'reset_yearly' => "TINYINT(1) NOT NULL DEFAULT 0",
            'is_default' => "TINYINT(1) NOT NULL DEFAULT 0",
            'active' => "TINYINT(1) NOT NULL DEFAULT 1",
            'notes' => "TEXT NULL",
            'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
        ] as $column => $definition) {
            pzdoc_add_column_if_missing($pdo, 'document_series', $column, $definition);
        }

        pzdoc_add_index_if_missing($pdo, 'document_series', 'idx_document_series_type_active', 'CREATE INDEX idx_document_series_type_active ON document_series (document_type, active, is_default)');
        pzdoc_add_index_if_missing($pdo, 'document_series', 'idx_document_series_code', 'CREATE INDEX idx_document_series_code ON document_series (series_code)');

        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS document_numbers (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                document_type VARCHAR(50) NOT NULL,\n                document_id INT NOT NULL,\n                series_id INT NOT NULL,\n                series_code VARCHAR(50) NOT NULL,\n                number_int INT NOT NULL,\n                full_number VARCHAR(120) NOT NULL,\n                issued_date DATE NOT NULL,\n                year INT NOT NULL,\n                status VARCHAR(30) NOT NULL DEFAULT 'emis',\n                issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                issued_by INT NULL,\n                notes TEXT NULL,\n                UNIQUE KEY uniq_full_number (full_number),\n                UNIQUE KEY uniq_document_ref (document_type, document_id),\n                UNIQUE KEY uniq_series_number (series_id, number_int),\n                INDEX idx_document_numbers_type_date (document_type, issued_date),\n                INDEX idx_document_numbers_series (series_id)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");

        foreach ([
            'document_type' => "VARCHAR(50) NOT NULL",
            'document_id' => "INT NOT NULL",
            'series_id' => "INT NOT NULL",
            'series_code' => "VARCHAR(50) NOT NULL",
            'number_int' => "INT NOT NULL",
            'full_number' => "VARCHAR(120) NOT NULL",
            'issued_date' => "DATE NOT NULL",
            'year' => "INT NOT NULL",
            'status' => "VARCHAR(30) NOT NULL DEFAULT 'emis'",
            'issued_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            'issued_by' => "INT NULL",
            'notes' => "TEXT NULL",
        ] as $column => $definition) {
            pzdoc_add_column_if_missing($pdo, 'document_numbers', $column, $definition);
        }

        pzdoc_add_index_if_missing($pdo, 'document_numbers', 'idx_document_numbers_type_date', 'CREATE INDEX idx_document_numbers_type_date ON document_numbers (document_type, issued_date)');
        pzdoc_add_index_if_missing($pdo, 'document_numbers', 'idx_document_numbers_series', 'CREATE INDEX idx_document_numbers_series ON document_numbers (series_id)');
    }
}

if (!function_exists('pzdoc_seed_document_defaults')) {
    function pzdoc_seed_document_defaults(PDO $pdo): void
    {
        $types = ['oferta', 'contract', 'proces_verbal'];

        foreach ($types as $type) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_series WHERE document_type = ?");
            $stmt->execute([$type]);
            $exists = (int)$stmt->fetchColumn() > 0;

            if (!$exists) {
                $seriesCode = pzdoc_default_series_code($type);
                $seriesName = pzdoc_default_series_name($type);
                $pattern = ($type === 'contract') ? '{N}/{DD}.{MM}.{YYYY}' : '{SERIE} {N}/{DD}.{MM}.{YYYY}';

                $insert = $pdo->prepare("\n                    INSERT INTO document_series\n                        (document_type, name, series_code, format_pattern, year, next_number, padding, reset_yearly, is_default, active, notes)\n                    VALUES\n                        (?, ?, ?, ?, NULL, 1, 1, 0, 1, 1, NULL)\n                ");
                $insert->execute([$type, $seriesName, $seriesCode, $pattern]);
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_templates WHERE document_type = ?");
            $stmt->execute([$type]);
            $templateExists = (int)$stmt->fetchColumn() > 0;

            if (!$templateExists) {
                $insert = $pdo->prepare("\n                    INSERT INTO document_templates\n                        (document_type, name, slug, description, content_html, is_default, is_active, created_by)\n                    VALUES\n                        (?, ?, ?, ?, ?, 1, 1, NULL)\n                ");

                $name = 'Sablon ' . pzdoc_default_series_name($type);
                $slug = 'default_' . $type;
                $description = 'Sablon implicit generat automat de motorul nou de documente.';
                $content = pzdoc_default_template_content($type);

                $insert->execute([$type, $name, $slug, $description, $content]);
            }
        }
    }
}

if (!function_exists('pzdoc_install_document_schema')) {
    function pzdoc_install_document_schema(PDO $pdo): void
    {
        pzdoc_ensure_document_schema($pdo);
        pzdoc_seed_document_defaults($pdo);
    }
}

/*
|--------------------------------------------------------------------------
| Rulare directa optionala
|--------------------------------------------------------------------------
| Daca intri in browser pe document_schema.php ca admin, verifica schema.
| In restul aplicatiei, fisierul va fi inclus si se va apela functia din cod.
|--------------------------------------------------------------------------
*/
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === basename(__FILE__)) {
    require_login();

    if (!is_admin()) {
        header('Location: calendar.php');
        exit;
    }

    try {
        pzdoc_install_document_schema($pdo);
        echo 'Schema documente verificata cu succes.';
    } catch (Throwable $e) {
        error_log('PestZone document schema install error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Eroare la verificarea schemei de documente.';
    }
}
