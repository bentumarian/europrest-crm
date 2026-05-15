<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/notification_lib.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

function pz_drag_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function pz_drag_time_to_minutes(string $time): ?int
{
    $time = trim($time);
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
        return null;
    }
    $h = (int)$m[1];
    $min = (int)$m[2];
    if ($h < 0 || $h > 23 || !in_array($min, [0, 30], true)) {
        return null;
    }
    return $h * 60 + $min;
}

function pz_drag_minutes_to_time(int $minutes): string
{
    $minutes = max(0, min(1439, $minutes));
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d:00', $h, $m);
}

function pz_drag_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}


function pz_drag_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function pz_drag_sync_primary_team(PDO $pdo, int $appointmentId, int $newTeamId): void
{
    if ($appointmentId <= 0 || $newTeamId <= 0 || !pz_drag_table_exists($pdo, 'appointment_teams')) {
        return;
    }

    $stmt = $pdo->prepare("SELECT team_id FROM appointment_teams WHERE appointment_id = ? AND is_primary = 0");
    $stmt->execute([$appointmentId]);
    $supportIds = [];
    while ($teamId = $stmt->fetchColumn()) {
        $id = (int)$teamId;
        if ($id > 0 && $id !== $newTeamId) {
            $supportIds[$id] = $id;
        }
    }

    $pdo->prepare("DELETE FROM appointment_teams WHERE appointment_id = ?")->execute([$appointmentId]);
    $insert = $pdo->prepare("INSERT IGNORE INTO appointment_teams (appointment_id, team_id, is_primary) VALUES (?, ?, ?)");
    $insert->execute([$appointmentId, $newTeamId, 1]);

    foreach ($supportIds as $supportId) {
        $insert->execute([$appointmentId, $supportId, 0]);
    }
}

