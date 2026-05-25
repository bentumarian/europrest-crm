<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';

if (!is_admin()) { header('Location: calendar.php'); exit; }
stock_ensure_schema($pdo);

$msg = '';
$err = '';

function stock_product_upload_aviz_file(array $file, ?string $oldPath = null): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fișierul de aviz nu a putut fi încărcat.');
    }
    if ((int)($file['size'] ?? 0) > 15 * 1024 * 1024) {
        throw new RuntimeException('Fișierul de aviz depășește limita de 15 MB.');
    }

    $originalName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Fișierul de aviz trebuie să fie PDF, JPG, PNG sau WEBP.');
    }

    $dir = __DIR__ . '/uploads/product_avize';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nu pot crea folderul pentru avize.');
    }

    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
    $base = trim((string)$base, '-_.') ?: 'aviz';
    $relative = 'uploads/product_avize/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $base . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], __DIR__ . '/' . $relative)) {
        throw new RuntimeException('Fișierul de aviz nu a putut fi salvat.');
    }

    if ($oldPath) {
        $oldFull = realpath(__DIR__ . '/' . ltrim($oldPath, '/\\'));
        $uploadsRoot = realpath(__DIR__ . '/uploads');
        if ($oldFull && $uploadsRoot && str_starts_with($oldFull, $uploadsRoot) && is_file($oldFull)) {
            @unlink($oldFull);
        }
    }

    return $relative;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $action = $_POST['action'] ?? '';
        if ($action === 'save_product') {
            $id = (int)($_POST['id'] ?? 0);
            $existing = $id > 0 ? stock_get_product($pdo, $id) : null;
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
                'notes' => trim((string)($_POST['notes'] ?? '')),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ];
            stock_validate_product_data($data);

            $avizFile = $existing['aviz_file'] ?? null;
            if (!empty($_POST['remove_aviz_file'])) {
                $avizFile = null;
            }
            if (!empty($_FILES['aviz_file'])) {
                $uploadedAviz = stock_product_upload_aviz_file($_FILES['aviz_file'], $avizFile);
                if ($uploadedAviz !== null) {
                    $avizFile = $uploadedAviz;
                }
            }
            $data['aviz_file'] = $avizFile;

            if (!stock_is_biocide_group($data['product_group'])) {
                $data['aviz_no'] = $data['aviz_no'] ?: null;
                $data['aviz_valid_until'] = $data['aviz_valid_until'] ?: null;
            }

            if ($id > 0) {
                $sql = "UPDATE stock_products SET name=:name, product_group=:product_group, unit_consumption=:unit_consumption, package_qty=:package_qty, min_qty=:min_qty, aviz_no=:aviz_no, aviz_valid_until=:aviz_valid_until, active_substance=:active_substance, product_concentration=:product_concentration, contact_time=:contact_time, default_application_method=:default_application_method, aviz_file=:aviz_file, notes=:notes, is_active=:is_active, updated_at=NOW() WHERE id=:id";
                $data['id'] = $id;
                $pdo->prepare($sql)->execute($data);
                $msg = 'Produs actualizat.';
            } else {
                $sql = "INSERT INTO stock_products (name, product_group, unit_consumption, package_qty, min_qty, aviz_no, aviz_valid_until, active_substance, product_concentration, contact_time, default_application_method, aviz_file, notes, is_active, created_at) VALUES (:name, :product_group, :unit_consumption, :package_qty, :min_qty, :aviz_no, :aviz_valid_until, :active_substance, :product_concentration, :contact_time, :default_application_method, :aviz_file, :notes, :is_active, NOW())";
                $pdo->prepare($sql)->execute($data);
                $msg = 'Produs adăugat.';
            }
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId > 0 ? stock_get_product($pdo, $editId) : null;

