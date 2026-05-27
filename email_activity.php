<?php
/*
|--------------------------------------------------------------------------
| Activitate Email - vizualizator log-uri
|--------------------------------------------------------------------------
| Combina doua surse:
|  1. notification_logs (channel='email') - SendGrid generic
|  2. document_email_logs - emailuri trimise atașate la documente (PV, oferte, contracte)
|
| Afiseaza ultimele 100 trimiteri cu status, motiv, mesaj, link la document.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/lib/notification_lib.php';

$isAdmin = is_admin();
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

pz_notify_init();

function ea_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo = pz_db();

// Filtre
$statusFilter = (string)($_GET['status'] ?? '');
$validStatuses = ['', 'sent', 'failed', 'skipped'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = '';

$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 10 || $limit > 500) $limit = 100;

// === Combinam log-urile din ambele tabele ===
$logs = [];

// 1. notification_logs (channel='email')
try {
    $where = "WHERE channel = 'email'";
    $params = [];
    if ($statusFilter !== '') { $where .= " AND status = ?"; $params[] = $statusFilter; }
    $stmt = $pdo->prepare("
        SELECT id, recipient, subject, message, status, http_code, provider_response, related_type, related_id, created_at,
               'notification_logs' AS log_source
        FROM notification_logs
        $where
        ORDER BY created_at DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $logs[] = $r;
} catch (Throwable $e) { /* tabela poate sa nu existe */ }

// 2. document_email_logs (specific PV/oferte/contracte)
try {
    $where = "";
    $params = [];
    if ($statusFilter !== '') { $where = "WHERE status = ?"; $params[] = $statusFilter; }
    $stmt = $pdo->prepare("
        SELECT id, recipient, subject, body AS message, status, NULL AS http_code, provider_response,
               'document' AS related_type, document_id AS related_id, sent_at AS created_at,
               'document_email_logs' AS log_source
        FROM document_email_logs
        $where
        ORDER BY sent_at DESC, id DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $logs[] = $r;
} catch (Throwable $e) { /* tabela poate sa nu existe */ }

// Sortam combinatia descrescator după created_at, limit la $limit
usort($logs, function($a, $b) { return strcmp((string)$b['created_at'], (string)$a['created_at']); });
$logs = array_slice($logs, 0, $limit);

// Statistici 24h - din ambele surse combinate
$stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0];
try {
    $r = $pdo->query("
        SELECT
            SUM(status='sent')    AS sent,
            SUM(status='failed')  AS failed,
            SUM(status='skipped') AS skipped,
            COUNT(*) AS total
        FROM notification_logs
        WHERE channel = 'email' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['sent','failed','skipped','total'] as $k) $stats[$k] += (int)($r[$k] ?? 0);
} catch (Throwable $e) {}
try {
    $r = $pdo->query("
        SELECT
            SUM(status='sent')    AS sent,
            SUM(status='failed')  AS failed,
            COUNT(*) AS total
        FROM document_email_logs
        WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['sent','failed','total'] as $k) $stats[$k] += (int)($r[$k] ?? 0);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Activitate Email</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.ea-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.ea-stat-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 14px 16px; box-shadow: var(--shadow); }
