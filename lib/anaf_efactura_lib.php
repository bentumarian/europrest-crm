<?php
/*
|--------------------------------------------------------------------------
| ANAF e-Factura — librărie de conexiune directă
|--------------------------------------------------------------------------
| Implementează OAuth2 cu certificat digital pentru SPV ANAF
| și funcțiile de listare / descărcare / parsare facturi UBL 2.1.
|
| Documentație oficială:
|   https://mfinante.gov.ro/static/10/eFactura/prezentare%20api%20efactura.pdf
|   https://static.anaf.ro/static/10/Anaf/Informatii_R/API/Oauth_procedura_inregistrare_aplicatii_portal_ANAF.pdf
|
| Endpoint-uri folosite (produs):
|   AUTH:       https://logincert.anaf.ro/anaf-oauth2/v1/authorize
|   TOKEN:      https://logincert.anaf.ro/anaf-oauth2/v1/token
|   LISTA:      https://api.anaf.ro/prod/FCTEL/rest/listaMesajeFactura
|   LISTA PG:   https://api.anaf.ro/prod/FCTEL/rest/listaMesajePaginatieFactura
|   DESCARCARE: https://api.anaf.ro/prod/FCTEL/rest/descarcare?id={id}
|   STARE:      https://api.anaf.ro/prod/FCTEL/rest/stareMesaj?id_incarcare={id}
|   UPLOAD:     https://api.anaf.ro/prod/FCTEL/rest/upload?standard=UBL&cif={cif}
|--------------------------------------------------------------------------
*/

if (!defined('ANAF_EFACTURA_LIB')) {
    define('ANAF_EFACTURA_LIB', '1.0');
}

/* ───────────────────────── Constante endpoint ANAF ───────────────────────── */

if (!defined('ANAF_OAUTH_AUTHORIZE')) {
    define('ANAF_OAUTH_AUTHORIZE', 'https://logincert.anaf.ro/anaf-oauth2/v1/authorize');
}
if (!defined('ANAF_OAUTH_TOKEN')) {
    define('ANAF_OAUTH_TOKEN', 'https://logincert.anaf.ro/anaf-oauth2/v1/token');
}
if (!defined('ANAF_API_BASE')) {
    define('ANAF_API_BASE', 'https://api.anaf.ro/prod/FCTEL/rest');
}
if (!defined('ANAF_API_BASE_TEST')) {
    define('ANAF_API_BASE_TEST', 'https://api.anaf.ro/test/FCTEL/rest');
}
if (!defined('ANAF_EFACTURA_DIR')) {
    // Folder unde se salvează XML-urile/PDF-urile descărcate (relativ la root)
    define('ANAF_EFACTURA_DIR', 'storage/efactura');
}

/* ───────────────────────── Schema BD ───────────────────────── */

