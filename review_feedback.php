<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/review_lib.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Acces permis doar administratorului.');
}

pz_review_init();

$scanResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'run_scan') {
        $scanResult = pz_review_scan_and_send(80);
    } elseif ($action === 'send_manual') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        if ($appointmentId > 0) {
            $scanResult = ['manual' => pz_review_create_and_send($appointmentId, true)];
        }
    }
}

$pdo = pz_review_db();
$status = trim((string)($_GET['status'] ?? ''));
$rating = trim((string)($_GET['rating'] ?? ''));
$where = [];
$params = [];
if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
if ($rating !== '') { $where[] = 'rating = ?'; $params[] = (int)$rating; }
$sql = 'SELECT * FROM review_requests';
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY id DESC LIMIT 250';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats = ['total'=>0,'sent'=>0,'opened'=>0,'rated'=>0,'completed'=>0,'five'=>0,'low'=>0];
try {
    $all = $pdo->query('SELECT status, rating, completed_at FROM review_requests')->fetchAll(PDO::FETCH_ASSOC);
    $stats['total'] = count($all);
    foreach ($all as $r) {
        if (($r['status'] ?? '') === 'sent') $stats['sent']++;
        if (!empty($r['opened_at']) || in_array(($r['status'] ?? ''), ['opened','rated','completed','google_clicked'], true)) $stats['opened']++;
        if ((int)($r['rating'] ?? 0) > 0) $stats['rated']++;
        if (!empty($r['completed_at'])) $stats['completed']++;
        if ((int)($r['rating'] ?? 0) === 5) $stats['five']++;
        if ((int)($r['rating'] ?? 0) >= 1 && (int)($r['rating'] ?? 0) <= 4) $stats['low']++;
    }
} catch (Throwable $e) {}

function rf_status_label(string $status): string {
    $map = [
        'created'=>'creat','sent'=>'trimis','skipped'=>'sarita','failed'=>'esuat','opened'=>'deschis','rated'=>'evaluat','completed'=>'formular completat','google_clicked'=>'google accesat'
    ];
    return $map[$status] ?? $status;
}

