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
        <style>
        .billing-module-nav{grid-column:1/-1;display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 14px}
        .billing-module-nav a{min-height:34px;padding:7px 11px;border-radius:4px;border:1px solid var(--border);background:var(--surface);color:var(--muted);font-size:12.5px;font-weight:700;box-shadow:none;text-decoration:none}
        .billing-module-nav a:hover{color:var(--text);border-color:var(--accent-pale)}
        .billing-module-nav a.active{background:var(--accent);border-color:var(--accent);color:#fff;box-shadow:none}
        @media(max-width:720px){.billing-module-nav{display:grid;grid-template-columns:1fr 1fr}.billing-module-nav a{text-align:center}}
        </style>
        <nav class="billing-module-nav" aria-label="Navigare financiar">
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

