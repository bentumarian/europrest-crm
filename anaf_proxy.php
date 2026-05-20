<?php
/**
 * Proxy ANAF — PestZone
 * Plasează în public_html și accesează via URL-ul exact (fără redirecturi).
 */

define('ALLOWED_ORIGIN', '*'); // sau: 'https://domeniultau.ro'
define('ANAF_URL', 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva');
define('ANAF_BILANT_URL', 'https://webservicesp.anaf.ro/bilant');
define('MAX_CUIS', 500);

header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// ── Preflight CORS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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
        CURLOPT_USERAGENT      => 'PestZone-ANAF-Proxy/1.0',
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
    CURLOPT_USERAGENT      => 'PestZone-ANAF-Proxy/1.0',
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
