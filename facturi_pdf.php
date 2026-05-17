<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/smartbill_lib.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    http_response_code(403);
    exit('Acces interzis.');
}

$invoiceId = max(0, (int)($_GET['id'] ?? 0));
if ($invoiceId <= 0) {
    http_response_code(400);
    exit('Factura lipsa.');
}

$result = pz_smartbill_invoice_pdf($pdo, $invoiceId);
if (empty($result['ok'])) {
    http_response_code(400);
    exit((string)($result['error'] ?? 'PDF-ul nu a putut fi descarcat.'));
}

$invoice = $result['invoice'] ?? [];
$series = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($invoice['smartbill_series'] ?? 'Factura'));
$number = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($invoice['smartbill_number'] ?? $invoiceId));
$filename = trim($series . '_' . $number, '_') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen((string)$result['body']));
echo (string)$result['body'];
