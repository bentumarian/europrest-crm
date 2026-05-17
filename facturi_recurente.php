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

function recr_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

pz_smartbill_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) {
        csrf_require();
    }
    $action = (string)($_POST['action'] ?? '');
    $recurringId = max(0, (int)($_POST['recurring_id'] ?? 0));

    if ($action === 'generate' && $recurringId > 0) {
        $result = pz_smartbill_generate_recurring_invoice($pdo, $recurringId);
        if (!empty($result['ok'])) {
            header('Location: facturi_recurente.php?generated=1&invoice_id=' . (int)$result['invoice_id']);
            exit;
        }
        header('Location: facturi_recurente.php?error=' . urlencode((string)($result['error'] ?? 'Factura recurenta nu a putut fi generata.')));
        exit;
    }

    if (($action === 'pause' || $action === 'resume') && $recurringId > 0) {
        $status = $action === 'pause' ? 'paused' : 'active';
        $stmt = $pdo->prepare("UPDATE smartbill_recurring_invoices SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $recurringId]);
        header('Location: facturi_recurente.php?updated=1');
        exit;
    }
}

$rows = [];
try {
    $rows = $pdo->query("
        SELECT r.*, i.client_name, i.gross_amount, i.currency, i.smartbill_series, i.smartbill_number
        FROM smartbill_recurring_invoices r
        LEFT JOIN smartbill_invoices i ON i.id = r.template_invoice_id
        ORDER BY FIELD(r.status, 'active', 'paused', 'ended'), r.next_issue_date ASC, r.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}

$frequencyLabels = [
    'weekly' => 'Saptamanal',
    'monthly' => 'Lunar',
    'quarterly' => 'Trimestrial',
    'yearly' => 'Anual',
];
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Facturi recurente</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        .rec-page{max-width:1180px;margin:0 auto;display:grid;gap:16px}
        .hero,.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);padding:18px}
        .hero{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}
        h1,h2{margin:0;letter-spacing:-.035em}.hero p,.muted{color:var(--muted);font-weight:700}
        table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:var(--muted)}
        .pill{display:inline-flex;border-radius:999px;padding:5px 9px;background:var(--surface-soft);font-weight:900;color:var(--muted);font-size:12px}
        .pill.active{background:var(--success-soft);color:var(--success)}.pill.paused{background:var(--warning-soft);color:var(--warning)}
        .alert{border-radius:14px;padding:12px 14px;font-weight:850}.ok{background:var(--success-soft);color:var(--success)}.err{background:var(--danger-soft);color:var(--danger)}
        .actions{display:flex;gap:7px;flex-wrap:wrap}
        @media(max-width:860px){.table-wrap{overflow-x:auto}table{min-width:900px}}
    </style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('facturi_recurente', true); ?>
    <main class="main">
        <div class="content rec-page">
            <section class="hero">
                <div>
                    <h1>Facturi recurente</h1>
                    <p>Modele de facturare periodica pentru abonamente, contracte sau servicii repetate.</p>
                </div>
                <a class="btn ghost" href="facturi.php">Factura noua</a>
            </section>

            <?php if (isset($_GET['created'])): ?><div class="alert ok">Recurența a fost creata.</div><?php endif; ?>
            <?php if (isset($_GET['updated'])): ?><div class="alert ok">Recurența a fost actualizată.</div><?php endif; ?>
            <?php if (isset($_GET['generated'])): ?><div class="alert ok">Factura recurenta a fost generata. <a href="facturi.php?id=<?= (int)($_GET['invoice_id'] ?? 0) ?>">Deschide factura</a></div><?php endif; ?>
            <?php if (isset($_GET['error'])): ?><div class="alert err"><?= recr_h($_GET['error']) ?></div><?php endif; ?>

            <section class="card">
                <h2>Programări recurente</h2>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Status</th><th>Model</th><th>Client</th><th>Frecvență</th><th>Următoarea emitere</th><th>Automatizări</th><th>Acțiuni</th></tr></thead>
                        <tbody>
                        <?php if (!$rows): ?><tr><td colspan="7" class="muted">Nu există facturi recurente.</td></tr><?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><span class="pill <?= recr_h($row['status'] ?? '') ?>"><?= recr_h($row['status'] ?? '') ?></span></td>
                                <td>
                                    <strong><?= recr_h($row['title'] ?? 'Factura recurenta') ?></strong>
                                    <div class="muted">Model #<?= (int)($row['template_invoice_id'] ?? 0) ?> <?= trim((string)($row['smartbill_number'] ?? '')) !== '' ? recr_h(trim((string)(($row['smartbill_series'] ?? '') . ' ' . ($row['smartbill_number'] ?? '')))) : '' ?></div>
                                </td>
                                <td><?= recr_h($row['client_name'] ?? '-') ?><div class="muted"><?= number_format((float)($row['gross_amount'] ?? 0), 2, ',', '.') ?> <?= recr_h($row['currency'] ?? 'RON') ?></div></td>
                                <td><?= recr_h($frequencyLabels[(string)($row['frequency'] ?? '')] ?? $row['frequency'] ?? '-') ?><div class="muted">Interval: <?= (int)($row['interval_value'] ?? 1) ?></div></td>
                                <td><?= recr_h($row['next_issue_date'] ?? '-') ?></td>
                                <td>
                                    <span class="pill"><?= !empty($row['auto_issue']) ? 'emite automat' : 'draft manual' ?></span>
                                    <span class="pill"><?= !empty($row['auto_email']) ? 'email automat' : 'email manual' ?></span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <a class="btn ghost" href="facturi.php?id=<?= (int)($row['template_invoice_id'] ?? 0) ?>">Model</a>
                                        <form method="post" style="margin:0">
                                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                            <input type="hidden" name="action" value="generate">
                                            <input type="hidden" name="recurring_id" value="<?= (int)$row['id'] ?>">
                                            <button class="btn accent" type="submit">Genereaza acum</button>
                                        </form>
                                        <form method="post" style="margin:0">
                                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                            <input type="hidden" name="action" value="<?= ($row['status'] ?? '') === 'active' ? 'pause' : 'resume' ?>">
                                            <input type="hidden" name="recurring_id" value="<?= (int)$row['id'] ?>">
                                            <button class="btn ghost" type="submit"><?= ($row['status'] ?? '') === 'active' ? 'Pauza' : 'Activeaza' ?></button>
                                        </form>
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
