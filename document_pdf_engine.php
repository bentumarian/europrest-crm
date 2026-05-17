<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/document_tokens.php';

if (file_exists(__DIR__ . '/settings_lib.php')) {
    require_once __DIR__ . '/settings_lib.php';
}

/*
|--------------------------------------------------------------------------
| PestZone - document PDF engine
|--------------------------------------------------------------------------
| Motor unic PDF pentru:
| - oferta
| - contract
| - proces verbal
|
| Acest fișier nu contine formulare si nu decide continutul documentului.
| Continutul vine din document_tokens.php, iar aici se aplica designul A4,
| header, footer si randarea prin mPDF.
|--------------------------------------------------------------------------
*/

if (!function_exists('pzdoc_pdf_h')) {
    function pzdoc_pdf_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pzdoc_pdf_repair_romanian_text')) {
    function pzdoc_pdf_repair_romanian_text(string $html): string
    {
        $pairs = [
            '?os.' => '&#536;os.', '?oseaua' => '&#536;oseaua', '?OSEAUA' => '&#536;OSEAUA',
            'Bucure?ti' => 'Bucure&#537;ti', 'BUCURE?TI' => 'BUCURE&#536;TI',
            'Constan?a' => 'Constan&#539;a', 'CONSTAN?A' => 'CONSTAN&#538;A',
            'Timi?oara' => 'Timi&#537;oara', 'TIMI?OARA' => 'TIMI&#536;OARA',
            'Bra?ov' => 'Bra&#537;ov', 'BRA?OV' => 'BRA&#536;OV',
            'Ploie?ti' => 'Ploie&#537;ti', 'PLOIE?TI' => 'PLOIE&#536;TI',
            'Ia?i' => 'Ia&#537;i', 'IA?I' => 'IA&#536;I',
            'Bistri?a' => 'Bistri&#539;a', 'BISTRI?A' => 'BISTRI&#538;A',
            'Gala?i' => 'Gala&#539;i', 'GALA?I' => 'GALA&#538;I',
            'Dambovi?a' => 'Dambovi&#539;a', 'DAMBOVI?A' => 'DAMBOVI&#538;A',
            'Ialomi?a' => 'Ialomi&#539;a', 'IALOMI?A' => 'IALOMI&#538;A',
        ];

        return strtr($html, $pairs);
    }
}

if (!function_exists('pzdoc_pdf_prepare_html')) {
    function pzdoc_pdf_prepare_html(string $html): string
    {
        $html = pzdoc_pdf_repair_romanian_text($html);

        // Șablonul poate contine font-family inline din editor. In mPDF, fonturi
        // precum Arial pot pierde diacriticele romanesti; DejaVu Sans le suporta.
        $html = preg_replace('/font-family\s*:\s*[^;"\']+;?/i', 'font-family: dejavusans, DejaVu Sans, sans-serif;', $html) ?? $html;

        return $html;
    }
}

if (!function_exists('pzdoc_pdf_float')) {
    function pzdoc_pdf_float($value, float $fallback, float $min, float $max): float
    {
        $value = str_replace(',', '.', trim((string)$value));
        if (!is_numeric($value)) {
            return $fallback;
        }
        $value = (float)$value;
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }
}

if (!function_exists('pzdoc_pdf_bool')) {
    function pzdoc_pdf_bool($value, bool $fallback = false): bool
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'da', 'on'], true);
    }
}

if (!function_exists('pzdoc_pdf_convert_px_to_pt')) {
    /**
     * Normalizeaza font-size din px in pt pentru mPDF.
     * Important: 1pt = 1.333px, deci 1px = 0.75pt.
     * Conversia 1px -> 1pt marestea artificial textul in PDF fata de preview.
     */
    function pzdoc_pdf_convert_px_to_pt(string $html): string
    {
        return preg_replace_callback(
            '/font-size\s*:\s*([0-9]+(?:\.[0-9]+)?)\s*px/i',
            function (array $m): string {
                $pt = round(((float)$m[1]) * 0.75, 2);
                $value = rtrim(rtrim(number_format($pt, 2, '.', ''), '0'), '.');
                return 'font-size:' . $value . 'pt';
            },
            $html
        ) ?? $html;
    }
}


