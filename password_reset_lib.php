<?php
/**
 * Password reset helpers - EuroPrest CRM
 * Texte fara diacritice, compatibil cu editorul PHP/cPanel.
 */

if (!function_exists('pr_h')) {
    function pr_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pr_table_exists')) {
    function pr_table_exists(PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('pr_column_exists')) {
    function pr_column_exists(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n              AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('pr_ensure_schema')) {
    function pr_ensure_schema(PDO $pdo): void {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS password_resets (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                account_type VARCHAR(30) NOT NULL,\n                account_id INT NOT NULL,\n                email VARCHAR(190) NOT NULL,\n                token_hash CHAR(64) NOT NULL,\n                expires_at DATETIME NOT NULL,\n                used_at DATETIME NULL,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                created_ip VARCHAR(45) NULL,\n                user_agent VARCHAR(255) NULL,\n                INDEX idx_token_hash (token_hash),\n                INDEX idx_email_created (email, created_at),\n                INDEX idx_account (account_type, account_id),\n                INDEX idx_expires (expires_at)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");
    }
}

if (!function_exists('pr_client_ip')) {
    function pr_client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return (string)$_SERVER[$key];
            }
        }
        return '0.0.0.0';
    }
}

if (!function_exists('pr_base_url')) {
    function pr_base_url(): string {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

        if ($dir === '' || $dir === '.') {
            $dir = '';
        }

        return $scheme . '://' . $host . $dir;
    }
}

if (!function_exists('pr_find_account_by_email')) {
    function pr_find_account_by_email(PDO $pdo, string $email): ?array {
        $email = trim(strtolower($email));

        if (pr_table_exists($pdo, 'users')) {
            $hasActive = pr_column_exists($pdo, 'users', 'active');
            $sql = "SELECT id, name, email FROM users WHERE LOWER(email) = LOWER(?)";
            if ($hasActive) {
                $sql .= " AND active = 1";
            }
            $sql .= " LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return [
                    'account_type' => 'user',
                    'account_id' => (int)$row['id'],
                    'email' => (string)$row['email'],
                    'name' => (string)($row['name'] ?? 'Utilizator'),
                ];
            }
        }

        if (pr_table_exists($pdo, 'team_members') && pr_column_exists($pdo, 'team_members', 'email')) {
            $hasActive = pr_column_exists($pdo, 'team_members', 'active');
            $sql = "SELECT id, name, email FROM team_members WHERE LOWER(email) = LOWER(?)";
            if ($hasActive) {
                $sql .= " AND active = 1";
            }
            $sql .= " LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return [
                    'account_type' => 'team',
                    'account_id' => (int)$row['id'],
                    'email' => (string)$row['email'],
                    'name' => (string)($row['name'] ?? 'Echipa teren'),
                ];
            }
        }

        return null;
    }
}

