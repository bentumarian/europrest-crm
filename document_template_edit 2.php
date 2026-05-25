<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/document_core.php';
require_once __DIR__ . '/document_tokens.php';
require_once __DIR__ . '/document_pdf_engine.php';

if (!is_admin()) {
    header('Location: calendar.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| PestZone - editor șablon document
|--------------------------------------------------------------------------
| Editor unic pentru șabloane folosite de motorul nou:
| - oferta
| - contract
| - proces verbal
|
| Reguli:
| - folosește document_templates.is_active
| - folosește content_html ca sursa principala
| - un șablon implicit trebuie sa ramana activ
| - fara dependente de vechiul document_engine/document_render_lib
|--------------------------------------------------------------------------
*/

pzdoc_require_schema($pdo);

function dte_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dte_types(): array
{
    return [
        'oferta' => 'Oferta',
        'contract' => 'Contract',
        'act_aditional' => 'Act adițional',
        'proces_verbal' => 'Proces verbal',
    ];
}

function dte_type_label(string $type): string
{
    $type = pzdoc_normalize_document_type($type);
    $types = dte_types();
    return $types[$type] ?? 'Document';
}

function dte_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function dte_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '_', $text) ?: 'sablon';
    $text = trim($text, '_');
    return $text !== '' ? substr($text, 0, 160) : 'sablon';
}