// Filtre listă produse
$filterSearch = trim((string)($_GET['q'] ?? ''));
$filterGroup = trim((string)($_GET['group'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? '')); // '', 'active', 'inactive'
$where = ['1=1'];
$params = [];
if ($filterSearch !== '') {
    $where[] = '(name LIKE ? OR active_substance LIKE ? OR aviz_no LIKE ?)';
    $like = '%' . $filterSearch . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterGroup !== '' && array_key_exists($filterGroup, stock_group_options())) {
    $where[] = 'product_group = ?';
    $params[] = $filterGroup;
}
if ($filterStatus === 'active') {
    $where[] = 'is_active = 1';
} elseif ($filterStatus === 'inactive') {
    $where[] = 'is_active = 0';
}
$sqlList = 'SELECT * FROM stock_products WHERE ' . implode(' AND ', $where) . ' ORDER BY is_active DESC, product_group ASC, name ASC';
$stmtList = $pdo->prepare($sqlList);
$stmtList->execute($params);
$products = $stmtList->fetchAll(PDO::FETCH_ASSOC);
$groups = stock_group_options();
$units = stock_unit_options();

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Produse gestiune</title>
</head><body><div class="layout"><?php render_sidebar('stock_products', true); ?><main class="main"><div class="content">
<div class="stock-hero"><div><h1>Nomenclator produse</h1><p>Produse și materiale DDD, cu stoc minim și măsuri de siguranță pentru PV.</p></div><div class="stock-actions"><a class="btn" href="avize_sanitare.php" target="_blank" rel="noopener">Pagina publică avize</a><?php if ($edit): ?><a class="btn" href="stock_products.php">Anulează editarea</a><?php endif; ?></div></div>
<?php render_stock_module_nav('products'); ?>
<?php if ($msg): ?><div class="notice notice-success"><?= stock_h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice notice-danger"><?= stock_h($err) ?></div><?php endif; ?>
<form class="stock-card" method="post" id="productForm" enctype="multipart/form-data">
<?= csrf_field() ?><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<h2 style="margin:0 0 14px;font-size:18px;"><?= $edit ? 'Editează produs' : 'Adaugă produs' ?></h2>
<div class="stock-grid">
    <div class="stock-field"><label>Denumire produs *</label><input name="name" required value="<?= stock_h($edit['name'] ?? '') ?>" placeholder="Ex: K-Othrine SC 25"></div>
    <div class="stock-field"><label>Grupă produs *</label><select name="product_group" id="product_group" required><?php foreach ($groups as $k => $v): ?><option value="<?= stock_h($k) ?>" <?= (($edit['product_group'] ?? 'materiale') === $k ? 'selected' : '') ?>><?= stock_h($v) ?></option><?php endforeach; ?></select><small>Pentru Dezinsecție / Dezinfecție / Deratizare sunt obligatorii avizul, valabilitatea și măsurile de siguranță.</small></div>
</div>
<div class="stock-grid-3" style="margin-top:14px;">
    <div class="stock-field"><label>Unitate consum *</label><select name="unit_consumption" id="unit_consumption" required><?php foreach ($units as $k => $v): ?><option value="<?= stock_h($k) ?>" <?= (($edit['unit_consumption'] ?? 'buc') === $k ? 'selected' : '') ?>><?= stock_h($v) ?></option><?php endforeach; ?></select></div>
    <div class="stock-field"><label>Cantitate per ambalaj *</label><input name="package_qty" id="package_qty" required value="<?= stock_h($edit['package_qty'] ?? '1') ?>" inputmode="decimal"><small id="packagePreview"></small></div>
    <div class="stock-field"><label>Stoc minim</label><input name="min_qty" id="min_qty" value="<?= stock_h($edit['min_qty'] ?? '0') ?>" inputmode="decimal"><small>Se exprimă în unitatea de consum: ml / gr / buc. Când stocul ajunge la acest nivel, apare alerta în Notificări.</small></div>
</div>
<div class="stock-grid js-biocide-only" style="margin-top:14px;">
    <div class="stock-field"><label>Număr aviz *</label><input name="aviz_no" value="<?= stock_h($edit['aviz_no'] ?? '') ?>" placeholder="Număr act administrativ / aviz"></div>
    <div class="stock-field"><label>Valabilitate aviz *</label><input type="date" name="aviz_valid_until" value="<?= stock_h($edit['aviz_valid_until'] ?? '') ?>"></div>
</div>
<div class="stock-field js-biocide-only" style="margin-top:14px;">
    <label>Fișier aviz sanitar public</label>
    <input type="file" name="aviz_file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*">
    <small>PDF/JPG/PNG/WEBP, maxim 15 MB. Fișierul apare în pagina publică de avize pentru clienți.</small>
    <?php if (!empty($edit['aviz_file'])): ?>
        <div style="margin-top:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <a class="btn" href="avize_sanitare.php?download=<?= (int)($edit['id'] ?? 0) ?>" target="_blank" rel="noopener">Descarcă avizul curent</a>
            <label style="display:flex;gap:7px;align-items:center;margin:0;text-transform:none;letter-spacing:0;font-size:13px;"><input type="checkbox" name="remove_aviz_file" value="1" style="width:auto;min-height:auto;"> Șterge fișierul curent</label>
        </div>
    <?php endif; ?>
</div>
<div class="stock-grid-4 js-biocide-only" style="margin-top:14px;">
    <div class="stock-field"><label>Substanță activă</label><input name="active_substance" value="<?= stock_h($edit['active_substance'] ?? '') ?>"></div>
    <div class="stock-field"><label>Concentrație produs</label><input name="product_concentration" value="<?= stock_h($edit['product_concentration'] ?? '') ?>"></div>
    <div class="stock-field"><label>Timp contact / acțiune</label><input name="contact_time" value="<?= stock_h($edit['contact_time'] ?? '') ?>"></div>
    <div class="stock-field"><label>Metodă aplicare implicită</label>
        <?php
            $applicationMethods = [
                ''                  => 'Alege',
                'pulverizare'       => 'Pulverizare',
                'aplicare directa'  => 'Aplicare directă',
                'nebulizare'        => 'Nebulizare',
                'amplasare'         => 'Amplasare',
                'momeala'           => 'Momeală',
            ];
            $rawMethod = (string)($edit['default_application_method'] ?? '');
            // Normalizez valorile vechi (text liber) catre cele 5 standard din PV
            $lc = mb_strtolower($rawMethod, 'UTF-8');
            if (strpos($lc, 'pulver') !== false) {
                $currentMethod = 'pulverizare';
            } elseif (strpos($lc, 'nebul') !== false) {
                $currentMethod = 'nebulizare';
            } elseif (strpos($lc, 'amplas') !== false) {
                $currentMethod = 'amplasare';
            } elseif (strpos($lc, 'direct') !== false) {
                $currentMethod = 'aplicare directa';
            } elseif (strpos($lc, 'momea') !== false) {
                $currentMethod = 'momeala';
            } else {
                $currentMethod = '';
            }
        ?>
        <select name="default_application_method">
            <?php foreach ($applicationMethods as $value => $label): ?>
                <option value="<?= stock_h($value) ?>" <?= $currentMethod === $value ? 'selected' : '' ?>><?= stock_h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <small>Apare ca metodă implicită în PV când selectezi acest produs.</small>
    </div>
</div>
<div class="stock-field" style="margin-top:14px;"><label>Observații interne</label><textarea name="notes" rows="2"><?= stock_h($edit['notes'] ?? '') ?></textarea></div>
<div class="actions-row"><label style="display:flex;gap:8px;align-items:center;margin:0;text-transform:none;letter-spacing:0;font-size:14px;"><input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto;" <?= ((int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '') ?>> Produs activ</label><div class="stock-actions"><a class="btn" href="stock_products.php">Curăță</a><button class="btn accent" type="submit">Salvează produs</button></div></div>
</form>
<form class="stock-card" method="get" style="margin-bottom:0;">
    <h2 style="margin:0 0 14px;font-size:18px;">Caută în nomenclator</h2>
    <div class="stock-grid-3">
        <div class="stock-field"><label>Căutare</label><input type="text" name="q" value="<?= stock_h($filterSearch) ?>" placeholder="Denumire, substanță activă, aviz..." autocomplete="off"></div>
        <div class="stock-field"><label>Grupă</label><select name="group" onchange="this.form.submit()"><option value="">Toate grupele</option><?php foreach ($groups as $k => $v): ?><option value="<?= stock_h($k) ?>" <?= $filterGroup === $k ? 'selected' : '' ?>><?= stock_h($v) ?></option><?php endforeach; ?></select></div>
        <div class="stock-field"><label>Status</label><select name="status" onchange="this.form.submit()"><option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Toate</option><option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Doar active</option><option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Doar inactive</option></select></div>
    </div>
    <div class="actions-row"><a class="btn" href="stock_products.php">Resetează</a><button type="submit" class="btn accent">Caută</button></div>
</form>
<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Listă produse (<?= count($products) ?>)</h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Produs</th><th>Grupă</th><th>UM</th><th>Ambalaj</th><th>Stoc minim</th><th>Aviz</th><th>Valabilitate</th><th>Status</th><th>Acțiuni</th></tr></thead><tbody>
<?php foreach ($products as $p): ?>
<tr><td><strong><?= stock_h($p['name']) ?></strong></td><td><?= stock_h(stock_group_label($p['product_group'])) ?></td><td><?= stock_h($p['unit_consumption']) ?></td><td><?= stock_h(stock_package_display($p['package_qty'], $p['unit_consumption'])) ?></td><td><?= stock_h(stock_unit_display($p['min_qty'] ?? 0, $p['unit_consumption'])) ?></td><td><?= stock_h($p['aviz_no'] ?: '-') ?></td><td><?= stock_h($p['aviz_valid_until'] ?: '-') ?></td><td><?= (int)$p['is_active'] === 1 ? '<span class="stock-badge green">Activ</span>' : '<span class="stock-badge red">Inactiv</span>' ?></td><td><a class="btn" href="stock_products.php?edit=<?= (int)$p['id'] ?>">Editează</a></td></tr>
<?php endforeach; ?><?php if (!$products): ?><tr><td colspan="9">Niciun produs nu corespunde filtrelor.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div></main></div>
<script>
function stockParseNumber(v){v=(v||'').toString().replace(',', '.');var n=parseFloat(v);return isNaN(n)?0:n;}
function stockFmt(n){return (Math.round(n*1000)/1000).toString();}
function refreshProductForm(){var group=document.getElementById('product_group').value;var isBio=['dezinsectie','dezinfectie','deratizare'].indexOf(group)>=0;document.querySelectorAll('.js-biocide-only').forEach(function(el){el.classList.toggle('is-hidden', !isBio);});var unit=document.getElementById('unit_consumption').value;var qty=stockParseNumber(document.getElementById('package_qty').value);var preview='';if(unit==='ml'){preview='1 ambalaj = '+stockFmt(qty)+' ml / '+stockFmt(qty/1000)+' L';}else if(unit==='gr'){preview='1 ambalaj = '+stockFmt(qty)+' gr / '+stockFmt(qty/1000)+' kg';}else{preview='1 ambalaj = '+stockFmt(qty)+' buc';}document.getElementById('packagePreview').textContent=preview;}
document.getElementById('product_group').addEventListener('change', refreshProductForm);document.getElementById('unit_consumption').addEventListener('change', refreshProductForm);document.getElementById('package_qty').addEventListener('input', refreshProductForm);refreshProductForm();
</script>
</body></html>
