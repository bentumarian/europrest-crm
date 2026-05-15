<?php
/**
 * PestZone CRM - oblio_api_lib.php
 * Oblio API connector. Oblio ramane sursa fiscala oficiala.
 */

if (!function_exists('oblio_h')) {
    function oblio_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function oblio_ensure_settings_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
            setting_value LONGTEXT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function oblio_settings_defaults(): array
{
    return [
        'billing.provider' => 'oblio',
        'billing.enabled' => '0',
        'oblio.client_id' => '',
        'oblio.client_secret' => '',
        'oblio.company_cif' => '',
        'oblio.invoice_series' => '',
        'oblio.proforma_series' => '',
        'oblio.receipt_series' => '',
        'oblio.vat_name' => 'Normala',
        'oblio.vat_percentage' => '21',
        'oblio.vat_included' => '0',
        'oblio.currency' => 'RON',
        'oblio.language' => 'RO',
        'oblio.precision' => '2',
        'oblio.default_due_days' => '15',
        'oblio.work_station' => 'Sediu',
        'oblio.use_stock' => '0',
        'oblio.send_email' => '0',
        'oblio.spv_extern' => '0',
        'oblio.issuer_name' => '',
        'oblio.default_product_name' => 'Servicii DDD conform contract / lucrare',
        'oblio.default_measuring_unit' => 'buc',
        'oblio.receipt_series' => '',
        'oblio.cached_vat_rates_json' => '',
        'oblio.sync_days_back' => '30',
        'oblio.cron_key' => '',
    ];
}

function oblio_settings(PDO $pdo): array
{
    oblio_ensure_settings_schema($pdo);
    $settings = oblio_settings_defaults();

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
    } catch (Throwable $e) {}

    return $settings;
}

function oblio_set_settings(PDO $pdo, array $values): void
{
    oblio_ensure_settings_schema($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");

    foreach ($values as $key => $value) {
        $stmt->execute([(string)$key, (string)$value]);
    }
}

function oblio_mask_secret(string $secret): string
{
    $secret = trim($secret);
    if ($secret === '') return '';
    if (strlen($secret) <= 8) return str_repeat('*', strlen($secret));
    return substr($secret, 0, 4) . str_repeat('*', max(4, strlen($secret) - 8)) . substr($secret, -4);
}

function oblio_clean_cif(string $cif): string
{
    $cif = strtoupper(trim($cif));
    return str_replace([' ', '-', '.', '/'], '', $cif);
}

function oblio_bool_value($v): int
{
    return !empty($v) && (string)$v !== '0' ? 1 : 0;
}

function oblio_json_decode(string $raw): array
{
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['status' => 0, 'statusMessage' => 'Raspuns invalid sau gol de la Oblio.', 'raw' => $raw];
    }
    return $data;
}

function oblio_http_request(string $method, string $url, array $headers = [], $body = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'error' => 'Extensia PHP cURL nu este activa pe server.', 'raw' => '', 'json' => []];
    }

    $ch = curl_init($url);
    $finalHeaders = array_merge(['Accept: application/json'], $headers);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);

    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($raw === false) $raw = '';
    $json = oblio_json_decode((string)$raw);
    $apiStatus = (int)($json['status'] ?? 0);

    $ok = false;
    if ($httpCode >= 200 && $httpCode < 300) {
        if (isset($json['access_token'])) $ok = true;
        elseif ($apiStatus === 200) $ok = true;
        elseif (isset($json['data']) && $apiStatus === 0) $ok = true;
    }

    if ($err !== '') {
        return ['ok' => false, 'http_code' => $httpCode, 'error' => $err, 'raw' => $raw, 'json' => $json, 'content_type' => $contentType];
    }

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'error' => $ok ? '' : (string)($json['statusMessage'] ?? $json['error_description'] ?? $json['error'] ?? 'Eroare Oblio API.'),
        'raw' => $raw,
        'json' => $json,
        'content_type' => $contentType,
    ];
}

