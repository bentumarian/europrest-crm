<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
stock_ensure_schema($pdo);

$rows = stock_current_by_product($pdo);
$totalProducts = count($rows);
$lowRows = stock_low_stock_rows($pdo);
$lowStock = count($lowRows);
$totalQty = 0;
foreach ($rows as $r) { $totalQty += (float)($r['current_qty'] ?? 0); }
$movementsCount = stock_table_exists($pdo, 'stock_movements') ? (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn() : 0;
$expiringSoonCount = stock_count_expiring_soon($pdo, 30);
$expiredCount = stock_count_already_expired($pdo);
$openInventoryCount = 0;
$openInventoryId = 0;
if (stock_table_exists($pdo, 'stock_inventories')) {
    try {
        $stmtInv = $pdo->prepare("SELECT id FROM stock_inventories WHERE status = 'draft' ORDER BY id DESC LIMIT 1");
        $stmtInv->execute();
        $openRow = $stmtInv->fetch(PDO::FETCH_ASSOC);
        if ($openRow) {
            $openInventoryId = (int)$openRow['id'];
            $openInventoryCount = 1;
        }
    } catch (Throwable $e) { /* tolerăm lipsa tabelului */ }
}
$deferredPvsCount = count(stock_deferred_pvs_list($pdo));
$totalAlerts = $lowStock + $expiringSoonCount + $expiredCount;

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Gestiune stocuri</title>
<style>
.stock-kpi.alert { background: #fef2f2; border-color: #fecaca; }
.stock-kpi.warn { background: #fffbeb; border-color: #fde68a; }
.stock-kpi.alert .value { color: #b42318; }
.stock-kpi.warn .value { color: #b45309; }
</style>
</head><body><div class="layout"><?php render_sidebar('stock', true); ?><main class="main"><div class="content">
<div class="stock-hero"><div><h1>Gestiune stocuri DDD</h1><p>Nomenclator produse, intrări, ieșiri, inventar fizic, alerte de expirare și stoc minim.</p></div><div class="stock-actions"><a class="btn accent" href="stock_products.php">Produs nou</a><a class="btn" href="stock_receipts.php">Intrare stoc</a><a class="btn" href="stock_movements.php">Mișcare manuală</a><a class="btn" href="stock_inventory.php">Inventar fizic</a><a class="btn" href="stock_export.php?type=stock_current">Export Excel</a></div></div>
<?php render_stock_module_nav('dashboard'); ?>

<div class="stock-kpis">
    <div class="stock-kpi">
        <div class="label">Produse</div>
        <div class="value"><?= (int)$totalProducts ?></div>
    </div>
    <a class="stock-kpi <?= $lowStock > 0 ? 'alert' : '' ?>" href="stock_notifications.php">
        <div class="label">Sub stoc minim</div>
        <div class="value"><?= (int)$lowStock ?></div>
    </a>
    <a class="stock-kpi <?= $expiringSoonCount > 0 ? 'warn' : '' ?>" href="stock_notifications.php">
        <div class="label">Expiră în 30 zile</div>
        <div class="value"><?= (int)$expiringSoonCount ?></div>
    </a>
    <a class="stock-kpi <?= $expiredCount > 0 ? 'alert' : '' ?>" href="stock_notifications.php">
        <div class="label">Expirate cu stoc</div>
        <div class="value"><?= (int)$expiredCount ?></div>
    </a>
    <a class="stock-kpi <?= $deferredPvsCount > 0 ? 'warn' : '' ?>" href="stock_deferred_pvs.php">
        <div class="label">PV fără consum</div>
        <div class="value"><?= (int)$deferredPvsCount ?></div>
    </a>
    <div class="stock-kpi">
        <div class="label">Mișcări totale</div>
        <div class="value"><?= (int)$movementsCount ?></div>
    </div>
    <div class="stock-kpi">
        <div class="label">Unități totale</div>
        <div class="value"><?= stock_h(stock_fmt_qty($totalQty)) ?></div>
    </div>
</div>

<?php if ($openInventoryId > 0): ?>
    <div class="notice notice-warning" style="background:#fffbeb;border-color:#fde68a;color:#92400e;">
        <strong>Inventar în desfășurare:</strong> există un inventar (#<?= (int)$openInventoryId ?>) neînchis.
        <a href="stock_inventory.php?id=<?= (int)$openInventoryId ?>" style="font-weight:900;text-decoration:underline;">Continuă numărătoarea</a>
    </div>
<?php endif; ?>

<?php if ($deferredPvsCount > 0): ?>
    <div class="notice notice-warning" style="background:#fffbeb;border-color:#fde68a;color:#92400e;">
        <strong>PV-uri emise în alb:</strong> <?= (int)$deferredPvsCount ?> PV(uri) așteaptă completarea cantităților consumate.
        <a href="stock_deferred_pvs.php" style="font-weight:900;text-decoration:underline;">Finalizează consumul</a>
    </div>
<?php endif; ?>

<?php if ($totalAlerts > 0): ?>
    <div class="notice notice-danger">
        <strong>Atenție:</strong> <?= (int)$totalAlerts ?> alerte active —
        <?php
            $bits = [];
            if ($lowStock > 0) $bits[] = $lowStock . ' sub stoc minim';
            if ($expiringSoonCount > 0) $bits[] = $expiringSoonCount . ' expirare apropiată';
            if ($expiredCount > 0) $bits[] = $expiredCount . ' deja expirate cu stoc';
            echo stock_h(implode(', ', $bits));
        ?>.
        <a href="stock_notifications.php" style="font-weight:900;text-decoration:underline;">Vezi notificările</a>
    </div>
<?php endif; ?>

<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Stoc curent pe produs</h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Produs</th><th>Grupa</th><th>Unitate</th><th>Stoc curent</th><th>Stoc minim</th><th>Status</th><th>Acțiuni</th></tr></thead><tbody>
<?php foreach ($rows as $r): $isLow = (float)$r['min_qty'] > 0 && (float)$r['current_qty'] <= (float)$r['min_qty']; ?>
<tr class="<?= $isLow ? 'stock-alert-row' : '' ?>"><td><strong><?= stock_h($r['name']) ?></strong></td><td><?= stock_h(stock_group_label($r['product_group'])) ?></td><td><?= stock_h($r['unit_consumption']) ?></td><td><?= stock_h(stock_unit_display($r['current_qty'], $r['unit_consumption'])) ?></td><td><?= stock_h(stock_unit_display($r['min_qty'], $r['unit_consumption'])) ?></td><td><?= $isLow ? '<span class="stock-badge red">Sub minim</span>' : '<span class="stock-badge green">OK</span>' ?></td><td><a class="btn" href="stock_card.php?product_id=<?= (int)$r['id'] ?>">Fișa</a></td></tr>
<?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="7">Nu există produse. Adaugă primul produs în nomenclator.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div></main></div></body></html>