if (!function_exists('anaf_efactura_ensure_schema')) {
    function anaf_efactura_ensure_schema(PDO $pdo): void
    {
        // Tabel pentru token-uri OAuth (un singur rând activ per CIF)
        $pdo->exec("CREATE TABLE IF NOT EXISTS anaf_oauth_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cif VARCHAR(20) NOT NULL,
            access_token TEXT NOT NULL,
            refresh_token TEXT NOT NULL,
            token_type VARCHAR(40) NOT NULL DEFAULT 'Bearer',
            expires_at DATETIME NOT NULL,
            refreshed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_anaf_cif (cif)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Tabel log pentru toate apelurile către ANAF (audit + debug)
        $pdo->exec("CREATE TABLE IF NOT EXISTS anaf_efactura_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cif VARCHAR(20) NULL,
            action VARCHAR(60) NOT NULL,
            endpoint VARCHAR(255) NULL,
            request_payload LONGTEXT NULL,
            response_status INT NULL,
            response_body LONGTEXT NULL,
            error_message TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_anaf_log_action (action),
            KEY idx_anaf_log_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Coloane noi pentru facturile primite — legătură cu ANAF
        $supplierColumns = [
            'anaf_message_id'   => "VARCHAR(80) NULL",
            'anaf_upload_id'    => "VARCHAR(80) NULL",
            'anaf_message_type' => "VARCHAR(40) NULL",
            'anaf_synced_at'    => "DATETIME NULL",
        ];
        foreach ($supplierColumns as $column => $definition) {
            if (!anaf_efactura_column_exists($pdo, 'smartbill_supplier_invoices', $column)) {
                try {
                    $pdo->exec("ALTER TABLE smartbill_supplier_invoices ADD COLUMN {$column} {$definition}");
                } catch (Throwable $e) {
                    error_log('ANAF efactura supplier_invoices column error: ' . $column . ' - ' . $e->getMessage());
                }
            }
        }

        // Index pe anaf_message_id pentru a evita duplicate
        try {
            $hasIdx = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'smartbill_supplier_invoices' AND INDEX_NAME = 'uq_anaf_message_id'")->fetchColumn();
            if (!(int)$hasIdx) {
                $pdo->exec("ALTER TABLE smartbill_supplier_invoices ADD UNIQUE KEY uq_anaf_message_id (anaf_message_id)");
            }
        } catch (Throwable $e) {
            error_log('ANAF efactura unique index error: ' . $e->getMessage());
        }

        // Coloane noi pentru facturile trimise — corelare cu mesajele ANAF
        $invoiceColumns = [
            'anaf_message_id' => "VARCHAR(80) NULL",
            'anaf_upload_id'  => "VARCHAR(80) NULL",
        ];
        foreach ($invoiceColumns as $column => $definition) {
            if (!anaf_efactura_column_exists($pdo, 'smartbill_invoices', $column)) {
                try {
                    $pdo->exec("ALTER TABLE smartbill_invoices ADD COLUMN {$column} {$definition}");
                } catch (Throwable $e) {
                    error_log('ANAF efactura smartbill_invoices column error: ' . $column . ' - ' . $e->getMessage());
                }
            }
        }
    }
}

if (!function_exists('anaf_efactura_column_exists')) {
    function anaf_efactura_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

/* ───────────────────────── Setări ───────────────────────── */

if (!function_exists('anaf_efactura_settings')) {
    function anaf_efactura_settings(PDO $pdo): array
    {
        $keys = [
            'anaf_efactura.enabled'       => '0',
            'anaf_efactura.client_id'     => '',
            'anaf_efactura.client_secret' => '',
            'anaf_efactura.cif'           => '',
            'anaf_efactura.environment'   => 'prod',     // prod | test
            'anaf_efactura.auto_sync'     => '1',
            'anaf_efactura.sync_days'     => '30',
            'anaf_efactura.last_sync_at'  => '',
        ];
        if (function_exists('pz_settings_get_all')) {
            $stored = pz_settings_get_all($pdo);
            foreach ($keys as $k => $default) {
                if (isset($stored[$k]) && $stored[$k] !== null) {
                    $keys[$k] = (string)$stored[$k];
                }
            }
        }
        return $keys;
    }
}

if (!function_exists('anaf_efactura_save_settings')) {
    function anaf_efactura_save_settings(PDO $pdo, array $post): void
    {
        if (!function_exists('pz_settings_set_many')) {
            return;
        }
        $clientSecret = trim((string)($post['anaf_client_secret'] ?? ''));
        $current = anaf_efactura_settings($pdo);
        if ($clientSecret === '' || $clientSecret === '********') {
            $clientSecret = (string)($current['anaf_efactura.client_secret'] ?? '');
        }
        $syncDays = max(1, min(60, (int)($post['anaf_sync_days'] ?? 30)));

        pz_settings_set_many($pdo, [
            'anaf_efactura.enabled'       => !empty($post['anaf_enabled']) ? '1' : '0',
            'anaf_efactura.client_id'     => trim((string)($post['anaf_client_id'] ?? '')),
            'anaf_efactura.client_secret' => $clientSecret,
            'anaf_efactura.cif'           => preg_replace('/[^0-9]/', '', (string)($post['anaf_cif'] ?? '')),
            'anaf_efactura.environment'   => in_array($post['anaf_environment'] ?? 'prod', ['prod', 'test'], true) ? (string)$post['anaf_environment'] : 'prod',
            'anaf_efactura.auto_sync'     => !empty($post['anaf_auto_sync']) ? '1' : '0',
            'anaf_efactura.sync_days'     => (string)$syncDays,
        ]);
    }
}

if (!function_exists('anaf_efactura_redirect_uri')) {
    function anaf_efactura_redirect_uri(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'app.pestzone.ro';
        return $scheme . '://' . $host . '/efactura_oauth_callback.php';
    }
}

if (!function_exists('anaf_efactura_api_base')) {
    function anaf_efactura_api_base(array $settings): string
    {
        return ($settings['anaf_efactura.environment'] ?? 'prod') === 'test'
            ? ANAF_API_BASE_TEST
            : ANAF_API_BASE;
    }
}

/* ───────────────────────── OAuth flow ───────────────────────── */

if (!function_exists('anaf_efactura_auth_url')) {
    function anaf_efactura_auth_url(array $settings, string $state): string
    {
        $params = [
            'response_type'        => 'code',
            'client_id'            => (string)($settings['anaf_efactura.client_id'] ?? ''),
            'redirect_uri'         => anaf_efactura_redirect_uri(),
            'state'                => $state,
            'token_content_type'   => 'jwt',
        ];
        return ANAF_OAUTH_AUTHORIZE . '?' . http_build_query($params);
    }
}

if (!function_exists('anaf_efactura_http_post')) {
    /**
     * Wrapper cURL pentru POST cu body x-www-form-urlencoded sau JSON.
     * Returnează [status, body, error].
     */
    function anaf_efactura_http_post(string $url, array $payload, array $headers = [], int $timeout = 30): array
    {
        if (!function_exists('curl_init')) {
            return [0, '', 'cURL nu este disponibil pe server.'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => array_merge([
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ], $headers),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);
        return [$status, (string)$body, $error];
    }
}

if (!function_exists('anaf_efactura_http_get')) {
    function anaf_efactura_http_get(string $url, array $headers = [], int $timeout = 60): array
    {
        if (!function_exists('curl_init')) {
            return [0, '', 'cURL nu este disponibil pe server.'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: application/json',
            ], $headers),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);
        return [$status, (string)$body, $error];
    }
}

if (!function_exists('anaf_efactura_log')) {
    function anaf_efactura_log(PDO $pdo, string $action, string $endpoint, ?array $request, int $status, string $response, ?string $error = null, ?string $cif = null): void
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO anaf_efactura_logs
                (cif, action, endpoint, request_payload, response_status, response_body, error_message)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $cif,
                $action,
                $endpoint,
                $request ? json_encode($request, JSON_UNESCAPED_UNICODE) : null,
                $status,
                mb_substr($response, 0, 60000),
                $error,
            ]);
        } catch (Throwable $e) {
            error_log('ANAF efactura log error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('anaf_efactura_exchange_code')) {
    /**
     * Schimbă codul OAuth pe access_token + refresh_token și salvează în BD.
     */
    function anaf_efactura_exchange_code(PDO $pdo, array $settings, string $code): array
    {
        $clientId = (string)($settings['anaf_efactura.client_id'] ?? '');
        $clientSecret = (string)($settings['anaf_efactura.client_secret'] ?? '');
        $cif = (string)($settings['anaf_efactura.cif'] ?? '');
        if ($clientId === '' || $clientSecret === '' || $cif === '') {
            return ['ok' => false, 'error' => 'Lipsesc credentialele ANAF (client_id / client_secret / CIF).'];
        }

        $payload = [
            'grant_type'         => 'authorization_code',
            'code'               => $code,
            'client_id'          => $clientId,
            'client_secret'      => $clientSecret,
            'redirect_uri'       => anaf_efactura_redirect_uri(),
            'token_content_type' => 'jwt',
        ];

        [$status, $body, $error] = anaf_efactura_http_post(ANAF_OAUTH_TOKEN, $payload, [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ]);
        anaf_efactura_log($pdo, 'oauth_exchange', ANAF_OAUTH_TOKEN, ['code' => substr($code, 0, 10) . '...'], $status, $body, $error, $cif);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'ANAF a returnat HTTP ' . $status . '. ' . $error . ' ' . mb_substr($body, 0, 400)];
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token']) || empty($data['refresh_token'])) {
            return ['ok' => false, 'error' => 'Raspuns ANAF invalid: lipseste access_token/refresh_token.'];
        }

        $expiresIn = (int)($data['expires_in'] ?? 7776000); // ~90 zile default
        $expiresAt = (new DateTime())->modify('+' . $expiresIn . ' seconds')->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("INSERT INTO anaf_oauth_tokens (cif, access_token, refresh_token, token_type, expires_at, refreshed_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token),
                token_type = VALUES(token_type), expires_at = VALUES(expires_at), refreshed_at = NOW()");
        $stmt->execute([
            $cif,
            (string)$data['access_token'],
            (string)$data['refresh_token'],
            (string)($data['token_type'] ?? 'Bearer'),
            $expiresAt,
        ]);

        return ['ok' => true, 'expires_at' => $expiresAt];
    }
}

if (!function_exists('anaf_efactura_refresh_token')) {
    function anaf_efactura_refresh_token(PDO $pdo, array $settings): array
    {
        $cif = (string)($settings['anaf_efactura.cif'] ?? '');
        $clientId = (string)($settings['anaf_efactura.client_id'] ?? '');
        $clientSecret = (string)($settings['anaf_efactura.client_secret'] ?? '');
        if ($cif === '' || $clientId === '' || $clientSecret === '') {
            return ['ok' => false, 'error' => 'Lipsesc credentialele ANAF.'];
        }

        $stmt = $pdo->prepare("SELECT refresh_token FROM anaf_oauth_tokens WHERE cif = ? LIMIT 1");
        $stmt->execute([$cif]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current || empty($current['refresh_token'])) {
            return ['ok' => false, 'error' => 'Nu exista refresh_token salvat. Conecteaza-te din nou la ANAF.'];
        }

        $payload = [
            'grant_type'         => 'refresh_token',
            'refresh_token'      => (string)$current['refresh_token'],
            'client_id'          => $clientId,
            'client_secret'      => $clientSecret,
            'token_content_type' => 'jwt',
        ];

        [$status, $body, $error] = anaf_efactura_http_post(ANAF_OAUTH_TOKEN, $payload, [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ]);
        anaf_efactura_log($pdo, 'oauth_refresh', ANAF_OAUTH_TOKEN, null, $status, $body, $error, $cif);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'ANAF refresh esuat: HTTP ' . $status . ' ' . $error . ' ' . mb_substr($body, 0, 400)];
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token']) || empty($data['refresh_token'])) {
            return ['ok' => false, 'error' => 'Raspuns refresh invalid.'];
        }

        $expiresIn = (int)($data['expires_in'] ?? 7776000);
        $expiresAt = (new DateTime())->modify('+' . $expiresIn . ' seconds')->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("UPDATE anaf_oauth_tokens SET access_token = ?, refresh_token = ?, token_type = ?, expires_at = ?, refreshed_at = NOW() WHERE cif = ?");
        $stmt->execute([
            (string)$data['access_token'],
            (string)$data['refresh_token'],
            (string)($data['token_type'] ?? 'Bearer'),
            $expiresAt,
            $cif,
        ]);

        return ['ok' => true, 'expires_at' => $expiresAt];
    }
}

