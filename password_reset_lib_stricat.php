<?php
/**
 * Password reset helpers - PestZone / EuroPrest CRM
 */

if (!function_exists('pr_h')) {
    function pr_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pr_table_exists')) {
    function pr_table_exists(PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('pr_column_exists')) {
    function pr_column_exists(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('pr_ensure_schema')) {
    function pr_ensure_schema(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_type VARCHAR(30) NOT NULL,
                account_id INT NOT NULL,
                email VARCHAR(190) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                INDEX idx_token_hash (token_hash),
                INDEX idx_email_created (email, created_at),
                INDEX idx_account (account_type, account_id),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
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
        if ($dir === '' || $dir === '.') $dir = '';
        return $scheme . '://' . $host . $dir;
    }
}

if (!function_exists('pr_find_account_by_email')) {
    function pr_find_account_by_email(PDO $pdo, string $email): ?array {
        $email = trim(mb_strtolower($email, 'UTF-8'));

        if (pr_table_exists($pdo, 'users')) {
            $hasActive = pr_column_exists($pdo, 'users', 'active');
            $sql = "SELECT id, name, email FROM users WHERE LOWER(email) = LOWER(?)";
            if ($hasActive) $sql .= " AND active = 1";
            $sql .= " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return ['account_type' => 'user', 'account_id' => (int)$row['id'], 'email' => (string)$row['email'], 'name' => (string)($row['name'] ?? 'Utilizator')];
            }
        }

        if (pr_table_exists($pdo, 'team_members') && pr_column_exists($pdo, 'team_members', 'email')) {
            $hasActive = pr_column_exists($pdo, 'team_members', 'active');
            $sql = "SELECT id, name, email FROM team_members WHERE LOWER(email) = LOWER(?)";
            if ($hasActive) $sql .= " AND active = 1";
            $sql .= " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return ['account_type' => 'team', 'account_id' => (int)$row['id'], 'email' => (string)$row['email'], 'name' => (string)($row['name'] ?? 'Echipa teren')];
            }
        }

        return null;
    }
}

if (!function_exists('pr_send_reset_email')) {
function pr_send_reset_email(string $to, string $name, string $link): bool {
    $subject = 'Resetare parola cont CRM';

    $safeName = htmlspecialchars($name !== '' ? $name : 'Utilizator', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.55;color:#10243e;">'
        . '<p>Buna ziua, ' . $safeName . ',</p>'
        . '<p>A fost solicitata resetarea parolei pentru contul tau din platforma CRM.</p>'
        . '<p><a href="' . $safeLink . '" style="display:inline-block;background:#1160B7;color:#ffffff;text-decoration:none;padding:11px 16px;border-radius:10px;font-weight:700;">Seteaza parola noua</a></p>'
        . '<p>Daca butonul nu functioneaza, copiaza acest link in browser:</p>'
        . '<p style="word-break:break-all;color:#526B82;">' . $safeLink . '</p>'
        . '<p>Linkul este valabil 60 de minute. Daca nu ai solicitat resetarea parolei, ignora acest email.</p>'
        . '<p>Mesaj automat.</p>'
        . '</div>';

    $text = "Buna ziua, " . ($name !== '' ? $name : 'Utilizator') . ",\n\n"
        . "A fost solicitata resetarea parolei pentru contul tau din platforma CRM.\n\n"
        . "Pentru a seta o parola noua, acceseaza linkul de mai jos:\n"
        . $link . "\n\n"
        . "Linkul este valabil 60 de minute.\n"
        . "Daca nu ai solicitat resetarea parolei, ignora acest email.\n\n"
        . "Mesaj automat.";

    $notificationFile = __DIR__ . '/notification_lib.php';
    if (file_exists($notificationFile)) {
        require_once $notificationFile;
    }

    if (function_exists('pz_sendgrid_send_email')) {
        try {
            $result = pz_sendgrid_send_email($to, $subject, $html, $text, [], 'password_reset', null);
            return !empty($result['ok']);
        } catch (Throwable $e) {
            error_log('Password reset SendGrid error: ' . $e->getMessage());
            return false;
        }
    }

    return false;
}        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $from = 'noreply@' . preg_replace('/^www\./', '', $host);
        $subject = 'Resetare parola cont CRM';
        $body = "Buna ziua,\n\n";
        $body .= "A fost solicitata resetarea parolei pentru contul tau din platforma CRM.\n\n";
        $body .= "Pentru a seta o parola noua, acceseaza linkul de mai jos:\n" . $link . "\n\n";
        $body .= "Linkul este valabil 60 de minute.\n";
        $body .= "Daca nu ai solicitat resetarea parolei, ignora acest email.\n\n";
        $body .= "Mesaj automat.";
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: CRM <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: PHP/' . phpversion(),
        ];
        return @mail($to, $subject, $body, implode("\r\n", $headers));
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

        $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE account_type = ? AND account_id = ? AND used_at IS NULL");
        $stmt->execute([$account['account_type'], $account['account_id']]);

        $stmt = $pdo->prepare("INSERT INTO password_resets (account_type, account_id, email, token_hash, expires_at, created_ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$account['account_type'], $account['account_id'], $account['email'], $tokenHash, $expiresAt, $ip, $ua]);

        $link = pr_base_url() . '/reset_password.php?token=' . urlencode($rawToken);
        return ['token' => $rawToken, 'link' => $link, 'expires_at' => $expiresAt];
    }
}

if (!function_exists('pr_get_valid_reset')) {
    function pr_get_valid_reset(PDO $pdo, string $rawToken): ?array {
        pr_ensure_schema($pdo);
        $rawToken = trim($rawToken);
        if ($rawToken === '' || strlen($rawToken) < 40) return null;
        $tokenHash = hash('sha256', $rawToken);
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at >= NOW() LIMIT 1");
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
            if (!pr_table_exists($pdo, 'users')) throw new RuntimeException('Tabelul users nu exista.');
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
            $stmt->execute([$hash, $accountId]);
        } elseif ($accountType === 'team') {
            if (!pr_table_exists($pdo, 'team_members')) throw new RuntimeException('Tabelul team_members nu exista.');
            $column = pr_column_exists($pdo, 'team_members', 'password_hash') ? 'password_hash' : 'password';
            if (!pr_column_exists($pdo, 'team_members', $column)) throw new RuntimeException('Nu exista coloana de parola pentru echipa teren.');
            $stmt = $pdo->prepare("UPDATE team_members SET {$column} = ? WHERE id = ? LIMIT 1");
            $stmt->execute([$hash, $accountId]);
        } else {
            throw new RuntimeException('Tip cont invalid.');
        }

        $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$reset['id']]);
    }
}