function oblio_raw_get(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL nu este activ.', 'body' => '', 'http_code' => 0, 'content_type' => ''];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'PestZoneCRM/1.0',
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false) $body = '';

    return [
        'ok' => $err === '' && $httpCode >= 200 && $httpCode < 300 && $body !== '',
        'error' => $err,
        'body' => $body,
        'http_code' => $httpCode,
        'content_type' => $contentType,
    ];
}

function oblio_get_access_token(PDO $pdo): array
{
    $s = oblio_settings($pdo);
    $clientId = trim((string)($s['oblio.client_id'] ?? ''));
    $clientSecret = trim((string)($s['oblio.client_secret'] ?? ''));

    if ($clientId === '' || $clientSecret === '') {
        return ['ok' => false, 'error' => 'Completeaza emailul contului Oblio si API secret.'];
    }

    $body = http_build_query(['client_id' => $clientId, 'client_secret' => $clientSecret]);

    $res = oblio_http_request(
        'POST',
        'https://www.oblio.eu/api/authorize/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        $body
    );

    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => $res['error'] ?: 'Nu s-a putut obtine token Oblio.', 'response' => $res];
    }

    $token = (string)($res['json']['access_token'] ?? '');
    if ($token === '') return ['ok' => false, 'error' => 'Oblio nu a returnat access_token.', 'response' => $res];

    return ['ok' => true, 'access_token' => $token, 'expires_in' => (int)($res['json']['expires_in'] ?? 3600), 'response' => $res];
}

function oblio_api(PDO $pdo, string $method, string $endpoint, array $query = [], ?array $jsonBody = null): array
{
    $token = oblio_get_access_token($pdo);
    if (empty($token['ok'])) return $token;

    $url = 'https://www.oblio.eu/api' . $endpoint;
    if ($query) $url .= '?' . http_build_query($query);

    $headers = ['Authorization: Bearer ' . $token['access_token']];
    $body = null;

    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $res = oblio_http_request($method, $url, $headers, $body);

    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => $res['error'] ?: 'Eroare API Oblio.', 'response' => $res];
    }

    return ['ok' => true, 'data' => $res['json']['data'] ?? null, 'response' => $res];
}

function oblio_api_form(PDO $pdo, string $method, string $endpoint, array $form): array
{
    $token = oblio_get_access_token($pdo);
    if (empty($token['ok'])) return $token;

    $url = 'https://www.oblio.eu/api' . $endpoint;
    $headers = [
        'Authorization: Bearer ' . $token['access_token'],
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $res = oblio_http_request($method, $url, $headers, http_build_query($form));

    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => $res['error'] ?: 'Eroare API Oblio.', 'response' => $res];
    }

    return ['ok' => true, 'data' => $res['json']['data'] ?? null, 'response' => $res];
}

function oblio_test_connection(PDO $pdo): array { return oblio_get_companies($pdo); }
function oblio_get_companies(PDO $pdo): array { return oblio_api($pdo, 'GET', '/nomenclature/companies'); }

function oblio_get_series(PDO $pdo, ?string $cif = null): array
{
    $s = oblio_settings($pdo);
    $cif = oblio_clean_cif($cif ?: (string)($s['oblio.company_cif'] ?? ''));
    if ($cif === '') return ['ok' => false, 'error' => 'Completeaza CUI/CIF firma emitenta.'];
    return oblio_api($pdo, 'GET', '/nomenclature/series', ['cif' => $cif]);
}

function oblio_get_vat_rates(PDO $pdo, ?string $cif = null): array
{
    $s = oblio_settings($pdo);
    $cif = oblio_clean_cif($cif ?: (string)($s['oblio.company_cif'] ?? ''));
    if ($cif === '') return ['ok' => false, 'error' => 'Completeaza CUI/CIF firma emitenta.'];
    return oblio_api($pdo, 'GET', '/nomenclature/vat_rates', ['cif' => $cif]);
}


