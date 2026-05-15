<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';

if (!is_admin()) { header('Location: calendar.php'); exit; }
if (!stock_table_exists($pdo, 'stock_products')) { header('Location: stock_install.php'); exit; }

$msg = '';
$err = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $action = $_POST['action'] ?? '';
        if ($action === 'save_receipt') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $inputMode = (string)($_POST['input_mode'] ?? 'qty');
            $directQty = stock_decimal($_POST['qty'] ?? 0);
            $packageCount = stock_decimal($_POST['package_count'] ?? 0);
            $product = stock_get_product($pdo, $productId);
            if (!$product) { throw new RuntimeException('Produsul selectat nu exista.'); }
            $isBio = stock_is_biocide_group((string)$product['product_group']);
            $qty = $inputMode === 'packages' ? $packageCount * (float)$product['package_qty'] : $directQty;
            $data = [
                'product_id' => $productId,
                'reception_date' => trim((string)($_POST['reception_date'] ?? '')),
                'document_no' => trim((string)($_POST['document_no'] ?? '')),
                'supplier' => trim((string)($_POST['supplier'] ?? '')),
                'qty' => $qty,
                'package_count' => $inputMode === 'packages' ? $packageCount : null,
                'lot' => $isBio ? trim((string)($_POST['lot'] ?? '')) : null,
                'expires_at' => $isBio ? (trim((string)($_POST['expires_at'] ?? '')) ?: null) : null,
                'notes' => trim((string)($_POST['notes'] ?? '')),
            ];
            stock_validate_receipt_data($pdo, $data);

            $stmt = $pdo->prepare("INSERT INTO stock_receipts (product_id, reception_date, document_no, supplier, qty, package_count, lot, expires_at, notes, created_by, created_at) VALUES (:product_id, :reception_date, :document_no, :supplier, :qty, :package_count, :lot, :expires_at, :notes, :created_by, NOW())");
            $stmt->execute([
                'product_id' => $data['product_id'],
                'reception_date' => $data['reception_date'],
                'document_no' => $data['document_no'],
                'supplier' => $data['supplier'] ?: null,
                'qty' => $data['qty'],
                'package_count' => $data['package_count'],
                'lot' => $data['lot'] ?: null,
                'expires_at' => $data['expires_at'] ?: null,
                'notes' => $data['notes'] ?: null,
                'created_by' => function_exists('current_user_id') ? current_user_id() : null,
            ]);
            $receiptId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO stock_movements (product_id, receipt_id, movement_type, qty, reference_type, reference_id, notes, created_by, created_at) VALUES (?, ?, 'receipt', ?, 'stock_receipt', ?, ?, ?, NOW())")->execute([
                $data['product_id'], $receiptId, $data['qty'], $receiptId, 'Intrare stoc: ' . $data['document_no'], function_exists('current_user_id') ? current_user_id() : null
            ]);
            $msg = 'Intrarea in stoc a fost salvata.';
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$products = $pdo->query("SELECT id, name, product_group, unit_consumption, package_qty FROM stock_products WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$receipts = $pdo->query("SELECT r.*, p.name AS product_name, p.product_group, p.unit_consumption FROM stock_receipts r INNER JOIN stock_products p ON p.id = r.product_id ORDER BY r.reception_date DESC, r.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$productJson = [];
foreach ($products as $p) { $productJson[(int)$p['id']] = $p; }

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Intrari stoc</title>
<style>
.stock-hero{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px 20px;box-shadow:var(--shadow);margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.stock-hero h1{margin:0 0 6px;font-size:24px;letter-spacing:-.03em}.stock-hero p{margin:0;color:var(--muted);font-size:14px}.stock-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px;box-shadow:var(--shadow);margin-bottom:16px}.stock-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.stock-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.stock-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:900;margin-bottom:6px}.stock-field small{display:block;color:var(--muted);font-size:12px;margin-top:5px}.stock-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}.stock-table{width:100%;border-collapse:collapse;font-size:13px;min-width:960px}.stock-table th,.stock-table td{padding:10px 11px;border-bottom:1px solid var(--border2);text-align:left;vertical-align:top}.stock-table th{font-size:11px;text-transform:uppercase;color:var(--muted);background:#f8fafc;white-space:nowrap}.stock-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.stock-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.stock-tabs a{pointer-events:auto;cursor:pointer;text-decoration:none;position:relative;z-index:3;min-height:38px;border:1px solid var(--border);border-radius:13px;padding:0 12px;display:inline-flex;align-items:center;font-weight:850;background:#fff}.stock-tabs a.active{background:var(--accent);border-color:var(--accent);color:#fff}.js-biocide-receipt.is-hidden{display:none!important}.stock-actions a,.stock-actions .btn,a.btn{pointer-events:auto!important;cursor:pointer!important;text-decoration:none!important;position:relative;z-index:3;}@media(max-width:900px){.stock-grid,.stock-grid-3{grid-template-columns:1fr}.stock-hero h1{font-size:20px}.stock-card{padding:14px}}
</style>
</head><body><div class="layout"><?php render_sidebar('stock', true); ?><main class="main"><div class="topbar"><div style="padding:0 20px;font-weight:900;">Gestiune - Intrari stoc</div></div><div class="content">
<div class="stock-hero"><div><h1>Intrari stoc</h1><p>Adauga marfa in stoc. Pentru biocide, lotul si data expirarii sunt obligatorii. Pentru materiale, aceste campuri nu apar.</p></div><div class="stock-actions"><a class="btn" href="stock_products.php">Produse</a><a class="btn accent" href="stock_receipts.php">Intrare noua</a></div></div>
<div class="stock-tabs">
    <a class="" href="stock.php">Dashboard</a>
    <a class="" href="stock_products.php">Produse</a>
    <a class="active" href="stock_receipts.php">Intrari stoc</a>
    <a class="" href="stock_movements.php">Iesiri / miscari</a>
    <a class="" href="stock_notifications.php">Notificari</a>
    <a class="" href="stock_card.php">Fisa magazie</a>
    <a class="" href="stock_work_registry.php">Registru evidenta lucrari</a>
</div>
<?php if ($msg): ?><div class="notice notice-success"><?= stock_h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice notice-danger"><?= stock_h($err) ?></div><?php endif; ?>
<form class="stock-card" method="post" id="receiptForm">
<?= csrf_field() ?><input type="hidden" name="action" value="save_receipt">
<h2 style="margin:0 0 14px;font-size:18px;">Adauga intrare stoc</h2>
<div class="stock-grid">
    <div class="stock-field"><label>Produs *</label><select name="product_id" id="product_id" required><option value="">Alege produs</option><?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= stock_h($p['name']) ?> - <?= stock_h(stock_group_label($p['product_group'])) ?></option><?php endforeach; ?></select><small id="productInfo"></small></div>
    <div class="stock-field"><label>Data receptiei *</label><input type="date" name="reception_date" required value="<?= date('Y-m-d') ?>"></div>
</div>
<div class="stock-grid" style="margin-top:14px;">
    <div class="stock-field"><label>Document intrare *</label><input name="document_no" required placeholder="Factura / aviz"></div>
    <div class="stock-field"><label>Furnizor</label><input name="supplier" placeholder="Denumire furnizor"></div>
</div>
<div class="stock-grid js-biocide-receipt is-hidden" style="margin-top:14px;">
    <div class="stock-field"><label>Lot produs biocid *</label><input name="lot" id="lot" placeholder="Ex: LOT123"></div>
    <div class="stock-field"><label>Data expirarii lotului *</label><input type="date" name="expires_at" id="expires_at"></div>
</div>
<div class="stock-grid" style="margin-top:14px;">
    <div class="stock-field"><label>Mod introducere cantitate</label><select name="input_mode" id="input_mode"><option value="qty">Cantitate directa in unitatea de consum</option><option value="packages">Numar ambalaje x cantitate per ambalaj</option></select></div>
    <div class="stock-field"><label>Cantitate calculata</label><input id="qty_preview" readonly value="0"><small>Valoarea salvata in stoc.</small></div>
</div>
<div class="stock-grid" style="margin-top:14px;" id="directQtyBox"><div class="stock-field"><label>Cantitate intrata *</label><input name="qty" id="qty" inputmode="decimal" value="0"><small id="qtyHelp"></small></div></div>
<div class="stock-grid" style="margin-top:14px;display:none;" id="packagesBox"><div class="stock-field"><label>Numar ambalaje</label><input name="package_count" id="package_count" inputmode="decimal" value="0"><small id="packageHelp"></small></div></div>
<div class="stock-field" style="margin-top:14px;"><label>Observatii</label><textarea name="notes" rows="2"></textarea></div>
<div class="actions-row"><div></div><button type="submit" class="btn accent">Salveaza intrarea</button></div>
</form>
<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Ultimele intrari</h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Data</th><th>Produs</th><th>Document</th><th>Furnizor</th><th>Cantitate</th><th>Lot</th><th>Expirare</th><th>Observatii</th></tr></thead><tbody>
<?php foreach ($receipts as $r): ?><tr><td><?= stock_h($r['reception_date']) ?></td><td><strong><?= stock_h($r['product_name']) ?></strong><br><span style="color:var(--muted);font-size:12px;"><?= stock_h(stock_group_label($r['product_group'])) ?></span></td><td><?= stock_h($r['document_no']) ?></td><td><?= stock_h($r['supplier'] ?: '-') ?></td><td><?= stock_h(stock_unit_display($r['qty'], $r['unit_consumption'])) ?></td><td><?= stock_h($r['lot'] ?: '-') ?></td><td><?= stock_h($r['expires_at'] ?: '-') ?></td><td><?= stock_h($r['notes'] ?: '-') ?></td></tr><?php endforeach; ?><?php if (!$receipts): ?><tr><td colspan="8">Nu exista intrari.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div></main></div>
<script>
var products = <?= json_encode($productJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
function n(v){v=(v||'').toString().replace(',', '.');var x=parseFloat(v);return isNaN(x)?0:x;}
function f(x){return (Math.round(x*1000)/1000).toString();}
function unitDisplay(q,u){if(u==='ml') return f(q)+' ml / '+f(q/1000)+' L'; if(u==='gr') return f(q)+' gr / '+f(q/1000)+' kg'; return f(q)+' buc';}
function isBioGroup(g){return ['dezinsectie','dezinfectie','deratizare'].indexOf(g)>=0;}
function refreshReceipt(){var id=document.getElementById('product_id').value;var p=products[id];var mode=document.getElementById('input_mode').value;document.getElementById('packagesBox').style.display=mode==='packages'?'grid':'none';document.getElementById('directQtyBox').style.display=mode==='qty'?'grid':'none';var qty=0;if(p){var bio=isBioGroup(p.product_group);document.querySelectorAll('.js-biocide-receipt').forEach(function(el){el.classList.toggle('is-hidden', !bio);});document.getElementById('productInfo').textContent='Unitate consum: '+p.unit_consumption+'. 1 ambalaj = '+unitDisplay(parseFloat(p.package_qty||1),p.unit_consumption);document.getElementById('qtyHelp').textContent='Introdu cantitatea in '+p.unit_consumption;document.getElementById('packageHelp').textContent='1 ambalaj = '+unitDisplay(parseFloat(p.package_qty||1),p.unit_consumption);qty=mode==='packages'?n(document.getElementById('package_count').value)*parseFloat(p.package_qty||1):n(document.getElementById('qty').value);document.getElementById('qty_preview').value=unitDisplay(qty,p.unit_consumption);}else{document.querySelectorAll('.js-biocide-receipt').forEach(function(el){el.classList.add('is-hidden');});document.getElementById('productInfo').textContent='';document.getElementById('qty_preview').value='0';}}
['product_id','input_mode','qty','package_count'].forEach(function(id){document.getElementById(id).addEventListener('change',refreshReceipt);document.getElementById(id).addEventListener('input',refreshReceipt);});refreshReceipt();
</script>
</body></html>
