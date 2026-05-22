<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once 'stock_lib.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
stock_ensure_schema($pdo);

$dateFrom = stock_date_or_default($_GET['date_from'] ?? $_POST['date_from'] ?? '', date('Y-m-01'));
$dateTo = stock_date_or_default($_GET['date_to'] ?? $_POST['date_to'] ?? '', date('Y-m-t'));
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_issued_pv') {
    try {
        csrf_require();
        [$from, $to] = stock_interval_bounds($dateFrom, $dateTo);
        if (!stock_table_exists($pdo, 'documents') || !stock_table_exists($pdo, 'document_materials')) {
            throw new RuntimeException('Documentele sau materialele PV nu sunt disponibile.');
        }
        $stmt = $pdo->prepare("\n            SELECT id, COALESCE(document_number, CONCAT('#', id)) AS number_label\n            FROM documents\n            WHERE document_type = 'proces_verbal'\n              AND status = 'issued'\n              AND document_date >= ?\n              AND document_date <= ?\n            ORDER BY document_date ASC, id ASC\n        ");
        $stmt->execute([$from, $to]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $synced = 0;
        $skipped = 0;
        $errors = [];

        foreach ($documents as $documentRow) {
            $documentId = (int)$documentRow['id'];
            $before = stock_count_document_consumes($pdo, $documentId);
            if ($before > 0) {
                $skipped++;
                continue;
            }
            try {
                $pdo->beginTransaction();
                stock_consume_document_materials($pdo, $documentId);
                $after = stock_count_document_consumes($pdo, $documentId);
                $pdo->commit();
                if ($after > $before) {
                    $synced++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $syncError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = (string)$documentRow['number_label'] . ': ' . $syncError->getMessage();
            }
        }

        $msg = 'Sincronizare finalizată: ' . $synced . ' PV sincronizate, ' . $skipped . ' fără modificări.';
        if ($errors) {
            $err = implode(' | ', array_slice($errors, 0, 5));
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$rows = stock_registry_rows($pdo, $dateFrom, $dateTo);
app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Registru evidență lucrări</title>
</head><body><div class="layout"><?php render_sidebar('stock', true); ?><main class="main"><div class="topbar"><div style="padding:0 20px;font-weight:900;">Gestiune - Registru lucrări</div></div><div class="content">
<div class="stock-hero"><div><h1>Registru evidență lucrări</h1><p>Raport legal compact, câte o lucrare/produs pe rând, cu toate coloanele obligatorii.</p></div></div>
<?php render_stock_module_nav('registry'); ?>
<?php if ($msg): ?><div class="notice notice-success"><?= stock_h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice notice-danger"><?= stock_h($err) ?></div><?php endif; ?>
<form class="stock-card" method="get">
    <h2 style="margin:0 0 14px;font-size:18px;">Filtre registru</h2>
    <div class="stock-grid">
        <div class="stock-field"><label>Data de la</label><input type="date" name="date_from" value="<?= stock_h($dateFrom) ?>"></div>
        <div class="stock-field"><label>Data până la</label><input type="date" name="date_to" value="<?= stock_h($dateTo) ?>"></div>
    </div>
    <div class="actions-row"><div></div><div class="stock-actions"><button class="btn accent" type="submit">Afișează</button><a class="btn" target="_blank" href="stock_work_registry_export_pdf.php?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>">Export PDF</a><a class="btn" href="stock_export.php?type=registry&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>">Export Excel</a></div></div>
</form>
<form class="stock-card" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="sync_issued_pv">
    <input type="hidden" name="date_from" value="<?= stock_h($dateFrom) ?>">
    <input type="hidden" name="date_to" value="<?= stock_h($dateTo) ?>">
    <h2 style="margin:0 0 8px;font-size:18px;">Sincronizare PV-uri emise</h2>
    <p style="margin:0 0 12px;color:var(--muted);font-size:12.5px;">Folosește acest buton când un PV emis are produse/loturi, dar nu apare încă în registru.</p>
    <div class="actions-row"><div></div><button class="btn accent" type="submit">Sincronizează PV-uri</button></div>
</form>
<div class="stock-note">Datele se completează automat când consumul de produs este legat de procesul verbal. Momentan apar doar mișcările de tip <strong>Consum lucrare/PV</strong> existente în stoc.</div>
<div class="stock-card"><h2 style="margin:0 0 14px;font-size:18px;">Registru - <?= stock_h($dateFrom) ?> - <?= stock_h($dateTo) ?></h2><div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Data</th><th>Beneficiar</th><th>Procedură</th><th>Produs biocid</th><th>Nr. aviz</th><th>Lot</th><th>Cantitate</th><th>Concentrație</th><th>Nr. PV</th><th>Lucrători</th></tr></thead><tbody>
<?php foreach($rows as $r): ?>
<tr><td><?= stock_h($r['procedure_date'] ? date('d.m.Y H:i', strtotime($r['procedure_date'])) : '-') ?></td><td><?= stock_h($r['beneficiary_name'] ?: '-') ?></td><td><?= stock_h(stock_group_label((string)$r['procedure_type'])) ?></td><td><?= stock_h($r['product_name']) ?></td><td><?= stock_h($r['aviz_no'] ?: '-') ?></td><td><?= stock_h($r['lot'] ?: '-') ?></td><td><?= stock_h(stock_unit_display($r['qty'], $r['unit_consumption'])) ?></td><td><?= stock_h($r['work_concentration'] ?: '-') ?></td><td><?= stock_h($r['pv_no'] ?: '-') ?></td><td><?= stock_h($r['workers_names'] ?: '-') ?></td></tr>
<?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="10">Nu există încă înregistrări de consum PV în intervalul selectat.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div></main></div></body></html>