if (!function_exists('anaf_efactura_get_valid_token')) {
    /**
     * Returnează un access_token valid; dacă e expirat / aproape, face refresh automat.
     */
    function anaf_efactura_get_valid_token(PDO $pdo, array $settings): array
    {
        $cif = (string)($settings['anaf_efactura.cif'] ?? '');
        if ($cif === '') {
            return ['ok' => false, 'error' => 'CIF lipsa din setari.'];
        }

        $stmt = $pdo->prepare("SELECT access_token, expires_at FROM anaf_oauth_tokens WHERE cif = ? LIMIT 1");
        $stmt->execute([$cif]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'Niciun token ANAF salvat. Conecteaza CRM-ul la ANAF.'];
        }

        $expiresAt = strtotime((string)$row['expires_at']);
        // Refresh dacă mai are mai puțin de 5 zile valabilitate
        if ($expiresAt - time() < 5 * 86400) {
            $refresh = anaf_efactura_refresh_token($pdo, $settings);
            if (!$refresh['ok']) {
                return $refresh;
            }
            $stmt->execute([$cif]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return ['ok' => true, 'access_token' => (string)$row['access_token']];
    }
}

if (!function_exists('anaf_efactura_token_status')) {
    function anaf_efactura_token_status(PDO $pdo, string $cif): array
    {
        $stmt = $pdo->prepare("SELECT expires_at, refreshed_at FROM anaf_oauth_tokens WHERE cif = ? LIMIT 1");
        $stmt->execute([$cif]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['connected' => false];
        }
        return [
            'connected'   => true,
            'expires_at'  => (string)$row['expires_at'],
            'refreshed_at' => (string)($row['refreshed_at'] ?? ''),
            'days_left'   => max(0, (int)floor((strtotime((string)$row['expires_at']) - time()) / 86400)),
        ];
    }
}

/* ───────────────────────── Listare / descărcare mesaje ───────────────────────── */

if (!function_exists('anaf_efactura_list_messages')) {
    /**
     * GET /listaMesajeFactura?zile=N&cif=CIF
     * N: 1..60, returnează toate mesajele primite/trimise din ultimele N zile.
     * Filtre $type: TOATE | FACTURA TRIMISA | FACTURA PRIMITA | ERORI FACTURA | MESAJ CUMPARATOR PRIMIT/TRANSMIS
     */
    function anaf_efactura_list_messages(PDO $pdo, array $settings, int $days = 60, ?string $type = null): array
    {
        $days = max(1, min(60, $days));
        $cif = (string)($settings['anaf_efactura.cif'] ?? '');
        if ($cif === '') {
            return ['ok' => false, 'error' => 'CIF lipsa.'];
        }

        $token = anaf_efactura_get_valid_token($pdo, $settings);
        if (!$token['ok']) {
            return $token;
        }

        $params = ['zile' => $days, 'cif' => $cif];
        if ($type !== null && $type !== '' && $type !== 'TOATE') {
            $params['filtru'] = $type;
        }
        $url = anaf_efactura_api_base($settings) . '/listaMesajeFactura?' . http_build_query($params);

        [$status, $body, $error] = anaf_efactura_http_get($url, [
            'Authorization: Bearer ' . $token['access_token'],
        ]);
        anaf_efactura_log($pdo, 'list_messages', $url, $params, $status, $body, $error, $cif);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'ANAF lista HTTP ' . $status . ' ' . mb_substr($body, 0, 400)];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Raspuns ANAF invalid.'];
        }
        if (!empty($data['eroare'])) {
            return ['ok' => false, 'error' => (string)$data['eroare']];
        }

        return ['ok' => true, 'messages' => $data['mesaje'] ?? [], 'raw' => $data];
    }
}