if (!function_exists('pzdoc_pdf_extract_content_font_size_pt')) {
    /**
     * Extrage o marime de font dominanta din HTML-ul șablonului/documentului.
     * Motiv: editorul si preview-ul pot salva font-size in template, iar modul compact PV
     * nu trebuie sa forteze PDF-ul la 9.2pt dacă șablonul are 11pt.
     */
    function pzdoc_pdf_extract_content_font_size_pt(string $html): ?float
    {
        if ($html === '') {
            return null;
        }

        $matches = [];
        if (!preg_match_all('/font-size\s*:\s*([0-9]+(?:\.[0-9]+)?)\s*(pt|px)/i', $html, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $counts = [];
        foreach ($matches as $m) {
            $value = (float)$m[1];
            $unit = strtolower((string)$m[2]);
            if ($unit === 'px') {
                // 1px = 0.75pt. Altfel PDF-ul devine mai mare decat preview-ul.
                $value = $value * 0.75;
            }
            if ($value < 7 || $value > 14) {
                continue;
            }
            $key = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
            if ($key === '') {
                continue;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        if (!$counts) {
            return null;
        }

        arsort($counts);
        $best = (float)array_key_first($counts);
        return ($best >= 7 && $best <= 14) ? $best : null;
    }
}

if (!function_exists('pzdoc_pdf_apply_content_font_to_design')) {
    /**
     * IMPORTANT 2026-05-11:
     * Nu mai deducem automat fontul dominant din HTML.
     * Motiv: editorul/HTML-ul poate contine font-size mostenit sau wrapper-e vechi,
     * iar motorul PDF ajungea sa mareasca documentul fata de preview.
     * Fontul de baza trebuie sa vina din designul documentului / regula PV,
     * iar stilurile inline explicite din sablon raman respectate de mPDF.
     */
    function pzdoc_pdf_apply_content_font_to_design(array $design, string $content): array
    {
        return $design;
    }
}

if (!function_exists('pzdoc_pdf_clean_relative_path')) {
    function pzdoc_pdf_clean_relative_path(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '' || strpos($path, '..') !== false) {
            return '';
        }
        return $path;
    }
}

if (!function_exists('pzdoc_pdf_image_data_uri')) {
    function pzdoc_pdf_image_data_uri(string $relativePath): string
    {
        $relativePath = pzdoc_pdf_clean_relative_path($relativePath);
        if ($relativePath === '') {
            return '';
        }

        $absolutePath = __DIR__ . '/' . $relativePath;
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return '';
        }

        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mime = 'image/png';
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $mime = 'image/jpeg';
        } elseif ($ext === 'gif') {
            $mime = 'image/gif';
        } elseif ($ext === 'svg') {
            $mime = 'image/svg+xml';
        } elseif ($ext === 'webp') {
            $mime = 'image/webp';
        }

        $data = @file_get_contents($absolutePath);
        if ($data === false) {
            return '';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}

if (!function_exists('pzdoc_pdf_autoload')) {
    function pzdoc_pdf_autoload(): void
    {
        $paths = [
            __DIR__ . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                require_once $path;
                return;
            }
        }
    }
}

if (!function_exists('pzdoc_pdf_engine_available')) {
    function pzdoc_pdf_engine_available(): bool
    {
        pzdoc_pdf_autoload();
        return class_exists('\\Mpdf\\Mpdf');
    }
}

if (!function_exists('pzdoc_pdf_design')) {
    function pzdoc_pdf_design(PDO $pdo, ?array $document = null): array
    {
        $settings = [];
        if (function_exists('pz_document_design_settings')) {
            $settings = pz_document_design_settings($pdo);
        } elseif (function_exists('pz_settings_get_all')) {
            $settings = pz_settings_get_all($pdo, []);
        }

        $logoPath = '';
        if (function_exists('pz_document_logo_path')) {
            $logoPath = pz_document_logo_path($pdo);
        }
        if ($logoPath === '') {
            $logoPath = pzdoc_pdf_clean_relative_path((string)($settings['document.header_logo_path'] ?? ''));
            if ($logoPath !== '' && !is_file(__DIR__ . '/' . $logoPath)) {
                $logoPath = '';
            }
        }

        $logoAlign = strtolower(trim((string)($settings['document.header_logo_align'] ?? 'center')));
        if (!in_array($logoAlign, ['left', 'center', 'right'], true)) {
            $logoAlign = 'center';
        }

        $footerText = trim((string)($settings['document.footer_text'] ?? ''));
        if ($footerText === '' && function_exists('pz_document_default_footer_text')) {
            $footerText = pz_document_default_footer_text($pdo);
        }

        $design = [
            'page_margin_top_mm' => pzdoc_pdf_float($settings['document.page_margin_top_mm'] ?? 18, 18, 5, 45),
            'page_margin_right_mm' => pzdoc_pdf_float($settings['document.page_margin_right_mm'] ?? 16, 16, 5, 45),
            'page_margin_bottom_mm' => pzdoc_pdf_float($settings['document.page_margin_bottom_mm'] ?? 18, 18, 5, 45),
            'page_margin_left_mm' => pzdoc_pdf_float($settings['document.page_margin_left_mm'] ?? 16, 16, 5, 45),
            'body_font_size_pt' => pzdoc_pdf_float($settings['document.body_font_size_pt'] ?? 10, 10, 7.5, 13),
            'line_height' => pzdoc_pdf_float($settings['document.line_height'] ?? 1.3, 1.3, 1.1, 1.8),
            'header_logo_enabled' => pzdoc_pdf_bool($settings['document.header_logo_enabled'] ?? '1', true),
            'header_logo_path' => $logoPath,
            'header_logo_text' => trim((string)($settings['document.header_logo_text'] ?? '')),
            'header_logo_align' => $logoAlign,
            'header_logo_width_mm' => pzdoc_pdf_float($settings['document.header_logo_width_mm'] ?? 60, 60, 20, 110),
            'header_logo_height_mm' => pzdoc_pdf_float($settings['document.header_logo_height_mm'] ?? 10, 10, 6, 35),
            'header_height_mm' => pzdoc_pdf_float($settings['document.header_height_mm'] ?? 16, 16, 0, 50),
            'footer_enabled' => pzdoc_pdf_bool($settings['document.footer_enabled'] ?? '1', true),
            'footer_text' => $footerText,
            'footer_height_mm' => pzdoc_pdf_float($settings['document.footer_height_mm'] ?? 14, 14, 0, 35),
            'footer_line_enabled' => pzdoc_pdf_bool($settings['document.footer_line_enabled'] ?? '1', true),
            'header_padding_top_mm' => 3.0,
            'margin_header_mm' => 4.0,
            'margin_footer_mm' => 4.0,
        ];

        if ($document && pzdoc_normalize_document_type((string)($document['document_type'] ?? '')) === 'proces_verbal'
            && pzdoc_pdf_bool($settings['document.pv_compact_enabled'] ?? '1', true)) {
            $design['page_margin_top_mm'] = pzdoc_pdf_float($settings['document.pv_page_margin_top_mm'] ?? 4, 4, 0, 25);
            $design['page_margin_bottom_mm'] = pzdoc_pdf_float($settings['document.pv_page_margin_bottom_mm'] ?? 7, 7, 0, 25);
            // Pentru PV, fontul de baza trebuie sa ramana predictibil si egal cu preview-ul.
            // Nu preluam automat 11pt din design global/wrapper HTML; implicit folosim 10pt.
            // Dacă vrei alt font pentru PV, se poate seta explicit document.pv_body_font_size_pt.
            $design['body_font_size_pt'] = pzdoc_pdf_float(
                (array_key_exists('document.pv_body_font_size_pt', $settings) && trim((string)$settings['document.pv_body_font_size_pt']) !== '')
                    ? $settings['document.pv_body_font_size_pt']
                    : 10,
                10,
                7.5,
                14
            );
            $design['line_height'] = pzdoc_pdf_float(
                (array_key_exists('document.pv_line_height', $settings) && trim((string)$settings['document.pv_line_height']) !== '')
                    ? $settings['document.pv_line_height']
                    : 1.28,
                1.28,
                1.05,
                1.8
            );
            $design['header_height_mm'] = pzdoc_pdf_float($settings['document.pv_header_height_mm'] ?? 8, 8, 0, 30);
            $design['footer_enabled'] = pzdoc_pdf_bool($settings['document.pv_footer_enabled'] ?? '0', false);
            $design['footer_height_mm'] = pzdoc_pdf_float($settings['document.pv_footer_height_mm'] ?? 0, 0, 0, 25);
            $design['header_padding_top_mm'] = 0.0;
            $design['margin_header_mm'] = 1.0;
            $design['margin_footer_mm'] = 2.0;
            $design['_pv_compact'] = true;
        }

        return $design;
    }
}


if (!function_exists('pzdoc_pdf_no_cache_headers')) {
    /**
     * Evita afișarea unui PDF vechi din cache-ul browserului.
     * PDF-ul trebuie regenerat live din aceeași sursa ca preview-ul.
     */
    function pzdoc_pdf_no_cache_headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-PestZone-Document-Render: live');
    }
}

if (!function_exists('pzdoc_pdf_safe_filename')) {
    function pzdoc_pdf_safe_filename(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'document';
        }

        $map = [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
        ];
        $name = strtr($name, $map);
        $name = preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', $name);
        $name = trim((string)$name, '._-');
        return $name !== '' ? $name : 'document';
    }
}

