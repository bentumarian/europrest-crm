<?php
/**
 * PestZone CRM - billing_lib.php
 * Oglinda locala pentru documentele Oblio + nomenclator produse/servicii.
 * Oblio ramane sursa fiscala oficiala.
 */

require_once __DIR__ . '/oblio_api_lib.php';

if (!function_exists('bill_h')) {
    function bill_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function bill_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function bill_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function bill_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (bill_table_exists($pdo, $table) && !bill_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}


function bill_seed_default_products(PDO $pdo): void
{
    $defaults = [
        ['Servicii DDD conform contract', 'Serviciu', 'buc', 'Normala', 21.00],
        ['Servicii dezinsectie', 'Serviciu', 'buc', 'Normala', 21.00],
        ['Servicii deratizare', 'Serviciu', 'buc', 'Normala', 21.00],
        ['Servicii dezinfectie', 'Serviciu', 'buc', 'Normala', 21.00],
    ];

    $check = $pdo->prepare("SELECT id FROM billing_products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $insert = $pdo->prepare("\n        INSERT INTO billing_products\n        (name, product_type, measuring_unit, currency, vat_name, vat_percentage, vat_included, description, active, updated_at)\n        VALUES (?, ?, ?, 'RON', ?, ?, 0, '', 1, NOW())\n    ");

    foreach ($defaults as $row) {
        $check->execute([$row[0]]);
        if (!$check->fetchColumn()) {
            $insert->execute($row);
        }
    }
}

function bill_ensure_schema(PDO $pdo): void
{
    oblio_ensure_settings_schema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billing_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            product_type ENUM('Serviciu','Marfa') NOT NULL DEFAULT 'Serviciu',
            code VARCHAR(80) NULL,
            measuring_unit VARCHAR(40) NOT NULL DEFAULT 'buc',
            default_price DECIMAL(12,2) NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'RON',
            vat_name VARCHAR(80) NOT NULL DEFAULT 'Normala',
            vat_percentage DECIMAL(6,2) NOT NULL DEFAULT 21.00,
            vat_included TINYINT(1) NOT NULL DEFAULT 0,
            description TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_billing_products_active (active),
            INDEX idx_billing_products_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billing_oblio_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            oblio_type ENUM('invoice','proforma','receipt','collect','notice') NOT NULL,
            client_id INT NULL,
            contract_id INT NULL,
            oblio_id VARCHAR(80) NULL,
            oblio_series VARCHAR(80) NULL,
            oblio_number VARCHAR(80) NULL,
            issue_date DATE NULL,
            due_date DATE NULL,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            collected_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'RON',
            status VARCHAR(60) NULL,
            canceled TINYINT(1) NOT NULL DEFAULT 0,
            link TEXT NULL,
            pdf_path TEXT NULL,
            einvoice_link TEXT NULL,
            einvoice_status TEXT NULL,
            raw_json LONGTEXT NULL,
            last_synced_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_billing_oblio_doc (oblio_type, oblio_series, oblio_number),
            INDEX idx_billing_client (client_id),
            INDEX idx_billing_issue_date (issue_date),
            INDEX idx_billing_balance (balance)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billing_oblio_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            product_id INT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
            measuring_unit VARCHAR(40) NOT NULL DEFAULT 'buc',
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            vat_name VARCHAR(80) NOT NULL DEFAULT 'Normala',
            vat_percentage DECIMAL(6,2) NOT NULL DEFAULT 21.00,
            vat_included TINYINT(1) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            raw_json LONGTEXT NULL,
            INDEX idx_billing_items_document (document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billing_oblio_collections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NULL,
            client_id INT NULL,
            oblio_invoice_series VARCHAR(80) NOT NULL,
            oblio_invoice_number VARCHAR(80) NOT NULL,
            collect_type VARCHAR(80) NOT NULL,
            collect_series VARCHAR(80) NULL,
            collect_number VARCHAR(120) NULL,
            issue_date DATE NULL,
            value DECIMAL(12,2) NOT NULL DEFAULT 0,
            link TEXT NULL,
            pdf_path TEXT NULL,
            raw_json LONGTEXT NULL,
            last_synced_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_billing_collect (oblio_invoice_series, oblio_invoice_number, collect_type, collect_number, issue_date, value),
            INDEX idx_billing_collect_document (document_id),
            INDEX idx_billing_collect_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billing_oblio_webhook_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(190) NULL,
            topic VARCHAR(190) NULL,
            payload LONGTEXT NULL,
            processed TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_billing_webhook_processed (processed),
            INDEX idx_billing_webhook_topic (topic)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billing_sync_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sync_type VARCHAR(80) NOT NULL,
            status ENUM('success','failed') NOT NULL,
            message TEXT NULL,
            stats_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_billing_sync_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billing_drafts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_type ENUM('invoice','proforma') NOT NULL DEFAULT 'invoice',
            status ENUM('draft','issued','canceled') NOT NULL DEFAULT 'draft',
            client_id INT NULL,
            contract_id INT NULL,
            issue_date DATE NULL,
            due_date DATE NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'RON',
            invoice_by_contract TINYINT(1) NOT NULL DEFAULT 0,
            mentions TEXT NULL,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            issued_document_id INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_billing_drafts_status (status),
            INDEX idx_billing_drafts_client (client_id),
            INDEX idx_billing_drafts_contract (contract_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billing_draft_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            draft_id INT NOT NULL,
            product_id INT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
            measuring_unit VARCHAR(40) NOT NULL DEFAULT 'buc',
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'RON',
            vat_name VARCHAR(80) NOT NULL DEFAULT 'Normala',
            vat_percentage DECIMAL(6,2) NOT NULL DEFAULT 21.00,
            vat_included TINYINT(1) NOT NULL DEFAULT 0,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            INDEX idx_billing_draft_items_draft (draft_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    bill_seed_default_products($pdo);
}

function bill_money($value, string $currency = 'RON'): string
{
    return number_format((float)$value, 2, ',', '.') . ' ' . $currency;
}

function bill_date_ro($date): string
{
    $date = trim((string)$date);
    if ($date === '' || $date === '0000-00-00') return '-';
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : $date;
}

function bill_normalize_oblio_type(string $type): string
{
    $t = strtolower(trim($type));
    $map = [
        'factura' => 'invoice',
        'invoice' => 'invoice',
        'proforma' => 'proforma',
        'chitanta' => 'receipt',
        'taxreceipt' => 'receipt',
        'collect' => 'collect',
        'incasare' => 'collect',
        'aviz' => 'notice',
        'notice' => 'notice',
    ];
    return $map[$t] ?? $t;
}

function bill_clients(PDO $pdo): array
{
    $cols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM clients");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[$r['Field']] = true;
    } catch (Throwable $e) {}

    $active = isset($cols['active']) ? "WHERE active = 1" : "";
    $stmt = $pdo->query("
        SELECT *
        FROM clients
        $active
        ORDER BY name ASC
        LIMIT 3000
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bill_client(PDO $pdo, int $clientId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bill_contracts_for_client(PDO $pdo, int $clientId): array
{
    if (!bill_table_exists($pdo, 'contracts')) return [];
    if (!bill_column_exists($pdo, 'contracts', 'client_id')) return [];

    $stmt = $pdo->prepare("
        SELECT id, contract_number, title, contract_date, start_date, end_date
        FROM contracts
        WHERE client_id = ?
        ORDER BY id DESC
        LIMIT 200
    ");
    $stmt->execute([$clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bill_contract(PDO $pdo, int $contractId): ?array
{
    if (!bill_table_exists($pdo, 'contracts')) return null;
    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ? LIMIT 1");
    $stmt->execute([$contractId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bill_products(PDO $pdo, bool $activeOnly = true): array
{
    bill_ensure_schema($pdo);
    $where = $activeOnly ? "WHERE active = 1" : "";
    $stmt = $pdo->query("SELECT * FROM billing_products $where ORDER BY active DESC, name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bill_product(PDO $pdo, int $id): ?array
{
    bill_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM billing_products WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bill_save_product(PDO $pdo, array $data): int
{
    bill_ensure_schema($pdo);
    $id = (int)($data['id'] ?? 0);

    $payload = [
        'name' => trim((string)($data['name'] ?? '')),
        'product_type' => in_array(($data['product_type'] ?? 'Serviciu'), ['Serviciu','Marfa'], true) ? $data['product_type'] : 'Serviciu',
        'code' => trim((string)($data['code'] ?? '')),
        'measuring_unit' => trim((string)($data['measuring_unit'] ?? 'buc')) ?: 'buc',
        'default_price' => ($data['default_price'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$data['default_price']) : null,
        'currency' => trim((string)($data['currency'] ?? 'RON')) ?: 'RON',
        'vat_name' => trim((string)($data['vat_name'] ?? 'Normala')) ?: 'Normala',
        'vat_percentage' => (float)str_replace(',', '.', (string)($data['vat_percentage'] ?? '21')),
        'vat_included' => !empty($data['vat_included']) ? 1 : 0,
        'description' => trim((string)($data['description'] ?? '')),
        'active' => !empty($data['active']) ? 1 : 0,
    ];

    if ($payload['name'] === '') throw new RuntimeException('Denumirea produsului/serviciului este obligatorie.');

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE billing_products
            SET name=?, product_type=?, code=?, measuring_unit=?, default_price=?, currency=?, vat_name=?, vat_percentage=?, vat_included=?, description=?, active=?, updated_at=NOW()
            WHERE id=?
        ");
        $stmt->execute(array_merge(array_values($payload), [$id]));
        return $id;
    }

    $stmt = $pdo->prepare("
        INSERT INTO billing_products
        (name, product_type, code, measuring_unit, default_price, currency, vat_name, vat_percentage, vat_included, description, active, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute(array_values($payload));
    return (int)$pdo->lastInsertId();
}

function bill_delete_or_deactivate_product(PDO $pdo, int $id): string
{
    bill_ensure_schema($pdo);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM billing_oblio_items WHERE product_id = ?");
    $stmt->execute([$id]);
    $used = (int)$stmt->fetchColumn();

    if ($used > 0) {
        $stmt = $pdo->prepare("UPDATE billing_products SET active = 0, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        return 'deactivated';
    }

    $stmt = $pdo->prepare("DELETE FROM billing_products WHERE id = ?");
    $stmt->execute([$id]);
    return 'deleted';
}

function bill_pdf_dir(): string
{
    $dir = __DIR__ . '/uploads/billing_docs';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

function bill_pdf_public_path(string $absolute): string
{
    $absolute = str_replace('\\', '/', $absolute);
    $base = str_replace('\\', '/', __DIR__) . '/';
    if (strpos($absolute, $base) === 0) return substr($absolute, strlen($base));
    return $absolute;
}

function bill_download_pdf(string $url, string $filenameBase): string
{
    $url = trim($url);
    if ($url === '') return '';

    $res = oblio_raw_get($url);
    if (empty($res['ok'])) return '';

    $body = (string)$res['body'];
    if ($body === '') return '';

    $safe = preg_replace('/[^a-z0-9_\-]+/i', '_', $filenameBase);
    $safe = trim($safe, '_') ?: 'document_oblio';
    $path = bill_pdf_dir() . '/' . $safe . '_' . date('Ymd_His') . '.pdf';

    file_put_contents($path, $body);
    return bill_pdf_public_path($path);
}

function bill_calculate_collected(array $doc): float
{
    $total = 0.0;
    $collects = is_array($doc['collects'] ?? null) ? $doc['collects'] : [];
    foreach ($collects as $c) {
        $total += (float)($c['value'] ?? 0);
    }
    return $total;
}

function bill_extract_total(array $doc, ?float $fallback = null): float
{
    foreach (['total', 'totalWithVat', 'value', 'amount'] as $key) {
        if (isset($doc[$key]) && $doc[$key] !== '') return (float)$doc[$key];
    }
    return $fallback !== null ? $fallback : 0.0;
}

function bill_find_client_by_oblio_client(PDO $pdo, array $client): ?int
{
    $cif = oblio_clean_cif((string)($client['cif'] ?? ''));
    if ($cif !== '') {
        $variants = [$cif, preg_replace('/^RO/i', '', $cif), 'RO' . preg_replace('/^RO/i', '', $cif)];
        $variants = array_unique(array_filter($variants));

        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $stmt = $pdo->prepare("
            SELECT id FROM clients
            WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(fiscal_code, ' ', ''), '-', ''), '.', ''), '/', '')) IN ($placeholders)
            LIMIT 1
        ");
        $stmt->execute($variants);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
    }

    $name = trim((string)($client['name'] ?? ''));
    if ($name !== '') {
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
    }

    return null;
}

function bill_save_oblio_document(PDO $pdo, string $type, array $doc, ?int $clientId = null, ?int $contractId = null, array $localItems = [], ?float $fallbackTotal = null): int
{
    bill_ensure_schema($pdo);

    $type = bill_normalize_oblio_type($type);
    $series = trim((string)($doc['seriesName'] ?? $doc['oblio_series'] ?? ''));
    $number = trim((string)($doc['number'] ?? $doc['oblio_number'] ?? ''));

    if ($series === '' || $number === '') {
        throw new RuntimeException('Document Oblio fara serie sau numar.');
    }

    if ($clientId === null && is_array($doc['client'] ?? null)) {
        $clientId = bill_find_client_by_oblio_client($pdo, $doc['client']);
    }

    $total = bill_extract_total($doc, $fallbackTotal);
    $collected = bill_calculate_collected($doc);
    $balance = max(0, $total - $collected);
    $canceled = !empty($doc['canceled']) ? 1 : 0;
    $status = $canceled ? 'anulat' : ($balance <= 0 && $total > 0 ? 'incasat' : 'emis');
    $link = trim((string)($doc['link'] ?? ''));

    $pdfPath = '';
    if ($link !== '') {
        $pdfPath = bill_download_pdf($link, $type . '_' . $series . '_' . $number);
    }

    $stmt = $pdo->prepare("
        INSERT INTO billing_oblio_documents
        (oblio_type, client_id, contract_id, oblio_id, oblio_series, oblio_number, issue_date, due_date, total, collected_total, balance, currency, status, canceled, link, pdf_path, einvoice_link, einvoice_status, raw_json, last_synced_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            client_id = COALESCE(VALUES(client_id), client_id),
            contract_id = COALESCE(VALUES(contract_id), contract_id),
            oblio_id = VALUES(oblio_id),
            issue_date = VALUES(issue_date),
            due_date = VALUES(due_date),
            total = VALUES(total),
            collected_total = VALUES(collected_total),
            balance = VALUES(balance),
            currency = VALUES(currency),
            status = VALUES(status),
            canceled = VALUES(canceled),
            link = VALUES(link),
            pdf_path = IF(VALUES(pdf_path) <> '', VALUES(pdf_path), pdf_path),
            einvoice_link = VALUES(einvoice_link),
            einvoice_status = VALUES(einvoice_status),
            raw_json = VALUES(raw_json),
            last_synced_at = NOW(),
            updated_at = NOW()
    ");

    $stmt->execute([
        $type,
        $clientId,
        $contractId,
        (string)($doc['id'] ?? ''),
        $series,
        $number,
        !empty($doc['issueDate']) ? $doc['issueDate'] : null,
        !empty($doc['dueDate']) ? $doc['dueDate'] : null,
        $total,
        $collected,
        $balance,
        (string)($doc['currency'] ?? 'RON'),
        $status,
        $canceled,
        $link,
        $pdfPath,
        (string)($doc['einvoice'] ?? ''),
        isset($doc['einvoiceStatus']) ? json_encode($doc['einvoiceStatus'], JSON_UNESCAPED_UNICODE) : null,
        json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $stmt = $pdo->prepare("SELECT id FROM billing_oblio_documents WHERE oblio_type = ? AND oblio_series = ? AND oblio_number = ? LIMIT 1");
    $stmt->execute([$type, $series, $number]);
    $documentId = (int)$stmt->fetchColumn();

    $products = is_array($doc['products'] ?? null) ? $doc['products'] : $localItems;
    if ($products) {
        $pdo->prepare("DELETE FROM billing_oblio_items WHERE document_id = ?")->execute([$documentId]);

        $itemStmt = $pdo->prepare("
            INSERT INTO billing_oblio_items
            (document_id, product_id, name, description, quantity, measuring_unit, unit_price, vat_name, vat_percentage, vat_included, total, raw_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($products as $p) {
            $qty = (float)($p['quantity'] ?? 1);
            $price = (float)($p['price'] ?? $p['unit_price'] ?? 0);
            $itemTotal = isset($p['total']) ? (float)$p['total'] : $qty * $price;

            $itemStmt->execute([
                $documentId,
                !empty($p['product_id']) ? (int)$p['product_id'] : null,
                (string)($p['name'] ?? '-'),
                (string)($p['description'] ?? ''),
                $qty,
                (string)($p['measuringUnit'] ?? $p['measuring_unit'] ?? 'buc'),
                $price,
                (string)($p['vatName'] ?? $p['vat_name'] ?? 'Normala'),
                (float)($p['vatPercentage'] ?? $p['vat_percentage'] ?? 21),
                !empty($p['vatIncluded'] ?? $p['vat_included'] ?? 0) ? 1 : 0,
                $itemTotal,
                json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    bill_save_collects($pdo, $documentId, $clientId, $series, $number, $doc);

    return $documentId;
}

function bill_save_collects(PDO $pdo, int $documentId, ?int $clientId, string $series, string $number, array $doc): void
{
    $collects = is_array($doc['collects'] ?? null) ? $doc['collects'] : [];
    if (!$collects) return;

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO billing_oblio_collections
        (document_id, client_id, oblio_invoice_series, oblio_invoice_number, collect_type, collect_series, collect_number, issue_date, value, link, raw_json, last_synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    foreach ($collects as $c) {
        $stmt->execute([
            $documentId,
            $clientId,
            $series,
            $number,
            (string)($c['type'] ?? ''),
            (string)($c['seriesName'] ?? ''),
            (string)($c['number'] ?? $c['documentNumber'] ?? ''),
            !empty($c['issueDate']) ? $c['issueDate'] : null,
            (float)($c['value'] ?? 0),
            (string)($c['link'] ?? ''),
            json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

function bill_create_document_from_form(PDO $pdo, array $post): array
{
    bill_ensure_schema($pdo);

    $type = strtolower((string)($post['document_type'] ?? 'proforma'));
    if (!in_array($type, ['proforma','invoice'], true)) {
        return ['ok' => false, 'error' => 'Tip document invalid.'];
    }

    $clientId = (int)($post['client_id'] ?? 0);
    $client = bill_client($pdo, $clientId);
    if (!$client) return ['ok' => false, 'error' => 'Selecteaza clientul.'];

    $contractId = !empty($post['contract_id']) ? (int)$post['contract_id'] : null;
    $contract = $contractId ? bill_contract($pdo, $contractId) : null;

    $names = $post['item_name'] ?? [];
    $qtys = $post['item_quantity'] ?? [];
    $ums = $post['item_unit'] ?? [];
    $prices = $post['item_price'] ?? [];
    $vatNames = $post['item_vat_name'] ?? [];
    $vatPercentages = $post['item_vat_percentage'] ?? [];
    $productIds = $post['item_product_id'] ?? [];
    $descriptions = $post['item_description'] ?? [];

    $products = [];
    $localItems = [];
    $fallbackTotal = 0.0;

    foreach ((array)$names as $i => $name) {
        $name = trim((string)$name);
        if ($name === '') continue;

        $qty = max(0.001, (float)str_replace(',', '.', (string)($qtys[$i] ?? 1)));
        $price = (float)str_replace(',', '.', (string)($prices[$i] ?? 0));
        $unit = trim((string)($ums[$i] ?? 'buc')) ?: 'buc';
        $vatName = trim((string)($vatNames[$i] ?? 'Normala')) ?: 'Normala';
        $vatPct = (float)str_replace(',', '.', (string)($vatPercentages[$i] ?? 21));
        $productId = !empty($productIds[$i]) ? (int)$productIds[$i] : null;
        $desc = trim((string)($descriptions[$i] ?? ''));

        $fallbackTotal += $qty * $price;

        $product = oblio_build_service_product($pdo, $name, $price, $qty, $desc, [
            'measuringUnit' => $unit,
            'vatName' => $vatName,
            'vatPercentage' => $vatPct,
            'vatIncluded' => !empty($post['vat_included']) ? 1 : oblio_bool_value(oblio_settings($pdo)['oblio.vat_included'] ?? '0'),
        ]);

        $products[] = $product;
        $localItems[] = array_merge($product, ['product_id' => $productId]);
    }

    if (!$products) return ['ok' => false, 'error' => 'Adauga cel putin un produs/serviciu.'];

    $mentions = trim((string)($post['mentions'] ?? ''));
    if (!empty($post['invoice_by_contract']) && $contract) {
        $contractNumber = trim((string)($contract['contract_number'] ?? ''));
        if ($contractNumber !== '') {
            $mentions = trim($mentions . "\n" . 'Facturare conform contract nr. ' . $contractNumber);
        }
    }

    $extra = [
        'issueDate' => trim((string)($post['issue_date'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
        'dueDate' => trim((string)($post['due_date'] ?? '')) ?: null,
        'mentions' => $mentions,
        'internalNote' => 'Document emis din PestZone CRM',
        'idempotencyKey' => 'pz_' . $type . '_' . $clientId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
    ];

    if ($type === 'invoice' && !empty($post['collect_now'])) {
        $collectType = trim((string)($post['collect_type'] ?? 'Chitanta'));
        $collect = [
            'type' => $collectType,
            'issueDate' => trim((string)($post['collect_issue_date'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
            'value' => (float)str_replace(',', '.', (string)($post['collect_value'] ?? $fallbackTotal)),
            'mentions' => trim((string)($post['collect_mentions'] ?? '')),
        ];
        if ($collectType === 'Chitanta') {
            $receiptSeries = trim((string)($post['collect_series'] ?? oblio_settings($pdo)['oblio.receipt_series'] ?? ''));
            if ($receiptSeries !== '') $collect['seriesName'] = $receiptSeries;
        } else {
            $documentNumber = trim((string)($post['collect_number'] ?? ''));
            if ($documentNumber !== '') $collect['documentNumber'] = $documentNumber;
        }
        $extra['collect'] = $collect;
    }

    $payload = oblio_basic_document_payload($pdo, $type, $client, $products, $extra);
    $res = oblio_issue_document($pdo, $type, $payload);

    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => $res['error'] ?? 'Eroare emitere document Oblio.', 'response' => $res, 'payload' => $payload];
    }

    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
    $series = (string)($data['seriesName'] ?? '');
    $number = (string)($data['number'] ?? '');

    if ($series !== '' && $number !== '') {
        $view = oblio_get_document($pdo, $type, $series, $number);
        if (!empty($view['ok']) && is_array($view['data'])) {
            $data = array_merge($data, $view['data']);
        }
    }

    $documentId = bill_save_oblio_document($pdo, $type, $data, $clientId, $contractId, $localItems, $fallbackTotal);

    return [
        'ok' => true,
        'document_id' => $documentId,
        'oblio' => $data,
        'response' => $res,
    ];
}

function bill_documents(PDO $pdo, array $filters = []): array
{
    bill_ensure_schema($pdo);
    $where = [];
    $params = [];

    if (!empty($filters['type'])) {
        $where[] = 'd.oblio_type = ?';
        $params[] = $filters['type'];
    }
    if (!empty($filters['client_id'])) {
        $where[] = 'd.client_id = ?';
        $params[] = (int)$filters['client_id'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(d.oblio_series LIKE ? OR d.oblio_number LIKE ? OR c.name LIKE ? OR c.fiscal_code LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $limit = (int)($filters['limit'] ?? 200);

    $stmt = $pdo->prepare("
        SELECT d.*, c.name AS client_name, c.fiscal_code AS client_fiscal_code
        FROM billing_oblio_documents d
        LEFT JOIN clients c ON c.id = d.client_id
        $sqlWhere
        ORDER BY d.issue_date DESC, d.id DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bill_document(PDO $pdo, int $id): ?array
{
    bill_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT d.*, c.name AS client_name, c.fiscal_code AS client_fiscal_code
        FROM billing_oblio_documents d
        LEFT JOIN clients c ON c.id = d.client_id
        WHERE d.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bill_open_local_pdf(array $doc): void
{
    $path = trim((string)($doc['pdf_path'] ?? ''));
    if ($path === '') {
        http_response_code(404);
        exit('PDF-ul local nu exista.');
    }

    $abs = __DIR__ . '/' . ltrim(str_replace('\\', '/', $path), '/');
    if (!is_file($abs)) {
        http_response_code(404);
        exit('Fisierul PDF nu a fost gasit pe server.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($abs) . '"');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

function bill_sync_recent_invoices(PDO $pdo, int $daysBack = 30): array
{
    bill_ensure_schema($pdo);

    $issuedAfter = date('Y-m-d', strtotime('-' . max(1, $daysBack) . ' days'));
    $issuedBefore = date('Y-m-d');
    $offset = 0;
    $count = 0;
    $errors = [];

    do {
        $res = oblio_list_invoices($pdo, [
            'issuedAfter' => $issuedAfter,
            'issuedBefore' => $issuedBefore,
            'offset' => $offset,
            'limitPerPage' => 100,
        ]);

        if (empty($res['ok'])) {
            $errors[] = $res['error'] ?? 'Eroare sincronizare Oblio.';
            break;
        }

        $data = is_array($res['data'] ?? null) ? $res['data'] : [];

        foreach ($data as $doc) {
            try {
                bill_save_oblio_document($pdo, 'invoice', $doc);
                $count++;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $offset += 100;
    } while (count($data) === 100 && $offset <= 1000);

    $status = $errors ? 'failed' : 'success';
    $stmt = $pdo->prepare("INSERT INTO billing_sync_log (sync_type, status, message, stats_json) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        'recent_invoices',
        $status,
        $errors ? implode("\n", $errors) : 'OK',
        json_encode(['count' => $count, 'errors' => $errors], JSON_UNESCAPED_UNICODE),
    ]);

    return ['ok' => !$errors, 'count' => $count, 'errors' => $errors];
}

function bill_sync_client_invoices(PDO $pdo, int $clientId): array
{
    $client = bill_client($pdo, $clientId);
    if (!$client) return ['ok' => false, 'error' => 'Client inexistent.'];

    $cif = oblio_clean_cif((string)($client['fiscal_code'] ?? ''));
    if ($cif === '') return ['ok' => false, 'error' => 'Clientul nu are CUI/CNP/CI completat.'];

    $res = oblio_list_invoices_by_client($pdo, $cif, [
        'withProducts' => 1,
        'withCollects' => 1,
        'withEinvoiceStatus' => 1,
        'limitPerPage' => 100,
    ]);

    if (empty($res['ok'])) return $res;

    $count = 0;
    foreach ((array)$res['data'] as $doc) {
        bill_save_oblio_document($pdo, 'invoice', $doc, $clientId);
        $count++;
    }

    return ['ok' => true, 'count' => $count, 'data' => $res['data']];
}

function bill_collect_invoice_from_form(PDO $pdo, array $post): array
{
    bill_ensure_schema($pdo);

    $documentId = (int)($post['document_id'] ?? 0);
    $doc = bill_document($pdo, $documentId);
    if (!$doc) return ['ok' => false, 'error' => 'Factura nu exista local. Sincronizeaza din Oblio.'];

    if ($doc['oblio_type'] !== 'invoice') return ['ok' => false, 'error' => 'Se pot incasa doar facturi.'];

    $type = trim((string)($post['collect_type'] ?? 'Chitanta'));
    $value = (float)str_replace(',', '.', (string)($post['collect_value'] ?? $doc['balance']));
    if ($value <= 0) return ['ok' => false, 'error' => 'Valoarea incasata trebuie sa fie mai mare decat zero.'];

    $collect = [
        'type' => $type,
        'value' => $value,
        'issueDate' => trim((string)($post['collect_issue_date'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
        'mentions' => trim((string)($post['collect_mentions'] ?? '')),
    ];

    if ($type === 'Chitanta') {
        $series = trim((string)($post['collect_series'] ?? oblio_settings($pdo)['oblio.receipt_series'] ?? ''));
        if ($series !== '') $collect['seriesName'] = $series;
    } else {
        $nr = trim((string)($post['collect_number'] ?? ''));
        if ($nr !== '') $collect['documentNumber'] = $nr;
    }

    $res = oblio_collect_invoice($pdo, (string)$doc['oblio_series'], (string)$doc['oblio_number'], $collect);
    if (empty($res['ok'])) return $res;

    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
    if ($data) {
        bill_save_oblio_document($pdo, 'invoice', $data, (int)$doc['client_id'], !empty($doc['contract_id']) ? (int)$doc['contract_id'] : null);
    }

    return ['ok' => true, 'data' => $data, 'response' => $res];
}


function bill_vat_options(PDO $pdo): array
{
    $settings = oblio_settings($pdo);
    $options = [];

    $cached = trim((string)($settings['oblio.cached_vat_rates_json'] ?? ''));
    if ($cached !== '') {
        $rows = json_decode($cached, true);
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $name = trim((string)($r['name'] ?? ''));
                $pct = $r['percentage'] ?? $r['percent'] ?? $r['vatPercentage'] ?? null;
                if ($name !== '' && $pct !== null && $pct !== '') {
                    $options[] = ['name' => $name, 'percentage' => (float)$pct, 'label' => $name . ' / ' . (float)$pct . '%'];
                }
            }
        }
    }

    $defaults = [
        ['name' => 'Normala', 'percentage' => 21.0, 'label' => 'Normala / 21%'],
        ['name' => 'Taxare inversa', 'percentage' => 0.0, 'label' => 'Taxare inversa / 0%'],
        ['name' => 'Scutit', 'percentage' => 0.0, 'label' => 'Scutit / 0% - chirie'],
    ];

    foreach ($defaults as $d) {
        $exists = false;
        foreach ($options as $o) {
            if (mb_strtolower($o['name'], 'UTF-8') === mb_strtolower($d['name'], 'UTF-8') && (float)$o['percentage'] === (float)$d['percentage']) {
                $exists = true;
                break;
            }
        }
        if (!$exists) $options[] = $d;
    }

    return $options;
}

function bill_current_user_id(): ?int
{
    foreach (['user_id', 'id'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) return (int)$_SESSION[$key];
    }
    if (isset($_SESSION['user']['id']) && is_numeric($_SESSION['user']['id'])) return (int)$_SESSION['user']['id'];
    return null;
}

function bill_contract_label(array $contract): string
{
    $number = trim((string)($contract['contract_number'] ?? ''));
    if ($number === '') $number = 'Contract #' . (int)($contract['id'] ?? 0);
    $date = trim((string)($contract['contract_date'] ?? $contract['start_date'] ?? ''));
    $dateText = bill_date_ro($date);
    return $dateText !== '-' ? ($number . ' / ' . $dateText) : $number;
}

function bill_draft(PDO $pdo, int $id): ?array
{
    bill_ensure_schema($pdo);
    $stmt = $pdo->prepare("\n        SELECT d.*, c.name AS client_name, c.fiscal_code AS client_fiscal_code, ct.contract_number, ct.contract_date\n        FROM billing_drafts d\n        LEFT JOIN clients c ON c.id = d.client_id\n        LEFT JOIN contracts ct ON ct.id = d.contract_id\n        WHERE d.id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bill_draft_items(PDO $pdo, int $draftId): array
{
    bill_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM billing_draft_items WHERE draft_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$draftId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bill_drafts(PDO $pdo, array $filters = []): array
{
    bill_ensure_schema($pdo);
    $where = [];
    $params = [];
    if (!empty($filters['status'])) { $where[] = 'd.status = ?'; $params[] = $filters['status']; }
    if (!empty($filters['q'])) {
        $where[] = '(c.name LIKE ? OR c.fiscal_code LIKE ? OR d.id LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("\n        SELECT d.*, c.name AS client_name, c.fiscal_code AS client_fiscal_code, od.oblio_series, od.oblio_number\n        FROM billing_drafts d\n        LEFT JOIN clients c ON c.id = d.client_id\n        LEFT JOIN billing_oblio_documents od ON od.id = d.issued_document_id\n        $sqlWhere\n        ORDER BY d.updated_at DESC, d.id DESC\n        LIMIT 300\n    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bill_parse_vat_value(string $value): array
{
    $parts = explode('|', $value, 2);
    $name = trim((string)($parts[0] ?? 'Normala')) ?: 'Normala';
    $pct = isset($parts[1]) ? (float)str_replace(',', '.', $parts[1]) : 21.0;
    return [$name, $pct];
}

function bill_collect_items_from_form(array $post): array
{
    $names = (array)($post['item_name'] ?? []);
    $qtys = (array)($post['item_quantity'] ?? []);
    $ums = (array)($post['item_unit'] ?? []);
    $prices = (array)($post['item_price'] ?? []);
    $vatKeys = (array)($post['item_vat_key'] ?? []);
    $productIds = (array)($post['item_product_id'] ?? []);
    $descriptions = (array)($post['item_description'] ?? []);

    $items = [];
    foreach ($names as $i => $name) {
        $name = trim((string)$name);
        if ($name === '') continue;
        $qty = max(0.001, (float)str_replace(',', '.', (string)($qtys[$i] ?? 1)));
        $price = (float)str_replace(',', '.', (string)($prices[$i] ?? 0));
        $unit = trim((string)($ums[$i] ?? 'buc')) ?: 'buc';
        [$vatName, $vatPct] = bill_parse_vat_value((string)($vatKeys[$i] ?? 'Normala|21'));
        $items[] = [
            'product_id' => !empty($productIds[$i]) ? (int)$productIds[$i] : null,
            'name' => $name,
            'description' => trim((string)($descriptions[$i] ?? '')),
            'quantity' => $qty,
            'measuring_unit' => $unit,
            'unit_price' => $price,
            'currency' => trim((string)($post['currency'] ?? 'RON')) ?: 'RON',
            'vat_name' => $vatName,
            'vat_percentage' => $vatPct,
            'vat_included' => !empty($post['vat_included']) ? 1 : 0,
            'line_total' => $qty * $price,
        ];
    }
    return $items;
}

function bill_save_draft_from_form(PDO $pdo, array $post): array
{
    bill_ensure_schema($pdo);
    $draftId = (int)($post['draft_id'] ?? 0);
    $type = strtolower((string)($post['document_type'] ?? 'invoice'));
    if (!in_array($type, ['invoice','proforma'], true)) $type = 'invoice';

    $clientId = (int)($post['client_id'] ?? 0);
    if ($clientId <= 0 || !bill_client($pdo, $clientId)) {
        return ['ok' => false, 'error' => 'Selecteaza clientul din CRM.'];
    }

    $contractId = !empty($post['contract_id']) ? (int)$post['contract_id'] : null;
    if ($contractId && !bill_contract($pdo, $contractId)) $contractId = null;

    $items = bill_collect_items_from_form($post);
    if (!$items) return ['ok' => false, 'error' => 'Adauga cel putin un produs sau serviciu.'];

    $total = 0.0;
    foreach ($items as $item) $total += (float)$item['line_total'];

    $data = [
        'document_type' => $type,
        'client_id' => $clientId,
        'contract_id' => $contractId,
        'issue_date' => trim((string)($post['issue_date'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
        'due_date' => trim((string)($post['due_date'] ?? '')) ?: null,
        'currency' => trim((string)($post['currency'] ?? 'RON')) ?: 'RON',
        'invoice_by_contract' => !empty($post['invoice_by_contract']) && $contractId ? 1 : 0,
        'mentions' => trim((string)($post['mentions'] ?? '')),
        'total' => $total,
    ];

    $pdo->beginTransaction();
    try {
        if ($draftId > 0) {
            $draft = bill_draft($pdo, $draftId);
            if (!$draft) throw new RuntimeException('Schita nu exista.');
            if ($draft['status'] === 'issued') throw new RuntimeException('Schita este deja emisa in Oblio.');
            $stmt = $pdo->prepare("\n                UPDATE billing_drafts\n                SET document_type=?, client_id=?, contract_id=?, issue_date=?, due_date=?, currency=?, invoice_by_contract=?, mentions=?, total=?, updated_at=NOW()\n                WHERE id=?\n            ");
            $stmt->execute([$data['document_type'], $data['client_id'], $data['contract_id'], $data['issue_date'], $data['due_date'], $data['currency'], $data['invoice_by_contract'], $data['mentions'], $data['total'], $draftId]);
        } else {
            $stmt = $pdo->prepare("\n                INSERT INTO billing_drafts\n                (document_type, status, client_id, contract_id, issue_date, due_date, currency, invoice_by_contract, mentions, total, created_by, updated_at)\n                VALUES (?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n            ");
            $stmt->execute([$data['document_type'], $data['client_id'], $data['contract_id'], $data['issue_date'], $data['due_date'], $data['currency'], $data['invoice_by_contract'], $data['mentions'], $data['total'], bill_current_user_id()]);
            $draftId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM billing_draft_items WHERE draft_id = ?')->execute([$draftId]);
        $stmt = $pdo->prepare("\n            INSERT INTO billing_draft_items\n            (draft_id, product_id, name, description, quantity, measuring_unit, unit_price, currency, vat_name, vat_percentage, vat_included, line_total, sort_order)\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ");
        foreach ($items as $i => $item) {
            $stmt->execute([$draftId, $item['product_id'], $item['name'], $item['description'], $item['quantity'], $item['measuring_unit'], $item['unit_price'], $item['currency'], $item['vat_name'], $item['vat_percentage'], $item['vat_included'], $item['line_total'], $i]);
        }
        if ($pdo->inTransaction()) { if ($pdo->inTransaction()) { $pdo->commit(); } }
        return ['ok' => true, 'draft_id' => $draftId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function bill_draft_to_oblio_products(PDO $pdo, array $items): array
{
    $products = [];
    $localItems = [];
    foreach ($items as $item) {
        $p = oblio_build_service_product($pdo, (string)$item['name'], (float)$item['unit_price'], (float)$item['quantity'], (string)$item['description'], [
            'measuringUnit' => (string)$item['measuring_unit'],
            'vatName' => (string)$item['vat_name'],
            'vatPercentage' => (float)$item['vat_percentage'],
            'vatIncluded' => !empty($item['vat_included']) ? 1 : 0,
        ]);
        $products[] = $p;
        $localItems[] = array_merge($p, ['product_id' => !empty($item['product_id']) ? (int)$item['product_id'] : null]);
    }
    return [$products, $localItems];
}


function bill_required_oblio_series(PDO $pdo, string $type): array
{
    $settings = oblio_settings($pdo);
    $type = strtolower($type);

    if ($type === 'invoice') {
        $series = trim((string)($settings['oblio.invoice_series'] ?? ''));

        if ($series === '') {
            return [
                'ok' => false,
                'error' => 'Lipseste seria de factura. Mergi la Setari → Integrare facturare si completeaza campul Serie factura exact ca in Oblio.',
            ];
        }

        return ['ok' => true, 'series' => $series];
    }

    if ($type === 'proforma') {
        $series = trim((string)($settings['oblio.proforma_series'] ?? ''));

        if ($series === '') {
            return [
                'ok' => false,
                'error' => 'Lipseste seria de proforma. Mergi la Setari → Integrare facturare si completeaza campul Serie proforma exact ca in Oblio sau emite documentul ca Factura.',
            ];
        }

        return ['ok' => true, 'series' => $series];
    }

    return [
        'ok' => false,
        'error' => 'Tip document invalid pentru Oblio.',
    ];
}
function bill_issue_draft_to_oblio(PDO $pdo, int $draftId): array
{
    bill_ensure_schema($pdo);

    $draft = bill_draft($pdo, $draftId);

    if (!$draft) {
        return ['ok' => false, 'error' => 'Schita nu exista.'];
    }

    if ($draft['status'] === 'issued') {
        return ['ok' => false, 'error' => 'Aceasta schita este deja emisa.'];
    }

    $type = strtolower((string)$draft['document_type']);

    if (!in_array($type, ['invoice', 'proforma'], true)) {
        return ['ok' => false, 'error' => 'Tip document invalid.'];
    }

    $seriesCheck = bill_required_oblio_series($pdo, $type);

    if (empty($seriesCheck['ok'])) {
        return $seriesCheck;
    }

    $client = bill_client($pdo, (int)$draft['client_id']);

    if (!$client) {
        return ['ok' => false, 'error' => 'Clientul nu exista.'];
    }

    $items = bill_draft_items($pdo, $draftId);

    if (!$items) {
        return ['ok' => false, 'error' => 'Schita nu are produse/servicii.'];
    }

    [$products, $localItems] = bill_draft_to_oblio_products($pdo, $items);

    $contractId = !empty($draft['contract_id']) ? (int)$draft['contract_id'] : null;
    $contract = $contractId ? bill_contract($pdo, $contractId) : null;

    $mentions = trim((string)($draft['mentions'] ?? ''));

    if (!empty($draft['invoice_by_contract']) && $contract) {
        $label = bill_contract_label($contract);

        if ($label !== '') {
            $mentions = trim($mentions . "\n" . 'Facturare conf. contract ' . $label);
        }
    }

    $extra = [
        'issueDate' => $draft['issue_date'] ?: date('Y-m-d'),
        'dueDate' => $draft['due_date'] ?: null,
        'mentions' => $mentions,
        'internalNote' => 'Document emis din PestZone CRM pe baza schitei #' . $draftId,
        'idempotencyKey' => 'pz_draft_' . $draftId . '_' . date('YmdHis'),
    ];

    $payload = oblio_basic_document_payload($pdo, $type, $client, $products, $extra);

    if (empty($payload['seriesName'])) {
        return [
            'ok' => false,
            'error' => $type === 'invoice'
                ? 'Lipseste seria de factura in payload. Verifica Setari → Integrare facturare → Serie factura.'
                : 'Lipseste seria de proforma in payload. Verifica Setari → Integrare facturare → Serie proforma.',
            'payload' => $payload,
        ];
    }

    $res = oblio_issue_document($pdo, $type, $payload);

    if (empty($res['ok'])) {
        return [
            'ok' => false,
            'error' => $res['error'] ?? 'Eroare emitere in Oblio.',
            'response' => $res,
            'payload' => $payload,
        ];
    }

    $data = is_array($res['data'] ?? null) ? $res['data'] : [];

    $series = (string)($data['seriesName'] ?? '');
    $number = (string)($data['number'] ?? '');

    if ($series !== '' && $number !== '') {
        $view = oblio_get_document($pdo, $type, $series, $number);

        if (!empty($view['ok']) && is_array($view['data'])) {
            $data = array_merge($data, $view['data']);
        }
    }

    $documentId = bill_save_oblio_document($pdo, $type, $data, (int)$draft['client_id'], $contractId, $localItems, (float)$draft['total']);

    $stmt = $pdo->prepare("
        UPDATE billing_drafts
        SET status = 'issued',
            issued_document_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$documentId, $draftId]);

    return [
        'ok' => true,
        'document_id' => $documentId,
        'draft_id' => $draftId,
        'oblio' => $data,
    ];
}



function bill_delete_draft(PDO $pdo, int $draftId): bool
{
    $draft = bill_draft($pdo, $draftId);
    if (!$draft || $draft['status'] === 'issued') return false;
    $pdo->prepare('DELETE FROM billing_draft_items WHERE draft_id = ?')->execute([$draftId]);
    $pdo->prepare('DELETE FROM billing_drafts WHERE id = ?')->execute([$draftId]);
    return true;
}
