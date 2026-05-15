<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_login();

if (!is_admin()) {
    http_response_code(403);
    exit('Acces permis doar administratorului.');
}

function bpdf_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function bpdf_safe_filename(string $type, string $series, string $number): string
{
    $name = strtolower($type . '_' . $series . '_' . $number);
    $name = preg_replace('/[^a-z0-9_\-]+/i', '_', $name);
    $name = trim($name, '_');

    return ($name !== '' ? $name : uniqid('oblio_doc_', true)) . '.pdf';
}

function bpdf_storage_dir(): string
{
    $dir = __DIR__ . '/storage/oblio_pdfs';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function bpdf_output_pdf(string $path, string $filename): void
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

function bpdf_download_fast(string $url, string $absolutePath): array
{
    if ($url === '' || !preg_match('~^https://~i', $url)) {
        return ['ok' => false, 'error' => 'Link Oblio invalid.', 'http' => 0, 'type' => '', 'bytes' => 0, 'preview' => ''];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL nu este activ pe server.', 'http' => 0, 'type' => '', 'bytes' => 0, 'preview' => ''];
    }

    $urls = [$url];

    if (strpos($url, 'preload=1') !== false) {
        $urls[] = str_replace(['&preload=1', '?preload=1'], ['', ''], $url);
    }

    if (strpos($url, 'api=1') === false) {
        $urls[] = $url . (strpos($url, '?') !== false ? '&api=1' : '?api=1');
    }

    $last = null;

    foreach ($urls as $tryUrl) {
        $ch = curl_init($tryUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'PestZone CRM PDF Downloader',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/pdf,application/octet-stream,*/*'],
        ]);

        $data = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $bytes = strlen((string)$data);
        $preview = substr((string)$data, 0, 160);

        $last = [
            'ok' => false,
            'error' => $err ?: 'Nu am primit PDF valid de la Oblio.',
            'http' => $http,
            'type' => $type,
            'bytes' => $bytes,
            'preview' => $preview,
            'url' => $tryUrl,
        ];

        if ($data === false || $data === '' || $http < 200 || $http >= 300) {
            continue;
        }

        $isPdf = substr((string)$data, 0, 4) === '%PDF' || stripos($type, 'pdf') !== false;

        if (!$isPdf) {
            continue;
        }

        if (file_put_contents($absolutePath, $data) === false) {
            return [
                'ok' => false,
                'error' => 'Nu pot salva PDF-ul local.',
                'http' => $http,
                'type' => $type,
                'bytes' => $bytes,
                'preview' => $preview,
                'url' => $tryUrl,
            ];
        }

        return [
            'ok' => true,
            'error' => '',
            'http' => $http,
            'type' => $type,
            'bytes' => $bytes,
            'preview' => '',
            'url' => $tryUrl,
        ];
    }

    return $last ?: ['ok' => false, 'error' => 'Descarcare esuata.', 'http' => 0, 'type' => '', 'bytes' => 0, 'preview' => '', 'url' => $url];
}

try {
    $id = (int)($_GET['id'] ?? 0);
    $sync = isset($_GET['sync']) && (string)$_GET['sync'] === '1';

    if ($id <= 0) {
        throw new RuntimeException('ID document lipsa.');
    }

    $stmt = $pdo->prepare("SELECT * FROM billing_oblio_documents WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        throw new RuntimeException('Documentul nu exista in billing_oblio_documents.');
    }

    $type = (string)($doc['oblio_type'] ?? 'document');
    $series = (string)($doc['oblio_series'] ?? 'doc');
    $number = (string)($doc['oblio_number'] ?? $id);
    $link = trim((string)($doc['link'] ?? ''));
    $pdfPath = trim((string)($doc['pdf_path'] ?? ''));

    if ($link === '' && !empty($doc['raw_json'])) {
        $raw = json_decode((string)$doc['raw_json'], true);
        if (is_array($raw) && !empty($raw['link'])) {
            $link = trim((string)$raw['link']);
        }
    }

    $filename = bpdf_safe_filename($type, $series, $number);

    if ($pdfPath !== '') {
        $clean = ltrim(str_replace('\\', '/', $pdfPath), '/');
        if (strpos($clean, '..') === false) {
            $absoluteExisting = __DIR__ . '/' . $clean;
            if (is_file($absoluteExisting) && filesize($absoluteExisting) > 100) {
                bpdf_output_pdf($absoluteExisting, $filename);
            }
        }
    }

    if (!$sync) {
        ?>
        <!doctype html>
        <html lang="ro">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>PDF document Oblio</title>
            <style>
                body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:24px;color:#111827}
                .card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:20px;box-shadow:0 12px 30px rgba(15,23,42,.08)}
                h1{margin:0 0 8px;font-size:22px}
                p{color:#4b5563;line-height:1.5}
                .row{padding:10px 0;border-bottom:1px solid #eef2f7}
                .btn{display:inline-flex;margin:6px 6px 0 0;padding:10px 13px;border-radius:10px;border:1px solid #d1d5db;text-decoration:none;color:#111827;background:#fff;font-weight:700}
                .btn.primary{background:#0f766e;color:#fff;border-color:#0f766e}
                code{background:#f8fafc;border:1px solid #e5e7eb;padding:2px 5px;border-radius:6px}
            </style>
        </head>
        <body>
            <div class="card">
                <h1>PDF document Oblio</h1>
                <p>Documentul este emis în Oblio. PDF-ul nu este încă salvat local în CRM sau linkul Oblio răspunde greu.</p>

                <div class="row"><strong>Document:</strong> <?= bpdf_h(strtoupper($type)) ?> <?= bpdf_h($series) ?> <?= bpdf_h($number) ?></div>
                <div class="row"><strong>ID CRM:</strong> <?= (int)$id ?></div>
                <div class="row"><strong>PDF local:</strong> nu există încă</div>

                <p>
                    <a class="btn primary" href="billing_document_pdf.php?id=<?= (int)$id ?>&sync=1">Sincronizează PDF în CRM</a>
                    <?php if ($link !== ''): ?>
                        <a class="btn" target="_blank" href="<?= bpdf_h($link) ?>">Deschide link Oblio</a>
                    <?php endif; ?>
                    <a class="btn" href="billing_documents.php">Înapoi la documente</a>
                </p>

                <p><small>Dacă sincronizarea eșuează, pagina va afișa codul HTTP, tipul răspunsului și linkul încercat.</small></p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    if ($link === '') {
        throw new RuntimeException('Documentul nu are link Oblio salvat.');
    }

    $relativePath = 'storage/oblio_pdfs/' . $filename;
    $absolutePath = __DIR__ . '/' . $relativePath;

    $result = bpdf_download_fast($link, $absolutePath);

    if (empty($result['ok'])) {
        http_response_code(500);
        echo '<h2>Eroare descărcare PDF din Oblio</h2>';
        echo '<p><strong>' . bpdf_h($result['error']) . '</strong></p>';
        echo '<pre>';
        echo 'Document ID CRM: ' . bpdf_h($id) . "\n";
        echo 'Tip: ' . bpdf_h($type) . "\n";
        echo 'Serie: ' . bpdf_h($series) . "\n";
        echo 'Numar: ' . bpdf_h($number) . "\n";
        echo 'HTTP: ' . bpdf_h($result['http']) . "\n";
        echo 'Content-Type: ' . bpdf_h($result['type']) . "\n";
        echo 'Bytes: ' . bpdf_h($result['bytes']) . "\n";
        echo 'URL incercat: ' . bpdf_h($result['url'] ?? $link) . "\n";
        echo 'Preview raspuns: ' . bpdf_h($result['preview']) . "\n";
        echo '</pre>';
        echo '<p><a target="_blank" href="' . bpdf_h($link) . '">Deschide linkul Oblio direct</a></p>';
        echo '<p><a href="billing_document_pdf.php?id=' . (int)$id . '">Înapoi</a></p>';
        exit;
    }

    $stmt = $pdo->prepare("UPDATE billing_oblio_documents SET pdf_path = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$relativePath, $id]);

    bpdf_output_pdf($absolutePath, $filename);

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Eroare PDF Oblio: ' . bpdf_h($e->getMessage());
    exit;
}