function rf_date($value): string {
    $value = trim((string)$value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts ? date('d.m.Y H:i', $ts) : $value;
}
$autoBase = pz_review_public_base_url();
$demoLowUrl = ($autoBase !== '') ? ($autoBase . '/feedback.php?demo=low') : 'feedback.php?demo=low';
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Feedback clienti</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.page{max-width:1320px;margin:0 auto;display:flex;flex-direction:column;gap:16px}.hero{background:var(--surface);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);padding:22px 24px;display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.hero h1{margin:0;font-size:26px;letter-spacing:-.04em}.hero p{margin:6px 0 0;color:var(--muted)}.btn{border:0;border-radius:14px;background:var(--accent);color:#fff;font-weight:800;padding:11px 14px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.btn.ghost{background:#fff;color:var(--text);border:1px solid var(--border)}.cards{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}.stat{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);padding:14px}.stat span{display:block;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.05em;font-weight:800}.stat strong{display:block;font-size:24px;letter-spacing:-.04em;margin-top:4px}.panel{background:var(--surface);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);overflow:hidden}.filters{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;flex-wrap:wrap;align-items:center}.filters select,.filters input{border:1px solid var(--border);border-radius:12px;padding:10px 12px;font:inherit;background:#fff}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:980px}th,td{padding:13px 14px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}th{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;background:#f8fafc}td{font-size:13px}.client{font-weight:800}.muted{color:var(--muted);font-size:12px}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;border:1px solid var(--border);background:#fff}.badge.good{background:#ecfdf5;color:#047857;border-color:#bbf7d0}.badge.warn{background:#fffbeb;color:#b45309;border-color:#fde68a}.badge.bad{background:#fef2f2;color:#dc2626;border-color:#fecaca}.notice{border-radius:14px;padding:12px 14px;font-weight:650;background:#EEF8FF;color:#002050;border:1px solid #B1D6F0}.answers{font-size:12px;color:var(--muted);line-height:1.55}.actions{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:900px){.cards{grid-template-columns:repeat(2,1fr)}.hero{align-items:flex-start}.page{max-width:100%}}@media(max-width:600px){.cards{grid-template-columns:1fr}.hero{padding:16px}}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('reports', true); ?>
    <main class="main">
        <div class="topbar" style="padding:12px 20px;"><strong>Feedback clienti</strong></div>
        <div class="content page">
            <section class="hero">
                <div>
                    <h1>Feedback clienti</h1>
                    <p>Monitorizeaza solicitarile de review, ratingurile si formularele de satisfactie.</p>
                </div>
                <div class="actions">
                    <form method="post" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="run_scan">
                        <button class="btn" type="submit">Ruleaza verificarea acum</button>
                    </form>
                    <a class="btn ghost" target="_blank" href="<?= pz_review_h($demoLowUrl) ?>">Vezi formular nemultumit</a>
                    <a class="btn ghost" href="review_settings.php">Setari review</a>
                </div>
            </section>

            <?php if ($scanResult !== null): ?>
                <div class="notice">Rezultat verificare: <?= pz_review_h(json_encode($scanResult, JSON_UNESCAPED_UNICODE)) ?></div>
            <?php endif; ?>

            <section class="cards">
                <div class="stat"><span>Total</span><strong><?= (int)$stats['total'] ?></strong></div>
                <div class="stat"><span>Trimise</span><strong><?= (int)$stats['sent'] ?></strong></div>
                <div class="stat"><span>Deschise</span><strong><?= (int)$stats['opened'] ?></strong></div>
                <div class="stat"><span>Evaluate</span><strong><?= (int)$stats['rated'] ?></strong></div>
                <div class="stat"><span>5 stele</span><strong><?= (int)$stats['five'] ?></strong></div>
                <div class="stat"><span>Sub 5</span><strong><?= (int)$stats['low'] ?></strong></div>
            </section>

            <section class="panel">
                <form class="filters" method="get">
                    <select name="status">
                        <option value="">Toate statusurile</option>
                        <?php foreach (['sent','opened','rated','completed','google_clicked','failed','skipped'] as $s): ?>
                            <option value="<?= pz_review_h($s) ?>" <?= $status===$s?'selected':'' ?>><?= pz_review_h(rf_status_label($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="rating">
                        <option value="">Toate ratingurile</option>
                        <?php for ($i=5;$i>=1;$i--): ?>
                            <option value="<?= $i ?>" <?= $rating===(string)$i?'selected':'' ?>><?= $i ?> stele</option>
                        <?php endfor; ?>
                    </select>
                    <button class="btn ghost" type="submit">Aplica</button>
                    <a class="btn ghost" href="review_feedback.php">Reseteaza</a>
                </form>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Data</th><th>Client</th><th>Contact</th><th>Expediere</th><th>Status</th><th>Rating</th><th>Google</th><th>Formular</th><th>Link</th></tr></thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="9" class="muted">Nu exista solicitari de review.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $client = pz_review_load_client((int)($row['client_id'] ?? 0));
                                $name = pz_review_client_name($client);
                                $answers = pz_review_answers_for_request((int)$row['id']);
                                $ratingValue = (int)($row['rating'] ?? 0);
                                $badgeClass = $ratingValue >= 5 ? 'good' : ($ratingValue >= 1 ? 'bad' : 'warn');
                                $channel = (string)($row['delivery_channel'] ?? 'sms');
                                $isFirst = ((string)($row['is_first_intervention'] ?? '') === '1');
                                $allowGoogle = ((string)($row['allow_google_review'] ?? '1') === '1');
                                ?>
                                <tr>
                                    <td><?= pz_review_h(rf_date($row['created_at'] ?? '')) ?><br><span class="muted">Trimis: <?= pz_review_h(rf_date($row['sent_at'] ?? '')) ?></span></td>
                                    <td><span class="client"><?= pz_review_h($name) ?></span><br><span class="muted">Client #<?= (int)($row['client_id'] ?? 0) ?> / Programare #<?= (int)($row['appointment_id'] ?? 0) ?></span></td>
                                    <td><?= pz_review_h($row['phone'] ?? '-') ?><br><span class="muted"><?= pz_review_h($row['email'] ?? '') ?></span></td>
                                    <td><span class="badge <?= $channel === 'email' ? 'warn' : 'good' ?>"><?= pz_review_h(strtoupper($channel)) ?></span><br><span class="muted"><?= $isFirst ? 'prima interventie' : 'interventie ulterioara' ?></span><br><span class="muted">Google: <?= $allowGoogle ? 'permis' : 'blocat' ?></span></td>
                                    <td><span class="badge <?= pz_review_h($badgeClass) ?>"><?= pz_review_h(rf_status_label((string)($row['status'] ?? ''))) ?></span></td>
                                    <td><?= $ratingValue > 0 ? pz_review_h((string)$ratingValue) . ' / 5' : '-' ?><br><?php if (!empty($row['rating_comment'])): ?><span class="muted"><?= nl2br(pz_review_h($row['rating_comment'])) ?></span><?php endif; ?></td>
                                    <td><?= !empty($row['google_clicked_at']) ? '<span class="badge good">accesat</span><br><span class="muted">'.pz_review_h(rf_date($row['google_clicked_at'])).'</span>' : '<span class="muted">-</span>' ?></td>
                                    <td>
                                        <?php if ($answers): ?>
                                            <div class="answers">
                                                <?php foreach ($answers as $a): ?>
                                                    <strong><?= pz_review_h($a['question_label'] ?? '') ?></strong><br><?= nl2br(pz_review_h($a['answer_value'] ?? '')) ?><br><br>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><a class="btn ghost" target="_blank" href="feedback.php?t=<?= urlencode((string)$row['token']) ?>">Deschide</a></td>
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
