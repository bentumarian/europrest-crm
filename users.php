<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';

// Strict admin: doar admin-ul gestionează utilizatorii.
if (!is_admin()) {
    header('Location: calendar.php');
    exit;
}

function us_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Asigură schema users + populează default 'admin' pentru rândurile existente.
 */
function us_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NULL,
        email VARCHAR(160) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(40) NOT NULL DEFAULT 'admin',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'name'       => 'VARCHAR(160) NULL',
        'email'      => 'VARCHAR(160) NULL',
        'password'   => 'VARCHAR(255) NULL',
        'role'       => "VARCHAR(40) NOT NULL DEFAULT 'admin'",
        'active'     => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ];
    foreach ($columns as $c => $d) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME=?");
            $st->execute([$c]);
            if ((int)$st->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN {$c} {$d}");
            }
        } catch (Throwable $e) {
            error_log('us_ensure_schema: ' . $e->getMessage());
        }
    }
}

/**
 * Valori canonice + etichete UI pentru roluri.
 */
function us_role_options(): array {
    return [
        'admin'  => ['label' => 'Administrator', 'desc' => 'Acces complet, inclusiv setări'],
        'office' => ['label' => 'Birou',         'desc' => 'Operare platformă, fără setări'],
    ];
}

function us_role_label(string $role): string {
    $opts = us_role_options();
    return $opts[$role]['label'] ?? 'Administrator';
}

function us_role_badge_class(string $role): string {
    return $role === 'office' ? 'pz-badge-role office' : 'pz-badge-role admin';
}

us_ensure_schema($pdo);

$error = '';
$success = '';
$currentUserId = function_exists('current_user_id') ? (int)(current_user_id() ?? 0) : 0;
$allowedRoles = array_keys(us_role_options());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user' || $action === 'update_user') {
        $id       = (int)($_POST['user_id'] ?? 0);
        $name     = trim((string)($_POST['name'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $active   = !empty($_POST['active']) ? 1 : 0;
        $role     = strtolower(trim((string)($_POST['role'] ?? 'admin')));
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'admin';
        }

        if ($name === '' || $email === '') {
            $error = 'Completează numele și emailul.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Emailul nu este valid.';
        } elseif ($action === 'create_user' && trim($password) === '') {
            $error = 'Completează parola.';
        } elseif ($action === 'update_user' && $id === $currentUserId && $active === 0) {
            $error = 'Nu îți poți dezactiva propriul cont.';
        } elseif ($action === 'update_user' && $id === $currentUserId && $role !== 'admin') {
            // Protecție: utilizatorul curent (admin) nu se poate transforma singur în office.
            $error = 'Nu îți poți schimba propriul rol din administrator.';
        } else {
            try {
                if ($action === 'create_user') {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?");
                    $st->execute([$email]);
                    if ((int)$st->fetchColumn() > 0) {
                        $error = 'Există deja un utilizator cu acest email.';
                    } else {
                        $st = $pdo->prepare("INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, ?, ?)");
                        $st->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
                        $success = 'Utilizatorul a fost adăugat.';
                    }
                } else {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=? AND id<>?");
                    $st->execute([$email, $id]);
                    if ((int)$st->fetchColumn() > 0) {
                        $error = 'Există deja alt utilizator cu acest email.';
                    } else {
                        if (trim($password) !== '') {
                            $st = $pdo->prepare("UPDATE users SET name=?, email=?, password=?, role=?, active=? WHERE id=?");
                            $st->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $active, $id]);
                        } else {
                            $st = $pdo->prepare("UPDATE users SET name=?, email=?, role=?, active=? WHERE id=?");
                            $st->execute([$name, $email, $role, $active, $id]);
                        }
                        $success = 'Utilizatorul a fost actualizat.';
                    }
                }
            } catch (Throwable $e) {
                error_log('PestZone users save: ' . $e->getMessage());
                $error = 'Utilizatorul nu a putut fi salvat.';
            }
        }
    }

    if ($action === 'delete_user') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id === $currentUserId) {
            $error = 'Nu îți poți șterge propriul cont.';
        } elseif ($id > 0) {
            try {
                $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
                $success = 'Utilizatorul a fost șters.';
            } catch (Throwable $e) {
                error_log('PestZone users delete: ' . $e->getMessage());
                $error = 'Utilizatorul nu a putut fi șters.';
            }
        }
    }
}

