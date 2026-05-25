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

/*
|--------------------------------------------------------------------------
| Detectare logo pagină login (din /assets/)
|--------------------------------------------------------------------------
| Acceptă orice extensie comună. Dacă nu există, cade pe wordmark text.
| Cache busting cu filemtime astfel încât refresh-ul să prindă imaginea nouă.
*/
function login_find_logo(): ?string
{
    $candidates = [
        'loghin-logo.svg',
        'loghin-logo.png',
        'loghin-logo.jpg',
        'loghin-logo.jpeg',
        'loghin-logo.webp',
        'login-logo.svg',
        'login-logo.png',
    ];
    foreach ($candidates as $name) {
        $abs = __DIR__ . '/assets/' . $name;
        if (is_file($abs)) {
            $ver = @filemtime($abs) ?: time();
            return 'assets/' . $name . '?v=' . $ver;
        }
    }
    return null;
}

$loginLogoUrl = login_find_logo();

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
<title>Autentificare · emma.ro</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css" rel="stylesheet">

<style>
:root {
    /* Paleta oficială emma.ro */
    --em-navy:        #061142;
    --em-navy-alt:    #070F3F;
    --em-coral-start: #FF5A5F;
    --em-coral-mid:   #FF7A3D;
    --em-coral-end:   #FF9A3D;
    --em-muted:       #3E4C8F;
    --em-gray-200:    #E5E7EB;
    --em-gray-50:     #F9FAFB;

    --font: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; }

body {
    min-height: 100vh;
    font-family: var(--font);
    background: var(--em-navy);
    color: #FFFFFF;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
}

/* ============================================================
   LAYOUT — Navy full screen, logo deasupra + card centrat
   ============================================================ */
.em-login {
    position: relative;
    min-height: 100vh;
    background: var(--em-navy);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 20px 80px;
    overflow: hidden;
    gap: 28px;
}

/* Blob-uri coral difuze pe fundal */
.em-decor {
    position: absolute;
    pointer-events: none;
    z-index: 0;
    border-radius: 50%;
}
.em-decor-1 {
    top: -120px;
    left: -80px;
    width: 420px;
    height: 420px;
    background: radial-gradient(circle, rgba(255, 122, 61, .35), transparent 70%);
    filter: blur(40px);
}
.em-decor-2 {
    bottom: -140px;
    right: -90px;
    width: 460px;
    height: 460px;
    background: radial-gradient(circle, rgba(255, 90, 95, .32), transparent 70%);
    filter: blur(50px);
}
.em-decor-3 {
    top: 28%;
    right: 22%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(255, 154, 61, .22), transparent 70%);
    filter: blur(34px);
}

/* ============================================================
   CARD ALB CENTRAT
   ============================================================ */
.em-card {
    position: relative;
    z-index: 2;
    background: #FFFFFF;
    border-radius: 16px;
    padding: 38px 36px 32px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 30px 70px -20px rgba(0, 0, 0, .45),
                0 10px 30px -15px rgba(255, 90, 95, .18);
}

/* ============================================================
   BRAND BLOCK — logo deasupra cardului, pe Navy
   ============================================================ */
.em-brand-area {
    position: relative;
    z-index: 2;
    text-align: center;
}

/* Logo image — varianta principală (pe fundal Navy) */
.em-logo-img {
    display: block;
    width: clamp(220px, 28vw, 300px);
    height: auto;
    max-width: 100%;
    margin: 0 auto;
    -webkit-user-select: none;
    user-select: none;
}

/* Wordmark text — fallback când nu există image */
.em-wordmark {
    display: inline-flex;
    align-items: baseline;
    font-size: clamp(44px, 8vw, 60px);
    font-weight: 700;
    letter-spacing: -0.04em;
    line-height: 0.95;
    color: #FFFFFF;
    font-family: var(--font);
}
.em-wordmark-dot { color: var(--em-coral-start); }

/* ============================================================
   TITLU CARD
   ============================================================ */
.em-card-title {
    text-align: center;
    font-size: 14px;
    font-weight: 500;
    color: var(--em-navy);
    margin: 0 0 22px;
    letter-spacing: -.005em;
}

/* ============================================================
   EROARE
   ============================================================ */
.em-error {
    margin-bottom: 18px;
    padding: 11px 14px;
    background: #FEF2F2;
    border: 1px solid #FCA5A5;
    border-radius: 10px;
    color: #B91C1C;
    font-size: 12.5px;
    line-height: 1.45;
    display: flex;
    align-items: center;
    gap: 10px;
}
.em-error i {
    font-size: 16px;
    color: #DC2626;
    flex-shrink: 0;
}

/* ============================================================
   INPUTS — bordură subtilă, icon stânga
   ============================================================ */
.em-input {
    position: relative;
    margin-bottom: 12px;
}

.em-input-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 16px;
    color: var(--em-muted);
    pointer-events: none;
    line-height: 1;
}

.em-input input[type="email"],
.em-input input[type="password"],
.em-input input[type="text"] {
    width: 100%;
    height: 46px;
    padding: 0 14px 0 42px;
    border: 1px solid var(--em-gray-200);
    border-radius: 10px;
    background: #FFFFFF;
    color: var(--em-navy);
    font-size: 14px;
    font-family: inherit;
    outline: none;
    -webkit-appearance: none;
    appearance: none;
    transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.em-input input::placeholder {
    color: #9DA1BD;
    font-weight: 400;
}
.em-input input:hover {
    border-color: #CFD2E0;
}
.em-input input:focus {
    border-color: var(--em-coral-mid);
    box-shadow: 0 0 0 3px rgba(255, 122, 61, .14);
}
.em-input input:-webkit-autofill {
    -webkit-text-fill-color: var(--em-navy);
    -webkit-box-shadow: 0 0 0 1000px #FFFFFF inset;
    transition: background-color 999999s ease-in-out 0s;
    caret-color: var(--em-navy);
}

.em-input-pwd input { padding-right: 42px; }

.em-eye {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: 0;
    padding: 6px;
    color: var(--em-muted);
    cursor: pointer;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.em-eye i { font-size: 16px; }
.em-eye:hover { color: var(--em-navy); }

/* ============================================================
   META ROW — Ține-mă minte + Am uitat parola
   ============================================================ */
.em-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 14px 0 18px;
    font-size: 12px;
}

.em-remember {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--em-muted);
    cursor: pointer;
    -webkit-user-select: none;
    user-select: none;
}
.em-remember input {
    width: 14px;
    height: 14px;
    margin: 0;
    accent-color: var(--em-coral-mid);
    cursor: pointer;
}

