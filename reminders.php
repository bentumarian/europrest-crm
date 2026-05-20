<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once __DIR__ . '/notification_lib.php';

$isAdmin = is_admin();
if (!$isAdmin) { header('Location: calendar.php'); exit; }

function rem_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/*
|--------------------------------------------------------------------------
| Schema auto-bootstrap
|--------------------------------------------------------------------------
*/
$pdo->exec("
    CREATE TABLE IF NOT EXISTS reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        category VARCHAR(30) NOT NULL DEFAULT 'general',
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

/*
|--------------------------------------------------------------------------
| Catalog categorii + recurente
|--------------------------------------------------------------------------
*/
$categories = [
    'general'          => ['label' => 'General',         'icon' => '📌', 'color' => 'bl'],
    'vehicle'          => ['label' => 'Revizie mașină',  'icon' => '🚗', 'color' => 'or'],
    'meeting'          => ['label' => 'Întâlnire',       'icon' => '👥', 'color' => 'gr'],
    'internal_meeting' => ['label' => 'Ședință internă', 'icon' => '📋', 'color' => 'bl'],
    'accounting'       => ['label' => 'Contabilitate',   'icon' => '💼', 'color' => 'or'],
    'supply'           => ['label' => 'Aprovizionare',   'icon' => '📦', 'color' => 'gr'],
    'other'            => ['label' => 'Altul',           'icon' => '⚙️', 'color' => 'mu'],
];

$recurrenceTypes = [
    ''        => 'Fără recurență',
    'daily'   => 'Zilnic',
    'weekly'  => 'Săptămânal',
    'monthly' => 'Lunar',
    'yearly'  => 'Anual',
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

function rem_users(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT id, COALESCE(name, username, email, CONCAT('User #', id)) AS name FROM users WHERE COALESCE(active, 1) = 1 ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
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
            $category = (string)($_POST['category'] ?? 'general');
            if (!isset($categories[$category])) $category = 'general';
            $remindDate = trim((string)($_POST['remind_date'] ?? ''));
            $remindTime = trim((string)($_POST['remind_time'] ?? '')) ?: null;
            $responsibleUserId = (int)($_POST['responsible_user_id'] ?? 0) ?: null;
            $recurrenceType = (string)($_POST['recurrence_type'] ?? '');
            if (!isset($recurrenceTypes[$recurrenceType])) $recurrenceType = '';
            $recurrenceInterval = max(1, (int)($_POST['recurrence_interval'] ?? 1));
            $recurrenceEndDate = trim((string)($_POST['recurrence_end_date'] ?? '')) ?: null;

            if ($title === '' || $remindDate === '') {
                throw new RuntimeException('Completează titlul și data.');
            }
            $d = DateTime::createFromFormat('Y-m-d', $remindDate);
            if (!$d || $d->format('Y-m-d') !== $remindDate) {
                throw new RuntimeException('Data introdusă nu este validă.');
            }
            if ($remindTime !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $remindTime)) {
                $remindTime = null;
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO reminders
                    (title, description, category, remind_date, remind_time, responsible_user_id,
                     created_by, status, recurrence_type, recurrence_interval, recurrence_end_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
                ");
                $stmt->execute([
                    $title, $description ?: null, $category, $remindDate, $remindTime,
                    $responsibleUserId, current_user_id(),
                    $recurrenceType ?: null, $recurrenceInterval, $recurrenceEndDate
                ]);
                $flashSuccess = 'Reminder adăugat.';
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('ID invalid.');
                $stmt = $pdo->prepare("
                    UPDATE reminders SET
                        title = ?, description = ?, category = ?, remind_date = ?, remind_time = ?,
                        responsible_user_id = ?, recurrence_type = ?, recurrence_interval = ?,
                        recurrence_end_date = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title, $description ?: null, $category, $remindDate, $remindTime,
                    $responsibleUserId, $recurrenceType ?: null, $recurrenceInterval,
                    $recurrenceEndDate, $id
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

            // Daca e recurent, cream urmatorul
            if (!empty($rem['recurrence_type'])) {
                $nextDate = rem_next_date((string)$rem['remind_date'], (string)$rem['recurrence_type'], (int)$rem['recurrence_interval']);
                $endDate = $rem['recurrence_end_date'] ?? null;
                if ($nextDate && (!$endDate || $nextDate <= $endDate)) {
                    $parentId = (int)($rem['recurrence_parent_id'] ?: $rem['id']);
                    $pdo->prepare("
                        INSERT INTO reminders
                        (title, description, category, remind_date, remind_time, responsible_user_id,
                         created_by, status, recurrence_type, recurrence_interval, recurrence_end_date,
                         recurrence_parent_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
                    ")->execute([
                        $rem['title'], $rem['description'], $rem['category'], $nextDate, $rem['remind_time'],
                        $rem['responsible_user_id'], current_user_id(),
                        $rem['recurrence_type'], $rem['recurrence_interval'], $endDate,
                        $parentId
                    ]);
                    $flashSuccess = 'Marcat ca finalizat. Următoarea apariție programată pentru ' . $nextDate . '.';
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
    foreach (['status', 'category', 'responsible'] as $k) {
        if (isset($_GET[$k])) $params[$k] = $_GET[$k];
    }
    header('Location: reminders.php?' . http_build_query(array_filter($params)));
    exit;
}

$flashSuccess = trim((string)($_GET['flash'] ?? ''));
$flashError = trim((string)($_GET['flash_err'] ?? ''));

/*
|--------------------------------------------------------------------------
| Filtre + listă
|--------------------------------------------------------------------------
*/
$filterStatus = (string)($_GET['status'] ?? 'pending');
if (!in_array($filterStatus, ['all', 'pending', 'done', 'overdue'], true)) $filterStatus = 'pending';
$filterCategory = (string)($_GET['category'] ?? '');
if ($filterCategory !== '' && !isset($categories[$filterCategory])) $filterCategory = '';
$filterResponsible = (int)($_GET['responsible'] ?? 0);
$searchQ = trim((string)($_GET['q'] ?? ''));

$where = ['1=1'];
$params = [];
$today = date('Y-m-d');

if ($filterStatus === 'pending') {
    $where[] = "r.status = 'pending'";
} elseif ($filterStatus === 'done') {
    $where[] = "r.status = 'done'";
} elseif ($filterStatus === 'overdue') {
    $where[] = "r.status = 'pending' AND r.remind_date < ?";
    $params[] = $today;
}

if ($filterCategory !== '') {
    $where[] = "r.category = ?";
    $params[] = $filterCategory;
}

if ($filterResponsible > 0) {
    $where[] = "r.responsible_user_id = ?";
    $params[] = $filterResponsible;
}

if ($searchQ !== '') {
    $where[] = "(r.title LIKE ? OR r.description LIKE ?)";
    $params[] = '%' . $searchQ . '%';
    $params[] = '%' . $searchQ . '%';
}

$sql = "
    SELECT r.*,
           u.name AS responsible_name,
           cb.name AS created_by_name
    FROM reminders r
    LEFT JOIN users u ON u.id = r.responsible_user_id
    LEFT JOIN users cb ON cb.id = r.created_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END ASC,
        r.remind_date ASC,
        COALESCE(r.remind_time, '23:59:59') ASC,
        r.id ASC
    LIMIT 500
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPI counters
$kpi = ['today' => 0, 'overdue' => 0, 'week' => 0, 'total_pending' => 0];
try {
    $r = $pdo->query("
        SELECT
            SUM(CASE WHEN status='pending' AND remind_date = CURDATE() THEN 1 ELSE 0 END) AS today,
            SUM(CASE WHEN status='pending' AND remind_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
            SUM(CASE WHEN status='pending' AND remind_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS total_pending
        FROM reminders
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($kpi as $k => $_) $kpi[$k] = (int)($r[$k] ?? 0);
} catch (Throwable $e) {}

$users = rem_users($pdo);

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
<title>Remindere - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
* { font-family: 'Inter', system-ui, -apple-system, sans-serif !important; }

.rem-page { max-width: 1280px; margin: 0 auto; display: flex; flex-direction: column; gap: 14px; padding: 16px 20px; }

.rem-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.rem-header h1 { font-size: 22px; font-weight: 700; color: var(--pz-title); letter-spacing: -.02em; margin: 0; }
.rem-header .sub { font-size: 13px; color: var(--pz-mu); margin-top: 2px; }

.rem-kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.rem-kpi-card { background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); padding: 14px 16px; }
.rem-kpi-card.tone-danger { border-color: var(--pz-reb); background: var(--pz-res); }
.rem-kpi-card.tone-warning { border-color: var(--pz-orb); background: var(--pz-ors); }
.rem-kpi-card .lbl { font-size: 11px; font-weight: 700; color: var(--pz-mu); text-transform: uppercase; letter-spacing: .04em; }
.rem-kpi-card.tone-danger .lbl { color: var(--pz-re); }
.rem-kpi-card.tone-warning .lbl { color: var(--pz-or); }
.rem-kpi-card .val { font-size: 26px; font-weight: 700; color: var(--pz-title); margin-top: 4px; letter-spacing: -.02em; }
.rem-kpi-card.tone-danger .val { color: var(--pz-re); }
.rem-kpi-card.tone-warning .val { color: var(--pz-or); }

.rem-filter-bar { display: grid; grid-template-columns: minmax(200px, 1.5fr) 160px 200px 200px auto; gap: 8px; align-items: end; background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); padding: 12px; }
.rem-filter-bar .field label { display: block; font-size: 10.5px; font-weight: 800; color: var(--pz-mu); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
.rem-filter-bar .field input, .rem-filter-bar .field select { width: 100%; min-height: 38px; box-sizing: border-box; }

.rem-list { background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); overflow: hidden; }
.rem-row { display: grid; grid-template-columns: 110px minmax(0, 1fr) 160px 160px 130px 150px; gap: 12px; padding: 12px 14px; border-bottom: 1px solid var(--pz-lines); align-items: center; font-size: 13px; }
.rem-row:last-child { border-bottom: 0; }
.rem-row:hover { background: var(--pz-soft); }
.rem-row.is-overdue { background: var(--pz-res); }
.rem-row.is-overdue:hover { background: #FECACA; }
.rem-row.is-done { opacity: .58; }

.rem-date { font-family: var(--mono, ui-monospace, monospace); font-size: 12px; color: var(--pz-text); font-weight: 600; }
.rem-date .time { display: block; color: var(--pz-mu); font-size: 11px; margin-top: 2px; }
.rem-date.is-today { color: var(--pz-bl); }
.rem-date.is-overdue { color: var(--pz-re); font-weight: 700; }

.rem-title-cell .ttl { font-weight: 650; color: var(--pz-title); }
.rem-title-cell .desc { font-size: 11.5px; color: var(--pz-mu); margin-top: 2px; overflow-wrap: anywhere; line-height: 1.4; }

.rem-cat { display: inline-flex; align-items: center; gap: 6px; padding: 4px 9px; border-radius: var(--pz-rs); font-size: 11px; font-weight: 700; }
.rem-cat.bl { background: var(--pz-bls); color: var(--pz-bld); border: 1px solid var(--pz-blb); }
.rem-cat.gr { background: var(--pz-grs); color: var(--pz-gr); border: 1px solid var(--pz-grb); }
.rem-cat.or { background: var(--pz-ors); color: var(--pz-or); border: 1px solid var(--pz-orb); }
.rem-cat.mu { background: var(--pz-soft); color: var(--pz-text); border: 1px solid var(--pz-line); }

.rem-resp { font-size: 12px; color: var(--pz-text); }
.rem-resp .placeholder { color: var(--pz-mu); font-style: italic; }

.rem-recurrence { font-size: 11px; color: var(--pz-mu); }

.rem-actions { display: flex; gap: 4px; justify-content: flex-end; }
.rem-actions form { margin: 0; padding: 0; display: inline; }
.rem-actions .btn { min-height: 30px; padding: 0 9px; font-size: 12px; line-height: 1; }

.rem-empty { padding: 48px 20px; text-align: center; color: var(--pz-mu); font-size: 14px; }

.rem-modal-form { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.rem-modal-form .full { grid-column: 1 / -1; }
.rem-modal-form label { display: block; font-size: 10.5px; font-weight: 800; color: var(--pz-mu); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
.rem-modal-form input, .rem-modal-form select, .rem-modal-form textarea { width: 100%; box-sizing: border-box; }

.rem-recurrence-box { background: var(--pz-soft); border: 1px dashed var(--pz-line); border-radius: var(--pz-rs); padding: 10px 12px; }
.rem-recurrence-box.is-active { background: var(--pz-bls); border-color: var(--pz-blb); border-style: solid; }
.rem-recurrence-fields { display: none; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
.rem-recurrence-box.is-active .rem-recurrence-fields { display: grid; }

@media (max-width: 900px) {
    .rem-kpi-grid { grid-template-columns: 1fr 1fr; }
    .rem-filter-bar { grid-template-columns: 1fr; }
    .rem-row { grid-template-columns: 1fr; gap: 6px; }
    .rem-modal-form { grid-template-columns: 1fr; }
}
</style>
<?php render_search_preview_assets(); ?>
</head>
<body>
<div class="layout">
    <?php render_sidebar('reminders', true); ?>
    <main class="main">
        <div class="content rem-page">

            <div class="rem-header">
                <div>
                    <h1>Remindere</h1>
                    <div class="sub">Sarcini interne, întâlniri, revizii, livrări — totul într-un loc.</div>
                </div>
                <button class="btn accent" type="button" onclick="remOpenCreate()">+ Reminder nou</button>
            </div>

            <?php if ($flashSuccess !== ''): ?>
                <div class="notice notice-success"><?= rem_h($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError !== ''): ?>
                <div class="notice notice-danger"><?= rem_h($flashError) ?></div>
            <?php endif; ?>

            <div class="rem-kpi-grid">
                <div class="rem-kpi-card">
                    <div class="lbl">Total active</div>
                    <div class="val"><?= (int)$kpi['total_pending'] ?></div>
                </div>
                <div class="rem-kpi-card tone-warning">
                    <div class="lbl">Scadente azi</div>
                    <div class="val"><?= (int)$kpi['today'] ?></div>
                </div>
                <div class="rem-kpi-card">
                    <div class="lbl">Săptămâna asta</div>
                    <div class="val"><?= (int)$kpi['week'] ?></div>
                </div>
                <div class="rem-kpi-card tone-danger">
                    <div class="lbl">În întârziere</div>
                    <div class="val"><?= (int)$kpi['overdue'] ?></div>
                </div>
            </div>

            <form method="get" class="rem-filter-bar">
                <div class="field">
                    <label>Căutare</label>
                    <div class="pz-search-wrap">
                        <input type="search" id="remindersSearchInput" name="q" value="<?= rem_h($searchQ) ?>" placeholder="Titlu sau descriere" autocomplete="off">
                        <div class="pz-search-preview"></div>
                    </div>
                </div>
                <div class="field">
                    <label>Status</label>
                    <select name="status">
                        <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Active</option>
                        <option value="overdue" <?= $filterStatus === 'overdue' ? 'selected' : '' ?>>În întârziere</option>
                        <option value="done" <?= $filterStatus === 'done' ? 'selected' : '' ?>>Finalizate</option>
                        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Toate</option>
                    </select>
                </div>
                <div class="field">
                    <label>Categorie</label>
                    <select name="category">
                        <option value="">Toate</option>
                        <?php foreach ($categories as $key => $cat): ?>
                            <option value="<?= rem_h($key) ?>" <?= $filterCategory === $key ? 'selected' : '' ?>><?= rem_h($cat['icon'] . ' ' . $cat['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Responsabil</label>
                    <select name="responsible">
                        <option value="">Toți</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $filterResponsible === (int)$u['id'] ? 'selected' : '' ?>><?= rem_h($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>&nbsp;</label>
                    <button class="btn" type="submit">Filtrează</button>
                </div>
            </form>

            <div class="rem-list">
                <?php if (empty($reminders)): ?>
                    <div class="rem-empty">
                        Niciun reminder pentru filtrele alese.<br>
                        <button class="btn accent" type="button" onclick="remOpenCreate()" style="margin-top:14px;">+ Adaugă primul reminder</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($reminders as $rem):
                        $cat = $categories[$rem['category']] ?? $categories['general'];
                        $isOverdue = $rem['status'] === 'pending' && $rem['remind_date'] < $today;
                        $isToday = $rem['remind_date'] === $today;
                        $isDone = $rem['status'] === 'done';
                        $rowClass = '';
                        if ($isDone) $rowClass = 'is-done';
                        elseif ($isOverdue) $rowClass = 'is-overdue';
                        $dateClass = $isOverdue ? 'is-overdue' : ($isToday ? 'is-today' : '');
                    ?>
                        <div class="rem-row <?= $rowClass ?>">
                            <div class="rem-date <?= $dateClass ?>">
                                <?= rem_h(date('d.m.Y', strtotime($rem['remind_date']))) ?>
                                <?php if ($rem['remind_time']): ?>
                                    <span class="time"><?= rem_h(substr($rem['remind_time'], 0, 5)) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="rem-title-cell">
                                <div class="ttl"><?= rem_h($rem['title']) ?></div>
                                <?php if ($rem['description']): ?>
                                    <div class="desc"><?= rem_h(mb_substr($rem['description'], 0, 140)) . (mb_strlen($rem['description']) > 140 ? '…' : '') ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="rem-cat <?= rem_h($cat['color']) ?>"><?= rem_h($cat['icon']) ?> <?= rem_h($cat['label']) ?></span>
                            </div>
                            <div class="rem-resp">
                                <?php if ($rem['responsible_name']): ?>
                                    <?= rem_h($rem['responsible_name']) ?>
                                <?php else: ?>
                                    <span class="placeholder">Neasignat</span>
                                <?php endif; ?>
                            </div>
                            <div class="rem-recurrence">
                                <?php if (!empty($rem['recurrence_type'])): ?>
                                    🔁 <?= rem_h($recurrenceTypes[$rem['recurrence_type']] ?? '') ?><?php if ((int)$rem['recurrence_interval'] > 1): ?> (×<?= (int)$rem['recurrence_interval'] ?>)<?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                            <div class="rem-actions">
                                <?php if (!$isDone): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="complete">
                                        <input type="hidden" name="id" value="<?= (int)$rem['id'] ?>">
                                        <button class="btn" type="submit" title="Marchează ca finalizat">✓</button>
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
    <div class="modal-box" style="max-width:720px;">
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
                    <input type="text" name="title" id="remTitle" required maxlength="200" placeholder="Ex: Revizie Renault Master B 123 ABC">
                </div>

                <div class="full">
                    <label>Descriere</label>
                    <textarea name="description" id="remDescription" rows="3" placeholder="Detalii, locație, persoană contact, etc."></textarea>
                </div>

                <div>
                    <label>Data *</label>
                    <input type="date" name="remind_date" id="remDate" required>
                </div>

                <div>
                    <label>Ora (opțional)</label>
                    <input type="time" name="remind_time" id="remTime" step="900">
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
                    <label>Responsabil</label>
                    <select name="responsible_user_id" id="remResponsible">
                        <option value="">Neasignat</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= rem_h($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="full">
                    <div class="rem-recurrence-box" id="remRecurrenceBox">
                        <label style="margin:0;">Recurență</label>
                        <select name="recurrence_type" id="remRecurrenceType" onchange="remToggleRecurrence()" style="margin-top:6px;">
                            <?php foreach ($recurrenceTypes as $key => $label): ?>
                                <option value="<?= rem_h($key) ?>"><?= rem_h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="rem-recurrence-fields">
                            <div>
                                <label>La fiecare</label>
                                <input type="number" name="recurrence_interval" id="remRecurrenceInterval" value="1" min="1" max="365">
                            </div>
                            <div>
                                <label>Până la (opțional)</label>
                                <input type="date" name="recurrence_end_date" id="remRecurrenceEnd">
                            </div>
                        </div>
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
    remToggleRecurrence();
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
    document.getElementById('remTime').value = (d.remind_time || '').substring(0, 5);
    document.getElementById('remCategory').value = d.category || 'general';
    document.getElementById('remResponsible').value = d.responsible_user_id || '';
    document.getElementById('remRecurrenceType').value = d.recurrence_type || '';
    document.getElementById('remRecurrenceInterval').value = d.recurrence_interval || 1;
    document.getElementById('remRecurrenceEnd').value = d.recurrence_end_date || '';
    remToggleRecurrence();
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

function remToggleRecurrence() {
    const type = document.getElementById('remRecurrenceType').value;
    const box = document.getElementById('remRecurrenceBox');
    if (type) box.classList.add('is-active');
    else box.classList.remove('is-active');
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

<?php
// Preview live pentru bara „Caută reminder".
$previewReminders = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'reminders'")->fetch()) {
        $stmtPrev = $pdo->query("SELECT id, title FROM reminders ORDER BY id DESC LIMIT 2000");
        while ($r = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
            $t = html_entity_decode((string)($r['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $previewReminders[] = [
                'title'  => $t !== '' ? $t : ('Reminder #' . (int)$r['id']),
                'url'    => 'reminders.php?q=' . urlencode($t),
                'type'   => 'reminder',
                'search' => $t,
            ];
        }
    }
} catch (Throwable $e) { error_log('reminders.php preview: ' . $e->getMessage()); }
?>
<script>
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('remindersSearchInput',
            <?= json_encode($previewReminders, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
    };
    go();
})();
</script>
