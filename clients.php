<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

// Fortam afisarea paginii si conexiunea la baza de date pe UTF-8.
// Ajuta la textele romanesti si reduce problemele de tip mojibake.
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
} catch (Throwable $e) {
    // Nu blocam pagina daca serverul MySQL nu accepta explicit collation-ul.
}

$isAdmin = is_admin();

if (!$isAdmin) {
    header("Location: calendar.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function c_fix_encoding_issues($value): string {
    $value = (string)$value;

    if ($value === '') {
        return '';
    }

    // Reparatie doar la afisare pentru cele mai comune texte romanesti salvate gresit in DB:
    // a/A, a/A, i/I, s/S, t/T + spatii non-breaking afisate ca A.
    $map = [
        'Äƒ' => 'a', 'Ä‚' => 'A',
        'Ã¢' => 'a', 'Ã‚' => 'A',
        'Ã®' => 'i', 'ÃŽ' => 'I',
        'È™' => 's', 'È˜' => 'S',
        'È›' => 't', 'Èš' => 'T',
        'ÅŸ' => 's', 'Åž' => 'S',
        'Å£' => 't', 'Å¢' => 'T',
        'A ' => ' ', 'A ' => ' ',
    ];

    $value = strtr($value, $map);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function c_no_ro_diacritics($value): string {
    $value = c_fix_encoding_issues($value);

    if ($value === '') {
        return '';
    }

    // Pentru coloana Adresa / localitate afisam fara diacritice.
    // Astfel evitam complet problemele vizuale generate de encoding diferit in datele importate.
    $map = [
        'a' => 'a', 'A' => 'A',
        'a' => 'a', 'A' => 'A',
        'i' => 'i', 'I' => 'I',
        's' => 's', 'S' => 'S',
        's' => 's', 'S' => 'S',
        't' => 't', 'T' => 'T',
        't' => 't', 'T' => 'T',
    ];

    $value = strtr($value, $map);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function c_clean_address_display($value): string {
    $value = c_no_ro_diacritics($value);

    if ($value === '' || $value === '-') {
        return $value;
    }

    // Unele adrese vechi au fost deja salvate in baza de date cu semnul ? in locul literelor romanesti.
    // Cand caracterul a fost inlocuit cu ?, litera originala nu mai poate fi recuperata 100%.
    // Reparam aici cele mai frecvente cazuri din adrese si eliminam restul semnelor vizibile.
    $value = str_replace(["�", "□", "¤"], "?", $value);

    $exactMap = [
        'CONSTAN?A' => 'CONSTANTA',
        'Constan?a' => 'Constanta',
        'constan?a' => 'constanta',
        'N?VODARI' => 'NAVODARI',
        'N?vodari' => 'Navodari',
        'n?vodari' => 'navodari',
        'VOD?' => 'VODA',
        'Vod?' => 'Voda',
        'vod?' => 'voda',
        '?TEFAN' => 'STEFAN',
        '?tefan' => 'Stefan',
        '?OS.' => 'SOS.',
        '?os.' => 'Sos.',
        '?OSEAUA' => 'SOSEAUA',
        '?oseaua' => 'Soseaua',
    ];

    $value = strtr($value, $exactMap);

    $regexMap = [
        '/\bCONSTAN\?A\b/ui' => 'CONSTANTA',
        '/\bN\?VODARI\b/ui' => 'NAVODARI',
        '/\bVOD\?\b/ui' => 'VODA',
        '/\bM\?R\?+E\?TI\b/ui' => 'MARASESTI',
        '/\bM\?R\?+S\?TI\b/ui' => 'MARASESTI',
        '/\bM\?R\?SE\?TI\b/ui' => 'MARASESTI',
        '/\bM\?R\?\?E\?TI\b/ui' => 'MARASESTI',
        '/\bD\?MBOVI\?A\b/ui' => 'DAMBOVITA',
        '/\bIALOMI\?A\b/ui' => 'IALOMITA',
        '/\bTULCEA\b/ui' => 'TULCEA',
    ];

    foreach ($regexMap as $pattern => $replacement) {
        $value = preg_replace($pattern, $replacement, $value) ?? $value;
    }

    // Ultima protectie: nu mai afisam niciun ? ramas in coloana adresa.
    // Este mai curat vizual decat sa apara "CONSTAN?A" / "VOD?" in lista.
    $value = str_replace('?', '', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+,/u', ',', $value) ?? $value;
    $value = preg_replace('/,\s*,+/u', ',', $value) ?? $value;

    return trim($value);
}

function c_h_address($value): string {
    return htmlspecialchars(c_clean_address_display($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function c_h($value): string {
    return htmlspecialchars(c_fix_encoding_issues($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function c_fix_encoding_issues_recursive($value) {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = c_fix_encoding_issues_recursive($item);
        }
        return $value;
    }

    if (is_string($value)) {
        return c_fix_encoding_issues($value);
    }

    return $value;
}

function c_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0) > 0;
}

function c_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0) > 0;
}

function c_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!c_column_exists($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Nu blocam pagina daca ALTER nu poate rula.
        }
    }
}

function c_clean_text(?string $value): string {
    $value = trim((string)$value);
    $map = [
        'a' => 'a', 'A' => 'A',
        'a' => 'a', 'A' => 'A',
        'i' => 'i', 'I' => 'I',
        's' => 's', 'S' => 'S',
        's' => 's', 'S' => 'S',
        't' => 't', 'T' => 'T',
        't' => 't', 'T' => 'T',
    ];

    $value = strtr($value, $map);
    $value = preg_replace('/\s+/', ' ', $value);

    return trim((string)$value);
}

function c_clean_phone(?string $value): string {
    return trim((string)$value);
}

function c_clean_fiscal_code(?string $value): string {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^0-9A-Z]/', '', $value);
    $value = preg_replace('/^RO/', '', (string)$value);

    return trim((string)$value);
}

function c_decimal_nullable($value): ?float {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace([' ', ','], ['', '.'], $value);
    return is_numeric($value) ? (float)$value : null;
}

function c_clean_surface_unit($value): string {
    $value = trim((string)$value);
    return in_array($value, ['mp', 'ml', 'buc'], true) ? $value : 'mp';
}

function c_client_type_label(string $type): string {
    return $type === 'individual' ? 'PF' : 'PJ';
}

function c_client_address(array $client): string {
    return trim((string)($client['registered_address'] ?? '')) ?: trim((string)($client['address'] ?? ''));
}

function c_client_contact_person(array $client): string {
    $type = (string)($client['client_type'] ?? 'company');
    $name = trim((string)($client['name'] ?? ''));
    $rep = trim((string)($client['legal_representative_name'] ?? ''));

    if ($type === 'individual') {
        return $name;
    }

    return $rep !== '' ? $rep : $name;
}

function c_client_status_class($status, int $active): string {
    if ($active !== 1) {
        return 'inactive';
    }

    $status = strtolower(trim((string)$status));

    if (in_array($status, ['season', 'seasonal', 'sezonier'], true)) {
        return 'season';
    }

    return 'active';
}

function c_client_status_label($status, int $active): string {
    if ($active !== 1) {
        return 'Inactiv';
    }

    $status = strtolower(trim((string)$status));

    if (in_array($status, ['season', 'seasonal', 'sezonier'], true)) {
        return 'Sezonier';
    }

    return 'Activ';
}

function c_normalize_anaf_item(array $item): array {
    $general = $item['date_generale'] ?? [];

    return [
        'client_type' => 'company',
        'name' => c_clean_text($general['denumire'] ?? ''),
        'fiscal_code' => c_clean_fiscal_code((string)($general['cui'] ?? '')),
        'registry_number' => c_clean_text($general['nrRegCom'] ?? ''),
        'registered_address' => c_clean_text($general['adresa'] ?? ''),
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
                'Content-Type: application/json',
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
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
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

/*
|--------------------------------------------------------------------------
| AJAX ANAF
|--------------------------------------------------------------------------
*/
if (isset($_GET['ajax']) && $_GET['ajax'] === 'anaf_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(c_anaf_lookup($_GET['cui'] ?? ''), JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/
$pdo->exec("
    CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_type VARCHAR(20) NOT NULL DEFAULT 'company',
        name VARCHAR(180) NOT NULL,
        fiscal_code VARCHAR(30) NULL,
        registry_number VARCHAR(100) NULL,
        registered_address VARCHAR(255) NULL,
        bank_name VARCHAR(160) NULL,
        bank_account VARCHAR(80) NULL,
        phone VARCHAR(60) NULL,
        email VARCHAR(160) NULL,
        address VARCHAR(255) NULL,
        legal_representative_name VARCHAR(180) NULL,
        legal_representative_role VARCHAR(120) NULL,
        anaf_last_lookup_at DATETIME NULL,
        anaf_raw_response LONGTEXT NULL,
        notes TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$clientColumns = [
    'client_type' => "VARCHAR(20) NOT NULL DEFAULT 'company'",
    'name' => "VARCHAR(180) NOT NULL",
    'fiscal_code' => "VARCHAR(30) NULL",
    'registry_number' => "VARCHAR(100) NULL",
    'registered_address' => "VARCHAR(255) NULL",
    'registered_surface_value' => "DECIMAL(12,2) NULL",
    'registered_surface_unit' => "VARCHAR(20) NOT NULL DEFAULT 'mp'",
    'bank_name' => "VARCHAR(160) NULL",
    'bank_account' => "VARCHAR(80) NULL",
    'phone' => "VARCHAR(60) NULL",
    'email' => "VARCHAR(160) NULL",
    'address' => "VARCHAR(255) NULL",
    'legal_representative_name' => "VARCHAR(180) NULL",
    'legal_representative_role' => "VARCHAR(120) NULL",
    'anaf_last_lookup_at' => "DATETIME NULL",
    'anaf_raw_response' => "LONGTEXT NULL",
    'notes' => "TEXT NULL",
    'sms_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
    'sms_opt_out_reason' => "VARCHAR(255) NULL",
    'sms_opt_out_at' => "DATETIME NULL",
    'active' => "TINYINT(1) NOT NULL DEFAULT 1",
    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
];

foreach ($clientColumns as $column => $definition) {
    c_ensure_column($pdo, 'clients', $column, $definition);
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS client_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        location_name VARCHAR(180) NOT NULL DEFAULT 'Punct de lucru',
        address VARCHAR(255) NULL,
        surface_value DECIMAL(12,2) NULL,
        surface_unit VARCHAR(20) NOT NULL DEFAULT 'mp',
        contact_person VARCHAR(180) NULL,
        phone VARCHAR(60) NULL,
        notes TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_locations_client_id (client_id),
        INDEX idx_client_locations_active (active)
    )
");

$locationColumns = [
    'client_id' => "INT NOT NULL",
    'location_name' => "VARCHAR(180) NOT NULL DEFAULT 'Punct de lucru'",
    'address' => "VARCHAR(255) NULL",
    'surface_value' => "DECIMAL(12,2) NULL",
    'surface_unit' => "VARCHAR(20) NOT NULL DEFAULT 'mp'",
    'contact_person' => "VARCHAR(180) NULL",
    'phone' => "VARCHAR(60) NULL",
    'notes' => "TEXT NULL",
    'active' => "TINYINT(1) NOT NULL DEFAULT 1",
    'sort_order' => "INT NOT NULL DEFAULT 0",
    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
];

foreach ($locationColumns as $column => $definition) {
    c_ensure_column($pdo, 'client_locations', $column, $definition);
}

/*
|--------------------------------------------------------------------------
| POST handlers
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_sms') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $smsEnabled = (int)($_POST['sms_enabled'] ?? 1) === 1 ? 1 : 0;

        if ($clientId > 0) {
            $stmt = $pdo->prepare("
                UPDATE clients
                SET sms_enabled = ?,
                    sms_opt_out_reason = NULL,
                    sms_opt_out_at = CASE WHEN ? = 0 THEN NOW() ELSE NULL END
                WHERE id = ?
            ");
            $stmt->execute([$smsEnabled, $smsEnabled, $clientId]);
        }

        header('Location: clients.php?client_id=' . urlencode((string)$clientId) . '&sms_updated=1');
        exit;
    }

    if ($action === 'create' || $action === 'update') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $clientType = ($_POST['client_type'] ?? 'company') === 'individual' ? 'individual' : 'company';
        $name = c_clean_text($_POST['name'] ?? '');
        $fiscalCode = c_clean_fiscal_code($_POST['fiscal_code'] ?? '');
        $registryNumber = c_clean_text($_POST['registry_number'] ?? '');
        $registeredAddress = c_clean_text($_POST['registered_address'] ?? '');
        $registeredSurfaceValue = null;
        $registeredSurfaceUnit = 'mp';
        $bankName = c_clean_text($_POST['bank_name'] ?? '');
        $bankAccount = c_clean_text($_POST['bank_account'] ?? '');
        $phone = c_clean_phone($_POST['phone'] ?? '');
        $email = trim((string)($_POST['email'] ?? ''));
        $legalRepresentativeName = c_clean_text($_POST['legal_representative_name'] ?? '');
        $legalRepresentativeRole = c_clean_text($_POST['legal_representative_role'] ?? '');
        $notes = trim((string)($_POST['notes'] ?? ''));
        $anafRawResponse = trim((string)($_POST['anaf_raw_response'] ?? ''));
        $anafLastLookupAt = trim((string)($_POST['anaf_last_lookup_at'] ?? ''));
        // Status activ/inactiv din toggle (default 1 = activ pentru clientii noi)
        $clientActive = isset($_POST['active']) && (string)$_POST['active'] === '1' ? 1 : 0;
        // SMS activ/inactiv din toggle (default 1 = SMS activate pentru clientii noi)
        $smsEnabledFromForm = isset($_POST['sms_enabled']) && (string)$_POST['sms_enabled'] === '1' ? 1 : 0;

        if ($clientType === 'individual') {
            $legalRepresentativeName = '';
            $legalRepresentativeRole = '';
        }

        if ($clientType === 'company' && $legalRepresentativeRole === '') {
            $legalRepresentativeRole = 'Administrator';
        }

        if ($name === '') {
            header('Location: clients.php?error=missing_name');
            exit;
        }

        if ($clientType === 'company' && $legalRepresentativeName === '') {
            header('Location: clients.php?error=missing_rep');
            exit;
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare("
                INSERT INTO clients
                (
                    client_type,
                    name,
                    fiscal_code,
                    registry_number,
                    registered_address,
                    registered_surface_value,
                    registered_surface_unit,
                    bank_name,
                    bank_account,
                    phone,
                    email,
                    address,
                    legal_representative_name,
                    legal_representative_role,
                    anaf_last_lookup_at,
                    anaf_raw_response,
                    notes,
                    active,
                    sms_enabled
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $clientType,
                $name,
                $fiscalCode ?: null,
                $registryNumber ?: null,
                $registeredAddress ?: null,
                $registeredSurfaceValue,
                $registeredSurfaceUnit,
                $bankName ?: null,
                $bankAccount ?: null,
                $phone ?: null,
                $email ?: null,
                $registeredAddress ?: null,
                $legalRepresentativeName ?: null,
                $legalRepresentativeRole ?: null,
                $anafLastLookupAt ?: null,
                $anafRawResponse ?: null,
                $notes ?: null,
                $clientActive,
                $smsEnabledFromForm,
            ]);

            $clientId = (int)$pdo->lastInsertId();
            $redirectParam = 'created=1';
        } else {
            if ($clientId <= 0) {
                header('Location: clients.php?error=invalid_client');
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE clients
                SET client_type = ?,
                    name = ?,
                    fiscal_code = ?,
                    registry_number = ?,
                    registered_address = ?,
                    registered_surface_value = ?,
                    registered_surface_unit = ?,
                    bank_name = ?,
                    bank_account = ?,
                    phone = ?,
                    email = ?,
                    address = ?,
                    legal_representative_name = ?,
                    legal_representative_role = ?,
                    anaf_last_lookup_at = ?,
                    anaf_raw_response = ?,
                    notes = ?,
                    active = ?,
                    sms_enabled = ?,
                    sms_opt_out_reason = NULL,
                    sms_opt_out_at = CASE WHEN ? = 0 THEN NOW() ELSE NULL END
                WHERE id = ?
            ");

            $stmt->execute([
                $clientType,
                $name,
                $fiscalCode ?: null,
                $registryNumber ?: null,
                $registeredAddress ?: null,
                $registeredSurfaceValue,
                $registeredSurfaceUnit,
                $bankName ?: null,
                $bankAccount ?: null,
                $phone ?: null,
                $email ?: null,
                $registeredAddress ?: null,
                $legalRepresentativeName ?: null,
                $legalRepresentativeRole ?: null,
                $anafLastLookupAt ?: null,
                $anafRawResponse ?: null,
                $notes ?: null,
                $clientActive,
                $smsEnabledFromForm,
                $smsEnabledFromForm,
                $clientId,
            ]);

            $redirectParam = 'updated=1';
        }

        $locationIds = $_POST['location_id'] ?? [];
        $locationNames = $_POST['location_name'] ?? [];
        $locationAddresses = $_POST['location_address'] ?? [];
        $locationSurfaces = $_POST['location_surface_value'] ?? [];
        $locationSurfaceUnits = $_POST['location_surface_unit'] ?? [];
        $locationContacts = $_POST['location_contact_person'] ?? [];
        $locationPhones = $_POST['location_phone'] ?? [];
        $locationNotes = $_POST['location_notes'] ?? [];
        $locationActive = $_POST['location_active'] ?? [];

        if (!is_array($locationIds)) $locationIds = [];
        if (!is_array($locationNames)) $locationNames = [];
        if (!is_array($locationAddresses)) $locationAddresses = [];
        if (!is_array($locationSurfaces)) $locationSurfaces = [];
        if (!is_array($locationSurfaceUnits)) $locationSurfaceUnits = [];
        if (!is_array($locationContacts)) $locationContacts = [];
        if (!is_array($locationPhones)) $locationPhones = [];
        if (!is_array($locationNotes)) $locationNotes = [];
        if (!is_array($locationActive)) $locationActive = [];

        $maxRows = max(
            count($locationIds),
            count($locationNames),
            count($locationAddresses),
            count($locationSurfaces),
            count($locationSurfaceUnits),
            count($locationContacts),
            count($locationPhones),
            count($locationNotes),
            count($locationActive)
        );

        for ($i = 0; $i < $maxRows; $i++) {
            $locId = (int)($locationIds[$i] ?? 0);
            $locActive = (int)($locationActive[$i] ?? 1) === 1 ? 1 : 0;
            $locName = c_clean_text($locationNames[$i] ?? '');
            $locAddress = c_clean_text($locationAddresses[$i] ?? '');
            $locSurfaceValue = c_decimal_nullable($locationSurfaces[$i] ?? '');
            $locSurfaceUnit = c_clean_surface_unit($locationSurfaceUnits[$i] ?? 'mp');
            $locContact = c_clean_text($locationContacts[$i] ?? '');
            $locPhone = c_clean_phone($locationPhones[$i] ?? '');
            $locNotes = trim((string)($locationNotes[$i] ?? ''));

            if ($locId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE client_locations
                    SET location_name = ?,
                        address = ?,
                        surface_value = ?,
                        surface_unit = ?,
                        contact_person = ?,
                        phone = ?,
                        notes = ?,
                        active = ?,
                        sort_order = ?
                    WHERE id = ?
                      AND client_id = ?
                ");
                $stmt->execute([
                    $locName !== '' ? $locName : 'Punct de lucru',
                    $locAddress ?: null,
                    $locSurfaceValue,
                    $locSurfaceUnit,
                    $locContact ?: null,
                    $locPhone ?: null,
                    $locNotes ?: null,
                    $locActive,
                    $i,
                    $locId,
                    $clientId,
                ]);
            } else {
                if ($locActive === 0) {
                    continue;
                }

                if ($locName === '' && $locAddress === '' && $locSurfaceValue === null && $locContact === '' && $locPhone === '' && $locNotes === '') {
                    continue;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO client_locations
                    (
                        client_id,
                        location_name,
                        address,
                        surface_value,
                        surface_unit,
                        contact_person,
                        phone,
                        notes,
                        active,
                        sort_order
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                ");
                $stmt->execute([
                    $clientId,
                    $locName !== '' ? $locName : 'Punct de lucru',
                    $locAddress ?: null,
                    $locSurfaceValue,
                    $locSurfaceUnit,
                    $locContact ?: null,
                    $locPhone ?: null,
                    $locNotes ?: null,
                    $i,
                ]);
            }
        }

        header('Location: clients.php?client_id=' . urlencode((string)$clientId) . '&' . $redirectParam);
        exit;
    }

    if ($action === 'permanent_delete') {
        $clientId = (int)($_POST['client_id'] ?? 0);

        if ($clientId <= 0) {
            header('Location: clients.php?error=invalid_client');
            exit;
        }

        $appointmentsCount = 0;
        $tasksCount = 0;

        if (c_table_exists($pdo, 'appointments')) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM appointments WHERE client_id = ?");
            $stmt->execute([$clientId]);
            $appointmentsCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        }

        if (c_table_exists($pdo, 'tasks')) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM tasks WHERE client_id = ?");
            $stmt->execute([$clientId]);
            $tasksCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        }

        if ($appointmentsCount > 0 || $tasksCount > 0) {
            header('Location: clients.php?client_id=' . urlencode((string)$clientId) . '&delete_blocked=1');
            exit;
        }

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("DELETE FROM client_locations WHERE client_id = ?");
            $stmt->execute([$clientId]);

            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);

            $pdo->commit();

            header('Location: clients.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            header('Location: clients.php?client_id=' . urlencode((string)$clientId) . '&delete_error=1');
            exit;
        }
    }

    if ($action === 'deactivate') {
        $clientId = (int)($_POST['client_id'] ?? 0);

        if ($clientId > 0) {
            $stmt = $pdo->prepare("UPDATE clients SET active = 0 WHERE id = ?");
            $stmt->execute([$clientId]);
        }

        header('Location: clients.php?deactivated=1');
        exit;
    }

    if ($action === 'reactivate') {
        $clientId = (int)($_POST['client_id'] ?? 0);

        if ($clientId > 0) {
            $stmt = $pdo->prepare("UPDATE clients SET active = 1 WHERE id = ?");
            $stmt->execute([$clientId]);
        }

        header('Location: clients.php?client_id=' . urlencode((string)$clientId) . '&reactivated=1');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Date pagina
|--------------------------------------------------------------------------
*/
$search = trim((string)($_GET['q'] ?? ''));
$legacyShowInactive = (int)($_GET['inactive'] ?? 0) === 1;
$statusFilter = (string)($_GET['status'] ?? ($legacyShowInactive ? 'all' : 'active'));
$typeFilter = (string)($_GET['type'] ?? 'all');
$selectedClientId = (int)($_GET['client_id'] ?? 0);

$allowedStatuses = ['active', 'inactive', 'all'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'active';
}

$allowedTypes = ['all', 'company', 'individual'];
if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = 'all';
}

$allowedPerPage = [20, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 20;
}

$page = max(1, (int)($_GET['page'] ?? 1));

$whereParts = ['1=1'];
$params = [];

if ($statusFilter === 'active') {
    $whereParts[] = 'c.active = 1';
} elseif ($statusFilter === 'inactive') {
    $whereParts[] = 'c.active = 0';
}

if ($typeFilter === 'company') {
    $whereParts[] = "c.client_type <> 'individual'";
} elseif ($typeFilter === 'individual') {
    $whereParts[] = "c.client_type = 'individual'";
}

if ($search !== '') {
    $whereParts[] = "(
        c.name LIKE ?
        OR c.legal_representative_name LIKE ?
        OR EXISTS (
            SELECT 1
            FROM client_locations cl_search
            WHERE cl_search.client_id = c.id
              AND cl_search.contact_person LIKE ?
        )
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where = 'WHERE ' . implode(' AND ', $whereParts);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c {$where}");
$countStmt->execute($params);
$totalClients = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalClients / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$contractsCountSql = c_table_exists($pdo, 'contracts')
    ? "(SELECT COUNT(*) FROM contracts cc WHERE cc.client_id = c.id)"
    : "0";

$stmt = $pdo->prepare("
    SELECT
        c.*,
        COUNT(DISTINCT CASE WHEN l.active = 1 THEN l.id END) AS locations_count,
        COUNT(DISTINCT CASE WHEN t.status IN ('de_programat', 'contactat', 'amanat') AND t.recurrence_stopped = 0 THEN t.id END) AS active_tasks_count,
        COUNT(DISTINCT a.id) AS appointments_count,
        COUNT(DISTINCT CASE WHEN a.status = 'finalizata' THEN a.id END) AS completed_appointments_count,
        {$contractsCountSql} AS contracts_count
    FROM clients c
    LEFT JOIN client_locations l ON l.client_id = c.id
    LEFT JOIN tasks t ON t.client_id = c.id
    LEFT JOIN appointments a ON a.client_id = c.id
    {$where}
    GROUP BY c.id
    ORDER BY c.active DESC, c.name ASC, c.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$showInactive = $statusFilter !== 'active';
$listBaseQuery = [
    'q' => $search,
    'status' => $statusFilter,
    'type' => $typeFilter,
    'per_page' => $perPage,
];
$listBaseQuery = array_filter($listBaseQuery, static function ($v) {
    return $v !== '' && $v !== null;
});
$fromResult = $totalClients > 0 ? $offset + 1 : 0;
$toResult = min($offset + count($clients), $totalClients);

$selectedClient = null;
$selectedLocations = [];
$selectedAppointments = [];
$selectedTasks = [];

if ($selectedClientId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$selectedClientId]);
    $selectedClient = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedClient) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM client_locations
            WHERE client_id = ?
            ORDER BY active DESC, sort_order ASC, location_name ASC, id ASC
        ");
        $stmt->execute([$selectedClientId]);
        $selectedLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (c_table_exists($pdo, 'appointments')) {
            $stmt = $pdo->prepare("
                SELECT
                    a.*,
                    l.location_name,
                    tm.name AS team_name
                FROM appointments a
                LEFT JOIN client_locations l ON l.id = a.client_location_id
                LEFT JOIN team_members tm ON tm.id = a.team_member_id
                WHERE a.client_id = ?
                ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC
                LIMIT 8
            ");
            $stmt->execute([$selectedClientId]);
            $selectedAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (c_table_exists($pdo, 'tasks')) {
            $stmt = $pdo->prepare("
                SELECT
                    t.*,
                    l.location_name
                FROM tasks t
                LEFT JOIN client_locations l ON l.id = t.client_location_id
                WHERE t.client_id = ?
                  AND t.status IN ('de_programat', 'contactat', 'amanat')
                  AND t.recurrence_stopped = 0
                ORDER BY t.due_date ASC, t.id ASC
                LIMIT 8
            ");
            $stmt->execute([$selectedClientId]);
            $selectedTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$clientsForJs = [];
foreach ($clients as $client) {
    $clientId = (int)$client['id'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM client_locations
        WHERE client_id = ?
        ORDER BY active DESC, sort_order ASC, location_name ASC, id ASC
    ");
    $stmt->execute([$clientId]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clientsForJs[$clientId] = [
        'id' => $clientId,
        'client_type' => $client['client_type'] ?? 'company',
        'name' => $client['name'] ?? '',
        'fiscal_code' => $client['fiscal_code'] ?? '',
        'registry_number' => $client['registry_number'] ?? '',
        'registered_address' => $client['registered_address'] ?? '',
        'registered_surface_value' => $client['registered_surface_value'] ?? '',
        'registered_surface_unit' => $client['registered_surface_unit'] ?? 'mp',
        'bank_name' => $client['bank_name'] ?? '',
        'bank_account' => $client['bank_account'] ?? '',
        'phone' => $client['phone'] ?? '',
        'email' => $client['email'] ?? '',
        'legal_representative_name' => $client['legal_representative_name'] ?? '',
        'legal_representative_role' => $client['legal_representative_role'] ?? '',
        'anaf_last_lookup_at' => $client['anaf_last_lookup_at'] ?? '',
        'anaf_raw_response' => $client['anaf_raw_response'] ?? '',
        'notes' => $client['notes'] ?? '',
        'active' => (int)($client['active'] ?? 1),
        'client_status' => $client['client_status'] ?? '',
        'sms_enabled' => (int)($client['sms_enabled'] ?? 1),
        'locations_count' => (int)($client['locations_count'] ?? 0),
        'contracts_count' => (int)($client['contracts_count'] ?? 0),
        'appointments_count' => (int)($client['appointments_count'] ?? 0),
        'completed_appointments_count' => (int)($client['completed_appointments_count'] ?? 0),
        'active_tasks_count' => (int)($client['active_tasks_count'] ?? 0),
        'locations' => array_map(function($location) {
            return [
                'id' => (int)$location['id'],
                'location_name' => $location['location_name'] ?? '',
                'address' => $location['address'] ?? '',
                'surface_value' => $location['surface_value'] ?? '',
                'surface_unit' => $location['surface_unit'] ?? 'mp',
                'contact_person' => $location['contact_person'] ?? '',
                'phone' => $location['phone'] ?? '',
                'notes' => $location['notes'] ?? '',
                'active' => (int)($location['active'] ?? 1),
            ];
        }, $locations),
    ];
}

$shouldOpenCreate = isset($_GET['open_create']) && $_GET['open_create'] === '1';
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Contacte - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

<style>
.clients-topbar { align-items: center; padding: 12px 20px; }
.clients-toolbar { width: 100%; display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 8px; align-items: center; }
.clients-search { width: 100%; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; }
.clients-search input { height: 42px; min-width: 0; }
.clients-search .btn, .clients-toolbar > .btn { height: 42px; justify-content: center; white-space: nowrap; }
.clients-hero { background: linear-gradient(135deg, #10243E, #163B63); color: #fff; border-radius: var(--radius-lg); padding: 22px 24px; box-shadow: var(--shadow-lg); margin-bottom: 16px; display: flex; justify-content: space-between; gap: 18px; flex-wrap: wrap; align-items: center; }
.clients-hero h1 { font-size: 24px; font-weight: 900; letter-spacing: -.03em; margin: 0; }
.clients-hero p { color: rgba(255,255,255,.72); margin: 4px 0 0; max-width: 760px; }
.hero-pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 8px 13px; border: 1px solid rgba(255,255,255,.18); background: rgba(255,255,255,.10); color: #fff; font-weight: 900; font-size: 13px; }
.clients-layout { display: grid; grid-template-columns: <?= $selectedClient ? 'minmax(280px, .75fr) minmax(0, 1.25fr)' : '1fr' ?>; gap: 14px; align-items: start; }
.clients-list-card, .client-profile-card, .panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow); overflow: hidden; }
.card-head { padding: 15px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.card-title { color: var(--text); font-size: 16px; font-weight: 900; }
.card-subtitle { color: var(--muted); font-size: 12px; font-weight: 750; margin-top: 2px; }
.client-list { display: grid; gap: 0; }
.client-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 14px 16px; border-bottom: 1px solid var(--border2); text-decoration: none; color: inherit; background: var(--surface); transition: background .12s; }
.client-row:last-child { border-bottom: none; }
.client-row:hover { background: var(--surface-soft); }
.client-row.active { background: var(--accent-soft); }
.client-main { min-width: 0; }
.client-title-line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.client-name { color: var(--text); font-size: 15px; font-weight: 900; overflow-wrap: anywhere; }
.type-pill, .status-pill { display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 999px; background: var(--surface-soft); border: 1px solid var(--border2); color: var(--text); font-size: 11px; font-weight: 900; }
.status-pill.inactive { color: #7a8796; }
.client-meta { margin-top: 5px; color: var(--muted); font-size: 12px; font-weight: 750; line-height: 1.45; overflow-wrap: anywhere; }
.client-actions { display: flex; align-items: center; gap: 7px; }
.client-actions .btn { min-height: 34px; font-size: 12px; padding: 6px 10px; }
.profile-header { padding: 18px; border-bottom: 1px solid var(--border); background: var(--surface-soft); }
.profile-title-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.profile-title { color: var(--text); font-size: 22px; font-weight: 900; letter-spacing: -.03em; margin: 0; }
.profile-sub { color: var(--muted); font-size: 13px; font-weight: 750; margin-top: 4px; }
.profile-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.profile-actions .sms-toggle-form { display: contents; margin: 0; }
.profile-body { padding: 16px; display: grid; gap: 14px; }
.info-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.info-box { border: 1px solid var(--border2); border-radius: 14px; padding: 12px; background: var(--surface); min-width: 0; }
.info-label { color: var(--muted); font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: .05em; }
.info-value { color: var(--text); font-size: 14px; font-weight: 800; margin-top: 4px; line-height: 1.35; overflow-wrap: anywhere; }
.section-title { color: var(--text); font-size: 15px; font-weight: 900; margin-bottom: 10px; }
.location-list, .history-list { display: grid; gap: 9px; }
.location-item, .history-item { border: 1px solid var(--border2); border-radius: 14px; padding: 12px; background: var(--surface); }
.location-top, .history-top { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
.location-name, .history-title { color: var(--text); font-weight: 900; font-size: 14px; }
.location-meta, .history-meta { color: var(--muted); font-size: 12px; font-weight: 750; line-height: 1.45; margin-top: 4px; overflow-wrap: anywhere; }
.empty-state { padding: 26px 16px; text-align: center; color: var(--muted); font-weight: 800; }
.form-section {
    background: #fff;
    border: 1px solid var(--border);
    border-left: 3px solid var(--accent);
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 16px;
    box-shadow: var(--shadow);
    transition: box-shadow .15s ease, border-color .15s ease;
}
.form-section:hover { box-shadow: var(--shadow-md); }
.form-section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text);
    font-size: 15px;
    font-weight: 800;
    letter-spacing: -.01em;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border2);
}
.form-section-title::before {
    content: '';
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--accent);
    flex-shrink: 0;
}

/* === Toggle switch Activ/Inactiv === */
.status-toggle-row {
    display: flex;
    align-items: center;
    gap: 14px;
}
.status-toggle {
    position: relative;
    display: inline-block;
    width: 52px;
    height: 28px;
    flex-shrink: 0;
}
.status-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}
.status-toggle .slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background: var(--border);
    border-radius: 999px;
    transition: background .18s ease;
}
.status-toggle .slider::before {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 22px;
    height: 22px;
    background: #fff;
    border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,.18);
    transition: transform .18s ease;
}
.status-toggle input:checked + .slider {
    background: var(--tone-success);
}
.status-toggle input:checked + .slider::before {
    transform: translateX(24px);
}
.status-toggle-label {
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
}
.status-toggle-meta {
    font-size: 12px;
    font-weight: 500;
    color: var(--muted);
    margin-top: 2px;
}
.status-toggle-state {
    margin-left: auto;
    padding: 4px 14px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    background: var(--tone-success-soft);
    color: var(--tone-success);
    border: 1px solid rgba(4,120,87,.22);
    white-space: nowrap;
    flex-shrink: 0;
    min-width: 70px;
    text-align: center;
}
.status-toggle-state.is-inactive {
    background: var(--surface-soft);
    color: var(--muted);
    border-color: var(--border);
}
.type-switch { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
.type-option { border: 1px solid var(--border); border-radius: 14px; padding: 10px; cursor: pointer; background: var(--surface); color: var(--text); font-weight: 900; text-align: center; }
.type-option.active { background: var(--accent); border-color: var(--accent); color: #fff; }
.location-form-list { display: grid; gap: 10px; }
.location-form-row { border: 1px solid var(--border2); border-radius: 16px; padding: 12px; background: var(--surface-soft); }
.location-row-head { display: flex; justify-content: space-between; gap: 8px; align-items: center; margin-bottom: 10px; }
.location-row-title { color: var(--text); font-weight: 900; font-size: 13px; }
.anaf-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: end; }
.anaf-message { margin-top: 8px; font-size: 12px; font-weight: 800; color: var(--muted); }
.anaf-message.ok { color: #166534; }
.anaf-message.bad { color: #991b1b; }
.client-danger-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.client-danger-actions form { margin: 0; }
.client-danger-actions .btn { min-height: 42px; }
@media(max-width: 1100px) { .clients-layout { grid-template-columns: 1fr; } .info-grid { grid-template-columns: 1fr; } }
@media(max-width: 860px) {
    body { overflow-x: hidden !important; }
    .clients-topbar { width: 100% !important; max-width: 100vw !important; padding: 8px 10px 12px !important; overflow-x: hidden !important; display: block !important; position: relative !important; top: auto !important; }
    .clients-toolbar { grid-template-columns: 1fr !important; gap: 8px !important; }
    .clients-search { grid-template-columns: 1fr !important; }
    .clients-search .btn, .clients-toolbar > .btn { width: 100%; min-width: 0; }
    .content { width: 100% !important; max-width: 100vw !important; overflow-x: hidden !important; }
    .clients-hero { padding: 18px; }
    .client-row { grid-template-columns: 1fr; }
    .client-actions { display: grid; grid-template-columns: 1fr 1fr; width: 100%; }
    .client-actions .btn { width: 100%; justify-content: center; }
    .profile-actions { display: grid; grid-template-columns: 1fr; width: 100%; }
    .profile-actions .btn { width: 100%; justify-content: center; }
    .anaf-row { grid-template-columns: 1fr; }
    .type-switch { grid-template-columns: 1fr; }
}

/* Lista contacte in stil Contracte - responsive */
.clients-layout { grid-template-columns: 1fr !important; }
.clients-topbar { align-items: center; padding: 12px 20px; }
.clients-toolbar { width: 100%; display: grid; grid-template-columns: minmax(220px, 1fr) 145px 145px 140px auto auto; gap: 8px; align-items: center; }
.clients-toolbar input, .clients-toolbar select { height: 42px; min-width: 0; }
.clients-toolbar .btn { height: 42px; justify-content: center; white-space: nowrap; }
.clients-hero { background: var(--surface) !important; color: var(--text) !important; border: 1px solid var(--border); box-shadow: var(--shadow); }
.clients-hero p { color: var(--muted) !important; }
.hero-pill { color: var(--text) !important; border: 1px solid var(--border2) !important; background: var(--surface-soft) !important; }
.clients-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.clients-table { width: 100%; border-collapse: collapse; table-layout: fixed; min-width: 1060px; }
.clients-table th, .clients-table td { padding: 9px 6px; border-bottom: 1px solid var(--border2); vertical-align: middle; text-align: left; }
.clients-table th { background: var(--surface-soft); color: var(--muted); font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .035em; white-space: nowrap; }
.clients-table td { color: var(--text); font-size: 12px; font-weight: 650; line-height: 1.28; overflow-wrap: anywhere; word-break: break-word; }
.clients-table tr:hover td { background: rgba(15, 118, 110, .035); }
.clients-table th:nth-child(1), .clients-table td:nth-child(1) { width: 23%; }
.clients-table th:nth-child(2), .clients-table td:nth-child(2) { width: 5%; }
.clients-table th:nth-child(3), .clients-table td:nth-child(3) { width: 8%; }
.clients-table th:nth-child(4), .clients-table td:nth-child(4) { width: 12%; }
.clients-table th:nth-child(5), .clients-table td:nth-child(5) { width: 9%; }
.clients-table th:nth-child(6), .clients-table td:nth-child(6) { width: 13%; }
.clients-table th:nth-child(7), .clients-table td:nth-child(7) { width: 14%; }
.clients-table th:nth-child(8), .clients-table td:nth-child(8) { width: 7%; }
.clients-table th:nth-child(9), .clients-table td:nth-child(9) { width: 195px; }
.client-cell-title { font-weight: 900; color: var(--text); font-size: 12.3px; line-height: 1.25; overflow-wrap: anywhere; word-break: break-word; }
.client-cell-sub { margin-top: 3px; font-size: 11px; color: var(--muted); font-weight: 750; line-height: 1.25; overflow-wrap: anywhere; word-break: break-word; }
.type-pill, .status-pill { font-size: 10px; padding: 3px 7px; }
.client-status-badge { display: inline-flex; align-items: center; justify-content: center; padding: 3px 8px; border-radius: 999px; font-size: 10px; font-weight: 900; border: 1px solid #bbf7d0; background: #ecfdf5; color: #047857; white-space: nowrap; }
.client-status-badge.inactive { border-color: #e5e7eb; background: #f8fafc; color: #64748b; }
.client-status-badge.season { border-color: #fde68a; background: #fffbeb; color: #92400e; }
.client-row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 5px; flex-wrap: nowrap; white-space: nowrap; }
.icon-action { flex: 0 0 30px; width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border2); border-radius: 9px; background: #fff; color: #64748b; text-decoration: none; cursor: pointer; transition: .18s ease; padding: 0; }
.icon-action:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); }
.icon-action.is-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.icon-action.is-primary:hover { filter: brightness(1.08); background: var(--accent); color: #fff; }
.icon-action svg { width: 14.5px; height: 14.5px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.icon-action:focus { outline: none; box-shadow: var(--focus-ring); }

/* === Kebab menu (...) - meniu cu actiunile secundare === */
.row-menu { position: relative; display: inline-block; }
.row-menu-trigger { width: 30px; height: 30px; border-radius: 9px; border: 1px solid var(--border2); background: #fff; color: #64748b; cursor: pointer; padding: 0; display: inline-flex; align-items: center; justify-content: center; transition: .14s ease; }
.row-menu-trigger:hover { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
.row-menu-trigger svg { width: 14px; height: 14px; fill: currentColor; }
.row-menu.is-open .row-menu-trigger { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
.row-menu-dropdown { display: none; position: absolute; top: calc(100% + 4px); right: 0; min-width: 200px; background: #fff; border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow-lg); padding: 4px; z-index: 100; flex-direction: column; gap: 1px; animation: rowMenuIn .12s ease; }
.row-menu.is-open .row-menu-dropdown { display: flex; }
@keyframes rowMenuIn { from { opacity: 0; transform: translateY(-3px); } to { opacity: 1; transform: translateY(0); } }
.row-menu-item { display: grid; grid-template-columns: 26px 1fr; gap: 9px; align-items: center; padding: 8px 10px; border-radius: 7px; color: var(--text); text-decoration: none; font-size: 12.5px; font-weight: 600; cursor: pointer; border: 0; background: transparent; text-align: left; transition: background .12s ease; width: 100%; font-family: inherit; }
.row-menu-item:hover { background: var(--accent-soft); }
.row-menu-item svg { width: 14px; height: 14px; color: var(--muted); fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.row-menu-item:hover svg { color: var(--accent); }
.clients-pagination { padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; color: var(--muted); font-size: 13px; font-weight: 800; }
.page-buttons { display: flex; gap: 6px; align-items: center; }
.page-btn { min-width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 10px; text-decoration: none; color: var(--text); background: var(--surface); font-weight: 900; }
.page-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }
.page-btn.disabled { pointer-events: none; opacity: .45; }

@media(max-width: 1220px) {
    .clients-table { min-width: 1000px; }
    .clients-table th, .clients-table td { padding: 8px 5px; }
    .clients-table td { font-size: 11.5px; }
    .client-cell-title { font-size: 11.8px; }
    .client-cell-sub { font-size: 10.5px; }
    .icon-action { flex-basis: 28px; width: 28px; height: 28px; }
    .icon-action svg { width: 13.5px; height: 13.5px; }
    .clients-table th:nth-child(9), .clients-table td:nth-child(9) { width: 178px; }
}

@media(max-width: 980px) {
    .clients-toolbar { grid-template-columns: 1fr 1fr; }
    .clients-toolbar .search-input { grid-column: 1 / -1; }
}

@media(max-width: 760px) {
    .clients-toolbar { grid-template-columns: 1fr !important; }
    .clients-table-wrap { overflow-x: visible; }
    .clients-table { min-width: 0; width: 100%; border-collapse: separate; border-spacing: 0 10px; table-layout: auto; }
    .clients-table thead { display: none; }
    .clients-table tbody, .clients-table tr, .clients-table td { display: block; width: 100% !important; }
    .clients-table tr { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow); padding: 10px 12px; }
    .clients-table td { border-bottom: 0; padding: 6px 0; font-size: 12px; line-height: 1.35; }
    .clients-table td::before { content: attr(data-label); display: block; margin-bottom: 2px; color: var(--muted); font-size: 9.5px; font-weight: 900; text-transform: uppercase; letter-spacing: .045em; }
    .clients-table td:first-child { padding-top: 0; }
    .clients-table td:first-child::before { display: none; }
    .clients-table td:last-child { padding-top: 10px; }
    .clients-table td:last-child::before { margin-bottom: 7px; }
    .client-cell-title { font-size: 14px; line-height: 1.25; }
    .client-cell-sub { font-size: 11.5px; }
    .client-row-actions { justify-content: flex-start; gap: 7px; flex-wrap: nowrap; overflow-x: auto; padding-bottom: 2px; }
    .icon-action { flex: 0 0 34px; width: 34px; height: 34px; border-radius: 10px; }
    .icon-action svg { width: 15.5px; height: 15.5px; }
}




/* === Fisa rapida client - pop-up / panou rapid === */
.client-quick-modal { z-index: 1200; }
.client-quick-box { width: min(980px, calc(100vw - 32px)); max-height: calc(100vh - 44px); overflow: hidden; display: flex; flex-direction: column; }
.client-quick-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 18px 20px; border-bottom: 1px solid var(--border); background: linear-gradient(135deg, #ffffff, #f8fafc); }
.client-quick-title { margin: 0; font-size: 22px; font-weight: 900; letter-spacing: -.035em; color: var(--text); }
.client-quick-subtitle { margin-top: 4px; color: var(--muted); font-size: 13px; font-weight: 750; line-height: 1.35; }
.client-quick-body { padding: 18px 20px 20px; overflow: auto; }
.client-quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.client-quick-actions .btn { min-height: 38px; font-size: 12px; padding: 8px 11px; }
.client-quick-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); gap: 14px; }
.client-quick-card { border: 1px solid var(--border); border-radius: 16px; background: #fff; box-shadow: 0 8px 22px rgba(15,23,42,.04); overflow: hidden; }
.client-quick-card-head { padding: 13px 14px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.client-quick-card-title { font-size: 14px; font-weight: 900; color: var(--text); }
.client-quick-card-body { padding: 14px; }
.client-quick-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 12px; }
.quick-field { min-width: 0; }
.quick-label { color: var(--muted); font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 3px; }
.quick-value { color: var(--text); font-size: 12.5px; font-weight: 750; line-height: 1.35; overflow-wrap: anywhere; }
.quick-value.is-muted { color: var(--muted); font-weight: 700; }
.quick-kpi-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.quick-kpi { border: 1px solid var(--border2); border-radius: 14px; background: var(--surface-soft); padding: 11px 12px; }
.quick-kpi-value { font-size: 18px; font-weight: 900; color: var(--text); line-height: 1; }
.quick-kpi-label { margin-top: 4px; font-size: 10px; font-weight: 900; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
.quick-locations-list { display: grid; gap: 9px; }
.quick-location { border: 1px solid var(--border2); border-radius: 14px; padding: 11px 12px; background: #fff; }
.quick-location-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 6px; }
.quick-location-name { font-size: 13px; font-weight: 900; color: var(--text); line-height: 1.25; }
.quick-location-meta { color: var(--muted); font-size: 12px; font-weight: 700; line-height: 1.4; overflow-wrap: anywhere; }
.quick-empty { border: 1px dashed var(--border2); border-radius: 14px; background: var(--surface-soft); padding: 14px; color: var(--muted); font-weight: 750; font-size: 12.5px; }
@media (max-width: 820px) {
    .client-quick-grid { grid-template-columns: 1fr; }
    .client-quick-details { grid-template-columns: 1fr; }
    .client-quick-box { width: calc(100vw - 20px); max-height: calc(100vh - 20px); }
    .client-quick-header { padding: 15px; }
    .client-quick-body { padding: 14px; }
}


/* === Compactare formular client nou / editare client === */
#clientModal .modal-box {
    width: min(900px, calc(100vw - 28px));
    max-height: calc(100vh - 28px);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

#clientModal .modal-header {
    padding: 14px 18px;
    flex-shrink: 0;
}

#clientModal .modal-header h2 {
    font-size: 18px;
    line-height: 1.2;
}

#clientModal form {
    overflow: auto;
    padding: 12px 18px 16px;
}

#clientModal .form-section {
    padding: 12px 14px;
    margin-bottom: 10px;
    border-radius: 14px;
}

#clientModal .form-section-title {
    font-size: 14px;
    margin-bottom: 9px;
    padding-bottom: 8px;
}

#clientModal .type-switch {
    margin-bottom: 0;
}

#clientModal .type-option {
    padding: 8px 10px;
    min-height: 38px;
}

#clientModal .form-grid {
    gap: 9px 12px;
}

#clientModal input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
#clientModal select {
    min-height: 38px;
    height: 38px;
    padding-top: 0;
    padding-bottom: 0;
}

#clientModal textarea {
    min-height: 58px;
    padding-top: 10px;
    padding-bottom: 10px;
}

#clientModal #notes {
    min-height: 72px;
}

#clientModal .anaf-row {
    gap: 8px;
}

#clientModal .anaf-row .btn {
    min-height: 38px;
    height: 38px;
}

#clientModal .location-form-list {
    gap: 8px;
}

