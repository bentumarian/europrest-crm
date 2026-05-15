<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

function disp_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function disp_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) > 0;
    } catch (Throwable $e) { return false; }
}

function disp_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('dispecerat rows: ' . $e->getMessage());
        return [];
    }
}

function disp_scalar(PDO $pdo, string $sql, array $params = [], $fallback = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? $fallback : $v;
    } catch (Throwable $e) {
        error_log('dispecerat scalar: ' . $e->getMessage());
        return $fallback;
    }
}

function disp_safe_date($date): string {
    $date = (string)$date;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $date : date('Y-m-d');
}

function disp_time($time): string { return $time ? substr((string)$time, 0, 5) : '--:--'; }

function disp_minutes($time): int {
    $time = substr((string)$time, 0, 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) return 0;
    [$h, $m] = array_map('intval', explode(':', $time));
    return $h * 60 + $m;
}

function disp_status_label(string $status): string {
    return [
        'neconfirmata' => 'Neconfirmata',
        'confirmata' => 'Confirmata',
        'in_lucru' => 'In lucru',
        'finalizata' => 'Finalizata',
        'anulata' => 'Anulata',
        'de_programat' => 'De programat',
        'contactat' => 'Contactat',
        'amanat' => 'Amanat',
        'programat' => 'Programat',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function disp_status_tone(string $status): string {
    return [
        'neconfirmata' => 'warn',
        'confirmata' => 'info',
        'in_lucru' => 'active',
        'finalizata' => 'ok',
        'anulata' => 'muted',
    ][$status] ?? 'info';
}

$currentDate = disp_safe_date($_GET['date'] ?? date('Y-m-d'));
$prevDate = date('Y-m-d', strtotime($currentDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
$today = date('Y-m-d');

$startHour = 6;
$endHour = 20;
$slotMinutes = 30;
$slotHeight = 36;
$slotCount = (($endHour - $startHour) * 60) / $slotMinutes;
$startMinute = $startHour * 60;
$endMinute = $endHour * 60;

$hasTasks = disp_table_exists($pdo, 'tasks');
$hasTeams = disp_table_exists($pdo, 'team_members');
$hasAppointments = disp_table_exists($pdo, 'appointments');

$teams = $hasTeams ? disp_rows($pdo, "SELECT id, name, color FROM team_members WHERE active = 1 ORDER BY name ASC") : [];
if (!$teams) { $teams = []; }

$openTaskWhere = "t.status IN ('de_programat','contactat','amanat') AND (t.appointment_id IS NULL OR t.appointment_id = 0)";
$tasks = [];
if ($hasTasks) {
    $tasks = disp_rows($pdo, "
        SELECT t.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
               cl.location_name, cl.address AS location_address
        FROM tasks t
        LEFT JOIN clients c ON c.id = t.client_id
        LEFT JOIN client_locations cl ON cl.id = t.client_location_id
        WHERE {$openTaskWhere}
          AND (t.skipped_at IS NULL OR t.skipped_at = '0000-00-00 00:00:00')
        ORDER BY CASE WHEN t.due_date < ? THEN 0 ELSE 1 END, t.due_date ASC, t.id ASC
        LIMIT 80
    ", [$currentDate]);
}

$appointments = [];
if ($hasAppointments) {
    $appointments = disp_rows($pdo, "
        SELECT a.*, c.name AS client_name, tm.name AS team_name, tm.color AS team_color
        FROM appointments a
        LEFT JOIN clients c ON c.id = a.client_id
        LEFT JOIN team_members tm ON tm.id = a.team_member_id
        WHERE a.appointment_date = ? AND a.status <> 'anulata'
        ORDER BY a.start_time ASC, a.id ASC
    ", [$currentDate]);
}

$appointmentsByTeam = [];
foreach ($appointments as $a) {
    $tid = (int)($a['team_member_id'] ?? 0);
    if (!isset($appointmentsByTeam[$tid])) $appointmentsByTeam[$tid] = [];
    $appointmentsByTeam[$tid][] = $a;
}

$tasksLate = $hasTasks ? (int)disp_scalar($pdo, "SELECT COUNT(*) FROM tasks t WHERE {$openTaskWhere} AND t.due_date < ? AND (t.skipped_at IS NULL OR t.skipped_at = '0000-00-00 00:00:00')", [$currentDate]) : 0;
$tasksToday = $hasTasks ? (int)disp_scalar($pdo, "SELECT COUNT(*) FROM tasks t WHERE {$openTaskWhere} AND t.due_date = ? AND (t.skipped_at IS NULL OR t.skipped_at = '0000-00-00 00:00:00')", [$currentDate]) : 0;
$jobsToday = count($appointments);

$pz_page_title = 'Dispecerat';
$pz_page_breadcrumbs = ['Alocare sarcini'];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dispecerat</title>
    <?php app_theme_css(); ?>
    <style>
        .dispatch-page { max-width: 1680px; margin: 0 auto; }
        .dispatch-hero { margin-bottom:14px; border:1px solid rgba(203,213,225,.78); border-radius:26px; padding:18px; background:linear-gradient(135deg,rgba(255,255,255,.98),rgba(248,250,252,.94)); box-shadow:0 22px 48px -34px rgba(15,23,42,.42); display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
        .dispatch-title { margin:0; font-size:27px; line-height:1.05; letter-spacing:-.04em; color:#0F172A; }
        .dispatch-sub { margin:6px 0 0; color:#64748B; font-size:13px; font-weight:650; max-width:780px; }
        .dispatch-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
        .disp-btn { min-height:36px; border-radius:13px; padding:0 12px; border:1px solid rgba(203,213,225,.88); background:#fff; color:#0F172A; font-size:12.5px; font-weight:850; display:inline-flex; align-items:center; justify-content:center; gap:7px; }
        .disp-btn.primary { background:linear-gradient(135deg,#4F46E5,#2563EB); color:#fff; border-color:rgba(79,70,229,.7); box-shadow:0 12px 24px -16px rgba(79,70,229,.75); }
        .dispatch-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; margin-top:13px; max-width:620px; }
        .dispatch-stat { border:1px solid rgba(226,232,240,.82); background:#fff; border-radius:17px; padding:11px; }
        .dispatch-stat .n { font-size:21px; font-weight:950; letter-spacing:-.04em; color:#0F172A; line-height:1; }
        .dispatch-stat .l { margin-top:5px; font-size:11px; font-weight:850; color:#64748B; text-transform:uppercase; letter-spacing:.05em; }
        .dispatch-layout { display:grid; grid-template-columns:360px minmax(0,1fr); gap:14px; align-items:start; }
        .dispatch-card { border:1px solid rgba(226,232,240,.82); border-radius:24px; background:rgba(255,255,255,.93); box-shadow:0 18px 42px -32px rgba(15,23,42,.42); overflow:hidden; }
        .dispatch-card-head { padding:14px 15px 11px; border-bottom:1px solid rgba(226,232,240,.78); display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
        .dispatch-card-title { font-size:14px; font-weight:950; letter-spacing:-.02em; color:#0F172A; }
        .dispatch-card-sub { margin-top:3px; font-size:12px; font-weight:650; color:#64748B; }
        .task-scroll { max-height:690px; overflow:auto; padding:12px; display:grid; gap:9px; }
        .task-item { width:100%; text-align:left; border:1px solid rgba(226,232,240,.86); border-radius:18px; background:#fff; padding:12px; cursor:pointer; transition:transform .12s ease, border-color .12s ease, box-shadow .12s ease; }
        .task-item:hover { transform:translateY(-1px); border-color:rgba(79,70,229,.28); box-shadow:0 14px 24px -22px rgba(15,23,42,.5); }
        .task-item.is-selected { border-color:rgba(79,70,229,.65); box-shadow:0 0 0 4px rgba(79,70,229,.12); }
        .task-top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
        .task-client { font-size:13px; font-weight:950; color:#0F172A; line-height:1.2; }
        .task-service { margin-top:4px; font-size:12px; font-weight:800; color:#2563EB; }
        .task-meta { margin-top:9px; display:grid; gap:4px; font-size:11.5px; color:#64748B; font-weight:650; }
        .due-badge { display:inline-flex; align-items:center; min-height:24px; border-radius:999px; padding:0 8px; font-size:10.5px; font-weight:950; white-space:nowrap; }
        .due-badge.late { background:#FEF2F2; color:#DC2626; border:1px solid #FECACA; }
        .due-badge.today { background:#FFFBEB; color:#B45309; border:1px solid #FDE68A; }
        .due-badge.future { background:#EFF6FF; color:#2563EB; border:1px solid #BFDBFE; }
        .calendar-card { min-width:0; }
        .selected-task-mini { margin-top:5px; font-size:12px; font-weight:800; color:#2563EB; }
        .selected-task-mini.muted { color:#64748B; }
        .calendar-toolbar { padding:12px 14px; display:flex; justify-content:space-between; gap:10px; align-items:center; border-bottom:1px solid rgba(226,232,240,.78); background:rgba(248,250,252,.78); }
        .date-nav { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .date-pill { min-height:34px; border-radius:13px; padding:0 12px; display:inline-flex; align-items:center; background:#fff; border:1px solid rgba(226,232,240,.88); font-size:12.5px; font-weight:900; color:#0F172A; }
        .calendar-scroll { max-height:none; overflow:auto; position:relative; }
        .timeline { display:grid; grid-template-columns:72px repeat(<?= max(1, count($teams)) ?>, minmax(260px, 1fr)); grid-template-rows:48px <?= (int)$slotCount * (int)$slotHeight ?>px; min-width:<?= 72 + max(1, count($teams)) * 260 ?>px; }
        .time-head, .team-head { position:sticky; top:0; z-index:6; background:rgba(255,255,255,.96); border-bottom:1px solid rgba(226,232,240,.86); }
        .time-head { left:0; z-index:8; border-right:1px solid rgba(226,232,240,.86); }
        .team-head { display:flex; align-items:center; gap:8px; padding:0 12px; font-size:12.5px; font-weight:950; color:#0F172A; border-right:1px solid rgba(226,232,240,.72); }
        .time-col { position:sticky; left:0; z-index:4; background:#F8FAFC; border-right:1px solid rgba(226,232,240,.86); grid-row:2; }
        .time-label { height:<?= (int)$slotHeight * 2 ?>px; padding:4px 8px 0 0; text-align:right; font-size:11px; font-weight:850; color:#64748B; border-bottom:1px solid rgba(226,232,240,.76); }
        .team-lane { position:relative; grid-row:2; height:<?= (int)$slotCount * (int)$slotHeight ?>px; border-right:1px solid rgba(226,232,240,.72); background:linear-gradient(to bottom, rgba(226,232,240,.82) 1px, transparent 1px); background-size:100% <?= (int)$slotHeight ?>px; }
        .slot-layer { position:absolute; inset:0; display:grid; grid-template-rows:repeat(<?= (int)$slotCount ?>, <?= (int)$slotHeight ?>px); z-index:1; }
        .slot-btn { border:0; border-bottom:1px solid transparent; background:transparent; padding:0; cursor:pointer; }
        .slot-btn:hover { background:rgba(79,70,229,.06); }
        .event { position:absolute; left:8px; right:8px; z-index:3; border-radius:13px; padding:7px 9px; overflow:hidden; border:1px solid rgba(255,255,255,.62); box-shadow:0 12px 20px -16px rgba(15,23,42,.58); color:#fff; display:block; }
        .event.info { background:linear-gradient(135deg,#2563EB,#4F46E5); }
        .event.active { background:linear-gradient(135deg,#7C3AED,#4F46E5); }
        .event.ok { background:linear-gradient(135deg,#059669,#16A34A); }
        .event.warn { background:linear-gradient(135deg,#D97706,#F59E0B); }
        .event.muted { background:linear-gradient(135deg,#64748B,#475569); }
        .event-time { font-size:10.5px; font-weight:900; opacity:.92; }
        .event-title { margin-top:2px; font-size:11.5px; font-weight:900; line-height:1.15; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .event-meta { margin-top:2px; font-size:10.5px; font-weight:700; opacity:.86; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .dot { width:9px; height:9px; border-radius:999px; background:#2563EB; flex:0 0 auto; }
        .side-body { padding:14px; }
        .selected-box { border:1px solid rgba(226,232,240,.86); border-radius:18px; background:#F8FAFC; padding:13px; min-height:160px; }
        .selected-title { font-size:13px; font-weight:950; color:#0F172A; }
        .selected-meta { margin-top:8px; display:grid; gap:6px; font-size:12px; color:#64748B; font-weight:650; }
        .slot-box { margin-top:12px; border:1px solid rgba(226,232,240,.86); border-radius:18px; background:#fff; padding:13px; }
        .slot-title { font-size:12px; color:#64748B; font-weight:850; text-transform:uppercase; letter-spacing:.05em; }
        .slot-value { margin-top:6px; font-size:15px; font-weight:950; color:#0F172A; }
        .empty-state { padding:22px; border:1px dashed rgba(148,163,184,.65); border-radius:18px; color:#64748B; font-size:13px; font-weight:750; text-align:center; background:#F8FAFC; }
        .hint-box { margin-top:12px; padding:12px; border-radius:17px; background:#EFF6FF; border:1px solid #BFDBFE; color:#1D4ED8; font-size:12px; font-weight:750; line-height:1.4; }
        @media(max-width:1300px){ .dispatch-layout{grid-template-columns:320px minmax(0,1fr);} }
        @media(max-width:860px){ .dispatch-hero{display:block;} .dispatch-actions{justify-content:flex-start;margin-top:13px;} .dispatch-stats{grid-template-columns:1fr 1fr 1fr;} .dispatch-layout{grid-template-columns:1fr;} .task-scroll{max-height:380px;} .dispatch-title{font-size:23px;} .timeline{min-width:980px;} }
    </style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('dispecerat', $isAdmin); ?>
    <main class="main">
        <div class="content">
            <div class="dispatch-page">
                <section class="dispatch-hero">
                    <div>
                        <h1 class="dispatch-title">Dispecerat</h1>
                        <p class="dispatch-sub">Selectezi o sarcina din stanga, apoi alegi ora libera si echipa din calendar. Formularul complet se deschide in Calendar cu datele preluate corect.</p>
                        <div class="dispatch-stats">
                            <div class="dispatch-stat"><div class="n"><?= count($tasks) ?></div><div class="l">sarcini in lista</div></div>
                            <div class="dispatch-stat"><div class="n"><?= (int)$tasksLate ?></div><div class="l">intarziate</div></div>
                            <div class="dispatch-stat"><div class="n"><?= (int)$jobsToday ?></div><div class="l">programari zi</div></div>
                        </div>
                    </div>
                    <div class="dispatch-actions">
                        <a class="disp-btn" href="dispecerat.php?date=<?= disp_h($prevDate) ?>">Ziua anterioara</a>
                        <a class="disp-btn primary" href="dispecerat.php?date=<?= disp_h($today) ?>">Azi</a>
                        <a class="disp-btn" href="dispecerat.php?date=<?= disp_h($nextDate) ?>">Ziua urmatoare</a>
                        <a class="disp-btn" href="dashboard.php">Dashboard</a>
                    </div>
                </section>

                <div class="dispatch-layout">
                    <section class="dispatch-card">
                        <div class="dispatch-card-head">
                            <div><div class="dispatch-card-title">Sarcini de programat</div><div class="dispatch-card-sub">Scroll vertical. Click pentru selectie.</div></div>
                        </div>
                        <div class="task-scroll" id="taskList">
                            <?php if (empty($tasks)): ?>
                                <div class="empty-state">Nu exista sarcini de programat.</div>
                            <?php else: ?>
                                <?php foreach ($tasks as $task):
                                    $due = (string)($task['due_date'] ?? '');
                                    $dueClass = ($due && $due < $currentDate) ? 'late' : (($due === $currentDate) ? 'today' : 'future');
                                    $address = trim((string)($task['address'] ?? '')) ?: trim((string)($task['location_address'] ?? ''));
                                    $client = trim((string)($task['client_name'] ?? '')) ?: 'Client #' . (int)($task['client_id'] ?? 0);
                                    $location = trim((string)($task['location_name'] ?? ''));
                                ?>
                                <button class="task-item" type="button"
                                    data-task-id="<?= (int)$task['id'] ?>"
                                    data-client="<?= disp_h($client) ?>"
                                    data-service="<?= disp_h($task['service_type'] ?? '') ?>"
                                    data-title="<?= disp_h($task['title'] ?? '') ?>"
                                    data-address="<?= disp_h($address) ?>"
                                    data-location="<?= disp_h($location) ?>"
                                    data-due="<?= disp_h($due) ?>"
                                >
                                    <div class="task-top">
                                        <div>
                                            <div class="task-client"><?= disp_h($client) ?></div>
                                            <div class="task-service"><?= disp_h($task['service_type'] ?? $task['title'] ?? '') ?></div>
                                        </div>
                                        <span class="due-badge <?= disp_h($dueClass) ?>"><?= disp_h($due ?: '-') ?></span>
                                    </div>
                                    <div class="task-meta">
                                        <?php if ($location !== ''): ?><div>Locatie: <?= disp_h($location) ?></div><?php endif; ?>
                                        <?php if ($address !== ''): ?><div>Adresa: <?= disp_h($address) ?></div><?php endif; ?>
                                    </div>
                                </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="dispatch-card calendar-card">
                        <div class="calendar-toolbar">
                            <div>
                                <div class="dispatch-card-title">Calendar echipe</div>
                                <div class="dispatch-card-sub"><?= disp_h(date('d.m.Y', strtotime($currentDate))) ?> · interval complet <?= sprintf('%02d:00', $startHour) ?>-<?= sprintf('%02d:00', $endHour) ?></div>
                                <div class="selected-task-mini muted" id="selectedTaskMini">Nicio sarcina selectata. Click pe un slot liber deschide formularul.</div>
                            </div>
                            <div class="date-nav">
                                <a class="date-pill" href="calendar.php?date=<?= disp_h($currentDate) ?>&view=day">Deschide Calendar</a>
                            </div>
                        </div>
                        <div class="calendar-scroll" id="calendarScroll">
                            <div class="timeline" style="--slot-count:<?= (int)$slotCount ?>; --slot-height:<?= (int)$slotHeight ?>px;">
                                <div class="time-head"></div>
                                <?php $col = 2; foreach ($teams as $team): ?>
                                    <div class="team-head" style="grid-column:<?= (int)$col ?>; grid-row:1;"><span class="dot" style="background:<?= disp_h($team['color'] ?: '#2563EB') ?>"></span><?= disp_h($team['name']) ?></div>
                                <?php $col++; endforeach; ?>

                                <div class="time-col" style="grid-column:1;">
                                    <?php for ($h = $startHour; $h < $endHour; $h++): ?>
                                        <div class="time-label"><?= sprintf('%02d:00', $h) ?></div>
                                    <?php endfor; ?>
                                </div>

                                <?php if (empty($teams)): ?>
                                    <div style="grid-column:2; grid-row:2; padding:20px;"><div class="empty-state">Nu exista echipe active.</div></div>
                                <?php else: ?>
                                    <?php $col = 2; foreach ($teams as $team): $tid = (int)$team['id']; ?>
                                        <div class="team-lane" style="grid-column:<?= (int)$col ?>;">
                                            <div class="slot-layer">
                                                <?php for ($s = 0; $s < $slotCount; $s++):
                                                    $mins = $startMinute + ($s * $slotMinutes);
                                                    $hh = intdiv($mins, 60);
                                                    $mm = $mins % 60;
                                                    $time = sprintf('%02d:%02d', $hh, $mm);
                                                ?>
                                                    <button class="slot-btn" type="button" data-date="<?= disp_h($currentDate) ?>" data-team-id="<?= (int)$tid ?>" data-team-name="<?= disp_h($team['name']) ?>" data-time="<?= disp_h($time) ?>" aria-label="<?= disp_h($team['name'] . ' ' . $time) ?>"></button>
                                                <?php endfor; ?>
                                            </div>
                                            <?php foreach (($appointmentsByTeam[$tid] ?? []) as $a):
                                                $start = disp_minutes($a['start_time'] ?? '');
                                                $end = disp_minutes($a['end_time'] ?? '');
                                                if ($end <= $start) $end = $start + 60;
                                                $topIndex = max(0, floor((max($start, $startMinute) - $startMinute) / $slotMinutes));
                                                $span = max(1, ceil((min($end, $endMinute) - max($start, $startMinute)) / $slotMinutes));
                                                if ($start >= $endMinute || $end <= $startMinute) continue;
                                                $topPx = $topIndex * $slotHeight;
                                                $heightPx = max(28, ($span * $slotHeight) - 6);
                                                $tone = disp_status_tone((string)($a['status'] ?? ''));
                                                $client = trim((string)($a['client_name'] ?? '')) ?: trim((string)($a['title'] ?? 'Programare'));
                                            ?>
                                                <a class="event <?= disp_h($tone) ?>" href="calendar.php?date=<?= disp_h($currentDate) ?>&view=day" style="top:<?= (int)$topPx + 3 ?>px; height:<?= (int)$heightPx ?>px;">
                                                    <div class="event-time"><?= disp_h(disp_time($a['start_time'] ?? '')) ?> - <?= disp_h(disp_time($a['end_time'] ?? '')) ?></div>
                                                    <div class="event-title"><?= disp_h($client) ?></div>
                                                    <div class="event-meta"><?= disp_h($a['service_type'] ?? '') ?></div>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php $col++; endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
</div>
            </div>
        </div>
    </main>
</div>
<script>
(function(){
    var selectedTask = null;
    var selectedMini = document.getElementById('selectedTaskMini');

    function esc(s){ return String(s || '').replace(/[&<>'"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]; }); }

    function updateSelectedMini(){
        if (!selectedMini) return;
        if (!selectedTask) {
            selectedMini.classList.add('muted');
            selectedMini.innerHTML = 'Nicio sarcina selectata. Click pe un slot liber deschide formularul.';
            return;
        }
        selectedMini.classList.remove('muted');
        selectedMini.innerHTML = 'Sarcina selectata: <strong>' + esc(selectedTask.client) + '</strong>' + (selectedTask.service ? ' · ' + esc(selectedTask.service) : '') + '. Click pe ora/echipa pentru programare.';
    }

    document.querySelectorAll('.task-item').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.querySelectorAll('.task-item.is-selected').forEach(function(x){ x.classList.remove('is-selected'); });
            btn.classList.add('is-selected');
            selectedTask = {
                id: btn.dataset.taskId || '',
                client: btn.dataset.client || '',
                service: btn.dataset.service || '',
                title: btn.dataset.title || '',
                address: btn.dataset.address || '',
                location: btn.dataset.location || '',
                due: btn.dataset.due || ''
            };
            updateSelectedMini();
        });
    });

    document.querySelectorAll('.slot-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            var params = new URLSearchParams();
            params.set('date', btn.dataset.date || '');
            params.set('view', 'day');
            params.set('open_create', '1');
            params.set('team_member_id', btn.dataset.teamId || '');
            params.set('start_time', btn.dataset.time || '');
            if (selectedTask && selectedTask.id) params.set('task_id', selectedTask.id);
            window.location.href = 'calendar.php?' + params.toString();
        });
    });
})();
</script>
</body>
</html>
