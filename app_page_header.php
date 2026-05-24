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
            padding: 16px 20px;
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
        }
        .pz-ph-tabs a:hover { color: var(--pz-title); }
        .pz-ph-tabs a.active {
            color: var(--pz-bld);
            border-bottom-color: var(--pz-bl);
            font-weight: 500;
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
            gap: 6px;
            background: var(--pz-bg);
            border: 1px solid var(--pz-line);
            border-radius: 6px;
            padding: 0 8px;
            height: 32px;
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
            width: 100px !important;
            box-shadow: none !important;
            outline: none !important;
            font-weight: 500 !important;
        }
        .pz-fb-date-range input:focus {
            outline: none !important;
            box-shadow: none !important;
            border: 0 !important;
        }
        .pz-fb-date-range .sep { color: var(--pz-fa); font-size: 12px; user-select: none; }

        .pz-fb-search {
            position: relative;
            flex: 1;
            min-width: 160px;
            max-width: 280px;
        }
        .pz-fb-search i {
            position: absolute;
            left: 9px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            color: var(--pz-fa);
        }
        .pz-fb-search input {
            width: 100%;
            height: 32px;
            padding: 0 10px 0 30px;
            border: 1px solid var(--pz-line);
            border-radius: 6px;
            font-size: 12px;
            background: var(--pz-bg);
            color: var(--pz-title);
            font-family: inherit;
        }
        .pz-fb-search input:focus {
            outline: none;
            border-color: var(--pz-bl);
            box-shadow: 0 0 0 3px var(--pz-bls);
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
            .pz-fb-date-range { width: 100%; justify-content: space-between; }
            .pz-fb-date-range input[type="date"] { flex: 1; min-width: 0; width: auto; }
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

        /* KPI inline */
        .pz-ph-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 8px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid var(--pz-lines);
        }
        .pz-ph-kpi {
            background: var(--pz-soft);
            border-radius: 6px;
            padding: 9px 12px;
        }
        .pz-ph-kpi .label {
            font-size: 10.5px;
            color: var(--pz-mu);
            margin: 0;
            font-weight: 400;
        }
        .pz-ph-kpi .value {
            font-size: 18px;
            font-weight: 500;
            color: var(--pz-title);
            margin: 2px 0 0;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
        }
        .pz-ph-kpi .value .meta {
            font-size: 11px;
            color: var(--pz-fa);
            font-weight: 400;
            margin-left: 4px;
        }
        .pz-ph-kpi.success .value { color: var(--pz-gr); }
        .pz-ph-kpi.warning .value { color: var(--pz-or); }
        .pz-ph-kpi.danger  .value { color: var(--pz-re); }
        .pz-ph-kpi.info    .value { color: var(--pz-bld); }

        /* Responsive */
        @media (max-width: 768px) {
            .pz-ph { padding: 14px 16px; }
            .pz-ph-title { font-size: 18px; }
            .pz-ph-actions { width: 100%; justify-content: flex-start; }
            .pz-ph-period { width: 100%; }
            .pz-ph-period a { flex: 1; text-align: center; }
        }
        @media (max-width: 480px) {
            .pz-ph-btn { padding: 0 9px; font-size: 11px; height: 30px; }
            .pz-ph-btn i { font-size: 12px; }
        }
        </style>
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

        // Există conținut explicit pentru subheader?
        $hasSubheader = !empty($kpis) || !empty($tabs) || ($toolbar !== '');
        // Dacă nu, dar avem meta sau nimic, putem afișa o bară meta default
        $showMeta = !$hasSubheader && !empty($meta);

        ?>
        <div class="pz-ph">
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
                        $isButton = isset($action['type']) && $action['type'] === 'button';
                    ?>
                        <?php if ($isButton): ?>
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
                        $kLabel = (string)($kpi['label'] ?? '');
                        $kValue = (string)($kpi['value'] ?? '');
                        $kMeta  = (string)($kpi['meta']  ?? '');
                        $kTone  = (string)($kpi['tone']  ?? '');
                    ?>
                        <div class="pz-ph-kpi <?= pz_ph_h($kTone) ?>">
                            <p class="label"><?= pz_ph_h($kLabel) ?></p>
                            <p class="value"><?= pz_ph_h($kValue) ?><?php if ($kMeta !== ''): ?><span class="meta"><?= pz_ph_h($kMeta) ?></span><?php endif; ?></p>
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
    }
}
