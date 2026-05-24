<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';
require_once 'settings_lib.php';
if (!is_office_or_admin()) { header('Location: calendar.php'); exit; }
stock_ensure_schema($pdo);

$dateFrom = stock_date_or_default($_GET['date_from'] ?? '', date('Y-m-01'));
$dateTo = stock_date_or_default($_GET['date_to'] ?? '', date('Y-m-t'));
$productId = (int)($_GET['product_id'] ?? 0);
$group = trim((string)($_GET['group'] ?? ''));
if ($group !== '' && !array_key_exists($group, stock_group_options())) { $group = ''; }

$products = $pdo->query("SELECT id, name FROM stock_products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$summaryRows = stock_stock_summary_interval($pdo, $dateFrom, $dateTo, $productId, $group);
$groups = stock_group_options();

$company = function_exists('pz_company_settings') ? pz_company_settings($pdo) : [];
$companyName = trim((string)($company['company.display_name'] ?? '')) ?: trim((string)($company['company.legal_name'] ?? 'Compania'));
$companyCui = trim((string)($company['company.cui'] ?? ''));
$companyAddress = trim((string)($company['company.address'] ?? ''));

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Fișa magazie</title>
</head><body><div class="layout"><?php render_sidebar('stock_card', true); ?><main class="main"><div class="content">
<div class="stock-hero"><div><h1>Fișa magazie</h1><p>Raport cantitativ pentru contabilitate: stoc inițial + intrări - consum/ieșiri = stoc final.</p></div></div>
<?php render_stock_module_nav('card'); ?>
<form class="stock-card" method="get">
    <h2 style="margin:0 0 14px;font-size:18px;">Filtre raport</h2>
    <div class="stock-grid-4">
        <div class="stock-field"><label>Data de la</label><input type="date" name="date_from" value="<?= stock_h($dateFrom) ?>"></div>
        <div class="stock-field"><label>Data până la</label><input type="date" name="date_to" value="<?= stock_h($dateTo) ?>"></div>
        <div class="stock-field"><label>Grupa</label><select name="group"><option value="">Toate grupele</option><?php foreach($groups as $k=>$v): ?><option value="<?= stock_h($k) ?>" <?= $group===$k?'selected':'' ?>><?= stock_h($v) ?></option><?php endforeach; ?></select></div>
        <div class="stock-field"><label>Produs</label><select name="product_id"><option value="0">Toate produsele</option><?php foreach($products as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $productId===(int)$p['id']?'selected':'' ?>><?= stock_h($p['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="actions-row"><div></div><div class="stock-actions"><button class="btn accent" type="submit">Afișează</button><a class="btn" target="_blank" href="stock_card_export_pdf.php?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&group=<?= urlencode($group) ?>&product_id=<?= (int)$productId ?>">Export PDF</a><a class="btn" href="stock_export.php?type=card&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&group=<?= urlencode($group) ?>&product_id=<?= (int)$productId ?>">Export Excel</a></div></div>
</form>
<div class="stock-note">Raportul nu conține prețuri. Consum/Ieșiri include consum PV, pierderi, expirate și ajustări minus. Intrări include recepții, retururi și ajustări plus.</div>
<div class="stock-card">
    <div style="margin:0 0 6px;font-size:14px;font-weight:800;color:var(--text);"><?= stock_h($companyName) ?></div>
    <?php if ($companyCui !== '' || $companyAddress !== ''): ?>
    <div style="margin:0 0 12px;font-size:11.5px;color:var(--muted);">
        <?= $companyCui !== '' ? 'CUI ' . stock_h($companyCui) : '' ?>
        <?= ($companyCui !== '' && $companyAddress !== '') ? ' · ' : '' ?>
        <?= $companyAddress !== '' ? stock_h($companyAddress) : '' ?>
    </div>
    <?php endif; ?>
    <h2 style="margin:0 0 14px;font-size:18px;">Fișa magazie - <?= stock_h($dateFrom) ?> - <?= stock_h($dateTo) ?></h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Produs</th><th>Grupă</th><th>UM</th><th>Stoc inițial</th><th>Intrări</th><th>Consum / ieșiri</th><th>Stoc final</th><th>Status</th></tr></thead><tbody>
<?php foreach($summaryRows as $r): $low=(float)$r['min_qty']>0 && (float)$r['final_qty'] <= (float)$r['min_qty']; ?>
<tr><td><strong><?= stock_h($r['name']) ?></strong></td><td><?= stock_h(stock_group_label($r['product_group'])) ?></td><td><?= stock_h($r['unit_consumption']) ?></td><td><?= stock_h(stock_unit_display($r['initial_qty'], $r['unit_consumption'])) ?></td><td><?= stock_h(stock_unit_display($r['in_qty'], $r['unit_consumption'])) ?></td><td><?= stock_h(stock_unit_display($r['out_qty'], $r['unit_consumption'])) ?></td><td><strong><?= stock_h(stock_unit_display($r['final_qty'], $r['unit_consumption'])) ?></strong></td><td><?= $low ? '<span class="stock-badge red">Sub minim</span>' : '<span class="stock-badge green">OK</span>' ?></td></tr>
<?php endforeach; ?><?php if(!$summaryRows): ?><tr><td colspan="8">Nu există produse pentru filtrele selectate.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div></main></div></body></html>
