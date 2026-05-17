<?php
/**
 * Cron pentru SMS scadență sarcina la 7 zile.
 *
 * Rulare cPanel:
 *   php /home/USER/public_html/cron_task_expiry_7_sms.php SECRET
 *
 * Secretul se gaseste in app_settings.sms_cron_secret.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sms_addon_lib.php';
require_once __DIR__ . '/notification_lib.php';

pz_sms_init();

$secret = pz_sms_get('sms_cron_secret', '');
$provided = $argv[1] ?? ($_GET['key'] ?? '');

if ($secret === '') {
    $secret = bin2hex(random_bytes(16));
    pz_sms_set('sms_cron_secret', $secret);
    exit("Secret cron generat: {$secret}\n");
}

if (!hash_equals((string)$secret, (string)$provided)) {
    http_response_code(403);
    exit("Acces interzis.\n");
}

$pdo = pz_sms_db();

try {
    // Selectam doar sarcinile cu client activ SI sms_enabled=1.
    // Filtrul e aici la nivel de SQL pentru eficienta, dar pz_smslink_send_sms
    // verifica DIN NOU la momentul trimiterii (defense in depth).
    $sql = "SELECT t.id
            FROM tasks t
            INNER JOIN clients c ON c.id = t.client_id
            WHERE DATE(t.due_date) = DATE(DATE_ADD(CURDATE(), INTERVAL 7 DAY))
              AND (t.sms_7_days_sent_at IS NULL)
              AND (t.status IS NULL OR t.status NOT IN ('finalizat','executat','anulat','cancelled'))
              AND c.sms_enabled = 1
              AND c.active = 1
              AND c.phone IS NOT NULL
              AND c.phone <> ''
            LIMIT 200";

    $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0; $failed = 0; $skipped = 0;

    foreach ($ids as $id) {
        // Folosim functia centralizata din notification_lib.php
        // (NU mai e definita in sms_addon_lib - era duplicata)
        $res = pz_send_task_expiring_7_sms((int)$id);
        if (!empty($res['ok']))         $sent++;
        elseif (!empty($res['skipped'])) $skipped++;
        else                             $failed++;
    }

    echo "Cron SMS sarcini 7 zile finalizat. Trimise: {$sent}. Sarite: {$skipped}. Esuate: {$failed}.\n";

} catch (Throwable $e) {
    echo 'Eroare cron SMS: ' . $e->getMessage() . "\n";
    exit(1);
}
