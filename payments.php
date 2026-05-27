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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: payment.php');
    exit;
}

function pay_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pay_money($value, string $currency = 'RON'): string
{
    return number_format(pz_smartbill_money($value), 2, ',', '.') . ' ' . $currency;
}

function pay_invoice_ref(array $row): string
{
    $series = trim((string)($row['smartbill_series'] ?? ''));
    $number = trim((string)($row['smartbill_number'] ?? ''));
    return trim($series . $number) !== '' ? trim($series . ' ' . $number) : ('#' . (int)($row['smartbill_invoice_id'] ?? 0));
}

function pay_document_ref(array $row): string
{
    $series = trim((string)($row['document_series'] ?? ''));
    $number = trim((string)($row['document_number'] ?? ''));
    return trim($series . $number) !== '' ? trim($series . ' ' . $number) : '-';
}

function pay_payment_link(array $row): string
{
    $query = [];

    if (!empty($row['smartbill_invoice_id'])) {
        $query['invoice_id'] = (int)$row['smartbill_invoice_id'];
    }

    if (!empty($row['client_id'])) {
        $query['client_id'] = (int)$row['client_id'];
    } elseif (trim((string)($row['client_name'] ?? '')) !== '') {
        $query['q'] = (string)$row['client_name'];
    }

    return 'payment.php' . ($query ? ('?' . http_build_query($query)) : '');
}

pz_smartbill_ensure_schema($pdo);

$q = trim((string)($_GET['q'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? 'all'));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$clientIdFilter = max(0, (int)($_GET['client_id'] ?? 0));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-t')));
$paymentTypes = pz_smartbill_payment_types();
$allowedStatuses = ['all', 'issued', 'manual', 'error', 'deleted'];
if ($typeFilter !== 'all' && !isset($paymentTypes[$typeFilter])) {
    $typeFilter = 'all';
}
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
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
    SELECT p.*,
           i.client_name,
           i.client_id,
           i.client_fiscal_code,
           i.smartbill_series,
           i.smartbill_number,
           i.gross_amount,
           i.currency AS invoice_currency,
           i.smartbill_status AS invoice_status,
           i.source_type
    FROM smartbill_invoice_payments p
    LEFT JOIN smartbill_invoices i ON i.id = p.smartbill_invoice_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 500
");
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'all' => 0,
    'issued' => 0,
    'manual' => 0,
    'error' => 0,
    'deleted' => 0,
    'total' => 0.0,
    'cash' => 0.0,
    'card' => 0.0,
    'bank' => 0.0,
];
foreach ($payments as $payment) {
    $status = trim((string)($payment['smartbill_status'] ?? '')) ?: 'manual';
    $amount = pz_smartbill_money($payment['amount'] ?? 0);
    $type = (string)($payment['payment_type'] ?? 'alta');
    $stats['all']++;
    if (isset($stats[$status])) {
        $stats[$status]++;
    }
    if (in_array($status, ['error', 'deleted'], true)) {
        continue;
    }
    $stats['total'] += $amount;
    if (pz_smartbill_payment_is_cash($type)) {
        $stats['cash'] += $amount;
    } elseif (in_array($type, ['card', 'card_online'], true)) {
        $stats['card'] += $amount;
    } else {
        $stats['bank'] += $amount;
    }
}

$statusTabs = [
    'all' => 'Toate',
    'issued' => 'Emise',
    'manual' => 'Manuale',
    'error' => 'Erori',
    'deleted' => 'Șterse',
];

