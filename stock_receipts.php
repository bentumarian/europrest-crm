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

        if ($action === 'save_receipt') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $directQty = stock_decimal($_POST['qty'] ?? 0);
            $packageCount = stock_decimal($_POST['package_count'] ?? 0);
            $product = stock_get_product($pdo, $productId);
            if (!$product) { throw new RuntimeException('Produsul selectat nu există.'); }
            $isBio = stock_is_biocide_group((string)$product['product_group']);
            // qty e sursa de adevar (sincronizata din JS cu package_count).
            // Daca utilizatorul a completat doar ambalaje fara qty (caz extrem),
            // reconstruim qty din package_count.
            $qty = $directQty > 0 ? $directQty : $packageCount * (float)$product['package_qty'];
            $data = [
                'product_id' => $productId,
                'reception_date' => trim((string)($_POST['reception_date'] ?? '')),
                'document_no' => trim((string)($_POST['document_no'] ?? '')),
                'supplier' => trim((string)($_POST['supplier'] ?? '')),
                'qty' => $qty,
                'package_count' => $packageCount > 0 ? $packageCount : null,
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
            $msg = 'Intrarea în stoc a fost salvată.';
        } elseif ($action === 'update_receipt') {
            $receiptId = (int)($_POST['id'] ?? 0);
            stock_update_receipt_metadata($pdo, $receiptId, [
                'reception_date' => $_POST['reception_date'] ?? '',
                'document_no'    => $_POST['document_no'] ?? '',
                'supplier'       => $_POST['supplier'] ?? '',
                'lot'            => $_POST['lot'] ?? '',
                'expires_at'     => $_POST['expires_at'] ?? '',
                'notes'          => $_POST['notes'] ?? '',
            ]);
            $msg = 'Recepția a fost actualizată. Pentru corecții de cantitate folosește Ajustare plus / minus din Mișcări stoc.';
        } elseif ($action === 'cancel_receipt') {
            $receiptId = (int)($_POST['id'] ?? 0);
            $reason = (string)($_POST['cancel_reason'] ?? '');
            stock_cancel_receipt($pdo, $receiptId, $reason);
            $msg = 'Recepția a fost anulată. Stocul a fost ajustat automat și mișcarea apare în istoric.';
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$editId = (int)($_GET['edit'] ?? 0);
$editReceipt = $editId > 0 ? stock_get_receipt($pdo, $editId) : null;
$editProduct = $editReceipt ? stock_get_product($pdo, (int)$editReceipt['product_id']) : null;
$isEditing = $editReceipt && empty($editReceipt['cancelled_at']);
$editIsBio = $editProduct ? stock_is_biocide_group((string)$editProduct['product_group']) : false;

$products = $pdo->query("SELECT id, name, product_group, unit_consumption, package_qty FROM stock_products WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Filtre listă recepții
$filterSearch = trim((string)($_GET['q'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? '')); // '', 'active', 'cancelled'
$filterFrom = trim((string)($_GET['from'] ?? ''));
$filterTo = trim((string)($_GET['to'] ?? ''));
$filterProduct = (int)($_GET['product_id'] ?? 0);

$wh = ['1=1'];
$pa = [];
if ($filterSearch !== '') {
    $wh[] = '(p.name LIKE ? OR r.document_no LIKE ? OR r.supplier LIKE ? OR r.lot LIKE ?)';
    $like = '%' . $filterSearch . '%';
    $pa[] = $like; $pa[] = $like; $pa[] = $like; $pa[] = $like;
}
if ($filterStatus === 'active') {
    $wh[] = 'r.cancelled_at IS NULL';
} elseif ($filterStatus === 'cancelled') {
    $wh[] = 'r.cancelled_at IS NOT NULL';
}
if ($filterFrom !== '') {
    $wh[] = 'r.reception_date >= ?';
    $pa[] = $filterFrom;
}
if ($filterTo !== '') {
    $wh[] = 'r.reception_date <= ?';
    $pa[] = $filterTo;
}
if ($filterProduct > 0) {
    $wh[] = 'r.product_id = ?';
    $pa[] = $filterProduct;
}
$sqlRCount = 'SELECT COUNT(*) FROM stock_receipts r INNER JOIN stock_products p ON p.id = r.product_id WHERE ' . implode(' AND ', $wh);
$stmtRCount = $pdo->prepare($sqlRCount);
$stmtRCount->execute($pa);
$totalReceipts = (int)$stmtRCount->fetchColumn();

[$pageR, $perPageR, $offsetR, $totalPagesR] = stock_pagination_state($totalReceipts, 50);

$sqlR = 'SELECT r.*, p.name AS product_name, p.product_group, p.unit_consumption FROM stock_receipts r INNER JOIN stock_products p ON p.id = r.product_id WHERE ' . implode(' AND ', $wh) . ' ORDER BY r.reception_date DESC, r.id DESC LIMIT ' . (int)$perPageR . ' OFFSET ' . (int)$offsetR;
$stmtR = $pdo->prepare($sqlR);
$stmtR->execute($pa);
$receipts = $stmtR->fetchAll(PDO::FETCH_ASSOC);

$productJson = [];
foreach ($products as $p) { $productJson[(int)$p['id']] = $p; }

// Calculez consumul pentru fiecare recepție afișată, ca să știu dacă mai poate fi anulată
$receiptConsumedMap = [];
foreach ($receipts as $r) {
    $receiptConsumedMap[(int)$r['id']] = stock_receipt_consumed_qty($pdo, (int)$r['id']);
}

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Intrări stoc</title>
<style>
.receipt-cancelled td { opacity: .55; text-decoration: line-through; }
.receipt-cancelled td .stock-badge { opacity: 1; text-decoration: none; }
.stock-actions-inline { display: flex; gap: 6px; flex-wrap: wrap; }
.stock-actions-inline form { margin: 0; }
.btn-mini { padding: 4px 9px; font-size: 12px; border-radius: 6px; }
.btn-danger { background: #b42318; color: #fff; border-color: #b42318; }
.btn-danger:hover { background: #8a1a13; }
.stock-edit-banner { background: var(--surface-alt, #f3f6fb); border-left: 4px solid var(--accent, #2563eb); padding: 10px 14px; border-radius: 6px; margin-bottom: 10px; font-size: 13px; }
.stock-edit-banner strong { display: block; margin-bottom: 2px; }
.stock-edit-banner .small { color: var(--muted, #555); font-size: 12px; }

/* Card cantitate intrare stoc - doua inputuri sincronizate bidirectional */
.qty-card {
    background: var(--surface-alt, #f8fafc);
    border: 1px dashed var(--border, #d5dce5);
    border-radius: 8px;
    padding: 14px 16px;
}
.qty-grid {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 14px;
    align-items: end;
}
.qty-field label {
    display: block;
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
    color: var(--muted, #64748b);
    margin-bottom: 6px;
    letter-spacing: 0;
}
.qty-input-wrap {
    position: relative;
}
.qty-input-wrap input {
    padding-right: 70px !important;
    text-align: left;
    font-weight: 600;
}
.qty-unit-suffix {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted, #64748b);
    font-weight: 700;
    font-size: 13px;
    pointer-events: none;
    background: var(--surface, #fff);
    padding: 0 4px;
}
.qty-separator {
    align-self: center;
    color: var(--muted, #64748b);
    font-weight: 800;
    font-size: 11px;
    letter-spacing: 0.1em;
    padding-bottom: 12px;
}
.qty-helper {
    display: block;
    margin-top: 4px;
    color: var(--muted, #64748b);
    font-size: 11.5px;
}
.qty-summary {
    margin-top: 12px;
    padding: 8px 12px;
    background: #ecfdf5;
    border-left: 3px solid #15803d;
    border-radius: 4px;
    font-size: 13px;
    color: #14532d;
}
.qty-summary strong { font-weight: 800; }
@media(max-width: 760px) {
    .qty-grid {
        grid-template-columns: 1fr;
    }
    .qty-separator {
        padding-bottom: 0;
        text-align: center;
    }
}
</style>
</head><body><div class="layout"><?php render_sidebar('stock_receipts', true); ?><main class="main"><div class="content">
<div class="stock-hero"><div><h1>Intrări stoc</h1><p>Adaugă marfa în stoc. Pentru biocide, lotul și data expirării sunt obligatorii.</p></div><div class="stock-actions"><?php if ($isEditing): ?><a class="btn" href="stock_receipts.php">Anulează editarea</a><?php endif; ?></div></div>
<?php render_stock_module_nav('receipts'); ?>
<?php if ($msg): ?><div class="notice notice-success"><?= stock_h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice notice-danger"><?= stock_h($err) ?></div><?php endif; ?>

<?php if ($isEditing): ?>
<form class="stock-card" method="post">
<?= csrf_field() ?>
<input type="hidden" name="action" value="update_receipt">
<input type="hidden" name="id" value="<?= (int)$editReceipt['id'] ?>">
<h2 style="margin:0 0 14px;font-size:18px;">Editează recepția #<?= (int)$editReceipt['id'] ?></h2>
<div class="stock-edit-banner">
    <strong>Produs: <?= stock_h($editProduct['name'] ?? '-') ?> · <?= stock_h(stock_unit_display((float)$editReceipt['qty'], (string)$editProduct['unit_consumption'])) ?></strong>
    <span class="small">Produsul și cantitatea nu pot fi modificate. Pentru corecții cantitative, folosește <strong>Ajustare plus / minus</strong> din pagina Mișcări stoc, sau anulează această recepție și introdu una corectă.</span>
</div>
<div class="stock-grid">
    <div class="stock-field"><label>Data recepției *</label><input type="date" name="reception_date" required value="<?= stock_h($editReceipt['reception_date']) ?>"></div>
    <div class="stock-field"><label>Document intrare *</label><input name="document_no" required value="<?= stock_h($editReceipt['document_no']) ?>"></div>
</div>
<div class="stock-grid" style="margin-top:14px;">
    <div class="stock-field"><label>Furnizor</label><input name="supplier" value="<?= stock_h($editReceipt['supplier'] ?? '') ?>"></div>
    <div class="stock-field"><label>&nbsp;</label></div>
</div>
<?php if ($editIsBio): ?>
<div class="stock-grid" style="margin-top:14px;">
    <div class="stock-field"><label>Lot biocid *</label><input name="lot" required value="<?= stock_h($editReceipt['lot'] ?? '') ?>"></div>
    <div class="stock-field"><label>Data expirării *</label><input type="date" name="expires_at" required value="<?= stock_h($editReceipt['expires_at'] ?? '') ?>"></div>
</div>
<?php endif; ?>
<div class="stock-field" style="margin-top:14px;"><label>Observații</label><textarea name="notes" rows="2"><?= stock_h($editReceipt['notes'] ?? '') ?></textarea></div>
<div class="actions-row"><a class="btn" href="stock_receipts.php">Renunță</a><button type="submit" class="btn accent">Salvează modificările</button></div>
</form>
<?php else: ?>
<form class="stock-card" method="post" id="receiptForm">
<?= csrf_field() ?><input type="hidden" name="action" value="save_receipt">
<h2 style="margin:0 0 14px;font-size:18px;">Adaugă intrare stoc</h2>
<div class="stock-grid">
    <div class="stock-field"><label>Produs *</label><select name="product_id" id="product_id" required><option value="">Alege produs</option><?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= stock_h($p['name']) ?> - <?= stock_h(stock_group_label($p['product_group'])) ?></option><?php endforeach; ?></select><small id="productInfo"></small></div>
    <div class="stock-field"><label>Data recepției *</label><input type="date" name="reception_date" required value="<?= date('Y-m-d') ?>"></div>
</div>
<div class="stock-grid" style="margin-top:14px;">
    <div class="stock-field"><label>Document intrare *</label><input name="document_no" required placeholder="Factura / aviz"></div>
    <div class="stock-field"><label>Furnizor</label><input name="supplier" placeholder="Denumire furnizor"></div>
</div>
<div class="stock-grid js-biocide-receipt is-hidden" style="margin-top:14px;">
    <div class="stock-field"><label>Lot produs biocid *</label><input name="lot" id="lot" placeholder="Ex: LOT123"></div>
    <div class="stock-field"><label>Data expirării lotului *</label><input type="date" name="expires_at" id="expires_at"></div>
</div>
<div class="qty-card" style="margin-top:14px;">
    <input type="hidden" name="input_mode" value="qty">
    <div class="qty-grid">
        <div class="qty-field">
            <label>Cantitate intrată *</label>
            <div class="qty-input-wrap">
                <input name="qty" id="qty" inputmode="decimal" value="0" autocomplete="off">
                <span class="qty-unit-suffix" id="qtyUnitSuffix">—</span>
            </div>
            <small id="qtyHelp" class="qty-helper">Selectează întâi produsul.</small>
        </div>
        <div class="qty-separator">SAU</div>
        <div class="qty-field">
            <label>Număr ambalaje</label>
            <div class="qty-input-wrap">
                <input name="package_count" id="package_count" inputmode="decimal" value="0" autocomplete="off">
                <span class="qty-unit-suffix">ambalaje</span>
            </div>
            <small id="packageHelp" class="qty-helper">—</small>
        </div>
    </div>
    <div class="qty-summary" id="qtySummary" style="display:none;">
        Total intrat în stoc: <strong id="qtySummaryValue">—</strong>
    </div>
</div>
<div class="stock-field" style="margin-top:14px;"><label>Observații</label><textarea name="notes" rows="2"></textarea></div>
<div class="actions-row"><div></div><button type="submit" class="btn accent">Salvează intrarea</button></div>
</form>
<?php endif; ?>

<form class="stock-card" method="get" style="margin-bottom:0;">
    <h2 style="margin:0 0 14px;font-size:18px;">Filtrează intrări</h2>
    <div class="stock-grid-4">
        <div class="stock-field"><label>Căutare</label><input type="text" name="q" value="<?= stock_h($filterSearch) ?>" placeholder="Produs, document, furnizor, lot..." autocomplete="off"></div>
        <div class="stock-field"><label>Produs</label><select name="product_id" onchange="this.form.submit()"><option value="0">Toate</option><?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $filterProduct === (int)$p['id'] ? 'selected' : '' ?>><?= stock_h($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="stock-field"><label>Status</label><select name="status" onchange="this.form.submit()"><option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Toate</option><option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Doar active</option><option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Doar anulate</option></select></div>
        <div class="stock-field"><label>De la</label><input type="date" name="from" value="<?= stock_h($filterFrom) ?>" onchange="this.form.submit()"></div>
        <div class="stock-field"><label>Până la</label><input type="date" name="to" value="<?= stock_h($filterTo) ?>" onchange="this.form.submit()"></div>
    </div>
    <div class="actions-row">
        <a class="btn" href="stock_receipts.php">Resetează</a>
        <div class="stock-actions">
            <a class="btn" href="stock_export.php?type=receipts&<?= http_build_query(array_filter(['q' => $filterSearch, 'status' => $filterStatus, 'from' => $filterFrom, 'to' => $filterTo, 'product_id' => $filterProduct ?: null])) ?>">Export Excel</a>
            <button type="submit" class="btn accent">Caută</button>
        </div>
    </div>
</form>

<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Intrări recente (<?= (int)$totalReceipts ?>)</h2>
<div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Data</th><th>Produs</th><th>Document</th><th>Furnizor</th><th>Cantitate</th><th>Lot</th><th>Expirare</th><th>Status</th><th>Acțiuni</th></tr></thead><tbody>
<?php foreach ($receipts as $r):
    $rid = (int)$r['id'];
    $consumed = (float)($receiptConsumedMap[$rid] ?? 0);
    $isCancelled = !empty($r['cancelled_at']);
    $canCancel = !$isCancelled && $consumed <= 0.0001;
?>
<tr class="<?= $isCancelled ? 'receipt-cancelled' : '' ?>">
    <td><?= stock_h($r['reception_date']) ?></td>
    <td><strong><?= stock_h($r['product_name']) ?></strong><br><span style="color:var(--muted);font-size:12px;"><?= stock_h(stock_group_label($r['product_group'])) ?></span></td>
    <td><?= stock_h($r['document_no']) ?><?php if ($r['notes']): ?><br><span style="color:var(--muted);font-size:12px;"><?= stock_h($r['notes']) ?></span><?php endif; ?></td>
    <td><?= stock_h($r['supplier'] ?: '-') ?></td>
    <td><?= stock_h(stock_unit_display($r['qty'], $r['unit_consumption'])) ?><?php if ($consumed > 0): ?><br><span style="color:var(--muted);font-size:12px;">consumat: <?= stock_h(stock_unit_display($consumed, $r['unit_consumption'])) ?></span><?php endif; ?></td>
    <td><?= stock_h($r['lot'] ?: '-') ?></td>
    <td><?= stock_h($r['expires_at'] ?: '-') ?></td>
    <td>
        <?php if ($isCancelled): ?>
            <span class="stock-badge red">Anulată</span>
            <?php if (!empty($r['cancel_reason'])): ?><br><span style="color:var(--muted);font-size:11px;"><?= stock_h($r['cancel_reason']) ?></span><?php endif; ?>
        <?php else: ?>
            <span class="stock-badge green">Activă</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if (!$isCancelled): ?>
            <div class="stock-actions-inline">
                <a class="btn btn-mini" href="stock_receipts.php?edit=<?= $rid ?>">Editează</a>
                <?php if ($canCancel): ?>
                    <form method="post" onsubmit="return stockConfirmCancel(this);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_receipt">
                        <input type="hidden" name="id" value="<?= $rid ?>">
                        <input type="hidden" name="cancel_reason" value="">
                        <button type="submit" class="btn btn-mini btn-danger">Anulează</button>
                    </form>
                <?php else: ?>
                    <span class="stock-badge" title="Lotul a fost consumat - anulează întâi mișcările minus">Consumat</span>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <span style="color:var(--muted);font-size:12px;">—</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (!$receipts): ?><tr><td colspan="9">Nicio intrare nu corespunde filtrelor.</td></tr><?php endif; ?>
</tbody></table></div>
<?php stock_render_pagination($pageR, $totalPagesR, $totalReceipts); ?>
</div>
</div></main></div>
<script>
function stockConfirmCancel(form){
    var reason = prompt('Motivul anulării recepției? (apare în istoricul de stoc)');
    if (reason === null) return false;
    reason = (reason || '').trim();
    if (reason === '') { alert('Motivul este obligatoriu.'); return false; }
    form.querySelector('input[name="cancel_reason"]').value = reason;
    return confirm('Confirmi anularea recepției? Operațiunea generează automat o mișcare de ajustare minus și nu poate fi anulată.');
}
<?php if (!$isEditing): ?>
var products = <?= json_encode($productJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function rcptParseNum(v) {
    v = (v || '').toString().replace(',', '.');
    var x = parseFloat(v);
    return isNaN(x) ? 0 : x;
}
function rcptFmt(x) {
    return (Math.round(x * 1000) / 1000).toString();
}
function rcptUnitFull(q, u) {
    if (u === 'ml') return rcptFmt(q) + ' ml / ' + rcptFmt(q / 1000) + ' L';
    if (u === 'gr') return rcptFmt(q) + ' gr / ' + rcptFmt(q / 1000) + ' kg';
    return rcptFmt(q) + ' buc';
}
function rcptIsBio(g) {
    return ['dezinsectie', 'dezinfectie', 'deratizare'].indexOf(g) >= 0;
}

var rcptSyncLock = false; // previne bucla infinita la sync

function rcptCurrentProduct() {
    var id = document.getElementById('product_id').value;
    return products[id] || null;
}

function rcptRefreshProductInfo() {
    var p = rcptCurrentProduct();
    var bioFields = document.querySelectorAll('.js-biocide-receipt');
    var info = document.getElementById('productInfo');
    var qtySuffix = document.getElementById('qtyUnitSuffix');
    var qtyHelp = document.getElementById('qtyHelp');
    var packageHelp = document.getElementById('packageHelp');

    if (p) {
        bioFields.forEach(function (el) { el.classList.toggle('is-hidden', !rcptIsBio(p.product_group)); });
        info.textContent = 'Unitate consum: ' + p.unit_consumption + '. 1 ambalaj = ' + rcptUnitFull(parseFloat(p.package_qty || 1), p.unit_consumption);
        qtySuffix.textContent = p.unit_consumption;
        qtyHelp.textContent = 'Introdu cantitatea în ' + p.unit_consumption + ' sau completează numărul de ambalaje în dreapta.';
        packageHelp.textContent = '1 ambalaj = ' + rcptUnitFull(parseFloat(p.package_qty || 1), p.unit_consumption);
    } else {
        bioFields.forEach(function (el) { el.classList.add('is-hidden'); });
        info.textContent = '';
        qtySuffix.textContent = '—';
        qtyHelp.textContent = 'Selectează întâi produsul.';
        packageHelp.textContent = '—';
    }
    rcptUpdateSummary();
}

function rcptUpdateSummary() {
    var p = rcptCurrentProduct();
    var summary = document.getElementById('qtySummary');
    var summaryValue = document.getElementById('qtySummaryValue');
    if (!p) { summary.style.display = 'none'; return; }
    var qty = rcptParseNum(document.getElementById('qty').value);
    if (qty <= 0) { summary.style.display = 'none'; return; }
    summary.style.display = 'block';
    summaryValue.textContent = rcptUnitFull(qty, p.unit_consumption);
}

function rcptOnQtyInput() {
    if (rcptSyncLock) return;
    var p = rcptCurrentProduct();
    if (!p) { rcptUpdateSummary(); return; }
    var qty = rcptParseNum(document.getElementById('qty').value);
    var pkgQty = parseFloat(p.package_qty || 1);
    if (pkgQty > 0) {
        rcptSyncLock = true;
        document.getElementById('package_count').value = rcptFmt(qty / pkgQty);
        rcptSyncLock = false;
    }
    rcptUpdateSummary();
}

function rcptOnPackageInput() {
    if (rcptSyncLock) return;
    var p = rcptCurrentProduct();
    if (!p) { rcptUpdateSummary(); return; }
    var packages = rcptParseNum(document.getElementById('package_count').value);
    var pkgQty = parseFloat(p.package_qty || 1);
    rcptSyncLock = true;
    document.getElementById('qty').value = rcptFmt(packages * pkgQty);
    rcptSyncLock = false;
    rcptUpdateSummary();
}

document.getElementById('product_id').addEventListener('change', function () {
    rcptRefreshProductInfo();
    // recalculez qty din package_count daca exista o valoare
    if (rcptParseNum(document.getElementById('package_count').value) > 0) {
        rcptOnPackageInput();
    }
});
document.getElementById('qty').addEventListener('input', rcptOnQtyInput);
document.getElementById('package_count').addEventListener('input', rcptOnPackageInput);
rcptRefreshProductInfo();
<?php endif; ?>
</script>
</body></html>
