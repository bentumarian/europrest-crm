<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/smartbill_lib.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    http_response_code(403);
    exit('Acces interzis.');
}

function ef_archive_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    exit($message);
}

function ef_archive_date(?string $date, string $fallback): string
{
    $d = DateTime::createFromFormat('Y-m-d', (string)$date);
    return ($d && $d->format('Y-m-d') === (string)$date) ? (string)$date : $fallback;
}

function ef_archive_file_path(?string $path): ?string
{
    $path = ltrim(trim((string)$path), '/');
    if ($path === '') {
        return null;
    }

    $fullPath = realpath(__DIR__ . '/' . $path);
    $basePath = realpath(__DIR__);
    if (!$fullPath || !$basePath || strpos($fullPath, $basePath) !== 0 || !is_file($fullPath)) {
        return null;
    }

    return $fullPath;
}

function ef_archive_safe_name(string $name): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($name));
    return trim((string)$safe, '-_.') ?: 'document';
}

pz_smartbill_ensure_schema($pdo);

if (!class_exists('ZipArchive')) {
    ef_archive_fail('Extensia ZIP nu este disponibila pe server.', 500);
}

$scope = (string)($_GET['scope'] ?? '');
$format = (string)($_GET['format'] ?? 'all');
if (!in_array($scope, ['sent', 'received'], true) || !in_array($format, ['all', 'xml', 'pdf'], true)) {
    ef_archive_fail('Cerere invalida.');
}

$rows = [];
if ($scope === 'sent') {
    $sentQ = trim((string)($_GET['sent_q'] ?? ''));
    $sentStatus = trim((string)($_GET['sent_status'] ?? 'all'));
    $sentFrom = ef_archive_date($_GET['sent_from'] ?? null, date('Y-m-01'));
    $sentTo = ef_archive_date($_GET['sent_to'] ?? null, date('Y-m-t'));

    $where = [
        "source_type <> 'receipt'",
        "smartbill_number IS NOT NULL",
        "smartbill_number <> ''",
        "invoice_date BETWEEN ? AND ?",
    ];
    $params = [$sentFrom, $sentTo];
    if ($sentQ !== '') {
        $where[] = "(client_name LIKE ? OR client_fiscal_code LIKE ? OR smartbill_series LIKE ? OR smartbill_number LIKE ?)";
        $like = '%' . $sentQ . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($sentStatus !== 'all') {
        if ($sentStatus === 'neverificat') {
            $where[] = "(efactura_status IS NULL OR efactura_status = '')";
        } else {
            $where[] = "efactura_status = ?";
            $params[] = $sentStatus;
        }
    }

    $stmt = $pdo->prepare("
        SELECT id, smartbill_series, smartbill_number, invoice_date, efactura_xml_path, efactura_pdf_path
        FROM smartbill_invoices
        WHERE " . implode(' AND ', $where) . "
        ORDER BY invoice_date DESC, id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $receivedQ = trim((string)($_GET['received_q'] ?? ''));
    $receivedStatus = trim((string)($_GET['received_status'] ?? 'all'));
    $receivedFrom = ef_archive_date($_GET['received_from'] ?? null, date('Y-m-01'));
    $receivedTo = ef_archive_date($_GET['received_to'] ?? null, date('Y-m-t'));

    $where = ["issue_date BETWEEN ? AND ?"];
    $params = [$receivedFrom, $receivedTo];
    if ($receivedQ !== '') {
        $where[] = "(supplier_name LIKE ? OR supplier_fiscal_code LIKE ? OR document_series LIKE ? OR document_number LIKE ?)";
        $like = '%' . $receivedQ . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($receivedStatus !== 'all') {
        $where[] = "efactura_status = ?";
        $params[] = $receivedStatus;
    }

    $stmt = $pdo->prepare("
        SELECT id, document_series, document_number, issue_date, xml_path, pdf_path
        FROM smartbill_supplier_invoices
        WHERE " . implode(' AND ', $where) . "
        ORDER BY issue_date DESC, id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$tmpFile = tempnam(sys_get_temp_dir(), 'efactura_zip_');
if (!$tmpFile) {
    ef_archive_fail('Nu am putut crea arhiva temporara.', 500);
}

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmpFile);
    ef_archive_fail('Nu am putut deschide arhiva temporara.', 500);
}

$added = 0;
foreach ($rows as $row) {
    if ($scope === 'sent') {
        $base = ef_archive_safe_name(trim((string)(($row['smartbill_series'] ?? 'factura') . '-' . ($row['smartbill_number'] ?? $row['id']))));
        $xmlPath = ef_archive_file_path($row['efactura_xml_path'] ?? '');
        $pdfPath = ef_archive_file_path($row['efactura_pdf_path'] ?? '');
    } else {
        $base = ef_archive_safe_name(trim((string)(($row['document_series'] ?? 'furnizor') . '-' . ($row['document_number'] ?? $row['id']))));
        $xmlPath = ef_archive_file_path($row['xml_path'] ?? '');
        $pdfPath = ef_archive_file_path($row['pdf_path'] ?? '');
    }

    if (($format === 'all' || $format === 'xml') && $xmlPath) {
        $zip->addFile($xmlPath, $base . '.xml');
        $added++;
    }
    if (($format === 'all' || $format === 'pdf') && $pdfPath) {
        $zip->addFile($pdfPath, $base . '.pdf');
        $added++;
    }
}

$zip->close();

if ($added <= 0) {
    @unlink($tmpFile);
    ef_archive_fail('Nu exista fisiere sincronizate pentru filtrele selectate.', 404);
}

$fileName = 'efactura-' . $scope . '-' . date('Ymd-His') . '.zip';
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($tmpFile));
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('X-Content-Type-Options: nosniff');
readfile($tmpFile);
@unlink($tmpFile);
exit;