function oblio_cache_vat_rates(PDO $pdo, array $vatRates): void
{
    $rows = [];

    foreach ($vatRates as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = trim((string)($row['name'] ?? $row['vatName'] ?? ''));
        $percent = $row['percent'] ?? $row['percentage'] ?? $row['vatPercentage'] ?? null;

        if ($name === '' || $percent === null || $percent === '') {
            continue;
        }

        $rows[] = [
            'name' => $name,
            'percentage' => (float)$percent,
        ];
    }

    if ($rows) {
        oblio_set_settings($pdo, [
            'oblio.cached_vat_rates_json' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

function oblio_issue_document(PDO $pdo, string $type, array $payload): array
{
    $type = strtolower($type);
    if (!in_array($type, ['invoice', 'proforma'], true)) return ['ok' => false, 'error' => 'Tip document Oblio invalid.'];
    return oblio_api($pdo, 'POST', $type === 'invoice' ? '/docs/invoice' : '/docs/proforma', [], $payload);
}

function oblio_get_document(PDO $pdo, string $type, string $seriesName, string $number): array
{
    $s = oblio_settings($pdo);
    $cif = oblio_clean_cif((string)($s['oblio.company_cif'] ?? ''));
    if ($cif === '') return ['ok' => false, 'error' => 'Completeaza CUI/CIF firma emitenta.'];

    $type = strtolower($type);
    if (!in_array($type, ['invoice', 'proforma', 'notice'], true)) return ['ok' => false, 'error' => 'Tip document invalid.'];

    return oblio_api($pdo, 'GET', '/docs/' . $type, [
        'cif' => $cif,
        'seriesName' => $seriesName,
        'number' => $number,
    ]);
}

function oblio_list_invoices(PDO $pdo, array $filters = []): array
{
    $s = oblio_settings($pdo);
    $cif = oblio_clean_cif((string)($s['oblio.company_cif'] ?? ''));
    if ($cif === '') return ['ok' => false, 'error' => 'Completeaza CUI/CIF firma emitenta.'];

    $query = array_merge([
        'cif' => $cif,
        'draft' => -1,
        'canceled' => -1,
        'collected' => -1,
        'withProducts' => 1,
        'withCollects' => 1,
        'withEinvoiceStatus' => 1,
        'orderBy' => 'issueDate',
        'orderDir' => 'DESC',
        'limitPerPage' => 100,
        'offset' => 0,
    ], $filters);

    return oblio_api($pdo, 'GET', '/docs/invoice/list', $query);
}

function oblio_list_invoices_by_client(PDO $pdo, string $clientCif, array $filters = []): array
{
    $clientCif = oblio_clean_cif($clientCif);
    $filters['client'] = ['cif' => $clientCif];
    return oblio_list_invoices($pdo, $filters);
}

function oblio_collect_invoice(PDO $pdo, string $seriesName, string $number, array $collect): array
{
    $s = oblio_settings($pdo);
    $cif = oblio_clean_cif((string)($s['oblio.company_cif'] ?? ''));
    if ($cif === '') return ['ok' => false, 'error' => 'Completeaza CUI/CIF firma emitenta.'];

    $form = [
        'cif' => $cif,
        'seriesName' => $seriesName,
        'number' => $number,
        'collect' => $collect,
    ];

    return oblio_api_form($pdo, 'PUT', '/docs/invoice/collect', $form);
}

function oblio_build_client_payload(array $client): array
{
    return [
        'cif' => trim((string)($client['fiscal_code'] ?? '')),
        'name' => trim((string)($client['name'] ?? $client['client_name'] ?? '')),
        'rc' => trim((string)($client['registry_number'] ?? '')),
        'code' => trim((string)($client['id'] ?? '')),
        'address' => trim((string)($client['registered_address'] ?? $client['address'] ?? '')),
        'state' => trim((string)($client['county'] ?? $client['state'] ?? '')),
        'city' => trim((string)($client['city'] ?? '')),
        'country' => 'Romania',
        'iban' => trim((string)($client['bank_account'] ?? '')),
        'bank' => trim((string)($client['bank_name'] ?? '')),
        'email' => trim((string)($client['email'] ?? $client['client_email'] ?? '')),
        'phone' => trim((string)($client['phone'] ?? $client['client_phone'] ?? '')),
        'contact' => trim((string)($client['contact_person'] ?? $client['legal_representative_name'] ?? '')),
        'vatPayer' => 0,
        'save' => 1,
        'autocomplete' => trim((string)($client['fiscal_code'] ?? '')) !== '' ? 1 : 0,
    ];
}

function oblio_build_service_product(PDO $pdo, string $name, float $price, float $quantity = 1, string $description = '', array $overrides = []): array
{
    $s = oblio_settings($pdo);

    $product = [
        'name' => $name !== '' ? $name : (string)($s['oblio.default_product_name'] ?? 'Servicii DDD'),
        'code' => '',
        'description' => $description,
        'price' => $price,
        'measuringUnit' => (string)($s['oblio.default_measuring_unit'] ?? 'buc'),
        'currency' => (string)($s['oblio.currency'] ?? 'RON'),
        'vatName' => (string)($s['oblio.vat_name'] ?? 'Normala'),
        'vatPercentage' => (float)($s['oblio.vat_percentage'] ?? 19),
        'vatIncluded' => oblio_bool_value($s['oblio.vat_included'] ?? '0'),
        'quantity' => $quantity,
        'productType' => 'Serviciu',
        'save' => 1,
    ];

    foreach ($overrides as $k => $v) {
        if ($v !== null && $v !== '') $product[$k] = $v;
    }

    return $product;
}

function oblio_basic_document_payload(PDO $pdo, string $documentType, array $client, array $products, array $extra = []): array
{
    $s = oblio_settings($pdo);

    $documentType = strtolower($documentType);
    $cif = oblio_clean_cif((string)($s['oblio.company_cif'] ?? ''));

    $series = $documentType === 'invoice'
        ? trim((string)($s['oblio.invoice_series'] ?? ''))
        : trim((string)($s['oblio.proforma_series'] ?? ''));

    $issueDate = $extra['issueDate'] ?? date('Y-m-d');
    $dueDays = max(0, (int)($s['oblio.default_due_days'] ?? 15));
    $dueDate = $extra['dueDate'] ?? date('Y-m-d', strtotime('+' . $dueDays . ' days'));

    $payload = [
        'cif' => $cif,
        'client' => oblio_build_client_payload($client),
        'issueDate' => $issueDate,
        'dueDate' => $dueDate,
        'seriesName' => $series,
        'language' => (string)($s['oblio.language'] ?? 'RO'),
        'precision' => (int)($s['oblio.precision'] ?? 2),
        'currency' => (string)($s['oblio.currency'] ?? 'RON'),
        'products' => $products,
        'issuerName' => (string)($s['oblio.issuer_name'] ?? ''),
        'mentions' => $extra['mentions'] ?? '',
        'internalNote' => $extra['internalNote'] ?? 'Document emis din PestZone CRM',
        'workStation' => (string)($s['oblio.work_station'] ?? 'Sediu'),
        'sendEmail' => oblio_bool_value($s['oblio.send_email'] ?? '0'),
        'idempotencyKey' => $extra['idempotencyKey'] ?? uniqid('pz_', true),
    ];

    if ($documentType === 'invoice') {
        $payload['deliveryDate'] = $extra['deliveryDate'] ?? $issueDate;
        $payload['useStock'] = oblio_bool_value($s['oblio.use_stock'] ?? '0');
        $payload['spvExtern'] = oblio_bool_value($s['oblio.spv_extern'] ?? '0');

        if (!empty($extra['collect']) && is_array($extra['collect'])) {
            $payload['collect'] = $extra['collect'];
        }
    }

    foreach ($payload as $key => $value) {
        if ($value === null || $value === '') unset($payload[$key]);
    }

    return $payload;
}
