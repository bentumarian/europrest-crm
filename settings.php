<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/settings_lib.php';

if (file_exists(__DIR__ . '/super_admin_guard.php')) {
    require_once __DIR__ . '/super_admin_guard.php';
}

if (!is_admin()) {
    http_response_code(403);
    exit('Acces permis doar administratorului.');
}

function st_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function st_setting_icon(string $name): string
{
    if ($name === 'templates') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="4" y="4" width="16" height="16" rx="3"></rect>
            <path d="M8 8h8"></path>
            <path d="M8 12h8"></path>
            <path d="M8 16h5"></path>
            <path d="M17 15.5l1.5 1.5 2.5-3"></path>
        </svg>';
    }

    
    if ($name === 'invoice') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M7 3h10a2 2 0 0 1 2 2v16l-3-2-3 2-3-2-3 2V5a2 2 0 0 1 2-2Z"></path>
            <path d="M9 8h6"></path>
            <path d="M9 12h6"></path>
            <path d="M9 16h4"></path>
        </svg>';
    }
if ($name === 'sms') {
        return '<span style="font-size:12px;font-weight:700;letter-spacing:-.02em;">SMS</span>';
    }

    if ($name === 'star') {
        return app_icon_svg('star');
    }

    return app_icon_svg($name);
}

try {
    pz_settings_ensure_schema($pdo);
} catch (Throwable $e) {
    error_log('PestZone settings schema error: ' . $e->getMessage());
}

$company = pz_company_settings($pdo);
$companyName = trim((string)($company['company.display_name'] ?? '')) ?: trim((string)($company['company.legal_name'] ?? 'Compania'));
$logoText = trim((string)($company['company.logo_text'] ?? '')) ?: 'PZ';

$isSuperAdmin = function_exists('pz_is_super_admin') ? pz_is_super_admin() : is_admin();

$cards = [
    [
        'title' => 'Utilizatori',
        'desc' => 'Gestioneaza utilizatorii de birou, parolele si accesul administrativ',
        'url' => 'users.php',
        'icon' => 'users',
    ],
    [
        'title' => 'Serii documente',
        'desc' => 'Configureaza numerotarea pentru oferte, contracte si procese verbale',
        'url' => 'document_series.php',
        'icon' => 'series',
    ],
    [
        'title' => 'Design documente',
        'desc' => 'Configureaza antetul, footerul, marginile si fontul documentelor',
        'url' => 'document_design.php',
        'icon' => 'design',
    ],
    [
        'title' => 'Sabloane documente',
        'desc' => 'Gestioneaza continutul pentru oferte, contracte si procese verbale',
        'url' => 'document_templates.php',
        'icon' => 'templates',
    ],
    [
        'title' => 'Servicii',
        'desc' => 'Gestioneaza serviciile DDD care apar in contracte, sarcini si programari',
        'url' => 'services.php',
        'icon' => 'services',
    ],
    [
        'title' => 'Echipe teren',
        'desc' => 'Adauga echipe, parole pentru operatori si culori in calendar',
        'url' => 'team.php',
        'icon' => 'team',
    ],
];

$superAdminCards = [
    [
        'title' => 'Integrare facturare',
        'desc' => 'Configureaza Oblio pentru emitere facturi, proforme si incasari prin API',
        'url' => 'billing_settings.php',
        'icon' => 'invoice',
    ],
    [
        'title' => 'Comunicare / Integrari',
        'desc' => 'Configureaza SendGrid pentru email si SMSLink.ro pentru SMS-uri',
        'url' => 'communication_settings.php',
        'icon' => 'mail',
    ],
    [
        'title' => 'Review & Satisfactie',
        'desc' => 'Configureaza cererea de review, linkul Google si formularul de feedback intern',
        'url' => 'review_settings.php',
        'icon' => 'star',
    ],
    [
        'title' => 'Sabloane email',
        'desc' => 'Personalizeaza subiectele si mesajele pentru emailurile trimise prin SendGrid',
        'url' => 'email_templates.php',
        'icon' => 'mail',
    ],
    [
        'title' => 'Sabloane SMS',
        'desc' => 'Personalizeaza mesajele pentru programari si remindere la scadenta',
        'url' => 'sms_templates.php',
        'icon' => 'sms',
    ],
    [
        'title' => 'Activitate SMS',
        'desc' => 'Vezi ultimele SMS-uri trimise, sarite (skipped) sau esuate. Util pentru debugging.',
        'url' => 'sms_activity.php',
        'icon' => 'reports',
    ],
    [
        'title' => 'Activitate Email',
        'desc' => 'Vezi ultimele emailuri trimise sau esuate. Combina log-urile SendGrid + log-urile pe documente.',
        'url' => 'email_activity.php',
        'icon' => 'mail',
    ],
];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Setari - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.settings-page {
    max-width: 1120px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.settings-head {
    max-width: 920px;
    background:
        radial-gradient(circle at top right, rgba(91,75,255,.12), transparent 34%),
        linear-gradient(135deg, #ffffff 0%, #f8fbff 55%, #eef4ff 100%);
    border: 1px solid rgba(203,213,225,.95);
    border-radius: 24px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, .08);
    padding: 24px 28px;
    position: relative;
    overflow: hidden;
}

.settings-head:before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    background: linear-gradient(180deg, var(--accent), #7c6cff);
    opacity: .95;
}

.settings-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    color: var(--accent);
    font-size: 11px;
    font-weight: 750;
    letter-spacing: .12em;
    text-transform: uppercase;
}

