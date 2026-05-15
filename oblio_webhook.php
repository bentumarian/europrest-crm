<?php
/**
 * Endpoint webhook Oblio.
 * Oblio cere raspuns 200 cu valoarea base64 a headerului X-Oblio-Request-Id.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/billing_lib.php';

bill_ensure_schema($pdo);

$requestId = $_SERVER['HTTP_X_OBLIO_REQUEST_ID'] ?? '';
$payload = file_get_contents('php://input') ?: '';
$data = json_decode($payload, true);
$topic = '';

if (is_array($data)) {
    $topic = (string)($data['topic'] ?? $data['event'] ?? $data['type'] ?? '');
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO billing_oblio_webhook_events (request_id, topic, payload, processed)
        VALUES (?, ?, ?, 0)
    ");
    $stmt->execute([$requestId, $topic, $payload]);
} catch (Throwable $e) {}

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo base64_encode($requestId);
