<?php
/**
 * Cron: sincronizeaza local facturile Oblio recente.
 * Recomandare cPanel cron, la 5 minute:
 * /usr/local/bin/php /home/USER/app.pestzone.ro/cron_oblio_sync.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/billing_lib.php';

try {
    $s = oblio_settings($pdo);

    if (PHP_SAPI !== 'cli') {
        $key = trim((string)($_GET['key'] ?? ''));
        $expected = trim((string)($s['oblio.cron_key'] ?? ''));

        if ($expected !== '' && !hash_equals($expected, $key)) {
            http_response_code(403);
            exit('Forbidden');
        }

        if ($expected === '') {
            http_response_code(403);
            exit('Seteaza oblio.cron_key in app_settings sau ruleaza cronul prin CLI.');
        }
    }

    $days = max(1, (int)($s['oblio.sync_days_back'] ?? 30));
    $res = bill_sync_recent_invoices($pdo, $days);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
