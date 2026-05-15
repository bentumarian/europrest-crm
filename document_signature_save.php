<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/document_core.php';
require_once __DIR__ . '/document_access.php';

header('Content-Type: application/json; charset=utf-8');

function pzsig_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pzsig_safe_relative_path($path): string
{
    $path = str_replace('\\', '/', trim((string)($path ?? '')));
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false || preg_match('#(^|/)[.](/|$)#', $path)) {
        return '';
    }
    return $path;
}

function pzsig_document_payload(array $document): array
{
    if (is_array($document['payload'] ?? null)) {
        return $document['payload'];
    }
    if (function_exists('pzdoc_json_decode')) {
        return pzdoc_json_decode($document['payload_json'] ?? null);
    }
    $decoded = json_decode((string)($document['payload_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function pzsig_document_appointment_id(array $document): int
{
    $appointmentId = (int)($document['appointment_id'] ?? 0);
    if ($appointmentId > 0) {
        return $appointmentId;
    }
    $payload = pzsig_document_payload($document);
    return (int)($payload['appointment_id'] ?? 0);
}

function pzsig_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value !== '') {
            $parts = explode(',', $value);
            return trim($parts[0]);
        }
    }
    return '';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pzsig_json(['ok' => false, 'error' => 'Metoda invalida.'], 405);
    }

    csrf_require();
    pzdoc_require_schema($pdo);

    if (!function_exists('is_team_user') || !is_team_user()) {
        pzsig_json(['ok' => false, 'error' => 'Semnatura se poate salva doar din modul Angajat / Teren.'], 403);
    }

    $documentId = (int)($_POST['document_id'] ?? 0);
    if ($documentId <= 0) {
        pzsig_json(['ok' => false, 'error' => 'Document lipsa.'], 400);
    }

    $document = pzdoc_load_accessible_document($pdo, $documentId, false);
    if (!$document) {
        pzsig_json(['ok' => false, 'error' => 'Nu ai acces la acest PV.'], 403);
    }

    $type = pzdoc_normalize_document_type((string)($document['document_type'] ?? ''));
    if ($type !== 'proces_verbal') {
        pzsig_json(['ok' => false, 'error' => 'Semnatura este disponibila doar pentru PV.'], 400);
    }

    if ((string)($document['status'] ?? '') !== 'issued') {
        pzsig_json(['ok' => false, 'error' => 'PV-ul trebuie emis inainte de semnare.'], 400);
    }

    $appointmentId = pzsig_document_appointment_id($document);
    if ($appointmentId <= 0 || !pzdoc_user_can_access_appointment_for_pv($pdo, $appointmentId, true)) {
        pzsig_json(['ok' => false, 'error' => 'Semnatura se poate salva doar dupa finalizarea lucrarii tale.'], 403);
    }

    $signatureData = trim((string)($_POST['signature_data'] ?? ''));
    if ($signatureData === '') {
        pzsig_json(['ok' => false, 'error' => 'Semnatura lipsa.'], 400);
    }

    if (strlen($signatureData) > 2500000) {
        pzsig_json(['ok' => false, 'error' => 'Semnatura este prea mare. Sterge si semneaza din nou.'], 400);
    }

    if (!preg_match('#^data:image/png;base64,#', $signatureData)) {
        pzsig_json(['ok' => false, 'error' => 'Format semnatura invalid.'], 400);
    }

    $base64 = substr($signatureData, strpos($signatureData, ',') + 1);
    $binary = base64_decode($base64, true);
    if ($binary === false || strlen($binary) < 100) {
        pzsig_json(['ok' => false, 'error' => 'Semnatura nu a putut fi citita.'], 400);
    }

    if (substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        pzsig_json(['ok' => false, 'error' => 'Fisierul semnaturii nu este PNG valid.'], 400);
    }

    $dir = __DIR__ . '/uploads/signatures';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        pzsig_json(['ok' => false, 'error' => 'Folderul pentru semnaturi nu a putut fi creat.'], 500);
    }

    $filename = 'pv_' . $documentId . '_client_signature_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';
    $absolutePath = $dir . '/' . $filename;
    if (file_put_contents($absolutePath, $binary, LOCK_EX) === false) {
        pzsig_json(['ok' => false, 'error' => 'Semnatura nu a putut fi salvata pe server.'], 500);
    }

    @chmod($absolutePath, 0644);
    $relativePath = 'uploads/signatures/' . $filename;

    $payload = pzsig_document_payload($document);
    $oldPath = pzsig_safe_relative_path($payload['client_signature_path'] ?? '');

    $payload['client_signature_path'] = $relativePath;
    $payload['client_signature_at'] = date('Y-m-d H:i:s');
    $payload['client_signature_by_team_id'] = function_exists('current_team_id') ? current_team_id() : null;
    $payload['client_signature_by_team_name'] = function_exists('current_user_name') ? current_user_name() : '';
    $payload['client_signature_ip'] = pzsig_client_ip();
    $payload['client_signature_user_agent'] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt = $pdo->prepare("UPDATE documents SET payload_json = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([pzdoc_json_encode($payload), $documentId]);

    if ($oldPath !== '' && strpos($oldPath, 'uploads/signatures/') === 0 && $oldPath !== $relativePath) {
        $oldAbs = __DIR__ . '/' . $oldPath;
        if (is_file($oldAbs)) {
            @unlink($oldAbs);
        }
    }

    pzsig_json([
        'ok' => true,
        'message' => 'Semnatura salvata.',
        'document_id' => $documentId,
        'signature_path' => $relativePath,
    ]);
} catch (Throwable $e) {
    error_log('PestZone document signature error: ' . $e->getMessage());
    pzsig_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
