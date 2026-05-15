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
        if ($action === 'save_product') {
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'name' => trim((string)($_POST['name'] ?? '')),
                'product_group' => trim((string)($_POST['product_group'] ?? 'materiale')),
                'unit_consumption' => trim((string)($_POST['unit_consumption'] ?? 'buc')),
                'package_qty' => stock_decimal($_POST['package_qty'] ?? 1),
                'min_qty' => stock_decimal($_POST['min_qty'] ?? 0),
                'aviz_no' => trim((string)($_POST['aviz_no'] ?? '')),
                'aviz_valid_until' => trim((string)($_POST['aviz_valid_until'] ?? '')) ?: null,
                'active_substance' => trim((string)($_POST['active_substance'] ?? '')),
                'product_concentration' => trim((string)($_POST['product_concentration'] ?? '')),
                'contact_time' => trim((string)($_POST['contact_time'] ?? '')),
                'default_application_method' => trim((string)($_POST['default_application_method'] ?? '')),
                'safety_measures' => trim((string)($_POST['safety_measures'] ?? '')),
                'notes' => trim((string)($_POST['notes'] ?? '')),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ];
            stock_validate_product_data($data);

            if (!stock_is_biocide_group($data['product_group'])) {
                $data['aviz_no'] = $data['aviz_no'] ?: null;
                $data['aviz_valid_until'] = $data['aviz_valid_until'] ?: null;
                $data['safety_measures'] = $data['safety_measures'] ?: null;
            }

            if ($id > 0) {
                $sql = "UPDATE stock_products SET name=:name, product_group=:product_group, unit_consumption=:unit_consumption, package_qty=:package_qty, min_qty=:min_qty, aviz_no=:aviz_no, aviz_valid_until=:aviz_valid_until, active_substance=:active_substance, product_concentration=:product_concentration, contact_time=:contact_time, default_application_method=:default_application_method, safety_measures=:safety_measures, notes=:notes, is_active=:is_active, updated_at=NOW() WHERE id=:id";
                $data['id'] = $id;
                $pdo->prepare($sql)->execute($data);
                $msg = 'Produs actualizat.';
            } else {
                $sql = "INSERT INTO stock_products (name, product_group, unit_consumption, package_qty, min_qty, aviz_no, aviz_valid_until, active_substance, product_concentration, contact_time, default_application_method, safety_measures, notes, is_active, created_at) VALUES (:name, :product_group, :unit_consumption, :package_qty, :min_qty, :aviz_no, :aviz_valid_until, :active_substance, :product_concentration, :contact_time, :default_application_method, :safety_measures, :notes, :is_active, NOW())";
                $pdo->prepare($sql)->execute($data);
                $msg = 'Produs adaugat.';
            }
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId > 0 ? stock_get_product($pdo, $editId) : null;
$products = $pdo->query("SELECT * FROM stock_products ORDER BY is_active DESC, product_group ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$groups = stock_group_options();
$units = stock_unit_options();

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Produse gestiune</title>
<style>
.stock-hero{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px 20px;box-shadow:var(--shadow);margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.stock-hero h1{margin:0 0 6px;font-size:24px;letter-spacing:-.03em}.stock-hero p{margin:0;color:var(--muted);font-size:14px}.stock-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px;box-shadow:var(--shadow);margin-bottom:16px}.stock-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.stock-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.stock-grid-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.stock-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:900;margin-bottom:6px}.stock-field small{display:block;color:var(--muted);font-size:12px;margin-top:5px}.stock-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}.stock-table{width:100%;border-collapse:collapse;font-size:13px;min-width:860px}.stock-table th,.stock-table td{padding:10px 11px;border-bottom:1px solid var(--border2);text-align:left;vertical-align:top}.stock-table th{font-size:11px;text-transform:uppercase;color:var(--muted);background:#f8fafc;white-space:nowrap}.stock-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.stock-badge{display:inline-flex;align-items:center;min-height:24px;border-radius:999px;padding:0 9px;font-size:11px;font-weight:850;border:1px solid var(--border);background:#f8fafc;color:var(--text);white-space:nowrap}.stock-badge.green{background:var(--success-soft);color:var(--success);border-color:rgba(31,111,84,.22)}.stock-badge.red{background:var(--danger-soft);color:var(--danger);border-color:rgba(180,35,24,.22)}.stock-badge.yellow{background:var(--warning-soft);color:var(--warning);border-color:rgba(154,103,0,.22)}.stock-badge.blue{background:var(--accent-soft);color:var(--accent);border-color:rgba(0,113,163,.22)}.stock-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.stock-tabs a{pointer-events:auto;cursor:pointer;text-decoration:none;position:relative;z-index:3;min-height:38px;border:1px solid var(--border);border-radius:13px;padding:0 12px;display:inline-flex;align-items:center;font-weight:850;background:#fff}.stock-tabs a.active{background:var(--accent);border-color:var(--accent);color:#fff}.stock-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}.stock-kpi{background:#fff;border:1px solid var(--border);border-radius:18px;padding:16px;box-shadow:var(--shadow);display:block;text-decoration:none;color:inherit}.stock-kpi .label{font-size:12px;color:var(--muted);font-weight:850;text-transform:uppercase;letter-spacing:.04em}.stock-kpi .value{font-size:26px;font-weight:950;margin-top:6px}.js-biocide-only.is-hidden{display:none!important}.stock-alert-row{background:#fffafa}.stock-note{background:#fff;border:1px dashed var(--border);border-radius:14px;padding:12px 14px;color:var(--muted);font-size:13px;margin-bottom:14px}.stock-actions a,.stock-actions .btn,a.btn{pointer-events:auto!important;cursor:pointer!important;text-decoration:none!important;position:relative;z-index:3;}@media(max-width:900px){.stock-grid,.stock-grid-3,.stock-grid-4,.stock-kpis{grid-template-columns:1fr}.stock-hero h1{font-size:20px}.stock-table{min-width:760px}.stock-card{padding:14px}}
</style>
</head><body><div class="layout"><?php render_sidebar('stock', true); ?><main class="main"><div class="topbar"><div style="padding:0 20px;font-weight:900;">Gestiune - Produse</div></div><div class="content">
<div class="stock-hero"><div><h1>Nomenclator produse</h1><p>Produse si materiale DDD, cu stoc minim si masuri de siguranta pentru PV.</p></div><div class="stock-actions"><a class="btn" href="stock.php">Dashboard</a><a class="btn accent" href="stock_products.php">Produs nou</a></div></div>
<div class="stock-tabs">
    <a class="" href="stock.php">Dashboard</a>
    <a class="active" href="stock_products.php">Produse</a>
    <a class="" href="stock_receipts.php">Intrari stoc</a>
    <a class="" href="stock_movements.php">Iesiri / miscari</a>
    <a class="" href="stock_notifications.php">Notificari</a>
    <a class="" href="stock_card.php">Fisa magazie</a>
    <a class="" href="stock_work_registry.php">Registru evidenta lucrari</a>
</div>
<?php if ($msg): ?><div class="notice notice-success"><?= stock_h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice notice-danger"><?= stock_h($err) ?></div><?php endif; ?>
<form class="stock-card" method="post" id="productForm">
<?= csrf_field() ?><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<h2 style="margin:0 0 14px;font-size:18px;"><?= $edit ? 'Editeaza produs' : 'Adauga produs' ?></h2>
<div class="stock-grid">
    <div class="stock-field"><label>Denumire produs *</label><input name="name" required value="<?= stock_h($edit['name'] ?? '') ?>" placeholder="Ex: K-Othrine SC 25"></div>
    <div class="stock-field"><label>Grupa produs *</label><select name="product_group" id="product_group" required><?php foreach ($groups as $k => $v): ?><option value="<?= stock_h($k) ?>" <?= (($edit['product_group'] ?? 'materiale') === $k ? 'selected' : '') ?>><?= stock_h($v) ?></option><?php endforeach; ?></select><small>Pentru Dezinsectie / Dezinfectie / Deratizare sunt obligatorii avizul, valabilitatea si masurile de siguranta.</small></div>
</div>
<div class="stock-grid-3" style="margin-top:14px;">
    <div class="stock-field"><label>Unitate consum *</label><select name="unit_consumption" id="unit_consumption" required><?php foreach ($units as $k => $v): ?><option value="<?= stock_h($k) ?>" <?= (($edit['unit_consumption'] ?? 'buc') === $k ? 'selected' : '') ?>><?= stock_h($v) ?></option><?php endforeach; ?></select></div>
    <div class="stock-field"><label>Cantitate per ambalaj *</label><input name="package_qty" id="package_qty" required value="<?= stock_h($edit['package_qty'] ?? '1') ?>" inputmode="decimal"><small id="packagePreview"></small></div>
    <div class="stock-field"><label>Stoc minim</label><input name="min_qty" id="min_qty" value="<?= stock_h($edit['min_qty'] ?? '0') ?>" inputmode="decimal"><small>Se exprima in unitatea de consum: ml / gr / buc. Cand stocul ajunge la acest nivel, apare alerta in Notificari.</small></div>
</div>
<div class="stock-grid js-biocide-only" style="margin-top:14px;">
    <div class="stock-field"><label>Numar aviz *</label><input name="aviz_no" value="<?= stock_h($edit['aviz_no'] ?? '') ?>" placeholder="Numar act administrativ / aviz"></div>
    <div class="stock-field"><label>Valabilitate aviz *</label><input type="date" name="aviz_valid_until" value="<?= stock_h($edit['aviz_valid_until'] ?? '') ?>"></div>
</div>
<div class="stock-grid-4 js-biocide-only" style="margin-top:14px;">
    <div class="stock-field"><label>Substanta activa</label><input name="active_substance" value="<?= stock_h($edit['active_substance'] ?? '') ?>"></div>
    <div class="stock-field"><label>Concentratie produs</label><input name="product_concentration" value="<?= stock_h($edit['product_concentration'] ?? '') ?>"></div>
    <div class="stock-field"><label>Timp contact / actiune</label><input name="contact_time" value="<?= stock_h($edit['contact_time'] ?? '') ?>"></div>
    <div class="stock-field"><label>Metoda aplicare implicita</label><input name="default_application_method" value="<?= stock_h($edit['default_application_method'] ?? '') ?>"></div>
</div>
<div class="stock-field js-biocide-only" style="margin-top:14px;"><label>Masuri de siguranta pentru PV *</label><textarea name="safety_measures" rows="5" placeholder="Acest text intra automat in PV sub tabelul cu produse biocide."><?= stock_h($edit['safety_measures'] ?? '') ?></textarea><small>Nu se completeaza fraze CLP. Se trec doar instructiunile utile pentru beneficiar.</small></div>
<div class="stock-field" style="margin-top:14px;"><label>Observatii interne</label><textarea name="notes" rows="2"><?= stock_h($edit['notes'] ?? '') ?></textarea></div>
<div class="actions-row"><label style="display:flex;gap:8px;align-items:center;margin:0;text-transform:none;letter-spacing:0;font-size:14px;"><input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto;" <?= ((int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '') ?>> Produs activ</label><div class="stock-actions"><a class="btn" href="stock_products.php">Curata</a><button class="btn accent" type="submit">Salveaza produs</button></div></div>
</form>
<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Lista produse</h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Produs</th><th>Grupa</th><th>UM</th><th>Ambalaj</th><th>Stoc minim</th><th>Aviz</th><th>Valabilitate</th><th>Masuri PV</th><th>Status</th><th>Actiuni</th></tr></thead><tbody>
<?php foreach ($products as $p): $bio = stock_is_biocide_group($p['product_group']); ?>
<tr><td><strong><?= stock_h($p['name']) ?></strong></td><td><?= stock_h(stock_group_label($p['product_group'])) ?></td><td><?= stock_h($p['unit_consumption']) ?></td><td><?= stock_h(stock_package_display($p['package_qty'], $p['unit_consumption'])) ?></td><td><?= stock_h(stock_unit_display($p['min_qty'] ?? 0, $p['unit_consumption'])) ?></td><td><?= stock_h($p['aviz_no'] ?: '-') ?></td><td><?= stock_h($p['aviz_valid_until'] ?: '-') ?></td><td><?= $bio ? (!empty($p['safety_measures']) ? '<span class="stock-badge green">Completat</span>' : '<span class="stock-badge red">Lipsa</span>') : '<span class="stock-badge">N/A</span>' ?></td><td><?= (int)$p['is_active'] === 1 ? '<span class="stock-badge green">Activ</span>' : '<span class="stock-badge red">Inactiv</span>' ?></td><td><a class="btn" href="stock_products.php?edit=<?= (int)$p['id'] ?>">Editeaza</a></td></tr>
<?php endforeach; ?><?php if (!$products): ?><tr><td colspan="10">Nu exista produse.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div></main></div>
<script>
function stockParseNumber(v){v=(v||'').toString().replace(',', '.');var n=parseFloat(v);return isNaN(n)?0:n;}
function stockFmt(n){return (Math.round(n*1000)/1000).toString();}
function refreshProductForm(){var group=document.getElementById('product_group').value;var isBio=['dezinsectie','dezinfectie','deratizare'].indexOf(group)>=0;document.querySelectorAll('.js-biocide-only').forEach(function(el){el.classList.toggle('is-hidden', !isBio);});var unit=document.getElementById('unit_consumption').value;var qty=stockParseNumber(document.getElementById('package_qty').value);var preview='';if(unit==='ml'){preview='1 ambalaj = '+stockFmt(qty)+' ml / '+stockFmt(qty/1000)+' L';}else if(unit==='gr'){preview='1 ambalaj = '+stockFmt(qty)+' gr / '+stockFmt(qty/1000)+' kg';}else{preview='1 ambalaj = '+stockFmt(qty)+' buc';}document.getElementById('packagePreview').textContent=preview;}
document.getElementById('product_group').addEventListener('change', refreshProductForm);document.getElementById('unit_consumption').addEventListener('change', refreshProductForm);document.getElementById('package_qty').addEventListener('input', refreshProductForm);refreshProductForm();
</script>
</body></html>