$users = $pdo->query("
    SELECT id, name, email, role, active, created_at
    FROM users
    ORDER BY active DESC, role ASC, name ASC, email ASC
")->fetchAll(PDO::FETCH_ASSOC);

$totalUsers   = count($users);
$activeUsers  = 0;
$adminCount   = 0;
$officeCount  = 0;
foreach ($users as $u) {
    if ((int)$u['active'] === 1) $activeUsers++;
    $r = strtolower(trim((string)($u['role'] ?? '')));
    if ($r === 'office') $officeCount++;
    else $adminCount++; // include și valori null/legacy
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Utilizatori · PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css" rel="stylesheet">
<?php app_theme_css(); ?>
<style>
/* PZ Users — design consistent cu dashboard (--pz-* tokens) */
.pz-users {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--pz-text);
    background: var(--pz-bg);
    padding: 18px 20px;
    display: grid;
    gap: 14px;
    max-width: 1280px;
    margin: 0 auto;
    width: 100%;
    min-width: 0;
}
.pz-users * { box-sizing: border-box; }
.pz-users a { text-decoration: none; color: inherit; }

/* Header */
.pz-users-head {
    display: flex; align-items: flex-end; justify-content: space-between;
    flex-wrap: wrap; gap: 14px;
}
.pz-users-head .back {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; color: var(--pz-mu);
    padding: 4px 9px; border-radius: var(--pz-rs);
    transition: color .15s, background .15s;
}
.pz-users-head .back:hover { background: var(--pz-soft); color: var(--pz-title); }
.pz-users-head .back i { font-size: 13px; }
.pz-users-head .pz-title { font-size: 22px; font-weight: 500; color: var(--pz-title); margin: 6px 0 0; letter-spacing: -.005em; }
.pz-users-head .pz-sub { font-size: 13px; color: var(--pz-mu); margin: 4px 0 0; }

/* KPI row */
.pz-users-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}
.pz-stat {
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    padding: 12px 14px;
    display: flex; align-items: center; gap: 12px;
}
.pz-stat .ico {
    width: 36px; height: 36px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.pz-stat .ico i { font-size: 18px; }
.pz-stat.users  .ico { background: var(--pz-bls); color: var(--pz-bld); }
.pz-stat.admins .ico { background: var(--pz-grs); color: var(--pz-gr); }
.pz-stat.office .ico { background: var(--pz-ors); color: var(--pz-or); }
.pz-stat .body { min-width: 0; }
.pz-stat .label { font-size: 11px; color: var(--pz-mu); margin: 0; }
.pz-stat .value { font-size: 19px; font-weight: 500; color: var(--pz-title); margin: 1px 0 0; line-height: 1.2; font-variant-numeric: tabular-nums; }
.pz-stat .meta { font-size: 11px; color: var(--pz-fa); }

/* Notice (success/error) */
.pz-notice {
    border-radius: var(--pz-r);
    padding: 10px 13px;
    font-size: 13px;
    font-weight: 500;
    display: flex; align-items: center; gap: 8px;
}
.pz-notice i { font-size: 16px; }
.pz-notice.ok  { background: var(--pz-grs); color: var(--pz-gr); border: 1px solid var(--pz-grb); }
.pz-notice.err { background: var(--pz-res); color: var(--pz-re); border: 1px solid var(--pz-reb); }

/* Layout: tabel + formular side-by-side */
.pz-users-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    gap: 10px;
}
.pz-users-grid > * { min-width: 0; }

/* Card */
.pz-card {
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    padding: 14px 16px;
}
.pz-card-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 12px; gap: 10px;
}
.pz-card-head .pz-card-title-sm { font-size: 11.5px; color: var(--pz-mu); margin: 0; }
.pz-card-head .pz-card-title { font-size: 15px; font-weight: 500; color: var(--pz-title); margin: 2px 0 0; }

/* Tabel */
.pz-table-wrap {
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    overflow: auto;
}
.pz-table { width: 100%; border-collapse: collapse; min-width: 640px; }
.pz-table th {
    background: var(--pz-soft);
    color: var(--pz-mu);
    text-align: left;
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-weight: 500;
    padding: 9px 12px;
    border-bottom: 1px solid var(--pz-line);
}
.pz-table td {
    padding: 10px 12px;
    font-size: 13px;
    color: var(--pz-text);
    border-top: 1px solid var(--pz-lines);
    vertical-align: middle;
}
.pz-table tbody tr:hover { background: var(--pz-soft); }
.pz-table .name-cell strong { font-weight: 500; color: var(--pz-title); }
.pz-table .name-cell .sub { font-size: 11px; color: var(--pz-fa); }
.pz-table .email-cell { color: var(--pz-mu); }
.pz-table .date-cell { color: var(--pz-fa); font-size: 11.5px; }

