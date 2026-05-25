<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
stock_ensure_schema($pdo);

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
            $isPlus = ($data['movement_type'] === 'adjust_plus');
            $referenceType = $isPlus ? 'manual_stock_in' : 'manual_stock_out';
            $pdo->prepare("INSERT INTO stock_movements (product_id, receipt_id, movement_type, qty, reference_type, reference_id, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, NOW())")->execute([
                $data['product_id'], $data['receipt_id'] > 0 ? $data['receipt_id'] : null, $data['movement_type'], $data['qty'], $referenceType, $data['notes'] ?: stock_movement_label($data['movement_type']), function_exists('current_user_id') ? current_user_id() : null
            ]);
            $msg = $isPlus ? 'Ajustarea plus a fost salvată.' : 'Ieșirea din stoc a fost salvată.';
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$products = stock_current_by_product($pdo);
$activeProducts = array_values(array_filter($products, function ($p) { return (int)($p['is_active'] ?? 1) === 1; }));

// Filtre istoric mișcări
$filterSearch = trim((string)($_GET['q'] ?? ''));
$filterType = trim((string)($_GET['type'] ?? ''));
$filterProduct = (int)($_GET['product_id'] ?? 0);
$filterFrom = trim((string)($_GET['from'] ?? ''));
$filterTo = trim((string)($_GET['to'] ?? ''));

$wh = ['1=1'];
$pa = [];
if ($filterSearch !== '') {
    $wh[] = '(p.name LIKE ? OR m.notes LIKE ? OR r.lot LIKE ?)';
    $like = '%' . $filterSearch . '%';
    $pa[] = $like; $pa[] = $like; $pa[] = $like;
}
if ($filterType !== '' && array_key_exists($filterType, stock_movement_labels())) {
    $wh[] = 'm.movement_type = ?';
    $pa[] = $filterType;
}
if ($filterProduct > 0) {
    $wh[] = 'm.product_id = ?';
    $pa[] = $filterProduct;
}
if ($filterFrom !== '') {
    $wh[] = 'DATE(m.created_at) >= ?';
    $pa[] = $filterFrom;
}
if ($filterTo !== '') {
    $wh[] = 'DATE(m.created_at) <= ?';
    $pa[] = $filterTo;
}
$sqlMCount = 'SELECT COUNT(*) FROM stock_movements m INNER JOIN stock_products p ON p.id=m.product_id LEFT JOIN stock_receipts r ON r.id=m.receipt_id WHERE ' . implode(' AND ', $wh);
$stmtMCount = $pdo->prepare($sqlMCount);
$stmtMCount->execute($pa);
$totalMovements = (int)$stmtMCount->fetchColumn();

[$pageM, $perPageM, $offsetM, $totalPagesM] = stock_pagination_state($totalMovements, 50);

$sqlM = 'SELECT m.*, p.name AS product_name, p.unit_consumption, r.lot, r.expires_at FROM stock_movements m INNER JOIN stock_products p ON p.id=m.product_id LEFT JOIN stock_receipts r ON r.id=m.receipt_id WHERE ' . implode(' AND ', $wh) . ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . (int)$perPageM . ' OFFSET ' . (int)$offsetM;
$stmtM = $pdo->prepare($sqlM);
$stmtM->execute($pa);
$rows = $stmtM->fetchAll(PDO::FETCH_ASSOC);

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
</head><body><div class="layout"><?php render_sidebar('stock_movements', true); ?><main class="main"><div class="content"><div class="stock-hero"><div><h1>Mișcări manuale de stoc</h1><p>Pierdere, expirat, ajustare minus pentru ieșiri / ajustare plus pentru intrări de corecție (ex: surplus inventar).</p></div></div>
<?php render_stock_module_nav('movements'); ?>
<?php if ($msg): ?><div class="notice notice-success"><?= stock_h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice notice-danger"><?= stock_h($err) ?></div><?php endif; ?>
<form class="stock-card" method="post" id="outForm">
<?= csrf_field() ?><input type="hidden" name="action" value="save_outgoing">
<h2 style="margin:0 0 14px;font-size:18px;">Adaugă mișcare manuală</h2>
<div class="stock-grid-4">
    <div class="stock-field"><label>Produs *</label><select name="product_id" id="product_id" required><option value="">Alege produs</option><?php foreach($activeProducts as $p): ?><option value="<?= (int)$p['id'] ?>"><?= stock_h($p['name']) ?></option><?php endforeach; ?></select><small id="stockInfo"></small></div>
    <div class="stock-field js-receipt-out is-hidden"><label>Lot / stoc *</label><select name="receipt_id" id="receipt_id"><option value="0">Alege lot</option></select><small id="receiptInfo"></small></div>
    <div class="stock-field"><label>Tip mișcare *</label><select name="movement_type" id="movement_type" required>
        <optgroup label="Ieșiri (scad stocul)">
            <option value="loss">Pierdere</option>
            <option value="expired">Expirat</option>
            <option value="adjust_minus">Ajustare minus</option>
        </optgroup>
        <optgroup label="Intrări (cresc stocul)">
            <option value="adjust_plus">Ajustare plus (surplus inventar / corecție)</option>
        </optgroup>
    </select><small id="movementHint"></small></div>
    <div class="stock-field"><label>Cantitate *</label><input name="qty" id="qty" inputmode="decimal" required value="0"><small id="qtyHelp"></small></div>
    <div class="stock-field"><label>Observații</label><input name="notes" placeholder="Ex: deteriorat, expirat, surplus inventar"></div>
