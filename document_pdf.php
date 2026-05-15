<?php
/**
 * PestZone CRM — document_pdf.php
 * Genereaza PDF prin mPDF (via document_engine.php).
 * URL params: id (obligatoriu), mode (inline | download).
 */

require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/document_engine.php';
require_once __DIR__ . '/document_access.php';

$documentId = max(0, (int)($_GET['id'] ?? 0));
if ($documentId <= 0) {
    http_response_code(400);
    exit('Document invalid.');
}

try {
    pzdoc_require_schema($pdo);
    $document = pzdoc_get_document($pdo, $documentId, false);
    if (!$document) { http_response_code(404); exit('Document inexistent.'); }
    if (!pzdoc_user_can_access_document($pdo, $document)) { http_response_code(403); exit('Acces refuzat.'); }

    $mode = strtolower((string)($_GET['mode'] ?? 'inline'));
    if (!in_array($mode, ['inline', 'download'], true)) { $mode = 'inline'; }

    pzdoc_engine_output_pdf($pdo, $documentId, $mode);
} catch (Throwable $e) {
    error_log('PestZone document_pdf error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><body style="font-family:Arial;padding:24px;">';
    echo '<h2>Eroare generare PDF</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="document_view.php?id=' . (int)$documentId . '">Inapoi</a></p>';
    echo '</body></html>';
}