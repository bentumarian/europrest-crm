<?php
/**
 * INSTALLER — Sistem documente curat (PDF real prin mPDF)
 * ----------------------------------------------------------------
 * Ce face:
 *   1. Backup-eaza fisierele afectate (.bak.YYYYMMDD_HHMMSS)
 *   2. Scrie document_engine.php (NOU — engine unic preview + PDF)
 *   3. Scrie document_pdf.php (REWRITE — genereaza PDF real prin mPDF)
 *   4. Patcheaza document_view.php (iframe srcdoc + URL-uri PDF corecte)
 *   5. Patcheaza document_send_email.php (PDF atasat, nu link public)
 *   6. Patcheaza document_send_quick.php (PDF atasat)
 *   7. Sterge fisiere moarte (document_print.php daca exista)
 *
 * Cum se foloseste:
 *   1. Incarca pe server langa config.php
 *   2. Deschide ca admin: https://app.pestzone.ro/install_doc_system.php
 *   3. Click "INSTALEAZA"
 *   4. Testeaza un document: View → click "PDF" → ar trebui sa descarce real PDF
 *   5. Sterge install_doc_system.php de pe server
 */

require_once __DIR__ . '/config.php';
if (function_exists('require_login')) require_login();
if (function_exists('is_admin') && !is_admin()) { http_response_code(403); exit('Doar admin.'); }

$ROOT = __DIR__;

/* =============================================================
   CONTINUT FISIERE NOI (document_engine.php, document_pdf.php)
   ============================================================= */

