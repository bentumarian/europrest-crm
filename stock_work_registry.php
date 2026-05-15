<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
if (!stock_table_exists($pdo, 'stock_movements')) { header('Location: stock_install.php'); exit; }

$dateFrom = stock_date_or_default($_GET['date_from'] ?? '', date('Y-m-01'));
$dateTo = stock_date_or_default($_GET['date_to'] ?? '', date('Y-m-t'));
$rows = stock_registry_rows($pdo, $dateFrom, $dateTo);
app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Registru evidenta lucrari</title>
<style>
.stock-hero{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px 20px;box-shadow:var(--shadow);margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.stock-hero h1{margin:0 0 6px;font-size:24px;letter-spacing:-.03em}.stock-hero p{margin:0;color:var(--muted);font-size:14px}.stock-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px;box-shadow:var(--shadow);margin-bottom:16px}.stock-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.stock-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:900;margin-bottom:6px}.stock-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}.stock-table{width:100%;border-collapse:collapse;font-size:11px;min-width:1320px}.stock-table th,.stock-table td{padding:7px 8px;border-bottom:1px solid var(--border2);text-align:left;vertical-align:top}.stock-table th{font-size:9px;text-transform:uppercase;color:var(--muted);background:#f8fafc;white-space:nowrap}.stock-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.stock-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.stock-tabs a{pointer-events:auto;cursor:pointer;text-decoration:none;position:relative;z-index:3;min-height:38px;border:1px solid var(--border);border-radius:13px;padding:0 12px;display:inline-flex;align-items:center;font-weight:850;background:#fff}.stock-tabs a.active{background:var(--accent);border-color:var(--accent);color:#fff}.stock-note{background:#fff;border:1px dashed var(--border);border-radius:14px;padding:12px 14px;color:var(--muted);font-size:13px;margin-bottom:14px}.stock-actions a,.stock-actions .btn,a.btn{pointer-events:auto!important;cursor:pointer!important;text-decoration:none!important;position:relative;z-index:3;}@media(max-width:900px){.stock-grid{grid-template-columns:1fr}.stock-hero h1{font-size:20px}.stock-card{padding:14px}}
</style>
</head><body><div class="layout"><?php render_sidebar('stock', true); ?><main class="main"><div class="topbar"><div style="padding:0 20px;font-weight:900;">Gestiune - Registru evidenta lucrari</div></div><div class="content">
<div class="stock-hero"><div><h1>Registru evidenta lucrari</h1><p>Raport legal compact, cate o lucrare/produs pe rand, cu toate coloanele obligatorii.</p></div></div>
<div class="stock-tabs">
    <a class="" href="stock.php">Dashboard</a>
    <a class="" href="stock_products.php">Produse</a>
    <a class="" href="stock_receipts.php">Intrari stoc</a>
    <a class="" href="stock_movements.php">Iesiri / miscari</a>
    <a class="" href="stock_notifications.php">Notificari</a>
    <a class="" href="stock_card.php">Fisa magazie</a>
    <a class="active" href="stock_work_registry.php">Registru evidenta lucrari</a>
</div>
<form class="stock-card" method="get">
    <h2 style="margin:0 0 14px;font-size:18px;">Filtre registru</h2>
    <div class="stock-grid">
        <div class="stock-field"><label>Data de la</label><input type="date" name="date_from" value="<?= stock_h($dateFrom) ?>"></div>
        <div class="stock-field"><label>Data pana la</label><input type="date" name="date_to" value="<?= stock_h($dateTo) ?>"></div>
    </div>
    <div class="actions-row"><div></div><div class="stock-actions"><button class="btn accent" type="submit">Afiseaza</button><a class="btn" target="_blank" href="stock_work_registry_export_pdf.php?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>">Export PDF</a></div></div>
</form>
<div class="stock-note">Datele vor fi completate automat cand consumul de produs va fi legat de procesul verbal. Momentan apar doar miscarile de tip <strong>Consum lucrare/PV</strong> existente in stoc.</div>
<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Registru - <?= stock_h($dateFrom) ?> - <?= stock_h($dateTo) ?></h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Data</th><th>Beneficiar</th><th>Procedura</th><th>Produs biocid</th><th>Nr. aviz</th><th>Lot</th><th>Cantitate</th><th>Concentratie</th><th>Nr. PV</th><th>Lucratori</th></tr></thead><tbody>
<?php foreach($rows as $r): ?>
<tr><td><?= stock_h($r['procedure_date'] ? date('d.m.Y H:i', strtotime($r['procedure_date'])) : '-') ?></td><td><?= stock_h($r['beneficiary_name'] ?: '-') ?></td><td><?= stock_h(stock_group_label((string)$r['procedure_type'])) ?></td><td><?= stock_h($r['product_name']) ?></td><td><?= stock_h($r['aviz_no'] ?: '-') ?></td><td><?= stock_h($r['lot'] ?: '-') ?></td><td><?= stock_h(stock_unit_display($r['qty'], $r['unit_consumption'])) ?></td><td><?= stock_h($r['work_concentration'] ?: '-') ?></td><td><?= stock_h($r['pv_no'] ?: '-') ?></td><td><?= stock_h($r['workers_names'] ?: '-') ?></td></tr>
<?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="10">Nu exista inca inregistrari de consum PV in intervalul selectat.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div></main></div></body></html>
