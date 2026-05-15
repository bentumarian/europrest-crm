<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/billing_lib.php';

if (!is_admin()) { header('Location: calendar.php'); exit; }

bill_ensure_schema($pdo);

$error = '';
$success = '';
$createdDocumentId = 0;
$draftId = (int)($_GET['draft_id'] ?? $_POST['draft_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) csrf_require();
    $action = (string)($_POST['action'] ?? 'save_draft');

    try {
        if ($action === 'save_draft' || $action === 'issue_draft') {
            $save = bill_save_draft_from_form($pdo, $_POST);
            if (empty($save['ok'])) {
                $error = $save['error'] ?? 'Eroare salvare schita.';
            } else {
                $draftId = (int)$save['draft_id'];
                if ($action === 'issue_draft') {
                    $issue = bill_issue_draft_to_oblio($pdo, $draftId);
                    if (empty($issue['ok'])) {
                        $error = $issue['error'] ?? 'Eroare emitere in Oblio.';
                    } else {
                        $createdDocumentId = (int)$issue['document_id'];
                        $success = 'Documentul a fost emis in Oblio si salvat local in CRM.';
                    }
                } else {
                    $success = 'Schita a fost salvata.';
                }
            }
        }

        if ($action === 'delete_draft') {
            if (bill_delete_draft($pdo, (int)($_POST['draft_id'] ?? 0))) {
                $success = 'Schita a fost stearsa.';
                $draftId = 0;
            } else {
                $error = 'Schita nu poate fi stearsa.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$draft = $draftId > 0 ? bill_draft($pdo, $draftId) : null;
$draftItems = $draft ? bill_draft_items($pdo, (int)$draft['id']) : [];
$clients = bill_clients($pdo);
$products = bill_products($pdo, true);
$settings = oblio_settings($pdo);
$vatOptions = bill_vat_options($pdo);
$drafts = bill_drafts($pdo, ['status' => 'draft']);

$contractsByClient = [];
foreach ($clients as $c) {
    $contractsByClient[(int)$c['id']] = bill_contracts_for_client($pdo, (int)$c['id']);
}

if (!$draftItems) {
    $draftItems = [[
        'product_id' => '',
        'name' => '',
        'description' => '',
        'quantity' => '1',
        'measuring_unit' => 'buc',
        'unit_price' => '',
        'vat_name' => 'Normala',
        'vat_percentage' => '21',
    ]];
}

$selectedClientId = (int)($draft['client_id'] ?? ($_GET['client_id'] ?? 0));
$selectedContractId = (int)($draft['contract_id'] ?? ($_GET['contract_id'] ?? 0));
$selectedClientName = '';
foreach ($clients as $c) {
    if ((int)$c['id'] === $selectedClientId) {
        $selectedClientName = trim((string)$c['name']) . (!empty($c['fiscal_code']) ? ' - ' . trim((string)$c['fiscal_code']) : '');
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Facturare - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.page{display:grid;gap:14px}.hero,.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow)}
.hero{padding:18px 20px}.hero h1{margin:0 0 5px;font-size:25px;font-weight:650;letter-spacing:-.035em}.hero p{margin:0;color:var(--muted)}
.card{overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid var(--border2);display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.card-head h2{margin:0;font-size:16px;font-weight:650}.card-body{padding:16px;display:grid;gap:14px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}.grid-4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px}
label{display:block;margin-bottom:5px;color:var(--muted);font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.04em}
input,select,textarea{width:100%;border:1px solid var(--border);border-radius:12px;padding:10px 11px;min-height:42px;background:#fff;color:var(--text);font:inherit;outline:none}textarea{min-height:85px}
.check{display:flex;align-items:center;gap:8px;font-weight:700}.check input{width:18px;height:18px;min-height:0}.notice{margin:0}.muted{color:var(--muted)}
.client-search{position:relative}.search-results{position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--border);box-shadow:0 14px 36px rgba(15,23,42,.16);border-radius:14px;max-height:270px;overflow:auto;z-index:40;display:none}.search-results.open{display:block}.search-item{padding:10px 11px;cursor:pointer;border-bottom:1px solid var(--border2)}.search-item:hover{background:var(--surface-soft)}.search-title{font-weight:800}.search-sub{font-size:12px;color:var(--muted);margin-top:2px}
.items{overflow:auto;border:1px solid var(--border2);border-radius:14px}table{width:100%;border-collapse:collapse;min-width:1120px}th,td{border-bottom:1px solid var(--border2);padding:8px;vertical-align:top}th{background:var(--surface-soft);color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em;text-align:left}.totals{display:flex;justify-content:flex-end}.totals-box{width:min(420px,100%);display:grid;gap:8px}.totals-row{display:flex;justify-content:space-between;gap:16px}.totals-row strong{font-size:18px}.status-pill{display:inline-flex;padding:5px 9px;border-radius:999px;border:1px solid var(--border);font-size:12px;font-weight:800;background:#f8fafc}.status-pill.issued{background:#ecfdf5;color:#047857;border-color:#bbf7d0}.actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}.draft-list{display:grid;gap:8px}.draft-item{border:1px solid var(--border2);border-radius:12px;padding:10px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:10px}.hidden{display:none!important}
@media(max-width:900px){.grid-2,.grid-3,.grid-4{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('billing', true); ?>
<main class="main">
<div class="topbar"><strong>Facturare</strong></div>
<div class="content page">

<section class="hero">
<h1>Facturare Oblio</h1>
<p>Creezi mai intai o schita in CRM, apoi o convertesti in factura sau proforma in Oblio. Oblio ramane sursa fiscala oficiala.</p>
</section>

<?php if ($success): ?>
<div class="notice notice-success">
<?= bill_h($success) ?>
<?php if ($createdDocumentId): ?> · <a href="billing_documents.php?view=<?= (int)$createdDocumentId ?>">Vezi document</a><?php endif; ?>
</div>
<?php endif; ?>
<?php if ($error): ?><div class="notice notice-danger"><?= bill_h($error) ?></div><?php endif; ?>

<form method="post" class="card" id="billingForm">
<?= function_exists('csrf_field') ? csrf_field() : '' ?>
<input type="hidden" name="draft_id" value="<?= (int)($draft['id'] ?? 0) ?>">
<input type="hidden" name="client_id" id="clientId" value="<?= (int)$selectedClientId ?>">
<div class="card-head">
    <h2>Date document</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <span class="status-pill <?= (($draft['status'] ?? 'draft') === 'issued') ? 'issued' : '' ?>"><?= (($draft['status'] ?? 'draft') === 'issued') ? 'Emis' : 'Schita' ?></span>
        <a class="btn" href="billing_documents.php">Documente emise</a>
    </div>
</div>
<div class="card-body">

<div class="grid-4">
<div>
<label>Tip document</label>
<select name="document_type" id="documentType">
    <option value="invoice" <?= (($draft['document_type'] ?? 'invoice') === 'invoice') ? 'selected' : '' ?>>Factura</option>
    <option value="proforma" <?= (($draft['document_type'] ?? '') === 'proforma') ? 'selected' : '' ?>>Proforma</option>
</select>
</div>
<div><label>Data emitere</label><input type="date" name="issue_date" value="<?= bill_h($draft['issue_date'] ?? date('Y-m-d')) ?>"></div>
<div><label>Data scadenta</label><input type="date" name="due_date" value="<?= bill_h($draft['due_date'] ?? date('Y-m-d', strtotime('+' . (int)($settings['oblio.default_due_days'] ?? 15) . ' days'))) ?>"></div>
<div><label>Valuta</label><input name="currency" value="<?= bill_h($draft['currency'] ?? 'RON') ?>"></div>
</div>

<div class="grid-2">
<div class="client-search">
<label>Client CRM</label>
<input type="text" id="clientSearch" autocomplete="off" value="<?= bill_h($selectedClientName) ?>" placeholder="Cauta dupa nume, CUI, telefon, email">
<div class="search-results" id="clientResults"></div>
</div>
<div>
<label>Contract optional</label>
<select name="contract_id" id="contractSelect"></select>
</div>
</div>

<label class="check"><input type="checkbox" name="invoice_by_contract" value="1" <?= !empty($draft['invoice_by_contract']) || !$draft ? 'checked' : '' ?>> Facturare conform contract, daca exista contract selectat</label>
<p class="muted" id="contractHint" style="margin:0"></p>

<div>
<label>Produse / servicii</label>
<div class="items">
<table>
<thead><tr><th style="width:210px">Nomenclator</th><th style="width:220px">Produs sau serviciu</th><th>Descriere</th><th style="width:100px">Cant.</th><th style="width:90px">UM</th><th style="width:130px">Pret unitar</th><th style="width:160px">TVA</th><th style="width:120px">Total</th><th style="width:90px"></th></tr></thead>
<tbody id="itemsBody"></tbody>
</table>
</div>
<button class="btn" type="button" onclick="addRow()">+ Adauga rand</button>
</div>

<div><label>Mentiuni</label><textarea name="mentions" id="mentions" placeholder="Mentiuni pe document"><?= bill_h($draft['mentions'] ?? '') ?></textarea></div>

<div class="totals"><div class="totals-box">
    <div class="totals-row"><span>Subtotal</span><span id="subtotalText">0,00 RON</span></div>
    <div class="totals-row"><span>TVA estimat</span><span id="vatText">0,00 RON</span></div>
    <div class="totals-row"><strong>Total</strong><strong id="totalText">0,00 RON</strong></div>
</div></div>

<div class="actions">
    <a class="btn" href="billing_products.php">Nomenclator</a>
    <?php if ($draft && ($draft['status'] ?? '') !== 'issued'): ?>
        <button class="btn danger" type="submit" name="action" value="delete_draft" onclick="return confirm('Stergi aceasta schita?');">Sterge schita</button>
    <?php endif; ?>
    <?php if (!$draft || ($draft['status'] ?? '') !== 'issued'): ?>
        <button class="btn" type="submit" name="action" value="save_draft">Salveaza schita</button>
        <button class="btn accent" type="submit" name="action" value="issue_draft" onclick="return confirm('Convertesti schita in document Oblio? Numarul fiscal va fi generat de Oblio.');">Converteste in Oblio</button>
    <?php endif; ?>
    <?php if ($draft && ($draft['status'] ?? '') === 'issued' && !empty($draft['issued_document_id'])): ?>
        <a class="btn accent" href="billing_documents.php?view=<?= (int)$draft['issued_document_id'] ?>">Vezi document emis</a>
    <?php endif; ?>
</div>

</div>
</form>

<?php if ($drafts): ?>
<section class="card">
<div class="card-head"><h2>Schite recente</h2><a class="btn" href="billing.php">Schita noua</a></div>
<div class="card-body draft-list">
<?php foreach (array_slice($drafts, 0, 8) as $d): ?>
    <div class="draft-item">
        <div>
            <strong><?= bill_h(($d['document_type'] === 'invoice' ? 'Factura' : 'Proforma') . ' schita #' . $d['id']) ?></strong><br>
            <span class="muted"><?= bill_h($d['client_name'] ?? '-') ?> · <?= bill_money($d['total'], $d['currency'] ?? 'RON') ?></span>
        </div>
        <a class="btn" href="billing.php?draft_id=<?= (int)$d['id'] ?>">Deschide</a>
    </div>
<?php endforeach; ?>
</div>
</section>
<?php endif; ?>

</div>
</main>
</div>

<script>
const CLIENTS = <?= json_encode($clients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CONTRACTS_BY_CLIENT = <?= json_encode($contractsByClient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const PRODUCTS = <?= json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const VAT_OPTIONS = <?= json_encode($vatOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_ITEMS = <?= json_encode($draftItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_CLIENT_ID = <?= (int)$selectedClientId ?>;
const INITIAL_CONTRACT_ID = <?= (int)$selectedContractId ?>;

function fmtMoney(v){return (Number(v)||0).toLocaleString('ro-RO',{minimumFractionDigits:2,maximumFractionDigits:2})+' RON';}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function norm(s){return String(s??'').toLowerCase();}

function renderClientResults(q=''){
    const box=document.getElementById('clientResults');
    const query=norm(q).trim();
    let rows=CLIENTS.filter(c=>{
        if(!query) return true;
        return norm(c.name).includes(query)||norm(c.fiscal_code).includes(query)||norm(c.phone).includes(query)||norm(c.email).includes(query);
    }).slice(0,50);
    box.innerHTML=rows.map(c=>`<div class="search-item" onclick="selectClient(${Number(c.id)})"><div class="search-title">${esc(c.name)}</div><div class="search-sub">${esc(c.fiscal_code||'')} ${c.email?' · '+esc(c.email):''} ${c.phone?' · '+esc(c.phone):''}</div></div>`).join('') || '<div class="search-item muted">Niciun client gasit</div>';
    box.classList.add('open');
}
function selectClient(id){
    const c=CLIENTS.find(x=>Number(x.id)===Number(id));
    if(!c) return;
    document.getElementById('clientId').value=id;
    document.getElementById('clientSearch').value=(c.name||'')+(c.fiscal_code?' - '+c.fiscal_code:'');
    document.getElementById('clientResults').classList.remove('open');
    renderContracts(id,true);
}
function renderContracts(clientId, autoSelect){
    const select=document.getElementById('contractSelect');
    const rows=CONTRACTS_BY_CLIENT[String(clientId)]||[];
    select.innerHTML='<option value="">Fara contract</option>'+rows.map(ct=>`<option value="${Number(ct.id)}">${esc(contractLabel(ct))}</option>`).join('');
    if(INITIAL_CONTRACT_ID && rows.some(ct=>Number(ct.id)===INITIAL_CONTRACT_ID)) select.value=String(INITIAL_CONTRACT_ID);
    else if(autoSelect && rows.length) select.value=String(rows[0].id);
    updateContractHint();
}
function contractLabel(ct){
    let nr=ct.contract_number||('Contract #'+ct.id);
    let dt=ct.contract_date||ct.start_date||'';
    if(dt){ const p=String(dt).split('-'); if(p.length===3) dt=p[2]+'.'+p[1]+'.'+p[0]; }
    return dt ? nr+' / '+dt : nr;
}
function updateContractHint(){
    const clientId=document.getElementById('clientId').value;
    const rows=CONTRACTS_BY_CLIENT[String(clientId)]||[];
    const id=Number(document.getElementById('contractSelect').value||0);
    const ct=rows.find(x=>Number(x.id)===id);
    document.getElementById('contractHint').textContent=ct ? 'Pe document se va transmite mentiunea: Facturare conf. contract '+contractLabel(ct) : 'Nu este selectat niciun contract.';
}

function productOptions(selected){
    return '<option value="">Manual</option>'+PRODUCTS.map(p=>`<option value="${Number(p.id)}" ${Number(selected||0)===Number(p.id)?'selected':''}>${esc(p.name)}</option>`).join('');
}
function vatOptions(selectedName, selectedPct){
    return VAT_OPTIONS.map(v=>{
        const val=String(v.name)+'|'+String(v.percentage);
        const sel=(String(v.name)===String(selectedName||'Normala') && Number(v.percentage)===Number(selectedPct||21))?'selected':'';
        return `<option value="${esc(val)}" ${sel}>${esc(v.label||v.name+' / '+v.percentage+'%')}</option>`;
    }).join('');
}
function addRow(item={}){
    const body=document.getElementById('itemsBody');
    const tr=document.createElement('tr');
    tr.innerHTML=`
        <td><select name="item_product_id[]" onchange="fillProduct(this)">${productOptions(item.product_id)}</select></td>
        <td><input name="item_name[]" required value="${esc(item.name||'')}" placeholder="Servicii DDD"></td>
        <td><input name="item_description[]" value="${esc(item.description||'')}" placeholder="Descriere suplimentara"></td>
        <td><input name="item_quantity[]" type="number" step="0.001" value="${esc(item.quantity||'1')}" oninput="calcTotals()"></td>
        <td><input name="item_unit[]" value="${esc(item.measuring_unit||item.measuringUnit||'buc')}"></td>
        <td><input name="item_price[]" type="number" step="0.01" value="${esc(item.unit_price||item.price||'')}" oninput="calcTotals()"></td>
        <td><select name="item_vat_key[]" onchange="calcTotals()">${vatOptions(item.vat_name||item.vatName||'Normala', item.vat_percentage||item.vatPercentage||21)}</select></td>
        <td class="line-total">0,00 RON</td>
        <td><button class="btn" type="button" onclick="removeRow(this)">Sterge</button></td>`;
    body.appendChild(tr);
    calcTotals();
}
function fillProduct(sel){
    const p=PRODUCTS.find(x=>Number(x.id)===Number(sel.value));
    if(!p) return;
    const tr=sel.closest('tr');
    tr.querySelector('[name="item_name[]"]').value=p.name||'';
    tr.querySelector('[name="item_description[]"]').value=p.description||'';
    tr.querySelector('[name="item_unit[]"]').value=p.measuring_unit||'buc';
    if(p.default_price!==null && p.default_price!==undefined && p.default_price!=='') tr.querySelector('[name="item_price[]"]').value=p.default_price;
    const vatSelect=tr.querySelector('[name="item_vat_key[]"]');
    const target=(p.vat_name||'Normala')+'|'+String(Number(p.vat_percentage||21));
    [...vatSelect.options].forEach(o=>{ if(o.value===target) vatSelect.value=target; });
    calcTotals();
}
function removeRow(btn){
    const body=document.getElementById('itemsBody');
    if(body.querySelectorAll('tr').length>1){ btn.closest('tr').remove(); calcTotals(); }
}
function calcTotals(){
    let subtotal=0, vat=0;
    document.querySelectorAll('#itemsBody tr').forEach(tr=>{
        const qty=Number(tr.querySelector('[name="item_quantity[]"]').value||0);
        const price=Number(tr.querySelector('[name="item_price[]"]').value||0);
        const parts=String(tr.querySelector('[name="item_vat_key[]"]').value||'Normala|21').split('|');
        const pct=Number(parts[1]||0);
        const line=qty*price;
        subtotal+=line; vat+=line*pct/100;
        tr.querySelector('.line-total').textContent=fmtMoney(line);
    });
    document.getElementById('subtotalText').textContent=fmtMoney(subtotal);
    document.getElementById('vatText').textContent=fmtMoney(vat);
    document.getElementById('totalText').textContent=fmtMoney(subtotal+vat);
}

const clientSearch=document.getElementById('clientSearch');
clientSearch.addEventListener('input',()=>renderClientResults(clientSearch.value));
clientSearch.addEventListener('focus',()=>renderClientResults(clientSearch.value));
document.addEventListener('click',e=>{ if(!e.target.closest('.client-search')) document.getElementById('clientResults').classList.remove('open'); });
document.getElementById('contractSelect').addEventListener('change',updateContractHint);

if(INITIAL_CLIENT_ID) renderContracts(INITIAL_CLIENT_ID,false); else renderContracts(0,false);
INITIAL_ITEMS.forEach(it=>addRow(it));
if(!INITIAL_ITEMS.length) addRow();
calcTotals();
</script>
</body>
</html>