$DOCUMENT_ENGINE_PHP = <<<'PHP_CONTENT'
<?php
/**
 * PestZone CRM — document_engine.php
 * SURSA UNICA pentru randare documente: HTML pentru preview SI PDF.
 *
 * Public API:
 *   pzdoc_engine_preview_html(PDO, int): string         → HTML pentru iframe srcdoc
 *   pzdoc_engine_output_pdf(PDO, int, string $mode)     → output mPDF (download/inline). EXIT.
 *   pzdoc_engine_pdf_to_file(PDO, int, string): string  → scrie PDF pe disc, returneaza filename
 *   pzdoc_engine_pdf_string(PDO, int): string           → bytes PDF
 *   pzdoc_engine_pdf_filename(array): string            → filename pe baza tipului + numarului
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/document_core.php';
require_once __DIR__ . '/document_tokens.php';
require_once __DIR__ . '/document_pdf_engine.php';

if (!function_exists('pzdoc_engine_build')) {
    function pzdoc_engine_build(PDO $pdo, int $documentId, bool $forPdf = false): array
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, true);
        if (!$document) { throw new RuntimeException('Document inexistent.'); }

        $design  = pzdoc_pdf_design($pdo, $document);
        $content = pzdoc_pdf_convert_px_to_pt(pzdoc_render_document_html($pdo, $documentId, null, false));
        $design  = pzdoc_pdf_apply_content_font_to_design($design, $content);

        $header = pzdoc_pdf_header_html($pdo, $design);
        $footer = pzdoc_pdf_footer_html($pdo, $document, $design);
        $css    = pzdoc_pdf_global_css($design, $forPdf);

        return ['document' => $document, 'design' => $design, 'content' => $content, 'header' => $header, 'footer' => $footer, 'css' => $css];
    }
}

if (!function_exists('pzdoc_engine_preview_html')) {
    function pzdoc_engine_preview_html(PDO $pdo, int $documentId): string
    {
        $build  = pzdoc_engine_build($pdo, $documentId, false);
        $design = $build['design'];

        $pageTop    = (float)$design['page_margin_top_mm'];
        $pageRight  = (float)$design['page_margin_right_mm'];
        $pageBottom = (float)$design['page_margin_bottom_mm'];
        $pageLeft   = (float)$design['page_margin_left_mm'];

        $headerHtml = $build['header'] !== '' ? '<div class="pzdoc-preview-header">' . $build['header'] . '</div>' : '';
        $footerHtml = $build['footer'] !== '' ? '<div class="pzdoc-preview-footer">' . $build['footer'] . '</div>' : '';

        return '<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title>Preview document</title>
<style>
html, body { margin: 0; padding: 0; background: #eef2f7; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.pzdoc-preview-shell { padding: 18px; display: flex; justify-content: center; }
.pzdoc-preview-a4 { width: 210mm; min-height: 297mm; background: #fff; box-shadow: 0 12px 30px rgba(16,36,62,.16); box-sizing: border-box; padding: ' . $pageTop . 'mm ' . $pageRight . 'mm ' . $pageBottom . 'mm ' . $pageLeft . 'mm; position: relative; }
.pzdoc-preview-header { margin-bottom: 6mm; }
.pzdoc-preview-footer { margin-top: 10mm; }
@media (max-width: 900px) { .pzdoc-preview-shell { padding: 10px; } .pzdoc-preview-a4 { width: 100%; min-height: auto; padding: 14px; } }
</style>
' . $build['css'] . '
</head>
<body>
<div class="pzdoc-preview-shell">
    <div class="pzdoc-preview-a4">
        ' . $headerHtml . '
        <div class="pzdoc-content">' . $build['content'] . '</div>
        ' . $footerHtml . '
    </div>
</div>
</body>
</html>';
    }
}

if (!function_exists('pzdoc_engine_pdf_filename')) {
    function pzdoc_engine_pdf_filename(array $document): string
    {
        $type = function_exists('pzdoc_normalize_document_type')
            ? pzdoc_normalize_document_type((string)($document['document_type'] ?? 'document'))
            : (string)($document['document_type'] ?? 'document');
        $typeMap = ['oferta' => 'Oferta', 'contract' => 'Contract', 'proces_verbal' => 'Proces_verbal'];
        $typeLabel = $typeMap[$type] ?? 'Document';
        $number = trim((string)($document['document_number'] ?? ''));
        if ($number === '') { $number = 'draft-' . (int)($document['id'] ?? 0); }
        $number = preg_replace('/[^a-zA-Z0-9._-]/', '_', $number);
        return $typeLabel . '_' . $number . '.pdf';
    }
}

if (!function_exists('pzdoc_engine_create_mpdf')) {
    function pzdoc_engine_create_mpdf(PDO $pdo, array $document, array $design)
    {
        if (function_exists('pzdoc_pdf_autoload')) { pzdoc_pdf_autoload(); }
        if (!class_exists('\\Mpdf\\Mpdf')) { throw new RuntimeException('mPDF nu este instalat. Ruleaza composer require mpdf/mpdf.'); }
        return pzdoc_pdf_create_mpdf($pdo, $document, $design);
    }
}

if (!function_exists('pzdoc_engine_output_pdf')) {
    function pzdoc_engine_output_pdf(PDO $pdo, int $documentId, string $mode = 'inline'): void
    {
        $build = pzdoc_engine_build($pdo, $documentId, true);
        $mpdf = pzdoc_engine_create_mpdf($pdo, $build['document'], $build['design']);
        $fullHtml = pzdoc_pdf_wrap_content_html($build['design'], $build['content'], true);
        $mpdf->WriteHTML($fullHtml);
        $filename = pzdoc_engine_pdf_filename($build['document']);
        $destination = strtolower($mode) === 'download' ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE;
        $mpdf->Output($filename, $destination);
        exit;
    }
}

if (!function_exists('pzdoc_engine_pdf_to_file')) {
    function pzdoc_engine_pdf_to_file(PDO $pdo, int $documentId, string $filePath): string
    {
        $build = pzdoc_engine_build($pdo, $documentId, true);
        $mpdf = pzdoc_engine_create_mpdf($pdo, $build['document'], $build['design']);
        $fullHtml = pzdoc_pdf_wrap_content_html($build['design'], $build['content'], true);
        $mpdf->WriteHTML($fullHtml);
        $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);
        return pzdoc_engine_pdf_filename($build['document']);
    }
}

if (!function_exists('pzdoc_engine_pdf_string')) {
    function pzdoc_engine_pdf_string(PDO $pdo, int $documentId): string
    {
        $build = pzdoc_engine_build($pdo, $documentId, true);
        $mpdf = pzdoc_engine_create_mpdf($pdo, $build['document'], $build['design']);
        $fullHtml = pzdoc_pdf_wrap_content_html($build['design'], $build['content'], true);
        $mpdf->WriteHTML($fullHtml);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
PHP_CONTENT;

$DOCUMENT_PDF_PHP = <<<'PHP_CONTENT'
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
PHP_CONTENT;

/* =============================================================
   PATCH-URI PE FISIERE EXISTENTE
   ============================================================= */

