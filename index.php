<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();
$isTeamUser = is_team_user();
$currentTeamId = current_team_id();

if (!$isAdmin && $isTeamUser) {
    header("Location: calendar.php");
    exit;
}

$today = date('Y-m-d');

$todayAppointments = 0;
$todayFinalized = 0;
$openTasks = 0;
$lateTasks = 0;
$activeTeams = 0;
$totalClients = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM appointments
        WHERE appointment_date = ?
          AND status != 'anulata'
    ");
    $stmt->execute([$today]);
    $todayAppointments = (int)($stmt->fetch()['total'] ?? 0);
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM appointments
        WHERE appointment_date = ?
          AND status = 'finalizata'
    ");
    $stmt->execute([$today]);
    $todayFinalized = (int)($stmt->fetch()['total'] ?? 0);
} catch (Exception $e) {}

try {
    $openTasks = (int)($pdo->query("
        SELECT COUNT(*) AS total
        FROM tasks
        WHERE status IN ('de_programat', 'contactat', 'amanat')
          AND recurrence_stopped = 0
    ")->fetch()['total'] ?? 0);
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM tasks
        WHERE status IN ('de_programat', 'contactat', 'amanat')
          AND recurrence_stopped = 0
          AND due_date < ?
    ");
    $stmt->execute([$today]);
    $lateTasks = (int)($stmt->fetch()['total'] ?? 0);
} catch (Exception $e) {}

try {
    $activeTeams = (int)($pdo->query("
        SELECT COUNT(*) AS total
        FROM team_members
        WHERE active = 1
    ")->fetch()['total'] ?? 0);
} catch (Exception $e) {}

try {
    $totalClients = (int)($pdo->query("
        SELECT COUNT(*) AS total
        FROM clients
    ")->fetch()['total'] ?? 0);
} catch (Exception $e) {}

$upcomingAppointments = [];

try {
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            c.name AS client_name,
            c.phone AS client_phone,
            t.name AS team_name
        FROM appointments a
        LEFT JOIN clients c ON c.id = a.client_id
        LEFT JOIN team_members t ON t.id = a.team_member_id
        WHERE a.appointment_date >= ?
          AND a.status != 'anulata'
        ORDER BY a.appointment_date ASC, a.start_time ASC
        LIMIT 8
    ");
    $stmt->execute([$today]);
    $upcomingAppointments = $stmt->fetchAll();
} catch (Exception $e) {}

$urgentTasks = [];

try {
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            c.name AS client_name,
            c.phone AS client_phone
        FROM tasks t
        LEFT JOIN clients c ON c.id = t.client_id
        WHERE t.status IN ('de_programat', 'contactat', 'amanat')
          AND t.recurrence_stopped = 0
        ORDER BY t.due_date ASC, t.id ASC
        LIMIT 8
    ");
    $stmt->execute();
    $urgentTasks = $stmt->fetchAll();
} catch (Exception $e) {}

function ro_short_date_dashboard(string $date): string {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d ? $d->format('d.m.Y') : $date;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Dashboard · PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

<style>
.dashboard-hero {
    background: linear-gradient(135deg, #102015, #1E3A27);
    color: #fff;
    border-radius: var(--radius-lg);
    padding: 26px;
    box-shadow: var(--shadow-lg);
    display: flex;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.dashboard-hero h1 {
    font-size: 28px;
    font-weight: 900;
    letter-spacing: -0.03em;
}

.dashboard-hero p {
    color: #DDE8D6;
    margin-top: 6px;
    max-width: 720px;
}

.hero-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-start;
}

.hero-actions .btn {
    border-color: rgba(255,255,255,.18);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 18px;
    box-shadow: var(--shadow);
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.stat-value {
    font-size: 32px;
    font-weight: 900;
    margin-top: 8px;
    line-height: 1;
}

.stat-note {
    color: var(--muted);
    margin-top: 8px;
    font-size: 13px;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: 1.2fr .8fr;
    gap: 16px;
}

.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.panel-header {
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
}

.panel-header h2 {
    font-size: 17px;
    font-weight: 900;
}

.panel-body {
    padding: 10px;
}

.row-item {
    display: grid;
    grid-template-columns: 110px 1fr auto;
    gap: 12px;
    align-items: center;
    padding: 12px;
    border-radius: 14px;
}

.row-item:hover {
    background: var(--surface-soft);
}

.row-date {
    font-weight: 900;
    color: var(--text);
    font-size: 13px;
}

.row-title {
    font-weight: 900;
}

.row-sub {
    color: var(--muted);
    font-size: 13px;
    margin-top: 2px;
}

.badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 5px 9px;
    font-size: 12px;
    font-weight: 900;
    background: var(--accent-soft);
    color: var(--text);
    white-space: nowrap;
}

.badge.warning {
    background: var(--warning-soft);
    color: #92400E;
}

.empty-state {
    padding: 26px;
    color: var(--muted);
    text-align: center;
    font-weight: 700;
}

@media(max-width:1050px) {
    .stats-grid {
        grid-template-columns: repeat(2, minmax(160px, 1fr));
    }

    .dashboard-grid {
        grid-template-columns: 1fr;
    }
}

@media(max-width:640px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .row-item {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>
<div class="layout">
    <?php render_sidebar('dashboard', $isAdmin); ?>

    <main class="main">
        <div class="topbar">
            <div class="top-left">
                <div class="page-title">Dashboard</div>
            </div>

            <div class="top-right">
                <div class="user-badge">⚙️ Admin</div>
                <a class="btn accent" href="calendar.php">Deschide calendarul</a>
            </div>
        </div>

        <div class="content">
            <section class="dashboard-hero">
                <div>
                    <h1>Bun venit în PestZone</h1>
                    <p>
                        Ai o privire rapidă asupra lucrărilor de astăzi, sarcinilor de birou,
                        echipelor active și clienților.
                    </p>
                </div>

                <div class="hero-actions">
                    <a class="btn accent" href="calendar.php">+ Programare</a>
                    <a class="btn" href="tasks.php">Sarcini birou</a>
                    <a class="btn" href="clients.php">Clienți</a>
                </div>
            </section>

            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Lucrări azi</div>
                    <div class="stat-value"><?= (int)$todayAppointments ?></div>
                    <div class="stat-note">Programări active pentru astăzi</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Finalizate azi</div>
                    <div class="stat-value"><?= (int)$todayFinalized ?></div>
                    <div class="stat-note">Lucrări marcate finalizate</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Sarcini deschise</div>
                    <div class="stat-value"><?= (int)$openTasks ?></div>
                    <div class="stat-note"><?= (int)$lateTasks ?> întârziate</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Clienți / echipe</div>
                    <div class="stat-value"><?= (int)$totalClients ?></div>
                    <div class="stat-note"><?= (int)$activeTeams ?> echipe active</div>
                </div>
            </section>

            <section class="dashboard-grid">
                <div class="panel">
                    <div class="panel-header">
                        <h2>Programări următoare</h2>
                        <a class="btn ghost" href="calendar.php">Vezi calendar</a>
                    </div>

                    <div class="panel-body">
                        <?php if (!$upcomingAppointments): ?>
                            <div class="empty-state">Nu există programări viitoare.</div>
                        <?php else: ?>
                            <?php foreach ($upcomingAppointments as $appt): ?>
                                <div class="row-item">
                                    <div class="row-date">
                                        <?= htmlspecialchars(ro_short_date_dashboard($appt['appointment_date'])) ?><br>
                                        <?= htmlspecialchars(substr($appt['start_time'], 0, 5)) ?>
                                    </div>

                                    <div>
                                        <div class="row-title">
                                            <?= htmlspecialchars($appt['client_name'] ?: 'Client') ?>
                                        </div>
                                        <div class="row-sub">
                                            <?= htmlspecialchars($appt['service_type']) ?>
                                            <?php if (!empty($appt['team_name'])): ?>
                                                · <?= htmlspecialchars($appt['team_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <span class="badge">
                                        <?= htmlspecialchars($appt['status']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Sarcini urgente</h2>
                        <a class="btn ghost" href="tasks.php">Vezi sarcini</a>
                    </div>

                    <div class="panel-body">
                        <?php if (!$urgentTasks): ?>
                            <div class="empty-state">Nu există sarcini deschise.</div>
                        <?php else: ?>
                            <?php foreach ($urgentTasks as $task): ?>
                                <?php $isLate = $task['due_date'] < $today; ?>
                                <div class="row-item">
                                    <div class="row-date">
                                        <?= htmlspecialchars(ro_short_date_dashboard($task['due_date'])) ?>
                                    </div>

                                    <div>
                                        <div class="row-title">
                                            <?= htmlspecialchars($task['client_name'] ?: 'Client') ?>
                                        </div>
                                        <div class="row-sub">
                                            <?= htmlspecialchars($task['service_type']) ?>
                                        </div>
                                    </div>

                                    <span class="badge <?= $isLate ? 'warning' : '' ?>">
                                        <?= $isLate ? 'Întârziată' : 'Deschisă' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>