if (!function_exists('pr_build_reset_email')) {
    function pr_build_reset_email(string $name, string $link): array {
        $displayName = trim($name) !== '' ? trim($name) : 'Utilizator';
        $safeName = htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $subject = 'Resetare parola cont CRM';

        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.55;color:#10243e;">'
            . '<p>Buna ziua, ' . $safeName . ',</p>'
            . '<p>A fost solicitata resetarea parolei pentru contul tau din platforma CRM.</p>'
            . '<p><a href="' . $safeLink . '" style="display:inline-block;background:#1160B7;color:#ffffff;text-decoration:none;padding:11px 16px;border-radius:10px;font-weight:700;">Seteaza parola noua</a></p>'
            . '<p>Daca butonul nu functioneaza, copiaza acest link in browser:</p>'
            . '<p style="word-break:break-all;color:#526B82;">' . $safeLink . '</p>'
            . '<p>Linkul este valabil 60 de minute. Daca nu ai solicitat resetarea parolei, ignora acest email.</p>'
            . '<p>Mesaj automat.</p>'
            . '</div>';

        $text = "Buna ziua, {$displayName},\n\n"
            . "A fost solicitata resetarea parolei pentru contul tau din platforma CRM.\n\n"
            . "Pentru a seta o parola noua, acceseaza linkul de mai jos:\n"
            . $link . "\n\n"
            . "Linkul este valabil 60 de minute.\n"
            . "Daca nu ai solicitat resetarea parolei, ignora acest email.\n\n"
            . "Mesaj automat.";

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
}

if (!function_exists('pr_send_reset_email')) {
    function pr_send_reset_email(string $to, string $name, string $link): bool {
        $email = pr_build_reset_email($name, $link);

        $notificationFile = __DIR__ . '/notification_lib.php';
        if (file_exists($notificationFile)) {
            require_once $notificationFile;
        }

        if (function_exists('pz_sendgrid_send_email')) {
            try {
                $result = pz_sendgrid_send_email(
                    $to,
                    $email['subject'],
                    $email['html'],
                    $email['text'],
                    [],
                    'password_reset',
                    null
                );
                return !empty($result['ok']);
            } catch (Throwable $e) {
                error_log('Password reset SendGrid error: ' . $e->getMessage());
                return false;
            }
        }

        error_log('Password reset email failed: pz_sendgrid_send_email unavailable.');
        return false;
    }
}

if (!function_exists('pr_create_reset')) {
    function pr_create_reset(PDO $pdo, array $account): array {
        pr_ensure_schema($pdo);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new DateTimeImmutable('+60 minutes'))->format('Y-m-d H:i:s');
        $ip = pr_client_ip();
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

        $stmt = $pdo->prepare("\n            UPDATE password_resets\n            SET used_at = NOW()\n            WHERE account_type = ?\n              AND account_id = ?\n              AND used_at IS NULL\n        ");
        $stmt->execute([$account['account_type'], $account['account_id']]);

        $stmt = $pdo->prepare("\n            INSERT INTO password_resets\n                (account_type, account_id, email, token_hash, expires_at, created_ip, user_agent)\n            VALUES\n                (?, ?, ?, ?, ?, ?, ?)\n        ");
        $stmt->execute([
            $account['account_type'],
            $account['account_id'],
            $account['email'],
            $tokenHash,
            $expiresAt,
            $ip,
            $ua,
        ]);

        $link = pr_base_url() . '/reset_password.php?token=' . urlencode($rawToken);

        return ['token' => $rawToken, 'link' => $link, 'expires_at' => $expiresAt];
    }
}

if (!function_exists('pr_get_valid_reset')) {
    function pr_get_valid_reset(PDO $pdo, string $rawToken): ?array {
        pr_ensure_schema($pdo);
        $rawToken = trim($rawToken);

        if ($rawToken === '' || strlen($rawToken) < 40) {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);
        $stmt = $pdo->prepare("\n            SELECT *\n            FROM password_resets\n            WHERE token_hash = ?\n              AND used_at IS NULL\n              AND expires_at >= NOW()\n            LIMIT 1\n        ");
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('pr_update_password')) {
    function pr_update_password(PDO $pdo, array $reset, string $password): void {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $accountType = (string)$reset['account_type'];
        $accountId = (int)$reset['account_id'];

        if ($accountType === 'user') {
            if (!pr_table_exists($pdo, 'users')) {
                throw new RuntimeException('Tabelul users nu exista.');
            }

            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
            $stmt->execute([$hash, $accountId]);
        } elseif ($accountType === 'team') {
            if (!pr_table_exists($pdo, 'team_members')) {
                throw new RuntimeException('Tabelul team_members nu exista.');
            }

            $column = pr_column_exists($pdo, 'team_members', 'password_hash') ? 'password_hash' : 'password';
            if (!pr_column_exists($pdo, 'team_members', $column)) {
                throw new RuntimeException('Nu exista coloana de parola pentru echipa teren.');
            }

            $stmt = $pdo->prepare("UPDATE team_members SET {$column} = ? WHERE id = ? LIMIT 1");
            $stmt->execute([$hash, $accountId]);
        } else {
            throw new RuntimeException('Tip cont invalid.');
        }

        $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$reset['id']]);
    }
}
