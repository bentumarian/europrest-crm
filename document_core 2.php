<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/document_schema.php';
if (file_exists(__DIR__ . '/stock_lib.php')) {
    require_once __DIR__ . '/stock_lib.php';
}

/*
|--------------------------------------------------------------------------
| PestZone - document core
|--------------------------------------------------------------------------
| Motor comun pentru documente:
| - oferte
| - contracte
| - procese verbale
|
| Reguli:
| - toate documentele folosesc tabelul documents
| - serviciile/liniile folosesc document_items
| - materialele/biocidele folosesc document_materials
| - numarul se aloca doar la emitere, nu la draft
| - documentul emis se blocheaza pentru editare operationala
|--------------------------------------------------------------------------
*/

if (!function_exists('pzdoc_allowed_document_types')) {
    function pzdoc_allowed_document_types(): array
    {
        return ['oferta', 'contract', 'proces_verbal', 'act_aditional'];
    }
}

if (!function_exists('pzdoc_allowed_statuses')) {
    function pzdoc_allowed_statuses(): array
    {
        return ['draft', 'issued', 'cancelled'];
    }
}

if (!function_exists('pzdoc_require_schema')) {
    function pzdoc_require_schema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        pzdoc_install_document_schema($pdo);
        $done = true;
    }
}

if (!function_exists('pzdoc_normalize_document_type')) {
    function pzdoc_normalize_document_type(string $type): string
    {
        $type = strtolower(trim($type));
        $type = str_replace(['-', ' '], '_', $type);

        $map = [
            'offer' => 'oferta',
            'offers' => 'oferta',
            'oferta' => 'oferta',
            'oferte' => 'oferta',
            'contract' => 'contract',
            'contracte' => 'contract',
            'pv' => 'proces_verbal',
            'proces' => 'proces_verbal',
            'proces_verbal' => 'proces_verbal',
            'procese_verbale' => 'proces_verbal',
            'act_aditional' => 'act_aditional',
            'acte_aditionale' => 'act_aditional',
            'addendum' => 'act_aditional',
            'addenda' => 'act_aditional',
        ];

        return $map[$type] ?? $type;
    }
}

if (!function_exists('pzdoc_validate_document_type')) {
    function pzdoc_validate_document_type(string $type): string
    {
        $type = pzdoc_normalize_document_type($type);
        if (!in_array($type, pzdoc_allowed_document_types(), true)) {
            throw new InvalidArgumentException('Tip document invalid.');
        }
        return $type;
    }
}

if (!function_exists('pzdoc_bool')) {
    function pzdoc_bool($value): int
    {
        return !empty($value) ? 1 : 0;
    }
}

if (!function_exists('pzdoc_str')) {
    function pzdoc_str($value, int $maxLength = 0): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if ($maxLength > 0 && function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $maxLength, 'UTF-8');
        } elseif ($maxLength > 0) {
            $value = substr($value, 0, $maxLength);
        }
        return $value;
    }
}

if (!function_exists('pzdoc_decimal')) {
    function pzdoc_decimal($value, float $default = 0.0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], $value);
        }
        return is_numeric($value) ? (float)$value : $default;
    }
}

if (!function_exists('pzdoc_date')) {
    function pzdoc_date($value = null): string
    {
        if ($value === null || trim((string)$value) === '') {
            return date('Y-m-d');
        }

        $ts = strtotime((string)$value);
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }
}

if (!function_exists('pzdoc_time_or_null')) {
    function pzdoc_time_or_null($value = null): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        $ts = strtotime((string)$value);
        return $ts ? date('H:i:s', $ts) : null;
    }
}

if (!function_exists('pzdoc_current_user_id')) {
    function pzdoc_current_user_id(): ?int
    {
        if (function_exists('current_user_id')) {
            $id = current_user_id();
            return $id ? (int)$id : null;
        }
        return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}

if (!function_exists('pzdoc_json_encode')) {
    function pzdoc_json_encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{}';
    }
}

