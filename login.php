<?php
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
| Login pentru administrator / birou si tehnicieni.
| Texte UTF-8, cu diacritice pastrate.
| Redirect după autentificare: dashboard.php
|
| SECURITY:
| - CSRF token verificat la POST
| - session_regenerate_id după login (anti session fixation)
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
 * - Dacă stored password incepe cu '$' este hash bcrypt/argon -> password_verify
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
            $error = 'Completează emailul si parola.';
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
                | 2. Login tehnician
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
                            $_SESSION['team_member_name'] = $team['name'] ?? 'Tehnician';
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

                    // Mesaj generic - nu spunem dacă emailul există sau nu (anti enumerare)
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
<title>Autentificare · PestZone CRM</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css" rel="stylesheet">

<style>
:root {
    /* Paleta oficială PestZone — preluată din app_theme_css */
    --pz-bl:    #2563EB;
    --pz-bld:   #1E3A8A;
    --pz-blb:   #BFDBFE;
    --pz-gr:    #166534;
    --pz-grb:   #BBF7D0;
    --pz-title: #0F172A;
    --pz-text:  #334155;
    --pz-mu:    #64748B;

    --font: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; }

body {
    min-height: 100vh;
    font-family: var(--font);
    background: var(--pz-title);
    color: #fff;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
}

.pz-login-page {
    position: relative;
    min-height: 100vh;
    overflow: hidden;
    padding: 44px 40px 40px;
    display: flex;
    flex-direction: column;
}

/* Blob-uri decorative cu paleta PestZone */
.pz-decor {
    position: absolute;
    pointer-events: none;
    border-radius: 50%;
}
.pz-decor-1 {
    top: 0; right: 0;
    width: 480px; height: 480px;
    background: var(--pz-bl);
    transform: translate(160px, -160px);
}
.pz-decor-2 {
    bottom: 0; left: 0;
    width: 360px; height: 360px;
    background: var(--pz-bld);
    transform: translate(-140px, 140px);
}
.pz-decor-3 {
    top: 46%; left: 38%;
    width: 160px; height: 160px;
    background: var(--pz-gr);
    transform: translate(-50%, -50%) rotate(45deg);
    border-radius: 28px;
    opacity: 0.28;
}

/* Brand */
.pz-brand {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 52px;
    z-index: 2;
}
.pz-brand-bar {
    width: 9px; height: 34px;
    background: var(--pz-bl);
    border-radius: 3px;
}
.pz-brand-name {
    font-size: 18px;
    font-weight: 600;
    letter-spacing: -0.02em;
    color: #fff;
}
.pz-brand-chip {
    margin-left: 4px;
    font-size: 11px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.55);
    border: 1px solid rgba(255, 255, 255, 0.18);
    padding: 3px 8px;
    border-radius: 6px;
}

/* Hero text */
.pz-hero {
    position: relative;
    z-index: 2;
    margin-bottom: 36px;
    max-width: 480px;
}
.pz-hero h1 {
    font-size: 42px;
    font-weight: 600;
    line-height: 1.05;
    letter-spacing: -0.025em;
    margin: 0 0 14px;
    color: #fff;
}
.pz-hero p {
    font-size: 15px;
    color: rgba(255, 255, 255, 0.62);
    line-height: 1.55;
    margin: 0;
    max-width: 420px;
}

/* Mini cards demo */
.pz-mini-cards {
    position: relative;
    z-index: 2;
    display: flex;
    gap: 14px;
    max-width: 480px;
    margin-bottom: 32px;
}
.pz-mini-card {
    flex: 1;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 12px 14px;
}
.pz-mini-card .pz-mini-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}
.pz-mini-card .pz-mini-head i {
    font-size: 15px;
}
.pz-mini-card .pz-mini-head .lbl {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.pz-mini-card .pz-mini-val {
    font-size: 18px;
    font-weight: 600;
    letter-spacing: -0.01em;
    color: #fff;
}

/* Form card */
.pz-form-card {
    position: relative;
    z-index: 2;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    padding: 26px 28px;
    max-width: 480px;
    margin-bottom: 20px;
}

.pz-form-group {
    margin-bottom: 18px;
}
.pz-form-group:last-of-type {
    margin-bottom: 24px;
}

.pz-form-group label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 8px;
}

