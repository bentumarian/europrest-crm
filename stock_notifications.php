<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
if (!stock_table_exists($pdo, 'stock_products')) { header('Location: stock_install.php'); exit; }

$rows = stock_low_stock_rows($pdo);
app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Notificări gestiune</title>
</head><body><div class="layout"><?php render_sidebar('stock', true); ?><main class="main"><div class="topbar"><div style="padding:0 20px;font-weight:900;">Gestiune - Notificări</div></div><div class="content">
<div class="stock-hero"><div><h1>Notificări stoc minim</h1><p>Aici apar automat produsele care au ajuns la sau sub stocul minim setat în nomenclator.</p></div><div class="stock-actions"><a class="btn" href="stock_products.php">Setează stoc minim</a><a class="btn accent" href="stock_receipts.php">Adaugă intrare</a></div></div>
<?php render_stock_module_nav('notifications'); ?>
<?php if (!$rows): ?><div class="notice notice-success">Nu există alerte de stoc minim.</div><?php else: ?><div class="notice notice-danger">Există <?= count($rows) ?> produs(e) la sau sub stocul minim.</div><?php endif; ?>
<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Alerte active</h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Produs</th><th>Grupă</th><th>Stoc curent</th><th>Stoc minim</th><th>Diferență până la minim</th><th>Acțiuni</th></tr></thead><tbody>
<?php foreach ($rows as $r): $diff = (float)$r['min_qty'] - (float)$r['current_qty']; ?>
<tr class="stock-alert-row"><td><strong><?= stock_h($r['name']) ?></strong></td><td><?= stock_h(stock_group_label($r['product_group'])) ?></td><td><?= stock_h(stock_unit_display($r['current_qty'], $r['unit_consumption'])) ?></td><td><?= stock_h(stock_unit_display($r['min_qty'], $r['unit_consumption'])) ?></td><td><span class="stock-badge red"><?= stock_h(stock_unit_display($diff, $r['unit_consumption'])) ?></span></td><td><div class="stock-actions"><a class="btn" href="stock_receipts.php">Adaugă stoc</a><a class="btn" href="stock_products.php?edit=<?= (int)$r['id'] ?>">Editează minim</a></div></td></tr>
<?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="6">Nu există notificări.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div></main></div></body></html>