.settings-eyebrow:before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 99px;
    background: var(--accent);
    box-shadow: 0 0 0 5px rgba(91,75,255,.1);
}

.settings-head h1 {
    margin: 0;
    font-size: 28px;
    font-weight: 750;
    letter-spacing: -.045em;
    color: var(--text);
}

.settings-head p {
    margin: 7px 0 0;
    color: var(--muted);
    font-weight: 400;
    font-size: 14px;
    line-height: 1.55;
    max-width: 720px;
}

.section-label {
    max-width: 920px;
    margin: 8px 0 -8px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .12em;
    color: var(--muted);
}

.section-label:after {
    content: "";
    height: 1px;
    flex: 1;
    background: linear-gradient(90deg, var(--border), transparent);
}

.settings-list {
    background: rgba(255,255,255,.94);
    border: 1px solid rgba(203,213,225,.95);
    border-radius: 22px;
    box-shadow: 0 16px 38px rgba(15, 23, 42, .07);
    overflow: hidden;
    max-width: 920px;
    backdrop-filter: blur(8px);
}

.setting-row {
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr) 42px;
    gap: 15px;
    align-items: center;
    padding: 17px 20px;
    border-bottom: 1px solid rgba(226,232,240,.9);
    transition: background .14s ease, transform .14s ease;
    text-decoration: none;
    color: inherit;
}

.setting-row:last-child {
    border-bottom: 0;
}

.setting-row:hover {
    background: linear-gradient(90deg, rgba(91,75,255,.055), rgba(255,255,255,0));
    transform: translateX(2px);
}

.setting-icon {
    width: 40px;
    height: 40px;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(91,75,255,.12), rgba(91,75,255,.055));
    border: 1px solid rgba(91,75,255,.08);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--accent);
    flex: 0 0 auto;
}

.setting-icon svg {
    width: 20px;
    height: 20px;
}

.setting-icon .nav-icon {
    width: 20px;
    height: 20px;
    margin: 0;
}

.setting-title {
    font-weight: 750;
    font-size: 15px;
    color: var(--text);
    letter-spacing: -.018em;
}

.setting-desc {
    display: block;
    font-weight: 400;
    color: var(--muted);
    margin-top: 4px;
    line-height: 1.38;
    font-size: 13px;
}

.setting-arrow {
    width: 38px;
    height: 38px;
    border: 1px solid rgba(203,213,225,.9);
    border-radius: 14px;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 23px;
    font-weight: 500;
    color: var(--muted);
    transition: color .14s ease, border-color .14s ease, background .14s ease;
}

.setting-row:hover .setting-arrow {
    color: var(--accent);
    border-color: rgba(91,75,255,.25);
    background: rgba(91,75,255,.045);
}

.company-card {
    background: rgba(255,255,255,.94);
    border: 1px solid rgba(203,213,225,.95);
    border-radius: 22px;
    box-shadow: 0 16px 38px rgba(15, 23, 42, .07);
    padding: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    max-width: 920px;
}

