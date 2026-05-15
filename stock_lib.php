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
            'dezinsectie' => 'Dezinsectie',
            'dezinfectie' => 'Dezinfectie',
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
            throw new RuntimeException('Tabelul stock_movements nu exista. Ruleaza stock_install.php.');
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

if (!function_exists('stock_consume_document_materials')) {
    function stock_consume_document_materials(PDO $pdo, int $documentId): void
    {
        if ($documentId <= 0 || !stock_table_exists($pdo, 'stock_products') || !stock_table_exists($pdo, 'stock_receipts') || !stock_table_exists($pdo, 'stock_movements')) {
            return;
        }
        if (!stock_table_exists($pdo, 'documents') || !stock_table_exists($pdo, 'document_materials')) {
            return;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'consume' AND reference_type = 'document_pv' AND reference_id = ?");
        $stmt->execute([$documentId]);
        if ((int)$stmt->fetchColumn() > 0) {
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

        $stmt = $pdo->prepare("SELECT * FROM document_materials WHERE document_id = ? AND stock_product_id IS NOT NULL AND stock_product_id > 0 AND quantity > 0 ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$documentId]);
        $materials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$materials) {
            return;
        }

        foreach ($materials as $material) {
            $productId = (int)($material['stock_product_id'] ?? 0);
            $receiptId = (int)($material['stock_receipt_id'] ?? 0);
            $qty = stock_decimal($material['quantity'] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            if ($receiptId <= 0) {
                throw new RuntimeException('Selecteaza lotul pentru produsul "' . (string)($material['material_name'] ?? 'produs') . '" inainte de emiterea PV.');
            }

            $product = stock_get_product($pdo, $productId);
            if (!$product) {
                throw new RuntimeException('Produsul din PV nu mai exista in gestiune: ' . (string)($material['material_name'] ?? $productId));
            }

            $stmtReceipt = $pdo->prepare('SELECT * FROM stock_receipts WHERE id = ? AND product_id = ? LIMIT 1 FOR UPDATE');
            $stmtReceipt->execute([$receiptId, $productId]);
            $receipt = $stmtReceipt->fetch(PDO::FETCH_ASSOC);
            if (!$receipt) {
                throw new RuntimeException('Lotul selectat nu apartine produsului "' . (string)($product['name'] ?? $material['material_name']) . '".');
            }

            $available = stock_available_qty_for_receipt($pdo, $receiptId);
            if ($qty > $available + 0.0001) {
                throw new RuntimeException('Stoc insuficient pe lot pentru "' . (string)($product['name'] ?? $material['material_name']) . '". Disponibil: ' . stock_unit_display($available, (string)($product['unit_consumption'] ?? $material['unit'] ?? 'buc')) . '. Consum cerut: ' . stock_unit_display($qty, (string)($product['unit_consumption'] ?? $material['unit'] ?? 'buc')) . '.');
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
            throw new RuntimeException('Cantitatea per ambalaj trebuie sa fie mai mare decat zero.');
        }
        if ((float)($data['min_qty'] ?? 0) < 0) {
            throw new RuntimeException('Stocul minim nu poate fi negativ.');
        }
        if (stock_is_biocide_group($group)) {
            if (trim((string)($data['aviz_no'] ?? '')) === '') {
                throw new RuntimeException('Numarul de aviz este obligatoriu pentru Dezinsectie / Dezinfectie / Deratizare.');
            }
            if (trim((string)($data['aviz_valid_until'] ?? '')) === '') {
                throw new RuntimeException('Valabilitatea avizului este obligatorie pentru produsul biocid.');
            }
            if (trim((string)($data['safety_measures'] ?? '')) === '') {
                throw new RuntimeException('Masurile de siguranta pentru PV sunt obligatorii pentru produsul biocid.');
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
            throw new RuntimeException('Produsul selectat nu exista.');
        }
        if (trim((string)($data['reception_date'] ?? '')) === '') {
            throw new RuntimeException('Data receptiei este obligatorie.');
        }
        if (trim((string)($data['document_no'] ?? '')) === '') {
            throw new RuntimeException('Numarul facturii / avizului este obligatoriu.');
        }
        if ((float)($data['qty'] ?? 0) <= 0) {
            throw new RuntimeException('Cantitatea intrata trebuie sa fie mai mare decat zero.');
        }
        if (stock_is_biocide_group((string)($product['product_group'] ?? ''))) {
            if (trim((string)($data['lot'] ?? '')) === '') {
                throw new RuntimeException('Lotul este obligatoriu pentru produsele biocide.');
            }
            if (trim((string)($data['expires_at'] ?? '')) === '') {
                throw new RuntimeException('Data expirarii lotului este obligatorie pentru produsele biocide.');
            }
        }
        return $product;
    }
}

if (!function_exists('stock_validate_outgoing_data')) {
    function stock_validate_outgoing_data(PDO $pdo, array $data): array
    {
        $productId = (int)($data['product_id'] ?? 0);
        $product = stock_get_product($pdo, $productId);
        if (!$product) {
            throw new RuntimeException('Produsul selectat nu exista.');
        }
        $allowed = ['loss', 'expired', 'adjust_minus'];
        if (!in_array((string)($data['movement_type'] ?? ''), $allowed, true)) {
            throw new RuntimeException('Tipul iesirii din stoc este invalid.');
        }
        $qty = (float)($data['qty'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('Cantitatea scoasa din stoc trebuie sa fie mai mare decat zero.');
        }
        $currentQty = stock_current_qty_for_product($pdo, $productId);
        if ($qty > $currentQty) {
            throw new RuntimeException('Cantitatea depaseste stocul disponibil. Disponibil: ' . stock_unit_display($currentQty, (string)$product['unit_consumption']));
        }
        return $product;
    }
}

/* Extra helpers V5 - fisa magazie interval + registru evidenta lucrari */
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
