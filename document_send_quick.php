<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/document_core.php';
require_once __DIR__ . '/document_tokens.php';
require_once __DIR__ . '/document_engine.php';
require_once __DIR__ . '/document_access.php';

if (file_exists(__DIR__ . '/notification_lib.php')) {
    require_once __DIR__ . '/notification_lib.php';
}

header('Content-Type: application/json; charset=utf-8');

function pzquick_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pzquick_str($value, int $max = 0): string
{
    $value = trim((string)($value ?? ''));
    if ($max > 0) {
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $max, 'UTF-8');
        } else {
            $value = substr($value, 0, $max);
        }
    }
    return $value;
}

function pzquick_valid_email(string $email): bool
{
    return trim($email) !== '' && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

function pzquick_email_texts(array $document): array
{
    $type = pzdoc_normalize_document_type((string)($document['document_type'] ?? ''));
    $number = pzquick_str($document['document_number'] ?? '') ?: ('ID ' . (int)($document['id'] ?? 0));
    $client = pzquick_str($document['client_name_snapshot'] ?? '');

    if ($type === 'oferta') {
        $subject = 'Oferta ' . $number;
        $body = "Buna ziua,\n\nVa transmitem oferta comerciala {$number}, atasata acestui email.\n\nPentru orice detalii sau clarificari, va stam la dispozitie.\n\nCu stima,\n{{company_name}}\n{{company_phone}}\n{{company_email}}";
    } elseif ($type === 'contract') {
        $subject = 'Contract ' . $number;
        $body = "Buna ziua,\n\nVa transmitem contractul {$number}, atasat acestui email.\n\nPentru orice detalii sau clarificari, va stam la dispozitie.\n\nCu stima,\n{{company_name}}\n{{company_phone}}\n{{company_email}}";
    } elseif ($type === 'proces_verbal') {
        $subject = 'Proces verbal ' . $number;
        $body = "Buna ziua,\n\nVa transmitem procesul verbal {$number}, atasat acestui email.\n\nPentru orice detalii sau clarificari, va stam la dispozitie.\n\nCu stima,\n{{company_name}}\n{{company_phone}}\n{{company_email}}";
    } else {
        $label = pzdoc_document_type_label($type);
        $subject = $label . ' ' . $number;
        $body = "Buna ziua,\n\nVa transmitem {$label} {$number}, atasat acestui email.\n\nPentru orice detalii sau clarificari, va stam la dispozitie.\n\nCu stima,\n{{company_name}}\n{{company_phone}}\n{{company_email}}";
    }

    if ($client !== '') {
        $subject .= ' - ' . $client;
    }

    return ['subject' => $subject, 'body' => $body];
}

function pzquick_relative_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $base = str_replace('\\', '/', __DIR__);
    if (strpos($path, $base . '/') === 0) {
        return substr($path, strlen($base) + 1);
    }
    return $path;
}

function pzquick_log_email(PDO $pdo, int $documentId, string $recipient, string $subject, string $body, string $attachmentPath, string $status, string $providerResponse): void
{
    try {
        $sentBy = function_exists('current_user_id') ? current_user_id() : null;
        $stmt = $pdo->prepare("\n            INSERT INTO document_email_logs\n                (document_id, recipient, cc, subject, body, attachment_path, status, provider, provider_response, sent_by, sent_at)\n            VALUES\n                (?, ?, '', ?, ?, ?, ?, 'sendgrid', ?, ?, NOW())\n        ");
        $stmt->execute([
            $documentId,
            pzquick_str($recipient, 255),
            pzquick_str($subject, 255),
            $body,
            pzquick_str($attachmentPath, 255),
            pzquick_str($status, 40),
            $providerResponse,
            $sentBy,
        ]);
    } catch (Throwable $e) {
        error_log('PestZone quick document email log error: ' . $e->getMessage());
    }
}

function pzquick_email_body_to_html(string $body): string
{
    $body = trim($body);
    if ($body === '') {
        return '';
    }
    if (stripos($body, '<p') !== false || stripos($body, '<br') !== false || stripos($body, '<div') !== false) {
        return $body;
    }
    return '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
}

