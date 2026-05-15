<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/billing_lib.php';

if (!is_admin()) { header('Location: calendar.php'); exit; }

bill_ensure_schema($pdo);

if (isset($_GET['pdf'])) {
    $doc = bill_document($pdo, (int)$_GET['pdf']);
    if (!$doc) { http_response_code(404); exit('Document inexistent.'); }
    bill_open_local_pdf($doc);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) csrf_require();

    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'sync_recent') {
            $days = max(1, (int)($_POST['days'] ?? 30));
            $res = bill_sync_recent_invoices($pdo, $days);
            if (!empty($res['ok'])) $success = 'Sincronizare finalizata. Facturi sincronizate: ' . (int)$res['count'];
            else $error = 'Sincronizare cu erori: ' . implode('; ', $res['errors'] ?? []);
        }
        if ($action === 'sync_client') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $res = bill_sync_client_invoices($pdo, $clientId);
            if (!empty($res['ok'])) $success = 'Client sincronizat. Facturi gasite: ' . (int)$res['count'];
            else $error = $res['error'] ?? 'Eroare sincronizare client.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$docs = bill_documents($pdo, ['q' => $q, 'type' => $type, 'limit' => 500]);
$clients = bill_clients($pdo);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Documente Oblio - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.page{display:grid;gap:14px}.hero,.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow)}
.hero{padding:18px 20px}.hero h1{margin:0 0 5px;font-size:25px;font-weight:650;letter-spacing:-.035em}.hero p{margin:0;color:var(--muted)}
.card{overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid var(--border2);display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.card-head h2{margin:0;font-size:16px;font-weight:650}.card-body{padding:16px;display:grid;gap:14px}
.filters{display:grid;grid-template-columns:1fr 170px auto auto;gap:8px;align-items:end}
input,select{width:100%;border:1px solid var(--border);border-radius:12px;padding:10px 11px;min-height:42px;background:#fff;color:var(--text);font:inherit;outline:none}label{display:block;margin-bottom:5px;color:var(--muted);font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.04em}
.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{border-bottom:1px solid var(--border2);padding:9px;font-size:13px;vertical-align:middle}th{background:var(--surface-soft);color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em;text-align:left}
.badge{display:inline-flex;padding:4px 8px;border-radius:999px;border:1px solid var(--border);font-size:11px;font-weight:800}.paid{background:#ecfdf5;color:#047857;border-color:#bbf7d0}.open{background:#fffbeb;color:#92400e;border-color:#fde68a}.cancel{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
.doc-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:nowrap;white-space:nowrap}.icon-action{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border2);border-radius:10px;background:#fff;color:#64748b;text-decoration:none;cursor:pointer;transition:.18s ease;padding:0}.icon-action:hover{border-color:var(--accent);color:var(--accent);background:rgba(14,116,144,.06)}.icon-action svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.icon-action.disabled{opacity:.38;pointer-events:none;cursor:not-allowed;background:var(--surface-soft)}.notice{margin:0}@media(max-width:900px){.filters{grid-template-columns:1fr}.doc-actions{justify-content:flex-start}}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('billing_documents', true); ?>
<main class="main">
<div class="topbar"><strong>Documente Oblio</strong></div>
<div class="content page">

<section class="hero">
<h1>Documente emise / sincronizate din Oblio</h1>
<p>Aici vezi oglinda locala din CRM. PDF-urile se deschid direct din CRM, dar datele fiscale vin din Oblio.</p>
</section>

<?php if ($success): ?><div class="notice notice-success"><?= bill_h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-danger"><?= bill_h($error) ?></div><?php endif; ?>

<section class="card">
<div class="card-head"><h2>Sincronizare</h2><a class="btn accent" href="billing.php">Emite document</a></div>
<div class="card-body">
<form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
<?= function_exists('csrf_field') ? csrf_field() : '' ?>
<input type="hidden" name="action" value="sync_recent">
<div><label>Zile inapoi</label><input name="days" type="number" value="30" style="width:120px"></div>
<button class="btn" type="submit">Sincronizeaza facturi recente</button>
</form>
<form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
<?= function_exists('csrf_field') ? csrf_field() : '' ?>
<input type="hidden" name="action" value="sync_client">
<div><label>Client</label><select name="client_id" style="min-width:280px"><option value="">Selecteaza</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= bill_h($c['name']) ?><?= !empty($c['fiscal_code'])?' - '.bill_h($c['fiscal_code']):'' ?></option><?php endforeach; ?></select></div>
<button class="btn" type="submit">Sincronizeaza client</button>
</form>
</div>
</section>

<section class="card">
<div class="card-head"><h2>Lista documente</h2><a class="btn" href="billing_collections.php">Incasari / chitante</a></div>
<form method="get" class="card-body">
<div class="filters">
<div><label>Cautare</label><input name="q" value="<?= bill_h($q) ?>" placeholder="serie, numar, client, CUI"></div>
<div><label>Tip</label><select name="type"><option value="">Toate</option><option value="invoice" <?= $type==='invoice'?'selected':'' ?>>Facturi</option><option value="proforma" <?= $type==='proforma'?'selected':'' ?>>Proforme</option></select></div>
<button class="btn accent" type="submit">Filtreaza</button>
<a class="btn" href="billing_documents.php">Reset</a>
</div>
</form>
<div class="table-wrap">
<table>
<thead><tr><th>Document</th><th>Client</th><th>Data</th><th>Total</th><th>Incasat</th><th>Sold</th><th>Status</th><th style="text-align:right;">Actiuni</th></tr></thead>
<tbody>
<?php if (!$docs): ?><tr><td colspan="8">Nu exista documente sincronizate.</td></tr><?php endif; ?>
<?php foreach($docs as $d): ?>
<tr>
<td><strong><?= bill_h(strtoupper($d['oblio_type'])) ?> <?= bill_h($d['oblio_series']) ?> <?= bill_h($d['oblio_number']) ?></strong><br><span style="color:var(--muted)">Ultima sync: <?= bill_h($d['last_synced_at'] ?: '-') ?></span></td>
<td><?= bill_h($d['client_name'] ?: '-') ?><br><span style="color:var(--muted)"><?= bill_h($d['client_fiscal_code'] ?: '') ?></span></td>
<td><?= bill_date_ro($d['issue_date']) ?></td>
<td><?= bill_money($d['total'], $d['currency']) ?></td>
<td><?= bill_money($d['collected_total'], $d['currency']) ?></td>
<td><?= bill_money($d['balance'], $d['currency']) ?></td>
<td><span class="badge <?= !empty($d['canceled']) ? 'cancel' : ((float)$d['balance'] <= 0 && (float)$d['total'] > 0 ? 'paid' : 'open') ?>"><?= bill_h($d['status'] ?: '-') ?></span></td>
<td style="text-align:right;">
    <div class="doc-actions">
        <?php if (!empty($d['pdf_path'])): ?>
            <a class="icon-action" target="_blank" href="billing_documents.php?pdf=<?= (int)$d['id'] ?>" title="PDF local" aria-label="PDF local">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h5"></path></svg>
            </a>
        <?php else: ?>
            <span class="icon-action disabled" title="PDF local indisponibil" aria-label="PDF local indisponibil">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h5"></path></svg>
            </span>
        <?php endif; ?>

        <?php if (!empty($d['link'])): ?>
            <a class="icon-action" target="_blank" href="billing_document_pdf.php?id=<?= (int)$d['id'] ?>" title="PDF CRM" aria-label="PDF CRM">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"></path><path d="M8 8h8"></path><path d="M8 12h8"></path><path d="M8 16h5"></path></svg>
            </a>
        <?php else: ?>
            <span class="icon-action disabled" title="PDF CRM indisponibil" aria-label="PDF CRM indisponibil">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"></path><path d="M8 8h8"></path><path d="M8 12h8"></path><path d="M8 16h5"></path></svg>
            </span>
        <?php endif; ?>

        <a class="icon-action" href="billing_document_email.php?id=<?= (int)$d['id'] ?>" title="Trimite email" aria-label="Trimite email">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"></path><path d="m22 6-10 7L2 6"></path></svg>
        </a>
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
</body>
</html>
