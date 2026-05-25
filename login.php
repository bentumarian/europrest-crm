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

.em-login {
    position: relative;
    min-height: 100vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

/* Blob-uri decorative coral foarte difuze */
.em-decor {
    position: absolute;
    pointer-events: none;
    border-radius: 50%;
    z-index: 0;
}
.em-decor-1 {
    top: -180px; right: -120px;
    width: 520px; height: 520px;
    background: radial-gradient(circle, rgba(255, 90, 95, .22), transparent 65%);
    filter: blur(40px);
}
.em-decor-2 {
    bottom: -160px; left: -100px;
    width: 460px; height: 460px;
    background: radial-gradient(circle, rgba(255, 122, 61, .18), transparent 65%);
    filter: blur(50px);
}
.em-decor-3 {
    top: 40%; right: 35%;
    width: 220px; height: 220px;
    background: radial-gradient(circle, rgba(255, 154, 61, .12), transparent 65%);
    filter: blur(40px);
}

/* Linie verticală subtilă coral — accent editorial */
.em-divider {
    position: absolute;
    top: 12%;
    left: 50%;
    transform: translateX(-50%);
    width: 1px;
    height: 76%;
    background: linear-gradient(180deg, transparent, rgba(255, 122, 61, .25), transparent);
    z-index: 0;
}

/* Container principal — grid pe desktop, stack pe mobil */
.em-wrap {
    position: relative;
    z-index: 2;
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 80px;
    align-items: center;
    max-width: 1100px;
    width: 100%;
    margin: 0 auto;
    padding: 60px 56px;
}

/* ============================================================
   BRAND BLOCK — logo emma.ro + tagline aliniat ca în logo
   ============================================================ */
.em-brand {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

/* Sub-container care wraps DOAR logo + tagline.
   inline-flex column + align-items: stretch → lățimea = lățimea logo-ului,
   tagline-ul (stretch) se aliniază exact între edge-urile logo-ului. */
.em-brand-mark {
    display: inline-flex;
    flex-direction: column;
    align-items: stretch;
    max-width: 100%;
}

/* Logo image — varianta principală (când există fișierul în /assets) */
.em-logo-img {
    display: block;
    width: clamp(220px, 26vw, 320px);
    height: auto;
    max-width: 100%;
    -webkit-user-select: none;
    user-select: none;
}

/* Wordmark text — fallback când nu există image */
.em-wordmark {
    display: flex;
    align-items: baseline;
    font-size: clamp(52px, 6.5vw, 76px);
    font-weight: 700;
    letter-spacing: -0.045em;
    line-height: 0.92;
    color: #FFFFFF;
    font-family: var(--font);
}
.em-wordmark-dot { color: var(--em-coral-start); }

/* Tagline — width = 100% din .em-brand-mark = wordmark width */
.em-tagline {
    display: flex;
    justify-content: space-between;
    width: 100%;
    margin-top: 10px;
    font-size: clamp(11px, 0.9vw, 13.5px);
    font-weight: 400;
    color: rgba(255, 255, 255, 0.52);
    letter-spacing: 0.04em;
}

.em-hero-copy {
    margin-top: 36px;
    max-width: 340px;
    font-size: 16px;
    line-height: 1.55;
    color: rgba(255, 255, 255, 0.72);
    font-weight: 400;
}

/* ============================================================
   GROWTH STATS — sparkline-uri crescătoare (subliminal trust signal)
   ============================================================ */
.em-stats {
    margin-top: 36px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    max-width: 340px;
}
.em-stat {
    display: flex;
    align-items: center;
    gap: 16px;
}
.em-spark {
    width: 62px;
    height: 26px;
    flex-shrink: 0;
    overflow: visible;
}
.em-spark path.line {
    fill: none;
    stroke: url(#emSparkGradient);
    stroke-width: 1.6;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.em-spark path.area {
    fill: url(#emSparkArea);
    opacity: .9;
}
.em-spark .dot {
    fill: var(--em-coral-start);
}
.em-stat-text {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
    min-width: 0;
}
.em-stat-num {
    font-size: 18px;
    font-weight: 500;
    color: #FFFFFF;
    letter-spacing: -0.01em;
    font-variant-numeric: tabular-nums;
}
.em-stat-num .arrow {
    color: var(--em-coral-mid);
    font-size: 13px;
    margin-right: 2px;
    vertical-align: 1px;
}
.em-stat-lbl {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.48);
    margin-top: 4px;
    letter-spacing: 0.04em;
}

/* ============================================================
   FORM
   ============================================================ */
.em-form-wrap {
    width: 100%;
    max-width: 380px;
}

.em-error {
    margin-bottom: 22px;
    padding: 11px 14px;
    background: rgba(220, 38, 38, 0.12);
    border: 1px solid rgba(220, 38, 38, 0.35);
    border-radius: 8px;
    color: #FCA5A5;
    font-size: 12.5px;
    line-height: 1.45;
    display: flex;
    align-items: center;
    gap: 10px;
}
.em-error i {
    font-size: 16px;
    color: #F87171;
    flex-shrink: 0;
}

.em-form-group {
    margin-bottom: 22px;
}

.em-form-label {
    display: block;
    font-size: 10.5px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.48);
    text-transform: uppercase;
    letter-spacing: 0.12em;
    margin-bottom: 8px;
}

.em-label-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 8px;
}
.em-label-row .em-form-label { margin-bottom: 0; }
.em-label-row a {
    font-size: 11px;
    color: var(--em-coral-mid);
    text-decoration: none;
    font-weight: 500;
    letter-spacing: 0;
}
.em-label-row a:hover { color: var(--em-coral-start); }

.em-input-wrap { position: relative; }

.em-form-wrap input[type="email"],
.em-form-wrap input[type="password"],
.em-form-wrap input[type="text"] {
    width: 100%;
    height: 40px;
    padding: 0 32px 10px 0;
    border: 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 0;
    font-size: 15px;
    color: #FFFFFF;
    background: transparent;
    font-family: inherit;
    outline: none;
    -webkit-appearance: none;
    appearance: none;
    transition: border-color 0.18s ease;
}
.em-form-wrap input::placeholder {
    color: rgba(255, 255, 255, 0.32);
    font-weight: 400;
}
.em-form-wrap input:focus {
    border-bottom-color: var(--em-coral-mid);
}
.em-form-wrap input:-webkit-autofill {
    -webkit-text-fill-color: #FFFFFF;
    -webkit-box-shadow: 0 0 0 1000px transparent inset;
    transition: background-color 999999s ease-in-out 0s;
    caret-color: #FFFFFF;
}

.em-input-eye {
    position: absolute;
    right: 0;
    top: 8px;
    font-size: 16px;
    color: rgba(255, 255, 255, 0.4);
    cursor: pointer;
    background: transparent;
    border: 0;
    padding: 4px;
    line-height: 1;
}
.em-input-eye:hover { color: rgba(255, 255, 255, 0.85); }

.em-submit {
    width: 100%;
    margin-top: 8px;
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
    transition: transform 0.12s ease, box-shadow 0.18s ease, filter 0.18s ease;
    box-shadow: 0 10px 28px -12px rgba(255, 90, 95, 0.55);
}
.em-submit:hover {
    filter: brightness(1.05);
    box-shadow: 0 14px 32px -10px rgba(255, 90, 95, 0.65);
}
.em-submit:active { transform: translateY(1px); }
.em-submit i { font-size: 16px; }

/* Footer */
.em-foot {
    position: relative;
    z-index: 2;
    text-align: center;
    font-size: 10.5px;
    color: rgba(255, 255, 255, 0.32);
    letter-spacing: 0.12em;
    padding: 20px 24px 24px;
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
   RESPONSIVE — pe mobil stack vertical: logo+tagline DEASUPRA formularului
   ============================================================ */
@media (max-width: 860px) {
    .em-wrap {
        grid-template-columns: 1fr;
        gap: 36px;
        padding: 48px 32px;
        max-width: 480px;
    }
    .em-divider { display: none; }
    /* Pe mobil ascundem copy-ul descriptiv și mini-stats — doar logo + tagline + form */
    .em-hero-copy { display: none; }
    .em-stats { display: none; }
    .em-form-wrap { max-width: 100%; }
    .em-decor-3 { display: none; }
}

@media (max-width: 520px) {
    .em-wrap {
        gap: 32px;
        padding: 40px 22px 32px;
    }
    .em-wordmark {
        font-size: clamp(46px, 14vw, 60px);
    }
    .em-tagline {
        font-size: 11px;
        margin-top: 8px;
    }
    .em-form-wrap input { font-size: 16px; }
    .em-submit { height: 46px; font-size: 14px; }
    .em-foot {
        font-size: 9.5px;
        padding: 16px 18px 22px;
    }
    .em-foot .em-foot-sec { margin: 0 8px; }
    .em-decor-1 { width: 320px; height: 320px; top: -120px; right: -100px; }
    .em-decor-2 { width: 280px; height: 280px; bottom: -120px; left: -80px; }
}
</style>
</head>

<body>

<div class="em-login">

    <div class="em-decor em-decor-1" aria-hidden="true"></div>
    <div class="em-decor em-decor-2" aria-hidden="true"></div>
    <div class="em-decor em-decor-3" aria-hidden="true"></div>
    <div class="em-divider" aria-hidden="true"></div>

    <div class="em-wrap">

        <div class="em-brand">
            <div class="em-brand-mark">
                <?php if ($loginLogoUrl): ?>
                    <img class="em-logo-img" src="<?= login_h($loginLogoUrl) ?>" alt="emma.ro" draggable="false">
                <?php else: ?>
                    <div class="em-wordmark" aria-label="emma.ro">
                        <span>emma</span><span class="em-wordmark-dot">.ro</span>
                    </div>
                <?php endif; ?>
                <div class="em-tagline" aria-hidden="true">
                    <span>plan.</span>
                    <span>execute.</span>
                    <span>control.</span>
                </div>
            </div>

            <p class="em-hero-copy">Operațiunile firmei tale, dintr-un singur ecran. Bun venit înapoi.</p>

            <div class="em-stats" aria-hidden="true">
                <svg width="0" height="0" style="position:absolute;">
                    <defs>
                        <linearGradient id="emSparkGradient" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0" stop-color="#FF5A5F"/>
                            <stop offset="1" stop-color="#FF9A3D"/>
                        </linearGradient>
                        <linearGradient id="emSparkArea" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#FF7A3D" stop-opacity=".22"/>
                            <stop offset="1" stop-color="#FF7A3D" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                </svg>

                <div class="em-stat">
                    <svg class="em-spark" viewBox="0 0 62 26" preserveAspectRatio="none">
                        <path class="area" d="M0,22 L10,20 L20,17 L30,13 L42,8 L52,5 L62,2 L62,26 L0,26 Z"/>
                        <path class="line" d="M0,22 L10,20 L20,17 L30,13 L42,8 L52,5 L62,2"/>
                        <circle class="dot" cx="62" cy="2" r="2"/>
                    </svg>
                    <div class="em-stat-text">
                        <span class="em-stat-num"><span class="arrow">▲</span>+24%</span>
                        <span class="em-stat-lbl">facturare luna asta</span>
                    </div>
                </div>

                <div class="em-stat">
                    <svg class="em-spark" viewBox="0 0 62 26" preserveAspectRatio="none">
                        <path class="area" d="M0,21 L8,19 L16,18 L24,15 L32,12 L40,9 L50,6 L62,4 L62,26 L0,26 Z"/>
                        <path class="line" d="M0,21 L8,19 L16,18 L24,15 L32,12 L40,9 L50,6 L62,4"/>
                        <circle class="dot" cx="62" cy="4" r="2"/>
                    </svg>
                    <div class="em-stat-text">
                        <span class="em-stat-num"><span class="arrow">▲</span>+312</span>
                        <span class="em-stat-lbl">lucrări finalizate</span>
                    </div>
                </div>

                <div class="em-stat">
                    <svg class="em-spark" viewBox="0 0 62 26" preserveAspectRatio="none">
                        <path class="area" d="M0,23 L12,21 L22,19 L32,16 L40,11 L48,7 L56,4 L62,3 L62,26 L0,26 Z"/>
                        <path class="line" d="M0,23 L12,21 L22,19 L32,16 L40,11 L48,7 L56,4 L62,3"/>
                        <circle class="dot" cx="62" cy="3" r="2"/>
                    </svg>
                    <div class="em-stat-text">
                        <span class="em-stat-num"><span class="arrow">▲</span>98%</span>
                        <span class="em-stat-lbl">timp economisit la facturare</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="em-form-wrap">

            <?php if ($error): ?>
                <div class="em-error">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i>
                    <span><?= login_h($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="on" novalidate>
                <?= csrf_field() ?>

                <div class="em-form-group">
                    <label class="em-form-label" for="email">Email</label>
                    <div class="em-input-wrap">
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

                <div class="em-form-group">
                    <div class="em-label-row">
                        <label class="em-form-label" for="password">Parolă</label>
                        <a href="forgot_password.php">Am uitat parola</a>
                    </div>
                    <div class="em-input-wrap">
                        <input
                            id="password"
                            type="password"
                            name="password"
                            placeholder="••••••••••"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="em-input-eye" id="pzPwdToggle" aria-label="Arată/ascunde parola">
                            <i class="ti ti-eye" id="pzPwdEyeIcon" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <button class="em-submit" type="submit">
                    Autentificare
                    <i class="ti ti-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
        </div>

    </div>

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