if (!function_exists('pzdoc_json_decode')) {
    function pzdoc_json_decode($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('pzdoc_get_default_template')) {
    function pzdoc_get_default_template(PDO $pdo, string $documentType): ?array
    {
        pzdoc_require_schema($pdo);
        $documentType = pzdoc_validate_document_type($documentType);

        $stmt = $pdo->prepare("\n            SELECT *\n            FROM document_templates\n            WHERE document_type = ?\n              AND is_active = 1\n            ORDER BY is_default DESC, id ASC\n            LIMIT 1\n        ");
        $stmt->execute([$documentType]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        return $template ?: null;
    }
}

if (!function_exists('pzdoc_get_template')) {
    function pzdoc_get_template(PDO $pdo, ?int $templateId, string $documentType): ?array
    {
        pzdoc_require_schema($pdo);
        $documentType = pzdoc_validate_document_type($documentType);

        if ($templateId && $templateId > 0) {
            $stmt = $pdo->prepare("\n                SELECT *\n                FROM document_templates\n                WHERE id = ?\n                  AND document_type = ?\n                  AND is_active = 1\n                LIMIT 1\n            ");
            $stmt->execute([$templateId, $documentType]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($template) {
                return $template;
            }
        }

        return pzdoc_get_default_template($pdo, $documentType);
    }
}

if (!function_exists('pzdoc_fetch_client_snapshot')) {
    function pzdoc_build_client_billing_address(array $client): string
    {
        $line = trim((string)($client['billing_address_line'] ?? ''));
        $county = trim((string)($client['billing_county'] ?? ''));
        $city = trim((string)($client['billing_city'] ?? ''));
        $sector = trim((string)($client['billing_sector'] ?? ''));
        $country = trim((string)($client['billing_country'] ?? ''));
        $postal = trim((string)($client['billing_postal_code'] ?? ''));

        $location = trim(implode(', ', array_filter([$county, $city, $sector], static fn($value) => $value !== '')));
        $address = trim(implode(', ', array_filter([$line, $location, $country], static fn($value) => $value !== '')));

        if ($postal !== '') {
            $address .= ($address !== '' ? ', ' : '') . 'CP ' . $postal;
        }

        return $address;
    }

    function pzdoc_fetch_client_snapshot(PDO $pdo, ?int $clientId): array
    {
        if (!$clientId || !pzdoc_table_exists($pdo, 'clients')) {
            return [];
        }

        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            return [];
        }

        $address = pzdoc_build_client_billing_address($client);
        if ($address === '') {
            $address = trim((string)($client['registered_address'] ?? ''));
        }
        if ($address === '' && !empty($client['address'])) {
            $address = $client['address'];
        }

        return [
            'client_id' => (int)$client['id'],
            'client_name_snapshot' => pzdoc_str($client['name'] ?? null, 220),
            'client_identifier_snapshot' => pzdoc_str($client['fiscal_code'] ?? null, 80),
            'client_registry_snapshot' => pzdoc_str($client['registry_number'] ?? null, 120),
            'client_address_snapshot' => pzdoc_str($address),
            'client_representative_snapshot' => pzdoc_str($client['legal_representative_name'] ?? null, 220),
            'client_email_snapshot' => pzdoc_str($client['email'] ?? null, 180),
            'client_phone_snapshot' => pzdoc_str($client['phone'] ?? null, 80),
        ];
    }
}

if (!function_exists('pzdoc_fetch_location_snapshot')) {
    function pzdoc_fetch_location_snapshot(PDO $pdo, ?int $locationId, ?int $clientId = null): array
    {
        if (!$locationId || !pzdoc_table_exists($pdo, 'client_locations')) {
            return [];
        }

        $sql = "SELECT * FROM client_locations WHERE id = ?";
        $params = [(int)$locationId];
        if ($clientId) {
            $sql .= " AND client_id = ?";
            $params[] = (int)$clientId;
        }
        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$location) {
            return [];
        }

        return [
            'client_location_id' => (int)$location['id'],
            'location_name_snapshot' => pzdoc_str($location['location_name'] ?? null, 220),
            'location_address_snapshot' => pzdoc_str($location['address'] ?? null),
            'location_contact_snapshot' => pzdoc_str($location['contact_person'] ?? null, 220),
            'location_phone_snapshot' => pzdoc_str($location['phone'] ?? null, 80),
        ];
    }
}

if (!function_exists('pzdoc_guess_title')) {
    function pzdoc_guess_title(string $documentType, array $data = []): string
    {
        if (!empty($data['title'])) {
            return (string)$data['title'];
        }

        $name = [
            'oferta' => 'Oferta',
            'contract' => 'Contract',
            'proces_verbal' => 'Proces verbal',
            'act_aditional' => 'Act adițional',
        ][$documentType] ?? 'Document';

        $client = pzdoc_str($data['client_name_snapshot'] ?? null, 120);
        if ($client) {
            return $name . ' - ' . $client;
        }

        return $name;
    }
}

if (!function_exists('pzdoc_calculate_items_totals')) {
    function pzdoc_calculate_items_totals(array $items, float $documentVatPercent = 0.0): array
    {
        $subtotal = 0.0;
        $vatAmount = 0.0;

        foreach ($items as $item) {
            $qty = pzdoc_decimal($item['quantity'] ?? 1, 1);
            $unitPrice = pzdoc_decimal($item['unit_price'] ?? 0, 0);
            $itemType = trim((string)($item['item_type'] ?? ''));
            if ($itemType === 'contract_service') {
                // Contractele folosesc sume nete fixe: fără TVA si fara inmultire cu suprafata.
                // quantity = suprafata informativa, unit_price = pret/intervenție.
                $lineTotal = round($unitPrice, 2);
                $vatPercent = 0.0;
            } elseif ($itemType === 'offer_service') {
                // Ofertele sunt tot fără TVA. Valoarea liniei = cantitate x pret unitar,
                // recalculata pe server ca sa evitam diferente de tip 300 -> 299,96.
                $lineTotal = round($qty * $unitPrice, 2);
                $vatPercent = 0.0;
            } else {
                $lineTotal = array_key_exists('total_price', $item) && $item['total_price'] !== ''
                    ? pzdoc_decimal($item['total_price'], 0)
                    : round($qty * $unitPrice, 2);

                $vatPercent = array_key_exists('vat_percent', $item) && $item['vat_percent'] !== ''
                    ? pzdoc_decimal($item['vat_percent'], $documentVatPercent)
                    : $documentVatPercent;
            }

            $subtotal += $lineTotal;
            $vatAmount += round($lineTotal * $vatPercent / 100, 2);
        }

        return [
            'subtotal' => round($subtotal, 2),
            'vat_amount' => round($vatAmount, 2),
            'total_amount' => round($subtotal + $vatAmount, 2),
        ];
    }
}

if (!function_exists('pzdoc_create_document')) {
    function pzdoc_create_document(PDO $pdo, string $documentType, array $data = []): int
    {
        pzdoc_require_schema($pdo);
        $documentType = pzdoc_validate_document_type($documentType);

        $clientId = !empty($data['client_id']) ? (int)$data['client_id'] : null;
        $locationId = !empty($data['client_location_id']) ? (int)$data['client_location_id'] : null;
        $templateId = !empty($data['template_id']) ? (int)$data['template_id'] : null;
        $template = pzdoc_get_template($pdo, $templateId, $documentType);

        $snapshots = [];
        if ($clientId) {
            $snapshots = array_merge($snapshots, pzdoc_fetch_client_snapshot($pdo, $clientId));
        }
        if ($locationId) {
            $snapshots = array_merge($snapshots, pzdoc_fetch_location_snapshot($pdo, $locationId, $clientId));
        }

        foreach ($snapshots as $key => $value) {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                $data[$key] = $value;
            }
        }

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $vatPercent = pzdoc_decimal($data['vat_percent'] ?? 0, 0);
        if (in_array($documentType, ['oferta', 'contract', 'act_aditional'], true)) {
            $vatPercent = 0.0;
        }
        $totals = pzdoc_calculate_items_totals($items, $vatPercent);

        $documentDate = pzdoc_date($data['document_date'] ?? null);
        $documentTime = pzdoc_time_or_null($data['document_time'] ?? null);
        if ($documentType === 'proces_verbal' && $documentTime === null) {
            $documentTime = date('H:i:s');
        }

        $payload = $data['payload_json'] ?? [];
        if (!is_array($payload)) {
            $payload = pzdoc_json_decode($payload);
        }

        // Ștampila firmei: bifata automat pentru operatori (team), optional pentru admin.
        $applyStamp = 0;
        if (array_key_exists('apply_company_stamp', $data)) {
            $applyStamp = !empty($data['apply_company_stamp']) ? 1 : 0;
        } elseif (function_exists('is_team_user') && is_team_user()) {
            $applyStamp = 1;
        }

        $insert = $pdo->prepare("\n            INSERT INTO documents (\n                document_type, status, template_id, document_date, document_time, title,\n                client_id, client_location_id, contract_id, appointment_id, source_document_id,\n                client_name_snapshot, client_identifier_snapshot, client_registry_snapshot, client_address_snapshot,\n                client_representative_snapshot, client_email_snapshot, client_phone_snapshot,\n                location_name_snapshot, location_address_snapshot, location_contact_snapshot, location_phone_snapshot,\n                subtotal, vat_percent, vat_amount, total_amount, currency,\n                content_html, payload_json, notes, executor_notes, recommendations, client_notes, internal_notes, apply_company_stamp, created_by\n            ) VALUES (\n                :document_type, 'draft', :template_id, :document_date, :document_time, :title,\n                :client_id, :client_location_id, :contract_id, :appointment_id, :source_document_id,\n                :client_name_snapshot, :client_identifier_snapshot, :client_registry_snapshot, :client_address_snapshot,\n                :client_representative_snapshot, :client_email_snapshot, :client_phone_snapshot,\n                :location_name_snapshot, :location_address_snapshot, :location_contact_snapshot, :location_phone_snapshot,\n                :subtotal, :vat_percent, :vat_amount, :total_amount, :currency,\n                :content_html, :payload_json, :notes, :executor_notes, :recommendations, :client_notes, :internal_notes, :apply_company_stamp, :created_by\n            )\n        ");

        $insert->execute([
            ':document_type' => $documentType,
            ':template_id' => $template ? (int)$template['id'] : null,
            ':document_date' => $documentDate,
            ':document_time' => $documentTime,
            ':title' => pzdoc_str(pzdoc_guess_title($documentType, $data), 220) ?: 'Document',
            ':client_id' => $clientId,
            ':client_location_id' => $locationId,
            ':contract_id' => !empty($data['contract_id']) ? (int)$data['contract_id'] : null,
            ':appointment_id' => !empty($data['appointment_id']) ? (int)$data['appointment_id'] : null,
            ':source_document_id' => !empty($data['source_document_id']) ? (int)$data['source_document_id'] : null,
            ':client_name_snapshot' => pzdoc_str($data['client_name_snapshot'] ?? null, 220),
            ':client_identifier_snapshot' => pzdoc_str($data['client_identifier_snapshot'] ?? null, 80),
            ':client_registry_snapshot' => pzdoc_str($data['client_registry_snapshot'] ?? null, 120),
            ':client_address_snapshot' => pzdoc_str($data['client_address_snapshot'] ?? null),
            ':client_representative_snapshot' => pzdoc_str($data['client_representative_snapshot'] ?? null, 220),
            ':client_email_snapshot' => pzdoc_str($data['client_email_snapshot'] ?? null, 180),
            ':client_phone_snapshot' => pzdoc_str($data['client_phone_snapshot'] ?? null, 80),
            ':location_name_snapshot' => pzdoc_str($data['location_name_snapshot'] ?? null, 220),
            ':location_address_snapshot' => pzdoc_str($data['location_address_snapshot'] ?? null),
            ':location_contact_snapshot' => pzdoc_str($data['location_contact_snapshot'] ?? null, 220),
            ':location_phone_snapshot' => pzdoc_str($data['location_phone_snapshot'] ?? null, 80),
            ':subtotal' => $totals['subtotal'],
            ':vat_percent' => $vatPercent,
            ':vat_amount' => $totals['vat_amount'],
            ':total_amount' => $totals['total_amount'],
            ':currency' => pzdoc_str($data['currency'] ?? 'RON', 10) ?: 'RON',
            ':content_html' => pzdoc_str($data['content_html'] ?? null),
            ':payload_json' => pzdoc_json_encode($payload),
            ':notes' => pzdoc_str($data['notes'] ?? null),
            ':executor_notes' => pzdoc_str($data['executor_notes'] ?? null),
            ':recommendations' => pzdoc_str($data['recommendations'] ?? null),
            ':client_notes' => pzdoc_str($data['client_notes'] ?? null),
            ':internal_notes' => pzdoc_str($data['internal_notes'] ?? null),
            ':apply_company_stamp' => $applyStamp,
            ':created_by' => pzdoc_current_user_id(),
        ]);

        $documentId = (int)$pdo->lastInsertId();

        if ($items) {
            pzdoc_replace_document_items($pdo, $documentId, $items, false);
        }

        $materials = is_array($data['materials'] ?? null) ? $data['materials'] : [];
        if ($materials) {
            pzdoc_replace_document_materials($pdo, $documentId, $materials);
        }

        return $documentId;
    }
}

