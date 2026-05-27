<?php
/**
 * Proxy ANAF — Emma
 * Plasează în public_html și accesează via URL-ul exact (fără redirecturi).
 */

// Restrictiv la domeniul de productie ca proxy-ul sa nu fie folosit de alte site-uri.
// Daca ai nevoie sa-l consumi si din alta origine (ex: staging), seteaza-l acolo
// sau muta logica de Allow-Origin la o whitelist dinamica.
define('ALLOWED_ORIGIN', 'https://app.pestzone.ro');
define('ANAF_URL', 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva');
define('ANAF_BILANT_URL', 'https://webservicesp.anaf.ro/bilant');
define('DATA_GOV_API', 'https://data.gov.ro/api/3/action');
define('DATA_GOV_API_RO', 'https://data.gov.ro/ro/api/3/action');
define('MAX_CUIS', 500);

header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

function pz_proxy_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function pz_proxy_http_get_json(string $url, int $timeout = 20): array
{
    $body = '';
    $status = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Emma-ANAF-Proxy/1.0',
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $body = $raw !== false ? (string)$raw : '';
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: Emma-ANAF-Proxy/1.0\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $body = $raw !== false ? (string)$raw : '';
        if (!empty($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                    $status = (int)$m[1];
                }
            }
        }
        if ($raw === false) {
            $error = 'Nu s-a putut citi răspunsul.';
        }
    }

    $json = json_decode($body, true);
    return [
        'ok' => $status >= 200 && $status < 300 && is_array($json),
        'status' => $status,
        'error' => $error,
        'body_preview' => substr($body, 0, 500),
        'json' => is_array($json) ? $json : null,
        'url' => $url,
    ];
}

function pz_onrc_digits($value): string
{
    return preg_replace('/[^0-9]/', '', (string)$value) ?? '';
}

function pz_onrc_norm_key(string $key): string
{
    $ascii = $key;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
        if ($converted !== false) {
            $ascii = $converted;
        }
    }
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', $ascii) ?? '');
}

function pz_onrc_pick(array $row, array $names): string
{
    $normalized = [];
    foreach ($row as $key => $value) {
        $normalized[pz_onrc_norm_key((string)$key)] = $value;
    }
    foreach ($names as $name) {
        $normName = pz_onrc_norm_key($name);
        if (array_key_exists($normName, $normalized)) {
            return trim((string)$normalized[$normName]);
        }
    }
    return '';
}

function pz_onrc_row_matches_cui(array $row, string $cui): bool
{
    foreach ($row as $key => $value) {
        if (stripos((string)$key, 'cui') !== false && pz_onrc_digits($value) === $cui) {
            return true;
        }
    }
    return false;
}

function pz_onrc_value_matches($value, string $needle): bool
{
    $valueDigits = pz_onrc_digits($value);
    $needleDigits = pz_onrc_digits($needle);
    if ($valueDigits !== '' && $needleDigits !== '' && $valueDigits === $needleDigits) {
        return true;
    }
    return pz_onrc_norm_key((string)$value) === pz_onrc_norm_key($needle);
}

function pz_onrc_row_matches_columns(array $row, string $needle, array $columns): bool
{
    foreach ($columns as $column) {
        $value = pz_onrc_pick($row, [$column]);
        if ($value !== '' && pz_onrc_value_matches($value, $needle)) {
            return true;
        }
    }
    return false;
}

function pz_onrc_find_resource(array $resources, string $needle): ?array
{
    $needle = strtoupper($needle);
    foreach ($resources as $resource) {
        $name = strtoupper((string)($resource['name'] ?? ''));
        $url = strtoupper((string)($resource['url'] ?? ''));
        if (strpos($name, $needle) !== false || strpos($url, $needle) !== false) {
            return $resource;
        }
    }
    return null;
}