if (!function_exists('anaf_efactura_download_message')) {
    /**
     * GET /descarcare?id=ID — descarcă ZIP cu factura semnată, îl salvează pe disk
     * și extrage XML-ul. Returnează path-uri și metadata parsată.
     */
    function anaf_efactura_download_message(PDO $pdo, array $settings, string $messageId): array
    {
        $cif = (string)($settings['anaf_efactura.cif'] ?? '');
        $token = anaf_efactura_get_valid_token($pdo, $settings);
        if (!$token['ok']) {
            return $token;
        }

        $url = anaf_efactura_api_base($settings) . '/descarcare?id=' . urlencode($messageId);

        [$status, $body, $error] = anaf_efactura_http_get($url, [
            'Authorization: Bearer ' . $token['access_token'],
            'Accept: application/zip, application/octet-stream',
        ], 120);
        anaf_efactura_log($pdo, 'download', $url, ['id' => $messageId], $status, mb_substr($body, 0, 200) . '...(binary)', $error, $cif);

        if ($status !== 200 || $body === '') {
            return ['ok' => false, 'error' => 'Descarcare esuata: HTTP ' . $status . ' ' . $error];
        }

        // Salvăm ZIP-ul
        $baseDir = __DIR__ . '/' . ANAF_EFACTURA_DIR;
        $year = date('Y');
        $month = date('m');
        $subDir = $baseDir . '/' . $year . '/' . $month;
        if (!is_dir($subDir) && !mkdir($subDir, 0750, true) && !is_dir($subDir)) {
            return ['ok' => false, 'error' => 'Nu pot crea folderul de stocare: ' . $subDir];
        }
        $zipPath = $subDir . '/efactura_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $messageId) . '.zip';
        if (file_put_contents($zipPath, $body) === false) {
            return ['ok' => false, 'error' => 'Nu pot scrie ZIP-ul pe disk.'];
        }

        // Extrage XML-ul din ZIP
        $xmlContent = '';
        $xmlPath = null;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (preg_match('/\.xml$/i', (string)$name) && stripos((string)$name, 'semnatura') === false) {
                        $xmlContent = (string)$zip->getFromIndex($i);
                        $xmlPath = $subDir . '/efactura_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $messageId) . '.xml';
                        file_put_contents($xmlPath, $xmlContent);
                        break;
                    }
                }
                $zip->close();
            }
        }

        $meta = $xmlContent !== '' ? anaf_efactura_parse_invoice_xml($xmlContent) : [];

        // Path-uri relative la root (cum se folosesc deja în BD)
        $relZip = ANAF_EFACTURA_DIR . '/' . $year . '/' . $month . '/' . basename($zipPath);
        $relXml = $xmlPath ? ANAF_EFACTURA_DIR . '/' . $year . '/' . $month . '/' . basename($xmlPath) : null;

        return [
            'ok'       => true,
            'zip_path' => $relZip,
            'xml_path' => $relXml,
            'meta'     => $meta,
        ];
    }
}

