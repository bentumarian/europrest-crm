<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/lib/settings_lib.php';
require_once __DIR__ . '/super_admin_guard.php';

pz_require_super_admin();

function cs_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

pz_settings_ensure_schema($pdo);
$defaults = pz_company_defaults();
$fields = array_keys($defaults);
$settings = pz_settings_get_all($pdo, $defaults);
$success = false;
$error = '';

$formFields = [
    'company.legal_name',
    'company.cui',
    'company.reg_com',
    'company.provider_role_label',
    'company.address',
    'company.bank_name',
    'company.bank_account',
    'company.email',
    'company.phone',
    'company.website',
    'company.legal_representative_name',
    'company.legal_representative_role',
    'company.authorizations',
    'company.notes',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $values = [];
    foreach ($fields as $f) {
        if (in_array($f, $formFields, true)) {
            $values[$f] = trim((string)($_POST[str_replace('.', '_', $f)] ?? ''));
        } else {
            $values[$f] = $settings[$f] ?? ($defaults[$f] ?? '');
        }
    }

    // Nu mai folosim text logo / marca. Denumirea afișata rămâne automat denumirea legala.
    if (array_key_exists('company.logo_text', $defaults)) {
        $values['company.logo_text'] = '';
    }
    if (array_key_exists('company.display_name', $defaults)) {
        $values['company.display_name'] = $values['company.legal_name'] ?? '';
    }

    if (($values['company.legal_name'] ?? '') === '') {
        $error = 'Completează denumirea legala a companiei.';
    } else {
        try {
            pz_settings_set_many($pdo, $values);
            $settings = pz_settings_get_all($pdo, $defaults);
            $success = true;
        } catch (Throwable $e) {
            error_log('Emma company settings: ' . $e->getMessage());
            $error = 'Setările nu au putut fi salvate.';
        }
    }
}

function cs_input($name, $label, $settings, $type = 'text') {
    $field = str_replace('.', '_', $name);
    echo '<div class="field"><label>' . cs_h($label) . '</label><input type="' . cs_h($type) . '" name="' . cs_h($field) . '" value="' . cs_h($settings[$name] ?? '') . '"></div>';
}

