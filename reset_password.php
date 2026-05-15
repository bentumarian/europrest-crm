<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/password_reset_lib.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = false;
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$reset = null;

try {
    pr_ensure_schema($pdo);
    $reset = pr_get_valid_reset($pdo, $token);
    if (!$reset) {
        $error = 'Linkul de resetare este invalid sau a expirat.';
    }
} catch (Throwable $e) {
    error_log('Password reset validation error: ' . $e->getMessage());
    $error = 'Nu se poate valida linkul de resetare. Contacteaza administratorul.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset && $error === '') {
    if (!csrf_check()) {
        $error = 'Sesiune expirata. Reincarca pagina si reincearca.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password_confirm'] ?? '');
        if (strlen($password) < 8) {
            $error = 'Parola trebuie sa aiba minimum 8 caractere.';
        } elseif ($password !== $password2) {
            $error = 'Parolele introduse nu coincid.';
        } else {
            try {
                pr_update_password($pdo, $reset, $password);
                $success = true;
                $reset = null;
            } catch (Throwable $e) {
                error_log('Password reset update error: ' . $e->getMessage());
                $error = 'Parola nu a putut fi actualizata. Contacteaza administratorul.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Seteaza parola noua</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--brand:#4F46E5;--brand-dark:#4338CA;--text:#0F172A;--muted:#64748B;--border:#E5E7EB;--surface:#FFFFFF;--danger:#DC2626;--success:#047857;--font:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:var(--font);background:linear-gradient(180deg,var(--brand),var(--brand-dark));display:flex;align-items:center;justify-content:center;padding:24px;color:var(--text)}
.card{width:100%;max-width:430px;background:var(--surface);border:1px solid rgba(255,255,255,.25);border-radius:22px;box-shadow:0 24px 70px rgba(2,24,43,.20);padding:30px}.brand{display:flex;justify-content:center;margin-bottom:20px}.brand img{height:68px;width:auto;object-fit:contain}.fallback-logo{width:68px;height:68px;border-radius:20px;background:var(--brand);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:24px}
h1{margin:0 0 8px;text-align:center;font-size:24px;letter-spacing:-.03em}.sub{margin:0 0 22px;text-align:center;color:var(--muted);font-size:14px;line-height:1.55}.field{margin-bottom:16px}label{display:block;margin-bottom:7px;color:var(--muted);font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}input{width:100%;min-height:48px;border:1px solid var(--border);border-radius:14px;padding:0 14px;font:inherit;outline:none}input:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(79,70,229,.18)}.btn{width:100%;min-height:48px;border:1px solid var(--brand);border-radius:14px;background:var(--brand);color:#fff;font:inherit;font-weight:800;cursor:pointer}.btn:hover{background:var(--brand-dark);border-color:var(--brand-dark)}.msg{border-radius:14px;padding:12px 14px;margin-bottom:18px;font-size:14px;line-height:1.5}.err{background:#fff1f2;border:1px solid #fecdd3;color:var(--danger)}.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:var(--success)}.back{display:block;text-align:center;margin-top:18px;color:var(--brand);font-weight:700;text-decoration:none;font-size:14px}.back:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="card">
    <div class="brand">
        <?php if (file_exists(__DIR__ . '/assets/brand-monogram.png')): ?>
            <img src="assets/brand-monogram.png" alt="Brand">
        <?php else: ?>
            <div class="fallback-logo">P</div>
        <?php endif; ?>
    </div>
    <h1>Parola noua</h1>
    <p class="sub">Alege o parola noua pentru contul tau. Linkul poate fi folosit o singura data.</p>
    <?php if ($success): ?>
        <div class="msg ok">Parola a fost schimbata cu succes. Te poti autentifica folosind noua parola.</div>
        <a class="back" href="login.php">Mergi la autentificare</a>
    <?php else: ?>
        <?php if ($error): ?><div class="msg err"><?= pr_h($error) ?></div><?php endif; ?>
        <?php if ($reset): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= pr_h($token) ?>">
                <div class="field"><label>Parola noua</label><input type="password" name="password" required autocomplete="new-password" minlength="8" placeholder="Minimum 8 caractere"></div>
                <div class="field"><label>Confirma parola</label><input type="password" name="password_confirm" required autocomplete="new-password" minlength="8" placeholder="Reintrodu parola"></div>
                <button class="btn" type="submit">Salveaza parola noua</button>
            </form>
        <?php endif; ?>
        <a class="back" href="login.php">Inapoi la autentificare</a>
    <?php endif; ?>
</div>
</body>
</html>