// Patch document_view.php — replace iframe with srcdoc + update PDF button URLs
$VIEW_PATCHES = [
    [
        'name' => 'Adauga require document_engine.php',
        'find' => "require_once __DIR__ . '/document_access.php';",
        'replace' => "require_once __DIR__ . '/document_access.php';\nrequire_once __DIR__ . '/document_engine.php';",
    ],
    [
        'name' => 'Genereaza previewHtml via engine (in try)',
        'find' => "    \$template = \$preview['template'];\n    \$previewHtml = '';",
        'replace' => "    \$template = \$preview['template'];\n    try { \$previewHtml = pzdoc_engine_preview_html(\$pdo, \$documentId); } catch (Throwable \$pvErr) { \$previewHtml = '<p style=\"padding:20px;color:#b91c1c;font-family:Arial;\">Eroare preview: ' . dview_h(\$pvErr->getMessage()) . '</p>'; }",
    ],
    [
        'name' => 'Buton "Printeaza / Salveaza PDF" → document_pdf.php',
        'find' => '<a class="btn accent" target="_blank" href="<?= dview_h(dview_print_url($document, $printCacheKey, true, false)) ?>">Printeaza / Salveaza PDF</a>',
        'replace' => '<a class="btn accent" target="_blank" href="document_pdf.php?id=<?= (int)$document[\'id\'] ?>&mode=download">Descarca PDF</a>',
    ],
    [
        'name' => 'Link "Deschide pagina de print" → PDF inline',
        'find' => '<a class="link-muted" target="_blank" href="<?= dview_h(dview_print_url($document, $printCacheKey, false, false)) ?>">Deschide pagina de print</a>',
        'replace' => '<a class="link-muted" target="_blank" href="document_pdf.php?id=<?= (int)$document[\'id\'] ?>">Deschide PDF</a>',
    ],
    [
        'name' => 'Iframe preview → srcdoc (HTML direct, fara fisier extern)',
        'find' => '<iframe class="print-preview-frame" src="<?= dview_h(dview_print_url($document, $printCacheKey, false, true)) ?>" title="Previzualizare document A4"></iframe>',
        'replace' => '<iframe class="print-preview-frame" srcdoc="<?= dview_h($previewHtml) ?>" title="Previzualizare document A4"></iframe>',
    ],
];

