<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/document_core.php';

if (file_exists(__DIR__ . '/lib/settings_lib.php')) {
    require_once __DIR__ . '/lib/settings_lib.php';
}

/*
|--------------------------------------------------------------------------
| Emma - document tokens
|--------------------------------------------------------------------------
| Transforma datele unui document in variabile pentru șabloane:
| {{document_number}}, {{client_name}}, {{items_table}}, {{materials_table}}
|
| Acest fișier nu emite documente si nu genereaza PDF. El doar pregateste
| HTML-ul final care va fi trimis catre document_pdf_engine.php.
|--------------------------------------------------------------------------
*/

if (!function_exists('pzdoc_h')) {
    function pzdoc_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pzdoc_token_text')) {
    function pzdoc_token_text($value, string $empty = '-'): string
    {
        $value = trim((string)($value ?? ''));
        return pzdoc_h($value !== '' ? $value : $empty);
    }
}

if (!function_exists('pzdoc_token_multiline')) {
    function pzdoc_token_multiline($value, string $empty = '-'): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return pzdoc_h($empty);
        }
        return nl2br(pzdoc_h($value));
    }
}

if (!function_exists('pzdoc_format_date_display')) {
    function pzdoc_format_date_display($value, string $empty = '-'): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '' || $value === '0000-00-00') {
            return $empty;
        }
        $ts = strtotime($value);
        return $ts ? date('d.m.Y', $ts) : $empty;
    }
}

if (!function_exists('pzdoc_format_time_display')) {
    function pzdoc_format_time_display($value, string $empty = '-'): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return $empty;
        }
        $ts = strtotime($value);
        return $ts ? date('H:i', $ts) : $empty;
    }
}

if (!function_exists('pzdoc_format_number_display')) {
    function pzdoc_format_number_display($value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            $value = 0;
        }
        $number = (float)$value;
        if ($decimals > 0 && abs($number - round($number)) < 0.00001) {
            $decimals = 0;
        }
        return number_format($number, $decimals, ',', '.');
    }
}

if (!function_exists('pzdoc_format_qty_display')) {
    function pzdoc_format_qty_display($value): string
    {
        $num = (float)($value ?? 0);
        $out = number_format($num, 3, ',', '.');
        $out = rtrim(rtrim($out, '0'), ',');
        return $out === '' ? '0' : $out;
    }
}

if (!function_exists('pzdoc_document_type_label')) {
    function pzdoc_document_type_label(string $type): string
    {
        $type = pzdoc_normalize_document_type($type);
        $map = [
            'oferta' => 'Oferta',
            'contract' => 'Contract',
            'proces_verbal' => 'Proces verbal',
        ];
        return $map[$type] ?? 'Document';
    }
}

if (!function_exists('pzdoc_status_label')) {
    function pzdoc_status_label(string $status): string
    {
        $map = [
            'draft' => 'Draft',
            'issued' => 'Emis',
            'cancelled' => 'Anulat',
        ];
        return $map[$status] ?? ucfirst($status);
    }
}

if (!function_exists('pzdoc_company_data')) {
    function pzdoc_company_data(PDO $pdo): array
    {
        if (function_exists('pz_company_settings')) {
            $s = pz_company_settings($pdo);
        } else {
            $s = [
                'company.logo_text' => 'EUROPREST',
                'company.display_name' => 'EUROPREST TEAM 98',
                'company.legal_name' => 'EUROPREST TEAM 98',
                'company.cui' => 'RO10135994',
                'company.reg_com' => '',
                'company.address' => '',
                'company.bank_name' => '',
                'company.bank_account' => '',
                'company.email' => '',
                'company.phone' => '',
                'company.website' => '',
                'company.legal_representative_name' => '',
                'company.legal_representative_role' => '',
                'company.authorizations' => '',
                'company.provider_role_label' => 'PRESTATOR',
            ];
        }

        return [
            'name' => trim((string)($s['company.display_name'] ?? '')) ?: trim((string)($s['company.legal_name'] ?? '')),
            'legal_name' => trim((string)($s['company.legal_name'] ?? '')),
            'cui' => trim((string)($s['company.cui'] ?? '')),
            'reg_com' => trim((string)($s['company.reg_com'] ?? '')),
            'address' => trim((string)($s['company.address'] ?? '')),
            'bank_name' => trim((string)($s['company.bank_name'] ?? '')),
            'bank_account' => trim((string)($s['company.bank_account'] ?? '')),
            'email' => trim((string)($s['company.email'] ?? '')),
            'phone' => trim((string)($s['company.phone'] ?? '')),
            'website' => trim((string)($s['company.website'] ?? '')),
            'representative_name' => trim((string)($s['company.legal_representative_name'] ?? '')),
            'representative_role' => trim((string)($s['company.legal_representative_role'] ?? '')),
            'authorizations' => trim((string)($s['company.authorizations'] ?? '')),
            'provider_role_label' => trim((string)($s['company.provider_role_label'] ?? '')) ?: 'PRESTATOR',
        ];
    }
}

if (!function_exists('pzdoc_join_nonempty')) {
    function pzdoc_join_nonempty(array $parts, string $glue = ', '): string
    {
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return implode($glue, $out);
    }
}

if (!function_exists('pzdoc_company_block_html')) {
    function pzdoc_company_block_html(array $company): string
    {
        // Bloc firma simplificat pentru oferte/documente.
        // Pastreaza doar informatiile esentiale, fara banca, website, reprezentant sau autorizatii.
        $lines = [];
        $name = $company['legal_name'] ?: $company['name'];
        if ($name !== '') {
            $lines[] = '<strong>' . pzdoc_h($name) . '</strong>';
        }
        $idLine = pzdoc_join_nonempty([$company['cui'] ?? '', $company['reg_com'] ?? ''], ' / ');
        if ($idLine !== '') {
            $lines[] = pzdoc_h($idLine);
        }
        if (($company['address'] ?? '') !== '') {
            $lines[] = pzdoc_h($company['address']);
        }
        $contactLine = pzdoc_join_nonempty([$company['email'] ?? '', $company['phone'] ?? ''], ' | ');
        if ($contactLine !== '') {
            $lines[] = pzdoc_h($contactLine);
        }

        if (!$lines) {
            return '-';
        }

        return '<div>' . implode('<br>', $lines) . '</div>';
    }
}

if (!function_exists('pzdoc_client_block_html')) {
    function pzdoc_client_block_html(array $document): string
    {
        $lines = [];
        if (trim((string)($document['client_name_snapshot'] ?? '')) !== '') {
            $lines[] = '<strong>' . pzdoc_h($document['client_name_snapshot']) . '</strong>';
        }
        $idLine = pzdoc_join_nonempty([
            $document['client_identifier_snapshot'] ?? '',
            $document['client_registry_snapshot'] ?? '',
        ], ' / ');
        if ($idLine !== '') {
            $lines[] = pzdoc_h($idLine);
        }
        if (trim((string)($document['client_address_snapshot'] ?? '')) !== '') {
            $lines[] = pzdoc_h($document['client_address_snapshot']);
        }
        if (trim((string)($document['client_representative_snapshot'] ?? '')) !== '') {
            $lines[] = 'Reprezentant: ' . pzdoc_h($document['client_representative_snapshot']);
        }
        $contactLine = pzdoc_join_nonempty([
            $document['client_email_snapshot'] ?? '',
            $document['client_phone_snapshot'] ?? '',
        ], ' | ');
        if ($contactLine !== '') {
            $lines[] = pzdoc_h($contactLine);
        }
        return $lines ? implode('<br>', $lines) : '-';
    }
}

if (!function_exists('pzdoc_location_block_html')) {
    function pzdoc_location_block_html(array $document): string
    {
        $lines = [];
        if (trim((string)($document['location_name_snapshot'] ?? '')) !== '') {
            $lines[] = '<strong>' . pzdoc_h($document['location_name_snapshot']) . '</strong>';
        }
        if (trim((string)($document['location_address_snapshot'] ?? '')) !== '') {
            $lines[] = pzdoc_h($document['location_address_snapshot']);
        }
        if (trim((string)($document['location_contact_snapshot'] ?? '')) !== '') {
            $lines[] = 'Contact: ' . pzdoc_h($document['location_contact_snapshot']);
        }
        if (trim((string)($document['location_phone_snapshot'] ?? '')) !== '') {
            $lines[] = 'Telefon: ' . pzdoc_h($document['location_phone_snapshot']);
        }
        return $lines ? implode('<br>', $lines) : '-';
    }
}


