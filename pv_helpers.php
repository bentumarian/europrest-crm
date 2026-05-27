<?php

/*
|--------------------------------------------------------------------------
| pv_helpers.php
|--------------------------------------------------------------------------
| Helper-i pentru pagina Procese Verbale (PV).
| 36 funcții grupate pe categorii:
|   - String/format (pz_pv_h, pz_pv_str, pz_pv_decimal, pz_pv_date_ro,
|     pz_pv_date_for_storage, pz_pv_time_ro)
|   - Status + URL (pz_pv_status_label, pz_pv_status_class, pz_pv_current_url)
|   - Fetch-uri DB (pz_pv_fetch_clients, pz_pv_fetch_locations,
|     pz_pv_fetch_services, pz_pv_fetch_templates, pz_pv_fetch_contracts,
|     pz_pv_fetch_products, pz_pv_fetch_receipts, pz_pv_fetch_appointment(s))
|   - Adresă + client (pz_pv_client_address)
|   - Contracte (pz_pv_contracts_by_id, pz_pv_find_default_contract_id,
|     pz_pv_resolve_basis_from_post)
|   - Servicii (pz_pv_service_*, pz_pv_normalize_selected_services,
|     pz_pv_selected_services_from_items, pz_pv_build_service_items_from_selected)
|   - Build payload din POST (pz_pv_build_items_from_post,
|     pz_pv_build_materials_from_post, pz_pv_build_payload_from_post)
|   - Misc (pz_pv_surface_from_appointment, pz_pv_locations_by_id,
|     pz_pv_products_by_id, pz_pv_receipts_by_id, pz_pv_redirect_with_error)
|
| Dependențe: PDO global ($pdo), config.php, document_core/tokens (pentru
| funcțiile mai complexe). Includ doar funcțiile - nu rulează queries la load.
|--------------------------------------------------------------------------
*/

function pz_pv_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pz_pv_str($value, int $max = 0): string {
    $value = trim((string)$value);
    if ($max > 0) {
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $max, 'UTF-8');
        } else {
            $value = substr($value, 0, $max);
        }
    }
    return $value;
}

function pz_pv_decimal($value, float $default = 0.0): float {
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_string($value)) {
        $value = str_replace([' ', ','], ['', '.'], $value);
    }
    return is_numeric($value) ? (float)$value : $default;
}

function pz_pv_date_ro(?string $date): string {
    // Wrapper subțire peste pz_date() (definit în app_helpers.php).
    // Păstrat ca alias pentru a nu sparge apelurile existente din module.
    return pz_date($date);
}

function pz_pv_date_for_storage($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : '';
}

function pz_pv_time_ro(?string $time): string {
    if (!$time) {
        return '-';
    }
    $ts = strtotime($time);
    return $ts ? date('H:i', $ts) : substr((string)$time, 0, 5);
}

function pz_pv_status_label(string $status): string {
    return [
        'draft' => 'Draft',
        'issued' => 'Emis',
        'cancelled' => 'Anulat',
    ][$status] ?? $status;
}

function pz_pv_status_class(string $status): string {
    return [
        'draft' => 'draft',
        'issued' => 'issued',
        'cancelled' => 'cancelled',
    ][$status] ?? 'draft';
}

function pz_pv_current_url(array $extra = []): string {
    $params = $_GET;
    foreach ($extra as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'service-reports' . ($params ? '?' . http_build_query($params) : '');
}

function pz_pv_fetch_clients(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'clients')) {
        return [];
    }

    $stmt = $pdo->query("\n        SELECT *\n        FROM clients\n        WHERE COALESCE(active, 1) = 1\n        ORDER BY name ASC\n        LIMIT 1500\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_pv_client_address(array $client): string {
    $line = trim((string)($client['billing_address_line'] ?? ''));
    $county = trim((string)($client['billing_county'] ?? ''));
    $city = trim((string)($client['billing_city'] ?? ''));
    $country = trim((string)($client['billing_country'] ?? ''));
    $postal = trim((string)($client['billing_postal_code'] ?? ''));
    $address = trim(implode(', ', array_filter([$line, $county, $city, $country], static fn($value) => $value !== '')));
    if ($postal !== '') {
        $address .= ($address !== '' ? ', ' : '') . 'CP ' . $postal;
    }
    if ($address !== '') {
        return $address;
    }

    return trim((string)(($client['registered_address'] ?? '') ?: ($client['address'] ?? '')));
}