function pz_onrc_latest_package(): array
{
    $query = http_build_query([
        'fq' => 'organization:onrc',
        'q' => 'Firme înregistrate la Registrul Comerțului',
        'sort' => 'metadata_modified desc',
        'rows' => 10,
    ]);
    $res = pz_proxy_http_get_json(DATA_GOV_API . '/package_search?' . $query);
    if (!$res['ok'] || empty($res['json']['success'])) {
        return ['ok' => false, 'error' => 'Nu am putut citi catalogul data.gov.ro.', 'debug' => $res];
    }

    foreach (($res['json']['result']['results'] ?? []) as $package) {
        $title = (string)($package['title'] ?? '');
        if (stripos($title, 'Firme înregistrate la Registrul Comerțului') !== false) {
            return ['ok' => true, 'package' => $package];
        }
    }

    return ['ok' => false, 'error' => 'Nu am găsit ultimul set ONRC pentru firme.'];
}

function pz_onrc_csv_search(string $url, string $needle, array $columns, int $limit = 20): array
{
    @set_time_limit(180);

    if ($url === '') {
        return ['ok' => false, 'records' => []];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 90,
            'ignore_errors' => true,
            'header' => "User-Agent: Emma-ANAF-Proxy/1.0\r\n",
        ],
    ]);
    $handle = @fopen($url, 'r', false, $context);
    if (!$handle) {
        return ['ok' => false, 'records' => []];
    }

    $header = null;
    $matchIndexes = [];
    $records = [];

    while (($values = fgetcsv($handle, 0, '^', '"', '')) !== false) {
        if ($header === null) {
            $header = array_map(function ($item) {
                return preg_replace('/^\xEF\xBB\xBF/', '', trim((string)$item));
            }, $values);
            $normalizedColumns = array_map('pz_onrc_norm_key', $columns);
            foreach ($header as $index => $name) {
                if (in_array(pz_onrc_norm_key((string)$name), $normalizedColumns, true)) {
                    $matchIndexes[] = $index;
                }
            }
            continue;
        }

        if (!is_array($header) || count($header) === 0 || count($matchIndexes) === 0) {
            continue;
        }

        $matches = false;
        foreach ($matchIndexes as $index) {
            if (isset($values[$index]) && pz_onrc_value_matches($values[$index], $needle)) {
                $matches = true;
                break;
            }
        }

        if ($matches) {
            if (count($values) < count($header)) {
                $values = array_pad($values, count($header), '');
            }
            $row = array_combine($header, array_slice($values, 0, count($header)));
            $records[] = $row;
            if (count($records) >= $limit) {
                break;
            }
        }
    }

    fclose($handle);
    return ['ok' => true, 'records' => $records];
}

function pz_onrc_resource_search(array $resource, string $needle, array $columns, int $limit = 20, bool $allowCsvFallback = true): array
{
    $resourceId = (string)($resource['id'] ?? '');
    $resourceUrl = (string)($resource['url'] ?? '');
    $query = http_build_query([
        'resource_id' => $resourceId,
        'q' => $needle,
        'limit' => $limit,
    ]);

    if ($resourceId !== '') {
        foreach ([DATA_GOV_API, DATA_GOV_API_RO] as $base) {
            $res = pz_proxy_http_get_json($base . '/datastore_search?' . $query, 25);
            if (!empty($res['ok']) && !empty($res['json']['success'])) {
                $records = $res['json']['result']['records'] ?? [];
                if (is_array($records)) {
                    $exact = array_values(array_filter($records, function ($row) use ($needle, $columns) {
                        return is_array($row) && pz_onrc_row_matches_columns($row, $needle, $columns);
                    }));
                    return ['ok' => true, 'records' => $exact ?: $records, 'resource_id' => $resourceId, 'source' => 'datastore'];
                }
            }
        }
    }

    if (!$allowCsvFallback) {
        return ['ok' => false, 'records' => [], 'resource_id' => $resourceId, 'source' => 'unavailable'];
    }

    $csv = pz_onrc_csv_search($resourceUrl, $needle, $columns, $limit);
    return [
        'ok' => $csv['ok'],
        'records' => $csv['records'] ?? [],
        'resource_id' => $resourceId,
        'source' => 'csv',
    ];
}

