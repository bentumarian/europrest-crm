<?php
/**
 * PestZone CRM - cron_reminder_emails.php
 * Trimite email cu o zi inainte pentru remindere pending.
 *
 * Folosire:
 *  - CLI: php cron_reminder_emails.php
 *  - HTTP: GET /cron_reminder_emails.php?key=SECRET_KEY
 *    (cheia se seteaza in app_settings: reminder_cron_key)
 *
 * Cron cPanel sugerat: zilnic la 07:00
 *   curl -s 'https://domeniul-tau.ro/cron_reminder_emails.php?key=CHEIE'
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_lib.php';

pz_notify_init();

$isCli = (PHP_SAPI === 'cli');
$key = (string)($_GET['key'] ?? '');
$expected = (string)pz_setting_get('reminder_cron_key', '');

if (!$isCli && ($expected === '' || !hash_equals($expected, $key))) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$tomorrow = date('Y-m-d', strtotime('+1 day'));
$pdo = pz_db();

// Verificăm că tabela reminders există (poate nu a fost încă încărcată pagina reminders.php)
if (!pz_table_exists('reminders')) {
    $out = ['ok' => true, 'message' => 'Tabela reminders nu exista inca. Acceseaza reminders.php o data pentru bootstrap.', 'sent' => 0];
    if ($isCli) {
        echo date('Y-m-d H:i:s') . " " . json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

// Selectam remindere scadente maine, neasignate inca pentru email.
// Fallback: dacă responsible_user_id e NULL, folosim created_by (autorul reminderului).
$stmt = $pdo->prepare("
    SELECT r.*,
           COALESCE(ur.email, uc.email) AS responsible_email,
           COALESCE(ur.name,  uc.name)  AS responsible_name
    FROM reminders r
    LEFT JOIN users ur ON ur.id = r.responsible_user_id
    LEFT JOIN users uc ON uc.id = r.created_by
    WHERE r.status = 'pending'
      AND r.remind_date = ?
      AND r.email_notified_at IS NULL
      AND COALESCE(ur.email, uc.email) IS NOT NULL
      AND COALESCE(ur.email, uc.email) <> ''
    ORDER BY r.id ASC
    LIMIT 200
");
$stmt->execute([$tomorrow]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = [
    // categorii noi
    'vehicle_review'   => 'Revizie Auto',
    'itp'              => 'ITP',
    'insurance'        => 'Asigurare',
    'index_meter'      => 'Transmitere index',
    'other'            => 'Altul',
    // legacy (compatibilitate retro pentru remindere vechi)
    'general'          => 'Altul',
    'vehicle'          => 'Revizie Auto',
    'meeting'          => 'Altul',
    'internal_meeting' => 'Altul',
    'accounting'       => 'Altul',
    'supply'           => 'Altul',
];

$baseUrl = rtrim((string)pz_setting_get('app_base_url', ''), '/');
$brand = (string)pz_setting_get('sms_brand_name', 'PestZone');

$sentCount = 0;
$failedCount = 0;
$skippedCount = 0;
$results = [];

foreach ($rows as $rem) {
    $email = trim((string)$rem['responsible_email']);
    if ($email === '') {
        $skippedCount++;
        continue;
    }

    $catLabel = $categories[$rem['category']] ?? 'General';
    $dateRo = date('d.m.Y', strtotime($rem['remind_date']));
    $timeStr = $rem['remind_time'] ? substr($rem['remind_time'], 0, 5) : '';
    $title = (string)$rem['title'];
    $description = (string)($rem['description'] ?? '');

    $subject = 'Reminder maine: ' . $title;

    $linkHtml = '';
    if ($baseUrl !== '') {
        $linkHtml = '<p style="margin:18px 0 0;"><a href="' . htmlspecialchars($baseUrl . '/reminders.php?edit=' . (int)$rem['id'], ENT_QUOTES) . '" style="background:#2563EB;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;">Deschide reminder</a></p>';
    }

    $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#f8fafc;">'
        . '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;">'
        . '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;font-weight:700;margin-bottom:8px;">Reminder · ' . htmlspecialchars($catLabel, ENT_QUOTES) . '</div>'
        . '<h1 style="font-size:22px;color:#0f172a;margin:0 0 16px;font-weight:700;letter-spacing:-.01em;">' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
        . '<div style="background:#eff6ff;border-left:4px solid #2563eb;padding:12px 16px;border-radius:6px;margin-bottom:16px;">'
        . '<div style="font-size:13px;color:#1e3a8a;font-weight:600;margin-bottom:2px;">Scadent mâine</div>'
        . '<div style="font-size:18px;color:#1e3a8a;font-weight:700;">' . htmlspecialchars($dateRo, ENT_QUOTES);
    if ($timeStr) {
        $html .= ' &middot; ' . htmlspecialchars($timeStr, ENT_QUOTES);
    }
    $html .= '</div></div>';

    if ($description !== '') {
        $html .= '<div style="font-size:14px;color:#334155;line-height:1.6;white-space:pre-wrap;">' . nl2br(htmlspecialchars($description, ENT_QUOTES)) . '</div>';
    }

    $html .= $linkHtml
        . '<hr style="border:0;border-top:1px solid #e2e8f0;margin:20px 0 12px;">'
        . '<div style="font-size:11px;color:#94a3b8;">' . htmlspecialchars($brand, ENT_QUOTES) . ' · email automat. Nu răspunde la acest mesaj.</div>'
        . '</div></div>';

    $text = 'Reminder pentru maine, ' . $dateRo . ($timeStr ? ' la ' . $timeStr : '') . "\n\n"
        . $title . "\n"
        . ($description ? "\n" . $description . "\n" : '');

    $res = pz_sendgrid_send_email($email, $subject, $html, $text, [], 'reminder', (int)$rem['id']);

    if (!empty($res['ok'])) {
        $pdo->prepare("UPDATE reminders SET email_notified_at = NOW() WHERE id = ?")->execute([(int)$rem['id']]);
        $sentCount++;
        $results[] = ['id' => (int)$rem['id'], 'email' => $email, 'status' => 'sent'];
    } else {
        $failedCount++;
        $results[] = ['id' => (int)$rem['id'], 'email' => $email, 'status' => 'failed', 'error' => $res['error'] ?? null];
    }
}

$summary = [
    'ok' => true,
    'date' => date('Y-m-d H:i:s'),
    'tomorrow' => $tomorrow,
    'candidates' => count($rows),
    'sent' => $sentCount,
    'failed' => $failedCount,
    'skipped' => $skippedCount,
    'details' => $results,
];

if ($isCli) {
    echo date('Y-m-d H:i:s') . " Reminder email cron\n";
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}