// Patch document_send_email.php — PDF attached, no public link
$EMAIL_PATCHES = [
    [
        'name' => 'Schimba require: public_link_lib → engine',
        'find' => "require_once __DIR__ . '/document_public_link_lib.php';",
        'replace' => "require_once __DIR__ . '/document_engine.php';",
    ],
    [
        'name' => 'Inlocuieste public link cu PDF atasat',
        'find' => "\$publicLink = pzdoc_public_create_link(\$pdo, \$documentId, 90);\n        \$publicUrl = (string)(\$publicLink['url'] ?? '');\n        if (\$publicUrl === '') {\n            throw new RuntimeException('Nu am putut genera linkul public pentru document.');\n        }\n        \$htmlBody = pzdoc_email_append_public_link_html(\$htmlBody, \$publicUrl);",
        'replace' => "// Generam PDF-ul real prin mPDF si il atasam la email.\n        \$tmpDir = __DIR__ . '/tmp/document_emails';\n        if (!is_dir(\$tmpDir)) { @mkdir(\$tmpDir, 0755, true); }\n        if (!is_writable(\$tmpDir)) { throw new RuntimeException('Folderul tmp/document_emails nu este scriibil.'); }\n        \$pdfTmpPath = \$tmpDir . '/doc_' . \$documentId . '_' . bin2hex(random_bytes(4)) . '.pdf';\n        \$pdfFilename = pzdoc_engine_pdf_to_file(\$pdo, \$documentId, \$pdfTmpPath);",
    ],
    [
        'name' => 'Eliminat text public link in textBody',
        'find' => "\$textBody = pzdoc_email_append_public_link_text(\$textBody, \$publicUrl);",
        'replace' => "// (PDF-ul e atasat direct la email, nu mai trimitem link public)",
    ],
    [
        'name' => 'Atasament PDF real',
        'find' => "// Nu mai atasam PDF generat cu mPDF. Trimitem link catre document_print.php,\n        // aceeasi pagina A4 care se printeaza corect din browser.\n        \$attachments = [];",
        'replace' => "\$attachments = [[\n            'path' => \$pdfTmpPath,\n            'mime' => 'application/pdf',\n            'filename' => \$pdfFilename,\n        ]];",
    ],
    [
        'name' => 'Log attachment path',
        'find' => "\$attachmentRelative = 'public-link:' . \$publicUrl;",
        'replace' => "\$attachmentRelative = 'mpdf:' . basename(\$pdfTmpPath);",
    ],
    [
        'name' => 'Link "Print A4" 1 → "Descarca PDF"',
        'find' => '<a class="btn" target="_blank" href="document_print.php?id=<?= (int)$documentId ?>">Print A4</a>',
        'replace' => '<a class="btn" target="_blank" href="document_pdf.php?id=<?= (int)$documentId ?>&mode=download">Descarca PDF</a>',
    ],
    [
        'name' => 'Link "Verifica document A4" → PDF inline',
        'find' => '<a class="btn" target="_blank" href="document_print.php?id=<?= (int)$documentId ?>">Verifica document A4</a>',
        'replace' => '<a class="btn" target="_blank" href="document_pdf.php?id=<?= (int)$documentId ?>">Verifica PDF</a>',
    ],
];

// Patch document_send_quick.php — PDF attached, no public link
$QUICK_PATCHES = [
    [
        'name' => 'Schimba require: public_link_lib → engine',
        'find' => "require_once __DIR__ . '/document_public_link_lib.php';",
        'replace' => "require_once __DIR__ . '/document_engine.php';",
    ],
    [
        'name' => 'Inlocuieste public link + textBody + attachments cu PDF atasat',
        'find' => "    \$publicLink = pzdoc_public_create_link(\$pdo, \$documentId, 90);\n    \$publicUrl = (string)(\$publicLink['url'] ?? '');\n    if (\$publicUrl === '') {\n        pzquick_json(['ok' => false, 'error' => 'Nu am putut genera linkul public pentru document.'], 500);\n    }\n    \$htmlBody = pzquick_append_public_link_html(\$htmlBody, \$publicUrl);\n    \$textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], \"\n\", \$htmlBody)), ENT_QUOTES, 'UTF-8'));\n    \$textBody = pzquick_append_public_link_text(\$textBody, \$publicUrl);\n\n    // Nu atasam PDF generat cu mPDF. Trimitem linkul catre document_print.php,\n    // aceeasi pagina A4 care se printeaza corect din browser.\n    \$attachments = [];",
        'replace' => "    // PDF atasat real prin engine (mPDF). Fara link public.\n    \$tmpDir = __DIR__ . '/tmp/document_emails';\n    if (!is_dir(\$tmpDir)) { @mkdir(\$tmpDir, 0755, true); }\n    if (!is_writable(\$tmpDir)) { pzquick_json(['ok' => false, 'error' => 'Folderul tmp/document_emails nu este scriibil.'], 500); }\n    \$pdfTmpPath = \$tmpDir . '/doc_' . \$documentId . '_' . bin2hex(random_bytes(4)) . '.pdf';\n    try {\n        \$pdfFilename = pzdoc_engine_pdf_to_file(\$pdo, \$documentId, \$pdfTmpPath);\n    } catch (Throwable \$pe) {\n        pzquick_json(['ok' => false, 'error' => 'Nu am putut genera PDF-ul: ' . \$pe->getMessage()], 500);\n    }\n    \$textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], \"\n\", \$htmlBody)), ENT_QUOTES, 'UTF-8'));\n    \$attachments = [[\n        'path' => \$pdfTmpPath,\n        'mime' => 'application/pdf',\n        'filename' => \$pdfFilename,\n    ]];",
    ],
    [
        'name' => 'Log attachment path (quick)',
        'find' => "\$attachmentRelative = 'public-link:' . \$publicUrl;",
        'replace' => "\$attachmentRelative = 'mpdf:' . basename(\$pdfTmpPath);",
    ],
];