function pz_onrc_normalize_firma(array $row): array
{
    return [
        'denumire' => pz_onrc_pick($row, ['DENUMIRE', 'FIRMA', 'NUME_FIRMA']),
        'cui' => pz_onrc_pick($row, ['CUI']),
        'reg_com' => pz_onrc_pick($row, ['COD_INMATRICULARE', 'NR_REG_COM', 'NUMAR_REGISTRU_COMERTULUI']),
        'euid' => pz_onrc_pick($row, ['EUID']),
        'stare' => pz_onrc_pick($row, ['STARE_FIRMA', 'STARE']),
        'adresa' => pz_onrc_pick($row, ['ADRESA_COMPLETA', 'ADRESA']),
        'tara' => pz_onrc_pick($row, ['ADR_TARA', 'TARA']),
        'judet' => pz_onrc_pick($row, ['ADR_JUDET', 'JUDET']),
        'localitate' => pz_onrc_pick($row, ['ADR_LOCALITATE', 'LOCALITATE']),
        'strada' => pz_onrc_pick($row, ['ADR_DEN_STRADA', 'STRADA']),
        'numar' => pz_onrc_pick($row, ['ADR_NR_STRADA', 'ADR_DEN_NR_STRADA', 'NUMAR']),
        'cod_postal' => pz_onrc_pick($row, ['ADR_COD_POSTAL', 'COD_POSTAL']),
        'raw' => $row,
    ];
}

function pz_onrc_normalize_caen(array $row): array
{
    return [
        'cod' => pz_onrc_pick($row, ['COD_CAEN_AUTORIZAT', 'COD_CAEN', 'CAEN', 'CLASA_CAEN']),
        'denumire' => pz_onrc_pick($row, ['DENUMIRE_CAEN', 'DEN_CAEN', 'DENUMIRE_ACTIVITATE']),
        'tip' => pz_onrc_pick($row, ['TIP_ACTIVITATE', 'TIP', 'ACTIVITATE']),
        'raw' => $row,
    ];
}

function pz_onrc_normalize_reprezentant(array $row): array
{
    return [
        'nume' => pz_onrc_pick($row, ['PERSOANA_IMPUTERNICITA', 'NUME', 'DENUMIRE', 'NUME_REPREZENTANT', 'REPREZENTANT']),
        'prenume' => pz_onrc_pick($row, ['PRENUME']),
        'calitate' => pz_onrc_pick($row, ['CALITATE', 'FUNCTIE', 'CALITATE_REPREZENTANT']),
        'raw' => $row,
    ];
}

function pz_onrc_lookup(string $cui): array
{
    $cui = pz_onrc_digits($cui);
    if ($cui === '') {
        return ['ok' => false, 'error' => 'CUI invalid.'];
    }

    $latest = pz_onrc_latest_package();
    if (!$latest['ok']) {
        return $latest;
    }

    $package = $latest['package'];
    $resources = is_array($package['resources'] ?? null) ? $package['resources'] : [];
    $firmaResource = pz_onrc_find_resource($resources, 'OD_FIRME');
    $caenResource = pz_onrc_find_resource($resources, 'OD_CAEN_AUTORIZAT');
    $repResource = pz_onrc_find_resource($resources, 'OD_REPREZENTANTI_LEGALI');

    $firmaRows = $firmaResource ? pz_onrc_resource_search($firmaResource, $cui, ['CUI'], 1) : ['records' => []];
    $firma = !empty($firmaRows['records'][0]) ? pz_onrc_normalize_firma($firmaRows['records'][0]) : null;
    $regCom = $firma['reg_com'] ?? '';
    $caenRows = $caenResource && $regCom !== '' ? pz_onrc_resource_search($caenResource, $regCom, ['COD_INMATRICULARE'], 50, false) : ['records' => []];
    $repRows = $repResource && $regCom !== '' ? pz_onrc_resource_search($repResource, $regCom, ['COD_INMATRICULARE'], 30, false) : ['records' => []];

    return [
        'ok' => true,
        'dataset' => [
            'title' => (string)($package['title'] ?? ''),
            'name' => (string)($package['name'] ?? ''),
            'metadata_modified' => (string)($package['metadata_modified'] ?? ''),
            'url' => 'https://data.gov.ro/dataset/' . rawurlencode((string)($package['name'] ?? '')),
        ],
        'resources' => [
            'firma' => $firmaResource ? ['id' => $firmaResource['id'] ?? '', 'name' => $firmaResource['name'] ?? '', 'url' => $firmaResource['url'] ?? ''] : null,
            'caen' => $caenResource ? ['id' => $caenResource['id'] ?? '', 'name' => $caenResource['name'] ?? '', 'url' => $caenResource['url'] ?? ''] : null,
            'reprezentanti' => $repResource ? ['id' => $repResource['id'] ?? '', 'name' => $repResource['name'] ?? '', 'url' => $repResource['url'] ?? ''] : null,
        ],
        'lookup_source' => [
            'firma' => $firmaRows['source'] ?? '',
            'caen' => $caenRows['source'] ?? '',
            'reprezentanti' => $repRows['source'] ?? '',
        ],
        'found' => [
            'firma' => $firma,
            'caen_autorizat' => array_map('pz_onrc_normalize_caen', array_slice($caenRows['records'] ?? [], 0, 20)),
            'reprezentanti_legali' => array_map('pz_onrc_normalize_reprezentant', array_slice($repRows['records'] ?? [], 0, 12)),
        ],
    ];
}