/* Badge-uri rol + status */
.pz-badge {
    display: inline-flex; align-items: center; gap: 4px;
    border-radius: 999px;
    padding: 2px 9px;
    font-size: 11px;
    font-weight: 500;
}
.pz-badge i { font-size: 11px; }
.pz-badge-role.admin  { color: var(--pz-bld); background: var(--pz-bls); }
.pz-badge-role.office { color: var(--pz-or);  background: var(--pz-ors); }
.pz-badge-status.active   { color: var(--pz-gr); background: var(--pz-grs); }
.pz-badge-status.inactive { color: var(--pz-mu); background: var(--pz-soft); }

/* Actions */
.row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.pz-btn-mini {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-rs);
    padding: 5px 10px;
    font-size: 11.5px;
    font-weight: 500;
    color: var(--pz-text);
    cursor: pointer;
    font-family: inherit;
    transition: all .15s;
}
.pz-btn-mini i { font-size: 12px; }
.pz-btn-mini:hover { border-color: var(--pz-blb); color: var(--pz-bl); background: var(--pz-bls); }
.pz-btn-mini.danger { color: var(--pz-re); border-color: var(--pz-reb); }
.pz-btn-mini.danger:hover { background: var(--pz-res); border-color: var(--pz-re); }

/* Form */
.pz-form { display: flex; flex-direction: column; gap: 12px; }
.pz-form label {
    font-size: 10.5px;
    font-weight: 500;
    color: var(--pz-mu);
    text-transform: uppercase;
    letter-spacing: .04em;
    display: block;
    margin-bottom: 4px;
}
.pz-form input[type="text"],
.pz-form input[type="email"],
.pz-form input[type="password"],
.pz-form select {
    width: 100%;
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-rs);
    height: 36px;
    padding: 7px 11px;
    font-size: 13px;
    font-weight: 400;
    color: var(--pz-title);
    background: var(--pz-surf);
    font-family: inherit;
    transition: border-color .15s, box-shadow .15s;
}
.pz-form input:focus,
.pz-form select:focus {
    border-color: var(--pz-bl);
    outline: none;
    box-shadow: 0 0 0 3px var(--pz-bls);
}
.pz-form .hint { font-size: 11px; color: var(--pz-fa); margin-top: 3px; display: block; }

.pz-form .role-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.pz-form .role-card {
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-rs);
    padding: 10px 11px;
    cursor: pointer;
    transition: all .15s;
    background: var(--pz-surf);
}
.pz-form .role-card:hover { border-color: var(--pz-blb); background: var(--pz-bls); }
.pz-form .role-card.selected {
    border-color: var(--pz-bl);
    background: var(--pz-bls);
    box-shadow: 0 0 0 2px var(--pz-bls);
}
.pz-form .role-card input[type="radio"] { display: none; }
.pz-form .role-card .role-name { font-size: 12.5px; font-weight: 500; color: var(--pz-title); }
.pz-form .role-card .role-desc { font-size: 10.5px; color: var(--pz-mu); margin-top: 2px; line-height: 1.3; }

.pz-form .check {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px;
    color: var(--pz-text);
    font-weight: 400;
    cursor: pointer;
}
.pz-form .check input[type="checkbox"] {
    width: 16px; height: 16px;
    accent-color: var(--pz-bl);
    cursor: pointer;
}

.pz-form-actions { display: flex; gap: 8px; flex-wrap: wrap; padding-top: 4px; }
.pz-btn-primary, .pz-btn-ghost {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 500;
    font-family: inherit;
    border-radius: var(--pz-rs);
    cursor: pointer;
    transition: all .15s;
}
.pz-btn-primary {
    background: var(--pz-bl);
    color: #fff;
    border: 1px solid var(--pz-bl);
}
.pz-btn-primary:hover { background: var(--pz-bld); border-color: var(--pz-bld); }
.pz-btn-ghost {
    background: var(--pz-surf);
    color: var(--pz-text);
    border: 1px solid var(--pz-line);
}
.pz-btn-ghost:hover { background: var(--pz-soft); color: var(--pz-title); }

/* Empty state */
.pz-empty {
    text-align: center;
    padding: 28px 14px;
    color: var(--pz-fa);
    font-size: 13px;
}

