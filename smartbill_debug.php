<?php
/*
|--------------------------------------------------------------------------
| SmartBill — diagnostic ultimele apeluri
|--------------------------------------------------------------------------
| Pagină de debug pentru a vedea exact ce s-a trimis și ce a întors SmartBill
| la ultimele acțiuni (emitere factură, încasare, ștergere chitanță).
|
| Util când apar erori de validare ANAF/SmartBill ca de exemplu
| „Ciful clientului de pe factura ... difera de ciful clientului incasarii".
|
| Acces: doar administrator. Nu se scrie nimic — read-only.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

function sbd_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sbd_pretty(?string $json): string
{
    if ($json === null || $json === '') return '(gol)';
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) return $json;
    return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$actionFilter = (string)($_GET['action'] ?? 'all');
$allowedActions = ['all', 'issue_invoice', 'issue_payment', 'delete_receipt', 'cancel_invoice', 'reverse_invoice', 'draft_saved', 'send_email'];
if (!in_array($actionFilter, $allowedActions, true)) $actionFilter = 'all';

$limit = max(5, min(100, (int)($_GET['limit'] ?? 20)));

$where = '';
$params = [];
if ($actionFilter !== 'all') {
    $where = 'WHERE action = ?';
    $params[] = $actionFilter;
}

$stmt = $pdo->prepare("
    SELECT l.*,
           i.smartbill_series, i.smartbill_number, i.client_name, i.client_fiscal_code,
           i.appointment_id AS invoice_appointment_id,
           a.id AS appointment_id_real, a.billing_status AS appointment_billing_status,
           a.status AS appointment_status, a.appointment_date, a.start_time
    FROM smartbill_invoice_logs l
    LEFT JOIN smartbill_invoices i ON i.id = l.smartbill_invoice_id
    LEFT JOIN appointments a ON a.id = i.appointment_id
    {$where}
    ORDER BY l.id DESC
    LIMIT {$limit}
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>SmartBill — Diagnostic</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        .dbg-page { max-width:none; margin:0; display:grid; gap:10px; }
        .hero { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; padding:4px 0 2px; }
        .hero h1 { margin:0; font-size:22px; font-weight:700; color:var(--text); }
        .hero p { margin:4px 0 0; color:var(--pz-mu); font-weight:600; font-size:12px; }
        .panel { background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:var(--pz-r); }
        .panel-head { padding:12px 14px; border-bottom:1px solid var(--pz-lines); display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; }
        .panel-title { font-size:13px; font-weight:800; color:var(--text); }
        .panel-body { padding:14px; }
        .filters { display:flex; gap:8px; flex-wrap:wrap; align-items:end; }
        label { display:block; font-size:10px; font-weight:800; margin:0 0 4px; color:var(--pz-mu); text-transform:uppercase; letter-spacing:.04em; }
        select, input { min-height:32px; border:1px solid var(--pz-line); border-radius:var(--pz-rs); padding:6px 9px; font:inherit; font-size:12.5px; font-weight:600; background:#fff; color:var(--text); }
        .log-card { border:1px solid var(--pz-line); border-radius:var(--pz-r); margin-bottom:12px; overflow:hidden; }
        .log-head { padding:10px 14px; background:var(--pz-soft); display:grid; grid-template-columns:120px 1fr auto; gap:14px; align-items:center; }
        .log-head .meta { font-size:11.5px; color:var(--pz-mu); font-weight:600; }
        .log-head strong { font-size:13px; color:var(--text); }
        .log-status { display:inline-flex; align-items:center; padding:3px 8px; border-radius:999px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
        .log-status.error { background:var(--pz-res); color:var(--pz-re); border:1px solid var(--pz-reb); }
        .log-status.ok { background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); }
        .log-status.draft { background:var(--pz-ors); color:var(--pz-or); border:1px solid var(--pz-orb); }
        .log-body { display:grid; grid-template-columns:1fr 1fr; gap:0; }
        .log-section { padding:12px 14px; border-top:1px solid var(--pz-lines); }
        .log-section + .log-section { border-left:1px solid var(--pz-lines); }
        .log-section h3 { margin:0 0 8px; font-size:11px; font-weight:800; color:var(--pz-mu); text-transform:uppercase; letter-spacing:.04em; }
        .log-section pre { margin:0; padding:10px; background:#fafbfc; border:1px solid var(--pz-line); border-radius:var(--pz-rs); font-size:11.5px; line-height:1.45; font-family:ui-monospace,Menlo,Monaco,Consolas,monospace; max-height:400px; overflow:auto; white-space:pre-wrap; word-break:break-word; }
        .log-message { color:var(--pz-mu); font-weight:600; font-size:12px; padding:8px 14px; background:#fafbfc; border-top:1px solid var(--pz-lines); }
        .log-message.error { background:var(--pz-res); color:var(--pz-re); }
        @media(max-width:980px) { .log-body { grid-template-columns:1fr; } .log-section + .log-section { border-left:0; } .log-head { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('facturi', true); ?>
    <main class="main">
        <div class="content dbg-page">
            <section class="hero">
                <div>
                    <h1>SmartBill — Diagnostic</h1>
                    <p>Ultimele apeluri către SmartBill API: payload trimis + răspuns primit. Util pentru debug erori.</p>
                </div>
                <div class="filters">
                    <a class="btn ghost" href="invoices.php">Înapoi la Facturi</a>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Filtre</div>
                    <form method="get" class="filters">
                        <div>
                            <label>Acțiune</label>
                            <select name="action">
                                <option value="all" <?= $actionFilter === 'all' ? 'selected' : '' ?>>Toate</option>
                                <option value="issue_invoice" <?= $actionFilter === 'issue_invoice' ? 'selected' : '' ?>>Emitere factură</option>
                                <option value="issue_payment" <?= $actionFilter === 'issue_payment' ? 'selected' : '' ?>>Încasare</option>
                                <option value="delete_receipt" <?= $actionFilter === 'delete_receipt' ? 'selected' : '' ?>>Ștergere chitanță</option>
                                <option value="cancel_invoice" <?= $actionFilter === 'cancel_invoice' ? 'selected' : '' ?>>Anulare factură</option>
                                <option value="reverse_invoice" <?= $actionFilter === 'reverse_invoice' ? 'selected' : '' ?>>Storno</option>
                                <option value="draft_saved" <?= $actionFilter === 'draft_saved' ? 'selected' : '' ?>>Salvare draft</option>
                                <option value="send_email" <?= $actionFilter === 'send_email' ? 'selected' : '' ?>>Email</option>
                            </select>
                        </div>
                        <div>
                            <label>Limită</label>
                            <input type="number" name="limit" min="5" max="100" value="<?= (int)$limit ?>">
                        </div>
                        <button class="btn accent" type="submit">Aplică</button>
                    </form>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Ultimele <?= (int)count($logs) ?> înregistrări</div>
                </div>
                <div class="panel-body">
                    <?php if (!$logs): ?>
                        <p class="muted">Nu există log-uri pentru filtrele selectate.</p>
                    <?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                            $status = (string)($log['status'] ?? '');
                            $statusClass = $status === 'error' ? 'error' : (in_array($status, ['issued', 'paid', 'partial', 'ok'], true) ? 'ok' : 'draft');
                            $invoiceRef = trim((string)(($log['smartbill_series'] ?? '') . ' ' . ($log['smartbill_number'] ?? '')));
                        ?>
                        <article class="log-card">
                            <div class="log-head">
                                <div>
                                    <div class="meta">#<?= (int)$log['id'] ?> · <?= sbd_h($log['created_at']) ?></div>
                                    <strong><?= sbd_h($log['action']) ?></strong>
                                </div>
                                <div>
                                    <strong><?= sbd_h($log['client_name'] ?: '-') ?></strong>
                                    <div class="meta">
                                        <?= sbd_h($log['client_fiscal_code'] ?: '-') ?>
                                        <?php if ($invoiceRef !== ''): ?> · <?= sbd_h($invoiceRef) ?><?php endif; ?>
                                    </div>
                                </div>
                                <span class="log-status <?= sbd_h($statusClass) ?>"><?= sbd_h($status ?: '—') ?></span>
                            </div>
                            <?php if (!empty($log['message'])): ?>
                                <div class="log-message <?= $status === 'error' ? 'error' : '' ?>"><?= sbd_h($log['message']) ?></div>
                            <?php endif; ?>
                            <div class="log-body">
                                <div class="log-section">
                                    <h3>Request trimis la SmartBill</h3>
                                    <pre><?= sbd_h(sbd_pretty($log['request_json'] ?? null)) ?></pre>
                                </div>
                                <div class="log-section">
                                    <h3>Răspuns SmartBill</h3>
                                    <pre><?= sbd_h(sbd_pretty($log['response_json'] ?? null)) ?></pre>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
