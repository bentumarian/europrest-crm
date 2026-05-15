<?php
/**
 * PestZone - link public securizat pentru documente.
 * Linkul permite clientului sa vada exact pagina A4 printabila, fara login si fara mPDF.
 */

if (!function_exists('pzdoc_public_require_schema')) {
    function pzdoc_public_require_schema(PDO $pdo): void
    {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS document_public_links (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                document_id INT NOT NULL,\n                token_hash CHAR(64) NOT NULL,\n                created_by INT NULL,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                expires_at DATETIME NULL,\n                access_count INT NOT NULL DEFAULT 0,\n                last_accessed_at DATETIME NULL,\n                UNIQUE KEY uq_document_public_token_hash (token_hash),\n                INDEX idx_document_public_document (document_id),\n                INDEX idx_document_public_expires (expires_at)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");
    }
}

if (!function_exists('pzdoc_public_base_url')) {
    function pzdoc_public_base_url(): string
    {
        if (defined('APP_URL') && APP_URL) {
            return rtrim((string)APP_URL, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        return rtrim($scheme . '://' . $host . ($dir === '' || $dir === '/' ? '' : $dir), '/');
    }
}

if (!function_exists('pzdoc_public_token_hash')) {
    function pzdoc_public_token_hash(string $token): string
    {
        return hash('sha256', trim($token));
    }
}

if (!function_exists('pzdoc_public_create_link')) {
    function pzdoc_public_create_link(PDO $pdo, int $documentId, int $validDays = 90): array
    {
        pzdoc_public_require_schema($pdo);
        if ($documentId <= 0) {
            throw new RuntimeException('Document invalid pentru link public.');
        }
        $token = bin2hex(random_bytes(24));
        $hash = pzdoc_public_token_hash($token);
        $expiresAt = date('Y-m-d H:i:s', time() + max(1, $validDays) * 86400);
        $createdBy = function_exists('current_user_id') ? current_user_id() : null;
        $stmt = $pdo->prepare("\n            INSERT INTO document_public_links\n                (document_id, token_hash, created_by, expires_at)\n            VALUES\n                (?, ?, ?, ?)\n        ");
        $stmt->execute([$documentId, $hash, $createdBy, $expiresAt]);
        return [
            'token' => $token,
            'url' => pzdoc_public_base_url() . '/document_print.php?t=' . rawurlencode($token),
            'expires_at' => $expiresAt,
        ];
    }
}

if (!function_exists('pzdoc_public_load_document')) {
    function pzdoc_public_load_document(PDO $pdo, string $token): ?array
    {
        pzdoc_public_require_schema($pdo);
        $token = trim($token);
        if ($token === '' || strlen($token) < 20) {
            return null;
        }
        $hash = pzdoc_public_token_hash($token);
        $stmt = $pdo->prepare("\n            SELECT *\n            FROM document_public_links\n            WHERE token_hash = ?\n              AND (expires_at IS NULL OR expires_at >= NOW())\n            LIMIT 1\n        ");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $documentId = (int)($row['document_id'] ?? 0);
        if ($documentId <= 0 || !function_exists('pzdoc_get_document')) {
            return null;
        }
        $document = pzdoc_get_document($pdo, $documentId, true);
        if (!$document) {
            return null;
        }
        try {
            $upd = $pdo->prepare("\n                UPDATE document_public_links\n                SET access_count = access_count + 1, last_accessed_at = NOW()\n                WHERE id = ?\n            ");
            $upd->execute([(int)$row['id']]);
        } catch (Throwable $e) {
            error_log('PestZone public document access log warning: ' . $e->getMessage());
        }
        return $document;
    }
}
