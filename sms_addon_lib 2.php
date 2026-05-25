<?php
/*
|--------------------------------------------------------------------------
| sms_addon_lib.php  - utilitare adaugatoare pentru modulul SMS
|--------------------------------------------------------------------------
| ATENTIE: Trimiterea efectiva a SMS-urilor este in notification_lib.php.
| Acest fișier expune doar utilitare unice (init schema, helpere DB).
|
| Functii eliminate (erau duplicate cu notification_lib.php):
|   - pz_send_appointment_created_sms() -> folosește pz_send_appointment_confirmation_sms()
|   - pz_send_task_expiring_7_sms()     -> rămâne doar in notification_lib.php
|   - pz_sms_send()                     -> folosește direct pz_smslink_send_sms()
|   - pz_sms_client_enabled()           -> folosește pz_client_sms_enabled()
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/notification_lib.php';

if (!function_exists('pz_sms_db')) {
    function pz_sms_db(): PDO {
        if (function_exists('pz_db')) return pz_db();
        global $pdo, $db, $conn;
        if (isset($pdo) && $pdo instanceof PDO) return $pdo;
        if (isset($db)  && $db  instanceof PDO) return $db;
        if (isset($conn) && $conn instanceof PDO) return $conn;
        throw new RuntimeException('Nu am gasit conexiunea PDO.');
    }
}

if (!function_exists('pz_sms_table_exists')) {
    function pz_sms_table_exists($table): bool {
        try {
            $stmt = pz_sms_db()->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('pz_sms_col_exists')) {
    function pz_sms_col_exists($table, $col): bool {
        try {
            $stmt = pz_sms_db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$col]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('pz_sms_add_col')) {
    function pz_sms_add_col($table, $col, $definition): void {
        if (pz_sms_table_exists($table) && !pz_sms_col_exists($table, $col)) {
            pz_sms_db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
        }
    }
}

if (!function_exists('pz_sms_get')) {
    function pz_sms_get($key, $default = '') {
        if (function_exists('pz_setting_get')) return pz_setting_get($key, $default);
        $stmt = pz_sms_db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    }
}

if (!function_exists('pz_sms_set')) {
    function pz_sms_set($key, $value): void {
        if (function_exists('pz_setting_set')) { pz_setting_set($key, $value); return; }
        $stmt = pz_sms_db()->prepare(
            'INSERT INTO app_settings(setting_key, setting_value, updated_at) VALUES(?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        $stmt->execute([$key, (string)$value]);
    }
}

if (!function_exists('pz_sms_init')) {
    function pz_sms_init(): void {
        $pdo = pz_sms_db();
        if (function_exists('pz_notify_init')) pz_notify_init();

        $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key   VARCHAR(120) PRIMARY KEY,
            setting_value MEDIUMTEXT NULL,
            updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_templates (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            template_key  VARCHAR(120) UNIQUE,
            channel       ENUM('email','sms') NOT NULL,
            title         VARCHAR(160) NOT NULL,
            subject       VARCHAR(255) NULL,
            body          MEDIUMTEXT NOT NULL,
            active        TINYINT(1) NOT NULL DEFAULT 1,
            updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_logs (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            channel           ENUM('email','sms') NOT NULL,
            provider          VARCHAR(50) NOT NULL,
            recipient         VARCHAR(255) NOT NULL,
            subject           VARCHAR(255) NULL,
            message           MEDIUMTEXT NULL,
            status            ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
            http_code         INT NULL,
            provider_response MEDIUMTEXT NULL,
            related_type      VARCHAR(80) NULL,
            related_id        INT NULL,
            created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_created (created_at),
            INDEX idx_notif_status (status),
            INDEX idx_notif_related (related_type, related_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Coloane pentru opt-out per client
        pz_sms_add_col('clients',     'sms_enabled',                "TINYINT(1) NOT NULL DEFAULT 1");
        pz_sms_add_col('clients',     'sms_opt_out_reason',         "VARCHAR(255) NULL");
        pz_sms_add_col('clients',     'sms_opt_out_at',             "DATETIME NULL");
        pz_sms_add_col('appointments','sms_confirmation_sent_at',   "DATETIME NULL");
        pz_sms_add_col('appointments','sms_confirmation_status',    "VARCHAR(30) NULL");
        pz_sms_add_col('tasks',       'sms_7_days_sent_at',         "DATETIME NULL");
        pz_sms_add_col('tasks',       'sms_7_days_status',          "VARCHAR(30) NULL");

        pz_sms_seed();
    }
}

if (!function_exists('pz_sms_seed')) {
    function pz_sms_seed(): void {
        $rows = [
            [
                'appointment_created_sms', 'sms', 'SMS confirmare programare', null,
                '{brand}: Programarea pentru {service} a fost efectuata pentru data de {date}, interval {time}, la locatia {location}.'
            ],
            [
                'task_expiring_7_sms', 'sms', 'SMS scadență sarcina in 7 zile', null,
                '{brand}: Bună ziua, va reamintim ca valabilitatea procesului verbal expira in 7 zile. Vă rugăm sa ne contactati pentru programarea urmatoarei intervenții.'
            ],
        ];
        $stmt = pz_sms_db()->prepare(
            'INSERT IGNORE INTO notification_templates (template_key, channel, title, subject, body) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) $stmt->execute($row);

        if (pz_sms_get('sms_brand_name', '') === '') pz_sms_set('sms_brand_name', 'PestZone');
    }
}