if (!function_exists('pzdoc_pdf_filename')) {
    function pzdoc_pdf_filename(array $document): string
    {
        $type = pzdoc_document_type_label((string)($document['document_type'] ?? 'document'));
        $number = trim((string)($document['document_number'] ?? ''));
        if ($number === '') {
            $number = 'draft_' . (int)($document['id'] ?? 0);
        }
        $client = trim((string)($document['client_name_snapshot'] ?? ''));

        $base = $type . '_' . $number;
        if ($client !== '') {
            $base .= '_' . $client;
        }

        return pzdoc_pdf_safe_filename($base) . '.pdf';
    }
}

if (!function_exists('pzdoc_pdf_footer_text')) {
    function pzdoc_pdf_footer_text(PDO $pdo, array $document, array $design): string
    {
        $text = trim((string)($design['footer_text'] ?? ''));
        if ($text === '' && function_exists('pz_document_default_footer_text')) {
            $text = pz_document_default_footer_text($pdo);
        }
        if ($text === '') {
            return '';
        }

        $tokens = pzdoc_build_tokens($pdo, $document);
        $text = pzdoc_apply_tokens($text, $tokens);

        $company = pzdoc_company_data($pdo);
        $replacements = [
            '{COMPANY}' => (string)($company['display_name'] ?? $company['legal_name'] ?? ''),
            '{COMPANY_NAME}' => (string)($company['display_name'] ?? $company['legal_name'] ?? ''),
            '{WEBSITE}' => (string)($company['website'] ?? ''),
            '{EMAIL}' => (string)($company['email'] ?? ''),
            '{PHONE}' => (string)($company['phone'] ?? ''),
            '{DOCUMENT_NUMBER}' => (string)($document['document_number'] ?? ''),
            '{DOCUMENT_DATE}' => pzdoc_format_date_display($document['document_date'] ?? ''),
            '{CONTRACT_NUMBER}' => (string)($document['document_number'] ?? ''),
        ];

        return strtr($text, $replacements);
    }
}

