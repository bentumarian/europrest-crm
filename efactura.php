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

function ef_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ef_date(?string $date, string $fallback): string
{
    $d = DateTime::createFromFormat('Y-m-d', (string)$date);
    return ($d && $d->format('Y-m-d') === (string)$date) ? (string)$date : $fallback;
}

function ef_statuses(): array
{
    return [
        'all' => 'Toate',
        'de_trimis' => 'De trimis',
        'in_trimitere' => 'In trimitere',
        'in_validare' => 'In validare',
        'validata' => 'Validata',
        'eroare' => 'Cu eroare',
        'neverificat' => 'Neverificat',
    ];
}

function ef_file_available(?string $path): bool
{
    $path = trim((string)$path);
    return $path !== '' && is_file(__DIR__ . '/' . ltrim($path, '/'));
}

pz_smartbill_ensure_schema($pdo);

$sentQ = trim((string)($_GET['sent_q'] ?? ''));
$sentStatus = trim((string)($_GET['sent_status'] ?? 'all'));
$sentFrom = ef_date($_GET['sent_from'] ?? null, date('Y-m-01'));
$sentTo = ef_date($_GET['sent_to'] ?? null, date('Y-m-t'));
$receivedQ = trim((string)($_GET['received_q'] ?? ''));
$receivedStatus = trim((string)($_GET['received_status'] ?? 'all'));
$receivedFrom = ef_date($_GET['received_from'] ?? null, date('Y-m-01'));
$receivedTo = ef_date($_GET['received_to'] ?? null, date('Y-m-t'));
$sentStatuses = ef_statuses();
$receivedStatuses = pz_smartbill_supplier_invoice_statuses();
if (!isset($sentStatuses[$sentStatus])) {
    $sentStatus = 'all';
}
if ($receivedStatus !== 'all' && !isset($receivedStatuses[$receivedStatus])) {
    $receivedStatus = 'all';
}

$sentWhere = [
    "source_type <> 'receipt'",
    "smartbill_number IS NOT NULL",
    "smartbill_number <> ''",
    "invoice_date BETWEEN ? AND ?",
];
$sentParams = [$sentFrom, $sentTo];
if ($sentQ !== '') {
    $sentWhere[] = "(client_name LIKE ? OR client_fiscal_code LIKE ? OR smartbill_series LIKE ? OR smartbill_number LIKE ?)";
    $like = '%' . $sentQ . '%';
    array_push($sentParams, $like, $like, $like, $like);
}
if ($sentStatus !== 'all') {
    if ($sentStatus === 'neverificat') {
        $sentWhere[] = "(efactura_status IS NULL OR efactura_status = '')";
    } else {
        $sentWhere[] = "efactura_status = ?";
        $sentParams[] = $sentStatus;
    }
}

$sentRows = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, client_name, client_fiscal_code, smartbill_series, smartbill_number, invoice_date,
               gross_amount, currency, efactura_status, efactura_message, last_status_check_at,
               efactura_xml_path, efactura_pdf_path
        FROM smartbill_invoices
        WHERE " . implode(' AND ', $sentWhere) . "
        ORDER BY invoice_date DESC, id DESC
        LIMIT 120
    ");
    $stmt->execute($sentParams);
    $sentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $sentRows = [];
}

$receivedWhere = ["issue_date BETWEEN ? AND ?"];
$receivedParams = [$receivedFrom, $receivedTo];
if ($receivedQ !== '') {
    $receivedWhere[] = "(supplier_name LIKE ? OR supplier_fiscal_code LIKE ? OR document_series LIKE ? OR document_number LIKE ?)";
    $like = '%' . $receivedQ . '%';
    array_push($receivedParams, $like, $like, $like, $like);
}
if ($receivedStatus !== 'all') {
    $receivedWhere[] = "efactura_status = ?";
    $receivedParams[] = $receivedStatus;
}