.ea-stat-card .lbl { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
.ea-stat-card .val { font-size: 26px; font-weight: 800; color: var(--text); margin-top: 4px; letter-spacing: -.02em; }
.ea-stat-card.tone-success .val { color: var(--tone-success); }
.ea-stat-card.tone-warning .val { color: var(--tone-warning); }
.ea-stat-card.tone-danger .val { color: var(--tone-danger); }

.ea-filter { display: flex; gap: 10px; align-items: center; padding: 12px 14px; background: #fff; border: 1px solid var(--border); border-radius: 12px; margin-bottom: 14px; }
.ea-filter select { width: auto; min-width: 160px; }
.ea-filter .spacer { flex: 1; }
.ea-filter .info { color: var(--muted); font-size: 12px; }

.ea-table { width: 100%; background: #fff; border: 1px solid var(--border); border-radius: 14px; overflow: hidden; box-shadow: var(--shadow); }
.ea-row { display: grid; grid-template-columns: 110px 200px 90px minmax(0, 1fr) 130px; gap: 12px; padding: 11px 14px; border-bottom: 1px solid var(--border2); align-items: start; font-size: 13px; }
.ea-row:last-child { border-bottom: 0; }
.ea-row:hover { background: var(--surface-soft); }
.ea-time { font-family: var(--mono); font-size: 11.5px; color: var(--muted); white-space: nowrap; }
.ea-recipient { font-weight: 700; color: var(--text); overflow-wrap: anywhere; }
.ea-status { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.ea-status.sent    { background: var(--tone-success-soft); color: var(--tone-success); border: 1px solid rgba(4,120,87,.22); }
.ea-status.skipped { background: var(--tone-warning-soft); color: var(--tone-warning); border: 1px solid rgba(180,83,9,.22); }
.ea-status.failed  { background: var(--tone-danger-soft);  color: var(--tone-danger);  border: 1px solid rgba(220,38,38,.22); }
.ea-msg { color: var(--text); line-height: 1.4; overflow-wrap: anywhere; }
.ea-msg .subject { font-weight: 700; margin-bottom: 4px; }
.ea-msg .reason { color: var(--tone-danger); font-size: 11.5px; margin-top: 4px; font-style: italic; overflow-wrap: anywhere; }
.ea-msg .preview { color: var(--muted); font-size: 11.5px; margin-top: 3px; }
.ea-related { font-size: 11.5px; color: var(--muted); }
.ea-related a { color: var(--accent); font-weight: 700; text-decoration: none; }
.ea-related a:hover { text-decoration: underline; }
.ea-related .src { display: block; font-size: 10px; color: var(--muted-2); margin-top: 2px; text-transform: uppercase; letter-spacing: .04em; }

.ea-empty { padding: 40px 20px; text-align: center; color: var(--muted); font-weight: 700; }
.ea-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; background: var(--surface-soft); border-bottom: 1px solid var(--border); font-weight: 700; font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: .05em; }
.ea-header > div { white-space: nowrap; }

@media (max-width: 900px) {
    .ea-stats { grid-template-columns: 1fr 1fr; }
    .ea-row { grid-template-columns: 1fr; gap: 4px; }
    .ea-header { display: none; }
}
/* DS v2.4 */
.ea-stat-card,.ea-table { border-radius:var(--pz-r) !important; box-shadow:none !important; }
.ea-filter { border-radius:var(--pz-r) !important; }
.ea-status { border-radius:var(--pz-rs) !important; }
.ea-status.sent    { background:var(--pz-grs) !important; color:var(--pz-gr) !important; border-color:var(--pz-grb) !important; }
.ea-status.skipped { background:var(--pz-ors) !important; color:var(--pz-or) !important; border-color:var(--pz-orb) !important; }
.ea-status.failed  { background:var(--pz-res) !important; color:var(--pz-re) !important; border-color:var(--pz-reb) !important; }
.ea-stat-card.tone-success .val { color:var(--pz-gr) !important; }
.ea-stat-card.tone-warning .val { color:var(--pz-or) !important; }
.ea-stat-card.tone-danger .val  { color:var(--pz-re) !important; }
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('email_activity', $isAdmin); ?>
    <main class="main">
        <div class="content">
            <?php pz_page_header([
                'back'     => ['href' => 'settings.php', 'label' => 'Înapoi la setări'],
                'kicker'   => 'ADMINISTRARE · COMUNICARE',
                'title'    => 'Activitate Email',
                'subtitle' => 'Ultimele emailuri trimise (sau eșuate). Combinăm log-urile din SendGrid + log-urile pe documente.',
            ]); ?>

            <!-- Stats 24h -->
            <div class="ea-stats">
                <div class="ea-stat-card">
                    <div class="lbl">Total ultimele 24h</div>
                    <div class="val"><?= (int)$stats['total'] ?></div>
                </div>
                <div class="ea-stat-card tone-success">
                    <div class="lbl">Trimise OK</div>
                    <div class="val"><?= (int)$stats['sent'] ?></div>
                </div>
                <div class="ea-stat-card tone-warning">
                    <div class="lbl">Skipped</div>
                    <div class="val"><?= (int)$stats['skipped'] ?></div>
                </div>
                <div class="ea-stat-card tone-danger">
                    <div class="lbl">Eșuate</div>
                    <div class="val"><?= (int)$stats['failed'] ?></div>
                </div>
            </div>

            <!-- Filtre -->
            <form class="ea-filter" method="get">
                <label style="margin:0; padding:0;">Status</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="">Toate</option>
                    <option value="sent"    <?= $statusFilter==='sent'?'selected':'' ?>>Trimise OK</option>
                    <option value="skipped" <?= $statusFilter==='skipped'?'selected':'' ?>>Skipped</option>
                    <option value="failed"  <?= $statusFilter==='failed'?'selected':'' ?>>Eșuate</option>
                </select>
                <label style="margin:0; padding:0;">Limită</label>
                <select name="limit" onchange="this.form.submit()">
                    <?php foreach ([50, 100, 200, 500] as $lim): ?>
                        <option value="<?= $lim ?>" <?= $limit===$lim?'selected':'' ?>><?= $lim ?> rânduri</option>
                    <?php endforeach; ?>
                </select>
                <div class="spacer"></div>
                <span class="info">Afișate: <?= count($logs) ?></span>
            </form>

            <!-- Lista -->
            <div class="ea-table">
                <div class="ea-header">
                    <div>Data / ora</div>
                    <div>Destinatar</div>
                    <div>Status</div>
                    <div>Subiect / motiv</div>
                    <div>Asociat cu</div>
                </div>
                <?php if (empty($logs)): ?>
                    <div class="ea-empty">Nicio activitate email găsită pentru filtrul selectat.</div>
                <?php else: ?>
                    <?php foreach ($logs as $log):
                        $status = (string)$log['status'];
                        $isFailed = $status === 'failed';
                        $isSkipped = $status === 'skipped';
                        $reason = (string)($log['provider_response'] ?? '');
                        $subject = (string)($log['subject'] ?? '');
                        $message = (string)($log['message'] ?? '');
                        $relatedLink = '';
                        $relType = (string)($log['related_type'] ?? '');
                        $relId = (int)($log['related_id'] ?? 0);
                        if ($relId > 0) {
                            if ($relType === 'document')    $relatedLink = 'document_view.php?id=' . $relId;
                            elseif ($relType === 'appointment') $relatedLink = 'calendar.php?appointment_id=' . $relId;
                            elseif ($relType === 'task')    $relatedLink = 'tasks.php?task_id=' . $relId;
                        }
                        $logSource = (string)($log['log_source'] ?? '');
                    ?>
                        <div class="ea-row">
                            <div class="ea-time"><?= ea_h(date('d.m.Y H:i', strtotime((string)$log['created_at']))) ?></div>
                            <div class="ea-recipient"><?= ea_h($log['recipient']) ?></div>
                            <div><span class="ea-status <?= ea_h($status) ?>"><?= $status === 'sent' ? 'Trimis' : ($status === 'skipped' ? 'Skipped' : 'Eșuat') ?></span></div>
                            <div class="ea-msg">
                                <?php if ($subject !== ''): ?>
                                    <div class="subject"><?= ea_h(mb_substr($subject, 0, 100)) . (mb_strlen($subject) > 100 ? '…' : '') ?></div>
                                <?php endif; ?>
                                <?php if ($isFailed || $isSkipped): ?>
                                    <div class="reason"><?= ea_h(mb_substr($reason ?: '(fără motiv consemnat)', 0, 200)) ?></div>
                                <?php elseif ($message !== ''): ?>
                                    <div class="preview"><?= ea_h(mb_substr($message, 0, 100)) . (mb_strlen($message) > 100 ? '…' : '') ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="ea-related">
                                <?php if ($relatedLink): ?>
                                    <a href="<?= ea_h($relatedLink) ?>"><?= ea_h(ucfirst($relType)) ?> #<?= (int)$relId ?></a>
                                <?php elseif ($relType): ?>
                                    <?= ea_h(ucfirst($relType)) ?><?= $relId > 0 ? ' #' . (int)$relId : '' ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                                <?php if ($logSource): ?>
                                    <span class="src"><?= ea_h($logSource === 'document_email_logs' ? 'Doc' : 'Notif') ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <p style="margin-top:14px; color:var(--muted); font-size:12px;">
                <strong>Sent</strong> = email trimis cu succes prin SendGrid.
                <strong>Skipped</strong> = email NU a fost trimis intenționat (ex: configurație lipsă).
                <strong>Eșuat</strong> = SendGrid a returnat eroare (auth invalid, sender neverificat, etc).
            </p>

        </div>
    </main>
</div>
</body>
</html>
