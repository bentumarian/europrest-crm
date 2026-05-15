<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| PestZone CRM - cron_oblio_pdf_sync.php
|--------------------------------------------------------------------------
| Descarca local PDF-urile documentelor Oblio care au link, dar nu au pdf_path.
| Rulare recomandata din cPanel Cron Jobs, la 5 minute.
|--------------------------------------------------------------------------
*/

if (php_sapi_name() !== 'cli') {
    require_login();

    if (!is_admin()) {
        http_response_code(403);
        exit('Acces permis doar administratorului.');
    }
}

function cps_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}



function cps_storage_dir(): string
{
    $dir = __DIR__ . '/storage/oblio_pdfs';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function cps_safe_filename(string $type, string $series, string $number): string
{
    $name = strtolower($type . '_' . $series . '_' . $number);
    $name = preg_replace('/[^a-z0-9_\-]+/i', '_', $name);
    $name = trim($name, '_');

    return ($name !== '' ? $name : uniqid('oblio_doc_', true)) . '.pdf';
}

function cps_download_pdf(string $url, string $absolutePath): array
{
    if ($url === '' || !preg_match('~^https://~i', $url)) {
        return ['ok' => false, 'error' => 'Link invalid'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL inactiv'];
    }

    $urls = [$url];

    if (strpos($url, 'preload=1') !== false) {
        $urls[] = str_replace(['&preload=1', '?preload=1'], ['', ''], $url);
    }

    foreach ($urls as $tryUrl) {
        $ch = curl_init($tryUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'PestZone CRM Cron PDF Sync',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/pdf,application/octet-stream,*/*',
            ],
        ]);

        $data = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($data === false || $data === '' || $http < 200 || $http >= 300) {
            continue;
        }

        $isPdf = substr((string)$data, 0, 4) === '%PDF' || stripos($type, 'pdf') !== false;

        if (!$isPdf) {
            continue;
        }

        if (file_put_contents($absolutePath, $data) === false) {
            return ['ok' => false, 'error' => 'Nu pot salva PDF local'];
        }

        return ['ok' => true, 'error' => ''];
    }

    return ['ok' => false, 'error' => $err ?: 'PDF nedescarcat'];
}

function cps_log(PDO $pdo, string $status, string $message, array $stats = []): void
{
    if (!cps_table_exists($pdo, 'billing_sync_log')) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO billing_sync_log (sync_type, status, message, stats_json, created_at)
            VALUES ('pdf_sync', ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $status,
            $message,
            json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        // Nu blocam cronul daca logarea esueaza.
    }
}

try {
    if (!cps_table_exists($pdo, 'billing_oblio_documents')) {
        throw new RuntimeException('Tabelul billing_oblio_documents nu exista.');
    }

    $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 10;

    $stmt = $pdo->prepare("
        SELECT *
        FROM billing_oblio_documents
        WHERE canceled = 0
          AND link IS NOT NULL
          AND link <> ''
          AND (pdf_path IS NULL OR pdf_path = '')
        ORDER BY id ASC
        LIMIT $limit
    ");
    $stmt->execute();

    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'checked' => count($docs),
        'downloaded' => 0,
        'failed' => 0,
        'errors' => [],
    ];

    foreach ($docs as $doc) {
        $type = (string)($doc['oblio_type'] ?? 'document');
        $series = (string)($doc['oblio_series'] ?? 'doc');
        $number = (string)($doc['oblio_number'] ?? $doc['id']);
        $link = trim((string)($doc['link'] ?? ''));

        $filename = cps_safe_filename($type, $series, $number);
        $relativePath = 'storage/oblio_pdfs/' . $filename;
        $absolutePath = cps_storage_dir() . '/' . $filename;

        $result = cps_download_pdf($link, $absolutePath);

        if (!empty($result['ok'])) {
            $update = $pdo->prepare("
                UPDATE billing_oblio_documents
                SET pdf_path = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $update->execute([$relativePath, (int)$doc['id']]);

            $stats['downloaded']++;
        } else {
            $stats['failed']++;
            $stats['errors'][] = [
                'id' => (int)$doc['id'],
                'doc' => $series . ' ' . $number,
                'error' => $result['error'] ?? 'eroare',
            ];
        }
    }

    cps_log($pdo, $stats['failed'] > 0 ? 'failed' : 'success', 'PDF sync executat', $stats);

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'stats' => $stats], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    cps_log($pdo, 'failed', $e->getMessage(), []);

    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}