.pz-label-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 8px;
}
.pz-label-row label { margin-bottom: 0; }
.pz-label-row a {
    font-size: 11px;
    color: var(--pz-blb);
    text-decoration: none;
    font-weight: 500;
}
.pz-label-row a:hover { text-decoration: underline; }

.pz-input-wrap { position: relative; }

.pz-form-card input[type="email"],
.pz-form-card input[type="password"],
.pz-form-card input[type="text"] {
    width: 100%;
    height: 42px;
    padding: 0 32px 10px 0;
    border: 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 0;
    font-size: 15px;
    color: #fff;
    background: transparent;
    box-sizing: border-box;
    font-family: inherit;
    outline: none;
    -webkit-appearance: none;
    appearance: none;
    transition: border-color 0.16s ease;
}
.pz-form-card input::placeholder {
    color: rgba(255, 255, 255, 0.35);
    font-weight: 400;
}
.pz-form-card input:focus {
    border-bottom-color: var(--pz-blb);
}
.pz-form-card input:-webkit-autofill {
    -webkit-text-fill-color: #fff;
    -webkit-box-shadow: 0 0 0 1000px transparent inset;
    transition: background-color 999999s ease-in-out 0s;
    caret-color: #fff;
}

.pz-input-eye {
    position: absolute;
    right: 0;
    top: 11px;
    font-size: 18px;
    color: rgba(255, 255, 255, 0.4);
    cursor: pointer;
    background: transparent;
    border: 0;
    padding: 0;
    line-height: 1;
}
.pz-input-eye:hover { color: rgba(255, 255, 255, 0.7); }

/* Buton principal — pill albastru PestZone */
.pz-submit {
    width: 100%;
    height: 48px;
    background: var(--pz-bl);
    color: #fff;
    border: 0;
    border-radius: 24px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    letter-spacing: 0.01em;
    transition: background-color 0.15s ease, transform 0.1s ease;
}
.pz-submit:hover { background: var(--pz-bld); }
.pz-submit:active { transform: translateY(1px); }
.pz-submit i { font-size: 16px; }

