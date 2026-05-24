<?php

/*
|--------------------------------------------------------------------------
| Dashboard mobile period selector
|--------------------------------------------------------------------------
| Activeaza pe dashboard un selector compact pentru perioada financiara pe
| ecrane mici, fara sa schimbe layout-ul desktop.
|--------------------------------------------------------------------------
*/

if (!function_exists('app_dashboard_mobile_period_assets')) {
    function app_dashboard_mobile_period_assets(): string
    {
        return <<<'HTML'
<style>
.pz-period-control {
    position: relative;
    display: inline-flex;
    align-items: center;
    max-width: 100%;
    min-width: 0;
}

.pz-period-mobile {
    display: none;
    position: relative;
}

.pz-period-mobile summary {
    list-style: none;
}

.pz-period-mobile summary::-webkit-details-marker {
    display: none;
}

@media (max-width: 640px) {
    .pz-head-actions {
        width: 100%;
        justify-content: flex-end;
    }

    .pz-period-control {
        width: auto;
        margin-left: auto;
    }

    .pz-period-control > .pz-period {
        display: none !important;
    }

    .pz-period-mobile {
        display: inline-block;
    }

    .pz-period-mobile summary {
        width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--pz-line);
        border-radius: var(--pz-r);
        background: var(--pz-surf);
        color: var(--pz-text);
        cursor: pointer;
        box-shadow: 0 8px 22px rgba(15, 23, 42, .05);
        transition: background .15s, border-color .15s, color .15s, box-shadow .15s;
        -webkit-tap-highlight-color: transparent;
    }

    .pz-period-mobile summary:hover,
    .pz-period-mobile[open] summary {
        background: var(--pz-soft);
        border-color: var(--pz-blb);
        color: var(--pz-bld);
        box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
    }

    .pz-period-mobile summary i {
        font-size: 20px;
        line-height: 1;
    }

    .pz-period-menu {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        width: min(245px, calc(100vw - 28px));
        display: grid;
        gap: 3px;
        padding: 6px;
        border: 1px solid var(--pz-line);
        border-radius: var(--pz-r);
        background: var(--pz-surf);
        box-shadow: 0 18px 42px rgba(15, 23, 42, .14);
        z-index: 80;
    }

    .pz-period-menu a {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 9px 10px;
        border-radius: 8px;
        color: var(--pz-mu);
        font-size: 12px;
        font-weight: 500;
        text-decoration: none;
        white-space: nowrap;
    }

    .pz-period-menu a:hover {
        background: var(--pz-soft);
        color: var(--pz-title);
    }

    .pz-period-menu a.current {
        background: var(--pz-bls);
        color: var(--pz-bld);
    }

    .pz-period-menu a i {
        font-size: 15px;
        line-height: 1;
    }

    /* KPI mobile: 4 carduri grupate pe 2 randuri */
    .pz-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px !important;
    }

    .pz-kpi {
        min-width: 0;
        padding: 10px 11px !important;
    }

    .pz-kpi .pz-kpi-head {
        align-items: flex-start;
        gap: 6px;
        margin-bottom: 5px;
    }

    .pz-kpi .pz-kpi-label {
        min-width: 0;
        font-size: 10.5px !important;
        line-height: 1.25;
    }

    .pz-kpi .pz-kpi-badge {
        flex-shrink: 0;
        font-size: 9.5px !important;
        padding: 1px 5px !important;
    }

    .pz-kpi .pz-kpi-value {
        font-size: 20px !important;
        line-height: 1.18;
    }

    .pz-kpi .pz-kpi-value .unit {
        font-size: 10.5px !important;
        margin-left: 2px;
    }

    .pz-kpi .pz-kpi-foot {
        font-size: 10px;
        line-height: 1.25;
    }
}

@media (max-width: 480px) {
    .pz-period-mobile summary {
        width: 40px;
        height: 40px;
    }

    .pz-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px !important;
    }

    .pz-kpi .pz-kpi-value {
        font-size: 19px !important;
    }
}

@media (max-width: 360px) {
    .pz-kpi-grid {
        gap: 7px !important;
    }

    .pz-kpi {
        padding: 9px 9px !important;
    }

    .pz-kpi .pz-kpi-label {
        font-size: 10px !important;
    }

    .pz-kpi .pz-kpi-value {
        font-size: 17px !important;
    }

    .pz-kpi .pz-kpi-value .unit,
    .pz-kpi .pz-kpi-foot {
        font-size: 9.5px !important;
    }
}
</style>
<script>
(function () {
    function buildDashboardPeriodMenu() {
        var headActions = document.querySelector('.pz-head-actions');
        var period = headActions ? headActions.querySelector('.pz-period') : null;
        if (!period || period.dataset.mobileMenuReady === '1') return;

        period.dataset.mobileMenuReady = '1';

        var control = document.createElement('div');
        control.className = 'pz-period-control';
        period.parentNode.insertBefore(control, period);
        control.appendChild(period);

        var mobile = document.createElement('details');
        mobile.className = 'pz-period-mobile';

        var summary = document.createElement('summary');
        summary.setAttribute('aria-label', 'Alege perioada financiara');
        summary.setAttribute('title', 'Alege perioada');
        summary.innerHTML = '<i class="ti ti-adjustments-horizontal" aria-hidden="true"></i><span class="sr-only">Alege perioada</span>';
        mobile.appendChild(summary);

        var menu = document.createElement('div');
        menu.className = 'pz-period-menu';
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', 'Alege perioada');

        period.querySelectorAll('a').forEach(function (link) {
            var item = link.cloneNode(true);
            item.setAttribute('role', 'menuitem');
            item.textContent = '';

            var label = document.createElement('span');
            label.textContent = (link.textContent || '').trim();
            item.appendChild(label);

            if (link.classList.contains('current')) {
                var check = document.createElement('i');
                check.className = 'ti ti-check';
                check.setAttribute('aria-hidden', 'true');
                item.appendChild(check);
            }

            menu.appendChild(item);
        });

        mobile.appendChild(menu);
        control.appendChild(mobile);

        document.addEventListener('click', function (event) {
            if (mobile.open && !mobile.contains(event.target)) {
                mobile.open = false;
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                mobile.open = false;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildDashboardPeriodMenu);
    } else {
        buildDashboardPeriodMenu();
    }
})();
</script>
HTML;
    }
}

if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'dashboard.php' && !defined('PZ_DASHBOARD_MOBILE_PERIOD_ASSETS')) {
    define('PZ_DASHBOARD_MOBILE_PERIOD_ASSETS', true);

    ob_start(static function (string $html): string {
        if (stripos($html, 'class="pz-period"') === false) {
            return $html;
        }

        $assets = app_dashboard_mobile_period_assets();
        if (stripos($html, '</head>') !== false) {
            return str_ireplace('</head>', $assets . "\n</head>", $html);
        }

        return $html . $assets;
    });
}
