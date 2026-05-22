<?php
/* STOCK_LIB_PESTZONE_CLEAN_V4 - fara cod in afara PHP */

if (!function_exists('stock_h')) {
    function stock_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('stock_decimal')) {
    function stock_decimal($value): float
    {
        $value = trim((string)$value);
        $value = str_replace([' ', ','], ['', '.'], $value);
        if ($value === '' || !is_numeric($value)) {
            return 0.0;
        }
        return round((float)$value, 3);
    }
}

if (!function_exists('stock_fmt_qty')) {
    function stock_fmt_qty($value): string
    {
        $n = (float)$value;
        $s = number_format($n, 3, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
}

if (!function_exists('stock_group_options')) {
    function stock_group_options(): array
    {
        return [
            'dezinsectie' => 'Dezinsecție',
            'dezinfectie' => 'Dezinfecție',
            'deratizare' => 'Deratizare',
            'materiale' => 'Materiale',
        ];
    }
}

if (!function_exists('stock_group_label')) {
    function stock_group_label(string $group): string
    {
        $labels = stock_group_options();
        return $labels[$group] ?? $group;
    }
}

if (!function_exists('stock_unit_options')) {
    function stock_unit_options(): array
    {
        return [
            'ml' => 'ml',
            'gr' => 'gr',
            'buc' => 'buc',
        ];
    }
}

if (!function_exists('stock_is_biocide_group')) {
    function stock_is_biocide_group(string $group): bool
    {
        return in_array($group, ['dezinsectie', 'dezinfectie', 'deratizare'], true);
    }
}

if (!function_exists('stock_movement_labels')) {
    function stock_movement_labels(): array
    {
        return [
            'receipt' => 'Intrare stoc',
            'loss' => 'Pierdere',
            'expired' => 'Produs expirat',
            'adjust_minus' => 'Ajustare minus',
            'adjust_plus' => 'Ajustare plus',
            'consume' => 'Consum lucrare/PV',
            'return' => 'Retur',
        ];
    }
}

if (!function_exists('stock_movement_label')) {
    function stock_movement_label(string $type): string
    {
        $labels = stock_movement_labels();
        return $labels[$type] ?? $type;
    }
}

if (!function_exists('stock_unit_display')) {
    function stock_unit_display($qty, string $unit): string
    {
        $qty = (float)$qty;
        $base = stock_fmt_qty($qty) . ' ' . $unit;
        if ($unit === 'ml') {
            return $base . ' / ' . stock_fmt_qty($qty / 1000) . ' L';
        }
        if ($unit === 'gr') {
            return $base . ' / ' . stock_fmt_qty($qty / 1000) . ' kg';
        }
        return $base;
    }
}

if (!function_exists('stock_package_display')) {
    function stock_package_display($packageQty, string $unit): string
    {
        $packageQty = (float)$packageQty;
        if ($packageQty <= 0) {
            $packageQty = 1;
        }
        return '1 ambalaj = ' . stock_unit_display($packageQty, $unit);
    }
}

if (!function_exists('stock_table_exists')) {
    function stock_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('stock_column_exists')) {
    function stock_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('stock_add_column_if_missing')) {
    function stock_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!stock_column_exists($pdo, $table, $column)) {
            $safeTable = str_replace('`', '', $table);
            $safeColumn = str_replace('`', '', $column);
            $pdo->exec('ALTER TABLE `' . $safeTable . '` ADD COLUMN `' . $safeColumn . '` ' . $definition);
        }
    }
}

