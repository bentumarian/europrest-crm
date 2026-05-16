<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

function cw_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function cw_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}
function cw_date(?string $date): string {
    $date = (string)$date;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $date : date('Y-m-d');
}
function cw_color(?string $color, string $fallback = '#163B63'): string {
    $color = trim((string)$color);
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : $fallback;
}
function cw_lighten(string $hex, float $amount = 0.84): string {
    $hex = cw_color($hex);
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    $r = (int)round($r + (255 - $r) * $amount);
    $g = (int)round($g + (255 - $g) * $amount);
    $b = (int)round($b + (255 - $b) * $amount);
    return sprintf('#%02X%02X%02X', $r, $g, $b);
}
function cw_initials(?string $text): string {
    $text = trim((string)$text);
    if ($text === '') { return '?'; }
    $parts = preg_split('/\s+/', $text) ?: [];
    $out = '';
    foreach ($parts as $p) {
        if ($p === '') { continue; }
        $out .= function_exists('mb_substr') ? mb_substr($p, 0, 1, 'UTF-8') : substr($p, 0, 1);
        if (strlen($out) >= 2) { break; }
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($out ?: '?', 'UTF-8') : strtoupper($out ?: '?');
}
function cw_slot_row(?string $time, int $startHour = 6): int {
    if (!$time || strpos($time, ':') === false) { return 3; }
    [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));
    return max(3, (int)floor((($h * 60 + $m) - ($startHour * 60)) / 30) + 3);
}
function cw_span(?string $start, ?string $end): int {
    if (!$start || !$end || strpos($start, ':') === false || strpos($end, ':') === false) { return 2; }
    [$sh, $sm] = array_map('intval', explode(':', substr($start, 0, 5)));
    [$eh, $em] = array_map('intval', explode(':', substr($end, 0, 5)));
    return max(1, (int)ceil(max(30, ($eh * 60 + $em) - ($sh * 60 + $sm)) / 30));
}
function cw_label(string $date): string {
    $d = new DateTime($date);
    $map = [1 => 'LUN', 2 => 'MAR', 3 => 'MIE', 4 => 'JOI', 5 => 'VIN', 6 => 'SAM', 7 => 'DUM'];
    return ($map[(int)$d->format('N')] ?? $d->format('D')) . '. ' . $d->format('d.m');
}
function cw_selected_team_ids(): array {
    $ids = [];
    $rawIds = $_GET['team_ids'] ?? null;
    $rawTeams = $_GET['teams'] ?? ($_GET['team'] ?? 'all');
    if (is_array($rawIds)) {
        foreach ($rawIds as $rawId) {
            $id = (int)$rawId;
            if ($id > 0) { $ids[$id] = $id; }
        }
    } elseif ((string)$rawTeams !== 'all') {
        foreach (explode(',', (string)$rawTeams) as $rawId) {
            $id = (int)trim($rawId);
            if ($id > 0) { $ids[$id] = $id; }
        }
    }
    return array_values($ids);
}

$isAdmin = is_admin();
$date = cw_date($_GET['date'] ?? date('Y-m-d'));
$selectedTeamIds = cw_selected_team_ids();

$dt = new DateTime($date);
$weekStartObj = (clone $dt)->modify('monday this week');
$weekEndObj = (clone $weekStartObj)->modify('+6 days');
$weekStart = $weekStartObj->format('Y-m-d');
$weekEnd = $weekEndObj->format('Y-m-d');
$prevWeek = (clone $weekStartObj)->modify('-7 days')->format('Y-m-d');
$nextWeek = (clone $weekStartObj)->modify('+7 days')->format('Y-m-d');

$weekDates = [];
for ($i = 0; $i < 7; $i++) {
    $d = (clone $weekStartObj)->modify('+' . $i . ' days');
    $weekDates[] = ['date' => $d->format('Y-m-d'), 'label' => cw_label($d->format('Y-m-d'))];
}

