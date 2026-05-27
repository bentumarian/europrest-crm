<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once __DIR__ . '/lib/notification_lib.php';

$isAdmin = is_admin();
if (!$isAdmin) { header('Location: calendar.php'); exit; }

function rem_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/*
|--------------------------------------------------------------------------
| Schema auto-bootstrap (idempotent)
|--------------------------------------------------------------------------
*/
$pdo->exec("
    CREATE TABLE IF NOT EXISTS reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        category VARCHAR(30) NOT NULL DEFAULT 'other',
        remind_date DATE NOT NULL,
        remind_time TIME NULL,
        responsible_user_id INT NULL,
        created_by INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        recurrence_type VARCHAR(20) NULL,
        recurrence_interval INT NULL DEFAULT 1,
        recurrence_end_date DATE NULL,
        recurrence_parent_id INT NULL,
        email_notified_at DATETIME NULL,
        completed_at DATETIME NULL,
        completed_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_reminders_status (status),
        INDEX idx_reminders_date (remind_date),
        INDEX idx_reminders_responsible (responsible_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Coloane noi pentru preaviz (idempotent — verific cu SHOW COLUMNS înainte de ALTER)
try {
    $existingCols = [];
    $stmtCols = $pdo->query("SHOW COLUMNS FROM reminders");
    while ($c = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
        $existingCols[(string)$c['Field']] = true;
    }
    if (!isset($existingCols['notice_period_value'])) {
        $pdo->exec("ALTER TABLE reminders ADD COLUMN notice_period_value INT NULL AFTER recurrence_end_date");
    }
    if (!isset($existingCols['notice_period_unit'])) {
        $pdo->exec("ALTER TABLE reminders ADD COLUMN notice_period_unit VARCHAR(10) NULL AFTER notice_period_value");
    }
    if (!isset($existingCols['email_to'])) {
        $pdo->exec("ALTER TABLE reminders ADD COLUMN email_to VARCHAR(255) NULL AFTER notice_period_unit");
    }
} catch (Throwable $e) {
    error_log('reminders.php schema upgrade: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Catalog categorii + recurențe + preaviz
|--------------------------------------------------------------------------
*/
$categories = [
    'vehicle_review' => ['label' => 'Revizie Auto',     'icon' => '🚗', 'color' => 'or'],
    'itp'            => ['label' => 'ITP',              'icon' => '🔧', 'color' => 'bl'],
    'insurance'      => ['label' => 'Asigurare',        'icon' => '🛡️', 'color' => 'gr'],
    'index_meter'    => ['label' => 'Transmitere index','icon' => '📊', 'color' => 'bl'],
    'other'          => ['label' => 'Altul',            'icon' => '📌', 'color' => 'mu'],
];

// Pentru remindere vechi cu categorii care nu mai există în noua listă,
// le mapăm la 'other' la afișare (compatibilitate retro, nu pierde date).
function rem_category_resolve(string $key, array $categories): array {
    if (isset($categories[$key])) return $categories[$key];
    // mapări legacy
    $legacyMap = [
        'vehicle' => 'vehicle_review',
        'general' => 'other',
        'meeting' => 'other',
        'internal_meeting' => 'other',
        'accounting' => 'other',
        'supply' => 'other',
    ];
    $mapped = $legacyMap[$key] ?? 'other';
    return $categories[$mapped] ?? $categories['other'];
}

$recurrenceTypes = [
    ''        => 'Fără recurență',
    'daily'   => 'Zilnic',
    'weekly'  => 'Săptămânal',
    'monthly' => 'Lunar',
    'yearly'  => 'Anual',
];

$noticeUnits = [
    'day'   => 'zile',
    'week'  => 'săptămâni',
    'month' => 'luni',
];

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function rem_next_date(string $current, string $type, int $interval): ?string {
    if ($type === '' || $interval < 1) return null;
    try {
        $d = new DateTime($current);
        switch ($type) {
            case 'daily':   $d->modify('+' . $interval . ' day'); break;
            case 'weekly':  $d->modify('+' . $interval . ' week'); break;
            case 'monthly': $d->modify('+' . $interval . ' month'); break;
            case 'yearly':  $d->modify('+' . $interval . ' year'); break;
            default: return null;
        }
        return $d->format('Y-m-d');
    } catch (Throwable $e) { return null; }
}

/**
 * Calculează data la care reminderul intră în fereastra de preaviz.
 * Ex: scadență 15.10, preaviz „1 săptămână" → start = 08.10
 * Returnează NULL dacă nu există preaviz (deci e considerat activ mereu).
 */
function rem_notice_start(string $remindDate, ?int $value, ?string $unit): ?string {
    if (!$value || $value < 1 || !$unit) return null;
    try {
        $d = new DateTime($remindDate);
        switch ($unit) {
            case 'day':   $d->modify('-' . (int)$value . ' day'); break;
            case 'week':  $d->modify('-' . (int)$value . ' week'); break;
            case 'month': $d->modify('-' . (int)$value . ' month'); break;
            default: return null;
        }
        return $d->format('Y-m-d');
    } catch (Throwable $e) { return null; }
}

/*
|--------------------------------------------------------------------------
| POST handler
|--------------------------------------------------------------------------
*/
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create' || $action === 'update') {
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $category = (string)($_POST['category'] ?? 'other');
            if (!isset($categories[$category])) $category = 'other';
            $remindDate = trim((string)($_POST['remind_date'] ?? ''));

            // Recurența: [interval][unit] - dacă intervalul = 0 sau lipsă, fără recurență
            $recurrenceInterval = max(0, (int)($_POST['recurrence_interval'] ?? 0));
            $recurrenceUnitMap = ['day' => 'daily', 'week' => 'weekly', 'month' => 'monthly', 'year' => 'yearly'];
            $recurrenceUnitRaw = (string)($_POST['recurrence_unit'] ?? 'year');
            if ($recurrenceInterval > 0 && isset($recurrenceUnitMap[$recurrenceUnitRaw])) {
                $recurrenceType = $recurrenceUnitMap[$recurrenceUnitRaw];
            } else {
                $recurrenceType = '';
                $recurrenceInterval = 1; // valoare neutră în DB când nu există recurență
            }
            $recurrenceEndDate = null; // câmp eliminat din UI

            // Preaviz: [value][unit] - dacă value = 0 sau lipsă, fără preaviz
            $noticeUnitRaw = (string)($_POST['notice_period_unit'] ?? 'day');
            $noticeValueRaw = max(0, (int)($_POST['notice_period_value'] ?? 0));
            if ($noticeValueRaw > 0 && isset($noticeUnits[$noticeUnitRaw])) {
                $noticeValue = $noticeValueRaw;
                $noticeUnit = $noticeUnitRaw;
            } else {
                $noticeValue = null;
                $noticeUnit = null;
            }

            // Email opțional: dacă bifa „Trimite email" e activă, email_to e obligatoriu și valid
            $emailEnabled = !empty($_POST['email_enabled']);
            $emailToRaw = trim((string)($_POST['email_to'] ?? ''));
            $emailTo = null;
            if ($emailEnabled) {
                if ($emailToRaw === '' || !filter_var($emailToRaw, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Pentru notificare email, completează o adresă validă.');
                }
                $emailTo = $emailToRaw;
            }

            if ($title === '' || $remindDate === '') {
                throw new RuntimeException('Completează titlul și data scadenței.');
            }
            $d = DateTime::createFromFormat('Y-m-d', $remindDate);
            if (!$d || $d->format('Y-m-d') !== $remindDate) {
                throw new RuntimeException('Data introdusă nu este validă.');
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO reminders
                    (title, description, category, remind_date, responsible_user_id, created_by,
                     status, recurrence_type, recurrence_interval, recurrence_end_date,
                     notice_period_value, notice_period_unit, email_to)
                    VALUES (?, ?, ?, ?, NULL, ?, 'pending', ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $title, $description ?: null, $category, $remindDate,
                    current_user_id(),
                    $recurrenceType ?: null, $recurrenceInterval, $recurrenceEndDate,
                    $noticeValue, $noticeUnit, $emailTo
                ]);
                $flashSuccess = 'Reminder adăugat.';
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('ID invalid.');
                $stmt = $pdo->prepare("
                    UPDATE reminders SET
                        title = ?, description = ?, category = ?, remind_date = ?,
                        recurrence_type = ?, recurrence_interval = ?, recurrence_end_date = ?,
                        notice_period_value = ?, notice_period_unit = ?, email_to = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title, $description ?: null, $category, $remindDate,
                    $recurrenceType ?: null, $recurrenceInterval, $recurrenceEndDate,
                    $noticeValue, $noticeUnit, $emailTo, $id
                ]);
                $flashSuccess = 'Reminder actualizat.';
            }
        } elseif ($action === 'complete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID invalid.');

            $stmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $rem = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rem) throw new RuntimeException('Reminder negăsit.');

            $pdo->prepare("UPDATE reminders SET status='done', completed_at=NOW(), completed_by=? WHERE id=?")
                ->execute([current_user_id(), $id]);

            // Daca e recurent, cream urmatorul cu aceleași setări de preaviz
            if (!empty($rem['recurrence_type'])) {
                $nextDate = rem_next_date((string)$rem['remind_date'], (string)$rem['recurrence_type'], (int)$rem['recurrence_interval']);
                $endDate = $rem['recurrence_end_date'] ?? null;
                if ($nextDate && (!$endDate || $nextDate <= $endDate)) {
                    $parentId = (int)($rem['recurrence_parent_id'] ?: $rem['id']);
                    $pdo->prepare("
                        INSERT INTO reminders
                        (title, description, category, remind_date, responsible_user_id, created_by,
                         status, recurrence_type, recurrence_interval, recurrence_end_date,
                         recurrence_parent_id, notice_period_value, notice_period_unit, email_to)
                        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $rem['title'], $rem['description'], $rem['category'], $nextDate,
                        $rem['responsible_user_id'], current_user_id(),
                        $rem['recurrence_type'], $rem['recurrence_interval'], $endDate,
                        $parentId,
                        $rem['notice_period_value'] ?? null, $rem['notice_period_unit'] ?? null,
                        $rem['email_to'] ?? null
                    ]);
                    $flashSuccess = 'Marcat ca finalizat. Următoarea apariție programată pentru ' . pz_date($nextDate) . '.';
                } else {
                    $flashSuccess = 'Reminder finalizat. Recurența a ajuns la sfârșit.';
                }
            } else {
                $flashSuccess = 'Reminder finalizat.';
            }
        } elseif ($action === 'reopen') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID invalid.');
            $pdo->prepare("UPDATE reminders SET status='pending', completed_at=NULL, completed_by=NULL WHERE id=?")->execute([$id]);
            $flashSuccess = 'Reminder redeschis.';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID invalid.');
            $pdo->prepare("DELETE FROM reminders WHERE id=?")->execute([$id]);
            $flashSuccess = 'Reminder șters.';
        }
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
    }

    $params = ['flash' => $flashSuccess ?: '', 'flash_err' => $flashError ?: ''];
    if (isset($_GET['tab'])) $params['tab'] = $_GET['tab'];
    header('Location: reminders.php?' . http_build_query(array_filter($params)));
    exit;
}

