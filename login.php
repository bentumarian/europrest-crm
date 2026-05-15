<?php
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
| Login pentru administrator / birou si echipe teren.
| Texte fara diacritice, compatibil cu editorul PHP/cPanel.
| Redirect dupa autentificare: dashboard.php
|
| SECURITY:
| - CSRF token verificat la POST
| - session_regenerate_id dupa login (anti session fixation)
| - Verificare parola sigura: hash bcrypt sau plaintext legacy (rehashed)
| - Rate limiting: maxim 5 esecuri / 15 min per email sau per IP
|--------------------------------------------------------------------------
*/

if (is_logged_in()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

const LOGIN_MAX_FAILURES = 5;
const LOGIN_WINDOW_MINUTES = 15;

function login_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function login_table_exists(PDO $pdo, string $table): bool
{
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

function login_column_exists(PDO $pdo, string $table, string $column): bool
{
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

/**
 * Verifica parola in mod sigur:
 * - Daca stored password incepe cu '$' este hash bcrypt/argon -> password_verify
 * - Altfel se considera plaintext legacy (compat invers) si va fi rehash-uit
 */
function login_verify_password(?string $storedPassword, string $inputPassword): bool
{
    $storedPassword = (string)$storedPassword;

    if ($storedPassword === '') {
        return false;
    }

    if ($storedPassword[0] === '$') {
        return password_verify($inputPassword, $storedPassword);
    }

    return hash_equals($storedPassword, $inputPassword);
}

function login_password_needs_rehash(?string $storedPassword, string $inputPassword): bool
{
    $storedPassword = (string)$storedPassword;

    if ($storedPassword === '') {
        return false;
    }

    if ($storedPassword[0] !== '$') {
        return true;
    }

    return password_needs_rehash($storedPassword, PASSWORD_DEFAULT);
}

/*
|--------------------------------------------------------------------------
| Rate limiting helpers
|--------------------------------------------------------------------------
*/
function login_get_client_ip(): string
{
    $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            $value = (string)$_SERVER[$key];
            // X-Forwarded-For poate fi lista, luam primul IP
            $ip = trim(explode(',', $value)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

function login_ensure_attempts_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(160) NULL,
            ip VARCHAR(45) NULL,
            attempted_at DATETIME NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_email_time (email, attempted_at),
            INDEX idx_ip_time (ip, attempted_at)
        )
    ");
}

function login_count_recent_failures(PDO $pdo, string $email, string $ip, int $windowMinutes): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM login_attempts
        WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
          AND success = 0
          AND (email = :email OR ip = :ip)
    ");
    $stmt->execute([
        ':minutes' => $windowMinutes,
        ':email'   => $email,
        ':ip'      => $ip,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0);
}

function login_record_attempt(PDO $pdo, string $email, string $ip, bool $success): void
{
    $stmt = $pdo->prepare("
        INSERT INTO login_attempts (email, ip, attempted_at, success)
        VALUES (?, ?, NOW(), ?)
    ");
    $stmt->execute([$email, $ip, $success ? 1 : 0]);

    // Curatare probabilistica: 1% sansa de a sterge inregistrari mai vechi de 24h
    if (mt_rand(1, 100) === 1) {
        try {
            $pdo->exec("
                DELETE FROM login_attempts
                WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
        } catch (Throwable $e) {
            // Ignoram eroarea de curatare
        }
    }
}

function login_clear_failures(PDO $pdo, string $email, string $ip): void
{
    $stmt = $pdo->prepare("
        DELETE FROM login_attempts
        WHERE success = 0
          AND (email = ? OR ip = ?)
    ");
    $stmt->execute([$email, $ip]);
}

/*
|--------------------------------------------------------------------------
| Asigura tabelul login_attempts
|--------------------------------------------------------------------------
*/
try {
    login_ensure_attempts_table($pdo);
} catch (Throwable $e) {
    error_log('Login: nu s-a putut crea login_attempts: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_check()) {
        $error = 'Sesiune expirata. Reincarca pagina si reincearca.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = login_get_client_ip();

        if ($email === '' || $password === '') {
            $error = 'Completeaza emailul si parola.';
        } else {

            /*
            |----------------------------------------------------------------------
            | Verificare rate limit
            |----------------------------------------------------------------------
            */
            $recentFailures = 0;
            try {
                $recentFailures = login_count_recent_failures($pdo, $email, $ip, LOGIN_WINDOW_MINUTES);
            } catch (Throwable $e) {
                error_log('Login: rate limit check failed: ' . $e->getMessage());
            }

            if ($recentFailures >= LOGIN_MAX_FAILURES) {
                $error = 'Prea multe incercari esuate. Reincearca peste ' . LOGIN_WINDOW_MINUTES . ' minute.';
            } else {

                $loggedIn = false;

                /*
                |--------------------------------------------------------------
                | 1. Login administrator / birou
                |--------------------------------------------------------------
                */
                if (login_table_exists($pdo, 'users')) {
                    $userActiveSql = login_column_exists($pdo, 'users', 'active') ? " AND active = 1" : "";
                    $stmt = $pdo->prepare("
                        SELECT *
                        FROM users
                        WHERE email = ? " . $userActiveSql . "
                        LIMIT 1
                    ");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user && login_verify_password($user['password'] ?? '', $password)) {
                        if (login_password_needs_rehash($user['password'] ?? '', $password)) {
                            $newHash = password_hash($password, PASSWORD_DEFAULT);
                            $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                            $update->execute([$newHash, $user['id']]);
                        }

                        // Regenerare ID sesiune - previne session fixation
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['name'] ?? 'Administrator';
                        $_SESSION['user_email'] = $user['email'] ?? $email;
                        $_SESSION['user_role'] = 'admin';

                        unset($_SESSION['team_member_id']);
                        unset($_SESSION['team_member_name']);

                        // Inregistrare succes si curatare esecuri
                        try {
                            login_record_attempt($pdo, $email, $ip, true);
                            login_clear_failures($pdo, $email, $ip);
                        } catch (Throwable $e) {
                            error_log('Login: login_record_attempt success failed: ' . $e->getMessage());
                        }

                        $loggedIn = true;
                        header("Location: dashboard.php");
                        exit;
                    }
                }

                /*
                |--------------------------------------------------------------
                | 2. Login echipa teren
                |--------------------------------------------------------------
                */
                if (!$loggedIn && login_table_exists($pdo, 'team_members')) {
                    $hasPasswordHash = login_column_exists($pdo, 'team_members', 'password_hash');
                    $hasPassword = login_column_exists($pdo, 'team_members', 'password');
                    $hasUsername = login_column_exists($pdo, 'team_members', 'username');

                    $teamWhere = $hasUsername ? "(email = ? OR username = ?)" : "email = ?";
                    $teamParams = $hasUsername ? [$email, $email] : [$email];

                    $stmt = $pdo->prepare("
                        SELECT *
                        FROM team_members
                        WHERE {$teamWhere}
                          AND active = 1
                        LIMIT 1
                    ");
                    $stmt->execute($teamParams);
                    $team = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($team) {
                        $teamPasswordColumn = '';
                        $teamStoredPassword = '';

                        if ($hasPasswordHash && !empty($team['password_hash'])) {
                            $teamPasswordColumn = 'password_hash';
                            $teamStoredPassword = $team['password_hash'];
                        } elseif ($hasPassword && !empty($team['password'])) {
                            $teamPasswordColumn = 'password';
                            $teamStoredPassword = $team['password'];
                        }

                        if ($teamPasswordColumn !== '' && login_verify_password($teamStoredPassword, $password)) {
                            if (login_password_needs_rehash($teamStoredPassword, $password)) {
                                $newHash = password_hash($password, PASSWORD_DEFAULT);
                                $update = $pdo->prepare("UPDATE team_members SET {$teamPasswordColumn} = ? WHERE id = ?");
                                $update->execute([$newHash, $team['id']]);
                            }

                            session_regenerate_id(true);

                            $_SESSION['team_member_id'] = $team['id'];
                            $_SESSION['team_member_name'] = $team['name'] ?? 'Echipa teren';
                            $_SESSION['user_email'] = $team['email'] ?? $email;
                            $_SESSION['user_role'] = 'team';

                            unset($_SESSION['user_id']);
                            unset($_SESSION['user_name']);

                            // Inregistrare succes si curatare esecuri
                            try {
                                login_record_attempt($pdo, $email, $ip, true);
                                login_clear_failures($pdo, $email, $ip);
                            } catch (Throwable $e) {
                                error_log('Login: login_record_attempt success failed: ' . $e->getMessage());
                            }

                            $loggedIn = true;
                            header("Location: dashboard.php");
                            exit;
                        }
                    }
                }

                if (!$loggedIn) {
                    // Inregistrare esec
                    try {
                        login_record_attempt($pdo, $email, $ip, false);
                    } catch (Throwable $e) {
                        error_log('Login: login_record_attempt failure failed: ' . $e->getMessage());
                    }

                    // Mesaj generic - nu spunem daca emailul exista sau nu (anti enumerare)
                    $error = 'Email sau parola gresita.';
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Autentificare</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    /* Tema global CRM - fara diacritice */
    --primary: #1160B7;
    --primary-soft: #002050;
    --primary-light: #B1D6F0;
    --secondary: #526B82;
    --secondary-2: #DFE2E8;
    --background: #DFE2E8;
    --card: #FFFFFF;
    --border: rgba(177, 214, 240, .58);
    --text: #002050;
    --muted: #526B82;
    --muted-light: #7F94A8;
    --danger-bg: #FFF4F1;
    --danger-border: rgba(210, 71, 38, .24);
    --danger-text: #D24726;
    --shadow-3d: 0 34px 90px rgba(0, 32, 80, .20), 0 14px 34px rgba(0, 32, 80, .10);
    --shadow-soft: 0 18px 40px rgba(0, 32, 80, .13);
    --radius: 34px;
    --font: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }

body {
    margin: 0;
    min-height: 100vh;
    font-family: var(--font);
    color: var(--text);
    background:
        radial-gradient(circle at 18% 10%, rgba(17, 96, 183, .18), transparent 30%),
        radial-gradient(circle at 86% 88%, rgba(177, 214, 240, .30), transparent 36%),
        radial-gradient(circle at 50% 110%, rgba(0, 32, 80, .13), transparent 45%),
        linear-gradient(135deg, #FFFFFF 0%, #EEF4F8 45%, var(--background) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 34px;
    overflow-x: hidden;
}

body::before {
    content: "";
    position: fixed;
    inset: 0;
    pointer-events: none;
    background-image:
        linear-gradient(rgba(0, 32, 80, .038) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 32, 80, .038) 1px, transparent 1px);
    background-size: 44px 44px;
    mask-image: linear-gradient(to bottom, rgba(0,0,0,.62), transparent 82%);
}

body::after {
    content: "";
    position: fixed;
    inset: auto 0 0 0;
    height: 38vh;
    pointer-events: none;
    background: linear-gradient(180deg, transparent 0%, rgba(0, 32, 80, .10) 100%);
}

.login-shell {
    position: relative;
    width: min(420px, calc(100vw - 56px));
    max-width: 420px;
    z-index: 1;
    perspective: 1200px;
}

.login-card {
    width: 100%;
    position: relative;
    background:
        linear-gradient(145deg, rgba(255,255,255,.88) 0%, rgba(255,255,255,.72) 54%, rgba(177,214,240,.24) 100%);
    border: 1px solid rgba(255,255,255,.86);
    border-top-color: rgba(177,214,240,.78);
    border-radius: var(--radius);
    box-shadow: var(--shadow-3d);
    padding: 32px 32px 28px;
    overflow: hidden;
    backdrop-filter: blur(22px) saturate(150%);
    -webkit-backdrop-filter: blur(22px) saturate(150%);
    transform: translateZ(0);
}

.login-card::before {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    top: 0;
    height: 7px;
    background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 52%, rgba(255,255,255,.80) 100%);
    box-shadow: 0 1px 0 rgba(255,255,255,.86) inset;
}

.login-card::after {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(circle at 50% 0%, rgba(255,255,255,.92), transparent 34%),
        linear-gradient(135deg, rgba(255,255,255,.55) 0%, transparent 45%),
        linear-gradient(315deg, rgba(177,214,240,.22) 0%, transparent 40%);
    opacity: .78;
    z-index: 0;
}

.login-card > * {
    position: relative;
    z-index: 1;
}

.brand {
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 2px 0 24px;
}

.brand-badge {
    width: 78px;
    height: 78px;
    border-radius: 28px;
    background:
        linear-gradient(145deg, #1772D3 0%, var(--primary) 58%, #0B4E9C 100%);
    border: 1px solid rgba(255,255,255,.42);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow:
        0 20px 42px rgba(0, 32, 80, .28),
        inset 0 1px 0 rgba(255,255,255,.38),
        inset 0 -12px 22px rgba(0,32,80,.12);
}

.brand-badge img {
    display: block;
    width: 53px;
    height: 53px;
    object-fit: contain;
    filter: drop-shadow(0 5px 10px rgba(0,32,80,.18));
}

.login-title {
    margin: 0;
    text-align: center;
    font-size: 25px;
    line-height: 1.18;
    letter-spacing: -.045em;
    font-weight: 900;
    color: var(--text);
}

.login-subtitle {
    margin: 10px 0 23px;
    text-align: center;
    font-size: 14px;
    line-height: 1.5;
    color: var(--muted);
    font-weight: 600;
}

.form-group { margin-bottom: 14px; }

label {
    display: block;
    margin-bottom: 8px;
    color: var(--text);
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .085em;
}

.input-wrap {
    position: relative;
}

.input-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    width: 18px;
    height: 18px;
    transform: translateY(-50%);
    color: var(--secondary);
    pointer-events: none;
}

.input-icon svg {
    display: block;
    width: 18px;
    height: 18px;
    stroke: currentColor;
    stroke-width: 1.95;
    fill: none;
    stroke-linecap: round;
    stroke-linejoin: round;
}

input {
    width: 100%;
    min-height: 52px;
    padding: 0 15px 0 46px;
    border-radius: 18px;
    border: 1px solid rgba(0,32,80,.13);
    background: rgba(255,255,255,.74);
    color: var(--text);
    font-family: var(--font);
    font-size: 14px;
    font-weight: 700;
    outline: none;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.80),
        0 10px 22px rgba(0,32,80,.035);
    transition: border-color .16s ease, box-shadow .16s ease, background .16s ease, transform .12s ease;
}

input::placeholder { color: #7F94A8; font-weight: 600; }

input:focus {
    border-color: var(--primary);
    box-shadow:
        0 0 0 4px rgba(17,96,183,.15),
        inset 0 1px 0 rgba(255,255,255,.88),
        0 14px 30px rgba(0,32,80,.08);
    background: rgba(255,255,255,.92);
}

.forgot-row {
    margin: 2px 0 15px;
    text-align: right;
}

.forgot-row a {
    color: var(--primary);
    font-weight: 900;
    text-decoration: none;
    font-size: 13px;
}

.forgot-row a:hover { text-decoration: underline; }

.login-button {
    width: 100%;
    min-height: 54px;
    border: 1px solid rgba(255,255,255,.38);
    border-radius: 18px;
    background:
        linear-gradient(145deg, #1772D3 0%, var(--primary) 58%, #0B4E9C 100%);
    color: #fff;
    font-family: var(--font);
    font-size: 14px;
    font-weight: 900;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow:
        0 20px 38px rgba(17,96,183,.25),
        inset 0 1px 0 rgba(255,255,255,.28),
        inset 0 -12px 22px rgba(0,32,80,.13);
    transition: transform .12s ease, box-shadow .16s ease, filter .16s ease;
}

.login-button svg {
    width: 18px;
    height: 18px;
    stroke: currentColor;
    stroke-width: 2.2;
    fill: none;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.login-button:hover {
    filter: brightness(1.03);
    box-shadow:
        0 24px 46px rgba(17,96,183,.30),
        inset 0 1px 0 rgba(255,255,255,.34),
        inset 0 -12px 22px rgba(0,32,80,.13);
}

.login-button:active { transform: translateY(1px) scale(.997); }

.error {
    border: 1px solid var(--danger-border);
    background: rgba(255,244,241,.88);
    color: var(--danger-text);
    border-radius: 18px;
    padding: 12px 14px;
    margin-bottom: 18px;
    font-size: 13px;
    font-weight: 800;
    line-height: 1.45;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.65);
}

.login-meta {
    margin-top: 19px;
    padding-top: 16px;
    border-top: 1px solid rgba(0,32,80,.08);
    color: var(--muted);
    text-align: center;
    font-size: 12px;
    line-height: 1.45;
}

.security-line {
    margin-top: 14px;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    color: var(--secondary);
    font-size: 12px;
    font-weight: 800;
}

.security-line svg {
    width: 16px;
    height: 16px;
    stroke: currentColor;
    stroke-width: 1.9;
    fill: none;
    stroke-linecap: round;
    stroke-linejoin: round;
}

@media (max-width: 520px) {
    body {
        align-items: center;
        padding: 22px 0;
        background:
            radial-gradient(circle at 50% 0%, rgba(17,96,183,.16), transparent 34%),
            radial-gradient(circle at 50% 100%, rgba(0,32,80,.13), transparent 42%),
            linear-gradient(180deg, #FFFFFF 0%, #EEF4F8 52%, var(--background) 100%);
    }

    body::before { background-size: 36px 36px; }

    .login-shell {
        width: calc(100vw - 42px);
        max-width: 390px;
        margin: 0 auto;
    }

    .login-card {
        padding: 26px 20px 23px;
        border-radius: 28px;
    }

    .brand {
        margin-bottom: 20px;
    }

    .brand-badge {
        width: 70px;
        height: 70px;
        border-radius: 24px;
    }

    .brand-badge img {
        width: 48px;
        height: 48px;
    }

    .login-title { font-size: 23px; }

    .login-subtitle {
        margin-bottom: 20px;
        font-size: 13px;
    }

    input {
        min-height: 50px;
        border-radius: 17px;
    }

    .login-button {
        min-height: 52px;
        border-radius: 17px;
    }
}

@media (max-width: 380px) {
    body { padding: 18px 0; }

    .login-shell {
        width: calc(100vw - 34px);
        max-width: 350px;
    }

    .login-card {
        padding: 23px 17px 21px;
        border-radius: 24px;
    }

    .brand-badge {
        width: 64px;
        height: 64px;
        border-radius: 22px;
    }

    .brand-badge img {
        width: 44px;
        height: 44px;
    }

    .login-title { font-size: 22px; }
    .forgot-row a { font-size: 12px; }
}
</style>
</head>

<body>

<main class="login-shell">
    <form class="login-card" method="post" autocomplete="on">
        <?= csrf_field() ?>

        <div class="brand">
            <div class="brand-badge" aria-hidden="true">
                <img src="assets/brand-icon.png" alt="">
            </div>
        </div>

        <h1 class="login-title">Autentificare</h1>
        <p class="login-subtitle">Introdu datele contului pentru a continua.</p>

        <?php if ($error): ?>
            <div class="error"><?= login_h($error) ?></div>
        <?php endif; ?>

        <div class="form-group">
            <label for="email">Email</label>
            <div class="input-wrap">
                <span class="input-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"></path><path d="m4 7 8 6 8-6"></path></svg>
                </span>
                <input
                    id="email"
                    type="email"
                    name="email"
                    required
                    placeholder="email@domeniu.ro"
                    autocomplete="username"
                    value="<?= login_h($_POST['email'] ?? '') ?>"
                >
            </div>
        </div>

        <div class="form-group">
            <label for="password">Parola</label>
            <div class="input-wrap">
                <span class="input-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
                </span>
                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="Introdu parola"
                    required
                    autocomplete="current-password"
                >
            </div>
        </div>

        <div class="forgot-row">
            <a href="forgot_password.php">Am uitat parola</a>
        </div>

        <button class="login-button" type="submit">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h12"></path><path d="m13 6 6 6-6 6"></path></svg>
            <span>Intra in aplicatie</span>
        </button>

        <div class="security-line" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 5 3.2 8.5 7 10 3.8-1.5 7-5 7-10V6l-7-3Z"></path><path d="m9.5 12 1.8 1.8 3.6-4"></path></svg>
            <span>Acces securizat</span>
        </div>
    </form>
</main>

</body>
</html>