</div>
<div class="actions-row"><div></div><button type="submit" class="btn accent">Salvează mișcarea</button></div>
</form>

<form class="stock-card" method="get" style="margin-bottom:0;">
    <h2 style="margin:0 0 14px;font-size:18px;">Filtrează istoricul</h2>
    <div class="stock-grid-4">
        <div class="stock-field"><label>Căutare</label><input type="text" name="q" value="<?= stock_h($filterSearch) ?>" placeholder="Produs, observații, lot..." autocomplete="off"></div>
        <div class="stock-field"><label>Tip mișcare</label><select name="type" onchange="this.form.submit()"><option value="">Toate</option><?php foreach (stock_movement_labels() as $k => $lbl): ?><option value="<?= stock_h($k) ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= stock_h($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="stock-field"><label>Produs</label><select name="product_id" onchange="this.form.submit()"><option value="0">Toate</option><?php foreach ($activeProducts as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $filterProduct === (int)$p['id'] ? 'selected' : '' ?>><?= stock_h($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="stock-field"><label>De la</label><input type="date" name="from" value="<?= stock_h($filterFrom) ?>" onchange="this.form.submit()"></div>
        <div class="stock-field"><label>Până la</label><input type="date" name="to" value="<?= stock_h($filterTo) ?>" onchange="this.form.submit()"></div>
    </div>
    <div class="actions-row">
        <a class="btn" href="stock_movements.php">Resetează</a>
        <div class="stock-actions">
            <a class="btn" href="stock_export.php?type=movements&<?= http_build_query(array_filter(['q' => $filterSearch, 'type_filter' => $filterType, 'from' => $filterFrom, 'to' => $filterTo, 'product_id' => $filterProduct ?: null])) ?>">Export Excel</a>
            <button type="submit" class="btn accent">Caută</button>
        </div>
    </div>
</form>

<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Istoric mișcări (<?= (int)$totalMovements ?>)</h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Data</th><th>Produs</th><th>Lot</th><th>Tip</th><th>Cantitate</th><th>Referință</th><th>Observații</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= stock_h($r['created_at']) ?></td><td><?= stock_h($r['product_name']) ?></td><td><?= stock_h($r['lot'] ?: '-') ?></td><td><span class="stock-badge blue"><?= stock_h(stock_movement_label($r['movement_type'])) ?></span></td><td><?= stock_h(stock_unit_display($r['qty'], $r['unit_consumption'])) ?></td><td><?= stock_h(($r['reference_type'] ?: '-') . ($r['reference_id'] ? ' #' . $r['reference_id'] : '')) ?></td><td><?= stock_h($r['notes'] ?: '-') ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="7">Nicio mișcare nu corespunde filtrelor.</td></tr><?php endif; ?></tbody></table></div><?php stock_render_pagination($pageM, $totalPagesM, $totalMovements); ?></div></div></main></div>
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
        var available=receipts.filter(function(r){return Number(r.product_id)===Number(id);});
        available.forEach(function(r){
            var opt=document.createElement('option');
            opt.value=r.id;
            opt.textContent=r.lot+(r.expires_at?' · exp. '+r.expires_at:'')+' · '+unitDisplay(r.available,r.unit);
            receiptSelect.appendChild(opt);
        });
        // FIFO sugerat: primul lot din listă are cea mai apropiată expirare
        if(isBio && available.length>0){
            receiptSelect.value=available[0].id;
            refreshReceiptInfo();
        }
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
function refreshMovementHint(){
    var type=document.getElementById('movement_type').value;
    var hint='';
    if(type==='adjust_plus'){hint='Crește stocul. Folosește la surplus constatat la inventar sau corecții.';}
    else if(type==='loss'){hint='Scoate produsul ca pierdere (deteriorat, scurgere etc.).';}
    else if(type==='expired'){hint='Scoate lotul expirat din stoc.';}
    else if(type==='adjust_minus'){hint='Scoate cantitatea ca diferență constatată la inventar.';}
    document.getElementById('movementHint').textContent=hint;
}
document.getElementById('product_id').addEventListener('change',refreshOut);
document.getElementById('receipt_id').addEventListener('change',refreshReceiptInfo);
document.getElementById('movement_type').addEventListener('change',refreshMovementHint);
refreshOut();
refreshMovementHint();
</script>
</body></html>