// ── Preflight CORS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── GET cu action=onrc — date ONRC din ultimul set public data.gov.ro ─────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'onrc') {
    @set_time_limit(180);
    $cui = pz_onrc_digits($_GET['cui'] ?? '');

    if ($cui === '') {
        pz_proxy_json(['error' => 'CUI invalid.'], 400);
    }

    $result = pz_onrc_lookup($cui);
    if (empty($result['ok'])) {
        pz_proxy_json(['error' => $result['error'] ?? 'Nu am putut citi datele ONRC.', 'debug' => $result['debug'] ?? null], 502);
    }

    pz_proxy_json($result);
}

// ── GET cu action=bilant — proxy pentru bilanț ANAF ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'bilant') {
    $cui = (int) ($_GET['cui'] ?? 0);
    $an  = (int) ($_GET['an']  ?? (date('Y') - 1));

    if ($cui <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'CUI invalid.']);
        exit;
    }

    $url = ANAF_BILANT_URL . '?an=' . $an . '&cui=' . $cui;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Emma-ANAF-Proxy/1.0',
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Nu s-a putut contacta ANAF.', 'details' => $err]);
        exit;
    }
    http_response_code($httpCode);
    echo $resp;
    exit;
}

// ── GET — diagnosticare ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $testPayload = json_encode([['cui' => 14388698, 'data' => date('Y-m-d')]]);
    $ch = curl_init(ANAF_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $testPayload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp      = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr   = curl_error($ch);
    curl_close($ch);

    echo json_encode([
        'status'          => 'ok',
        'proxy'           => 'anaf_proxy.php funcționează corect',
        'url_accesat'     => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
        'anaf_http_code'  => $httpCode,
        'anaf_curl_error' => $curlErr ?: null,
        'anaf_raspuns'    => $resp ? json_decode($resp, true) : null,
        'instructiune'    => 'Copiaza "url_accesat" de mai sus si foloseste-l exact in aplicatie.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── POST — proxy spre ANAF ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodă nepermisă.']);
    exit;
}

// ── Citire și validare body ───────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Body invalid. Se așteaptă un array JSON cu obiecte {cui, data}.']);
    exit;
}

if (count($data) > MAX_CUIS) {
    http_response_code(400);
    echo json_encode(['error' => 'Maxim ' . MAX_CUIS . ' CUI-uri per cerere.']);
    exit;
}

// Sanitizare intrare — păstrăm doar câmpurile necesare
$payload = [];
foreach ($data as $item) {
    if (!isset($item['cui']) || !is_numeric($item['cui'])) continue;
    $payload[] = [
        'cui'  => (int) $item['cui'],
        'data' => isset($item['data']) ? preg_replace('/[^0-9\-]/', '', $item['data']) : date('Y-m-d'),
    ];
}

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Niciun CUI valid în cerere.']);
    exit;
}

// ── Cerere cURL spre ANAF ─────────────────────────────────────────────────────
$jsonPayload = json_encode($payload);

$ch = curl_init(ANAF_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $jsonPayload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonPayload),
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'Emma-ANAF-Proxy/1.0',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ── Gestionare erori cURL ─────────────────────────────────────────────────────
if ($response === false) {
    http_response_code(502);
    echo json_encode([
        'error'   => 'Nu s-a putut contacta ANAF.',
        'details' => $curlError,
    ]);
    exit;
}

// ── Răspuns ───────────────────────────────────────────────────────────────────
http_response_code($httpCode);
echo $response;
