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
// h() este definit global în app_helpers.php (inclus prin app_ui.php).

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
            // Nu blocam pagina dacă acea coloana există deja sau ALTER-ul nu poate rula.
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
| Tabel tehnicieni
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
| Tabel programări, pentru verificare la stergere
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
| Tehnicieni impliciti
|--------------------------------------------------------------------------
*/
$countTeams = (int)($pdo->query("SELECT COUNT(*) AS total FROM team_members")->fetch()['total'] ?? 0);

if ($countTeams === 0) {
    $stmt = $pdo->prepare("
        INSERT INTO team_members (name, color, active)
        VALUES (?, ?, ?)
    ");

    $defaultTeams = [
        ['Tehnician 1', '#163B63', 1],
        ['Tehnician 2', '#315B7D', 1],
        ['Tehnician 3', '#64748B', 1],
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
| Query tehnicieni
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
<title>Tehnicieni · <?= h(pz_app_name()) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

</head>

<body>
<div class="layout">

    <?php render_sidebar('team', $isAdmin); ?>

    <main class="main">

<?php /* Topbar vechi eliminat — înlocuit cu pz_page_header mai jos. */ ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="notice notice-success">Tehnicianul a fost adaugat.</div>
        <?php endif; ?>

        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success">Tehnicianul a fost actualizat.</div>
        <?php endif; ?>

        <?php if (isset($_GET['toggled'])): ?>
            <div class="notice notice-success">Statusul tehnicianului a fost schimbat.</div>
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
            <div class="notice notice-warning">Tehnicianul a fost șters.</div>
        <?php endif; ?>

        <?php if (isset($_GET['delete_blocked'])): ?>
            <div class="notice notice-warning">Tehnicianul are programări asociate si a fost dezactivat in loc sa fie șters.</div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notice notice-danger">Completează numele tehnicianului.</div>
        <?php endif; ?>

        <div class="content">

            <?php pz_page_header([
                'back'     => ['href' => 'settings.php', 'label' => 'Înapoi la setări'],
                'kicker'   => 'Setări · Operațional',
                'title'    => 'Tehnicieni',
                'subtitle' => 'Gestionează tehnicienii, culorile din calendar și accesul operatorilor.',
                'actions'  => [[
                    'label'   => 'Tehnician nou',
                    'icon'    => 'ti-plus',
                    'variant' => 'primary',
                    'type'    => 'button',
                    'onclick' => 'openCreateTeamModal()',
                ]],
                'kpis'     => [
                    ['label' => 'Total',    'value' => (int)$totalTeams],
                    ['label' => 'Active',   'value' => (int)$activeTeams,   'tone' => 'success'],
                    ['label' => 'Inactive', 'value' => (int)$inactiveTeams, 'tone' => 'warning'],
                ],
            ]); ?>

            <?php if (!$teams): ?>
                <div class="empty-state">
                    Nu există tehnicieni definiti.
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
                                    Editează
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
            <h2>Tehnician nou</h2>
            <button class="modal-close" type="button" onclick="closeModal('createTeamModal')">&times;</button>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="form-grid">
                <div>
                    <label>Nume tehnician *</label>
                    <input type="text" name="name" required placeholder="Ex: Alex">
                </div>

                <div>
                    <label>Telefon</label>
                    <input type="text" name="phone" placeholder="07xx xxx xxx">
                </div>

                <div>
                    <label>Email</label>
                    <input type="email" name="email" placeholder="tehnician@email.ro">
                </div>

                <div>
                    <label>Utilizator login</label>
                    <input type="text" name="username" placeholder="ex: alex">
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
                    <label>Observații</label>
                    <textarea name="notes" placeholder="Detalii interne despre tehnician..."></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('createTeamModal')">Renunță</button>
                    <button class="btn accent" type="submit">Salvează tehnicianul</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="editTeamModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Editează tehnician</h2>
            <button class="modal-close" type="button" onclick="closeModal('editTeamModal')">&times;</button>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="team_id" id="edit_team_id">

            <div class="form-grid">
                <div>
                    <label>Nume tehnician *</label>
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
                    <input type="password" name="password" placeholder="Lasa gol dacă nu schimbi parola">
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
                    <label>Observații</label>
                    <textarea name="notes" id="edit_notes"></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div class="actions-left">
                    <button class="btn danger" type="button" onclick="deleteCurrentTeam()">Șterge</button>
                </div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('editTeamModal')">Renunță</button>
                    <button class="btn accent" type="submit">Salvează modificarile</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="teamDetailsModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Detalii tehnician</h2>
            <button class="modal-close" type="button" onclick="closeModal('teamDetailsModal')">&times;</button>
        </div>

        <div class="details-grid" id="teamDetailsContent"></div>

        <div class="actions-row">
            <div></div>

            <div class="actions-right">
                <button class="btn" type="button" onclick="openEditTeamFromDetails()">Editează</button>
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
        alert('Tehnicianul nu a fost gasit.');
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
        alert('Tehnicianul nu a fost gasit.');
        return;
    }

    currentTeamId = id;

    document.getElementById('detailsCalendarLink').href = 'calendar.php?team=' + encodeURIComponent(id);

    document.getElementById('teamDetailsContent').innerHTML = `
        <div class="details-row">
            <div class="details-label">Tehnician</div>
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
            <div class="details-label">Programări azi</div>
            <div class="details-value">${escHtml(team.today_count || '0')}</div>
        </div>

        <div class="details-row">
            <div class="details-label">Programări total</div>
            <div class="details-value">${escHtml(team.appointments_count || '0')}</div>
        </div>

        <div class="details-row">
            <div class="details-label">Observații</div>
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

    if (confirm('Sigur vrei sa stergi acest tehnician? Dacă are programări, va fi dezactivat in loc sa fie șters.')) {
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
