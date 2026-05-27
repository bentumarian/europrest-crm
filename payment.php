<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/lib/smartbill_lib.php';

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

function inc_open_invoices_for_client(PDO $pdo, int $clientId, int $limit = 100): array
{
    if ($clientId <= 0) {
        return ['rows' => [], 'total' => 0.0];
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM smartbill_invoices
        WHERE source_type <> 'receipt'
          AND smartbill_status = 'issued'
          AND COALESCE(smartbill_number, '') <> ''
          AND client_id = ?
        ORDER BY due_date ASC, invoice_date ASC, id ASC
        LIMIT " . max(1, min(200, $limit)) . "
    ");
    $stmt->execute([$clientId]);

    $rows = [];
    $total = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoiceRow) {
        $fullInvoice = pz_smartbill_fetch_invoice($pdo, (int)$invoiceRow['id']);
        if (!$fullInvoice) {
            continue;
        }
        $gross = pz_smartbill_money($fullInvoice['gross_amount'] ?? 0);
        $paid = pz_smartbill_paid_amount($fullInvoice);
        $remaining = max(0, round($gross - $paid, 2));
        if ($remaining <= 0.005) {
            continue;
        }
        $fullInvoice['paid_amount'] = $paid;
        $fullInvoice['remaining_amount'] = $remaining;
        $rows[] = $fullInvoice;
        $total += $remaining;
    }

    return ['rows' => $rows, 'total' => $total];
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
$invoiceIdFilter = max(0, (int)($_GET['invoice_id'] ?? 0));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-t')));
$paymentTypes = pz_smartbill_payment_types();
$primaryPaymentTypes = array_intersect_key($paymentTypes, array_flip(['chitanta', 'card', 'transfer_bancar']));
if ($typeFilter !== 'all' && !isset($paymentTypes[$typeFilter])) {
    $typeFilter = 'all';
}
if (!in_array($statusFilter, ['all', 'issued', 'error', 'deleted', 'manual'], true)) {
    $statusFilter = 'all';
}

// Prefill server-side din factură când vii cu ?invoice_id=X — toate câmpurile clientului
// se preiau direct de pe factură (smartbill_invoices), care le are deja completate
// corect din emiterea anterioară. Nu depinde de cache-ul JS din clients.
$paymentPrefill = [
    'client_id' => 0,
    'client_name' => '',
    'client_fiscal_code' => '',
    'client_reg_com' => '',
    'client_contact' => '',
    'client_email' => '',
    'client_phone' => '',
    'client_country' => 'Romania',
    'client_county' => '',
    'client_city' => '',
    'client_address' => '',
];

if ($invoiceIdFilter > 0) {
    $stmt = $pdo->prepare("
        SELECT id, client_id, client_name, client_fiscal_code, client_reg_com,
               client_contact, client_email, client_phone,
               client_country, client_county, client_city, client_address
        FROM smartbill_invoices
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceIdFilter]);
    $invoiceContext = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($invoiceContext) {
        if ($clientIdFilter <= 0 && !empty($invoiceContext['client_id'])) {
            $clientIdFilter = (int)$invoiceContext['client_id'];
        }

        if ($clientIdFilter <= 0 && $q === '' && trim((string)($invoiceContext['client_name'] ?? '')) !== '') {
            $q = trim((string)$invoiceContext['client_name']);
        }

        // Prefill complet din factură
        $paymentPrefill['client_id'] = (int)($invoiceContext['client_id'] ?? 0);
        $paymentPrefill['client_name'] = (string)($invoiceContext['client_name'] ?? '');
        $paymentPrefill['client_fiscal_code'] = (string)($invoiceContext['client_fiscal_code'] ?? '');
        $paymentPrefill['client_reg_com'] = (string)($invoiceContext['client_reg_com'] ?? '');
        $paymentPrefill['client_contact'] = (string)($invoiceContext['client_contact'] ?? '');
        $paymentPrefill['client_email'] = (string)($invoiceContext['client_email'] ?? '');
        $paymentPrefill['client_phone'] = (string)($invoiceContext['client_phone'] ?? '');
        $paymentPrefill['client_country'] = (string)($invoiceContext['client_country'] ?? 'Romania') ?: 'Romania';
        $paymentPrefill['client_county'] = (string)($invoiceContext['client_county'] ?? '');
        $paymentPrefill['client_city'] = (string)($invoiceContext['client_city'] ?? '');
        $paymentPrefill['client_address'] = (string)($invoiceContext['client_address'] ?? '');
    }
}

