<?php
/*
|--------------------------------------------------------------------------
| PestZone CRM - Review & Satisfactie
|--------------------------------------------------------------------------
| Modul separat pentru solicitare feedback dupa interventii finalizate.
| Regula: prima interventie trimite SMS si poate afisa Google Review la 5 stele.
| Interventiile ulterioare trimit doar email si raman strict pentru control intern.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_lib.php';

if (!function_exists('pz_review_db')) {
    function pz_review_db(): PDO
    {
        if (function_exists('pz_db')) {
            return pz_db();
        }
        global $pdo, $db, $conn;
        if (isset($pdo) && $pdo instanceof PDO) return $pdo;
        if (isset($db) && $db instanceof PDO) return $db;
        if (isset($conn) && $conn instanceof PDO) return $conn;
        throw new RuntimeException('Nu am gasit conexiunea PDO.');
    }
}

if (!function_exists('pz_review_h')) {
    function pz_review_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pz_review_table_exists')) {
    function pz_review_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('pz_review_column_exists')) {
    function pz_review_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('pz_review_ensure_column')) {
    function pz_review_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
    {
        try {
            if (pz_review_table_exists($pdo, $table) && !pz_review_column_exists($pdo, $table, $column)) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        } catch (Throwable $e) {
            error_log('PestZone review migration warning: ' . $e->getMessage());
        }
    }
}

if (!function_exists('pz_review_setting_get')) {
    function pz_review_setting_get(string $key, string $default = ''): string
    {
        try {
            pz_notify_init();
            return (string)pz_setting_get($key, $default);
        } catch (Throwable $e) {
            try {
                $stmt = pz_review_db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
                $stmt->execute([$key]);
                $value = $stmt->fetchColumn();
                return ($value === false || $value === null) ? $default : (string)$value;
            } catch (Throwable $e2) {
                return $default;
            }
        }
    }
}

if (!function_exists('pz_review_setting_set')) {
    function pz_review_setting_set(string $key, string $value): void
    {
        pz_notify_init();
        pz_setting_set($key, $value);
    }
}

if (!function_exists('pz_review_public_base_url')) {
    function pz_review_public_base_url(): string
    {
        $configured = trim(pz_review_setting_get('review_public_base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return '';
        }
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if ($dir === '' || $dir === '.') {
            $dir = '';
        }
        return $scheme . '://' . $host . $dir;
    }
}

if (!function_exists('pz_review_feedback_url')) {
    function pz_review_feedback_url(string $token): string
    {
        $base = pz_review_public_base_url();
        if ($base === '') {
            return 'feedback.php?t=' . urlencode($token);
        }
        return $base . '/feedback.php?t=' . urlencode($token);
    }
}

if (!function_exists('pz_review_default_questions')) {
    function pz_review_default_questions(): array
    {
        return [
            'q1' => ['label' => 'Cat de multumit ati fost de comunicarea cu biroul?', 'type' => 'score'],
            'q2' => ['label' => 'Echipa a ajuns in intervalul stabilit?', 'type' => 'score'],
            'q3' => ['label' => 'Operatorii au fost politicosi si profesionisti?', 'type' => 'score'],
            'q4' => ['label' => 'Lucrarea a fost explicata clar?', 'type' => 'score'],
            'q5' => ['label' => 'Interventia a fost realizata curat si ordonat?', 'type' => 'score'],
            'q6' => ['label' => 'Problema a fost rezolvata sau imbunatatita?', 'type' => 'score'],
            'q7' => ['label' => 'Documentele / procesul verbal au fost clare?', 'type' => 'score'],
            'q8' => ['label' => 'Cat de probabil este sa ne recomandati?', 'type' => 'score'],
            'q9' => ['label' => 'Ce putem imbunatati?', 'type' => 'text'],
            'q10' => ['label' => 'Doriti sa va contacteze cineva din conducere?', 'type' => 'yesno'],
        ];
    }
}

if (!function_exists('pz_review_init')) {
    function pz_review_init(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo = pz_review_db();
        pz_notify_init();

        $pdo->exec("CREATE TABLE IF NOT EXISTS review_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NULL,
            appointment_id INT NULL,
            token VARCHAR(80) NOT NULL UNIQUE,
            phone VARCHAR(40) NULL,
            email VARCHAR(190) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'created',
            rating TINYINT NULL,
            rating_comment MEDIUMTEXT NULL,
            sent_at DATETIME NULL,
            opened_at DATETIME NULL,
            rated_at DATETIME NULL,
            google_clicked_at DATETIME NULL,
            completed_at DATETIME NULL,
            internal_alert_sent_at DATETIME NULL,
            provider_status VARCHAR(40) NULL,
            provider_response MEDIUMTEXT NULL,
            delivery_channel VARCHAR(20) NOT NULL DEFAULT 'sms',
            is_first_intervention TINYINT(1) NULL,
            allow_google_review TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_review_client (client_id),
            INDEX idx_review_appointment (appointment_id),
            INDEX idx_review_status (status),
            INDEX idx_review_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS review_answers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            question_key VARCHAR(40) NOT NULL,
            question_label VARCHAR(255) NOT NULL,
            answer_value MEDIUMTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_review_answers_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        pz_review_ensure_column($pdo, 'review_requests', 'delivery_channel', "VARCHAR(20) NOT NULL DEFAULT 'sms'");
        pz_review_ensure_column($pdo, 'review_requests', 'is_first_intervention', "TINYINT(1) NULL");
        pz_review_ensure_column($pdo, 'review_requests', 'allow_google_review', "TINYINT(1) NOT NULL DEFAULT 1");

        $defaults = [
            'review_enabled' => '0',
            'review_only_first_appointment' => '1',
            'review_scan_days' => '7',
            'review_google_url' => '',
            'review_alert_email' => pz_review_setting_get('email_reply_to', ''),
            'review_sms_template' => '{brand}: Va multumim ca ati ales serviciile noastre. Spuneti-ne cum a fost experienta: {feedback_link}',
            'review_email_subject' => 'Formular satisfactie interventie {brand}',
            'review_email_template' => '<p>Buna ziua,</p><p>Va rugam sa ne transmiteti feedback despre interventia efectuata de echipa noastra:</p><p><a href="{feedback_link}">Completeaza formularul de satisfactie</a></p><p>Va multumim,<br>{brand}</p>',
            'review_cron_key' => bin2hex(random_bytes(16)),
        ];

        foreach ($defaults as $key => $value) {
            if (pz_review_setting_get($key, '') === '') {
                pz_review_setting_set($key, (string)$value);
            }
        }

        $done = true;
    }
}

if (!function_exists('pz_review_first_non_empty')) {
    function pz_review_first_non_empty(array $row, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim((string)$row[$key]);
            }
        }
        return $default;
    }
}

if (!function_exists('pz_review_load_client')) {
    function pz_review_load_client(int $clientId): array
    {
        if ($clientId <= 0 || !pz_review_table_exists(pz_review_db(), 'clients')) {
            return [];
        }
        $stmt = pz_review_db()->prepare('SELECT * FROM clients WHERE id = ? LIMIT 1');
        $stmt->execute([$clientId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('pz_review_client_name')) {
    function pz_review_client_name(array $client): string
    {
        return pz_review_first_non_empty($client, ['name', 'client_name', 'denumire', 'nume', 'company_name', 'legal_name'], 'Client');
    }
}

if (!function_exists('pz_review_client_phone')) {
    function pz_review_client_phone(array $client): string
    {
        return pz_review_first_non_empty($client, ['phone', 'telefon', 'client_phone', 'mobile', 'contact_phone']);
    }
}

if (!function_exists('pz_review_client_email')) {
    function pz_review_client_email(array $client): string
    {
        return pz_review_first_non_empty($client, ['email', 'client_email', 'mail']);
    }
}

if (!function_exists('pz_review_clean_phone')) {
    function pz_review_clean_phone(string $phone): string
    {
        if (function_exists('pz_clean_phone_ro')) {
            return pz_clean_phone_ro($phone);
        }
        $phone = preg_replace('/[^0-9+]/', '', trim($phone));
        if (strpos($phone, '+40') === 0) return '0' . substr($phone, 3);
        if (strpos($phone, '0040') === 0) return '0' . substr($phone, 4);
        return $phone;
    }
}

if (!function_exists('pz_review_load_appointment')) {
    function pz_review_load_appointment(int $appointmentId): array
    {
        if ($appointmentId <= 0 || !pz_review_table_exists(pz_review_db(), 'appointments')) {
            return [];
        }
        $stmt = pz_review_db()->prepare('SELECT * FROM appointments WHERE id = ? LIMIT 1');
        $stmt->execute([$appointmentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('pz_review_request_exists_for_appointment')) {
    function pz_review_request_exists_for_appointment(int $appointmentId): bool
    {
        $stmt = pz_review_db()->prepare('SELECT COUNT(*) FROM review_requests WHERE appointment_id = ?');
        $stmt->execute([$appointmentId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('pz_review_client_already_requested')) {
    function pz_review_client_already_requested(int $clientId): bool
    {
        if ($clientId <= 0) return false;
        $stmt = pz_review_db()->prepare('SELECT COUNT(*) FROM review_requests WHERE client_id = ?');
        $stmt->execute([$clientId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('pz_review_has_prior_finalized_appointment')) {
    function pz_review_has_prior_finalized_appointment(array $appointment): bool
    {
        $clientId = (int)($appointment['client_id'] ?? 0);
        $appointmentId = (int)($appointment['id'] ?? 0);
        if ($clientId <= 0 || $appointmentId <= 0) return false;

        $date = (string)($appointment['appointment_date'] ?? $appointment['date'] ?? '');
        if ($date !== '') {
            $sql = "SELECT COUNT(*) FROM appointments
                    WHERE client_id = ?
                      AND status = 'finalizata'
                      AND id <> ?
                      AND (appointment_date < ? OR (appointment_date = ? AND id < ?))";
            $stmt = pz_review_db()->prepare($sql);
            $stmt->execute([$clientId, $appointmentId, $date, $date, $appointmentId]);
            return (int)$stmt->fetchColumn() > 0;
        }

        $stmt = pz_review_db()->prepare("SELECT COUNT(*) FROM appointments WHERE client_id = ? AND status = 'finalizata' AND id < ?");
        $stmt->execute([$clientId, $appointmentId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('pz_review_token')) {
    function pz_review_token(): string
    {
        return bin2hex(random_bytes(24));
    }
}

if (!function_exists('pz_review_appointment_phone_email')) {
    function pz_review_appointment_phone_email(array $appointment, array $client): array
    {
        $phone = pz_review_first_non_empty($appointment, ['contact_phone', 'phone', 'appointment_phone']);
        $email = pz_review_first_non_empty($appointment, ['contact_email', 'email', 'client_email_snapshot']);

        if ($phone === '' && !empty($appointment['client_location_id']) && pz_review_table_exists(pz_review_db(), 'client_locations')) {
            try {
                $stmt = pz_review_db()->prepare('SELECT * FROM client_locations WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$appointment['client_location_id']]);
                $loc = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $phone = pz_review_first_non_empty($loc, ['phone', 'contact_phone', 'location_phone', 'telefon']);
                if ($email === '') {
                    $email = pz_review_first_non_empty($loc, ['email', 'contact_email']);
                }
            } catch (Throwable $e) {
                // continuam cu datele clientului
            }
        }

        if ($phone === '') {
            $phone = pz_review_client_phone($client);
        }
        if ($email === '') {
            $email = pz_review_client_email($client);
        }

        return [pz_review_clean_phone($phone), trim($email)];
    }
}

if (!function_exists('pz_review_plain_text_from_html')) {
    function pz_review_plain_text_from_html(string $html): string
    {
        $html = str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $html);
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }
}

if (!function_exists('pz_review_send_email_request')) {
    function pz_review_send_email_request(string $email, string $subject, string $html, int $requestId): array
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'skipped' => true, 'error' => 'Email invalid sau lipsa.'];
        }
        return pz_sendgrid_send_email(
            $email,
            $subject,
            $html,
            pz_review_plain_text_from_html($html),
            [],
            'review_request',
            $requestId
        );
    }
}

if (!function_exists('pz_review_create_and_send')) {
    function pz_review_create_and_send(int $appointmentId, bool $force = false): array
    {
        pz_review_init();
        $pdo = pz_review_db();

        if (!$force && pz_review_setting_get('review_enabled', '0') !== '1') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'Modulul Review este dezactivat.'];
        }

        $appointment = pz_review_load_appointment($appointmentId);
        if (!$appointment) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'Programarea nu exista.'];
        }
        if ((string)($appointment['status'] ?? '') !== 'finalizata') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'Programarea nu este finalizata.'];
        }
        if (pz_review_request_exists_for_appointment($appointmentId)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'Exista deja solicitare pentru aceasta programare.'];
        }

        $clientId = (int)($appointment['client_id'] ?? 0);
        if ($clientId <= 0) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'Programarea nu are client.'];
        }

        $isFirstIntervention = !pz_review_has_prior_finalized_appointment($appointment);
        $googleOnlyFirst = pz_review_setting_get('review_only_first_appointment', '1') === '1';
        $allowGoogleReview = $isFirstIntervention || !$googleOnlyFirst;
        $deliveryChannel = $isFirstIntervention ? 'sms' : 'email';

        $client = pz_review_load_client($clientId);
        list($phone, $email) = pz_review_appointment_phone_email($appointment, $client);

        if ($deliveryChannel === 'sms' && ($phone === '' || strlen($phone) < 10)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'Prima interventie trebuie trimisa prin SMS, dar telefonul este invalid sau lipsa.'];
        }
        if ($deliveryChannel === 'email' && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'Interventia ulterioara trebuie trimisa prin email, dar emailul este invalid sau lipsa.'];
        }

        $token = pz_review_token();
        $stmt = $pdo->prepare("INSERT INTO review_requests
            (client_id, appointment_id, token, phone, email, status, delivery_channel, is_first_intervention, allow_google_review, created_at)
            VALUES (?, ?, ?, ?, ?, 'created', ?, ?, ?, NOW())");
        $stmt->execute([
            $clientId,
            $appointmentId,
            $token,
            $phone,
            $email,
            $deliveryChannel,
            $isFirstIntervention ? 1 : 0,
            $allowGoogleReview ? 1 : 0,
        ]);
        $requestId = (int)$pdo->lastInsertId();

        $feedbackLink = pz_review_feedback_url($token);
        $brand = pz_review_setting_get('sms_brand_name', 'PestZone');
        $clientName = pz_review_client_name($client);

        if ($deliveryChannel === 'sms') {
            $template = pz_review_setting_get('review_sms_template', '{brand}: Va multumim ca ati ales serviciile noastre. Spuneti-ne cum a fost experienta: {feedback_link}');
            $message = pz_render_template($template, [
                'brand' => $brand,
                'feedback_link' => $feedbackLink,
                'client' => $clientName,
            ]);

            $result = pz_smslink_send_sms($phone, $message, 'review_request', $requestId, $clientId);
        } else {
            $subjectTemplate = pz_review_setting_get('review_email_subject', 'Formular satisfactie interventie {brand}');
            $bodyTemplate = pz_review_setting_get('review_email_template', '<p>Buna ziua,</p><p>Va rugam sa ne transmiteti feedback despre interventia efectuata de echipa noastra:</p><p><a href="{feedback_link}">Completeaza formularul de satisfactie</a></p><p>Va multumim,<br>{brand}</p>');
            $vars = [
                'brand' => $brand,
                'feedback_link' => $feedbackLink,
                'client' => $clientName,
            ];
            $subject = pz_render_template($subjectTemplate, $vars);
            $html = pz_render_template($bodyTemplate, $vars);
            $result = pz_review_send_email_request($email, $subject, $html, $requestId);
        }

        $status = !empty($result['ok']) ? 'sent' : (!empty($result['skipped']) ? 'skipped' : 'failed');
        $stmt = $pdo->prepare("UPDATE review_requests SET status = ?, provider_status = ?, provider_response = ?, sent_at = CASE WHEN ? = 'sent' THEN NOW() ELSE sent_at END WHERE id = ?");
        $stmt->execute([$status, $status, json_encode($result, JSON_UNESCAPED_UNICODE), $status, $requestId]);

        return [
            'ok' => !empty($result['ok']),
            'request_id' => $requestId,
            'status' => $status,
            'channel' => $deliveryChannel,
            'is_first_intervention' => $isFirstIntervention,
            'allow_google_review' => $allowGoogleReview,
            'provider' => $result,
            'link' => $feedbackLink,
        ];
    }
}

if (!function_exists('pz_review_scan_and_send')) {
    function pz_review_scan_and_send(int $limit = 50): array
    {
        pz_review_init();
        $pdo = pz_review_db();
        $limit = max(1, min(200, $limit));
        $days = (int)pz_review_setting_get('review_scan_days', '7');
        if ($days <= 0) $days = 7;

        $sql = "SELECT a.*
                FROM appointments a
                WHERE a.status = 'finalizata'
                  AND COALESCE(a.client_id, 0) > 0
                  AND NOT EXISTS (SELECT 1 FROM review_requests rr WHERE rr.appointment_id = a.id)
                  AND (a.appointment_date IS NULL OR a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL " . $days . " DAY))
                ORDER BY a.appointment_date ASC, a.id ASC
                LIMIT " . (int)$limit;
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $stats = ['checked' => count($rows), 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];
        foreach ($rows as $row) {
            $res = pz_review_create_and_send((int)$row['id']);
            if (!empty($res['ok'])) {
                $stats['sent']++;
            } elseif (!empty($res['skipped'])) {
                $stats['skipped']++;
            } else {
                $stats['failed']++;
            }
            $stats['details'][] = ['appointment_id' => (int)$row['id'], 'result' => $res];
        }
        return $stats;
    }
}

if (!function_exists('pz_review_load_request_by_token')) {
    function pz_review_load_request_by_token(string $token): array
    {
        pz_review_init();
        $stmt = pz_review_db()->prepare('SELECT * FROM review_requests WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('pz_review_mark_opened')) {
    function pz_review_mark_opened(int $requestId): void
    {
        pz_review_db()->prepare("UPDATE review_requests SET opened_at = COALESCE(opened_at, NOW()), status = CASE WHEN status IN ('sent','created') THEN 'opened' ELSE status END WHERE id = ?")->execute([$requestId]);
    }
}

if (!function_exists('pz_review_save_rating')) {
    function pz_review_save_rating(int $requestId, int $rating, string $comment = ''): void
    {
        $rating = max(1, min(5, $rating));
        pz_review_db()->prepare("UPDATE review_requests SET rating = ?, rating_comment = ?, rated_at = NOW(), status = 'rated' WHERE id = ?")->execute([$rating, $comment, $requestId]);
    }
}

if (!function_exists('pz_review_mark_google_click')) {
    function pz_review_mark_google_click(int $requestId): void
    {
        pz_review_db()->prepare("UPDATE review_requests SET google_clicked_at = NOW(), status = CASE WHEN status IN ('sent','created','opened','rated') THEN 'google_clicked' ELSE status END WHERE id = ?")->execute([$requestId]);
    }
}

if (!function_exists('pz_review_save_answers')) {
    function pz_review_save_answers(int $requestId, array $answers): void
    {
        $pdo = pz_review_db();
        $questions = pz_review_default_questions();
        $pdo->prepare('DELETE FROM review_answers WHERE request_id = ?')->execute([$requestId]);
        $stmt = $pdo->prepare('INSERT INTO review_answers (request_id, question_key, question_label, answer_value) VALUES (?, ?, ?, ?)');
        foreach ($questions as $key => $q) {
            $value = isset($answers[$key]) ? trim((string)$answers[$key]) : '';
            if ($value === '') {
                continue;
            }
            $stmt->execute([$requestId, $key, (string)$q['label'], $value]);
        }
        $pdo->prepare("UPDATE review_requests SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$requestId]);
        pz_review_send_internal_alert($requestId);
    }
}

if (!function_exists('pz_review_answers_for_request')) {
    function pz_review_answers_for_request(int $requestId): array
    {
        $stmt = pz_review_db()->prepare('SELECT * FROM review_answers WHERE request_id = ? ORDER BY id ASC');
        $stmt->execute([$requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('pz_review_send_internal_alert')) {
    function pz_review_send_internal_alert(int $requestId): void
    {
        $pdo = pz_review_db();
        $stmt = $pdo->prepare('SELECT * FROM review_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$request) return;
        if (!empty($request['internal_alert_sent_at'])) return;

        $to = trim(pz_review_setting_get('review_alert_email', ''));
        if ($to === '') return;

        $client = pz_review_load_client((int)($request['client_id'] ?? 0));
        $clientName = pz_review_client_name($client);
        $rating = (int)($request['rating'] ?? 0);
        $answers = pz_review_answers_for_request($requestId);

        $html = '<h2>Feedback client sub 5 stele</h2>';
        $html .= '<p><strong>Client:</strong> ' . pz_review_h($clientName) . '</p>';
        $html .= '<p><strong>Rating:</strong> ' . pz_review_h((string)$rating) . ' / 5</p>';
        if (trim((string)($request['rating_comment'] ?? '')) !== '') {
            $html .= '<p><strong>Comentariu initial:</strong><br>' . nl2br(pz_review_h($request['rating_comment'])) . '</p>';
        }
        if ($answers) {
            $html .= '<h3>Raspunsuri formular satisfactie</h3><ul>';
            foreach ($answers as $a) {
                $html .= '<li><strong>' . pz_review_h($a['question_label'] ?? '') . '</strong><br>' . nl2br(pz_review_h($a['answer_value'] ?? '')) . '</li>';
            }
            $html .= '</ul>';
        }

        $res = pz_sendgrid_send_email($to, 'Feedback client sub 5 stele - ' . $clientName, $html, strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), [], 'review_request', $requestId);
        if (!empty($res['ok'])) {
            $pdo->prepare('UPDATE review_requests SET internal_alert_sent_at = NOW() WHERE id = ?')->execute([$requestId]);
        }
    }
}
