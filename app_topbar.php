<?php

/*
|--------------------------------------------------------------------------
| app_topbar.php
|--------------------------------------------------------------------------
| app_topbar() - Header global, opt-in pe fiecare pagină.
| Apel: app_topbar('Dashboard', ['Astăzi']);
| Se pune imediat sub <main class="main"> în paginile care îl folosesc.
| Nu modifică nimic în paginile care nu îl apelează.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/app_helpers.php';
require_once __DIR__ . '/app_icons.php';
require_once __DIR__ . '/app_search_preview.php';

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
            border: 1px solid var(--border);
            border-radius: var(--shell-radius);
            position: fixed;
            top: var(--shell-gap);
            left: calc(var(--sidebar-width) + var(--shell-gap) * 2);
            right: var(--shell-gap);
            z-index: 50;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }
        /* Compensam pentru topbar-ul fixed (înălțime topbar + spațiu deasupra lui + gap între topbar și content) */
        body:has(.app-topbar) .main { padding-top: calc(var(--topbar-height) + var(--shell-gap) * 2); }
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
                /* Pe mobil rămâne lipit pe edge (fără floating gap) */
                top: 0;
                left: 0;
                right: 0;
                border-radius: 0;
                border: 0;
                border-bottom: 1px solid var(--border);
                box-shadow: none;
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

        /* ============================================================
           emma.ro Topbar — floating card
           Override final cu tokens --pz-* / --em-*
           TEST: background coral start
           ============================================================ */
        .app-topbar {
            background: var(--em-coral-start) !important;
            border: 1px solid var(--em-coral-start) !important;
            border-radius: var(--shell-radius) !important;
            box-shadow: 0 8px 24px -14px rgba(255, 90, 95, .45) !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        .app-topbar .tb-breadcrumb {
            color: var(--pz-mu) !important;
            font-size: 13px !important;
            font-weight: 400 !important;
        }
        .app-topbar .tb-breadcrumb .tb-current {
            color: var(--pz-title) !important;
            font-weight: 500 !important;
        }
        .app-topbar .tb-breadcrumb .tb-sep {
            color: var(--pz-fa) !important;
            opacity: 1 !important;
        }
        .app-topbar .tb-breadcrumb a {
            color: var(--pz-mu) !important;
        }
        .app-topbar .tb-breadcrumb a:hover {
            color: var(--pz-bl) !important;
        }

        .app-topbar .tb-search {
            background: var(--pz-bg) !important;
            border: 1px solid var(--pz-line) !important;
            border-radius: var(--pz-r) !important;
            box-shadow: none !important;
            transition: border-color .15s ease, background .15s ease !important;
            height: 34px !important;
        }
        .app-topbar .tb-search:hover {
            background: var(--pz-soft) !important;
            border-color: var(--pz-blb) !important;
        }
        .app-topbar .tb-search:focus-within {
            background: var(--pz-surf) !important;
            border-color: var(--pz-bl) !important;
            box-shadow: 0 0 0 3px var(--pz-bls) !important;
        }
        .app-topbar .tb-search .nav-icon,
        .app-topbar .tb-search > svg {
            color: var(--pz-fa) !important;
        }
        .app-topbar .tb-search input {
            color: var(--pz-title) !important;
            font-size: 13px !important;
        }
        .app-topbar .tb-search input::placeholder {
            color: var(--pz-fa) !important;
        }

        .app-topbar .tb-kbd {
            background: var(--pz-surf) !important;
            border: 1px solid var(--pz-line) !important;
            color: var(--pz-mu) !important;
            font-weight: 500 !important;
        }

        .app-topbar .tb-iconbtn,
        .app-topbar .tb-bell {
            background: var(--pz-surf) !important;
            border: 1px solid var(--pz-line) !important;
            color: var(--pz-text) !important;
            border-radius: var(--pz-r) !important;
            box-shadow: none !important;
            transition: background .15s ease, border-color .15s ease, color .15s ease !important;
        }
        .app-topbar .tb-iconbtn:hover,
        .app-topbar .tb-bell:hover {
            background: var(--pz-soft) !important;
            border-color: var(--pz-blb) !important;
            color: var(--pz-bld) !important;
        }
        .app-topbar .tb-iconbtn .nav-icon,
        .app-topbar .tb-iconbtn svg,
        .app-topbar .tb-bell .nav-icon,
        .app-topbar .tb-bell svg {
            color: inherit !important;
        }
        .app-topbar .tb-bell .tb-bell-dot {
            background: var(--pz-re) !important;
            border: 2px solid var(--pz-surf) !important;
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
                <form class="tb-search pz-search-wrap" action="clients.php" method="get" role="search" id="tbSearchForm">
                    <?= app_icon_svg('search') ?>
                    <input type="search" name="q" id="tbSearchInput" placeholder="Caută client" autocomplete="off" value="<?= app_h($_GET['q'] ?? '') ?>">
                    <span class="tb-kbd">Cmd K</span>
                    <div class="pz-search-preview"></div>
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
                            <a class="tb-dropdown-item tone-warning" role="menuitem" href="service-reports">
                                <span class="tdi-icon"><?= app_icon_svg('processes') ?></span>
                                <span>
                                    <span class="tdi-label">PV-uri neemise</span>
                                    <span class="tdi-sub">Lucrări finalizate fără PV</span>
                                </span>
                            </a>
                            <a class="tb-dropdown-item" role="menuitem" href="work_billing.php">
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
                            <a class="tb-dropdown-item" role="menuitem" href="clients.php?open_create=1">
                                <span class="tdi-icon"><?= app_icon_svg('clients') ?></span>
                                <span>
                                    <span class="tdi-label">Client</span>
                                    <span class="tdi-sub">Adaugă firmă sau persoană</span>
                                </span>
                            </a>
                            <a class="tb-dropdown-item tone-info" role="menuitem" href="contracts.php?new=1">
                                <span class="tdi-icon"><?= app_icon_svg('contracts') ?></span>
                                <span>
                                    <span class="tdi-label">Contract</span>
                                    <span class="tdi-sub">Emite contract nou</span>
                                </span>
                            </a>
                            <a class="tb-dropdown-item tone-success" role="menuitem" href="invoice.php">
                                <span class="tdi-icon"><?= app_icon_svg('invoice') ?></span>
                                <span>
                                    <span class="tdi-label">Factură</span>
                                    <span class="tdi-sub">Emite factură nouă</span>
                                </span>
                            </a>
                            <a class="tb-dropdown-item tone-success" role="menuitem" href="incasari.php">
                                <span class="tdi-icon"><?= app_icon_svg('invoice') ?></span>
                                <span>
                                    <span class="tdi-label">Încasare</span>
                                    <span class="tdi-sub">Înregistrează plată</span>
                                </span>
                            </a>
                            <a class="tb-dropdown-item tone-warning" role="menuitem" href="tasks.php?open_create=1">
                                <span class="tdi-icon"><?= app_icon_svg('tasks') ?></span>
                                <span>
                                    <span class="tdi-label">Sarcină</span>
                                    <span class="tdi-sub">Adaugă în backlog</span>
                                </span>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
        <?php
        if ($showSearch) {
            render_search_preview_assets();
            global $pdo;
            $previewClients = [];
            if (isset($pdo) && $pdo instanceof PDO) {
                try {
                    $stmtPrev = $pdo->query("SELECT id, name, fiscal_code FROM clients ORDER BY name ASC LIMIT 2000");
                    while ($cli = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
                        $nm = html_entity_decode((string)($cli['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $cf = html_entity_decode((string)($cli['fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $previewClients[] = [
                            'title'  => $nm,
                            'url'    => 'client.php?id=' . (int)$cli['id'],
                            'type'   => 'client',
                            'search' => $nm . ' ' . $cf,
                        ];
                    }
                } catch (Throwable $e) {
                    error_log('topbar search preview: ' . $e->getMessage());
                }
            }
            ?>
            <script>
            (function () {
                var go = function () {
                    if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
                    window.pzSearchPreview.attach('tbSearchInput',
                        <?= json_encode($previewClients, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                        { minChars: 1, maxResults: 8, emptyText: 'Niciun client. Apasă Enter pentru căutare extinsă.' }
                    );
                };
                go();
            })();
            </script>
            <?php
        }
        ?>
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

