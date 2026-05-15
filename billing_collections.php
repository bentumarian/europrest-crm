<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/billing_lib.php';

if (!is_admin()) { header('Location: calendar.php'); exit; }

bill_ensure_schema($pdo);

$error = '';
$success = '';
$selectedClientId = (int)($_GET['client_id'] ?? 0);
$selectedDocId = (int)($_GET['document_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) csrf_require();

    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'sync_client') {
            $selectedClientId = (int)($_POST['client_id'] ?? 0);
            $res = bill_sync_client_invoices($pdo, $selectedClientId);
            if (!empty($res['ok'])) $success = 'Facturile clientului au fost sincronizate din Oblio.';
            else $error = $res['error'] ?? 'Eroare sincronizare client.';
        }
        if ($action === 'collect') {
            $res = bill_collect_invoice_from_form($pdo, $_POST);
            if (!empty($res['ok'])) $success = 'Incasarea a fost trimisa in Oblio si salvata local.';
            else $error = $res['error'] ?? 'Eroare incasare.';
            $selectedDocId = (int)($_POST['document_id'] ?? 0);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$clients = bill_clients($pdo);
$filters = ['type' => 'invoice', 'limit' => 500];
if ($selectedClientId) $filters['client_id'] = $selectedClientId;
$invoices = bill_documents($pdo, $filters);
$selectedDoc = $selectedDocId ? bill_document($pdo, $selectedDocId) : null;
$settings = oblio_settings($pdo);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Incasari / chitante - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.page{display:grid;gap:14px}.hero,.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow)}
.hero{padding:18px 20px}.hero h1{margin:0 0 5px;font-size:25px;font-weight:650;letter-spacing:-.035em}.hero p{margin:0;color:var(--muted)}
.card{overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid var(--border2);display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.card-head h2{margin:0;font-size:16px;font-weight:650}.card-body{padding:16px;display:grid;gap:14px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.grid-4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px}
input,select,textarea{width:100%;border:1px solid var(--border);border-radius:12px;padding:10px 11px;min-height:42px;background:#fff;color:var(--text);font:inherit;outline:none}textarea{min-height:80px}label{display:block;margin-bottom:5px;color:var(--muted);font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.04em}
.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:900px}th,td{border-bottom:1px solid var(--border2);padding:9px;font-size:13px;vertical-align:middle}th{background:var(--surface-soft);color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em;text-align:left}
.notice{margin:0}.badge{display:inline-flex;padding:4px 8px;border-radius:999px;border:1px solid var(--border);font-size:11px;font-weight:800}.open{background:#fffbeb;color:#92400e;border-color:#fde68a}.paid{background:#ecfdf5;color:#047857;border-color:#bbf7d0}@media(max-width:900px){.grid-2,.grid-4{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('billing_collections', true); ?>
<main class="main">
<div class="topbar"><strong>Incasari / chitante</strong></div>
<div class="content page">

<section class="hero">
<h1>Incasari / chitante prin Oblio</h1>
<p>Selectezi clientul, sincronizezi facturile din Oblio, alegi factura cu sold si trimiti incasarea in Oblio. CRM salveaza rezultatul local.</p>
</section>

<?php if ($success): ?><div class="notice notice-success"><?= bill_h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-danger"><?= bill_h($error) ?></div><?php endif; ?>

<section class="card">
<div class="card-head"><h2>1. Selecteaza client si sincronizeaza</h2><a class="btn" href="billing_documents.php">Documente</a></div>
<form method="post" class="card-body">
<?= function_exists('csrf_field') ? csrf_field() : '' ?>
<input type="hidden" name="action" value="sync_client">
<div class="grid-2">
<div><label>Client</label><select name="client_id" required><option value="">Selecteaza client</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $selectedClientId===(int)$c['id']?'selected':'' ?>><?= bill_h($c['name']) ?><?= !empty($c['fiscal_code'])?' - '.bill_h($c['fiscal_code']):'' ?></option><?php endforeach; ?></select></div>
<div style="align-self:end"><button class="btn accent" type="submit">Sincronizeaza facturile clientului</button></div>
</div>
</form>
</section>

<section class="card">
<div class="card-head"><h2>2. Facturi cu sold</h2></div>
<div class="table-wrap">
<table>
<thead><tr><th>Factura</th><th>Client</th><th>Data</th><th>Total</th><th>Incasat</th><th>Sold</th><th>Status</th><th>Actiune</th></tr></thead>
<tbody>
<?php 
$hasRows = false;
foreach($invoices as $inv):
    if ((float)$inv['balance'] <= 0) continue;
    $hasRows = true;
?>
<tr>
<td><strong><?= bill_h($inv['oblio_series']) ?> <?= bill_h($inv['oblio_number']) ?></strong></td>
<td><?= bill_h($inv['client_name'] ?: '-') ?></td>
<td><?= bill_date_ro($inv['issue_date']) ?></td>
<td><?= bill_money($inv['total'], $inv['currency']) ?></td>
<td><?= bill_money($inv['collected_total'], $inv['currency']) ?></td>
<td><strong><?= bill_money($inv['balance'], $inv['currency']) ?></strong></td>
<td><span class="badge open">Rest de incasat</span></td>
<td><a class="btn accent" href="billing_collections.php?document_id=<?= (int)$inv['id'] ?>&client_id=<?= (int)$inv['client_id'] ?>">Incaseaza</a></td>
</tr>
<?php endforeach; ?>
<?php if (!$hasRows): ?><tr><td colspan="8">Nu exista facturi cu sold pentru filtrul curent. Sincronizeaza clientul sau verifica in Oblio.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
</section>

<?php if ($selectedDoc): ?>
<section class="card">
<div class="card-head"><h2>3. Incaseaza factura <?= bill_h($selectedDoc['oblio_series']) ?> <?= bill_h($selectedDoc['oblio_number']) ?></h2></div>
<form method="post" class="card-body">
<?= function_exists('csrf_field') ? csrf_field() : '' ?>
<input type="hidden" name="action" value="collect">
<input type="hidden" name="document_id" value="<?= (int)$selectedDoc['id'] ?>">
<div class="grid-4">
<div><label>Sold disponibil</label><input value="<?= bill_h(bill_money($selectedDoc['balance'], $selectedDoc['currency'])) ?>" readonly></div>
<div><label>Suma incasata</label><input type="number" step="0.01" name="collect_value" value="<?= bill_h($selectedDoc['balance']) ?>" required></div>
<div><label>Data incasare</label><input type="date" name="collect_issue_date" value="<?= date('Y-m-d') ?>"></div>
<div><label>Tip incasare</label><select name="collect_type"><option>Chitanta</option><option>Ordin de plata</option><option>Card</option><option>Bon fiscal</option><option>Bon fiscal card</option><option>Alta incasare numerar</option><option>Alta incasare banca</option></select></div>
</div>
<div class="grid-2">
<div><label>Serie chitanta</label><input name="collect_series" value="<?= bill_h($settings['oblio.receipt_series'] ?? '') ?>" placeholder="CH"></div>
<div><label>Numar document incasare</label><input name="collect_number" placeholder="OP 123 / POS 123, pentru incasari fara chitanta"></div>
</div>
<div><label>Mentiuni</label><textarea name="collect_mentions">Incasare factura <?= bill_h($selectedDoc['oblio_series']) ?> <?= bill_h($selectedDoc['oblio_number']) ?></textarea></div>
<div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;">
<?php if(!empty($selectedDoc['pdf_path'])): ?><a class="btn" target="_blank" href="billing_documents.php?pdf=<?= (int)$selectedDoc['id'] ?>">Vezi factura</a><?php endif; ?>
<button class="btn accent" type="submit" onclick="return confirm('Trimiti incasarea in Oblio?');">Trimite incasare in Oblio</button>
</div>
</form>
</section>
<?php endif; ?>

</div>
</main>
</div>
</body>
</html>
