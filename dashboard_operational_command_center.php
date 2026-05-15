<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();
if (!$isAdmin) {
    header("Location: calendar.php");
    exit;
}

function dash_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dash_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('dashboard table check error: ' . $e->getMessage());
        return false;
    }
}

function dash_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('dashboard column check error: ' . $e->getMessage());
        return false;
    }
}

function dash_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('dashboard count error: ' . $e->getMessage() . ' | ' . $sql);
        return 0;
    }
}

function dash_one(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('dashboard one error: ' . $e->getMessage() . ' | ' . $sql);
        return [];
    }
}

function dash_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('dashboard rows error: ' . $e->getMessage() . ' | ' . $sql);
        return [];
    }
}

function dash_money($amount): string {
    return number_format((float)$amount, 0, ',', '.');
}

function dash_time(?string $time): string {
    return $time ? substr((string)$time, 0, 5) : '--:--';
}

function dash_status_label(string $status): string {
    $map = [
        'neconfirmata' => 'Neconfirmata',
        'confirmata' => 'Confirmata',
        'in_lucru' => 'In lucru',
        'finalizata' => 'Finalizata',
        'anulata' => 'Anulata',
        'de_programat' => 'De programat',
        'contactat' => 'Contactat',
        'amanat' => 'Amanat',
        'programat' => 'Programat',
        'draft' => 'Draft',
        'issued' => 'Emis',
    ];
    return $map[$status] ?? $status;
}

function dash_status_tone(string $status): string {
    $map = [
        'finalizata' => 'success',
        'confirmata' => 'info',
        'in_lucru' => 'info',
        'neconfirmata' => 'warning',
        'de_programat' => 'warning',
        'amanat' => 'warning',
        'anulata' => 'muted',
        'issued' => 'success',
        'draft' => 'warning',
    ];
    return $map[$status] ?? 'muted';
}

function dash_initials(string $name): string {
    $name = trim($name);
    if ($name === '') return '?';
    $parts = preg_split('/\s+/', $name);
    $letters = '';
    foreach ($parts as $p) {
        if ($p !== '') $letters .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($letters) >= 2) break;
    }
    return $letters ?: '?';
}

function dash_icon(string $name): string {
    $icons = [
        'plus' => '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="4"/><path d="M8 2v5M16 2v5M3 10h18"/></svg>',
        'client' => '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.5-3.7 2.7-6 5.5-6s5 2.3 5.5 6"/><path d="M17 9.5h4M19 7.5v4"/></svg>',
        'contract' => '<svg viewBox="0 0 24 24"><path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5M8 12h8M8 16h6"/></svg>',
        'pv' => '<svg viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="3"/><path d="M9 8h6M9 12h6M9 16h3M14 17l1.5 1.5L19 15"/></svg>',
        'invoice' => '<svg viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.2-2 1.2-2-1.2-2 1.2-2-1.2L6 21V3Z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>',
        'task' => '<svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M8 9h8M8 13h8M8 17h4"/></svg>',
        'team' => '<svg viewBox="0 0 24 24"><circle cx="8.5" cy="8" r="3"/><path d="M3 20c.5-3.5 2.7-5.5 5.5-5.5S13.5 16.5 14 20"/><circle cx="17" cy="10" r="2.5"/><path d="M15.5 15.5c2.6.3 4.5 1.9 5 4.5"/></svg>',
        'money' => '<svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="3"/><circle cx="12" cy="12" r="3"/><path d="M6 10v4M18 10v4"/></svg>',
        'mail' => '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M4 7l8 6 8-6"/></svg>',
        'warning' => '<svg viewBox="0 0 24 24"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17h.01"/></svg>',
        'check' => '<svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>',
        'arrow' => '<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
        'trend' => '<svg viewBox="0 0 24 24"><path d="M4 17 9 12l4 4 7-8"/><path d="M15 8h5v5"/></svg>',
        'doc' => '<svg viewBox="0 0 24 24"><path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5M8 13h8M8 17h5"/></svg>',
        'risk' => '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="M12 8v5M12 16h.01"/></svg>',
        'clock' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/></svg>',
        'star' => '<svg viewBox="0 0 24 24"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/></svg>',
    ];
    return '<span class="dash-ico">' . ($icons[$name] ?? $icons['check']) . '</span>';
}

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$soonDate = date('Y-m-d', strtotime('+30 days'));

$hasAppointments = dash_table_exists($pdo, 'appointments');
$hasTasks = dash_table_exists($pdo, 'tasks');
$hasDocuments = dash_table_exists($pdo, 'documents');
$hasTeams = dash_table_exists($pdo, 'team_members');
$hasClients = dash_table_exists($pdo, 'clients');
$hasContracts = dash_table_exists($pdo, 'contracts');
$hasNotifications = dash_table_exists($pdo, 'notification_logs');
$hasDocumentEmailLogs = dash_table_exists($pdo, 'document_email_logs');
$hasReviews = dash_table_exists($pdo, 'review_requests');
$hasBillingDocs = dash_table_exists($pdo, 'billing_oblio_documents');

$appointmentsToday = 0;
$completedToday = 0;
$inProgressToday = 0;
$pendingToday = 0;
$appointmentsWeek = 0;
$completedWeek = 0;
$todayAppointments = [];
$finishedNoPv = 0;
$finishedNoPvToday = 0;
$finishedNoPvList = [];
$ibDue = 0;
$ibDueAmount = 0.0;
$ibNoValue = 0;
$ibBilledMonth = 0;
$ibBilledMonthAmount = 0.0;
$ibNoBillMonth = 0;
$ibNoBillMonthAmount = 0.0;
$ibOldestList = [];