$statusLabels = [
    'issued' => 'Emisă',
    'manual' => 'Manuală',
    'error' => 'Eroare',
    'deleted' => 'Ștearsă',
];
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Încasări</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        /* Aliniat cu paleta pz-* si pattern-ul panel/alert/btn (consistent cu invoices.php) */
        .payments-page { max-width:none; margin:0; display:grid; gap:10px; }
        .hero { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; padding:4px 0 2px; }
        .hero h1 { margin:0; font-size:22px; font-weight:700; display:flex; align-items:center; gap:8px; color:var(--text); letter-spacing:-.02em; }
        .hero p { margin:4px 0 0; color:var(--pz-mu); font-weight:600; font-size:12px; }
        .count-badge { display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:22px; border:1px solid var(--pz-line); border-radius:var(--pz-r); background:#fff; color:var(--text); font-size:12px; font-weight:700; }
        .actions { display:flex; gap:6px; flex-wrap:wrap; }

        .panel { background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:var(--pz-r); box-shadow:none; }
        .panel-head { padding:14px 16px; border-bottom:1px solid var(--pz-lines); display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; }
        .panel-title { font-size:14px; font-weight:800; color:var(--text); }
        .panel-body { padding:14px 16px; }

        .toolbar { display:grid; grid-template-columns:minmax(260px,1fr) 150px 150px 160px auto; gap:8px; align-items:end; }
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
        .status-issued { background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); }
        .status-manual { background:var(--accent-soft); color:var(--accent-deep); border:1px solid var(--accent-soft-2); }
        .status-error, .status-deleted { background:var(--pz-res); color:var(--pz-re); border:1px solid var(--pz-reb); }

        .row-actions { display:flex; gap:5px; justify-content:flex-end; flex-wrap:wrap; }
        .row-actions .btn { min-height:28px; padding:5px 8px; font-size:11.5px; }

        .empty { padding:22px; text-align:center; color:var(--pz-mu); font-weight:600; }

        @media(max-width:1100px) {
            .toolbar { grid-template-columns:1fr 1fr 1fr; }
            .metrics { grid-template-columns:1fr 1fr; }
        }
        @media(max-width:640px) {
            .toolbar, .metrics { grid-template-columns:1fr; }
            .tabs { width:100%; }
            .tabs a { flex:1; text-align:center; justify-content:center; }
            table { font-size:11.5px; }
            th, td { padding:7px 8px; }
        }
    </style>
    <?php render_search_preview_assets(); ?>