if (!function_exists('stock_ensure_schema')) {
    /**
     * Crează idempotent toate tabelele și coloanele necesare pentru modulul Gestiune.
     * Se rulează automat la prima accesare a oricărei pagini stock_*.php, fără pași manuali.
     */
    function stock_ensure_schema(PDO $pdo): void
    {
        try {
            if (!stock_table_exists($pdo, 'stock_products')) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS stock_products (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        name VARCHAR(255) NOT NULL,
                        product_group VARCHAR(40) NOT NULL DEFAULT 'materiale',
                        unit_consumption VARCHAR(10) NOT NULL DEFAULT 'buc',
                        package_qty DECIMAL(14,3) NOT NULL DEFAULT 1,
                        min_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
                        aviz_no VARCHAR(120) NULL,
                        aviz_valid_until DATE NULL,
                        aviz_file VARCHAR(255) NULL,
                        active_substance VARCHAR(255) NULL,
                        product_concentration VARCHAR(120) NULL,
                        contact_time VARCHAR(120) NULL,
                        default_application_method VARCHAR(120) NULL,
                        safety_measures TEXT NULL,
                        notes TEXT NULL,
                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                        created_at DATETIME NULL,
                        updated_at DATETIME NULL,
                        PRIMARY KEY (id),
                        KEY idx_stock_products_group (product_group),
                        KEY idx_stock_products_active (is_active)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            if (!stock_table_exists($pdo, 'stock_receipts')) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS stock_receipts (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        product_id INT UNSIGNED NOT NULL,
                        reception_date DATE NOT NULL,
                        document_no VARCHAR(120) NOT NULL,
                        supplier VARCHAR(255) NULL,
                        qty DECIMAL(14,3) NOT NULL DEFAULT 0,
                        package_count DECIMAL(14,3) NULL,
                        lot VARCHAR(120) NULL,
                        expires_at DATE NULL,
                        notes TEXT NULL,
                        created_by INT UNSIGNED NULL,
                        created_at DATETIME NULL,
                        cancelled_at DATETIME NULL,
                        cancelled_by INT UNSIGNED NULL,
                        cancel_reason TEXT NULL,
                        PRIMARY KEY (id),
                        KEY idx_stock_receipts_product (product_id),
                        KEY idx_stock_receipts_date (reception_date),
                        KEY idx_stock_receipts_expires (expires_at),
                        KEY idx_stock_receipts_cancelled (cancelled_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            if (!stock_table_exists($pdo, 'stock_movements')) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS stock_movements (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        product_id INT UNSIGNED NOT NULL,
                        receipt_id INT UNSIGNED NULL,
                        movement_type VARCHAR(40) NOT NULL,
                        qty DECIMAL(14,3) NOT NULL DEFAULT 0,
                        reference_type VARCHAR(60) NULL,
                        reference_id INT UNSIGNED NULL,
                        notes TEXT NULL,
                        created_by INT UNSIGNED NULL,
                        created_at DATETIME NULL,
                        procedure_at DATETIME NULL,
                        beneficiary_name VARCHAR(255) NULL,
                        procedure_type VARCHAR(40) NULL,
                        work_concentration VARCHAR(120) NULL,
                        pv_no VARCHAR(120) NULL,
                        workers_names VARCHAR(255) NULL,
                        PRIMARY KEY (id),
                        KEY idx_stock_movements_product (product_id),
                        KEY idx_stock_movements_receipt (receipt_id),
                        KEY idx_stock_movements_type (movement_type),
                        KEY idx_stock_movements_reference (reference_type, reference_id),
                        KEY idx_stock_movements_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            if (!stock_table_exists($pdo, 'stock_inventories')) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS stock_inventories (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        inventory_date DATE NOT NULL,
                        product_group VARCHAR(40) NULL,
                        notes TEXT NULL,
                        total_lines INT UNSIGNED NOT NULL DEFAULT 0,
                        lines_with_diff INT UNSIGNED NOT NULL DEFAULT 0,
                        positive_adjustments INT UNSIGNED NOT NULL DEFAULT 0,
                        negative_adjustments INT UNSIGNED NOT NULL DEFAULT 0,
                        status VARCHAR(20) NOT NULL DEFAULT 'draft',
                        created_by INT UNSIGNED NULL,
                        created_at DATETIME NOT NULL,
                        finalized_by INT UNSIGNED NULL,
                        finalized_at DATETIME NULL,
                        PRIMARY KEY (id),
                        KEY idx_stock_inventories_status (status),
                        KEY idx_stock_inventories_date (inventory_date)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            if (!stock_table_exists($pdo, 'stock_inventory_lines')) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS stock_inventory_lines (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        inventory_id INT UNSIGNED NOT NULL,
                        product_id INT UNSIGNED NOT NULL,
                        receipt_id INT UNSIGNED NULL,
                        expected_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
                        counted_qty DECIMAL(14,3) NULL,
                        difference DECIMAL(14,3) NULL,
                        movement_id INT UNSIGNED NULL,
                        notes VARCHAR(255) NULL,
                        PRIMARY KEY (id),
                        KEY idx_stock_inventory_lines_inv (inventory_id),
                        KEY idx_stock_inventory_lines_product (product_id),
                        KEY idx_stock_inventory_lines_receipt (receipt_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            // Coloane care s-ar putea să lipsească pe instalări vechi - se adaugă idempotent.
            stock_add_column_if_missing($pdo, 'stock_products', 'aviz_file', "VARCHAR(255) NULL AFTER aviz_valid_until");
            stock_add_column_if_missing($pdo, 'stock_products', 'active_substance', "VARCHAR(255) NULL");
            stock_add_column_if_missing($pdo, 'stock_products', 'product_concentration', "VARCHAR(120) NULL");
            stock_add_column_if_missing($pdo, 'stock_products', 'contact_time', "VARCHAR(120) NULL");
            stock_add_column_if_missing($pdo, 'stock_products', 'default_application_method', "VARCHAR(120) NULL");
            stock_add_column_if_missing($pdo, 'stock_products', 'safety_measures', "TEXT NULL");
            stock_add_column_if_missing($pdo, 'stock_products', 'notes', "TEXT NULL");
            stock_add_column_if_missing($pdo, 'stock_products', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
            stock_add_column_if_missing($pdo, 'stock_products', 'updated_at', "DATETIME NULL");

            stock_add_column_if_missing($pdo, 'stock_receipts', 'package_count', "DECIMAL(14,3) NULL");
            stock_add_column_if_missing($pdo, 'stock_receipts', 'lot', "VARCHAR(120) NULL");
            stock_add_column_if_missing($pdo, 'stock_receipts', 'expires_at', "DATE NULL");
            stock_add_column_if_missing($pdo, 'stock_receipts', 'cancelled_at', "DATETIME NULL");
            stock_add_column_if_missing($pdo, 'stock_receipts', 'cancelled_by', "INT UNSIGNED NULL");
            stock_add_column_if_missing($pdo, 'stock_receipts', 'cancel_reason', "TEXT NULL");

            stock_add_column_if_missing($pdo, 'stock_movements', 'receipt_id', "INT UNSIGNED NULL AFTER product_id");
            stock_add_column_if_missing($pdo, 'stock_movements', 'reference_type', "VARCHAR(60) NULL");
            stock_add_column_if_missing($pdo, 'stock_movements', 'reference_id', "INT UNSIGNED NULL");
            stock_add_column_if_missing($pdo, 'stock_movements', 'procedure_at', "DATETIME NULL");
            stock_add_column_if_missing($pdo, 'stock_movements', 'beneficiary_name', "VARCHAR(255) NULL");
            stock_add_column_if_missing($pdo, 'stock_movements', 'procedure_type', "VARCHAR(40) NULL");
            stock_add_column_if_missing($pdo, 'stock_movements', 'work_concentration', "VARCHAR(120) NULL");
            stock_add_column_if_missing($pdo, 'stock_movements', 'pv_no', "VARCHAR(120) NULL");
            stock_add_column_if_missing($pdo, 'stock_movements', 'workers_names', "VARCHAR(255) NULL");
        } catch (Throwable $e) {
            error_log('PestZone stock schema ensure error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('stock_get_product')) {
    function stock_get_product(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('stock_current_by_product')) {
    function stock_current_by_product(PDO $pdo): array
    {
        if (!stock_table_exists($pdo, 'stock_products')) {
            return [];
        }
        $sql = "
            SELECT
                p.*,
                COALESCE(r.in_qty, 0) AS in_qty,
                COALESCE(m.plus_qty, 0) AS plus_qty,
                COALESCE(m.minus_qty, 0) AS minus_qty,
                (COALESCE(r.in_qty, 0) + COALESCE(m.plus_qty, 0) - COALESCE(m.minus_qty, 0)) AS current_qty
            FROM stock_products p
            LEFT JOIN (
                SELECT product_id, SUM(qty) AS in_qty
                FROM stock_receipts
                GROUP BY product_id
            ) r ON r.product_id = p.id
            LEFT JOIN (
                SELECT
                    product_id,
                    SUM(CASE WHEN movement_type IN ('adjust_plus','return') THEN qty ELSE 0 END) AS plus_qty,
                    SUM(CASE WHEN movement_type IN ('consume','adjust_minus','loss','expired') THEN qty ELSE 0 END) AS minus_qty
                FROM stock_movements
                GROUP BY product_id
            ) m ON m.product_id = p.id
            ORDER BY p.name ASC
        ";
        try {
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $pdo->query('SELECT *, 0 AS in_qty, 0 AS plus_qty, 0 AS minus_qty, 0 AS current_qty FROM stock_products ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

if (!function_exists('stock_current_qty_for_product')) {
    function stock_current_qty_for_product(PDO $pdo, int $productId): float
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) FROM stock_receipts WHERE product_id = ?");
        $stmt->execute([$productId]);
        $in = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN movement_type IN ('adjust_plus','return') THEN qty ELSE 0 END), 0) AS plus_qty,
            COALESCE(SUM(CASE WHEN movement_type IN ('consume','adjust_minus','loss','expired') THEN qty ELSE 0 END), 0) AS minus_qty
            FROM stock_movements WHERE product_id = ?");
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['plus_qty' => 0, 'minus_qty' => 0];
        return round($in + (float)$row['plus_qty'] - (float)$row['minus_qty'], 3);
    }
}

if (!function_exists('stock_low_stock_rows')) {
    function stock_low_stock_rows(PDO $pdo): array
    {
        $rows = stock_current_by_product($pdo);
        return array_values(array_filter($rows, function ($r) {
            return (float)($r['min_qty'] ?? 0) > 0 && (float)($r['current_qty'] ?? 0) <= (float)($r['min_qty'] ?? 0);
        }));
    }
}

if (!function_exists('stock_lots_for_product')) {
    function stock_lots_for_product(PDO $pdo, int $productId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_receipts WHERE product_id = ? ORDER BY reception_date ASC, id ASC');
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('stock_expiring_lots_query')) {
    /**
     * Query intern reutilizabil pentru loturi cu stoc disponibil > 0.
     * Returnează doar loturile active (cancelled_at IS NULL) cu cantitate netă rămasă.
     *
     * @param string $where  Clauză WHERE suplimentară (fără AND la început), referă r.* și p.*
     */
    function stock_expiring_lots_query(PDO $pdo, string $where = '', array $params = []): array
    {
        if (!stock_table_exists($pdo, 'stock_receipts') || !stock_table_exists($pdo, 'stock_products')) {
            return [];
        }
        $extraWhere = trim($where) !== '' ? (' AND ' . $where) : '';
        $sql = "
            SELECT
                r.id AS receipt_id,
                r.product_id,
                r.lot,
                r.expires_at,
                r.reception_date,
                r.document_no,
                r.qty AS received_qty,
                p.name AS product_name,
                p.product_group,
                p.unit_consumption,
                p.aviz_no,
                (
                    r.qty
                    + COALESCE((SELECT SUM(qty) FROM stock_movements m
                        WHERE m.receipt_id = r.id
                          AND m.movement_type IN ('adjust_plus','return')), 0)
                    - COALESCE((SELECT SUM(qty) FROM stock_movements m
                        WHERE m.receipt_id = r.id
                          AND m.movement_type IN ('consume','adjust_minus','loss','expired')), 0)
                ) AS available_qty
            FROM stock_receipts r
            INNER JOIN stock_products p ON p.id = r.product_id
            WHERE r.cancelled_at IS NULL
              AND r.expires_at IS NOT NULL
              $extraWhere
            HAVING available_qty > 0.0001
            ORDER BY r.expires_at ASC, r.id ASC
            LIMIT 200
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('stock_expiring_soon_rows')) {
    /**
     * Loturi care expiră în următoarele $days zile (default 30) și au încă stoc disponibil.
     */
    function stock_expiring_soon_rows(PDO $pdo, int $days = 30): array
    {
        $days = max(1, $days);
        return stock_expiring_lots_query(
            $pdo,
            'r.expires_at >= CURDATE() AND r.expires_at <= DATE_ADD(CURDATE(), INTERVAL ? DAY)',
            [$days]
        );
    }
}

if (!function_exists('stock_already_expired_with_stock_rows')) {
    /**
     * Loturi deja expirate dar care au încă stoc neutilizat (risc de consum eronat).
     */
    function stock_already_expired_with_stock_rows(PDO $pdo): array
    {
        return stock_expiring_lots_query($pdo, 'r.expires_at < CURDATE()');
    }
}

if (!function_exists('stock_count_expiring_soon')) {
    function stock_count_expiring_soon(PDO $pdo, int $days = 30): int
    {
        return count(stock_expiring_soon_rows($pdo, $days));
    }
}

if (!function_exists('stock_count_already_expired')) {
    function stock_count_already_expired(PDO $pdo): int
    {
        return count(stock_already_expired_with_stock_rows($pdo));
    }
}


if (!function_exists('stock_available_qty_for_receipt')) {
    function stock_available_qty_for_receipt(PDO $pdo, int $receiptId): float
    {
        if ($receiptId <= 0 || !stock_table_exists($pdo, 'stock_receipts')) {
            return 0.0;
        }

        $stmt = $pdo->prepare('SELECT product_id, qty FROM stock_receipts WHERE id = ? LIMIT 1');
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$receipt) {
            return 0.0;
        }

        $baseQty = (float)($receipt['qty'] ?? 0);
        if (!stock_table_exists($pdo, 'stock_movements')) {
            return round($baseQty, 3);
        }

        $stmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN movement_type IN ('adjust_plus','return') THEN qty ELSE 0 END), 0) AS plus_qty,
            COALESCE(SUM(CASE WHEN movement_type IN ('consume','adjust_minus','loss','expired') THEN qty ELSE 0 END), 0) AS minus_qty
            FROM stock_movements
            WHERE receipt_id = ? AND product_id = ?");
        $stmt->execute([$receiptId, (int)$receipt['product_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['plus_qty' => 0, 'minus_qty' => 0];

        return round($baseQty + (float)$row['plus_qty'] - (float)$row['minus_qty'], 3);
    }
}

if (!function_exists('stock_insert_movement_dynamic')) {
    function stock_insert_movement_dynamic(PDO $pdo, array $data): void
    {
        if (!stock_table_exists($pdo, 'stock_movements')) {
            stock_ensure_schema($pdo);
            if (!stock_table_exists($pdo, 'stock_movements')) {
                throw new RuntimeException('Tabelul stock_movements nu există și nu poate fi creat automat.');
            }
        }

        $columns = [
            'product_id' => (int)($data['product_id'] ?? 0),
            'receipt_id' => !empty($data['receipt_id']) ? (int)$data['receipt_id'] : null,
            'movement_type' => (string)($data['movement_type'] ?? ''),
            'qty' => (float)($data['qty'] ?? 0),
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => !empty($data['reference_id']) ? (int)$data['reference_id'] : null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? (function_exists('current_user_id') ? current_user_id() : null),
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ];

        foreach (['procedure_at', 'beneficiary_name', 'procedure_type', 'work_concentration', 'pv_no', 'workers_names'] as $optionalColumn) {
            if (array_key_exists($optionalColumn, $data) && stock_column_exists($pdo, 'stock_movements', $optionalColumn)) {
                $columns[$optionalColumn] = $data[$optionalColumn];
            }
        }

        $names = array_keys($columns);
        $placeholders = array_map(function($c) { return ':' . $c; }, $names);
        $sql = 'INSERT INTO stock_movements (`' . implode('`,`', $names) . '`) VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        foreach ($columns as $column => $value) {
            $stmt->bindValue(':' . $column, $value);
        }
        $stmt->execute();
    }
}

if (!function_exists('stock_count_document_consumes')) {
    function stock_count_document_consumes(PDO $pdo, int $documentId): int
    {
        if ($documentId <= 0 || !stock_table_exists($pdo, 'stock_movements')) {
            return 0;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'consume' AND reference_type = 'document_pv' AND reference_id = ?");
        $stmt->execute([$documentId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('stock_resolve_document_material_stock')) {
    function stock_resolve_document_material_stock(PDO $pdo, array $material, float $neededQty = 0.0): array
    {
        $productId = (int)($material['stock_product_id'] ?? 0);
        $receiptId = (int)($material['stock_receipt_id'] ?? 0);
        $materialId = (int)($material['id'] ?? 0);
        $name = trim((string)($material['material_name'] ?? ''));
        $lot = trim((string)($material['lot_number'] ?? ''));

        if ($receiptId > 0 && $productId <= 0) {
            $stmt = $pdo->prepare('SELECT product_id FROM stock_receipts WHERE id = ? LIMIT 1');
            $stmt->execute([$receiptId]);
            $productId = (int)$stmt->fetchColumn();
        }

        if ($productId <= 0 && $name !== '') {
            $stmt = $pdo->prepare('SELECT id FROM stock_products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) ORDER BY id ASC LIMIT 2');
            $stmt->execute([$name]);
            $matches = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (count($matches) === 1) {
                $productId = (int)$matches[0];
            }
        }

        if ($receiptId <= 0 && $lot !== '') {
            if ($productId > 0) {
                $stmt = $pdo->prepare('SELECT id FROM stock_receipts WHERE product_id = ? AND LOWER(TRIM(lot)) = LOWER(TRIM(?)) ORDER BY reception_date ASC, id ASC LIMIT 10');
                $stmt->execute([$productId, $lot]);
                $matches = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $availableMatches = array_values(array_map('intval', $matches));
                if (count($availableMatches) === 1) {
                    $receiptId = (int)$availableMatches[0];
                }
            } elseif ($name !== '') {
                $stmt = $pdo->prepare("\n                    SELECT r.id, r.product_id\n                    FROM stock_receipts r\n                    INNER JOIN stock_products p ON p.id = r.product_id\n                    WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(?)) AND LOWER(TRIM(r.lot)) = LOWER(TRIM(?))\n                    ORDER BY r.reception_date ASC, r.id ASC\n                    LIMIT 10\n                ");
                $stmt->execute([$name, $lot]);
                $matches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $availableMatches = array_values($matches);
                if (count($availableMatches) === 1) {
                    $receiptId = (int)$availableMatches[0]['id'];
                    $productId = (int)$availableMatches[0]['product_id'];
                }
            }
        }

        if ($productId > 0 && $receiptId <= 0) {
            $stmt = $pdo->prepare("SELECT id FROM stock_receipts WHERE product_id = ? ORDER BY COALESCE(expires_at, '2999-12-31') ASC, reception_date ASC, id ASC LIMIT 20");
            $stmt->execute([$productId]);
            $matches = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $availableMatches = array_values(array_map('intval', $matches));
            if (count($availableMatches) === 1) {
                $receiptId = (int)$availableMatches[0];
            }
        }

        if ($materialId > 0 && ($productId > 0 || $receiptId > 0)) {
            $stmt = $pdo->prepare('UPDATE document_materials SET stock_product_id = COALESCE(NULLIF(?, 0), stock_product_id), stock_receipt_id = COALESCE(NULLIF(?, 0), stock_receipt_id) WHERE id = ?');
            $stmt->execute([$productId, $receiptId, $materialId]);
        }

        return [$productId, $receiptId];
    }
}

if (!function_exists('stock_consume_document_materials')) {
    function stock_consume_document_materials(PDO $pdo, int $documentId): void
    {
        if ($documentId <= 0 || !stock_table_exists($pdo, 'stock_products') || !stock_table_exists($pdo, 'stock_receipts') || !stock_table_exists($pdo, 'stock_movements')) {
            return;
        }
        if (!stock_table_exists($pdo, 'documents') || !stock_table_exists($pdo, 'document_materials')) {
            return;
        }

        if (stock_count_document_consumes($pdo, $documentId) > 0) {
            return; // protectie impotriva scaderii duble
        }

        $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? LIMIT 1");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$document || ($document['document_type'] ?? '') !== 'proces_verbal' || ($document['status'] ?? '') !== 'issued') {
            return;
        }

        $payload = [];
        if (!empty($document['payload_json'])) {
            $decoded = json_decode((string)$document['payload_json'], true);
            if (is_array($decoded)) { $payload = $decoded; }
        }
        if (($payload['stock_consumption_deferred'] ?? '') === '1') {
            return;
        }

        $stmt = $pdo->prepare("SELECT * FROM document_materials WHERE document_id = ? AND TRIM(material_name) <> '' ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$documentId]);
        $materials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$materials) {
            return;
        }

        $created = 0;
        foreach ($materials as $material) {
            $qty = stock_decimal($material['quantity'] ?? 0);
            if ($qty <= 0) {
                throw new RuntimeException('Cantitate lipsă pentru produsul "' . (string)($material['material_name'] ?? 'produs') . '".');
            }
            [$productId, $receiptId] = stock_resolve_document_material_stock($pdo, $material, $qty);
            if ($productId <= 0) {
                throw new RuntimeException('Produs negăsit în gestiune: ' . (string)($material['material_name'] ?? 'produs'));
            }
            if ($receiptId <= 0) {
                throw new RuntimeException('Selectează lotul pentru produsul "' . (string)($material['material_name'] ?? 'produs') . '" înainte de emiterea PV.');
            }

            $product = stock_get_product($pdo, $productId);
            if (!$product) {
                throw new RuntimeException('Produsul din PV nu mai există in gestiune: ' . (string)($material['material_name'] ?? $productId));
            }

            $stmtReceipt = $pdo->prepare('SELECT * FROM stock_receipts WHERE id = ? AND product_id = ? LIMIT 1 FOR UPDATE');
            $stmtReceipt->execute([$receiptId, $productId]);
            $receipt = $stmtReceipt->fetch(PDO::FETCH_ASSOC);
            if (!$receipt) {
                throw new RuntimeException('Lotul selectat nu aparține produsului "' . (string)($product['name'] ?? $material['material_name']) . '".');
            }

            $procedureAt = trim((string)($document['document_date'] ?? ''));
            if ($procedureAt !== '') {
                $time = trim((string)($document['document_time'] ?? ''));
                $procedureAt .= ' ' . ($time !== '' ? substr($time, 0, 8) : '00:00:00');
            } else {
                $procedureAt = date('Y-m-d H:i:s');
            }

            stock_insert_movement_dynamic($pdo, [
                'product_id' => $productId,
                'receipt_id' => $receiptId,
                'movement_type' => 'consume',
                'qty' => $qty,
                'reference_type' => 'document_pv',
                'reference_id' => $documentId,
                'notes' => 'Consum PV ' . ((string)($document['document_number'] ?? '') ?: '#' . $documentId),
                'procedure_at' => $procedureAt,
                'beneficiary_name' => (string)($document['client_name_snapshot'] ?? ''),
                'procedure_type' => (string)($material['product_group'] ?? $product['product_group'] ?? ''),
                'work_concentration' => (string)($material['work_concentration'] ?? ''),
                'pv_no' => (string)($document['document_number'] ?? ''),
                'workers_names' => (string)($payload['workers_names'] ?? ''),
            ]);
            $created++;
        }

    }
}

if (!function_exists('stock_return_document_materials_on_cancel')) {
    function stock_return_document_materials_on_cancel(PDO $pdo, int $documentId): void
    {
        if ($documentId <= 0 || !stock_table_exists($pdo, 'stock_movements')) {
            return;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'return' AND reference_type = 'document_pv_cancel' AND reference_id = ?");
        $stmt->execute([$documentId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return; // deja returnat
        }

        $stmt = $pdo->prepare("SELECT * FROM stock_movements WHERE movement_type = 'consume' AND reference_type = 'document_pv' AND reference_id = ? ORDER BY id ASC");
        $stmt->execute([$documentId]);
        $consumes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($consumes as $consume) {
            stock_insert_movement_dynamic($pdo, [
                'product_id' => (int)$consume['product_id'],
                'receipt_id' => !empty($consume['receipt_id']) ? (int)$consume['receipt_id'] : null,
                'movement_type' => 'return',
                'qty' => stock_decimal($consume['qty'] ?? 0),
                'reference_type' => 'document_pv_cancel',
                'reference_id' => $documentId,
                'notes' => 'Retur automat la anulare PV pentru miscarea #' . (int)$consume['id'],
                'procedure_at' => $consume['procedure_at'] ?? null,
                'beneficiary_name' => $consume['beneficiary_name'] ?? null,
                'procedure_type' => $consume['procedure_type'] ?? null,
                'work_concentration' => $consume['work_concentration'] ?? null,
                'pv_no' => $consume['pv_no'] ?? null,
                'workers_names' => $consume['workers_names'] ?? null,
            ]);
        }
    }
}

if (!function_exists('stock_receipt_consumed_qty')) {
    /**
     * Cantitatea consumată (minus) de pe lot, excluzând mișcarea automată de anulare a recepției.
     */
    function stock_receipt_consumed_qty(PDO $pdo, int $receiptId): float
    {
        if ($receiptId <= 0 || !stock_table_exists($pdo, 'stock_movements')) {
            return 0.0;
        }
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(qty), 0)
            FROM stock_movements
            WHERE receipt_id = ?
              AND movement_type IN ('consume','adjust_minus','loss','expired')
              AND COALESCE(reference_type, '') <> 'stock_receipt_cancel'
        ");
        $stmt->execute([$receiptId]);
        return round((float)$stmt->fetchColumn(), 3);
    }
}

if (!function_exists('stock_get_receipt')) {
    function stock_get_receipt(PDO $pdo, int $id): ?array
    {
        if ($id <= 0 || !stock_table_exists($pdo, 'stock_receipts')) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM stock_receipts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('stock_update_receipt_metadata')) {
    /**
     * Actualizează metadata recepției - NU permite modificarea cantității sau produsului.
     * Pentru corecții cantitative se folosesc ajustări plus/minus în Mișcări stoc.
     */
    function stock_update_receipt_metadata(PDO $pdo, int $receiptId, array $data): void
    {
        $existing = stock_get_receipt($pdo, $receiptId);
        if (!$existing) {
            throw new RuntimeException('Recepția nu mai există.');
        }
        if (!empty($existing['cancelled_at'])) {
            throw new RuntimeException('Recepție anulată - nu mai poate fi editată.');
        }

        $product = stock_get_product($pdo, (int)$existing['product_id']);
        if (!$product) {
            throw new RuntimeException('Produsul asociat recepției nu mai există.');
        }

        $isBio = stock_is_biocide_group((string)$product['product_group']);

        $receptionDate = trim((string)($data['reception_date'] ?? ''));
        if ($receptionDate === '') {
            throw new RuntimeException('Data recepției este obligatorie.');
        }
        $documentNo = trim((string)($data['document_no'] ?? ''));
        if ($documentNo === '') {
            throw new RuntimeException('Numărul facturii / avizului este obligatoriu.');
        }

        $lot = $isBio ? trim((string)($data['lot'] ?? '')) : null;
        $expiresAt = $isBio ? (trim((string)($data['expires_at'] ?? '')) ?: null) : null;
        if ($isBio) {
            if ($lot === '' || $lot === null) {
                throw new RuntimeException('Lotul este obligatoriu pentru produsele biocide.');
            }
            if ($expiresAt === null) {
                throw new RuntimeException('Data expirării lotului este obligatorie pentru produsele biocide.');
            }
        }

        $stmt = $pdo->prepare("
            UPDATE stock_receipts
            SET reception_date = :reception_date,
                document_no = :document_no,
                supplier = :supplier,
                lot = :lot,
                expires_at = :expires_at,
                notes = :notes
            WHERE id = :id
        ");
        $stmt->execute([
            'reception_date' => $receptionDate,
            'document_no'    => $documentNo,
            'supplier'       => trim((string)($data['supplier'] ?? '')) ?: null,
            'lot'            => $lot ?: null,
            'expires_at'     => $expiresAt,
            'notes'          => trim((string)($data['notes'] ?? '')) ?: null,
            'id'             => $receiptId,
        ]);
    }
}

if (!function_exists('stock_cancel_receipt')) {
    /**
     * Anulează o recepție:
     * - Refuză anularea dacă lotul a fost deja consumat (PV, ajustări minus, pierderi, expirate).
     * - Generează automat o mișcare adjust_minus = qty recepție (pista de audit + corecție stoc).
     * - Marchează recepția cu cancelled_at / cancelled_by / cancel_reason.
     */
    function stock_cancel_receipt(PDO $pdo, int $receiptId, string $reason): void
    {
        $existing = stock_get_receipt($pdo, $receiptId);
        if (!$existing) {
            throw new RuntimeException('Recepția nu mai există.');
        }
        if (!empty($existing['cancelled_at'])) {
            throw new RuntimeException('Recepție deja anulată.');
        }

        $consumed = stock_receipt_consumed_qty($pdo, $receiptId);
        if ($consumed > 0.0001) {
            throw new RuntimeException('Nu poți anula recepția: din acest lot s-au scos deja ' . stock_fmt_qty($consumed) . ' unități (PV/ajustări/pierderi). Anulează întâi mișcările respective.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Introdu motivul anulării recepției.');
        }

        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            stock_insert_movement_dynamic($pdo, [
                'product_id'     => (int)$existing['product_id'],
                'receipt_id'     => $receiptId,
                'movement_type'  => 'adjust_minus',
                'qty'            => (float)$existing['qty'],
                'reference_type' => 'stock_receipt_cancel',
                'reference_id'   => $receiptId,
                'notes'          => 'Anulare recepție #' . $receiptId . ' (' . (string)$existing['document_no'] . '): ' . $reason,
            ]);

            $stmt = $pdo->prepare("
                UPDATE stock_receipts
                SET cancelled_at = NOW(),
                    cancelled_by = :cancelled_by,
                    cancel_reason = :reason
                WHERE id = :id
            ");
            $stmt->execute([
                'cancelled_by' => function_exists('current_user_id') ? current_user_id() : null,
                'reason'       => $reason,
                'id'           => $receiptId,
            ]);

            if ($startedTx) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
}

/*
 |----------------------------------------------------------------------
 | INVENTAR FIZIC
 |----------------------------------------------------------------------
 | Flux:
 | 1) stock_inventory_build_snapshot() - genereaza lista de itemi de
 |    numarat (loturi pentru biocide, produse pentru materiale).
 | 2) stock_inventory_create() - persista inventarul + liniile cu
 |    expected_qty curent si counted_qty NULL.
 | 3) Utilizatorul completeaza counted_qty si finalizeaza.
 | 4) stock_inventory_finalize() - calculeaza diferentele si genereaza
 |    automat ajustari plus/minus in stock_movements.
 */

if (!function_exists('stock_inventory_build_snapshot')) {
    /**
     * Returneaza lista de itemi de numarat pentru un inventar.
     * Pentru biocide: o linie pe (produs, lot activ cu stoc > 0 sau stoc minim > 0).
     * Pentru materiale: o linie pe produs.
     *
     * @param string $group  Daca e setat, filtreaza la o singura grupa.
     */
    function stock_inventory_build_snapshot(PDO $pdo, string $group = ''): array
    {
        $where = ['p.is_active = 1'];
        $params = [];
        if ($group !== '' && array_key_exists($group, stock_group_options())) {
            $where[] = 'p.product_group = ?';
            $params[] = $group;
        }
        $whereSql = implode(' AND ', $where);

        // Produse non-biocide: o linie pe produs.
        $sqlNonBio = "
            SELECT
                p.id AS product_id,
                p.name AS product_name,
                p.product_group,
                p.unit_consumption,
                NULL AS receipt_id,
                NULL AS lot,
                NULL AS expires_at,
                (
                    COALESCE((SELECT SUM(qty) FROM stock_receipts WHERE product_id = p.id AND cancelled_at IS NULL), 0)
                    + COALESCE((SELECT SUM(qty) FROM stock_movements WHERE product_id = p.id AND movement_type IN ('adjust_plus','return')), 0)
                    - COALESCE((SELECT SUM(qty) FROM stock_movements WHERE product_id = p.id AND movement_type IN ('consume','adjust_minus','loss','expired')), 0)
                ) AS expected_qty
            FROM stock_products p
            WHERE $whereSql
              AND p.product_group NOT IN ('dezinsectie','dezinfectie','deratizare')
            ORDER BY p.name ASC
        ";
        $stmt = $pdo->prepare($sqlNonBio);
        $stmt->execute($params);
        $nonBio = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Biocide: o linie pe (produs, lot activ).
        $sqlBio = "
            SELECT
                p.id AS product_id,
                p.name AS product_name,
                p.product_group,
                p.unit_consumption,
                r.id AS receipt_id,
                r.lot,
                r.expires_at,
                (
                    r.qty
                    + COALESCE((SELECT SUM(qty) FROM stock_movements m WHERE m.receipt_id = r.id AND m.movement_type IN ('adjust_plus','return')), 0)
                    - COALESCE((SELECT SUM(qty) FROM stock_movements m WHERE m.receipt_id = r.id AND m.movement_type IN ('consume','adjust_minus','loss','expired')), 0)
                ) AS expected_qty
            FROM stock_products p
            INNER JOIN stock_receipts r ON r.product_id = p.id AND r.cancelled_at IS NULL
            WHERE $whereSql
              AND p.product_group IN ('dezinsectie','dezinfectie','deratizare')
            ORDER BY p.name ASC, COALESCE(r.expires_at, '2999-12-31') ASC, r.id ASC
        ";
        $stmt = $pdo->prepare($sqlBio);
        $stmt->execute($params);
        $bio = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Filtru: nu includem loturi epuizate cu expected = 0 si fara mișcări
        // recente (n-ar avea sens să le numerăm). Păstrăm însă cele cu expected > 0
        // sau care apar in snapshot la cererea explicita.
        $bio = array_values(array_filter($bio, function ($row) {
            return (float)$row['expected_qty'] > -0.0001; // includem si 0 ca să poată detecta surplus
        }));

        return array_merge($nonBio, $bio);
    }
}

if (!function_exists('stock_inventory_create')) {
    /**
     * Creează un nou inventar în status 'draft' cu lista de linii precompletată.
     * Returnează inventory_id.
     */
    function stock_inventory_create(PDO $pdo, string $inventoryDate, string $group, string $notes): int
    {
        $inventoryDate = trim($inventoryDate) ?: date('Y-m-d');
        $group = trim($group);
        if ($group !== '' && !array_key_exists($group, stock_group_options())) {
            $group = '';
        }
        $snapshot = stock_inventory_build_snapshot($pdo, $group);
        if (!$snapshot) {
            throw new RuntimeException('Nu există produse active de inventariat.');
        }

        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO stock_inventories (inventory_date, product_group, notes, total_lines, created_by, created_at, status)
                VALUES (?, ?, ?, ?, ?, NOW(), 'draft')
            ");
            $stmt->execute([
                $inventoryDate,
                $group !== '' ? $group : null,
                trim($notes) ?: null,
                count($snapshot),
                function_exists('current_user_id') ? current_user_id() : null,
            ]);
            $inventoryId = (int)$pdo->lastInsertId();

            $insertLine = $pdo->prepare("
                INSERT INTO stock_inventory_lines
                    (inventory_id, product_id, receipt_id, expected_qty)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($snapshot as $row) {
                $insertLine->execute([
                    $inventoryId,
                    (int)$row['product_id'],
                    !empty($row['receipt_id']) ? (int)$row['receipt_id'] : null,
                    (float)$row['expected_qty'],
                ]);
            }

            if ($startedTx) { $pdo->commit(); }
            return $inventoryId;
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
}

if (!function_exists('stock_inventory_get')) {
    function stock_inventory_get(PDO $pdo, int $id): ?array
    {
        if ($id <= 0 || !stock_table_exists($pdo, 'stock_inventories')) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM stock_inventories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('stock_inventory_lines')) {
    function stock_inventory_lines(PDO $pdo, int $inventoryId): array
    {
        if ($inventoryId <= 0 || !stock_table_exists($pdo, 'stock_inventory_lines')) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT l.*, p.name AS product_name, p.product_group, p.unit_consumption,
                   r.lot, r.expires_at
            FROM stock_inventory_lines l
            INNER JOIN stock_products p ON p.id = l.product_id
            LEFT JOIN stock_receipts r ON r.id = l.receipt_id
            WHERE l.inventory_id = ?
            ORDER BY p.product_group ASC, p.name ASC, COALESCE(r.expires_at, '2999-12-31') ASC, l.id ASC
        ");
        $stmt->execute([$inventoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('stock_inventory_finalize')) {
    /**
     * Finalizează un inventar:
     * - Pentru fiecare linie cu counted_qty != NULL, calculează diferența și
     *   generează automat o mișcare adjust_plus sau adjust_minus.
     * - Mișcările sunt legate de inventar prin reference_type='inventory'.
     * - Actualizează contoarele pe inventar și îl marchează 'finalized'.
     *
     * @param array $countedMap  Map product_line_id => counted_qty
     */
    function stock_inventory_finalize(PDO $pdo, int $inventoryId, array $countedMap): void
    {
        $inventory = stock_inventory_get($pdo, $inventoryId);
        if (!$inventory) {
            throw new RuntimeException('Inventarul nu mai există.');
        }
        if ($inventory['status'] === 'finalized') {
            throw new RuntimeException('Inventarul a fost deja finalizat.');
        }
        $lines = stock_inventory_lines($pdo, $inventoryId);
        if (!$lines) {
            throw new RuntimeException('Inventarul nu are linii de numărat.');
        }

        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $linesWithDiff = 0;
            $positiveAdj = 0;
            $negativeAdj = 0;

            foreach ($lines as $line) {
                $lineId = (int)$line['id'];
                if (!array_key_exists($lineId, $countedMap) || $countedMap[$lineId] === null || $countedMap[$lineId] === '') {
                    // Linie nenumărată - sărim (nu generăm ajustare).
                    continue;
                }
                $counted = stock_decimal($countedMap[$lineId]);
                $expected = (float)$line['expected_qty'];
                $diff = round($counted - $expected, 3);

                $pdo->prepare("UPDATE stock_inventory_lines SET counted_qty = ?, difference = ? WHERE id = ?")
                    ->execute([$counted, $diff, $lineId]);

                if (abs($diff) < 0.0001) {
                    continue;
                }

                $linesWithDiff++;
                $movementType = $diff > 0 ? 'adjust_plus' : 'adjust_minus';
                $qty = abs($diff);

                // Pentru ajustare minus la non-biocide fără lot, verificăm că nu
                // scoatem mai mult decât există. La biocide cu receipt_id e ok
                // pentru că receipt_id specifică lotul.
                if ($movementType === 'adjust_minus' && empty($line['receipt_id'])) {
                    $current = stock_current_qty_for_product($pdo, (int)$line['product_id']);
                    if ($qty > $current + 0.0001) {
                        throw new RuntimeException(
                            'Inventar: pentru produsul "' . (string)$line['product_name'] .
                            '" nu pot scoate ' . stock_fmt_qty($qty) .
                            ' - stoc curent doar ' . stock_fmt_qty($current) . '.'
                        );
                    }
                }

                stock_insert_movement_dynamic($pdo, [
                    'product_id'     => (int)$line['product_id'],
                    'receipt_id'     => !empty($line['receipt_id']) ? (int)$line['receipt_id'] : null,
                    'movement_type'  => $movementType,
                    'qty'            => $qty,
                    'reference_type' => 'inventory',
                    'reference_id'   => $inventoryId,
                    'notes'          => 'Ajustare automata inventar #' . $inventoryId .
                                        ' (asteptat ' . stock_fmt_qty($expected) .
                                        ', numarat ' . stock_fmt_qty($counted) . ')',
                ]);

                $movementId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE stock_inventory_lines SET movement_id = ? WHERE id = ?")
                    ->execute([$movementId, $lineId]);

                if ($diff > 0) { $positiveAdj++; } else { $negativeAdj++; }
            }

            $pdo->prepare("
                UPDATE stock_inventories
                SET status = 'finalized',
                    finalized_at = NOW(),
                    finalized_by = ?,
                    lines_with_diff = ?,
                    positive_adjustments = ?,
                    negative_adjustments = ?
                WHERE id = ?
            ")->execute([
                function_exists('current_user_id') ? current_user_id() : null,
                $linesWithDiff,
                $positiveAdj,
                $negativeAdj,
                $inventoryId,
            ]);

            if ($startedTx) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
}

if (!function_exists('stock_pick_fifo_lot')) {
    /**
     * Întoarce ID-ul recepției (lot) cu cea mai apropiată expirare și stoc > 0
     * pentru un produs dat. NULL dacă nu există stoc disponibil.
     */
    function stock_pick_fifo_lot(PDO $pdo, int $productId, float $neededQty = 0.001): ?int
    {
        if ($productId <= 0 || !stock_table_exists($pdo, 'stock_receipts')) {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT id FROM stock_receipts
            WHERE product_id = ?
              AND cancelled_at IS NULL
            ORDER BY COALESCE(expires_at, '2999-12-31') ASC, reception_date ASC, id ASC
        ");
        $stmt->execute([$productId]);
        $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($candidates as $receiptId) {
            $available = stock_available_qty_for_receipt($pdo, (int)$receiptId);
            if ($available >= $neededQty - 0.0001) {
                return (int)$receiptId;
            }
        }
        // Nu există un lot care să acopere integral cererea - returnez primul cu stoc > 0
        foreach ($candidates as $receiptId) {
            $available = stock_available_qty_for_receipt($pdo, (int)$receiptId);
            if ($available > 0.0001) {
                return (int)$receiptId;
            }
        }
        return null;
    }
}

if (!function_exists('stock_deferred_pvs_list')) {
    /**
     * Lista PV-urilor emise cu flag-ul stock_consumption_deferred = '1',
     * neînchise încă (nu au consumuri).
     */
    function stock_deferred_pvs_list(PDO $pdo): array
    {
        if (!stock_table_exists($pdo, 'documents')) {
            return [];
        }
        $sql = "
            SELECT d.id, d.document_number, d.document_date, d.document_time,
                   d.client_name_snapshot, d.payload_json
            FROM documents d
            WHERE d.document_type = 'proces_verbal'
              AND d.status = 'issued'
              AND d.payload_json LIKE '%\"stock_consumption_deferred\":\"1\"%'
            ORDER BY d.document_date DESC, d.id DESC
            LIMIT 200
        ";
        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        // Adaug numarul de materiale + numarul de PV-uri care au deja consumuri
        // (consumate intre timp - le filtram din lista)
        $result = [];
        foreach ($rows as $row) {
            $documentId = (int)$row['id'];
            if (stock_count_document_consumes($pdo, $documentId) > 0) {
                continue;
            }
            $matCountStmt = $pdo->prepare("
                SELECT COUNT(*) FROM document_materials
                WHERE document_id = ? AND TRIM(COALESCE(material_name, '')) <> ''
            ");
            $matCountStmt->execute([$documentId]);
            $row['materials_count'] = (int)$matCountStmt->fetchColumn();
            $result[] = $row;
        }
        return $result;
    }
}

if (!function_exists('stock_deferred_pv_materials')) {
    /**
     * Lista materialelor unui PV deferred, cu produs rezolvat și sugestie FIFO de lot.
     */
    function stock_deferred_pv_materials(PDO $pdo, int $documentId): array
    {
        if ($documentId <= 0 || !stock_table_exists($pdo, 'document_materials')) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT m.*, p.name AS product_name, p.product_group, p.unit_consumption,
                   p.package_qty
            FROM document_materials m
            LEFT JOIN stock_products p ON p.id = m.stock_product_id
            WHERE m.document_id = ?
              AND TRIM(COALESCE(m.material_name, '')) <> ''
            ORDER BY m.sort_order ASC, m.id ASC
        ");
        $stmt->execute([$documentId]);
        $materials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Pentru fiecare material adaug sugestia FIFO + disponibil pe acel lot
        foreach ($materials as &$material) {
            $productId = (int)($material['stock_product_id'] ?? 0);
            if ($productId <= 0 && !empty($material['material_name'])) {
                // Încearcă rezolvare după nume
                [$resolvedPid, $resolvedRid] = stock_resolve_document_material_stock($pdo, $material, 0);
                $productId = $resolvedPid > 0 ? $resolvedPid : 0;
                $material['stock_product_id'] = $productId ?: null;
            }
            $fifoLot = $productId > 0 ? stock_pick_fifo_lot($pdo, $productId) : null;
            $material['fifo_lot_id'] = $fifoLot;
            $material['fifo_lot_available'] = $fifoLot ? stock_available_qty_for_receipt($pdo, $fifoLot) : 0;
            if ($fifoLot) {
                $lotStmt = $pdo->prepare("SELECT lot, expires_at FROM stock_receipts WHERE id = ? LIMIT 1");
                $lotStmt->execute([$fifoLot]);
                $lotRow = $lotStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $material['fifo_lot_label'] = (string)($lotRow['lot'] ?? '-');
                $material['fifo_lot_expires'] = (string)($lotRow['expires_at'] ?? '');
            } else {
                $material['fifo_lot_label'] = '';
                $material['fifo_lot_expires'] = '';
            }
        }
        unset($material);
        return $materials;
    }
}

if (!function_exists('stock_finalize_deferred_pv')) {
    /**
     * Finalizează consumul unui PV emis în alb:
     * - Pentru fiecare material cu cantitate > 0 în $qtyMap, actualizează
     *   document_materials.quantity și asignează FIFO lot.
     * - Șterge flag-ul stock_consumption_deferred din payload_json.
     * - Apelează stock_consume_document_materials() care creează mișcările.
     *
     * @param array $qtyMap  Map document_material_id => cantitate reală
     */
    function stock_finalize_deferred_pv(PDO $pdo, int $documentId, array $qtyMap): void
    {
        if ($documentId <= 0) {
            throw new RuntimeException('Document invalid.');
        }
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? LIMIT 1");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            throw new RuntimeException('PV-ul nu mai există.');
        }
        if (($document['document_type'] ?? '') !== 'proces_verbal' || ($document['status'] ?? '') !== 'issued') {
            throw new RuntimeException('Doar PV-urile emise pot fi finalizate.');
        }
        $payload = [];
        if (!empty($document['payload_json'])) {
            $decoded = json_decode((string)$document['payload_json'], true);
            if (is_array($decoded)) { $payload = $decoded; }
        }
        if (($payload['stock_consumption_deferred'] ?? '') !== '1') {
            throw new RuntimeException('Acest PV nu este emis în alb sau a fost deja finalizat.');
        }

        $materials = stock_deferred_pv_materials($pdo, $documentId);
        if (!$materials) {
            throw new RuntimeException('PV-ul nu are materiale declarate.');
        }

        // Verific ca cel puțin un material să aibă cantitate validă
        $anyQty = false;
        foreach ($materials as $material) {
            $mid = (int)$material['id'];
            $qty = stock_decimal($qtyMap[$mid] ?? 0);
            if ($qty > 0) { $anyQty = true; break; }
        }
        if (!$anyQty) {
            throw new RuntimeException('Introdu cel puțin o cantitate consumată.');
        }

        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            foreach ($materials as $material) {
                $mid = (int)$material['id'];
                $qty = stock_decimal($qtyMap[$mid] ?? 0);
                if ($qty <= 0) {
                    // Material fără cantitate - îl golesc ca să fie sărit de
                    // consume function (validare cere qty > 0; setând qty NULL
                    // dar... preferinm să-l ștergem din document_materials)
                    $pdo->prepare("DELETE FROM document_materials WHERE id = ?")
                        ->execute([$mid]);
                    continue;
                }
                $productId = (int)($material['stock_product_id'] ?? 0);
                if ($productId <= 0) {
                    throw new RuntimeException('Produsul "' . ($material['material_name'] ?? '?') . '" nu a putut fi rezolvat din nomenclator.');
                }
                $fifoLot = stock_pick_fifo_lot($pdo, $productId, $qty);
                if (!$fifoLot) {
                    throw new RuntimeException('Nu există stoc disponibil pentru "' . ($material['material_name'] ?? '?') . '". Adaugă întâi o intrare în stoc.');
                }
                $pdo->prepare("
                    UPDATE document_materials
                    SET quantity = ?,
                        stock_product_id = ?,
                        stock_receipt_id = ?
                    WHERE id = ?
                ")->execute([$qty, $productId, $fifoLot, $mid]);
            }

            // Sterg flag-ul deferred din payload
            $payload['stock_consumption_deferred'] = '0';
            $payload['stock_consumption_finalized_at'] = date('Y-m-d H:i:s');
            $payload['stock_consumption_finalized_by'] = function_exists('current_user_id') ? current_user_id() : null;
            $pdo->prepare("UPDATE documents SET payload_json = ? WHERE id = ?")
                ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $documentId]);

            // Generez consumurile (acum că flag-ul nu mai blochează)
            stock_consume_document_materials($pdo, $documentId);

            if ($startedTx) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
}

if (!function_exists('stock_inventory_list')) {
    function stock_inventory_list(PDO $pdo, int $limit = 50): array
    {
        if (!stock_table_exists($pdo, 'stock_inventories')) {
            return [];
        }
        $sql = "SELECT i.*, u.name AS created_by_name
                FROM stock_inventories i
                LEFT JOIN users u ON u.id = i.created_by
                ORDER BY i.created_at DESC
                LIMIT " . (int)$limit;
        try {
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // tabela users s-ar putea sa nu existe in unele instalari
            return $pdo->query("SELECT i.*, NULL AS created_by_name FROM stock_inventories i ORDER BY i.created_at DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
}

if (!function_exists('stock_validate_product_data')) {
    function stock_validate_product_data(array $data): void
    {
        if (trim((string)($data['name'] ?? '')) === '') {
            throw new RuntimeException('Denumirea produsului este obligatorie.');
        }
        $group = (string)($data['product_group'] ?? '');
        if (!array_key_exists($group, stock_group_options())) {
            throw new RuntimeException('Grupa produsului este invalida.');
        }
        $unit = (string)($data['unit_consumption'] ?? '');
        if (!array_key_exists($unit, stock_unit_options())) {
            throw new RuntimeException('Unitatea de consum este invalida.');
        }
        if ((float)($data['package_qty'] ?? 0) <= 0) {
            throw new RuntimeException('Cantitatea per ambalaj trebuie să fie mai mare decât zero.');
        }
        if ((float)($data['min_qty'] ?? 0) < 0) {
            throw new RuntimeException('Stocul minim nu poate fi negativ.');
        }
        if (stock_is_biocide_group($group)) {
            if (trim((string)($data['aviz_no'] ?? '')) === '') {
                throw new RuntimeException('Numărul de aviz este obligatoriu pentru Dezinsecție / Dezinfecție / Deratizare.');
            }
            if (trim((string)($data['aviz_valid_until'] ?? '')) === '') {
                throw new RuntimeException('Valabilitatea avizului este obligatorie pentru produsul biocid.');
            }
        }
    }
}

if (!function_exists('stock_validate_receipt_data')) {
    function stock_validate_receipt_data(PDO $pdo, array $data): array
    {
        $productId = (int)($data['product_id'] ?? 0);
        $product = stock_get_product($pdo, $productId);
        if (!$product) {
            throw new RuntimeException('Produsul selectat nu există.');
        }
        if (trim((string)($data['reception_date'] ?? '')) === '') {
            throw new RuntimeException('Data recepției este obligatorie.');
        }
        if (trim((string)($data['document_no'] ?? '')) === '') {
            throw new RuntimeException('Numărul facturii / avizului este obligatoriu.');
        }
        if ((float)($data['qty'] ?? 0) <= 0) {
            throw new RuntimeException('Cantitatea intrată trebuie să fie mai mare decât zero.');
        }
        if (stock_is_biocide_group((string)($product['product_group'] ?? ''))) {
            if (trim((string)($data['lot'] ?? '')) === '') {
                throw new RuntimeException('Lotul este obligatoriu pentru produsele biocide.');
            }
            if (trim((string)($data['expires_at'] ?? '')) === '') {
                throw new RuntimeException('Data expirării lotului este obligatorie pentru produsele biocide.');
            }
        }
        return $product;
    }
}

if (!function_exists('stock_validate_outgoing_data')) {
    /**
     * Validează o mișcare manuală de stoc (ieșire sau ajustare plus).
     * Tipuri permise:
     *   - loss, expired, adjust_minus → scad stocul, verifică plafon
     *   - adjust_plus → adaugă în stoc, NU verifică plafon
     */
    function stock_validate_outgoing_data(PDO $pdo, array $data): array
    {
        $productId = (int)($data['product_id'] ?? 0);
        $receiptId = (int)($data['receipt_id'] ?? 0);
        $product = stock_get_product($pdo, $productId);
        if (!$product) {
            throw new RuntimeException('Produsul selectat nu există.');
        }
        $movementType = (string)($data['movement_type'] ?? '');
        $allowed = ['loss', 'expired', 'adjust_minus', 'adjust_plus'];
        if (!in_array($movementType, $allowed, true)) {
            throw new RuntimeException('Tipul mișcării din stoc este invalid.');
        }
        $qty = (float)($data['qty'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('Cantitatea trebuie să fie mai mare decât zero.');
        }
        $isPlus = ($movementType === 'adjust_plus');
        if (!$isPlus) {
            $currentQty = stock_current_qty_for_product($pdo, $productId);
            if ($qty > $currentQty) {
                throw new RuntimeException('Cantitatea depășește stocul disponibil. Disponibil: ' . stock_unit_display($currentQty, (string)$product['unit_consumption']));
            }
        }
        if (stock_is_biocide_group((string)($product['product_group'] ?? '')) && $receiptId <= 0) {
            throw new RuntimeException('Selectează lotul pentru produsul biocid înainte de ' . ($isPlus ? 'ajustarea plus.' : 'ieșirea din stoc.'));
        }
        if ($receiptId > 0) {
            $stmt = $pdo->prepare('SELECT id, cancelled_at FROM stock_receipts WHERE id = ? AND product_id = ? LIMIT 1');
            $stmt->execute([$receiptId, $productId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Lotul selectat nu aparține produsului ales.');
            }
            if (!empty($row['cancelled_at'])) {
                throw new RuntimeException('Lotul selectat aparține unei recepții anulate.');
            }
            if (!$isPlus) {
                $available = stock_available_qty_for_receipt($pdo, $receiptId);
                if ($qty > $available + 0.0001) {
                    throw new RuntimeException('Cantitatea depășește stocul disponibil pe lot. Disponibil: ' . stock_unit_display($available, (string)$product['unit_consumption']));
                }
            }
        }
        return $product;
    }
}

/* Extra helpers V5 - fișa magazie interval + registru evidență lucrări */
if (!function_exists('stock_date_or_default')) {
    function stock_date_or_default($value, string $default): string
    {
        $value = trim((string)$value);
        if ($value === '') { return $default; }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : $default;
    }
}

if (!function_exists('stock_interval_bounds')) {
    function stock_interval_bounds(string $dateFrom, string $dateTo): array
    {
        $from = stock_date_or_default($dateFrom, date('Y-m-01'));
        $to = stock_date_or_default($dateTo, date('Y-m-t'));
        if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }
        return [$from, $to, $from . ' 00:00:00', $to . ' 23:59:59'];
    }
}

if (!function_exists('stock_stock_summary_interval')) {
    function stock_stock_summary_interval(PDO $pdo, string $dateFrom, string $dateTo, int $productId = 0, string $group = ''): array
    {
        [$from, $to, $fromDt, $toDt] = stock_interval_bounds($dateFrom, $dateTo);
        $where = ['1=1'];
        $params = [];
        if ($productId > 0) { $where[] = 'p.id = ?'; $params[] = $productId; }
        if ($group !== '' && array_key_exists($group, stock_group_options())) { $where[] = 'p.product_group = ?'; $params[] = $group; }
        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT p.id, p.name, p.product_group, p.unit_consumption, p.min_qty,
                COALESCE(ri.qty,0) + COALESCE(mi.plus_qty,0) - COALESCE(mi.minus_qty,0) AS initial_qty,
                COALESCE(rp.qty,0) + COALESCE(mp.plus_qty,0) AS in_qty,
                COALESCE(mp.minus_qty,0) AS out_qty,
                (COALESCE(ri.qty,0) + COALESCE(mi.plus_qty,0) - COALESCE(mi.minus_qty,0) + COALESCE(rp.qty,0) + COALESCE(mp.plus_qty,0) - COALESCE(mp.minus_qty,0)) AS final_qty
            FROM stock_products p
            LEFT JOIN (
                SELECT product_id, SUM(qty) qty FROM stock_receipts WHERE reception_date < ? GROUP BY product_id
            ) ri ON ri.product_id = p.id
            LEFT JOIN (
                SELECT product_id,
                    SUM(CASE WHEN movement_type IN ('adjust_plus','return') THEN qty ELSE 0 END) plus_qty,
                    SUM(CASE WHEN movement_type IN ('consume','adjust_minus','loss','expired') THEN qty ELSE 0 END) minus_qty
                FROM stock_movements WHERE created_at < ? GROUP BY product_id
            ) mi ON mi.product_id = p.id
            LEFT JOIN (
                SELECT product_id, SUM(qty) qty FROM stock_receipts WHERE reception_date >= ? AND reception_date <= ? GROUP BY product_id
            ) rp ON rp.product_id = p.id
            LEFT JOIN (
                SELECT product_id,
                    SUM(CASE WHEN movement_type IN ('adjust_plus','return') THEN qty ELSE 0 END) plus_qty,
                    SUM(CASE WHEN movement_type IN ('consume','adjust_minus','loss','expired') THEN qty ELSE 0 END) minus_qty
                FROM stock_movements WHERE created_at >= ? AND created_at <= ? GROUP BY product_id
            ) mp ON mp.product_id = p.id
            WHERE $whereSql
            ORDER BY p.product_group ASC, p.name ASC
        ";
        $allParams = array_merge([$from, $fromDt, $from, $to, $fromDt, $toDt], $params);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($allParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('stock_registry_rows')) {
    function stock_registry_rows(PDO $pdo, string $dateFrom, string $dateTo): array
    {
        [$from, $to, $fromDt, $toDt] = stock_interval_bounds($dateFrom, $dateTo);
        $hasProcedureAt = stock_column_exists($pdo, 'stock_movements', 'procedure_at');
        $dateExpr = $hasProcedureAt ? 'COALESCE(m.procedure_at, m.created_at)' : 'm.created_at';
        $beneficiaryExpr = stock_column_exists($pdo, 'stock_movements', 'beneficiary_name') ? 'm.beneficiary_name' : "NULL";
        $procedureTypeExpr = stock_column_exists($pdo, 'stock_movements', 'procedure_type') ? 'm.procedure_type' : 'p.product_group';
        $concentrationExpr = stock_column_exists($pdo, 'stock_movements', 'work_concentration') ? 'm.work_concentration' : "NULL";
        $pvNoExpr = stock_column_exists($pdo, 'stock_movements', 'pv_no') ? 'm.pv_no' : "CONCAT(COALESCE(m.reference_type,''), IF(m.reference_id IS NULL OR m.reference_id=0, '', CONCAT(' #', m.reference_id)))";
        $workersExpr = stock_column_exists($pdo, 'stock_movements', 'workers_names') ? 'm.workers_names' : "NULL";
        $sql = "
            SELECT
                $dateExpr AS procedure_date,
                $beneficiaryExpr AS beneficiary_name,
                $procedureTypeExpr AS procedure_type,
                p.name AS product_name,
                p.aviz_no AS aviz_no,
                r.lot AS lot,
                m.qty AS qty,
                p.unit_consumption AS unit_consumption,
                $concentrationExpr AS work_concentration,
                $pvNoExpr AS pv_no,
                $workersExpr AS workers_names
            FROM stock_movements m
            INNER JOIN stock_products p ON p.id = m.product_id
            LEFT JOIN stock_receipts r ON r.id = m.receipt_id
            WHERE m.movement_type = 'consume'
              AND p.product_group IN ('dezinsectie','dezinfectie','deratizare')
              AND $dateExpr >= ? AND $dateExpr <= ?
            ORDER BY $dateExpr ASC, m.id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fromDt, $toDt]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('stock_pagination_state')) {
    /**
     * Calculează starea paginării pe baza GET-ului.
     * Returnează: [page, perPage, offset, totalPages]
     */
    function stock_pagination_state(int $totalRows, int $perPage = 50, ?int $requestedPage = null): array
    {
        if ($perPage < 1) { $perPage = 50; }
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $page = $requestedPage !== null ? $requestedPage : (int)($_GET['page'] ?? 1);
        if ($page < 1) { $page = 1; }
        if ($page > $totalPages) { $page = $totalPages; }
        $offset = ($page - 1) * $perPage;
        return [$page, $perPage, $offset, $totalPages];
    }
}

if (!function_exists('stock_render_pagination')) {
    /**
     * Afișează un nav simplu de paginare (prev / 1 .. n / next) care păstrează
     * toate filtrele curente din query string.
     */
    function stock_render_pagination(int $page, int $totalPages, int $totalRows): void
    {
        if ($totalPages <= 1) {
            echo '<div style="text-align:right;color:var(--muted);font-size:12.5px;margin-top:8px;">' . (int)$totalRows . ' înregistrări</div>';
            return;
        }
        $params = $_GET;
        $build = function(int $p) use ($params) {
            $params['page'] = $p;
            return '?' . http_build_query($params);
        };
        echo '<div class="stock-pagination" style="display:flex;gap:6px;justify-content:center;align-items:center;margin-top:12px;flex-wrap:wrap;">';
        echo '<span style="color:var(--muted);font-size:12.5px;margin-right:auto;">' . (int)$totalRows . ' înregistrări · pagina ' . (int)$page . '/' . (int)$totalPages . '</span>';
        if ($page > 1) {
            echo '<a class="btn" href="' . htmlspecialchars($build($page - 1), ENT_QUOTES, 'UTF-8') . '">‹ Anterior</a>';
        }
        // Afișare paginare compactă cu ferestre de pagini în jurul celei curente
        $window = 2;
        $shown = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === 1 || $i === $totalPages || abs($i - $page) <= $window) {
                $shown[] = $i;
            }
        }
        $prev = 0;
        foreach ($shown as $i) {
            if ($prev !== 0 && $i > $prev + 1) {
                echo '<span style="color:var(--muted);padding:0 4px;">…</span>';
            }
            $cls = $i === $page ? 'btn accent' : 'btn';
            echo '<a class="' . $cls . '" href="' . htmlspecialchars($build($i), ENT_QUOTES, 'UTF-8') . '">' . (int)$i . '</a>';
            $prev = $i;
        }
        if ($page < $totalPages) {
            echo '<a class="btn" href="' . htmlspecialchars($build($page + 1), ENT_QUOTES, 'UTF-8') . '">Următor ›</a>';
        }
        echo '</div>';
    }
}

if (!function_exists('stock_render_pdf_or_html')) {
    function stock_render_pdf_or_html(string $html, string $filename): void
    {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) { require_once $autoload; }
        if (class_exists('\\Mpdf\\Mpdf')) {
            $mpdfClass = '\\Mpdf\\Mpdf';
            $mpdf = new $mpdfClass(['format' => 'A4-L', 'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 8, 'margin_bottom' => 8]);
            $mpdf->WriteHTML($html);
            $mpdf->Output($filename, 'I');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
}