#clientModal .location-form-row {
    padding: 10px 12px;
    border-radius: 14px;
}

#clientModal .location-row-head {
    margin-bottom: 8px;
}

#clientModal .location-row-head .btn {
    min-height: 34px;
    height: 34px;
    padding: 0 12px;
    font-size: 12px;
}

#clientModal .client-add-location-action {
    display: flex;
    justify-content: flex-start;
    padding-top: 13px;
    margin-top: 13px;
    border-top: 1px solid var(--border2);
}

#clientModal .client-add-location-action .btn {
    min-height: 38px;
    height: 38px;
    padding: 0 17px;
}

#clientModal .status-toggle-row {
    gap: 10px;
}

#clientModal .status-toggle-row + .status-toggle-row {
    margin-top: 10px !important;
}

#clientModal .actions-row {
    padding-top: 4px;
    margin-top: 0;
}

#clientModal .actions-right .btn {
    min-height: 40px;
    height: 40px;
}

@media (max-width: 760px) {
    #clientModal .modal-box {
        width: calc(100vw - 18px);
        max-height: calc(100vh - 18px);
    }

    #clientModal .modal-header {
        padding: 12px 14px;
    }

    #clientModal form {
        padding: 10px 14px 14px;
    }

    #clientModal .form-section {
        padding: 11px 12px;
        margin-bottom: 9px;
    }

    #clientModal .form-grid {
        gap: 8px;
    }

    #clientModal .client-add-location-action {
        justify-content: center;
    }

    #clientModal .client-add-location-action .btn {
        width: 100%;
    }
}

