<?php
/**
 * PestZone CRM - notification_lib.php
 * Safe v2: SendGrid + SMSLink + SMS Templates + client opt-out.
 */

if (defined('PZ_NOTIFICATION_LIB_LOADED')) {
    return;
}
define('PZ_NOTIFICATION_LIB_LOADED', true);

require_once __DIR__ . '/config.php';

function pz_db(): PDO
{
    global $pdo, $db, $conn;

    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    if (isset($conn) && $conn instanceof PDO) return $conn;

    throw new RuntimeException('Nu am găsit conexiunea PDO. Verifică config.php.');
}

function pz_table_exists(string $table): bool
{
    try {
        $stmt = pz_db()->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function pz_column_exists(string $table, string $column): bool
{
    try {
        if (!pz_table_exists($table)) return false;
        $stmt = pz_db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function pz_add_column_if_missing(string $table, string $column, string $definition): void
{
    try {
        if (pz_table_exists($table) && !pz_column_exists($table, $column)) {
            pz_db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $e) {
        error_log('PestZone migration warning: '.$e->getMessage());
    }
}

function pz_setting_get_raw(string $key, $default = '')
{
    try {
        $stmt = pz_db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null) ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function pz_setting_set_raw(string $key, $value): void
{
    $stmt = pz_db()->prepare("
        INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $stmt->execute([$key, (string)$value]);
}

function pz_notify_init(): void
{
    static $done = false;
    static $running = false;

    if ($done || $running) return;
    $running = true;

    $pdo = pz_db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(120) PRIMARY KEY,
            setting_value MEDIUMTEXT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            channel ENUM('email','sms') NOT NULL,
            provider VARCHAR(50) NOT NULL,
            recipient VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NULL,
            message MEDIUMTEXT NULL,
            status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
            http_code INT NULL,
            provider_response MEDIUMTEXT NULL,
            related_type VARCHAR(80) NULL,
            related_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notification_logs_channel_created (channel, created_at),
            INDEX idx_notification_logs_related (related_type, related_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(120) NOT NULL UNIQUE,
            channel ENUM('email','sms') NOT NULL,
            title VARCHAR(160) NOT NULL,
            subject VARCHAR(255) NULL,
            body MEDIUMTEXT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    pz_add_column_if_missing('clients', 'sms_enabled', "TINYINT(1) NOT NULL DEFAULT 1");
    pz_add_column_if_missing('clients', 'sms_opt_out_reason', "VARCHAR(255) NULL");
    pz_add_column_if_missing('clients', 'sms_opt_out_at', "DATETIME NULL");

    pz_add_column_if_missing('appointments', 'sms_confirmation_sent_at', "DATETIME NULL");
    pz_add_column_if_missing('appointments', 'sms_confirmation_status', "VARCHAR(30) NULL");

    pz_add_column_if_missing('tasks', 'sms_7_days_sent_at', "DATETIME NULL");
    pz_add_column_if_missing('tasks', 'sms_7_days_status', "VARCHAR(30) NULL");

    $templates = [
        ['appointment_created_sms', 'sms', 'SMS confirmare programare', null,
            '{brand}: Programarea pentru {service} a fost efectuata pentru data de {date}, interval {time}, la locatia {location}.'],
        ['task_expiring_7_sms', 'sms', 'SMS scadenta sarcina in 7 zile', null,
            '{brand}: Buna ziua, va reamintim ca valabilitatea procesului verbal expira in 7 zile. Va rugam sa ne contactati pentru programarea urmatoarei interventii.'],
        ['password_reset_email', 'email', 'Email resetare parola', 'Resetare parola PestZone',
            '<p>Buna ziua,</p><p>Ati solicitat resetarea parolei pentru PestZone.</p><p><a href="{reset_link}">Resetare parola</a></p><p>Linkul este valabil 60 de minute.</p>'],
        ['contract_send_email', 'email', 'Email trimitere contract', 'Contract {contract_number}',
            '<p>Buna ziua,</p><p>Va transmitem contractul {contract_number}.</p><p>Cu stima,<br>PestZone</p>'],
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO notification_templates
        (template_key, channel, title, subject, body)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($templates as $tpl) {
        $stmt->execute($tpl);
    }

    if (pz_setting_get_raw('sms_brand_name', '') === '') {
        pz_setting_set_raw('sms_brand_name', 'PestZone');
    }
    if (pz_setting_get_raw('smslink_enabled', '') === '') {
        pz_setting_set_raw('smslink_enabled', '1');
    }

    $running = false;
    $done = true;
}

function pz_setting_get(string $key, $default = '')
{
    pz_notify_init();
    return pz_setting_get_raw($key, $default);
}

function pz_setting_set(string $key, $value): void
{
    pz_notify_init();
    pz_setting_set_raw($key, $value);
}

function pz_notify_log(
    string $channel,
    string $provider,
    string $recipient,
    ?string $subject,
    ?string $message,
    string $status,
    ?int $httpCode,
    ?string $response,
    ?string $relatedType = null,
    ?int $relatedId = null
): void {
    try {
        pz_notify_init();
        $stmt = pz_db()->prepare("
            INSERT INTO notification_logs
            (channel, provider, recipient, subject, message, status, http_code, provider_response, related_type, related_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$channel, $provider, $recipient, $subject, $message, $status, $httpCode, $response, $relatedType, $relatedId]);
    } catch (Throwable $e) {
        error_log('PestZone notification log warning: '.$e->getMessage());
    }
}

function pz_render_template(string $template, array $vars): string
{
    $vars['brand'] = $vars['brand'] ?? pz_setting_get('sms_brand_name', 'PestZone');
    foreach ($vars as $key => $value) {
        $template = str_replace('{'.$key.'}', (string)$value, $template);
    }
    return $template;
}

function pz_template_get(string $templateKey): ?array
{
    pz_notify_init();
    $stmt = pz_db()->prepare("SELECT * FROM notification_templates WHERE template_key = ? AND active = 1 LIMIT 1");
    $stmt->execute([$templateKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pz_template_body(string $templateKey, string $fallback): string
{
    $tpl = pz_template_get($templateKey);
    return $tpl ? (string)$tpl['body'] : $fallback;
}

function pz_clean_phone_ro(string $phone): string
{
    $phone = trim($phone);
    $phone = preg_replace('/[^\d\+]/', '', $phone);
    if (str_starts_with($phone, '+40')) return '0'.substr($phone, 3);
    if (str_starts_with($phone, '0040')) return '0'.substr($phone, 4);
    return $phone;
}

function pz_client_sms_enabled(int $clientId): bool
{
    if ($clientId <= 0 || !pz_table_exists('clients') || !pz_column_exists('clients', 'sms_enabled')) {
        return true;
    }
    $stmt = pz_db()->prepare("SELECT sms_enabled FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$clientId]);
    return ((string)$stmt->fetchColumn() !== '0');
}

function pz_sendgrid_send_email(
    string $toEmail,
    string $subject,
    string $html,
    ?string $text = null,
    array $attachments = [],
    ?string $relatedType = null,
    ?int $relatedId = null
): array {
    pz_notify_init();

    $apiKey = trim((string)pz_setting_get('sendgrid_api_key', ''));
    $region = trim((string)pz_setting_get('sendgrid_region', 'global'));
    $fromEmail = trim((string)pz_setting_get('email_from_address', ''));
    $fromName = trim((string)pz_setting_get('email_from_name', 'PestZone'));
    $replyTo = trim((string)pz_setting_get('email_reply_to', ''));

    if ($apiKey === '' || $fromEmail === '') {
        $msg = 'SendGrid nu este configurat complet.';
        pz_notify_log('email', 'sendgrid', $toEmail, $subject, $html, 'failed', null, $msg, $relatedType, $relatedId);
        return ['ok' => false, 'error' => $msg];
    }

    $endpoint = ($region === 'eu') ? 'https://api.eu.sendgrid.com/v3/mail/send' : 'https://api.sendgrid.com/v3/mail/send';

    $payload = [
        'personalizations' => [[ 'to' => [[ 'email' => $toEmail ]] ]],
        'from' => ['email' => $fromEmail, 'name' => $fromName ?: 'PestZone'],
        'subject' => $subject,
        'content' => [['type' => 'text/html', 'value' => $html]]
    ];

    if ($text !== null && trim($text) !== '') {
        array_unshift($payload['content'], ['type' => 'text/plain', 'value' => $text]);
    }
    if ($replyTo !== '') {
        $payload['reply_to'] = ['email' => $replyTo];
    }

    if ($attachments) {
        $payload['attachments'] = [];
        foreach ($attachments as $att) {
            if (empty($att['path']) || !is_readable($att['path'])) continue;
            $payload['attachments'][] = [
                'content' => base64_encode(file_get_contents($att['path'])),
                'type' => $att['mime'] ?? 'application/octet-stream',
                'filename' => $att['filename'] ?? basename($att['path']),
                'disposition' => 'attachment'
            ];
        }
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($curlError === '' && $httpCode >= 200 && $httpCode < 300);
    pz_notify_log('email','sendgrid',$toEmail,$subject,$html,$ok?'sent':'failed',$httpCode ?: null,$curlError ?: (string)$response,$relatedType,$relatedId);

    return ['ok'=>$ok, 'http_code'=>$httpCode, 'response'=>$response, 'error'=>$curlError ?: (!$ok ? (string)$response : null)];
}

function pz_smslink_send_sms(
    string $toPhone,
    string $message,
    ?string $relatedType = null,
    ?int $relatedId = null,
    ?int $clientId = null,
    bool $allowWithoutClient = false
): array {
    pz_notify_init();

    // GUARD #1 - clientId obligatoriu (fail-safe).
    // Trimitere fara client se accepta DOAR daca apelantul a setat explicit
    // $allowWithoutClient = true (cazuri: SMS de test din comm settings).
    // Asta previne accidente in cod nou care uita sa treaca clientId.
    if (($clientId === null || $clientId <= 0) && !$allowWithoutClient) {
        $msg = 'Trimitere SMS refuzata: lipseste clientId. Pentru SMS de test folositi $allowWithoutClient = true.';
        pz_notify_log('sms','smslink',$toPhone,null,$message,'skipped',null,$msg,$relatedType,$relatedId);
        return ['ok'=>false, 'skipped'=>true, 'error'=>$msg];
    }

    // GUARD #2 - cand avem clientId, citim DIRECT din DB statusul sms_enabled.
    // Nu folosim cache. Daca query-ul esueaza, NU trimitem (fail-safe).
    if ($clientId !== null && $clientId > 0) {
        try {
            $stmt = pz_db()->prepare("SELECT sms_enabled FROM clients WHERE id = ? LIMIT 1");
            $stmt->execute([$clientId]);
            $row = $stmt->fetchColumn();
            if ($row !== false && (string)$row === '0') {
                $msg = 'SMS oprit explicit pentru clientul #' . $clientId . ' (sms_enabled=0).';
                pz_notify_log('sms','smslink',$toPhone,null,$message,'skipped',null,$msg,$relatedType,$relatedId);
                return ['ok'=>false, 'skipped'=>true, 'error'=>$msg];
            }
        } catch (Throwable $e) {
            $msg = 'Eroare la verificarea sms_enabled: ' . $e->getMessage();
            pz_notify_log('sms','smslink',$toPhone,null,$message,'failed',null,$msg,$relatedType,$relatedId);
            return ['ok'=>false, 'error'=>$msg];
        }
    }

    $connectionId = trim((string)pz_setting_get('smslink_connection_id', ''));
    $password = trim((string)pz_setting_get('smslink_password', ''));
    $enabled = (int)pz_setting_get('smslink_enabled', '1');
    $toPhone = pz_clean_phone_ro($toPhone);

    if (!$enabled) {
        $msg = 'Trimiterea SMS este dezactivată global.';
        pz_notify_log('sms','smslink',$toPhone,null,$message,'skipped',null,$msg,$relatedType,$relatedId);
        return ['ok'=>false, 'skipped'=>true, 'error'=>$msg];
    }
    if ($connectionId === '' || $password === '') {
        $msg = 'SMSLink nu este configurat complet.';
        pz_notify_log('sms','smslink',$toPhone,null,$message,'failed',null,$msg,$relatedType,$relatedId);
        return ['ok'=>false, 'error'=>$msg];
    }
    if ($toPhone === '' || strlen($toPhone) < 10) {
        $msg = 'Telefon invalid pentru SMS: '.$toPhone;
        pz_notify_log('sms','smslink',$toPhone,null,$message,'failed',null,$msg,$relatedType,$relatedId);
        return ['ok'=>false, 'error'=>$msg];
    }

    $endpoint = 'https://secure.smslink.ro/sms/gateway/communicate/index.php';
    $query = http_build_query([
        'connection_id' => $connectionId,
        'password' => $password,
        'to' => $toPhone,
        'message' => $message
    ]);

    $ch = curl_init($endpoint.'?'.$query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $responseText = trim((string)$response);
    $ok = ($curlError === '' && $httpCode >= 200 && $httpCode < 300 && $responseText !== '');
    pz_notify_log('sms','smslink',$toPhone,null,$message,$ok?'sent':'failed',$httpCode ?: null,$curlError ?: $responseText,$relatedType,$relatedId);

    return ['ok'=>$ok, 'http_code'=>$httpCode, 'response'=>$responseText, 'error'=>$curlError ?: (!$ok ? $responseText : null)];
}

function pz_send_appointment_confirmation_sms(int $appointmentId): array
{
    pz_notify_init();
    // PZ_FIX_TEMPLATE_ACTIVE_CHECK_pz_send_appointment_confirmation_sms
    $__tpl = pz_template_get('appointment_created_sms');
    if (!$__tpl) {
        pz_notify_log('sms','smslink','',null,null,'skipped',null,'Sablonul SMS confirmare programare este dezactivat din Sabloane SMS.','appointment',null);
        return ['ok'=>false, 'skipped'=>true, 'error'=>'Sablonul SMS confirmare programare este dezactivat din Sabloane SMS.'];
    }

    $pdo = pz_db();

    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ? LIMIT 1");
    $stmt->execute([$appointmentId]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$a) {
        return ['ok' => false, 'error' => 'Programarea nu există.'];
    }

    $clientId = (int)($a['client_id'] ?? 0);
    $client = [];

    if ($clientId > 0 && pz_table_exists('clients')) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    if ($clientId > 0 && !pz_client_sms_enabled($clientId)) {
        $msg = 'SMS oprit pentru client.';
        pz_notify_log('sms', 'smslink', (string)($a['contact_phone'] ?? ($client['phone'] ?? '')), null, null, 'skipped', null, $msg, 'appointment', $appointmentId);
        return ['ok' => false, 'skipped' => true, 'error' => $msg];
    }

    /*
     * Ordinea corectă pentru PestZone:
     * 1. appointments.contact_phone — snapshotul salvat efectiv în programare
     * 2. client_locations.phone — telefonul locației
     * 3. clients.phone — telefonul general al clientului
     */
    $phone = '';
    $location = 'locatia clientului';
    $address = '';

    // 1. Telefonul salvat direct în programare
    foreach (['contact_phone', 'phone', 'appointment_phone'] as $col) {
        if (!empty($a[$col])) {
            $phone = (string)$a[$col];
            break;
        }
    }

    // 2. Telefon / adresă / denumire client
    if ($phone === '') {
        foreach (['phone', 'telefon', 'client_phone'] as $col) {
            if (!empty($client[$col])) {
                $phone = (string)$client[$col];
                break;
            }
        }
    }

    foreach (['address', 'registered_address', 'social_address', 'sediu_social'] as $col) {
        if (!empty($client[$col])) {
            $address = (string)$client[$col];
            break;
        }
    }

    // 3. Punct de lucru / locație
    if (!empty($a['client_location_id']) && pz_table_exists('client_locations')) {
        $stmt = $pdo->prepare("SELECT * FROM client_locations WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$a['client_location_id']]);
        $loc = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach (['location_name', 'name', 'denumire', 'label'] as $col) {
            if (!empty($loc[$col])) {
                $location = (string)$loc[$col];
                break;
            }
        }

        foreach (['address', 'location_address', 'adresa'] as $col) {
            if (!empty($loc[$col])) {
                $address = (string)$loc[$col];
                break;
            }
        }

        // Telefon locație doar dacă programarea nu are telefon salvat
        if ($phone === '') {
            foreach (['phone', 'contact_phone', 'location_phone', 'telefon'] as $col) {
                if (!empty($loc[$col])) {
                    $phone = (string)$loc[$col];
                    break;
                }
            }
        }
    } else {
        $location = 'Sediu social / domiciliu';
    }

    $phone = pz_clean_phone_ro($phone);

    if ($phone === '' || strlen($phone) < 10) {
        $debug = [
            'appointment_id' => $appointmentId,
            'appointment_contact_phone' => $a['contact_phone'] ?? null,
            'appointment_client_id' => $a['client_id'] ?? null,
            'appointment_client_location_id' => $a['client_location_id'] ?? null,
            'client_phone' => $client['phone'] ?? null,
            'client_loaded' => !empty($client),
        ];

        pz_notify_log(
            'sms',
            'smslink',
            $phone,
            null,
            null,
            'failed',
            null,
            'Telefon invalid sau lipsă pentru SMS. Debug: ' . json_encode($debug, JSON_UNESCAPED_UNICODE),
            'appointment',
            $appointmentId
        );

        return [
            'ok' => false,
            'error' => 'Telefon invalid sau lipsă pentru SMS. Verifică dacă programarea are client selectat și telefon contact lucrare completat.',
            'debug' => $debug
        ];
    }

    $service = 'servicii DDD';
    foreach (['service_type', 'service_name', 'service', 'title', 'service_label'] as $col) {
        if (!empty($a[$col])) {
            $service = (string)$a[$col];
            break;
        }
    }

    if (!empty($a['service_id']) && pz_table_exists('services')) {
        $stmt = $pdo->prepare("SELECT name FROM services WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$a['service_id']]);
        $sn = $stmt->fetchColumn();
        if ($sn) {
            $service = (string)$sn;
        }
    }

    $date = (string)($a['appointment_date'] ?? $a['date'] ?? '');
    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
        $date = date('d.m.Y', strtotime($date));
    }

    $timeParts = [];
    foreach (['start_time', 'time_start', 'start'] as $col) {
        if (!empty($a[$col])) {
            $timeParts[] = substr((string)$a[$col], 0, 5);
            break;
        }
    }
    foreach (['end_time', 'time_end', 'end'] as $col) {
        if (!empty($a[$col])) {
            $timeParts[] = substr((string)$a[$col], 0, 5);
            break;
        }
    }

    $body = pz_template_body(
        'appointment_created_sms',
        '{brand}: Programarea pentru {service} a fost efectuata pentru data de {date}, interval {time}, la locatia {location}.'
    );

    $msg = pz_render_template($body, [
        'client' => $client['name'] ?? '',
        'service' => $service,
        'date' => $date,
        'time' => implode('-', $timeParts),
        'location' => $location,
        'address' => $address,
        'brand' => pz_setting_get('sms_brand_name', 'PestZone'),
        'company_phone' => pz_setting_get('company_phone', '')
    ]);

    $res = pz_smslink_send_sms($phone, $msg, 'appointment', $appointmentId, $clientId);

    try {
        if (!empty($res['ok'])) {
            $pdo->prepare("UPDATE appointments SET sms_confirmation_sent_at = NOW(), sms_confirmation_status = 'sent' WHERE id = ?")->execute([$appointmentId]);
        } elseif (!empty($res['skipped'])) {
            $pdo->prepare("UPDATE appointments SET sms_confirmation_status = 'skipped' WHERE id = ?")->execute([$appointmentId]);
        } else {
            $pdo->prepare("UPDATE appointments SET sms_confirmation_status = 'failed' WHERE id = ?")->execute([$appointmentId]);
        }
    } catch (Throwable $e) {}

    return $res;
}

function pz_send_task_expiring_7_sms(int $taskId): array
{
    pz_notify_init();
    // PZ_FIX_TEMPLATE_ACTIVE_CHECK_pz_send_task_expiring_7_sms
    $__tpl = pz_template_get('task_expiring_7_sms');
    if (!$__tpl) {
        pz_notify_log('sms','smslink','',null,null,'skipped',null,'Sablonul SMS scadenta sarcina 7 zile este dezactivat din Sabloane SMS.','task',null);
        return ['ok'=>false, 'skipped'=>true, 'error'=>'Sablonul SMS scadenta sarcina 7 zile este dezactivat din Sabloane SMS.'];
    }

    $pdo = pz_db();

    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? LIMIT 1");
    $stmt->execute([$taskId]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) return ['ok'=>false, 'error'=>'Sarcina nu există.'];

    $clientId = (int)($t['client_id'] ?? 0);
    $client = [];
    if ($clientId > 0 && pz_table_exists('clients')) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    if ($clientId > 0 && !pz_client_sms_enabled($clientId)) {
        return ['ok'=>false, 'skipped'=>true, 'error'=>'SMS oprit pentru client.'];
    }

    $phone = (string)($client['phone'] ?? '');
    if ($phone === '') return ['ok'=>false, 'error'=>'Clientul nu are telefon.'];

    $service = 'servicii DDD';
    foreach (['service_name','service','title'] as $col) {
        if (!empty($t[$col])) { $service = (string)$t[$col]; break; }
    }

    $location = 'locatia clientului';
    if (!empty($t['client_location_id']) && pz_table_exists('client_locations')) {
        $stmt = $pdo->prepare("SELECT location_name FROM client_locations WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$t['client_location_id']]);
        $ln = $stmt->fetchColumn();
        if ($ln) $location = (string)$ln;
    }

    $due = (string)($t['due_date'] ?? '');
    if ($due && preg_match('/^\d{4}-\d{2}-\d{2}/', $due)) $due = date('d.m.Y', strtotime($due));

    $body = pz_template_body('task_expiring_7_sms', '{brand}: Buna ziua, va reamintim ca valabilitatea procesului verbal expira in 7 zile. Va rugam sa ne contactati pentru programarea urmatoarei interventii.');
    $msg = pz_render_template($body, [
        'client'=>$client['name'] ?? '',
        'service'=>$service,
        'date'=>$due,
        'location'=>$location,
        'brand'=>pz_setting_get('sms_brand_name','PestZone'),
        'company_phone'=>pz_setting_get('company_phone','')
    ]);

    $res = pz_smslink_send_sms($phone, $msg, 'task', $taskId, $clientId);

    try {
        if ($res['ok']) {
            $pdo->prepare("UPDATE tasks SET sms_7_days_sent_at = NOW(), sms_7_days_status = 'sent' WHERE id = ?")->execute([$taskId]);
        } elseif (!empty($res['skipped'])) {
            $pdo->prepare("UPDATE tasks SET sms_7_days_status = 'skipped' WHERE id = ?")->execute([$taskId]);
        } else {
            $pdo->prepare("UPDATE tasks SET sms_7_days_status = 'failed' WHERE id = ?")->execute([$taskId]);
        }
    } catch (Throwable $e) {}

    return $res;
}

try {
    pz_notify_init();
} catch (Throwable $e) {
    error_log('PestZone notification init error: '.$e->getMessage());
}
