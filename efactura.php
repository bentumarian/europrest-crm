<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/lib/smartbill_lib.php';
require_once __DIR__ . '/lib/anaf_efactura_lib.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

function ef_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Setări + status conexiune ANAF
anaf_efactura_ensure_schema($pdo);
$anafSettings = anaf_efactura_settings($pdo);
$anafTokenStatus = anaf_efactura_token_status($pdo, (string)($anafSettings['anaf_efactura.cif'] ?? ''));
$anafEnabled = ($anafSettings['anaf_efactura.enabled'] ?? '0') === '1';
$anafConnected = !empty($anafTokenStatus['connected']) && (int)($anafTokenStatus['days_left'] ?? 0) > 0;

// Mesaje feedback din POST → redirect
$syncFeedback = '';
$syncError = '';

// POST handler — sincronizare manuală sau refresh status trimise
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) {
        csrf_require();
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'sync_received') {
        if (!$anafEnabled || !$anafConnected) {
            header('Location: efactura.php?ef_error=' . urlencode('Conexiunea la ANAF nu este activa. Mergi la „Setari e-Factura".'));
            exit;
        }
        $days = max(1, min(60, (int)($anafSettings['anaf_efactura.sync_days'] ?? 30)));
        $result = anaf_efactura_sync_received($pdo, $days);
        if (!empty($result['ok'])) {
            $msg = sprintf('Sincronizare completa. Mesaje verificate: %d. Facturi noi salvate: %d.',
                (int)($result['fetched'] ?? 0), (int)($result['saved'] ?? 0));
            if (!empty($result['errors'])) {
                $msg .= ' Erori partiale: ' . count($result['errors']);
            }
            header('Location: efactura.php?ef_success=' . urlencode($msg));
        } else {
            header('Location: efactura.php?ef_error=' . urlencode((string)($result['error'] ?? 'Eroare necunoscuta')));
        }
        exit;
    }

    if ($action === 'sync_sent_status') {
        if (!$anafEnabled || !$anafConnected) {
            header('Location: efactura.php?ef_error=' . urlencode('Conexiunea la ANAF nu este activa.'));
            exit;
        }
        $result = anaf_efactura_sync_sent_status($pdo, 100);
        if (!empty($result['ok'])) {
            $msg = sprintf('Status facturi trimise actualizat. Verificate: %d. Actualizate: %d.',
                (int)($result['checked'] ?? 0), (int)($result['updated'] ?? 0));
            header('Location: efactura.php?ef_success=' . urlencode($msg));
        } else {
            header('Location: efactura.php?ef_error=' . urlencode((string)($result['error'] ?? 'Eroare necunoscuta')));
        }
        exit;
    }
}

if (isset($_GET['ef_success'])) $syncFeedback = (string)$_GET['ef_success'];
if (isset($_GET['ef_error'])) $syncError = (string)$_GET['ef_error'];

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