</style>
</head>

<body>
<div class="layout">
    <?php render_sidebar('clients', $isAdmin); ?>

    <main class="main">
        <div class="topbar clients-topbar">
            <form method="get" class="clients-toolbar">
                <input class="search-input" type="text" name="q" value="<?= c_h($search) ?>" placeholder="Cauta dupa nume client sau persoana de contact...">

                <select name="status">
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Activ</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactiv</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Toate</option>
                </select>

                <select name="type">
                    <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>PJ + PF</option>
                    <option value="company" <?= $typeFilter === 'company' ? 'selected' : '' ?>>Doar PJ</option>
                    <option value="individual" <?= $typeFilter === 'individual' ? 'selected' : '' ?>>Doar PF</option>
                </select>

                <select name="per_page">
                    <?php foreach ([20, 50, 100] as $pp): ?>
                        <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?> / pagina</option>
                    <?php endforeach; ?>
                </select>

                <button class="btn" type="submit">Filtreaza</button>
                <button class="btn accent" type="button" onclick="openClientModal()">+ Client nou</button>
            </form>
        </div>

        <?php if (isset($_GET['created'])): ?><div class="notice notice-success">Clientul a fost adaugat.</div><?php endif; ?>
        <?php if (isset($_GET['updated'])): ?><div class="notice notice-success">Fisa clientului a fost actualizata.</div><?php endif; ?>
        <?php if (isset($_GET['task_added'])): ?><div class="notice notice-success">Sarcina a fost adaugata clientului.</div><?php endif; ?>
        <?php if (isset($_GET['sms_updated'])): ?><div class="notice notice-success">Setarea pentru notificari SMS a fost actualizata.</div><?php endif; ?>
        <?php if (isset($_GET['deactivated'])): ?><div class="notice notice-warning">Clientul a fost dezactivat.</div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="notice notice-warning">Clientul a fost sters definitiv.</div><?php endif; ?>
        <?php if (isset($_GET['delete_blocked'])): ?><div class="notice notice-danger">Clientul nu poate fi sters definitiv deoarece are programari sau sarcini. Il poti dezactiva pentru a pastra istoricul.</div><?php endif; ?>
        <?php if (isset($_GET['delete_error'])): ?><div class="notice notice-danger">Clientul nu a putut fi sters. Verifica baza de date sau incearca dezactivarea.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'missing_name'): ?><div class="notice notice-danger">Completeaza denumirea / numele clientului.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'missing_rep'): ?><div class="notice notice-danger">Pentru persoana juridica trebuie completat reprezentantul legal.</div><?php endif; ?>

        <div class="content">
            <section class="clients-hero">
                <div>
                    <h1>Contacte</h1>
                    <p>Lista compacta de clienti, in acelasi stil cu modulul Contracte.</p>
                </div>
                <span class="hero-pill">Rezultate: <?= (int)$totalClients ?></span>
            </section>

            <section class="clients-layout">
                <div class="clients-list-card">
                    <div class="card-head">
                        <div>
                            <div class="card-title">Lista contacte</div>
                            <div class="card-subtitle">Afisare <?= (int)$fromResult ?>-<?= (int)$toResult ?> din <?= (int)$totalClients ?></div>
                        </div>
                        <a class="btn" href="clients.php">Reseteaza filtrele</a>
                    </div>

                    <?php if (!$clients): ?>
                        <div class="empty-state">Nu exista contacte pentru filtrul selectat.</div>
                    <?php else: ?>
                        <div class="clients-table-wrap">
                            <table class="clients-table">
                                <thead>
                                    <tr>
                                        <th style="width:26%;">Client</th>
                                        <th>Tip</th>
                                        <th>CUI / CNP</th>
                                        <th>Persoana contact</th>
                                        <th>Telefon</th>
                                        <th>Email</th>
                                        <th>Adresa / localitate</th>
                                        <th>Status</th>
                                        <th class="actions-col" style="text-align:right;">Actiuni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clients as $client): ?>
                                        <?php
                                            $cid = (int)$client['id'];
                                            $address = c_client_address($client);
                                            $contactPerson = c_client_contact_person($client);
                                            $preserve = $listBaseQuery;
                                            $preserve['client_id'] = $cid;
                                            $viewUrl = 'clients.php?' . http_build_query($preserve);
                                            $statusClass = c_client_status_class($client['client_status'] ?? null, (int)($client['active'] ?? 1));
                                            $statusLabel = c_client_status_label($client['client_status'] ?? null, (int)($client['active'] ?? 1));
                                        ?>
                                        <tr>
                                            <td data-label="Client">
                                                <div class="client-cell-title"><?= c_h($client['name']) ?></div>
                                                <div class="client-cell-sub">
                                                    <?= (int)($client['locations_count'] ?? 0) ?> locatii ·
                                                    <?= (int)($client['contracts_count'] ?? 0) ?> contracte ·
                                                    <?= (int)($client['appointments_count'] ?? 0) ?> programari
                                                </div>
                                            </td>
                                            <td data-label="Tip"><span class="type-pill"><?= c_h(c_client_type_label($client['client_type'] ?? 'company')) ?></span></td>
                                            <td data-label="CUI / CNP"><?= c_h($client['fiscal_code'] ?: '-') ?></td>
                                            <td data-label="Persoana contact"><?= c_h($contactPerson ?: '-') ?></td>
                                            <td data-label="Telefon"><?= c_h($client['phone'] ?: '-') ?></td>
                                            <td data-label="Email"><?= c_h($client['email'] ?: '-') ?></td>
                                            <td data-label="Adresa / localitate">
                                                <div class="client-cell-sub"><?= c_h_address($address ?: '-') ?></div>
                                            </td>
                                            <td data-label="Status"><span class="client-status-badge <?= c_h($statusClass) ?>"><?= c_h($statusLabel) ?></span></td>
                                            <td data-label="Actiuni">
                                                <div class="client-row-actions">
                                                    <!-- Actiunea principala: Vezi clientul -->
                                                    <a class="icon-action is-primary" href="<?= c_h($viewUrl) ?>" title="Vezi client" aria-label="Vezi client" onclick="event.preventDefault(); openClientQuickView(<?= $cid ?>);">
                                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                                    </a>
                                                    <!-- Restul de actiuni in meniu kebab -->
                                                    <div class="row-menu" data-row-menu>
                                                        <button class="row-menu-trigger" type="button" onclick="rowMenuToggle(this)" title="Mai multe actiuni" aria-label="Mai multe actiuni" aria-haspopup="menu">
                                                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                                                <circle cx="12" cy="5" r="1.6"></circle>
                                                                <circle cx="12" cy="12" r="1.6"></circle>
                                                                <circle cx="12" cy="19" r="1.6"></circle>
                                                            </svg>
                                                        </button>
                                                        <div class="row-menu-dropdown" role="menu">
                                                            <button class="row-menu-item" type="button" onclick="openClientModal(<?= $cid ?>); rowMenuCloseAll();" role="menuitem">
                                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"></path></svg>
                                                                <span>Editeaza client</span>
                                                            </button>
                                                            <a class="row-menu-item" href="contracts.php?client_id=<?= $cid ?>" role="menuitem">
                                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h8"></path></svg>
                                                                <span>Vezi contracte</span>
                                                            </a>
                                                            <a class="row-menu-item" href="tasks.php?client_id=<?= $cid ?>" role="menuitem">
                                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                                                                <span>Vezi sarcini</span>
                                                            </a>
                                                            <a class="row-menu-item" href="calendar.php?client_id=<?= $cid ?>" role="menuitem">
                                                                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4"></path><path d="M8 2v4"></path><path d="M3 10h18"></path></svg>
                                                                <span>Vezi programari</span>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="clients-pagination">
                            <div>Pagina <?= (int)$page ?> din <?= (int)$totalPages ?></div>
                            <div class="page-buttons">
                                <?php
                                    $prevQuery = $listBaseQuery;
                                    $prevQuery['page'] = max(1, $page - 1);
                                    $nextQuery = $listBaseQuery;
                                    $nextQuery['page'] = min($totalPages, $page + 1);
                                ?>
                                <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="clients.php?<?= c_h(http_build_query($prevQuery)) ?>">‹</a>
                                <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);
                                    for ($p = $startPage; $p <= $endPage; $p++):
                                        $pageQuery = $listBaseQuery;
                                        $pageQuery['page'] = $p;
                                ?>
                                    <a class="page-btn <?= $p === $page ? 'active' : '' ?>" href="clients.php?<?= c_h(http_build_query($pageQuery)) ?>"><?= (int)$p ?></a>
                                <?php endfor; ?>
                                <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="clients.php?<?= c_h(http_build_query($nextQuery)) ?>">›</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($selectedClient): ?>
                    <div class="client-profile-card">
                        <div class="profile-header">
                            <div class="profile-title-row">
                                <div>
                                    <h2 class="profile-title"><?= c_h($selectedClient['name']) ?></h2>
                                    <div class="profile-sub">
                                        <?= c_h(c_client_type_label($selectedClient['client_type'] ?? 'company')) ?>
                                        <?php if (!empty($selectedClient['fiscal_code'])): ?> · <?= c_h($selectedClient['fiscal_code']) ?><?php endif; ?>
                                        <?php if ((int)($selectedClient['active'] ?? 1) === 0): ?> · Inactiv<?php endif; ?>
                                        <?php if ((int)($selectedClient['sms_enabled'] ?? 1) === 0): ?> · SMS oprit<?php else: ?> · SMS activ<?php endif; ?>
                                    </div>
                                </div>
                                <div class="profile-actions">
                                    <button class="btn" type="button" onclick="openClientModal(<?= (int)$selectedClient['id'] ?>)">Editeaza fisa</button>
                                    <a class="btn" href="contract_create.php?client_id=<?= (int)$selectedClient['id'] ?>">Emite contract</a>
                                    <a class="btn" href="tasks.php?client_id=<?= (int)$selectedClient['id'] ?>&open_create=1&return_to=client">Adauga sarcina</a>
                                    <a class="btn accent" href="calendar.php?client_id=<?= (int)$selectedClient['id'] ?>&open_create=1">Programare</a>
                                    <form method="post" action="clients.php" class="sms-toggle-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_sms">
                                        <input type="hidden" name="client_id" value="<?= (int)$selectedClient['id'] ?>">
                                        <?php if ((int)($selectedClient['sms_enabled'] ?? 1) === 1): ?>
                                            <input type="hidden" name="sms_enabled" value="0">
                                            <button class="btn" type="submit" onclick="return confirm('Opresti notificarile SMS pentru acest client?')">Opreste notificari SMS</button>
                                        <?php else: ?>
                                            <input type="hidden" name="sms_enabled" value="1">
                                            <button class="btn accent" type="submit">Porneste notificari SMS</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="profile-body">
                            <div class="panel">
                                <div class="card-head">
                                    <div>
                                        <div class="card-title">Date identificare</div>
                                        <div class="card-subtitle">Date generale client</div>
                                    </div>
                                </div>
                                <div style="padding:16px;">
                                    <div class="info-grid">
                                        <div class="info-box"><div class="info-label">Denumire / nume</div><div class="info-value"><?= c_h($selectedClient['name'] ?? '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">CUI / CNP</div><div class="info-value"><?= c_h($selectedClient['fiscal_code'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Reg. Com. / Serie CI</div><div class="info-value"><?= c_h($selectedClient['registry_number'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Telefon</div><div class="info-value"><?= c_h($selectedClient['phone'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Email</div><div class="info-value"><?= c_h($selectedClient['email'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Reprezentant</div><div class="info-value"><?= c_h(c_client_contact_person($selectedClient) ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Banca</div><div class="info-value"><?= c_h($selectedClient['bank_name'] ?: '-') ?></div></div>
                                        <div class="info-box"><div class="info-label">Cont bancar</div><div class="info-value"><?= c_h($selectedClient['bank_account'] ?: '-') ?></div></div>
                                        <div class="info-box" style="grid-column:1/-1;"><div class="info-label">Sediu social / domiciliu</div><div class="info-value"><?= c_h(c_client_address($selectedClient) ?: '-') ?></div></div>
                                        <?php if (!empty($selectedClient['anaf_last_lookup_at'])): ?>
                                            <div class="info-box" style="grid-column:1/-1;"><div class="info-label">Ultima interogare ANAF</div><div class="info-value"><?= c_h($selectedClient['anaf_last_lookup_at']) ?></div></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="panel">
                                <div class="card-head">
                                    <div>
                                        <div class="card-title">Locatii / puncte de lucru</div>
                                        <div class="card-subtitle">Daca nu exista punct de lucru, prestarea se face pe sediu / domiciliu</div>
                                    </div>
                                </div>
                                <div style="padding:16px;">
                                    <?php if (!$selectedLocations): ?>
                                        <div class="empty-state">Nu exista puncte de lucru adaugate.</div>
                                    <?php else: ?>
                                        <div class="location-list">
                                            <?php foreach ($selectedLocations as $location): ?>
                                                <div class="location-item">
                                                    <div class="location-top">
                                                        <div>
                                                            <div class="location-name"><?= c_h($location['location_name'] ?: 'Punct de lucru') ?></div>
                                                            <div class="location-meta">
                                                                <?= c_h($location['address'] ?: '-') ?><?php if (!empty($location['surface_value'])): ?><br>Suprafata: <?= c_h(rtrim(rtrim(number_format((float)$location['surface_value'], 2, '.', ''), '0'), '.')) ?> <?= c_h($location['surface_unit'] ?: 'mp') ?><?php endif; ?><br>
                                                                Contact: <?= c_h($location['contact_person'] ?: '-') ?><?= !empty($location['phone']) ? ' / ' . c_h($location['phone']) : '' ?>
                                                                <?php if (!empty($location['notes'])): ?><br><?= nl2br(c_h($location['notes'])) ?><?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <span class="status-pill <?= (int)$location['active'] === 1 ? '' : 'inactive' ?>"><?= (int)$location['active'] === 1 ? 'Activ' : 'Inactiv' ?></span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="panel" id="sarcini-client">
                                <div class="card-head">
                                    <div>
                                        <div class="card-title">Sarcini active</div>
                                        <div class="card-subtitle">Sarcini neprogramate pentru acest client</div>
                                    </div>
                                    <a class="btn" href="tasks.php?client_id=<?= (int)$selectedClient['id'] ?>&open_create=1&return_to=client">Adauga sarcina</a>
                                </div>
                                <div style="padding:16px;">
                                    <?php if (!$selectedTasks): ?>
                                        <div class="empty-state">Nu exista sarcini active pentru acest client. Apasa „Adauga sarcina” pentru a crea una.</div>
                                    <?php else: ?>
                                        <div class="history-list">
                                            <?php foreach ($selectedTasks as $task): ?>
                                                <div class="history-item">
                                                    <div class="history-top">
                                                        <div>
                                                            <div class="history-title"><?= c_h($task['service_type'] ?: 'Sarcina') ?></div>
                                                            <div class="history-meta">
                                                                Scadenta: <?= c_h($task['due_date']) ?> · <?= c_h($task['location_name'] ?: 'Sediu / domiciliu') ?>
                                                            </div>
                                                        </div>
                                                        <a class="btn" href="calendar.php?client_id=<?= (int)$selectedClient['id'] ?>&task_id=<?= (int)$task['id'] ?>&service_type=<?= urlencode((string)($task['service_type'] ?? '')) ?><?= !empty($task['client_location_id']) ? '&client_location_id=' . (int)$task['client_location_id'] : '' ?>&open_create=1">Programeaza</a>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="panel">
                                <div class="card-head">
                                    <div>
                                        <div class="card-title">Istoric programari</div>
                                        <div class="card-subtitle">Ultimele programari ale clientului</div>
                                    </div>
                                </div>
                                <div style="padding:16px;">
                                    <?php if (!$selectedAppointments): ?>
                                        <div class="empty-state">Nu exista programari.</div>
                                    <?php else: ?>
                                        <div class="history-list">
                                            <?php foreach ($selectedAppointments as $appointment): ?>
                                                <div class="history-item">
                                                    <div class="history-top">
                                                        <div>
                                                            <div class="history-title"><?= c_h($appointment['service_type'] ?: 'Lucrare') ?></div>
                                                            <div class="history-meta">
                                                                <?= c_h($appointment['appointment_date']) ?> · <?= c_h(substr((string)$appointment['start_time'], 0, 5)) ?> · <?= c_h($appointment['team_name'] ?: '-') ?><br>
                                                                <?= c_h($appointment['location_name'] ?: 'Sediu / domiciliu') ?> · <?= c_h($appointment['status'] ?: '-') ?>
                                                            </div>
                                                        </div>
                                                        <span class="status-pill"><?= c_h($appointment['status'] ?: '-') ?></span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="client-danger-actions">
                                <?php if ((int)($selectedClient['active'] ?? 1) === 1): ?>
                                    <form method="post" onsubmit="return confirm('Sigur vrei sa dezactivezi acest client? Nu stergem istoricul, doar il ascundem din listele active.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="client_id" value="<?= (int)$selectedClient['id'] ?>">
                                        <button class="btn danger" type="submit">Dezactiveaza clientul</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reactivate">
                                        <input type="hidden" name="client_id" value="<?= (int)$selectedClient['id'] ?>">
                                        <button class="btn accent" type="submit">Reactiveaza clientul</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" onsubmit="return confirm('Stergerea definitiva este permisa doar daca acest client nu are programari sau sarcini. Daca are istoric, sistemul va bloca stergerea. Continui?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="permanent_delete">
                                    <input type="hidden" name="client_id" value="<?= (int)$selectedClient['id'] ?>">
                                    <button class="btn danger" type="submit">Sterge definitiv</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<div class="modal" id="clientModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="clientModalTitle">Client nou</h2>
            <button class="modal-close" type="button" onclick="closeClientModal()">&times;</button>
        </div>

        <form method="post" id="clientForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="form_action" value="create">
            <input type="hidden" name="client_id" id="client_id" value="">
            <input type="hidden" name="anaf_raw_response" id="anaf_raw_response" value="">
            <input type="hidden" name="anaf_last_lookup_at" id="anaf_last_lookup_at" value="">

            <div class="form-section">
                <div class="form-section-title">Tip client</div>
                <input type="hidden" name="client_type" id="client_type" value="company">
                <div class="type-switch">
                    <button type="button" class="type-option active" id="type_company" onclick="setClientType('company')">Persoana juridica</button>
                    <button type="button" class="type-option" id="type_individual" onclick="setClientType('individual')">Persoana fizica</button>
                </div>
            </div>

            <div class="form-section" id="anaf_section">
                <div class="form-section-title">Preluare date ANAF</div>
                <div class="anaf-row">
                    <div>
                        <label>CUI firma</label>
                        <input type="text" id="anaf_cui" placeholder="Ex: 14837428">
                    </div>
                    <button class="btn accent" type="button" onclick="lookupAnaf()">Cauta ANAF</button>
                </div>
                <div class="anaf-message" id="anaf_message">Telefonul nu se preia de la ANAF. Completeaza manual telefonul dorit.</div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Zona 1 - Identificare</div>
                <div class="form-grid">
                    <div>
                        <label>Denumire / nume *</label>
                        <input type="text" name="name" id="name" required>
                    </div>
                    <div>
                        <label>CUI / CNP</label>
                        <input type="text" name="fiscal_code" id="fiscal_code">
                    </div>
                    <div>
                        <label>Nr. Reg. Com. / Serie CI</label>
                        <input type="text" name="registry_number" id="registry_number">
                    </div>
                    <div>
                        <label>Email</label>
                        <input type="email" name="email" id="email">
                    </div>
                    <div>
                        <label>Telefon general</label>
                        <input type="tel" name="phone" id="phone">
                    </div>
                    <div id="rep_name_wrap">
                        <label>Reprezentant legal *</label>
                        <input type="text" name="legal_representative_name" id="legal_representative_name">
                    </div>
                    <div id="rep_role_wrap">
                        <label>Calitate reprezentant</label>
                        <input type="text" name="legal_representative_role" id="legal_representative_role" value="Administrator">
                    </div>
                    <div class="form-group full">
                        <label>Sediu social / adresa domiciliu</label>
                        <input type="text" name="registered_address" id="registered_address">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Zona 2 - Date bancare</div>
                <div class="form-grid">
                    <div>
                        <label>Banca</label>
                        <input type="text" name="bank_name" id="bank_name">
                    </div>
                    <div>
                        <label>Cont bancar</label>
                        <input type="text" name="bank_account" id="bank_account">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Zona 3 - Locatii / puncte de lucru</div>
                <div class="location-form-list" id="locationsFormList"></div>
                <div class="client-add-location-action">
                    <button class="btn" type="button" onclick="addLocationRow()">+ Adauga punct de lucru</button>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">Observatii client</div>
                <textarea name="notes" id="notes" placeholder="Observatii generale despre client..."></textarea>
            </div>

            <div class="form-section">
                <div class="form-section-title">Status client</div>
                <div class="status-toggle-row">
                    <label class="status-toggle" title="Activeaza / dezactiveaza clientul">
                        <input type="checkbox" name="active" id="client_active" value="1" checked onchange="updateClientStatusLabel(this)">
                        <span class="slider"></span>
                    </label>
                    <div>
                        <div class="status-toggle-label">Client activ</div>
                        <div class="status-toggle-meta">Clientii inactivi nu apar in cautari si nu primesc programari.</div>
                    </div>
                    <span class="status-toggle-state" id="status_state">Activ</span>
                </div>

                <div class="status-toggle-row" style="margin-top:14px;">
                    <label class="status-toggle" title="Activeaza / dezactiveaza trimiterea de SMS-uri catre client">
                        <input type="checkbox" name="sms_enabled" id="client_sms_enabled" value="1" checked onchange="updateClientSmsLabel(this)">
                        <span class="slider"></span>
                    </label>
                    <div>
                        <div class="status-toggle-label">Trimite SMS-uri catre client</div>
                        <div class="status-toggle-meta">Cand e oprit, NU se trimit SMS-uri automate (programari, scadente) sau manuale catre acest client.</div>
                    </div>
                    <span class="status-toggle-state" id="sms_state">Pornit</span>
                </div>
            </div>

            <div class="actions-row">
                <div></div>
                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeClientModal()">Renunta</button>
                    <button class="btn accent" type="submit">Salveaza clientul</button>
                </div>
            </div>
        </form>
    </div>
</div>


<div class="modal client-quick-modal" id="clientQuickModal">
    <div class="modal-box client-quick-box">
        <div class="client-quick-header">
            <div>
                <h2 class="client-quick-title" id="quickClientTitle">Fisa rapida client</h2>
                <div class="client-quick-subtitle" id="quickClientSubtitle">Date client, locatii si activitate.</div>
            </div>
            <button class="modal-close" type="button" onclick="closeClientQuickView()">&times;</button>
        </div>
        <div class="client-quick-body" id="quickClientBody"></div>
    </div>
</div>

<script>
const clientsData = <?= json_encode(c_fix_encoding_issues_recursive($clientsForJs), JSON_UNESCAPED_UNICODE) ?>;
const shouldOpenCreate = <?= $shouldOpenCreate ? 'true' : 'false' ?>;
let locationIndex = 0;

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function cleanText(value) {
    return String(value || '')
        .replace(/[aa]/g, 'a')
        .replace(/[AA]/g, 'A')
        .replace(/[i]/g, 'i')
        .replace(/[I]/g, 'I')
        .replace(/[ss]/g, 's')
        .replace(/[SS]/g, 'S')
        .replace(/[tt]/g, 't')
        .replace(/[TT]/g, 'T')
        .replace(/\s+/g, ' ')
        .trim();
}

function quickUrl(path, params = {}) {
    const query = new URLSearchParams();
    Object.keys(params).forEach(key => {
        const value = params[key];
        if (value !== undefined && value !== null && String(value) !== '') query.set(key, value);
    });
    const qs = query.toString();
    return path + (qs ? '?' + qs : '');
}

function quickClientTypeLabel(type) {
    return type === 'individual' ? 'PF' : 'PJ';
}

function quickStatusLabel(client) {
    if (Number(client.active) !== 1) return 'Inactiv';
    if (client.client_status === 'season') return 'Sezon';
    return 'Activ';
}

function quickField(label, value) {
    const safeValue = value && String(value).trim() !== '' ? escHtml(value) : '<span class="is-muted">-</span>';
    return `<div class="quick-field"><div class="quick-label">${escHtml(label)}</div><div class="quick-value">${safeValue}</div></div>`;
}

function quickKpi(value, label) {
    return `<div class="quick-kpi"><div class="quick-kpi-value">${escHtml(value)}</div><div class="quick-kpi-label">${escHtml(label)}</div></div>`;
}

function formatLocationSurface(location) {
    const value = String(location.surface_value || '').trim();
    if (!value) return '';
    return `Suprafata: ${escHtml(value)} ${escHtml(location.surface_unit || 'mp')}`;
}

function renderQuickLocations(client) {
    const locations = client.locations || [];
    if (!locations.length) {
        return `<div class="quick-empty">Clientul nu are locatii/puncte de lucru salvate. Pentru documente si servicii, adauga cel putin o locatie.</div>`;
    }

    return `<div class="quick-locations-list">${locations.map(location => {
        const contactBits = [];
        if (location.contact_person) contactBits.push('Contact: ' + escHtml(location.contact_person));
        if (location.phone) contactBits.push('Tel: ' + escHtml(location.phone));
        const surface = formatLocationSurface(location);
        const status = Number(location.active) === 1 ? 'Activ' : 'Inactiv';
        const statusClass = Number(location.active) === 1 ? '' : 'inactive';
        return `
            <div class="quick-location">
                <div class="quick-location-top">
                    <div class="quick-location-name">${escHtml(location.location_name || 'Punct de lucru')}</div>
                    <span class="client-status-badge ${statusClass}">${status}</span>
                </div>
                <div class="quick-location-meta">
                    ${escHtml(location.address || '-')}
                    ${contactBits.length ? '<br>' + contactBits.join(' | ') : ''}
                    ${surface ? '<br>' + surface : ''}
                    ${location.notes ? '<br>' + escHtml(location.notes) : ''}
                </div>
            </div>`;
    }).join('')}</div>`;
}

function openClientQuickView(clientId) {
    const client = clientsData[clientId];
    if (!client) {
        window.location.href = quickUrl('clients.php', {client_id: clientId});
        return;
    }

    const title = document.getElementById('quickClientTitle');
    const subtitle = document.getElementById('quickClientSubtitle');
    const body = document.getElementById('quickClientBody');
    const clientName = client.name || 'Client';
    const cid = encodeURIComponent(client.id);

    title.textContent = clientName;
    subtitle.textContent = `${quickClientTypeLabel(client.client_type)} · ${client.fiscal_code || 'fara CUI/CNP'} · ${quickStatusLabel(client)}`;

    body.innerHTML = `
        <div class="client-quick-actions">
            <button class="btn accent" type="button" onclick="closeClientQuickView(); openClientModal(${Number(client.id)});">Editeaza client</button>
        </div>
        <div class="client-quick-grid">
            <div class="client-quick-card">
                <div class="client-quick-card-head">
                    <div class="client-quick-card-title">Date client</div>
                    <span class="client-status-badge ${Number(client.active) === 1 ? '' : 'inactive'}">${escHtml(quickStatusLabel(client))}</span>
                </div>
                <div class="client-quick-card-body">
                    <div class="client-quick-details">
                        ${quickField('Tip', quickClientTypeLabel(client.client_type))}
                        ${quickField('CUI / CNP', client.fiscal_code)}
                        ${quickField('Reg. Com. / Serie CI', client.registry_number)}
                        ${quickField('Reprezentant', client.legal_representative_name)}
                        ${quickField('Calitate reprezentant', client.legal_representative_role)}
                        ${quickField('Telefon', client.phone)}
                        ${quickField('Email', client.email)}
                        ${quickField('Adresa sediu / domiciliu', client.registered_address)}
                        ${quickField('Banca', client.bank_name)}
                        ${quickField('Cont bancar', client.bank_account)}
                    </div>
                    ${client.notes ? `<div style="margin-top:12px;">${quickField('Observatii client', client.notes)}</div>` : ''}
                </div>
            </div>
            <div class="client-quick-card">
                <div class="client-quick-card-head"><div class="client-quick-card-title">Informatii activitate</div></div>
                <div class="client-quick-card-body">
                    <div class="quick-kpi-grid">
                        ${quickKpi(Number(client.contracts_count || 0) > 0 ? 'Da' : 'Nu', 'Are contract')}
                        ${quickKpi(client.contracts_count || 0, 'Contracte')}
                        ${quickKpi(client.completed_appointments_count || 0, 'Interventii finalizate')}
                        ${quickKpi(client.appointments_count || 0, 'Programari totale')}
                        ${quickKpi(client.active_tasks_count || 0, 'Sarcini active')}
                        ${quickKpi(client.locations_count || 0, 'Locatii')}
                    </div>
                </div>
            </div>
        </div>
        <div class="client-quick-card" style="margin-top:14px;">
            <div class="client-quick-card-head"><div class="client-quick-card-title">Locatii / puncte de lucru</div></div>
            <div class="client-quick-card-body">${renderQuickLocations(client)}</div>
        </div>
    `;

    document.getElementById('clientQuickModal').classList.add('open');
}

function closeClientQuickView() {
    document.getElementById('clientQuickModal').classList.remove('open');
}

function setField(id, value) {
    const field = document.getElementById(id);
    if (field) field.value = value || '';
}

function setClientType(type) {
    const isIndividual = type === 'individual';
    document.getElementById('client_type').value = isIndividual ? 'individual' : 'company';
    document.getElementById('type_company').classList.toggle('active', !isIndividual);
    document.getElementById('type_individual').classList.toggle('active', isIndividual);
    document.getElementById('anaf_section').style.display = isIndividual ? 'none' : 'block';
    document.getElementById('rep_name_wrap').style.display = isIndividual ? 'none' : 'block';
    document.getElementById('rep_role_wrap').style.display = isIndividual ? 'none' : 'block';

    const repName = document.getElementById('legal_representative_name');
    if (repName) repName.required = !isIndividual;

    if (isIndividual) {
        setField('legal_representative_name', '');
        setField('legal_representative_role', '');
    } else if (!document.getElementById('legal_representative_role').value) {
        setField('legal_representative_role', 'Administrator');
    }
}

function resetClientForm() {
    document.getElementById('clientForm').reset();
    setField('form_action', 'create');
    setField('client_id', '');
    setField('anaf_raw_response', '');
    setField('anaf_last_lookup_at', '');
    setField('anaf_cui', '');
    document.getElementById('anaf_message').className = 'anaf-message';
    document.getElementById('anaf_message').textContent = 'Telefonul nu se preia de la ANAF. Completeaza manual telefonul dorit.';
    document.getElementById('locationsFormList').innerHTML = '';
    locationIndex = 0;
    setClientType('company');
}

function openClientModal(clientId = null) {
    resetClientForm();

    if (clientId && clientsData[clientId]) {
        const client = clientsData[clientId];
        document.getElementById('clientModalTitle').textContent = 'Editeaza client';
        setField('form_action', 'update');
        setField('client_id', client.id);
        setClientType(client.client_type || 'company');
        setField('name', client.name);
        setField('fiscal_code', client.fiscal_code);
        setField('registry_number', client.registry_number);
        setField('registered_address', client.registered_address);
        setField('bank_name', client.bank_name);
        setField('bank_account', client.bank_account);
        setField('phone', client.phone);
        setField('email', client.email);
        setField('legal_representative_name', client.legal_representative_name);
        setField('legal_representative_role', client.legal_representative_role || (client.client_type === 'company' ? 'Administrator' : ''));
        setField('anaf_raw_response', client.anaf_raw_response);
        setField('anaf_last_lookup_at', client.anaf_last_lookup_at);
        setField('notes', client.notes);

        // Setam toggle Activ/Inactiv conform datelor existente
        const activeCheckbox = document.getElementById('client_active');
        if (activeCheckbox) {
            activeCheckbox.checked = (Number(client.active) === 1);
            updateClientStatusLabel(activeCheckbox);
        }
        // Setam toggle SMS conform datelor existente (default = activat daca lipseste)
        const smsCheckbox = document.getElementById('client_sms_enabled');
        if (smsCheckbox) {
            smsCheckbox.checked = (client.sms_enabled === undefined || client.sms_enabled === null) ? true : (Number(client.sms_enabled) === 1);
            updateClientSmsLabel(smsCheckbox);
        }

        (client.locations || []).forEach(location => addLocationRow(location));
    } else {
        document.getElementById('clientModalTitle').textContent = 'Client nou';
        // Pentru client nou, default e activ
        const activeCheckbox = document.getElementById('client_active');
        if (activeCheckbox) {
            activeCheckbox.checked = true;
            updateClientStatusLabel(activeCheckbox);
        }
        const smsCheckbox = document.getElementById('client_sms_enabled');
        if (smsCheckbox) {
            smsCheckbox.checked = true;
            updateClientSmsLabel(smsCheckbox);
        }
        addLocationRow();
    }

    document.getElementById('clientModal').classList.add('open');
}

/* === Toggle label dinamic Activ/Inactiv === */
function updateClientStatusLabel(checkbox) {
    const stateEl = document.getElementById('status_state');
    if (!stateEl) return;
    if (checkbox.checked) {
        stateEl.textContent = 'Activ';
        stateEl.classList.remove('is-inactive');
    } else {
        stateEl.textContent = 'Inactiv';
        stateEl.classList.add('is-inactive');
    }
}

/* === Toggle label dinamic SMS pornit/oprit === */
function updateClientSmsLabel(checkbox) {
    const stateEl = document.getElementById('sms_state');
    if (!stateEl) return;
    if (checkbox.checked) {
        stateEl.textContent = 'Pornit';
        stateEl.classList.remove('is-inactive');
    } else {
        stateEl.textContent = 'Oprit';
        stateEl.classList.add('is-inactive');
    }
}

function closeClientModal() {
    document.getElementById('clientModal').classList.remove('open');
}

function addLocationRow(location = {}) {
    const list = document.getElementById('locationsFormList');
    const idx = locationIndex++;
    const row = document.createElement('div');
    row.className = 'location-form-row';
    row.dataset.idx = idx;

    row.innerHTML = `
        <input type="hidden" name="location_id[]" value="${escHtml(location.id || '')}">
        <input type="hidden" name="location_active[]" id="location_active_${idx}" value="${location.active === 0 ? '0' : '1'}">
        <div class="location-row-head">
            <div class="location-row-title">Punct de lucru</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="btn" type="button" onclick="copyClientContactToLocation(${idx})">Preia date contact</button>
                <button class="btn danger" type="button" onclick="removeLocationRow(this, ${idx}, ${location.id ? 'true' : 'false'})">Sterge</button>
            </div>
        </div>
        <div class="form-grid">
            <div>
                <label>Nume punct de lucru</label>
                <input type="text" name="location_name[]" id="location_name_${idx}" value="${escHtml(location.location_name || '')}" placeholder="Ex: Magazin Tomis Mall">
            </div>
            <div>
                <label>Persoana contact locatie</label>
                <input type="text" name="location_contact_person[]" id="location_contact_person_${idx}" value="${escHtml(location.contact_person || '')}">
            </div>
            <div>
                <label>Telefon contact locatie</label>
                <input type="tel" name="location_phone[]" id="location_phone_${idx}" value="${escHtml(location.phone || '')}">
            </div>
            <div class="form-group full">
                <label>Adresa punct de lucru</label>
                <input type="text" name="location_address[]" id="location_address_${idx}" value="${escHtml(location.address || '')}">
            </div>
            <div>
                <label>Suprafata locatie</label>
                <input type="text" name="location_surface_value[]" id="location_surface_value_${idx}" value="${escHtml(location.surface_value || '')}" placeholder="Ex: 120">
            </div>
            <div>
                <label>Unitate suprafata</label>
                <select name="location_surface_unit[]" id="location_surface_unit_${idx}">
                    <option value="mp" ${(!location.surface_unit || location.surface_unit === 'mp') ? 'selected' : ''}>m²</option>
                    <option value="ml" ${location.surface_unit === 'ml' ? 'selected' : ''}>ml</option>
                    <option value="buc" ${location.surface_unit === 'buc' ? 'selected' : ''}>buc.</option>
                </select>
            </div>
            <div class="form-group full">
                <label>Notite locatie / particularitati</label>
                <textarea name="location_notes[]" id="location_notes_${idx}">${escHtml(location.notes || '')}</textarea>
            </div>
        </div>
    `;

    if (location.active === 0) {
        row.style.opacity = '.55';
    }

    list.appendChild(row);
}

function removeLocationRow(button, idx, existing) {
    const row = button.closest('.location-form-row');
    if (!row) return;

    if (existing) {
        document.getElementById('location_active_' + idx).value = '0';
        row.style.display = 'none';
    } else {
        row.remove();
    }
}

function copyClientContactToLocation(idx) {
    const type = document.getElementById('client_type').value;
    const clientName = document.getElementById('name').value;
    const repName = document.getElementById('legal_representative_name').value;
    const phone = document.getElementById('phone').value;
    const contact = type === 'individual' ? clientName : (repName || clientName);

    setField('location_contact_person_' + idx, contact);
    setField('location_phone_' + idx, phone);
}

async function lookupAnaf() {
    const cui = document.getElementById('anaf_cui').value || document.getElementById('fiscal_code').value;
    const message = document.getElementById('anaf_message');

    if (!cui.trim()) {
        message.className = 'anaf-message bad';
        message.textContent = 'Introdu CUI-ul firmei.';
        return;
    }

    message.className = 'anaf-message';
    message.textContent = 'Se interogheaza ANAF...';

    try {
        const res = await fetch('clients.php?ajax=anaf_lookup&cui=' + encodeURIComponent(cui));
        const json = await res.json();

        if (!json.success || !json.data) {
            message.className = 'anaf-message bad';
            message.textContent = json.message || 'Firma nu a fost gasita la ANAF.';
            console.warn('ANAF debug:', json.debug || null);
            return;
        }

        const data = json.data;
        setClientType('company');
        setField('name', cleanText(data.name || ''));
        setField('fiscal_code', data.fiscal_code || '');
        setField('registry_number', cleanText(data.registry_number || ''));
        setField('registered_address', cleanText(data.registered_address || ''));
        setField('bank_account', cleanText(data.bank_account || ''));
        setField('anaf_last_lookup_at', data.anaf_last_lookup_at || '');
        setField('anaf_raw_response', data.anaf_raw_response || '');

        message.className = 'anaf-message ok';
        message.textContent = 'Datele au fost preluate de la ANAF. Telefonul ramane completat manual.';
    } catch (err) {
        console.error(err);
        message.className = 'anaf-message bad';
        message.textContent = 'Eroare la interogarea ANAF.';
    }
}

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', event => {
        if (event.target === modal) modal.classList.remove('open');
    });
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') document.querySelectorAll('.modal.open').forEach(modal => modal.classList.remove('open'));
});

document.addEventListener('DOMContentLoaded', () => {
    if (shouldOpenCreate) {
        openClientModal();
    }
});

/* === Kebab menu toggle pentru row actions === */
function rowMenuCloseAll() {
    document.querySelectorAll('.row-menu.is-open').forEach(m => m.classList.remove('is-open'));
}
function rowMenuToggle(triggerEl) {
    const menu = triggerEl.closest('.row-menu');
    if (!menu) return;
    const wasOpen = menu.classList.contains('is-open');
    rowMenuCloseAll();
    if (!wasOpen) menu.classList.add('is-open');
}
document.addEventListener('click', (e) => {
    if (!e.target.closest('.row-menu')) rowMenuCloseAll();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') rowMenuCloseAll();
});
</script>
</body>
</html>