if (!function_exists('anaf_efactura_check_status')) {
    /**
     * GET /stareMesaj?id_incarcare={id} — verifică starea unei facturi trimise.
     */
    function anaf_efactura_check_status(PDO $pdo, array $settings, string $uploadId): array
    {
        $cif = (string)($settings['anaf_efactura.cif'] ?? '');
        $token = anaf_efactura_get_valid_token($pdo, $settings);
        if (!$token['ok']) {
            return $token;
        }

        $url = anaf_efactura_api_base($settings) . '/stareMesaj?id_incarcare=' . urlencode($uploadId);

        [$status, $body, $error] = anaf_efactura_http_get($url, [
            'Authorization: Bearer ' . $token['access_token'],
        ]);
        anaf_efactura_log($pdo, 'check_status', $url, ['id' => $uploadId], $status, $body, $error, $cif);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'ANAF stareMesaj HTTP ' . $status];
        }

        $data = json_decode($body, true);
        return ['ok' => true, 'data' => is_array($data) ? $data : ['raw' => $body]];
    }
}

/* ───────────────────────── Parsare XML UBL 2.1 ───────────────────────── */

if (!function_exists('anaf_efactura_parse_invoice_xml')) {
    /**
     * Extrage metadata dintr-un XML UBL 2.1 (Invoice sau CreditNote).
     */
    function anaf_efactura_parse_invoice_xml(string $xmlContent): array
    {
        $meta = [
            'supplier_name'        => '',
            'supplier_fiscal_code' => '',
            'customer_name'        => '',
            'customer_fiscal_code' => '',
            'document_type'        => '',
            'document_series'      => '',
            'document_number'      => '',
            'issue_date'           => null,
            'due_date'             => null,
            'currency'             => 'RON',
            'net_amount'           => 0.0,
            'vat_amount'           => 0.0,
            'gross_amount'         => 0.0,
        ];

        if ($xmlContent === '') {
            return $meta;
        }

        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xmlContent);
        libxml_use_internal_errors($prev);
        if (!$doc) {
            return $meta;
        }

        $rootName = $doc->getName();
        $meta['document_type'] = $rootName === 'CreditNote' ? 'CreditNote' : 'Invoice';

        $namespaces = $doc->getNamespaces(true);
        $cbc = $namespaces['cbc'] ?? 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
        $cac = $namespaces['cac'] ?? 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
        $doc->registerXPathNamespace('cbc', $cbc);
        $doc->registerXPathNamespace('cac', $cac);

        $first = function($xpath) use ($doc): string {
            $r = $doc->xpath($xpath);
            return $r && isset($r[0]) ? trim((string)$r[0]) : '';
        };

        // ID factură (poate fi „SERIE NR" împreună sau doar număr)
        $invoiceId = $first('//cbc:ID[1]');
        if ($invoiceId !== '') {
            // încearcă separare serie/numar
            if (preg_match('/^([A-Za-z]+)[\s-]?(\d+)$/', $invoiceId, $m)) {
                $meta['document_series'] = $m[1];
                $meta['document_number'] = $m[2];
            } else {
                $meta['document_number'] = $invoiceId;
            }
        }

        $meta['issue_date'] = $first('//cbc:IssueDate') ?: null;
        $meta['due_date'] = $first('//cbc:DueDate') ?: null;
        $meta['currency'] = $first('//cbc:DocumentCurrencyCode') ?: 'RON';

        // Furnizor
        $meta['supplier_name'] = $first('//cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName')
            ?: $first('//cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name');
        $meta['supplier_fiscal_code'] = $first('//cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID')
            ?: $first('//cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID');

        // Cumpărător
        $meta['customer_name'] = $first('//cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName')
            ?: $first('//cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name');
        $meta['customer_fiscal_code'] = $first('//cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID')
            ?: $first('//cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID');

        // Totale
        $meta['net_amount']   = (float)($first('//cac:LegalMonetaryTotal/cbc:LineExtensionAmount') ?: 0);
        $taxTotal = (float)($first('//cac:TaxTotal/cbc:TaxAmount') ?: 0);
        $meta['vat_amount']   = $taxTotal;
        $meta['gross_amount'] = (float)($first('//cac:LegalMonetaryTotal/cbc:PayableAmount')
            ?: $first('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount') ?: 0);

        return $meta;
    }
}

