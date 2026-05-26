<?php

/*
|--------------------------------------------------------------------------
| app_sidebar.php
|--------------------------------------------------------------------------
| render_sidebar() - sidebar principal al aplicației + JS aferent
| (toggle, submeniuri, închidere automată pe mobile).
|
| Apel: render_sidebar('clients', $isAdmin);
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/app_helpers.php';
require_once __DIR__ . '/app_icons.php';
require_once __DIR__ . '/app_brand.php';
require_once __DIR__ . '/app_topbar.php';
require_once __DIR__ . '/app_module_navs.php';

if (!function_exists('render_sidebar')) {
    function render_sidebar(string $active = '', bool $isAdmin = true): void
{
    $userName = function_exists('current_user_name') ? current_user_name() : 'Utilizator';
    $originalActive = $active;

    $settingsActiveKeys = [
        'settings',
        'company_settings',
        'users',
        'services',
        'team',
        'communication_settings',
        'smartbill_settings',
        'email_templates',
        'sms_templates',
        'sms_activity',
        'email_activity',
        'data_import',
        'review_settings',
        'style_guide',
        // Mutate aici din sidebar (configurare documente):
        'document_templates',
        'document_series',
        'document_design',
    ];

    if (in_array($active, $settingsActiveKeys, true)) {
        $active = 'settings';
    }

    $stockActiveKeys = ['stock', 'stock_products', 'stock_receipts', 'stock_movements', 'stock_card', 'stock_inventory', 'stock_notifications', 'stock_work_registry', 'stock_deferred_pvs'];
    if (in_array($active, $stockActiveKeys, true)) {
        $active = 'stock';
    }

    // Doar paginile operaționale, nu și configurarea (mutată în Setări):
    $documentsKeys = [
        'documente',
        'oferte',
        'contracts',
        'addenda',
        'procese_verbale',
    ];
    $documentsOpen = in_array($active, $documentsKeys, true);

    $billingKeys = [
        'factura',
        'facturi',
        'incasare',
        'incasari',
        'efactura',
        'interventii_facturare',
        'facturi_recurente',
    ];
    $billingOpen = in_array($active, $billingKeys, true);
    if ($billingOpen) {
        $active = 'facturare';
    }

    if ($isAdmin) {
        $mainBeforeDocuments = [
            'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
            'clients'   => ['label' => 'Clienți', 'href' => 'clients.php', 'icon' => 'clients'],
            'tasks'     => ['label' => 'Sarcini', 'href' => 'tasks.php', 'icon' => 'tasks'],
            'calendar'  => ['label' => 'Calendar', 'href' => 'calendar.php', 'icon' => 'calendar'],
        ];

        $documentItems = [
            // Ordonate dupa frecventa de folosire: PV-urile se emit dupa fiecare lucrare,
            // contractele recurent, ofertele inainte de contract, actele aditional ocazional,
            // arhiva la final (utilizare episodica).
            'procese_verbale' => ['label' => 'Procese verbale', 'href' => 'service-reports', 'icon' => 'processes'],
            'contracts' => ['label' => 'Contracte', 'href' => 'contracts.php', 'icon' => 'contracts'],
            'oferte' => ['label' => 'Oferte', 'href' => 'offers', 'icon' => 'offers'],
            'addenda' => ['label' => 'Acte adiționale', 'href' => 'addenda.php', 'icon' => 'contracts'],
            'documente' => ['label' => 'Arhivă documente', 'href' => 'documents', 'icon' => 'documents'],
            // Șabloane/Serii/Design PDF s-au mutat în Setări (erau dublate)
        ];

        $billingItems = [
            // Lista lucrărilor e punctul de plecare al facturării zilnice (vezi „De facturat"),
            // apoi emiterea facturii, apoi înregistrarea încasării.
            'interventii_facturare' => ['label' => 'Lista lucrări', 'href' => 'work_billing.php', 'icon' => 'processes'],
            'facturi' => ['label' => 'Facturi', 'href' => 'invoices.php', 'icon' => 'invoice'],
            'incasari' => ['label' => 'Încasări', 'href' => 'payments.php', 'icon' => 'invoice'],
        ];

        $mainAfterDocuments = [
            'stock'     => ['label' => 'Gestiune', 'href' => 'stock.php', 'icon' => 'stock'],
            'reports'   => ['label' => 'Rapoarte', 'href' => 'reports.php', 'icon' => 'reports'],
            'reminders' => ['label' => 'Reminders', 'href' => 'reminders.php', 'icon' => 'alert'],
            'review_feedback' => ['label' => 'Feedback', 'href' => 'review_feedback.php', 'icon' => 'star'],
            'settings'  => ['label' => 'Setări', 'href' => 'settings.php', 'icon' => 'settings'],
        ];
    } else {
        $mainBeforeDocuments = [
            'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
            'calendar'  => ['label' => 'Calendar', 'href' => 'calendar.php', 'icon' => 'calendar'],
        ];

        $documentItems = [];
        $billingItems = [];
        $mainAfterDocuments = [];
    }
    ?>

    <style>
    .nav-group {
        display: grid;
        gap: 4px;
    }

    .nav-group-button {
        width: 100%;
        border: 0;
        text-align: left;
        font-family: inherit;
        cursor: pointer;
    }

    .nav-group-button .nav-chevron {
        margin-left: auto;
        font-size: 13px;
        line-height: 1;
        opacity: .75;
        transition: transform .16s ease;
    }

    .nav-group-button.open .nav-chevron {
        transform: rotate(90deg);
    }

    .nav-submenu {
        display: none;
        flex-direction: column;
        gap: 4px;
        margin: 0 0 4px 0;
    }

    .nav-submenu.open {
        display: flex;
    }

    .nav-subitem {
        min-height: 36px;
        border-radius: 12px;
        padding: 0 10px 0 44px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: rgba(255, 255, 255, .68);
        font-size: 13px;
        font-weight: 400;
        transition: background .15s ease, color .15s ease;
    }

    .nav-subitem:hover {
        background: rgba(129, 140, 248, .10);
        color: #fff;
    }

    .nav-subitem.active {
        background: rgba(129, 140, 248, .18);
        color: #fff;
    }

    .nav-subitem .nav-icon {
        width: 17px;
        height: 17px;
        flex-basis: 17px;
    }

    .nav-subitem .nav-icon svg {
        width: 17px;
        height: 17px;
        stroke-width: 1.8;
    }
    
    /* Fix buton Documente - sa nu mai fie alb */
    .sidebar .nav-group-button,
    .sidebar .nav-group-button.nav-item {
        background: transparent !important;
        color: rgba(255, 255, 255, .82) !important;
        border: 0 !important;
        box-shadow: none !important;
        appearance: none !important;
        -webkit-appearance: none !important;
    }

    .sidebar .nav-group-button:hover {
        background: rgba(129, 140, 248, .10) !important;
        color: #ffffff !important;
    }

    .sidebar .nav-group-button.active,
    .sidebar .nav-group-button.open,
    .sidebar .nav-group-button.active.open {
        background: rgba(129, 140, 248, .18) !important;
        color: #ffffff !important;
    }

    .sidebar .nav-group-button.active .nav-label,
    .sidebar .nav-group-button.open .nav-label,
    .sidebar .nav-group-button.active svg,
    .sidebar .nav-group-button.open svg,
    .sidebar .nav-group-button.active .nav-chevron,
    .sidebar .nav-group-button.open .nav-chevron {
        color: #ffffff !important;
        stroke: #ffffff !important;
    }


    /* Liquid glass sidebar - Dynamics palette */
    .sidebar {
        isolation: isolate;
        background:
            radial-gradient(circle at 18% 8%, rgba(177,214,240,.20), transparent 26%),
            radial-gradient(circle at 92% 32%, rgba(17,96,183,.24), transparent 34%),
            linear-gradient(180deg, rgba(0,32,80,.98), rgba(0,32,80,.92) 54%, rgba(0,32,80,.98));
        border-right: 1px solid rgba(177,214,240,.20);
        box-shadow: 12px 0 34px -28px rgba(0,32,80,.95), inset -1px 0 0 rgba(255,255,255,.05);
        backdrop-filter: blur(18px) saturate(1.25);
        -webkit-backdrop-filter: blur(18px) saturate(1.25);
    }

    .sidebar::before {
        content: '';
        position: absolute;
        inset: 0;
        z-index: 0;
        pointer-events: none;
        background:
            linear-gradient(115deg, rgba(255,255,255,.18), transparent 24%, transparent 72%, rgba(177,214,240,.10)),
            linear-gradient(180deg, rgba(255,255,255,.08), transparent 18%);
        opacity: .75;
    }

    .sidebar::after {
        content: '';
        position: absolute;
        top: 110px;
        right: -70px;
        width: 150px;
        height: 330px;
        z-index: 0;
        pointer-events: none;
        border-radius: 999px;
        background: rgba(177,214,240,.14);
        filter: blur(35px);
        transform: rotate(-18deg);
    }

    .sidebar > * {
        position: relative;
        z-index: 1;
    }

    .sidebar-brand {
        border-bottom-color: rgba(177,214,240,.18);
    }

    .nav-item,
    .sidebar .nav-group-button,
    .sidebar .nav-group-button.nav-item {
        color: rgba(255,255,255,.78) !important;
    }

    .nav-item:hover,
    .sidebar .nav-group-button:hover {
        background: rgba(177,214,240,.12) !important;
        color: #ffffff !important;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.10);
    }

    .nav-item.active,
    .sidebar .nav-group-button.active,
    .sidebar .nav-group-button.open,
    .sidebar .nav-group-button.active.open {
        background: linear-gradient(135deg, rgba(177,214,240,.20), rgba(17,96,183,.22)) !important;
        color: #ffffff !important;
        border: 1px solid rgba(177,214,240,.16) !important;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.16), 0 12px 22px -18px rgba(177,214,240,.55) !important;
    }

    .nav-item.active::before {
        background: #B1D6F0 !important;
        box-shadow: 0 0 14px rgba(177,214,240,.75) !important;
    }

    .nav-subitem:hover,
    .nav-subitem.active {
        background: rgba(177,214,240,.12) !important;
        color: #ffffff !important;
    }

    .sidebar-footer {
        border-top-color: rgba(177,214,240,.18);
        background: linear-gradient(180deg, transparent, rgba(177,214,240,.06));
    }

    .sidebar-user {
        display: flex;
        align-items: baseline;
        gap: 5px;
        color: rgba(255,255,255,.70);
        font-size: 12px !important;
        line-height: 1.2;
        font-weight: 750;
    }

    .sidebar-user-label {
        flex: 0 0 auto;
        font-size: 11px;
        color: rgba(255,255,255,.62);
    }

    .sidebar-user-name {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 15px;
        font-weight: 900;
        color: #ffffff;
        letter-spacing: -.02em;
    }

    .logout-btn {
        background: rgba(177,214,240,.10);
        border-color: rgba(177,214,240,.24);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
    }

    .logout-btn:hover {
        background: rgba(177,214,240,.18);
        border-color: rgba(177,214,240,.34);
    }

    /* PestZone sidebar standard */
    .sidebar {
        background: var(--accent-deep) !important;
        border-right: 1px solid #0E2A49 !important;
        box-shadow: none !important;
        backdrop-filter: none !important;
        -webkit-backdrop-filter: none !important;
    }

    .sidebar::before,
    .sidebar::after {
        display: none !important;
    }

    .sidebar-brand {
        background: var(--accent-deep) !important;
        border-bottom: 1px solid rgba(255, 255, 255, .10) !important;
    }

    .sidebar-nav {
        gap: 2px !important;
        padding: 12px 10px !important;
    }

    .nav-item,
    .sidebar .nav-group-button,
    .sidebar .nav-group-button.nav-item,
    .nav-subitem {
        min-height: 36px !important;
        border: 1px solid transparent !important;
        border-radius: 6px !important;
        background: transparent !important;
        color: rgba(255, 255, 255, .78) !important;
        box-shadow: none !important;
        font-size: var(--type-sidebar) !important;
        font-weight: var(--type-weight-regular) !important;
        letter-spacing: 0 !important;
    }

    .nav-item:hover,
    .sidebar .nav-group-button:hover,
    .nav-subitem:hover {
        background: rgba(255, 255, 255, .08) !important;
        border-color: rgba(255, 255, 255, .08) !important;
        color: #FFFFFF !important;
        box-shadow: none !important;
    }

    .nav-item.active,
    .sidebar .nav-group-button.active,
    .sidebar .nav-group-button.open,
    .sidebar .nav-group-button.active.open,
    .nav-subitem.active {
        background: rgba(255, 255, 255, .13) !important;
        border-color: rgba(255, 255, 255, .16) !important;
        color: #FFFFFF !important;
        box-shadow: none !important;
        font-weight: var(--type-weight-medium) !important;
    }

    .nav-item.active::before {
        width: 2px !important;
        background: #60A5FA !important;
        box-shadow: none !important;
    }

    .nav-item svg,
    .sidebar .nav-group-button svg,
    .nav-subitem svg,
    .logout-btn svg {
        stroke: currentColor !important;
    }

    .nav-icon {
        color: inherit !important;
    }

    .nav-submenu {
        gap: 2px !important;
        margin: 2px 0 4px !important;
    }

    .nav-subitem {
        padding-left: 40px !important;
        font-size: var(--type-sidebar) !important;
        font-weight: var(--type-weight-regular) !important;
        color: rgba(255, 255, 255, .68) !important;
    }

    .nav-chevron {
        color: rgba(255, 255, 255, .58) !important;
    }

    .sidebar-footer {
        border-top: 1px solid rgba(255, 255, 255, .10) !important;
        background: var(--accent-deep) !important;
    }

    .sidebar-user {
        color: rgba(255, 255, 255, .68) !important;
        font-size: 12px !important;
        font-weight: var(--type-weight-regular) !important;
    }

    .sidebar-user-label {
        color: rgba(255, 255, 255, .58) !important;
        font-size: 11px !important;
    }

    .sidebar-user-name {
        color: #FFFFFF !important;
        font-size: 13px !important;
        font-weight: var(--type-weight-medium) !important;
    }

    .logout-btn {
        min-height: 34px !important;
        border: 1px solid rgba(255, 255, 255, .14) !important;
        border-radius: 6px !important;
        background: rgba(255, 255, 255, .06) !important;
        color: rgba(255, 255, 255, .84) !important;
        font-weight: var(--type-weight-medium) !important;
        box-shadow: none !important;
    }

    .logout-btn:hover {
        background: rgba(255, 255, 255, .10) !important;
        border-color: rgba(255, 255, 255, .18) !important;
        color: #FFFFFF !important;
    }

    /* ============================================================
       emma.ro Sidebar — Navy + Coral accent
       Override final care preia controlul peste toate stilurile de mai sus.
       Folosește tokens --em-* / --pz-* din app_theme_css.php
       ============================================================ */

    .sidebar {
        background: var(--em-navy) !important;
        border-right: none !important;
        border-radius: var(--shell-radius) !important;
        box-shadow: 0 12px 32px -18px rgba(6, 17, 66, .45) !important;
        backdrop-filter: none !important;
        -webkit-backdrop-filter: none !important;
    }
    .sidebar::before, .sidebar::after { display: none !important; }

    .sidebar-brand {
        background: var(--em-navy) !important;
        border-bottom: 1px solid var(--em-navy-soft) !important;
        padding: 22px 14px 20px !important;
    }
    /* Logo pe culorile lui naturale (fără filtre sau recolorare) */
    .brand-logo, .brand-logo-link { color: var(--pz-brand) !important; }
    .sidebar .brand-logo-link { min-height: 120px !important; }
    .sidebar .brand-logo { width: 190px !important; max-height: 110px !important; }

    .sidebar-nav {
        gap: 2px !important;
        padding: 12px 10px !important;
    }

    /* Nav items */
    .nav-item,
    .sidebar .nav-group-button,
    .sidebar .nav-group-button.nav-item,
    .nav-subitem {
        min-height: 34px !important;
        border: 1px solid transparent !important;
        border-radius: 7px !important;
        background: transparent !important;
        color: rgba(255, 255, 255, .72) !important;
        box-shadow: none !important;
        font-size: 13px !important;
        font-weight: 400 !important;
        letter-spacing: 0 !important;
        padding: 6px 10px !important;
        gap: 9px !important;
        transition: background .15s ease, color .15s ease, border-color .15s ease !important;
    }

    .nav-item:hover,
    .sidebar .nav-group-button:hover,
    .nav-subitem:hover {
        background: rgba(255, 255, 255, .08) !important;
        border-color: transparent !important;
        color: #FFFFFF !important;
        box-shadow: none !important;
    }

    .nav-item.active,
    .sidebar .nav-group-button.active,
    .sidebar .nav-group-button.open,
    .sidebar .nav-group-button.active.open,
    .nav-subitem.active {
        background: var(--em-coral-gradient-h) !important;
        border-color: transparent !important;
        color: #FFFFFF !important;
        box-shadow: 0 6px 16px -8px rgba(255, 90, 95, .55) !important;
        font-weight: 500 !important;
    }

    /* Text + iconuri albe pe gradient coral pentru orice variantă activă/open */
    .sidebar .nav-group-button.active .nav-label,
    .sidebar .nav-group-button.open .nav-label,
    .sidebar .nav-group-button.active svg,
    .sidebar .nav-group-button.open svg,
    .sidebar .nav-group-button.active .nav-chevron,
    .sidebar .nav-group-button.open .nav-chevron,
    .sidebar .nav-item.active .nav-label,
    .sidebar .nav-item.active svg,
    .sidebar .nav-subitem.active .nav-label,
    .sidebar .nav-subitem.active svg {
        color: #FFFFFF !important;
        stroke: #FFFFFF !important;
    }

    /* Eliminăm bara verticală stângă — gradientul coral e deja suficient marker */
    .nav-item.active::before {
        display: none !important;
    }

    .nav-item svg,
    .sidebar .nav-group-button svg,
    .nav-subitem svg,
    .logout-btn svg {
        stroke: currentColor !important;
        opacity: 1 !important;
    }

    .nav-icon { color: inherit !important; }

    .nav-submenu {
        gap: 2px !important;
        margin: 2px 0 6px !important;
    }

    .nav-subitem {
        padding-left: 38px !important;
        font-size: 12.5px !important;
        font-weight: 400 !important;
        color: rgba(255, 255, 255, .62) !important;
        min-height: 30px !important;
    }
    .nav-subitem:hover { color: #FFFFFF !important; background: rgba(255,255,255,.06) !important; }
    .nav-subitem.active {
        background: rgba(255, 122, 61, .18) !important;
        color: #FFFFFF !important;
        font-weight: 500 !important;
        box-shadow: none !important;
    }

    .nav-chevron { color: rgba(255, 255, 255, .48) !important; }

    .sidebar-footer {
        border-top: 1px solid var(--em-navy-soft) !important;
        background: var(--em-navy) !important;
        padding: 12px 14px !important;
    }

    .sidebar-user {
        color: rgba(255, 255, 255, .68) !important;
        font-size: 12px !important;
        font-weight: 400 !important;
    }
    .sidebar-user-label {
        color: rgba(255, 255, 255, .52) !important;
        font-size: 10.5px !important;
    }
    .sidebar-user-name {
        color: #FFFFFF !important;
        font-size: 13px !important;
        font-weight: 500 !important;
    }

    .logout-btn {
        min-height: 32px !important;
        border: 1px solid rgba(255, 255, 255, .14) !important;
        border-radius: 7px !important;
        background: rgba(255, 255, 255, .04) !important;
        color: rgba(255, 255, 255, .78) !important;
        font-weight: 400 !important;
        font-size: 12.5px !important;
        box-shadow: none !important;
    }
    .logout-btn:hover {
        background: rgba(255, 90, 95, .14) !important;
        border-color: rgba(255, 90, 95, .35) !important;
        color: #FFFFFF !important;
    }

    /* Mobile menu button - keep light scheme */
    .mobile-menu-button {
        background: var(--pz-surf) !important;
        border: 1px solid var(--pz-line) !important;
        color: var(--pz-title) !important;
    }
    .mobile-menu-button span { background: var(--pz-title) !important; }

</style>

    <button class="mobile-menu-button" id="mobileMenuButton" type="button" aria-label="Meniu" aria-expanded="false" onclick="toggleAppSidebar()">
        <span></span>
    </button>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeAppSidebar()"></div>

    <aside class="sidebar" id="appSidebar" aria-label="Meniu principal">
        <div class="sidebar-brand">
            <a class="brand-logo-link" href="dashboard.php" aria-label="Dashboard">
                <?= app_brand_logo('brand-logo', 'white') ?>
            </a>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($mainBeforeDocuments as $key => $item): ?>
                <a class="nav-item <?= $active === $key ? 'active' : '' ?>" href="<?= app_h($item['href']) ?>">
                    <?= app_icon_svg($item['icon']) ?>
                    <span class="nav-label"><?= app_h($item['label']) ?></span>
                </a>
            <?php endforeach; ?>

            <?php if ($isAdmin): ?>
                <div class="nav-group">
                    <button
                        class="nav-item nav-group-button <?= $documentsOpen ? 'active open' : '' ?>"
                        type="button"
                        onclick="toggleDocumentsMenu()"
                        aria-expanded="<?= $documentsOpen ? 'true' : 'false' ?>"
                        id="documentsMenuButton"
                    >
                        <?= app_icon_svg('contracts') ?>
                        <span class="nav-label">Documente</span>
                        <span class="nav-chevron">›</span>
                    </button>

                    <div class="nav-submenu <?= $documentsOpen ? 'open' : '' ?>" id="documentsSubmenu">
                        <?php foreach ($documentItems as $key => $item): ?>
                            <a class="nav-subitem <?= $active === $key ? 'active' : '' ?>" href="<?= app_h($item['href']) ?>">
                                <?= app_icon_svg($item['icon']) ?>
                                <span class="nav-label"><?= app_h($item['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <div class="nav-group">
                    <button
                        class="nav-item nav-group-button <?= $billingOpen ? 'active open' : '' ?>"
                        type="button"
                        onclick="toggleBillingMenu()"
                        aria-expanded="<?= $billingOpen ? 'true' : 'false' ?>"
                        id="billingMenuButton"
                    >
                        <?= app_icon_svg('invoice') ?>
                        <span class="nav-label">Financiar</span>
                        <span class="nav-chevron">›</span>
                    </button>

                    <div class="nav-submenu <?= $billingOpen ? 'open' : '' ?>" id="billingSubmenu">
                        <?php foreach ($billingItems as $key => $item): ?>
                            <?php $billingSubActive = $originalActive === $key || ($originalActive === 'factura' && $key === 'facturi') || ($originalActive === 'incasare' && $key === 'incasari'); ?>
                            <a class="nav-subitem <?= $billingSubActive ? 'active' : '' ?>" href="<?= app_h($item['href']) ?>">
                                <?= app_icon_svg($item['icon']) ?>
                                <span class="nav-label"><?= app_h($item['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($mainAfterDocuments as $key => $item): ?>
                <a class="nav-item <?= $active === $key ? 'active' : '' ?>" href="<?= app_h($item['href']) ?>">
                    <?= app_icon_svg($item['icon']) ?>
                    <span class="nav-label"><?= app_h($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <span class="sidebar-user-label">Logat:</span>
                <span class="sidebar-user-name"><?= app_h($userName) ?></span>
            </div>

            <a class="logout-btn" href="logout.php">
                <?= app_icon_svg('logout') ?>
                <span>Ieșire</span>
            </a>
        </div>
    </aside>

    <?php render_mobile_app_header(); ?>

    <?php
    // ============================================================
    // TOPBAR GLOBAL - se afiseaza automat pe orice pagina care
    // folosește render_sidebar(). Titlul se deriva din $active.
    // Pentru a-l dezactiva pe o pagina anume:
    //   $pz_skip_topbar = true;  (înainte de render_sidebar)
    // Pentru a suprascrie titlul:
    //   $pz_page_title = 'Titlu custom';
    //   $pz_page_breadcrumbs = ['Crumb 1'];
    // ============================================================
    global $pz_skip_topbar, $pz_page_title, $pz_page_breadcrumbs, $pz_topbar_opts;

    // Topbar (search, notificări, +) e doar pentru admin.
    // Tehnicienii văd doar sidebar + conținut, fără toolbar global.
    if (empty($pz_skip_topbar) && $isAdmin) {
        $pz_title_map = [
            // pagini principale
            'dashboard'              => ['Dashboard',  []],
            'calendar'               => ['Calendar',   []],
            'tasks'                  => ['Sarcini',    []],
            'clients'                => ['Clienți',    []],
            'stock'                  => ['Gestiune',   []],
            'stock_products'         => ['Gestiune',   ['Produse']],
            'stock_receipts'         => ['Gestiune',   ['Intrări']],
            'stock_movements'        => ['Gestiune',   ['Mișcări']],
            'stock_inventory'        => ['Gestiune',   ['Inventar fizic']],
            'stock_deferred_pvs'     => ['Gestiune',   ['PV fără consum']],
            'stock_notifications'    => ['Gestiune',   ['Notificări']],
            'stock_card'             => ['Gestiune',   ['Fișa magazie']],
            'stock_work_registry'    => ['Gestiune',   ['Registru lucrări']],
            'facturare'              => ['Financiar',  ['Facturi']],
            'factura'                => ['Financiar',  ['Facturi', 'Factură']],
            'interventii_facturare'  => ['Financiar',  ['Lista lucrări']],
            'facturi'                => ['Financiar',  ['Facturi']],
            'incasare'               => ['Financiar',  ['Încasări', 'Încasare']],
            'incasari'               => ['Financiar',  ['Încasări']],
            'efactura'               => ['Financiar',  ['E-Factura']],
            'facturi_recurente'      => ['Recurente',  ['Facturi automate']],
            'reports'                => ['Rapoarte',   []],
            'review_feedback'        => ['Feedback',   []],
            'settings'               => ['Setări',     []],

            // submeniu Documente
            'documente'              => ['Documente',  ['Arhivă']],
            'oferte'                 => ['Documente',  ['Oferte']],
            'contracts'              => ['Documente',  ['Contracte']],
            'addenda'                => ['Documente',  ['Acte adiționale']],
            'procese_verbale'        => ['Documente',  ['Procese verbale']],

            // sub-pagini Setări
            'users'                  => ['Setări',     ['Utilizatori']],
            'services'               => ['Setări',     ['Servicii']],
            'team'                   => ['Setări',     ['Tehnicieni']],
            'company_settings'       => ['Setări',     ['Compania mea']],
            'document_templates'     => ['Setări',     ['Șabloane documente']],
            'document_series'        => ['Setări',     ['Serii documente']],
            'document_design'        => ['Setări',     ['Design documente']],
            'communication_settings' => ['Setări',     ['Comunicare / Integrări']],
            'smartbill_settings'     => ['Setări',     ['SmartBill']],
            'email_templates'        => ['Setări',     ['Șabloane email']],
            'sms_templates'          => ['Setări',     ['Șabloane SMS']],
            'sms_activity'           => ['Setări',     ['Activitate SMS']],
            'email_activity'         => ['Setări',     ['Activitate Email']],
            'data_import'            => ['Setări',     ['Import date']],
            'style_guide'            => ['Ghid de stil', ['Identitate internă']],
        ];

        // Caută în titluri folosind cheia originală, înainte de gruparea vizuală în sidebar.
        $pz_active_lookup = isset($pz_title_map[$originalActive]) ? $originalActive : (isset($pz_title_map[$active]) ? $active : 'dashboard');
        list($pz_t, $pz_b) = $pz_title_map[$pz_active_lookup];

        // Permite override din pagina
        $pz_t = $pz_page_title       ?? $pz_t;
        $pz_b = $pz_page_breadcrumbs ?? $pz_b;
        $pz_o = is_array($pz_topbar_opts ?? null) ? $pz_topbar_opts : [];

        if (function_exists('app_topbar')) {
            app_topbar($pz_t, $pz_b, $pz_o);
        }
    }
    ?>

    <script>
    function setAppSidebarState(isOpen) {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const button = document.getElementById('mobileMenuButton');

        if (!sidebar || !overlay) {
            return;
        }

        sidebar.classList.toggle('open', isOpen);
        overlay.classList.toggle('open', isOpen);
        document.body.classList.toggle('app-sidebar-open', isOpen);

        if (button) {
            button.classList.toggle('is-open', isOpen);
            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
    }

    function toggleAppSidebar() {
        const sidebar = document.getElementById('appSidebar');

        if (!sidebar) {
            return;
        }

        setAppSidebarState(!sidebar.classList.contains('open'));
    }

    function closeAppSidebar() {
        setAppSidebarState(false);
    }

    function toggleDocumentsMenu() {
        const submenu = document.getElementById('documentsSubmenu');
        const button = document.getElementById('documentsMenuButton');

        if (!submenu || !button) {
            return;
        }

        const isOpen = submenu.classList.toggle('open');
        button.classList.toggle('open', isOpen);
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');


    }

    function toggleBillingMenu() {
        const submenu = document.getElementById('billingSubmenu');
        const button = document.getElementById('billingMenuButton');

        if (!submenu || !button) {
            return;
        }

        const isOpen = submenu.classList.toggle('open');
        button.classList.toggle('open', isOpen);
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    document.addEventListener('DOMContentLoaded', function() {
        const submenu = document.getElementById('documentsSubmenu');
        const button = document.getElementById('documentsMenuButton');

        if (!submenu || !button) {
            return;
        }

        const hasActiveChild = submenu.querySelector('.nav-subitem.active');

        if (hasActiveChild) {
            submenu.classList.add('open');
            button.classList.add('open');
            button.setAttribute('aria-expanded', 'true');
        } else {
            submenu.classList.remove('open');
            button.classList.remove('open');
            button.setAttribute('aria-expanded', 'false');
        }

        try {
            localStorage.removeItem('pz_documents_menu_open');
        } catch (e) {}
    });

    document.addEventListener('DOMContentLoaded', function() {
        const submenu = document.getElementById('billingSubmenu');
        const button = document.getElementById('billingMenuButton');

        if (!submenu || !button) {
            return;
        }

        const hasActiveChild = submenu.querySelector('.nav-subitem.active');

        if (hasActiveChild) {
            submenu.classList.add('open');
            button.classList.add('open');
            button.setAttribute('aria-expanded', 'true');
        } else {
            submenu.classList.remove('open');
            button.classList.remove('open');
            button.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeAppSidebar();
        }
    });

    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('appSidebar');
        const button = document.getElementById('mobileMenuButton');

        if (!sidebar || !button || !sidebar.classList.contains('open')) {
            return;
        }

        if (sidebar.contains(event.target) || button.contains(event.target)) {
            return;
        }

        closeAppSidebar();
    });
    </script>
    <?php
}


}

