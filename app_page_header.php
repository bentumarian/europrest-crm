<?php
/*
|--------------------------------------------------------------------------
| app_page_header.php
|--------------------------------------------------------------------------
| Componenta UI reutilizabilă pentru header-ul de pagină în CRM PestZone.
| Conform regulilor de design unificat:
|   - Kicker (mic, gri, uppercase) - zona modulului
|   - Titlu h2 (20px, weight 500) - numele paginii
|   - Subtitlu (12px, gri) - metrici live sau descriere
|   - Acțiuni dreapta (max 3 butoane, înălțime 32px)
|   - Optional: period selector pill
|   - Optional: tab-uri text cu underline activ
|   - Optional: KPI mini inline
|
| Folosire:
|   pz_page_header([
|       'kicker'   => 'Operațional',
|       'title'    => 'Clienți',
|       'subtitle' => '412 contacte · ultim adăugat acum 2 zile',
|       'actions'  => [
|           ['label' => 'Export', 'href' => '?export=1', 'variant' => 'ghost', 'icon' => 'ti-download'],
|           ['label' => 'Client nou', 'href' => 'client.php?new=1', 'variant' => 'primary', 'icon' => 'ti-plus'],
|       ],
|   ]);
|
| Tabs:
|   'tabs' => [
|       ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'stock.php', 'active' => true],
|       ['key' => 'produse', 'label' => 'Produse', 'href' => 'stock_products.php'],
|   ]
|
| Period:
|   'period' => [
|       'current' => 'month',
|       'param' => 'period',
|       'options' => ['today' => 'Azi', 'month' => 'Lună', 'year' => 'An'],
|   ]
|--------------------------------------------------------------------------
*/

if (!function_exists('pz_ph_h')) {
    function pz_ph_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pz_ph_period_url')) {
    function pz_ph_period_url(string $param, string $value): string
    {
        $params = $_GET;
        $params[$param] = $value;
        $base = basename($_SERVER['PHP_SELF'] ?? 'index.php');
        return $base . '?' . http_build_query($params);
    }
}