if (!function_exists('pzdoc_location_surface_text')) {
    function pzdoc_location_surface_text(PDO $pdo, array $document, array $payload = []): string
    {
        $surface = trim((string)($payload['surface_text'] ?? ''));
        if ($surface !== '') {
            return $surface;
        }

        $locationId = !empty($document['client_location_id']) ? (int)$document['client_location_id'] : 0;
        if ($locationId > 0 && pzdoc_table_exists($pdo, 'client_locations')) {
            try {
                $stmt = $pdo->prepare('SELECT surface_value, surface_unit FROM client_locations WHERE id = ? LIMIT 1');
                $stmt->execute([$locationId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $value = trim((string)($row['surface_value'] ?? ''));
                    $unit = trim((string)($row['surface_unit'] ?? ''));
                    if ($value !== '') {
                        return trim($value . ' ' . $unit);
                    }
                }
            } catch (Throwable $e) {
                error_log('Emma location surface token error: ' . $e->getMessage());
            }
        }

        return '';
    }
}

if (!function_exists('pzdoc_appointment_tokens')) {
    function pzdoc_appointment_tokens(PDO $pdo, array $document): array
    {
        $empty = [
            'programare_client' => '',
            'programare_reprezentant_client' => '',
            'programare_locație' => '',
            'programare_adresa_locație' => '',
            'programare_ora_inceput' => '',
            'programare_data' => '',
            'programare_reprezentant_locație' => '',
            'programare_telefon_locație' => '',
            'programare_suprafata_locație' => '',
            'appointment_client_name' => '',
            'appointment_client_representative' => '',
            'appointment_location_name' => '',
            'appointment_location_address' => '',
            'appointment_start_time' => '',
            'appointment_date' => '',
            'appointment_location_contact' => '',
            'appointment_location_phone' => '',
            'appointment_location_surface' => '',
        ];

        $appointmentId = !empty($document['appointment_id']) ? (int)$document['appointment_id'] : 0;
        if ($appointmentId <= 0 || !pzdoc_table_exists($pdo, 'appointments')) {
            return $empty;
        }

        try {
            $stmt = $pdo->prepare("\n                SELECT a.*,\n                       c.name AS client_name,\n                       c.legal_representative_name AS client_representative,\n                       l.location_name AS location_name,\n                       l.address AS location_address,\n                       l.contact_person AS location_contact,\n                       l.phone AS location_phone\n                FROM appointments a\n                LEFT JOIN clients c ON c.id = a.client_id\n                LEFT JOIN client_locations l ON l.id = a.client_location_id\n                WHERE a.id = ?\n                LIMIT 1\n            ");
            $stmt->execute([$appointmentId]);
            $a = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$a) {
                return $empty;
            }

            $date = $a['appointment_date'] ?? '';
            $time = $a['start_time'] ?? '';
            $clientName = $a['client_name'] ?? ($document['client_name_snapshot'] ?? '');
            $clientRep = $a['client_representative'] ?? ($document['client_representative_snapshot'] ?? '');
            $locName = $a['location_name'] ?? ($document['location_name_snapshot'] ?? '');
            $locAddress = $a['location_address'] ?? ($a['address'] ?? ($document['location_address_snapshot'] ?? ''));
            $locContact = $a['location_contact'] ?? ($a['contact_person'] ?? ($document['location_contact_snapshot'] ?? ''));
            $locPhone = $a['location_phone'] ?? ($a['contact_phone'] ?? ($document['location_phone_snapshot'] ?? ''));
            $locSurface = trim((string)($a['location_surface_value'] ?? ''));
            if ($locSurface !== '') {
                $locSurface = trim($locSurface . ' ' . trim((string)($a['location_surface_unit'] ?? '')));
            }

            return [
                'programare_client' => pzdoc_token_text($clientName, ''),
                'programare_reprezentant_client' => pzdoc_token_text($clientRep, ''),
                'programare_locație' => pzdoc_token_text($locName, ''),
                'programare_adresa_locație' => pzdoc_token_multiline($locAddress, ''),
                'programare_ora_inceput' => pzdoc_h(pzdoc_format_time_display($time, '')),
                'programare_data' => pzdoc_h(pzdoc_format_date_display($date, '')),
                'programare_reprezentant_locație' => pzdoc_token_text($locContact, ''),
                'programare_telefon_locație' => pzdoc_token_text($locPhone, ''),
                'programare_suprafata_locație' => pzdoc_token_text($locSurface, ''),
                'appointment_client_name' => pzdoc_token_text($clientName, ''),
                'appointment_client_representative' => pzdoc_token_text($clientRep, ''),
                'appointment_location_name' => pzdoc_token_text($locName, ''),
                'appointment_location_address' => pzdoc_token_multiline($locAddress, ''),
                'appointment_start_time' => pzdoc_h(pzdoc_format_time_display($time, '')),
                'appointment_date' => pzdoc_h(pzdoc_format_date_display($date, '')),
                'appointment_location_contact' => pzdoc_token_text($locContact, ''),
                'appointment_location_phone' => pzdoc_token_text($locPhone, ''),
                'appointment_location_surface' => pzdoc_token_text($locSurface, ''),
            ];
        } catch (Throwable $e) {
            error_log('Emma appointment token error: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('pzdoc_pv_service_choices')) {
    function pzdoc_pv_service_choices(): array
    {
        return [
            'dezinsectie' => 'DEZINSECTIE',
            'dezinfectie' => 'DEZINFECTIE',
            'deratizare' => 'DERATIZARE',
            'monitorizare' => 'MONITORIZARE',
        ];
    }
}

if (!function_exists('pzdoc_pv_service_key_from_text')) {
    function pzdoc_pv_service_key_from_text(string $text): string
    {
        $text = strtolower(trim($text));
        $text = str_replace(['ă','â','î','ș','ş','ț','ţ'], ['a','a','i','s','s','t','t'], $text);
        if ($text === '') {
            return '';
        }
        if (strpos($text, 'dezinsect') !== false || strpos($text, 'gandac') !== false || strpos($text, 'plosnit') !== false || strpos($text, 'puric') !== false || strpos($text, 'mus') !== false || strpos($text, 'tantar') !== false || strpos($text, 'viesp') !== false) {
            return 'dezinsectie';
        }
        if (strpos($text, 'dezinfect') !== false) {
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
}

if (!function_exists('pzdoc_pv_selected_services')) {
    function pzdoc_pv_selected_services(array $document): array
    {
        $choices = pzdoc_pv_service_choices();
        $payload = is_array($document['payload'] ?? null) ? $document['payload'] : pzdoc_json_decode($document['payload_json'] ?? null);
        $selected = [];

        $payloadServices = $payload['pv_services'] ?? [];
        if (!is_array($payloadServices)) {
            $payloadServices = [$payloadServices];
        }
        foreach ($payloadServices as $raw) {
            $raw = is_scalar($raw) ? (string)$raw : '';
            $key = array_key_exists($raw, $choices) ? $raw : pzdoc_pv_service_key_from_text($raw);
            if ($key !== '' && isset($choices[$key]) && !in_array($key, $selected, true)) {
                $selected[] = $key;
            }
        }

        if (!$selected) {
            $items = is_array($document['items'] ?? null) ? $document['items'] : [];
            foreach ($items as $item) {
                $key = pzdoc_pv_service_key_from_text((string)($item['service_name'] ?? ''));
                if ($key !== '' && isset($choices[$key]) && !in_array($key, $selected, true)) {
                    $selected[] = $key;
                }
            }
        }

        return $selected;
    }
}

if (!function_exists('pzdoc_services_checks_html')) {
    function pzdoc_services_checks_html(array $document): string
    {
        $choices = pzdoc_pv_service_choices();
        $selected = pzdoc_pv_selected_services($document);

        $html = '<div class="pzdoc-service-checks" style="width:100%; margin:6px 0 8px 0; font-size:11px; line-height:1.25;">';
        $html .= '<div style="font-weight:800; margin-bottom:5px; text-transform:uppercase;">TRATAMENT EFECTUAT:</div>';
        $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse; width:100%; table-layout:fixed;">';
        $html .= '<tr>';

        foreach ($choices as $key => $label) {
            $isChecked = in_array($key, $selected, true);
            $boxStyle = 'display:inline-block; width:15px; height:15px; line-height:14px; text-align:center; border:1.6px solid #111; font-size:12px; font-weight:900; margin-right:5px; vertical-align:middle; box-sizing:border-box;';
            $box = '<span style="' . $boxStyle . '">' . ($isChecked ? '&#10003;' : '&nbsp;') . '</span>';
            $html .= '<td style="border:1px solid #333; padding:5px 6px; text-align:center; font-weight:800; white-space:nowrap; vertical-align:middle;">';
            $html .= $box . '<span style="vertical-align:middle;">' . pzdoc_h($label) . '</span>';
            $html .= '</td>';
        }

        $html .= '</tr>';
        $html .= '</table>';
        $html .= '</div>';
        return $html;
    }
}


if (!function_exists('pzdoc_pv_service_match_label')) {
    function pzdoc_pv_service_match_label(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $normalized = strtolower($value);
        $normalized = strtr($normalized, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't',
        ]);

        if (strpos($normalized, 'dezinsect') !== false) {
            return 'DEZINSECTIE';
        }
        if (strpos($normalized, 'dezinfect') !== false || strpos($normalized, 'dezinfectie') !== false) {
            return 'DEZINFECTIE';
        }
        if (strpos($normalized, 'derat') !== false) {
            return 'DERATIZARE';
        }
        if (strpos($normalized, 'monitor') !== false) {
            return 'MONITORIZARE';
        }

        return strtoupper($value);
    }
}

if (!function_exists('pzdoc_pv_collect_service_labels')) {
    function pzdoc_pv_collect_service_labels(array $document): array
    {
        $labels = [];
        $order = ['DEZINSECTIE', 'DEZINFECTIE', 'DERATIZARE', 'MONITORIZARE'];

        $add = function ($value) use (&$labels) {
            $label = pzdoc_pv_service_match_label((string)$value);
            if ($label !== null && $label !== '') {
                $labels[$label] = true;
            }
        };

        $items = is_array($document['items'] ?? null) ? $document['items'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $add($item['service_name'] ?? '');
            $add($item['description'] ?? '');
        }

        $payload = $document['payload'] ?? [];
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (is_array($payload)) {
            foreach (['services', 'selected_services', 'pv_services', 'service_types', 'services_selected'] as $key) {
                if (!isset($payload[$key])) {
                    continue;
                }
                $values = is_array($payload[$key]) ? $payload[$key] : explode(',', (string)$payload[$key]);
                foreach ($values as $value) {
                    $add($value);
                }
            }
        }

        $out = [];
        foreach ($order as $label) {
            if (isset($labels[$label])) {
                $out[] = $label;
                unset($labels[$label]);
            }
        }
        foreach (array_keys($labels) as $label) {
            if (!in_array($label, $out, true)) {
                $out[] = $label;
            }
        }

        return $out;
    }
}

if (!function_exists('pzdoc_pv_services_badges_html')) {
    function pzdoc_pv_services_badges_html(array $document): string
    {
        $labels = pzdoc_pv_collect_service_labels($document);
        if (!$labels) {
            return '<span>-</span>';
        }

        $html = '<span class="pv-services-badges" style="display:block; margin:2px 0 6px 0;">';
        foreach ($labels as $label) {
            $html .= '<span style="display:inline-block; border:1px solid #111827; border-radius:3px; padding:2px 8px; margin:0 4px 4px 0; font-weight:bold; font-size:9.2pt; line-height:1.25; white-space:nowrap;">' . pzdoc_h($label) . '</span>';
        }
        $html .= '</span>';

        return $html;
    }
}

if (!function_exists('pzdoc_items_table_html')) {
    function pzdoc_items_table_html(array $document): string
    {
        $items = is_array($document['items'] ?? null) ? $document['items'] : [];
        if (!$items) {
            return '<p>-</p>';
        }

        $type = pzdoc_normalize_document_type((string)($document['document_type'] ?? ''));

        if ($type === 'proces_verbal') {
            return pzdoc_services_checks_html($document);
        }

        if ($type === 'oferta') {
            $currency = trim((string)($document['currency'] ?? 'RON')) ?: 'RON';
            $html = '<table class="pzdoc-table pzdoc-items-table pzdoc-offer-items-table" width="100%" cellspacing="0" cellpadding="0">';
            $html .= '<thead><tr>';
            $html .= '<th style="width:8%;">Nr. crt.</th>';
            $html .= '<th>Denumire</th>';
            $html .= '<th style="width:12%;">Cant.</th>';
            $html .= '<th style="width:10%;">U.M.</th>';
            $html .= '<th style="width:16%;">Pret unitar</th>';
            $html .= '<th style="width:18%;">Valoare totala</th>';
            $html .= '</tr></thead><tbody>';

            $i = 1;
            foreach ($items as $item) {
                $qty = pzdoc_format_qty_display($item['quantity'] ?? 0);
                $qtyRaw = (float)($item['quantity'] ?? 1);
                $unitPriceRaw = (float)($item['unit_price'] ?? 0);
                $lineTotalRaw = round($qtyRaw * $unitPriceRaw, 2);
                $unit = trim((string)($item['unit'] ?? ''));
                $description = trim((string)($item['description'] ?? ''));

                $html .= '<tr>';
                $html .= '<td class="center">' . (int)$i . '</td>';
                $html .= '<td><strong>' . pzdoc_token_text($item['service_name'] ?? '-') . '</strong></td>';
                $html .= '<td class="right">' . pzdoc_h($qty !== '' ? $qty : '-') . '</td>';
                $html .= '<td class="center">' . pzdoc_h($unit !== '' ? $unit : '-') . '</td>';
                $html .= '<td class="right">' . pzdoc_format_number_display($item['unit_price'] ?? 0) . ' ' . pzdoc_h($currency) . '</td>';
                $html .= '<td class="right">' . pzdoc_format_number_display($lineTotalRaw) . ' ' . pzdoc_h($currency) . '</td>';
                $html .= '</tr>';

                if ($description !== '') {
                    $html .= '<tr class="pzdoc-offer-description-row">';
                    $html .= '<td></td><td colspan="5" style="font-size:9.2pt; color:#374151; line-height:1.35;">' . pzdoc_token_multiline($description) . '</td>';
                    $html .= '</tr>';
                }
                $i++;
            }

            $html .= '</tbody></table>';
            return $html;
        }

        $showMoney = in_array($type, ['contract'], true);

        $html = '<table class="pzdoc-table pzdoc-items-table" width="100%" cellspacing="0" cellpadding="0">';
        $html .= '<thead><tr>';
        $html .= '<th>Nr.</th><th>Serviciu</th><th>Locație</th><th>Detalii</th><th>Cant.</th>';
        if ($type === 'contract') {
            $html .= '<th>Frecvență</th>';
        }
        if ($showMoney) {
            $html .= '<th>Pret unitar</th><th>Total</th>';
        }
        $html .= '</tr></thead><tbody>';

        $i = 1;
        foreach ($items as $item) {
            $qty = pzdoc_format_qty_display($item['quantity'] ?? 0);
            $unitPriceRaw = (float)($item['unit_price'] ?? 0);
            $lineTotalRaw = $type === 'contract' ? $unitPriceRaw : (float)($item['total_price'] ?? 0);
            $unit = trim((string)($item['unit'] ?? ''));
            $qtyText = trim($qty . ' ' . $unit);
            $locationParts = [];
            if (trim((string)($item['location_name'] ?? '')) !== '') {
                $locationParts[] = '<strong>' . pzdoc_h($item['location_name']) . '</strong>';
            }
            if (trim((string)($item['location_address'] ?? '')) !== '') {
                $locationParts[] = pzdoc_h($item['location_address']);
            }
            $locationHtml = $locationParts ? implode('<br>', $locationParts) : '-';

            $html .= '<tr>';
            $html .= '<td class="center">' . (int)$i . '</td>';
            $html .= '<td>' . pzdoc_token_text($item['service_name'] ?? '-') . '</td>';
            $html .= '<td>' . $locationHtml . '</td>';
            $html .= '<td>' . pzdoc_token_multiline($item['description'] ?? '') . '</td>';
            $html .= '<td class="right">' . pzdoc_h($qtyText !== '' ? $qtyText : '-') . '</td>';
            if ($type === 'contract') {
                $html .= '<td>' . pzdoc_token_text($item['frequency_text'] ?? '') . '</td>';
            }
            if ($showMoney) {
                $currency = trim((string)($item['currency'] ?? $document['currency'] ?? 'RON'));
                $html .= '<td class="right">' . pzdoc_format_number_display($item['unit_price'] ?? 0) . ' ' . pzdoc_h($currency) . '</td>';
                $html .= '<td class="right">' . pzdoc_format_number_display($lineTotalRaw) . ' ' . pzdoc_h($currency) . '</td>';
            }
            $html .= '</tr>';
            $i++;
        }

        $html .= '</tbody></table>';
        return $html;
    }
}


if (!function_exists('pzdoc_pv_services_brackets_html')) {
    function pzdoc_pv_services_brackets_html(array $document): string
    {
        $labels = [
            'dezinsectie' => 'DEZINSECTIE',
            'dezinfectie' => 'DEZINFECTIE',
            'deratizare' => 'DERATIZARE',
            'monitorizare' => 'MONITORIZARE',
        ];

        $selected = [];

        $normalize = static function ($text): string {
            $text = strtoupper(trim((string)$text));
            $map = [
                'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
                'ă' => 'A', 'â' => 'A', 'î' => 'I', 'ș' => 'S', 'ş' => 'S', 'ț' => 'T', 'ţ' => 'T',
            ];
            $text = strtr($text, $map);
            return preg_replace('/\s+/', ' ', $text) ?: '';
        };

        $addFromText = static function ($text) use (&$selected, $normalize): void {
            $n = $normalize($text);
            if ($n === '') {
                return;
            }
            if (strpos($n, 'DEZINSECT') !== false) {
                $selected['dezinsectie'] = true;
            }
            if (strpos($n, 'DEZINFECT') !== false) {
                $selected['dezinfectie'] = true;
            }
            if (strpos($n, 'DERATIZ') !== false) {
                $selected['deratizare'] = true;
            }
            if (strpos($n, 'MONITORIZ') !== false || strpos($n, 'MONITOR') !== false) {
                $selected['monitorizare'] = true;
            }
        };

        $payload = [];
        if (is_array($document['payload'] ?? null)) {
            $payload = $document['payload'];
        } elseif (function_exists('pzdoc_json_decode')) {
            $payload = pzdoc_json_decode($document['payload_json'] ?? null);
        } elseif (!empty($document['payload_json'])) {
            $tmp = json_decode((string)$document['payload_json'], true);
            $payload = is_array($tmp) ? $tmp : [];
        }

        $scanValue = static function ($value) use (&$scanValue, $addFromText): void {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $scanValue($v);
                }
                return;
            }
            $addFromText($value);
        };

        foreach ([
            'selected_services', 'services_selected', 'pv_services', 'services', 'service_types',
            'treatments', 'tratament', 'tratamente', 'service_name', 'service'
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                $scanValue($payload[$key]);
            }
        }

        $items = is_array($document['items'] ?? null) ? $document['items'] : [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $addFromText($item['service_name'] ?? '');
                $addFromText($item['description'] ?? '');
            }
        }

        $parts = [];
        foreach ($labels as $key => $label) {
            if (!empty($selected[$key])) {
                $parts[] = '<span style="white-space:nowrap;">[' . pzdoc_h($label) . ']</span>';
            }
        }

        return $parts ? implode(' ', $parts) : '-';
    }
}

if (!function_exists('pzdoc_services_table_or_pv_html')) {
    function pzdoc_services_table_or_pv_html(array $document): string
    {
        $type = function_exists('pzdoc_normalize_document_type')
            ? pzdoc_normalize_document_type((string)($document['document_type'] ?? ''))
            : (string)($document['document_type'] ?? '');

        if ($type === 'proces_verbal') {
            return pzdoc_pv_services_brackets_html($document);
        }

        return function_exists('pzdoc_items_table_html') ? pzdoc_items_table_html($document) : '-';
    }
}
if (!function_exists('pzdoc_materials_table_html')) {
    function pzdoc_materials_table_html(array $document): string
    {
        $materials = is_array($document['materials'] ?? null) ? $document['materials'] : [];
        if (!$materials) {
            return '<p>-</p>';
        }

        $type = pzdoc_normalize_document_type((string)($document['document_type'] ?? ''));
        $payload = is_array($document['payload'] ?? null) ? $document['payload'] : [];
        if (!$payload && !empty($document['payload_json'])) {
            $decodedPayload = json_decode((string)$document['payload_json'], true);
            if (is_array($decodedPayload)) {
                $payload = $decodedPayload;
            }
        }
        $deferredConsumption = (($payload['stock_consumption_deferred'] ?? '') === '1');

        if ($type === 'proces_verbal') {
            $tableStyle = 'font-size:7.6pt;line-height:1.12;margin:4px 0 8px 0;table-layout:auto;';
            $thStyle = 'font-size:7.3pt;line-height:1.08;padding:3px 5px;text-align:center;vertical-align:middle;white-space:nowrap;';
            $tdStyle = 'font-size:7.5pt;line-height:1.12;padding:3px 5px;text-align:center;vertical-align:middle;white-space:normal;overflow-wrap:break-word;';
            $tdCompactStyle = 'font-size:7.5pt;line-height:1.12;padding:3px 5px;text-align:center;vertical-align:middle;white-space:nowrap;';
            $html = '<table class="pzdoc-table pzdoc-materials-table" width="100%" cellspacing="0" cellpadding="0" style="' . $tableStyle . '">';
            $html .= '<thead><tr>';
            $html .= '<th style="' . $thStyle . '">DENUMIRE</th>';
            $html .= '<th style="' . $thStyle . '">NR. AVIZ</th>';
            $html .= '<th style="' . $thStyle . '">LOT</th>';
            $html .= '<th style="' . $thStyle . '">VALABILITATE</th>';
            $html .= '<th style="' . $thStyle . '">DILUTIE %</th>';
            $html .= '<th style="' . $thStyle . '">CANTITATE</th>';
            $html .= '<th style="' . $thStyle . '">UM</th>';
            $html .= '<th style="' . $thStyle . '">APLICARE</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($materials as $m) {
                $productGroup = trim((string)($m['product_group'] ?? ''));
                $isMaterial = ($productGroup === 'materiale');
                $method = '';
                if (!$deferredConsumption) {
                    $method = trim((string)($m['application_method'] ?? ''));
                    $methodCustom = trim((string)($m['application_method_custom'] ?? ''));
                    if ($methodCustom !== '') {
                        $method = $methodCustom;
                    }
                }
                $concentration = $deferredConsumption ? '' : (string)($m['work_concentration'] ?? '');
                $rawQty = $m['quantity'] ?? null;
                $qtyText = ($deferredConsumption || $rawQty === null || $rawQty === '') ? '' : pzdoc_format_qty_display($rawQty);
                $unitText = $deferredConsumption ? '' : trim((string)($m['unit'] ?? ''));
                $lotText = trim((string)($m['lot_number'] ?? ''));
                $expiryText = pzdoc_format_date_display($m['expiry_date'] ?? null, '');
                if ($isMaterial && $lotText === '') {
                    $lotText = '-';
                }
                if ($isMaterial && trim((string)$expiryText) === '') {
                    $expiryText = '-';
                }
                if ($isMaterial && trim((string)$concentration) === '') {
                    $concentration = '-';
                }

                $html .= '<tr>';
                $html .= '<td style="' . $tdStyle . '">' . pzdoc_token_text($m['material_name'] ?? '-') . '</td>';
                $html .= '<td style="' . $tdCompactStyle . '">' . pzdoc_token_text($m['aviz_no'] ?? '') . '</td>';
                $html .= '<td style="' . $tdCompactStyle . '">' . pzdoc_token_text($lotText, '') . '</td>';
                $html .= '<td style="' . $tdCompactStyle . '">' . pzdoc_h($expiryText) . '</td>';
                $html .= '<td style="' . $tdCompactStyle . '">' . pzdoc_token_text($concentration, '') . '</td>';
                $html .= '<td style="' . $tdCompactStyle . '">' . pzdoc_h($qtyText) . '</td>';
                $html .= '<td style="' . $tdCompactStyle . '">' . pzdoc_h($unitText) . '</td>';
                $html .= '<td style="' . $tdCompactStyle . '">' . pzdoc_token_text($method, '') . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            return $html;
        }

        $html = '<table class="pzdoc-table pzdoc-materials-table" width="100%" cellspacing="0" cellpadding="0">';
        $html .= '<thead><tr>';
        $html .= '<th>Nr.</th><th>Produs / material</th><th>Cant.</th><th>Lot</th><th>Valabilitate</th><th>Metoda</th><th>Zona</th><th>Observații</th>';
        $html .= '</tr></thead><tbody>';

        $i = 1;
        foreach ($materials as $m) {
            $qty = pzdoc_format_qty_display($m['quantity'] ?? 0);
            $unit = trim((string)($m['unit'] ?? ''));
            $qtyText = trim($qty . ' ' . $unit);
            $method = trim((string)($m['application_method_custom'] ?? ''));
            if ($method === '') {
                $method = trim((string)($m['application_method'] ?? ''));
            }

            $productParts = [];
            $productParts[] = '<strong>' . pzdoc_token_text($m['material_name'] ?? '-') . '</strong>';
            if (trim((string)($m['aviz_no'] ?? '')) !== '') {
                $productParts[] = 'Aviz: ' . pzdoc_h($m['aviz_no']);
            }
            if (trim((string)($m['work_concentration'] ?? '')) !== '') {
                $productParts[] = 'Concentratie: ' . pzdoc_h($m['work_concentration']);
            }

            $html .= '<tr>';
            $html .= '<td class="center">' . (int)$i . '</td>';
            $html .= '<td>' . implode('<br>', $productParts) . '</td>';
            $html .= '<td class="right">' . pzdoc_h($qtyText !== '' ? $qtyText : '-') . '</td>';
            $html .= '<td>' . pzdoc_token_text($m['lot_number'] ?? '') . '</td>';
            $html .= '<td>' . pzdoc_h(pzdoc_format_date_display($m['expiry_date'] ?? null)) . '</td>';
            $html .= '<td>' . pzdoc_token_text($method) . '</td>';
            $html .= '<td>' . pzdoc_token_text($m['application_area'] ?? '') . '</td>';
            $html .= '<td>' . pzdoc_token_multiline($m['notes'] ?? '') . '</td>';
            $html .= '</tr>';
            $i++;
        }

        $html .= '</tbody></table>';
        return $html;
    }
}

if (!function_exists('pzdoc_materials_safety_html')) {
    function pzdoc_materials_safety_html(array $document): string
    {
        $materials = is_array($document['materials'] ?? null) ? $document['materials'] : [];
        $lines = [];
        foreach ($materials as $m) {
            $text = trim((string)($m['safety_measures'] ?? ''));
            if ($text === '') {
                continue;
            }
            $name = trim((string)($m['material_name'] ?? 'Produs'));
            $lines[] = '<p><strong>' . pzdoc_h($name) . ':</strong><br>' . pzdoc_token_multiline($text) . '</p>';
        }
        return $lines ? implode('', $lines) : '<p>-</p>';
    }
}


if (!function_exists('pzdoc_contract_number_text')) {
    function pzdoc_contract_number_text(PDO $pdo, array $document, array $payload = []): string
    {
        $fromPayload = trim((string)($payload['contract_number'] ?? ''));
        if ($fromPayload !== '') {
            return $fromPayload;
        }

        // Pentru documentul de tip contract, numarul contractului este chiar numarul documentului emis.
        $type = function_exists('pzdoc_normalize_document_type')
            ? pzdoc_normalize_document_type((string)($document['document_type'] ?? ''))
            : (string)($document['document_type'] ?? '');
        if ($type === 'contract') {
            $number = trim((string)($document['document_number'] ?? ''));
            return $number !== '' ? $number : 'Draft';
        }

        // Pentru PV-uri sau alte documente legate de un contract, cauta contractul sursa.
        $contractId = (int)($document['contract_id'] ?? 0);
        if ($contractId > 0 && function_exists('pzdoc_table_exists') && pzdoc_table_exists($pdo, 'documents')) {
            try {
                $stmt = $pdo->prepare("SELECT document_number FROM documents WHERE id = ? AND document_type = 'contract' LIMIT 1");
                $stmt->execute([$contractId]);
                $number = trim((string)($stmt->fetchColumn() ?: ''));
                if ($number !== '') {
                    return $number;
                }
            } catch (Throwable $e) {
                error_log('Emma contract number token error: ' . $e->getMessage());
            }
        }

        return 'nota de comanda';
    }
}

if (!function_exists('pzdoc_basis_document_text')) {
    function pzdoc_basis_document_text(PDO $pdo, array $document, array $payload = []): string
    {
        $direct = trim((string)($payload['basis_document'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $manual = trim((string)($payload['basis_manual_text'] ?? ''));
        if ($manual !== '') {
            return $manual;
        }

        $number = pzdoc_contract_number_text($pdo, $document, $payload);
        $type = trim((string)($payload['basis_type'] ?? ''));
        $norm = function_exists('mb_strtolower') ? mb_strtolower($number, 'UTF-8') : strtolower($number);

        if ($type === 'contract' && $number !== '' && $norm !== 'nota de comanda' && $norm !== 'nota comanda') {
            return 'Contract nr. ' . $number;
        }
        if ($type === 'achizitie_directa') {
            return 'Achizitie directa';
        }
        if ($type === 'manual') {
            return 'Alta baza';
        }

        return $number !== '' ? $number : 'Nota de comanda';
    }
}


if (!function_exists('pzdoc_client_representative_role_text')) {
    function pzdoc_client_representative_role_text(PDO $pdo, array $document, array $payload = []): string
    {
        $fromPayload = trim((string)($payload['client_representative_role'] ?? ''));
        if ($fromPayload !== '') {
            return $fromPayload;
        }

        $clientId = (int)($document['client_id'] ?? 0);
        if ($clientId <= 0 || !function_exists('pzdoc_table_exists') || !pzdoc_table_exists($pdo, 'clients')) {
            return '';
        }

        try {
            $stmt = $pdo->prepare('SELECT legal_representative_role FROM clients WHERE id = ? LIMIT 1');
            $stmt->execute([$clientId]);
            return trim((string)($stmt->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            error_log('Emma client representative role token error: ' . $e->getMessage());
            return '';
        }
    }
}


if (!function_exists('pzdoc_payment_due_days_value')) {
    /**
     * Returneaza numarul de zile pentru termenul de plata.
     * Prioritate:
     * 1) payload numeric: payment_due_days / termen_plata_zile / payment_days / due_days
     * 2) primul numar gasit in payment_terms, ex: "Plata in 15 zile"
     * 3) fallback 5 zile
     */
    function pzdoc_payment_due_days_value(array $payload, int $default = 5): int
    {
        $keys = ['payment_due_days', 'termen_plata_zile', 'payment_days', 'due_days'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $value = (int)$payload[$key];
                if ($value > 0) {
                    return $value;
                }
            }
        }

        $paymentTerms = trim((string)($payload['payment_terms'] ?? ''));
        if ($paymentTerms !== '' && preg_match('/\b(\d{1,3})\b/u', $paymentTerms, $m)) {
            $value = (int)$m[1];
            if ($value > 0) {
                return $value;
            }
        }

        return max(1, $default);
    }
}

if (!function_exists('pzdoc_contract_item_surface_text')) {
    function pzdoc_contract_item_surface_text(array $item, string $fallbackSurface = ''): string
    {
        $unit = trim((string)($item['unit'] ?? ''));
        $qty = pzdoc_format_qty_display($item['quantity'] ?? 0);
        $unitNorm = strtolower(str_replace([' ', '.', '²'], ['', '', '2'], $unit));

        if (in_array($unitNorm, ['mp', 'm2', 'metrupatrat', 'metripatrati'], true)) {
            return $qty;
        }

        $fallbackSurface = trim($fallbackSurface);
        if ($fallbackSurface !== '') {
            return $fallbackSurface;
        }

        return trim($qty . ($unit !== '' ? ' ' . $unit : ''));
    }
}

if (!function_exists('pzdoc_contract_services_table_html')) {
    function pzdoc_contract_services_table_html(array $document): string
    {
        $items = is_array($document['items'] ?? null) ? $document['items'] : [];
        if (!$items) {
            // Fallback pentru contracte „standard" / de execuție: nu au tabel de
            // servicii, dar utilizatorul a completat obiectul contractului într-un
            // câmp text. Dacă există, îl randăm aici ca textul să apară în loc de
            // liniuță. Sursa principală: payload.contract_object. Backup: notes
            // (vezi contracts.php — pentru tipul „execution" textul ajunge și acolo).
            $payload = is_array($document['payload'] ?? null) ? $document['payload'] : [];
            $contractObject = trim((string)($payload['contract_object'] ?? ''));
            if ($contractObject === '') {
                $contractObject = trim((string)($document['notes'] ?? ''));
            }
            if ($contractObject !== '') {
                return '<div class="pzdoc-contract-object">'
                     . pzdoc_token_multiline($contractObject)
                     . '</div>';
            }
            return '<p>-</p>';
        }

        $serviceItems = [];
        $manualItems = [];
        foreach ($items as $item) {
            if (($item['item_type'] ?? '') === 'contract_manual') {
                $manualItems[] = $item;
            } else {
                $serviceItems[] = $item;
            }
        }

        $currency = trim((string)($document['currency'] ?? 'RON')) ?: 'RON';
        $html = '';

        $i = 1;
        if ($serviceItems) {
            $html .= '<p style="margin:10px 0 5px 0;"><strong>Servicii recurente</strong></p>';
            $html .= '<table class="pzdoc-table pzdoc-contract-services-table" width="100%" cellspacing="0" cellpadding="0">';
            $html .= '<thead><tr>';
            $html .= '<th style="width:5%;">Nr.</th>';
            $html .= '<th style="width:14%;">Locație</th>';
            $html .= '<th style="width:25%;">Adresa</th>';
            $html .= '<th style="width:28%;">Serviciu contractat</th>';
            $html .= '<th style="width:7%;">m.p.</th>';
            $html .= '<th style="width:9%;">Frecvență</th>';
            $html .= '<th style="width:12%;">Preț / intervenție</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($serviceItems as $item) {
                $locationName = trim((string)($item['location_name'] ?? ''));
                if ($locationName === '') {
                    $locationName = trim((string)($document['location_name_snapshot'] ?? ''));
                }
                $locationAddress = trim((string)($item['location_address'] ?? ''));
                if ($locationAddress === '') {
                    $locationAddress = trim((string)($document['location_address_snapshot'] ?? ''));
                }
                $surface = pzdoc_contract_item_surface_text($item, trim((string)($document['location_surface'] ?? '')));
                $price = pzdoc_format_number_display($item['unit_price'] ?? 0) . ' ' . $currency;

                $html .= '<tr>';
                $html .= '<td class="center pzdoc-nowrap">' . (int)$i . '</td>';
                $html .= '<td class="center">' . pzdoc_token_text($locationName) . '</td>';
                $html .= '<td class="center">' . pzdoc_token_multiline($locationAddress) . '</td>';
                $html .= '<td class="center">' . pzdoc_token_text($item['service_name'] ?? '') . '</td>';
                $html .= '<td class="center pzdoc-nowrap">' . pzdoc_token_text($surface) . '</td>';
                $html .= '<td class="center pzdoc-nowrap">' . pzdoc_token_text($item['frequency_text'] ?? '') . '</td>';
                $html .= '<td class="center pzdoc-nowrap">' . pzdoc_h($price) . '</td>';
                $html .= '</tr>';

                $description = trim((string)($item['description'] ?? ''));
                if ($description !== '') {
                    $html .= '<tr>';
                    $html .= '<td></td><td colspan="6"><strong>Descriere:</strong> ' . pzdoc_token_multiline($description) . '</td>';
                    $html .= '</tr>';
                }

                $i++;
            }

            $html .= '</tbody></table>';
        }

        if ($manualItems) {
            $html .= '<p style="margin:10px 0 5px 0;"><strong>Servicii / materiale suplimentare</strong></p>';
            $html .= '<table class="pzdoc-table pzdoc-contract-services-table" width="100%" cellspacing="0" cellpadding="0">';
            $html .= '<thead><tr>';
            $html .= '<th style="width:6%;">Nr.</th>';
            $html .= '<th style="width:50%;">Produs / serviciu</th>';
            $html .= '<th style="width:7%;">U.M.</th>';
            $html .= '<th style="width:9%;">Cant.</th>';
            $html .= '<th style="width:14%;">Preț unitar</th>';
            $html .= '<th style="width:14%;">Valoare totală</th>';
            $html .= '</tr></thead><tbody>';

            $manualIndex = 1;
            foreach ($manualItems as $item) {
                $qtyRaw = pzdoc_decimal($item['quantity'] ?? 0, 0);
                $qty = pzdoc_format_qty_display($qtyRaw);
                $unit = trim((string)($item['unit'] ?? ''));
                $unitPriceRaw = pzdoc_decimal($item['unit_price'] ?? 0, 0);
                $unitPrice = pzdoc_format_number_display($unitPriceRaw) . ' ' . $currency;
                $total = pzdoc_format_number_display($qtyRaw * $unitPriceRaw) . ' ' . $currency;
                $description = trim((string)($item['description'] ?? ''));
                $serviceHtml = '<strong>' . pzdoc_token_text($item['service_name'] ?? '') . '</strong>';
                if ($description !== '') {
                    $serviceHtml .= '<br><span style="font-size:9.2pt;color:#374151;">' . pzdoc_token_multiline($description) . '</span>';
                }

                $html .= '<tr>';
                $html .= '<td class="center pzdoc-nowrap">S' . (int)$manualIndex . '</td>';
                $html .= '<td class="center">' . $serviceHtml . '</td>';
                $html .= '<td class="center pzdoc-nowrap">' . pzdoc_h($unit !== '' ? $unit : '-') . '</td>';
                $html .= '<td class="center pzdoc-nowrap">' . pzdoc_h($qty !== '' ? $qty : '-') . '</td>';
                $html .= '<td class="center pzdoc-nowrap">' . pzdoc_h($unitPrice) . '</td>';
                $html .= '<td class="center pzdoc-nowrap">' . pzdoc_h($total) . '</td>';
                $html .= '</tr>';
                $manualIndex++;
            }

            $html .= '</tbody></table>';
        }

        return $html;
    }
}

if (!function_exists('pzdoc_contract_first_item_tokens')) {
    function pzdoc_contract_first_item_tokens(array $document): array
    {
        $items = is_array($document['items'] ?? null) ? $document['items'] : [];
        $item = $items[0] ?? [];
        if (!is_array($item)) {
            $item = [];
        }
        $currency = trim((string)($document['currency'] ?? 'RON')) ?: 'RON';

        $locationName = trim((string)($item['location_name'] ?? ''));
        if ($locationName === '') {
            $locationName = trim((string)($document['location_name_snapshot'] ?? ''));
        }
        $locationAddress = trim((string)($item['location_address'] ?? ''));
        if ($locationAddress === '') {
            $locationAddress = trim((string)($document['location_address_snapshot'] ?? ''));
        }

        return [
            'row_no' => $items ? '1' : '',
            'service_name' => pzdoc_token_text($item['service_name'] ?? '', ''),
            'surface' => pzdoc_token_text(pzdoc_contract_item_surface_text($item, trim((string)($document['location_surface'] ?? ''))), ''),
            'frequency' => pzdoc_token_text($item['frequency_text'] ?? '', ''),
            'price' => $items ? pzdoc_format_number_display($item['unit_price'] ?? 0) : '',
            'price_with_currency' => $items ? pzdoc_format_number_display($item['unit_price'] ?? 0) . ' ' . pzdoc_h($currency) : '',
        ];
    }
}


if (!function_exists('pzdoc_signature_safe_relative_path')) {
    function pzdoc_signature_safe_relative_path($path): string
    {
        $path = str_replace('\\', '/', trim((string)($path ?? '')));
        $path = ltrim($path, '/');
        if ($path === '' || strpos($path, '..') !== false || preg_match('#(^|/)[.](/|$)#', $path)) {
            return '';
        }
        return $path;
    }
}

if (!function_exists('pzdoc_signature_payload')) {
    function pzdoc_signature_payload(array $document): array
    {
        if (is_array($document['payload'] ?? null)) {
            return $document['payload'];
        }
        if (function_exists('pzdoc_json_decode')) {
            return pzdoc_json_decode($document['payload_json'] ?? null);
        }
        $decoded = json_decode((string)($document['payload_json'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('pzdoc_document_signature_path')) {
    function pzdoc_document_signature_path(array $document): string
    {
        $payload = pzdoc_signature_payload($document);
        $path = pzdoc_signature_safe_relative_path($payload['client_signature_path'] ?? '');
        if ($path === '') {
            return '';
        }
        $absolutePath = __DIR__ . '/' . $path;
        return is_file($absolutePath) && is_readable($absolutePath) ? $path : '';
    }
}

if (!function_exists('pzdoc_document_has_client_signature')) {
    function pzdoc_document_has_client_signature(array $document): bool
    {
        return pzdoc_document_signature_path($document) !== '';
    }
}

if (!function_exists('pzdoc_client_signature_html')) {
    function pzdoc_client_signature_html(array $document): string
    {
        $path = pzdoc_document_signature_path($document);
        if ($path === '') {
            return '<span class="pzdoc-signature-line">________________________</span>';
        }

        $absolutePath = __DIR__ . '/' . $path;
        $data = @file_get_contents($absolutePath);
        if ($data === false || $data === '') {
            return '<span class="pzdoc-signature-line">________________________</span>';
        }

        $src = 'data:image/png;base64,' . base64_encode($data);
        // Container patrat 38mm x 38mm pentru documente/PDF.
        return '<span class="pzdoc-client-signature-box" style="display:inline-block;width:38mm;height:38mm;line-height:38mm;text-align:center;vertical-align:middle;">'
            . '<img class="pzdoc-client-signature" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" style="max-width:38mm;max-height:38mm;vertical-align:middle;" alt="Semnătura beneficiar">'
            . '</span>';
    }
}

if (!function_exists('pzdoc_client_signature_saved_at')) {
    function pzdoc_client_signature_saved_at(array $document): string
    {
        $payload = pzdoc_signature_payload($document);
        $savedAt = trim((string)($payload['client_signature_at'] ?? ''));
        if ($savedAt === '') {
            return '';
        }
        if (function_exists('pzdoc_format_date_display')) {
            $date = pzdoc_format_date_display($savedAt);
            $ts = strtotime($savedAt);
            return $date . ($ts ? ' ' . date('H:i', $ts) : '');
        }
        return $savedAt;
    }
}

if (!function_exists('pzdoc_template_has_client_signature_token')) {
    function pzdoc_template_has_client_signature_token(string $html): bool
    {
        return preg_match('/\{\{\s*client_signature\s*\}\}/i', $html) === 1;
    }
}

if (!function_exists('pzdoc_template_has_company_stamp_token')) {
    function pzdoc_template_has_company_stamp_token(string $html): bool
    {
        return preg_match('/\{\{\s*company_stamp\s*\}\}/i', $html) === 1;
    }
}

if (!function_exists('pzdoc_company_stamp_html')) {
    /**
     * Returneaza tag-ul <img> pentru ștampila firmei (data URI).
     * Dimensiunile sunt citite din Setări -> Design documente:
     * document.company_stamp_width_mm si document.company_stamp_height_mm.
     */
    function pzdoc_company_stamp_html(): string
    {
        if (!function_exists('pz_document_design_settings')) {
            return '';
        }
        $s = pz_document_design_settings();
        $rel = trim((string)($s['document.company_stamp_path'] ?? ''));
        if ($rel === '') {
            return '';
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (strpos($rel, '..') !== false) {
            return '';
        }
        $abs = __DIR__ . '/' . $rel;
        if (!is_file($abs)) {
            return '';
        }
        $bytes = @file_get_contents($abs);
        if ($bytes === false || $bytes === '') {
            return '';
        }
        $mime = 'image/png';
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if ($ext === 'jpg' || $ext === 'jpeg') $mime = 'image/jpeg';
        elseif ($ext === 'webp') $mime = 'image/webp';

        $width = (float)str_replace(',', '.', (string)($s['document.company_stamp_width_mm'] ?? 36));
        $height = (float)str_replace(',', '.', (string)($s['document.company_stamp_height_mm'] ?? 36));
        if ($width <= 0) $width = 36;
        if ($height <= 0) $height = 36;
        $width = max(18, min(90, $width));
        $height = max(18, min(90, $height));
        $widthCss = rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.');
        $heightCss = rtrim(rtrim(number_format($height, 2, '.', ''), '0'), '.');

        $src = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8')
            . '" style="width:' . $widthCss . 'mm;height:' . $heightCss . 'mm;display:inline-block;" alt="Ștampila firmei">';
    }
}

if (!function_exists('pzdoc_document_wants_stamp')) {
    /**
     * Returneaza true dacă documentul a fost marcat sa primeasca ștampila (apply_company_stamp=1).
     * Ștampila este permisa pe oferta, contract, proces verbal si act adițional.
     */
    function pzdoc_document_wants_stamp(array $document): bool
    {
        $type = function_exists('pzdoc_normalize_document_type')
            ? pzdoc_normalize_document_type((string)($document['document_type'] ?? ''))
            : (string)($document['document_type'] ?? '');
        if (!in_array($type, ['oferta', 'contract', 'proces_verbal', 'act_aditional'], true)) {
            return false;
        }
        return !empty($document['apply_company_stamp']);
    }
}

if (!function_exists('pzdoc_append_client_signature_if_needed')) {
    /**
     * Pastrat pentru compatibilitate. Semnătura client se pune în șablon via {{client_signature}}.
     */
    function pzdoc_append_client_signature_if_needed(string $html, array $document, bool $signatureTokenPresent): string
    {
        return $html;
    }
}

if (!function_exists('pzdoc_append_company_stamp_if_needed')) {
    /**
     * Dacă sablonul nu contine explicit {{company_stamp}}, dar documentul are ștampila activata,
     * adaugam o zona simpla la final. Astfel butonul functioneaza si pentru șabloanele vechi.
     *
     * Excepție: pentru act adițional NU se face auto-append. Plasarea ștampilei
     * este controlată strict prin tokenul {{company_stamp}} din șablon — așa
     * evităm riscul ca ștampila să apară aiurea pe pagină.
     */
    function pzdoc_append_company_stamp_if_needed(string $html, array $document, bool $stampTokenPresent): string
    {
        if ($stampTokenPresent || !pzdoc_document_wants_stamp($document)) {
            return $html;
        }
        $docType = function_exists('pzdoc_normalize_document_type')
            ? pzdoc_normalize_document_type((string)($document['document_type'] ?? ''))
            : (string)($document['document_type'] ?? '');
        if ($docType === 'act_aditional') {
            // Doar plasare manuală via {{company_stamp}}; fără fallback automat la final.
            return $html;
        }
        $stampHtml = pzdoc_company_stamp_html();
        if ($stampHtml === '') {
            return $html;
        }
        return $html
            . '<div class="pzdoc-stamp-fallback" style="margin-top:12mm;page-break-inside:avoid;text-align:left;">'
            . '<div style="font-weight:bold;margin-bottom:3mm;">Ștampila prestatorului</div>'
            . $stampHtml
            . '</div>';
    }
}

if (!function_exists('pzdoc_payload_tokens')) {
    function pzdoc_payload_tokens(array $payload, string $prefix = ''): array
    {
        $tokens = [];
        foreach ($payload as $key => $value) {
            $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$key);
            $safeKey = trim($safeKey, '_');
            if ($safeKey === '') {
                continue;
            }
            $tokenKey = $prefix === '' ? $safeKey : $prefix . '_' . $safeKey;
            if (is_array($value)) {
                $tokens += pzdoc_payload_tokens($value, $tokenKey);
            } else {
                $tokens[$tokenKey] = pzdoc_token_multiline($value);
            }
        }
        return $tokens;
    }
}


if (!function_exists('pzdoc_pv_services_compact_html')) {
    function pzdoc_pv_services_compact_html(array $document): string
    {
        $payload = is_array($document['payload'] ?? null) ? $document['payload'] : pzdoc_json_decode($document['payload_json'] ?? null);
        if (!is_array($payload)) {
            $payload = [];
        }

        $selectedRaw = [];
        $possibleKeys = [
            'services_selected',
            'selected_services',
            'pv_services',
            'service_types',
            'executed_services',
            'services_checks',
            'services_box',
            'servicii_executate',
        ];

        foreach ($possibleKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_array($value)) {
                foreach ($value as $v) {
                    if (is_array($v)) {
                        foreach ($v as $vv) {
                            $selectedRaw[] = (string)$vv;
                        }
                    } else {
                        $selectedRaw[] = (string)$v;
                    }
                }
            } else {
                foreach (preg_split('/[,;|]+/', (string)$value) as $v) {
                    $selectedRaw[] = $v;
                }
            }
        }

        $items = is_array($document['items'] ?? null) ? $document['items'] : [];
        foreach ($items as $item) {
            $selectedRaw[] = (string)($item['service_name'] ?? '');
            $selectedRaw[] = (string)($item['description'] ?? '');
            $selectedRaw[] = (string)($item['name'] ?? '');
        }

        $normalize = static function (string $text): string {
            $text = trim($text);
            if ($text === '') {
                return '';
            }
            $from = ['ă','â','î','ș','ş','ț','ţ','Ă','Â','Î','Ș','Ş','Ț','Ţ'];
            $to   = ['a','a','i','s','s','t','t','A','A','I','S','S','T','T'];
            $text = str_replace($from, $to, $text);
            $text = strtolower($text);
            return preg_replace('/[^a-z0-9]+/', ' ', $text) ?: '';
        };

        $selected = [
            'dezinsectie' => false,
            'dezinfectie' => false,
            'deratizare' => false,
            'monitorizare' => false,
        ];

        foreach ($selectedRaw as $raw) {
            $text = $normalize((string)$raw);
            if ($text === '') {
                continue;
            }
            if (strpos($text, 'dezinsect') !== false || strpos($text, 'insect') !== false) {
                $selected['dezinsectie'] = true;
            }
            if (strpos($text, 'dezinfect') !== false || strpos($text, 'dezinfect') !== false || strpos($text, 'dezinfectie') !== false) {
                $selected['dezinfectie'] = true;
            }
            if (strpos($text, 'derat') !== false || strpos($text, 'rozator') !== false || strpos($text, 'raticid') !== false) {
                $selected['deratizare'] = true;
            }
            if (strpos($text, 'monitor') !== false || strpos($text, 'capcan') !== false || strpos($text, 'statii') !== false || strpos($text, 'statie') !== false) {
                $selected['monitorizare'] = true;
            }
        }

        $labels = [
            'dezinsectie' => 'DEZINSECTIE',
            'dezinfectie' => 'DEZINFECTIE',
            'deratizare' => 'DERATIZARE',
            'monitorizare' => 'MONITORIZARE',
        ];

        $parts = [];
        foreach ($labels as $key => $label) {
            if (!empty($selected[$key])) {
                $parts[] = '<strong>' . pzdoc_h($label) . '</strong>';
            }
        }

        $servicesText = $parts ? implode(', ', $parts) : '-';

        return '<p class="pzdoc-services-compact" style="margin:6px 0 8px 0; font-size:10pt; line-height:1.25;">'
            . '<strong>SERVICII EXECUTATE:</strong> ' . $servicesText . '</p>';
    }
}

if (!function_exists('pzdoc_public_url')) {
    function pzdoc_public_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        if (defined('APP_URL') && APP_URL) {
            return rtrim((string)APP_URL, '/') . '/' . $path;
        }

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
            (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );
        $scheme = $isHttps ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'app.pestzone.ro');
        $baseDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        if ($baseDir === '' || $baseDir === '.') {
            $baseDir = '';
        }

        return $scheme . '://' . $host . $baseDir . '/' . $path;
    }
}

if (!function_exists('pzdoc_build_tokens')) {
    function pzdoc_build_tokens(PDO $pdo, array $document): array
    {
        $company = pzdoc_company_data($pdo);
        $payload = is_array($document['payload'] ?? null) ? $document['payload'] : pzdoc_json_decode($document['payload_json'] ?? null);
        $currency = trim((string)($document['currency'] ?? 'RON')) ?: 'RON';
        $docNumber = trim((string)($document['document_number'] ?? ''));
        $locationSurface = pzdoc_location_surface_text($pdo, $document, $payload);
        $contractNumber = pzdoc_contract_number_text($pdo, $document, $payload);
        $basisDocument = pzdoc_basis_document_text($pdo, $document, $payload);
        $clientRepresentativeRole = pzdoc_client_representative_role_text($pdo, $document, $payload);
        $contractFirstItemTokens = pzdoc_contract_first_item_tokens($document);
        $publicAvizeUrl = pzdoc_public_url('avize_sanitare.php');

        $validDays = (int)($payload['valid_days'] ?? 0);
        if ($validDays <= 0) {
            $validDays = 15;
        }
        $validUntil = trim((string)($payload['valid_until'] ?? ''));
        if ($validUntil === '' && !empty($document['document_date'])) {
            $ts = strtotime((string)$document['document_date'] . ' +' . $validDays . ' days');
            if ($ts) {
                $validUntil = date('Y-m-d', $ts);
            }
        }
        $discountType = trim((string)($payload['discount_type'] ?? 'none'));
        $discountValue = (float)($payload['discount_value'] ?? 0);
        $subtotalFloat = (float)($document['subtotal'] ?? 0);
        $discountAmount = 0.0;
        $discountLabel = '-';
        if ($discountType === 'percent' && $discountValue > 0) {
            $discountAmount = round($subtotalFloat * min(100, max(0, $discountValue)) / 100, 2);
            $discountLabel = pzdoc_format_number_display($discountValue) . '%';
        } elseif ($discountType === 'value' && $discountValue > 0) {
            $discountAmount = min($subtotalFloat, round($discountValue, 2));
            $discountLabel = pzdoc_format_number_display($discountValue) . ' ' . $currency;
        }
        $discountAmount = min($subtotalFloat, $discountAmount);
        $discountBlock = '';
        if ($discountAmount > 0) {
            $discountBlock = '<p><strong>Discount:</strong> ' . pzdoc_h($discountLabel) . ' (-' . pzdoc_format_number_display($discountAmount) . ' ' . pzdoc_h($currency) . ')</p>';
        }

        // Total efectiv = total_amount din DB minus discount (DB-ul nu stochează
        // discount-ul scăzut, doar îl ține în payload). Pentru oferte fără TVA,
        // asta înseamnă subtotal - discount.
        $rawTotalAmount = (float)($document['total_amount'] ?? 0);
        $netTotalAmount = max(0.0, round($rawTotalAmount - $discountAmount, 2));
        $offerIntro = trim((string)($payload['offer_intro'] ?? ''));
        if ($offerIntro === '') {
            $offerIntro = 'Va transmitem prezenta oferta comerciala pentru prestarea serviciilor detaliate mai jos:';
        }
        $offerFooter = trim((string)($payload['offer_footer'] ?? ''));
        if ($offerFooter === '') {
            $offerFooter = 'Serviciile suplimentare care nu sunt mentionate expres in prezenta oferta se vor factura separat, doar cu acordul beneficiarului. Acceptarea ofertei se poate face prin semnare, comanda ferma sau confirmare scrisa transmisa pe email.';
        }
        $paymentTerms = trim((string)($payload['payment_terms'] ?? ''));
        if ($paymentTerms === '') {
            $paymentTerms = 'Conform intelegerii comerciale dintre parti.';
        }
        $paymentDueDays = pzdoc_payment_due_days_value($payload, 5);

        $tokens = [
            'document_id' => pzdoc_token_text($document['id'] ?? ''),
            'document_type' => pzdoc_token_text($document['document_type'] ?? ''),
            'document_type_label' => pzdoc_h(pzdoc_document_type_label((string)($document['document_type'] ?? ''))),
            'document_status' => pzdoc_h(pzdoc_status_label((string)($document['status'] ?? 'draft'))),
            'document_title' => pzdoc_token_text($document['title'] ?? ''),
            'document_number' => pzdoc_token_text($docNumber !== '' ? $docNumber : 'Draft'),
            'document_date' => pzdoc_h(pzdoc_format_date_display($document['document_date'] ?? null)),
            'document_time' => pzdoc_h(pzdoc_format_time_display($document['document_time'] ?? null)),
            'contract_date' => pzdoc_h(pzdoc_format_date_display($document['document_date'] ?? null)),
            'contract_number' => pzdoc_token_text($contractNumber, 'nota de comanda'),
            'numar_contract' => pzdoc_token_text($contractNumber, 'nota de comanda'),
            'document_baza' => pzdoc_token_text($basisDocument, 'Nota de comanda'),
            'basis_document' => pzdoc_token_text($basisDocument, 'Nota de comanda'),
            'in_baza' => pzdoc_token_text($basisDocument, 'Nota de comanda'),
            'pv_basis' => pzdoc_token_text($basisDocument, 'Nota de comanda'),
            'created_at' => pzdoc_h(pzdoc_format_date_display($document['created_at'] ?? null)),
            'issued_at' => pzdoc_h(pzdoc_format_date_display($document['issued_at'] ?? null)),
            'currency' => pzdoc_h($currency),
            'subtotal' => pzdoc_format_number_display($document['subtotal'] ?? 0) . ' ' . pzdoc_h($currency),
            'vat_percent' => pzdoc_format_number_display($document['vat_percent'] ?? 0) . '%',
            'vat_amount' => pzdoc_format_number_display($document['vat_amount'] ?? 0) . ' ' . pzdoc_h($currency),
            'document_total' => pzdoc_format_number_display($netTotalAmount) . ' ' . pzdoc_h($currency),
            'total_amount' => pzdoc_format_number_display($netTotalAmount) . ' ' . pzdoc_h($currency),
            'subtotal_without_vat' => pzdoc_format_number_display($document['subtotal'] ?? 0) . ' ' . pzdoc_h($currency) . ' fără TVA',
            'total_without_vat' => pzdoc_format_number_display($netTotalAmount) . ' ' . pzdoc_h($currency) . ' fără TVA',
            'total_fara_tva' => pzdoc_format_number_display($netTotalAmount) . ' ' . pzdoc_h($currency) . ' fără TVA',
            'discount_label' => pzdoc_token_text($discountLabel),
            'discount_amount' => pzdoc_format_number_display($discountAmount) . ' ' . pzdoc_h($currency),
            'discount_block' => $discountBlock,
            'valid_days' => pzdoc_token_text($validDays),
            'valid_until' => pzdoc_h(pzdoc_format_date_display($validUntil)),
            'payment_terms' => pzdoc_token_multiline($paymentTerms),
            'payment_due_days' => pzdoc_token_text($paymentDueDays),
            'termen_plata_zile' => pzdoc_token_text($paymentDueDays),
            // Datele perioadei contractului: formatate explicit (RO: dd.mm.yyyy) ca sa
            // bata valoarea raw injectata automat de pzdoc_payload_tokens($payload).
            'contract_start_date' => pzdoc_h(pzdoc_format_date_display($payload['contract_start_date'] ?? null)),
            'contract_end_date' => pzdoc_h(pzdoc_format_date_display($payload['contract_end_date'] ?? null)),
            // Tokeni pentru act adițional (pop. din payload de addenda.php; goale pentru alte tipuri)
            'parent_contract_number' => pzdoc_token_text($payload['parent_contract_number'] ?? '', '-'),
            'parent_contract_date' => pzdoc_h(pzdoc_format_date_display($payload['parent_contract_date'] ?? null)),
            'addendum_start_date' => pzdoc_h(pzdoc_format_date_display($payload['addendum_start_date'] ?? null)),
            'addendum_end_date' => pzdoc_h(pzdoc_format_date_display($payload['addendum_end_date'] ?? null)),
            'delivery_terms' => pzdoc_token_multiline($payload['delivery_terms'] ?? ''),
            'offer_intro' => pzdoc_token_multiline($offerIntro),
            'offer_footer' => pzdoc_token_multiline($offerFooter),
            'prices_without_vat_note' => 'Toate preturile din prezenta oferta sunt exprimate fără TVA. TVA se va aplica, dacă este cazul, conform legislatiei in vigoare la data facturarii.',

            'company_name' => pzdoc_token_text($company['name'] ?? ''),
            'company_legal_name' => pzdoc_token_text($company['legal_name'] ?? ''),
            'company_cui' => pzdoc_token_text($company['cui'] ?? ''),
            'company_reg_com' => pzdoc_token_text($company['reg_com'] ?? ''),
            'company_address' => pzdoc_token_multiline($company['address'] ?? ''),
            'company_bank_name' => pzdoc_token_text($company['bank_name'] ?? ''),
            'company_bank_account' => pzdoc_token_text($company['bank_account'] ?? ''),
            'company_email' => pzdoc_token_text($company['email'] ?? ''),
            'company_phone' => pzdoc_token_text($company['phone'] ?? ''),
            'company_website' => pzdoc_token_text($company['website'] ?? ''),
            // PZ_PV_OPERATOR: pentru PV, dacă avem operatori (workers_names) → ei devin reprezentant prestator.
            'company_representative' => pzdoc_token_text(
                (function () use ($document, $company) {
                    $type = function_exists('pzdoc_normalize_document_type')
                        ? pzdoc_normalize_document_type((string)($document['document_type'] ?? ''))
                        : (string)($document['document_type'] ?? '');
                    if ($type === 'proces_verbal') {
                        $payload = is_array($document['payload'] ?? null) ? $document['payload'] : (function_exists('pzdoc_json_decode') ? pzdoc_json_decode($document['payload_json'] ?? null) : []);
                        $workers = trim((string)(($payload['workers_names'] ?? '')));
                        if ($workers !== '') { return $workers; }
                    }
                    return (string)($company['representative_name'] ?? '');
                })()
            ),
            'operator_ddd' => pzdoc_token_text(
                (function () use ($document) {
                    $payload = is_array($document['payload'] ?? null) ? $document['payload'] : (function_exists('pzdoc_json_decode') ? pzdoc_json_decode($document['payload_json'] ?? null) : []);
                    return trim((string)($payload['workers_names'] ?? ''));
                })()
            ),
            'company_representative_role' => pzdoc_token_text($company['representative_role'] ?? ''),
            'provider_representative' => pzdoc_token_text($company['representative_name'] ?? ''),
            'provider_representative_role' => pzdoc_token_text($company['representative_role'] ?? ''),
            'company_authorizations' => pzdoc_token_multiline($company['authorizations'] ?? ''),
            'company_provider_role' => pzdoc_token_text($company['provider_role_label'] ?? ''),
            'company_block' => pzdoc_company_block_html($company),
            // Token {{company_stamp}}: respecta flag-ul apply_company_stamp pe oferta / contract / PV.
            'company_stamp' => pzdoc_document_wants_stamp($document) ? pzdoc_company_stamp_html() : '',

            'client_name' => pzdoc_token_text($document['client_name_snapshot'] ?? ''),
            'client_cui' => pzdoc_token_text($document['client_identifier_snapshot'] ?? ''),
            'client_identifier' => pzdoc_token_text($document['client_identifier_snapshot'] ?? ''),
            'client_tax_id' => pzdoc_token_text($document['client_identifier_snapshot'] ?? ''),
            'client_registry' => pzdoc_token_text($document['client_registry_snapshot'] ?? ''),
            'client_reg_com' => pzdoc_token_text($document['client_registry_snapshot'] ?? ''),
            'client_address' => pzdoc_token_multiline($document['client_address_snapshot'] ?? ''),
            'client_representative' => pzdoc_token_text($document['client_representative_snapshot'] ?? ''),
            'client_representative_role' => pzdoc_token_text($clientRepresentativeRole, ''),
            'client_email' => pzdoc_token_text($document['client_email_snapshot'] ?? ''),
            'client_phone' => pzdoc_token_text($document['client_phone_snapshot'] ?? ''),
            'client_signature' => pzdoc_client_signature_html($document),
            'client_signature_saved_at' => pzdoc_token_text(pzdoc_client_signature_saved_at($document)),
            'client_block' => pzdoc_client_block_html($document),

            'location_name' => pzdoc_token_text($document['location_name_snapshot'] ?? ''),
            'location_address' => pzdoc_token_multiline($document['location_address_snapshot'] ?? ''),
            'location_contact' => pzdoc_token_text($document['location_contact_snapshot'] ?? ''),
            'location_phone' => pzdoc_token_text($document['location_phone_snapshot'] ?? ''),
            'location_surface' => pzdoc_token_text($locationSurface, ''),
            'location_area' => pzdoc_token_text($locationSurface, ''),
            'suprafata_locație' => pzdoc_token_text($locationSurface, ''),
            'surface_text' => pzdoc_token_text($locationSurface, ''),
            'treated_areas' => pzdoc_token_multiline($payload['treated_areas'] ?? ''),
            'zone_tratate' => pzdoc_token_multiline($payload['treated_areas'] ?? ''),
            'pv_treated_areas' => pzdoc_token_multiline($payload['treated_areas'] ?? ''),
            'location_block' => pzdoc_location_block_html($document),

            'items_table' => pzdoc_items_table_html($document),
            'services_table' => pzdoc_services_table_or_pv_html($document),
            'contract_services_table' => pzdoc_contract_services_table_html($document),
            'contract_items_table' => pzdoc_contract_services_table_html($document),
                        'services_checks' => pzdoc_pv_services_brackets_html($document),
                        'services_box' => pzdoc_pv_services_brackets_html($document),
            'materials_table' => pzdoc_materials_table_html($document),
            'biocides_table' => pzdoc_materials_table_html($document),
            'materials_safety' => pzdoc_materials_safety_html($document),
            'safety_measures' => pzdoc_materials_safety_html($document),
            'avize_sanitare_url' => pzdoc_h($publicAvizeUrl),
            'avize_sanitare_link' => '<a href="' . pzdoc_h($publicAvizeUrl) . '" target="_blank" rel="noopener">Descarcă avizele sanitare ale produselor</a>',
            'product_avize_url' => pzdoc_h($publicAvizeUrl),
            'product_avize_link' => '<a href="' . pzdoc_h($publicAvizeUrl) . '" target="_blank" rel="noopener">Descarcă avizele sanitare ale produselor</a>',

            'notes' => pzdoc_token_multiline($document['notes'] ?? ''),
            'executor_notes' => pzdoc_token_multiline($document['executor_notes'] ?? ''),
            'recommendations' => pzdoc_token_multiline($document['recommendations'] ?? ''),
            'client_notes' => pzdoc_token_multiline($document['client_notes'] ?? ''),
            'internal_notes' => pzdoc_token_multiline($document['internal_notes'] ?? ''),

            // Token universal pentru „obiectul documentului" — funcționează pentru
            // contracte standard (sursă: payload.contract_object), acte adiționale
            // și alte tipizate ce stochează descrierea liberă în câmpul `notes`.
            // Cu fallback automat între cele două surse.
            'document_object' => pzdoc_token_multiline(
                trim((string)($payload['contract_object'] ?? '')) !== ''
                    ? (string)($payload['contract_object'] ?? '')
                    : (string)($document['notes'] ?? '')
            ),
        ];

        $tokens += $contractFirstItemTokens;

        $tokens += pzdoc_appointment_tokens($pdo, $document);
        $tokens += pzdoc_payload_tokens($payload);

        return $tokens;
    }
}

if (!function_exists('pzdoc_apply_tokens')) {
    function pzdoc_apply_tokens(string $html, array $tokens): string
    {
        $replace = [];
        foreach ($tokens as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            $replace['{{' . $key . '}}'] = (string)$value;
            $replace['{{ ' . $key . ' }}'] = (string)$value;
        }

        $html = strtr($html, $replace);

        // Inlocuieste tokenii ramasi cu un camp gol, ca sa nu apara {{...}} in PDF.
        $html = preg_replace('/\{\{\s*[a-zA-Z0-9_\.\-]+\s*\}\}/', '', $html);

        return $html;
    }
}

if (!function_exists('pzdoc_base_document_css')) {
    function pzdoc_base_document_css(): string
    {
        return '<style>
            body{font-family:dejavusans,Arial,sans-serif;color:#111827;}
            h1{margin:0 0 10px 0;line-height:1.2;}
            h2{margin:14px 0 7px 0;line-height:1.25;}
            h3{margin:10px 0 6px 0;line-height:1.25;}
            p{margin:0 0 7px 0;}
            .muted{color:#6b7280;}
            .right{text-align:right;}
            .center{text-align:center;}
            .pzdoc-table{border-collapse:collapse;width:100%;margin:6px 0 10px 0;font-size:9.2pt;table-layout:auto;}
            .pzdoc-table th{width:auto !important;background:#f3f4f6;border:1px solid #d1d5db;padding:5px 6px;text-align:left;font-weight:bold;white-space:normal;word-break:normal;overflow-wrap:break-word;}
            .pzdoc-table td{width:auto !important;border:1px solid #d1d5db;padding:5px 6px;vertical-align:top;white-space:normal;word-break:normal;overflow-wrap:break-word;}
            .pzdoc-table .pzdoc-nowrap{white-space:nowrap;}
            .pzdoc-contract-services-table{font-size:8.5pt;}
            .pzdoc-contract-services-table th,.pzdoc-contract-services-table td{padding:4px 5px;line-height:1.25;}
            .pzdoc-materials-table th,.pzdoc-materials-table td{text-align:center !important;vertical-align:middle !important;}
            .pzdoc-summary{width:45%;margin-left:auto;border-collapse:collapse;font-size:9.5pt;}
            .pzdoc-summary td{border:1px solid #d1d5db;padding:5px 6px;}
            .signature-table{width:100%;border-collapse:collapse;margin-top:22px;}
            .signature-table td{width:50%;vertical-align:top;text-align:center;padding-top:24px;}
            .pzdoc-client-signature{max-width:170px;max-height:170px;vertical-align:middle;}
            .pzdoc-client-signature-box{display:inline-block;width:180px;height:180px;line-height:180px;text-align:center;vertical-align:middle;}
            .pzdoc-signature-line{display:inline-block;color:#111827;}
        </style>';
    }
}

if (!function_exists('pzdoc_select_template_html')) {
    function pzdoc_select_template_html(PDO $pdo, array $document, ?int $templateId = null): array
    {
        $type = pzdoc_validate_document_type((string)($document['document_type'] ?? ''));
        $chosenTemplateId = $templateId ?: (!empty($document['template_id']) ? (int)$document['template_id'] : null);
        $template = pzdoc_get_template($pdo, $chosenTemplateId, $type);

        $customHtml = trim((string)($document['content_html'] ?? ''));
        if ($customHtml !== '') {
            return [$customHtml, $template];
        }

        if ($template && trim((string)($template['content_html'] ?? '')) !== '') {
            return [(string)$template['content_html'], $template];
        }

        return [pzdoc_default_template_content($type), $template];
    }
}

if (!function_exists('pzdoc_render_document_html')) {
    function pzdoc_render_document_html(PDO $pdo, int $documentId, ?int $templateId = null, bool $includeBaseCss = true): string
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, true);
        if (!$document) {
            throw new RuntimeException('Document inexistent.');
        }

        list($templateHtml, $template) = pzdoc_select_template_html($pdo, $document, $templateId);
        $hasSignatureToken = pzdoc_template_has_client_signature_token($templateHtml);
        $hasStampToken = pzdoc_template_has_company_stamp_token($templateHtml);
        $tokens = pzdoc_build_tokens($pdo, $document);
        $html = pzdoc_apply_tokens($templateHtml, $tokens);
        $html = pzdoc_append_client_signature_if_needed($html, $document, $hasSignatureToken);
        $html = pzdoc_append_company_stamp_if_needed($html, $document, $hasStampToken);

        if ($includeBaseCss) {
            // Injecteaza CSS de baza DOAR dacă șablonul nu contine deja un bloc <style>.
            // Astfel, font-size-ul setat in editor (șablon) nu este suprascris.
            if (stripos($html, '<style') === false) {
                $html = pzdoc_base_document_css() . $html;
            }
        }

        return $html;
    }
}

if (!function_exists('pzdoc_render_document_preview')) {
    function pzdoc_render_document_preview(PDO $pdo, int $documentId, ?int $templateId = null): array
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, true);
        if (!$document) {
            throw new RuntimeException('Document inexistent.');
        }

        list($templateHtml, $template) = pzdoc_select_template_html($pdo, $document, $templateId);
        $hasSignatureToken = pzdoc_template_has_client_signature_token($templateHtml);
        $hasStampToken = pzdoc_template_has_company_stamp_token($templateHtml);
        $tokens = pzdoc_build_tokens($pdo, $document);
        $html = pzdoc_apply_tokens($templateHtml, $tokens);
        $html = pzdoc_append_client_signature_if_needed($html, $document, $hasSignatureToken);
        $html = pzdoc_append_company_stamp_if_needed($html, $document, $hasStampToken);

        // Fara CSS propriu aici. Preview-ul vizual si PDF-ul aplica acelasi CSS
        // din document_pdf_engine.php, ca sa nu existe randari paralele diferite.
        return [
            'document' => $document,
            'template' => $template,
            'tokens' => $tokens,
            'html' => $html,
        ];
    }
}

if (!function_exists('pzdoc_available_tokens')) {
    function pzdoc_available_tokens(): array
    {
        return [
            'Document' => [
                '{{document_number}}', '{{document_date}}', '{{document_time}}', '{{contract_number}}', '{{contract_date}}', '{{document_type_label}}', '{{document_status}}', '{{document_title}}',
                '{{subtotal}}', '{{subtotal_without_vat}}', '{{vat_percent}}', '{{vat_amount}}', '{{document_total}}', '{{total_without_vat}}', '{{total_fara_tva}}', '{{currency}}',
            ],
            'Prestator' => [
                '{{company_block}}', '{{company_name}}', '{{company_legal_name}}', '{{company_cui}}', '{{company_reg_com}}', '{{company_address}}',
                '{{company_bank_name}}', '{{company_bank_account}}', '{{company_email}}', '{{company_phone}}', '{{company_website}}',
                '{{company_representative}}', '{{company_representative_role}}', '{{provider_representative}}', '{{provider_representative_role}}', '{{company_authorizations}}', '{{company_provider_role}}', '{{operator_ddd}}',
                '{{company_stamp}}',
            ],
            'Client' => [
                '{{client_block}}', '{{client_name}}', '{{client_cui}}', '{{client_identifier}}', '{{client_registry}}', '{{client_address}}',
                '{{client_representative}}', '{{client_representative_role}}', '{{client_tax_id}}', '{{client_reg_com}}', '{{client_email}}', '{{client_phone}}', '{{client_signature}}', '{{client_signature_saved_at}}',
            ],
            'Locație' => [
                '{{location_block}}', '{{location_name}}', '{{location_address}}', '{{location_contact}}', '{{location_phone}}', '{{location_surface}}', '{{suprafata_locație}}', '{{surface_text}}', '{{treated_areas}}', '{{zone_tratate}}', '{{pv_treated_areas}}',
            ],
            'Programare' => [
                '{{programare_client}}', '{{programare_reprezentant_client}}', '{{programare_locație}}', '{{programare_adresa_locație}}',
                '{{programare_ora_inceput}}', '{{programare_data}}', '{{programare_reprezentant_locație}}', '{{programare_telefon_locație}}', '{{programare_suprafata_locație}}',
                '{{appointment_client_name}}', '{{appointment_client_representative}}', '{{appointment_location_name}}', '{{appointment_location_address}}',
                '{{appointment_start_time}}', '{{appointment_date}}', '{{appointment_location_contact}}', '{{appointment_location_phone}}', '{{appointment_location_surface}}',
            ],
            'Tabele' => [
                '{{items_table}}', '{{services_table}}', '{{contract_services_table}}', '{{contract_items_table}}', '{{services_checks}}', '{{services_box}}', '{{materials_table}}', '{{biocides_table}}', '{{materials_safety}}', '{{safety_measures}}',
            ],
            'Observații' => [
                '{{document_object}}', '{{notes}}', '{{executor_notes}}', '{{recommendations}}', '{{client_notes}}', '{{internal_notes}}', '{{offer_intro}}', '{{offer_footer}}',
            ],
            'Avize sanitare' => [
                '{{avize_sanitare_link}}', '{{avize_sanitare_url}}', '{{product_avize_link}}', '{{product_avize_url}}',
            ],
            'Date suplimentare' => [
                '{{contract_number}}', '{{numar_contract}}', '{{document_baza}}', '{{basis_document}}', '{{in_baza}}', '{{pv_basis}}',
                '{{contract_start_date}}', '{{contract_end_date}}', '{{contract_value}}', '{{auto_renewal_text}}', '{{payment_terms}}', '{{payment_due_days}}', '{{termen_plata_zile}}', '{{valid_days}}', '{{valid_until}}', '{{delivery_terms}}', '{{discount_label}}', '{{discount_amount}}', '{{discount_block}}', '{{prices_without_vat_note}}',
            ],
            'Act adițional' => [
                '{{parent_contract_number}}', '{{parent_contract_date}}', '{{addendum_start_date}}', '{{addendum_end_date}}',
            ],
        ];
    }
}

if (basename(__FILE__) === basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            pzdoc_require_schema($pdo);
        }
        echo 'Document tokens incarcat corect.';
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Eroare document_tokens.php: ' . pzdoc_h($e->getMessage());
    }
}
