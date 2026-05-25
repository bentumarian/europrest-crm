<?php

/*
|--------------------------------------------------------------------------
| clients_anaf_lib.php
|--------------------------------------------------------------------------
| Bibliotecă self-contained pentru lookup-ul ANAF (CUI) și normalizarea
| răspunsurilor primite de la serviciul ANAF (TVA-Plătitor).
|
| Funcții publice:
|   c_anaf_lookup(string $cui): array
|     - apelează webservicesp.anaf.ro și întoarce datele firmei
|   c_normalize_anaf_item(array $item): array
|     - normalizează un obiect din răspunsul ANAF
|   c_anaf_address_parts(array $item): array
|     - extrage și curăță componentele adresei (județ, oraș, stradă, etc.)
|
| Funcții interne (helpers):
|   c_first_anaf_value, c_anaf_address_line_from_full,
|   c_normalize_anaf_county, c_normalize_anaf_city
|
| Dependențe: clients_helpers.php (c_clean_fiscal_code, c_clean_text)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../clients_helpers.php';

function c_first_anaf_value(array $sources, array $keys): string {
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $value = c_clean_text((string)($source[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    return '';
}

function c_anaf_address_line_from_full(string $fullAddress): string {
    $parts = array_map('trim', explode(',', $fullAddress));
    $kept = [];

    foreach ($parts as $part) {
        $part = c_clean_text($part);
        if ($part === '') {
            continue;
        }

        if (preg_match('/^(JUD\.?|JUDETUL)\b/i', $part)) {
            continue;
        }
        if (preg_match('/^(MUN\.?|MUNICIPIUL|ORAS(?:UL)?|COM\.?|COMUNA|SAT(?:UL)?)\b/i', $part)) {
            continue;
        }
        if (preg_match('/^SECTOR(?:UL)?\s*[1-6]\b/i', $part)) {
            continue;
        }
        if (preg_match('/^(TARA\s*)?ROMANIA$/i', $part)) {
            continue;
        }

        $kept[] = $part;
    }

    return c_clean_text(implode(', ', $kept));
}

function c_normalize_anaf_county(string $county): string {
    $county = c_clean_text($county);
    $county = preg_replace('/^(JUDETUL|JUD\.?)\s+/i', '', $county) ?? $county;
    $county = preg_replace('/^MUNICIPIUL\s+/i', '', $county) ?? $county;
    $county = trim($county);

    if (preg_match('/BUCURE[ȘS]TI/i', $county)) {
        return 'Bucuresti';
    }

    return $county;
}

function c_normalize_anaf_city(string $city): string {
    $city = c_clean_text($city);
    $city = preg_replace('/^(MUN\.?|MUNICIPIUL|ORAS(?:UL)?|ORAȘ(?:UL)?|COM\.?|COMUNA|SAT(?:UL)?)\s+/iu', '', $city) ?? $city;
    $city = trim($city, " \t\n\r\0\x0B,.-");

    if (preg_match('/^SECTOR(?:UL)?\s*([1-6])$/iu', $city, $m)) {
        return 'Sector ' . c_clean_text($m[1] ?? '');
    }

    return $city;
}

function c_anaf_address_parts(array $item): array {
    $general = is_array($item['date_generale'] ?? null) ? $item['date_generale'] : [];
    $sediu = is_array($item['adresa_sediu_social'] ?? null) ? $item['adresa_sediu_social'] : [];
    $domiciliu = is_array($item['adresa_domiciliu_fiscal'] ?? null) ? $item['adresa_domiciliu_fiscal'] : [];
    $sources = [$sediu, $domiciliu, $general];
    $fullAddress = c_clean_text((string)($general['adresa'] ?? ''));

    $street = c_first_anaf_value($sources, [
        'sdenumire_Strada', 'ddenumire_Strada', 'denumire_Strada',
        'strada', 'street',
    ]);
    $number = c_first_anaf_value($sources, [
        'snumar_Strada', 'dnumar_Strada', 'numar_Strada',
        'numar', 'street_number',
    ]);
    $details = c_first_anaf_value($sources, [
        'sdetalii_Adresa', 'ddetalii_Adresa', 'detalii_Adresa',
        'detalii', 'address_details',
    ]);

    $lineParts = [];
    if ($street !== '') {
        $lineParts[] = trim($street . ($number !== '' ? ' nr. ' . $number : ''));
    }
    if ($details !== '') {
        $lineParts[] = $details;
    }

    $addressLine = c_clean_text(implode(', ', array_filter($lineParts)));

    $county = c_first_anaf_value($sources, [
        'sdenumire_Județ', 'ddenumire_Județ', 'denumire_Județ',
        'județ', 'county',
    ]);
    $city = c_first_anaf_value($sources, [
        'sdenumire_Localitate', 'ddenumire_Localitate', 'denumire_Localitate',
        'localitate', 'oraș', 'city',
    ]);
    $country = c_first_anaf_value($sources, [
        'sțară', 'dțară', 'țară', 'country',
    ]);
    $postal = c_first_anaf_value($sources, [
        'scod_Postal', 'dcod_Postal', 'cod_Postal', 'codPostal',
        'cod_postal', 'postal_code', 'zip',
    ]);

    if ($county === '' && preg_match('/\bJUD\.?\s*([^,]+)/i', $fullAddress, $m)) {
        $county = c_clean_text($m[1] ?? '');
    }
    $county = c_normalize_anaf_county($county);

    if (preg_match('/\bSECTOR(?:UL)?\s*([1-6])\b/i', $fullAddress, $m)) {
        $city = 'Sector ' . c_clean_text($m[1] ?? '');
        if ($county === '' || preg_match('/BUCURESTI/i', $county . ' ' . $fullAddress)) {
            $county = 'Bucuresti';
        }
    } elseif ($city === '' && preg_match('/\b(?:MUN\.?|MUNICIPIUL|ORAS(?:UL)?|COM\.?|COMUNA|SAT(?:UL)?)\s*([^,]+)/i', $fullAddress, $m)) {
        $city = c_clean_text($m[1] ?? '');
    }
    $city = c_normalize_anaf_city($city);

    if ($postal === '' && preg_match('/\b(?:CP|COD\s*POSTAL)\s*[:\-]?\s*([0-9]{4,10})\b/i', $fullAddress, $m)) {
        $postal = c_clean_text($m[1] ?? '');
    }

    if ($addressLine === '') {
        $addressLine = c_anaf_address_line_from_full($fullAddress);
    }

    return [
        'billing_country' => $country !== '' ? $country : 'Romania',
        'billing_county' => $county,
        'billing_city' => $city,
        'billing_sector' => '',
        'billing_address_line' => $addressLine,
        'billing_postal_code' => $postal,
    ];
}

function c_normalize_anaf_item(array $item): array {
    $general = is_array($item['date_generale'] ?? null) ? $item['date_generale'] : [];
    $addressParts = c_anaf_address_parts($item);

    return [
        'client_type' => 'company',
        'name' => c_clean_text($general['denumire'] ?? ''),
        'fiscal_code' => c_clean_fiscal_code((string)($general['cui'] ?? '')),
        'registry_number' => c_clean_text($general['nrRegCom'] ?? ''),
        'registered_address' => c_clean_text($general['adresa'] ?? '') ?: c_build_billing_address($addressParts),
        'billing_country' => $addressParts['billing_country'],
        'billing_county' => $addressParts['billing_county'],
        'billing_city' => $addressParts['billing_city'],
        'billing_sector' => $addressParts['billing_sector'],
        'billing_address_line' => $addressParts['billing_address_line'],
        'billing_postal_code' => $addressParts['billing_postal_code'],
        'phone' => '', // Nu preluam telefonul de la ANAF.
        'email' => '',
        'bank_name' => '',
        'bank_account' => c_clean_text($general['iban'] ?? ''),
        'legal_representative_name' => '',
        'legal_representative_role' => '',
        'anaf_last_lookup_at' => date('Y-m-d H:i:s'),
        'tva' => [
            'scpTVA' => (bool)($item['inregistrare_scop_Tva']['scpTVA'] ?? false),
            'statusSplitTVA' => (bool)($item['inregistrare_SplitTVA']['statusSplitTVA'] ?? false),
            'statusRO_e_Factura' => (bool)($general['statusRO_e_Factura'] ?? false),
        ],
        'inactive' => [
            'statusInactivi' => (bool)($item['stare_inactiv']['statusInactivi'] ?? false),
            'dataInactivare' => c_clean_text($item['stare_inactiv']['dataInactivare'] ?? ''),
            'dataReactivare' => c_clean_text($item['stare_inactiv']['dataReactivare'] ?? ''),
        ],
    ];
}

function c_anaf_lookup(string $cui): array {
    $cui = c_clean_fiscal_code($cui);

    if ($cui === '') {
        return [
            'success' => false,
            'message' => 'Introdu CUI-ul firmei.',
        ];
    }

    $url = 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva';
    $payload = json_encode([
        [
            'cui' => (int)$cui,
            'data' => date('Y-m-d'),
        ]
    ], JSON_UNESCAPED_UNICODE);

    $status = 0;
    $contentType = '';
    $raw = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
                'Content-Length: ' . strlen((string)$payload),
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === '' && $curlError !== '') {
            return [
                'success' => false,
                'message' => 'Nu s-a putut contacta ANAF: ' . $curlError,
                'debug' => [
                    'url' => $url,
                    'http_status' => $status,
                    'content_type' => $contentType,
                ],
            ];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json; charset=utf-8\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => 25,
            ]
        ]);

        $raw = (string)@file_get_contents($url, false, $context);
        $status = 0;

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $m)) {
                    $status = (int)$m[1];
                }
                if (stripos($header, 'Content-Type:') === 0) {
                    $contentType = trim(substr($header, 13));
                }
            }
        }
    }

    $json = json_decode($raw, true);
    if (!is_array($json) && function_exists('mb_convert_encoding')) {
        $json = json_decode(mb_convert_encoding($raw, 'UTF-8', 'UTF-8'), true);
    }

    if (!is_array($json)) {
        return [
            'success' => false,
            'message' => 'Raspuns invalid de la ANAF. Serverul a primit HTML/text in loc de JSON sau ANAF a returnat temporar o pagina de eroare.',
            'debug' => [
                'url' => $url,
                'http_status' => $status,
                'content_type' => $contentType,
                'json_error' => json_last_error_msg(),
                'response_preview' => substr($raw, 0, 600),
            ],
        ];
    }

    $found = $json['found'] ?? [];

    if (!is_array($found) || empty($found[0]) || !is_array($found[0])) {
        return [
            'success' => false,
            'message' => 'Firma nu a fost gasita la ANAF pentru CUI-ul introdus.',
            'data' => null,
            'debug' => [
                'url' => $url,
                'http_status' => $status,
                'content_type' => $contentType,
                'response' => $json,
            ],
        ];
    }

    $data = c_normalize_anaf_item($found[0]);
    $data['anaf_raw_response'] = json_encode($json, JSON_UNESCAPED_UNICODE);

    return [
        'success' => true,
        'message' => 'Datele firmei au fost gasite la ANAF.',
        'data' => $data,
        'debug' => [
            'url' => $url,
            'http_status' => $status,
            'content_type' => $contentType,
        ],
    ];
}