if (!function_exists('pzdoc_get_document')) {
    function pzdoc_get_document(PDO $pdo, int $documentId, bool $withChildren = true): ?array
    {
        pzdoc_require_schema($pdo);

        $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            return null;
        }

        $document['payload'] = pzdoc_json_decode($document['payload_json'] ?? null);

        if ($withChildren) {
            $document['items'] = pzdoc_get_document_items($pdo, $documentId);
            $document['materials'] = pzdoc_get_document_materials($pdo, $documentId);
        }

        return $document;
    }
}

if (!function_exists('pzdoc_get_document_items')) {
    function pzdoc_get_document_items(PDO $pdo, int $documentId): array
    {
        pzdoc_require_schema($pdo);
        $stmt = $pdo->prepare("\n            SELECT *\n            FROM document_items\n            WHERE document_id = ?\n            ORDER BY sort_order ASC, id ASC\n        ");
        $stmt->execute([(int)$documentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('pzdoc_get_document_materials')) {
    function pzdoc_get_document_materials(PDO $pdo, int $documentId): array
    {
        pzdoc_require_schema($pdo);
        $stmt = $pdo->prepare("\n            SELECT *\n            FROM document_materials\n            WHERE document_id = ?\n            ORDER BY sort_order ASC, id ASC\n        ");
        $stmt->execute([(int)$documentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('pzdoc_is_editable')) {
    function pzdoc_is_editable(array $document): bool
    {
        return ($document['status'] ?? '') === 'draft' && empty($document['locked_at']);
    }
}

if (!function_exists('pzdoc_assert_editable')) {
    function pzdoc_assert_editable(array $document): void
    {
        if (!pzdoc_is_editable($document)) {
            throw new RuntimeException('Documentul nu mai poate fi editat.');
        }
    }
}

if (!function_exists('pzdoc_update_document')) {
    function pzdoc_update_document(PDO $pdo, int $documentId, array $data = []): void
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, false);
        if (!$document) {
            throw new RuntimeException('Document inexistent.');
        }
        pzdoc_assert_editable($document);

        $documentType = pzdoc_validate_document_type($document['document_type']);
        $clientId = array_key_exists('client_id', $data) ? (!empty($data['client_id']) ? (int)$data['client_id'] : null) : (!empty($document['client_id']) ? (int)$document['client_id'] : null);
        $locationId = array_key_exists('client_location_id', $data) ? (!empty($data['client_location_id']) ? (int)$data['client_location_id'] : null) : (!empty($document['client_location_id']) ? (int)$document['client_location_id'] : null);

        $snapshots = [];
        if ($clientId) {
            $snapshots = array_merge($snapshots, pzdoc_fetch_client_snapshot($pdo, $clientId));
        }
        if ($locationId) {
            $snapshots = array_merge($snapshots, pzdoc_fetch_location_snapshot($pdo, $locationId, $clientId));
        }
        foreach ($snapshots as $key => $value) {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                $data[$key] = $value;
            }
        }

        $items = array_key_exists('items', $data) && is_array($data['items']) ? $data['items'] : null;
        $vatPercent = array_key_exists('vat_percent', $data) ? pzdoc_decimal($data['vat_percent'], 0) : pzdoc_decimal($document['vat_percent'] ?? 0, 0);
        if (in_array($documentType, ['oferta', 'contract', 'act_aditional'], true)) {
            $vatPercent = 0.0;
        }
        $totals = $items !== null
            ? pzdoc_calculate_items_totals($items, $vatPercent)
            : [
                'subtotal' => pzdoc_decimal($document['subtotal'] ?? 0),
                'vat_amount' => pzdoc_decimal($document['vat_amount'] ?? 0),
                'total_amount' => pzdoc_decimal($document['total_amount'] ?? 0),
            ];

        $templateId = null;
        if (array_key_exists('template_id', $data)) {
            $template = pzdoc_get_template($pdo, !empty($data['template_id']) ? (int)$data['template_id'] : null, $documentType);
            $templateId = $template ? (int)$template['id'] : null;
        } else {
            $templateId = !empty($document['template_id']) ? (int)$document['template_id'] : null;
        }

        $payload = array_key_exists('payload_json', $data) ? $data['payload_json'] : ($document['payload_json'] ?? []);
        if (!is_array($payload)) {
            $payload = pzdoc_json_decode($payload);
        }

        // Ștampila firmei: bifare automata pentru operatori (team), pastram valoarea existenta dacă nu e in $data.
        if (array_key_exists('apply_company_stamp', $data)) {
            $applyStamp = !empty($data['apply_company_stamp']) ? 1 : 0;
        } elseif (function_exists('is_team_user') && is_team_user()) {
            $applyStamp = 1;
        } else {
            $applyStamp = !empty($document['apply_company_stamp']) ? 1 : 0;
        }

        $stmt = $pdo->prepare("\n            UPDATE documents SET\n                template_id = :template_id,\n                document_date = :document_date,\n                document_time = :document_time,\n                title = :title,\n                client_id = :client_id,\n                client_location_id = :client_location_id,\n                contract_id = :contract_id,\n                appointment_id = :appointment_id,\n                source_document_id = :source_document_id,\n                client_name_snapshot = :client_name_snapshot,\n                client_identifier_snapshot = :client_identifier_snapshot,\n                client_registry_snapshot = :client_registry_snapshot,\n                client_address_snapshot = :client_address_snapshot,\n                client_representative_snapshot = :client_representative_snapshot,\n                client_email_snapshot = :client_email_snapshot,\n                client_phone_snapshot = :client_phone_snapshot,\n                location_name_snapshot = :location_name_snapshot,\n                location_address_snapshot = :location_address_snapshot,\n                location_contact_snapshot = :location_contact_snapshot,\n                location_phone_snapshot = :location_phone_snapshot,\n                subtotal = :subtotal,\n                vat_percent = :vat_percent,\n                vat_amount = :vat_amount,\n                total_amount = :total_amount,\n                currency = :currency,\n                content_html = :content_html,\n                payload_json = :payload_json,\n                notes = :notes,\n                executor_notes = :executor_notes,\n                recommendations = :recommendations,\n                client_notes = :client_notes,\n                internal_notes = :internal_notes,\n                apply_company_stamp = :apply_company_stamp\n            WHERE id = :id\n              AND status = 'draft'\n        ");

        $stmt->execute([
            ':template_id' => $templateId,
            ':apply_company_stamp' => $applyStamp,
            ':document_date' => array_key_exists('document_date', $data) ? pzdoc_date($data['document_date']) : pzdoc_date($document['document_date'] ?? null),
            ':document_time' => array_key_exists('document_time', $data) ? pzdoc_time_or_null($data['document_time']) : ($document['document_time'] ?? null),
            ':title' => pzdoc_str($data['title'] ?? $document['title'] ?? pzdoc_guess_title($documentType, $data), 220) ?: 'Document',
            ':client_id' => $clientId,
            ':client_location_id' => $locationId,
            ':contract_id' => array_key_exists('contract_id', $data) ? (!empty($data['contract_id']) ? (int)$data['contract_id'] : null) : ($document['contract_id'] ?? null),
            ':appointment_id' => array_key_exists('appointment_id', $data) ? (!empty($data['appointment_id']) ? (int)$data['appointment_id'] : null) : ($document['appointment_id'] ?? null),
            ':source_document_id' => array_key_exists('source_document_id', $data) ? (!empty($data['source_document_id']) ? (int)$data['source_document_id'] : null) : ($document['source_document_id'] ?? null),
            ':client_name_snapshot' => pzdoc_str($data['client_name_snapshot'] ?? $document['client_name_snapshot'] ?? null, 220),
            ':client_identifier_snapshot' => pzdoc_str($data['client_identifier_snapshot'] ?? $document['client_identifier_snapshot'] ?? null, 80),
            ':client_registry_snapshot' => pzdoc_str($data['client_registry_snapshot'] ?? $document['client_registry_snapshot'] ?? null, 120),
            ':client_address_snapshot' => pzdoc_str($data['client_address_snapshot'] ?? $document['client_address_snapshot'] ?? null),
            ':client_representative_snapshot' => pzdoc_str($data['client_representative_snapshot'] ?? $document['client_representative_snapshot'] ?? null, 220),
            ':client_email_snapshot' => pzdoc_str($data['client_email_snapshot'] ?? $document['client_email_snapshot'] ?? null, 180),
            ':client_phone_snapshot' => pzdoc_str($data['client_phone_snapshot'] ?? $document['client_phone_snapshot'] ?? null, 80),
            ':location_name_snapshot' => pzdoc_str($data['location_name_snapshot'] ?? $document['location_name_snapshot'] ?? null, 220),
            ':location_address_snapshot' => pzdoc_str($data['location_address_snapshot'] ?? $document['location_address_snapshot'] ?? null),
            ':location_contact_snapshot' => pzdoc_str($data['location_contact_snapshot'] ?? $document['location_contact_snapshot'] ?? null, 220),
            ':location_phone_snapshot' => pzdoc_str($data['location_phone_snapshot'] ?? $document['location_phone_snapshot'] ?? null, 80),
            ':subtotal' => $totals['subtotal'],
            ':vat_percent' => $vatPercent,
            ':vat_amount' => $totals['vat_amount'],
            ':total_amount' => $totals['total_amount'],
            ':currency' => pzdoc_str($data['currency'] ?? $document['currency'] ?? 'RON', 10) ?: 'RON',
            ':content_html' => pzdoc_str($data['content_html'] ?? $document['content_html'] ?? null),
            ':payload_json' => pzdoc_json_encode($payload),
            ':notes' => pzdoc_str($data['notes'] ?? $document['notes'] ?? null),
            ':executor_notes' => pzdoc_str($data['executor_notes'] ?? $document['executor_notes'] ?? null),
            ':recommendations' => pzdoc_str($data['recommendations'] ?? $document['recommendations'] ?? null),
            ':client_notes' => pzdoc_str($data['client_notes'] ?? $document['client_notes'] ?? null),
            ':internal_notes' => pzdoc_str($data['internal_notes'] ?? $document['internal_notes'] ?? null),
            ':id' => $documentId,
        ]);

        if ($items !== null) {
            pzdoc_replace_document_items($pdo, $documentId, $items, false);
        }

        if (array_key_exists('materials', $data) && is_array($data['materials'])) {
            pzdoc_replace_document_materials($pdo, $documentId, $data['materials']);
        }
    }
}

if (!function_exists('pzdoc_replace_document_items')) {
    function pzdoc_replace_document_items(PDO $pdo, int $documentId, array $items, bool $checkEditable = true): void
    {
        pzdoc_require_schema($pdo);
        if ($checkEditable) {
            $document = pzdoc_get_document($pdo, $documentId, false);
            if (!$document) {
                throw new RuntimeException('Document inexistent.');
            }
            pzdoc_assert_editable($document);
        }

        $documentTypeForItems = '';
        try {
            $typeStmt = $pdo->prepare("SELECT document_type FROM documents WHERE id = ? LIMIT 1");
            $typeStmt->execute([(int)$documentId]);
            $documentTypeForItems = (string)$typeStmt->fetchColumn();
        } catch (Throwable $e) {
            $documentTypeForItems = '';
        }

        $pdo->prepare("DELETE FROM document_items WHERE document_id = ?")->execute([(int)$documentId]);

        $insert = $pdo->prepare("\n            INSERT INTO document_items (\n                document_id, item_type, service_id, service_name, description, client_location_id,\n                location_name, location_address, quantity, unit, unit_price, vat_percent,\n                total_price, currency, frequency_text, planned_date, sort_order\n            ) VALUES (\n                :document_id, :item_type, :service_id, :service_name, :description, :client_location_id,\n                :location_name, :location_address, :quantity, :unit, :unit_price, :vat_percent,\n                :total_price, :currency, :frequency_text, :planned_date, :sort_order\n            )\n        ");

        $sort = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $serviceName = pzdoc_str($item['service_name'] ?? $item['name'] ?? null, 220);
            if (!$serviceName && !empty($item['service_id'])) {
                $serviceName = pzdoc_lookup_service_name($pdo, (int)$item['service_id']);
            }
            if (!$serviceName) {
                continue;
            }

            $quantity = pzdoc_decimal($item['quantity'] ?? 1, 1);
            $unitPrice = round(pzdoc_decimal($item['unit_price'] ?? 0, 0), 2);
            $itemType = pzdoc_str($item['item_type'] ?? 'service', 40) ?: 'service';
            if ($documentTypeForItems === 'contract' || $itemType === 'contract_service') {
                // In contract, pretul este suma neta fixa / intervenție. Nu folosim TVA si nu inmultim cu suprafata.
                $itemType = 'contract_service';
                $totalPrice = $unitPrice;
                $itemVatPercent = 0.0;
            } elseif ($documentTypeForItems === 'oferta' || $itemType === 'offer_service') {
                // In oferta, preturile sunt fără TVA. Valoarea liniei se recalculeaza pe server din cantitate x pret unitar.
                $itemType = 'offer_service';
                $totalPrice = round($quantity * $unitPrice, 2);
                $itemVatPercent = 0.0;
            } else {
                $totalPrice = array_key_exists('total_price', $item) && $item['total_price'] !== ''
                    ? round(pzdoc_decimal($item['total_price'], 0), 2)
                    : round($quantity * $unitPrice, 2);
                $itemVatPercent = pzdoc_decimal($item['vat_percent'] ?? 0, 0);
            }

            $insert->execute([
                ':document_id' => $documentId,
                ':item_type' => $itemType,
                ':service_id' => !empty($item['service_id']) ? (int)$item['service_id'] : null,
                ':service_name' => $serviceName,
                ':description' => pzdoc_str($item['description'] ?? null),
                ':client_location_id' => !empty($item['client_location_id']) ? (int)$item['client_location_id'] : null,
                ':location_name' => pzdoc_str($item['location_name'] ?? null, 220),
                ':location_address' => pzdoc_str($item['location_address'] ?? null),
                ':quantity' => $quantity,
                ':unit' => pzdoc_str($item['unit'] ?? null, 30),
                ':unit_price' => $unitPrice,
                ':vat_percent' => $itemVatPercent,
                ':total_price' => $totalPrice,
                ':currency' => pzdoc_str($item['currency'] ?? 'RON', 10) ?: 'RON',
                ':frequency_text' => pzdoc_str($item['frequency_text'] ?? null, 255),
                ':planned_date' => !empty($item['planned_date']) ? pzdoc_date($item['planned_date']) : null,
                ':sort_order' => array_key_exists('sort_order', $item) ? (int)$item['sort_order'] : $sort,
            ]);
            $sort++;
        }

        pzdoc_recalculate_document_totals($pdo, $documentId);
    }
}

if (!function_exists('pzdoc_replace_document_materials')) {
    function pzdoc_replace_document_materials(PDO $pdo, int $documentId, array $materials): void
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, false);
        if (!$document) {
            throw new RuntimeException('Document inexistent.');
        }
        pzdoc_assert_editable($document);

        $pdo->prepare("DELETE FROM document_materials WHERE document_id = ?")->execute([(int)$documentId]);

        $insert = $pdo->prepare("\n            INSERT INTO document_materials (\n                document_id, document_item_id, stock_product_id, stock_receipt_id, material_name, product_group,\n                aviz_no, quantity, unit, lot_number, expiry_date, application_method, application_method_custom,\n                application_area, work_concentration, safety_measures, notes, sort_order\n            ) VALUES (\n                :document_id, :document_item_id, :stock_product_id, :stock_receipt_id, :material_name, :product_group,\n                :aviz_no, :quantity, :unit, :lot_number, :expiry_date, :application_method, :application_method_custom,\n                :application_area, :work_concentration, :safety_measures, :notes, :sort_order\n            )\n        ");

        $sort = 0;
        foreach ($materials as $material) {
            if (!is_array($material)) {
                continue;
            }

            $name = pzdoc_str($material['material_name'] ?? $material['product_name'] ?? $material['name'] ?? null, 255);
            if (!$name) {
                continue;
            }

            $insert->execute([
                ':document_id' => $documentId,
                ':document_item_id' => !empty($material['document_item_id']) ? (int)$material['document_item_id'] : null,
                ':stock_product_id' => !empty($material['stock_product_id']) ? (int)$material['stock_product_id'] : null,
                ':stock_receipt_id' => !empty($material['stock_receipt_id']) ? (int)$material['stock_receipt_id'] : null,
                ':material_name' => $name,
                ':product_group' => pzdoc_str($material['product_group'] ?? null, 50),
                ':aviz_no' => pzdoc_str($material['aviz_no'] ?? null, 120),
                ':quantity' => pzdoc_decimal($material['quantity'] ?? 0, 0),
                ':unit' => pzdoc_str($material['unit'] ?? null, 30),
                ':lot_number' => pzdoc_str($material['lot_number'] ?? $material['lot'] ?? null, 120),
                ':expiry_date' => !empty($material['expiry_date']) ? pzdoc_date($material['expiry_date']) : null,
                ':application_method' => pzdoc_str($material['application_method'] ?? null, 160),
                ':application_method_custom' => pzdoc_str($material['application_method_custom'] ?? null, 255),
                ':application_area' => pzdoc_str($material['application_area'] ?? null, 160),
                ':work_concentration' => pzdoc_str($material['work_concentration'] ?? null, 120),
                ':safety_measures' => pzdoc_str($material['safety_measures'] ?? null),
                ':notes' => pzdoc_str($material['notes'] ?? null),
                ':sort_order' => array_key_exists('sort_order', $material) ? (int)$material['sort_order'] : $sort,
            ]);
            $sort++;
        }
    }
}