/*
|--------------------------------------------------------------------------
| Helper vechi pastrat pentru compatibilitate
|--------------------------------------------------------------------------
| Fluxul nou nu mai foloseste atasamente PDF generate cu mPDF.
|--------------------------------------------------------------------------
*/
if (!function_exists('pzdoc_ensure_email_tmp_dir')) {
    function pzdoc_ensure_email_tmp_dir(): string {
        $dir = __DIR__ . '/tmp/document_emails';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException(
                    'Nu pot crea folderul ' . $dir . '. Verifica permisiunile pe folderul tmp/. Setati 775 si ownerul corect.'
                );
            }
        }
        if (!is_writable($dir)) {
            throw new RuntimeException(
                'Folderul ' . $dir . ' exista dar nu e writable. Setati permisiuni 775 si ownerul user-ului PHP.'
            );
        }
        return $dir;
    }
}

/*
|--------------------------------------------------------------------------
| Helper: Rezolva email-ul clientului cu fallback
|--------------------------------------------------------------------------
| Sursa primara: client_email_snapshot (frozen la emitere)
| Fallback:      clients.email (live, daca snapshot e gol/invalid)
|
| Returneaza ['email'=>'X', 'source'=>'snapshot|live|none', 'updated'=>bool]
| Daca foloseste live, actualizeaza snapshot-ul in DB pentru data viitoare.
|--------------------------------------------------------------------------
*/
if (!function_exists('pzdoc_resolve_client_email')) {
    function pzdoc_resolve_client_email(PDO $pdo, array $document): array {
        $snapshot = trim((string)($document['client_email_snapshot'] ?? ''));
        if ($snapshot !== '' && filter_var($snapshot, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $snapshot, 'source' => 'snapshot', 'updated' => false];
        }
        $clientId = (int)($document['client_id'] ?? 0);
        if ($clientId <= 0) {
            return ['email' => '', 'source' => 'none', 'updated' => false];
        }
        try {
            $stmt = $pdo->prepare("SELECT email FROM clients WHERE id = ? LIMIT 1");
            $stmt->execute([$clientId]);
            $live = trim((string)$stmt->fetchColumn());
            if ($live === '' || !filter_var($live, FILTER_VALIDATE_EMAIL)) {
                return ['email' => '', 'source' => 'none', 'updated' => false];
            }
            // Auto-actualizare snapshot pentru consistenta data viitoare
            $upd = $pdo->prepare("UPDATE documents SET client_email_snapshot = ? WHERE id = ?");
            $upd->execute([$live, (int)$document['id']]);
            return ['email' => $live, 'source' => 'live', 'updated' => true];
        } catch (Throwable $e) {
            error_log('pzdoc_resolve_client_email error: ' . $e->getMessage());
            return ['email' => '', 'source' => 'none', 'updated' => false];
        }
    }
}


function pzquick_append_public_link_html(string $html, string $url): string
{
    $urlEsc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $html . "\n" .
        '<div style="margin:18px 0;padding:16px;border:1px solid #dbe3ef;border-radius:12px;background:#f8fafc;">' .
        '<p style="margin:0 0 12px;font-weight:700;color:#10243e;">Documentul este disponibil aici:</p>' .
        '<p style="margin:0 0 12px;"><a href="' . $urlEsc . '" style="display:inline-block;background:#10243e;color:#ffffff;text-decoration:none;padding:10px 14px;border-radius:10px;font-weight:700;">Deschide documentul</a></p>' .
        '<p style="margin:0;color:#64748b;font-size:13px;line-height:1.45;">Din pagina deschisa, documentul poate fi printat sau salvat ca PDF. Pagina pastreaza exact aspectul documentului din CRM.</p>' .
        '</div>';
}

