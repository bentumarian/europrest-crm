<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once __DIR__ . '/lib/stock_lib.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
stock_ensure_schema($pdo);

$msg = '';
$err = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $action = $_POST['action'] ?? '';
        if ($action === 'finalize_deferred_pv') {
            $documentId = (int)($_POST['document_id'] ?? 0);
            $qtyMap = [];
            foreach ((array)($_POST['qty'] ?? []) as $matId => $value) {
                $qtyMap[(int)$matId] = $value;
            }
            stock_finalize_deferred_pv($pdo, $documentId, $qtyMap);
            header('Location: stock_deferred_pvs.php?done=' . $documentId);
            exit;
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$activeDocId = (int)($_GET['id'] ?? 0);
$activeDocument = null;
$activeMaterials = [];
if ($activeDocId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND document_type = 'proces_verbal' LIMIT 1");
    $stmt->execute([$activeDocId]);
    $activeDocument = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($activeDocument) {
        $activeMaterials = stock_deferred_pv_materials($pdo, $activeDocId);
    }
}

$deferredList = stock_deferred_pvs_list($pdo);

$justDone = (int)($_GET['done'] ?? 0);
if ($justDone > 0) {
    $msg = 'PV-ul #' . $justDone . ' a fost finalizat. Materialele au fost scoase din stoc cu FIFO automat.';
}

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>PV-uri fără consum - Gestiune</title>
<style>
.dpv-empty { padding: 24px; text-align: center; color: var(--muted, #64748b); }
.dpv-card-row { background: #fffbeb; border-left: 3px solid #b45309; }
.dpv-meta-line { color: var(--muted, #64748b); font-size: 12px; margin-top: 2px; }
.dpv-finalize-banner { background: #fef3c7; border-left: 4px solid #b45309; padding: 12px 16px; border-radius: 6px; margin-bottom: 12px; font-size: 13.5px; }
.dpv-finalize-banner strong { display: block; margin-bottom: 4px; }
.dpv-material-row td { vertical-align: middle; }
.dpv-fifo-info { color: var(--muted, #64748b); font-size: 11.5px; }
.dpv-fifo-info strong { color: var(--text, #111); }
.dpv-no-stock { color: #b42318; font-weight: 700; font-size: 11.5px; }
.dpv-qty-input { max-width: 110px; }
.dpv-qty-unit { color: var(--muted, #64748b); margin-left: 6px; font-size: 12px; }
</style>
</head><body><div class="layout"><?php render_sidebar('stock_deferred_pvs', true); ?><main class="main"><div class="content">
<?php
$dpvActions = $activeDocument ? [["label" => "Înapoi la listă", "href" => "stock_deferred_pvs.php", "variant" => "ghost"]] : [];
render_stock_page_header("deferred_pvs", "PV-uri fără consum (emise în alb)", "PV-uri emise de birou cu cantitățile completate ulterior pe hârtie de tehnician. Aici introduci cantitățile reale și sistemul scoate automat din stoc cu FIFO.", $dpvActions);
?>

<?php if ($msg): ?><div class="notice notice-success"><?= stock_h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice notice-danger"><?= stock_h($err) ?></div><?php endif; ?>

<?php if (!$activeDocument): ?>

    <!-- LISTA -->
    <div class="stock-card">
        <h2 style="margin:0 0 14px;font-size:18px;">Listă PV-uri în așteptare (<?= count($deferredList) ?>)</h2>
        <?php if (!$deferredList): ?>
            <div class="dpv-empty">Niciun PV emis în alb nefinalizat. Toate consumurile sunt închise.</div>
        <?php else: ?>
            <div class="stock-table-wrap">
                <table class="stock-table">
                    <thead>
                        <tr>
                            <th>Nr. PV</th>
                            <th>Data emiterii</th>
                            <th>Client</th>
                            <th>Materiale</th>
                            <th>Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deferredList as $pv): ?>
                            <tr class="dpv-card-row">
                                <td>
                                    <strong><?= stock_h($pv['document_number'] ?: '#' . $pv['id']) ?></strong>
                                    <div class="dpv-meta-line">ID intern: <?= (int)$pv['id'] ?></div>
                                </td>
                                <td>
                                    <?= stock_h($pv['document_date']) ?>
                                    <?php if (!empty($pv['document_time'])): ?>
                                        <div class="dpv-meta-line"><?= stock_h(substr((string)$pv['document_time'], 0, 5)) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= stock_h($pv['client_name_snapshot'] ?: '-') ?></td>
                                <td>
                                    <span class="stock-badge blue"><?= (int)$pv['materials_count'] ?> material(e)</span>
                                </td>
                                <td>
                                    <div class="stock-actions-inline">
                                        <a class="btn btn-mini accent" href="stock_deferred_pvs.php?id=<?= (int)$pv['id'] ?>">Finalizează consum</a>
                                        <a class="btn btn-mini" href="document_view.php?id=<?= (int)$pv['id'] ?>" target="_blank">Vezi PV</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>

    <!-- FINALIZARE -->
    <div class="stock-card">
        <h2 style="margin:0 0 6px;font-size:18px;">
            Finalizează PV <?= stock_h($activeDocument['document_number'] ?: '#' . $activeDocument['id']) ?>
        </h2>
        <div style="color:var(--muted);font-size:13px;">
            <?= stock_h($activeDocument['document_date']) ?>
            <?php if (!empty($activeDocument['document_time'])): ?>
                · <?= stock_h(substr((string)$activeDocument['document_time'], 0, 5)) ?>
            <?php endif; ?>
            · <?= stock_h($activeDocument['client_name_snapshot'] ?: 'Client necunoscut') ?>
        </div>
    </div>

    <div class="dpv-finalize-banner">
        <strong>Cum funcționează finalizarea</strong>
        Introduci pentru fiecare material cantitatea reală consumată de tehnician.
        Sistemul alege automat lotul cu expirare cea mai apropiată (FIFO) și creează mișcările de consum în istoricul de stoc.
        Materialele lăsate cu cantitate 0 sunt eliminate din PV (n-au fost consumate).
    </div>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="finalize_deferred_pv">
        <input type="hidden" name="document_id" value="<?= (int)$activeDocument['id'] ?>">

        <div class="stock-card">
            <h2 style="margin:0 0 14px;font-size:18px;">Materiale (<?= count($activeMaterials) ?>)</h2>

            <?php if (!$activeMaterials): ?>
                <div class="dpv-empty">PV-ul nu are materiale declarate. Nu poate fi finalizat — anulează-l și emite altul.</div>
            <?php else: ?>
                <div class="stock-table-wrap">
                    <table class="stock-table">
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th>Grupă</th>
                                <th>Cantitate consumată</th>
                                <th>Lot FIFO sugerat</th>
                                <th>Disponibil pe lot</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeMaterials as $material):
                                $hasProduct = !empty($material['stock_product_id']);
                                $hasFifo = !empty($material['fifo_lot_id']);
                                $unit = (string)($material['unit_consumption'] ?? '-');
                                $available = (float)($material['fifo_lot_available'] ?? 0);
                            ?>
                                <tr class="dpv-material-row">
                                    <td>
                                        <strong><?= stock_h($material['material_name']) ?></strong>
                                        <?php if (!$hasProduct): ?>
                                            <div class="dpv-no-stock">Produs nerezolvat din nomenclator</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= stock_h(stock_group_label((string)($material['product_group'] ?? '-'))) ?></td>
                                    <td>
                                        <input type="text" inputmode="decimal" class="dpv-qty-input"
                                               name="qty[<?= (int)$material['id'] ?>]"
                                               value="<?= stock_h(stock_fmt_qty($material['quantity'] ?? 0)) ?>"
                                               placeholder="0">
                                        <span class="dpv-qty-unit"><?= stock_h($unit) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($hasFifo): ?>
                                            <div class="dpv-fifo-info">
                                                <strong>Lot <?= stock_h($material['fifo_lot_label']) ?></strong>
                                                <?php if (!empty($material['fifo_lot_expires'])): ?>
                                                    · exp. <?= stock_h($material['fifo_lot_expires']) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($hasProduct): ?>
                                            <span class="dpv-no-stock">Fără stoc disponibil</span>
                                        <?php else: ?>
                                            <span class="dpv-no-stock">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($hasFifo): ?>
                                            <?= stock_h(stock_unit_display($available, $unit)) ?>
                                        <?php else: ?>
                                            <span class="dpv-no-stock">0</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="actions-row">
                    <a class="btn" href="stock_deferred_pvs.php">Renunță</a>
                    <button type="submit" class="btn accent" onclick="return confirm('Finalizez PV-ul? Cantitățile vor fi scăzute din stoc cu FIFO automat și mișcările vor apărea în registrul de consum.');">Finalizează și scade stocul</button>
                </div>
            <?php endif; ?>
        </div>
    </form>

<?php endif; ?>

</div></main></div></body></html>
