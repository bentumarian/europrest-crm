<?php
/*
|--------------------------------------------------------------------------
| Config general Emma
|--------------------------------------------------------------------------
| Salvează fișierul ca UTF-8.
| Nu pune spatii sau text înainte de <?php.
|
| Datele sensibile (parola DB) sunt in config.local.php (NU in Git).
|--------------------------------------------------------------------------
*/

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

date_default_timezone_set('Europe/Bucharest');

/*
|--------------------------------------------------------------------------
| Mediu aplicatie si erori
|--------------------------------------------------------------------------
| Implicit rulam in productie. Pentru local, seteaza in config.local.php:
|   'app_env' => 'local',
|   'app_debug' => true,
|--------------------------------------------------------------------------
*/
$localConfigPath = __DIR__ . '/config.local.php';

if (!file_exists($localConfigPath)) {
    error_log('Emma: lipseste config.local.php');
    die('Eroare configurare server. Contacteaza administratorul.');
}

$dbConfig = require $localConfigPath;

if (!is_array($dbConfig)) {
    error_log('Emma: config.local.php este invalid');
    die('Eroare configurare server. Contacteaza administratorul.');
}

$appEnv = strtolower((string)($dbConfig['app_env'] ?? getenv('APP_ENV') ?: 'production'));
$appDebug = filter_var($dbConfig['app_debug'] ?? getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
$isProduction = $appEnv === 'production';

if ($isProduction || !$appDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

if (!defined('PZ_APP_ENV')) {
    define('PZ_APP_ENV', $appEnv);
}
if (!defined('PZ_APP_DEBUG')) {
    define('PZ_APP_DEBUG', $appDebug);
}

/*
|--------------------------------------------------------------------------
| Sesiune securizata
|--------------------------------------------------------------------------
*/
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('PESTZONE_SESSION');
    session_start();
}

/*
|--------------------------------------------------------------------------
| Headere securitate
|--------------------------------------------------------------------------
*/
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    if (!empty($isHttps)) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }
}

/*
|--------------------------------------------------------------------------
| Configurare SendGrid
|--------------------------------------------------------------------------
| Recomandat: cheia SendGrid se tine in config.local.php, nu in acest fișier.
| Exemplu config.local.php:
|   'sendgrid_api_key' => 'SG_xxxxxxxxx',
|   'sendgrid_from_email' => 'office@pestzone.ro',
|   'sendgrid_from_name' => 'Emma',
|
| Alternativ, se pot folosi variabile de mediu cu aceleasi nume:
| SENDGRID_API_KEY, SENDGRID_FROM_EMAIL, SENDGRID_FROM_NAME
|--------------------------------------------------------------------------
*/
if (is_array($dbConfig)) {
    $sgApiKey = (string)($dbConfig['sendgrid_api_key'] ?? getenv('SENDGRID_API_KEY') ?: '');
    $sgFromEmail = (string)($dbConfig['sendgrid_from_email'] ?? getenv('SENDGRID_FROM_EMAIL') ?: 'office@pestzone.ro');
    $sgFromName = (string)($dbConfig['sendgrid_from_name'] ?? getenv('SENDGRID_FROM_NAME') ?: 'Emma');

    if ($sgApiKey !== '' && !defined('SENDGRID_API_KEY')) {
        define('SENDGRID_API_KEY', $sgApiKey);
    }

    if ($sgFromEmail !== '' && !defined('SENDGRID_FROM_EMAIL')) {
        define('SENDGRID_FROM_EMAIL', $sgFromEmail);
    }

    if ($sgFromName !== '' && !defined('SENDGRID_FROM_NAME')) {
        define('SENDGRID_FROM_NAME', $sgFromName);
    }
}

if (empty($dbConfig['db_host']) || empty($dbConfig['db_name'])) {
    error_log('Emma: config.local.php este invalid');
    die('Eroare configurare server. Contacteaza administratorul.');
}

$db_host = (string)$dbConfig['db_host'];
$db_name = (string)$dbConfig['db_name'];
$db_user = (string)$dbConfig['db_user'];
$db_pass = (string)$dbConfig['db_pass'];

/*
|--------------------------------------------------------------------------
| Conexiune PDO
|--------------------------------------------------------------------------
*/
try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );

    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET CHARACTER SET utf8mb4");

} catch (PDOException $e) {
    error_log('Emma DB connection error: ' . $e->getMessage());
    die('Eroare conexiune baza de date.');
}

/*
|--------------------------------------------------------------------------
| Helpers autentificare
|--------------------------------------------------------------------------
*/
function is_logged_in(): bool
{
    return !empty($_SESSION['user_role']) &&
        (
            !empty($_SESSION['user_id']) ||
            !empty($_SESSION['team_member_id'])
        );
}

function require_login(): void
{
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}

function is_admin(): bool
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function is_team_user(): bool
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'team';
}

function current_user_id(): ?int
{
    return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function current_team_id(): ?int
{
    return !empty($_SESSION['team_member_id']) ? (int)$_SESSION['team_member_id'] : null;
}

function current_user_name(): string
{
    if (!empty($_SESSION['user_name'])) {
        return (string)$_SESSION['user_name'];
    }

    if (!empty($_SESSION['team_member_name'])) {
        return (string)$_SESSION['team_member_name'];
    }

    return 'Utilizator';
}

function current_user_email(): string
{
    return !empty($_SESSION['user_email']) ? (string)$_SESSION['user_email'] : '';
}

/*
|--------------------------------------------------------------------------
| Helper text sigur (escape HTML)
|--------------------------------------------------------------------------
*/
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
| Foloseste:
|   <?= csrf_field() ?>     in interiorul fiecarui <form method="post">
|   csrf_require();          la inceputul handler-ului POST
|--------------------------------------------------------------------------
*/
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_require(): void
{
    if (!csrf_check()) {
        http_response_code(419);
        die('Cerere invalida (CSRF token expirat). Reincarca pagina si reincearca.');
    }
}
