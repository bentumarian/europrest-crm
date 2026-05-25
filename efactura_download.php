<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/lib/smartbill_lib.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    http_response_code(403);
    exit('Acces interzis.');
}

function ef_dl_fail(string $message, int $code = 404): void
{
    http_response_code($code);
    exit($message);
}

pz_smartbill_ensure_schema($pdo);

$scope = (string)($_GET['scope'] ?? '');
$format = (string)($_GET['format'] ?? '');
$id = max(0, (int)($_GET['id'] ?? 0));
if (!in_array($scope, ['sent', 'received'], true) || !in_array($format, ['xml', 'pdf'], true) || $id <= 0) {
    ef_dl_fail('Cerere invalida.', 400);
}

if ($scope === 'sent') {
    $stmt = $pdo->prepare("SELECT smartbill_series, smartbill_number, efactura_xml_path, efactura_pdf_path FROM smartbill_invoices WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $path = $format === 'xml' ? (string)($row['efactura_xml_path'] ?? '') : (string)($row['efactura_pdf_path'] ?? '');
    $nameBase = trim((string)(($row['smartbill_series'] ?? 'factura') . '-' . ($row['smartbill_number'] ?? $id)));
} else {
    $stmt = $pdo->prepare("SELECT supplier_name, document_series, document_number, xml_path, pdf_path FROM smartbill_supplier_invoices WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $path = $format === 'xml' ? (string)($row['xml_path'] ?? '') : (string)($row['pdf_path'] ?? '');
    $nameBase = trim((string)(($row['document_series'] ?? 'furnizor') . '-' . ($row['document_number'] ?? $id)));
}

if (!$row) {
    ef_dl_fail('Documentul nu există.');
}

$path = ltrim(trim($path), '/');
if ($path === '') {
    ef_dl_fail('Fisierul nu este sincronizat in CRM.');
}

$fullPath = realpath(__DIR__ . '/' . $path);
$basePath = realpath(__DIR__);
if (!$fullPath || !$basePath || strpos($fullPath, $basePath) !== 0 || !is_file($fullPath)) {
    ef_dl_fail('Fisierul nu există pe server.');
}

$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $nameBase) ?: ('efactura-' . $id);
$fileName = $safeName . '.' . $format;
$mime = $format === 'xml' ? 'application/xml' : 'application/pdf';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
exit;
