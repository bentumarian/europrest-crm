<?php
/*
|--------------------------------------------------------------------------
| Cron — sincronizare e-Factura ANAF
|--------------------------------------------------------------------------
| Apelat zilnic (sau de 2 ori/zi) pentru:
|  1. Refresh access_token dacă mai are < 5 zile valabilitate
|  2. Listează ultimele N zile de facturi PRIMITE din ANAF
|  3. Pentru fiecare mesaj nou, descarcă XML/ZIP, parsează, salvează
|  4. Verifică starea facturilor TRIMISE care nu sunt încă „validata"/"eroare"
|
| Configurare în cPanel:
|   - Path: /usr/bin/php /home/USER/public_html/cron_efactura_sync.php
|   - Frecvență: 0 6,14 * * *  (de 2 ori pe zi: 06:00 și 14:00)
|
| Sau apelabil manual din UI prin efactura.php (POST action=sync_now).
|--------------------------------------------------------------------------
*/

// Permite apel atât din CLI cât și din browser (cu auth)
$fromCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/config.php';

if (!$fromCli) {
    require_login();
    if (function_exists('is_admin') && !is_admin()) {
        http_response_code(403);
        exit('Acces interzis.');
    }
}

require_once __DIR__ . '/anaf_efactura_lib.php';

if (function_exists('pz_smartbill_ensure_schema')) {
    pz_smartbill_ensure_schema($pdo);
}
anaf_efactura_ensure_schema($pdo);

$settings = anaf_efactura_settings($pdo);
if (($settings['anaf_efactura.enabled'] ?? '0') !== '1') {
    $msg = 'Integrarea ANAF e-Factura este dezactivata din setari.';
    if ($fromCli) {
        echo $msg . PHP_EOL;
        exit(0);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$days = max(1, min(60, (int)($settings['anaf_efactura.sync_days'] ?? 30)));

$results = [
    'received' => null,
    'sent_status' => null,
];

// Pasul 1+2: facturi primite
$results['received'] = anaf_efactura_sync_received($pdo, $days);

// Pasul 3: status facturi trimise
$results['sent_status'] = anaf_efactura_sync_sent_status($pdo, 50);

if ($fromCli) {
    echo '[' . date('Y-m-d H:i:s') . '] e-Factura sync rezultat:' . PHP_EOL;
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results, JSON_UNESCAPED_UNICODE);
exit;
