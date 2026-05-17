<?php
/*
|--------------------------------------------------------------------------
| Activitate SMS - vizualizator log-uri din notification_logs
|--------------------------------------------------------------------------
| Arata ultimele 100 SMS-uri (sau după filtrul ales) cu:
|  - Data si ora
|  - Destinatar (telefon)
|  - Status: sent / skipped / failed
|  - Motivul (dacă skipped sau failed)
|  - Mesajul (preview)
|  - Linkul catre programare/sarcina asociata
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/notification_lib.php';

$isAdmin = is_admin();
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

pz_notify_init();

function sa_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo = pz_db();

// Filtre
$statusFilter = (string)($_GET['status'] ?? '');
$validStatuses = ['', 'sent', 'skipped', 'failed'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = '';

$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 10 || $limit > 500) $limit = 100;

// Query - doar SMS, nu si email
$where = "WHERE channel = 'sms'";
$params = [];
if ($statusFilter !== '') {
    $where .= " AND status = ?";
    $params[] = $statusFilter;
}

try {
    $sql = "SELECT id, recipient, message, status, http_code, provider_response, related_type, related_id, created_at
            FROM notification_logs
            $where
            ORDER BY created_at DESC
            LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $logs = [];
    $errorMsg = $e->getMessage();
}

// Statistici 24h
try {
    $stats = $pdo->query("
        SELECT
            SUM(status='sent')    AS sent,
            SUM(status='skipped') AS skipped,
            SUM(status='failed')  AS failed,
            COUNT(*) AS total
        FROM notification_logs
        WHERE channel = 'sms' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ")->fetch(PDO::FETCH_ASSOC) ?: ['sent'=>0,'skipped'=>0,'failed'=>0,'total'=>0];
} catch (Throwable $e) {
    $stats = ['sent'=>0,'skipped'=>0,'failed'=>0,'total'=>0];
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Activitate SMS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.sa-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.sa-stat-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 14px 16px; box-shadow: var(--shadow); }
.sa-stat-card .lbl { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
.sa-stat-card .val { font-size: 26px; font-weight: 800; color: var(--text); margin-top: 4px; letter-spacing: -.02em; }
.sa-stat-card.tone-success .val { color: var(--tone-success); }
.sa-stat-card.tone-warning .val { color: var(--tone-warning); }
.sa-stat-card.tone-danger .val { color: var(--tone-danger); }

.sa-filter { display: flex; gap: 10px; align-items: center; padding: 12px 14px; background: #fff; border: 1px solid var(--border); border-radius: 12px; margin-bottom: 14px; }
.sa-filter select { width: auto; min-width: 160px; }
.sa-filter .spacer { flex: 1; }
.sa-filter .info { color: var(--muted); font-size: 12px; }

.sa-table { width: 100%; background: #fff; border: 1px solid var(--border); border-radius: 14px; overflow: hidden; box-shadow: var(--shadow); }
.sa-row { display: grid; grid-template-columns: 110px 130px 90px minmax(0, 1fr) 110px; gap: 12px; padding: 11px 14px; border-bottom: 1px solid var(--border2); align-items: start; font-size: 13px; }
.sa-row:last-child { border-bottom: 0; }
.sa-row:hover { background: var(--surface-soft); }
.sa-time { font-family: var(--mono); font-size: 11.5px; color: var(--muted); white-space: nowrap; }
.sa-recipient { font-weight: 700; color: var(--text); }
.sa-status { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.sa-status.sent    { background: var(--tone-success-soft); color: var(--tone-success); border: 1px solid rgba(4,120,87,.22); }
.sa-status.skipped { background: var(--tone-warning-soft); color: var(--tone-warning); border: 1px solid rgba(180,83,9,.22); }
.sa-status.failed  { background: var(--tone-danger-soft);  color: var(--tone-danger);  border: 1px solid rgba(220,38,38,.22); }
.sa-msg { color: var(--text); line-height: 1.4; overflow-wrap: anywhere; }
.sa-msg .reason { color: var(--muted); font-size: 11.5px; margin-top: 4px; font-style: italic; }
.sa-related { font-size: 11.5px; color: var(--muted); }
.sa-related a { color: var(--accent); font-weight: 700; text-decoration: none; }
.sa-related a:hover { text-decoration: underline; }

.sa-empty { padding: 40px 20px; text-align: center; color: var(--muted); font-weight: 700; }
.sa-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; background: var(--surface-soft); border-bottom: 1px solid var(--border); font-weight: 700; font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: .05em; }
.sa-header > div { white-space: nowrap; }

@media (max-width: 900px) {
    .sa-stats { grid-template-columns: 1fr 1fr; }
    .sa-row { grid-template-columns: 1fr; gap: 4px; }
    .sa-header { display: none; }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('sms_activity', $isAdmin); ?>
    <main class="main">
        <div class="topbar" style="padding:12px 20px;"><a class="btn ghost" href="settings.php">Înapoi la Setări</a></div>
        <div class="content">

            <div style="margin-bottom: 18px;">
                <h1 style="margin:0; font-size:22px; font-weight:800; letter-spacing:-.025em;">Activitate SMS</h1>
                <p style="margin:4px 0 0; color:var(--muted); font-size:13px;">Ultimele SMS-uri trimise, sărite (skipped) sau eșuate. Util pentru debugging.</p>
            </div>

            <!-- Stats 24h -->
            <div class="sa-stats">
                <div class="sa-stat-card">
                    <div class="lbl">Total ultimele 24h</div>
                    <div class="val"><?= (int)$stats['total'] ?></div>
                </div>
                <div class="sa-stat-card tone-success">
                    <div class="lbl">Trimise OK</div>
                    <div class="val"><?= (int)$stats['sent'] ?></div>
                </div>
                <div class="sa-stat-card tone-warning">
                    <div class="lbl">Skipped (oprite)</div>
                    <div class="val"><?= (int)$stats['skipped'] ?></div>
                </div>
                <div class="sa-stat-card tone-danger">
                    <div class="lbl">Eșuate</div>
                    <div class="val"><?= (int)$stats['failed'] ?></div>
                </div>
            </div>

            <!-- Filtre -->
            <form class="sa-filter" method="get">
                <label style="margin:0; padding:0;">Status</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="">Toate</option>
                    <option value="sent"    <?= $statusFilter==='sent'?'selected':'' ?>>Trimise OK</option>
                    <option value="skipped" <?= $statusFilter==='skipped'?'selected':'' ?>>Skipped (oprite)</option>
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
            <div class="sa-table">
                <div class="sa-header">
                    <div>Data / ora</div>
                    <div>Destinatar</div>
                    <div>Status</div>
                    <div>Mesaj / motiv</div>
                    <div>Asociat cu</div>
                </div>
                <?php if (empty($logs)): ?>
                    <div class="sa-empty">Nicio activitate SMS găsită pentru filtrul selectat.</div>
                <?php else: ?>
                    <?php foreach ($logs as $log):
                        $status = (string)$log['status'];
                        $relatedLink = '';
                        $relType = (string)($log['related_type'] ?? '');
                        $relId = (int)($log['related_id'] ?? 0);
                        if ($relId > 0) {
                            if ($relType === 'appointment') $relatedLink = 'calendar.php?appointment_id=' . $relId;
                            elseif ($relType === 'task')    $relatedLink = 'tasks.php?task_id=' . $relId;
                        }
                        $reason = (string)($log['provider_response'] ?? '');
                        $msg = (string)($log['message'] ?? '');
                        $isSkipped = $status === 'skipped';
                        $isFailed  = $status === 'failed';
                    ?>
                        <div class="sa-row">
                            <div class="sa-time"><?= sa_h(date('d.m.Y H:i', strtotime((string)$log['created_at']))) ?></div>
                            <div class="sa-recipient"><?= sa_h($log['recipient']) ?></div>
                            <div><span class="sa-status <?= sa_h($status) ?>"><?= $status === 'sent' ? 'Trimis' : ($status === 'skipped' ? 'Skipped' : 'Eșuat') ?></span></div>
                            <div class="sa-msg">
                                <?php if ($isSkipped || $isFailed): ?>
                                    <div class="reason"><?= sa_h($reason ?: '(fără motiv consemnat)') ?></div>
                                    <?php if ($msg && !$isSkipped): ?>
                                        <div style="margin-top:4px; color:var(--muted); font-size:11.5px;"><?= sa_h(mb_substr($msg, 0, 120)) . (mb_strlen($msg) > 120 ? '…' : '') ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= sa_h(mb_substr($msg, 0, 160)) . (mb_strlen($msg) > 160 ? '…' : '') ?>
                                <?php endif; ?>
                            </div>
                            <div class="sa-related">
                                <?php if ($relatedLink): ?>
                                    <a href="<?= sa_h($relatedLink) ?>"><?= sa_h(ucfirst($relType)) ?> #<?= (int)$relId ?></a>
                                <?php elseif ($relType): ?>
                                    <?= sa_h(ucfirst($relType)) ?><?= $relId > 0 ? ' #' . (int)$relId : '' ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <p style="margin-top:14px; color:var(--muted); font-size:12px;">
                <strong>Skipped</strong> = SMS-ul a fost OPRIT intenționat (client cu sms_enabled=0, lipsă clientId, etc).
                <strong>Eșuat</strong> = SMSLink a returnat eroare (credențiale, telefon invalid, fără sold, etc).
            </p>

        </div>
    </main>
</div>
</body>
</html>
