<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
if (!stock_table_exists($pdo, 'stock_movements')) { header('Location: stock_install.php'); exit; }

$msg = '';
$err = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $action = $_POST['action'] ?? '';
        if ($action === 'save_outgoing') {
            $data = [
                'product_id' => (int)($_POST['product_id'] ?? 0),
                'movement_type' => trim((string)($_POST['movement_type'] ?? 'loss')),
                'qty' => stock_decimal($_POST['qty'] ?? 0),
                'notes' => trim((string)($_POST['notes'] ?? '')),
            ];
            $product = stock_validate_outgoing_data($pdo, $data);
            $pdo->prepare("INSERT INTO stock_movements (product_id, receipt_id, movement_type, qty, reference_type, reference_id, notes, created_by, created_at) VALUES (?, NULL, ?, ?, 'manual_stock_out', NULL, ?, ?, NOW())")->execute([
                $data['product_id'], $data['movement_type'], $data['qty'], $data['notes'] ?: stock_movement_label($data['movement_type']), function_exists('current_user_id') ? current_user_id() : null
            ]);
            $msg = 'Iesirea din stoc a fost salvata.';
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$products = stock_current_by_product($pdo);
$activeProducts = array_values(array_filter($products, function ($p) { return (int)($p['is_active'] ?? 1) === 1; }));
$rows = $pdo->query("SELECT m.*, p.name AS product_name, p.unit_consumption FROM stock_movements m INNER JOIN stock_products p ON p.id=m.product_id ORDER BY m.created_at DESC, m.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$productJson = [];
foreach ($activeProducts as $p) { $productJson[(int)$p['id']] = $p; }
app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Miscari stoc</title>
<style>
.stock-hero{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px 20px;box-shadow:var(--shadow);margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.stock-hero h1{margin:0 0 6px;font-size:24px;letter-spacing:-.03em}.stock-hero p{margin:0;color:var(--muted);font-size:14px}.stock-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px;box-shadow:var(--shadow);margin-bottom:16px}.stock-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.stock-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.stock-grid-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.stock-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:900;margin-bottom:6px}.stock-field small{display:block;color:var(--muted);font-size:12px;margin-top:5px}.stock-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}.stock-table{width:100%;border-collapse:collapse;font-size:13px;min-width:860px}.stock-table th,.stock-table td{padding:10px 11px;border-bottom:1px solid var(--border2);text-align:left;vertical-align:top}.stock-table th{font-size:11px;text-transform:uppercase;color:var(--muted);background:#f8fafc;white-space:nowrap}.stock-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.stock-badge{display:inline-flex;align-items:center;min-height:24px;border-radius:999px;padding:0 9px;font-size:11px;font-weight:850;border:1px solid var(--border);background:#f8fafc;color:var(--text);white-space:nowrap}.stock-badge.green{background:var(--success-soft);color:var(--success);border-color:rgba(31,111,84,.22)}.stock-badge.red{background:var(--danger-soft);color:var(--danger);border-color:rgba(180,35,24,.22)}.stock-badge.yellow{background:var(--warning-soft);color:var(--warning);border-color:rgba(154,103,0,.22)}.stock-badge.blue{background:var(--accent-soft);color:var(--accent);border-color:rgba(0,113,163,.22)}.stock-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.stock-tabs a{pointer-events:auto;cursor:pointer;text-decoration:none;position:relative;z-index:3;min-height:38px;border:1px solid var(--border);border-radius:13px;padding:0 12px;display:inline-flex;align-items:center;font-weight:850;background:#fff}.stock-tabs a.active{background:var(--accent);border-color:var(--accent);color:#fff}.stock-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}.stock-kpi{background:#fff;border:1px solid var(--border);border-radius:18px;padding:16px;box-shadow:var(--shadow);display:block;text-decoration:none;color:inherit}.stock-kpi .label{font-size:12px;color:var(--muted);font-weight:850;text-transform:uppercase;letter-spacing:.04em}.stock-kpi .value{font-size:26px;font-weight:950;margin-top:6px}.js-biocide-only.is-hidden{display:none!important}.stock-alert-row{background:#fffafa}.stock-note{background:#fff;border:1px dashed var(--border);border-radius:14px;padding:12px 14px;color:var(--muted);font-size:13px;margin-bottom:14px}.stock-actions a,.stock-actions .btn,a.btn{pointer-events:auto!important;cursor:pointer!important;text-decoration:none!important;position:relative;z-index:3;}@media(max-width:900px){.stock-grid,.stock-grid-3,.stock-grid-4,.stock-kpis{grid-template-columns:1fr}.stock-hero h1{font-size:20px}.stock-table{min-width:760px}.stock-card{padding:14px}}
</style>
</head><body><div class="layout"><?php render_sidebar('stock', true); ?><main class="main"><div class="topbar"><div style="padding:0 20px;font-weight:900;">Gestiune - Iesiri / miscari</div></div><div class="content"><div class="stock-hero"><div><h1>Iesiri si miscari stoc</h1><p>Scoate manual din stoc produse pierdute, expirate sau diferente constatate la inventar.</p></div></div>
<div class="stock-tabs">
    <a class="" href="stock.php">Dashboard</a>
    <a class="" href="stock_products.php">Produse</a>
    <a class="" href="stock_receipts.php">Intrari stoc</a>
    <a class="active" href="stock_movements.php">Iesiri / miscari</a>
    <a class="" href="stock_notifications.php">Notificari</a>
    <a class="" href="stock_card.php">Fisa magazie</a>
    <a class="" href="stock_work_registry.php">Registru evidenta lucrari</a>
</div>
<?php if ($msg): ?><div class="notice notice-success"><?= stock_h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice notice-danger"><?= stock_h($err) ?></div><?php endif; ?>
<form class="stock-card" method="post" id="outForm">
<?= csrf_field() ?><input type="hidden" name="action" value="save_outgoing">
<h2 style="margin:0 0 14px;font-size:18px;">Scoate din stoc</h2>
<div class="stock-grid-4">
    <div class="stock-field"><label>Produs *</label><select name="product_id" id="product_id" required><option value="">Alege produs</option><?php foreach($activeProducts as $p): ?><option value="<?= (int)$p['id'] ?>"><?= stock_h($p['name']) ?></option><?php endforeach; ?></select><small id="stockInfo"></small></div>
    <div class="stock-field"><label>Tip iesire *</label><select name="movement_type" required><option value="loss">Pierdere</option><option value="expired">Expirat</option><option value="adjust_minus">Ajustare minus</option></select></div>
    <div class="stock-field"><label>Cantitate *</label><input name="qty" id="qty" inputmode="decimal" required value="0"><small id="qtyHelp"></small></div>
    <div class="stock-field"><label>Observatii</label><input name="notes" placeholder="Ex: deteriorat, expirat, lipsa inventar"></div>
</div>
<div class="actions-row"><div></div><button type="submit" class="btn accent">Salveaza iesirea</button></div>
</form>
<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Istoric miscari</h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Data</th><th>Produs</th><th>Tip</th><th>Cantitate</th><th>Referinta</th><th>Observatii</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= stock_h($r['created_at']) ?></td><td><?= stock_h($r['product_name']) ?></td><td><span class="stock-badge blue"><?= stock_h(stock_movement_label($r['movement_type'])) ?></span></td><td><?= stock_h(stock_unit_display($r['qty'], $r['unit_consumption'])) ?></td><td><?= stock_h(($r['reference_type'] ?: '-') . ($r['reference_id'] ? ' #' . $r['reference_id'] : '')) ?></td><td><?= stock_h($r['notes'] ?: '-') ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="6">Nu exista miscari.</td></tr><?php endif; ?></tbody></table></div></div></div></main></div>
<script>
var products = <?= json_encode($productJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
function f(x){return (Math.round(parseFloat(x||0)*1000)/1000).toString();}
function unitDisplay(q,u){q=parseFloat(q||0);if(u==='ml') return f(q)+' ml / '+f(q/1000)+' L'; if(u==='gr') return f(q)+' gr / '+f(q/1000)+' kg'; return f(q)+' buc';}
function refreshOut(){var id=document.getElementById('product_id').value;var p=products[id];if(p){document.getElementById('stockInfo').textContent='Disponibil: '+unitDisplay(p.current_qty,p.unit_consumption);document.getElementById('qtyHelp').textContent='Introdu cantitatea in '+p.unit_consumption;}else{document.getElementById('stockInfo').textContent='';document.getElementById('qtyHelp').textContent='';}}
document.getElementById('product_id').addEventListener('change',refreshOut);refreshOut();
</script>
</body></html>