.company-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.company-logo {
    width: 70px;
    height: 48px;
    border: 1px solid rgba(203,213,225,.9);
    border-radius: 16px;
    background: linear-gradient(135deg, #ffffff, #f7f9ff);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--accent);
    font-weight: 750;
    font-size: 13px;
    text-align: center;
}

.company-name {
    font-weight: 750;
    font-size: 16px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: -.02em;
}

.company-sub {
    font-weight: 400;
    color: var(--muted);
    margin-top: 3px;
    font-size: 13px;
}

.admin-note {
    max-width: 920px;
    background: linear-gradient(135deg, #fff7ed, #fffbeb);
    color: var(--warning);
    border: 1px solid rgba(154,103,0,.18);
    border-radius: 18px;
    padding: 13px 15px;
    font-weight: 500;
    line-height: 1.45;
    font-size: 13px;
}

.reset-card {
    background: rgba(255,255,255,.94);
    border: 1px solid #fecaca;
    border-radius: 22px;
    box-shadow: 0 16px 38px rgba(153, 27, 27, .06);
    overflow: hidden;
    max-width: 920px;
}

.reset-card .setting-row {
    border-bottom: 0;
}

.reset-card .setting-icon {
    background: linear-gradient(135deg, #fef3f2, #fff7f7);
    color: #b42318;
    border-color: rgba(180,35,24,.12);
}

.reset-card .setting-title {
    color: #991b1b;
}

.reset-card .setting-desc {
    color: #7f1d1d;
}

@media(max-width: 760px) {
    .settings-page {
        max-width: 100%;
    }

    .settings-head {
        padding: 18px 16px;
    }

    .setting-row {
        grid-template-columns: 34px minmax(0, 1fr) 38px;
        padding: 15px 12px;
    }

    .setting-title {
        font-size: 14px;
    }

    .setting-desc {
        font-size: 12px;
    }

    .company-card {
        align-items: stretch;
        flex-direction: column;
    }

    .company-card .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('settings', true); ?>

    <main class="main">
        <div class="content settings-page">
            <div class="settings-head">
                <div class="settings-eyebrow">Administrare platforma</div>
                <h1>Setari</h1>
                <p>Zona centrala pentru utilizatori, documente, servicii, echipe, comunicare si integrari.</p>
            </div>

            <div class="section-label">Administrare operationala</div>
            <section class="settings-list">
                <?php foreach ($cards as $card): ?>
                    <a class="setting-row" href="<?= st_h($card['url']) ?>">
                        <span class="setting-icon"><?= st_setting_icon((string)$card['icon']) ?></span>
                        <span>
                            <span class="setting-title"><?= st_h($card['title']) ?></span>
                            <span class="setting-desc"><?= st_h($card['desc']) ?></span>
                        </span>
                        <span class="setting-arrow">›</span>
                    </a>
                <?php endforeach; ?>
            </section>

            <?php if ($isSuperAdmin): ?>
                <div class="section-label">Comunicare si integrari</div>
                <section class="settings-list">
                    <?php foreach ($superAdminCards as $card): ?>
                        <a class="setting-row" href="<?= st_h($card['url']) ?>">
                            <span class="setting-icon"><?= st_setting_icon((string)$card['icon']) ?></span>
                            <span>
                                <span class="setting-title"><?= st_h($card['title']) ?></span>
                                <span class="setting-desc"><?= st_h($card['desc']) ?></span>
                            </span>
                            <span class="setting-arrow">›</span>
                        </a>
                    <?php endforeach; ?>
                </section>

                <section class="reset-card">
                    <a class="setting-row" href="platform_reset.php">
                        <span class="setting-icon"><?= st_setting_icon('settings') ?></span>
                        <span>
                            <span class="setting-title">Reset platforma</span>
                            <span class="setting-desc">Sterge datele operationale de test: clienti, contracte, sarcini, programari si numere generate</span>
                        </span>
                        <span class="setting-arrow">›</span>
                    </a>
                </section>
            <?php endif; ?>

            <section class="company-card">
                <div class="company-left">
                    <div class="company-logo"><?= st_h($logoText) ?></div>
                    <div style="min-width:0">
                        <div class="company-name"><?= st_h($companyName) ?></div>
                        <div class="company-sub">Datele prestatorului folosite in contracte si documente</div>
                    </div>
                </div>

                <?php if ($isSuperAdmin): ?>
                    <a class="btn ghost" href="company_settings.php">Editeaza compania</a>
                <?php else: ?>
                    <span class="btn ghost" style="opacity:.55;cursor:not-allowed;">Doar super admin</span>
                <?php endif; ?>
            </section>

            <div class="admin-note">
                Comunicarea, datele companiei si resetarea platformei sunt disponibile doar pentru super administrator.
                Administratorii obisnuiti nu pot face aceste modificari sensibile.
            </div>
        </div>
    </main>
</div>
</body>
</html>
