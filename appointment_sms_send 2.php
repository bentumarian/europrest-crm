<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_lib.php';

if (function_exists('require_login')) {
    require_login();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('csrf_require')) {
    csrf_require();
}

$appointmentId = (int)($_POST['appointment_id'] ?? $_GET['appointment_id'] ?? 0);

if ($appointmentId <= 0) {
    http_response_code(400);
    exit('Lipsește appointment_id.');
}

$result = pz_send_appointment_confirmation_sms($appointmentId);

if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$back = $_SERVER['HTTP_REFERER'] ?? 'calendar.php';

if (!empty($result['ok'])) {
    header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'sms_sent=1');
} else {
    header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'sms_error=' . urlencode($result['error'] ?? 'SMS eșuat'));
}
exit;
