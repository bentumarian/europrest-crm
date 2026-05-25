<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/smartbill_lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$date = $argv[1] ?? date('Y-m-d');
$results = pz_smartbill_generate_due_recurring_invoices($pdo, $date, 50);

foreach ($results as $result) {
    $line = 'recurenta #' . (int)($result['recurring_id'] ?? 0) . ': ';
    $line .= !empty($result['ok'])
        ? 'factura #' . (int)($result['invoice_id'] ?? 0) . ' generata'
        : 'eroare - ' . (string)($result['error'] ?? 'necunoscuta');
    echo $line . PHP_EOL;
}