/* =============================================================
   HELPERI INSTALL
   ============================================================= */

function backup_file(string $path): ?string {
    if (!is_file($path)) return null;
    $bak = $path . '.bak.' . date('Ymd_His');
    return @copy($path, $bak) ? $bak : null;
}

function write_file(string $path, string $content): bool {
    return @file_put_contents($path, $content) !== false;
}

function apply_patches(string $path, array $patches, array &$report): bool {
    if (!is_file($path)) {
        $report[] = ['file' => basename($path), 'status' => 'EROARE', 'msg' => 'Fisier inexistent'];
        return false;
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        $report[] = ['file' => basename($path), 'status' => 'EROARE', 'msg' => 'Nu pot citi fisierul'];
        return false;
    }

    $original = $content;
    $applied = 0; $skipped = 0; $failed = 0;
    $details = [];

    foreach ($patches as $patch) {
        $find = $patch['find'];
        $replace = $patch['replace'];

        // Verifica daca patch-ul e deja aplicat
        if (strpos($content, $replace) !== false && strpos($content, $find) === false) {
            $skipped++;
            $details[] = "  • {$patch['name']}: deja aplicat";
            continue;
        }

        if (strpos($content, $find) === false) {
            $failed++;
            $details[] = "  • {$patch['name']}: NU s-a gasit pattern-ul (poate fisierul e diferit)";
            continue;
        }

        // Verifica unicitate (sa nu fie multiple match-uri ambigue)
        if (substr_count($content, $find) > 1) {
            $failed++;
            $details[] = "  • {$patch['name']}: pattern apare de mai multe ori — necesita interventie manuala";
            continue;
        }

        $content = str_replace($find, $replace, $content);
        $applied++;
        $details[] = "  • {$patch['name']}: OK";
    }

    if ($content === $original) {
        $report[] = ['file' => basename($path), 'status' => 'NEMODIFICAT', 'msg' => 'Nimic de schimbat. Skipped: ' . $skipped . ', Failed: ' . $failed, 'details' => $details];
        return $failed === 0;
    }

    if ($failed > 0 && $applied === 0) {
        $report[] = ['file' => basename($path), 'status' => 'ESUAT', 'msg' => 'Niciun patch nu s-a aplicat. Failed: ' . $failed, 'details' => $details];
        return false;
    }

    $bak = backup_file($path);
    if (!$bak) {
        $report[] = ['file' => basename($path), 'status' => 'EROARE', 'msg' => 'Nu am putut crea backup', 'details' => $details];
        return false;
    }
    if (!write_file($path, $content)) {
        $report[] = ['file' => basename($path), 'status' => 'EROARE', 'msg' => 'Nu am putut scrie fisierul', 'details' => $details];
        return false;
    }

    $statusMsg = $applied . ' patch-uri aplicate';
    if ($skipped > 0) $statusMsg .= ', ' . $skipped . ' deja aplicate';
    if ($failed > 0) $statusMsg .= ', ' . $failed . ' esuate';
    $report[] = ['file' => basename($path), 'status' => $failed > 0 ? 'PARTIAL' : 'OK', 'msg' => $statusMsg . '. Backup: ' . basename($bak), 'details' => $details];
    return $failed === 0;
}