if (!function_exists('pz_page_header_css')) {
    /**
     * Inseramentu de CSS pentru header. Inclus o singură dată per pagină
     * prin guard static. Folosește tokens --pz-* globali.
     */
    function pz_page_header_css(): void
    {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        ?>
        <style>
        /* PZ Page Header — design unificat conform style guide */
        .pz-ph {
            background: var(--pz-surf);
            border: 1px solid var(--pz-line);
            border-radius: var(--pz-r);
            padding: 12px 18px;
            margin-bottom: 14px;
        }
        .pz-ph-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .pz-ph-main { min-width: 0; flex: 1; }
        /* Back link — buton „înapoi" pentru sub-pagini (ex. zona Setări) */
        .pz-ph-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--pz-mu);
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            margin: 0 0 10px;
            padding: 4px 8px 4px 4px;
            border-radius: 6px;
            transition: color .15s ease, background-color .15s ease;
            line-height: 1;
            width: fit-content;
        }
        .pz-ph-back:hover {
            color: var(--pz-bld);
            background: var(--pz-soft);
        }
        .pz-ph-back i {
            font-size: 16px;
        }
        .pz-ph-kicker {
            font-size: 11px;
            color: var(--pz-mu);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 500;
            margin: 0 0 2px;
        }
        .pz-ph-title {
            font-size: 20px;
            font-weight: 500;
            color: var(--pz-title);
            margin: 0;
            letter-spacing: -0.005em;
            line-height: 1.25;
        }
        .pz-ph-subtitle {
            font-size: 12px;
            color: var(--pz-fa);
            margin: 3px 0 0;
            line-height: 1.4;
        }
        .pz-ph-actions {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .pz-ph-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            height: 32px;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 500;
            font-family: inherit;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            border: 1px solid transparent;
            line-height: 1;
            white-space: nowrap;
        }
        .pz-ph-btn i { font-size: 14px; }
        .pz-ph-btn.primary {
            background: var(--pz-bl);
            color: #fff;
            border-color: var(--pz-bl);
        }
        .pz-ph-btn.primary:hover {
            background: var(--pz-bld);
            border-color: var(--pz-bld);
        }
        .pz-ph-btn.ghost {
            background: var(--pz-surf);
            color: var(--pz-text);
            border-color: var(--pz-line);
        }
        .pz-ph-btn.ghost:hover {
            background: var(--pz-soft);
            border-color: var(--pz-blb);
            color: var(--pz-bld);
        }
        .pz-ph-btn.success {
            background: var(--pz-gr);
            color: #fff;
            border-color: var(--pz-gr);
        }
        .pz-ph-btn.success:hover { background: #14532D; border-color: #14532D; }
        .pz-ph-btn.danger {
            background: var(--pz-surf);
            color: var(--pz-re);
            border-color: var(--pz-reb);
        }
        .pz-ph-btn.danger:hover {
            background: var(--pz-res);
        }

        /* Period selector pill */
        .pz-ph-period {
            display: inline-flex;
            padding: 2px;
            border: 1px solid var(--pz-line);
            border-radius: 6px;
            background: var(--pz-surf);
        }
        .pz-ph-period a {
            padding: 4px 10px;
            font-size: 11px;
            border-radius: 4px;
            color: var(--pz-mu);
            text-decoration: none;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .pz-ph-period a:hover { color: var(--pz-title); }
        .pz-ph-period a.current {
            background: var(--pz-bls);
            color: var(--pz-bld);
            font-weight: 500;
        }

        /* Sub-tabs */
        .pz-ph-tabs {
            display: flex;
            gap: 0;
            border-bottom: 1px solid var(--pz-line);
            margin: 14px -20px -16px;
            padding: 0 20px;
            overflow-x: auto;
            scrollbar-width: none;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
        }
        .pz-ph-tabs::-webkit-scrollbar { display: none; }
        .pz-ph-tabs a {
            padding: 10px 14px;
            font-size: 12.5px;
            color: var(--pz-mu);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            transition: all 0.15s;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .pz-ph-tabs a:hover { color: var(--pz-title); }
        .pz-ph-tabs a.active {
            color: var(--pz-bld);
            border-bottom-color: var(--pz-bl);
            font-weight: 500;
        }
        /* Wrapper pentru a putea afișa gradient fade — folosit la nivel de containere mobile */
        .pz-ph-tabs-wrap {
            position: relative;
            margin: 14px -20px -16px;
        }
        .pz-ph-tabs-wrap .pz-ph-tabs {
            margin: 0;
        }

        /* Toolbar - filtre inline (replace pentru bare de filtre vechi) */
        .pz-ph-toolbar {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            padding-top: 12px;
            margin-top: 14px;
            border-top: 1px solid var(--pz-lines);
            min-height: 32px;
        }
        .pz-ph-toolbar input[type="date"],
        .pz-ph-toolbar input[type="text"],
        .pz-ph-toolbar input[type="search"],
        .pz-ph-toolbar select {
            height: 32px;
            padding: 0 10px;
            border: 1px solid var(--pz-line);
            border-radius: 6px;
            font-size: 12px;
            background: var(--pz-surf);
            color: var(--pz-title);
            font-family: inherit;
            transition: border-color .15s, box-shadow .15s;
        }
        .pz-ph-toolbar input:focus,
        .pz-ph-toolbar select:focus {
            outline: none;
            border-color: var(--pz-bl);
            box-shadow: 0 0 0 3px var(--pz-bls);
        }
        .pz-ph-toolbar .pz-ph-search {
            position: relative;
            flex: 1;
            min-width: 180px;
            max-width: 320px;
        }
        .pz-ph-toolbar .pz-ph-search i {
            position: absolute;
            left: 9px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            color: var(--pz-fa);
        }
        .pz-ph-toolbar .pz-ph-search input {
            width: 100%;
            padding-left: 30px;
            background: var(--pz-bg);
        }
        .pz-ph-toolbar button,
        .pz-ph-toolbar .pz-ph-btn {
            margin-left: auto;
        }

        /* Filter bar — bara de filtre cu popover pentru filtre extinse */
        .pz-fb {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            width: 100%;
        }
        .pz-fb-date-range {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--pz-bg);
            border: 1px solid var(--pz-line);
            border-radius: 6px;
            padding: 0 8px;
            height: 32px;
            width: fit-content;
            cursor: pointer;
            flex-wrap: nowrap;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .pz-fb-date-range:hover {
            border-color: var(--pz-blb);
        }
        .pz-fb-date-range:focus-within {
            border-color: var(--pz-bl);
            box-shadow: 0 0 0 3px var(--pz-bls);
        }
        .pz-fb-date-range i {
            font-size: 14px;
            color: var(--pz-fa);
        }
        .pz-fb-date-range input,
        .pz-fb-date-range input[type="date"],
        .pz-fb-date-range input[type="text"],
        .pz-fb-date-range input.flatpickr-input {
            border: 0 !important;
            background: transparent !important;
            padding: 0 !important;
            margin: 0 !important;
            height: auto !important;
            min-height: 0 !important;
            font-size: 12px !important;
            color: var(--pz-title) !important;
            font-family: inherit !important;
            width: 74px !important;
            min-width: 0 !important;
            box-shadow: none !important;
            outline: none !important;
            font-weight: 500 !important;
            text-align: center;
            cursor: pointer;
            font-variant-numeric: tabular-nums;
        }
        .pz-fb-date-range input::placeholder {
            color: var(--pz-fa);
            font-weight: 400;
        }
        .pz-fb-date-range input:focus {
            outline: none !important;
            box-shadow: none !important;
            border: 0 !important;
        }
        .pz-fb-date-range .sep { color: var(--pz-fa); font-size: 11px; user-select: none; }

        /* Navigare cu butoane stânga/dreapta + buton text (Azi) — folosit ex. în calendar */
        .pz-fb-nav {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .pz-fb-nav-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 32px;
            min-width: 32px;
            padding: 0 10px;
            font-size: 12px;
            font-weight: 500;
            font-family: inherit;
            color: var(--pz-text);
            background: var(--pz-surf);
            border: 1px solid var(--pz-line);
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all .15s ease;
            line-height: 1;
            white-space: nowrap;
        }
        .pz-fb-nav-btn:hover {
            background: var(--pz-soft);
            border-color: var(--pz-blb);
            color: var(--pz-bld);
        }
        .pz-fb-nav-btn.arrow {
            font-size: 16px;
            padding: 0 8px;
            color: var(--pz-mu);
        }
        .pz-fb-nav-btn.arrow:hover {
            color: var(--pz-bld);
        }
        .pz-fb-nav-btn.primary {
            background: var(--pz-bl);
            color: #fff;
            border-color: var(--pz-bl);
        }
        .pz-fb-nav-btn.primary:hover {
            background: var(--pz-bld);
            border-color: var(--pz-bld);
            color: #fff;
        }

        .pz-fb-search {
            position: relative;
            flex: 1;
            min-width: 160px;
            max-width: 280px;
        }
        .pz-fb-search i {
            /* Iconul de lupă din interiorul input-ului ascuns —
               păstrăm marker-ul HTML pentru compatibilitate, dar nu îl arătăm. */
            display: none !important;
        }
        .pz-fb-search input {
            width: 100%;
            height: 32px;
            padding: 0 10px;
            border: 1px solid var(--pz-line);
            border-radius: 6px;
            font-size: 12px;
            background: var(--pz-bg);
            color: var(--pz-title);
            font-family: inherit;
            -webkit-appearance: none;
            appearance: none;
        }
        .pz-fb-search input:focus {
            outline: none;
            border-color: var(--pz-bl);
            box-shadow: 0 0 0 3px var(--pz-bls);
        }
        /* Ascund icon-urile native pe input[type="search"] (Webkit + Firefox)
           ca să rămână doar iconul nostru Tabler ti-search din interior */
        .pz-fb-search input[type="search"]::-webkit-search-decoration,
        .pz-fb-search input[type="search"]::-webkit-search-cancel-button,
        .pz-fb-search input[type="search"]::-webkit-search-results-button,
        .pz-fb-search input[type="search"]::-webkit-search-results-decoration {
            -webkit-appearance: none;
            appearance: none;
            display: none;
        }
        .pz-fb-search input::-ms-clear,
        .pz-fb-search input::-ms-reveal {
            display: none;
            width: 0;
            height: 0;
        }

        .pz-fb-spacer { flex: 1; }

        .pz-fb-filter-btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 32px;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 500;
            font-family: inherit;
            border-radius: 6px;
            cursor: pointer;
            background: var(--pz-surf);
            color: var(--pz-text);
            border: 1px solid var(--pz-line);
            white-space: nowrap;
        }
        .pz-fb-filter-btn:hover {
            background: var(--pz-soft);
            border-color: var(--pz-blb);
            color: var(--pz-bld);
        }
        .pz-fb-filter-btn .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 9px;
            background: var(--pz-bl);
            color: #fff;
            font-size: 10px;
            font-weight: 500;
            margin-left: 2px;
        }

        /* Popover cu filtre */
        .pz-fb-popover-wrap {
            position: relative;
            display: inline-block;
        }
        .pz-fb-popover {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 280px;
            max-width: 320px;
            background: var(--pz-surf);
            border: 1px solid var(--pz-line);
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08), 0 2px 6px rgba(15, 23, 42, 0.04);
            padding: 14px;
            z-index: 200;
            display: none;
            flex-direction: column;
            gap: 10px;
        }
        .pz-fb-popover.is-open { display: flex; }
        .pz-fb-popover .pf-row {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .pz-fb-popover label {
            font-size: 10.5px;
            font-weight: 500;
            color: var(--pz-mu);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .pz-fb-popover select,
        .pz-fb-popover input {
            width: 100%;
            height: 34px;
            padding: 0 10px;
            border: 1px solid var(--pz-line);
            border-radius: 6px;
            font-size: 13px;
            background: var(--pz-surf);
            color: var(--pz-title);
            font-family: inherit;
        }
        .pz-fb-popover select:focus,
        .pz-fb-popover input:focus {
            outline: none;
            border-color: var(--pz-bl);
            box-shadow: 0 0 0 3px var(--pz-bls);
        }
        .pz-fb-popover .pf-actions {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
            padding-top: 4px;
        }
        .pz-fb-popover .pf-actions .pz-ph-btn { margin-left: 0; }

        /* Mobile — filtre intră toate în popover */
        @media (max-width: 640px) {
            .pz-fb { gap: 6px; }
            .pz-fb-date-range {
                width: fit-content !important;
                flex: 0 0 auto !important;
                flex-wrap: nowrap !important;
                white-space: nowrap !important;
                flex-shrink: 0 !important;
                justify-content: flex-start !important;
                overflow: hidden !important;
            }
            .pz-fb-date-range input,
            .pz-fb-date-range input[type="date"],
            .pz-fb-date-range input[type="text"] {
                width: 74px !important;
                flex: 0 0 74px !important;
                min-width: 74px !important;
                max-width: 74px !important;
            }
            .pz-fb-date-range .sep {
                flex: 0 0 auto !important;
                display: inline-block !important;
            }
            .pz-fb-search { max-width: 100%; }
            .pz-fb-spacer { display: none; }
            .pz-fb-popover {
                position: fixed;
                top: auto;
                bottom: 0;
                left: 0;
                right: 0;
                max-width: 100%;
                width: 100%;
                border-radius: 12px 12px 0 0;
                max-height: 70vh;
                overflow-y: auto;
            }
            .pz-fb-popover.is-open::before {
                content: '';
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.4);
                z-index: -1;
                animation: pz-fb-fade 0.2s ease;
            }
        }
        @keyframes pz-fb-fade {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Meta bar (default când nu există kpis/tabs/toolbar) */
        .pz-ph-meta {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-top: 10px;
            margin-top: 12px;
            border-top: 1px solid var(--pz-lines);
            font-size: 11.5px;
            color: var(--pz-fa);
            flex-wrap: wrap;
        }
        .pz-ph-meta .meta-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .pz-ph-meta .meta-item i { font-size: 13px; color: var(--pz-mu); }
        .pz-ph-meta .meta-item strong { font-weight: 500; color: var(--pz-text); }

        /* KPI inline — emma.ro upgrade: accent-bar 3px + icon-tile + sublabel */
        .pz-ph-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--pz-lines);
        }
        .pz-ph-kpi {
            position: relative;
            overflow: hidden;
            background: var(--pz-surf);
            border: 1px solid var(--pz-line);
            border-radius: 8px;
            padding: 12px 14px;
        }
        /* Accent-bar 3px stânga, colorat după tone */
        .pz-ph-kpi::before {
            content: '';
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 3px;
            background: var(--em-muted, #3E4C8F);
        }
        .pz-ph-kpi.info::before    { background: var(--em-navy, #061142); }
        .pz-ph-kpi.danger::before  { background: var(--pz-re, #DC2626); }
        .pz-ph-kpi.success::before { background: var(--pz-gr-acc, #22C55E); }
        .pz-ph-kpi.warning::before { background: var(--em-coral-mid, #FF7A3D); }

        /* Head row: icon-tile + label uppercase */
        .pz-ph-kpi .kpi-head {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 8px;
        }
        .pz-ph-kpi .kpi-icon {
            width: 30px;
            height: 30px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--pz-soft);
            color: var(--pz-mu);
            flex-shrink: 0;
        }
        .pz-ph-kpi .kpi-icon i { font-size: 15px; }
        .pz-ph-kpi.info    .kpi-icon { background: #EEF0FB; color: var(--em-navy, #061142); }
        .pz-ph-kpi.danger  .kpi-icon { background: var(--pz-res); color: var(--pz-re); }
        .pz-ph-kpi.success .kpi-icon { background: var(--pz-grs); color: var(--pz-gr-acc); }
        .pz-ph-kpi.warning .kpi-icon { background: var(--em-coral-bg, #FFF1EC); color: var(--em-coral-mid, #FF7A3D); }

        .pz-ph-kpi .label {
            font-size: 10.5px;
            color: var(--pz-mu);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 500;
            margin: 0;
        }
        .pz-ph-kpi .value {
            display: flex;
            align-items: baseline;
            gap: 6px;
            flex-wrap: wrap;
            font-size: 24px;
            font-weight: 500;
            color: var(--pz-title);
            margin: 0;
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }
        .pz-ph-kpi .value .sublabel {
            font-size: 11px;
            color: var(--pz-mu);
            font-weight: 400;
            line-height: 1.2;
        }
        .pz-ph-kpi .value .meta {
            font-size: 11px;
            color: var(--pz-fa);
            font-weight: 400;
        }
        .pz-ph-kpi.success .value { color: var(--pz-gr-acc); }
        .pz-ph-kpi.warning .value { color: var(--em-coral-mid, #FF7A3D); }
        .pz-ph-kpi.danger  .value { color: var(--pz-re); }
        .pz-ph-kpi.info    .value { color: var(--em-navy, #061142); }

        /* Responsive */
        @media (max-width: 768px) {
            .pz-ph {
                padding: 14px 16px;
                max-width: 100%;
                box-sizing: border-box;
                overflow: hidden;
            }
            .pz-ph-title { font-size: 18px; }
            .pz-ph-actions { width: 100%; justify-content: flex-start; }
            .pz-ph-period { width: 100%; }
            .pz-ph-period a { flex: 1; text-align: center; }
            /* KPIs pe mobile — maxim 2 coloane, lățime în limitele cardului */
            .pz-ph-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 6px !important;
            }
            .pz-ph-kpi {
                padding: 8px 10px;
                min-width: 0;
            }
            .pz-ph-kpi .value { font-size: 16px; word-break: break-word; }
            .pz-ph-kpi .label { font-size: 10px; word-break: break-word; }
            .pz-ph-kpi .value .meta { font-size: 10px; }
            /* Tabs pe mobile — ascund tab-ul activ (pagina curentă e clară din
               kicker + title), iar restul se distribuie egal pe lățime ca să
               încapă toate fără scroll. */
            .pz-ph-tabs {
                margin-left: -16px;
                margin-right: -16px;
                padding-left: 8px;
                padding-right: 8px;
                overflow-x: hidden;
                justify-content: space-between;
                -webkit-mask-image: none;
                mask-image: none;
            }
            .pz-ph-tabs a {
                font-size: 11.5px;
                padding: 10px 4px;
                flex: 1 1 auto;
                min-width: 0;
                text-align: center;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .pz-ph-tabs a.active {
                display: none;
            }
            /* Toolbar — wrap permis ca să nu iasă din card */
            .pz-ph-toolbar { gap: 6px; }
            .pz-ph-toolbar > * { min-width: 0; }
        }
        @media (max-width: 480px) {
            .pz-ph-btn { padding: 0 9px; font-size: 11px; height: 30px; }
            .pz-ph-btn i { font-size: 12px; }
            /* La ecrane foarte mici — un singur card per rând pentru KPI dacă conținutul e prea lung */
            .pz-ph-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }
        </style>
        <?php
    }
}

if (!function_exists('pz_table_cards_css')) {
    /**
     * Emite CSS-ul care pe mobile (max-width: 760px) transformă un tabel în
     * carduri verticale, cu label-ul deasupra valorii și aliniere stânga.
     *
     * Pentru a se aplica, tabelul trebuie să aibă clasa pz-table-cards, iar
     * fiecare <td> trebuie să aibă atributul data-label="X".
     *
     * Apel: pz_table_cards_css();  (o singură dată per pagină, idempotent)
     */
    function pz_table_cards_css(): void
    {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        ?>
        <style>
        /* PZ Table → Cards layout pe mobile */
        @media (max-width: 760px) {
            .pz-table-cards-wrap {
                overflow: visible !important;
                background: transparent !important;
                border: 0 !important;
                box-shadow: none !important;
            }
            table.pz-table-cards {
                display: block !important;
                min-width: 0 !important;
                width: 100% !important;
            }
            table.pz-table-cards thead {
                display: none !important;
            }
            table.pz-table-cards tbody {
                display: block !important;
                width: 100% !important;
            }
            table.pz-table-cards tbody tr {
                display: block !important;
                background: var(--pz-surf) !important;
                border: 1px solid var(--pz-line) !important;
                border-radius: 10px !important;
                padding: 12px 14px !important;
                margin-bottom: 8px !important;
                box-shadow: none !important;
            }
            table.pz-table-cards tbody tr:last-child {
                margin-bottom: 0 !important;
            }
            table.pz-table-cards tbody td {
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 2px !important;
                padding: 6px 0 !important;
                border: 0 !important;
                border-bottom: 1px solid var(--pz-lines) !important;
                font-size: 13px !important;
                text-align: left !important;
                min-height: 0 !important;
                width: 100% !important;
            }
            table.pz-table-cards tbody td:last-child {
                border-bottom: 0 !important;
            }
            table.pz-table-cards tbody td::before {
                content: attr(data-label) !important;
                font-weight: 600 !important;
                font-size: 10px !important;
                color: var(--pz-mu) !important;
                text-transform: uppercase !important;
                letter-spacing: 0.05em !important;
                text-align: left !important;
                line-height: 1.2 !important;
            }
            table.pz-table-cards tbody td > * {
                text-align: left !important;
                min-width: 0 !important;
                word-break: break-word !important;
                max-width: 100% !important;
            }
            table.pz-table-cards tbody td strong {
                font-weight: 600 !important;
                color: var(--pz-text) !important;
            }
            table.pz-table-cards tbody td .status-pill,
            table.pz-table-cards tbody td .type-pill {
                border-radius: 6px !important;
                padding: 3px 8px !important;
                font-size: 11px !important;
                width: fit-content !important;
                align-self: flex-start !important;
            }
        }
        </style>
        <?php
    }
}

if (!function_exists('pz_date_picker_assets')) {
    /**
     * Injectează asset-urile vanillajs-datepicker (CSS + JS + locale ro) o singură dată per pagină
     * plus stilurile PestZone customizate cu tokens pz-*.
     *
     * Calendarul are vizualizare clară pe an: click pe titlul "mai 2026" deschide picker-ul
     * de luni, click pe an "2026" deschide picker-ul de ani, click pe interval "2020-2029"
     * deschide picker-ul de decenii. Săritura între ani devine 1 click în loc de 12.
     *
     * Folosire JS (după ce DOM-ul e gata):
     *   new Datepicker(input, { language: 'ro', format: 'dd.mm.yyyy', weekStart: 1, ... });
     *   new DateRangePicker(rangeEl, { language: 'ro', format: 'dd.mm.yyyy', weekStart: 1, ... });
     *
     * Sau mai simplu, prin helper-ul pz_date_range_init() (vezi mai jos) pentru cazul
     * standard cu două inputs (from/to) și hidden fields ISO pentru submit.
     */
    function pz_date_picker_assets(): void
    {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/vanillajs-datepicker@1.3.4/dist/css/datepicker.min.css">
        <style>
        /* PZ Date Picker — vanillajs-datepicker tematizat cu tokens PestZone */
        .datepicker {
            font-family: 'Satoshi', 'Inter', system-ui, -apple-system, sans-serif !important;
            font-size: 13px;
            z-index: 250;
        }
        .datepicker-picker {
            background: var(--pz-surf) !important;
            border: 1px solid var(--pz-line) !important;
            border-radius: 8px !important;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.10), 0 2px 6px rgba(15, 23, 42, 0.04) !important;
            padding: 10px !important;
            min-width: 260px;
        }
        .datepicker-dropdown { padding-top: 4px !important; }
        .datepicker-header { background: transparent !important; }
        .datepicker-controls {
            padding: 4px 0 !important;
            display: flex !important;
            align-items: center !important;
            gap: 2px !important;
        }
        .datepicker-controls .button {
            background: transparent !important;
            border: 0 !important;
            color: var(--pz-text) !important;
            font-weight: 500 !important;
            font-size: 13px !important;
            height: 30px !important;
            padding: 0 10px !important;
            border-radius: 6px !important;
            cursor: pointer !important;
            box-shadow: none !important;
            transition: background-color .12s, color .12s !important;
            line-height: 1 !important;
        }
        .datepicker-controls .button:hover {
            background: var(--pz-soft) !important;
            color: var(--pz-bld) !important;
        }
        .datepicker-controls .view-switch {
            font-weight: 600 !important;
            color: var(--pz-title) !important;
            flex: 1 !important;
            text-align: center !important;
            font-size: 13.5px !important;
        }
        .datepicker-controls .view-switch:hover {
            background: var(--pz-bls) !important;
            color: var(--pz-bld) !important;
        }
        .datepicker-controls .prev-button,
        .datepicker-controls .next-button {
            width: 30px !important;
            padding: 0 !important;
            color: var(--pz-mu) !important;
            font-weight: 700 !important;
        }
        .datepicker-grid { gap: 0 !important; }
        .datepicker-view .days-of-week {
            margin-bottom: 4px !important;
        }
        .datepicker-view .dow {
            color: var(--pz-mu) !important;
            font-size: 10.5px !important;
            font-weight: 600 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.04em !important;
            height: 28px !important;
            line-height: 28px !important;
        }
        .datepicker-cell {
            height: 34px !important;
            line-height: 34px !important;
            border-radius: 6px !important;
            color: var(--pz-text) !important;
            cursor: pointer !important;
            font-size: 13px !important;
            transition: background-color .12s, color .12s !important;
        }
        .datepicker-cell:not(.disabled):hover {
            background: var(--pz-soft) !important;
            color: var(--pz-bld) !important;
        }
        .datepicker-cell.disabled {
            color: var(--pz-fa) !important;
            cursor: not-allowed !important;
        }
        .datepicker-cell.prev:not(.selected),
        .datepicker-cell.next:not(.selected) {
            color: var(--pz-fa) !important;
        }
        .datepicker-cell.today:not(.selected):not(.range-start):not(.range-end) {
            color: var(--pz-bld) !important;
            font-weight: 600 !important;
            position: relative;
        }
        .datepicker-cell.today:not(.selected):not(.range-start):not(.range-end)::after {
            content: '';
            position: absolute;
            bottom: 4px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            background: var(--pz-bl);
            border-radius: 50%;
        }
        .datepicker-cell.selected,
        .datepicker-cell.selected:hover,
        .datepicker-cell.range-start,
        .datepicker-cell.range-end {
            background: var(--pz-bl) !important;
            color: #fff !important;
            font-weight: 500 !important;
        }
        .datepicker-cell.range {
            background: var(--pz-bls) !important;
            color: var(--pz-bld) !important;
            border-radius: 0 !important;
            font-weight: 500 !important;
        }
        .datepicker-cell.range:hover {
            background: var(--pz-blb) !important;
            color: var(--pz-bld) !important;
        }
        .datepicker-cell.range-start:not(.range-end) {
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
        }
        .datepicker-cell.range-end:not(.range-start) {
            border-top-left-radius: 0 !important;
            border-bottom-left-radius: 0 !important;
        }
        .datepicker-cell.focused:not(.selected) {
            background: var(--pz-soft) !important;
            color: var(--pz-bld) !important;
        }
        /* Vizualizare luni / ani / decenii — celulele sunt mai mari pentru click ușor */
        .datepicker .months .datepicker-cell,
        .datepicker .years .datepicker-cell,
        .datepicker .decades .datepicker-cell {
            height: 50px !important;
            line-height: 50px !important;
            font-weight: 500 !important;
            font-size: 13px !important;
        }
        .datepicker-footer { background: transparent !important; }
        .datepicker-controls.datepicker-footer {
            border-top: 1px solid var(--pz-lines) !important;
            padding-top: 8px !important;
            margin-top: 4px !important;
        }
        .datepicker-footer .button {
            color: var(--pz-bld) !important;
            font-weight: 500 !important;
        }
        @media (max-width: 480px) {
            .datepicker-picker { min-width: 240px; }
            .datepicker-cell { height: 32px !important; line-height: 32px !important; font-size: 12.5px !important; }
        }
        </style>
        <script defer src="https://cdn.jsdelivr.net/npm/vanillajs-datepicker@1.3.4/dist/js/datepicker-full.min.js"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/vanillajs-datepicker@1.3.4/dist/js/locales/ro.js"></script>
        <?php
    }
}

if (!function_exists('pz_date_range_init')) {
    /**
     * Emite inline <script> care atașează un DateRangePicker (vanillajs-datepicker)
     * pe două inputuri vizibile (type=text) și sincronizează două inputuri hidden cu
     * valori ISO yyyy-mm-dd pentru submit la backend.
     *
     * IMPORTANT: HTML-ul trebuie pregătit în prealabil așa:
     *   <input type="hidden" name="date_from" value="2026-05-01">
     *   <input type="hidden" name="date_to"   value="2026-05-31">
     *   <div class="pz-fb-date-range" id="myRange">
     *       <i class="ti ti-calendar"></i>
     *       <input type="text" id="myFrom" readonly value="01.05.2026">
     *       <span class="sep">—</span>
     *       <input type="text" id="myTo"   readonly value="31.05.2026">
     *   </div>
     *
     * Apoi în PHP:
     *   pz_date_range_init('myFrom', 'myTo', 'date_from', 'date_to', ['form_id' => 'filterForm']);
     *
     * Calendarul are vizibilitate clară pe an: click pe titlu „mai 2026" deschide
     * view-ul de luni; click pe an deschide view-ul de ani; click pe interval
     * deschide view-ul de decenii.
     *
     * $opts:
     *   'min_date' => 'YYYY-MM-DD'
     *   'max_date' => 'YYYY-MM-DD'
     *   'form_id'  => 'idFormular'  (auto-submit la schimbare ambelor date)
     */
    function pz_date_range_init(string $fromVisibleId, string $toVisibleId, string $fromHiddenName, string $toHiddenName, array $opts = []): void
    {
        pz_date_picker_assets();
        $minDate = isset($opts['min_date']) ? "'" . pz_ph_h($opts['min_date']) . "'" : 'null';
        $maxDate = isset($opts['max_date']) ? "'" . pz_ph_h($opts['max_date']) . "'" : 'null';
        $formId  = isset($opts['form_id'])  ? "'" . pz_ph_h($opts['form_id'])  . "'" : 'null';
        $fromVid = pz_ph_h($fromVisibleId);
        $toVid   = pz_ph_h($toVisibleId);
        $fromHN  = pz_ph_h($fromHiddenName);
        $toHN    = pz_ph_h($toHiddenName);
        ?>
        <script>
        (function() {
            function ready(fn) {
                if (document.readyState !== 'loading') return fn();
                document.addEventListener('DOMContentLoaded', fn);
            }
            function waitFor(checkFn, cb, tries) {
                tries = tries || 0;
                if (checkFn()) return cb();
                if (tries > 80) return; // ~4s
                setTimeout(function() { waitFor(checkFn, cb, tries + 1); }, 50);
            }
            ready(function() {
                waitFor(function() {
                    return typeof Datepicker !== 'undefined' && typeof DateRangePicker !== 'undefined';
                }, function() {
                    var fromInput   = document.getElementById('<?= $fromVid ?>');
                    var toInput     = document.getElementById('<?= $toVid ?>');
                    var fromHidden  = document.querySelector('input[type="hidden"][name="<?= $fromHN ?>"]');
                    var toHidden    = document.querySelector('input[type="hidden"][name="<?= $toHN ?>"]');
                    if (!fromInput || !toInput || !fromHidden || !toHidden) return;

                    function isoFromDate(d) {
                        if (!d) return '';
                        var y = d.getFullYear();
                        var m = String(d.getMonth() + 1).padStart(2, '0');
                        var day = String(d.getDate()).padStart(2, '0');
                        return y + '-' + m + '-' + day;
                    }

                    // Container DateRangePicker = cel mai apropiat ancestor comun existent.
                    function commonAncestor(a, b) {
                        var anc = a;
                        while (anc) {
                            if (anc.contains(b)) return anc;
                            anc = anc.parentNode;
                        }
                        return document.body;
                    }
                    var rangeRoot = commonAncestor(fromInput, toInput);

                    var minDate = <?= $minDate ?>;
                    var maxDate = <?= $maxDate ?>;
                    var hasRo = typeof Datepicker !== 'undefined' && Datepicker.locales && Datepicker.locales.ro;
                    var common = {
                        language: hasRo ? 'ro' : 'en',
                        format: 'dd.mm.yyyy',
                        weekStart: 1,
                        autohide: true,
                        todayHighlight: true,
                        maxView: 3,
                        showOnFocus: true,
                        showOnClick: true,
                        clearBtn: false,
                        todayBtn: true,
                        todayBtnMode: 1,
                        prevArrow: '‹',
                        nextArrow: '›'
                    };
                    if (minDate) common.minDate = minDate;
                    if (maxDate) common.maxDate = maxDate;

                    var rangeOpts = Object.assign({}, common, {
                        inputs: [fromInput, toInput],
                        allowOneSidedRange: true
                    });
                    var rangePicker = new DateRangePicker(rangeRoot, rangeOpts);

                    function syncHidden() {
                        var dates = rangePicker.getDates();
                        fromHidden.value = isoFromDate(dates[0]);
                        toHidden.value   = isoFromDate(dates[1]);
                    }
                    fromInput.addEventListener('changeDate', syncHidden);
                    toInput.addEventListener('changeDate', syncHidden);

                    // Auto-submit opțional la schimbare (debounced)
                    var formId = <?= $formId ?>;
                    if (formId) {
                        var form = document.getElementById(formId);
                        if (form) {
                            var debounce;
                            function maybeSubmit() {
                                clearTimeout(debounce);
                                debounce = setTimeout(function() {
                                    if (fromHidden.value && toHidden.value) form.submit();
                                }, 350);
                            }
                            fromInput.addEventListener('changeDate', maybeSubmit);
                            toInput.addEventListener('changeDate', maybeSubmit);
                        }
                    }
                });
            });
        })();
        </script>
        <?php
    }
}

if (!function_exists('pz_date_single_init')) {
    /**
     * Variantă single-date a date picker-ului PestZone — pentru cazuri în care
     * pagina lucrează cu o singură dată (ex. calendar pe zi/săptămână/lună).
     *
     * HTML necesar:
     *   <input type="hidden" name="date" value="2026-05-24">
     *   <input type="text" id="myDate" readonly value="24.05.2026" placeholder="zz.ll.aaaa">
     *
     * Apel:
     *   pz_date_single_init('myDate', 'date', ['form_id' => 'navForm']);
     *
     * Pe schimbare, sincronizează hidden + opțional auto-submit la formular.
     */
    function pz_date_single_init(string $visibleId, string $hiddenName, array $opts = []): void
    {
        pz_date_picker_assets();
        $minDate = isset($opts['min_date']) ? "'" . pz_ph_h($opts['min_date']) . "'" : 'null';
        $maxDate = isset($opts['max_date']) ? "'" . pz_ph_h($opts['max_date']) . "'" : 'null';
        $formId  = isset($opts['form_id'])  ? "'" . pz_ph_h($opts['form_id'])  . "'" : 'null';
        $vid     = pz_ph_h($visibleId);
        $hn      = pz_ph_h($hiddenName);
        ?>
        <script>
        (function() {
            function ready(fn) {
                if (document.readyState !== 'loading') return fn();
                document.addEventListener('DOMContentLoaded', fn);
            }
            function waitFor(checkFn, cb, tries) {
                tries = tries || 0;
                if (checkFn()) return cb();
                if (tries > 80) return;
                setTimeout(function() { waitFor(checkFn, cb, tries + 1); }, 50);
            }
            ready(function() {
                waitFor(function() { return typeof Datepicker !== 'undefined'; }, function() {
                    var input  = document.getElementById('<?= $vid ?>');
                    var hidden = document.querySelector('input[type="hidden"][name="<?= $hn ?>"]');
                    if (!input || !hidden) return;

                    function isoFromDate(d) {
                        if (!d) return '';
                        var y = d.getFullYear();
                        var m = String(d.getMonth() + 1).padStart(2, '0');
                        var day = String(d.getDate()).padStart(2, '0');
                        return y + '-' + m + '-' + day;
                    }

                    var hasRo = Datepicker.locales && Datepicker.locales.ro;
                    var opts = {
                        language: hasRo ? 'ro' : 'en',
                        format: 'dd.mm.yyyy',
                        weekStart: 1,
                        autohide: true,
                        todayHighlight: true,
                        maxView: 3,
                        showOnFocus: true,
                        showOnClick: true,
                        clearBtn: false,
                        todayBtn: true,
                        todayBtnMode: 1,
                        prevArrow: '‹',
                        nextArrow: '›'
                    };
                    var minDate = <?= $minDate ?>;
                    var maxDate = <?= $maxDate ?>;
                    if (minDate) opts.minDate = minDate;
                    if (maxDate) opts.maxDate = maxDate;

                    var dp = new Datepicker(input, opts);

                    input.addEventListener('changeDate', function() {
                        var d = dp.getDate();
                        hidden.value = isoFromDate(d);
                        var formId = <?= $formId ?>;
                        if (formId) {
                            var form = document.getElementById(formId);
                            if (form && hidden.value) form.submit();
                        }
                    });
                });
            });
        })();
        </script>
        <?php
    }
}

if (!function_exists('pz_page_header')) {
    /**
     * Render principal pentru header-ul de pagină.
     * Vezi documentația de mai sus pentru toate opțiunile.
     */
    function pz_page_header(array $opts): void
    {
        pz_page_header_css();
        // Asigură Tabler icons - dacă pagina nu l-a încărcat deja.
        static $tablerLoaded = false;
        if (!$tablerLoaded) {
            $tablerLoaded = true;
            echo '<link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css" rel="stylesheet">';
        }

        $kicker    = (string)($opts['kicker']    ?? '');
        $title     = (string)($opts['title']     ?? '');
        $subtitle  = (string)($opts['subtitle']  ?? '');
        $actions   = is_array($opts['actions']  ?? null) ? $opts['actions']  : [];
        $period    = is_array($opts['period']   ?? null) ? $opts['period']   : null;
        $tabs      = is_array($opts['tabs']     ?? null) ? $opts['tabs']     : [];
        $kpis      = is_array($opts['kpis']     ?? null) ? $opts['kpis']     : [];
        $toolbar   = (string)($opts['toolbar']   ?? '');  // HTML custom pentru filtre
        $meta      = is_array($opts['meta']     ?? null) ? $opts['meta']     : [];
        // Link „Înapoi" — pentru sub-pagini (zona Setări, drill-downs etc.)
        // Format: ['href' => 'settings.php', 'label' => 'Înapoi la setări']
        $back      = is_array($opts['back']     ?? null) ? $opts['back']     : null;

        // Există conținut explicit pentru subheader?
        $hasSubheader = !empty($kpis) || !empty($tabs) || ($toolbar !== '');
        // Dacă nu, dar avem meta sau nimic, putem afișa o bară meta default
        $showMeta = !$hasSubheader && !empty($meta);

        ?>
        <div class="pz-ph">
            <?php if ($back && !empty($back['href'])):
                $backLabel = (string)($back['label'] ?? 'Înapoi');
            ?>
                <a class="pz-ph-back" href="<?= pz_ph_h((string)$back['href']) ?>">
                    <i class="ti ti-arrow-left" aria-hidden="true"></i>
                    <?= pz_ph_h($backLabel) ?>
                </a>
            <?php endif; ?>
            <div class="pz-ph-top">
                <div class="pz-ph-main">
                    <?php if ($kicker !== ''): ?>
                        <p class="pz-ph-kicker"><?= pz_ph_h($kicker) ?></p>
                    <?php endif; ?>
                    <?php if ($title !== ''): ?>
                        <h2 class="pz-ph-title"><?= pz_ph_h($title) ?></h2>
                    <?php endif; ?>
                    <?php if ($subtitle !== ''): ?>
                        <p class="pz-ph-subtitle"><?= pz_ph_h($subtitle) ?></p>
                    <?php endif; ?>
                </div>
                <div class="pz-ph-actions">
                    <?php if ($period && !empty($period['options'])):
                        $current = (string)($period['current'] ?? '');
                        $param   = (string)($period['param']   ?? 'period');
                    ?>
                        <div class="pz-ph-period" role="group" aria-label="Perioadă">
                            <?php foreach ($period['options'] as $k => $lbl): ?>
                                <a href="<?= pz_ph_h(pz_ph_period_url($param, (string)$k)) ?>"
                                   class="<?= $current === (string)$k ? 'current' : '' ?>"><?= pz_ph_h($lbl) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($actions as $action):
                        $label   = (string)($action['label'] ?? '');
                        if ($label === '') continue;
                        $href    = (string)($action['href']  ?? '#');
                        $variant = (string)($action['variant'] ?? 'ghost');
                        $icon    = (string)($action['icon']  ?? '');
                        $onclick = (string)($action['onclick'] ?? '');
                        $target  = (string)($action['target'] ?? '');
                        $title_attr = (string)($action['title']  ?? '');
                        $actType = (string)($action['type'] ?? '');
                        $formAttr = (string)($action['form'] ?? '');
                        $isButton = $actType === 'button';
                        $isSubmit = $actType === 'submit';
                    ?>
                        <?php if ($isSubmit): ?>
                            <button type="submit"
                                    class="pz-ph-btn <?= pz_ph_h($variant) ?>"
                                    <?php if ($formAttr !== ''): ?>form="<?= pz_ph_h($formAttr) ?>"<?php endif; ?>
                                    <?php if ($onclick !== ''): ?>onclick="<?= pz_ph_h($onclick) ?>"<?php endif; ?>
                                    <?php if ($title_attr !== ''): ?>title="<?= pz_ph_h($title_attr) ?>"<?php endif; ?>>
                                <?php if ($icon !== ''): ?><i class="ti <?= pz_ph_h($icon) ?>" aria-hidden="true"></i><?php endif; ?>
                                <?= pz_ph_h($label) ?>
                            </button>
                        <?php elseif ($isButton): ?>
                            <button type="button"
                                    class="pz-ph-btn <?= pz_ph_h($variant) ?>"
                                    <?php if ($onclick !== ''): ?>onclick="<?= pz_ph_h($onclick) ?>"<?php endif; ?>
                                    <?php if ($title_attr !== ''): ?>title="<?= pz_ph_h($title_attr) ?>"<?php endif; ?>>
                                <?php if ($icon !== ''): ?><i class="ti <?= pz_ph_h($icon) ?>" aria-hidden="true"></i><?php endif; ?>
                                <?= pz_ph_h($label) ?>
                            </button>
                        <?php else: ?>
                            <a class="pz-ph-btn <?= pz_ph_h($variant) ?>"
                               href="<?= pz_ph_h($href) ?>"
                               <?php if ($target !== ''): ?>target="<?= pz_ph_h($target) ?>"<?php endif; ?>
                               <?php if ($onclick !== ''): ?>onclick="<?= pz_ph_h($onclick) ?>"<?php endif; ?>
                               <?php if ($title_attr !== ''): ?>title="<?= pz_ph_h($title_attr) ?>"<?php endif; ?>>
                                <?php if ($icon !== ''): ?><i class="ti <?= pz_ph_h($icon) ?>" aria-hidden="true"></i><?php endif; ?>
                                <?= pz_ph_h($label) ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($toolbar !== ''): ?>
                <div class="pz-ph-toolbar">
                    <?= $toolbar ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($kpis)): ?>
                <div class="pz-ph-kpis">
                    <?php foreach ($kpis as $kpi):
                        $kLabel    = (string)($kpi['label']    ?? '');
                        $kValue    = (string)($kpi['value']    ?? '');
                        $kMeta     = (string)($kpi['meta']     ?? '');
                        $kTone     = (string)($kpi['tone']     ?? '');
                        $kIcon     = (string)($kpi['icon']     ?? '');
                        $kSublabel = (string)($kpi['sublabel'] ?? '');
                    ?>
                        <div class="pz-ph-kpi <?= pz_ph_h($kTone) ?>">
                            <?php if ($kIcon !== ''): ?>
                                <div class="kpi-head">
                                    <span class="kpi-icon"><i class="ti <?= pz_ph_h($kIcon) ?>" aria-hidden="true"></i></span>
                                    <span class="label"><?= pz_ph_h($kLabel) ?></span>
                                </div>
                            <?php else: ?>
                                <p class="label"><?= pz_ph_h($kLabel) ?></p>
                            <?php endif; ?>
                            <p class="value">
                                <?= pz_ph_h($kValue) ?>
                                <?php if ($kSublabel !== ''): ?><span class="sublabel"><?= pz_ph_h($kSublabel) ?></span><?php endif; ?>
                                <?php if ($kMeta !== ''): ?><span class="meta"><?= pz_ph_h($kMeta) ?></span><?php endif; ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($showMeta): ?>
                <div class="pz-ph-meta">
                    <?php foreach ($meta as $m):
                        $mLabel = (string)($m['label'] ?? '');
                        $mValue = (string)($m['value'] ?? '');
                        $mIcon  = (string)($m['icon']  ?? '');
                    ?>
                        <span class="meta-item">
                            <?php if ($mIcon !== ''): ?><i class="ti <?= pz_ph_h($mIcon) ?>" aria-hidden="true"></i><?php endif; ?>
                            <?php if ($mLabel !== ''): ?><?= pz_ph_h($mLabel) ?>:&nbsp;<?php endif; ?>
                            <strong><?= pz_ph_h($mValue) ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($tabs)): ?>
                <nav class="pz-ph-tabs" aria-label="Tab-uri pagină">
                    <?php foreach ($tabs as $tab):
                        $tLabel  = (string)($tab['label'] ?? '');
                        if ($tLabel === '') continue;
                        $tHref   = (string)($tab['href']  ?? '#');
                        $tActive = !empty($tab['active']);
                    ?>
                        <a href="<?= pz_ph_h($tHref) ?>" class="<?= $tActive ? 'active' : '' ?>"><?= pz_ph_h($tLabel) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>
        <?php
        // Auto-scroll tab-ul activ în viewport — util pe mobile când tab-urile
        // depășesc lățimea containerului și activul ar fi tăiat la marginea
        // dreaptă (ex. "Arhivă doc..."). Idempotent: ruleaza o singura data per pagină.
        static $tabsScrollRendered = false;
        if (!empty($tabs) && !$tabsScrollRendered) {
            $tabsScrollRendered = true;
            ?>
            <script>
            (function() {
                function scrollActiveTabsIntoView() {
                    document.querySelectorAll('.pz-ph-tabs').forEach(function(navEl) {
                        var active = navEl.querySelector('a.active');
                        if (!active) return;
                        // Săltăm dacă tab-ul activ e deja vizibil integral
                        var navRect = navEl.getBoundingClientRect();
                        var aRect   = active.getBoundingClientRect();
                        var fullyVisible = (aRect.left >= navRect.left) && (aRect.right <= navRect.right);
                        if (fullyVisible) return;
                        // Centrăm tab-ul activ în viewport-ul scroll-abil
                        var target = active.offsetLeft - (navEl.clientWidth - active.offsetWidth) / 2;
                        navEl.scrollLeft = Math.max(0, target);
                    });
                }
                if (document.readyState !== 'loading') {
                    scrollActiveTabsIntoView();
                } else {
                    document.addEventListener('DOMContentLoaded', scrollActiveTabsIntoView);
                }
            })();
            </script>
            <?php
        }
    }
}
