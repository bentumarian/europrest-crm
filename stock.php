<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
if (!stock_table_exists($pdo, 'stock_products')) { header('Location: stock_install.php'); exit; }

$rows = stock_current_by_product($pdo);
$totalProducts = count($rows);
$lowRows = stock_low_stock_rows($pdo);
$lowStock = count($lowRows);
$totalQty = 0;
foreach ($rows as $r) { $totalQty += (float)($r['current_qty'] ?? 0); }
$movementsCount = stock_table_exists($pdo, 'stock_movements') ? (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn() : 0;

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Gestiune stocuri</title>
<style>
.stock-hero{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px 20px;box-shadow:var(--shadow);margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.stock-hero h1{margin:0 0 6px;font-size:24px;letter-spacing:-.03em}.stock-hero p{margin:0;color:var(--muted);font-size:14px}.stock-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px;box-shadow:var(--shadow);margin-bottom:16px}.stock-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.stock-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.stock-grid-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.stock-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:900;margin-bottom:6px}.stock-field small{display:block;color:var(--muted);font-size:12px;margin-top:5px}.stock-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}.stock-table{width:100%;border-collapse:collapse;font-size:13px;min-width:860px}.stock-table th,.stock-table td{padding:10px 11px;border-bottom:1px solid var(--border2);text-align:left;vertical-align:top}.stock-table th{font-size:11px;text-transform:uppercase;color:var(--muted);background:#f8fafc;white-space:nowrap}.stock-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.stock-badge{display:inline-flex;align-items:center;min-height:24px;border-radius:999px;padding:0 9px;font-size:11px;font-weight:850;border:1px solid var(--border);background:#f8fafc;color:var(--text);white-space:nowrap}.stock-badge.green{background:var(--success-soft);color:var(--success);border-color:rgba(31,111,84,.22)}.stock-badge.red{background:var(--danger-soft);color:var(--danger);border-color:rgba(180,35,24,.22)}.stock-badge.yellow{background:var(--warning-soft);color:var(--warning);border-color:rgba(154,103,0,.22)}.stock-badge.blue{background:var(--accent-soft);color:var(--accent);border-color:rgba(0,113,163,.22)}.stock-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.stock-tabs a{pointer-events:auto;cursor:pointer;text-decoration:none;position:relative;z-index:3;min-height:38px;border:1px solid var(--border);border-radius:13px;padding:0 12px;display:inline-flex;align-items:center;font-weight:850;background:#fff}.stock-tabs a.active{background:var(--accent);border-color:var(--accent);color:#fff}.stock-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}.stock-kpi{background:#fff;border:1px solid var(--border);border-radius:18px;padding:16px;box-shadow:var(--shadow);display:block;text-decoration:none;color:inherit}.stock-kpi .label{font-size:12px;color:var(--muted);font-weight:850;text-transform:uppercase;letter-spacing:.04em}.stock-kpi .value{font-size:26px;font-weight:950;margin-top:6px}.js-biocide-only.is-hidden{display:none!important}.stock-alert-row{background:#fffafa}.stock-note{background:#fff;border:1px dashed var(--border);border-radius:14px;padding:12px 14px;color:var(--muted);font-size:13px;margin-bottom:14px}.stock-actions a,.stock-actions .btn,a.btn{pointer-events:auto!important;cursor:pointer!important;text-decoration:none!important;position:relative;z-index:3;}@media(max-width:900px){.stock-grid,.stock-grid-3,.stock-grid-4,.stock-kpis{grid-template-columns:1fr}.stock-hero h1{font-size:20px}.stock-table{min-width:760px}.stock-card{padding:14px}}
</style>
</head><body><div class="layout"><?php render_sidebar('stock', true); ?><main class="main"><div class="topbar"><div style="padding:0 20px;font-weight:900;">Gestiune</div></div><div class="content">
<div class="stock-hero"><div><h1>Gestiune stocuri DDD</h1><p>Nomenclator produse, intrari, iesiri si notificari de stoc minim.</p></div><div class="stock-actions"><a class="btn" href="stock_install.php">Verifica tabele</a><a class="btn accent" href="stock_products.php">Produs nou</a><a class="btn" href="stock_receipts.php">Intrare stoc</a><a class="btn" href="stock_movements.php">Scoate din stoc</a></div></div>
<div class="stock-tabs">
    <a class="active" href="stock.php">Dashboard</a>
    <a class="" href="stock_products.php">Produse</a>
    <a class="" href="stock_receipts.php">Intrari stoc</a>
    <a class="" href="stock_movements.php">Iesiri / miscari</a>
    <a class="" href="stock_notifications.php">Notificari</a>
    <a class="" href="stock_card.php">Fisa magazie</a>
    <a class="" href="stock_work_registry.php">Registru evidenta lucrari</a>
</div>
<div class="stock-kpis"><div class="stock-kpi"><div class="label">Produse</div><div class="value"><?= (int)$totalProducts ?></div></div><a class="stock-kpi" href="stock_notifications.php"><div class="label">Notificari stoc minim</div><div class="value"><?= (int)$lowStock ?></div></a><div class="stock-kpi"><div class="label">Miscari stoc</div><div class="value"><?= (int)$movementsCount ?></div></div><div class="stock-kpi"><div class="label">Unitati totale</div><div class="value"><?= stock_h(stock_fmt_qty($totalQty)) ?></div></div></div>
<?php if ($lowStock > 0): ?><div class="notice notice-danger"><strong>Atentie:</strong> exista <?= (int)$lowStock ?> produs(e) la sau sub stocul minim. <a href="stock_notifications.php" style="font-weight:900;text-decoration:underline;">Vezi notificari</a></div><?php endif; ?>
<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Stoc curent pe produs</h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Produs</th><th>Grupa</th><th>Unitate</th><th>Stoc curent</th><th>Stoc minim</th><th>Status</th><th>Actiuni</th></tr></thead><tbody>
<?php foreach ($rows as $r): $isLow = (float)$r['min_qty'] > 0 && (float)$r['current_qty'] <= (float)$r['min_qty']; ?>
<tr class="<?= $isLow ? 'stock-alert-row' : '' ?>"><td><strong><?= stock_h($r['name']) ?></strong></td><td><?= stock_h(stock_group_label($r['product_group'])) ?></td><td><?= stock_h($r['unit_consumption']) ?></td><td><?= stock_h(stock_unit_display($r['current_qty'], $r['unit_consumption'])) ?></td><td><?= stock_h(stock_unit_display($r['min_qty'], $r['unit_consumption'])) ?></td><td><?= $isLow ? '<span class="stock-badge red">Sub minim</span>' : '<span class="stock-badge green">OK</span>' ?></td><td><a class="btn" href="stock_card.php?product_id=<?= (int)$r['id'] ?>">Fisa</a></td></tr>
<?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="7">Nu exista produse. Adauga primul produs in nomenclator.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div></main></div></body></html>
