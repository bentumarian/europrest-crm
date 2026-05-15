<?php
require_once 'config.php';
require_login();

/*
|--------------------------------------------------------------------------
| PestZone - ANAF lookup dupa CUI - V5
|--------------------------------------------------------------------------
| Corectii:
| - Endpoint ANAF V9 functional
| - Datele preluate sunt convertite fara diacritice
| - Nu se mai preia numarul de telefon de la ANAF
| - anaf_raw_response este salvat tot fara diacritice, ca sa evitam probleme
|   de encoding in platforma / baza de date / editor cPanel
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

if (!is_admin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acces interzis.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function anaf_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function anaf_clean_cui(string $cui): string {
    $cui = strtoupper(trim($cui));
    $cui = preg_replace('/^RO\s*/i', '', $cui);
    $cui = preg_replace('/[^0-9]/', '', $cui);

    return $cui ?: '';
}

function anaf_remove_diacritics($value) {
    if (is_array($value)) {
        $clean = [];

        foreach ($value as $key => $item) {
            $clean[$key] = anaf_remove_diacritics($item);
        }

        return $clean;
    }

    if (!is_string($value)) {
        return $value;
    }

    $map = [
        'ă' => 'a', 'Ă' => 'A',
        'â' => 'a', 'Â' => 'A',
        'î' => 'i', 'Î' => 'I',
        'ș' => 's', 'Ș' => 'S',
        'ş' => 's', 'Ş' => 'S',
        'ț' => 't', 'Ț' => 'T',
        'ţ' => 't', 'Ţ' => 'T',
        'á' => 'a', 'Á' => 'A',
        'à' => 'a', 'À' => 'A',
        'ä' => 'a', 'Ä' => 'A',
        'é' => 'e', 'É' => 'E',
        'è' => 'e', 'È' => 'E',
        'ë' => 'e', 'Ë' => 'E',
        'í' => 'i', 'Í' => 'I',
        'ì' => 'i', 'Ì' => 'I',
        'ï' => 'i', 'Ï' => 'I',
        'ó' => 'o', 'Ó' => 'O',
        'ò' => 'o', 'Ò' => 'O',
        'ö' => 'o', 'Ö' => 'O',
        'ú' => 'u', 'Ú' => 'U',
        'ù' => 'u', 'Ù' => 'U',
        'ü' => 'u', 'Ü' => 'U',
        'ñ' => 'n', 'Ñ' => 'N',
        'ç' => 'c', 'Ç' => 'C',
    ];

    $value = strtr($value, $map);

    // Curata eventuale caractere non-ASCII ramase, fara sa blocam daca iconv nu exista.
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    return $value;
}

function anaf_first_non_empty(...$values): string {
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function anaf_safe_substr(string $text, int $length = 700): string {
    $text = trim($text);
    $text = preg_replace('/\s+/', ' ', $text);

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $length, 'UTF-8');
    }

    return substr($text, 0, $length);
}

function anaf_extract_json_string(string $body): string {
    $body = trim($body);
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $body = trim($body);

    if ($body === '') {
        return '';
    }

    if ($body[0] === '{' || $body[0] === '[') {
        return $body;
    }

    $firstObject = strpos($body, '{');
    $firstArray = strpos($body, '[');

    if ($firstObject === false && $firstArray === false) {
        return $body;
    }

    if ($firstObject === false) {
        $start = $firstArray;
    } elseif ($firstArray === false) {
        $start = $firstObject;
    } else {
        $start = min($firstObject, $firstArray);
    }

    return trim(substr($body, $start));
}

function anaf_decode_json_response(string $body): array {
    $json = anaf_extract_json_string($body);

    if ($json === '') {
        return [
            'ok' => false,
            'data' => null,
            'error' => 'Raspuns gol de la ANAF.',
            'preview' => '',
        ];
    }

    $decoded = json_decode($json, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return [
            'ok' => true,
            'data' => $decoded,
            'error' => '',
            'preview' => '',
        ];
    }

    if (function_exists('mb_convert_encoding')) {
        $jsonUtf8 = mb_convert_encoding($json, 'UTF-8', 'UTF-8');
        $decoded = json_decode($jsonUtf8, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [
                'ok' => true,
                'data' => $decoded,
                'error' => '',
                'preview' => '',
            ];
        }
    }

    return [
        'ok' => false,
        'data' => null,
        'error' => json_last_error_msg(),
        'preview' => anaf_safe_substr($body),
    ];
}