function cs_textarea($name, $label, $settings) {
    $field = str_replace('.', '_', $name);
    echo '<div class="field full"><label>' . cs_h($label) . '</label><textarea name="' . cs_h($field) . '">' . cs_h($settings[$name] ?? '') . '</textarea></div>';
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Date companie - <?= h(pz_app_name()) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">
    <?php app_theme_css(); ?>
    <style>
        .top{
            padding:12px 20px;
            display:flex;
            justify-content:space-between;
            gap:12px;
        }
        .wrap{
            max-width:1180px;
            margin:0 auto;
            display:flex;
            flex-direction:column;
            gap:16px;
        }
        .head{
            background:#fff;
            border:1px solid var(--border);
            border-radius:20px;
            box-shadow:var(--shadow);
            padding:20px;
        }
        .head h1{
            margin:0;
            font-size:26px;
            letter-spacing:-.03em;
        }
        .head p{
            margin:6px 0 0;
            color:var(--muted);
            font-weight:700;
            line-height:1.45;
        }
        .settings-form{
            display:flex;
            flex-direction:column;
            gap:16px;
        }
        .cards-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:16px;
        }
        .card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:20px;
            box-shadow:var(--shadow);
            padding:18px;
        }
        .card-head{
            display:flex;
            align-items:center;
            gap:12px;
            margin-bottom:16px;
        }
        .card-icon{
            width:42px;
            height:42px;
            border-radius:14px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:rgba(0,120,170,.10);
            color:var(--accent);
            flex:0 0 auto;
        }
        .card-icon svg{
            width:21px;
            height:21px;
            stroke:currentColor;
            stroke-width:2.2;
            fill:none;
            stroke-linecap:round;
            stroke-linejoin:round;
        }
        .card-title h2{
            margin:0;
            font-size:18px;
            letter-spacing:-.02em;
        }
        .card-title p{
            margin:3px 0 0;
            color:var(--muted);
            font-size:13px;
            font-weight:700;
            line-height:1.35;
        }
        .form-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:12px;
        }
        .field{
            display:flex;
            flex-direction:column;
            gap:6px;
        }
        .field.full{
            grid-column:1/-1;
        }
        label{
            font-size:12px;
            font-weight:900;
            color:var(--muted);
            text-transform:uppercase;
            letter-spacing:.04em;
        }
        input,textarea{
            width:100%;
            border:1px solid var(--border);
            border-radius:12px;
            min-height:42px;
            padding:10px 12px;
            font-weight:700;
            background:#fff;
            color:var(--text);
            outline:none;
        }
        input:focus, textarea:focus{
            border-color:var(--accent);
            box-shadow:0 0 0 4px rgba(0,120,170,.10);
        }
        textarea{
            min-height:86px;
            resize:vertical;
            line-height:1.45;
        }
        .head, .card { border-radius: var(--pz-r) !important; box-shadow: none !important; }
        .card-icon { border-radius: var(--pz-rs) !important; background: var(--pz-bls) !important; color: var(--pz-bl) !important; }
        input, textarea { border-radius: var(--pz-rs) !important; min-height: 34px; }
        input:focus, textarea:focus { border-color: var(--pz-bl) !important; box-shadow: 0 0 0 3px var(--pz-bls) !important; }
        .notice { border-radius: var(--pz-rs) !important; font-weight: 600 !important; }
        .notice.ok  { background: var(--pz-grs) !important; color: var(--pz-gr) !important; }
        .notice.err { background: var(--pz-res) !important; color: var(--pz-re) !important; }
        * { font-family: 'Satoshi', 'Inter', system-ui, sans-serif !important; }
        .notice.ok{
            background:var(--success-soft);
            color:var(--success);
        }
        .notice.err{
            background:var(--danger-soft);
            color:var(--danger);
        }
        .actions-card{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:14px;
            flex-wrap:wrap;
        }
        .actions-copy{
            display:flex;
            align-items:center;
            gap:12px;
            color:var(--muted);
            font-weight:800;
            line-height:1.4;
        }
        .actions-copy .card-icon{
            background:rgba(0,120,170,.08);
        }
        .actions{
            display:flex;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
        }
        @media(max-width:1000px){
            .cards-grid{grid-template-columns:1fr;}
        }
        @media(max-width:700px){
            .top{flex-direction:column;}
            .form-grid{grid-template-columns:1fr;}
            .actions-card{align-items:stretch;}
            .actions,.actions .btn,.top .btn{width:100%;}
            .actions{flex-direction:column;}
            .head h1{font-size:22px;}
        }
    </style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('settings', true); ?>
    <main class="main">
        <div class="content wrap">
            <?php pz_page_header([
                'back'     => ['href' => 'settings.php', 'label' => 'Înapoi la setări'],
                'kicker'   => 'ADMINISTRARE · COMPANIE',
                'title'    => 'Date companie',
                'subtitle' => 'Date oficiale folosite automat în contracte, documente și identificarea prestatorului.',
                'actions'  => [[
                    'label'   => 'Salvează',
                    'icon'    => 'ti-device-floppy',
                    'variant' => 'primary',
                    'type'    => 'submit',
                    'form'    => 'companyForm',
                ]],
            ]); ?>

            <?php if ($success): ?>
                <div class="notice ok">Datele companiei au fost salvate.</div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice err"><?= cs_h($error) ?></div>
            <?php endif; ?>

            <form id="companyForm" method="post" class="settings-form">
                <?= csrf_field() ?>

                <div class="cards-grid">
                    <section class="card">
                        <div class="card-head">
                            <div class="card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9h.01"/><path d="M9 13h.01"/><path d="M9 17h.01"/></svg>
                            </div>
                            <div class="card-title">
                                <h2>Identificare firma</h2>
                                <p>Datele oficiale ale societatii.</p>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="field full">
                                <label>Denumire legala</label>
                                <input name="company_legal_name" value="<?= cs_h($settings['company.legal_name'] ?? '') ?>" required>
                            </div>
                            <?php
                            cs_input('company.cui', 'CUI / CIF', $settings);
                            cs_input('company.reg_com', 'Reg. Com.', $settings);
                            cs_input('company.provider_role_label', 'Calitate in contract', $settings);
                            ?>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-head">
                            <div class="card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                            </div>
                            <div class="card-title">
                                <h2>Date sediu si contact</h2>
                                <p>Adresa, banca si date de contact.</p>
                            </div>
                        </div>
                        <div class="form-grid">
                            <?php
                            cs_textarea('company.address', 'Sediu social', $settings);
                            cs_input('company.bank_name', 'Banca', $settings);
                            cs_input('company.bank_account', 'Cont bancar / IBAN', $settings);
                            cs_input('company.email', 'Email', $settings, 'email');
                            cs_input('company.phone', 'Telefon', $settings);
                            cs_input('company.website', 'Website', $settings);
                            ?>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-head">
                            <div class="card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M17 11l2 2 4-4"/></svg>
                            </div>
                            <div class="card-title">
                                <h2>Reprezentant</h2>
                                <p>Persoană care reprezinta societatea.</p>
                            </div>
                        </div>
                        <div class="form-grid">
                            <?php
                            cs_input('company.legal_representative_name', 'Reprezentant legal', $settings);
                            cs_input('company.legal_representative_role', 'Functie reprezentant', $settings);
                            ?>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-head">
                            <div class="card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 15h6"/><path d="M9 18h6"/><path d="M9 12h2"/></svg>
                            </div>
                            <div class="card-title">
                                <h2>Autorizatii si observatii</h2>
                                <p>Informatii interne si autorizatii folosite in documente.</p>
                            </div>
                        </div>
                        <div class="form-grid">
                            <?php
                            cs_textarea('company.authorizations', 'Autorizatii', $settings);
                            cs_textarea('company.notes', 'Observații interne', $settings);
                            ?>
                        </div>
                    </section>
                </div>

                <section class="card actions-card">
                    <div class="actions-copy">
                        <div class="card-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg>
                        </div>
                        <div>Salvează modificarile pentru contractele si documentele generate ulterior.</div>
                    </div>
                    <div class="actions">
                        <a class="btn ghost" href="settings.php">Renunță</a>
                        <button class="btn accent" type="submit">Salvează</button>
                    </div>
                </section>
            </form>
        </div>
    </main>
</div>
</body>
</html>