if (!function_exists('pzdoc_pdf_header_html')) {
    function pzdoc_pdf_header_html(PDO $pdo, array $design): string
    {
        if (empty($design['header_logo_enabled']) || (float)$design['header_height_mm'] <= 0) {
            return '';
        }

        $align = (string)($design['header_logo_align'] ?? 'center');
        $logoPath = (string)($design['header_logo_path'] ?? '');
        $logoData = $logoPath !== '' ? pzdoc_pdf_image_data_uri($logoPath) : '';
        $height = max(0, (float)$design['header_height_mm']);
        if ($height <= 0) {
            return '';
        }
        $paddingTop = max(0, (float)($design['header_padding_top_mm'] ?? 3));
        $logoWidth = max(10, (float)$design['header_logo_width_mm']);
        $logoHeight = max(5, (float)$design['header_logo_height_mm']);

        $inner = '';
        if ($logoData !== '') {
            $inner = '<img src="' . pzdoc_pdf_h($logoData) . '" style="max-width:' . $logoWidth . 'mm; max-height:' . $logoHeight . 'mm; width:auto; height:auto;">';
        } else {
            $logoText = trim((string)($design['header_logo_text'] ?? ''));
            if ($logoText === '' && function_exists('pz_company_logo_text')) {
                $logoText = pz_company_logo_text($pdo);
            }
            if ($logoText !== '') {
                $inner = '<div style="font-size:14pt; font-weight:bold; color:#10243e; letter-spacing:.04em;">' . pzdoc_pdf_h($logoText) . '</div>';
            }
        }

        if ($inner === '') {
            return '';
        }

        return '<div style="height:' . $height . 'mm; text-align:' . pzdoc_pdf_h($align) . '; padding-top:' . $paddingTop . 'mm; box-sizing:border-box;">' . $inner . '</div>';
    }
}