function pzquick_append_public_link_text(string $text, string $url): string
{
    return trim($text) . "\n\nDocument: " . $url . "\nDin pagina deschisa, documentul poate fi printat sau salvat ca PDF.";
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pzquick_json(['ok' => false, 'error' => 'Metoda invalida.'], 405);
    }

    csrf_require();
    pzdoc_require_schema($pdo);

    if (function_exists('pz_notify_init')) {
        pz_notify_init();
    }

    $documentId = (int)($_POST['document_id'] ?? 0);
    if ($documentId <= 0) {
        pzquick_json(['ok' => false, 'error' => 'Document lipsa.'], 400);
    }

    $document = pzdoc_load_accessible_document($pdo, $documentId, true);
    if (!$document) {
        pzquick_json(['ok' => false, 'error' => 'Nu ai acces la acest document.'], 403);
    }

    $documentType = pzdoc_normalize_document_type((string)($document['document_type'] ?? ''));
    if (!in_array($documentType, ['oferta', 'contract', 'proces_verbal'], true)) {
        pzquick_json(['ok' => false, 'error' => 'Trimiterea rapida este disponibila doar pentru oferte, contracte si procese verbale.'], 400);
    }

    if (($document['status'] ?? '') !== 'issued') {
        pzquick_json(['ok' => false, 'error' => 'Documentul trebuie emis inainte de trimiterea pe email.'], 400);
    }

    if (!function_exists('pz_sendgrid_send_email')) {
        pzquick_json(['ok' => false, 'error' => 'SendGrid nu este disponibil. Verifica notification_lib.php.'], 500);
    }

    // Verificare configuratie SendGrid - mesaj granular
    $sgKey = trim((string)pz_setting_get('sendgrid_api_key', ''));
    $sgFrom = trim((string)pz_setting_get('email_from_address', ''));
    if ($sgKey === '') {
        pzquick_json(['ok' => false, 'error' => 'Lipseste SendGrid API key. Configureaza in Setari > Comunicare / Integrari.'], 500);
    }
    if ($sgFrom === '' || !filter_var($sgFrom, FILTER_VALIDATE_EMAIL)) {
        pzquick_json(['ok' => false, 'error' => 'Lipseste sau e invalid email-ul expeditor (email_from_address). Configureaza in Setari > Comunicare / Integrari.'], 500);
    }


    // Rezolvare email cu fallback (snapshot -> live din clients.email)
    $emailResolved = pzdoc_resolve_client_email($pdo, $document);
    $to = $emailResolved['email'];
    if ($to === '') {
        $clientName = trim((string)($document['client_name_snapshot'] ?? 'Client'));
        pzquick_json(['ok' => false, 'error' => 'Clientul "' . $clientName . '" nu are email salvat. Adauga email-ul in fisa clientului si reincearca.'], 400);
    }

    $emailTexts = pzquick_email_texts($document);
    $subject = $emailTexts['subject'];
    $body = $emailTexts['body'];
    $tokens = pzdoc_build_tokens($pdo, $document);
    $subjectApplied = trim(strip_tags(pzdoc_apply_tokens($subject, $tokens)));
    $bodyApplied = pzdoc_apply_tokens($body, $tokens);
    $htmlBody = pzquick_email_body_to_html($bodyApplied);
    // PDF atasat real prin engine (mPDF). Fara link public.
    $tmpDir = __DIR__ . '/tmp/document_emails';
    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0755, true); }
    if (!is_writable($tmpDir)) { pzquick_json(['ok' => false, 'error' => 'Folderul tmp/document_emails nu este scriibil.'], 500); }
    $pdfTmpPath = $tmpDir . '/doc_' . $documentId . '_' . bin2hex(random_bytes(4)) . '.pdf';
    try {
        $pdfFilename = pzdoc_engine_pdf_to_file($pdo, $documentId, $pdfTmpPath);
    } catch (Throwable $pe) {
        pzquick_json(['ok' => false, 'error' => 'Nu am putut genera PDF-ul: ' . $pe->getMessage()], 500);
    }
    $textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "
", $htmlBody)), ENT_QUOTES, 'UTF-8'));
    $attachments = [[
        'path' => $pdfTmpPath,
        'mime' => 'application/pdf',
        'filename' => $pdfFilename,
    ]];

    $result = pz_sendgrid_send_email($to, $subjectApplied, $htmlBody, $textBody, $attachments, 'document', $documentId);
    $ok = !empty($result['ok']);
    $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $attachmentRelative = 'mpdf:' . basename($pdfTmpPath);
    pzquick_log_email($pdo, $documentId, $to, $subjectApplied, $bodyApplied, $attachmentRelative, $ok ? 'sent' : 'failed', $response);

    if (!$ok) {
        pzquick_json(['ok' => false, 'error' => $result['error'] ?? $result['response'] ?? 'Emailul nu a putut fi trimis.'], 500);
    }

    $stmt = $pdo->prepare("\n        UPDATE documents\n        SET email_sent_at = NOW(),\n            email_sent_to = ?,\n            email_sent_count = COALESCE(email_sent_count, 0) + 1\n        WHERE id = ?\n    ");
    $stmt->execute([$to, $documentId]);

    pzquick_json([
        'ok' => true,
        'message' => 'Email trimis cu succes.',
        'to' => $to,
        'document_id' => $documentId,
    ]);
} catch (Throwable $e) {
    error_log('PestZone quick document email error: ' . $e->getMessage());
    pzquick_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
