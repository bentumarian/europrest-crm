<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once __DIR__ . '/lib/stock_lib.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
stock_ensure_schema($pdo);

$daysAhead = (int)($_GET['days'] ?? 30);
if ($daysAhead < 7) { $daysAhead = 7; }
if ($daysAhead > 180) { $daysAhead = 180; }

$lowStockRows = stock_low_stock_rows($pdo);
$expiringSoonRows = stock_expiring_soon_rows($pdo, $daysAhead);
$expiredWithStockRows = stock_already_expired_with_stock_rows($pdo);

$totalAlerts = count($lowStockRows) + count($expiringSoonRows) + count($expiredWithStockRows);

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Notificări gestiune</title>
<style>
.alert-section { margin-top: 18px; }
.alert-section h2 { margin: 0 0 10px; font-size: 16px; display: flex; align-items: center; gap: 10px; }
.alert-count { background: #b42318; color: #fff; border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 700; }
.alert-count.zero { background: #15803d; }
.alert-count.warn { background: #b45309; }
.days-filter { display: flex; gap: 8px; align-items: center; font-size: 13px; }
.days-filter select { padding: 4px 8px; }
</style>
</head><body><div class="layout"><?php render_sidebar('stock_notifications', true); ?><main class="main"><div class="content">
<?php render_stock_page_header("notifications", "Notificări gestiune", "Alerte automate pentru stoc minim, loturi pe cale să expire și loturi deja expirate cu stoc rămas.", [
    ["label" => "Setează stoc minim", "href" => "stock_products.php", "variant" => "ghost"],
    ["label" => "Adaugă intrare", "href" => "stock_receipts.php", "variant" => "primary"],
]); ?>

<?php if ($totalAlerts === 0): ?>
    <div class="notice notice-success">Nu există alerte active. Stocul este în parametri normali și nu sunt loturi expirate sau pe cale să expire.</div>
<?php else: ?>
    <div class="notice notice-danger">
        <strong>Total alerte: <?= (int)$totalAlerts ?></strong> —
        stoc minim: <?= count($lowStockRows) ?>,
        expirare apropiată: <?= count($expiringSoonRows) ?>,
        deja expirate cu stoc: <?= count($expiredWithStockRows) ?>.
    </div>
<?php endif; ?>

<!-- LOTURI DEJA EXPIRATE - prioritate maximă -->
<section class="alert-section">
    <h2>
        Loturi expirate cu stoc rămas
        <span class="alert-count <?= count($expiredWithStockRows) === 0 ? 'zero' : '' ?>"><?= count($expiredWithStockRows) ?></span>
    </h2>
    <div class="stock-card">
        <?php if (!$expiredWithStockRows): ?>
            <div style="padding:14px;color:var(--muted);font-size:14px;">Niciun lot expirat cu stoc disponibil.</div>
        <?php else: ?>
            <div class="stock-note" style="margin-bottom:10px;color:#b42318;font-weight:600;">Aceste loturi NU mai pot fi folosite în PV. Scoate-le din stoc cu mișcarea „Expirat" sau anulează recepția dacă nu au fost niciodată consumate.</div>
            <div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Produs</th><th>Grupă</th><th>Lot</th><th>Expirat la</th><th>Stoc rămas</th><th>Document</th><th>Acțiuni</th></tr></thead><tbody>
            <?php foreach ($expiredWithStockRows as $r): ?>
                <tr class="stock-alert-row">
                    <td><strong><?= stock_h($r['product_name']) ?></strong></td>
                    <td><?= stock_h(stock_group_label((string)$r['product_group'])) ?></td>
                    <td><?= stock_h($r['lot'] ?: '-') ?></td>
                    <td><span class="stock-badge red"><?= stock_h($r['expires_at']) ?></span></td>
                    <td><strong><?= stock_h(stock_unit_display($r['available_qty'], (string)$r['unit_consumption'])) ?></strong></td>
                    <td><?= stock_h($r['document_no'] ?: '-') ?></td>
                    <td><a class="btn" href="stock_movements.php">Scoate din stoc</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</section>

<!-- LOTURI CARE EXPIRA CURÂND -->
<section class="alert-section">
    <h2>
        Loturi cu expirare apropiată
        <span class="alert-count <?= count($expiringSoonRows) === 0 ? 'zero' : 'warn' ?>"><?= count($expiringSoonRows) ?></span>
        <form method="get" class="days-filter" style="margin-left:auto;">
            <label for="days">Interval:</label>
            <select name="days" id="days" onchange="this.form.submit()">
                <?php foreach ([7, 14, 30, 60, 90, 180] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $daysAhead === $opt ? 'selected' : '' ?>>Următoarele <?= $opt ?> zile</option>
                <?php endforeach; ?>
            </select>
        </form>
    </h2>
    <div class="stock-card">
        <?php if (!$expiringSoonRows): ?>
            <div style="padding:14px;color:var(--muted);font-size:14px;">Niciun lot nu expiră în următoarele <?= (int)$daysAhead ?> zile.</div>
        <?php else: ?>
            <div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Produs</th><th>Grupă</th><th>Lot</th><th>Expiră la</th><th>Zile rămase</th><th>Stoc disponibil</th><th>Acțiuni</th></tr></thead><tbody>
            <?php foreach ($expiringSoonRows as $r):
                $daysLeft = (int)floor((strtotime($r['expires_at']) - strtotime('today')) / 86400);
                $urgent = $daysLeft <= 14;
            ?>
                <tr class="<?= $urgent ? 'stock-alert-row' : '' ?>">
                    <td><strong><?= stock_h($r['product_name']) ?></strong></td>
                    <td><?= stock_h(stock_group_label((string)$r['product_group'])) ?></td>
                    <td><?= stock_h($r['lot'] ?: '-') ?></td>
                    <td><?= stock_h($r['expires_at']) ?></td>
                    <td><span class="stock-badge <?= $urgent ? 'red' : 'blue' ?>"><?= $daysLeft ?> zile</span></td>
                    <td><strong><?= stock_h(stock_unit_display($r['available_qty'], (string)$r['unit_consumption'])) ?></strong></td>
                    <td><a class="btn" href="stock_products.php?edit=<?= (int)$r['product_id'] ?>">Vezi produs</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</section>

<!-- STOC MINIM (alerta existentă) -->
<section class="alert-section">
    <h2>
        Produse la sau sub stocul minim
        <span class="alert-count <?= count($lowStockRows) === 0 ? 'zero' : '' ?>"><?= count($lowStockRows) ?></span>
    </h2>
    <div class="stock-card">
        <?php if (!$lowStockRows): ?>
            <div style="padding:14px;color:var(--muted);font-size:14px;">Nicio alertă de stoc minim activă.</div>
        <?php else: ?>
            <div class="stock-table-wrap"><table class="stock-table"><thead><tr><th>Produs</th><th>Grupă</th><th>Stoc curent</th><th>Stoc minim</th><th>Diferență</th><th>Acțiuni</th></tr></thead><tbody>
            <?php foreach ($lowStockRows as $r): $diff = (float)$r['min_qty'] - (float)$r['current_qty']; ?>
                <tr class="stock-alert-row">
                    <td><strong><?= stock_h($r['name']) ?></strong></td>
                    <td><?= stock_h(stock_group_label($r['product_group'])) ?></td>
                    <td><?= stock_h(stock_unit_display($r['current_qty'], $r['unit_consumption'])) ?></td>
                    <td><?= stock_h(stock_unit_display($r['min_qty'], $r['unit_consumption'])) ?></td>
                    <td><span class="stock-badge red"><?= stock_h(stock_unit_display($diff, $r['unit_consumption'])) ?></span></td>
                    <td>
                        <div class="stock-actions">
                            <a class="btn" href="stock_receipts.php">Adaugă stoc</a>
                            <a class="btn" href="stock_products.php?edit=<?= (int)$r['id'] ?>">Editează minim</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</section>

</div></main></div></body></html>