/* =============================================================
   EXECUTIE
   ============================================================= */

$run = isset($_POST['run']) && $_POST['run'] === '1';
$report = [];

if ($run) {
    // 1) document_engine.php — scrie NEW
    $enginePath = $ROOT . '/document_engine.php';
    backup_file($enginePath);
    if (write_file($enginePath, $DOCUMENT_ENGINE_PHP)) {
        $report[] = ['file' => 'document_engine.php', 'status' => 'OK', 'msg' => 'Creat / inlocuit'];
    } else {
        $report[] = ['file' => 'document_engine.php', 'status' => 'EROARE', 'msg' => 'Nu am putut scrie'];
    }

    // 2) document_pdf.php — REPLACE
    $pdfPath = $ROOT . '/document_pdf.php';
    backup_file($pdfPath);
    if (write_file($pdfPath, $DOCUMENT_PDF_PHP)) {
        $report[] = ['file' => 'document_pdf.php', 'status' => 'OK', 'msg' => 'Inlocuit cu versiunea care genereaza PDF prin mPDF'];
    } else {
        $report[] = ['file' => 'document_pdf.php', 'status' => 'EROARE', 'msg' => 'Nu am putut scrie'];
    }

    // 3) Patches
    apply_patches($ROOT . '/document_view.php', $VIEW_PATCHES, $report);
    apply_patches($ROOT . '/document_send_email.php', $EMAIL_PATCHES, $report);
    apply_patches($ROOT . '/document_send_quick.php', $QUICK_PATCHES, $report);

    // 4) Cleanup — sterge fisierul mort document_print.php daca exista
    $printPath = $ROOT . '/document_print.php';
    if (is_file($printPath)) {
        backup_file($printPath);
        if (@unlink($printPath)) {
            $report[] = ['file' => 'document_print.php', 'status' => 'STERS', 'msg' => 'Eliminat (nu mai e folosit)'];
        }
    }

    // 5) Creeaza folderul tmp/document_emails pentru atasamentele PDF
    $tmpDir = $ROOT . '/tmp/document_emails';
    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0755, true); }
    if (is_dir($tmpDir) && is_writable($tmpDir)) {
        $report[] = ['file' => 'tmp/document_emails/', 'status' => 'OK', 'msg' => 'Folder pentru PDF-uri temporare la email — gata'];
    } else {
        $report[] = ['file' => 'tmp/document_emails/', 'status' => 'ATENTIE', 'msg' => 'Folderul nu e scriibil. Creeaza-l manual cu chmod 755.'];
    }
}

