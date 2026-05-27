<?php
/**
 * Emma CRM - document_design.php
 * Setări globale A4 — o singura dimensiune pentru TOATE documentele (PV, oferte, contracte).
 * Fara footer (dezactivat global). Defaults: NARROW (13 mm).
 */

require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/lib/settings_lib.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Acces permis doar administratorului.');
}

function pzdd_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pzdd_num($v, float $def, float $min, float $max): string
{
    $v = str_replace(',', '.', trim((string)$v));
    $n = ($v !== '' && is_numeric($v)) ? (float)$v : $def;
    if ($n < $min) $n = $min;
    if ($n > $max) $n = $max;
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}
function pzdd_bool($v): string { return !empty($v) ? '1' : '0'; }
function pzdd_align($v): string
{
    $v = strtolower(trim((string)$v));
    return in_array($v, ['left','center','right'], true) ? $v : 'center';
}

pz_settings_ensure_schema($pdo);
$settings = pz_document_design_settings($pdo);
$company = pz_company_settings($pdo);
$error = '';
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();

    // Setări globale A4 — o singura dimensiune pentru toate documentele
    $pageTopVal    = pzdd_num($_POST['page_top'] ?? '', 13, 5, 35);
    $pageRightVal  = pzdd_num($_POST['page_right'] ?? '', 13, 5, 35);
    $pageBottomVal = pzdd_num($_POST['page_bottom'] ?? '', 13, 5, 35);
    $pageLeftVal   = pzdd_num($_POST['page_left'] ?? '', 13, 5, 35);
    $fontSizeVal   = pzdd_num($_POST['font_size'] ?? '', 10.5, 8, 13);
    $lineHeightVal = pzdd_num($_POST['line_height'] ?? '', 1.35, 1.05, 1.75);
    $headerHeightVal = pzdd_num($_POST['header_height'] ?? '', 18, 0, 40);

    $values = [
        // A4 global
        'document.page_margin_top_mm'    => $pageTopVal,
        'document.page_margin_right_mm'  => $pageRightVal,
        'document.page_margin_bottom_mm' => $pageBottomVal,
        'document.page_margin_left_mm'   => $pageLeftVal,
        'document.body_font_size_pt'     => $fontSizeVal,
        'document.line_height'           => $lineHeightVal,

        // Antet / logo
        'document.header_logo_enabled'   => pzdd_bool($_POST['logo_enabled'] ?? ''),
        'document.header_logo_align'     => pzdd_align($_POST['logo_align'] ?? 'center'),
        'document.header_logo_width_mm'  => pzdd_num($_POST['logo_width'] ?? '', 60, 25, 90),
        'document.header_logo_height_mm' => pzdd_num($_POST['logo_height'] ?? '', 14, 6, 28),
        'document.header_height_mm'      => $headerHeightVal,

        // Footer DEZACTIVAT GLOBAL — nu se mai expune in UI
        'document.footer_enabled'        => '0',
        'document.footer_text'           => '',
        'document.footer_height_mm'      => '0',
        'document.footer_line_enabled'   => '0',

        // PV folosește ACELEASI setari ca celelalte documente — sincronizam si dezactivam compact
        'document.pv_compact_enabled'        => '0',
        'document.pv_page_margin_top_mm'     => $pageTopVal,
        'document.pv_page_margin_bottom_mm'  => $pageBottomVal,
        'document.pv_header_height_mm'       => $headerHeightVal,
        'document.pv_footer_enabled'         => '0',
        'document.pv_footer_height_mm'       => '0',
        'document.pv_body_font_size_pt'      => $fontSizeVal,
        'document.pv_line_height'            => $lineHeightVal,

        // Curatare flag-uri vechi
        'document.header_logo_text'      => '',
        'document.header_company_text'   => '',
        'document.header_strip_enabled'  => '0',

        // Pastram path-urile existente dacă nu se incarca alte fișiere
        'document.header_logo_path'      => trim((string)($settings['document.header_logo_path'] ?? '')),
        'document.company_stamp_path'    => trim((string)($settings['document.company_stamp_path'] ?? '')),
        'document.company_stamp_width_mm' => pzdd_num($_POST['stamp_width'] ?? '', 36, 18, 60),
        'document.company_stamp_height_mm' => pzdd_num($_POST['stamp_height'] ?? '', 36, 18, 60),
    ];

    if (!empty($_POST['remove_logo'])) {
        $values['document.header_logo_path'] = '';
    }

    if (!empty($_POST['remove_stamp'])) {
        $values['document.company_stamp_path'] = '';
    }

    // Upload logo
    if (isset($_FILES['logo_file']) && is_array($_FILES['logo_file']) && (int)($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ((int)$_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Logo-ul nu a putut fi incarcat.';
        } elseif ((int)($_FILES['logo_file']['size'] ?? 0) > 2 * 1024 * 1024) {
            $error = 'Logo-ul este prea mare. Maxim 2 MB.';
        } else {
            $tmp = (string)$_FILES['logo_file']['tmp_name'];
            $ext = strtolower(pathinfo((string)$_FILES['logo_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png','jpg','jpeg','webp'], true) || !@getimagesize($tmp)) {
                $error = 'Format invalid. Acceptat: PNG, JPG, WEBP.';
            } else {
                $dir = __DIR__ . '/uploads';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                if (!is_writable($dir)) {
                    $error = 'Folderul uploads nu poate fi scris.';
                } else {
                    $file = 'document_logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
                    if (!move_uploaded_file($tmp, $dir . '/' . $file)) {
                        $error = 'Logo-ul nu a putut fi salvat.';
                    } else {
                        $values['document.header_logo_path'] = 'uploads/' . $file;
                        $values['document.header_logo_enabled'] = '1';
                    }
                }
            }
        }
    }

    // Upload ștampila firmei
    if (isset($_FILES['stamp_file']) && is_array($_FILES['stamp_file']) && (int)($_FILES['stamp_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ((int)$_FILES['stamp_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Ștampila nu a putut fi incarcata.';
        } elseif ((int)($_FILES['stamp_file']['size'] ?? 0) > 2 * 1024 * 1024) {
            $error = 'Ștampila este prea mare. Maxim 2 MB.';
        } else {
            $tmp = (string)$_FILES['stamp_file']['tmp_name'];
            $ext = strtolower(pathinfo((string)$_FILES['stamp_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png','jpg','jpeg','webp'], true) || !@getimagesize($tmp)) {
                $error = 'Format ștampila invalid. Acceptat: PNG (cu fundal transparent), JPG, WEBP.';
            } else {
                $dir = __DIR__ . '/uploads';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                if (!is_writable($dir)) {
                    $error = 'Folderul uploads nu poate fi scris.';
                } else {
                    $file = 'company_stamp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
                    if (!move_uploaded_file($tmp, $dir . '/' . $file)) {
                        $error = 'Ștampila nu a putut fi salvata.';
                    } else {
                        $values['document.company_stamp_path'] = 'uploads/' . $file;
                    }
                }
            }
        }
    }

    if ($error === '') {
        try {
            pz_settings_set_many($pdo, $values);
            $settings = pz_document_design_settings($pdo);
            $success = true;
        } catch (Throwable $e) {
            error_log('Emma document_design save error: ' . $e->getMessage());
            $error = 'Setările nu au putut fi salvate.';
        }
    }
}

// Defaults NARROW: 13 mm pe toate marginile
$pageTop      = pzdd_num($settings['document.page_margin_top_mm'] ?? 13, 13, 5, 35);
$pageRight    = pzdd_num($settings['document.page_margin_right_mm'] ?? 13, 13, 5, 35);
$pageBottom   = pzdd_num($settings['document.page_margin_bottom_mm'] ?? 13, 13, 5, 35);
$pageLeft     = pzdd_num($settings['document.page_margin_left_mm'] ?? 13, 13, 5, 35);
$fontSize     = pzdd_num($settings['document.body_font_size_pt'] ?? 10.5, 10.5, 8, 13);
$lineHeight   = pzdd_num($settings['document.line_height'] ?? 1.35, 1.35, 1.05, 1.75);
$logoEnabled  = (string)($settings['document.header_logo_enabled'] ?? '1') !== '0';
$logoPath     = function_exists('pz_document_logo_path') ? pz_document_logo_path($pdo) : trim((string)($settings['document.header_logo_path'] ?? ''));
$logoAlign    = pzdd_align($settings['document.header_logo_align'] ?? 'center');
$logoWidth    = pzdd_num($settings['document.header_logo_width_mm'] ?? 60, 60, 25, 90);
$logoHeight   = pzdd_num($settings['document.header_logo_height_mm'] ?? 14, 14, 6, 28);
$headerHeight = pzdd_num($settings['document.header_height_mm'] ?? 18, 18, 0, 40);
$stampPath    = function_exists('pz_document_stamp_path') ? pz_document_stamp_path($pdo) : trim((string)($settings['document.company_stamp_path'] ?? ''));
$stampWidth   = pzdd_num($settings['document.company_stamp_width_mm'] ?? 36, 36, 18, 60);
$stampHeight  = pzdd_num($settings['document.company_stamp_height_mm'] ?? 36, 36, 18, 60);
$companyName  = trim((string)($company['company.display_name'] ?? $company['company.legal_name'] ?? 'EUROPREST'));
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Design documente - <?= h(pz_app_name()) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.dd-top{padding:12px 20px;display:flex;justify-content:space-between;gap:12px;align-items:center}
.dd-wrap{max-width:1260px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) 420px;gap:16px}
.dd-card{background:#fff;border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);padding:18px}
.dd-head h1{margin:0;font-size:26px}
.dd-head p{margin:6px 0 16px;color:var(--muted);font-weight:700}
.dd-section{border:1px solid var(--border);border-radius:18px;padding:14px;margin-bottom:14px}
.dd-section h3{margin:0 0 12px;font-size:16px}
.dd-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.dd-field{display:flex;flex-direction:column;gap:6px}
.dd-field.full{grid-column:1/-1}
.dd-field label{font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.dd-field input,.dd-field textarea,.dd-field select{width:100%;border:1px solid var(--border);border-radius:12px;min-height:42px;padding:10px 12px;font-weight:700;background:#fff}
.dd-check{display:flex;gap:9px;align-items:center;font-weight:900}
.dd-check input{width:18px;height:18px}
.dd-help{color:var(--muted);font-weight:700;line-height:1.45}
.notice{max-width:1260px;margin:0 auto 14px;border-radius:14px;padding:12px 14px;font-weight:900}
.notice.ok{background:var(--success-soft);color:var(--success)}
.notice.err{background:var(--danger-soft);color:var(--danger)}
.dd-preview{position:sticky;top:14px}
.a4-box{background:#eef2f7;border:1px solid var(--border);border-radius:18px;padding:14px;overflow:hidden}
.a4-page{width:100%;aspect-ratio:210/297;background:#fff;border:1px solid #d9e1ea;border-radius:8px;box-shadow:0 12px 28px rgba(16,36,62,.14);padding:calc(<?= pzdd_h($pageTop) ?>px / 1.6) calc(<?= pzdd_h($pageRight) ?>px / 1.6) calc(<?= pzdd_h($pageBottom) ?>px / 1.6) calc(<?= pzdd_h($pageLeft) ?>px / 1.6);display:flex;flex-direction:column}
.pv-header{height:<?= max(0, (float)$headerHeight) * 1.3 ?>px;margin-bottom:10px;display:flex;align-items:center;justify-content:<?= $logoAlign === 'left' ? 'flex-start' : ($logoAlign === 'right' ? 'flex-end' : 'center') ?>}
.pv-logo{max-width:<?= (float)$logoWidth * 1.35 ?>px;max-height:<?= (float)$logoHeight * 1.35 ?>px;object-fit:contain}
.pv-logo-text{font-weight:900;color:#10243e}
.pv-title{text-align:center;font-weight:900;margin:6px 0 12px}
.pv-line{height:7px;background:#e5e7eb;border-radius:99px;margin:5px 0}
.pv-line.short{width:68%}
@media(max-width:1040px){.dd-wrap{grid-template-columns:1fr}.dd-preview{position:static}.dd-top{align-items:stretch;flex-direction:column}}
@media(max-width:720px){.dd-grid{grid-template-columns:1fr}.dd-card{padding:12px}}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('settings', true); ?>
    <main class="main">
        <div class="content">
            <?php pz_page_header([
                'back'     => ['href' => 'settings.php', 'label' => 'Înapoi la setări'],
                'kicker'   => 'ADMINISTRARE · DOCUMENTE',
                'title'    => 'Design documente',
                'subtitle' => 'O singură dimensiune pentru toate documentele (procese verbale, oferte, contracte). Fără footer.',
                'actions'  => [[
                    'label'   => 'Salvează designul',
                    'icon'    => 'ti-device-floppy',
                    'variant' => 'primary',
                    'type'    => 'submit',
                    'form'    => 'designForm',
                ]],
            ]); ?>
            <?php if ($success): ?><div class="notice ok">Designul documentelor a fost salvat.</div><?php endif; ?>
            <?php if ($error): ?><div class="notice err"><?= pzdd_h($error) ?></div><?php endif; ?>
            <div class="dd-wrap">
                <form id="designForm" method="post" enctype="multipart/form-data" class="dd-card">
                    <?= csrf_field() ?>
<?php /* dd-head eliminat — info-ul e deja în pz_page_header de mai sus. */ ?>

                    <div class="dd-section"><h3>Pagina A4 (narrow)</h3><div class="dd-grid">
                        <div class="dd-field"><label>Margine sus, mm</label><input name="page_top" type="number" step="0.5" min="5" max="35" value="<?= pzdd_h($pageTop) ?>"></div>
                        <div class="dd-field"><label>Margine dreapta, mm</label><input name="page_right" type="number" step="0.5" min="5" max="35" value="<?= pzdd_h($pageRight) ?>"></div>
                        <div class="dd-field"><label>Margine jos, mm</label><input name="page_bottom" type="number" step="0.5" min="5" max="35" value="<?= pzdd_h($pageBottom) ?>"></div>
                        <div class="dd-field"><label>Margine stanga, mm</label><input name="page_left" type="number" step="0.5" min="5" max="35" value="<?= pzdd_h($pageLeft) ?>"></div>
                        <div class="dd-field"><label>Font text, pt</label><input name="font_size" type="number" step="0.1" min="8" max="13" value="<?= pzdd_h($fontSize) ?>"></div>
                        <div class="dd-field"><label>Spatiere randuri</label><input name="line_height" type="number" step="0.05" min="1.05" max="1.75" value="<?= pzdd_h($lineHeight) ?>"></div>
                        <div class="dd-field full"><span class="dd-help">Standard <strong>narrow</strong>: 13 mm pe toate marginile, font 10.5 pt, spatiere 1.35. Valorile se aplica identic pentru PV, oferte si contracte.</span></div>
                    </div></div>

                    <div class="dd-section"><h3>Antet / logo</h3><div class="dd-grid">
                        <div class="dd-field full"><label class="dd-check"><input type="checkbox" name="logo_enabled" value="1" <?= $logoEnabled ? 'checked' : '' ?>> Afiseaza logo in antet</label></div>
                        <div class="dd-field full"><label>Logo</label><input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp"><span class="dd-help">Optional. Acceptat PNG, JPG, WEBP, maxim 2 MB.</span></div>
                        <?php if ($logoPath): ?><div class="dd-field full"><label class="dd-check"><input type="checkbox" name="remove_logo" value="1"> Șterge logo-ul curent</label></div><?php endif; ?>
                        <div class="dd-field"><label>Pozitie logo</label><select name="logo_align"><option value="left" <?= $logoAlign==='left'?'selected':'' ?>>Stanga</option><option value="center" <?= $logoAlign==='center'?'selected':'' ?>>Centru</option><option value="right" <?= $logoAlign==='right'?'selected':'' ?>>Dreapta</option></select></div>
                        <div class="dd-field"><label>Spatiu antet, mm</label><input name="header_height" type="number" step="0.5" min="0" max="40" value="<?= pzdd_h($headerHeight) ?>"></div>
                        <div class="dd-field"><label>Latime logo, mm</label><input name="logo_width" type="number" step="0.5" min="25" max="90" value="<?= pzdd_h($logoWidth) ?>"></div>
                        <div class="dd-field"><label>Inaltime logo, mm</label><input name="logo_height" type="number" step="0.5" min="6" max="28" value="<?= pzdd_h($logoHeight) ?>"></div>
                    </div></div>

                    <div class="dd-section"><h3>Ștampila firmei</h3><div class="dd-grid">
                        <div class="dd-field full"><span class="dd-help">Ștampila apare pe procesele verbale doar cand este bifata explicit (operatorii din teren primesc bifa automat). Recomandat: PNG cu fundal transparent, ~400x400px.</span></div>
                        <div class="dd-field full"><label>Imagine ștampila</label><input type="file" name="stamp_file" accept="image/png,image/jpeg,image/webp"><span class="dd-help">Optional. Acceptat PNG, JPG, WEBP, maxim 2 MB.</span></div>
                        <?php if ($stampPath): ?>
                            <div class="dd-field"><label>Ștampila curenta</label><div style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:10px;display:flex;align-items:center;justify-content:center;min-height:80px;"><img src="<?= pzdd_h($stampPath) ?>" alt="Ștampila" style="max-width:90px;max-height:90px;object-fit:contain;"></div></div>
                            <div class="dd-field"><label>&nbsp;</label><label class="dd-check"><input type="checkbox" name="remove_stamp" value="1"> Șterge ștampila curenta</label></div>
                        <?php endif; ?>
                        <div class="dd-field"><label>Latime ștampila, mm</label><input name="stamp_width" type="number" step="0.5" min="18" max="60" value="<?= pzdd_h($stampWidth) ?>"></div>
                        <div class="dd-field"><label>Inaltime ștampila, mm</label><input name="stamp_height" type="number" step="0.5" min="18" max="60" value="<?= pzdd_h($stampHeight) ?>"></div>
                    </div></div>
                </form>

                <aside class="dd-card dd-preview">
                    <h2>Previzualizare orientativa</h2>
                    <p class="dd-help">PDF-ul real folosește aceleasi setari globale pentru toate tipurile de documente. Fara footer.</p>
                    <div class="a4-box"><div class="a4-page">
                        <div class="pv-header">
                            <?php if ($logoEnabled && $logoPath): ?><img class="pv-logo" src="<?= pzdd_h($logoPath) ?>" alt="Logo"><?php else: ?><div class="pv-logo-text"><?= pzdd_h($companyName) ?></div><?php endif; ?>
                        </div>
                        <div class="pv-title">Document A4</div>
                        <div class="pv-line"></div><div class="pv-line"></div><div class="pv-line short"></div><div class="pv-line"></div><div class="pv-line short"></div><div class="pv-line"></div>
                    </div></div>
                </aside>
            </div>
        </div>
    </main>
</div>
</body>
</html>