function pz_pv_fetch_locations(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'client_locations')) {
        return [];
    }

    $stmt = $pdo->query("\n        SELECT id, client_id, location_name, address, contact_person, phone,\n               surface_value, surface_unit, active, sort_order\n        FROM client_locations\n        WHERE COALESCE(active, 1) = 1\n        ORDER BY client_id ASC, sort_order ASC, location_name ASC\n        LIMIT 5000\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_pv_fetch_services(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'services')) {
        return [];
    }

    $stmt = $pdo->query("\n        SELECT id, name, description, active, sort_order\n        FROM services\n        WHERE COALESCE(active, 1) = 1\n        ORDER BY sort_order ASC, name ASC\n        LIMIT 500\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_pv_fetch_templates(PDO $pdo): array {
    $stmt = $pdo->prepare("\n        SELECT id, name, is_default\n        FROM document_templates\n        WHERE document_type = 'proces_verbal'\n          AND is_active = 1\n        ORDER BY is_default DESC, name ASC\n    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_pv_fetch_contracts(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'documents')) {
        return [];
    }

    try {
        $stmt = $pdo->query("\n            SELECT id, client_id, client_location_id, document_number, document_date, title, status, payload_json\n            FROM documents\n            WHERE document_type = 'contract'\n              AND status IN ('issued', 'sent', 'emitted', 'finalized')\n            ORDER BY document_date DESC, id DESC\n            LIMIT 2500\n        ");
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$contracts) {
            return [];
        }

        $contractsById = [];
        $ids = [];
        foreach ($contracts as $idx => $contract) {
            $id = (int)($contract['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $ids[] = $id;
            $contracts[$idx]['location_ids'] = [];
            if (!empty($contract['client_location_id'])) {
                $contracts[$idx]['location_ids'][] = (int)$contract['client_location_id'];
            }
            $contractsById[$id] = $idx;
        }

        if ($ids && pzdoc_table_exists($pdo, 'document_items')) {
            $in = implode(',', array_map('intval', $ids));
            $itemStmt = $pdo->query("\n                SELECT document_id, client_location_id\n                FROM document_items\n                WHERE document_id IN ($in)\n                  AND client_location_id IS NOT NULL\n                  AND client_location_id > 0\n            ");
            foreach (($itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $docId = (int)($row['document_id'] ?? 0);
                $locId = (int)($row['client_location_id'] ?? 0);
                if ($docId > 0 && $locId > 0 && isset($contractsById[$docId])) {
                    $idx = $contractsById[$docId];
                    if (!in_array($locId, $contracts[$idx]['location_ids'], true)) {
                        $contracts[$idx]['location_ids'][] = $locId;
                    }
                }
            }
        }

        foreach ($contracts as $idx => $contract) {
            $number = trim((string)($contract['document_number'] ?? ''));
            if ($number === '') {
                $number = 'Contract #' . (int)$contract['id'];
            }
            $date = pz_pv_date_ro($contract['document_date'] ?? null);
            $contracts[$idx]['contract_number_label'] = $number;
            $contracts[$idx]['contract_label'] = 'Contract nr. ' . $number . ($date !== '-' ? ' / ' . $date : '');
            $contracts[$idx]['location_ids'] = array_values(array_unique(array_map('intval', $contracts[$idx]['location_ids'] ?? [])));
        }

        return $contracts;
    } catch (Throwable $e) {
        error_log('Emma PV contracts fetch error: ' . $e->getMessage());
        return [];
    }
}

function pz_pv_contracts_by_id(array $contracts): array {
    $map = [];
    foreach ($contracts as $contract) {
        $id = (int)($contract['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = $contract;
        }
    }
    return $map;
}

function pz_pv_find_default_contract_id(array $contracts, int $clientId, int $locationId = 0): int {
    if ($clientId <= 0) {
        return 0;
    }

    $firstForClient = 0;
    foreach ($contracts as $contract) {
        if ((int)($contract['client_id'] ?? 0) !== $clientId) {
            continue;
        }
        $contractId = (int)($contract['id'] ?? 0);
        if ($contractId <= 0) {
            continue;
        }
        if ($firstForClient <= 0) {
            $firstForClient = $contractId;
        }
        $locationIds = array_map('intval', $contract['location_ids'] ?? []);
        if ($locationId > 0 && in_array($locationId, $locationIds, true)) {
            return $contractId;
        }
    }

    return $firstForClient;
}

function pz_pv_resolve_basis_from_post(array $post, array $contracts, array $contractsById, int $clientId, int $locationId): array {
    $basisType = pz_pv_str($post['basis_type'] ?? '', 60);
    $contractId = !empty($post['contract_id']) ? (int)$post['contract_id'] : 0;
    $manualText = pz_pv_str($post['basis_manual_text'] ?? '', 220);

    if ($basisType === '' || $basisType === 'auto') {
        if ($contractId <= 0) {
            $contractId = pz_pv_find_default_contract_id($contracts, $clientId, $locationId);
        }
        $basisType = $contractId > 0 ? 'contract' : 'nota_comanda';
    }

    if ($basisType === 'contract' && $contractId <= 0) {
        $contractId = pz_pv_find_default_contract_id($contracts, $clientId, $locationId);
    }

    if ($basisType === 'contract' && $contractId > 0 && isset($contractsById[$contractId])) {
        $contract = $contractsById[$contractId];
        $number = trim((string)($contract['contract_number_label'] ?? $contract['document_number'] ?? ''));
        if ($number === '') {
            $number = 'Contract #' . $contractId;
        }
        return [
            'contract_id' => $contractId,
            'basis_type' => 'contract',
            'basis_document' => 'Contract nr. ' . $number,
            'basis_manual_text' => '',
            'contract_number' => $number,
        ];
    }

    if ($basisType === 'achizitie_directa') {
        return [
            'contract_id' => 0,
            'basis_type' => 'achizitie_directa',
            'basis_document' => $manualText !== '' ? $manualText : 'Achizitie directa',
            'basis_manual_text' => $manualText,
            'contract_number' => '',
        ];
    }

    if ($basisType === 'manual') {
        return [
            'contract_id' => 0,
            'basis_type' => 'manual',
            'basis_document' => $manualText !== '' ? $manualText : 'Alta baza',
            'basis_manual_text' => $manualText,
            'contract_number' => '',
        ];
    }

    return [
        'contract_id' => 0,
        'basis_type' => 'nota_comanda',
        'basis_document' => $manualText !== '' ? $manualText : 'Nota de comanda',
        'basis_manual_text' => $manualText,
        'contract_number' => '',
    ];
}

function pz_pv_fetch_products(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'stock_products')) {
        return [];
    }

    $stmt = $pdo->query("\n        SELECT id, name, product_group, unit_consumption, aviz_no, aviz_valid_until,\n               default_application_method, safety_measures, product_concentration, is_active\n        FROM stock_products\n        WHERE COALESCE(is_active, 1) = 1\n        ORDER BY product_group ASC, name ASC\n        LIMIT 1000\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_pv_fetch_receipts(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'stock_receipts')) {
        return [];
    }

    // Exclude recepțiile anulate ca să nu apară în dropdown-ul de loturi din PV.
    $hasCancelled = function_exists('stock_column_exists') ? stock_column_exists($pdo, 'stock_receipts', 'cancelled_at') : false;
    $cancelledFilter = $hasCancelled ? "WHERE cancelled_at IS NULL" : '';
    $stmt = $pdo->query("\n        SELECT id, product_id, lot, expires_at, qty, reception_date\n        FROM stock_receipts\n        $cancelledFilter\n        ORDER BY product_id ASC, COALESCE(expires_at, '2999-12-31') ASC, reception_date ASC, id ASC\n        LIMIT 5000\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_pv_fetch_appointment(PDO $pdo, int $appointmentId): ?array {
    if ($appointmentId <= 0 || !pzdoc_table_exists($pdo, 'appointments')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT a.id, a.client_id, a.client_location_id, a.appointment_date, a.start_time, a.end_time,
                   a.service_type, a.title, a.status, a.notes, a.address, a.contact_person, a.contact_phone,
                   c.name AS client_name, c.legal_representative_name AS client_representative,
                   l.location_name, l.address AS location_address, l.contact_person AS location_contact_person,
                   l.phone AS location_phone, l.surface_value, l.surface_unit,
                   t.name AS team_member_name
            FROM appointments a
            LEFT JOIN clients c ON c.id = a.client_id
            LEFT JOIN client_locations l ON l.id = a.client_location_id
            LEFT JOIN team_members t ON t.id = a.team_member_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('Emma PV appointment fetch error: ' . $e->getMessage());
        return null;
    }
}

function pz_pv_fetch_appointments(PDO $pdo, ?int $teamMemberId = null): array {
    if (!pzdoc_table_exists($pdo, 'appointments')) {
        return [];
    }

    try {
        $where = '';
        $params = [];
        if ($teamMemberId !== null && $teamMemberId > 0) {
            $where = "WHERE a.team_member_id = ? AND a.status = 'finalizata'";
            $params[] = (int)$teamMemberId;
        }

        $stmt = $pdo->prepare("\n            SELECT a.id, a.client_id, a.client_location_id, a.appointment_date, a.start_time, a.end_time,\n                   a.service_type, a.title, a.status, a.notes, a.address, a.contact_person, a.contact_phone,\n                   c.name AS client_name, c.legal_representative_name AS client_representative,\n                   l.location_name, l.address AS location_address, l.contact_person AS location_contact_person,\n                   l.phone AS location_phone, l.surface_value, l.surface_unit,\n                   t.name AS team_member_name\n            FROM appointments a\n            LEFT JOIN clients c ON c.id = a.client_id\n            LEFT JOIN client_locations l ON l.id = a.client_location_id\n            LEFT JOIN team_members t ON t.id = a.team_member_id\n            {$where}\n            ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC\n            LIMIT 600\n        ");
        $stmt->execute($params);
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('Emma PV appointments list error: ' . $e->getMessage());
        return [];
    }
}

function pz_pv_service_from_appointment(array $appointment): string {
    $service = trim((string)($appointment['service_type'] ?? ''));
    if ($service === '') {
        $service = trim((string)($appointment['title'] ?? ''));
    }
    return $service;
}

function pz_pv_service_choices(): array {
    return [
        'dezinsectie' => 'Dezinsecție',
        'dezinfectie' => 'Dezinfecție',
        'deratizare' => 'Deratizare',
        'monitorizare' => 'Monitorizare',
    ];
}

function pz_pv_service_key_from_text(string $text): string {
    $text = strtolower(trim($text));
    $text = str_replace(['ă','â','î','ș','ş','ț','ţ'], ['a','a','i','s','s','t','t'], $text);
    if ($text === '') {
        return '';
    }
    if (strpos($text, 'dezinsect') !== false || strpos($text, 'gandac') !== false || strpos($text, 'plosnit') !== false || strpos($text, 'puric') !== false || strpos($text, 'mus') !== false || strpos($text, 'tantar') !== false || strpos($text, 'viesp') !== false) {
        return 'dezinsectie';
    }
    if (strpos($text, 'dezinfect') !== false || strpos($text, 'dezinfect') !== false) {
        return 'dezinfectie';
    }
    if (strpos($text, 'derat') !== false || strpos($text, 'rozator') !== false || strpos($text, 'soarece') !== false || strpos($text, 'sobolan') !== false) {
        return 'deratizare';
    }
    if (strpos($text, 'monitor') !== false || strpos($text, 'inspect') !== false || strpos($text, 'capcan') !== false) {
        return 'monitorizare';
    }
    return '';
}

function pz_pv_normalize_selected_services($value): array {
    $choices = pz_pv_service_choices();
    $input = is_array($value) ? $value : [$value];
    $selected = [];
    foreach ($input as $raw) {
        $raw = is_scalar($raw) ? (string)$raw : '';
        $key = array_key_exists($raw, $choices) ? $raw : pz_pv_service_key_from_text($raw);
        if ($key !== '' && array_key_exists($key, $choices) && !in_array($key, $selected, true)) {
            $selected[] = $key;
        }
    }
    return $selected;
}

function pz_pv_selected_services_from_items(array $items): array {
    $selected = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = pz_pv_service_key_from_text((string)($item['service_name'] ?? ''));
        if ($key !== '' && !in_array($key, $selected, true)) {
            $selected[] = $key;
        }
    }
    return $selected;
}

function pz_pv_build_service_items_from_selected(array $selectedServices, ?int $mainLocationId, string $surfaceText = ''): array {
    $choices = pz_pv_service_choices();
    $items = [];
    $sort = 0;
    foreach ($selectedServices as $key) {
        if (!isset($choices[$key])) {
            continue;
        }
        $items[] = [
            'item_type' => 'pv_service',
            'service_id' => null,
            'service_name' => $choices[$key],
            'description' => '',
            'client_location_id' => $mainLocationId ?: null,
            'location_name' => null,
            'location_address' => null,
            'quantity' => 1,
            'unit' => '',
            'unit_price' => 0,
            'vat_percent' => 0,
            'total_price' => 0,
            'currency' => 'RON',
            'frequency_text' => $surfaceText,
            'planned_date' => null,
            'sort_order' => $sort,
        ];
        $sort++;
    }
    return $items;
}

function pz_pv_surface_from_appointment(array $appointment): string {
    $surface = trim((string)($appointment['surface_value'] ?? ''));
    if ($surface !== '') {
        $surface .= ' ' . trim((string)($appointment['surface_unit'] ?? ''));
    }
    return trim($surface);
}

function pz_pv_locations_by_id(array $locations): array {
    $map = [];
    foreach ($locations as $location) {
        $map[(int)$location['id']] = $location;
    }
    return $map;
}

function pz_pv_products_by_id(array $products): array {
    $map = [];
    foreach ($products as $product) {
        $map[(int)$product['id']] = $product;
    }
    return $map;
}

function pz_pv_receipts_by_id(array $receipts): array {
    $map = [];
    foreach ($receipts as $receipt) {
        $map[(int)$receipt['id']] = $receipt;
    }
    return $map;
}

function pz_pv_build_items_from_post(array $postItems, array $locationsById, ?int $mainLocationId, string $defaultSurfaceText = ''): array {
    $items = [];
    $sort = 0;

    foreach ($postItems as $row) {
        if (!is_array($row)) {
            continue;
        }

        $serviceId = !empty($row['service_id']) ? (int)$row['service_id'] : null;
        $serviceName = pz_pv_str($row['service_name'] ?? '', 220);
        $description = pz_pv_str($row['description'] ?? '');

        if (!$serviceId && $serviceName === '' && $description === '') {
            continue;
        }

        $locationId = !empty($row['client_location_id']) ? (int)$row['client_location_id'] : ($mainLocationId ?: null);
        $location = ($locationId && isset($locationsById[$locationId])) ? $locationsById[$locationId] : null;

        $surfaceText = pz_pv_str($row['surface_text'] ?? '', 180);
        if ($surfaceText === '') {
            $surfaceText = pz_pv_str($defaultSurfaceText, 180);
        }

        $items[] = [
            'item_type' => 'pv_service',
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'description' => $description,
            'client_location_id' => $locationId,
            'location_name' => $location ? ($location['location_name'] ?? null) : null,
            'location_address' => $location ? ($location['address'] ?? null) : null,
            'quantity' => 1,
            'unit' => '',
            'unit_price' => 0,
            'vat_percent' => 0,
            'total_price' => 0,
            'currency' => 'RON',
            'frequency_text' => $surfaceText,
            'planned_date' => null,
            'sort_order' => $sort,
        ];
        $sort++;
    }

    return $items;
}

function pz_pv_application_method_value(string $value): string {
    $value = strtolower(trim($value));
    $value = str_replace(['ă','â','î','ș','ş','ț','ţ'], ['a','a','i','s','s','t','t'], $value);
    if (strpos($value, 'pulver') !== false) {
        return 'pulverizare';
    }
    if (strpos($value, 'nebul') !== false) {
        return 'nebulizare';
    }
    if (strpos($value, 'amplas') !== false) {
        return 'amplasare';
    }
    if (strpos($value, 'direct') !== false) {
        return 'aplicare directa';
    }
    if (strpos($value, 'momea') !== false) {
        return 'momeala';
    }
    return '';
}

function pz_pv_build_materials_from_post(array $postMaterials, array $productsById, array $receiptsById, bool $deferStockConsumption = false): array {
    $materials = [];
    $sort = 0;

    foreach ($postMaterials as $row) {
        if (!is_array($row)) {
            continue;
        }

        $productId = !empty($row['stock_product_id']) ? (int)$row['stock_product_id'] : null;
        $receiptId = !empty($row['stock_receipt_id']) ? (int)$row['stock_receipt_id'] : null;
        $product = ($productId && isset($productsById[$productId])) ? $productsById[$productId] : null;
        $receipt = ($receiptId && isset($receiptsById[$receiptId])) ? $receiptsById[$receiptId] : null;

        $manualName = pz_pv_str($row['manual_material_name'] ?? '', 255);
        $manualLot = pz_pv_str($row['manual_lot_number'] ?? '', 120);
        $manualQuantityRaw = $row['manual_quantity'] ?? '';
        $manualQuantity = $manualQuantityRaw !== '' ? pz_pv_decimal($manualQuantityRaw, 0) : '';
        $manualUnit = pz_pv_str($row['manual_unit'] ?? ($row['unit'] ?? ''), 30);
        $manualAviz = pz_pv_str($row['manual_aviz_no'] ?? '', 120);
        $manualExpiry = pz_pv_date_for_storage($row['manual_expiry_date'] ?? '');
        $manualConcentration = pz_pv_str($row['manual_work_concentration'] ?? '', 120);
        $manualApplicationMethod = pz_pv_str($row['manual_application_method'] ?? '', 160);
        $legacyName = pz_pv_str($row['material_name'] ?? '', 255);
        $productName = $product ? pz_pv_str($product['name'] ?? '', 255) : '';
        $name = $productName !== '' ? $productName : ($manualName !== '' ? $manualName : $legacyName);

        if ($name === '' && !$product) {
            continue;
        }

        $quantity = pz_pv_decimal($row['quantity'] ?? 0, 0);
        $applicationMethod = pz_pv_str($row['application_method'] ?? '', 160);
        if ($product && $applicationMethod === '') {
            $applicationMethod = pz_pv_application_method_value((string)($product['default_application_method'] ?? ''));
        }
        $applicationMethodCustom = pz_pv_str($row['application_method_custom'] ?? '', 255);
        $isBiocide = $product && function_exists('stock_is_biocide_group') && stock_is_biocide_group((string)($product['product_group'] ?? ''));

        if (!$deferStockConsumption && $product && $quantity <= 0) {
            throw new RuntimeException('Completează cantitatea utilizată pentru produsul "' . $name . '".');
        }

        if (!$deferStockConsumption && $isBiocide && !$receiptId) {
            throw new RuntimeException('Selectează lotul pentru produsul "' . $name . '".');
        }

        // Helper local: ia valoarea din POST dacă e non-vidă, altfel cade pe valoarea din DB (produs).
        // Folosim explicit comparație cu '' (nu doar `??`) pentru că PHP `??` returnează empty
        // string dacă cheia există dar valoarea e '' — și inputurile hidden din formular
        // trimit '' când JS nu apucă să le populeze.
        $pickFromProductOrPost = function($postValue, $productValue, int $maxLen) {
            $post = pz_pv_str($postValue ?? '', $maxLen);
            if ($post !== '') return $post;
            return pz_pv_str($productValue ?? '', $maxLen);
        };

        $materials[] = [
            'stock_product_id' => $product ? $productId : null,
            'stock_receipt_id' => $receipt ? $receiptId : null,
            'material_name' => $name,
            'product_group' => pz_pv_str($row['product_group'] ?? ($product['product_group'] ?? ''), 50),
            'aviz_no' => $product
                ? $pickFromProductOrPost($row['aviz_no'] ?? '', $product['aviz_no'] ?? '', 120)
                : ($manualAviz !== '' ? $manualAviz : pz_pv_str($row['aviz_no'] ?? '', 120)),
            'quantity' => $product ? $quantity : ($manualQuantityRaw !== '' ? $manualQuantity : (($row['quantity'] ?? '') !== '' ? $quantity : '')),
            'unit' => $product
                ? $pickFromProductOrPost($row['unit'] ?? '', $product['unit_consumption'] ?? '', 30)
                : $manualUnit,
            'lot_number' => $product
                ? $pickFromProductOrPost($row['lot_number'] ?? '', $receipt['lot'] ?? '', 120)
                : $manualLot,
            'expiry_date' => $product
                ? $pickFromProductOrPost($row['expiry_date'] ?? '', $receipt['expires_at'] ?? '', 40)
                : ($manualExpiry !== '' ? $manualExpiry : pz_pv_str($row['expiry_date'] ?? '', 40)),
            'application_method' => $product
                ? ($applicationMethod !== '' ? $applicationMethod : pz_pv_application_method_value((string)($product['default_application_method'] ?? '')))
                : ($manualApplicationMethod !== '' ? $manualApplicationMethod : $applicationMethod),
            'application_method_custom' => $applicationMethodCustom,
            'application_area' => pz_pv_str($row['application_area'] ?? '', 160),
            'work_concentration' => $product
                ? $pickFromProductOrPost($row['work_concentration'] ?? '', $product['product_concentration'] ?? '', 120)
                : ($manualConcentration !== '' ? $manualConcentration : pz_pv_str($row['work_concentration'] ?? '', 120)),
            'safety_measures' => pz_pv_str($row['safety_measures'] ?? ($product['safety_measures'] ?? '')),
            'notes' => pz_pv_str($row['notes'] ?? ''),
            'sort_order' => $sort,
        ];
        $sort++;

        if ($product && $manualName !== '') {
            $materials[] = [
                'stock_product_id' => null,
                'stock_receipt_id' => null,
                'material_name' => $manualName,
                'product_group' => '',
                'aviz_no' => $manualAviz,
                'quantity' => $manualQuantity,
                'unit' => $manualUnit,
                'lot_number' => $manualLot,
                'expiry_date' => $manualExpiry,
                'application_method' => $manualApplicationMethod,
                'application_method_custom' => '',
                'application_area' => '',
                'work_concentration' => $manualConcentration,
                'safety_measures' => '',
                'notes' => '',
                'sort_order' => $sort,
            ];
            $sort++;
        }
    }

    return $materials;
}

function pz_pv_build_payload_from_post(array $post): array {
    return [
        'pv_type' => pz_pv_str($post['pv_type'] ?? 'executie', 80),
        'pv_services' => pz_pv_normalize_selected_services($post['pv_services'] ?? []),
        'workers_names' => pz_pv_str($post['workers_names'] ?? ''),
        'surface_text' => pz_pv_str($post['surface_text'] ?? '', 160),
        'treated_areas' => pz_pv_str($post['treated_areas'] ?? ''),
        'start_time' => pz_pv_str($post['start_time'] ?? '', 20),
        'end_time' => pz_pv_str($post['end_time'] ?? '', 20),
        'basis_type' => pz_pv_str($post['basis_type'] ?? '', 60),
        'basis_manual_text' => pz_pv_str($post['basis_manual_text'] ?? '', 220),
        'basis_document' => '',
        'contract_number' => '',
        'materials_enabled' => !empty($post['materials_enabled']) ? '1' : '0',
        'stock_consumption_deferred' => !empty($post['stock_consumption_deferred']) ? '1' : '0',
    ];
}

function pz_pv_redirect_with_error(string $message, int $editId = 0): void {
    $_SESSION['pz_pv_error'] = $message;
    $url = 'service-reports';
    if ($editId > 0) {
        $url .= '?edit=' . (int)$editId;
    } else {
        $url .= '?new=1';
    }
    header('Location: ' . $url);
    exit;
}
