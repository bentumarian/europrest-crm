<?php
require_once __DIR__ . '/lib/review_lib.php';

pz_review_init();

$isCli = (PHP_SAPI === 'cli');
$key = (string)($_GET['key'] ?? '');
$expected = pz_review_setting_get('review_cron_key', '');

if (!$isCli && ($expected === '' || !hash_equals($expected, $key))) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$result = pz_review_scan_and_send(100);

if ($isCli) {
    echo date('Y-m-d H:i:s') . " Review scan\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