function pz_drag_assigned_team_ids_after_move(PDO $pdo, int $appointmentId, int $newPrimaryTeamId): array
{
    $ids = [];
    if ($newPrimaryTeamId > 0) {
        $ids[$newPrimaryTeamId] = $newPrimaryTeamId;
    }

    if ($appointmentId > 0 && pz_drag_table_exists($pdo, 'appointment_teams')) {
        $stmt = $pdo->prepare("SELECT team_id FROM appointment_teams WHERE appointment_id = ? AND is_primary = 0");
        $stmt->execute([$appointmentId]);
        while ($teamId = $stmt->fetchColumn()) {
            $id = (int)$teamId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    return array_values($ids);
}

function pz_drag_find_team_time_conflicts(PDO $pdo, array $teamIds, string $appointmentDate, string $startTime, string $endTime, int $excludeAppointmentId): array
{
    $clean = [];
    foreach ($teamIds as $teamId) {
        $id = (int)$teamId;
        if ($id > 0) {
            $clean[$id] = $id;
        }
    }
    $teamIds = array_values($clean);
    if (!$teamIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $params = $teamIds;

    if (pz_drag_table_exists($pdo, 'appointment_teams')) {
        $sql = "
            SELECT DISTINCT
                tm.id AS team_id,
                tm.name AS team_name,
                a.id AS appointment_id,
                a.start_time,
                a.end_time,
                COALESCE(c.name, a.title, 'Programare') AS client_name
            FROM team_members tm
            INNER JOIN appointments a
                ON a.team_member_id = tm.id
                OR EXISTS (
                    SELECT 1
                    FROM appointment_teams atx
                    WHERE atx.appointment_id = a.id
                      AND atx.team_id = tm.id
                )
            LEFT JOIN clients c ON c.id = a.client_id
            WHERE tm.id IN ($placeholders)
              AND a.appointment_date = ?
              AND COALESCE(a.status, '') <> 'anulata'
              AND a.start_time < ?
              AND a.end_time > ?
              AND a.id <> ?
            ORDER BY tm.name ASC, a.start_time ASC
        ";
    } else {
        $sql = "
            SELECT DISTINCT
                tm.id AS team_id,
                tm.name AS team_name,
                a.id AS appointment_id,
                a.start_time,
                a.end_time,
                COALESCE(c.name, a.title, 'Programare') AS client_name
            FROM appointments a
            INNER JOIN team_members tm ON tm.id = a.team_member_id
            LEFT JOIN clients c ON c.id = a.client_id
            WHERE tm.id IN ($placeholders)
              AND a.appointment_date = ?
              AND COALESCE(a.status, '') <> 'anulata'
              AND a.start_time < ?
              AND a.end_time > ?
              AND a.id <> ?
            ORDER BY tm.name ASC, a.start_time ASC
        ";
    }

    $params[] = $appointmentDate;
    $params[] = $endTime;
    $params[] = $startTime;
    $params[] = $excludeAppointmentId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pz_drag_conflict_message(array $conflicts): string
{
    if (!$conflicts) {
        return 'Un tehnician este deja alocat in intervalul selectat.';
    }

    $parts = [];
    foreach ($conflicts as $conflict) {
        $teamName = trim((string)($conflict['team_name'] ?? 'Tehnician'));
        $clientName = trim((string)($conflict['client_name'] ?? 'programare'));
        $start = substr((string)($conflict['start_time'] ?? ''), 0, 5);
        $end = substr((string)($conflict['end_time'] ?? ''), 0, 5);
        $parts[] = $teamName . ' este deja alocat la ' . $clientName . ' intre ' . $start . ' - ' . $end;
    }

    return implode('; ', array_slice($parts, 0, 3));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pz_drag_json(['ok' => false, 'error' => 'Metoda invalida.'], 405);
}

if (!function_exists('is_admin') || !is_admin()) {
    pz_drag_json(['ok' => false, 'error' => 'Nu ai drepturi pentru mutarea programarilor.'], 403);
}

if (function_exists('csrf_check') && !csrf_check()) {
    pz_drag_json(['ok' => false, 'error' => 'Sesiunea a expirat. Reincarca pagina si incearca din nou.'], 419);
}

$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$newTeamId = (int)($_POST['new_team_id'] ?? 0);
$newDate = trim((string)($_POST['new_date'] ?? ''));
$newStartRaw = trim((string)($_POST['new_start_time'] ?? ''));

if ($appointmentId <= 0 || $newTeamId <= 0 || !pz_drag_valid_date($newDate)) {
    pz_drag_json(['ok' => false, 'error' => 'Date invalide pentru mutare.'], 422);
}

$newStartMinutes = pz_drag_time_to_minutes($newStartRaw);
if ($newStartMinutes === null) {
    pz_drag_json(['ok' => false, 'error' => 'Ora noua este invalida.'], 422);
}

try {
    $teamStmt = $pdo->prepare("SELECT id FROM team_members WHERE id = ? AND active = 1 LIMIT 1");
    $teamStmt->execute([$newTeamId]);
    if (!$teamStmt->fetchColumn()) {
        pz_drag_json(['ok' => false, 'error' => 'Tehnicianul ales nu exista sau este inactiv.'], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ? LIMIT 1");
    $stmt->execute([$appointmentId]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old) {
        pz_drag_json(['ok' => false, 'error' => 'Programarea nu exista.'], 404);
    }

    if (($old['status'] ?? '') === 'finalizata') {
        pz_drag_json(['ok' => false, 'error' => 'Lucrarea finalizata nu poate fi mutata prin drag & drop.'], 409);
    }

    $oldStartMinutes = pz_drag_time_to_minutes((string)($old['start_time'] ?? '')) ?? 9 * 60;
    $oldEndMinutes = pz_drag_time_to_minutes((string)($old['end_time'] ?? ''));
    $duration = 60;
    if ($oldEndMinutes !== null && $oldEndMinutes > $oldStartMinutes) {
        $duration = max(30, $oldEndMinutes - $oldStartMinutes);
    }

    $newEndMinutes = $newStartMinutes + $duration;
    if ($newEndMinutes > 1439) {
        pz_drag_json(['ok' => false, 'error' => 'Lucrarea nu incape in ziua selectata. Alege o ora mai devreme.'], 422);
    }

    $newStartTime = pz_drag_minutes_to_time($newStartMinutes);
    $newEndTime = pz_drag_minutes_to_time($newEndMinutes);

    $oldDate = (string)($old['appointment_date'] ?? '');
    $oldStart = substr((string)($old['start_time'] ?? ''), 0, 5);
    $oldEnd = substr((string)($old['end_time'] ?? ''), 0, 5);
    $newStartShort = substr($newStartTime, 0, 5);
    $newEndShort = substr($newEndTime, 0, 5);

    $timeOrDateChanged = ($oldDate !== $newDate || $oldStart !== $newStartShort || $oldEnd !== $newEndShort);

    $assignedTeamIds = pz_drag_assigned_team_ids_after_move($pdo, $appointmentId, $newTeamId);
    $conflicts = pz_drag_find_team_time_conflicts($pdo, $assignedTeamIds, $newDate, $newStartTime, $newEndTime, $appointmentId);
    if ($conflicts) {
        pz_drag_json(['ok' => false, 'error' => pz_drag_conflict_message($conflicts)], 409);
    }

    $update = $pdo->prepare("UPDATE appointments SET team_member_id = ?, appointment_date = ?, start_time = ?, end_time = ? WHERE id = ?");
    $update->execute([$newTeamId, $newDate, $newStartTime, $newEndTime, $appointmentId]);
    pz_drag_sync_primary_team($pdo, $appointmentId, $newTeamId);

    // Regula noua: mutarea prin drag & drop nu mai trimite SMS automat.
    // Daca s-a schimbat data/ora, SMS-ul se trimite manual din fisa programarii.
    $smsStatus = 'not_sent';
    $smsError = null;

    pz_drag_json([
        'ok' => true,
        'appointment_id' => $appointmentId,
        'new_team_id' => $newTeamId,
        'new_date' => $newDate,
        'new_start_time' => $newStartTime,
        'new_end_time' => $newEndTime,
        'time_or_date_changed' => $timeOrDateChanged,
        'sms_status' => $smsStatus,
        'sms_error' => $smsError,
    ]);
} catch (Throwable $e) {
    error_log('PestZone appointment_drag_update error: ' . $e->getMessage());
    pz_drag_json(['ok' => false, 'error' => 'Eroare server la mutarea programarii.'], 500);
}