// Fallback: dacă nu vii cu invoice_id dar ai client_id, prefill din tabela clients
if ($paymentPrefill['client_name'] === '' && $clientIdFilter > 0 && inc_table_exists($pdo, 'clients')) {
    $stmt = $pdo->prepare("
        SELECT id, name, fiscal_code, registry_number, email, phone, legal_representative_name,
               registered_address, billing_country, billing_county, billing_city, billing_address_line
        FROM clients
        WHERE id = ? AND active = 1
        LIMIT 1
    ");
    $stmt->execute([$clientIdFilter]);
    $clientRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($clientRow) {
        $paymentPrefill['client_id'] = (int)$clientRow['id'];
        $paymentPrefill['client_name'] = (string)($clientRow['name'] ?? '');
        $paymentPrefill['client_fiscal_code'] = (string)($clientRow['fiscal_code'] ?? '');
        $paymentPrefill['client_reg_com'] = (string)($clientRow['registry_number'] ?? '');
        $paymentPrefill['client_contact'] = (string)($clientRow['legal_representative_name'] ?? '');
        $paymentPrefill['client_email'] = (string)($clientRow['email'] ?? '');
        $paymentPrefill['client_phone'] = (string)($clientRow['phone'] ?? '');
        $paymentPrefill['client_country'] = (string)($clientRow['billing_country'] ?? 'Romania') ?: 'Romania';
        $paymentPrefill['client_county'] = (string)($clientRow['billing_county'] ?? '');
        $paymentPrefill['client_city'] = (string)($clientRow['billing_city'] ?? '');
        $paymentPrefill['client_address'] = trim((string)($clientRow['billing_address_line'] ?? ''));
        // Dacă lipsesc datele structurate, fallback la registered_address (adresa ANAF)
        if ($paymentPrefill['client_address'] === '' && trim((string)($clientRow['registered_address'] ?? '')) !== '') {
            $paymentPrefill['client_address'] = trim((string)$clientRow['registered_address']);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) {
        csrf_require();
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'invoice_payment') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        if ($invoiceId <= 0) {
            $error = 'Factura nu a fost găsită.';
        } else {
            $result = pz_smartbill_issue_payment($pdo, $invoiceId, $_POST);
            if (!empty($result['ok'])) {
                header('Location: payment.php?invoice_payment_issued=1&invoice_id=' . $invoiceId);
                exit;
            }
            $error = (string)($result['error'] ?? 'Încasarea nu a putut fi emisă.');
        }
    }

    if ($action === 'delete_receipt') {
        $paymentId = max(0, (int)($_POST['payment_id'] ?? 0));
        $result = $paymentId > 0 ? pz_smartbill_delete_receipt($pdo, $paymentId) : ['ok' => false, 'error' => 'Chitanța nu a fost găsită.'];
        if (!empty($result['ok'])) {
            header('Location: payment.php?receipt_deleted=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'Chitanța nu a putut fi ștearsă.');
    }
}

if (isset($_GET['receipt_issued'])) {
    $success = 'Chitanța a fost emisă în SmartBill și salvată în CRM.';
}
if (isset($_GET['invoice_payment_issued'])) {
    $success = 'Încasarea facturii a fost emisă în SmartBill.';
}
if (isset($_GET['receipt_deleted'])) {
    $success = 'Chitanța a fost ștearsă din SmartBill și marcată în CRM.';
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
           i.gross_amount, i.currency AS invoice_currency, i.smartbill_status AS invoice_status,
           i.source_type AS invoice_source_type
    FROM smartbill_invoice_payments p
    INNER JOIN smartbill_invoices i ON i.id = p.smartbill_invoice_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 120
");
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$openInvoiceWhere = ["source_type <> 'receipt'", "smartbill_status = 'issued'", "COALESCE(smartbill_number, '') <> ''"];
$openInvoiceParams = [];
if ($q !== '') {
    $openInvoiceWhere[] = "(client_name LIKE ? OR client_fiscal_code LIKE ? OR smartbill_series LIKE ? OR smartbill_number LIKE ?)";
    $likeOpen = '%' . $q . '%';
    array_push($openInvoiceParams, $likeOpen, $likeOpen, $likeOpen, $likeOpen);
}
if ($clientIdFilter > 0) {
    $openInvoiceWhere[] = "client_id = ?";
    $openInvoiceParams[] = $clientIdFilter;
}
$stmt = $pdo->prepare("
    SELECT *
    FROM smartbill_invoices
    WHERE " . implode(' AND ', $openInvoiceWhere) . "
    ORDER BY due_date ASC, invoice_date ASC, id ASC
    LIMIT 80
");
$stmt->execute($openInvoiceParams);
$openInvoiceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$openInvoices = [];
$unpaidTotal = 0.0;
foreach ($openInvoiceRows as $invoiceRow) {
    $fullInvoice = pz_smartbill_fetch_invoice($pdo, (int)$invoiceRow['id']);
    if (!$fullInvoice) {
        continue;
    }
    $gross = pz_smartbill_money($fullInvoice['gross_amount'] ?? 0);
    $paid = pz_smartbill_paid_amount($fullInvoice);
    $remaining = max(0, round($gross - $paid, 2));
    if ($remaining <= 0.005) {
        continue;
    }
    $fullInvoice['paid_amount'] = $paid;
    $fullInvoice['remaining_amount'] = $remaining;
    $openInvoices[] = $fullInvoice;
    $unpaidTotal += $remaining;
}

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

$contextQuery = [];
if ($clientIdFilter > 0) {
    $contextQuery['client_id'] = $clientIdFilter;
} elseif ($q !== '') {
    $contextQuery['q'] = $q;
}
$paymentsReportLink = 'payments.php' . ($contextQuery ? ('?' . http_build_query($contextQuery)) : '');
$invoicesReportLink = 'invoices.php' . ($contextQuery ? ('?' . http_build_query($contextQuery)) : '');
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Încasare</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        .pay-page{max-width:none;margin:0;display:grid;grid-template-columns:1fr;gap:10px}
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
        .pay-page{max-width:none;grid-template-columns:1fr;gap:10px}
        .hero,.card{border-radius:var(--pz-r);padding:10px;box-shadow:none;border:1px solid var(--pz-line)}
        .hero h1{font-size:19px}.hero p{margin:4px 0 0;font-size:12px}
        label{font-size:9px;margin:3px 0 2px;letter-spacing:.025em}
        input,select,textarea{min-height:27px;border-radius:2px;padding:4px 7px;font-size:12px}
        textarea{min-height:34px}
        .form-grid{grid-template-columns:minmax(210px,1.45fr) minmax(108px,.7fr) minmax(108px,.7fr);gap:4px 8px}
        .pay-editor{padding:0;overflow:hidden}
        .pay-editor-head{display:flex;justify-content:space-between;align-items:center;gap:8px;border-bottom:1px solid var(--border);padding:7px 10px;background:var(--surface-soft)}
        .pay-editor-head h2{font-size:16px;letter-spacing:0}
        .pay-editor-body{padding:8px 10px}
        .payment-methods{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;margin-bottom:6px}
        .payment-methods label{display:flex;align-items:center;gap:6px;border:1px solid var(--border);border-radius:6px;padding:7px;margin:0!important;text-transform:none!important;letter-spacing:0!important;color:var(--text)!important;font-size:12px!important;cursor:pointer}
        .payment-methods input{width:auto!important;min-height:0!important;margin:0}
        .payment-methods label:has(input:checked){border-color:var(--success);background:var(--success-soft);color:var(--success)!important}
        .client-search-wrap{position:relative}
        .client-search-results{display:none;position:absolute;z-index:30;left:0;right:0;top:100%;margin-top:3px;background:#fff;border:1px solid var(--border);border-radius:var(--pz-rs);box-shadow:none;max-height:240px;overflow:auto}
        .client-search-results.is-open{display:block}
        .client-search-option{display:block;width:100%;border:0;background:#fff;text-align:left;padding:8px 10px;cursor:pointer;font:inherit}
        .client-search-option:hover,.client-search-option.is-active{background:var(--surface-soft)}
        .client-search-option strong{display:block;font-size:12px;color:var(--text)}
        .client-search-option span{display:block;margin-top:2px;font-size:11px;color:var(--muted);font-weight:750}
        .client-search-note{margin-top:3px;color:var(--muted);font-size:11px;font-weight:750}
        .client-search-note.is-error{color:var(--danger)}
        .client-unpaid-panel{display:none;border:1px solid var(--border);border-radius:6px;background:#fff;margin:4px 0 2px;overflow:hidden}
        .client-unpaid-panel.is-open{display:block}
        .client-unpaid-head{display:flex;justify-content:space-between;gap:8px;align-items:center;background:var(--surface-soft);border-bottom:1px solid var(--border);padding:8px 10px}
        .client-unpaid-head strong{font-size:12px}.client-unpaid-head span{font-size:12px;font-weight:900;color:var(--danger)}
        .client-unpaid-list{display:grid}
        .client-unpaid-row{display:grid;grid-template-columns:minmax(120px,1fr) 92px 92px 92px 76px;gap:8px;align-items:center;padding:7px 10px;border-bottom:1px solid var(--border);font-size:12px}
        .client-unpaid-row:last-child{border-bottom:0}
        .client-unpaid-row small{display:block;color:var(--muted);font-size:10px;font-weight:850}
        .client-unpaid-row a{font-weight:900;color:var(--brand);text-decoration:none}
        .client-unpaid-empty{padding:10px;color:var(--muted);font-size:12px;font-weight:850}
        .receipt-actions{display:flex;justify-content:flex-end;margin-top:8px}
        .receipt-actions .btn{min-height:30px;padding:6px 10px;font-size:12px}
        .open-invoice-list{display:grid;gap:8px;margin-top:10px}
        .open-invoice-card{border:1px solid var(--border);border-radius:8px;background:#fff;padding:9px;display:grid;grid-template-columns:minmax(220px,1.2fr) 100px 100px 100px minmax(330px,1.4fr);gap:8px;align-items:end}
        .open-invoice-card.is-highlighted{border-color:var(--accent);background:var(--accent-soft);box-shadow:0 0 0 3px var(--accent-soft)}
        .open-invoice-client strong{display:block;font-size:13px}.open-invoice-client span{display:block;color:var(--muted);font-size:11px;font-weight:800}
        .open-invoice-money span{display:block;color:var(--muted);font-size:9px;font-weight:950;text-transform:uppercase}.open-invoice-money strong{font-size:12px}
        .open-pay-form{display:grid;grid-template-columns:95px 118px 115px auto;gap:5px;align-items:end}
        .open-pay-form .btn{min-height:27px;padding:4px 8px;font-size:12px}
        .empty-state{border:1px dashed var(--border);border-radius:8px;background:var(--surface-soft);padding:12px;color:var(--muted);font-weight:850;text-align:center}
        @media(max-width:980px){.pay-page{grid-template-columns:1fr}.form-grid,.summary{grid-template-columns:1fr}}
        @media(max-width:1120px){.filter-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:1120px){.open-invoice-card{grid-template-columns:1fr 1fr}.open-pay-form{grid-template-columns:1fr 1fr}}
        @media(max-width:720px){.payment-methods{grid-template-columns:1fr}.form-grid,.open-invoice-card,.open-pay-form,.client-unpaid-row{grid-template-columns:1fr}}
        .pay-page{max-width:none;margin:0;grid-template-columns:1fr;gap:10px}
        .hero{border:0;background:transparent;box-shadow:none;border-radius:0;padding:4px 0 2px;align-items:center}
        .hero h1{font-size:22px;letter-spacing:0}
        .hero p{font-size:12px;font-weight:750}
        .card{border-radius:6px;box-shadow:none}
        .filter-grid{background:var(--surface);border-radius:6px}
        .pay-editor{border-radius:6px}
        .pay-editor-head{background:#eef1f5;padding:10px 12px}
        .pay-editor-head h2{font-size:18px}
        .pay-editor-body{padding:12px}
        .open-invoice-card{border-radius:6px;box-shadow:none}
        table{font-size:12px}
        th{background:#eef1f5;color:#334155;font-size:10px}
        th,td{padding:7px 8px}
        tbody tr:nth-child(even){background:#f8fafc}
        tbody tr:hover{background:#eef4ff}
        @media(max-width:1280px){.pay-page{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('incasare', true); ?>
    <main class="main">
        <div class="content pay-page">
            <section class="hero">
                <div>
                    <p style="font-size:11px;font-weight:600;color:var(--pz-mu);letter-spacing:.08em;text-transform:uppercase;margin:0 0 6px;line-height:1;">FINANCIAR</p>
                    <h1>Încasare</h1>
                    <p>Chitanțe, carduri, transferuri și încasări parțiale sincronizate cu SmartBill.</p>
                </div>
                <div class="actions">
                    <a class="btn ghost" href="smartbill_settings.php">Setări SmartBill</a>
                </div>
            </section>

            <?php render_billing_module_nav('incasari'); ?>

            <?php if ($success !== ''): ?><div class="alert ok"><?= inc_h($success) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert err"><?= inc_h($error) ?></div><?php endif; ?>

            <?php if ($openInvoices): ?>
            <section class="card full" id="facturi-neachitate">
                <h2>Facturi emise neîncasate<?= $clientIdFilter > 0 ? ' — ' . inc_h($paymentPrefill['client_name'] ?: 'client selectat') : '' ?></h2>
                <p class="muted">Alege factura pe care vrei să o încasezi. Suma este pre-completată cu soldul rămas; o poți modifica pentru încasare parțială.</p>
                <div class="open-invoice-list">
                    <?php foreach ($openInvoices as $invoice): ?>
                        <?php
                            $invoiceRef = trim((string)(($invoice['smartbill_series'] ?? '') . ' ' . ($invoice['smartbill_number'] ?? '')));
                            $currency = (string)($invoice['currency'] ?? 'RON');
                            $remaining = pz_smartbill_money($invoice['remaining_amount'] ?? 0);
                            $isHighlighted = $invoiceIdFilter > 0 && (int)$invoice['id'] === $invoiceIdFilter;
                        ?>
                        <article class="open-invoice-card<?= $isHighlighted ? ' is-highlighted' : '' ?>">
                            <div class="open-invoice-client">
                                <strong><?= inc_h($invoice['client_name'] ?? '-') ?></strong>
                                <span><?= inc_h($invoice['client_fiscal_code'] ?? '') ?> · <?= inc_h($invoiceRef) ?> · scadență <?= inc_h($invoice['due_date'] ?? '-') ?></span>
                            </div>
                            <div class="open-invoice-money"><span>Total</span><strong><?= inc_h(inc_money($invoice['gross_amount'] ?? 0, $currency)) ?></strong></div>
                            <div class="open-invoice-money"><span>Achitat</span><strong><?= inc_h(inc_money($invoice['paid_amount'] ?? 0, $currency)) ?></strong></div>
                            <div class="open-invoice-money"><span>Sold</span><strong><?= inc_h(inc_money($remaining, $currency)) ?></strong></div>
                            <form method="post" class="open-pay-form">
                                <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                <input type="hidden" name="action" value="invoice_payment">
                                <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                <input type="hidden" name="currency" value="<?= inc_h($currency) ?>">
                                <div>
                                    <label>Tip</label>
                                    <select name="payment_type">
                                        <?php foreach ($primaryPaymentTypes as $type => $label): ?>
                                            <option value="<?= inc_h($type) ?>"><?= inc_h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div><label>Suma</label><input type="number" name="amount" step="0.01" min="0.01" max="<?= inc_h(number_format($remaining, 2, '.', '')) ?>" value="<?= inc_h(number_format($remaining, 2, '.', '')) ?>"></div>
                                <div><label>Data</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"></div>
                                <button class="btn accent" type="submit">Încasează</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="card">
                <h2>Rezumat luna curentă</h2>
                <div class="summary">
                    <div class="metric"><span>Total incasat</span><strong><?= inc_h(inc_money($totals['issued'])) ?></strong></div>
                    <div class="metric"><span>Numerar</span><strong><?= inc_h(inc_money($totals['cash'])) ?></strong></div>
                    <div class="metric"><span>Banca / card</span><strong><?= inc_h(inc_money($totals['bank'])) ?></strong></div>
                    <div class="metric"><span>Sold neachitat</span><strong><?= inc_h(inc_money($unpaidTotal)) ?></strong></div>
                </div>
                <p class="muted">Sunt incluse încasările valide din CRM; documentele șterse sau cu eroare nu intră în total.</p>
            </section>

        </div>
    </main>
</div>
</body>
</html>