if (!function_exists('pzdoc_recalculate_document_totals')) {
    function pzdoc_recalculate_document_totals(PDO $pdo, int $documentId): void
    {
        pzdoc_require_schema($pdo);

        $stmt = $pdo->prepare("SELECT * FROM document_items WHERE document_id = ?");
        $stmt->execute([(int)$documentId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $subtotal = 0.0;
        $vatAmount = 0.0;
        foreach ($items as $item) {
            $itemType = trim((string)($item['item_type'] ?? ''));
            if ($itemType === 'contract_service') {
                // Contractele sunt nete si fixe. total_price vechi poate contine valori calculate gresit;
                // pentru contract luam mereu unit_price ca valoare reala / intervenție.
                $line = round(pzdoc_decimal($item['unit_price'] ?? $item['total_price'] ?? 0, 0), 2);
                $vat = 0.0;
            } elseif ($itemType === 'offer_service') {
                // Ofertele sunt nete si fără TVA: cantitate x pret unitar, fara campuri ascunse vechi.
                $qty = pzdoc_decimal($item['quantity'] ?? 1, 1);
                $unit = pzdoc_decimal($item['unit_price'] ?? 0, 0);
                $line = round($qty * $unit, 2);
                $vat = 0.0;
            } else {
                $line = pzdoc_decimal($item['total_price'] ?? 0, 0);
                $vat = pzdoc_decimal($item['vat_percent'] ?? 0, 0);
            }
            $subtotal += $line;
            $vatAmount += round($line * $vat / 100, 2);
        }

        $docVatPercent = 0.0;
        if ($items) {
            $docVatPercent = pzdoc_decimal($items[0]['vat_percent'] ?? 0, 0);
        }

        $update = $pdo->prepare("\n            UPDATE documents\n            SET subtotal = ?, vat_percent = ?, vat_amount = ?, total_amount = ?\n            WHERE id = ?\n        ");
        $update->execute([
            round($subtotal, 2),
            $docVatPercent,
            round($vatAmount, 2),
            round($subtotal + $vatAmount, 2),
            (int)$documentId,
        ]);
    }
}

if (!function_exists('pzdoc_lookup_service_name')) {
    function pzdoc_lookup_service_name(PDO $pdo, int $serviceId): ?string
    {
        if (!$serviceId || !pzdoc_table_exists($pdo, 'services')) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT name FROM services WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$serviceId]);
        $name = $stmt->fetchColumn();
        return $name ? pzdoc_str($name, 220) : null;
    }
}

