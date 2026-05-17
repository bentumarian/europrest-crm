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
        $content = pzdoc_pdf_prepare_html(pzdoc_pdf_convert_px_to_pt(pzdoc_render_document_html($pdo, $documentId, null, false)));
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
