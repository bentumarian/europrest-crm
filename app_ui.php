<?php

/*
|--------------------------------------------------------------------------
| UI global - aggregator
|--------------------------------------------------------------------------
| Conține doar require-uri către modulele de UI. Componenta efectivă se
| găsește în fișierele dedicate (un fișier per responsabilitate).
|
|   app_helpers.php                 - escape HTML, helpers de bază
|   app_icons.php                   - app_icon_svg()
|   app_module_navs.php             - render_billing_module_nav() + render_stock_module_nav()
|   app_theme_css.php               - app_theme_css() (CSS global, include și fostul identity)
|   app_dashboard_mobile_period.php - selector compact perioadă dashboard pe mobil
|   app_brand.php                   - app_brand_logo() + render_mobile_app_header()
|   app_search_preview.php          - render_search_preview_assets()
|   app_topbar.php                  - app_topbar()
|   app_sidebar.php                 - render_sidebar() + JS aferent
|
| Notă: app_toast() / app_toast_container() au fost eliminate (cod mort).
|       app_professional_identity_css() a fost fuzionat în app_theme_css();
|       există un stub no-op pentru backward compatibility.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/app_helpers.php';
require_once __DIR__ . '/app_icons.php';
require_once __DIR__ . '/app_module_navs.php';
require_once __DIR__ . '/app_theme_css.php';
require_once __DIR__ . '/app_dashboard_mobile_period.php';
require_once __DIR__ . '/app_brand.php';
require_once __DIR__ . '/app_search_preview.php';
require_once __DIR__ . '/app_topbar.php';
require_once __DIR__ . '/app_sidebar.php';