/* ───────────────────────── Sincronizare facturi primite ───────────────────────── */

if (!function_exists('anaf_efactura_sync_received')) {
    /**
     * Sincronizează facturile primite din ANAF pentru ultimele N zile.
     * Pentru fiecare mesaj nou (necunoscut în BD), descarcă, parsează și salvează.
     * Returnează ['ok' => bool, 'fetched' => int, 'saved' => int, 'errors' => [...]]
     */
    function anaf_efactura_sync_received(PDO $pdo, int $days = 30): array
    {
        $settings = anaf_efactura_settings($pdo);
        if (($settings['anaf_efactura.enabled'] ?? '0') !== '1') {
            return ['ok' => false, 'error' => 'Integrarea ANAF e-Factura este dezactivata.'];
        }
        if (function_exists('pz_smartbill_ensure_schema')) {
            pz_smartbill_ensure_schema($pdo);
        }
        anaf_efactura_ensure_schema($pdo);

        $list = anaf_efactura_list_messages($pdo, $settings, $days, 'FACTURA PRIMITA');
        if (!$list['ok']) {
            return $list;
        }

        $fetched = 0;
        $saved = 0;
        $errors = [];

        foreach ($list['messages'] as $message) {
            $fetched++;
            $messageId = (string)($message['id'] ?? '');
            if ($messageId === '') {
                continue;
            }

            // Skip dacă există deja
            $stmt = $pdo->prepare("SELECT id FROM smartbill_supplier_invoices WHERE anaf_message_id = ? LIMIT 1");
            $stmt->execute([$messageId]);
            if ($stmt->fetchColumn()) {
                continue;
            }

            $dl = anaf_efactura_download_message($pdo, $settings, $messageId);
            if (!$dl['ok']) {
                $errors[] = ['id' => $messageId, 'error' => $dl['error']];
                continue;
            }

            $meta = $dl['meta'] ?? [];
            $data = [
                'supplier_name'        => $meta['supplier_name'] ?? '',
                'supplier_fiscal_code' => $meta['supplier_fiscal_code'] ?? '',
                'document_series'      => $meta['document_series'] ?? '',
                'document_number'      => $meta['document_number'] ?? '',
                'issue_date'           => $meta['issue_date'] ?? null,
                'due_date'             => $meta['due_date'] ?? null,
                'currency'             => $meta['currency'] ?? 'RON',
                'net_amount'           => $meta['net_amount'] ?? 0,
                'vat_amount'           => $meta['vat_amount'] ?? 0,
                'gross_amount'         => $meta['gross_amount'] ?? 0,
                'efactura_status'      => 'primit',
                'source'               => 'anaf_direct',
                'xml_path'             => $dl['xml_path'] ?? null,
                'anaf_message_id'      => $messageId,
                'anaf_upload_id'       => (string)($message['id_solicitare'] ?? ''),
                'anaf_message_type'    => 'FACTURA PRIMITA',
                'anaf_synced_at'       => date('Y-m-d H:i:s'),
            ];

            try {
                $stmt = $pdo->prepare("INSERT INTO smartbill_supplier_invoices
                    (supplier_name, supplier_fiscal_code, document_series, document_number,
                     issue_date, due_date, currency, net_amount, vat_amount, gross_amount,
                     efactura_status, source, xml_path,
                     anaf_message_id, anaf_upload_id, anaf_message_type, anaf_synced_at, imported_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $data['supplier_name'] ?: 'Furnizor necunoscut',
                    $data['supplier_fiscal_code'],
                    $data['document_series'],
                    $data['document_number'],
                    $data['issue_date'],
                    $data['due_date'],
                    $data['currency'],
                    $data['net_amount'],
                    $data['vat_amount'],
                    $data['gross_amount'],
                    $data['efactura_status'],
                    $data['source'],
                    $data['xml_path'],
                    $data['anaf_message_id'],
                    $data['anaf_upload_id'],
                    $data['anaf_message_type'],
                    $data['anaf_synced_at'],
                ]);
                $saved++;
            } catch (Throwable $e) {
                $errors[] = ['id' => $messageId, 'error' => $e->getMessage()];
            }
        }

        // Actualizează ultima sincronizare
        if (function_exists('pz_settings_set_many')) {
            pz_settings_set_many($pdo, ['anaf_efactura.last_sync_at' => date('Y-m-d H:i:s')]);
        }

        return ['ok' => true, 'fetched' => $fetched, 'saved' => $saved, 'errors' => $errors];
    }
}