function status_color($s) {
    if (in_array($s, ['OK', 'STERS'], true)) return '#047857';
    if (in_array($s, ['PARTIAL', 'ATENTIE', 'NEMODIFICAT'], true)) return '#b45309';
    if (in_array($s, ['EROARE', 'ESUAT'], true)) return '#b91c1c';
    return '#64748b';
}
?>
<!doctype html>
<html lang="ro"><head><meta charset="utf-8"><title>Install — Sistem documente curat</title>
<style>
body{font-family:-apple-system,Arial;max-width:900px;margin:24px auto;padding:0 18px;color:#0f172a;background:#f8fafc}
h1{font-size:24px;margin:0 0 6px}
h2{font-size:16px;margin:18px 0 8px;color:#334155}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin:14px 0;box-shadow:0 1px 2px rgba(15,23,42,.03)}
.btn{display:inline-block;padding:12px 22px;border:0;border-radius:10px;background:#4f46e5;color:#fff;font-weight:700;cursor:pointer;font-size:14px;text-decoration:none}
.btn:hover{background:#4338ca}
.btn.ghost{background:#fff;color:#4f46e5;border:1px solid #4f46e5}
.status{display:inline-block;padding:3px 10px;border-radius:8px;color:#fff;font-weight:700;font-size:11px}
.warn{background:#fffbeb;border:1px solid #fcd34d;color:#78350f;padding:12px 14px;border-radius:10px;margin:12px 0;font-size:14px}
.ok{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:12px 14px;border-radius:10px;margin:12px 0;font-size:14px}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-family:ui-monospace,Menlo,monospace;font-size:12px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px}
th,td{padding:8px 10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}
th{background:#f8fafc;font-size:11px;text-transform:uppercase;color:#475569}
.det{margin:6px 0 0 0;font-size:11.5px;color:#475569;white-space:pre-wrap;font-family:ui-monospace,Menlo,monospace}
</style></head><body>

<h1>Sistem documente curat — instalare</h1>
<p style="color:#64748b">Reset complet: <strong>preview HTML A4 direct in pagina + PDF real prin mPDF</strong>. Acelasi continut, acelasi CSS. Email cu PDF atasat.</p>

<?php if (!$run): ?>
    <div class="warn">
        <strong>Ce face installer-ul:</strong>
        <ol style="margin:8px 0 0 18px;padding:0;">
            <li>Backup automat la <strong>document_pdf.php, document_view.php, document_send_email.php, document_send_quick.php</strong> (.bak.YYYYMMDD_HHMMSS)</li>
            <li>Scrie <code>document_engine.php</code> — sursa unica HTML / PDF</li>
            <li>Inlocuieste <code>document_pdf.php</code> — genereaza PDF real prin mPDF</li>
            <li>Patcheaza <code>document_view.php</code> — preview HTML direct (fara fisier extern de print), URL-uri PDF corecte</li>
            <li>Patcheaza <code>document_send_email.php</code> + <code>document_send_quick.php</code> — PDF atasat la email</li>
            <li>Sterge <code>document_print.php</code> daca exista (concept abandonat)</li>
            <li>Creeaza <code>tmp/document_emails/</code> pentru atasamente PDF temporare</li>
        </ol>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Pre-requisite</h2>
        <ul>
            <li>mPDF instalat in <code>vendor/mpdf/mpdf/</code> (verifica <code>composer.json</code>)</li>
            <li>Folderul <code>tmp/</code> scriibil (chmod 755)</li>
            <li>Esti logat ca admin (deja OK)</li>
        </ul>
        <form method="post">
            <input type="hidden" name="run" value="1">
            <button class="btn" type="submit">INSTALEAZA</button>
        </form>
    </div>

<?php else: ?>
    <div class="card">
        <h2 style="margin-top:0">Raport executie</h2>
        <table>
            <thead><tr><th>Fisier</th><th>Status</th><th>Detalii</th></tr></thead>
            <tbody>
            <?php foreach ($report as $r): ?>
                <tr>
                    <td><code><?= htmlspecialchars($r['file']) ?></code></td>
                    <td><span class="status" style="background:<?= status_color($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td>
                        <?= htmlspecialchars($r['msg']) ?>
                        <?php if (!empty($r['details'])): ?>
                            <div class="det"><?= htmlspecialchars(implode("\n", $r['details'])) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Test final</h2>
        <ol style="color:#334155">
            <li>Mergi pe un document existent (PV / oferta / contract) → click <strong>Vezi</strong></li>
            <li>Ar trebui sa vezi preview-ul A4 in pagina (cu margini, fonturi, layout final)</li>
            <li>Click pe butonul <strong>Descarca PDF</strong> → ar trebui sa primesti fisier .pdf real, identic cu preview-ul</li>
            <li>Click pe <strong>Trimite email</strong> → completeaza → trimite → primesti email cu <strong>PDF atasat</strong></li>
            <li>Daca tot e OK, sterge <code>install_doc_system.php</code> de pe server</li>
        </ol>

        <p style="margin-top:18px"><a class="btn ghost" href="?">Inapoi</a></p>
    </div>
<?php endif; ?>

</body></html>