if (!function_exists('pzdoc_get_default_series_for_update')) {
    function pzdoc_get_default_series_for_update(PDO $pdo, string $documentType, ?int $seriesId = null): array
    {
        $documentType = pzdoc_validate_document_type($documentType);

        if ($seriesId && $seriesId > 0) {
            $stmt = $pdo->prepare("\n                SELECT *\n                FROM document_series\n                WHERE id = ?\n                  AND document_type = ?\n                  AND active = 1\n                LIMIT 1\n                FOR UPDATE\n            ");
            $stmt->execute([$seriesId, $documentType]);
            $series = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($series) {
                return $series;
            }
        }

        $stmt = $pdo->prepare("\n            SELECT *\n            FROM document_series\n            WHERE document_type = ?\n              AND active = 1\n            ORDER BY is_default DESC, id ASC\n            LIMIT 1\n            FOR UPDATE\n        ");
        $stmt->execute([$documentType]);
        $series = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($series) {
            return $series;
        }

        $seriesCode = pzdoc_default_series_code($documentType);
        $seriesName = pzdoc_default_series_name($documentType);
        $pattern = ($documentType === 'contract') ? '{N}/{DD}.{MM}.{YYYY}' : '{SERIE} {N}/{DD}.{MM}.{YYYY}';

        $insert = $pdo->prepare("\n            INSERT INTO document_series\n                (document_type, name, series_code, format_pattern, year, next_number, padding, reset_yearly, is_default, active)\n            VALUES\n                (?, ?, ?, ?, NULL, 1, 1, 0, 1, 1)\n        ");
        $insert->execute([$documentType, $seriesName, $seriesCode, $pattern]);

        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare("SELECT * FROM document_series WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$id]);
        $series = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$series) {
            throw new RuntimeException('Seria documentului nu a putut fi creata.');
        }

        return $series;
    }
}