$allTeams = [];
try {
    $allTeams = $pdo->query("SELECT id, name, color FROM team_members WHERE active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$validTeamIds = array_map('intval', array_column($allTeams, 'id'));
$selectedTeamIds = $selectedTeamIds ? array_values(array_intersect($selectedTeamIds, $validTeamIds)) : [];
$teamQueryValue = $selectedTeamIds ? implode(',', $selectedTeamIds) : 'all';
$singleTeamForClassicCalendar = count($selectedTeamIds) === 1 ? (string)$selectedTeamIds[0] : 'all';

$teamSql = "SELECT id, name, color FROM team_members WHERE active = 1";
$teamParams = [];
if ($selectedTeamIds) {
    $teamSql .= " AND id IN (" . implode(',', array_fill(0, count($selectedTeamIds), '?')) . ")";
    $teamParams = $selectedTeamIds;
}
$teamSql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($teamSql);
$stmt->execute($teamParams);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$teams) { $teams = [['id' => 0, 'name' => 'Tehnician', 'color' => '#163B63']]; }
$teamIndexById = [];
foreach ($teams as $i => $t) { $teamIndexById[(int)$t['id']] = $i; }

$slots = [];
for ($h = 6; $h < 24; $h++) { $slots[] = sprintf('%02d:00', $h); $slots[] = sprintf('%02d:30', $h); }

$clients = [];
try {
    $clients = $pdo->query("SELECT id, name, phone, email, COALESCE(NULLIF(registered_address,''), address, '') AS address FROM clients WHERE COALESCE(active,1)=1 ORDER BY name ASC LIMIT 1500")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$services = [];
try {
    if (cw_table_exists($pdo, 'services')) {
        $services = $pdo->query("SELECT name, COALESCE(default_duration,60) AS default_duration FROM services WHERE COALESCE(active,1)=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}
if (!$services) {
    $services = [
        ['name' => 'Deratizare', 'default_duration' => 60],
        ['name' => 'Dezinsectie', 'default_duration' => 60],
        ['name' => 'Dezinfectie', 'default_duration' => 60],
        ['name' => 'Monitorizare', 'default_duration' => 60],
    ];
}

$appointments = [];
$params = [$weekStart, $weekEnd];
$teamFilterMain = '';
if ($selectedTeamIds) {
    $teamFilterMain = ' AND a.team_member_id IN (' . implode(',', array_fill(0, count($selectedTeamIds), '?')) . ')';
    $params = array_merge($params, $selectedTeamIds);
}
try {
    $stmt = $pdo->prepare("SELECT a.*, COALESCE(c.name, a.title, 'Client') AS client_name, tm.id AS display_team_member_id, tm.name AS display_team_name, tm.color AS display_team_color, 1 AS appointment_team_is_primary FROM appointments a LEFT JOIN clients c ON c.id = a.client_id LEFT JOIN team_members tm ON tm.id = a.team_member_id WHERE a.appointment_date BETWEEN ? AND ? AND COALESCE(a.status,'') <> 'anulata' $teamFilterMain");
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (cw_table_exists($pdo, 'appointment_teams')) {
        $params2 = [$weekStart, $weekEnd];
        $teamFilterSupport = '';
        if ($selectedTeamIds) {
            $teamFilterSupport = ' AND at.team_id IN (' . implode(',', array_fill(0, count($selectedTeamIds), '?')) . ')';
            $params2 = array_merge($params2, $selectedTeamIds);
        }
        $stmt = $pdo->prepare("SELECT a.*, COALESCE(c.name, a.title, 'Client') AS client_name, tm.id AS display_team_member_id, tm.name AS display_team_name, tm.color AS display_team_color, at.is_primary AS appointment_team_is_primary FROM appointments a INNER JOIN appointment_teams at ON at.appointment_id = a.id INNER JOIN team_members tm ON tm.id = at.team_id LEFT JOIN clients c ON c.id = a.client_id WHERE a.appointment_date BETWEEN ? AND ? AND COALESCE(a.status,'') <> 'anulata' $teamFilterSupport");
        $stmt->execute($params2);
        $extra = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $seen = [];
        foreach (array_merge($appointments, $extra) as $a) {
            $seen[(int)$a['id'] . '-' . (int)$a['display_team_member_id']] = $a;
        }
        $appointments = array_values($seen);
    }
} catch (Throwable $e) { error_log('Calendar week error: ' . $e->getMessage()); }

$appointmentsByGrid = [];
foreach ($appointments as $a) {
    $teamId = (int)($a['display_team_member_id'] ?? 0);
    if (isset($teamIndexById[$teamId])) { $appointmentsByGrid[] = $a; }
}

$pz_page_title = 'Calendar';
$pz_page_breadcrumbs = ['Tehnicieni'];
?><!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calendar tehnicieni - CRM</title>
    <?php app_theme_css(); ?>
    <style>
    .cw-toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin:0 0 14px;flex-wrap:wrap;background:#F5F7FB;border:1px solid var(--border);border-radius:18px;padding:12px}.cw-left,.cw-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.cw-date{min-width:165px;text-align:center}.cw-card{background:#fff;border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow-md);overflow:hidden}.cw-scroll{overflow:auto;max-height:calc(100vh - 155px);-webkit-overflow-scrolling:touch}.cw-grid{display:grid;grid-template-columns:54px repeat(var(--cw-cols),minmax(34px,1fr));grid-template-rows:32px 34px repeat(36,25px);min-width:max(100%,calc(54px + var(--cw-cols) * 34px));position:relative}.cw-corner{position:sticky;left:0;top:0;z-index:50;background:#fff;border-right:1px solid #94A3B8;border-bottom:1px solid #CBD5E1}.cw-day{position:sticky;top:0;z-index:35;display:flex;align-items:center;justify-content:center;background:#F8FAFC;border-left:3px solid rgba(15,23,42,.72);border-bottom:1px solid #CBD5E1;font-size:11px;font-weight:900;color:#002050;letter-spacing:.04em}.cw-team{position:sticky;top:32px;z-index:34;display:flex;align-items:center;justify-content:center;background:color-mix(in srgb,var(--team-color) 18%,#fff);border-left:1px solid #E2E8F0;border-bottom:1px solid #CBD5E1;box-shadow:inset 0 3px 0 var(--team-color)}.cw-team.day-start{border-left:3px solid rgba(15,23,42,.72)}.cw-dot{width:21px;height:21px;border-radius:999px;background:var(--team-color);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:9px}.cw-time{position:sticky;left:0;z-index:20;background:#fff;border-right:1px solid #94A3B8;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;justify-content:flex-end;padding-right:6px;font-family:var(--mono);font-size:9px;font-weight:700;color:#002050}.cw-slot{background:color-mix(in srgb,var(--team-color) 5%,#fff);border-left:1px solid #EEF2F7;border-bottom:1px solid #EEF2F7;cursor:pointer}.cw-slot.day-start{border-left:3px solid rgba(15,23,42,.72)}.cw-slot:hover{background:color-mix(in srgb,var(--team-color) 16%,#fff);outline:2px solid color-mix(in srgb,var(--team-color) 55%,transparent);outline-offset:-2px}.cw-hour{border-top:1px solid #CBD5E1}.cw-event{z-index:25;margin:2px;border-radius:7px;border:1px solid rgba(0,32,80,.18);box-shadow:0 8px 18px rgba(0,32,80,.13);cursor:pointer;position:relative;overflow:hidden}.cw-event:after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.28),transparent 56%)}.cw-event.done:before{content:'✓';position:absolute;right:4px;top:3px;z-index:2;background:rgba(255,255,255,.88);color:#002050;border-radius:99px;width:14px;height:14px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:900}.cw-team-filter{position:relative}.cw-team-picker{position:relative}.cw-team-picker summary{min-width:190px;min-height:40px;padding:10px 14px;border-radius:12px;border:1px solid var(--border);background:#fff;font-weight:800;cursor:pointer;list-style:none}.cw-team-picker summary::-webkit-details-marker{display:none}.cw-team-menu{position:absolute;right:0;top:calc(100% + 8px);z-index:90;width:250px;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-lg);padding:10px}.cw-team-all{display:block;padding:8px 10px;border-radius:10px;text-decoration:none;color:#002050;font-weight:850;background:#F8FAFC;margin-bottom:6px}.cw-team-option{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:9px;font-size:12px;font-weight:750;color:#002050;cursor:pointer}.cw-team-option:hover{background:#F8FAFC}.cw-team-option input{width:15px;height:15px}.cw-team-apply{width:100%;justify-content:center;margin-top:8px}.cw-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:400;padding:20px;overflow:auto}.cw-modal-backdrop.open{display:block}.cw-modal{background:#fff;border-radius:18px;max-width:680px;margin:30px auto;padding:18px;border:1px solid var(--border);box-shadow:var(--shadow-lg)}.cw-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:center;border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:14px}.cw-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.cw-full{grid-column:1/-1}.cw-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}@media(max-width:860px){.cw-grid{grid-template-columns:42px repeat(var(--cw-cols),minmax(30px,1fr));grid-template-rows:30px 32px repeat(36,23px);min-width:calc(42px + var(--cw-cols) * 30px)}.cw-time{font-size:8px;padding-right:4px}.cw-day{font-size:9px}.cw-dot{width:19px;height:19px;font-size:8px}.cw-toolbar{padding:8px}.cw-team-menu{right:auto;left:0}.cw-form-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('calendar', $isAdmin); ?>
    <main class="main">
        <div class="content">
            <div class="cw-toolbar">
                <div class="cw-left">
                    <a class="btn" href="calendar_week.php?date=<?= cw_h($prevWeek) ?>&teams=<?= cw_h($teamQueryValue) ?>">‹</a>
                    <a class="btn orange" href="calendar_week.php?date=<?= date('Y-m-d') ?>&teams=<?= cw_h($teamQueryValue) ?>">Azi</a>
                    <a class="btn" href="calendar_week.php?date=<?= cw_h($nextWeek) ?>&teams=<?= cw_h($teamQueryValue) ?>">›</a>
                    <span class="btn cw-date"><?= cw_h($weekStartObj->format('d.m.Y')) ?> - <?= cw_h($weekEndObj->format('d.m.Y')) ?></span>
                </div>
                <div class="cw-right">
                    <a class="btn" href="calendar.php?date=<?= cw_h($date) ?>&view=day&team=<?= cw_h($singleTeamForClassicCalendar) ?>">Zi</a>
                    <a class="btn accent" href="calendar_week.php?date=<?= cw_h($date) ?>&teams=<?= cw_h($teamQueryValue) ?>">Saptamana</a>
                    <a class="btn" href="calendar.php?date=<?= cw_h($date) ?>&view=month&team=<?= cw_h($singleTeamForClassicCalendar) ?>">Luna</a>
                    <form method="get" action="calendar_week.php" class="cw-team-filter">
                        <input type="hidden" name="date" value="<?= cw_h($date) ?>">
                        <details class="cw-team-picker">
                            <summary><?= $selectedTeamIds ? (count($selectedTeamIds) . ' tehnicieni') : 'Toate echipele' ?></summary>
                            <div class="cw-team-menu">
                                <a class="cw-team-all" href="calendar_week.php?date=<?= cw_h($date) ?>&teams=all">Toate echipele</a>
                                <?php foreach ($allTeams as $t): ?>
                                    <label class="cw-team-option">
                                        <input type="checkbox" name="team_ids[]" value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $selectedTeamIds, true) ? 'checked' : '' ?>>
                                        <span><?= cw_h($t['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <button class="btn accent cw-team-apply" type="submit">Aplica</button>
                            </div>
                        </details>
                    </form>
                </div>
            </div>

            <div class="cw-card"><div class="cw-scroll">
                <?php $cols = count($weekDates) * max(1, count($teams)); ?>
                <div class="cw-grid" style="--cw-cols:<?= (int)$cols ?>;">
                    <div class="cw-corner" style="grid-column:1;grid-row:1 / span 2;"></div>
                    <?php foreach ($weekDates as $dIndex => $d): $startCol = 2 + $dIndex * count($teams); ?>
                        <div class="cw-day" style="grid-column:<?= $startCol ?> / span <?= count($teams) ?>;grid-row:1;"><?= cw_h($d['label']) ?></div>
                        <?php foreach ($teams as $tIndex => $team): $color = cw_color($team['color'] ?? null); $col = $startCol + $tIndex; ?>
                            <div class="cw-team <?= $tIndex === 0 ? 'day-start' : '' ?>" style="grid-column:<?= $col ?>;grid-row:2;--team-color:<?= cw_h($color) ?>;" title="<?= cw_h($team['name']) ?>"><span class="cw-dot"><?= cw_h(cw_initials($team['name'])) ?></span></div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <?php foreach ($slots as $slotIndex => $slot): $row = $slotIndex + 3; $isHour = substr($slot, -3) === ':00'; ?>
                        <div class="cw-time <?= $isHour ? 'cw-hour' : '' ?>" style="grid-column:1;grid-row:<?= $row ?>;"><?= $isHour ? cw_h($slot) : '' ?></div>
                        <?php foreach ($weekDates as $dIndex => $d): $startCol = 2 + $dIndex * count($teams); ?>
                            <?php foreach ($teams as $tIndex => $team): $color = cw_color($team['color'] ?? null); $col = $startCol + $tIndex; ?>
                                <div class="cw-slot <?= $tIndex === 0 ? 'day-start' : '' ?> <?= $isHour ? 'cw-hour' : '' ?>" style="grid-column:<?= $col ?>;grid-row:<?= $row ?>;--team-color:<?= cw_h($color) ?>;" onclick="cwOpenCreate('<?= cw_h($d['date']) ?>','<?= cw_h($slot) ?>','<?= (int)$team['id'] ?>')"></div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <?php foreach ($appointmentsByGrid as $a):
                        $teamId = (int)$a['display_team_member_id'];
                        $teamIndex = $teamIndexById[$teamId] ?? null;
                        if ($teamIndex === null) { continue; }
                        $dayIndex = null;
                        foreach ($weekDates as $idx => $d) { if ($d['date'] === ($a['appointment_date'] ?? '')) { $dayIndex = $idx; break; } }
                        if ($dayIndex === null) { continue; }
                        $col = 2 + $dayIndex * count($teams) + $teamIndex;
                        $row = cw_slot_row($a['start_time'] ?? null);
                        $span = cw_span($a['start_time'] ?? null, $a['end_time'] ?? null);
                        $baseColor = cw_color($a['display_team_color'] ?? null);
                        $isDone = (($a['status'] ?? '') === 'finalizata');
                        $color = $isDone ? cw_lighten($baseColor) : ((($a['status'] ?? '') === 'in_lucru') ? '#64748B' : $baseColor);
                        $title = ($a['client_name'] ?? 'Client') . ' - ' . substr((string)($a['start_time'] ?? ''), 0, 5) . ' - ' . ($a['service_type'] ?? 'Lucrare') . ' - ' . ($a['display_team_name'] ?? 'Tehnician');
                    ?>
                        <div class="cw-event <?= $isDone ? 'done' : '' ?>" style="grid-column:<?= $col ?>;grid-row:<?= $row ?>/span <?= $span ?>;background:<?= cw_h($color) ?>;" title="<?= cw_h($title) ?>" onclick="window.location.href='calendar.php?date=<?= cw_h($a['appointment_date']) ?>&view=day&team=<?= (int)$teamId ?>'"></div>
                    <?php endforeach; ?>
                </div>
            </div></div>
        </div>
    </main>
</div>

<div class="cw-modal-backdrop" id="cwCreateModal">
    <div class="cw-modal">
        <div class="cw-modal-head"><h2 style="margin:0;">Programare noua</h2><button class="modal-close" type="button" onclick="cwCloseCreate()">&times;</button></div>
        <form method="post" action="calendar.php" id="cwCreateForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="redirect_date" id="cw_redirect_date" value="<?= cw_h($date) ?>">
            <input type="hidden" name="redirect_view" value="week">
            <input type="hidden" name="redirect_team" value="all">
            <input type="hidden" name="appointment_date" id="cw_date" value="">
            <input type="hidden" name="start_time" id="cw_time" value="">
            <input type="hidden" name="team_member_id" id="cw_team" value="">
            <input type="hidden" name="duration" id="cw_duration_hidden" value="60">
            <div class="cw-form-grid">
                <div class="cw-full"><label>Client *</label><select name="client_id" required><option value="">Alege clientul</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= cw_h($c['name']) ?></option><?php endforeach; ?></select></div>
                <div><label>Serviciu *</label><select name="service_type" required onchange="cwSetDuration(this)"><option value="">Alege lucrarea</option><?php foreach ($services as $s): ?><option value="<?= cw_h($s['name']) ?>" data-duration="<?= (int)$s['default_duration'] ?>"><?= cw_h($s['name']) ?></option><?php endforeach; ?></select></div>
                <div><label>Durata</label><select onchange="document.getElementById('cw_duration_hidden').value=this.value"><option value="30">30 min</option><option value="60" selected>1 ora</option><option value="90">1 ora 30</option><option value="120">2 ore</option><option value="180">3 ore</option><option value="240">4 ore</option></select></div>
                <div><label>Contact</label><input type="text" name="contact_person"></div>
                <div><label>Telefon</label><input type="tel" name="contact_phone"></div>
                <div class="cw-full"><label>Adresa</label><input type="text" name="address"></div>
                <div><label>Suma fara TVA *</label><input type="number" min="0" step="0.01" name="billing_amount" id="cw_billing_amount" value="" placeholder="0.00" required></div>
                <div><label>Moneda</label><input type="text" name="currency" value="RON" maxlength="3"></div>
                <div class="cw-full" style="display:flex;align-items:center;gap:10px;background:#FFF4E5;border:1px solid #F5D5A3;border-radius:10px;padding:10px 12px;">
                    <label style="display:flex;gap:8px;align-items:center;font-weight:800;cursor:pointer;margin:0;">
                        <input type="checkbox" name="not_invoiceable" id="cw_not_invoiceable" value="1" onchange="cwToggleNotInvoiceable()" style="width:16px;height:16px;">
                        Nu se factureaza
                    </label>
                    <span style="font-size:12px;color:#7C5E2A;">(bifeaza daca lucrarea nu se factureaza, apoi completeaza motivul)</span>
                </div>
                <div class="cw-full" id="cw_billing_note_wrap" style="display:none;">
                    <label>Motiv (obligatoriu daca nu se factureaza) *</label>
                    <textarea name="billing_note" id="cw_billing_note" rows="2" placeholder="Ex: lucrare in garantie, recheck gratuit, etc."></textarea>
                </div>
                <div class="cw-full"><label>Observatii pentru echipa</label><textarea name="notes" rows="3"></textarea></div>
            </div>
            <div class="cw-actions"><button class="btn" type="button" onclick="cwCloseCreate()">Renunta</button><button class="btn accent" type="submit">Salveaza programarea</button></div>
        </form>
    </div>
</div>

<script>
function cwOpenCreate(date, time, teamId){
    document.getElementById('cw_date').value = date;
    document.getElementById('cw_time').value = time;
    document.getElementById('cw_team').value = teamId;
    document.getElementById('cw_redirect_date').value = date;
    // Reset billing state la fiecare deschidere
    var notInv = document.getElementById('cw_not_invoiceable');
    if (notInv) notInv.checked = false;
    var note = document.getElementById('cw_billing_note');
    if (note) note.value = '';
    var amt = document.getElementById('cw_billing_amount');
    if (amt) amt.value = '';
    cwToggleNotInvoiceable();
    document.getElementById('cwCreateModal').classList.add('open');
}
function cwCloseCreate(){ document.getElementById('cwCreateModal').classList.remove('open'); }
function cwSetDuration(sel){
    var opt = sel.options[sel.selectedIndex];
    if(opt && opt.dataset.duration){ document.getElementById('cw_duration_hidden').value = opt.dataset.duration; }
}
function cwToggleNotInvoiceable(){
    var check = document.getElementById('cw_not_invoiceable');
    var amount = document.getElementById('cw_billing_amount');
    var noteWrap = document.getElementById('cw_billing_note_wrap');
    var note = document.getElementById('cw_billing_note');
    var checked = !!(check && check.checked);
    if (amount){
        amount.required = !checked;
        amount.disabled = checked;
        if (checked) amount.value = '0.00';
    }
    if (noteWrap) noteWrap.style.display = checked ? 'block' : 'none';
    if (note) note.required = checked;
}
document.addEventListener('DOMContentLoaded', function(){
    var form = document.getElementById('cwCreateForm');
    if (!form) return;
    form.addEventListener('submit', function(e){
        var notInv = !!document.getElementById('cw_not_invoiceable').checked;
        var note = (document.getElementById('cw_billing_note').value || '').trim();
        var amtRaw = (document.getElementById('cw_billing_amount').value || '0').replace(',', '.');
        var amt = Number(amtRaw);
        if (notInv){
            if (!note){
                e.preventDefault();
                alert('Completeaza motivul pentru care lucrarea nu se factureaza.');
                document.getElementById('cw_billing_note').focus();
                return;
            }
        } else {
            if (!Number.isFinite(amt) || amt <= 0){
                e.preventDefault();
                alert('Completeaza suma fara TVA, sau bifeaza "Nu se factureaza" si trece motivul.');
                document.getElementById('cw_billing_amount').focus();
                return;
            }
        }
    });
});
document.addEventListener('keydown', function(e){ if(e.key === 'Escape') cwCloseCreate(); });
document.addEventListener('click', function(e){
    document.querySelectorAll('.cw-team-picker[open]').forEach(function(d){ if(!d.contains(e.target)){ d.removeAttribute('open'); } });
});
</script>
<?php app_toast_container(); ?>
</body>
</html>