function ef_archive_url(string $scope, array $params): string
{
    return 'efactura_archive.php?' . http_build_query(['scope' => $scope, 'format' => 'all'] + $params);
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
        .ef-page{max-width:none;margin:0;display:grid;gap:10px}
        .hero{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:4px 0 2px}
        .hero h1{margin:0;font-size:22px;font-weight:700;color:var(--text);letter-spacing:-.02em;display:flex;align-items:center;gap:8px}
        .hero p{margin:4px 0 0;color:var(--pz-mu);font-weight:600;font-size:12px}
        .hero .actions{display:flex;gap:6px;flex-wrap:wrap}

        .panel{background:var(--pz-surf);border:1px solid var(--pz-line);border-radius:var(--pz-r);box-shadow:none}
        .panel-head{padding:12px 16px;border-bottom:1px solid var(--pz-lines);display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
        .panel-title{font-size:14px;font-weight:800;color:var(--text)}
        .panel-subtitle{font-size:12px;color:var(--pz-mu);margin-top:2px;font-weight:600}
        .panel-body{padding:14px 16px}

        .alert{border-radius:var(--pz-rs);padding:10px 13px;font-weight:600;font-size:12.5px}
        .alert.ok{background:var(--pz-grs);color:var(--pz-gr);border:1px solid var(--pz-grb)}
        .alert.err{background:var(--pz-res);color:var(--pz-re);border:1px solid var(--pz-reb)}
        .alert.warn{background:var(--pz-ors);color:var(--pz-or);border:1px solid var(--pz-orb)}
        .alert a{color:inherit;font-weight:800;text-decoration:underline}

        .conn-status{display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border-radius:999px;border:1px solid var(--pz-line);font-size:11.5px;font-weight:700}
        .conn-status .dot{width:8px;height:8px;border-radius:50%}
        .conn-status.connected{background:var(--pz-grs);color:var(--pz-gr);border-color:var(--pz-grb)}
        .conn-status.connected .dot{background:var(--pz-gr)}
        .conn-status.disconnected{background:var(--pz-res);color:var(--pz-re);border-color:var(--pz-reb)}
        .conn-status.disconnected .dot{background:var(--pz-re)}
        .conn-status.warning{background:var(--pz-ors);color:var(--pz-or);border-color:var(--pz-orb)}
        .conn-status.warning .dot{background:var(--pz-or)}

        .filter-grid{display:grid;grid-template-columns:minmax(220px,1fr) 150px 150px 170px auto;gap:8px;align-items:end}
        label{display:block;font-size:10px;font-weight:800;margin:3px 0 4px;color:var(--pz-mu);text-transform:uppercase;letter-spacing:.04em}
        input,select{width:100%;min-height:32px;border:1px solid var(--pz-line);border-radius:var(--pz-rs);padding:6px 9px;font:inherit;font-size:12.5px;font-weight:600;background:#fff;color:var(--text)}
        input:focus,select:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 3px var(--accent-soft)}

        table{width:100%;border-collapse:collapse;font-size:12.5px}
        th,td{padding:9px 10px;border-bottom:1px solid var(--pz-lines);text-align:left;vertical-align:top}
        th{background:var(--pz-soft);color:var(--pz-mu);font-size:10.5px;text-transform:uppercase;font-weight:700;letter-spacing:.04em}
        tbody tr:hover{background:var(--pz-soft)}
        tbody tr:last-child td{border-bottom:0}
        td a{color:var(--accent-deep);text-decoration:none;font-weight:700}
        td a:hover{text-decoration:underline}

        .pill{display:inline-flex;align-items:center;border-radius:var(--pz-rs);padding:3px 8px;background:var(--pz-soft);font-weight:700;color:var(--pz-mu);font-size:10.5px;text-transform:uppercase;letter-spacing:.04em}
        .pill.src-anaf{background:var(--pz-grs);color:var(--pz-gr);border:1px solid var(--pz-grb)}
        .pill.src-smartbill{background:var(--accent-soft);color:var(--accent-deep);border:1px solid var(--accent-soft-2)}

        .muted{color:var(--pz-mu);font-weight:500;font-size:11.5px;margin-top:2px}
        .downloads{display:flex;gap:5px;flex-wrap:wrap}
        .downloads .btn{min-height:28px;padding:5px 9px;font-size:11.5px}
        .download-disabled{opacity:.45;cursor:not-allowed}
        .table-wrap{overflow-x:auto}

        .export-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .export-card{padding:14px;border:1px solid var(--pz-line);border-radius:var(--pz-rs);background:var(--pz-soft);display:grid;gap:8px}
        .export-card strong{font-size:13px;color:var(--text)}
        .export-card p{margin:0;font-size:11.5px;color:var(--pz-mu);font-weight:600;line-height:1.4}
        .export-card .btn{justify-self:start}

        @media(max-width:980px){.filter-grid{grid-template-columns:1fr 1fr}.export-grid{grid-template-columns:1fr}}
        @media(max-width:640px){.filter-grid{grid-template-columns:1fr}table{font-size:11.5px}th,td{padding:7px 8px}}
    </style>
    <?php render_search_preview_assets(); ?>
</head>
<body>
<div class="layout">
    <?php render_sidebar('efactura', true); ?>
    <main class="main">
        <div class="content ef-page">
            <?php
            // Status indicator pentru hero
            $connClass = 'disconnected';
            $connText = 'Neconectat la ANAF';
            if ($anafEnabled && $anafConnected) {
                $daysLeft = (int)($anafTokenStatus['days_left'] ?? 0);
                if ($daysLeft > 5) {
                    $connClass = 'connected';
                    $connText = 'Conectat ANAF (' . $daysLeft . ' zile)';
                } else {
                    $connClass = 'warning';
                    $connText = 'Token expiră în ' . $daysLeft . ' zile';
                }
            } elseif (!$anafEnabled) {
                $connText = 'Integrare ANAF dezactivată';
            }
            ?>
            <section class="hero">
                <div>
                    <h1>E-Factura <span class="conn-status <?= ef_h($connClass) ?>"><span class="dot"></span><?= ef_h($connText) ?></span></h1>
                    <p>Facturi primite și trimise prin SPV ANAF, cu sincronizare automată și export arhivă.</p>
                </div>
                <div class="actions">
                    <?php if ($anafEnabled && $anafConnected): ?>
                        <form method="post" style="margin:0">
                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                            <input type="hidden" name="action" value="sync_received">
                            <button class="btn accent" type="submit" title="Descarcă facturi primite din ANAF">Sincronizează primite</button>
                        </form>
                        <form method="post" style="margin:0">
                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                            <input type="hidden" name="action" value="sync_sent_status">
                            <button class="btn ghost" type="submit" title="Verifică starea facturilor trimise în ANAF">Refresh status trimise</button>
                        </form>
                    <?php endif; ?>
                    <a class="btn ghost" href="efactura_settings.php">Setări e-Factura</a>
                </div>
            </section>

            <?php render_billing_module_nav('efactura'); ?>

            <?php if ($syncFeedback !== ''): ?><div class="alert ok"><?= ef_h($syncFeedback) ?></div><?php endif; ?>
            <?php if ($syncError !== ''): ?><div class="alert err"><?= ef_h($syncError) ?></div><?php endif; ?>

            <?php if (!$anafEnabled): ?>
                <div class="alert warn">
                    Integrarea ANAF e-Factura nu este activă. <a href="efactura_settings.php">Configurează acum</a> pentru a descărca automat facturile primite și pentru a verifica statusul celor trimise direct prin SPV ANAF.
                </div>
            <?php elseif (!$anafConnected): ?>
                <div class="alert warn">
                    Token-ul ANAF nu este valid sau lipsește. Apasă „Conectează la ANAF" din <a href="efactura_settings.php">Setări e-Factura</a> pentru a te autentifica cu certificatul digital.
                </div>
            <?php elseif (!empty($anafSettings['anaf_efactura.last_sync_at'])): ?>
                <div class="alert ok" style="font-size:11.5px">
                    Ultima sincronizare: <?= ef_h($anafSettings['anaf_efactura.last_sync_at']) ?>
                </div>
            <?php endif; ?>

            <!-- ╔══ Direcția 1: FACTURI PRIMITE ══╗ -->
            <section class="panel" id="primite">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Facturi primite</div>
                        <div class="panel-subtitle">Facturi de la furnizori, descărcate din SPV ANAF.</div>
                    </div>
                </div>
                <div class="panel-body">
                    <form method="get" class="filter-grid" style="margin-bottom:12px">
                        <div><label>Căutare</label>
                            <div class="pz-search-wrap">
                                <input type="search" id="efacturaReceivedSearchInput" name="received_q" value="<?= ef_h($receivedQ) ?>" placeholder="Caută" autocomplete="off">
                                <div class="pz-search-preview"></div>
                            </div>
                        </div>
                        <div><label>De la</label><input type="date" name="received_from" value="<?= ef_h($receivedFrom) ?>"></div>
                        <div><label>Până la</label><input type="date" name="received_to" value="<?= ef_h($receivedTo) ?>"></div>
                        <div><label>Status</label><select name="received_status"><option value="all">Toate</option><?php foreach ($receivedStatuses as $key => $label): ?><option value="<?= ef_h($key) ?>" <?= $receivedStatus === $key ? 'selected' : '' ?>><?= ef_h($label) ?></option><?php endforeach; ?></select></div>
                        <button class="btn accent" type="submit">Aplică</button>
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
                                    <th>Sursă</th>
                                    <th>Descarcă</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$receivedRows): ?>
                                <tr><td colspan="7" class="muted" style="text-align:center;padding:22px">Nu există facturi primite pentru filtrele selectate. Apasă „Sincronizează primite" sus pentru a prelua din ANAF.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($receivedRows as $row): ?>
                                <?php
                                    $xmlReady = ef_file_available($row['xml_path'] ?? '');
                                    $pdfReady = ef_file_available($row['pdf_path'] ?? '');
                                    $source = (string)($row['source'] ?? 'smartbill');
                                    $sourceLabel = $source === 'anaf_direct' ? 'ANAF' : 'SmartBill';
                                    $sourceClass = $source === 'anaf_direct' ? 'src-anaf' : 'src-smartbill';
                                ?>
                                <tr>
                                    <td><?= ef_h($row['issue_date'] ?? '-') ?></td>
                                    <td><strong><?= ef_h($row['supplier_name'] ?? '-') ?></strong><div class="muted"><?= ef_h($row['supplier_fiscal_code'] ?? '') ?></div></td>
                                    <td><?= ef_h(trim((string)(($row['document_series'] ?? '') . ' ' . ($row['document_number'] ?? ''))) ?: '-') ?></td>
                                    <td><?= number_format((float)($row['gross_amount'] ?? 0), 2, ',', '.') ?> <?= ef_h($row['currency'] ?? 'RON') ?></td>
                                    <td><span class="pill"><?= ef_h($receivedStatuses[(string)($row['efactura_status'] ?? '')] ?? ($row['efactura_status'] ?? '')) ?></span></td>
                                    <td><span class="pill <?= ef_h($sourceClass) ?>"><?= ef_h($sourceLabel) ?></span></td>
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
                </div>
            </section>

            <!-- ╔══ Direcția 2: FACTURI TRIMISE ══╗ -->
            <section class="panel" id="trimise">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Facturi trimise</div>
                        <div class="panel-subtitle">Facturile emise din CRM și statusul lor în SPV ANAF.</div>
                    </div>
                </div>
                <div class="panel-body">
                    <form method="get" class="filter-grid" style="margin-bottom:12px">
                        <div><label>Căutare</label>
                            <div class="pz-search-wrap">
                                <input type="search" id="efacturaSentSearchInput" name="sent_q" value="<?= ef_h($sentQ) ?>" placeholder="Caută" autocomplete="off">
                                <div class="pz-search-preview"></div>
                            </div>
                        </div>
                        <div><label>De la</label><input type="date" name="sent_from" value="<?= ef_h($sentFrom) ?>"></div>
                        <div><label>Până la</label><input type="date" name="sent_to" value="<?= ef_h($sentTo) ?>"></div>
                        <div><label>Status</label><select name="sent_status"><?php foreach ($sentStatuses as $key => $label): ?><option value="<?= ef_h($key) ?>" <?= $sentStatus === $key ? 'selected' : '' ?>><?= ef_h($label) ?></option><?php endforeach; ?></select></div>
                        <button class="btn accent" type="submit">Aplică</button>
                    </form>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Client</th>
                                    <th>Factură</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Ultima verificare</th>
                                    <th>Descarcă</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$sentRows): ?>
                                <tr><td colspan="7" class="muted" style="text-align:center;padding:22px">Nu există facturi trimise pentru filtrele selectate.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($sentRows as $row): ?>
                                <?php
                                    $xmlReady = ef_file_available($row['efactura_xml_path'] ?? '');
                                    $pdfReady = ef_file_available($row['efactura_pdf_path'] ?? '');
                                ?>
                                <tr>
                                    <td><?= ef_h($row['invoice_date'] ?? '-') ?></td>
                                    <td><strong><?= ef_h($row['client_name'] ?? '-') ?></strong><div class="muted"><?= ef_h($row['client_fiscal_code'] ?? '') ?></div></td>
                                    <td><a href="invoice.php?id=<?= (int)$row['id'] ?>"><?= ef_h(trim((string)(($row['smartbill_series'] ?? '') . ' ' . ($row['smartbill_number'] ?? '')))) ?></a></td>
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
                </div>
            </section>

            <!-- ╔══ Direcția 3: EXPORT ══╗ -->
            <section class="panel" id="export">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Export arhive</div>
                        <div class="panel-subtitle">Descarcă arhive ZIP cu XML/PDF pentru perioada filtrată mai sus. Util pentru contabilitate și raportări.</div>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="export-grid">
                        <div class="export-card">
                            <strong>Facturi primite</strong>
                            <p>Arhivă ZIP cu XML-urile (și PDF-urile, dacă există) pentru facturile primite filtrate. Perioada: <?= ef_h($receivedFrom) ?> → <?= ef_h($receivedTo) ?>.</p>
                            <a class="btn accent" href="<?= ef_h(ef_archive_url('received', [
                                'received_q' => $receivedQ,
                                'received_status' => $receivedStatus,
                                'received_from' => $receivedFrom,
                                'received_to' => $receivedTo,
                            ])) ?>">Descarcă ZIP primite</a>
                        </div>
                        <div class="export-card">
                            <strong>Facturi trimise</strong>
                            <p>Arhivă ZIP cu XML-urile (și PDF-urile, dacă există) pentru facturile trimise filtrate. Perioada: <?= ef_h($sentFrom) ?> → <?= ef_h($sentTo) ?>.</p>
                            <a class="btn accent" href="<?= ef_h(ef_archive_url('sent', [
                                'sent_q' => $sentQ,
                                'sent_status' => $sentStatus,
                                'sent_from' => $sentFrom,
                                'sent_to' => $sentTo,
                            ])) ?>">Descarcă ZIP trimise</a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>

