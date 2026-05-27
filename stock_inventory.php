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

        if ($action === 'start_inventory') {
            $inventoryId = stock_inventory_create(
                $pdo,
                (string)($_POST['inventory_date'] ?? ''),
                (string)($_POST['group'] ?? ''),
                (string)($_POST['notes'] ?? '')
            );
            header('Location: stock_inventory.php?id=' . $inventoryId);
            exit;
        }

        if ($action === 'finalize_inventory') {
            $inventoryId = (int)($_POST['inventory_id'] ?? 0);
            $countedMap = [];
            foreach ((array)($_POST['counted'] ?? []) as $lineId => $value) {
                $countedMap[(int)$lineId] = $value;
            }
            stock_inventory_finalize($pdo, $inventoryId, $countedMap);
            header('Location: stock_inventory.php?id=' . $inventoryId . '&done=1');
            exit;
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$activeInventoryId = (int)($_GET['id'] ?? 0);
$activeInventory = $activeInventoryId > 0 ? stock_inventory_get($pdo, $activeInventoryId) : null;
$activeLines = $activeInventory ? stock_inventory_lines($pdo, $activeInventoryId) : [];
$justFinalized = !empty($_GET['done']);

$inventories = stock_inventory_list($pdo, 30);
$groups = stock_group_options();

app_theme_css();
?>
<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Inventar fizic - Gestiune</title>
<style>
.inv-step-card { background: #f3f6fb; border-left: 4px solid var(--accent, #2563eb); padding: 12px 16px; border-radius: 6px; margin-bottom: 12px; font-size: 13.5px; }
.inv-step-card strong { display: block; margin-bottom: 4px; }
.inv-line-bio { background: #fdf6ec; }
.inv-line-material { background: #f6f9fb; }
.inv-diff-cell { font-weight: 700; }
.inv-diff-pos { color: #15803d; }
.inv-diff-neg { color: #b42318; }
.inv-input-counted { max-width: 110px; }
.inv-status-draft { background: #fffbeb; color: #b45309; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.inv-status-finalized { background: #ecfdf5; color: #15803d; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
</style>
</head><body><div class="layout"><?php render_sidebar('stock_inventory', true); ?><main class="main"><div class="content">
<?php
$invActions = !$activeInventory
    ? [["label" => "Vezi mișcările", "href" => "stock_movements.php", "variant" => "ghost"]]
    : [["label" => "Listă inventare", "href" => "stock_inventory.php", "variant" => "ghost"]];
render_stock_page_header("inventory", "Inventar fizic", "Numără stocul real, sistemul calculează diferențele și generează automat ajustări plus/minus în istoric.", $invActions);
?>

<?php if ($msg): ?><div class="notice notice-success"><?= stock_h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="notice notice-danger"><?= stock_h($err) ?></div><?php endif; ?>
<?php if ($justFinalized && $activeInventory && $activeInventory['status'] === 'finalized'): ?>
    <div class="notice notice-success">
        Inventar finalizat. <?= (int)$activeInventory['lines_with_diff'] ?> diferențe ajustate
        (<?= (int)$activeInventory['positive_adjustments'] ?> plus, <?= (int)$activeInventory['negative_adjustments'] ?> minus).
        Mișcările apar în istoric cu referința „inventory #<?= (int)$activeInventory['id'] ?>".
    </div>
<?php endif; ?>

<?php if (!$activeInventory): ?>
    <!-- PASUL 1: Form de start -->
    <form class="stock-card" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="start_inventory">
        <h2 style="margin:0 0 6px;font-size:18px;">Pornește un inventar nou</h2>
        <div class="inv-step-card">
            <strong>Cum funcționează inventarul</strong>
            Sistemul îți afișează lista de produse (cu lot pentru biocide) și cantitatea așteptată conform mișcărilor înregistrate.
            Tu numeri stocul real și completezi „Cantitate numărată". La finalizare, pentru fiecare diferență (counted ≠ expected) se generează automat o mișcare de ajustare plus sau minus, fără să modifici manual nimic. Liniile lăsate goale nu primesc ajustări.
        </div>
        <div class="stock-grid-3" style="margin-top:14px;">
            <div class="stock-field"><label>Data inventarului *</label><input type="date" name="inventory_date" required value="<?= date('Y-m-d') ?>"></div>
            <div class="stock-field"><label>Grupă (opțional)</label>
                <select name="group">
                    <option value="">Toate grupele</option>
                    <?php foreach ($groups as $k => $v): ?>
                        <option value="<?= stock_h($k) ?>"><?= stock_h($v) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Limitează inventarul la o singură grupă (ex. doar Deratizare).</small>
            </div>
            <div class="stock-field"><label>Notițe</label><input name="notes" placeholder="Ex: inventar trimestrial Q2"></div>
        </div>
        <div class="actions-row"><div></div><button type="submit" class="btn accent">Pornește inventarul</button></div>
    </form>

    <!-- Lista inventarelor anterioare -->
    <div class="stock-card">
        <h2 style="margin:0 0 14px;font-size:18px;">Inventare recente</h2>
        <div class="stock-table-wrap">
            <table class="stock-table">
                <thead><tr><th>Data</th><th>Grupă</th><th>Status</th><th>Linii</th><th>Diferențe</th><th>+/-</th><th>Creat de</th><th>Notițe</th><th>Acțiuni</th></tr></thead>
                <tbody>
                    <?php foreach ($inventories as $inv): ?>
                    <tr>
                        <td><?= stock_h($inv['inventory_date']) ?><br><span style="color:var(--muted);font-size:11px;"><?= stock_h($inv['created_at']) ?></span></td>
                        <td><?= !empty($inv['product_group']) ? stock_h(stock_group_label((string)$inv['product_group'])) : 'Toate' ?></td>
                        <td>
                            <?php if ($inv['status'] === 'finalized'): ?>
                                <span class="inv-status-finalized">Finalizat</span>
                            <?php else: ?>
                                <span class="inv-status-draft">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$inv['total_lines'] ?></td>
                        <td><?= (int)$inv['lines_with_diff'] ?></td>
                        <td><span style="color:#15803d;">+<?= (int)$inv['positive_adjustments'] ?></span> / <span style="color:#b42318;">-<?= (int)$inv['negative_adjustments'] ?></span></td>
                        <td><?= stock_h($inv['created_by_name'] ?? '-') ?></td>
                        <td style="max-width:240px;"><?= stock_h($inv['notes'] ?? '-') ?></td>
                        <td><a class="btn" href="stock_inventory.php?id=<?= (int)$inv['id'] ?>"><?= $inv['status'] === 'finalized' ? 'Vezi' : 'Continuă' ?></a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$inventories): ?><tr><td colspan="9">Niciun inventar înregistrat încă.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <!-- PASUL 2: Numărătoare + finalizare -->
    <?php $isFinalized = $activeInventory['status'] === 'finalized'; ?>

    <div class="stock-card">
        <h2 style="margin:0 0 6px;font-size:18px;">
            Inventar #<?= (int)$activeInventory['id'] ?> · <?= stock_h($activeInventory['inventory_date']) ?>
            <?php if ($isFinalized): ?>
                <span class="inv-status-finalized">Finalizat</span>
            <?php else: ?>
                <span class="inv-status-draft">Draft - de completat</span>
            <?php endif; ?>
        </h2>
        <div style="color:var(--muted);font-size:13px;">
            <?= !empty($activeInventory['product_group']) ? 'Grupă: ' . stock_h(stock_group_label((string)$activeInventory['product_group'])) : 'Toate grupele' ?>
            <?php if (!empty($activeInventory['notes'])): ?>· <?= stock_h($activeInventory['notes']) ?><?php endif; ?>
            <?php if ($isFinalized): ?>· Finalizat la <?= stock_h($activeInventory['finalized_at']) ?><?php endif; ?>
        </div>
    </div>

    <?php if (!$isFinalized): ?>
        <div class="inv-step-card">
            <strong>Completează „Cantitate numărată" pentru fiecare linie</strong>
            Sistemul calculează diferența și, la finalizare, generează automat ajustări plus/minus în istoric. Liniile lăsate goale sunt sărite. Numărătoarea este per lot pentru biocide și per produs pentru materiale.
        </div>
    <?php endif; ?>

    <form method="post" id="invForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="finalize_inventory">
        <input type="hidden" name="inventory_id" value="<?= (int)$activeInventory['id'] ?>">

        <div class="stock-card">
            <h2 style="margin:0 0 14px;font-size:18px;">Linii de inventariat (<?= count($activeLines) ?>)</h2>
            <div class="stock-table-wrap">
                <table class="stock-table">
                    <thead>
                        <tr>
                            <th>Produs</th>
                            <th>Grupă</th>
                            <th>Lot</th>
                            <th>Expirare</th>
                            <th>UM</th>
                            <th>Cantitate așteptată</th>
                            <th>Cantitate numărată</th>
                            <th>Diferență</th>
                            <?php if ($isFinalized): ?><th>Ajustare</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeLines as $line):
                            $isBio = stock_is_biocide_group((string)$line['product_group']);
                            $rowClass = $isBio ? 'inv-line-bio' : 'inv-line-material';
                            $counted = $line['counted_qty'];
                            $diff = $line['difference'];
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td><strong><?= stock_h($line['product_name']) ?></strong></td>
                            <td><?= stock_h(stock_group_label((string)$line['product_group'])) ?></td>
                            <td><?= stock_h($line['lot'] ?? '-') ?></td>
                            <td><?= stock_h($line['expires_at'] ?? '-') ?></td>
                            <td><?= stock_h($line['unit_consumption']) ?></td>
                            <td><strong><?= stock_h(stock_fmt_qty($line['expected_qty'])) ?></strong></td>
                            <td>
                                <?php if ($isFinalized): ?>
                                    <?= $counted !== null ? stock_h(stock_fmt_qty($counted)) : '<span style="color:var(--muted);">nenumărat</span>' ?>
                                <?php else: ?>
                                    <input type="text" inputmode="decimal" class="inv-input-counted"
                                           name="counted[<?= (int)$line['id'] ?>]"
                                           value="<?= $counted !== null ? stock_h(stock_fmt_qty($counted)) : '' ?>"
                                           placeholder="lasă gol = sări"
                                           data-expected="<?= stock_h(stock_fmt_qty($line['expected_qty'])) ?>"
                                           onchange="invUpdateDiff(this)">
                                <?php endif; ?>
                            </td>
                            <td class="inv-diff-cell">
                                <?php if ($diff !== null && $isFinalized):
                                    $diffVal = (float)$diff;
                                    if (abs($diffVal) < 0.0001):
                                ?>
                                    <span style="color:var(--muted);">0</span>
                                <?php elseif ($diffVal > 0): ?>
                                    <span class="inv-diff-pos">+<?= stock_h(stock_fmt_qty($diffVal)) ?></span>
                                <?php else: ?>
                                    <span class="inv-diff-neg"><?= stock_h(stock_fmt_qty($diffVal)) ?></span>
                                <?php endif; ?>
                                <?php elseif (!$isFinalized): ?>
                                    <span class="diff-preview" style="color:var(--muted);font-weight:400;">-</span>
                                <?php else: ?>
                                    <span style="color:var(--muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($isFinalized): ?>
                                <td>
                                    <?php if (!empty($line['movement_id'])): ?>
                                        <span class="stock-badge blue">mișcare #<?= (int)$line['movement_id'] ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--muted);font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$isFinalized): ?>
                <div class="actions-row">
                    <a class="btn" href="stock_inventory.php" onclick="return confirm('Inventarul rămâne in stare draft. Sigur ieși fără să finalizezi?');">Iese fără finalizare</a>
                    <button type="submit" class="btn accent" onclick="return confirm('Finalizez inventarul? Diferențele vor genera automat ajustări plus/minus în stoc.');">Finalizează inventarul</button>
                </div>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

</div></main></div>
<script>
function invFmtNumber(v){v=(v||'').toString().replace(',', '.');var n=parseFloat(v);return isNaN(n)?null:n;}
function invUpdateDiff(input){
    var counted = invFmtNumber(input.value);
    var expected = invFmtNumber(input.dataset.expected);
    var preview = input.closest('tr').querySelector('.diff-preview');
    if(!preview) return;
    if(counted === null){preview.textContent='-';preview.style.color='var(--muted)';preview.style.fontWeight='400';return;}
    var diff = Math.round((counted - expected) * 1000) / 1000;
    if(Math.abs(diff) < 0.0001){preview.textContent='0';preview.style.color='var(--muted)';preview.style.fontWeight='400';}
    else if(diff > 0){preview.textContent='+'+diff;preview.style.color='#15803d';preview.style.fontWeight='700';}
    else{preview.textContent=String(diff);preview.style.color='#b42318';preview.style.fontWeight='700';}
}
</script>
</body></html>
