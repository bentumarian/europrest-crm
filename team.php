<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();

if (!$isAdmin) {
    header("Location: calendar.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists_team(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");

    $stmt->execute([$table]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0) > 0;
}

function column_exists_team(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");

    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0) > 0;
}

function ensure_column_team(PDO $pdo, string $table, string $column, string $definition): void {
    if (!column_exists_team($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Exception $e) {
            // Nu blocam pagina daca acea coloana exista deja sau ALTER-ul nu poate rula.
        }
    }
}

function normalize_color_team(string $color): string {
    $color = trim($color);

    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return $color;
    }

    return '#163B63';
}

/*
|--------------------------------------------------------------------------
| Tabel echipe
|--------------------------------------------------------------------------
*/
$pdo->exec("
    CREATE TABLE IF NOT EXISTS team_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        phone VARCHAR(60) NULL,
        email VARCHAR(160) NULL,
        username VARCHAR(120) NULL,
        password_hash VARCHAR(255) NULL,
        color VARCHAR(20) NOT NULL DEFAULT '#163B63',
        active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

ensure_column_team($pdo, 'team_members', 'name', "VARCHAR(160) NOT NULL");
ensure_column_team($pdo, 'team_members', 'phone', "VARCHAR(60) NULL");
ensure_column_team($pdo, 'team_members', 'email', "VARCHAR(160) NULL");
ensure_column_team($pdo, 'team_members', 'username', "VARCHAR(120) NULL");
ensure_column_team($pdo, 'team_members', 'password_hash', "VARCHAR(255) NULL");
ensure_column_team($pdo, 'team_members', 'color', "VARCHAR(20) NOT NULL DEFAULT '#163B63'");
ensure_column_team($pdo, 'team_members', 'active', "TINYINT(1) NOT NULL DEFAULT 1");
ensure_column_team($pdo, 'team_members', 'notes', "TEXT NULL");
ensure_column_team($pdo, 'team_members', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

/*
|--------------------------------------------------------------------------
| Tabel programari, pentru verificare la stergere
|--------------------------------------------------------------------------
*/
$pdo->exec("
    CREATE TABLE IF NOT EXISTS appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NULL,
        team_member_id INT NULL,
        title VARCHAR(255) NULL,
        service_type VARCHAR(150) NULL,
        appointment_date DATE NOT NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'confirmata',
        address VARCHAR(255) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

/*
|--------------------------------------------------------------------------
| Echipe implicite
|--------------------------------------------------------------------------
*/
$countTeams = (int)($pdo->query("SELECT COUNT(*) AS total FROM team_members")->fetch()['total'] ?? 0);

if ($countTeams === 0) {
    $stmt = $pdo->prepare("
        INSERT INTO team_members (name, color, active)
        VALUES (?, ?, ?)
    ");

    $defaultTeams = [
        ['Echipa 1', '#163B63', 1],
        ['Echipa 2', '#315B7D', 1],
        ['Echipa 3', '#64748B', 1],
    ];

    foreach ($defaultTeams as $team) {
        $stmt->execute($team);
    }
}

/*
|--------------------------------------------------------------------------
| POST handler
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $color = normalize_color_team($_POST['color'] ?? '#163B63');
        $active = !empty($_POST['active']) ? 1 : 0;
        $notes = trim($_POST['notes'] ?? '');

        if ($name === '') {
            header("Location: team.php?error=1");
            exit;
        }

        if ($action === 'create') {
            $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;

            $stmt = $pdo->prepare("
                INSERT INTO team_members
                (name, phone, email, username, password_hash, color, active, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $name,
                $phone ?: null,
                $email ?: null,
                $username ?: null,
                $passwordHash,
                $color,
                $active,
                $notes ?: null
            ]);

            header("Location: team.php?success=1");
            exit;
        }

        if ($action === 'update' && $teamId > 0) {
            if ($password !== '') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    UPDATE team_members
                    SET name = ?,
                        phone = ?,
                        email = ?,
                        username = ?,
                        password_hash = ?,
                        color = ?,
                        active = ?,
                        notes = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $name,
                    $phone ?: null,
                    $email ?: null,
                    $username ?: null,
                    $passwordHash,
                    $color,
                    $active,
                    $notes ?: null,
                    $teamId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE team_members
                    SET name = ?,
                        phone = ?,
                        email = ?,
                        username = ?,
                        color = ?,
                        active = ?,
                        notes = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $name,
                    $phone ?: null,
                    $email ?: null,
                    $username ?: null,
                    $color,
                    $active,
                    $notes ?: null,
                    $teamId
                ]);
            }

            header("Location: team.php?updated=1");
            exit;
        }

        header("Location: team.php?error=1");
        exit;
    }

    if ($action === 'toggle') {
        $teamId = (int)($_POST['team_id'] ?? 0);

        if ($teamId > 0) {
            $stmt = $pdo->prepare("
                UPDATE team_members
                SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END
                WHERE id = ?
            ");
            $stmt->execute([$teamId]);

            header("Location: team.php?toggled=1");
            exit;
        }

        header("Location: team.php?error=1");
        exit;
    }

    if ($action === 'delete') {
        $teamId = (int)($_POST['team_id'] ?? 0);

        if ($teamId > 0) {
            $usedInAppointments = 0;

            if (table_exists_team($pdo, 'appointments')) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS total
                    FROM appointments
                    WHERE team_member_id = ?
                ");
                $stmt->execute([$teamId]);
                $usedInAppointments = (int)($stmt->fetch()['total'] ?? 0);
            }

            if ($usedInAppointments > 0) {
                $stmt = $pdo->prepare("
                    UPDATE team_members
                    SET active = 0
                    WHERE id = ?
                ");
                $stmt->execute([$teamId]);

                header("Location: team.php?delete_blocked=1");
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM team_members WHERE id = ?");
            $stmt->execute([$teamId]);

            header("Location: team.php?deleted=1");
            exit;
        }

        header("Location: team.php?error=1");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Query echipe
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT *
    FROM team_members
    ORDER BY active DESC, id ASC
");
$teams = $stmt->fetchAll();

$totalTeams = count($teams);
$activeTeams = 0;
$inactiveTeams = 0;

foreach ($teams as $team) {
    if ((int)$team['active'] === 1) {
        $activeTeams++;
    } else {
        $inactiveTeams++;
    }
}

$appointmentsByTeam = [];

if (table_exists_team($pdo, 'appointments')) {
    $stmt = $pdo->query("
        SELECT team_member_id, COUNT(*) AS total
        FROM appointments
        WHERE team_member_id IS NOT NULL
        GROUP BY team_member_id
    ");

    foreach ($stmt->fetchAll() as $row) {
        $appointmentsByTeam[(int)$row['team_member_id']] = (int)$row['total'];
    }
}

$todayAppointmentsByTeam = [];

if (table_exists_team($pdo, 'appointments')) {
    $stmt = $pdo->prepare("
        SELECT team_member_id, COUNT(*) AS total
        FROM appointments
        WHERE team_member_id IS NOT NULL
          AND appointment_date = ?
          AND status != 'anulata'
        GROUP BY team_member_id
    ");
    $stmt->execute([date('Y-m-d')]);

    foreach ($stmt->fetchAll() as $row) {
        $todayAppointmentsByTeam[(int)$row['team_member_id']] = (int)$row['total'];
    }
}

$teamsForJs = [];

foreach ($teams as $team) {
    $teamId = (int)$team['id'];

    $teamsForJs[$teamId] = [
        'id' => $teamId,
        'name' => $team['name'] ?? '',
        'phone' => $team['phone'] ?? '',
        'email' => $team['email'] ?? '',
        'username' => $team['username'] ?? '',
        'color' => $team['color'] ?? '#163B63',
        'active' => (int)($team['active'] ?? 1),
        'notes' => $team['notes'] ?? '',
        'appointments_count' => $appointmentsByTeam[$teamId] ?? 0,
        'today_count' => $todayAppointmentsByTeam[$teamId] ?? 0,
    ];
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Echipe · PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

<style>
.team-topbar {
    align-items: center;
    padding: 12px 20px;
}

.team-toolbar {
    width: 100%;
    min-width: 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}

.team-hero {
    background: linear-gradient(135deg, #10243e, #163b63);
    color: #fff;
    border-radius: var(--radius-lg);
    padding: 22px 24px;
    box-shadow: var(--shadow-lg);
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
    align-items: center;
}

.team-hero h1 {
    font-size: 24px;
    font-weight: 900;
    letter-spacing: -.03em;
    margin: 0;
}

.team-hero p {
    color: rgba(255,255,255,.72);
    margin-top: 4px;
    max-width: 780px;
}

.stats {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.stat-pill {
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 999px;
    padding: 8px 13px;
    color: #fff;
    font-weight: 900;
    font-size: 13px;
}

.team-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.team-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.team-card.inactive {
    opacity: .68;
}

.team-headline {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.team-main {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.team-dot {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    color: #fff;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.team-title {
    font-size: 17px;
    font-weight: 900;
    color: var(--text);
    line-height: 1.2;
}

.team-sub {
    margin-top: 3px;
    color: var(--muted);
    font-size: 13px;
}

.team-meta {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.team-pill {
    background: var(--surface-soft);
    border: 1px solid var(--border2);
    border-radius: 999px;
    padding: 6px 10px;
    color: var(--muted);
    font-weight: 900;
    font-size: 12px;
}

.team-pill.active {
    background: rgba(22, 59, 99, .08);
    border-color: rgba(22, 59, 99, .18);
    color: var(--text);
}

.team-actions {
    margin-top: auto;
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.team-actions .btn {
    flex: 1 1 auto;
}

.empty-state {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 34px;
    text-align: center;
    color: var(--muted);
    font-weight: 800;
}

.details-grid {
    display: grid;
    gap: 10px;
    margin-bottom: 16px;
}

.details-row {
    display: grid;
    grid-template-columns: 135px 1fr;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border2);
}

.details-row:last-child {
    border-bottom: none;
}

.details-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.details-value {
    color: var(--text);
    font-weight: 700;
}

@media(max-width: 1100px) {
    .team-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 860px) {
    .team-topbar {
        padding: 8px 10px;
    }

    .team-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .team-toolbar .btn {
        width: 100%;
        height: 42px;
    }

    .team-hero {
        padding: 18px;
    }

    .team-grid {
        grid-template-columns: 1fr;
    }
}

@media(max-width: 620px) {
    .details-row {
        grid-template-columns: 1fr;
        gap: 3px;
    }
}
</style>
</head>

<body>
<div class="layout">

    <?php render_sidebar('team', $isAdmin); ?>

    <main class="main">

        <div class="topbar team-topbar">
            <div class="team-toolbar">
                <button class="btn accent" type="button" onclick="openCreateTeamModal()">
                    + Echipa noua
                </button>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="notice notice-success">Echipa a fost adaugata.</div>
        <?php endif; ?>

        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success">Echipa a fost actualizata.</div>
        <?php endif; ?>

        <?php if (isset($_GET['toggled'])): ?>
            <div class="notice notice-success">Statusul echipei a fost schimbat.</div>
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
            <div class="notice notice-warning">Echipa a fost stearsa.</div>
        <?php endif; ?>

        <?php if (isset($_GET['delete_blocked'])): ?>
            <div class="notice notice-warning">Echipa are programari asociate si a fost dezactivata in loc sa fie stearsa.</div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notice notice-danger">Completeaza numele echipei.</div>
        <?php endif; ?>

        <div class="content">

            <section class="team-hero">
                <div>
                    <h1>Echipe</h1>
                    <p>
                        Gestioneaza echipele de teren, culorile din calendar si accesul operatorilor.
                    </p>
                </div>

                <div class="stats">
                    <span class="stat-pill"><?= (int)$totalTeams ?> total</span>
                    <span class="stat-pill"><?= (int)$activeTeams ?> active</span>
                    <span class="stat-pill"><?= (int)$inactiveTeams ?> inactive</span>
                </div>
            </section>

            <?php if (!$teams): ?>
                <div class="empty-state">
                    Nu exista echipe definite.
                </div>
            <?php else: ?>
                <section class="team-grid">
                    <?php foreach ($teams as $team): ?>
                        <?php
                            $teamId = (int)$team['id'];
                            $isActive = (int)$team['active'] === 1;
                            $color = $team['color'] ?: '#163B63';
                            $todayCount = $todayAppointmentsByTeam[$teamId] ?? 0;
                            $appointmentsCount = $appointmentsByTeam[$teamId] ?? 0;
                            $initial = strtoupper(substr((string)$team['name'], 0, 1));
                        ?>

                        <article class="team-card <?= $isActive ? '' : 'inactive' ?>">
                            <div class="team-headline">
                                <div class="team-main">
                                    <div class="team-dot" style="background:<?= h($color) ?>">
                                        <?= h($initial) ?>
                                    </div>

                                    <div>
                                        <div class="team-title"><?= h($team['name']) ?></div>
                                        <div class="team-sub">
                                            <?= !empty($team['phone']) ? h($team['phone']) : 'Telefon lipsa' ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="team-meta">
                                <span class="team-pill active">
                                    <?= $isActive ? 'Activa' : 'Inactiva' ?>
                                </span>

                                <span class="team-pill">
                                    <?= (int)$todayCount ?> azi
                                </span>

                                <span class="team-pill">
                                    <?= (int)$appointmentsCount ?> total
                                </span>
                            </div>

                            <?php if (!empty($team['notes'])): ?>
                                <div class="team-sub">
                                    <?= h($team['notes']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="team-actions">
                                <button class="btn" type="button" onclick="openTeamDetails(<?= $teamId ?>)">
                                    Detalii
                                </button>

                                <button class="btn" type="button" onclick="openEditTeamModal(<?= $teamId ?>)">
                                    Editeaza
                                </button>

                                <form method="post" style="display:inline-flex;flex:1;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="team_id" value="<?= $teamId ?>">
                                    <button class="btn" type="submit">
                                        <?= $isActive ? 'Dezactiveaza' : 'Activeaza' ?>
                                    </button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

        </div>
    </main>
</div>

<div class="modal" id="createTeamModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Echipa noua</h2>
            <button class="modal-close" type="button" onclick="closeModal('createTeamModal')">&times;</button>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="form-grid">
                <div>
                    <label>Nume echipa *</label>
                    <input type="text" name="name" required placeholder="Ex: Echipa 1">
                </div>

                <div>
                    <label>Telefon</label>
                    <input type="text" name="phone" placeholder="07xx xxx xxx">
                </div>

                <div>
                    <label>Email</label>
                    <input type="email" name="email" placeholder="echipa@email.ro">
                </div>

                <div>
                    <label>Utilizator login</label>
                    <input type="text" name="username" placeholder="ex: echipa1">
                </div>

                <div>
                    <label>Parola login</label>
                    <input type="password" name="password" placeholder="Optional">
                </div>

                <div>
                    <label>Culoare calendar</label>
                    <input type="color" name="color" value="#163B63">
                </div>

                <div>
                    <label>Status</label>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:900;">
                        <input type="checkbox" name="active" value="1" checked>
                        Activa
                    </label>
                </div>

                <div class="form-group full">
                    <label>Observatii</label>
                    <textarea name="notes" placeholder="Detalii interne despre echipa..."></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('createTeamModal')">Renunta</button>
                    <button class="btn accent" type="submit">Salveaza echipa</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="editTeamModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Editeaza echipa</h2>
            <button class="modal-close" type="button" onclick="closeModal('editTeamModal')">&times;</button>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="team_id" id="edit_team_id">

            <div class="form-grid">
                <div>
                    <label>Nume echipa *</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>

                <div>
                    <label>Telefon</label>
                    <input type="text" name="phone" id="edit_phone">
                </div>

                <div>
                    <label>Email</label>
                    <input type="email" name="email" id="edit_email">
                </div>

                <div>
                    <label>Utilizator login</label>
                    <input type="text" name="username" id="edit_username">
                </div>

                <div>
                    <label>Parola noua</label>
                    <input type="password" name="password" placeholder="Lasa gol daca nu schimbi parola">
                </div>

                <div>
                    <label>Culoare calendar</label>
                    <input type="color" name="color" id="edit_color" value="#163B63">
                </div>

                <div>
                    <label>Status</label>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:900;">
                        <input type="checkbox" name="active" value="1" id="edit_active">
                        Activa
                    </label>
                </div>

                <div class="form-group full">
                    <label>Observatii</label>
                    <textarea name="notes" id="edit_notes"></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div class="actions-left">
                    <button class="btn danger" type="button" onclick="deleteCurrentTeam()">Sterge</button>
                </div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('editTeamModal')">Renunta</button>
                    <button class="btn accent" type="submit">Salveaza modificarile</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="teamDetailsModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Detalii echipa</h2>
            <button class="modal-close" type="button" onclick="closeModal('teamDetailsModal')">&times;</button>
        </div>

        <div class="details-grid" id="teamDetailsContent"></div>

        <div class="actions-row">
            <div></div>

            <div class="actions-right">
                <button class="btn" type="button" onclick="openEditTeamFromDetails()">Editeaza</button>
                <a class="btn accent" id="detailsCalendarLink" href="#">Vezi calendar</a>
            </div>
        </div>
    </div>
</div>

<form method="post" id="deleteTeamForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="team_id" id="delete_team_id">
</form>

<script>
const teamsData = <?= json_encode($teamsForJs, JSON_UNESCAPED_UNICODE) ?>;
let currentTeamId = null;

function openModal(id) {
    document.getElementById(id)?.classList.add('open');
}

function closeModal(id) {
    document.getElementById(id)?.classList.remove('open');
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function openCreateTeamModal() {
    openModal('createTeamModal');
}

function openEditTeamModal(id) {
    const team = teamsData[id];

    if (!team) {
        alert('Echipa nu a fost gasita.');
        return;
    }

    currentTeamId = id;

    document.getElementById('edit_team_id').value = team.id || '';
    document.getElementById('edit_name').value = team.name || '';
    document.getElementById('edit_phone').value = team.phone || '';
    document.getElementById('edit_email').value = team.email || '';
    document.getElementById('edit_username').value = team.username || '';
    document.getElementById('edit_color').value = team.color || '#163B63';
    document.getElementById('edit_active').checked = Number(team.active) === 1;
    document.getElementById('edit_notes').value = team.notes || '';

    openModal('editTeamModal');
}

function openTeamDetails(id) {
    const team = teamsData[id];

    if (!team) {
        alert('Echipa nu a fost gasita.');
        return;
    }

    currentTeamId = id;

    document.getElementById('detailsCalendarLink').href = 'calendar.php?team=' + encodeURIComponent(id);

    document.getElementById('teamDetailsContent').innerHTML = `
        <div class="details-row">
            <div class="details-label">Echipa</div>
            <div class="details-value">${escHtml(team.name || '-')}</div>
        </div>

        <div class="details-row">
            <div class="details-label">Telefon</div>
            <div class="details-value">${escHtml(team.phone || '-')}</div>
        </div>

        <div class="details-row">
            <div class="details-label">Email</div>
            <div class="details-value">${escHtml(team.email || '-')}</div>
        </div>

        <div class="details-row">
            <div class="details-label">Utilizator</div>
            <div class="details-value">${escHtml(team.username || '-')}</div>
        </div>

        <div class="details-row">
            <div class="details-label">Status</div>
            <div class="details-value">${Number(team.active) === 1 ? 'Activa' : 'Inactiva'}</div>
        </div>

        <div class="details-row">
            <div class="details-label">Programari azi</div>
            <div class="details-value">${escHtml(team.today_count || '0')}</div>
        </div>

        <div class="details-row">
            <div class="details-label">Programari total</div>
            <div class="details-value">${escHtml(team.appointments_count || '0')}</div>
        </div>

        <div class="details-row">
            <div class="details-label">Observatii</div>
            <div class="details-value">${escHtml(team.notes || '-').replace(/\n/g, '<br>')}</div>
        </div>
    `;

    openModal('teamDetailsModal');
}

function openEditTeamFromDetails() {
    if (!currentTeamId) {
        return;
    }

    closeModal('teamDetailsModal');
    openEditTeamModal(currentTeamId);
}

function deleteCurrentTeam() {
    if (!currentTeamId) {
        return;
    }

    if (confirm('Sigur vrei sa stergi aceasta echipa? Daca are programari, va fi dezactivata in loc sa fie stearsa.')) {
        document.getElementById('delete_team_id').value = currentTeamId;
        document.getElementById('deleteTeamForm').submit();
    }
}

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', event => {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.open').forEach(modal => modal.classList.remove('open'));
    }
});
</script>
</body>
</html>