$receivedRows = [];
try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM smartbill_supplier_invoices
        WHERE " . implode(' AND ', $receivedWhere) . "
        ORDER BY issue_date DESC, id DESC
        LIMIT 120
    ");
    $stmt->execute($receivedParams);
    $receivedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $receivedRows = [];
}
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>E-Factura</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        .ef-page{max-width:1220px;margin:0 auto;display:grid;gap:18px}
        .hero,.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);padding:18px}
        .hero{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
        h1,h2{margin:0;letter-spacing:-.035em}.muted,.hero p{color:var(--muted);font-weight:700}
        .summary{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .summary-card{border:1px solid var(--border);border-radius:14px;background:var(--surface-soft);padding:14px}
        .summary-card strong{display:block;font-size:16px}.summary-card span{display:block;margin-top:5px;color:var(--muted);font-weight:750}
        .filter-grid{display:grid;grid-template-columns:1fr 150px 150px 170px auto;gap:10px;align-items:end;margin-top:14px}
        label{display:block;font-size:12px;font-weight:900;margin:10px 0 6px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        input,select{width:100%;min-height:42px;border:1px solid var(--border);border-radius:12px;padding:10px 12px;font:inherit;font-weight:750;background:#fff;box-sizing:border-box;color:var(--text)}
        table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:10px 9px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:var(--muted)}
        .pill{display:inline-flex;border-radius:999px;padding:5px 9px;background:var(--surface-soft);font-weight:900;color:var(--muted);font-size:12px}
        .downloads{display:flex;gap:6px;flex-wrap:wrap}.download-disabled{opacity:.45;cursor:not-allowed}
        .table-wrap{overflow-x:auto}
        @media(max-width:980px){.summary,.filter-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:720px){.summary,.filter-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('efactura', true); ?>
    <main class="main">
        <div class="content ef-page">
            <section class="hero">
                <div>
                    <h1>E-Factura</h1>
                    <p>Facturi trimise si facturi primite, cu filtre si descarcare XML/PDF cand documentele sunt sincronizate.</p>
                </div>
                <a class="btn ghost" href="smartbill_settings.php">Setări SmartBill</a>
            </section>

            <?php render_billing_module_nav('efactura'); ?>

            <section class="summary">
                <div class="summary-card">
                    <strong>Trimise</strong>
                    <span>Facturile emise din CRM si statusul lor e-Factura/SPV.</span>
                </div>
                <div class="summary-card">
                    <strong>Primite</strong>
                    <span>Facturile de furnizor preluate din SmartBill/SPV cand sincronizarea este disponibila.</span>
                </div>
            </section>

            <section class="card">
                <h2>Facturi trimise</h2>
                <form method="get" class="filter-grid">
                    <div><label>Căutare</label><input type="search" name="sent_q" value="<?= ef_h($sentQ) ?>" placeholder="Client, CUI, serie, numar"></div>
                    <div><label>De la</label><input type="date" name="sent_from" value="<?= ef_h($sentFrom) ?>"></div>
                    <div><label>Pana la</label><input type="date" name="sent_to" value="<?= ef_h($sentTo) ?>"></div>
                    <div><label>Status</label><select name="sent_status"><?php foreach ($sentStatuses as $key => $label): ?><option value="<?= ef_h($key) ?>" <?= $sentStatus === $key ? 'selected' : '' ?>><?= ef_h($label) ?></option><?php endforeach; ?></select></div>
                    <button class="btn accent" type="submit">Filtreaza</button>
                </form>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Client</th>
                                <th>Factura</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Ultima verificare</th>
                                <th>Download</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$sentRows): ?>
                            <tr><td colspan="7" class="muted">Nu există facturi trimise pentru filtrele selectate.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($sentRows as $row): ?>
                            <?php
                                $xmlReady = ef_file_available($row['efactura_xml_path'] ?? '');
                                $pdfReady = ef_file_available($row['efactura_pdf_path'] ?? '');
                            ?>
                            <tr>
                                <td><?= ef_h($row['invoice_date'] ?? '') ?></td>
                                <td><strong><?= ef_h($row['client_name'] ?? '-') ?></strong><div class="muted"><?= ef_h($row['client_fiscal_code'] ?? '') ?></div></td>
                                <td><a href="facturi.php?id=<?= (int)$row['id'] ?>"><?= ef_h(trim((string)(($row['smartbill_series'] ?? '') . ' ' . ($row['smartbill_number'] ?? '')))) ?></a></td>
                                <td><?= number_format((float)($row['gross_amount'] ?? 0), 2, ',', '.') ?> <?= ef_h($row['currency'] ?? 'RON') ?></td>
                                <td>
                                    <span class="pill"><?= ef_h($row['efactura_status'] ?: 'Neverificat') ?></span>
                                    <?php if (!empty($row['efactura_message'])): ?><div class="muted"><?= ef_h($row['efactura_message']) ?></div><?php endif; ?>
                                </td>
                                <td><?= ef_h($row['last_status_check_at'] ?? '-') ?></td>
                                <td>
                                    <div class="downloads">
                                        <?php if ($xmlReady): ?><a class="btn ghost" href="efactura_download.php?scope=sent&id=<?= (int)$row['id'] ?>&format=xml">XML</a><?php else: ?><span class="btn ghost download-disabled">XML</span><?php endif; ?>
                                        <?php if ($pdfReady): ?><a class="btn ghost" href="efactura_download.php?scope=sent&id=<?= (int)$row['id'] ?>&format=pdf">PDF</a><?php else: ?><span class="btn ghost download-disabled">PDF</span><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <h2>Facturi primite</h2>
                <form method="get" class="filter-grid">
                    <div><label>Căutare</label><input type="search" name="received_q" value="<?= ef_h($receivedQ) ?>" placeholder="Furnizor, CUI, serie, numar"></div>
                    <div><label>De la</label><input type="date" name="received_from" value="<?= ef_h($receivedFrom) ?>"></div>
                    <div><label>Pana la</label><input type="date" name="received_to" value="<?= ef_h($receivedTo) ?>"></div>
                    <div><label>Status</label><select name="received_status"><option value="all">Toate</option><?php foreach ($receivedStatuses as $key => $label): ?><option value="<?= ef_h($key) ?>" <?= $receivedStatus === $key ? 'selected' : '' ?>><?= ef_h($label) ?></option><?php endforeach; ?></select></div>
                    <button class="btn accent" type="submit">Filtreaza</button>
                </form>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Furnizor</th>
                                <th>Document</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Sursa</th>
                                <th>Download</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$receivedRows): ?>
                            <tr><td colspan="7" class="muted">Nu există facturi primite sincronizate pentru filtrele selectate.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($receivedRows as $row): ?>
                            <?php
                                $xmlReady = ef_file_available($row['xml_path'] ?? '');
                                $pdfReady = ef_file_available($row['pdf_path'] ?? '');
                            ?>
                            <tr>
                                <td><?= ef_h($row['issue_date'] ?? '') ?></td>
                                <td><strong><?= ef_h($row['supplier_name'] ?? '-') ?></strong><div class="muted"><?= ef_h($row['supplier_fiscal_code'] ?? '') ?></div></td>
                                <td><?= ef_h(trim((string)(($row['document_series'] ?? '') . ' ' . ($row['document_number'] ?? ''))) ?: '-') ?></td>
                                <td><?= number_format((float)($row['gross_amount'] ?? 0), 2, ',', '.') ?> <?= ef_h($row['currency'] ?? 'RON') ?></td>
                                <td><span class="pill"><?= ef_h($receivedStatuses[(string)($row['efactura_status'] ?? '')] ?? ($row['efactura_status'] ?? '')) ?></span></td>
                                <td><?= ef_h($row['source'] ?? 'smartbill') ?></td>
                                <td>
                                    <div class="downloads">
                                        <?php if ($xmlReady): ?><a class="btn ghost" href="efactura_download.php?scope=received&id=<?= (int)$row['id'] ?>&format=xml">XML</a><?php else: ?><span class="btn ghost download-disabled">XML</span><?php endif; ?>
                                        <?php if ($pdfReady): ?><a class="btn ghost" href="efactura_download.php?scope=received&id=<?= (int)$row['id'] ?>&format=pdf">PDF</a><?php else: ?><span class="btn ghost download-disabled">PDF</span><?php endif; ?>
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
