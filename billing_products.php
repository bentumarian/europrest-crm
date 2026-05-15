<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/billing_lib.php';

if (!is_admin()) { header('Location: calendar.php'); exit; }

bill_ensure_schema($pdo);

$error = '';
$success = '';
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) csrf_require();

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_product') {
            $id = bill_save_product($pdo, $_POST);
            $success = $id ? 'Produsul/serviciul a fost salvat.' : 'Salvat.';
        }

        if ($action === 'delete_product') {
            $result = bill_delete_or_deactivate_product($pdo, (int)($_POST['id'] ?? 0));
            $success = $result === 'deleted' ? 'Produsul/serviciul a fost sters.' : 'Produsul/serviciul a fost dezactivat, deoarece a fost folosit.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['edit'])) {
    $edit = bill_product($pdo, (int)$_GET['edit']);
}

$products = bill_products($pdo, false);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Nomenclator facturare - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.page{display:grid;gap:14px}.hero,.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow)}
.hero{padding:18px 20px}.hero h1{margin:0 0 5px;font-size:25px;font-weight:650;letter-spacing:-.035em}.hero p{margin:0;color:var(--muted)}
.card{overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid var(--border2);display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.card-head h2{margin:0;font-size:16px;font-weight:650}.card-body{padding:16px;display:grid;gap:14px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.grid-4{display:grid;grid-template-columns:1.4fr .7fr .7fr .7fr;gap:10px}
label{display:block;margin-bottom:5px;color:var(--muted);font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.04em}
input,select,textarea{width:100%;border:1px solid var(--border);border-radius:12px;padding:10px 11px;min-height:42px;background:#fff;color:var(--text);font:inherit;outline:none}textarea{min-height:80px}
.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:900px}th,td{border-bottom:1px solid var(--border2);padding:9px;font-size:13px;vertical-align:middle}th{background:var(--surface-soft);color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em;text-align:left}
.badge{display:inline-flex;padding:4px 8px;border-radius:999px;border:1px solid var(--border);font-size:11px;font-weight:800}.ok{background:#ecfdf5;color:#047857;border-color:#bbf7d0}.off{background:#f8fafc;color:#64748b}
.actions{display:flex;gap:6px;flex-wrap:wrap}.notice{margin:0}@media(max-width:900px){.grid-2,.grid-4{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('billing_products', true); ?>
<main class="main">
<div class="topbar"><strong>Nomenclator produse / servicii</strong></div>
<div class="content page">
<section class="hero">
<h1>Nomenclator produse / servicii</h1>
<p>Aceste produse sunt folosite doar pentru completare rapida in CRM. Documentele fiscale se emit in Oblio.</p>
</section>

<?php if ($success): ?><div class="notice notice-success"><?= bill_h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-danger"><?= bill_h($error) ?></div><?php endif; ?>

<section class="card">
<div class="card-head"><h2><?= $edit ? 'Editeaza produs / serviciu' : 'Adauga produs / serviciu' ?></h2></div>
<form method="post" class="card-body">
<?= function_exists('csrf_field') ? csrf_field() : '' ?>
<input type="hidden" name="action" value="save_product">
<input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

<div class="grid-2">
    <div><label>Denumire</label><input name="name" required value="<?= bill_h($edit['name'] ?? '') ?>" placeholder="Servicii DDD conform contract"></div>
    <div><label>Tip</label><select name="product_type"><option value="Serviciu" <?= (($edit['product_type'] ?? '') !== 'Marfa') ? 'selected' : '' ?>>Serviciu</option><option value="Marfa" <?= (($edit['product_type'] ?? '') === 'Marfa') ? 'selected' : '' ?>>Marfa</option></select></div>
</div>
<div class="grid-4">
    <div><label>Cod intern</label><input name="code" value="<?= bill_h($edit['code'] ?? '') ?>"></div>
    <div><label>UM</label><input name="measuring_unit" value="<?= bill_h($edit['measuring_unit'] ?? 'buc') ?>"></div>
    <div><label>Pret implicit</label><input type="number" step="0.01" name="default_price" value="<?= bill_h($edit['default_price'] ?? '') ?>"></div>
    <div><label>Moneda</label><input name="currency" value="<?= bill_h($edit['currency'] ?? 'RON') ?>"></div>
</div>
<div class="grid-4">
    <div><label>TVA</label><select name="vat_combo" onchange="setVatFromCombo(this)">
        <?php foreach (bill_vat_options($pdo) as $vat): ?>
            <?php $selectedVat = (($edit['vat_name'] ?? 'Normala') === $vat['name'] && (float)($edit['vat_percentage'] ?? 21) === (float)$vat['percentage']); ?>
            <option value="<?= bill_h($vat['name'] . '|' . $vat['percentage']) ?>" <?= $selectedVat ? 'selected' : '' ?>><?= bill_h($vat['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="vat_name" id="vatName" value="<?= bill_h($edit['vat_name'] ?? 'Normala') ?>">
    <input type="hidden" name="vat_percentage" id="vatPercentage" value="<?= bill_h($edit['vat_percentage'] ?? '21') ?>"></div>
    <div><label>Pret include TVA</label><select name="vat_included"><option value="0" <?= empty($edit['vat_included']) ? 'selected' : '' ?>>Nu</option><option value="1" <?= !empty($edit['vat_included']) ? 'selected' : '' ?>>Da</option></select></div>
    <div><label>Status</label><select name="active"><option value="1" <?= (($edit['active'] ?? 1) ? 'selected' : '') ?>>Activ</option><option value="0" <?= (isset($edit['active']) && !$edit['active']) ? 'selected' : '' ?>>Inactiv</option></select></div>
</div>
<div><label>Descriere</label><textarea name="description"><?= bill_h($edit['description'] ?? '') ?></textarea></div>
<div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;">
    <?php if ($edit): ?><a class="btn" href="billing_products.php">Anuleaza editarea</a><?php endif; ?>
    <button class="btn accent" type="submit">Salveaza</button>
</div>
</form>
</section>

<section class="card">
<div class="card-head"><h2>Produse / servicii existente</h2><a class="btn" href="billing.php">Emite document</a></div>
<div class="table-wrap">
<table>
<thead><tr><th>Denumire</th><th>Tip</th><th>UM</th><th>Pret</th><th>TVA</th><th>Status</th><th>Actiuni</th></tr></thead>
<tbody>
<?php if (!$products): ?>
<tr><td colspan="7">Nu exista produse/servicii.</td></tr>
<?php endif; ?>
<?php foreach ($products as $p): ?>
<tr>
<td><strong><?= bill_h($p['name']) ?></strong><br><span style="color:var(--muted)"><?= bill_h($p['description'] ?? '') ?></span></td>
<td><?= bill_h($p['product_type']) ?></td>
<td><?= bill_h($p['measuring_unit']) ?></td>
<td><?= $p['default_price'] !== null ? bill_money($p['default_price'], $p['currency']) : '-' ?></td>
<td><?= bill_h($p['vat_name']) ?> <?= bill_h($p['vat_percentage']) ?>%</td>
<td><span class="badge <?= !empty($p['active']) ? 'ok' : 'off' ?>"><?= !empty($p['active']) ? 'Activ' : 'Inactiv' ?></span></td>
<td>
<div class="actions">
<a class="btn" href="billing_products.php?edit=<?= (int)$p['id'] ?>">Editeaza</a>
<form method="post" onsubmit="return confirm('Stergi/dezactivezi acest produs?');">
<?= function_exists('csrf_field') ? csrf_field() : '' ?>
<input type="hidden" name="action" value="delete_product">
<input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
<button class="btn danger" type="submit">Sterge</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

</div>
</main>
</div>

<script>
function setVatFromCombo(sel){
    const parts=String(sel.value||'Normala|21').split('|');
    document.getElementById('vatName').value=parts[0]||'Normala';
    document.getElementById('vatPercentage').value=parts[1]||'21';
}
document.addEventListener('DOMContentLoaded',function(){const s=document.querySelector('[name="vat_combo"]'); if(s) setVatFromCombo(s);});
</script>
</body>
</html>
