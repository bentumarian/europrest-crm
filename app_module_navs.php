<?php

/*
|--------------------------------------------------------------------------
| app_module_navs.php
|--------------------------------------------------------------------------
| Bare de navigare secundare pentru modulele Facturare și Gestiune.
| Apel: render_billing_module_nav('facturi');
|       render_stock_module_nav('stock_products');
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/app_helpers.php';

if (!function_exists('render_billing_module_nav')) {
    function render_billing_module_nav(string $active = ''): void
    {
        $items = [
            'facturi' => ['label' => 'Facturi', 'href' => 'invoices.php'],
            'incasari' => ['label' => 'Încasări', 'href' => 'payments.php'],
            'interventii_facturare' => ['label' => 'Lista lucrări', 'href' => 'work_billing.php'],
        ];
        ?>
        <nav class="pz-subtabs billing-module-nav" aria-label="Navigare financiar">
            <?php foreach ($items as $key => $item): ?>
                <a class="pz-subtab <?= $active === $key ? 'active' : '' ?>" href="<?= app_h($item['href']) ?>"><?= app_h($item['label']) ?></a>
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
            'movements' => ['label' => 'Mișcări', 'href' => 'stock_movements.php'],
            'inventory' => ['label' => 'Inventar', 'href' => 'stock_inventory.php'],
            'deferred_pvs' => ['label' => 'PV fără consum', 'href' => 'stock_deferred_pvs.php'],
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


if (!function_exists('render_stock_page_header')) {
    /**
     * Header unificat pentru sub-paginile Gestiune.
     * Folosește pz_page_header() cu kicker GESTIUNE + tabs comuni.
     * Înlocuiește combinația .stock-hero + render_stock_module_nav().
     */
    function render_stock_page_header(string $activeKey, string $title, string $subtitle = '', array $actions = []): void
    {
        $items = [
            'dashboard'     => ['label' => 'Dashboard',         'href' => 'stock.php'],
            'products'      => ['label' => 'Produse',           'href' => 'stock_products.php'],
            'receipts'      => ['label' => 'Intrări',           'href' => 'stock_receipts.php'],
            'movements'     => ['label' => 'Mișcări',           'href' => 'stock_movements.php'],
            'inventory'     => ['label' => 'Inventar fizic',    'href' => 'stock_inventory.php'],
            'deferred_pvs'  => ['label' => 'PV fără consum',    'href' => 'stock_deferred_pvs.php'],
            'notifications' => ['label' => 'Notificări',        'href' => 'stock_notifications.php'],
            'card'          => ['label' => 'Fișa magazie',      'href' => 'stock_card.php'],
            'registry'      => ['label' => 'Registru lucrări',  'href' => 'stock_work_registry.php'],
        ];

        $tabs = [];
        foreach ($items as $key => $item) {
            $tabs[] = [
                'label'  => $item['label'],
                'href'   => $item['href'],
                'active' => ($key === $activeKey),
            ];
        }

        pz_page_header([
            'kicker'   => 'GESTIUNE',
            'title'    => $title,
            'subtitle' => $subtitle,
            'tabs'     => $tabs,
            'actions'  => $actions,
        ]);
    }
}
