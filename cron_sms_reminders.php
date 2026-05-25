<?php
/**
 * Cron pentru remindere SMS.
 *
 * Recomandare cPanel:
 * php /home/USER/public_html/cron_sms_reminders.php SECRET
 *
 * Setează secretul în app_settings:
 * key: sms_cron_secret
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/notification_lib.php';

pz_notify_init();

$secret = pz_setting_get('sms_cron_secret', '');
$provided = $argv[1] ?? ($_GET['key'] ?? '');

if ($secret === '') {
    pz_setting_set('sms_cron_secret', bin2hex(random_bytes(16)));
    exit("Secret cron generat. Intră în Comunicare / Integrări și verifică app_settings.sms_cron_secret.\n");
}

if (!hash_equals($secret, (string)$provided)) {
    http_response_code(403);
    exit("Acces interzis.\n");
}

$pdo = pz_db();

// Adaugă coloane dacă lipsesc, fără să stricăm aplicația.
try {
    $pdo->exec("ALTER TABLE appointments ADD COLUMN sms_reminder_sent_at DATETIME NULL");
} catch (Throwable $e) {}

try {
    $stmt = $pdo->query("
        SELECT id
        FROM appointments
        WHERE DATE(appointment_date) = DATE(DATE_ADD(NOW(), INTERVAL 1 DAY))
          AND (sms_reminder_sent_at IS NULL)
          AND (status IS NULL OR status NOT IN ('anulat','cancelled','finalizat','executat'))
        LIMIT 100
    ");
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    exit("Nu pot citi programările: " . $e->getMessage() . "\n");
}

$sent = 0;
$failed = 0;

foreach ($ids as $id) {
    $res = pz_send_appointment_confirmation_sms((int)$id);
    if ($res['ok']) {
        $sent++;
        $upd = $pdo->prepare("UPDATE appointments SET sms_reminder_sent_at = NOW() WHERE id = ?");
        $upd->execute([(int)$id]);
    } else {
        $failed++;
    }
}

echo "Remindere procesate. Trimise: {$sent}. Eșuate: {$failed}.\n";