function anaf_build_address(array $general, array $sediu, array $domiciliu): string {
    $direct = trim((string)($general['adresa'] ?? ''));

    if ($direct !== '') {
        return anaf_remove_diacritics($direct);
    }

    $parts = [];

    $street = anaf_first_non_empty($sediu['sdenumire_Strada'] ?? '', $domiciliu['ddenumire_Strada'] ?? '');
    $number = anaf_first_non_empty($sediu['snumar_Strada'] ?? '', $domiciliu['dnumar_Strada'] ?? '');
    $details = anaf_first_non_empty($sediu['sdetalii_Adresa'] ?? '', $domiciliu['ddetalii_Adresa'] ?? '');
    $locality = anaf_first_non_empty($sediu['sdenumire_Localitate'] ?? '', $domiciliu['ddenumire_Localitate'] ?? '');
    $county = anaf_first_non_empty($sediu['sdenumire_Judet'] ?? '', $domiciliu['ddenumire_Judet'] ?? '');
    $country = anaf_first_non_empty($sediu['stara'] ?? '', $domiciliu['dtara'] ?? '');

    if ($street !== '') {
        $parts[] = trim($street . ($number !== '' ? ' nr. ' . $number : ''));
    }

    if ($details !== '') {
        $parts[] = $details;
    }

    if ($locality !== '') {
        $parts[] = $locality;
    }

    if ($county !== '') {
        $parts[] = $county;
    }

    if ($country !== '') {
        $parts[] = $country;
    }

    return anaf_remove_diacritics(implode(', ', array_filter($parts)));
}

function anaf_http_post_json(string $url, array $payload): array {
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json, text/plain, */*',
                'Cache-Control: no-cache',
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PestZone CRM/1.0 (+https://app.pestzone.ro)',
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        if (defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        return [
            'ok' => $body !== false && $error === '',
            'status' => $status,
            'content_type' => $contentType,
            'body' => $body !== false ? (string)$body : '',
            'error' => $error,
            'errno' => $errno,
            'transport' => 'curl',
            'url' => $url,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json; charset=utf-8\r\nAccept: application/json, text/plain, */*\r\nUser-Agent: PestZone CRM/1.0\r\nCache-Control: no-cache\r\n",
            'content' => $jsonPayload,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;
    $contentType = '';

    if (!empty($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $headerLine, $m)) {
                $status = (int)$m[1];
            }
            if (stripos($headerLine, 'Content-Type:') === 0) {
                $contentType = trim(substr($headerLine, strlen('Content-Type:')));
            }
        }
    }

    return [
        'ok' => $body !== false,
        'status' => $status,
        'content_type' => $contentType,
        'body' => $body !== false ? (string)$body : '',
        'error' => $body === false ? 'Nu am putut apela serviciul ANAF.' : '',
        'errno' => 0,
        'transport' => 'stream',
        'url' => $url,
    ];
}

function anaf_call_service(array $payload): array {
    $endpoints = [
        'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva',
        'https://webservicesp.anaf.ro/PlatitorTvaRest/api/v8/ws/tva',
    ];

    $attempts = [];
    $lastResponse = null;

    foreach ($endpoints as $endpoint) {
        $response = anaf_http_post_json($endpoint, $payload);
        $lastResponse = $response;

        $attempt = [
            'url' => $endpoint,
            'status' => $response['status'] ?? 0,
            'content_type' => $response['content_type'] ?? '',
            'transport' => $response['transport'] ?? '',
            'error' => $response['error'] ?? '',
        ];

        if (!$response['ok']) {
            $attempt['result'] = 'transport_error';
            $attempts[] = $attempt;
            continue;
        }

        $decoded = anaf_decode_json_response($response['body']);

        if ($decoded['ok']) {
            $response['decoded'] = $decoded['data'];
            $response['decoded_ok'] = true;
            $response['attempts'] = $attempts;
            return $response;
        }

        $attempt['result'] = 'decode_error';
        $attempt['json_error'] = $decoded['error'];
        $attempt['response_preview'] = $decoded['preview'];
        $attempts[] = $attempt;

        $response['decoded_ok'] = false;
        $response['decode_error'] = $decoded['error'];
        $response['response_preview'] = $decoded['preview'];
        $lastResponse = $response;
    }

    if ($lastResponse) {
        $lastResponse['attempts'] = $attempts;
        return $lastResponse;
    }

    return [
        'ok' => false,
        'status' => 0,
        'content_type' => '',
        'body' => '',
        'error' => 'Nu exista endpoint ANAF disponibil.',
        'errno' => 0,
        'transport' => '',
        'url' => '',
        'decoded_ok' => false,
        'decode_error' => '',
        'response_preview' => '',
        'attempts' => $attempts,
    ];
}

$cuiInput = $_POST['cui'] ?? $_GET['cui'] ?? '';
$debug = isset($_GET['debug']) || isset($_POST['debug']);
$cui = anaf_clean_cui((string)$cuiInput);

if ($cui === '') {
    anaf_json_response([
        'success' => false,
        'message' => 'Introdu un CUI valid.',
    ], 400);
}

if (strlen($cui) < 2 || strlen($cui) > 10) {
    anaf_json_response([
        'success' => false,
        'message' => 'CUI-ul pare invalid. Verifica numarul introdus.',
    ], 400);
}