function dte_unique_slug(PDO $pdo, string $base, int $excludeId = 0): string
{
    $base = dte_slugify($base);
    $slug = $base;
    $i = 2;

    $sql = 'SELECT COUNT(*) FROM document_templates WHERE slug = ?';
    $paramsExtra = [];
    if ($excludeId > 0) {
        $sql .= ' AND id <> ?';
        $paramsExtra[] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    while (true) {
        $params = [$slug];
        foreach ($paramsExtra as $p) {
            $params[] = $p;
        }
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '_' . $i;
        $i++;
    }
}

function dte_get_template(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM document_templates WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dte_has_default(PDO $pdo, string $type, int $excludeId = 0): bool
{
    $sql = 'SELECT COUNT(*) FROM document_templates WHERE document_type = ? AND is_default = 1 AND is_active = 1';
    $params = [$type];
    if ($excludeId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function dte_set_default(PDO $pdo, int $templateId, string $type): void
{
    $stmt = $pdo->prepare('UPDATE document_templates SET is_default = 0 WHERE document_type = ?');
    $stmt->execute([$type]);

    $stmt = $pdo->prepare('UPDATE document_templates SET is_default = 1, is_active = 1 WHERE id = ?');
    $stmt->execute([$templateId]);
}

function dte_demo_tokens(): array
{
    $itemsTable = '<table class="pzdoc-table"><thead><tr><th>Serviciu</th><th>Descriere</th><th>Cant.</th><th>Preț</th><th>Total</th></tr></thead><tbody>'
        . '<tr><td>Dezinsectie</td><td>Tratament general spatii interioare</td><td>1</td><td>450.00</td><td>450.00</td></tr>'
        . '<tr><td>Deratizare</td><td>Monitorizare si completare statii</td><td>1</td><td>350.00</td><td>350.00</td></tr>'
        . '</tbody></table>';

    $materialsTable = '<table class="pzdoc-table"><thead><tr><th>Produs</th><th>Cantitate</th><th>Lot</th><th>Metoda</th><th>Zona</th></tr></thead><tbody>'
        . '<tr><td>Biocid demo</td><td>250 ml</td><td>LOT123</td><td>Pulverizare</td><td>Interior</td></tr>'
        . '</tbody></table>';

    return [
        'document_number' => 'PV 12/06.05.2026',
        'document_date' => '06.05.2026',
        'document_time' => '10:30',
        'document_type_label' => 'Proces verbal',
        'document_status' => 'Draft',
        'document_title' => 'Document demo',
        'subtotal' => '800.00',
        'vat_percent' => '21',
        'vat_amount' => '168.00',
        'document_total' => '968.00',
        'currency' => 'RON',

        'company_block' => '<strong>EUROPREST TEAM SRL</strong><br>CUI RO000000<br>Adresa societate demo<br>Tel: 0700 000 000<br>Email: office@example.ro',
        'company_name' => 'EUROPREST TEAM SRL',
        'company_legal_name' => 'EUROPREST TEAM SRL',
        'company_cui' => 'RO000000',
        'company_reg_com' => 'J13/000/0000',
        'company_address' => 'Adresa societate demo',
        'company_bank_name' => 'Banca demo',
        'company_bank_account' => 'RO00 BANK 0000 0000 0000 0000',
        'company_email' => 'office@example.ro',
        'company_phone' => '0700 000 000',
        'company_website' => 'www.pestzone.ro',
        'company_representative' => 'Administrator demo',
        'company_representative_role' => 'Administrator',
        'company_authorizations' => 'Autorizatii DDD valabile conform legislatiei aplicabile',
        'company_provider_role' => 'Prestator',

        'client_block' => '<strong>CLIENT DEMO SRL</strong><br>CUI RO12345678<br>Reg. Com.: J00/000/2026<br>Adresa: Bucuresti, Str. Exemplu nr. 1<br>Reprezentant: Popescu Ion',
        'client_name' => 'CLIENT DEMO SRL',
        'client_cui' => 'RO12345678',
        'client_identifier' => 'RO12345678',
        'client_registry' => 'J00/000/2026',
        'client_address' => 'Bucuresti, Str. Exemplu nr. 1',
        'client_representative' => 'Popescu Ion',
        'client_email' => 'client@example.ro',
        'client_phone' => '0700 111 111',

        'location_block' => '<strong>Punct de lucru demo</strong><br>Bucuresti, Str. Punctului nr. 10<br>Contact: Ionescu Maria<br>Telefon: 0700 222 222',
        'location_name' => 'Punct de lucru demo',
        'location_address' => 'Bucuresti, Str. Punctului nr. 10',
        'location_contact' => 'Ionescu Maria',
        'location_phone' => '0700 222 222',
        'treated_areas' => 'Bucatarie, depozit marfa, hol acces si zona perimetrala.',
        'zone_tratate' => 'Bucatarie, depozit marfa, hol acces si zona perimetrala.',
        'pv_treated_areas' => 'Bucatarie, depozit marfa, hol acces si zona perimetrala.',

        'items_table' => $itemsTable,
        'services_table' => $itemsTable,
        'materials_table' => $materialsTable,
        'biocides_table' => $materialsTable,
        'avize_sanitare_url' => 'https://app.pestzone.ro/avize_sanitare.php',
        'avize_sanitare_link' => '<a href="https://app.pestzone.ro/avize_sanitare.php" target="_blank" rel="noopener">Descarcă avizele sanitare ale produselor</a>',
        'product_avize_url' => 'https://app.pestzone.ro/avize_sanitare.php',
        'product_avize_link' => '<a href="https://app.pestzone.ro/avize_sanitare.php" target="_blank" rel="noopener">Descarcă avizele sanitare ale produselor</a>',
        'materials_safety' => '<p>Masuri de siguranta demo: aerisire, evitarea contactului direct, respectarea recomandarilor prestatorului.</p>',
        'safety_measures' => '<p>Masuri de siguranta demo: aerisire, evitarea contactului direct, respectarea recomandarilor prestatorului.</p>',

        'notes' => 'Observații generale demo.',
        'executor_notes' => 'Lucrarea a fost executata conform procedurii interne.',
        'recommendations' => 'Beneficiarul va mentine igiena spatiului si va anunta orice activitate ulterioara.',
        'client_notes' => 'Fara observatii din partea beneficiarului.',
        'internal_notes' => 'Nota interna demo.',

        'contract_start_date' => '06.05.2026',
        'contract_end_date' => '06.05.2027',
        'contract_value' => '12,000.00 RON',
        'auto_renewal_text' => 'Contractul se reinnoieste automat conform clauzelor agreate.',
        'payment_terms' => 'Plata in 15 zile de la emiterea facturii.',
        'valid_until' => '20.05.2026',
    ];
}

function dte_render_preview_html(PDO $pdo, string $content, string $documentType): string
{
    $documentType = pzdoc_normalize_document_type($documentType);
    $fakeDocument = ['document_type' => $documentType];
    $design = pzdoc_pdf_design($pdo, $fakeDocument);
    $html = pzdoc_apply_tokens($content, dte_demo_tokens());
    $html = pzdoc_pdf_convert_px_to_pt($html);

    // Preview-ul șablonului folosește acelasi CSS/global wrapper ca PDF-ul final.
    return pzdoc_pdf_wrap_content_html($design, $html, false);
}

function dte_pdf_css_for_type(PDO $pdo, string $documentType): string
{
    $documentType = pzdoc_normalize_document_type($documentType);
    $design = pzdoc_pdf_design($pdo, ['document_type' => $documentType]);
    return pzdoc_pdf_global_css($design, false);
}

$templateId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$template = dte_get_template($pdo, $templateId);
$error = '';
$flash = '';

$initialType = pzdoc_normalize_document_type((string)($_GET['type'] ?? ($template['document_type'] ?? 'oferta')));
if (!array_key_exists($initialType, dte_types())) {
    $initialType = 'oferta';
}

$form = [
    'id' => $template ? (int)$template['id'] : 0,
    'document_type' => $template ? (string)$template['document_type'] : $initialType,
    'name' => $template ? (string)$template['name'] : ('Șablon ' . dte_type_label($initialType)),
    'slug' => $template ? (string)($template['slug'] ?? '') : '',
    'description' => $template ? (string)($template['description'] ?? '') : 'Șablon creat in motorul nou de documente.',
    'content_html' => $template ? (string)($template['content_html'] ?? '') : pzdoc_default_template_content($initialType),
    'is_active' => $template ? (int)($template['is_active'] ?? 1) : 1,
    'is_default' => $template ? (int)($template['is_default'] ?? 0) : 0,
];

if (!$template && !dte_has_default($pdo, $initialType)) {
    $form['is_default'] = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? 'save');

    $postType = pzdoc_normalize_document_type((string)($_POST['document_type'] ?? 'oferta'));
    if (!array_key_exists($postType, dte_types())) {
        $postType = 'oferta';
    }

    $form = [
        'id' => (int)($_POST['id'] ?? 0),
        'document_type' => $postType,
        'name' => trim((string)($_POST['name'] ?? '')),
        'slug' => trim((string)($_POST['slug'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'content_html' => (string)($_POST['content_html'] ?? ''),
        'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        'is_default' => !empty($_POST['is_default']) ? 1 : 0,
    ];

    try {
        if ($form['name'] === '') {
            throw new RuntimeException('Completează numele șablonului.');
        }

        if (trim($form['content_html']) === '') {
            throw new RuntimeException('Continutul șablonului nu poate fi gol.');
        }

        if ($form['is_default']) {
            $form['is_active'] = 1;
        }

        $existing = $form['id'] > 0 ? dte_get_template($pdo, $form['id']) : null;
        if ($form['id'] > 0 && !$existing) {
            throw new RuntimeException('Șablonul nu a fost gasit.');
        }

        if ($existing && (int)($existing['is_default'] ?? 0) === 1) {
            // Un șablon implicit rămâne implicit până cand alegi altul din lista sau bifezi alt șablon ca implicit.
            $form['is_default'] = 1;
            $form['is_active'] = 1;
        }

        if (!$form['is_default'] && !dte_has_default($pdo, $form['document_type'], $form['id'])) {
            // Dacă nu există alt implicit pentru tipul acesta, acesta devine implicit automat.
            $form['is_default'] = 1;
            $form['is_active'] = 1;
        }

        $slugBase = $form['slug'] !== '' ? $form['slug'] : ($form['document_type'] . '_' . $form['name']);
        $form['slug'] = dte_unique_slug($pdo, $slugBase, $form['id']);

        $pdo->beginTransaction();

        if ($form['id'] > 0) {
            $stmt = $pdo->prepare("\n                UPDATE document_templates\n                SET document_type = ?, name = ?, slug = ?, description = ?, content_html = ?, is_active = ?\n                WHERE id = ?\n            ");
            $stmt->execute([
                $form['document_type'],
                $form['name'],
                $form['slug'],
                $form['description'],
                $form['content_html'],
                $form['is_active'],
                $form['id'],
            ]);
            $savedId = $form['id'];
        } else {
            $stmt = $pdo->prepare("\n                INSERT INTO document_templates\n                    (document_type, name, slug, description, content_html, is_default, is_active, created_by)\n                VALUES\n                    (?, ?, ?, ?, ?, 0, ?, ?)\n            ");
            $stmt->execute([
                $form['document_type'],
                $form['name'],
                $form['slug'],
                $form['description'],
                $form['content_html'],
                $form['is_active'],
                pzdoc_current_user_id(),
            ]);
            $savedId = (int)$pdo->lastInsertId();
            $form['id'] = $savedId;
        }

        if ($form['is_default']) {
            dte_set_default($pdo, $savedId, $form['document_type']);
        } else {
            $stmt = $pdo->prepare('UPDATE document_templates SET is_default = 0 WHERE id = ?');
            $stmt->execute([$savedId]);
        }

        $pdo->commit();

        if ($action === 'save_back') {
            dte_redirect('document_templates.php?ok=saved');
        }

        dte_redirect('document_template_edit.php?id=' . $savedId . '&ok=saved');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('PestZone template edit error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

if (isset($_GET['ok']) && $_GET['ok'] === 'saved') {
    $flash = 'Șablonul a fost salvat.';
}

$previewHtml = dte_render_preview_html($pdo, (string)$form['content_html'], (string)$form['document_type']);
$tokensByGroup = pzdoc_available_tokens();
$isAdmin = is_admin();
$isNew = (int)$form['id'] <= 0;
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title><?= $isNew ? 'Șablon nou' : 'Editare șablon' ?> - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

<style>
/* Shell aliniat cu contracts.php / addenda.php (paleta noua pz-*) */
.template-topbar { align-items:center; padding:12px 20px; }
.template-toolbar { width:100%; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
.template-toolbar-left, .template-toolbar-right { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

.panel { background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:var(--pz-r); box-shadow:none; margin-bottom:12px; }
.panel-head { padding:14px 16px; border-bottom:1px solid var(--pz-lines); display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; }
.panel-title { font-size:16px; font-weight:900; color:var(--text); }
.panel-subtitle { font-size:12px; color:var(--pz-mu); margin-top:2px; }
.panel-body { padding:14px 16px; }

.alert { border-radius:var(--pz-rs); padding:10px 13px; margin-bottom:12px; font-weight:600; font-size:12.5px; }
.alert.error   { background:var(--pz-res); color:var(--pz-re); border:1px solid var(--pz-reb); }
.alert.success { background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); }

.editor-layout { display:grid; grid-template-columns:minmax(0, 1.15fr) minmax(320px, .85fr); gap:12px; align-items:start; }

/* card era folosit pe sectiunile vechi; il aliniem la panel */
.card { background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:var(--pz-r); box-shadow:none; padding:14px; }

.form-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; }
.field.full { grid-column:1 / -1; }
.field label { display:block; margin-bottom:5px; font-size:12px; font-weight:850; color:var(--pz-mu); }
.field input:not([type="checkbox"]):not([type="radio"]), .field select, .field textarea { width:100%; border:1px solid var(--pz-line); border-radius:var(--pz-rs); background:#fff; color:var(--text); padding:7px 10px; font-size:12.5px; outline:none; transition:border-color .14s; }
.field input:not([type="checkbox"]):not([type="radio"]):focus, .field select:focus, .field textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }

.word-editor-shell { border:1px solid var(--pz-line); border-radius:var(--pz-r); overflow:hidden; background:#fff; }
.tox.tox-tinymce { border:0 !important; border-radius:var(--pz-r) !important; }
.field textarea.editor { min-height:520px; font-family:var(--mono); font-size:12px; line-height:1.45; resize:vertical; white-space:pre-wrap; }

.option-row { display:flex; gap:18px; flex-wrap:wrap; align-items:center; margin-top:4px; }
/* .check-pill e <label>, dar .field label o face block uppercase. Override cu specificitate mai mare: */
.field .check-pill {
    display:inline-flex; align-items:center; gap:8px;
    padding:0; margin:0; min-height:0;
    font-size:13px; font-weight:500; color:var(--pz-text);
    text-transform:none; letter-spacing:0;
    background:transparent; border:0;
    cursor:pointer; transition:color .12s;
}
.field .check-pill input {
    width:16px; height:16px;
    min-width:0; min-height:0;
    margin:0; accent-color:var(--pz-bl);
    cursor:pointer;
    appearance:auto; -webkit-appearance:auto;
    flex-shrink:0;
}
.field .check-pill:hover { color:var(--pz-bld); }
.field .check-pill:has(input:checked) { color:var(--pz-bld); font-weight:600; }

.editor-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; margin-top:12px; }

.btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:34px; border-radius:var(--pz-rs); padding:0 11px; border:1px solid var(--pz-line); background:#fff; color:var(--text); font-size:12.5px; font-weight:600; text-decoration:none; cursor:pointer; white-space:nowrap; box-shadow:none; }
.btn:hover { border-color:var(--accent); color:var(--accent-deep); }
.btn.primary, .btn.accent { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn.primary:hover, .btn.accent:hover { background:var(--accent-strong); color:#fff; }

.side-stack { display:grid; gap:12px; }
.side-stack h3 { margin:0 0 10px; font-size:14px; font-weight:900; color:var(--text); letter-spacing:-.01em; }
.preview-frame { width:100%; height:520px; border:1px solid var(--pz-line); border-radius:var(--pz-r); background:#fff; }

.tokens-list { display:grid; gap:8px; }
.token-group { border:1px solid var(--pz-line); border-radius:var(--pz-r); background:var(--pz-soft, #F8FAFC); padding:10px; }
.token-group-title { font-weight:800; color:var(--text); margin-bottom:7px; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
.token-wrap { display:flex; flex-wrap:wrap; gap:6px; }
.token-pill { border:1px solid var(--pz-line); background:#fff; border-radius:var(--pz-rs); padding:5px 8px; font-family:var(--mono); font-size:11px; color:var(--accent-deep); cursor:pointer; user-select:none; transition:background .12s, border-color .12s; }
.token-pill:hover { background:var(--accent-soft); border-color:var(--accent-soft-2); }

.help-box { background:var(--pz-ors); border:1px solid var(--pz-orb); color:var(--pz-or); border-radius:var(--pz-rs); padding:10px 12px; font-size:12px; font-weight:600; line-height:1.4; }
.muted-small { color:var(--pz-mu); font-size:12px; line-height:1.4; }

/* Hero compact, fara gradient / shadow puternic — aliniat cu restul aplicatiei */
.template-hero {
    background:var(--pz-surf); color:var(--pz-title); border:1px solid var(--pz-line); border-radius:8px; padding:22px 24px;
    box-shadow:none; margin-bottom:16px;
    display:block;
}
.template-hero h1 { font-size:22px; font-weight:700; letter-spacing:0; margin:0; color:var(--pz-title); }

@media(max-width: 1120px) {
    .editor-layout { grid-template-columns:1fr; }
}

@media(max-width: 860px) {
    .template-topbar { width:100% !important; padding:8px 10px 14px 10px !important; display:block !important; }
    .template-toolbar, .template-toolbar-left, .template-toolbar-right { display:grid !important; grid-template-columns:1fr !important; width:100% !important; }
    .template-toolbar .btn, .template-toolbar form, .template-toolbar button,
    .editor-actions .btn, .editor-actions button { width:100% !important; }
    .form-grid { grid-template-columns:1fr; }
    .template-hero { padding:16px; }
    .template-hero h1 { font-size:19px; }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('document_templates', $isAdmin); ?>

    <main class="main">
        <div class="topbar template-topbar">
            <div class="template-toolbar">
                <div class="template-toolbar-left">
                    <a class="btn" href="document_templates.php">Înapoi la șabloane</a>
                </div>
                <div class="template-toolbar-right">
                    <?php if (!$isNew): ?>
                        <a class="btn" href="document_template_edit.php">+ Șablon nou</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert success" style="margin:10px 20px 0;"><?= dte_h($flash) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error" style="margin:10px 20px 0;"><?= dte_h($error) ?></div>
        <?php endif; ?>

        <div class="content">
            <section class="template-hero">
                <div class="pz-page-eyebrow">Setări · Șabloane documente</div>
                <h1><?= $isNew ? 'Șablon nou' : 'Editare șablon' ?></h1>
            </section>

            <form method="post" id="templateForm">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
                <input type="hidden" name="slug" value="<?= dte_h($form['slug']) ?>">
                <input type="hidden" name="description" value="<?= dte_h($form['description']) ?>">

                <div class="editor-layout">
                    <section class="panel">
                        <div class="panel-head">
                            <div>
                                <div class="panel-title">Conținut șablon</div>
                                <div class="panel-subtitle">Editezi vizual, ca in Word. Variabilele din dreapta se inserează prin click si se completează automat la emitere.</div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="form-grid">
                                <div class="field">
                                    <label>Tip document</label>
                                    <select name="document_type" id="documentType">
                                        <?php foreach (dte_types() as $type => $label): ?>
                                            <option value="<?= dte_h($type) ?>" <?= $form['document_type'] === $type ? 'selected' : '' ?>><?= dte_h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="field">
                                    <label>Nume șablon</label>
                                    <input type="text" name="name" value="<?= dte_h($form['name']) ?>" required>
                                </div>

                                <div class="field full">
                                    <label>Status</label>
                                    <div class="option-row">
                                        <label class="check-pill">
                                            <input type="checkbox" name="is_active" value="1" <?= (int)$form['is_active'] === 1 ? 'checked' : '' ?>> Activ
                                        </label>
                                        <label class="check-pill">
                                            <input type="checkbox" name="is_default" value="1" <?= (int)$form['is_default'] === 1 ? 'checked' : '' ?>> Șablon implicit
                                        </label>
                                    </div>
                                </div>

                                <div class="field full">
                                    <label>Document</label>
                                    <div class="word-editor-shell">
                                        <textarea class="editor" name="content_html" id="contentHtml" spellcheck="false" required><?= dte_h($form['content_html']) ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="editor-actions">
                                <button class="btn" type="button" id="refreshPreview">Actualizează preview</button>
                                <button class="btn" type="submit" name="action" value="save">Salvează</button>
                                <button class="btn primary" type="submit" name="action" value="save_back">Salvează si inapoi</button>
                            </div>
                        </div>
                    </section>

                    <aside class="side-stack">
                        <section class="panel">
                            <div class="panel-head">
                                <div>
                                    <div class="panel-title">Preview cu date demo</div>
                                    <div class="panel-subtitle">La emiterea reala, tokenii se completează automat din client, locație, servicii, materiale si setarile firmei.</div>
                                </div>
                            </div>
                            <div class="panel-body">
                                <iframe class="preview-frame" id="previewFrame" srcdoc="<?= dte_h($previewHtml) ?>"></iframe>
                            </div>
                        </section>

                        <section class="panel">
                            <div class="panel-head">
                                <div>
                                    <div class="panel-title">Variabile disponibile</div>
                                    <div class="panel-subtitle">Click pe o variabila pentru a o introduce in editor la pozitia cursorului.</div>
                                </div>
                            </div>
                            <div class="panel-body">
                                <div class="tokens-list">
                                    <?php foreach ($tokensByGroup as $group => $tokens): ?>
                                        <div class="token-group">
                                            <div class="token-group-title"><?= dte_h($group) ?></div>
                                            <div class="token-wrap">
                                                <?php foreach ($tokens as $token): ?>
                                                    <span class="token-pill" data-token="<?= dte_h($token) ?>"><?= dte_h($token) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </section>
                    </aside>
                </div>
            </form>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@8/tinymce.min.js"></script>
<script>
const editor = document.getElementById('contentHtml');
const previewFrame = document.getElementById('previewFrame');
const refreshPreview = document.getElementById('refreshPreview');
const templateForm = document.getElementById('templateForm');
const demoTokens = <?= json_encode(dte_demo_tokens(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const pdfCssByType = <?= json_encode([
    'oferta' => dte_pdf_css_for_type($pdo, 'oferta'),
    'contract' => dte_pdf_css_for_type($pdo, 'contract'),
    'act_aditional' => dte_pdf_css_for_type($pdo, 'act_aditional'),
    'proces_verbal' => dte_pdf_css_for_type($pdo, 'proces_verbal'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let previewTimer = null;

function getTiny() {
    return window.tinymce ? tinymce.get('contentHtml') : null;
}

function getEditorValue() {
    const tiny = getTiny();
    return tiny ? tiny.getContent() : (editor.value || '');
}

function syncEditor() {
    const tiny = getTiny();
    if (tiny) tiny.save();
}

function applyDemoTokens(html) {
    Object.keys(demoTokens).forEach(function(key) {
        const value = String(demoTokens[key] ?? '');
        html = html.split('{{' + key + '}}').join(value);
        html = html.split('{{ ' + key + ' }}').join(value);
    });
    html = html.replace(/\{\{\s*[a-zA-Z0-9_.\-]+\s*\}\}/g, '');
    return html;
}

function normalizeEditorHtmlForPdf(html) {
    // Aceeasi normalizare principala ca in motorul PDF: font-size in px -> pt.
    // 1px = 0.75pt. Conversia 1px -> 1pt mareste artificial PDF-ul.
    return String(html || '').replace(/font-size\s*:\s*([0-9]+(?:\.[0-9]+)?)\s*px/gi, function(match, value) {
        var pt = Math.round((parseFloat(value) * 0.75) * 100) / 100;
        return 'font-size:' + pt + 'pt';
    });
}

function updatePreview() {
    syncEditor();
    const typeEl = document.getElementById('documentType');
    const type = typeEl ? (typeEl.value || 'oferta') : 'oferta';
    const css = pdfCssByType[type] || pdfCssByType.oferta || '';
    const body = normalizeEditorHtmlForPdf(applyDemoTokens(getEditorValue()));
    const html = '<!doctype html><html lang="ro"><head><meta charset="UTF-8">' + css + '</head><body><div class="pzdoc-content">' + body + '</div></body></html>';
    previewFrame.setAttribute('srcdoc', html);
}

function insertToken(text) {
    const tiny = getTiny();
    if (tiny) {
        tiny.insertContent(text);
        tiny.focus();
        updatePreview();
        return;
    }
    const start = editor.selectionStart || 0;
    const end = editor.selectionEnd || 0;
    const value = editor.value;
    editor.value = value.substring(0, start) + text + value.substring(end);
    editor.focus();
    const pos = start + text.length;
    editor.setSelectionRange(pos, pos);
    updatePreview();
}

function initTiny() {
    if (!window.tinymce) {
        editor.style.display = 'block';
        return;
    }
    tinymce.init({
        selector: '#contentHtml',
        license_key: 'gpl',
        height: 680,
        language: 'ro',
        promotion: false,
        branding: false,
        browser_spellcheck: true,
        contextmenu: false,
        entity_encoding: 'raw',
        convert_urls: false,
        relative_urls: false,
        remove_script_host: false,
        verify_html: false,
        forced_root_block: 'p',
        menubar: 'edit view insert format table tools',
        plugins: 'preview searchreplace autolink directionality visualblocks visualchars fullscreen link table charmap pagebreak nonbreaking anchor advlist lists wordcount help quickbars',
        toolbar: [
            'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor removeformat',
            'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table link hr pagebreak | visualblocks fullscreen preview'
        ],
        toolbar_mode: 'wrap',
        font_size_formats: '7pt 8pt 9pt 10pt 10.5pt 11pt 12pt 13pt 14pt 15pt 16pt 18pt 20pt 22pt 24pt 28pt 32pt',
        fontsize_formats: '7pt 8pt 9pt 10pt 10.5pt 11pt 12pt 13pt 14pt 15pt 16pt 18pt 20pt 22pt 24pt 28pt 32pt',
        quickbars_selection_toolbar: 'bold italic underline | blocks | forecolor backcolor | bullist numlist | link',
        quickbars_insert_toolbar: 'table hr pagebreak',
        table_toolbar: 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol',
        table_default_attributes: { border: '1' },
        table_default_styles: { width: '100%', borderCollapse: 'collapse' },
        content_style: `
            body { font-family: Arial, sans-serif; font-size: 10.5pt; line-height: 1.35; color: #111827; padding: 18px; }
            table { border-collapse: collapse; width: 100%; margin: 2.5mm 0 4mm; }
            th, td { border: 1px solid #d1d5db; padding: 1.7mm 2mm; vertical-align: top; }
            th { background: #f3f4f6; font-weight: 700; color:#10243e; }
            h1 { font-size:16pt; line-height:1.18; margin:0 0 5mm; color:#10243e; }
            h2 { font-size:12pt; line-height:1.2; margin:5mm 0 2mm; color:#10243e; }
            h3 { font-size:10.5pt; line-height:1.2; margin:4mm 0 2mm; color:#10243e; }
            p { margin: 0 0 2.2mm; }
        `,
        setup: function(tiny) {
            tiny.on('init', function() { updatePreview(); });
            tiny.on('change keyup undo redo SetContent input', function() {
                clearTimeout(previewTimer);
                previewTimer = setTimeout(updatePreview, 450);
            });
            tiny.addShortcut('meta+s', 'Salvează șablonul', function() {
                syncEditor();
                templateForm.requestSubmit();
            });
        }
    });
}

document.querySelectorAll('.token-pill').forEach(function(pill) {
    pill.addEventListener('click', function() {
        insertToken(this.getAttribute('data-token') || '');
    });
});

refreshPreview.addEventListener('click', updatePreview);
const documentTypeSelect = document.getElementById('documentType');
if (documentTypeSelect) {
    documentTypeSelect.addEventListener('change', updatePreview);
}
editor.addEventListener('input', function() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(updatePreview, 450);
});
templateForm.addEventListener('submit', function() { syncEditor(); });
initTiny();
</script>
</body>
</html>