$flashSuccess = trim((string)($_GET['flash'] ?? ''));
$flashError = trim((string)($_GET['flash_err'] ?? ''));

/*
|--------------------------------------------------------------------------
| Listare
|--------------------------------------------------------------------------
*/
$tab = (string)($_GET['tab'] ?? 'active');
if (!in_array($tab, ['active', 'history', 'upcoming'], true)) $tab = 'active';
$today = date('Y-m-d');

// Toate reminderele pending pentru calculul logic preaviz
$allPending = [];
try {
    $stmt = $pdo->query("SELECT * FROM reminders WHERE status = 'pending' ORDER BY remind_date ASC, id ASC LIMIT 1000");
    $allPending = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('reminders.php pending fetch: ' . $e->getMessage()); }

$activeList = []; // în fereastra de preaviz sau scadente
$upcomingList = []; // după fereastra de preaviz
foreach ($allPending as $rem) {
    $remindDate = (string)$rem['remind_date'];
    $noticeStart = rem_notice_start($remindDate, isset($rem['notice_period_value']) ? (int)$rem['notice_period_value'] : null, $rem['notice_period_unit'] ?? null);
    // Fără preaviz → mereu activ. Cu preaviz → activ doar după data de start.
    $isActive = ($noticeStart === null) ? true : ($noticeStart <= $today);
    if ($isActive) {
        $activeList[] = $rem;
    } else {
        $upcomingList[] = $rem;
    }
}