if (!function_exists('pzdoc_format_document_number')) {
    function pzdoc_format_document_number(array $series, int $number, string $issuedDate): string
    {
        $ts = strtotime($issuedDate) ?: time();
        $padding = max(1, (int)($series['padding'] ?? 1));
        $numberPadded = str_pad((string)$number, $padding, '0', STR_PAD_LEFT);
        $pattern = (string)($series['format_pattern'] ?? '{N}/{DD}.{MM}.{YYYY}');
        if (trim($pattern) === '') {
            $pattern = '{N}/{DD}.{MM}.{YYYY}';
        }

        $replacements = [
            '{SERIE}' => (string)($series['series_code'] ?? ''),
            '{SERIES}' => (string)($series['series_code'] ?? ''),
            '{N}' => $numberPadded,
            '{NUMBER}' => $numberPadded,
            '{NR}' => $numberPadded,
            '{YYYY}' => date('Y', $ts),
            '{YY}' => date('y', $ts),
            '{MM}' => date('m', $ts),
            '{M}' => date('n', $ts),
            '{DD}' => date('d', $ts),
            '{D}' => date('j', $ts),
        ];

        $full = strtr($pattern, $replacements);
        $full = preg_replace('/\s+/', ' ', trim((string)$full));
        return $full !== '' ? $full : $numberPadded . '/' . date('d.m.Y', $ts);
    }
}