/* Responsive */
@media (max-width: 900px) {
    .pz-users-grid { grid-template-columns: minmax(0, 1fr); }
    .pz-users-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
}
@media (max-width: 640px) {
    .pz-users { padding: 14px 12px; gap: 12px; }
    .pz-users-head .pz-title { font-size: 19px; }
    .pz-stat { padding: 10px 12px; gap: 10px; }
    .pz-stat .ico { width: 30px; height: 30px; }
    .pz-stat .ico i { font-size: 15px; }
    .pz-stat .value { font-size: 17px; }
    .pz-table th, .pz-table td { padding: 8px 10px; font-size: 12px; }
    .pz-form .role-options { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .pz-users-stats { grid-template-columns: minmax(0, 1fr); }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('users', is_admin()); ?>
    <main class="main">
        <div class="content pz-users">

            <!-- Header -->
            <div class="pz-users-head">
                <div>
                    <a href="settings.php" class="back">
                        <i class="ti ti-arrow-left" aria-hidden="true"></i>Înapoi la setări
                    </a>
                    <h2 class="pz-title">Utilizatori</h2>
                    <p class="pz-sub">Administratori și operatori de birou. Tehnicienii se gestionează din modulul Tehnicieni.</p>
                </div>
            </div>

            <!-- KPI stats -->
            <div class="pz-users-stats">
                <div class="pz-stat users">
                    <div class="ico"><i class="ti ti-users" aria-hidden="true"></i></div>
                    <div class="body">
                        <p class="label">Total utilizatori</p>
                        <p class="value"><?= (int)$totalUsers ?></p>
                        <p class="meta"><?= (int)$activeUsers ?> activi · <?= (int)($totalUsers - $activeUsers) ?> inactivi</p>
                    </div>
                </div>
                <div class="pz-stat admins">
                    <div class="ico"><i class="ti ti-shield-check" aria-hidden="true"></i></div>
                    <div class="body">
                        <p class="label">Administratori</p>
                        <p class="value"><?= (int)$adminCount ?></p>
                        <p class="meta">Acces complet</p>
                    </div>
                </div>
                <div class="pz-stat office">
                    <div class="ico"><i class="ti ti-briefcase" aria-hidden="true"></i></div>
                    <div class="body">
                        <p class="label">Birou (office)</p>
                        <p class="value"><?= (int)$officeCount ?></p>
                        <p class="meta">Operare, fără setări</p>
                    </div>
                </div>
            </div>

            <!-- Notice messages -->
            <?php if ($success): ?>
                <div class="pz-notice ok">
                    <i class="ti ti-circle-check" aria-hidden="true"></i><?= us_h($success) ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="pz-notice err">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i><?= us_h($error) ?>
                </div>
            <?php endif; ?>

            <!-- Grid: lista + form -->
            <div class="pz-users-grid">

                <!-- Lista utilizatori -->
                <section class="pz-card">
                    <div class="pz-card-head">
                        <div>
                            <p class="pz-card-title-sm">Lista</p>
                            <p class="pz-card-title">Toți utilizatorii</p>
                        </div>
                    </div>
                    <div class="pz-table-wrap">
                        <table class="pz-table">
                            <thead>
                                <tr>
                                    <th>Nume</th>
                                    <th>Email</th>
                                    <th>Rol</th>
                                    <th>Status</th>
                                    <th>Creat</th>
                                    <th style="text-align: right;">Acțiuni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="6" class="pz-empty">Niciun utilizator încă.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u):
                                        $uId     = (int)$u['id'];
                                        $uRole   = strtolower(trim((string)($u['role'] ?? 'admin')));
                                        if (!in_array($uRole, $allowedRoles, true)) $uRole = 'admin';
                                        $uActive = (int)$u['active'];
                                        $isSelf  = ($uId === $currentUserId);
                                        $created = (string)($u['created_at'] ?? '');
                                        $createdShort = $created !== '' ? substr($created, 0, 10) : '—';
                                    ?>
                                        <tr>
                                            <td class="name-cell">
                                                <strong><?= us_h($u['name'] ?: 'Fără nume') ?></strong>
                                                <?php if ($isSelf): ?>
                                                    <div class="sub">contul tău</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="email-cell"><?= us_h($u['email']) ?></td>
                                            <td>
                                                <span class="pz-badge <?= us_role_badge_class($uRole) ?>">
                                                    <i class="ti <?= $uRole === 'office' ? 'ti-briefcase' : 'ti-shield-check' ?>" aria-hidden="true"></i>
                                                    <?= us_h(us_role_label($uRole)) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($uActive): ?>
                                                    <span class="pz-badge pz-badge-status active"><i class="ti ti-circle-check" aria-hidden="true"></i>Activ</span>
                                                <?php else: ?>
                                                    <span class="pz-badge pz-badge-status inactive"><i class="ti ti-circle-minus" aria-hidden="true"></i>Inactiv</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="date-cell"><?= us_h($createdShort) ?></td>
                                            <td style="text-align: right;">
                                                <div class="row-actions" style="justify-content: flex-end;">
                                                    <button class="pz-btn-mini" type="button" onclick='editUser(<?= json_encode([
                                                        "id"     => $uId,
                                                        "name"   => (string)($u["name"] ?? ""),
                                                        "email"  => (string)$u["email"],
                                                        "role"   => $uRole,
                                                        "active" => $uActive,
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                                                        <i class="ti ti-pencil" aria-hidden="true"></i>Editează
                                                    </button>
                                                    <?php if (!$isSelf): ?>
                                                        <form method="post" style="margin: 0;" onsubmit="return confirm('Ștergi acest utilizator? Acțiunea este definitivă.');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                            <button class="pz-btn-mini danger" type="submit">
                                                                <i class="ti ti-trash" aria-hidden="true"></i>Șterge
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Form adăugare/editare -->
                <aside class="pz-card">
                    <div class="pz-card-head">
                        <div>
                            <p class="pz-card-title-sm" id="formKicker">Nou</p>
                            <p class="pz-card-title" id="formTitle">Adaugă utilizator</p>
                        </div>
                    </div>
                    <form method="post" class="pz-form" id="userForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" id="action" value="create_user">
                        <input type="hidden" name="user_id" id="user_id" value="0">

                        <div>
                            <label for="name">Nume</label>
                            <input type="text" name="name" id="name" required autocomplete="name">
                        </div>

                        <div>
                            <label for="email">Email</label>
                            <input type="email" name="email" id="email" required autocomplete="email">
                        </div>

                        <div>
                            <label for="password">Parolă</label>
                            <input type="password" name="password" id="password" autocomplete="new-password">
                            <span class="hint">La editare, lasă gol dacă nu schimbi parola.</span>
                        </div>

                        <div>
                            <label>Rol</label>
                            <div class="role-options">
                                <label class="role-card" id="roleCardAdmin" onclick="selectRole('admin')">
                                    <input type="radio" name="role" value="admin" id="role_admin" checked>
                                    <div class="role-name"><i class="ti ti-shield-check" aria-hidden="true"></i> Administrator</div>
                                    <div class="role-desc">Acces complet, inclusiv setări</div>
                                </label>
                                <label class="role-card" id="roleCardOffice" onclick="selectRole('office')">
                                    <input type="radio" name="role" value="office" id="role_office">
                                    <div class="role-name"><i class="ti ti-briefcase" aria-hidden="true"></i> Birou</div>
                                    <div class="role-desc">Operare, fără setări</div>
                                </label>
                            </div>
                        </div>

                        <label class="check">
                            <input type="checkbox" name="active" id="active" value="1" checked>
                            <span>Utilizator activ</span>
                        </label>

                        <div class="pz-form-actions">
                            <button class="pz-btn-primary" type="submit">
                                <i class="ti ti-device-floppy" aria-hidden="true"></i>Salvează
                            </button>
                            <button class="pz-btn-ghost" type="button" onclick="resetUserForm()">
                                <i class="ti ti-refresh" aria-hidden="true"></i>Resetează
                            </button>
                        </div>
                    </form>
                </aside>

            </div>
        </div>
    </main>
</div>

<script>
function selectRole(role) {
    document.getElementById('role_admin').checked = (role === 'admin');
    document.getElementById('role_office').checked = (role === 'office');
    document.getElementById('roleCardAdmin').classList.toggle('selected', role === 'admin');
    document.getElementById('roleCardOffice').classList.toggle('selected', role === 'office');
}
// Selecție inițială pentru admin
selectRole('admin');

function editUser(u) {
    document.getElementById('formKicker').textContent = 'Editare';
    document.getElementById('formTitle').textContent  = 'Editează utilizator';
    document.getElementById('action').value           = 'update_user';
    document.getElementById('user_id').value          = u.id || 0;
    document.getElementById('name').value             = u.name  || '';
    document.getElementById('email').value            = u.email || '';
    document.getElementById('password').value         = '';
    document.getElementById('active').checked         = (String(u.active) === '1');
    selectRole(u.role === 'office' ? 'office' : 'admin');
    document.getElementById('name').focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetUserForm() {
    document.getElementById('userForm').reset();
    document.getElementById('formKicker').textContent = 'Nou';
    document.getElementById('formTitle').textContent  = 'Adaugă utilizator';
    document.getElementById('action').value           = 'create_user';
    document.getElementById('user_id').value          = '0';
    document.getElementById('active').checked         = true;
    selectRole('admin');
}
</script>
</body>
</html>