// Istoric: finalizate
$historyList = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM reminders
        WHERE status = 'done'
        ORDER BY completed_at DESC, id DESC
        LIMIT 500
    ");
    $historyList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('reminders.php history fetch: ' . $e->getMessage()); }

// Counter-uri pentru tab-uri
$countActive   = count($activeList);
$countUpcoming = count($upcomingList);
$countHistory  = count($historyList);

// Selectează lista de afișat în funcție de tab
if ($tab === 'history')      $displayList = $historyList;
elseif ($tab === 'upcoming') $displayList = $upcomingList;
else                          $displayList = $activeList;

// Pentru edit prefill
$editId = (int)($_GET['edit'] ?? 0);
$editData = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Reminders - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
* { font-family: 'Satoshi', 'Inter', system-ui, -apple-system, sans-serif !important; }

.rem-page { max-width: 1080px; margin: 0 auto; display: flex; flex-direction: column; gap: 14px; padding: 16px 20px; }

.rem-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.rem-header h1 { font-size: 22px; font-weight: 700; color: var(--pz-title); letter-spacing: -.02em; margin: 0; }
.rem-header .sub { font-size: 13px; color: var(--pz-mu); margin-top: 2px; }

.rem-tabs { display: flex; gap: 6px; background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); padding: 6px; }
.rem-tab { padding: 8px 14px; border-radius: var(--pz-rs); font-size: 13px; font-weight: 600; color: var(--pz-mu); display: inline-flex; align-items: center; gap: 8px; text-decoration: none; cursor: pointer; transition: background .15s, color .15s; border: 0; background: transparent; }
.rem-tab:hover { background: var(--pz-soft); color: var(--pz-title); }
.rem-tab.is-active { background: var(--pz-bls); color: var(--pz-bld); }
.rem-tab .count { font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 99px; background: var(--pz-line); color: var(--pz-mu); }
.rem-tab.is-active .count { background: var(--pz-blb); color: var(--pz-bld); }

