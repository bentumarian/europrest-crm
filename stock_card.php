<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
if (!stock_table_exists($pdo, 'stock_products')) { header('Location: stock_install.php'); exit; }

$dateFrom = stock_date_or_default($_GET['date_from'] ?? '', date('Y-m-01'));
$dateTo = stock_date_or_default($_GET['date_to'] ?? '', date('Y-m-t'));
$productId = (int)($_GET['product_id'] ?? 0);
$group = trim((string)($_GET['group'] ?? ''));
if ($group !== '' && !array_key_exists($group, stock_group_options())) { $group = ''; }

$products = $pdo->query("SELECT id, name FROM stock_products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$summaryRows = stock_stock_summary_interval($pdo, $dateFrom, $dateTo, $productId, $group);
$groups = stock_group_options();

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Fisa magazie</title>
<style>
.stock-hero{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px 20px;box-shadow:var(--shadow);margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.stock-hero h1{margin:0 0 6px;font-size:24px;letter-spacing:-.03em}.stock-hero p{margin:0;color:var(--muted);font-size:14px}.stock-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px;box-shadow:var(--shadow);margin-bottom:16px}.stock-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.stock-grid-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.stock-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:900;margin-bottom:6px}.stock-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}.stock-table{width:100%;border-collapse:collapse;font-size:13px;min-width:920px}.stock-table th,.stock-table td{padding:10px 11px;border-bottom:1px solid var(--border2);text-align:left;vertical-align:top}.stock-table th{font-size:11px;text-transform:uppercase;color:var(--muted);background:#f8fafc;white-space:nowrap}.stock-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.stock-badge{display:inline-flex;align-items:center;min-height:24px;border-radius:999px;padding:0 9px;font-size:11px;font-weight:850;border:1px solid var(--border);background:#f8fafc;color:var(--text);white-space:nowrap}.stock-badge.green{background:var(--success-soft);color:var(--success);border-color:rgba(31,111,84,.22)}.stock-badge.red{background:var(--danger-soft);color:var(--danger);border-color:rgba(180,35,24,.22)}.stock-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.stock-tabs a{pointer-events:auto;cursor:pointer;text-decoration:none;position:relative;z-index:3;min-height:38px;border:1px solid var(--border);border-radius:13px;padding:0 12px;display:inline-flex;align-items:center;font-weight:850;background:#fff}.stock-tabs a.active{background:var(--accent);border-color:var(--accent);color:#fff}.stock-note{background:#fff;border:1px dashed var(--border);border-radius:14px;padding:12px 14px;color:var(--muted);font-size:13px;margin-bottom:14px}.stock-actions a,.stock-actions .btn,a.btn{pointer-events:auto!important;cursor:pointer!important;text-decoration:none!important;position:relative;z-index:3;}@media(max-width:900px){.stock-grid,.stock-grid-4{grid-template-columns:1fr}.stock-hero h1{font-size:20px}.stock-card{padding:14px}}
</style>
</head><body><div class="layout"><?php render_sidebar('stock', true); ?><main class="main"><div class="topbar"><div style="padding:0 20px;font-weight:900;">Gestiune - Fisa magazie</div></div><div class="content">
<div class="stock-hero"><div><h1>Fisa magazie</h1><p>Raport cantitativ pentru contabilitate: stoc initial + intrari - consum/iesiri = stoc final.</p></div></div>
<div class="stock-tabs">
    <a class="" href="stock.php">Dashboard</a>
    <a class="" href="stock_products.php">Produse</a>
    <a class="" href="stock_receipts.php">Intrari stoc</a>
    <a class="" href="stock_movements.php">Iesiri / miscari</a>
    <a class="" href="stock_notifications.php">Notificari</a>
    <a class="active" href="stock_card.php">Fisa magazie</a>
    <a class="" href="stock_work_registry.php">Registru evidenta lucrari</a>
</div>
<form class="stock-card" method="get">
    <h2 style="margin:0 0 14px;font-size:18px;">Filtre raport</h2>
    <div class="stock-grid-4">
        <div class="stock-field"><label>Data de la</label><input type="date" name="date_from" value="<?= stock_h($dateFrom) ?>"></div>
        <div class="stock-field"><label>Data pana la</label><input type="date" name="date_to" value="<?= stock_h($dateTo) ?>"></div>
        <div class="stock-field"><label>Grupa</label><select name="group"><option value="">Toate grupele</option><?php foreach($groups as $k=>$v): ?><option value="<?= stock_h($k) ?>" <?= $group===$k?'selected':'' ?>><?= stock_h($v) ?></option><?php endforeach; ?></select></div>
        <div class="stock-field"><label>Produs</label><select name="product_id"><option value="0">Toate produsele</option><?php foreach($products as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $productId===(int)$p['id']?'selected':'' ?>><?= stock_h($p['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="actions-row"><div></div><div class="stock-actions"><button class="btn accent" type="submit">Afiseaza</button><a class="btn" target="_blank" href="stock_card_export_pdf.php?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&group=<?= urlencode($group) ?>&product_id=<?= (int)$productId ?>">Export PDF</a></div></div>
</form>
<div class="stock-note">Raportul nu contine preturi. Consum/Iesiri include consum PV, pierderi, expirate si ajustari minus. Intrari include receptii, retururi si ajustari plus.</div>
<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Fisa magazie - <?= stock_h($dateFrom) ?> - <?= stock_h($dateTo) ?></h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Produs</th><th>Grupa</th><th>UM</th><th>Stoc initial</th><th>Intrari</th><th>Consum / iesiri</th><th>Stoc final</th><th>Status</th></tr></thead><tbody>
<?php foreach($summaryRows as $r): $low=(float)$r['min_qty']>0 && (float)$r['final_qty'] <= (float)$r['min_qty']; ?>
<tr><td><strong><?= stock_h($r['name']) ?></strong></td><td><?= stock_h(stock_group_label($r['product_group'])) ?></td><td><?= stock_h($r['unit_consumption']) ?></td><td><?= stock_h(stock_unit_display($r['initial_qty'], $r['unit_consumption'])) ?></td><td><?= stock_h(stock_unit_display($r['in_qty'], $r['unit_consumption'])) ?></td><td><?= stock_h(stock_unit_display($r['out_qty'], $r['unit_consumption'])) ?></td><td><strong><?= stock_h(stock_unit_display($r['final_qty'], $r['unit_consumption'])) ?></strong></td><td><?= $low ? '<span class="stock-badge red">Sub minim</span>' : '<span class="stock-badge green">OK</span>' ?></td></tr>
<?php endforeach; ?><?php if(!$summaryRows): ?><tr><td colspan="8">Nu exista produse pentru filtrele selectate.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div></main></div></body></html>