if (!function_exists('pzdoc_issue_document')) {
    function pzdoc_issue_document(PDO $pdo, int $documentId, array $options = []): array
    {
        pzdoc_require_schema($pdo);

        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([(int)$documentId]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$document) {
                throw new RuntimeException('Document inexistent.');
            }

            if (($document['status'] ?? '') === 'issued') {
                if ($startedTransaction) {
                    $pdo->commit();
                }
                return $document;
            }

            if (($document['status'] ?? '') !== 'draft') {
                throw new RuntimeException('Doar documentele draft pot fi emise.');
            }

            $documentType = pzdoc_validate_document_type($document['document_type']);
            $issuedDate = pzdoc_date($options['issued_date'] ?? $document['document_date'] ?? null);
            $seriesId = !empty($options['series_id']) ? (int)$options['series_id'] : (!empty($document['document_series_id']) ? (int)$document['document_series_id'] : null);
            $series = pzdoc_get_default_series_for_update($pdo, $documentType, $seriesId);

            $currentYear = (int)date('Y', strtotime($issuedDate) ?: time());
            $seriesYear = !empty($series['year']) ? (int)$series['year'] : null;
            $resetYearly = !empty($series['reset_yearly']);

            if ($resetYearly && $seriesYear !== $currentYear) {
                $series['next_number'] = 1;
                $series['year'] = $currentYear;
                $upd = $pdo->prepare("UPDATE document_series SET next_number = 1, year = ? WHERE id = ?");
                $upd->execute([$currentYear, (int)$series['id']]);
            }

            $numberInt = max(1, (int)($series['next_number'] ?? 1));
            $fullNumber = pzdoc_format_document_number($series, $numberInt, $issuedDate);
            $issuedBy = pzdoc_current_user_id();

            $insertNumber = $pdo->prepare("\n                INSERT INTO document_numbers\n                    (document_type, document_id, series_id, series_code, number_int, full_number, issued_date, year, status, issued_by)\n                VALUES\n                    (?, ?, ?, ?, ?, ?, ?, ?, 'emis', ?)\n            ");
            $insertNumber->execute([
                $documentType,
                (int)$documentId,
                (int)$series['id'],
                (string)($series['series_code'] ?? ''),
                $numberInt,
                $fullNumber,
                $issuedDate,
                $currentYear,
                $issuedBy,
            ]);

            $numberId = (int)$pdo->lastInsertId();

            $updateSeries = $pdo->prepare("UPDATE document_series SET next_number = ?, year = COALESCE(year, ?) WHERE id = ?");
            $updateSeries->execute([$numberInt + 1, $currentYear, (int)$series['id']]);

            $updateDocument = $pdo->prepare("\n                UPDATE documents SET\n                    status = 'issued',\n                    document_series_id = ?,\n                    document_number_id = ?,\n                    document_number = ?,\n                    document_date = ?,\n                    issued_by = ?,\n                    issued_at = NOW(),\n                    locked_at = NOW()\n                WHERE id = ?\n            ");
            $updateDocument->execute([
                (int)$series['id'],
                $numberId,
                $fullNumber,
                $issuedDate,
                $issuedBy,
                (int)$documentId,
            ]);

            $document = pzdoc_get_document($pdo, $documentId, true);
            if (!$document) {
                throw new RuntimeException('Documentul emis nu a putut fi reincarcat.');
            }

            if ($documentType === 'proces_verbal' && function_exists('stock_consume_document_materials')) {
                stock_consume_document_materials($pdo, $documentId);
                $document = pzdoc_get_document($pdo, $documentId, true) ?: $document;
            }

            if ($startedTransaction) {
                $pdo->commit();
            }

            if ($documentType === 'contract') {
                try {
                    $flowFile = __DIR__ . '/contract_flow_lib.php';
                    if (file_exists($flowFile)) {
                        require_once $flowFile;
                    }
                    if (function_exists('pz_flow_sync_issued_contract')) {
                        pz_flow_sync_issued_contract($pdo, (int)$documentId);
                        $document = pzdoc_get_document($pdo, $documentId, true) ?: $document;
                    }
                } catch (Throwable $flowErr) {
                    error_log('PestZone contract flow sync from document_core issue hook error: ' . $flowErr->getMessage());
                }
            }

            return $document;
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('pzdoc_delete_draft')) {
    function pzdoc_delete_draft(PDO $pdo, int $documentId): void
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, false);
        if (!$document) {
            return;
        }
        pzdoc_assert_editable($document);

        $pdo->prepare("DELETE FROM document_materials WHERE document_id = ?")->execute([(int)$documentId]);
        $pdo->prepare("DELETE FROM document_items WHERE document_id = ?")->execute([(int)$documentId]);
        $pdo->prepare("DELETE FROM documents WHERE id = ? AND status = 'draft'")->execute([(int)$documentId]);
    }
}