if ($hasAppointments) {
    $appointmentsToday = dash_count($pdo, "SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status != 'anulata'", [$today]);
    $completedToday = dash_count($pdo, "SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status = 'finalizata'", [$today]);
    $inProgressToday = dash_count($pdo, "SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status = 'in_lucru'", [$today]);
    $pendingToday = dash_count($pdo, "SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status NOT IN ('finalizata','anulata')", [$today]);
    $appointmentsWeek = dash_count($pdo, "SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status != 'anulata'", [$weekStart, $weekEnd]);
    $completedWeek = dash_count($pdo, "SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status = 'finalizata'", [$weekStart, $weekEnd]);

    $todayAppointments = dash_rows($pdo, "
        SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.service_type, a.status,
               c.name AS client_name, t.name AS team_name, t.color AS team_color
        FROM appointments a
        LEFT JOIN clients c ON c.id = a.client_id
        LEFT JOIN team_members t ON t.id = a.team_member_id
        WHERE a.appointment_date = ? AND a.status != 'anulata'
        ORDER BY a.start_time ASC, a.id ASC
        LIMIT 8
    ", [$today]);

    if ($hasDocuments) {
        $finishedNoPvSql = "
            FROM appointments a
            LEFT JOIN documents d
              ON d.appointment_id = a.id
             AND d.document_type = 'proces_verbal'
             AND d.status = 'issued'
            WHERE a.status = 'finalizata'
              AND d.id IS NULL
        ";
        $finishedNoPv = dash_count($pdo, "SELECT COUNT(*) " . $finishedNoPvSql);
        $finishedNoPvToday = dash_count($pdo, "SELECT COUNT(*) " . $finishedNoPvSql . " AND a.appointment_date = ?", [$today]);
        $finishedNoPvList = dash_rows($pdo, "
            SELECT a.id, a.appointment_date, a.start_time, a.service_type, c.name AS client_name
            " . $finishedNoPvSql . "
            ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC
            LIMIT 5
        ");
    }

    if (dash_column_exists($pdo, 'appointments', 'billing_status')) {
        $moneyRow = dash_one($pdo, "
            SELECT COUNT(*) AS total, COALESCE(SUM(billing_amount), 0) AS amount_total
            FROM appointments
            WHERE status = 'finalizata' AND billing_status = 'de_facturat'
        ");
        $ibDue = (int)($moneyRow['total'] ?? 0);
        $ibDueAmount = (float)($moneyRow['amount_total'] ?? 0);

        if (dash_column_exists($pdo, 'appointments', 'billing_amount')) {
            $ibNoValue = dash_count($pdo, "
                SELECT COUNT(*) FROM appointments
                WHERE status = 'finalizata'
                  AND billing_status = 'de_facturat'
                  AND (billing_amount IS NULL OR billing_amount <= 0)
            ");
        }

        $billedRow = dash_one($pdo, "
            SELECT COUNT(*) AS total, COALESCE(SUM(billing_amount), 0) AS amount_total
            FROM appointments
            WHERE status = 'finalizata'
              AND billing_status = 'facturata'
              AND appointment_date BETWEEN ? AND ?
        ", [$monthStart, $monthEnd]);
        $ibBilledMonth = (int)($billedRow['total'] ?? 0);
        $ibBilledMonthAmount = (float)($billedRow['amount_total'] ?? 0);

        $noBillRow = dash_one($pdo, "
            SELECT COUNT(*) AS total, COALESCE(SUM(billing_amount), 0) AS amount_total
            FROM appointments
            WHERE status = 'finalizata'
              AND billing_status = 'nu_se_factureaza'
              AND appointment_date BETWEEN ? AND ?
        ", [$monthStart, $monthEnd]);
        $ibNoBillMonth = (int)($noBillRow['total'] ?? 0);
        $ibNoBillMonthAmount = (float)($noBillRow['amount_total'] ?? 0);

        $ibOldestList = dash_rows($pdo, "
            SELECT a.id, a.appointment_date, a.start_time, a.service_type, a.billing_amount, c.name AS client_name
            FROM appointments a
            LEFT JOIN clients c ON c.id = a.client_id
            WHERE a.status = 'finalizata'
              AND a.billing_status = 'de_facturat'
            ORDER BY a.appointment_date ASC, a.start_time ASC, a.id ASC
            LIMIT 5
        ");
    }
}

$tasksOverdueCount = 0;
$tasksTodayCount = 0;
$backlogTotal = 0;
$backlogList = [];
$tasksSkippedMonth = 0;
if ($hasTasks) {
    $tasksOverdueCount = dash_count($pdo, "SELECT COUNT(*) FROM tasks WHERE due_date < ? AND status IN ('de_programat','contactat','amanat') AND COALESCE(recurrence_stopped, 0) = 0", [$today]);
    $tasksTodayCount = dash_count($pdo, "SELECT COUNT(*) FROM tasks WHERE due_date = ? AND status IN ('de_programat','contactat','amanat') AND COALESCE(recurrence_stopped, 0) = 0", [$today]);
    $backlogTotal = $tasksOverdueCount + $tasksTodayCount;
    $backlogList = dash_rows($pdo, "
        SELECT tk.id, tk.service_type, tk.due_date, tk.status, tk.contact_person, tk.contact_phone, c.name AS client_name
        FROM tasks tk
        LEFT JOIN clients c ON c.id = tk.client_id
        WHERE tk.due_date <= ?
          AND tk.status IN ('de_programat','contactat','amanat')
          AND COALESCE(tk.recurrence_stopped, 0) = 0
        ORDER BY tk.due_date ASC, tk.id ASC
        LIMIT 5
    ", [$today]);
    if (dash_column_exists($pdo, 'tasks', 'skipped_at')) {
        $tasksSkippedMonth = dash_count($pdo, "SELECT COUNT(*) FROM tasks WHERE skipped_at BETWEEN ? AND ?", [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
    }
}

$teamStats = [];
$teamsTotal = 0;
$teamsFree = 0;
$teamsBusy = 0;
$teamCapacityHours = 8.0;
if ($hasTeams && $hasAppointments) {
    $teamStats = dash_rows($pdo, "
        SELECT tm.id, tm.name, tm.color,
               COUNT(a.id) AS jobs_total,
               SUM(CASE WHEN a.status = 'finalizata' THEN 1 ELSE 0 END) AS jobs_done,
               SUM(CASE WHEN a.status = 'in_lucru' THEN 1 ELSE 0 END) AS jobs_active,
               COALESCE(SUM(CASE WHEN a.start_time IS NOT NULL AND a.end_time IS NOT NULL
                    THEN GREATEST(0, TIME_TO_SEC(a.end_time) - TIME_TO_SEC(a.start_time)) / 3600
                    ELSE 0 END), 0) AS hours_booked,
               MIN(CASE WHEN a.status NOT IN ('finalizata','anulata') THEN a.start_time END) AS next_start
        FROM team_members tm
        LEFT JOIN appointments a
          ON a.team_member_id = tm.id
         AND a.appointment_date = ?
         AND a.status != 'anulata'
        WHERE tm.active = 1
        GROUP BY tm.id, tm.name, tm.color
        ORDER BY hours_booked DESC, tm.name ASC
    ", [$today]);
    foreach ($teamStats as $tm) {
        $teamsTotal++;
        if ((float)($tm['hours_booked'] ?? 0) <= 0.01) $teamsFree++; else $teamsBusy++;
    }
}

$pvIssuedToday = 0;
$pvDraftToday = 0;
$pvUnsent = 0;
$pvUnsigned = 0;
$pvUnsentList = [];
$contractsExpireSoon = 0;
$contractsSeason = 0;
$docsContractsMonth = 0;
if ($hasDocuments) {
    $pvIssuedToday = dash_count($pdo, "SELECT COUNT(*) FROM documents WHERE document_type = 'proces_verbal' AND status = 'issued' AND document_date = ?", [$today]);
    $pvDraftToday = dash_count($pdo, "SELECT COUNT(*) FROM documents WHERE document_type = 'proces_verbal' AND status = 'draft' AND document_date = ?", [$today]);
    $pvUnsent = dash_count($pdo, "SELECT COUNT(*) FROM documents WHERE document_type = 'proces_verbal' AND status = 'issued' AND COALESCE(email_sent_count, 0) = 0");
    $pvUnsigned = dash_count($pdo, "SELECT COUNT(*) FROM documents WHERE document_type = 'proces_verbal' AND status = 'issued' AND (payload_json IS NULL OR payload_json NOT LIKE '%client_signature_path%')");
    $pvUnsentList = dash_rows($pdo, "
        SELECT id, document_number, document_date, client_name_snapshot, location_name_snapshot
        FROM documents
        WHERE document_type = 'proces_verbal'
          AND status = 'issued'
          AND COALESCE(email_sent_count, 0) = 0
        ORDER BY document_date DESC, id DESC
        LIMIT 5
    ");
    $docsContractsMonth = dash_count($pdo, "SELECT COUNT(*) FROM documents WHERE document_type = 'contract' AND document_date BETWEEN ? AND ?", [$monthStart, $monthEnd]);
}
if ($hasContracts) {
    $contractsExpireSoon = dash_count($pdo, "SELECT COUNT(*) FROM contracts WHERE status IN ('activ','sezon') AND end_date IS NOT NULL AND end_date BETWEEN ? AND ?", [$today, $soonDate]);
    $contractsSeason = dash_count($pdo, "SELECT COUNT(*) FROM contracts WHERE status = 'sezon'");
}

$emailToday = 0;
$smsToday = 0;
$failedCommsToday = 0;
if ($hasNotifications) {
    $emailToday += dash_count($pdo, "SELECT COUNT(*) FROM notification_logs WHERE channel = 'email' AND status = 'sent' AND DATE(created_at) = ?", [$today]);
    $smsToday += dash_count($pdo, "SELECT COUNT(*) FROM notification_logs WHERE channel = 'sms' AND status = 'sent' AND DATE(created_at) = ?", [$today]);
    $failedCommsToday += dash_count($pdo, "SELECT COUNT(*) FROM notification_logs WHERE status = 'failed' AND DATE(created_at) = ?", [$today]);
}
if ($hasDocumentEmailLogs) {
    $emailToday += dash_count($pdo, "SELECT COUNT(*) FROM document_email_logs WHERE status = 'sent' AND DATE(sent_at) = ?", [$today]);
    $failedCommsToday += dash_count($pdo, "SELECT COUNT(*) FROM document_email_logs WHERE status = 'failed' AND DATE(sent_at) = ?", [$today]);
}

$feedbackMonth = 0;
$feedbackAvgMonth = 0.0;
$feedbackLowMonth = 0;
if ($hasReviews) {
    $reviewRow = dash_one($pdo, "
        SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS avg_rating
        FROM review_requests
        WHERE rating IS NOT NULL
          AND COALESCE(rated_at, completed_at, updated_at, created_at) BETWEEN ? AND ?
    ", [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
    $feedbackMonth = (int)($reviewRow['total'] ?? 0);
    $feedbackAvgMonth = (float)($reviewRow['avg_rating'] ?? 0);
    $feedbackLowMonth = dash_count($pdo, "
        SELECT COUNT(*) FROM review_requests
        WHERE rating IS NOT NULL AND rating <= 3
          AND COALESCE(rated_at, completed_at, updated_at, created_at) BETWEEN ? AND ?
    ", [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
}

$clientsMissingEmail = 0;
$clientsSmsStopped = 0;
if ($hasClients) {
    $clientsMissingEmail = dash_count($pdo, "SELECT COUNT(*) FROM clients WHERE active = 1 AND (email IS NULL OR TRIM(email) = '')");
    if (dash_column_exists($pdo, 'clients', 'sms_enabled')) {
        $clientsSmsStopped = dash_count($pdo, "SELECT COUNT(*) FROM clients WHERE active = 1 AND sms_enabled = 0");
    }
}

$oblioBalance = 0.0;
$oblioOpenDocs = 0;
if ($hasBillingDocs) {
    $oblioRow = dash_one($pdo, "SELECT COUNT(*) AS total, COALESCE(SUM(balance), 0) AS balance_total FROM billing_oblio_documents WHERE canceled = 0 AND balance > 0");
    $oblioOpenDocs = (int)($oblioRow['total'] ?? 0);
    $oblioBalance = (float)($oblioRow['balance_total'] ?? 0);
}

$hour = (int)date('H');
$greeting = $hour < 11 ? 'Buna dimineata' : ($hour < 18 ? 'Buna ziua' : 'Buna seara');
$weekCompletion = $appointmentsWeek > 0 ? round(($completedWeek / $appointmentsWeek) * 100) : 0;

$missionScore = 100;
$missionScore -= min(25, $tasksOverdueCount * 3);
$missionScore -= min(20, $finishedNoPv * 2);
$missionScore -= min(18, $pvUnsent * 2);
$missionScore -= min(15, $ibNoValue * 3);
$missionScore -= min(12, $failedCommsToday * 4);
$missionScore = max(0, $missionScore);
$missionTone = $missionScore >= 80 ? 'success' : ($missionScore >= 55 ? 'warning' : 'danger');

$alerts = [];
$addAlert = function(string $tone, string $title, string $detail, string $href, int $value = 0, string $icon = 'warning') use (&$alerts) {
    $alerts[] = compact('tone', 'title', 'detail', 'href', 'value', 'icon');
};
if ($tasksOverdueCount > 0) $addAlert('danger', 'Sarcini intarziate', $tasksOverdueCount . ' sarcini trebuie confirmate si programate.', 'tasks.php', $tasksOverdueCount, 'task');
if ($finishedNoPv > 0) $addAlert('danger', 'Lucrari finalizate fara PV', $finishedNoPv . ' lucrari nu au proces verbal emis.', 'procese_verbale.php', $finishedNoPv, 'pv');
if ($pvUnsent > 0) $addAlert('warning', 'PV-uri netrimise pe email', $pvUnsent . ' procese verbale emise asteapta trimitere.', 'procese_verbale.php', $pvUnsent, 'mail');
if ($ibNoValue > 0) $addAlert('warning', 'Lucrari fara valoare', $ibNoValue . ' interventii finalizate nu au valoare setata.', 'interventii_facturare.php?billing_status=de_facturat', $ibNoValue, 'money');
if ($failedCommsToday > 0) $addAlert('danger', 'Comunicari esuate azi', $failedCommsToday . ' SMS/email au status failed.', 'sms_activity.php', $failedCommsToday, 'warning');
if ($contractsExpireSoon > 0) $addAlert('warning', 'Contracte aproape de expirare', $contractsExpireSoon . ' contracte expira in urmatoarele 30 de zile.', 'contracts.php', $contractsExpireSoon, 'contract');
if ($clientsMissingEmail > 0) $addAlert('info', 'Clienti fara email', $clientsMissingEmail . ' clienti activi nu au email salvat.', 'clients.php', $clientsMissingEmail, 'client');
if ($teamsFree > 0 && $appointmentsToday > 0) $addAlert('info', 'Capacitate disponibila', $teamsFree . ' echipe sunt libere sau subincarcate azi.', 'calendar.php?date=' . urlencode($today) . '&view=day', $teamsFree, 'team');
if (!$alerts) $addAlert('success', 'Totul este sub control', 'Nu exista blocaje operationale importante in acest moment.', 'calendar.php?date=' . urlencode($today) . '&view=day', 0, 'check');

$pipeline = [
    ['label' => 'De programat', 'value' => $backlogTotal, 'href' => 'tasks.php', 'tone' => $tasksOverdueCount > 0 ? 'danger' : 'warning'],
    ['label' => 'Programate sapt.', 'value' => $appointmentsWeek, 'href' => 'calendar.php?date=' . urlencode($today) . '&view=week', 'tone' => 'info'],
    ['label' => 'In lucru azi', 'value' => $inProgressToday, 'href' => 'calendar.php?date=' . urlencode($today) . '&view=day', 'tone' => 'info'],
    ['label' => 'Finalizate fara PV', 'value' => $finishedNoPv, 'href' => 'procese_verbale.php', 'tone' => $finishedNoPv > 0 ? 'danger' : 'success'],
    ['label' => 'PV netrimise', 'value' => $pvUnsent, 'href' => 'procese_verbale.php', 'tone' => $pvUnsent > 0 ? 'warning' : 'success'],
    ['label' => 'De facturat', 'value' => $ibDue, 'href' => 'interventii_facturare.php?billing_status=de_facturat', 'tone' => $ibDue > 0 ? 'warning' : 'success'],
    ['label' => 'Facturate luna', 'value' => $ibBilledMonth, 'href' => 'interventii_facturare.php?billing_status=facturata', 'tone' => 'success'],
];

$missionItems = [
    ['label' => 'Programari azi', 'value' => $appointmentsToday, 'meta' => $completedToday . ' finalizate / ' . $pendingToday . ' ramase', 'href' => 'calendar.php?date=' . urlencode($today) . '&view=day', 'icon' => 'calendar', 'tone' => 'info'],
    ['label' => 'De programat', 'value' => $backlogTotal, 'meta' => $tasksOverdueCount . ' intarziate / ' . $tasksTodayCount . ' azi', 'href' => 'tasks.php', 'icon' => 'task', 'tone' => $tasksOverdueCount > 0 ? 'danger' : 'warning'],
    ['label' => 'PV-uri de rezolvat', 'value' => $pvUnsent + $finishedNoPv, 'meta' => $finishedNoPv . ' fara PV / ' . $pvUnsent . ' netrimise', 'href' => 'procese_verbale.php', 'icon' => 'pv', 'tone' => ($pvUnsent + $finishedNoPv) > 0 ? 'warning' : 'success'],
    ['label' => 'Bani blocati', 'value' => dash_money($ibDueAmount), 'meta' => $ibDue . ' interventii de facturat', 'href' => 'interventii_facturare.php?billing_status=de_facturat', 'icon' => 'money', 'tone' => $ibDue > 0 ? 'warning' : 'success'],
];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Panou operational</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<?php app_theme_css(); ?>
<style>
:root {
    --dash-bg: #DFE2E8;
    --dash-card: #ffffff;
    --dash-card-2: #F8FAFC;
    --dash-line: rgba(0, 32, 80, .14);
    --dash-text: #002050;
    --dash-muted: #526B82;
    --dash-blue: #1160B7;
    --dash-indigo: #1160B7;
    --dash-violet: #B1D6F0;
    --dash-green: #1160B7;
    --dash-amber: #D24726;
    --dash-red: #D24726;
    --dash-shadow: 0 16px 45px -28px rgba(0,32,80,.45), 0 1px 2px rgba(0,32,80,.04);
    --dash-radius: 22px;
}
.content { max-width: 1520px; }
.dash-wrap { display: flex; flex-direction: column; gap: 16px; }
.dash-ico { width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; }
.dash-ico svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.command-hero {
    position: relative; overflow: hidden; color: #fff; border-radius: 28px; padding: 22px;
    background:
        radial-gradient(circle at 88% 12%, rgba(177,214,240,.42), transparent 24%),
        radial-gradient(circle at 18% 2%, rgba(17,96,183,.45), transparent 26%),
        linear-gradient(135deg, #002050 0%, #063E78 52%, #1160B7 100%);
    box-shadow: 0 22px 55px -35px rgba(15,23,42,.9);
}
.command-hero::after { content: ""; position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,.055) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px); background-size: 36px 36px; mask-image: linear-gradient(90deg, rgba(0,0,0,.7), transparent 75%); pointer-events: none; }
.hero-content { position: relative; z-index: 1; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 18px; align-items: start; }
.eyebrow { display: inline-flex; gap: 7px; align-items: center; color: rgba(219,234,254,.95); font-size: 11px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
.hero-title { margin: 8px 0 6px; font-size: clamp(26px, 3vw, 42px); line-height: 1.02; letter-spacing: -.055em; font-weight: 900; }
.hero-sub { margin: 0; max-width: 820px; color: rgba(226,232,240,.86); font-size: 14px; font-weight: 650; line-height: 1.5; }
.hero-score { min-width: 170px; padding: 13px; border-radius: 20px; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.16); backdrop-filter: blur(10px); text-align: right; }
.score-label { color: rgba(226,232,240,.78); font-size: 11px; font-weight: 850; letter-spacing: .08em; text-transform: uppercase; }
.score-value { margin-top: 5px; font-family: var(--mono); font-size: 32px; font-weight: 900; letter-spacing: -.04em; }
.score-pill { display: inline-flex; margin-top: 6px; padding: 4px 9px; border-radius: 999px; font-size: 11px; font-weight: 850; background: rgba(255,255,255,.13); color: #fff; }
.hero-actions { position: relative; z-index: 1; display: flex; flex-wrap: wrap; gap: 9px; margin-top: 18px; }
.action-btn { min-height: 38px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; border-radius: 14px; border: 1px solid rgba(255,255,255,.18); background: rgba(255,255,255,.1); color: #fff; text-decoration: none; font-size: 13px; font-weight: 850; backdrop-filter: blur(10px); transition: transform .15s ease, background .15s ease; }
.action-btn:hover { transform: translateY(-1px); background: rgba(255,255,255,.17); color: #fff; }
.action-btn.primary { color: #002050; background: #fff; border-color: #fff; box-shadow: 0 16px 30px -22px #fff; }
.top-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.kpi { position: relative; overflow: hidden; min-height: 118px; padding: 16px; border-radius: var(--dash-radius); background: var(--dash-card); border: 1px solid var(--dash-line); box-shadow: var(--dash-shadow); color: inherit; text-decoration: none; }
.kpi::after { content: ""; position: absolute; right: -24px; top: -24px; width: 96px; height: 96px; border-radius: 999px; background: rgba(37,99,235,.09); }
.kpi-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; position: relative; z-index: 1; }
.kpi-icon { width: 38px; height: 38px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; background: #EEF8FF; color: var(--dash-blue); }
.kpi-value { margin-top: 13px; font-family: var(--mono); font-size: 30px; font-weight: 900; line-height: 1; color: var(--dash-text); letter-spacing: -.05em; position: relative; z-index: 1; }
.kpi-label { display: block; margin-top: 7px; color: var(--dash-muted); font-size: 12px; font-weight: 850; letter-spacing: .04em; text-transform: uppercase; position: relative; z-index: 1; }
.kpi-meta { display: block; margin-top: 5px; color: var(--dash-muted); font-size: 12px; font-weight: 700; position: relative; z-index: 1; }
.tone-danger .kpi-icon, .tone-danger .mini-icon { background: #FFF4F1; color: var(--dash-red); }
.tone-danger .kpi-value { color: var(--dash-red); }
.tone-warning .kpi-icon, .tone-warning .mini-icon { background: #FFF8F5; color: var(--dash-amber); }
.tone-warning .kpi-value { color: var(--dash-amber); }
.tone-success .kpi-icon, .tone-success .mini-icon { background: #EEF8FF; color: var(--dash-green); }
.tone-success .kpi-value { color: var(--dash-green); }
.tone-info .kpi-icon, .tone-info .mini-icon { background: #EEF8FF; color: var(--dash-blue); }
.tone-muted .kpi-icon, .tone-muted .mini-icon { background: #F8FAFC; color: var(--dash-muted); }
.cockpit-grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(360px, .65fr); gap: 16px; align-items: stretch; }
.dash-card { background: var(--dash-card); border: 1px solid var(--dash-line); border-radius: var(--dash-radius); box-shadow: var(--dash-shadow); overflow: hidden; }
.card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 16px 18px; border-bottom: 1px solid rgba(226,232,240,.9); }
.card-title { margin: 0; display: flex; align-items: center; gap: 9px; color: var(--dash-text); font-size: 15px; font-weight: 900; letter-spacing: -.02em; }
.card-sub { margin-top: 3px; color: var(--dash-muted); font-size: 12px; font-weight: 650; }
.card-link { display: inline-flex; align-items: center; gap: 6px; padding: 7px 10px; border-radius: 11px; background: #f8fafc; border: 1px solid var(--dash-line); color: var(--dash-text); font-size: 12px; font-weight: 850; text-decoration: none; white-space: nowrap; }
.card-body { padding: 16px 18px; }
.mission-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.mission-item { display: grid; grid-template-columns: 42px minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 14px; border-radius: 18px; background: linear-gradient(180deg, #fff, #f8fafc); border: 1px solid rgba(226,232,240,.9); color: inherit; text-decoration: none; }
.mini-icon { width: 42px; height: 42px; border-radius: 15px; display: inline-flex; align-items: center; justify-content: center; }
.mission-label { color: var(--dash-muted); font-size: 11px; font-weight: 900; letter-spacing: .06em; text-transform: uppercase; }
.mission-value { margin-top: 2px; font-family: var(--mono); font-size: 24px; line-height: 1; font-weight: 900; color: var(--dash-text); }
.mission-meta { margin-top: 5px; color: var(--dash-muted); font-size: 12px; font-weight: 700; }
.go-arrow { color: var(--dash-muted); }
.alert-list { display: flex; flex-direction: column; gap: 9px; max-height: 335px; overflow: auto; padding-right: 3px; }
.alert-row { display: grid; grid-template-columns: 36px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 11px; border-radius: 16px; border: 1px solid rgba(226,232,240,.92); background: #fff; text-decoration: none; color: inherit; }
.alert-row:hover { border-color: rgba(37,99,235,.35); }
.alert-title { font-size: 13px; font-weight: 900; color: var(--dash-text); }
.alert-detail { margin-top: 2px; font-size: 12px; font-weight: 650; color: var(--dash-muted); line-height: 1.35; }
.alert-badge { min-width: 30px; height: 28px; padding: 0 8px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-family: var(--mono); font-weight: 900; font-size: 13px; background: #F8FAFC; color: var(--dash-text); }
.alert-row.tone-danger .alert-badge { background: #fee2e2; color: var(--dash-red); }
.alert-row.tone-warning .alert-badge { background: #fef3c7; color: var(--dash-amber); }
.alert-row.tone-success .alert-badge { background: #d1fae5; color: var(--dash-green); }
.alert-row.tone-info .alert-badge { background: #dbeafe; color: var(--dash-blue); }
.pipeline-card .card-body { padding-top: 18px; }
.pipeline-track { display: grid; grid-template-columns: repeat(7, minmax(132px, 1fr)); gap: 10px; }
.stage { position: relative; padding: 14px 12px; min-height: 112px; border-radius: 18px; border: 1px solid rgba(226,232,240,.9); background: linear-gradient(180deg,#fff,#f8fafc); text-decoration: none; color: inherit; overflow: hidden; }
.stage::after { content: ""; position: absolute; inset: auto 12px 10px 12px; height: 4px; border-radius: 999px; background: #e2e8f0; }
.stage.tone-danger::after { background: var(--dash-red); }
.stage.tone-warning::after { background: var(--dash-amber); }
.stage.tone-success::after { background: var(--dash-green); }
.stage.tone-info::after { background: var(--dash-blue); }
.stage-label { min-height: 30px; color: var(--dash-muted); font-size: 11px; font-weight: 900; letter-spacing: .04em; text-transform: uppercase; line-height: 1.25; }
.stage-value { margin-top: 10px; font-family: var(--mono); font-size: 30px; line-height: 1; font-weight: 900; color: var(--dash-text); }
.stage-arrow { position: absolute; top: 15px; right: 10px; color: var(--dash-muted); opacity: .7; }
.two-col { display: grid; grid-template-columns: minmax(0, 1fr) minmax(340px, .75fr); gap: 16px; }
.team-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 11px; }
.team-tile { padding: 13px; border-radius: 18px; border: 1px solid rgba(226,232,240,.9); background: #fff; }
.team-top { display: flex; align-items: center; gap: 10px; }
.avatar { width: 36px; height: 36px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; font-weight: 900; }
.team-name { font-size: 13px; font-weight: 900; color: var(--dash-text); min-width: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.team-meta { color: var(--dash-muted); font-size: 12px; font-weight: 700; margin-top: 2px; }
.load-bar { height: 7px; margin-top: 12px; border-radius: 999px; overflow: hidden; background: #e2e8f0; }
.load-bar span { display: block; height: 100%; border-radius: 999px; background: var(--dash-blue); }
.team-foot { display: flex; justify-content: space-between; gap: 8px; align-items: center; margin-top: 10px; font-size: 12px; color: var(--dash-muted); font-weight: 750; }
.badge { display: inline-flex; align-items: center; justify-content: center; min-height: 24px; padding: 4px 8px; border-radius: 999px; background: #F8FAFC; color: var(--dash-muted); font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: .03em; }
.badge.success { background: #dcfce7; color: var(--dash-green); }
.badge.warning { background: #fef3c7; color: var(--dash-amber); }
.badge.info { background: #dbeafe; color: var(--dash-blue); }
.money-stack { display: grid; gap: 10px; }
.money-main { padding: 16px; border-radius: 19px; background: linear-gradient(135deg, #002050, #002050); color: #fff; }
.money-label { color: rgba(226,232,240,.82); font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; }
.money-value { margin-top: 6px; font-family: var(--mono); font-size: 34px; font-weight: 900; line-height: 1; letter-spacing: -.05em; }
.money-meta { margin-top: 8px; color: rgba(226,232,240,.82); font-size: 12px; font-weight: 700; }
.money-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px; border-radius: 16px; background: #f8fafc; border: 1px solid rgba(226,232,240,.9); }
.money-row strong { font-family: var(--mono); color: var(--dash-text); }
.bottom-grid { display: grid; grid-template-columns: 1fr 1fr 1.15fr; gap: 16px; }
.compact-list { display: flex; flex-direction: column; gap: 8px; }
.list-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid rgba(226,232,240,.82); }
.list-row:last-child { border-bottom: 0; }
.list-main { min-width: 0; }
.list-title { font-size: 13px; font-weight: 900; color: var(--dash-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.list-meta { margin-top: 2px; font-size: 12px; color: var(--dash-muted); font-weight: 650; }
.list-value { font-family: var(--mono); font-weight: 900; color: var(--dash-text); white-space: nowrap; }
.agenda-list { display: flex; flex-direction: column; gap: 8px; max-height: 358px; overflow: auto; padding-right: 3px; }
.agenda-row { display: grid; grid-template-columns: 54px minmax(0,1fr) auto; gap: 10px; align-items: center; padding: 10px; border-radius: 16px; background: #fff; border: 1px solid rgba(226,232,240,.92); }
.ag-time { font-family: var(--mono); font-size: 13px; font-weight: 900; color: var(--dash-text); }
.ag-client { font-size: 13px; font-weight: 900; color: var(--dash-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ag-meta { margin-top: 3px; display: flex; align-items: center; gap: 6px; color: var(--dash-muted); font-size: 12px; font-weight: 700; }
.team-dot { width: 8px; height: 8px; border-radius: 999px; display: inline-block; }
.empty { padding: 20px; border-radius: 18px; background: #f8fafc; color: var(--dash-muted); text-align: center; font-weight: 750; }
@media (max-width: 1240px) { .top-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .cockpit-grid, .two-col, .bottom-grid { grid-template-columns: 1fr; } .pipeline-track { overflow-x: auto; } }
@media (max-width: 720px) { .command-hero { padding: 17px; border-radius: 22px; } .hero-content { grid-template-columns: 1fr; } .hero-score { width: 100%; text-align: left; } .top-kpis, .mission-grid { grid-template-columns: 1fr; } .card-head { flex-direction: column; } .action-btn { width: 100%; } .agenda-row { grid-template-columns: 46px minmax(0,1fr); } .agenda-row .badge { grid-column: 1 / -1; justify-self: start; } }
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('dashboard', $isAdmin); ?>
    <main class="main">
        <div class="content">
            <div class="dash-wrap">
                <section class="command-hero">
                    <div class="hero-content">
                        <div>
                            <div class="eyebrow"><?= dash_icon('star') ?> Panou operational / Command Center</div>
                            <h1 class="hero-title"><?= dash_h($greeting) ?>, Marian</h1>
                            <p class="hero-sub">Dashboard construit pentru ziua reala de lucru: ce trebuie programat, ce blocheaza documentele, unde sunt banii blocati si ce echipe sunt in teren.</p>
                        </div>
                        <div class="hero-score">
                            <div class="score-label">Scor operational</div>
                            <div class="score-value"><?= (int)$missionScore ?>%</div>
                            <span class="score-pill"><?= $missionTone === 'success' ? 'Stabil' : ($missionTone === 'warning' ? 'Atentie' : 'Critic') ?> / <?= date('d.m.Y') ?></span>
                        </div>
                    </div>
                    <div class="hero-actions">
                        <a class="action-btn primary" href="calendar.php?date=<?= dash_h($today) ?>&view=day&open_create=1"><?= dash_icon('plus') ?> Programare noua</a>
                        <a class="action-btn" href="clients.php?open_create=1"><?= dash_icon('client') ?> Client nou</a>
                        <a class="action-btn" href="contracts.php?new=1"><?= dash_icon('contract') ?> Contract nou</a>
                        <a class="action-btn" href="procese_verbale.php?new=1"><?= dash_icon('pv') ?> Emite PV</a>
                        <a class="action-btn" href="billing.php"><?= dash_icon('invoice') ?> Factura / Proforma</a>
                    </div>
                </section>

                <div class="top-kpis">
                    <a class="kpi tone-info" href="calendar.php?date=<?= dash_h($today) ?>&view=day">
                        <div class="kpi-head"><span class="kpi-icon"><?= dash_icon('calendar') ?></span><span class="badge info">azi</span></div>
                        <div class="kpi-value"><?= (int)$appointmentsToday ?></div>
                        <span class="kpi-label">Programari azi</span>
                        <span class="kpi-meta"><?= (int)$completedToday ?> finalizate / <?= (int)$pendingToday ?> ramase</span>
                    </a>
                    <a class="kpi tone-<?= $tasksOverdueCount > 0 ? 'danger' : 'warning' ?>" href="tasks.php">
                        <div class="kpi-head"><span class="kpi-icon"><?= dash_icon('task') ?></span><span class="badge <?= $tasksOverdueCount > 0 ? 'warning' : 'info' ?>">backlog</span></div>
                        <div class="kpi-value"><?= (int)$backlogTotal ?></div>
                        <span class="kpi-label">De programat</span>
                        <span class="kpi-meta"><?= (int)$tasksOverdueCount ?> intarziate / <?= (int)$tasksTodayCount ?> azi</span>
                    </a>
                    <a class="kpi tone-warning" href="interventii_facturare.php?billing_status=de_facturat">
                        <div class="kpi-head"><span class="kpi-icon"><?= dash_icon('money') ?></span><span class="badge warning">cash</span></div>
                        <div class="kpi-value"><?= dash_h(dash_money($ibDueAmount)) ?></div>
                        <span class="kpi-label">Lei blocati</span>
                        <span class="kpi-meta"><?= (int)$ibDue ?> interventii de facturat</span>
                    </a>
                    <a class="kpi tone-<?= ($pvUnsent + $finishedNoPv) > 0 ? 'warning' : 'success' ?>" href="procese_verbale.php">
                        <div class="kpi-head"><span class="kpi-icon"><?= dash_icon('pv') ?></span><span class="badge <?= ($pvUnsent + $finishedNoPv) > 0 ? 'warning' : 'success' ?>">PV</span></div>
                        <div class="kpi-value"><?= (int)($pvUnsent + $finishedNoPv) ?></div>
                        <span class="kpi-label">PV-uri de rezolvat</span>
                        <span class="kpi-meta"><?= (int)$finishedNoPv ?> fara PV / <?= (int)$pvUnsent ?> netrimise</span>
                    </a>
                </div>

                <div class="cockpit-grid">
                    <section class="dash-card">
                        <div class="card-head">
                            <div>
                                <h2 class="card-title"><?= dash_icon('check') ?> Misiunea zilei</h2>
                                <div class="card-sub">Cele mai importante actiuni pentru birou si management.</div>
                            </div>
                            <a class="card-link" href="calendar.php?date=<?= dash_h($today) ?>&view=day">Vezi ziua <?= dash_icon('arrow') ?></a>
                        </div>
                        <div class="card-body">
                            <div class="mission-grid">
                                <?php foreach ($missionItems as $item): ?>
                                    <a class="mission-item tone-<?= dash_h($item['tone']) ?>" href="<?= dash_h($item['href']) ?>">
                                        <span class="mini-icon"><?= dash_icon($item['icon']) ?></span>
                                        <span>
                                            <span class="mission-label"><?= dash_h($item['label']) ?></span>
                                            <span class="mission-value"><?= dash_h($item['value']) ?></span>
                                            <span class="mission-meta"><?= dash_h($item['meta']) ?></span>
                                        </span>
                                        <span class="go-arrow"><?= dash_icon('arrow') ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                    <section class="dash-card">
                        <div class="card-head">
                            <div>
                                <h2 class="card-title"><?= dash_icon('warning') ?> Inbox operational</h2>
                                <div class="card-sub">Blocaje si lucruri care trebuie rezolvate.</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="alert-list">
                                <?php foreach (array_slice($alerts, 0, 8) as $alert): ?>
                                    <a class="alert-row tone-<?= dash_h($alert['tone']) ?>" href="<?= dash_h($alert['href']) ?>">
                                        <span class="mini-icon"><?= dash_icon($alert['icon']) ?></span>
                                        <span>
                                            <span class="alert-title"><?= dash_h($alert['title']) ?></span>
                                            <span class="alert-detail"><?= dash_h($alert['detail']) ?></span>
                                        </span>
                                        <span class="alert-badge"><?= (int)$alert['value'] ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                </div>

                <section class="dash-card pipeline-card">
                    <div class="card-head">
                        <div>
                            <h2 class="card-title"><?= dash_icon('trend') ?> Pipeline operational</h2>
                            <div class="card-sub">Fluxul complet: sarcina -> lucrare -> PV -> email -> facturare.</div>
                        </div>
                        <a class="card-link" href="reports.php">Rapoarte <?= dash_icon('arrow') ?></a>
                    </div>
                    <div class="card-body">
                        <div class="pipeline-track">
                            <?php foreach ($pipeline as $stage): ?>
                                <a class="stage tone-<?= dash_h($stage['tone']) ?>" href="<?= dash_h($stage['href']) ?>">
                                    <span class="stage-label"><?= dash_h($stage['label']) ?></span>
                                    <span class="stage-value"><?= (int)$stage['value'] ?></span>
                                    <span class="stage-arrow"><?= dash_icon('arrow') ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <div class="two-col">
                    <section class="dash-card">
                        <div class="card-head">
                            <div>
                                <h2 class="card-title"><?= dash_icon('team') ?> Echipe in teren</h2>
                                <div class="card-sub"><?= (int)$teamsBusy ?> echipe cu program / <?= (int)$teamsFree ?> libere sau neincarcate.</div>
                            </div>
                            <a class="card-link" href="calendar.php?date=<?= dash_h($today) ?>&view=day">Calendar <?= dash_icon('arrow') ?></a>
                        </div>
                        <div class="card-body">
                            <?php if (!$teamStats): ?>
                                <div class="empty">Nu exista echipe active sau programari pentru calcul.</div>
                            <?php else: ?>
                                <div class="team-grid">
                                    <?php foreach ($teamStats as $tm):
                                        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($tm['color'] ?? '')) ? $tm['color'] : '#2563eb';
                                        $hours = (float)($tm['hours_booked'] ?? 0);
                                        $percent = min(100, max(0, ($hours / $teamCapacityHours) * 100));
                                        $jobsTotal = (int)($tm['jobs_total'] ?? 0);
                                        $jobsDone = (int)($tm['jobs_done'] ?? 0);
                                        $badgeClass = $hours <= .01 ? 'warning' : ($percent >= 85 ? 'success' : 'info');
                                        $badgeText = $hours <= .01 ? 'libera' : ($percent >= 85 ? 'plina' : 'activa');
                                    ?>
                                        <a class="team-tile" href="calendar.php?date=<?= dash_h($today) ?>&view=day&team=<?= (int)$tm['id'] ?>">
                                            <div class="team-top">
                                                <span class="avatar" style="background: <?= dash_h($color) ?>;"><?= dash_h(dash_initials((string)$tm['name'])) ?></span>
                                                <span style="min-width:0; flex:1;">
                                                    <span class="team-name"><?= dash_h($tm['name']) ?></span>
                                                    <span class="team-meta"><?= dash_h(number_format($hours, 1, ',', '')) ?>h / <?= (int)$jobsTotal ?> lucrari</span>
                                                </span>
                                                <span class="badge <?= dash_h($badgeClass) ?>"><?= dash_h($badgeText) ?></span>
                                            </div>
                                            <div class="load-bar"><span style="width: <?= dash_h(number_format($percent, 1, '.', '')) ?>%;"></span></div>
                                            <div class="team-foot"><span><?= (int)$jobsDone ?> finalizate</span><span><?= $tm['next_start'] ? 'urm. ' . dash_h(dash_time($tm['next_start'])) : 'fara urmatoare' ?></span></div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="dash-card">
                        <div class="card-head">
                            <div>
                                <h2 class="card-title"><?= dash_icon('money') ?> Bani si facturare</h2>
                                <div class="card-sub">Ce este finalizat, dar inca nu produce bani.</div>
                            </div>
                            <a class="card-link" href="interventii_facturare.php?billing_status=de_facturat">Verifica <?= dash_icon('arrow') ?></a>
                        </div>
                        <div class="card-body">
                            <div class="money-stack">
                                <div class="money-main">
                                    <div class="money-label">Bani blocati in lucrari finalizate</div>
                                    <div class="money-value"><?= dash_h(dash_money($ibDueAmount)) ?> lei</div>
                                    <div class="money-meta"><?= (int)$ibDue ?> interventii de facturat / <?= (int)$ibNoValue ?> fara valoare setata</div>
                                </div>
                                <div class="money-row"><span>Facturate luna aceasta</span><strong><?= (int)$ibBilledMonth ?> / <?= dash_h(dash_money($ibBilledMonthAmount)) ?> lei</strong></div>
                                <div class="money-row"><span>Nu se factureaza luna aceasta</span><strong><?= (int)$ibNoBillMonth ?> / <?= dash_h(dash_money($ibNoBillMonthAmount)) ?> lei</strong></div>
                                <div class="money-row"><span>Sold Oblio deschis</span><strong><?= (int)$oblioOpenDocs ?> / <?= dash_h(dash_money($oblioBalance)) ?> lei</strong></div>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="bottom-grid">
                    <section class="dash-card">
                        <div class="card-head">
                            <div>
                                <h2 class="card-title"><?= dash_icon('doc') ?> Documente</h2>
                                <div class="card-sub">PV-uri, contracte si comunicare documente.</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="compact-list">
                                <a class="list-row" href="procese_verbale.php"><span class="list-main"><span class="list-title">PV emise azi</span><span class="list-meta">Documente finalizate astazi</span></span><span class="list-value"><?= (int)$pvIssuedToday ?></span></a>
                                <a class="list-row" href="procese_verbale.php"><span class="list-main"><span class="list-title">PV draft azi</span><span class="list-meta">Necesita finalizare</span></span><span class="list-value"><?= (int)$pvDraftToday ?></span></a>
                                <a class="list-row" href="procese_verbale.php"><span class="list-main"><span class="list-title">PV nesemnate</span><span class="list-meta">Nu au semnatura client</span></span><span class="list-value"><?= (int)$pvUnsigned ?></span></a>
                                <a class="list-row" href="contracts.php"><span class="list-main"><span class="list-title">Contracte luna aceasta</span><span class="list-meta">Emise in luna curenta</span></span><span class="list-value"><?= (int)$docsContractsMonth ?></span></a>
                                <a class="list-row" href="contracts.php"><span class="list-main"><span class="list-title">Contracte expira curand</span><span class="list-meta">Urmatoarele 30 de zile</span></span><span class="list-value"><?= (int)$contractsExpireSoon ?></span></a>
                            </div>
                        </div>
                    </section>

                    <section class="dash-card">
                        <div class="card-head">
                            <div>
                                <h2 class="card-title"><?= dash_icon('risk') ?> Clienti si risc</h2>
                                <div class="card-sub">Date lipsa si semnale de atentie.</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="compact-list">
                                <a class="list-row" href="clients.php"><span class="list-main"><span class="list-title">Clienti fara email</span><span class="list-meta">Blocheaza trimiterea documentelor</span></span><span class="list-value"><?= (int)$clientsMissingEmail ?></span></a>
                                <a class="list-row" href="clients.php"><span class="list-main"><span class="list-title">Clienti cu SMS oprit</span><span class="list-meta">Nu primesc notificari automate</span></span><span class="list-value"><?= (int)$clientsSmsStopped ?></span></a>
                                <a class="list-row" href="tasks.php"><span class="list-main"><span class="list-title">Sarcini sarite luna aceasta</span><span class="list-meta">Clientul nu a dorit interventia</span></span><span class="list-value"><?= (int)$tasksSkippedMonth ?></span></a>
                                <a class="list-row" href="review_feedback.php"><span class="list-main"><span class="list-title">Feedback slab luna aceasta</span><span class="list-meta">Ratinguri de 3 stele sau sub</span></span><span class="list-value"><?= (int)$feedbackLowMonth ?></span></a>
                                <a class="list-row" href="review_feedback.php"><span class="list-main"><span class="list-title">Media feedback</span><span class="list-meta"><?= (int)$feedbackMonth ?> raspunsuri luna aceasta</span></span><span class="list-value"><?= $feedbackMonth > 0 ? dash_h(number_format($feedbackAvgMonth, 1, ',', '')) . '/5' : '-' ?></span></a>
                            </div>
                        </div>
                    </section>

                    <section class="dash-card">
                        <div class="card-head">
                            <div>
                                <h2 class="card-title"><?= dash_icon('clock') ?> Agenda azi</h2>
                                <div class="card-sub">Primele lucrari din zi, in ordine cronologica.</div>
                            </div>
                            <a class="card-link" href="calendar.php?date=<?= dash_h($today) ?>&view=day">Zi completa <?= dash_icon('arrow') ?></a>
                        </div>
                        <div class="card-body">
                            <?php if (!$todayAppointments): ?>
                                <div class="empty">Nu exista programari astazi.</div>
                            <?php else: ?>
                                <div class="agenda-list">
                                    <?php foreach ($todayAppointments as $a):
                                        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($a['team_color'] ?? '')) ? $a['team_color'] : '#2563eb';
                                        $tone = dash_status_tone((string)($a['status'] ?? ''));
                                    ?>
                                        <a class="agenda-row" href="calendar.php?date=<?= dash_h($today) ?>&view=day">
                                            <span class="ag-time"><?= dash_h(dash_time($a['start_time'] ?? null)) ?></span>
                                            <span style="min-width:0;">
                                                <span class="ag-client"><?= dash_h($a['client_name'] ?: 'Client') ?></span>
                                                <span class="ag-meta"><span class="team-dot" style="background: <?= dash_h($color) ?>"></span><?= dash_h($a['team_name'] ?: 'Fara echipa') ?><?= !empty($a['service_type']) ? ' / ' . dash_h($a['service_type']) : '' ?></span>
                                            </span>
                                            <span class="badge <?= dash_h($tone === 'success' ? 'success' : ($tone === 'warning' ? 'warning' : 'info')) ?>"><?= dash_h(dash_status_label((string)($a['status'] ?? ''))) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
