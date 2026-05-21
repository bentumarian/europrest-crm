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
        'desc' => 'Conturi de birou și acces administrativ',
        'url' => 'users.php',
        'icon' => 'users',
    ],
    [
        'title' => 'Import date',
        'desc' => 'Încarcă clienți și locații din Excel',
        'url' => 'data_import.php',
        'icon' => 'clients',
    ],
    [
        'title' => 'Serii documente',
        'desc' => 'Numerotare pentru oferte, contracte și procese verbale',
        'url' => 'document_series.php',
        'icon' => 'series',
    ],
    [
        'title' => 'Design documente',
        'desc' => 'Configurează antetul, footerul, marginile și fontul documentelor',
        'url' => 'document_design.php',
        'icon' => 'design',
    ],
    [
        'title' => 'Identitate platformă',
        'desc' => 'Paleta de culori, fontul Inter și regulile generale de UI',
        'url' => 'style_guide.php',
        'icon' => 'design',
    ],
    [
        'title' => 'Șabloane documente',
        'desc' => 'Gestionează conținutul pentru oferte, contracte și procese verbale',
        'url' => 'document_templates.php',
        'icon' => 'templates',
    ],
    [
        'title' => 'Servicii',
        'desc' => 'Serviciile DDD folosite în contracte, sarcini și programări',
        'url' => 'services.php',
        'icon' => 'services',
    ],
    [
        'title' => 'Tehnicieni',
        'desc' => 'Adaugă tehnicieni, parole de acces și culori în calendar',
        'url' => 'team.php',
        'icon' => 'team',
    ],
];

$superAdminCards = [
    [
        'title' => 'SmartBill',
        'desc' => 'Configurează facturarea, cotele TVA, seria și statusul e-Factura / SPV',
        'url' => 'smartbill_settings.php',
        'icon' => 'invoice',
    ],
    [
        'title' => 'Canale comunicare',
        'desc' => 'Conectare SendGrid și SMSLink.ro',
        'url' => 'communication_settings.php',
        'icon' => 'mail',
    ],
    [
        'title' => 'Review clienți',
        'desc' => 'Link Google și formular intern de satisfacție',
        'url' => 'review_settings.php',
        'icon' => 'star',
    ],
    [
        'title' => 'Șabloane email',
        'desc' => 'Textele emailurilor trimise din CRM',
        'url' => 'email_templates.php',
        'icon' => 'mail',
    ],
    [
        'title' => 'Șabloane SMS',
        'desc' => 'Mesaje pentru programări și remindere',
        'url' => 'sms_templates.php',
        'icon' => 'sms',
    ],
    [
        'title' => 'Jurnal SMS',
        'desc' => 'SMS-uri trimise, sărite sau eșuate',
        'url' => 'sms_activity.php',
        'icon' => 'reports',
    ],
    [
        'title' => 'Jurnal email',
        'desc' => 'Emailuri trimise sau eșuate',
        'url' => 'email_activity.php',
        'icon' => 'mail',
    ],
];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Setări - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.settings-page {
    gap: 12px !important;
}
.settings-page .settings-head {
    padding: 18px 20px !important;
}
.settings-page .settings-head p {
    max-width: 560px !important;
    margin-top: 8px !important;
    line-height: 1.4 !important;
}
.settings-page .section-label {
    margin-top: 6px !important;
}
.settings-page .setting-row {
    grid-template-columns: 28px minmax(0, 1fr) 30px !important;
    gap: 10px !important;
    padding: 10px 12px !important;
}
.settings-page .setting-icon {
    width: 24px !important;
    height: 24px !important;
    background: transparent !important;
    border: 0 !important;
    color: var(--muted) !important;
    box-shadow: none !important;
}
.settings-page .setting-icon .nav-icon,
.settings-page .setting-icon svg {
    width: 22px !important;
    height: 22px !important;
}
.settings-page .setting-desc {
    margin-top: 1px !important;
}
.settings-page .setting-arrow {
    width: 28px !important;
    height: 28px !important;
    border-radius: 4px !important;
}
@media(max-width: 760px) {
    .settings-page {
        gap: 10px !important;
    }
    .settings-page .settings-head {
        padding: 16px 18px !important;
    }
    .settings-page .setting-row {
        grid-template-columns: 24px minmax(0, 1fr) 28px !important;
        gap: 9px !important;
        padding: 9px 10px !important;
        min-height: 54px !important;
    }
    .settings-page .setting-icon,
    .settings-page .setting-icon .nav-icon,
    .settings-page .setting-icon svg {
        width: 21px !important;
        height: 21px !important;
    }
    .settings-page .setting-title {
        font-size: 13.5px !important;
    }
    .settings-page .setting-desc {
        font-size: 11.5px !important;
        line-height: 1.25 !important;
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
                <div class="settings-eyebrow">Administrare platformă</div>
                <h1>Setări</h1>
                <p>Zona centrală pentru utilizatori, documente, servicii, tehnicieni, comunicare și integrări.</p>
            </div>

            <div class="section-label">Administrare operațională</div>
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
                <div class="section-label">Comunicare și integrări</div>
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
                            <span class="setting-title">Reset platformă</span>
                            <span class="setting-desc">Șterge datele operaționale de test; contactele se păstrează dacă nu bifezi ștergerea lor separată</span>
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
                        <div class="company-sub">Datele prestatorului folosite în contracte și documente</div>
                    </div>
                </div>

                <?php if ($isSuperAdmin): ?>
                    <a class="btn ghost" href="company_settings.php">Editează compania</a>
                <?php else: ?>
                    <span class="btn ghost" style="opacity:.55;cursor:not-allowed;">Doar super admin</span>
                <?php endif; ?>
            </section>

            <div class="admin-note">
                Comunicarea, datele companiei și resetarea platformei sunt disponibile doar pentru super administrator.
                Administratorii obișnuiți nu pot face aceste modificări sensibile.
            </div>
        </div>
    </main>
</div>
</body>
</html>