if (!function_exists('pzdoc_cancel_document')) {
    function pzdoc_cancel_document(PDO $pdo, int $documentId, ?string $reason = null): void
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, false);
        if (!$document) {
            throw new RuntimeException('Document inexistent.');
        }

        if (($document['status'] ?? '') !== 'issued') {
            throw new RuntimeException('Doar documentele emise pot fi anulate.');
        }

        $payload = pzdoc_json_decode($document['payload_json'] ?? null);
        $payload['cancel_reason'] = $reason;
        $payload['cancelled_by'] = pzdoc_current_user_id();
        $payload['cancelled_at'] = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("\n            UPDATE documents\n            SET status = 'cancelled', cancelled_at = NOW(), payload_json = ?\n            WHERE id = ?\n        ");
        $stmt->execute([pzdoc_json_encode($payload), (int)$documentId]);

        if (!empty($document['document_number_id'])) {
            $upd = $pdo->prepare("UPDATE document_numbers SET status = 'anulat', notes = ? WHERE id = ?");
            $upd->execute([$reason, (int)$document['document_number_id']]);
        }

        if (($document['document_type'] ?? '') === 'proces_verbal' && function_exists('stock_return_document_materials_on_cancel')) {
            stock_return_document_materials_on_cancel($pdo, $documentId);
        }
    }
}

if (!function_exists('pzdoc_link_documents')) {
    function pzdoc_link_documents(PDO $pdo, int $fromDocumentId, int $toDocumentId, string $linkType, ?string $notes = null): void
    {
        pzdoc_require_schema($pdo);
        $stmt = $pdo->prepare("\n            INSERT INTO document_links\n                (from_document_id, to_document_id, link_type, notes, created_by)\n            VALUES\n                (?, ?, ?, ?, ?)\n        ");
        $stmt->execute([
            (int)$fromDocumentId,
            (int)$toDocumentId,
            pzdoc_str($linkType, 60) ?: 'linked',
            pzdoc_str($notes),
            pzdoc_current_user_id(),
        ]);
    }
}

if (!function_exists('pzdoc_list_documents')) {
    function pzdoc_list_documents(PDO $pdo, string $documentType, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        pzdoc_require_schema($pdo);
        $documentType = pzdoc_validate_document_type($documentType);
        $limit = max(1, min(200, (int)$limit));
        $offset = max(0, (int)$offset);

        $where = ['document_type = ?'];
        $params = [$documentType];

        if (!empty($filters['status']) && in_array((string)$filters['status'], pzdoc_allowed_statuses(), true)) {
            $where[] = 'status = ?';
            $params[] = (string)$filters['status'];
        }

        if (!empty($filters['client_id'])) {
            $where[] = 'client_id = ?';
            $params[] = (int)$filters['client_id'];
        }

        if (!empty($filters['q'])) {
            $q = '%' . trim((string)$filters['q']) . '%';
            $where[] = '(document_number LIKE ? OR title LIKE ? OR client_name_snapshot LIKE ? OR client_identifier_snapshot LIKE ?)';
            array_push($params, $q, $q, $q, $q);
        }

        $sql = "\n            SELECT *\n            FROM documents\n            WHERE " . implode(' AND ', $where) . "\n            ORDER BY document_date DESC, id DESC\n            LIMIT {$limit} OFFSET {$offset}\n        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('pzdoc_count_documents')) {
    function pzdoc_count_documents(PDO $pdo, string $documentType, array $filters = []): int
    {
        pzdoc_require_schema($pdo);
        $documentType = pzdoc_validate_document_type($documentType);

        $where = ['document_type = ?'];
        $params = [$documentType];

        if (!empty($filters['status']) && in_array((string)$filters['status'], pzdoc_allowed_statuses(), true)) {
            $where[] = 'status = ?';
            $params[] = (string)$filters['status'];
        }

        if (!empty($filters['client_id'])) {
            $where[] = 'client_id = ?';
            $params[] = (int)$filters['client_id'];
        }

        if (!empty($filters['q'])) {
            $q = '%' . trim((string)$filters['q']) . '%';
            $where[] = '(document_number LIKE ? OR title LIKE ? OR client_name_snapshot LIKE ? OR client_identifier_snapshot LIKE ?)';
            array_push($params, $q, $q, $q, $q);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

/*
|--------------------------------------------------------------------------
| Rulare directa optionala
|--------------------------------------------------------------------------
*/
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === basename(__FILE__)) {
    require_login();

    if (!is_admin()) {
        header('Location: calendar.php');
        exit;
    }

    try {
        pzdoc_require_schema($pdo);
        echo 'Motor documente incarcat corect.';
    } catch (Throwable $e) {
        error_log('PestZone document core error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Eroare la incarcarea motorului de documente.';
    }
}
