<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/smartbill_lib.php';
require_once __DIR__ . '/lib/billing/billing_lib.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

if (isset($_GET['id']) || isset($_GET['appointment_id'])) {
    header('Location: invoice.php?' . http_build_query($_GET));
    exit;
}

function bill_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bill_money($value, string $currency = 'RON'): string
{
    return number_format(pz_smartbill_money($value), 2, ',', '.') . ' ' . $currency;
}

function bill_invoice_ref(array $invoice): string
{
    $series = trim((string)($invoice['smartbill_series'] ?? ''));
    $number = trim((string)($invoice['smartbill_number'] ?? ''));
    return trim($series . ' ' . $number) ?: ('Draft #' . (int)($invoice['id'] ?? 0));
}

function bill_payment_link(array $invoice): string
{
    $query = ['invoice_id' => (int)($invoice['id'] ?? 0)];

    if (!empty($invoice['client_id'])) {
        $query['client_id'] = (int)$invoice['client_id'];
    } elseif (trim((string)($invoice['client_name'] ?? '')) !== '') {
        $query['q'] = (string)$invoice['client_name'];
    }

    return 'payment.php?' . http_build_query($query);
}

pz_smartbill_ensure_schema($pdo);

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$clientIdFilter = max(0, (int)($_GET['client_id'] ?? 0));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-t')));
$allowedStatuses = ['all', 'unpaid', 'overdue', 'partial', 'paid', 'issued', 'draft', 'error'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$where = ["source_type <> 'receipt'", "invoice_date BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];
if ($q !== '') {
    $where[] = "(client_name LIKE ? OR client_fiscal_code LIKE ? OR smartbill_series LIKE ? OR smartbill_number LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($clientIdFilter > 0) {
    $where[] = "client_id = ?";
    $params[] = $clientIdFilter;
}

$stmt = $pdo->prepare("
    SELECT i.*,
           COALESCE(p.local_paid_amount, 0) AS local_paid_amount
    FROM smartbill_invoices i
    LEFT JOIN (
        SELECT smartbill_invoice_id, SUM(amount) AS local_paid_amount
        FROM smartbill_invoice_payments
        WHERE COALESCE(smartbill_status, '') NOT IN ('error', 'deleted')
        GROUP BY smartbill_invoice_id
    ) p ON p.smartbill_invoice_id = i.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.invoice_date DESC, i.id DESC
    LIMIT 500
");
$stmt->execute($params);
$rawInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rows = [];
$stats = [
    'all' => 0,
    'issued' => 0,
    'unpaid' => 0,
    'overdue' => 0,
    'partial' => 0,
    'paid' => 0,
    'error' => 0,
    'gross' => 0.0,
    'paid_amount' => 0.0,
    'unpaid_amount' => 0.0,
];
$today = date('Y-m-d');

foreach ($rawInvoices as $raw) {
    $invoice = $raw;

    $status = (string)($invoice['smartbill_status'] ?? 'draft');
    // Status plată calculat dintr-un singur loc: pz_billing_get_invoice_payment_summary().
    $paySummary = pz_billing_get_invoice_payment_summary($pdo, (int)$invoice['id']);
    $gross = $paySummary['gross'];
    $paid = $paySummary['paid'];
    $remaining = $paySummary['remaining'];
    $payStatus = $paySummary['status']; // 'unpaid' | 'partially_paid' | 'paid'
    $paymentStatus = ['unpaid' => 'neincasata', 'partially_paid' => 'partial', 'paid' => 'incasata'][$payStatus] ?? 'neincasata';
    $isIssued = trim((string)($invoice['smartbill_number'] ?? '')) !== '';
    $isOverdue = $isIssued && $remaining > 0.005 && !empty($invoice['due_date']) && (string)$invoice['due_date'] < $today;

    $rowStatus = $status;
    $isStorno = ($status === 'storno') || ((string)($invoice['source_type'] ?? '') === 'storno');
    if ($status === 'error') {
        $rowStatus = 'error';
    } elseif ($isStorno) {
        $rowStatus = 'storno';
    } elseif (!$isIssued) {
        $rowStatus = 'draft';
    } elseif ($payStatus === 'paid') {
        $rowStatus = 'paid';
    } elseif ($payStatus === 'partially_paid') {
        $rowStatus = 'partial';
    } elseif ($isOverdue) {
        $rowStatus = 'overdue';
    } else {
        $rowStatus = 'unpaid';
    }

    $stats['all']++;
    if ($isIssued) $stats['issued']++;
    if ($rowStatus === 'error') $stats['error']++;
    if ($rowStatus === 'paid') $stats['paid']++;
    if ($rowStatus === 'partial') $stats['partial']++;
    if ($rowStatus === 'unpaid') $stats['unpaid']++;
    if ($rowStatus === 'overdue') {
        $stats['overdue']++;
        $stats['unpaid']++;
    }
    if ($isIssued && $rowStatus !== 'error') {
        $stats['gross'] += $gross;
        $stats['paid_amount'] += $paid;
        $stats['unpaid_amount'] += $remaining;
    }

    if ($statusFilter !== 'all') {
        if ($statusFilter === 'issued' && !$isIssued) continue;
        if ($statusFilter === 'draft' && $isIssued) continue;
        if (!in_array($statusFilter, ['issued', 'draft'], true) && $rowStatus !== $statusFilter) continue;
    }

    $invoice['paid_amount'] = $paid;
    $invoice['remaining_amount'] = $remaining;
    $invoice['row_status'] = $rowStatus;
    $invoice['payment_status'] = $paymentStatus;
    $rows[] = $invoice;
}

$statusTabs = [
    'all' => 'Toate',
    'unpaid' => 'Neîncasate',
    'overdue' => 'Termen depășit',
    'partial' => 'Parțial',
    'paid' => 'Încasate',
];

$statusLabels = [
    'paid' => 'Încasată',
    'partial' => 'Parțial',
    'unpaid' => 'Emisă',
    'overdue' => 'Depășită',
    'draft' => 'Ciornă',
    'error' => 'Eroare',
    'storno' => 'Storno',
];
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Facturi</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        /* Aliniat cu paleta pz-* si pattern-ul panel/alert din contracts.php / addenda.php */
        .billing-home { max-width:none; margin:0; display:grid; gap:10px; }
        .hero { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; padding:4px 0 2px; }
        .hero h1 { margin:0; font-size:22px; font-weight:700; display:flex; align-items:center; gap:8px; color:var(--text); letter-spacing:-.02em; }
        .hero p { margin:4px 0 0; color:var(--pz-mu); font-weight:600; font-size:12px; }
        .count-badge { display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:22px; border:1px solid var(--pz-line); border-radius:var(--pz-r); background:#fff; color:var(--text); font-size:12px; font-weight:700; }
        .actions { display:flex; gap:6px; flex-wrap:wrap; }

        .panel { background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:var(--pz-r); box-shadow:none; }
        .panel-head { padding:14px 16px; border-bottom:1px solid var(--pz-lines); display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; }
        .panel-title { font-size:14px; font-weight:800; color:var(--text); }
        .panel-subtitle { font-size:12px; color:var(--pz-mu); margin-top:2px; }
        .panel-body { padding:14px 16px; }

        .filters { display:grid; grid-template-columns:minmax(260px,1fr) 150px 150px auto; gap:8px; align-items:end; }
        label { display:block; font-size:10px; font-weight:800; margin:3px 0 4px; color:var(--pz-mu); text-transform:uppercase; letter-spacing:.04em; }
        input, select { width:100%; min-height:32px; border:1px solid var(--pz-line); border-radius:var(--pz-rs); padding:6px 9px; font:inherit; font-size:12.5px; font-weight:600; background:#fff; color:var(--text); }
        input:focus, select:focus { border-color:var(--accent); outline:none; box-shadow:0 0 0 3px var(--accent-soft); }

        .metrics { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:8px; }
        .metric { border:1px solid var(--pz-line); border-radius:var(--pz-r); background:var(--pz-surf); padding:10px 12px; }
        .metric span { display:block; color:var(--pz-mu); font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
        .metric strong { display:block; margin-top:4px; font-size:16px; font-weight:700; color:var(--text); }

        .tabs { display:flex; gap:0; flex-wrap:wrap; border:1px solid var(--pz-line); border-radius:var(--pz-rs); width:max-content; max-width:100%; background:#fff; overflow:hidden; }
        .tabs a { min-height:30px; padding:6px 12px; border-right:1px solid var(--pz-line); font-size:12px; font-weight:700; text-decoration:none; color:var(--pz-mu); background:#fff; display:inline-flex; align-items:center; gap:5px; }
        .tabs a:last-child { border-right:0; }
        .tabs a:hover { color:var(--accent-deep); background:var(--accent-soft); }
        .tabs a.active { background:var(--accent); color:#fff; }
        .tabs a small { font-weight:800; opacity:.75; }

        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th, td { padding:9px 10px; border-bottom:1px solid var(--pz-lines); text-align:left; vertical-align:middle; }
        th { background:var(--pz-soft, #F8FAFC); color:var(--pz-mu); font-size:10.5px; text-transform:uppercase; font-weight:700; letter-spacing:.04em; }
        tbody tr:hover { background:var(--pz-soft, #F8FAFC); }
        tbody tr:last-child td { border-bottom:0; }
        td a { color:var(--accent-deep); text-decoration:none; font-weight:700; }
        td a:hover { text-decoration:underline; }

        .amount { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .muted { color:var(--pz-mu); font-weight:500; font-size:11.5px; margin-top:2px; }

        .status-pill { display:inline-flex; align-items:center; justify-content:center; min-width:74px; border-radius:var(--pz-rs); padding:3px 8px; font-size:10.5px; font-weight:700; }
        .status-paid { background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); }
        .status-partial { background:var(--pz-ors); color:var(--pz-or); border:1px solid var(--pz-orb); }
        /* Emisă (unpaid) = verde, Ciornă (draft) = portocaliu */
        .status-unpaid { background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); }
        .status-draft { background:var(--pz-ors); color:var(--pz-or); border:1px solid var(--pz-orb); }
        .status-overdue, .status-error { background:var(--pz-res); color:var(--pz-re); border:1px solid var(--pz-reb); }
        /* Storno = gri-roscat (e tot o factura valida, dar negativa) */
        .status-storno { background:#F1F5F9; color:#475569; border:1px solid #CBD5E1; }

        /* e-Factura: verde = trimisă, portocaliu = netrimisă */
        .efactura-sent { background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); }
        .efactura-notsent { background:var(--pz-ors); color:var(--pz-or); border:1px solid var(--pz-orb); }

        /* Butoane icon-only pentru coloana de acțiuni */
        .row-actions-icons { gap:2px; align-items:center; }
        .icon-btn {
            display:inline-flex; align-items:center; justify-content:center;
            width:30px; height:30px; padding:0;
            background:transparent; border:1px solid transparent;
            border-radius:var(--pz-rs);
            color:var(--pz-mu);
            cursor:pointer;
            transition:background .12s, color .12s, border-color .12s;
        }
        .icon-btn:hover { background:var(--pz-bls, #EFF6FF); color:var(--pz-bl, #1D4ED8); border-color:var(--pz-line); }
        .icon-btn.icon-btn-danger:hover { background:var(--pz-res); color:var(--pz-re); border-color:var(--pz-reb); }
        .icon-btn .nav-icon { width:16px; height:16px; flex:0 0 16px; }
        .icon-btn .nav-icon svg { width:16px; height:16px; }

        .row-actions { display:flex; gap:5px; justify-content:flex-end; flex-wrap:wrap; }
        .row-actions .btn { min-height:28px; padding:5px 8px; font-size:11.5px; }

        .empty { padding:22px; text-align:center; color:var(--pz-mu); font-weight:600; }

        @media(max-width:900px) {
            .filters, .metrics { grid-template-columns:1fr 1fr; }
            .tabs { width:100%; }
            .tabs a { flex:1; text-align:center; justify-content:center; }
        }
        @media(max-width:640px) {
            .filters, .metrics { grid-template-columns:1fr; }
            table { font-size:11.5px; }
            th, td { padding:7px 8px; }
        }
    </style>
    <?php render_search_preview_assets(); ?>
</head>
<body>
<div class="layout">
    <?php render_sidebar('facturi', true); ?>
    <main class="main">
        <div class="content billing-home">
            <section class="hero">
                <div>
                    <div class="pz-page-eyebrow">Financiar</div>
                    <h1>Facturi <span class="count-badge"><?= (int)$stats['all'] ?></span></h1>
                </div>
                <div class="actions">
                    <a class="btn accent" href="invoice.php">+ Emite factură</a>
                </div>
            </section>

            <?php render_billing_module_nav('facturi'); ?>

            <section class="panel">
                <div class="panel-body">
                    <form method="get" class="filters">
                        <div><label>Căutare</label>
                            <div class="pz-search-wrap">
                                <input type="search" id="invoicesSearchInput" name="q" value="<?= bill_h($q) ?>" placeholder="Client, CUI, serie, numar factura" autocomplete="off">
                                <div class="pz-search-preview"></div>
                            </div>
                        </div>
                        <div><label>De la</label><input type="date" name="date_from" value="<?= bill_h($dateFrom) ?>"></div>
                        <div><label>Până la</label><input type="date" name="date_to" value="<?= bill_h($dateTo) ?>"></div>
                        <?php if ($clientIdFilter > 0): ?><input type="hidden" name="client_id" value="<?= (int)$clientIdFilter ?>"><?php endif; ?>
                        <input type="hidden" name="status" value="<?= bill_h($statusFilter) ?>">
                        <button class="btn accent" type="submit">Filtrează</button>
                    </form>
                </div>
            </section>

            <section class="pz-kpi-grid">
                <div class="pz-kpi-card bl">
                    <div class="pz-kpi-label">Facturat</div>
                    <div class="pz-kpi-value"><?= bill_h(bill_money($stats['gross'])) ?></div>
                </div>
                <div class="pz-kpi-card gr">
                    <div class="pz-kpi-label">Încasat</div>
                    <div class="pz-kpi-value"><?= bill_h(bill_money($stats['paid_amount'])) ?></div>
                </div>
                <div class="pz-kpi-card or">
                    <div class="pz-kpi-label">Sold neachitat</div>
                    <div class="pz-kpi-value"><?= bill_h(bill_money($stats['unpaid_amount'])) ?></div>
                </div>
                <div class="pz-kpi-card re">
                    <div class="pz-kpi-label">Termen depășit</div>
                    <div class="pz-kpi-value"><?= (int)$stats['overdue'] ?></div>
                    <div class="pz-kpi-sub mu">facturi</div>
                </div>
            </section>

            <nav class="tabs" aria-label="Filtre facturi">
                <?php foreach ($statusTabs as $key => $label): ?>
                    <?php
                    $query = $_GET;
                    $query['status'] = $key;
                    $href = 'invoices.php?' . http_build_query($query);
                    $count = $stats[$key] ?? 0;
                    ?>
                    <a class="<?= $statusFilter === $key ? 'active' : '' ?>" href="<?= bill_h($href) ?>"><?= bill_h($label) ?> <small><?= (int)$count ?></small></a>
                <?php endforeach; ?>
            </nav>

            <section class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>Factură</th>
                            <th>Client</th>
                            <th>Data emiterii</th>
                            <th>Scadenta</th>
                            <th class="amount">Total</th>
                            <th class="amount">Încasat</th>
                            <th class="amount">Sold</th>
                            <th>Status</th>
                            <th>e-Factura</th>
                            <th class="amount">Actiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="10" class="empty">Nu exista facturi pentru filtrele curente.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $invoice): ?>
                        <?php
                        $rowStatus = (string)($invoice['row_status'] ?? 'draft');
                        $currency = (string)($invoice['currency'] ?? 'RON');
                        ?>
                        <tr>
                            <td><a href="invoice.php?id=<?= (int)$invoice['id'] ?>"><?= bill_h(bill_invoice_ref($invoice)) ?></a></td>
                            <td>
                                <strong><?= bill_h($invoice['client_name'] ?? '-') ?></strong>
                                <div class="muted"><?= bill_h($invoice['client_fiscal_code'] ?? '') ?></div>
                            </td>
                            <td><?= bill_h($invoice['invoice_date'] ?? '-') ?></td>
                            <td><?= bill_h($invoice['due_date'] ?? '-') ?></td>
                            <td class="amount"><?= bill_h(bill_money($invoice['gross_amount'] ?? 0, $currency)) ?></td>
                            <td class="amount"><?= bill_h(bill_money($invoice['paid_amount'] ?? 0, $currency)) ?></td>
                            <td class="amount"><?= bill_h(bill_money($invoice['remaining_amount'] ?? 0, $currency)) ?></td>
                            <td><span class="status-pill status-<?= bill_h($rowStatus) ?>"><?= bill_h($statusLabels[$rowStatus] ?? $rowStatus) ?></span></td>
                            <?php
                                $isIssuedRow = trim((string)($invoice['smartbill_number'] ?? '')) !== '';
                                $efacturaStatusRaw = trim((string)($invoice['efactura_status'] ?? ''));
                                $efacturaSent = ($efacturaStatusRaw !== '');
                            ?>
                            <td>
                                <?php if ($isIssuedRow): ?>
                                    <?php if ($efacturaSent): ?>
                                        <span class="status-pill efactura-sent" title="Status ANAF: <?= bill_h($efacturaStatusRaw) ?>">Trimisă</span>
                                    <?php else: ?>
                                        <span class="status-pill efactura-notsent" title="Nu a fost trimisă la ANAF e-Factura">Netrimisă</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions row-actions-icons">
                                    <?php $clientEmailRow = trim((string)($invoice['client_email'] ?? '')); ?>
                                    <?php $isStornoRow = ($rowStatus === 'storno'); ?>

                                    <?php if ($isIssuedRow): ?>
                                        <!-- Facturi EMISE: Vizualizare PDF, Editare metadata, Email, (Storno doar daca nu e deja storno) -->
                                        <a class="icon-btn" href="invoice_pdf.php?id=<?= (int)$invoice['id'] ?>" target="_blank" rel="noopener" title="Vizualizare PDF" aria-label="Vizualizare PDF"><?= app_icon_svg('eye') ?></a>
                                        <a class="icon-btn" href="invoice.php?id=<?= (int)$invoice['id'] ?>" title="Editează (note, observații)" aria-label="Editează"><?= app_icon_svg('edit') ?></a>
                                        <?php if ($clientEmailRow !== ''): ?>
                                            <form method="post" action="invoice.php" style="display:inline;margin:0" onsubmit="return confirm(<?= bill_h(json_encode('Trimite factura ' . bill_invoice_ref($invoice) . ' pe email către ' . $clientEmailRow . '?', JSON_UNESCAPED_UNICODE)) ?>);">
                                                <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                                <input type="hidden" name="action" value="send_invoice_email">
                                                <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                                <input type="hidden" name="email_to" value="<?= bill_h($clientEmailRow) ?>">
                                                <button class="icon-btn" type="submit" title="Trimite pe email la <?= bill_h($clientEmailRow) ?>" aria-label="Trimite pe email"><?= app_icon_svg('send') ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!$isStornoRow): ?>
                                            <form method="post" action="invoice.php" style="display:inline;margin:0" onsubmit="return confirm(<?= bill_h(json_encode('STORNO factura ' . bill_invoice_ref($invoice) . "?\n\nSe va emite în SmartBill o factură de stornare cu valori negative, care anulează contabil această factură. Operațiunea NU poate fi anulată.\n\nContinui?", JSON_UNESCAPED_UNICODE)) ?>);">
                                                <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                                <input type="hidden" name="action" value="reverse_invoice">
                                                <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                                <button class="icon-btn icon-btn-danger" type="submit" title="Emite factură de storno" aria-label="Storno"><?= app_icon_svg('undo') ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!$isStornoRow && pz_smartbill_money($invoice['remaining_amount'] ?? 0) > 0.005): ?>
                                            <a class="btn accent" href="<?= bill_h(bill_payment_link($invoice)) ?>">Încasează</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Ciorne: Editare, Ștergere reală -->
                                        <a class="icon-btn" href="invoice.php?id=<?= (int)$invoice['id'] ?>" title="Editează draft" aria-label="Editează draft"><?= app_icon_svg('edit') ?></a>
                                        <form method="post" action="invoice.php" style="display:inline;margin:0" onsubmit="return confirm(<?= bill_h(json_encode('Sigur dorești să ștergi draft-ul ' . bill_invoice_ref($invoice) . '?\n\nAcțiunea nu poate fi anulată.', JSON_UNESCAPED_UNICODE)) ?>);">
                                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                            <input type="hidden" name="action" value="delete_invoice">
                                            <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                            <input type="hidden" name="return_to" value="invoices.php">
                                            <button class="icon-btn icon-btn-danger" type="submit" title="Șterge draft" aria-label="Șterge draft"><?= app_icon_svg('trash') ?></button>
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

<?php
$previewInvoices = [];
try {
    $stmtPrev = $pdo->query("SELECT id, smartbill_series, smartbill_number, client_name, client_fiscal_code, invoice_date FROM smartbill_invoices WHERE source_type <> 'receipt' ORDER BY invoice_date DESC, id DESC LIMIT 2000");
    while ($inv = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
        $ref = trim(((string)$inv['smartbill_series']) . ' ' . ((string)$inv['smartbill_number']));
        if ($ref === '') $ref = 'Draft #' . (int)$inv['id'];
        $cn = html_entity_decode((string)($inv['client_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cf = html_entity_decode((string)($inv['client_fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $previewInvoices[] = [
            'title'  => $ref . ($cn !== '' ? ' Â· ' . $cn : ''),
            'url'    => 'invoice.php?id=' . (int)$inv['id'],
            'type'   => 'invoice',
            'search' => $ref . ' ' . $cn . ' ' . $cf,
        ];
    }
} catch (Throwable $e) { error_log('invoices.php preview: ' . $e->getMessage()); }
?>
<script>
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('invoicesSearchInput',
            <?= json_encode($previewInvoices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
    };
    go();
})();
</script>
</body>
</html>