/* ───────────────────────── Sincronizare facturi trimise (status) ───────────────────────── */

if (!function_exists('anaf_efactura_sync_sent_status')) {
    /**
     * Pentru fiecare factură din smartbill_invoices care are anaf_upload_id setat,
     * verifică starea în ANAF și updatează efactura_status.
     */
    function anaf_efactura_sync_sent_status(PDO $pdo, int $limit = 50): array
    {
        $settings = anaf_efactura_settings($pdo);
        if (($settings['anaf_efactura.enabled'] ?? '0') !== '1') {
            return ['ok' => false, 'error' => 'Integrarea ANAF este dezactivata.'];
        }
        anaf_efactura_ensure_schema($pdo);

        $stmt = $pdo->prepare("SELECT id, anaf_upload_id FROM smartbill_invoices
            WHERE anaf_upload_id IS NOT NULL AND anaf_upload_id <> ''
              AND (efactura_status IS NULL OR efactura_status NOT IN ('validata', 'eroare'))
            ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $checked = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $checked++;
            $res = anaf_efactura_check_status($pdo, $settings, (string)$row['anaf_upload_id']);
            if (!$res['ok']) {
                continue;
            }
            $stareCode = '';
            $message = '';
            if (isset($res['data']['stare'])) {
                $stareCode = (string)$res['data']['stare'];
            }
            if (isset($res['data']['Errors']['errorMessage'])) {
                $message = (string)$res['data']['Errors']['errorMessage'];
            }

            // Mapare cod ANAF → status intern
            $map = [
                'in prelucrare' => 'in_validare',
                'ok'            => 'validata',
                'nok'           => 'eroare',
                'XML cu erori'  => 'eroare',
            ];
            $internal = $map[strtolower($stareCode)] ?? ($stareCode ?: 'neverificat');

            $upd = $pdo->prepare("UPDATE smartbill_invoices SET efactura_status = ?, efactura_message = ?, last_status_check_at = NOW() WHERE id = ?");
            $upd->execute([$internal, $message, (int)$row['id']]);
            $updated++;
        }

        return ['ok' => true, 'checked' => $checked, 'updated' => $updated];
    }
}
