<?php

/*
|--------------------------------------------------------------------------
| UI global
|--------------------------------------------------------------------------
| Fisier global pentru design, meniu si stiluri comune.
| Texte UTF-8, cu diacritice pastrate.
| Iconuri SVG line, fără biblioteci externe.
|--------------------------------------------------------------------------
*/

if (!function_exists('app_h')) {
    function app_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('render_billing_module_nav')) {
    function render_billing_module_nav(string $active = ''): void
    {
        $items = [
            'facturi' => ['label' => 'Facturare', 'href' => 'facturi.php'],
            'incasari' => ['label' => 'Încasare', 'href' => 'incasari.php'],
            'efactura' => ['label' => 'E-Factura', 'href' => 'efactura.php'],
            'interventii_facturare' => ['label' => 'Lista lucrări', 'href' => 'interventii_facturare.php'],
        ];
        ?>
        <style>
        .billing-module-nav{grid-column:1/-1;display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 14px}
        .billing-module-nav a{min-height:34px;padding:7px 11px;border-radius:4px;border:1px solid var(--border);background:var(--surface);color:var(--muted);font-size:12.5px;font-weight:700;box-shadow:none;text-decoration:none}
        .billing-module-nav a:hover{color:var(--text);border-color:var(--accent-pale)}
        .billing-module-nav a.active{background:var(--accent);border-color:var(--accent);color:#fff;box-shadow:none}
        @media(max-width:720px){.billing-module-nav{display:grid;grid-template-columns:1fr 1fr}.billing-module-nav a{text-align:center}}
        </style>
        <nav class="billing-module-nav" aria-label="Navigare facturare">
            <?php foreach ($items as $key => $item): ?>
                <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= app_h($item['href']) ?>"><?= app_h($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }
}

if (!function_exists('render_stock_module_nav')) {
    function render_stock_module_nav(string $active = ''): void
    {
        $items = [
            'dashboard' => ['label' => 'Dashboard', 'href' => 'stock.php'],
            'products' => ['label' => 'Produse', 'href' => 'stock_products.php'],
            'receipts' => ['label' => 'Intrări', 'href' => 'stock_receipts.php'],
            'movements' => ['label' => 'Ieșiri', 'href' => 'stock_movements.php'],
            'notifications' => ['label' => 'Notificări', 'href' => 'stock_notifications.php'],
            'card' => ['label' => 'Fișa magazie', 'href' => 'stock_card.php'],
            'registry' => ['label' => 'Registru lucrări', 'href' => 'stock_work_registry.php'],
        ];
        ?>
        <nav class="stock-tabs" aria-label="Navigare gestiune">
            <?php foreach ($items as $key => $item): ?>
                <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= app_h($item['href']) ?>"><?= app_h($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }
}

if (!function_exists('app_icon_svg')) {
    function app_icon_svg(string $name): string
    {
        $aliases = [
            'task' => 'tasks',
            'appointment' => 'calendar',
            'appointments' => 'calendar',
            'client' => 'clients',
            'document' => 'documents',
            'contract' => 'contracts',
            'process' => 'processes',
            'report' => 'reports',
            'billing' => 'invoice',
            'invoice_paid' => 'invoice',
            'notification' => 'alert',
            'notifications' => 'alert',
        ];
        $name = $aliases[$name] ?? $name;

        $icons = [
            'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="2"></rect><rect x="13" y="3" width="8" height="5" rx="2"></rect><rect x="13" y="10" width="8" height="11" rx="2"></rect><rect x="3" y="13" width="8" height="8" rx="2"></rect></svg>',
            'calendar'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="4"></rect><path d="M8 2v4"></path><path d="M16 2v4"></path><path d="M3 10h18"></path><path d="M8 14h3"></path><path d="M13 14h3"></path><path d="M8 18h3"></path></svg>',
            'tasks'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="4"></rect><path d="M8 8h8"></path><path d="M8 12h8"></path><path d="M8 16h5"></path><path d="M16.5 15.5l1.2 1.2 2.3-2.7"></path></svg>',
            'clients'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4"></path><circle cx="12" cy="9" r="3"></circle><path d="M4.5 18.5c.4-2.1 1.7-3.8 3.5-4.8"></path><path d="M19.5 18.5c-.4-2.1-1.7-3.8-3.5-4.8"></path></svg>',
            'contracts' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="3"></rect><path d="M9 8h6"></path><path d="M9 12h6"></path><path d="M9 16h3"></path><path d="M16 17l1 1 2-2"></path></svg>',
            'documents' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l3 3v15H6z"></path><path d="M15 3v4h4"></path><path d="M9 10h6"></path><path d="M9 14h6"></path><path d="M9 18h4"></path></svg>',
            'offers'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="3"></rect><path d="M8 9h8"></path><path d="M8 13h5"></path><path d="M16 15.5l2 2 3-4"></path><path d="M7 3v4"></path><path d="M17 3v4"></path></svg>',
            'processes' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="3"></rect><path d="M9 7h6"></path><path d="M9 11h6"></path><path d="M9 15h3"></path><path d="M14 17l1.5 1.5L19 15"></path></svg>',
            'series'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="3"></rect><path d="M8 9h8"></path><path d="M8 13h4"></path><path d="M15 13h1.5"></path><path d="M7 3v4"></path><path d="M17 3v4"></path></svg>',
            'services'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.4 3.2 3.5.4-2.6 2.3.7 3.4-3-1.8-3 1.8.7-3.4-2.6-2.3 3.5-.4L12 3z"></path><path d="M5 15h14"></path><path d="M7 19h10"></path></svg>',
            'team'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19c.4-3 2.6-5 5.5-5s5.1 2 5.5 5"></path><circle cx="17" cy="10" r="2.5"></circle><path d="M15.5 15c2.5.2 4.4 1.8 5 4"></path></svg>',
            'reports'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="4"></rect><path d="M8 17V11"></path><path d="M12 17V7"></path><path d="M16 17v-4"></path><path d="M7 17h10"></path></svg>',
            'star'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 16.8l-5.2 2.7 1-5.8-4.2-4.1 5.8-.8L12 3.5Z"></path></svg>',
            'users'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19c.5-3 2.7-5 5.5-5s5 2 5.5 5"></path><circle cx="17" cy="9" r="2.5"></circle><path d="M15.5 15c2.4.2 4.2 1.8 5 4"></path></svg>',
            'design'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 0 0-9 9c0 4.4 3.6 8 8 8h1.5a2 2 0 0 0 0-4H12a1.5 1.5 0 0 1 0-3h1a8 8 0 0 0 8-8c0-1.1-.9-2-2-2h-7z"></path><circle cx="7.5" cy="10" r=".8"></circle><circle cx="10.5" cy="7.5" r=".8"></circle><circle cx="14" cy="7.5" r=".8"></circle></svg>',
            'company'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V7a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v14"></path><path d="M15 10h3a2 2 0 0 1 2 2v9"></path><path d="M8 9h3"></path><path d="M8 13h3"></path><path d="M8 17h3"></path><path d="M3 21h18"></path></svg>',
            'settings'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M12 2v3"></path><path d="M12 19v3"></path><path d="M2 12h3"></path><path d="M19 12h3"></path><path d="M4.9 4.9l2.1 2.1"></path><path d="M17 17l2.1 2.1"></path><path d="M19.1 4.9L17 7"></path><path d="M7 17l-2.1 2.1"></path></svg>',
            'invoice'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-2-1.3-2 1.3-2-1.3-2 1.3-2-1.3L6 21V3Z"></path><path d="M9 8h6"></path><path d="M9 12h6"></path><path d="M9 16h4"></path></svg>',
            'stock'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7l8-4 8 4-8 4-8-4Z"></path><path d="M4 7v10l8 4 8-4V7"></path><path d="M12 11v10"></path><path d="M20 12l-8 4-8-4"></path></svg>',

            'plus'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>',
            'eye'       => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="3"></circle></svg>',
            'edit'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Z"></path><path d="M13.5 7.5l3 3"></path></svg>',
            'mail'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"></rect><path d="M4 7l8 6 8-6"></path></svg>',
            'phone'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.4 19.4 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.7.6 2.5a2 2 0 0 1-.4 2.1L8 9.6a16 16 0 0 0 6.4 6.4l1.3-1.3a2 2 0 0 1 2.1-.4c.8.3 1.6.5 2.5.6A2 2 0 0 1 22 16.9Z"></path></svg>',
            'search'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>',
            'more'      => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>',
            'check'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg>',
            'alert'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l9 16H3L12 4Z"></path><path d="M12 9v5"></path><path d="M12 17h.01"></path></svg>',
            'clipboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="3"></rect><path d="M9 4.5A3 3 0 0 1 12 2a3 3 0 0 1 3 2.5"></path><path d="M9 9h6"></path><path d="M9 13h6"></path><path d="M9 17h3"></path></svg>',
            'logout'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H6.5A2.5 2.5 0 0 0 4 6.5v11A2.5 2.5 0 0 0 6.5 20H10"></path><path d="M14 8l4 4-4 4"></path><path d="M18 12H9"></path></svg>',
        ];

        $svg = $icons[$name] ?? $icons['dashboard'];

        return '<span class="nav-icon nav-icon-' . app_h($name) . '">' . $svg . '</span>';
    }
}

if (!function_exists('app_theme_css')) {
    function app_theme_css(): void
    {
        ?>
        <style>
        @import url("https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap");

        :root {
            /* === Suprafete === */
            --bg: #F8FAFC;
            --surface: #FFFFFF;
            --surface-soft: #F8FAFC;
            --surface-strong: #E2E8F0;
            --surface-muted: #F1F5F9;

            /* === Text === */
            --text: #0F172A;
            --text-body: #334155;
            --muted: #64748B;
            --muted-2: #94A3B8;

            /* === Brand PestZone === */
            --accent:        #2563EB;
            --accent-strong: #1E3A8A;
            --accent-deep:   #12345A;
            --accent-orange: #9A3412;
            --accent-pale:   #BFDBFE;
            --accent-soft:   rgba(37, 99, 235, .08);
            --accent-soft-2: rgba(37, 99, 235, .18);

            /* === Borduri === */
            --border: #E2E8F0;
            --border2: #F1F5F9;

            /* === Tone semantice (FIX: înainte erau monocrome/sparte) === */
            --tone-danger:        #DC2626;
            --tone-danger-soft:   #FEE2E2;
            --tone-danger-bg:     #FEF2F2;
            --tone-warning:       #9A3412;
            --tone-warning-soft:  #FFF7ED;
            --tone-warning-bg:    #FFF7ED;
            --tone-success:       #166534;
            --tone-success-soft:  #F0FDF4;
            --tone-success-bg:    #F0FDF4;
            --tone-info:          #2563EB;
            --tone-info-soft:     #EFF6FF;
            --tone-info-bg:       #EFF6FF;
            --tone-neutral-bg:    #F8FAFC;

            /* === Aliasuri pentru compatibilitate cu codul vechi === */
            --danger:       var(--tone-danger);
            --danger-soft:  var(--tone-danger-bg);
            --success:      var(--tone-success);
            --success-soft: var(--tone-success-bg);
            --warning:      var(--tone-warning);
            --warning-soft: var(--tone-warning-bg);

            /* === Geometrie === */
            --radius-sm: 4px;
            --radius: 6px;
            --radius-lg: 8px;
            --radius-xl: 8px;

            /* === Shadows dezactivate pentru UI plat === */
            --shadow: none;
            --shadow-md: none;
            --shadow-lg: none;
            --shadow-accent: none;

            /* === Focus ring (matched la indigo accent) === */
            --focus-ring: 0 0 0 4px rgba(17, 96, 183, .18);

            /* === Tipografie === */
            --font: "IBM Plex Sans", "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            --mono: "DM Mono", ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;

            /* === Layout === */
            --sidebar-width: 230px;
            --topbar-height: 60px;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            max-width: 100%;
            min-height: 100%;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            background: var(--bg);
            color: var(--text);
            font-family: var(--font);
            line-height: 1.35;
            -webkit-text-size-adjust: 100%;
            text-rendering: geometricPrecision;
        }

        body {
            touch-action: manipulation;
        }

        body.app-sidebar-open {
            overflow: hidden;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .is-hidden {
            display: none !important;
        }

        button,
        input,
        select,
        textarea {
            font-family: inherit;
            max-width: 100%;
        }

        button {
            cursor: pointer;
        }
        /* Click fix: prevent invisible mobile overlay from blocking desktop/main buttons */
        .main, .content {
            position: relative;
            z-index: 1;
        }
        .sidebar-overlay {
            pointer-events: none !important;
        }
        .sidebar-overlay.open {
            pointer-events: auto !important;
        }
        a, button, .btn, input, select, textarea {
            pointer-events: auto;
        }


        img,
        svg {
            max-width: 100%;
        }

        .layout {
            width: 100%;
            max-width: 100%;
            min-height: 100vh;
            display: flex;
            background: var(--bg);
            overflow-x: hidden;
        }

        .main {
            position: relative;
            z-index: 1;
            flex: 1 1 auto;
            min-width: 0;
            max-width: 100%;
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .content {
            width: 100%;
            max-width: 100%;
            padding: 18px 20px 24px;
            overflow-x: hidden;
        }

        .topbar {
            width: 100%;
            max-width: 100%;
            min-height: 66px;
            background: rgba(255, 255, 255, .92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 40;
            display: flex;
            align-items: center;
            gap: 12px;
            overflow-x: hidden;
        }

        .app-mobile-header {
            display: none;
        }

        /* Sidebar */

        .mobile-menu-button {
            display: none;
            position: fixed;
            top: 12px;
            left: 12px;
            z-index: 160;
            width: 44px;
            height: 44px;
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, .16);
            background: var(--accent);
            color: #fff;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-lg);
        }

        .mobile-menu-button span {
            width: 18px;
            height: 2px;
            background: #fff;
            display: block;
            position: relative;
            border-radius: 999px;
            transition: background .14s ease;
        }

        .mobile-menu-button span::before,
        .mobile-menu-button span::after {
            content: "";
            position: absolute;
            left: 0;
            width: 18px;
            height: 2px;
            background: #fff;
            border-radius: 999px;
            transition: transform .14s ease, top .14s ease;
        }

        .mobile-menu-button span::before {
            top: -6px;
        }

        .mobile-menu-button span::after {
            top: 6px;
        }

        .mobile-menu-button.is-open span {
            background: transparent;
        }

        .mobile-menu-button.is-open span::before {
            top: 0;
            transform: rotate(45deg);
        }

        .mobile-menu-button.is-open span::after {
            top: 0;
            transform: rotate(-45deg);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(16, 36, 62, .46);
            z-index: 90;
            pointer-events: none;
        }

        .sidebar-overlay.open {
            display: block;
            pointer-events: auto;
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            height: 100dvh;
            max-height: 100dvh;
            overflow: hidden;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 100;
            background: var(--accent-deep);
            color: #fff;
            display: flex;
            flex-direction: column;
            border-right: 1px solid rgba(255, 255, 255, .06);
            box-shadow: 4px 0 12px rgba(15, 23, 42, .06);
        }

        .sidebar-brand {
            padding: 18px 16px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, .10);
            flex: 0 0 auto;
        }

        .brand-logo-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 88px;
            text-decoration: none;
        }

        .brand-logo {
            width: 76px;
            height: 76px;
            max-width: 76px;
            max-height: 76px;
            display: block;
            object-fit: contain;
            filter: none;
        }

        .brand-fallback {
            width: 66px;
            height: 66px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, .24);
            background: rgba(255, 255, 255, .08);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -.04em;
            line-height: 1;
        }

        .brand-mark,
        .brand-title,
        .brand-subtitle {
            display: none;
        }

        .sidebar-nav {
            padding: 14px 10px;
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .nav-item {
            position: relative;
            min-height: 40px;
            border-radius: 10px;
            padding: 0 12px;
            display: flex;
            align-items: center;
            gap: 11px;
            color: rgba(255, 255, 255, .68);
            font-size: 13.5px;
            font-weight: 600;
            letter-spacing: -.005em;
            transition: background .14s ease, color .14s ease, transform .12s ease;
        }

        .nav-item:hover {
            background: rgba(129, 140, 248, .10);
            color: #fff;
        }

        .nav-item.active {
            background: rgba(129, 140, 248, .18);
            color: #fff;
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: -10px;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 22px;
            border-radius: 0 3px 3px 0;
            background: #818CF8;
            opacity: 1;
            box-shadow: 0 0 10px rgba(129, 140, 248, .55);
        }

        .nav-item:active {
            transform: translateY(1px);
        }

        .nav-icon {
            width: 20px;
            height: 20px;
            flex: 0 0 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: currentColor;
            opacity: .98;
        }

        .nav-icon svg {
            width: 20px;
            height: 20px;
            display: block;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.7;
            stroke-linecap: round;
            stroke-linejoin: round;
            vector-effect: non-scaling-stroke;
        }

        .nav-label {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-footer {
            border-top: 1px solid rgba(255, 255, 255, .10);
            padding: 14px 12px;
            padding-bottom: max(14px, env(safe-area-inset-bottom));
            flex: 0 0 auto;
        }

        .sidebar-user {
            color: rgba(255, 255, 255, .68);
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 10px;
            padding: 0 4px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .logout-btn {
            width: 100%;
            min-height: 40px;
            border: 1px solid rgba(129, 140, 248, .25);
            border-radius: 12px;
            background: rgba(129, 140, 248, .12);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            transition: background .14s ease, border-color .14s ease;
        }

        .logout-btn:hover {
            background: rgba(129, 140, 248, .22);
            border-color: rgba(129, 140, 248, .40);
        }

        .logout-btn .nav-icon {
            width: 18px;
            height: 18px;
            flex-basis: 18px;
        }

        .logout-btn .nav-icon svg {
            width: 18px;
            height: 18px;
            stroke-width: 2;
        }


        /* Sidebar compact - icon stanga, text pe acelasi rand, culoare brand #21005b */
        @media(min-width: 861px) {
            .sidebar {
                background: #21005b;
                box-shadow: 4px 0 14px rgba(33, 0, 91, .16);
            }

            .sidebar-brand {
                padding: 14px 8px 12px;
            }

            .brand-logo-link {
                min-height: 58px;
            }

            .brand-logo {
                width: 58px;
                height: 58px;
                max-width: 58px;
                max-height: 58px;
            }

            .brand-fallback {
                width: 52px;
                height: 52px;
                border-radius: 16px;
                font-size: 18px;
            }

            .sidebar-nav {
                padding: 10px 7px;
                gap: 6px;
            }

            .nav-item {
                min-height: 42px;
                padding: 0 12px;
                flex-direction: row;
                align-items: center;
                justify-content: flex-start;
                gap: 10px;
                text-align: left;
                border-radius: 13px;
                font-size: 9px;
                line-height: 1.15;
                font-weight: 750;
                letter-spacing: -.01em;
            }

            .nav-item:hover {
                background: rgba(255, 255, 255, .10);
            }

            .nav-item.active {
                background: rgba(255, 255, 255, .15);
            }

            .nav-item.active::before {
                left: 0;
                width: 3px;
                height: 24px;
                background: #cbb8ff;
                box-shadow: 0 0 10px rgba(203, 184, 255, .55);
            }

            .nav-icon {
                width: 22px;
                height: 22px;
                flex: 0 0 22px;
            }

            .nav-icon svg {
                width: 22px;
                height: 22px;
                stroke-width: 1.75;
            }

            .nav-label {
                min-width: 0;
                max-width: 100%;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                display: block;
            }

            .nav-group {
                gap: 5px;
            }

            .nav-group-button .nav-chevron {
                position: static;
                margin-left: auto;
                font-size: 11px;
                flex: 0 0 auto;
            }

            .nav-submenu {
                gap: 5px;
                margin: 0 0 4px 0;
            }

            .nav-subitem {
                min-height: 38px;
                padding: 0 10px 0 22px;
                flex-direction: row;
                align-items: center;
                justify-content: flex-start;
                gap: 8px;
                text-align: left;
                border-radius: 12px;
                font-size: 8.4px;
                line-height: 1.15;
                font-weight: 750;
            }

            .nav-subitem:hover {
                background: rgba(255, 255, 255, .10);
            }

            .nav-subitem.active {
                background: rgba(255, 255, 255, .15);
            }

            .nav-subitem .nav-icon {
                width: 18px;
                height: 18px;
                flex: 0 0 18px;
            }

            .nav-subitem .nav-icon svg {
                width: 18px;
                height: 18px;
                stroke-width: 1.75;
            }

            .sidebar-footer {
                padding: 10px 7px;
                padding-bottom: max(10px, env(safe-area-inset-bottom));
            }

            .sidebar-user {
                text-align: left;
                font-size: 8px;
                line-height: 1.2;
                font-weight: 750;
                margin-bottom: 7px;
                padding: 0 4px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .logout-btn {
                min-height: 40px;
                padding: 0 12px;
                flex-direction: row;
                justify-content: flex-start;
                gap: 9px;
                font-size: 9px;
                border-radius: 13px;
            }

            .logout-btn .nav-icon {
                width: 18px;
                height: 18px;
                flex: 0 0 18px;
            }
        }

        /* Buttons / forms */

        .btn {
            min-height: 34px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            border-radius: 4px;
            padding: 0 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 12.5px;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
            box-shadow: none;
            transition: background .14s ease, border-color .14s ease, transform .12s ease;
        }

        .btn:hover {
            background: var(--surface-soft);
            border-color: var(--accent-soft-2);
        }

        .btn:focus-visible {
            outline: none;
            box-shadow: none;
        }

        .btn:active {
            transform: translateY(1px);
        }

        .btn.accent,
        .btn.primary,
        .btn.btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            box-shadow: none;
        }

        .btn.accent:hover,
        .btn.primary:hover,
        .btn.btn-primary:hover {
            background: var(--accent-strong);
            border-color: var(--accent-strong);
            filter: brightness(1.05);
        }

        .btn.small {
            min-height: 32px;
            padding: 0 10px;
            border-radius: 4px;
            font-size: 12px;
        }

        .btn svg,
        .icon-btn svg,
        .quick-action svg,
        .btn-circle svg {
            width: 18px;
            height: 18px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .icon-btn {
            width: 34px;
            height: 34px;
            min-height: 34px;
            border-radius: 4px;
            padding: 0;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background .14s ease, border-color .14s ease, color .14s ease, transform .12s ease;
        }

        .icon-btn:hover {
            background: var(--surface-soft);
            border-color: var(--accent-soft-2);
            color: var(--accent);
        }

        .icon-btn:active {
            transform: translateY(1px);
        }

        /* Buton circular - pentru actiuni icon-only (ex: + adauga) */
        .btn-circle {
            width: 34px;
            height: 34px;
            min-height: 34px;
            border-radius: 4px;
            padding: 0;
            border: 1px solid var(--accent);
            background: var(--accent);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: none;
            transition: filter .14s ease, transform .14s ease;
        }

        .btn-circle:hover {
            filter: brightness(1.08);
            box-shadow: none;
        }

        .btn-circle:active {
            transform: translateY(1px) scale(.96);
        }

        .btn.ghost {
            background: transparent;
            border-color: transparent;
        }

        .btn.ghost:hover {
            background: var(--surface-soft);
            border-color: var(--border);
        }

        .btn.danger {
            background: var(--tone-danger-bg);
            border-color: rgba(220, 38, 38, .22);
            color: var(--tone-danger);
        }

        .btn.danger:hover {
            background: var(--tone-danger-soft);
            border-color: rgba(220, 38, 38, .35);
        }

        .btn.success {
            background: var(--tone-success-bg);
            border-color: rgba(4, 120, 87, .22);
            color: var(--tone-success);
        }

        .btn.success:hover {
            background: var(--tone-success-soft);
        }

        .btn.warning {
            background: var(--tone-warning-bg);
            border-color: rgba(180, 83, 9, .22);
            color: var(--tone-warning);
        }

        .btn.warning:hover {
            background: var(--tone-warning-soft);
        }

        input,
        select,
        textarea {
            width: 100%;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            border-radius: 4px;
            min-height: 34px;
            padding: 0 9px;
            font-size: 12.5px;
            font-weight: 500;
            outline: none;
            transition: border-color .14s ease, background .14s ease;
        }

        input::placeholder,
        textarea::placeholder {
            color: var(--muted-2);
            font-weight: 400;
        }

        input:hover:not(:focus),
        select:hover:not(:focus),
        textarea:hover:not(:focus) {
            border-color: var(--accent-soft-2);
        }

        textarea {
            min-height: 96px;
            padding-top: 12px;
            padding-bottom: 12px;
            resize: vertical;
            line-height: 1.5;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--tone-info);
            box-shadow: none;
        }

        input[disabled],
        select[disabled],
        textarea[disabled] {
            background: var(--surface-soft);
            color: var(--muted);
            cursor: not-allowed;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .form-group.full,
        .form-grid .full {
            grid-column: 1 / -1;
        }

        .actions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .actions-left,
        .actions-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Notices (banner statice in pagina) */

        .notice {
            position: relative;
            margin: 14px 20px 0;
            border-radius: 14px;
            padding: 12px 14px 12px 16px;
            font-weight: 600;
            font-size: 13.5px;
            line-height: 1.4;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
        }

        .notice::before {
            content: '';
            position: absolute;
            left: 0;
            top: 8px;
            bottom: 8px;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: var(--accent);
        }

        .notice-success {
            background: var(--tone-success-bg);
            border-color: rgba(4, 120, 87, .22);
            color: var(--tone-success);
        }
        .notice-success::before { background: var(--tone-success); }

        .notice-warning {
            background: var(--tone-warning-bg);
            border-color: rgba(180, 83, 9, .22);
            color: var(--tone-warning);
        }
        .notice-warning::before { background: var(--tone-warning); }

        .notice-danger {
            background: var(--tone-danger-bg);
            border-color: rgba(220, 38, 38, .22);
            color: var(--tone-danger);
        }
        .notice-danger::before { background: var(--tone-danger); }

        .notice-info {
            background: var(--tone-info-bg);
            border-color: rgba(29, 78, 216, .22);
            color: var(--tone-info);
        }
        .notice-info::before { background: var(--tone-info); }

        /* Toasts (notificări mici, animate, jos-dreapta) */
        .toast-stack {
            position: fixed;
            bottom: 20px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 300;
            pointer-events: none;
        }

        .toast {
            min-width: 240px;
            max-width: 360px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-left: 3px solid var(--accent);
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            padding: 11px 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            pointer-events: auto;
            animation: toastIn .22s ease;
        }
        .toast.toast-success { border-left-color: var(--tone-success); }
        .toast.toast-warning { border-left-color: var(--tone-warning); }
        .toast.toast-danger  { border-left-color: var(--tone-danger); }
        .toast.toast-info    { border-left-color: var(--tone-info); }
        .toast.is-leaving    { animation: toastOut .18s ease forwards; }

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(20px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        @keyframes toastOut {
            from { opacity: 1; transform: translateX(0); }
            to   { opacity: 0; transform: translateX(20px); }
        }

        /* Status pills (refolosibile in liste, tabele) */
        .status-pill {
            display: inline-flex;
            align-items: center;
            min-height: 21px;
            padding: 3px 7px;
            border-radius: 4px;
            background: var(--surface-soft);
            border: 1px solid var(--border2);
            color: var(--text);
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }
        .status-pill.tone-danger  { background: var(--tone-danger-soft);  color: var(--tone-danger);  border-color: rgba(220,38,38,.22); }
        .status-pill.tone-warning { background: var(--tone-warning-soft); color: var(--tone-warning); border-color: rgba(180,83,9,.22); }
        .status-pill.tone-success { background: var(--tone-success-soft); color: var(--tone-success); border-color: rgba(4,120,87,.22); }
        .status-pill.tone-info    { background: var(--tone-info-soft);    color: var(--tone-info);    border-color: rgba(29,78,216,.22); }
        .status-pill.tone-neutral { background: var(--surface-soft);      color: var(--muted);        border-color: var(--border2); }

        /* Modal */

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .55);
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            z-index: 200;
            overflow-y: auto;
            padding: 18px;
        }

        .modal.open {
            display: block;
            animation: modalBgIn .14s ease;
        }

        @keyframes modalBgIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        .modal-box {
            width: min(760px, calc(100vw - 36px));
            max-width: calc(100vw - 36px);
            margin: 24px auto;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: var(--shadow-lg);
            padding: 20px;
            animation: modalBoxIn .18s ease;
        }

        @keyframes modalBoxIn {
            from { opacity: 0; transform: translateY(8px) scale(.985); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border2);
        }

        .modal-header h2 {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -.02em;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            border: 1px solid var(--border2);
            background: var(--surface);
            color: var(--muted);
            font-size: 18px;
            font-weight: 500;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .14s ease, color .14s ease, border-color .14s ease;
        }

        .modal-close:hover {
            background: var(--surface-soft);
            color: var(--text);
            border-color: var(--border);
        }

        .readonly-box {
            background: var(--surface-soft);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 13px 14px;
            color: var(--text);
            font-size: 14px;
            line-height: 1.55;
            font-weight: 750;
        }

        table {
            max-width: 100%;
            border-collapse: collapse;
        }

        .card,
        .table-card,
        .clients-card,
        .tasks-calendar-card,
        .services-grid,
        .team-grid,
        .report-grid,
        .kpi-grid,
        .kpi-grid-dashboard {
            max-width: 100%;
            min-width: 0;
        }

        .table-scroll,
        .table-responsive {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Tablet / responsive global safety */

        @media(max-width: 1180px) {
            .content {
                padding-left: 14px;
                padding-right: 14px;
            }

            .topbar {
                overflow-x: hidden;
            }

            .btn,
            button,
            input,
            select,
            textarea {
                max-width: 100%;
                min-width: 0;
                box-sizing: border-box;
            }

            .actions-row,
            .actions-left,
            .actions-right,
            .quick-range,
            .stats {
                max-width: 100%;
                min-width: 0;
                flex-wrap: wrap;
            }

            .actions-row .btn,
            .actions-left .btn,
            .actions-right .btn,
            .quick-range .btn,
            .stats .btn {
                min-width: 0;
                white-space: normal;
                text-align: center;
            }

            .kpi-grid,
            .kpi-grid-dashboard,
            .report-grid,
            .services-grid,
            .team-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        /* Mobile */

        @media(max-width: 860px) {
            html,
            body {
                overflow-x: hidden !important;
            }

            input,
            select,
            textarea {
                font-size: 16px !important;
            }

            .layout {
                display: block;
            }

            .mobile-menu-button {
                display: flex;
            }

            .sidebar-overlay.open {
                display: block;
            }

            .sidebar {
                transform: translateX(-105%);
                transition: transform .18s ease;
                width: min(286px, calc(100vw - 44px));
                max-width: calc(100vw - 44px);
                height: 100vh;
                height: 100dvh;
                max-height: 100dvh;
                overflow: hidden;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar-brand {
                min-height: 92px;
                padding: 18px 16px 14px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex: 0 0 auto;
            }

            .brand-logo-link {
                min-height: 60px;
                width: 100%;
            }

            .brand-logo {
                width: 68px;
                height: 68px;
                max-width: 68px;
                max-height: 68px;
            }

            .sidebar-nav {
                flex: 1 1 auto;
                min-height: 0;
                overflow-y: auto;
                padding-bottom: 10px;
            }

            .sidebar-footer {
                flex: 0 0 auto;
                padding-bottom: max(18px, calc(env(safe-area-inset-bottom) + 14px));
            }

            .logout-btn {
                min-height: 42px;
            }

            .main {
                margin-left: 0;
                width: 100%;
                min-width: 0;
            }

            .topbar {
                min-height: 56px;
                padding-top: 6px !important;
                padding-bottom: 6px !important;
                padding-left: 60px !important;
                padding-right: 10px !important;
            }

            .app-mobile-header {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 74px;
                padding: 10px 72px 10px 72px;
                background: var(--accent);
                color: #fff;
                border-bottom: 1px solid rgba(255, 255, 255, .12);
                width: 100%;
                overflow: hidden;
            }

            .app-mobile-logo {
                width: auto !important;
                height: 42px !important;
                max-width: 96px !important;
                max-height: 42px !important;
                display: block;
                object-fit: contain !important;
                object-position: center center !important;
            }

            .content {
                padding: 10px !important;
            }

            .notice {
                margin: 8px 10px 0 !important;
                padding: 10px 12px !important;
                border-radius: 14px !important;
                font-size: 13px !important;
            }

            .btn {
                min-height: 40px;
                border-radius: 13px;
                padding-left: 11px;
                padding-right: 11px;
                font-size: 13px;
            }

            input,
            select {
                min-height: 40px;
                border-radius: 13px;
                padding-left: 10px;
                padding-right: 10px;
            }

            textarea {
                min-height: 84px;
                border-radius: 13px;
            }

            .form-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }

            .actions-row {
                gap: 8px !important;
                margin-top: 10px !important;
            }

            .actions-left,
            .actions-right {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr;
                gap: 7px;
            }

            .actions-left .btn,
            .actions-right .btn,
            .actions-right a.btn,
            .actions-left a.btn {
                width: 100%;
            }

            .modal {
                padding: 9px !important;
            }

            .modal-box {
                width: calc(100vw - 18px) !important;
                max-width: calc(100vw - 18px) !important;
                max-height: calc(100vh - 18px) !important;
                margin: 9px auto !important;
                padding: 13px !important;
                border-radius: 18px !important;
                overflow-y: auto;
            }

            .modal-header {
                margin-bottom: 12px !important;
            }

            .modal-header h2 {
                font-size: 18px !important;
            }

            .modal-close {
                width: 36px;
                height: 36px;
            }

            .tasks-hero,
            .clients-hero,
            .services-hero,
            .team-hero,
            .reports-hero,
            .dashboard-hero {
                padding: 14px !important;
                margin-bottom: 10px !important;
                border-radius: 16px !important;
            }

            .tasks-hero h1,
            .clients-hero h1,
            .services-hero h1,
            .team-hero h1,
            .reports-hero h1,
            .dashboard-hero h1 {
                font-size: 20px !important;
            }

            .tasks-hero p,
            .clients-hero p,
            .services-hero p,
            .team-hero p,
            .reports-hero p,
            .dashboard-hero p {
                font-size: 13px !important;
                margin-top: 3px !important;
            }

            .stats {
                gap: 6px !important;
            }

            .stat-pill {
                padding: 6px 10px !important;
                font-size: 12px !important;
            }
        }

        @media(max-width: 420px) {
            .mobile-menu-button {
                top: 10px;
                left: 10px;
                width: 42px;
                height: 42px;
                border-radius: 14px;
            }

            .topbar {
                padding-left: 56px !important;
            }

            .app-mobile-header {
                min-height: 68px;
                padding: 9px 64px 9px 64px;
                justify-content: center;
                overflow: hidden;
            }

            .app-mobile-logo {
                width: auto !important;
                height: 38px !important;
                max-width: 86px !important;
                max-height: 38px !important;
                object-fit: contain !important;
                object-position: center center !important;
            }

            .content {
                padding: 8px !important;
            }

            .modal {
                padding: 7px !important;
            }

            .modal-box {
                width: calc(100vw - 14px) !important;
                max-width: calc(100vw - 14px) !important;
                margin: 7px auto !important;
            }
        }
        </style>
        <?php
    }
}


if (!function_exists('app_professional_identity_css')) {
    function app_professional_identity_css(): void
    {
        // Refinari tipografice si vizuale aplicate global.
        // Refacut sa nu mai depinda de !important peste tot.
        // Selectoare specifice = prioritate naturala mai mare in CSS.
        ?>
        <style id="pz-professional-identity">
        body { font-size: 13.5px; letter-spacing: 0; }

        /* Hero per-pagina - aspect uniform si calm */
        .dashboard-hero, .clients-hero, .contracts-hero, .tasks-hero,
        .services-hero, .team-hero, .reports-hero, .hero, .page-hero {
            background: var(--surface);
            background-image: none;
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: none;
            padding: 14px 16px;
            margin-bottom: 12px;
        }

        /* Titluri */
        .page-title h1, .settings-head h1,
        .dashboard-hero h1, .clients-hero h1, .contracts-hero h1, .tasks-hero h1,
        .services-hero h1, .team-hero h1, .reports-hero h1 {
            font-size: 22px;
            line-height: 1.18;
            font-weight: 700;
            letter-spacing: -.025em;
            color: var(--text);
        }

        .dashboard-hero p, .clients-hero p, .contracts-hero p, .tasks-hero p,
        .services-hero p, .team-hero p, .reports-hero p, .hero p, .page-hero p {
            color: var(--muted);
            font-size: 12.5px;
            font-weight: 500;
            margin-top: 3px;
            max-width: 760px;
        }

        .report-card h2, .setting-title {
            font-size: 15px;
            line-height: 1.25;
            font-weight: 700;
            letter-spacing: -.01em;
        }

        .muted, .cell-muted, .setting-desc, .kpi-sub, .stat-label {
            color: var(--muted);
            font-weight: 500;
            font-size: 12px;
            line-height: 1.4;
        }

        /* Carduri uniforme */
        .card, .table-card, .clients-card, .tasks-calendar-card,
        .kpi-card, .stat-card, .report-card, .settings-list,
        .company-card, .panel, .side-card, .doc-page, .readonly-box,
        .service-card, .team-card, .client-card, .mobile-contract-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
        }

        .kpi-card, .stat-card, .report-card, .company-card,
        .setting-row, .mobile-contract-card, .service-card,
        .team-card, .client-card {
            padding: 14px 16px;
        }

        .kpi-value, .stat-value {
            font-size: 26px;
            line-height: 1;
            font-weight: 800;
            letter-spacing: -.04em;
            color: var(--text);
        }

        /* Tabele */
        table th {
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        table td {
            font-size: 13px;
            font-weight: 500;
        }

        /* Badges, chips, pills */
        .badge, .status-badge, .chip, .stat-pill {
            font-size: 11.5px;
            font-weight: 700;
            border-radius: 999px;
        }

        /* Sidebar nav typography (override consistent peste regulile noi) */
        .nav-item {
            font-size: 13.5px;
            font-weight: 600;
        }
        /* === PestZone UI standard: compact, plat, fără texte grele === */
        body {
            background: var(--bg);
            color: var(--text-body);
            font-size: 13px;
            letter-spacing: 0;
        }

        .dashboard-hero, .clients-hero, .contracts-hero, .contract-hero, .tasks-hero,
        .services-hero, .team-hero, .reports-hero, .settings-head, .hero, .page-hero,
        .stock-hero, .ib-hero, .docs-hero, .document-hero, .email-hero, .pv-hero {
            background: var(--surface) !important;
            background-image: none !important;
            border: 1px solid var(--border) !important;
            border-radius: 8px !important;
            box-shadow: none !important;
            padding: 16px 18px !important;
            margin-bottom: 14px !important;
        }

        .dashboard-hero::before, .clients-hero::before, .contracts-hero::before, .contract-hero::before,
        .tasks-hero::before, .services-hero::before, .team-hero::before, .reports-hero::before,
        .settings-head::before, .hero::before, .page-hero::before, .stock-hero::before,
        .ib-hero::before, .docs-hero::before, .document-hero::before, .email-hero::before, .pv-hero::before,
        .dashboard-hero::after, .clients-hero::after, .contracts-hero::after, .contract-hero::after,
        .tasks-hero::after, .services-hero::after, .team-hero::after, .reports-hero::after,
        .settings-head::after, .hero::after, .page-hero::after, .stock-hero::after,
        .ib-hero::after, .docs-hero::after, .document-hero::after, .email-hero::after, .pv-hero::after {
            display: none !important;
        }

        .dashboard-hero h1, .clients-hero h1, .contracts-hero h1, .contract-hero h1,
        .tasks-hero h1, .services-hero h1, .team-hero h1, .reports-hero h1,
        .settings-head h1, .hero h1, .page-hero h1, .stock-hero h1,
        .ib-hero h1, .docs-hero h1, .document-hero h1, .email-hero h1, .pv-hero h1,
        .page-title h1 {
            color: var(--text) !important;
            font-size: 24px !important;
            line-height: 1.15 !important;
            font-weight: 700 !important;
            letter-spacing: 0 !important;
        }

        .dashboard-hero p, .clients-hero p, .contracts-hero p, .contract-hero p,
        .tasks-hero p, .services-hero p, .team-hero p, .reports-hero p,
        .settings-head p, .hero p, .page-hero p, .stock-hero p,
        .ib-hero p, .docs-hero p, .document-hero p, .email-hero p, .pv-hero p {
            color: var(--muted) !important;
            font-size: 12.5px !important;
            line-height: 1.45 !important;
            font-weight: 500 !important;
        }

        .card, .table-card, .clients-card, .tasks-calendar-card,
        .kpi-card, .stat-card, .report-card, .settings-list,
        .company-card, .panel, .side-card, .doc-page, .readonly-box,
        .service-card, .team-card, .client-card, .mobile-contract-card,
        .clients-list-card, .client-profile-card {
            background: var(--surface) !important;
            border: 1px solid var(--border) !important;
            border-radius: 8px !important;
            box-shadow: none !important;
        }

        input, select, textarea,
        .btn, button,
        .page-btn, .icon-action, .row-menu-trigger {
            border-radius: 4px !important;
            box-shadow: none !important;
        }

        input, select {
            min-height: 34px;
            font-size: 12.5px;
        }

        textarea {
            font-size: 12.5px;
        }

        select,
        .field select,
        .filter-grid select,
        .form-grid select,
        .amount-form select,
        .clients-toolbar select,
        #clientModal select,
        .tasks-filter-line select,
        .stock-field select,
        .settings-module-page select,
        .docs-page select,
        body.calendar-page .calendar-filter-line .select,
        body.calendar-page .support-team-select {
            appearance: none !important;
            -webkit-appearance: none !important;
            color-scheme: light !important;
            width: 100% !important;
            min-height: 34px !important;
            height: 34px !important;
            border: 1px solid var(--border) !important;
            border-radius: 4px !important;
            background-color: var(--surface) !important;
            background-image:
                linear-gradient(45deg, transparent 50%, #64748B 50%),
                linear-gradient(135deg, #64748B 50%, transparent 50%) !important;
            background-position:
                calc(100% - 14px) 50%,
                calc(100% - 9px) 50% !important;
            background-size: 5px 5px, 5px 5px !important;
            background-repeat: no-repeat !important;
            color: var(--text-body) !important;
            padding: 0 28px 0 9px !important;
            font-size: 12.5px !important;
            font-weight: 600 !important;
            line-height: 1.2 !important;
            box-shadow: none !important;
            outline: none !important;
        }

        select:hover,
        .field select:hover,
        .filter-grid select:hover,
        .form-grid select:hover,
        .amount-form select:hover,
        .clients-toolbar select:hover,
        #clientModal select:hover,
        .tasks-filter-line select:hover,
        .stock-field select:hover,
        body.calendar-page .calendar-filter-line .select:hover,
        body.calendar-page .support-team-select:hover {
            border-color: var(--accent-pale) !important;
            background-color: var(--surface) !important;
        }

        select:focus,
        select:focus-visible,
        .field select:focus,
        .field select:focus-visible,
        .filter-grid select:focus,
        .filter-grid select:focus-visible,
        .form-grid select:focus,
        .form-grid select:focus-visible,
        .amount-form select:focus,
        .amount-form select:focus-visible,
        .clients-toolbar select:focus,
        .clients-toolbar select:focus-visible,
        #clientModal select:focus,
        #clientModal select:focus-visible,
        .tasks-filter-line select:focus,
        .tasks-filter-line select:focus-visible,
        .stock-field select:focus,
        .stock-field select:focus-visible,
        body.calendar-page .calendar-filter-line .select:focus,
        body.calendar-page .calendar-filter-line .select:focus-visible,
        body.calendar-page .support-team-select:focus,
        body.calendar-page .support-team-select:focus-visible {
            border-color: var(--accent) !important;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, .12) !important;
            outline: none !important;
        }

        select:disabled,
        .field select:disabled,
        .filter-grid select:disabled,
        .form-grid select:disabled,
        .amount-form select:disabled,
        .clients-toolbar select:disabled,
        #clientModal select:disabled,
        .tasks-filter-line select:disabled,
        .stock-field select:disabled,
        body.calendar-page .support-team-select:disabled {
            background-color: var(--surface-soft) !important;
            color: var(--muted-2) !important;
            cursor: not-allowed !important;
        }

        select option {
            background: #FFFFFF !important;
            color: #334155 !important;
            font-weight: 500 !important;
        }

        select[multiple],
        select[size]:not([size="1"]) {
            height: auto !important;
            min-height: 82px !important;
            background-image: none !important;
            padding-right: 9px !important;
        }

        body.calendar-page .cal-team-summary,
        body.calendar-page .cal-view-summary {
            appearance: none !important;
            -webkit-appearance: none !important;
            color-scheme: light !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            min-height: 34px !important;
            height: 34px !important;
            border: 1px solid var(--border) !important;
            border-radius: 4px !important;
            background: var(--surface) !important;
            color: var(--text-body) !important;
            padding: 0 28px !important;
            font-size: 12.5px !important;
            font-weight: 600 !important;
            line-height: 1.2 !important;
            box-shadow: none !important;
            outline: none !important;
        }

        body.calendar-page .cal-team-summary-label,
        body.calendar-page .cal-view-summary-label {
            display: block !important;
            max-width: 100% !important;
            text-align: center !important;
            line-height: 1.2 !important;
        }

        body.calendar-page .cal-team-summary:hover,
        body.calendar-page .cal-view-summary:hover {
            border-color: var(--accent-pale) !important;
        }

        body.calendar-page .cal-team-summary:focus,
        body.calendar-page .cal-team-summary:focus-visible,
        body.calendar-page .cal-view-summary:focus,
        body.calendar-page .cal-view-summary:focus-visible,
        body.calendar-page .cal-team-picker.open .cal-team-summary,
        body.calendar-page .cal-view-picker.open .cal-view-summary {
            border-color: var(--accent) !important;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, .12) !important;
            outline: none !important;
        }

        body.calendar-page .cal-team-caret,
        body.calendar-page .cal-view-caret {
            line-height: 34px !important;
            color: var(--muted) !important;
        }

        .btn {
            min-height: 34px;
            padding: 7px 11px;
            font-size: 12.5px;
            font-weight: 700;
        }

        table th {
            color: var(--muted) !important;
            font-size: 10.5px !important;
            font-weight: 800 !important;
            letter-spacing: 0 !important;
        }

        table td {
            color: var(--text-body);
            font-size: 12.5px !important;
            font-weight: 600;
        }

        .badge, .status-badge, .chip, .stat-pill, .hero-pill,
        .type-pill, .status-pill, .client-status-badge, .pill,
        .trend-chip, .eff-grade-pill, .badge-count {
            border-radius: 4px !important;
            box-shadow: none !important;
            font-size: 11px !important;
            font-weight: 800 !important;
        }

        .dash-greeting, .dash-kpi-card, .panel, .exec-hero, .exec-card,
        .fin-card, .fin-row, .agenda-row, .eff-summary-card, .eff-row,
        .docs-page .doc-row, .docs-page .quick-card, .invoice-item, .payment-box, .summary-card,
        .metric, .location-item, .history-item, .info-box, .form-section,
        .stock-hero, .stock-card, .stock-kpi, .stock-note,
        .tasks-hero, .tasks-calendar-card {
            background: var(--surface) !important;
            background-image: none !important;
            border: 1px solid var(--border) !important;
            border-radius: 8px !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        .dash-greeting::before, .dash-greeting::after,
        .dash-kpi-card::before, .dash-kpi-card::after,
        .panel::before, .panel::after,
        .exec-hero::before, .exec-hero::after,
        .exec-card::before, .exec-card::after,
        .eff-summary-card::before, .eff-summary-card::after,
        .tasks-hero::before, .tasks-hero::after,
        .stock-hero::before, .stock-hero::after {
            display: none !important;
        }

        .dash-hero-action, .exec-card-link, .ib-small-btn,
        .amount-form input, .amount-form select, .no-bill-box input,
        .field select, .filter-grid input, .filter-grid select,
        .tasks-action-line .btn, .tasks-filter-line .btn,
        .tasks-filter-line select, .tasks-filter-line input,
        .stock-tabs a, .stock-actions .btn, .stock-actions a.btn {
            min-height: 34px !important;
            border-radius: 4px !important;
            box-shadow: none !important;
            font-size: 12.5px !important;
        }

        .eff-tabs, .team-tile .tile-bar, .eff-row-bar,
        .stock-tabs a, .stock-badge {
            border-radius: 4px !important;
            box-shadow: none !important;
        }

        .stock-badge {
            min-height: 21px !important;
            padding: 3px 7px !important;
            font-size: 11px !important;
            font-weight: 800 !important;
        }

        .stock-hero h1, .tasks-hero h1 {
            color: var(--text) !important;
            font-size: 24px !important;
            line-height: 1.15 !important;
            letter-spacing: 0 !important;
            font-weight: 700 !important;
        }

        .stock-hero p, .tasks-hero p {
            color: var(--muted) !important;
            font-size: 12.5px !important;
            line-height: 1.45 !important;
            font-weight: 500 !important;
        }

        .stock-kpi .label,
        .stock-field label {
            color: var(--muted) !important;
            font-size: 10.5px !important;
            letter-spacing: 0 !important;
            font-weight: 800 !important;
        }

        .stock-kpi .value {
            color: var(--text) !important;
            font-size: 24px !important;
            letter-spacing: 0 !important;
            font-weight: 750 !important;
        }

        .stock-hero {
            display: flex !important;
            align-items: flex-start !important;
            justify-content: space-between !important;
            gap: 12px !important;
            flex-wrap: wrap !important;
        }

        .stock-card {
            padding: 14px !important;
            margin-bottom: 14px !important;
        }

        .stock-grid,
        .stock-grid-3,
        .stock-grid-4 {
            display: grid !important;
            gap: 12px !important;
        }

        .stock-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        .stock-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
        .stock-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }

        .stock-kpis {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 12px !important;
            margin-bottom: 14px !important;
        }

        .stock-field label {
            display: block !important;
            margin-bottom: 5px !important;
            text-transform: uppercase !important;
        }

        .stock-field small {
            display: block !important;
            color: var(--muted) !important;
            font-size: 11.5px !important;
            margin-top: 4px !important;
        }

        .stock-tabs {
            display: flex !important;
            gap: 6px !important;
            flex-wrap: wrap !important;
            margin: 0 0 14px !important;
        }

        .stock-tabs a {
            min-height: 34px !important;
            padding: 7px 11px !important;
            display: inline-flex !important;
            align-items: center !important;
            border: 1px solid var(--border) !important;
            background: var(--surface) !important;
            color: var(--muted) !important;
            text-decoration: none !important;
            font-weight: 700 !important;
        }

        .stock-tabs a.active {
            background: var(--accent) !important;
            border-color: var(--accent) !important;
            color: #fff !important;
        }

        .stock-table-wrap {
            width: 100% !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
        }

        .stock-table {
            width: 100% !important;
            border-collapse: collapse !important;
            font-size: 12.5px !important;
        }

        .stock-table th,
        .stock-table td {
            padding: 8px 9px !important;
            border-bottom: 1px solid var(--border2) !important;
            text-align: left !important;
            vertical-align: top !important;
        }

        .stock-table th {
            background: var(--surface-soft) !important;
            white-space: nowrap !important;
        }

        .stock-actions {
            display: flex !important;
            gap: 7px !important;
            align-items: center !important;
            flex-wrap: wrap !important;
        }

        .stock-alert-row {
            background: var(--tone-danger-bg) !important;
        }

        .stock-note {
            padding: 11px 12px !important;
            color: var(--muted) !important;
            font-size: 12.5px !important;
            margin-bottom: 12px !important;
        }

        body.calendar-page .calendar-topbar,
        body.calendar-page .calendar-toolbar,
        body.calendar-page .calendar-date-line,
        body.calendar-page .calendar-filter-line,
        body.calendar-page .calendar-action-line {
            box-shadow: none !important;
        }

        body.calendar-page .calendar-date-line .btn,
        body.calendar-page .calendar-action-line .btn,
        body.calendar-page .calendar-date-form .date-input,
        body.calendar-page .calendar-filter-line .select,
        body.calendar-page .cal-team-summary,
        body.calendar-page .cal-view-summary,
        body.calendar-page .support-add-btn,
        body.calendar-page .support-remove-btn,
        body.calendar-page .support-team-select,
        body.calendar-page .pz-autocomplete-input,
        body.calendar-page .pz-autocomplete-selected,
        body.calendar-page .pz-autocomplete-results,
        body.calendar-page .mini-check {
            border-radius: 4px !important;
            box-shadow: none !important;
        }

        body.calendar-page .calendar-date-line .nav-arrow,
        body.calendar-page .calendar-date-line .nav-today-btn,
        body.calendar-page .calendar-date-form .date-input,
        body.calendar-page .calendar-filter-line .select,
        body.calendar-page .cal-team-summary,
        body.calendar-page .cal-view-summary,
        body.calendar-page .calendar-action-line .btn {
            height: 34px !important;
            min-height: 34px !important;
            font-size: 12.5px !important;
            font-weight: 700 !important;
        }

        body.calendar-page .calendar-date-line .nav-today-btn,
        body.calendar-page .calendar-date-form .date-input {
            background: var(--accent) !important;
            border-color: var(--accent) !important;
            color: #fff !important;
        }

        body.calendar-page .calendar-filter-line .view-select,
        body.calendar-page .cal-team-summary,
        body.calendar-page .cal-view-summary {
            border: 1px solid var(--border) !important;
            background: var(--surface) !important;
            color: var(--text-body) !important;
        }

        body.calendar-page .calendar-team-chip,
        body.calendar-page .event-done-badge,
        body.calendar-page .team-greeting-count,
        body.calendar-page .month-mini-team-bar,
        body.calendar-page .month-mini-dot {
            border-radius: 4px !important;
            box-shadow: none !important;
        }

        body.calendar-page .team-greeting,
        body.calendar-page .schedule-scroll,
        body.calendar-page .fc-card,
        body.calendar-page .readonly-box,
        body.calendar-page .office-note-box,
        body.calendar-page .pz-autocomplete-results {
            background: var(--surface) !important;
            border: 1px solid var(--border) !important;
            border-radius: 8px !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        body.calendar-page .time-head,
        body.calendar-page .team-head,
        body.calendar-page .team-dot,
        body.calendar-page .event,
        body.calendar-page .event.done,
        body.calendar-page .fc-event,
        body.calendar-page .fc-event-finalizata {
            box-shadow: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        body.calendar-page .team-head,
        body.calendar-page .time-head,
        body.calendar-page .time-cell,
        body.calendar-page .slot-cell {
            border-color: var(--border) !important;
        }

        body.calendar-page .slot-cell.off-hours,
        body.calendar-page .time-cell.off-hours {
            background: var(--surface-muted) !important;
            color: var(--muted) !important;
        }

        body.calendar-page .fc .fc-daygrid-day-frame {
            background: var(--surface) !important;
            box-shadow: none !important;
        }

        body.calendar-page .fc .fc-day-today .fc-daygrid-day-frame {
            background: var(--tone-info-bg) !important;
            box-shadow: inset 0 0 0 1px var(--accent-pale) !important;
        }

        body.calendar-page .fc-event-month-box {
            border-radius: 4px !important;
            box-shadow: none !important;
        }

        /* Icon standard: outline, monocrom, fără fundaluri colorate decorative. */
        .nav-icon,
        .btn .nav-icon,
        .icon-btn .nav-icon,
        .btn-circle .nav-icon,
        .tb-iconbtn .nav-icon,
        .tb-bell .nav-icon,
        .tdi-icon,
        .stat-icon,
        .setting-icon {
            color: currentColor !important;
            background: transparent !important;
            border-color: var(--border) !important;
            box-shadow: none !important;
        }

        .nav-icon svg,
        .btn svg,
        .icon-btn svg,
        .btn-circle svg,
        .tb-iconbtn svg,
        .tb-bell svg,
        .tdi-icon svg,
        .tdi-icon .nav-icon svg,
        .setting-icon svg {
            fill: none !important;
            stroke: currentColor !important;
            stroke-width: 1.8 !important;
            stroke-linecap: round !important;
            stroke-linejoin: round !important;
            vector-effect: non-scaling-stroke;
        }

        .tdi-icon {
            width: 30px !important;
            height: 30px !important;
            border: 1px solid var(--border) !important;
            border-radius: 4px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            color: var(--muted) !important;
        }

        .tb-dropdown-item.tone-info .tdi-icon,
        .tb-dropdown-item.tone-success .tdi-icon,
        .tb-dropdown-item.tone-warning .tdi-icon,
        .tb-dropdown-item.tone-danger .tdi-icon {
            background: transparent !important;
            color: var(--muted) !important;
            border-color: var(--border) !important;
        }

        .stat-icon {
            width: 24px !important;
            min-width: 24px !important;
            height: 24px !important;
            border: 1px solid var(--border) !important;
            border-radius: 4px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 0 !important;
            color: var(--muted) !important;
            line-height: 1 !important;
        }

        .stat-icon .nav-icon,
        .stat-icon .nav-icon svg,
        .stat-icon > svg {
            width: 14px !important;
            height: 14px !important;
            display: block !important;
            stroke-width: 1.9 !important;
        }

        .stat-pill.stat-active .stat-icon,
        .stat-pill.stat-today .stat-icon,
        .stat-pill.stat-overdue .stat-icon {
            background: transparent !important;
            color: var(--muted) !important;
            border-color: var(--border) !important;
        }

        .tasks-topbar,
        .tasks-toolbar,
        .tasks-view-switcher,
        .tasks-filter-line,
        .tasks-action-line {
            box-shadow: none !important;
        }

        .tasks-view-switcher .task-view-btn,
        .tasks-action-line .btn,
        .tasks-filter-line .btn,
        .tasks-filter-line select,
        .tasks-filter-line input,
        .tasks-status-legend span,
        .tasks-calendar-card .fc-button,
        .fc-event.fc-task-event {
            border-radius: 4px !important;
            box-shadow: none !important;
        }

        .tasks-view-switcher .task-view-btn,
        .tasks-action-line .btn,
        .tasks-filter-line .btn,
        .tasks-filter-line select,
        .tasks-filter-line input {
            min-height: 34px !important;
            height: 34px !important;
            font-size: 12.5px !important;
            font-weight: 700 !important;
        }

        .tasks-view-switcher .task-view-btn.active,
        .tasks-action-line .btn.accent {
            background: var(--accent) !important;
            border-color: var(--accent) !important;
            color: #fff !important;
        }

        .tasks-calendar-card .fc .fc-scrollgrid,
        .tasks-calendar-card .fc-theme-standard td,
        .tasks-calendar-card .fc-theme-standard th {
            border-color: var(--border) !important;
        }

        .tasks-calendar-card .fc .fc-daygrid-day-frame {
            background: var(--surface) !important;
            box-shadow: none !important;
        }

        .tasks-calendar-card .fc .fc-day-today .fc-daygrid-day-frame {
            background: var(--tone-info-bg) !important;
            box-shadow: inset 0 0 0 1px var(--accent-pale) !important;
        }

        .task-details-grid,
        .task-details-row {
            border-color: var(--border) !important;
        }

        .settings-page {
            max-width: 1120px !important;
            margin: 0 auto !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 14px !important;
        }

        .settings-module-page {
            max-width: 1180px !important;
            margin: 0 auto !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 12px !important;
        }

        .module-head {
            display: flex !important;
            justify-content: space-between !important;
            gap: 14px !important;
            align-items: flex-start !important;
            flex-wrap: wrap !important;
            background: var(--surface) !important;
            border: 1px solid var(--border) !important;
            border-radius: 8px !important;
            box-shadow: none !important;
            padding: 14px 16px !important;
        }

        .module-head h1 {
            margin: 0 !important;
            color: var(--text) !important;
            font-size: 20px !important;
            font-weight: 700 !important;
            letter-spacing: 0 !important;
        }

        .module-head p {
            margin: 3px 0 0 !important;
            color: var(--muted) !important;
            font-size: 12px !important;
            font-weight: 500 !important;
        }

        .settings-module-page .grid {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 12px !important;
        }

        .settings-module-page .row {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 8px !important;
        }

        .settings-module-page .full {
            grid-column: 1 / -1 !important;
        }

        .settings-eyebrow,
        .section-label,
        .details-label {
            color: var(--muted) !important;
            font-size: 10.5px !important;
            font-weight: 800 !important;
            letter-spacing: 0 !important;
            text-transform: uppercase !important;
        }

        .settings-eyebrow::before,
        .settings-head::before,
        .section-label::after {
            display: none !important;
        }

        .setting-row {
            display: grid !important;
            grid-template-columns: 40px minmax(0, 1fr) 34px !important;
            gap: 12px !important;
            align-items: center !important;
            padding: 13px 14px !important;
            border-bottom: 1px solid var(--border2) !important;
            color: inherit !important;
            text-decoration: none !important;
            transform: none !important;
        }

        .setting-row:hover {
            background: var(--surface-soft) !important;
            transform: none !important;
        }

        .setting-icon,
        .setting-arrow,
        .company-logo,
        .team-dot {
            border-radius: 4px !important;
            box-shadow: none !important;
        }

        .setting-icon,
        .setting-arrow {
            background: var(--surface-soft) !important;
            border: 1px solid var(--border) !important;
            color: var(--accent) !important;
        }

        .setting-icon {
            width: 34px !important;
            height: 34px !important;
        }

        .setting-arrow {
            width: 32px !important;
            height: 32px !important;
            font-size: 18px !important;
        }

        .setting-title,
        .service-title,
        .team-title,
        .panel-title,
        .doc-title {
            color: var(--text) !important;
            font-size: 14px !important;
            font-weight: 750 !important;
            letter-spacing: 0 !important;
        }

        .setting-desc,
        .service-desc,
        .team-sub,
        .panel-subtitle,
        .doc-meta {
            color: var(--muted) !important;
            font-size: 12px !important;
            font-weight: 500 !important;
            line-height: 1.4 !important;
        }

        .company-card,
        .reset-card,
        .services-hero,
        .team-hero,
        .service-card,
        .team-card,
        .empty-state,
        .details-row,
        .alert,
        .docs-page .doc-row,
        .docs-page .quick-card {
            background: var(--surface) !important;
            background-image: none !important;
            border: 1px solid var(--border) !important;
            border-radius: 8px !important;
            box-shadow: none !important;
        }

        .company-card {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 12px !important;
            padding: 14px !important;
        }

        .company-logo {
            width: 64px !important;
            height: 44px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            border: 1px solid var(--border) !important;
            background: var(--surface-soft) !important;
            color: var(--accent) !important;
            font-size: 12px !important;
            font-weight: 750 !important;
            text-align: center !important;
        }

        .company-name {
            color: var(--text) !important;
            font-size: 15px !important;
            font-weight: 750 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .company-sub {
            color: var(--muted) !important;
            font-size: 12px !important;
            font-weight: 500 !important;
            margin-top: 3px !important;
        }

        .admin-note {
            background: var(--tone-warning-bg) !important;
            color: var(--tone-warning) !important;
            border: 1px solid rgba(154, 52, 18, .18) !important;
            border-radius: 8px !important;
            padding: 11px 12px !important;
            font-size: 12.5px !important;
            font-weight: 600 !important;
            line-height: 1.45 !important;
        }

        .settings-module-page .card,
        .settings-module-page .panel,
        .settings-module-page .alert,
        .settings-module-page .notice {
            border-radius: 8px !important;
            box-shadow: none !important;
        }

        .settings-module-page .card,
        .settings-module-page .panel {
            padding: 14px !important;
        }

        .settings-module-page h2 {
            margin-top: 0 !important;
            font-size: 15px !important;
            letter-spacing: 0 !important;
        }

        .settings-module-page label {
            font-size: 10.5px !important;
            letter-spacing: 0 !important;
        }

        .settings-module-page input,
        .settings-module-page select,
        .settings-module-page textarea {
            min-height: 36px !important;
            border-radius: 4px !important;
            padding-top: 7px !important;
            padding-bottom: 7px !important;
            font-size: 12.5px !important;
            box-shadow: none !important;
        }

        .settings-module-page .btn {
            min-height: 34px !important;
            border-radius: 4px !important;
            padding-top: 7px !important;
            padding-bottom: 7px !important;
            font-size: 12px !important;
        }

        .settings-action-toolbar {
            justify-content: flex-start !important;
        }

        .reset-card .setting-title {
            color: var(--tone-danger) !important;
        }

        .reset-card .setting-desc {
            color: #7f1d1d !important;
        }

        .services-hero,
        .team-hero {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 14px !important;
            flex-wrap: wrap !important;
            color: var(--text) !important;
        }

        .services-topbar,
        .team-topbar,
        .docs-topbar,
        .offer-topbar {
            align-items: center !important;
            padding: 12px 20px !important;
        }

        .services-toolbar,
        .team-toolbar,
        .docs-toolbar,
        .offer-toolbar {
            width: 100% !important;
            min-width: 0 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 8px !important;
            flex-wrap: wrap !important;
        }

        .services-grid,
        .team-grid {
            display: grid !important;
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 12px !important;
        }

        .service-card,
        .team-card {
            min-width: 0 !important;
            padding: 14px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 12px !important;
        }

        .service-title-row,
        .team-headline,
        .team-main,
        .company-left {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            min-width: 0 !important;
        }

        .service-title-row,
        .team-headline {
            justify-content: space-between !important;
            align-items: flex-start !important;
        }

        .service-meta,
        .team-meta,
        .stats,
        .hero-actions,
        .doc-actions {
            display: flex !important;
            gap: 6px !important;
            flex-wrap: wrap !important;
        }

        .service-pill,
        .team-pill {
            background: var(--surface-soft) !important;
            border: 1px solid var(--border2) !important;
            border-radius: 4px !important;
            color: var(--muted) !important;
            font-size: 11px !important;
            font-weight: 800 !important;
            padding: 4px 7px !important;
        }

        .service-actions,
        .team-actions {
            margin-top: auto !important;
            display: flex !important;
            gap: 7px !important;
            flex-wrap: wrap !important;
        }

        .service-actions .btn,
        .service-actions button,
        .team-actions .btn {
            min-width: 0 !important;
            flex: 1 1 auto !important;
        }

        .details-grid {
            display: grid !important;
            gap: 8px !important;
        }

        .details-row {
            display: grid !important;
            grid-template-columns: 135px 1fr !important;
            gap: 10px !important;
            padding: 9px 0 !important;
            border-width: 0 0 1px !important;
            border-radius: 0 !important;
        }

        .service-checkbox {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            font-weight: 800 !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
            margin: 0 !important;
        }

        .service-checkbox input[type="checkbox"] {
            width: 16px !important;
            height: 16px !important;
            min-height: 16px !important;
            flex: 0 0 auto !important;
        }

        .docs-hero,
        .offer-hero {
            display: flex !important;
            justify-content: space-between !important;
            gap: 14px !important;
            align-items: flex-start !important;
            flex-wrap: wrap !important;
        }

        .hero-actions {
            display: flex !important;
            gap: 7px !important;
            flex-wrap: wrap !important;
            justify-content: flex-end !important;
        }

        .docs-page .stats-grid,
        .docs-page .quick-grid {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 12px !important;
            margin-bottom: 14px !important;
        }

        .docs-page .quick-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        }

        .panel {
            overflow: hidden !important;
            margin-bottom: 14px !important;
        }

        .panel-head {
            padding: 13px 14px !important;
            border-bottom: 1px solid var(--border2) !important;
            display: flex !important;
            justify-content: space-between !important;
            gap: 10px !important;
            align-items: center !important;
            flex-wrap: wrap !important;
        }

        .panel-body {
            padding: 14px !important;
        }

        .docs-page .filter-form {
            display: grid !important;
            grid-template-columns: minmax(220px, 1.2fr) 160px 160px 145px 145px 110px auto !important;
            gap: 10px !important;
            align-items: end !important;
        }

        .field label {
            display: block !important;
            color: var(--muted) !important;
            font-size: 10.5px !important;
            font-weight: 800 !important;
            margin: 0 0 5px !important;
            text-transform: uppercase !important;
            letter-spacing: 0 !important;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .docs-page .docs-list {
            display: grid !important;
            gap: 10px !important;
        }

        .docs-page .doc-row {
            padding: 12px !important;
            display: grid !important;
            grid-template-columns: minmax(250px, 1.2fr) minmax(135px, .45fr) minmax(170px, .55fr) minmax(120px, .35fr) minmax(115px, .3fr) auto !important;
            gap: 10px !important;
            align-items: center !important;
        }

        .docs-page .doc-number {
            color: var(--text) !important;
            font-weight: 750 !important;
        }

        .docs-page .email-state {
            color: var(--muted) !important;
            font-size: 12px !important;
            font-weight: 700 !important;
        }

        .docs-page .email-state.sent {
            color: var(--tone-success) !important;
        }

        .pagination {
            display: flex !important;
            gap: 6px !important;
            justify-content: flex-end !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            margin-top: 12px !important;
        }

        .docs-page .quick-card {
            padding: 12px !important;
            display: flex !important;
            justify-content: space-between !important;
            gap: 10px !important;
            align-items: center !important;
        }

        .docs-page .quick-card strong {
            display: block !important;
            color: var(--text) !important;
            font-size: 13px !important;
            font-weight: 750 !important;
        }

        .docs-page .quick-card span {
            display: block !important;
            margin-top: 3px !important;
            color: var(--muted) !important;
            font-size: 12px !important;
            font-weight: 600 !important;
        }

        .setting-icon,
        .stat-icon,
        .tdi-icon {
            background: transparent !important;
            color: var(--muted) !important;
            border: 1px solid var(--border) !important;
            border-radius: 4px !important;
            box-shadow: none !important;
        }

        .team-tile .tile-bar > span,
        .eff-row-bar > span,
        .mini-bar {
            border-radius: 4px !important;
        }

        @media(max-width: 860px) {
            .content { padding: 10px; }
            .page-title h1, .dashboard-hero h1, .clients-hero h1, .contracts-hero h1,
            .tasks-hero h1, .services-hero h1, .team-hero h1, .reports-hero h1 {
                font-size: 19px;
            }

            .stock-grid,
            .stock-grid-3,
            .stock-grid-4,
            .stock-kpis {
                grid-template-columns: 1fr !important;
            }

            .services-grid,
            .team-grid {
                grid-template-columns: 1fr !important;
            }

            .setting-row {
                grid-template-columns: 34px minmax(0, 1fr) 32px !important;
                padding: 12px !important;
            }

            .details-row {
                grid-template-columns: 1fr !important;
                gap: 3px !important;
            }

            .settings-module-page .grid,
            .settings-module-page .row {
                grid-template-columns: 1fr !important;
            }

            .company-card {
                align-items: stretch !important;
                flex-direction: column !important;
            }

            .company-card .btn {
                width: 100% !important;
                justify-content: center !important;
            }

            .docs-page .stats-grid,
            .docs-page .quick-grid,
            .docs-page .filter-form,
            .docs-page .doc-row {
                grid-template-columns: 1fr !important;
            }

            .docs-page .doc-actions,
            .hero-actions {
                justify-content: flex-start !important;
            }
        }

        @media(max-width: 760px) {
            .stock-hero {
                display: block !important;
                padding: 14px !important;
                margin-bottom: 10px !important;
            }

            .stock-hero h1 {
                font-size: 21px !important;
                line-height: 1.1 !important;
                margin: 0 !important;
            }

            .stock-hero p {
                max-width: 100% !important;
                margin: 6px 0 0 !important;
                font-size: 11.5px !important;
                line-height: 1.35 !important;
            }

            .stock-actions {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 6px !important;
                margin-top: 12px !important;
            }

            .stock-actions .btn,
            .stock-actions a.btn,
            .stock-tabs a {
                width: 100% !important;
                min-height: 34px !important;
                justify-content: center !important;
                padding: 7px 8px !important;
                font-size: 11.5px !important;
                line-height: 1.1 !important;
                text-align: center !important;
            }

            .stock-tabs {
                display: flex !important;
                flex-wrap: nowrap !important;
                gap: 6px !important;
                margin-bottom: 10px !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                scrollbar-width: none !important;
            }

            .stock-tabs::-webkit-scrollbar {
                display: none !important;
            }

            .stock-tabs a {
                width: auto !important;
                min-width: max-content !important;
                flex: 0 0 auto !important;
                white-space: nowrap !important;
            }

            .stock-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 8px !important;
                margin-bottom: 10px !important;
            }

            .stock-kpi {
                min-height: 76px !important;
                padding: 10px !important;
            }

            .stock-kpi .label {
                font-size: 9.5px !important;
                line-height: 1.15 !important;
            }

            .stock-kpi .value {
                margin-top: 5px !important;
                font-size: 24px !important;
                line-height: 1 !important;
            }

            .stock-card {
                padding: 12px !important;
                margin-bottom: 10px !important;
            }

            .stock-card h2 {
                margin-bottom: 10px !important;
                font-size: 18px !important;
                line-height: 1.15 !important;
            }

            .stock-table {
                min-width: 640px !important;
                font-size: 11px !important;
            }

            .stock-table th,
            .stock-table td {
                padding: 7px 8px !important;
                line-height: 1.25 !important;
            }

            .stock-badge {
                min-height: 20px !important;
                padding: 3px 6px !important;
                font-size: 10px !important;
            }

            .notice {
                padding: 10px 12px !important;
                margin-bottom: 10px !important;
                font-size: 12px !important;
                line-height: 1.35 !important;
            }
        }
        </style>
        <?php
    }
}

if (!function_exists('app_brand_logo')) {
    function app_brand_logo(string $class = 'brand-logo'): string
    {
        // Foloseste iconul/logo-ul existent al clientului, dacă este incarcat in /assets.
        // Ordinea permite să pui pe server oricare dintre fișierele de mai jos fără să modifici codul.
        $logoCandidates = [
            'assets/brand-icon.png',
            'assets/brand-icon.svg',
            'assets/brand-monogram.png',
            'assets/brand-monogram.svg',
            'assets/logo.png',
            'assets/logo.svg',
            'assets/icon.png',
            'assets/favicon.png',
            'assets/favicon.ico',
        ];

        foreach ($logoCandidates as $logoPath) {
            $logoFile = __DIR__ . '/' . $logoPath;
            if (is_file($logoFile)) {
                $version = @filemtime($logoFile) ?: time();
                return '<img class="' . app_h($class) . '" src="' . app_h($logoPath . '?v=' . $version) . '" alt="">';
            }
        }

        return '<span class="brand-fallback" aria-hidden="true"></span>';
    }
}

if (!function_exists('render_mobile_app_header')) {
    function render_mobile_app_header(): void
    {
        ?>
        <div class="app-mobile-header">
            <?= app_brand_logo('app-mobile-logo') ?>
        </div>
        <?php
    }
}

/*
|--------------------------------------------------------------------------
| app_topbar() - Header global, opt-in pe fiecare pagina
|--------------------------------------------------------------------------
| Apel: app_topbar('Dashboard', ['Astăzi']);
| Pune-l imediat sub <main class="main"> in fiecare pagina cand esti gata.
| Nu modifica nimic in paginile care nu il apeleaza.
|--------------------------------------------------------------------------
*/
if (!function_exists('app_topbar')) {
    function app_topbar(string $pageTitle = '', array $breadcrumbs = [], array $opts = []): void
    {
        $showSearch        = $opts['search']        ?? true;
        $showNotifications = $opts['notifications'] ?? true;
        $showAdd           = $opts['add']           ?? true;
        $userInitials      = strtoupper(substr(function_exists('current_user_name') ? (string)current_user_name() : 'U', 0, 1));
        ?>
        <style>
        .app-topbar {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: var(--topbar-height);
            padding: 10px 18px;
            background: #FFFFFF;
            border-bottom: 1px solid var(--border);
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            z-index: 50;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }
        /* Compensam pentru topbar-ul fixed - continutul incepe sub el */
        body:has(.app-topbar) .main { padding-top: var(--topbar-height); }
        body:has(.app-topbar) .app-mobile-header { display: none !important; }
        .app-topbar .tb-breadcrumb {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12.5px;
            color: var(--muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
        }
        .app-topbar .tb-breadcrumb .tb-current {
            color: var(--text);
            font-weight: 700;
        }
        .app-topbar .tb-breadcrumb .tb-sep {
            color: var(--surface-strong);
            font-size: 12px;
        }
        .app-topbar .tb-search {
            flex: 1;
            min-width: 0;
            max-width: 380px;
            height: 34px;
            background: var(--surface-soft);
            border: 1px solid var(--border);
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 0 11px;
            color: var(--muted);
            font-size: 12.5px;
            transition: border-color .14s ease, background .14s ease, box-shadow .14s ease;
            margin: 0;
        }
        .app-topbar .tb-search:hover {
            border-color: var(--accent-soft-2);
            background: var(--surface);
        }
        .app-topbar .tb-search:focus-within {
            background: var(--surface);
            border-color: var(--tone-info);
            box-shadow: none;
        }
        .app-topbar .tb-search .nav-icon, .app-topbar .tb-search > svg {
            width: 14px;
            height: 14px;
            stroke-width: 2.2;
            flex-shrink: 0;
        }
        .app-topbar .tb-search input {
            flex: 1;
            min-width: 0;
            border: 0;
            background: transparent;
            outline: none;
            padding: 0;
            margin: 0;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--text);
            min-height: 0;
        }
        .app-topbar .tb-search input::placeholder {
            color: var(--muted-2);
            font-weight: 400;
        }
        .app-topbar .tb-kbd {
            padding: 2px 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 4px;
            font-family: var(--mono);
            font-size: 10px;
            color: var(--muted);
            flex-shrink: 0;
            pointer-events: none;
        }
        .app-topbar .tb-spacer { flex: 1; }

        /* === Group de actiuni in dreapta - le alipim la marginea dreapta === */
        .app-topbar .tb-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        /* Stil unificat pentru butoanele icon-only din topbar (bell, +, etc) */
        .app-topbar .tb-iconbtn,
        .app-topbar .tb-bell {
            position: relative;
            width: 34px;
            height: 34px;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
            transition: background .14s ease, color .14s ease, border-color .14s ease;
        }
        .app-topbar .tb-iconbtn:hover,
        .app-topbar .tb-bell:hover {
            background: var(--surface-soft);
            color: var(--text);
            border-color: var(--accent-soft-2);
        }
        .app-topbar .tb-iconbtn:active,
        .app-topbar .tb-bell:active { transform: translateY(1px); }
        .app-topbar .tb-iconbtn .nav-icon,
        .app-topbar .tb-iconbtn svg,
        .app-topbar .tb-bell .nav-icon,
        .app-topbar .tb-bell svg {
            width: 16px;
            height: 16px;
            stroke-width: 1.9;
            fill: none;
            stroke: currentColor;
        }
        .app-topbar .tb-search-mobile {
            display: none !important;
        }
        /* Cand un dropdown e deschis, butonul lui rămâne evidentiat */
        .tb-menu.is-open .tb-iconbtn,
        .tb-menu.is-open .tb-bell {
            background: var(--surface-soft);
            color: var(--text);
            border-color: var(--accent-soft-2);
        }
        .app-topbar .tb-bell .tb-bell-dot {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--tone-danger);
            border: 1.5px solid var(--surface);
        }
        /* === Wrapper pentru fiecare buton care are dropdown === */
        .tb-menu {
            position: relative;
        }

        /* === Dropdown popover === */
        .tb-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            min-width: 250px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: none;
            padding: 6px;
            display: none;
            flex-direction: column;
            gap: 2px;
            z-index: 200;
            animation: tbDropIn .14s ease;
        }
        .tb-menu.is-open .tb-dropdown { display: flex; }
        @keyframes tbDropIn {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .tb-dropdown-header {
            padding: 8px 10px 6px;
            font-size: 8.2px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .tb-dropdown-item {
            display: grid;
            grid-template-columns: 32px minmax(0, 1fr);
            gap: 11px;
            align-items: center;
            padding: 9px 10px;
            border-radius: 8px;
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: background .12s ease;
        }
        .tb-dropdown-item:hover { background: var(--surface-soft); }

        .tb-dropdown-item .tdi-icon {
            width: 32px; height: 32px;
            border-radius: 9px;
            background: var(--surface-soft);
            color: var(--accent);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .tb-dropdown-item .tdi-icon .nav-icon,
        .tb-dropdown-item .tdi-icon .nav-icon svg {
            width: 16px; height: 16px; stroke-width: 2;
        }
        .tb-dropdown-item.tone-info .tdi-icon,
        .tb-dropdown-item.tone-success .tdi-icon,
        .tb-dropdown-item.tone-warning .tdi-icon,
        .tb-dropdown-item.tone-danger .tdi-icon {
            background: transparent;
            color: var(--muted);
            border: 1px solid var(--border);
            border-radius: 4px;
        }

        .tb-dropdown-item .tdi-label { line-height: 1.2; }
        .tb-dropdown-item .tdi-sub {
            margin-top: 2px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 500;
            line-height: 1.2;
        }
        .tb-dropdown-divider {
            height: 1px;
            background: var(--border2);
            margin: 4px 6px;
        }
        .tb-dropdown-empty {
            padding: 14px 10px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            font-weight: 500;
        }
        @media (max-width: 860px) {
            .app-topbar {
                left: 0;
                padding-left: 64px;
                padding-right: 12px;
            }
            .app-topbar .tb-search { display: none; }
            .app-topbar .tb-search-mobile {
                display: inline-flex !important;
            }
            .app-topbar .tb-breadcrumb { font-size: 12px; }
            body:has(.app-topbar) .main { padding-top: var(--topbar-height); }
        }
        </style>
        <div class="app-topbar" role="banner">
            <div class="tb-breadcrumb">
                <?php if ($pageTitle !== ''): ?>
                    <span class="<?= empty($breadcrumbs) ? 'tb-current' : '' ?>"><?= app_h($pageTitle) ?></span>
                <?php endif; ?>
                <?php foreach ($breadcrumbs as $i => $crumb): ?>
                    <span class="tb-sep">/</span>
                    <span class="<?= ($i === count($breadcrumbs) - 1) ? 'tb-current' : '' ?>"><?= app_h($crumb) ?></span>
                <?php endforeach; ?>
            </div>

            <?php if ($showSearch): ?>
                <form class="tb-search" action="clients.php" method="get" role="search" id="tbSearchForm">
                    <?= app_icon_svg('search') ?>
                    <input type="search" name="q" id="tbSearchInput" placeholder="Caută client" autocomplete="off" value="<?= app_h($_GET['q'] ?? '') ?>">
                    <span class="tb-kbd">Cmd K</span>
                </form>
            <?php else: ?>
                <div class="tb-spacer"></div>
            <?php endif; ?>

            <div class="tb-actions">
                <?php if ($showSearch): ?>
                    <a class="tb-iconbtn tb-search-mobile" href="clients.php" aria-label="Caută" title="Caută">
                        <?= app_icon_svg('search') ?>
                    </a>
                <?php endif; ?>

                <a class="tb-iconbtn" href="calendar.php" aria-label="Calendar" title="Calendar">
                    <?= app_icon_svg('calendar') ?>
                </a>

                <a class="tb-iconbtn" href="tasks.php" aria-label="Sarcini" title="Sarcini">
                    <?= app_icon_svg('tasks') ?>
                </a>

                <?php if ($showNotifications): ?>
                    <div class="tb-menu" data-tb-menu="bell">
                        <button type="button" class="tb-bell" aria-label="Notificari" title="Notificari" onclick="tbToggleMenu(this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            <span class="tb-bell-dot"></span>
                        </button>
                        <div class="tb-dropdown" role="menu" style="min-width: 280px;">
                            <div class="tb-dropdown-header">Notificari</div>
                            <a class="tb-dropdown-item tone-danger" role="menuitem" href="tasks.php">
                                <span class="tdi-icon"><?= app_icon_svg('tasks') ?></span>
                                <span>
                                    <span class="tdi-label">Sarcini întârziate</span>
                                    <span class="tdi-sub">Vezi backlog-ul</span>
                                </span>
                            </a>
                            <a class="tb-dropdown-item tone-warning" role="menuitem" href="procese_verbale.php">
                                <span class="tdi-icon"><?= app_icon_svg('processes') ?></span>
                                <span>
                                    <span class="tdi-label">PV-uri neemise</span>
                                    <span class="tdi-sub">Lucrări finalizate fără PV</span>
                                </span>
                            </a>
                            <a class="tb-dropdown-item" role="menuitem" href="interventii_facturare.php">
                                <span class="tdi-icon"><?= app_icon_svg('invoice') ?></span>
                                <span>
                                    <span class="tdi-label">Lucrări de transmis</span>
                                    <span class="tdi-sub">Checklist birou</span>
                                </span>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($showAdd): ?>
                    <div class="tb-menu" data-tb-menu="add">
                        <button type="button" class="tb-iconbtn" aria-label="Adaugă nou" title="Adaugă nou" onclick="tbToggleMenu(this)">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 5v14M5 12h14" stroke-linecap="round"></path>
                            </svg>
                        </button>
                        <div class="tb-dropdown" role="menu">
                            <div class="tb-dropdown-header">Adaugă rapid</div>
                            <a class="tb-dropdown-item tone-info" role="menuitem" href="calendar.php?date=<?= date('Y-m-d') ?>&view=day&open_create=1">
                                <span class="tdi-icon"><?= app_icon_svg('calendar') ?></span>
                                <span>
                                    <span class="tdi-label">Programare nouă</span>
                                    <span class="tdi-sub">Lucrare in calendar</span>
                                </span>
                            </a>
                            <a class="tb-dropdown-item" role="menuitem" href="clients.php?open_create=1">
                                <span class="tdi-icon"><?= app_icon_svg('clients') ?></span>
                                <span>
                                    <span class="tdi-label">Client nou</span>
                                    <span class="tdi-sub">Adaugă firmă sau persoană</span>
                                </span>
                            </a>
                            <a class="tb-dropdown-item tone-warning" role="menuitem" href="tasks.php?open_create=1">
                                <span class="tdi-icon"><?= app_icon_svg('tasks') ?></span>
                                <span>
                                    <span class="tdi-label">Sarcină noua</span>
                                    <span class="tdi-sub">In backlog</span>
                                </span>
                            </a>
                            <a class="tb-dropdown-item tone-success" role="menuitem" href="procese_verbale.php?new=1">
                                <span class="tdi-icon"><?= app_icon_svg('processes') ?></span>
                                <span>
                                    <span class="tdi-label">Emite PV</span>
                                    <span class="tdi-sub">Proces verbal nou</span>
                                </span>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
        <script>
        // Toggle dropdown topbar - se incarca o singura data per pagina
        if (typeof window.tbToggleMenu !== 'function') {
            window.tbToggleMenu = function(btn) {
                var menu = btn.closest('.tb-menu');
                if (!menu) return;
                var wasOpen = menu.classList.contains('is-open');
                document.querySelectorAll('.tb-menu.is-open').forEach(function(m) {
                    m.classList.remove('is-open');
                });
                if (!wasOpen) menu.classList.add('is-open');
            };
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.tb-menu')) {
                    document.querySelectorAll('.tb-menu.is-open').forEach(function(m) {
                        m.classList.remove('is-open');
                    });
                }
            });
            document.addEventListener('keydown', function(e) {
                // Escape inchide dropdown-uri si scoate focus din search
                if (e.key === 'Escape') {
                    document.querySelectorAll('.tb-menu.is-open').forEach(function(m) {
                        m.classList.remove('is-open');
                    });
                    var si = document.getElementById('tbSearchInput');
                    if (si && document.activeElement === si) si.blur();
                }
                // Cmd+K / Ctrl+K -> focus pe search
                if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
                    var inp = document.getElementById('tbSearchInput');
                    if (inp) {
                        e.preventDefault();
                        inp.focus();
                        inp.select();
                    }
                }
            });
        }
        </script>
        <?php
    }
}

/*
|--------------------------------------------------------------------------
| app_toast_container() - Container de toast-uri (pune-l o data in layout)
| app_toast() - Trigger toast pe partea de PHP, va aparea la load
|--------------------------------------------------------------------------
*/
if (!function_exists('app_toast_container')) {
    function app_toast_container(): void
    {
        ?>
        <div class="toast-stack" id="appToastStack" aria-live="polite" aria-atomic="true"></div>
        <script>
        (function() {
            window.appToast = function(message, type) {
                type = type || 'info';
                var stack = document.getElementById('appToastStack');
                if (!stack) return;
                var t = document.createElement('div');
                t.className = 'toast toast-' + type;
                t.textContent = message;
                stack.appendChild(t);
                setTimeout(function() {
                    t.classList.add('is-leaving');
                    setTimeout(function() { t.remove(); }, 200);
                }, 3200);
            };
            // Procesam toast-urile setate din PHP la load
            if (window.__appPendingToasts && Array.isArray(window.__appPendingToasts)) {
                window.__appPendingToasts.forEach(function(t) {
                    window.appToast(t.message, t.type);
                });
                window.__appPendingToasts = [];
            }
        })();
        </script>
        <?php
    }
}

if (!function_exists('app_toast')) {
    function app_toast(string $message, string $type = 'info'): void
    {
        $allowed = ['info', 'success', 'warning', 'danger'];
        if (!in_array($type, $allowed, true)) $type = 'info';
        $msg = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>
        <script>
        (function() {
            window.__appPendingToasts = window.__appPendingToasts || [];
            window.__appPendingToasts.push({ message: <?= $msg ?>, type: <?= json_encode($type) ?> });
        })();
        </script>
        <?php
    }
}

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
        'ui_template',
        // Mutate aici din sidebar (configurare documente):
        'document_templates',
        'document_series',
        'document_design',
    ];

    if (in_array($active, $settingsActiveKeys, true)) {
        $active = 'settings';
    }

    $stockActiveKeys = ['stock', 'stock_products', 'stock_receipts', 'stock_movements', 'stock_card'];
    if (in_array($active, $stockActiveKeys, true)) {
        $active = 'stock';
    }

    // Doar paginile operaționale, nu și configurarea (mutată în Setări):
    $documentsKeys = [
        'documente',
        'oferte',
        'contracts',
        'procese_verbale',
    ];
    $documentsOpen = in_array($active, $documentsKeys, true);

    $billingKeys = [
        'facturi',
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
            'calendar'  => ['label' => 'Calendar', 'href' => 'calendar.php', 'icon' => 'calendar'],
            'tasks'     => ['label' => 'Sarcini', 'href' => 'tasks.php', 'icon' => 'tasks'],
            'clients'   => ['label' => 'Contacte', 'href' => 'clients.php', 'icon' => 'clients'],
        ];

        $documentItems = [
            'documente' => ['label' => 'Toate documentele', 'href' => 'documente.php', 'icon' => 'documents'],
            'oferte' => ['label' => 'Oferte', 'href' => 'oferte.php', 'icon' => 'offers'],
            'contracts' => ['label' => 'Contracte', 'href' => 'contracts.php', 'icon' => 'contracts'],
            'procese_verbale' => ['label' => 'Procese verbale', 'href' => 'procese_verbale.php', 'icon' => 'processes'],
            // Șabloane/Serii/Design PDF s-au mutat în Setări (erau dublate)
        ];

        $billingItems = [
            'facturi' => ['label' => 'Facturare', 'href' => 'facturi.php', 'icon' => 'invoice'],
            'incasari' => ['label' => 'Încasare', 'href' => 'incasari.php', 'icon' => 'invoice'],
            'efactura' => ['label' => 'E-Factura', 'href' => 'efactura.php', 'icon' => 'documents'],
            'interventii_facturare' => ['label' => 'Lista lucrări', 'href' => 'interventii_facturare.php', 'icon' => 'processes'],
        ];

        $mainAfterDocuments = [
            'stock'     => ['label' => 'Gestiune', 'href' => 'stock.php', 'icon' => 'stock'],
            'reports'   => ['label' => 'Rapoarte', 'href' => 'reports.php', 'icon' => 'reports'],
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

    <?php app_professional_identity_css(); ?>

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
        font-weight: 650;
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
        font-size: 13px !important;
        font-weight: 650 !important;
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
        font-size: 12.5px !important;
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
        font-weight: 650 !important;
    }

    .sidebar-user-label {
        color: rgba(255, 255, 255, .58) !important;
        font-size: 11px !important;
    }

    .sidebar-user-name {
        color: #FFFFFF !important;
        font-size: 13px !important;
        font-weight: 750 !important;
    }

    .logout-btn {
        min-height: 34px !important;
        border: 1px solid rgba(255, 255, 255, .14) !important;
        border-radius: 6px !important;
        background: rgba(255, 255, 255, .06) !important;
        color: rgba(255, 255, 255, .84) !important;
        box-shadow: none !important;
    }

    .logout-btn:hover {
        background: rgba(255, 255, 255, .10) !important;
        border-color: rgba(255, 255, 255, .18) !important;
        color: #FFFFFF !important;
    }

</style>

    <button class="mobile-menu-button" id="mobileMenuButton" type="button" aria-label="Meniu" aria-expanded="false" onclick="toggleAppSidebar()">
        <span></span>
    </button>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeAppSidebar()"></div>

    <aside class="sidebar" id="appSidebar" aria-label="Meniu principal">
        <div class="sidebar-brand">
            <a class="brand-logo-link" href="dashboard.php" aria-label="Dashboard">
                <?= app_brand_logo('brand-logo') ?>
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
                        <span class="nav-label">Facturare</span>
                        <span class="nav-chevron">›</span>
                    </button>

                    <div class="nav-submenu <?= $billingOpen ? 'open' : '' ?>" id="billingSubmenu">
                        <?php foreach ($billingItems as $key => $item): ?>
                            <a class="nav-subitem <?= $originalActive === $key ? 'active' : '' ?>" href="<?= app_h($item['href']) ?>">
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
            'clients'                => ['Contacte',   []],
            'stock'                  => ['Gestiune',   []],
            'facturare'              => ['Facturare',  []],
            'interventii_facturare'  => ['Facturare',  ['Lista lucrări']],
            'facturi'                => ['Facturare',  ['Facturare']],
            'incasari'               => ['Facturare',  ['Încasare']],
            'efactura'               => ['Facturare',  ['E-Factura']],
            'facturi_recurente'      => ['Recurente',  ['Facturi automate']],
            'reports'                => ['Rapoarte',   []],
            'review_feedback'        => ['Feedback',   []],
            'settings'               => ['Setări',     []],

            // submeniu Documente
            'documente'              => ['Documente',  ['Toate']],
            'oferte'                 => ['Documente',  ['Oferte']],
            'contracts'              => ['Documente',  ['Contracte']],
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
            'ui_template'            => ['Template UI', ['Identitate interna']],
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