$requestDate = date('Y-m-d');
$requestPayload = [
    [
        'cui' => (int)$cui,
        'data' => $requestDate,
    ],
];

$response = anaf_call_service($requestPayload);

if (empty($response['ok'])) {
    anaf_json_response([
        'success' => false,
        'message' => 'Serviciul ANAF nu a raspuns. Incearca din nou mai tarziu.',
        'debug' => [
            'url' => $response['url'] ?? '',
            'transport' => $response['transport'] ?? '',
            'status' => $response['status'] ?? 0,
            'errno' => $response['errno'] ?? 0,
            'error' => $response['error'] ?? '',
            'attempts' => $response['attempts'] ?? [],
        ],
    ], 502);
}

if (empty($response['decoded_ok'])) {
    anaf_json_response([
        'success' => false,
        'message' => 'Raspuns invalid de la ANAF. Serverul a primit HTML/text in loc de JSON sau ANAF a returnat temporar o pagina de eroare.',
        'debug' => [
            'url' => $response['url'] ?? '',
            'http_status' => $response['status'] ?? 0,
            'content_type' => $response['content_type'] ?? '',
            'json_error' => $response['decode_error'] ?? '',
            'response_preview' => $response['response_preview'] ?? anaf_safe_substr($response['body'] ?? ''),
            'attempts' => $response['attempts'] ?? [],
        ],
    ], 502);
}

$decoded = anaf_remove_diacritics($response['decoded']);

if (isset($decoded['cod']) && (int)$decoded['cod'] !== 200 && !isset($decoded['found'])) {
    anaf_json_response([
        'success' => false,
        'message' => 'ANAF a returnat o eroare: ' . trim((string)($decoded['message'] ?? 'eroare necunoscuta')),
        'debug' => $debug ? $decoded : null,
    ], 502);
}

$notFound = $decoded['notFound'] ?? [];

if (!empty($notFound)) {
    anaf_json_response([
        'success' => false,
        'message' => 'CUI-ul nu a fost gasit in raspunsul ANAF.',
        'cui' => $cui,
        'debug' => $debug ? $decoded : null,
    ], 404);
}

$foundList = $decoded['found'] ?? [];
$found = is_array($foundList) && isset($foundList[0]) && is_array($foundList[0]) ? $foundList[0] : null;

if (!$found) {
    anaf_json_response([
        'success' => false,
        'message' => 'Nu am gasit date pentru acest CUI.',
        'debug' => $debug ? $decoded : null,
    ], 404);
}

$general = $found['date_generale'] ?? [];
$tva = $found['inregistrare_scop_Tva'] ?? [];
$inactive = $found['stare_inactiv'] ?? [];
$splitTva = $found['inregistrare_SplitTVA'] ?? [];
$sediuSocial = $found['adresa_sediu_social'] ?? [];
$domiciliuFiscal = $found['adresa_domiciliu_fiscal'] ?? [];

$registeredAddress = anaf_build_address($general, $sediuSocial, $domiciliuFiscal);

$data = [
    'client_type' => 'company',
    'name' => trim((string)anaf_remove_diacritics($general['denumire'] ?? '')),
    'fiscal_code' => trim((string)($general['cui'] ?? $cui)),
    'registry_number' => trim((string)anaf_remove_diacritics($general['nrRegCom'] ?? '')),
    'registered_address' => trim((string)$registeredAddress),

    // Nu mai preluam telefon de la ANAF.
    'phone' => '',

    'email' => '',
    'bank_name' => '',
    'bank_account' => trim((string)anaf_remove_diacritics($general['iban'] ?? '')),
    'legal_representative_name' => '',
    'legal_representative_role' => '',
    'anaf_last_lookup_at' => date('Y-m-d H:i:s'),
    'anaf_raw_response' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
    'tva' => [
        'scpTVA' => $tva['scpTVA'] ?? null,
        'mesaj_ScpTVA' => anaf_remove_diacritics($tva['mesaj_ScpTVA'] ?? ''),
        'statusSplitTVA' => $splitTva['statusSplitTVA'] ?? null,
        'statusRO_e_Factura' => $general['statusRO_e_Factura'] ?? null,
    ],
    'inactive' => [
        'statusInactivi' => $inactive['statusInactivi'] ?? null,
        'dataInactivare' => $inactive['dataInactivare'] ?? '',
        'dataReactivare' => $inactive['dataReactivare'] ?? '',
    ],
];

anaf_json_response([
    'success' => true,
    'message' => 'Datele firmei au fost gasite la ANAF.',
    'data' => $data,
    'debug' => $debug ? [
        'url' => $response['url'] ?? '',
        'http_status' => $response['status'] ?? 0,
        'content_type' => $response['content_type'] ?? '',
        'transport' => $response['transport'] ?? '',
        'attempts' => $response['attempts'] ?? [],
    ] : null,
]);
