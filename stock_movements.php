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
                'receipt_id' => (int)($_POST['receipt_id'] ?? 0),
                'movement_type' => trim((string)($_POST['movement_type'] ?? 'loss')),
                'qty' => stock_decimal($_POST['qty'] ?? 0),
                'notes' => trim((string)($_POST['notes'] ?? '')),
            ];
            $product = stock_validate_outgoing_data($pdo, $data);
            $pdo->prepare("INSERT INTO stock_movements (product_id, receipt_id, movement_type, qty, reference_type, reference_id, notes, created_by, created_at) VALUES (?, ?, ?, ?, 'manual_stock_out', NULL, ?, ?, NOW())")->execute([
                $data['product_id'], $data['receipt_id'] > 0 ? $data['receipt_id'] : null, $data['movement_type'], $data['qty'], $data['notes'] ?: stock_movement_label($data['movement_type']), function_exists('current_user_id') ? current_user_id() : null
            ]);
            $msg = 'Ieșirea din stoc a fost salvată.';
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$products = stock_current_by_product($pdo);
$activeProducts = array_values(array_filter($products, function ($p) { return (int)($p['is_active'] ?? 1) === 1; }));
$rows = $pdo->query("SELECT m.*, p.name AS product_name, p.unit_consumption, r.lot, r.expires_at FROM stock_movements m INNER JOIN stock_products p ON p.id=m.product_id LEFT JOIN stock_receipts r ON r.id=m.receipt_id ORDER BY m.created_at DESC, m.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$productJson = [];
foreach ($activeProducts as $p) { $productJson[(int)$p['id']] = $p; }
$receipts = stock_table_exists($pdo, 'stock_receipts')
    ? $pdo->query("SELECT r.*, p.unit_consumption FROM stock_receipts r INNER JOIN stock_products p ON p.id = r.product_id ORDER BY r.product_id ASC, COALESCE(r.expires_at, '2999-12-31') ASC, r.reception_date ASC, r.id ASC")->fetchAll(PDO::FETCH_ASSOC)
    : [];
$receiptJson = [];
foreach ($receipts as $receipt) {
    $available = stock_available_qty_for_receipt($pdo, (int)$receipt['id']);
    if ($available <= 0) {
        continue;
    }
    $receiptJson[] = [
        'id' => (int)$receipt['id'],
        'product_id' => (int)$receipt['product_id'],
        'lot' => (string)($receipt['lot'] ?: 'Fără lot'),
        'expires_at' => (string)($receipt['expires_at'] ?: ''),
        'available' => $available,
        'unit' => (string)$receipt['unit_consumption'],
    ];
}
app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mișcări stoc</title>
</head><body><div class="layout"><?php render_sidebar('stock', true); ?><main class="main"><div class="topbar"><div style="padding:0 20px;font-weight:900;">Gestiune - Ieșiri</div></div><div class="content"><div class="stock-hero"><div><h1>Ieșiri și mișcări stoc</h1><p>Scoate manual din stoc produse pierdute, expirate sau diferențe constatate la inventar.</p></div></div>
<?php render_stock_module_nav('movements'); ?>
<?php if ($msg): ?><div class="notice notice-success"><?= stock_h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice notice-danger"><?= stock_h($err) ?></div><?php endif; ?>
<form class="stock-card" method="post" id="outForm">
<?= csrf_field() ?><input type="hidden" name="action" value="save_outgoing">
<h2 style="margin:0 0 14px;font-size:18px;">Scoate din stoc</h2>
<div class="stock-grid-4">
    <div class="stock-field"><label>Produs *</label><select name="product_id" id="product_id" required><option value="">Alege produs</option><?php foreach($activeProducts as $p): ?><option value="<?= (int)$p['id'] ?>"><?= stock_h($p['name']) ?></option><?php endforeach; ?></select><small id="stockInfo"></small></div>
    <div class="stock-field js-receipt-out is-hidden"><label>Lot / stoc *</label><select name="receipt_id" id="receipt_id"><option value="0">Alege lot</option></select><small id="receiptInfo"></small></div>
    <div class="stock-field"><label>Tip ieșire *</label><select name="movement_type" required><option value="loss">Pierdere</option><option value="expired">Expirat</option><option value="adjust_minus">Ajustare minus</option></select></div>
    <div class="stock-field"><label>Cantitate *</label><input name="qty" id="qty" inputmode="decimal" required value="0"><small id="qtyHelp"></small></div>
    <div class="stock-field"><label>Observații</label><input name="notes" placeholder="Ex: deteriorat, expirat, lipsa inventar"></div>
</div>
<div class="actions-row"><div></div><button type="submit" class="btn accent">Salvează ieșirea</button></div>
</form>
<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Istoric mișcări</h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Data</th><th>Produs</th><th>Lot</th><th>Tip</th><th>Cantitate</th><th>Referință</th><th>Observații</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= stock_h($r['created_at']) ?></td><td><?= stock_h($r['product_name']) ?></td><td><?= stock_h($r['lot'] ?: '-') ?></td><td><span class="stock-badge blue"><?= stock_h(stock_movement_label($r['movement_type'])) ?></span></td><td><?= stock_h(stock_unit_display($r['qty'], $r['unit_consumption'])) ?></td><td><?= stock_h(($r['reference_type'] ?: '-') . ($r['reference_id'] ? ' #' . $r['reference_id'] : '')) ?></td><td><?= stock_h($r['notes'] ?: '-') ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="7">Nu există mișcări.</td></tr><?php endif; ?></tbody></table></div></div></div></main></div>
<script>
var products = <?= json_encode($productJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var receipts = <?= json_encode($receiptJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
function f(x){return (Math.round(parseFloat(x||0)*1000)/1000).toString();}
function unitDisplay(q,u){q=parseFloat(q||0);if(u==='ml') return f(q)+' ml / '+f(q/1000)+' L'; if(u==='gr') return f(q)+' gr / '+f(q/1000)+' kg'; return f(q)+' buc';}
function isBioGroup(g){return ['dezinsectie','dezinfectie','deratizare'].indexOf(g)>=0;}
function refreshOut(){
    var id=document.getElementById('product_id').value;
    var p=products[id];
    var receiptSelect=document.getElementById('receipt_id');
    var receiptInfo=document.getElementById('receiptInfo');
    receiptSelect.innerHTML='<option value="0">Alege lot</option>';
    receiptInfo.textContent='';
    if(p){
        var isBio=isBioGroup(p.product_group);
        document.querySelectorAll('.js-receipt-out').forEach(function(el){el.classList.toggle('is-hidden', !isBio);});
        document.getElementById('stockInfo').textContent='Disponibil: '+unitDisplay(p.current_qty,p.unit_consumption);
        document.getElementById('qtyHelp').textContent='Introdu cantitatea în '+p.unit_consumption;
        receipts.filter(function(r){return Number(r.product_id)===Number(id);}).forEach(function(r){
            var opt=document.createElement('option');
            opt.value=r.id;
            opt.textContent=r.lot+(r.expires_at?' · exp. '+r.expires_at:'')+' · '+unitDisplay(r.available,r.unit);
            receiptSelect.appendChild(opt);
        });
    }else{
        document.querySelectorAll('.js-receipt-out').forEach(function(el){el.classList.add('is-hidden');});
        document.getElementById('stockInfo').textContent='';
        document.getElementById('qtyHelp').textContent='';
    }
}
function refreshReceiptInfo(){
    var selected=receipts.find(function(r){return Number(r.id)===Number(document.getElementById('receipt_id').value||0);});
    document.getElementById('receiptInfo').textContent=selected?'Disponibil pe lot: '+unitDisplay(selected.available,selected.unit):'';
}
document.getElementById('product_id').addEventListener('change',refreshOut);
document.getElementById('receipt_id').addEventListener('change',refreshReceiptInfo);
refreshOut();
</script>
</body></html>
