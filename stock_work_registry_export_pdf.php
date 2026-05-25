<?php
require_once 'config.php';
require_login();
require_once __DIR__ . '/lib/stock_lib.php';
require_once __DIR__ . '/lib/settings_lib.php';
if (!is_admin()) { http_response_code(403); exit('Acces interzis'); }
if (!stock_table_exists($pdo, 'stock_movements')) { exit('Modulul Gestiune nu este instalat.'); }
$dateFrom = stock_date_or_default($_GET['date_from'] ?? '', date('Y-m-01'));
$dateTo = stock_date_or_default($_GET['date_to'] ?? '', date('Y-m-t'));
$rows = stock_registry_rows($pdo, $dateFrom, $dateTo);
$generatedAt = date('d.m.Y H:i');

$company = function_exists('pz_company_settings') ? pz_company_settings($pdo) : [];
$companyName = trim((string)($company['company.display_name'] ?? '')) ?: trim((string)($company['company.legal_name'] ?? 'Compania'));
$companyCui = trim((string)($company['company.cui'] ?? ''));
$companyAddress = trim((string)($company['company.address'] ?? ''));

ob_start();
?>
<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, Arial, sans-serif;font-size:8.5px;color:#111}h1{font-size:16px;margin:0 0 4px}.company-line{font-size:12px;font-weight:bold;color:#111;margin:0 0 2px}.company-meta{font-size:9px;color:#555;margin:0 0 6px}.meta{font-size:9px;color:#444;margin-bottom:8px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d5dce5;padding:4px 4px;vertical-align:top}th{background:#eef2f7;text-transform:uppercase;font-size:7px;text-align:left}.nowrap{white-space:nowrap}.small{font-size:7.5px}.footer{margin-top:8px;font-size:8px;color:#555}
</style></head><body>
<div class="company-line"><?= stock_h($companyName) ?></div>
<?php if ($companyCui !== '' || $companyAddress !== ''): ?>
<div class="company-meta">
    <?= $companyCui !== '' ? 'CUI ' . stock_h($companyCui) : '' ?>
    <?= ($companyCui !== '' && $companyAddress !== '') ? ' · ' : '' ?>
    <?= $companyAddress !== '' ? stock_h($companyAddress) : '' ?>
</div>
<?php endif; ?>
<h1>Registru evidență lucrări DDD</h1>
<div class="meta">Interval: <strong><?= stock_h(date('d.m.Y', strtotime($dateFrom))) ?></strong> - <strong><?= stock_h(date('d.m.Y', strtotime($dateTo))) ?></strong> | Generat: <?= stock_h($generatedAt) ?></div>
<table><thead><tr><th>Data</th><th>Beneficiar</th><th>Procedură</th><th>Produs biocid</th><th>Nr. aviz</th><th>Lot</th><th>Cantitate</th><th>Concentrație</th><th>Nr. PV</th><th>Lucrători</th></tr></thead><tbody>
<?php foreach($rows as $r): ?>
<tr><td class="nowrap"><?= stock_h($r['procedure_date'] ? date('d.m.Y H:i', strtotime($r['procedure_date'])) : '-') ?></td><td><?= stock_h($r['beneficiary_name'] ?: '-') ?></td><td><?= stock_h(stock_group_label((string)$r['procedure_type'])) ?></td><td><?= stock_h($r['product_name']) ?></td><td><?= stock_h($r['aviz_no'] ?: '-') ?></td><td><?= stock_h($r['lot'] ?: '-') ?></td><td class="nowrap"><?= stock_h(stock_unit_display($r['qty'], $r['unit_consumption'])) ?></td><td><?= stock_h($r['work_concentration'] ?: '-') ?></td><td><?= stock_h($r['pv_no'] ?: '-') ?></td><td><?= stock_h($r['workers_names'] ?: '-') ?></td></tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="10">Nu există încă înregistrări de consum PV în intervalul selectat.</td></tr><?php endif; ?>
</tbody></table>
<div class="footer">Registrul se completează automat din consumurile legate de procesele verbale.</div>
</body></html>
<?php
$html = ob_get_clean();
stock_render_pdf_or_html($html, 'registru_evidenta_lucrari_' . $dateFrom . '_' . $dateTo . '.pdf');