.em-forgot {
    color: var(--em-coral-start);
    text-decoration: none;
    font-weight: 500;
    transition: color .15s ease;
}
.em-forgot:hover { color: var(--em-coral-mid); }

/* ============================================================
   BUTON PRINCIPAL
   ============================================================ */
.em-submit {
    width: 100%;
    height: 48px;
    background: linear-gradient(135deg, var(--em-coral-start), var(--em-coral-mid));
    color: #FFFFFF;
    border: 0;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    letter-spacing: 0.02em;
    box-shadow: 0 10px 24px -10px rgba(255, 90, 95, .55);
    transition: transform .12s ease, box-shadow .18s ease, filter .18s ease;
}
.em-submit:hover {
    filter: brightness(1.06);
    box-shadow: 0 14px 30px -10px rgba(255, 90, 95, .65);
}
.em-submit:active { transform: translateY(1px); }
.em-submit i { font-size: 16px; }

/* ============================================================
   FOOTER PE NAVY (sub card)
   ============================================================ */
.em-foot {
    position: absolute;
    bottom: 18px;
    left: 0;
    right: 0;
    z-index: 2;
    text-align: center;
    font-size: 10.5px;
    color: rgba(255, 255, 255, 0.4);
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.em-foot .em-foot-sec {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0 14px;
}
.em-foot .em-foot-sec i {
    font-size: 12px;
    color: var(--em-coral-mid);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 520px) {
    .em-login {
        padding: 32px 16px 80px;
        gap: 22px;
    }
    .em-card {
        padding: 32px 24px 28px;
        border-radius: 14px;
        box-shadow: 0 16px 40px -16px rgba(0, 0, 0, .5);
    }
    .em-logo-img {
        width: clamp(200px, 60vw, 260px);
    }
    .em-card-title {
        font-size: 13.5px;
        margin: 0 0 18px;
    }
    .em-input input { font-size: 16px; height: 44px; }
    .em-eye { right: 6px; }
    .em-submit { height: 46px; }
    .em-decor-1 { width: 280px; height: 280px; top: -100px; left: -80px; }
    .em-decor-2 { width: 300px; height: 300px; bottom: -120px; right: -80px; }
    .em-decor-3 { display: none; }
    .em-foot {
        position: static;
        margin-top: 12px;
        padding: 8px 18px 0;
        font-size: 9.5px;
    }
    .em-foot .em-foot-sec { margin: 0 8px; }
}
</style>
</head>

<body>

<div class="em-login">

    <div class="em-decor em-decor-1" aria-hidden="true"></div>
    <div class="em-decor em-decor-2" aria-hidden="true"></div>
    <div class="em-decor em-decor-3" aria-hidden="true"></div>

    <div class="em-brand-area">
        <?php if ($loginLogoUrl): ?>
            <img class="em-logo-img" src="<?= login_h($loginLogoUrl) ?>" alt="emma.ro" draggable="false">
        <?php else: ?>
            <div class="em-wordmark" aria-label="emma.ro">
                <span>emma</span><span class="em-wordmark-dot">.ro</span>
            </div>
        <?php endif; ?>
    </div>

    <main class="em-card">

        <h1 class="em-card-title">Intră în contul tău</h1>

        <?php if ($error): ?>
            <div class="em-error">
                <i class="ti ti-alert-circle" aria-hidden="true"></i>
                <span><?= login_h($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="on" novalidate>
            <?= csrf_field() ?>

            <div class="em-input">
                <i class="ti ti-mail em-input-icon" aria-hidden="true"></i>
                <input
                    id="email"
                    type="email"
                    name="email"
                    placeholder="Email"
                    autocomplete="username"
                    aria-label="Email"
                    required
                    value="<?= login_h($_POST['email'] ?? '') ?>"
                >
            </div>

            <div class="em-input em-input-pwd">
                <i class="ti ti-lock em-input-icon" aria-hidden="true"></i>
                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="Parolă"
                    autocomplete="current-password"
                    aria-label="Parolă"
                    required
                >
                <button type="button" class="em-eye" id="pzPwdToggle" aria-label="Arată/ascunde parola">
                    <i class="ti ti-eye" id="pzPwdEyeIcon" aria-hidden="true"></i>
                </button>
            </div>

            <div class="em-meta">
                <label class="em-remember">
                    <input type="checkbox" name="remember" value="1">
                    <span>Ține-mă minte</span>
                </label>
                <a class="em-forgot" href="forgot_password.php">Am uitat parola</a>
            </div>

            <button class="em-submit" type="submit">
                Autentificare
                <i class="ti ti-arrow-right" aria-hidden="true"></i>
            </button>
        </form>

    </main>

    <div class="em-foot">
        <span class="em-foot-sec">
            <i class="ti ti-shield-check" aria-hidden="true"></i>
            Conexiune securizată
        </span>
        <span class="em-foot-sec">© <?= (int)date('Y') ?> · emma.ro</span>
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
