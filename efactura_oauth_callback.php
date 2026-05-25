<?php
/*
|--------------------------------------------------------------------------
| OAuth callback ANAF e-Factura
|--------------------------------------------------------------------------
| Endpoint apelat de ANAF după ce utilizatorul se loghează cu certificatul
| digital pe https://logincert.anaf.ro/anaf-oauth2/v1/authorize.
|
| Primește în query string: ?code=...&state=...
| Verifică state (CSRF), schimbă codul pe access_token + refresh_token,
| salvează în BD și redirectează la efactura_settings.php cu mesaj.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/lib/anaf_efactura_lib.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    http_response_code(403);
    exit('Acces interzis.');
}

anaf_efactura_ensure_schema($pdo);

$code = trim((string)($_GET['code'] ?? ''));
$state = trim((string)($_GET['state'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$errorDescription = trim((string)($_GET['error_description'] ?? ''));

// Caz 1: ANAF a întors eroare la login
if ($error !== '') {
    $msg = $error . ($errorDescription ? ': ' . $errorDescription : '');
    header('Location: efactura_settings.php?oauth_error=' . urlencode($msg));
    exit;
}

// Caz 2: lipsește codul
if ($code === '') {
    header('Location: efactura_settings.php?oauth_error=' . urlencode('Cod OAuth lipsa.'));
    exit;
}

// Verificare state CSRF
$expectedState = (string)($_SESSION['anaf_oauth_state'] ?? '');
if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    header('Location: efactura_settings.php?oauth_error=' . urlencode('State invalid (posibil atac CSRF). Reincearca.'));
    exit;
}
unset($_SESSION['anaf_oauth_state']);

// Schimbă codul pe token
$settings = anaf_efactura_settings($pdo);
$result = anaf_efactura_exchange_code($pdo, $settings, $code);

if (!$result['ok']) {
    header('Location: efactura_settings.php?oauth_error=' . urlencode((string)($result['error'] ?? 'Eroare necunoscuta.')));
    exit;
}

header('Location: efactura_settings.php?oauth_success=1');
exit;