if (!function_exists('pzdoc_pdf_footer_html')) {
    function pzdoc_pdf_footer_html(PDO $pdo, array $document, array $design): string
    {
        if (empty($design['footer_enabled']) || (float)$design['footer_height_mm'] <= 0) {
            return '';
        }

        $text = trim(pzdoc_pdf_footer_text($pdo, $document, $design));
        if ($text === '') {
            return '';
        }

        $border = !empty($design['footer_line_enabled']) ? 'border-top:1px solid #e5e7eb;' : '';

        return '<div style="' . $border . ' padding-top:2mm; text-align:center; color:#64748b; font-size:7.5pt; line-height:1.25;">'
            . nl2br(pzdoc_pdf_h($text), false)
            . '</div>';
    }
}

if (!function_exists('pzdoc_pdf_global_css')) {
    function pzdoc_pdf_global_css(array $design, bool $forPdf = true): string
    {
        $fontSize = (float)$design['body_font_size_pt'];
        $lineHeight = (float)$design['line_height'];

        return '
<style>
    html {
        font-family: dejavusans, DejaVu Sans, Arial, sans-serif;
        font-size: ' . $fontSize . 'pt;
        line-height: ' . $lineHeight . ';
        color: #111827;
    }
    html, body {
        margin: 0;
        padding: 0;
    }
    body { background: #ffffff; font-family: dejavusans, DejaVu Sans, Arial, sans-serif; font-size: ' . $fontSize . 'pt; line-height: ' . $lineHeight . '; color: #111827; }
    .pzdoc-content { width: 100%; font-size: ' . $fontSize . 'pt; line-height: ' . $lineHeight . '; }
    .pzdoc-content h1 { font-size: 16pt; line-height: 1.18; margin: 0 0 5mm; color: #10243e; }
    .pzdoc-content h2 { font-size: 12pt; line-height: 1.2; margin: 5mm 0 2mm; color: #10243e; }
    .pzdoc-content h3 { font-size: 10.5pt; line-height: 1.2; margin: 4mm 0 2mm; color: #10243e; }
    .pzdoc-content p { margin: 0 0 2.2mm; }
    .pzdoc-content table { width: 100%; border-collapse: collapse; margin: 2.5mm 0 4mm; page-break-inside: auto; }
    .pzdoc-content tr { page-break-inside: avoid; page-break-after: auto; }
    .pzdoc-content th, .pzdoc-content td { border: 1px solid #d1d5db; padding: 1.7mm 2mm; vertical-align: top; }
    .pzdoc-content th { background: #f3f4f6; font-weight: bold; color: #10243e; }
    .pzdoc-content .doc-table { width: 100%; border-collapse: collapse; }
    .pzdoc-content .doc-small { font-size: 8.5pt; color: #4b5563; }
    .pzdoc-content .doc-muted { color: #64748b; }
    .pzdoc-content .doc-signatures { width: 100%; margin-top: 10mm; border: 0; }
    .pzdoc-content .doc-signatures td { border: 0; width: 50%; padding-top: 10mm; text-align: center; }
    .pzdoc-page-number { color: #64748b; }
</style>';
    }
}

if (!function_exists('pzdoc_pdf_wrap_content_html')) {
    /**
     * Invelis unic pentru continutul documentului.
     * Aceeasi functie este folosita pentru PDF, preview real si preview șablon,
     * ca stilurile din editor sa nu fie interpretate diferit intre ecran si PDF.
     */
    function pzdoc_pdf_wrap_content_html(array $design, string $content, bool $forPdf = true): string
    {
        $globalCss = pzdoc_pdf_global_css($design, $forPdf);

        return '<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
' . $globalCss . '
</head>
<body>
<div class="pzdoc-content">
' . $content . '
</div>
</body>
</html>';
    }
}

if (!function_exists('pzdoc_pdf_full_html')) {
    function pzdoc_pdf_full_html(PDO $pdo, int $documentId, ?int $templateId = null, bool $forPdf = true): string
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, true);
        $design = pzdoc_pdf_design($pdo, $document ?: null);

        // Sursa unica: continutul final al documentului, fara CSS alternativ.
        // CSS-ul PDF/preview este aplicat o singura data prin pzdoc_pdf_wrap_content_html().
        $content = pzdoc_pdf_prepare_html(pzdoc_pdf_convert_px_to_pt(pzdoc_render_document_html($pdo, $documentId, $templateId, false)));
        $design = pzdoc_pdf_apply_content_font_to_design($design, $content);

        return pzdoc_pdf_wrap_content_html($design, $content, $forPdf);
    }
}

if (!function_exists('pzdoc_pdf_create_mpdf')) {
    function pzdoc_pdf_create_mpdf(PDO $pdo, array $document, array $design)
    {
        pzdoc_pdf_autoload();
        if (!class_exists('\\Mpdf\\Mpdf')) {
            throw new RuntimeException('mPDF nu este instalat. Ruleaza composer install sau composer require mpdf/mpdf.');
        }

        $tempDir = __DIR__ . '/tmp/mpdf';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            $tempDir = sys_get_temp_dir();
        }

        $headerHtml = pzdoc_pdf_header_html($pdo, $design);
        $footerHtml = pzdoc_pdf_footer_html($pdo, $document, $design);

        $marginTop = (float)$design['page_margin_top_mm'];
        $marginBottom = (float)$design['page_margin_bottom_mm'];

        if ($headerHtml !== '') {
            $marginTop += (float)$design['header_height_mm'] + 1;
        }
        if ($footerHtml !== '') {
            $marginBottom += (float)$design['footer_height_mm'] + 1;
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => $marginTop,
            'margin_right' => (float)$design['page_margin_right_mm'],
            'margin_bottom' => $marginBottom,
            'margin_left' => (float)$design['page_margin_left_mm'],
            'margin_header' => (float)($design['margin_header_mm'] ?? 4),
            'margin_footer' => (float)($design['margin_footer_mm'] ?? 4),
            'default_font' => 'dejavusans',
            'tempDir' => $tempDir,
        ]);

        $mpdf->shrink_tables_to_fit = 1;
        $mpdf->keep_table_proportions = false;
        $mpdf->simpleTables = true;
        $mpdf->useSubstitutions = true;
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->SetTitle(pzdoc_document_type_label((string)($document['document_type'] ?? 'document')));
        $mpdf->SetAuthor('PestZone CRM');

        if ($headerHtml !== '') {
            $mpdf->SetHTMLHeader($headerHtml);
        }
        if ($footerHtml !== '') {
            $mpdf->SetHTMLFooter($footerHtml);
        }

        return $mpdf;
    }
}

if (!function_exists('pzdoc_pdf_string')) {
    function pzdoc_pdf_string(PDO $pdo, int $documentId, ?int $templateId = null): array
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, true);
        if (!$document) {
            throw new RuntimeException('Document inexistent.');
        }

        $design = pzdoc_pdf_design($pdo, $document);
        $html = pzdoc_pdf_prepare_html(pzdoc_pdf_convert_px_to_pt(pzdoc_pdf_full_html($pdo, $documentId, $templateId, true)));
        $filename = pzdoc_pdf_filename($document);
        $mpdf = pzdoc_pdf_create_mpdf($pdo, $document, $design);
        $mpdf->WriteHTML($html);

        return [
            'ok' => true,
            'filename' => $filename,
            'pdf' => $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN),
            'html' => $html,
            'document' => $document,
        ];
    }
}

if (!function_exists('pzdoc_pdf_stream_document')) {
    function pzdoc_pdf_stream_document(PDO $pdo, int $documentId, ?int $templateId = null, bool $download = false): void
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, true);
        if (!$document) {
            http_response_code(404);
            exit('Document inexistent.');
        }

        $design = pzdoc_pdf_design($pdo, $document);
        $html = pzdoc_pdf_prepare_html(pzdoc_pdf_convert_px_to_pt(pzdoc_pdf_full_html($pdo, $documentId, $templateId, true)));
        $filename = pzdoc_pdf_filename($document);
        $mpdf = pzdoc_pdf_create_mpdf($pdo, $document, $design);
        $mpdf->WriteHTML($html);
        pzdoc_pdf_no_cache_headers();
        $mpdf->Output($filename, $download ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);
        exit;
    }
}