<?php
// Preview live pentru cele 2 bare „Caută" din e-factura (primite + trimise).
$previewEfReceived = [];
$previewEfSent = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'smartbill_supplier_invoices'")->fetch()) {
        $stmtR = $pdo->query("
            SELECT id, supplier_name, supplier_fiscal_code, invoice_series, invoice_number
            FROM smartbill_supplier_invoices ORDER BY id DESC LIMIT 500
        ");
        while ($r = $stmtR->fetch(PDO::FETCH_ASSOC)) {
            $nm  = html_entity_decode((string)($r['supplier_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cf  = html_entity_decode((string)($r['supplier_fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $ref = trim(((string)$r['invoice_series']) . ' ' . ((string)$r['invoice_number']));
            $previewEfReceived[] = [
                'title'  => ($ref !== '' ? ($ref . ' · ') : '') . ($nm !== '' ? $nm : ('Factură #' . (int)$r['id'])),
                'url'    => 'efactura.php?received_q=' . urlencode($ref !== '' ? $ref : $nm),
                'type'   => 'invoice',
                'search' => $ref . ' ' . $nm . ' ' . $cf,
            ];
        }
    }
    $stmtS = $pdo->query("
        SELECT id, smartbill_series, smartbill_number, client_name, client_fiscal_code
        FROM smartbill_invoices WHERE source_type <> 'receipt'
        ORDER BY id DESC LIMIT 500
    ");
    while ($r = $stmtS->fetch(PDO::FETCH_ASSOC)) {
        $nm  = html_entity_decode((string)($r['client_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cf  = html_entity_decode((string)($r['client_fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ref = trim(((string)$r['smartbill_series']) . ' ' . ((string)$r['smartbill_number']));
        $previewEfSent[] = [
            'title'  => ($ref !== '' ? ($ref . ' · ') : '') . ($nm !== '' ? $nm : ('Factură #' . (int)$r['id'])),
            'url'    => 'invoice.php?id=' . (int)$r['id'],
            'type'   => 'invoice',
            'search' => $ref . ' ' . $nm . ' ' . $cf,
        ];
    }
} catch (Throwable $e) { error_log('efactura.php preview: ' . $e->getMessage()); }
?>
<script>
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('efacturaReceivedSearchInput',
            <?= json_encode($previewEfReceived, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
        window.pzSearchPreview.attach('efacturaSentSearchInput',
            <?= json_encode($previewEfSent, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
    };
    go();
})();
</script>
