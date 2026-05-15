<?php
require_once 'config.php';
require_login();
require_once 'stock_lib.php';
if (!is_admin()) { http_response_code(403); exit('Acces interzis'); }
if (!stock_table_exists($pdo, 'stock_products')) { exit('Modulul Gestiune nu este instalat.'); }
$dateFrom = stock_date_or_default($_GET['date_from'] ?? '', date('Y-m-01'));
$dateTo = stock_date_or_default($_GET['date_to'] ?? '', date('Y-m-t'));
$productId = (int)($_GET['product_id'] ?? 0);
$group = trim((string)($_GET['group'] ?? ''));
if ($group !== '' && !array_key_exists($group, stock_group_options())) { $group = ''; }
$rows = stock_stock_summary_interval($pdo, $dateFrom, $dateTo, $productId, $group);
$generatedAt = date('d.m.Y H:i');
ob_start();
?>
<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, Arial, sans-serif;font-size:10px;color:#111}h1{font-size:18px;margin:0 0 4px}.meta{font-size:10px;color:#444;margin-bottom:10px}.small{font-size:9px;color:#555}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d5dce5;padding:5px 6px;vertical-align:top}th{background:#eef2f7;text-transform:uppercase;font-size:8.5px;text-align:left}td.num{text-align:right;white-space:nowrap}.footer{margin-top:10px;font-size:9px;color:#555}.ok{color:#166534;font-weight:bold}.low{color:#b42318;font-weight:bold}
</style></head><body>
<h1>Fisa magazie - raport cantitativ</h1>
<div class="meta">Interval: <strong><?= stock_h(date('d.m.Y', strtotime($dateFrom))) ?></strong> - <strong><?= stock_h(date('d.m.Y', strtotime($dateTo))) ?></strong> | Generat: <?= stock_h($generatedAt) ?></div>
<table><thead><tr><th>Produs</th><th>Grupa</th><th>UM</th><th>Stoc initial</th><th>Intrari</th><th>Consum / iesiri</th><th>Stoc final</th><th>Status</th></tr></thead><tbody>
<?php foreach($rows as $r): $low=(float)$r['min_qty']>0 && (float)$r['final_qty'] <= (float)$r['min_qty']; ?>
<tr><td><?= stock_h($r['name']) ?></td><td><?= stock_h(stock_group_label($r['product_group'])) ?></td><td><?= stock_h($r['unit_consumption']) ?></td><td class="num"><?= stock_h(stock_unit_display($r['initial_qty'], $r['unit_consumption'])) ?></td><td class="num"><?= stock_h(stock_unit_display($r['in_qty'], $r['unit_consumption'])) ?></td><td class="num"><?= stock_h(stock_unit_display($r['out_qty'], $r['unit_consumption'])) ?></td><td class="num"><strong><?= stock_h(stock_unit_display($r['final_qty'], $r['unit_consumption'])) ?></strong></td><td class="<?= $low ? 'low' : 'ok' ?>"><?= $low ? 'Sub minim' : 'OK' ?></td></tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="8">Nu exista produse pentru filtrele selectate.</td></tr><?php endif; ?>
</tbody></table>
<div class="footer">Formula: Stoc final = Stoc initial + Intrari - Consum/Iesiri. Raport fara valori/preturi.</div>
</body></html>
<?php
$html = ob_get_clean();
stock_render_pdf_or_html($html, 'fisa_magazie_' . $dateFrom . '_' . $dateTo . '.pdf');