if (!function_exists('pzdoc_pdf_save_document')) {
    function pzdoc_pdf_save_document(PDO $pdo, int $documentId, ?int $templateId = null, ?string $targetDir = null): array
    {
        $result = pzdoc_pdf_string($pdo, $documentId, $templateId);
        $targetDir = $targetDir ? rtrim($targetDir, '/\\') : (__DIR__ . '/tmp/documents');
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }
        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            throw new RuntimeException('Folderul pentru PDF nu poate fi scris.');
        }

        $filename = (string)$result['filename'];
        $path = $targetDir . '/' . $filename;
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'pdf';
        $i = 1;
        while (is_file($path)) {
            $path = $targetDir . '/' . $base . '_' . $i . '.' . $ext;
            $i++;
        }

        file_put_contents($path, $result['pdf']);
        $result['path'] = $path;
        $result['filename'] = basename($path);
        return $result;
    }
}

if (!function_exists('pzdoc_pdf_browser_preview_html')) {
    function pzdoc_pdf_browser_preview_html(PDO $pdo, int $documentId, ?int $templateId = null): string
    {
        pzdoc_require_schema($pdo);
        $document = pzdoc_get_document($pdo, $documentId, true);
        if (!$document) {
            throw new RuntimeException('Document inexistent.');
        }

        $design = pzdoc_pdf_design($pdo, $document);

        // Preview-ul folosește exact aceeași sursa de continut ca PDF-ul:
        // șablon -> tokeni -> semnătura/ștampila -> CSS PDF. Nu mai injectam CSS separat.
        $content = pzdoc_pdf_prepare_html(pzdoc_pdf_convert_px_to_pt(pzdoc_render_document_html($pdo, $documentId, $templateId, false)));
        $design = pzdoc_pdf_apply_content_font_to_design($design, $content);
        $header = pzdoc_pdf_header_html($pdo, $design);
        $footer = pzdoc_pdf_footer_html($pdo, $document, $design);
        $globalCss = pzdoc_pdf_global_css($design, false);

        $pageTop = (float)$design['page_margin_top_mm'];
        $pageRight = (float)$design['page_margin_right_mm'];
        $pageBottom = (float)$design['page_margin_bottom_mm'];
        $pageLeft = (float)$design['page_margin_left_mm'];

        return '<div class="pzdoc-preview-shell">
<style>
.pzdoc-preview-shell{background:#eef2f7;padding:18px;border-radius:18px;overflow:auto;}
.pzdoc-preview-a4{width:210mm;min-height:297mm;margin:0 auto;background:#fff;box-shadow:0 12px 30px rgba(16,36,62,.16);box-sizing:border-box;padding:' . $pageTop . 'mm ' . $pageRight . 'mm ' . $pageBottom . 'mm ' . $pageLeft . 'mm;}
.pzdoc-preview-footer{margin-top:12mm;}
@media(max-width:900px){.pzdoc-preview-shell{padding:10px}.pzdoc-preview-a4{width:100%;min-height:auto;padding:14px;}}
</style>
' . $globalCss . '
<div class="pzdoc-preview-a4">'
            . $header
            . '<div class="pzdoc-content">' . $content . '</div>'
            . ($footer !== '' ? '<div class="pzdoc-preview-footer">' . $footer . '</div>' : '')
            . '</div></div>';
    }
}

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    $available = pzdoc_pdf_engine_available();
    echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><title>Document PDF engine</title></head><body style="font-family:Arial,sans-serif;padding:24px;line-height:1.5;">';
    echo '<h2>Motor PDF documente incarcat corect.</h2>';
    echo '<p>mPDF: <strong>' . ($available ? 'detectat' : 'nedetectat') . '</strong></p>';
    if (!$available) {
        echo '<p style="color:#b91c1c;font-weight:bold;">Pentru generare PDF, verifica folderul vendor/ sau ruleaza composer install.</p>';
    }
    echo '</body></html>';
}