.rem-list { background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); overflow: hidden; }
.rem-row { display: grid; grid-template-columns: 130px minmax(0, 1fr) 160px 170px 150px; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--pz-lines); align-items: center; font-size: 13px; }
.rem-row:last-child { border-bottom: 0; }
.rem-row:hover { background: var(--pz-soft); }
.rem-row.is-overdue { background: var(--pz-res); }
.rem-row.is-overdue:hover { background: #FECACA; }
.rem-row.is-done { opacity: .58; }
.rem-row.is-notice { background: #FFFBEB; }
.rem-row.is-notice:hover { background: #FEF3C7; }

.rem-date { font-family: var(--mono, ui-monospace, monospace); font-size: 12.5px; color: var(--pz-text); font-weight: 700; }
.rem-date .pill { display: inline-block; margin-top: 4px; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px; font-family: 'Satoshi', 'Inter', sans-serif; text-transform: uppercase; letter-spacing: .03em; }
.rem-date .pill.due-soon { background: #FFEDD5; color: #9A3412; }
.rem-date .pill.due-today { background: var(--pz-bls); color: var(--pz-bld); }
.rem-date .pill.due-overdue { background: var(--pz-res); color: var(--pz-re); }

.rem-title-cell .ttl { font-weight: 650; color: var(--pz-title); font-size: 14px; }
.rem-title-cell .desc { font-size: 12px; color: var(--pz-mu); margin-top: 3px; overflow-wrap: anywhere; line-height: 1.45; }

.rem-cat { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: var(--pz-rs); font-size: 11px; font-weight: 700; white-space: nowrap; }
.rem-cat.bl { background: var(--pz-bls); color: var(--pz-bld); border: 1px solid var(--pz-blb); }
.rem-cat.gr { background: var(--pz-grs); color: var(--pz-gr); border: 1px solid var(--pz-grb); }
.rem-cat.or { background: var(--pz-ors); color: var(--pz-or); border: 1px solid var(--pz-orb); }
.rem-cat.mu { background: var(--pz-soft); color: var(--pz-text); border: 1px solid var(--pz-line); }

.rem-meta { font-size: 11.5px; color: var(--pz-mu); display: flex; flex-direction: column; gap: 3px; }
.rem-meta .line { display: flex; align-items: center; gap: 6px; }

.rem-actions { display: flex; gap: 6px; justify-content: flex-end; }
.rem-actions form { margin: 0; padding: 0; display: inline; }
.rem-actions .btn { min-height: 32px; padding: 0 10px; font-size: 12px; line-height: 1; }

.rem-empty { padding: 56px 20px; text-align: center; color: var(--pz-mu); font-size: 14px; }

.rem-modal-form { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.rem-modal-form .full { grid-column: 1 / -1; }
.rem-modal-form label { display: block; font-size: 10.5px; font-weight: 800; color: var(--pz-mu); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
.rem-modal-form input, .rem-modal-form select, .rem-modal-form textarea { width: 100%; box-sizing: border-box; }

.rem-email-box { background: var(--pz-soft); border: 1px dashed var(--pz-line); border-radius: var(--pz-rs); padding: 12px 14px; }
.rem-email-box.is-active { background: var(--pz-bls); border-color: var(--pz-blb); border-style: solid; }
.rem-email-fields { display: none; grid-template-columns: 1fr; gap: 10px; margin-top: 10px; }
.rem-email-box.is-active .rem-email-fields { display: grid; }
.rem-box-help { font-size: 11px; color: var(--pz-mu); margin-top: 6px; }

.rem-combo { display: flex; gap: 8px; }
.rem-combo input[type=number] { flex: 0 0 80px; }
.rem-combo select { flex: 1; }

@media (max-width: 900px) {
    .rem-row { grid-template-columns: 1fr; gap: 8px; }
    .rem-modal-form { grid-template-columns: 1fr; }
    .rem-tabs { overflow-x: auto; }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('reminders', true); ?>
    <main class="main">
        <div class="content rem-page">

            <?php pz_page_header([
                'kicker'   => 'Operațional',
                'title'    => 'Reminders',
                'subtitle' => 'Sarcini de office: revizii auto, ITP, asigurări, transmitere index și altele.',
                'actions'  => [
                    [
                        'label'   => 'Reminder nou',
                        'variant' => 'primary',
                        'icon'    => 'ti-plus',
                        'type'    => 'button',
                        'onclick' => 'remOpenCreate()',
                    ],
                ],
                'tabs' => [
                    ['label' => 'Active · ' . (int)$countActive,             'href' => 'reminders.php?tab=active',   'active' => $tab === 'active'],
                    ['label' => 'Programate ulterior · ' . (int)$countUpcoming, 'href' => 'reminders.php?tab=upcoming', 'active' => $tab === 'upcoming'],
                    ['label' => 'Istoric · ' . (int)$countHistory,           'href' => 'reminders.php?tab=history',  'active' => $tab === 'history'],
                ],
            ]); ?>

            <?php if ($flashSuccess !== ''): ?>
                <div class="notice notice-success"><?= rem_h($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError !== ''): ?>
                <div class="notice notice-danger"><?= rem_h($flashError) ?></div>
            <?php endif; ?>

            <div class="rem-list">
                <?php if (empty($displayList)): ?>
                    <div class="rem-empty">
                        <?php if ($tab === 'history'): ?>
                            Niciun reminder finalizat încă.
                        <?php elseif ($tab === 'upcoming'): ?>
                            Niciun reminder programat pentru viitor (toate sunt deja în fereastra de preaviz).
                        <?php else: ?>
                            Niciun reminder activ.<br>
                            <button class="btn accent" type="button" onclick="remOpenCreate()" style="margin-top:14px;">+ Adaugă primul reminder</button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($displayList as $rem):
                        $cat = rem_category_resolve((string)$rem['category'], $categories);
                        $remindDate = (string)$rem['remind_date'];
                        $isOverdue = $rem['status'] === 'pending' && $remindDate < $today;
                        $isToday   = $remindDate === $today;
                        $isDone    = $rem['status'] === 'done';
                        $noticeStart = rem_notice_start($remindDate, isset($rem['notice_period_value']) ? (int)$rem['notice_period_value'] : null, $rem['notice_period_unit'] ?? null);
                        $inNotice  = !$isDone && !$isOverdue && !$isToday && $noticeStart && $noticeStart <= $today && $remindDate > $today;

                        $rowClass = '';
                        if ($isDone) $rowClass = 'is-done';
                        elseif ($isOverdue) $rowClass = 'is-overdue';
                        elseif ($inNotice) $rowClass = 'is-notice';

                        $pillClass = '';
                        $pillText = '';
                        if ($isOverdue) { $pillClass = 'due-overdue'; $pillText = 'Scadent'; }
                        elseif ($isToday) { $pillClass = 'due-today'; $pillText = 'Astăzi'; }
                        elseif ($inNotice) { $pillClass = 'due-soon'; $pillText = 'În preaviz'; }
                    ?>
                        <div class="rem-row <?= $rowClass ?>">
                            <div class="rem-date">
                                <?= rem_h(pz_date($remindDate)) ?>
                                <?php if ($pillText !== ''): ?>
                                    <span class="pill <?= $pillClass ?>"><?= rem_h($pillText) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="rem-title-cell">
                                <div class="ttl"><?= rem_h($rem['title']) ?></div>
                                <?php if ($rem['description']): ?>
                                    <div class="desc"><?= rem_h(mb_substr((string)$rem['description'], 0, 180)) . (mb_strlen((string)$rem['description']) > 180 ? '…' : '') ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="rem-cat <?= rem_h($cat['color']) ?>"><?= rem_h($cat['icon']) ?> <?= rem_h($cat['label']) ?></span>
                            </div>
                            <div class="rem-meta">
                                <?php if (!empty($rem['recurrence_type'])):
                                    $recLabel = $recurrenceTypes[$rem['recurrence_type']] ?? '';
                                    $recInterval = (int)($rem['recurrence_interval'] ?? 1);
                                ?>
                                    <div class="line">🔁 <?= rem_h($recLabel) ?><?= $recInterval > 1 ? ' (×' . $recInterval . ')' : '' ?></div>
                                <?php endif; ?>
                                <?php if (!empty($rem['notice_period_value']) && !empty($rem['notice_period_unit'])):
                                    $unitLabel = $noticeUnits[$rem['notice_period_unit']] ?? '';
                                ?>
                                    <div class="line">⏰ Preaviz: <?= (int)$rem['notice_period_value'] ?> <?= rem_h($unitLabel) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($rem['email_to'])): ?>
                                    <div class="line" title="Notificare email către <?= rem_h($rem['email_to']) ?>">✉ Email activ</div>
                                <?php endif; ?>
                                <?php if (empty($rem['recurrence_type']) && empty($rem['notice_period_value']) && empty($rem['email_to'])): ?>
                                    <div class="line" style="color:var(--pz-mu)">—</div>
                                <?php endif; ?>
                            </div>
                            <div class="rem-actions">
                                <?php if (!$isDone): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="complete">
                                        <input type="hidden" name="id" value="<?= (int)$rem['id'] ?>">
                                        <button class="btn" type="submit" title="Marchează finalizat">✓</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reopen">
                                        <input type="hidden" name="id" value="<?= (int)$rem['id'] ?>">
                                        <button class="btn" type="submit" title="Redeschide">↺</button>
                                    </form>
                                <?php endif; ?>
                                <button class="btn" type="button" onclick="remOpenEdit(<?= (int)$rem['id'] ?>)" title="Editează">✎</button>
                                <form method="post" onsubmit="return confirm('Sigur ștergi acest reminder?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$rem['id'] ?>">
                                    <button class="btn danger" type="submit" title="Şterge">×</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<!-- Modal create/edit -->
<div class="modal" id="remModal">
    <div class="modal-box" style="max-width:680px;">
        <div class="modal-header">
            <h2 id="remModalTitle">Reminder nou</h2>
            <button class="modal-close" type="button" onclick="remCloseModal()">&times;</button>
        </div>
        <form method="post" id="remForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="remActionField" value="create">
            <input type="hidden" name="id" id="remIdField" value="">

            <div class="rem-modal-form">
                <div class="full">
                    <label>Titlu *</label>
                    <input type="text" name="title" id="remTitle" required maxlength="200" placeholder="Ex: ITP Renault Master B 123 ABC">
                </div>

                <div class="full">
                    <label>Descriere</label>
                    <textarea name="description" id="remDescription" rows="2" placeholder="Detalii suplimentare (opțional)"></textarea>
                </div>

                <div>
                    <label>Data scadenței *</label>
                    <input type="date" name="remind_date" id="remDate" required>
                </div>

                <div>
                    <label>Categorie</label>
                    <select name="category" id="remCategory">
                        <?php foreach ($categories as $key => $cat): ?>
                            <option value="<?= rem_h($key) ?>"><?= rem_h($cat['icon'] . ' ' . $cat['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Recurență (la fiecare)</label>
                    <div class="rem-combo">
                        <input type="number" name="recurrence_interval" id="remRecurrenceInterval" value="0" min="0" max="365">
                        <select name="recurrence_unit" id="remRecurrenceUnit">
                            <option value="day">zile</option>
                            <option value="week">săptămâni</option>
                            <option value="month">luni</option>
                            <option value="year" selected>ani</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label>Preaviz (înainte de scadență)</label>
                    <div class="rem-combo">
                        <input type="number" name="notice_period_value" id="remNoticeValue" value="0" min="0" max="365">
                        <select name="notice_period_unit" id="remNoticeUnit">
                            <option value="day" selected>zile</option>
                            <option value="week">săptămâni</option>
                            <option value="month">luni</option>
                        </select>
                    </div>
                </div>

                <div class="full">
                    <div class="rem-email-box" id="remEmailBox">
                        <label style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13px;color:var(--pz-title);">
                            <input type="checkbox" name="email_enabled" id="remEmailEnabled" value="1" onchange="remToggleEmail()" style="width:auto;margin:0;">
                            Trimite notificare email cu 1 zi înainte de scadență
                        </label>
                        <div class="rem-email-fields">
                            <div>
                                <label>Adresa de email</label>
                                <input type="email" name="email_to" id="remEmailTo" placeholder="ex: <?= rem_h(current_user_email() ?: 'office@firma.ro') ?>" maxlength="255">
                            </div>
                        </div>
                        <div class="rem-box-help">Dacă bifa este activă, cron-ul zilnic va trimite un email la adresa de mai sus cu o zi înainte de data scadenței.</div>
                    </div>
                </div>
            </div>

            <div class="actions-row" style="margin-top:16px;display:flex;justify-content:flex-end;gap:8px;">
                <button class="btn" type="button" onclick="remCloseModal()">Renunță</button>
                <button class="btn accent" type="submit">Salvează</button>
            </div>
        </form>
    </div>
</div>

<script>
const remEditData = <?= json_encode($editData, JSON_UNESCAPED_UNICODE) ?>;

function remOpenCreate() {
    document.getElementById('remModalTitle').textContent = 'Reminder nou';
    document.getElementById('remActionField').value = 'create';
    document.getElementById('remIdField').value = '';
    document.getElementById('remForm').reset();
    document.getElementById('remDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('remRecurrenceInterval').value = 0;
    document.getElementById('remRecurrenceUnit').value = 'year';
    document.getElementById('remNoticeValue').value = 0;
    document.getElementById('remNoticeUnit').value = 'day';
    document.getElementById('remEmailEnabled').checked = false;
    document.getElementById('remEmailTo').value = '';
    remToggleEmail();
    document.getElementById('remModal').classList.add('open');
    setTimeout(() => document.getElementById('remTitle').focus(), 60);
}

function remOpenEdit(id) {
    if (remEditData && Number(remEditData.id) === id) {
        remFillForm(remEditData);
    } else {
        window.location.href = 'reminders.php?edit=' + id;
    }
}

function remFillForm(d) {
    document.getElementById('remModalTitle').textContent = 'Editează reminder';
    document.getElementById('remActionField').value = 'update';
    document.getElementById('remIdField').value = d.id || '';
    document.getElementById('remTitle').value = d.title || '';
    document.getElementById('remDescription').value = d.description || '';
    document.getElementById('remDate').value = d.remind_date || '';
    // Mapează categoriile vechi la 'other' în UI
    const validCats = <?= json_encode(array_keys($categories)) ?>;
    document.getElementById('remCategory').value = validCats.includes(d.category) ? d.category : 'other';
    // Recurența: mapează din DB (daily/weekly/monthly/yearly) la UI (day/week/month/year)
    const dbToUiRec = {'daily':'day','weekly':'week','monthly':'month','yearly':'year'};
    if (d.recurrence_type && dbToUiRec[d.recurrence_type]) {
        document.getElementById('remRecurrenceUnit').value = dbToUiRec[d.recurrence_type];
        document.getElementById('remRecurrenceInterval').value = d.recurrence_interval || 1;
    } else {
        document.getElementById('remRecurrenceUnit').value = 'year';
        document.getElementById('remRecurrenceInterval').value = 0;
    }
    // Preaviz
    if (d.notice_period_value && d.notice_period_unit) {
        document.getElementById('remNoticeUnit').value = d.notice_period_unit;
        document.getElementById('remNoticeValue').value = d.notice_period_value;
    } else {
        document.getElementById('remNoticeUnit').value = 'day';
        document.getElementById('remNoticeValue').value = 0;
    }
    document.getElementById('remEmailEnabled').checked = !!d.email_to;
    document.getElementById('remEmailTo').value = d.email_to || '';
    remToggleEmail();
    document.getElementById('remModal').classList.add('open');
}

function remCloseModal() {
    document.getElementById('remModal').classList.remove('open');
    if (window.location.search.includes('edit=')) {
        const url = new URL(window.location.href);
        url.searchParams.delete('edit');
        window.history.replaceState({}, '', url);
    }
}

function remToggleEmail() {
    const checked = document.getElementById('remEmailEnabled').checked;
    const box = document.getElementById('remEmailBox');
    const input = document.getElementById('remEmailTo');
    if (checked) {
        box.classList.add('is-active');
        // Pre-completează cu email-ul utilizatorului curent dacă e gol
        if (!input.value) input.value = <?= json_encode(current_user_email() ?: '') ?>;
    } else {
        box.classList.remove('is-active');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (remEditData) {
        remFillForm(remEditData);
    }
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('open_create') === '1') {
        remOpenCreate();
    }
});

document.getElementById('remModal').addEventListener('click', function(e) {
    if (e.target === this) remCloseModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') remCloseModal();
});
</script>
</body>
</html>