/* Eroare */
.pz-error {
    position: relative;
    z-index: 2;
    background: rgba(220, 38, 38, 0.12);
    border: 1px solid rgba(220, 38, 38, 0.45);
    color: #FCA5A5;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.45;
    max-width: 480px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.pz-error i {
    font-size: 18px;
    color: #F87171;
    flex-shrink: 0;
}

/* Footer */
.pz-foot {
    position: relative;
    z-index: 2;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.4);
    max-width: 480px;
    margin-top: auto;
    padding-top: 20px;
    letter-spacing: 0.04em;
}
.pz-foot-sec {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.pz-foot-sec i {
    font-size: 13px;
    color: var(--pz-grb);
}

/* Responsive */
@media (max-width: 720px) {
    .pz-login-page { padding: 32px 24px 24px; }
    .pz-brand { margin-bottom: 36px; }
    .pz-hero { margin-bottom: 28px; }
    .pz-hero h1 { font-size: 34px; }
    .pz-hero p { font-size: 14px; }
    .pz-decor-1 { width: 360px; height: 360px; transform: translate(140px, -180px); }
    .pz-decor-2 { width: 280px; height: 280px; transform: translate(-100px, 140px); }
}

@media (max-width: 520px) {
    .pz-login-page { padding: 24px 18px 22px; }
    .pz-brand { margin-bottom: 28px; }
    .pz-brand-bar { width: 7px; height: 28px; }
    .pz-brand-name { font-size: 16px; }
    .pz-hero h1 { font-size: 28px; }
    .pz-hero p { font-size: 13.5px; line-height: 1.5; }
    .pz-mini-cards { display: none; }
    .pz-form-card { padding: 22px 20px; border-radius: 16px; }
    .pz-form-card input[type="email"],
    .pz-form-card input[type="password"] { font-size: 16px; }
    .pz-submit { height: 46px; }
    .pz-foot { font-size: 10.5px; flex-direction: column; gap: 6px; align-items: flex-start; }
    .pz-decor-1 { width: 280px; height: 280px; opacity: 0.85; }
    .pz-decor-2 { width: 220px; height: 220px; opacity: 0.85; }
    .pz-decor-3 { display: none; }
}
</style>
</head>

<body>

<div class="pz-login-page">

    <div class="pz-decor pz-decor-1" aria-hidden="true"></div>
    <div class="pz-decor pz-decor-2" aria-hidden="true"></div>
    <div class="pz-decor pz-decor-3" aria-hidden="true"></div>

    <div class="pz-brand">
        <div class="pz-brand-bar" aria-hidden="true"></div>
        <span class="pz-brand-name">PestZone</span>
        <span class="pz-brand-chip">CRM</span>
    </div>

    <div class="pz-hero">
        <h1>Salut,<br>bine ai revenit.</h1>
        <p>Continuă unde ai rămas. Programările, intervențiile și facturile te așteaptă.</p>
    </div>

    <div class="pz-mini-cards">
        <div class="pz-mini-card">
            <div class="pz-mini-head">
                <i class="ti ti-calendar-event" style="color: var(--pz-blb);" aria-hidden="true"></i>
                <span class="lbl">Azi</span>
            </div>
            <div class="pz-mini-val">23 lucrări</div>
        </div>
        <div class="pz-mini-card">
            <div class="pz-mini-head">
                <i class="ti ti-clock" style="color: var(--pz-grb);" aria-hidden="true"></i>
                <span class="lbl">În curs</span>
            </div>
            <div class="pz-mini-val">4 echipe</div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="pz-error">
            <i class="ti ti-alert-circle" aria-hidden="true"></i>
            <span><?= login_h($error) ?></span>
        </div>
    <?php endif; ?>

    <form class="pz-form-card" method="post" autocomplete="on" novalidate>
        <?= csrf_field() ?>

        <div class="pz-form-group">
            <label for="email">Email</label>
            <div class="pz-input-wrap">
                <input
                    id="email"
                    type="email"
                    name="email"
                    placeholder="nume@firma.ro"
                    autocomplete="username"
                    required
                    value="<?= login_h($_POST['email'] ?? '') ?>"
                >
            </div>
        </div>

        <div class="pz-form-group">
            <div class="pz-label-row">
                <label for="password">Parolă</label>
                <a href="forgot_password.php">Ai uitat?</a>
            </div>
            <div class="pz-input-wrap">
                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="••••••••••"
                    autocomplete="current-password"
                    required
                >
                <button type="button" class="pz-input-eye" id="pzPwdToggle" aria-label="Arată/ascunde parola">
                    <i class="ti ti-eye" id="pzPwdEyeIcon" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <button class="pz-submit" type="submit">
            Conectare
            <i class="ti ti-arrow-right" aria-hidden="true"></i>
        </button>
    </form>

    <div class="pz-foot">
        <span class="pz-foot-sec">
            <i class="ti ti-shield-check" aria-hidden="true"></i>
            CONEXIUNE SECURIZATĂ
        </span>
        <span>© <?= (int)date('Y') ?> · MADE IN ROMÂNIA</span>
    </div>

</div>

<script>
(function() {
    // Toggle vizibilitate parolă
    var btn = document.getElementById('pzPwdToggle');
    var input = document.getElementById('password');
    var icon = document.getElementById('pzPwdEyeIcon');
    if (!btn || !input || !icon) return;
    btn.addEventListener('click', function() {
        var isPwd = input.type === 'password';
        input.type = isPwd ? 'text' : 'password';
        icon.className = isPwd ? 'ti ti-eye-off' : 'ti ti-eye';
        input.focus();
    });
})();
</script>

</body>
</html>
