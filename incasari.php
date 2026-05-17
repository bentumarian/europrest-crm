<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/smartbill_lib.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

function inc_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function inc_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function inc_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function inc_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!inc_column_exists($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            error_log('Incasari add column error: ' . $table . '.' . $column . ' - ' . $e->getMessage());
        }
    }
}

function inc_money($value, string $currency = 'RON'): string
{
    return number_format(pz_smartbill_money($value), 2, ',', '.') . ' ' . $currency;
}

pz_smartbill_ensure_schema($pdo);
if (inc_table_exists($pdo, 'clients')) {
    inc_ensure_column($pdo, 'clients', 'billing_country', "VARCHAR(80) NULL");
    inc_ensure_column($pdo, 'clients', 'billing_county', "VARCHAR(120) NULL");
    inc_ensure_column($pdo, 'clients', 'billing_city', "VARCHAR(120) NULL");
    inc_ensure_column($pdo, 'clients', 'billing_address_line', "VARCHAR(255) NULL");
}

$settings = pz_smartbill_settings($pdo);
$success = '';
$error = '';
$q = trim((string)($_GET['q'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? 'all'));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$clientIdFilter = max(0, (int)($_GET['client_id'] ?? 0));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-t')));
$paymentTypes = pz_smartbill_payment_types();
if ($typeFilter !== 'all' && !isset($paymentTypes[$typeFilter])) {
    $typeFilter = 'all';
}
if (!in_array($statusFilter, ['all', 'issued', 'error', 'deleted', 'manual'], true)) {
    $statusFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) {
        csrf_require();
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'standalone_receipt') {
        $result = pz_smartbill_issue_standalone_receipt($pdo, $_POST);
        if (!empty($result['ok'])) {
            header('Location: incasari.php?receipt_issued=1&payment_id=' . (int)($result['payment_id'] ?? 0));
            exit;
        }
        $error = (string)($result['error'] ?? 'Chitanța nu a putut fi emisă.');
    }

    if ($action === 'delete_receipt') {
        $paymentId = max(0, (int)($_POST['payment_id'] ?? 0));
        $result = $paymentId > 0 ? pz_smartbill_delete_receipt($pdo, $paymentId) : ['ok' => false, 'error' => 'Chitanța nu a fost găsită.'];
        if (!empty($result['ok'])) {
            header('Location: incasari.php?receipt_deleted=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'Chitanța nu a putut fi ștearsă.');
    }
}

if (isset($_GET['receipt_issued'])) {
    $success = 'Chitanța a fost emisă în SmartBill și salvată in CRM.';
}
if (isset($_GET['receipt_deleted'])) {
    $success = 'Chitanța a fost ștearsă dîn SmartBill și marcată in CRM.';
}

$clients = [];
if (inc_table_exists($pdo, 'clients')) {
    $clients = $pdo->query("
        SELECT id, name, fiscal_code, email, phone, legal_representative_name,
               registered_address, billing_country, billing_county, billing_city, billing_address_line
        FROM clients
        WHERE active = 1
        ORDER BY name ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$clientsForJs = [];
foreach ($clients as $client) {
    $clientsForJs[(int)$client['id']] = [
        'name' => (string)($client['name'] ?? ''),
        'fiscal_code' => (string)($client['fiscal_code'] ?? ''),
        'contact' => (string)($client['legal_representative_name'] ?? ''),
        'email' => (string)($client['email'] ?? ''),
        'phone' => (string)($client['phone'] ?? ''),
        'country' => (string)($client['billing_country'] ?? 'Romania') ?: 'Romania',
        'county' => (string)($client['billing_county'] ?? ''),
        'city' => (string)($client['billing_city'] ?? ''),
        'address' => trim((string)($client['billing_address_line'] ?? '')),
    ];
}

$where = ["p.payment_date BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];
if ($q !== '') {
    $where[] = "(i.client_name LIKE ? OR i.client_fiscal_code LIKE ? OR i.smartbill_series LIKE ? OR i.smartbill_number LIKE ? OR p.document_series LIKE ? OR p.document_number LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($clientIdFilter > 0) {
    $where[] = "i.client_id = ?";
    $params[] = $clientIdFilter;
}
if ($typeFilter !== 'all') {
    $where[] = "p.payment_type = ?";
    $params[] = $typeFilter;
}
if ($statusFilter !== 'all') {
    $where[] = "p.smartbill_status = ?";
    $params[] = $statusFilter;
}
$stmt = $pdo->prepare("
    SELECT p.*, i.client_name, i.client_fiscal_code, i.smartbill_series, i.smartbill_number,
           i.gross_amount, i.currency AS invoice_currency, i.smartbill_status AS invoice_status
    FROM smartbill_invoice_payments p
    INNER JOIN smartbill_invoices i ON i.id = p.smartbill_invoice_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 120
");
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totals = [
    'issued' => 0.0,
    'cash' => 0.0,
    'bank' => 0.0,
];
foreach ($payments as $payment) {
    if (in_array((string)($payment['smartbill_status'] ?? ''), ['error', 'deleted'], true)) {
        continue;
    }
    $amount = pz_smartbill_money($payment['amount'] ?? 0);
    $totals['issued'] += $amount;
    if (pz_smartbill_payment_is_cash((string)($payment['payment_type'] ?? ''))) {
        $totals['cash'] += $amount;
    } else {
        $totals['bank'] += $amount;
    }
}
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Incasari</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        .pay-page{max-width:1220px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:18px}
        .hero,.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);padding:18px}
        .hero{grid-column:1/-1;display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}
        h1,h2{margin:0;letter-spacing:-.035em}.hero p,.muted{color:var(--muted);font-weight:700}
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .full{grid-column:1/-1}
        label{display:block;font-size:12px;font-weight:900;margin:10px 0 6px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        input,select,textarea{width:100%;min-height:42px;border:1px solid var(--border);border-radius:12px;padding:10px 12px;font:inherit;font-weight:750;background:#fff;box-sizing:border-box;color:var(--text)}
        textarea{min-height:82px;resize:vertical}
        .alert{grid-column:1/-1;border-radius:14px;padding:12px 14px;font-weight:850}
        .ok{background:var(--success-soft);color:var(--success);border:1px solid rgba(4,120,87,.18)}
        .err{background:var(--danger-soft);color:var(--danger);border:1px solid rgba(220,38,38,.18)}
        .summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}
        .metric{border:1px solid var(--border);background:var(--surface-soft);border-radius:14px;padding:12px}
        .metric span{display:block;color:var(--muted);font-size:11px;font-weight:900;text-transform:uppercase}.metric strong{display:block;margin-top:5px;font-size:18px}
        table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:10px 9px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:var(--muted)}
        .pill{display:inline-flex;border-radius:999px;padding:5px 9px;background:var(--surface-soft);font-weight:900;color:var(--muted);font-size:12px}.pill.ok{background:var(--success-soft);color:var(--success)}.pill.err{background:var(--danger-soft);color:var(--danger)}
        .actions{display:flex;gap:7px;flex-wrap:wrap}.actions form{margin:0}
        .filter-grid{display:grid;grid-template-columns:1fr 150px 150px 150px 150px auto;gap:10px;align-items:end}
        @media(max-width:980px){.pay-page{grid-template-columns:1fr}.form-grid,.summary{grid-template-columns:1fr}}
        @media(max-width:1120px){.filter-grid{grid-template-columns:1fr 1fr}}
    </style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('incasari', true); ?>
    <main class="main">
        <div class="content pay-page">
            <section class="hero">
                <div>
                    <h1>Incasari</h1>
                    <p>Chitante, OP, transferuri și încasări partiale sincronizate cu SmartBill.</p>
                </div>
                <div class="actions">
                    <a class="btn ghost" href="facturi.php">Facturi</a>
                    <a class="btn ghost" href="smartbill_settings.php">Setări SmartBill</a>
                </div>
            </section>

            <?php render_billing_module_nav('incasari'); ?>

            <?php if ($success !== ''): ?><div class="alert ok"><?= inc_h($success) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert err"><?= inc_h($error) ?></div><?php endif; ?>

            <section class="card full">
                <form method="get" class="filter-grid">
                    <?php if ($clientIdFilter > 0): ?>
                        <input type="hidden" name="client_id" value="<?= (int)$clientIdFilter ?>">
                    <?php endif; ?>
                    <div><label>Căutare</label><input type="search" name="q" value="<?= inc_h($q) ?>" placeholder="Client, CUI, factura, chitanța"></div>
                    <div><label>De la</label><input type="date" name="date_from" value="<?= inc_h($dateFrom) ?>"></div>
                    <div><label>Până la</label><input type="date" name="date_to" value="<?= inc_h($dateTo) ?>"></div>
                    <div><label>Tip</label><select name="type"><option value="all">Toate</option><?php foreach ($paymentTypes as $key => $label): ?><option value="<?= inc_h($key) ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= inc_h($label) ?></option><?php endforeach; ?></select></div>
                    <div><label>Status</label><select name="status"><option value="all">Toate</option><option value="issued" <?= $statusFilter === 'issued' ? 'selected' : '' ?>>Emise</option><option value="manual" <?= $statusFilter === 'manual' ? 'selected' : '' ?>>Manuale</option><option value="error" <?= $statusFilter === 'error' ? 'selected' : '' ?>>Erori</option><option value="deleted" <?= $statusFilter === 'deleted' ? 'selected' : '' ?>>Sterse</option></select></div>
                    <button class="btn accent" type="submit">Filtreaza</button>
                </form>
            </section>

            <section class="card">
                <h2>Chitanța fără factură</h2>
                <p class="muted">Folosim endpointul SmartBill de încasare fără factură. Pentru facturile existente, încasarea rămâne in pagina facturii.</p>
                <form method="post" class="form-grid">
                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                    <input type="hidden" name="action" value="standalone_receipt">
                    <div class="full">
                        <label>Client existent</label>
                        <select name="client_id" id="client_id" onchange="applyReceiptClient()">
                            <option value="">Alege client sau completeaza manual</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= (int)$client['id'] ?>" <?= $clientIdFilter === (int)$client['id'] ? 'selected' : '' ?>><?= inc_h($client['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Nume client *</label><input name="client_name" id="client_name" required></div>
                    <div><label>CUI / CNP *</label><input name="client_fiscal_code" id="client_fiscal_code" required></div>
                    <div><label>Persoană contact</label><input name="client_contact" id="client_contact"></div>
                    <div><label>Telefon</label><input name="client_phone" id="client_phone"></div>
                    <div><label>Email</label><input type="email" name="client_email" id="client_email"></div>
                    <div><label>Țară *</label><input name="client_country" id="client_country" value="Romania" required></div>
                    <div><label>Județ *</label><input name="client_county" id="client_county" required></div>
                    <div><label>Oraș / localitate *</label><input name="client_city" id="client_city" required></div>
                    <div class="full"><label>Adresa *</label><input name="client_address" id="client_address" required></div>
                    <div><label>Data chitanța</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"></div>
                    <div><label>Suma *</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
                    <div><label>Moneda</label><input name="currency" value="RON"></div>
                    <div><label>Serie chitanța</label><input name="document_series" value="<?= inc_h($settings['smartbill.receipt_series'] ?? '') ?>"></div>
                    <div class="full"><label>Text chitanța</label><input name="payment_text" value="Încasare fără factură"></div>
                    <div class="full"><label>Observații</label><textarea name="notes"></textarea></div>
                    <div class="full"><button class="btn accent" type="submit">Emite chitanța în SmartBill</button></div>
                </form>
            </section>

            <aside class="card">
                <h2>Rezumat</h2>
                <div class="summary">
                    <div class="metric"><span>Total incasat</span><strong><?= inc_h(inc_money($totals['issued'])) ?></strong></div>
                    <div class="metric"><span>Numerar</span><strong><?= inc_h(inc_money($totals['cash'])) ?></strong></div>
                    <div class="metric"><span>Banca / card</span><strong><?= inc_h(inc_money($totals['bank'])) ?></strong></div>
                </div>
                <p class="muted">Sunt incluse încasările valide din CRM; documentele șterse sau cu eroare nu intra in total.</p>
            </aside>

            <section class="card full">
                <h2>Lista încasări</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Client</th>
                            <th>Factura</th>
                            <th>Tip</th>
                            <th>Suma</th>
                            <th>Document</th>
                            <th>Status</th>
                            <th>Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$payments): ?>
                        <tr><td colspan="8" class="muted">Nu există încasări.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                            $status = (string)($payment['smartbill_status'] ?? '');
                            $statusClass = $status === 'issued' ? 'ok' : (in_array($status, ['error', 'deleted'], true) ? 'err' : '');
                            $invoiceDoc = trim((string)(($payment['smartbill_series'] ?? '') . ' ' . ($payment['smartbill_number'] ?? '')));
                            $paymentDoc = trim((string)(($payment['document_series'] ?? '') . ' ' . ($payment['document_number'] ?? '')));
                        ?>
                        <tr>
                            <td><?= inc_h($payment['payment_date'] ?? '') ?></td>
                            <td>
                                <strong><?= inc_h($payment['client_name'] ?? '-') ?></strong>
                                <div class="muted"><?= inc_h($payment['client_fiscal_code'] ?? '') ?></div>
                            </td>
                            <td>
                                <?php if ($invoiceDoc !== ''): ?>
                                    <a href="facturi.php?id=<?= (int)$payment['smartbill_invoice_id'] ?>"><?= inc_h($invoiceDoc) ?></a>
                                <?php else: ?>
                                    <span class="muted">Fără factură</span>
                                <?php endif; ?>
                            </td>
                            <td><?= inc_h(pz_smartbill_payment_label((string)($payment['payment_type'] ?? 'alta'))) ?></td>
                            <td><?= inc_h(inc_money($payment['amount'] ?? 0, (string)($payment['currency'] ?? 'RON'))) ?></td>
                            <td><?= inc_h($paymentDoc !== '' ? $paymentDoc : '-') ?></td>
                            <td>
                                <span class="pill <?= inc_h($statusClass) ?>"><?= inc_h($status ?: 'manual') ?></span>
                                <?php if (!empty($payment['error_message'])): ?><div class="muted"><?= inc_h($payment['error_message']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <a class="btn ghost" style="min-height:32px;padding:6px 9px;font-size:12px" href="facturi.php?id=<?= (int)$payment['smartbill_invoice_id'] ?>">Deschide</a>
                                    <?php if (($payment['payment_type'] ?? '') === 'chitanta' && $status === 'issued' && $paymentDoc !== ''): ?>
                                        <form method="post" onsubmit="return confirm('Stergi chitanța dîn SmartBill? SmartBill permite de regulă ștergerea doar pentru ultima chitanța din serie.');">
                                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                            <input type="hidden" name="action" value="delete_receipt">
                                            <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                                            <button class="btn ghost" style="min-height:32px;padding:6px 9px;font-size:12px;color:var(--danger)" type="submit">Șterge</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>
</div>
<script>
const receiptClients = <?= json_encode($clientsForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
function setReceiptField(id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value || '';
}
function applyReceiptClient() {
    const id = document.getElementById('client_id')?.value || '';
    const c = receiptClients[id];
    if (!c) return;
    setReceiptField('client_name', c.name);
    setReceiptField('client_fiscal_code', c.fiscal_code);
    setReceiptField('client_contact', c.contact);
    setReceiptField('client_email', c.email);
    setReceiptField('client_phone', c.phone);
    setReceiptField('client_country', c.country || 'Romania');
    setReceiptField('client_county', c.county);
    setReceiptField('client_city', c.city);
    setReceiptField('client_address', c.address);
}
document.addEventListener('DOMContentLoaded', () => {
    const selected = document.getElementById('client_id')?.value || '';
    if (selected) applyReceiptClient();
});
</script>
</body>
</html>
