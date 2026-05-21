<?php

/*
|--------------------------------------------------------------------------
| app_theme_css.php
|--------------------------------------------------------------------------
| CSS global al aplicației (variabile, layout, componente, identitate vizuală).
| Conține fuziunea fostelor funcții app_theme_css() + app_professional_identity_css().
| Apel: app_theme_css();
|--------------------------------------------------------------------------
*/

if (!function_exists('app_theme_css')) {
    function app_theme_css(): void
    {
        // Refinări tipografice și vizuale aplicate global (fostul professional_identity).
        // Selectoare specifice = prioritate naturală mai mare în CSS.
        ?>
        <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap&subset=latin-ext");

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

            /* === Aliasuri --pz-* pentru pagini ce folosesc paleta pz === */
            --pz-bg:      #F8FAFC;
            --pz-surf:    #FFFFFF;
            --pz-soft:    #F8FAFC;
            --pz-line:    #E2E8F0;
            --pz-lines:   #F1F5F9;
            --pz-title:   #0F172A;
            --pz-text:    #334155;
            --pz-mu:      #64748B;
            --pz-fa:      #94A3B8;
            --pz-bl:      #2563EB;
            --pz-bld:     #1E3A8A;
            --pz-bls:     #EFF6FF;
            --pz-blb:     #BFDBFE;
            --pz-gr:      #166534;
            --pz-grs:     #F0FDF4;
            --pz-grb:     #BBF7D0;
            --pz-or:      #9A3412;
            --pz-ors:     #FFF7ED;
            --pz-orb:     #FED7AA;
            --pz-re:      #991B1B;
            --pz-res:     #FEF2F2;
            --pz-reb:     #FECACA;
            --pz-r:       8px;
            --pz-rs:      4px;
            --pz-gap:     12px;

            /* Accente — variantele „acc" pentru dot-uri, accent-bars, status-dots */
            --pz-gr-acc:  #22C55E;
            --pz-or-acc:  #F97316;
            --pz-re-acc:  #EF4444;

            /* Brand — sidebar navy */
            --pz-brand:   #12345A;

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
            --font: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            --mono: "DM Mono", ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;

            /* === Sistem tipografic CRM === */
            --type-page: 13px;
            --type-label: 11px;
            --type-input: 13px;
            --type-table-main: 14px;
            --type-table-meta: 12px;
            --type-table-data: 13px;
            --type-stat: 26px;
            --type-sidebar: 14px;
            --type-weight-light: 300;
            --type-weight-regular: 400;
            --type-weight-medium: 500;
            --type-weight-semibold: 600;
            --type-muted: #6B7280;

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
            font-weight: var(--type-weight-regular);
            line-height: 1.45;
            -webkit-text-size-adjust: 100%;
            text-rendering: geometricPrecision;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
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

        /* === PestZone typography system: Inter, aerisit, fara greutati agresive === */
        :where(html, body, button, input, select, textarea) {
            font-family: var(--font) !important;
        }

        body {
            color: var(--text-body) !important;
            font-size: var(--type-page) !important;
            font-weight: var(--type-weight-regular) !important;
            line-height: 1.45 !important;
            letter-spacing: 0 !important;
        }

        :where(h1, .page-title h1, .dashboard-hero h1, .clients-hero h1, .contracts-hero h1,
        .contract-hero h1, .tasks-hero h1, .services-hero h1, .team-hero h1, .reports-hero h1,
        .settings-head h1, .hero h1, .page-hero h1, .stock-hero h1, .docs-hero h1, .document-hero h1,
        .email-hero h1, .pv-hero h1) {
            font-size: 24px !important;
            line-height: 1.18 !important;
            font-weight: var(--type-weight-semibold) !important;
            letter-spacing: -0.01em !important;
        }

        .type-display-light,
        .metric-display-light {
            font-weight: var(--type-weight-light) !important;
            letter-spacing: 0.01em !important;
        }

        :where(.panel-title, .card-title, .section-title, .report-card h2, .setting-title, .stock-card h2) {
            font-size: 16px !important;
            line-height: 1.25 !important;
            font-weight: var(--type-weight-medium) !important;
            letter-spacing: -0.01em !important;
        }

        :where(.muted, .cell-muted, .setting-desc, .panel-subtitle, .doc-meta, .stat-sub, .kpi-sub,
        .stock-note, .quick-card span, .dashboard-hero p, .clients-hero p, .contracts-hero p,
        .contract-hero p, .tasks-hero p, .services-hero p, .team-hero p, .reports-hero p,
        .settings-head p, .hero p, .page-hero p, .stock-hero p, .docs-hero p, .document-hero p,
        .email-hero p, .pv-hero p) {
            color: var(--type-muted) !important;
            font-size: var(--type-table-meta) !important;
            line-height: 1.45 !important;
            font-weight: var(--type-weight-regular) !important;
        }

        :where(label, .field label, .filter-form label, .filters label, .filter-grid label,
        .form-grid label, .clients-toolbar label, .stock-field label, .settings-module-page label,
        .section-label, .details-label, .stat-label, .stock-kpi .label, .metric span) {
            color: var(--type-muted) !important;
            font-size: var(--type-label) !important;
            line-height: 1.2 !important;
            font-weight: var(--type-weight-medium) !important;
            text-transform: uppercase !important;
            letter-spacing: 0.04em !important;
        }

        :where(input, select, textarea, .field input, .field select, .field textarea,
        .filter-form input, .filter-form select, .filters input, .filters select,
        .filter-grid input, .filter-grid select, .form-grid input, .form-grid select,
        .clients-toolbar input, .clients-toolbar select, .stock-field input, .stock-field select,
        .pz-autocomplete-input, body.calendar-page .calendar-filter-line .select,
        body.calendar-page .calendar-date-form .date-input) {
            font-size: var(--type-input) !important;
            line-height: 1.35 !important;
            font-weight: var(--type-weight-regular) !important;
            letter-spacing: 0 !important;
            text-transform: none !important;
        }

        :where(input::placeholder, textarea::placeholder, .pz-autocomplete-input::placeholder) {
            color: #9CA3AF !important;
            font-size: var(--type-input) !important;
            font-weight: var(--type-weight-regular) !important;
        }

        :where(table, .stock-table) {
            font-size: var(--type-table-data) !important;
            line-height: 1.45 !important;
        }

        :where(table th, .stock-table th) {
            color: var(--type-muted) !important;
            font-size: var(--type-label) !important;
            line-height: 1.2 !important;
            font-weight: var(--type-weight-medium) !important;
            text-transform: uppercase !important;
            letter-spacing: 0.04em !important;
        }

        :where(table td, .stock-table td) {
            color: var(--text-body) !important;
            font-size: var(--type-table-data) !important;
            line-height: 1.45 !important;
            font-weight: var(--type-weight-regular) !important;
            padding-top: 11px !important;
            padding-bottom: 11px !important;
        }

        :where(table td strong, .stock-table td strong, .doc-title, .client-name, .company-name,
        .location-name, .invoice-title, .contract-title, .work-title, .quick-card strong) {
            color: var(--text) !important;
            font-size: var(--type-table-main) !important;
            line-height: 1.35 !important;
            font-weight: var(--type-weight-medium) !important;
            letter-spacing: 0 !important;
        }

        :where(.doc-number, .amount, .date, .date-value, .table-date, .table-id, .doc-id,
        .invoice-number, .stock-table td, table td small) {
            font-size: var(--type-table-data) !important;
            font-weight: var(--type-weight-regular) !important;
            letter-spacing: 0 !important;
        }

        :where(.kpi-value, .stat-value, .stock-kpi .value, .metric strong, .dash-kpi-value,
        .dashboard-stat-value) {
            color: var(--text) !important;
            font-size: var(--type-stat) !important;
            line-height: 1 !important;
            font-weight: var(--type-weight-semibold) !important;
            letter-spacing: -0.01em !important;
        }

        :where(.badge, .status-badge, .chip, .stat-pill, .hero-pill, .type-pill, .status-pill,
        .client-status-badge, .pill, .trend-chip, .eff-grade-pill, .badge-count, .stock-badge,
        .email-state) {
            font-size: 11px !important;
            line-height: 1.2 !important;
            font-weight: var(--type-weight-medium) !important;
            letter-spacing: 0 !important;
        }

        :where(.btn, button, .page-btn, .icon-action, .row-menu-trigger, .stock-tabs a,
        .billing-module-nav a) {
            font-size: 12.5px !important;
            line-height: 1.2 !important;
            font-weight: var(--type-weight-medium) !important;
            letter-spacing: 0 !important;
        }

        :where(.topbar > div, .app-topbar, .app-topbar *) {
            font-weight: var(--type-weight-medium) !important;
        }

        :where(.topbar input, .topbar select, .app-topbar input, .app-topbar select) {
            font-weight: var(--type-weight-regular) !important;
        }
        </style>
        <?php
    }
}

if (!function_exists('app_professional_identity_css')) {
    /**
     * @deprecated CSS-ul a fost fuzionat în app_theme_css(). Stub păstrat pentru
     * compatibilitate cu eventuale apeluri vechi.
     */
    function app_professional_identity_css(): void
    {
        // no-op: vezi app_theme_css().
    }
}