</head>
<body>
<div class="layout">
    <?php render_sidebar('incasari', true); ?>
    <main class="main">
        <div class="content payments-page">
            <?php
                /*
                |------------------------------------------------------------
                | Header unificat Emma — înlocuiește hero + module_nav
                | + toolbar form + metrics + status tabs vechi.
                | Tabs principale = 3 module financiar (Încasări activ).
                | KPIs inline = Total / Numerar / Card / Bancă.
                | Toolbar = date range + search + popover (Modalitate + Status).
                | Actions = + Încasare (primary).
                |------------------------------------------------------------
                */
                $paymentsTabs = [
                    ['label' => 'Facturi',       'href' => 'invoices.php'],
                    ['label' => 'Încasări',      'href' => 'payments.php',     'active' => true],
                    ['label' => 'Lista lucrări', 'href' => 'work_billing.php'],
                ];

                $paymentsActiveFilters = 0;
                if ($typeFilter !== 'all')    $paymentsActiveFilters++;
                if ($statusFilter !== 'all')  $paymentsActiveFilters++;

                $dateFromDisplay = $dateFrom ? pz_date($dateFrom) : '';
                $dateToDisplay   = $dateTo   ? pz_date($dateTo)   : '';

                ob_start();
                ?>
                <form method="get" id="paymentsFilterForm" class="pz-fb">
                    <input type="hidden" name="date_from" value="<?= pay_h($dateFrom) ?>">
                    <input type="hidden" name="date_to"   value="<?= pay_h($dateTo) ?>">
                    <?php if ($clientIdFilter > 0): ?><input type="hidden" name="client_id" value="<?= (int)$clientIdFilter ?>"><?php endif; ?>

                    <div class="pz-fb-date-range" id="paymentsDateRange">
                        <i class="ti ti-calendar" aria-hidden="true"></i>
                        <input type="text" id="paymentsDateFrom" value="<?= pay_h($dateFromDisplay) ?>" placeholder="zz.ll.aaaa" readonly autocomplete="off" aria-label="Data început">
                        <span class="sep">—</span>
                        <input type="text" id="paymentsDateTo" value="<?= pay_h($dateToDisplay) ?>" placeholder="zz.ll.aaaa" readonly autocomplete="off" aria-label="Data final">
                    </div>

                    <div class="pz-fb-search">
                        <i class="ti ti-search" aria-hidden="true"></i>
                        <input type="text" id="paymentsSearchInput" name="q" value="<?= pay_h($q) ?>" placeholder="Caută" autocomplete="off">
                        <div class="pz-search-preview"></div>
                    </div>

                    <div class="pz-fb-spacer"></div>

                    <a class="pz-fb-nav-btn" href="payments.php" title="Resetare filtre">↻</a>

                    <div class="pz-fb-popover-wrap">
                        <button type="button" class="pz-fb-filter-btn" id="paymentsFiltersToggle" aria-haspopup="true" aria-expanded="false">
                            <i class="ti ti-adjustments-horizontal" aria-hidden="true"></i>
                            Filtre
                            <?php if ($paymentsActiveFilters > 0): ?>
                                <span class="badge"><?= (int)$paymentsActiveFilters ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="pz-fb-popover" id="paymentsFiltersPopover" role="dialog" aria-label="Filtre suplimentare încasări">
                            <div class="pf-row">
                                <label for="paymentsTypeSelect">Modalitate</label>
                                <select id="paymentsTypeSelect" name="type">
                                    <option value="all">Toate</option>
                                    <?php foreach ($paymentTypes as $key => $label): ?>
                                        <option value="<?= pay_h($key) ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= pay_h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pf-row">
                                <label for="paymentsStatusSelect">Status</label>
                                <select id="paymentsStatusSelect" name="status">
                                    <?php foreach ($statusTabs as $key => $label):
                                        $count = $key === 'all' ? $stats['all'] : ($stats[$key] ?? 0);
                                    ?>
                                        <option value="<?= pay_h($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= pay_h($label) ?> (<?= (int)$count ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pf-actions">
                                <button type="button" class="pz-ph-btn ghost" onclick="document.getElementById('paymentsFiltersPopover').classList.remove('is-open'); document.getElementById('paymentsFiltersToggle').setAttribute('aria-expanded','false');">Anulează</button>
                                <button type="submit" class="pz-ph-btn primary">Aplică</button>
                            </div>
                        </div>
                    </div>
                </form>
                <?php
                $paymentsToolbarHtml = ob_get_clean();

                pz_page_header([
                    'kicker'   => 'Financiar',
                    'title'    => 'Încasări',
                    'subtitle' => (int)$stats['all'] . ' încasări · ' . pay_h(pay_money($stats['total'])) . ' total',
                    'actions'  => [[
                        'label'   => 'Încasare nouă',
                        'href'    => 'payment.php',
                        'variant' => 'primary',
                        'iconOnly' => true,
                        'icon'    => 'ti-plus',
                    ]],
                    'tabs'     => $paymentsTabs,
                    'kpis'     => [
                        ['label' => 'Total încasat',      'value' => pay_h(pay_money($stats['total'])), 'tone' => 'success'],
                        ['label' => 'Numerar / chitanțe', 'value' => pay_h(pay_money($stats['cash']))],
                        ['label' => 'Card',               'value' => pay_h(pay_money($stats['card']))],
                        ['label' => 'Bancă / transfer',   'value' => pay_h(pay_money($stats['bank']))],
                    ],
                    'toolbar'  => $paymentsToolbarHtml,
                ]);

                pz_date_range_init('paymentsDateFrom', 'paymentsDateTo', 'date_from', 'date_to', [
                    'form_id' => 'paymentsFilterForm',
                ]);
                ?>
                <script>
                (function() {
                    var btn = document.getElementById('paymentsFiltersToggle');
                    var pop = document.getElementById('paymentsFiltersPopover');
                    if (!btn || !pop) return;
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var open = pop.classList.toggle('is-open');
                        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });
                    document.addEventListener('click', function(e) {
                        if (!pop.classList.contains('is-open')) return;
                        if (pop.contains(e.target) || btn.contains(e.target)) return;
                        pop.classList.remove('is-open');
                        btn.setAttribute('aria-expanded', 'false');
                    });
                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape' && pop.classList.contains('is-open')) {
                            pop.classList.remove('is-open');
                            btn.setAttribute('aria-expanded', 'false');
                            btn.focus();
                        }
                    });
                })();
                </script>
            <?php /* — sfârșit header unificat — */ ?>

            <?php pz_table_cards_css(); ?>
            <section class="panel">
                <table class="pz-table-cards">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Document</th>
                            <th>Factură</th>
                            <th>Modalitate</th>
                            <th>Client</th>
                            <th>Data</th>
                            <th class="amount">Total</th>
                            <th>Status</th>
                            <th class="amount">Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$payments): ?>
                        <tr><td colspan="9" class="empty">Nu există încasări pentru filtrele curente.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                        $status = trim((string)($payment['smartbill_status'] ?? '')) ?: 'manual';
                        $statusClass = isset($statusLabels[$status]) ? $status : 'manual';
                        $currency = (string)($payment['currency'] ?? ($payment['invoice_currency'] ?? 'RON'));
                        $invoiceId = (int)($payment['smartbill_invoice_id'] ?? 0);
                        ?>
                        <tr>
                            <td data-label="ID">#<?= (int)$payment['id'] ?></td>
                            <td data-label="Document"><?= pay_h(pay_document_ref($payment)) ?></td>
                            <td data-label="Factură">
                                <?php if ($invoiceId > 0): ?>
                                    <a href="invoice.php?id=<?= $invoiceId ?>"><?= pay_h(pay_invoice_ref($payment)) ?></a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td data-label="Modalitate"><?= pay_h(pz_smartbill_payment_label((string)($payment['payment_type'] ?? 'alta'))) ?></td>
                            <td data-label="Client">
                                <strong><?= pay_h($payment['client_name'] ?? '-') ?></strong>
                                <div class="muted"><?= pay_h($payment['client_fiscal_code'] ?? '') ?></div>
                            </td>
                            <td data-label="Data"><?= pay_h($payment['payment_date'] ?? '-') ?></td>
                            <td data-label="Total" class="amount"><?= pay_h(pay_money($payment['amount'] ?? 0, $currency)) ?></td>
                            <td data-label="Status"><span class="status-pill status-<?= pay_h($statusClass) ?>"><?= pay_h($statusLabels[$status] ?? $status) ?></span></td>
                            <td data-label="Acțiuni">
                                <div class="row-actions">
                                    <?php if ($invoiceId > 0): ?>
                                        <a class="btn ghost" href="invoice.php?id=<?= $invoiceId ?>">Factură</a>
                                    <?php endif; ?>
                                    <a class="btn ghost" href="<?= pay_h(pay_payment_link($payment)) ?>">Încasare</a>
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
// Preview live pentru bara „Caută" din pagina Încasări.
$previewPaymentsList = [];
try {
    $stmtPrev = $pdo->query("
        SELECT p.id, p.amount, p.currency, p.payment_date, p.document_series, p.document_number,
               i.client_name, i.client_fiscal_code, i.smartbill_series, i.smartbill_number
        FROM smartbill_invoice_payments p
        LEFT JOIN smartbill_invoices i ON i.id = p.smartbill_invoice_id
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 2000
    ");
    while ($r = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
        $nm = html_entity_decode((string)($r['client_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cf = html_entity_decode((string)($r['client_fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ref = trim(((string)$r['smartbill_series']) . ' ' . ((string)$r['smartbill_number']));
        $rec = trim(((string)($r['document_series'] ?? '')) . ' ' . ((string)($r['document_number'] ?? '')));
        $title = ((string)$r['payment_date']) . ' · ' . ($nm !== '' ? $nm : ($ref !== '' ? $ref : ('Încasare #' . (int)$r['id'])));
        $previewPaymentsList[] = [
            'title'  => $title,
            'url'    => 'payment.php?invoice_id=' . (int)0 . '&q=' . urlencode($nm),
            'type'   => 'payment',
            'search' => $nm . ' ' . $cf . ' ' . $ref . ' ' . $rec,
        ];
    }
} catch (Throwable $e) { error_log('payments.php preview: ' . $e->getMessage()); }
?>
<script>
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('paymentsSearchInput',
            <?= json_encode($previewPaymentsList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { mi                                  