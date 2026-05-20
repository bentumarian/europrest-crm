<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/password_reset_lib.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = false;

try {
    pr_ensure_schema($pdo);
} catch (Throwable $e) {
    error_log('Password reset schema error: ' . $e->getMessage());
    $error = 'Nu se poate initializa resetarea parolei. Contacteaza administratorul.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!csrf_check()) {
        $error = 'Sesiune expirata. Reincarca pagina si reincearca.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Introdu o adresa de email valida.';
        } else {
            $success = true;
            try {
                $account = pr_find_account_by_email($pdo, $email);
                if ($account) {
                    $reset = pr_create_reset($pdo, $account);
                    $sent = pr_send_reset_email($account['email'], $account['name'], $reset['link']);
                    if (!$sent) {
                        error_log('Password reset email could not be sent to: ' . $account['email'] . ' link: ' . $reset['link']);
                    }
                }
            } catch (Throwable $e) {
                error_log('Password reset request error: ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Resetare parola</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
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
    --danger-bg: #FFF4F1;
    --danger-border: rgba(210, 71, 38, .24);
    --danger-text: #D24726;
    --success-bg: #F0FDF4;
    --success-border: #BBF7D0;
    --success-text: #047857;
    --shadow-3d: 0 34px 90px rgba(0, 32, 80, .20), 0 14px 34px rgba(0, 32, 80, .10);
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
}
.login-card {
    width: 100%;
    position: relative;
    background: linear-gradient(145deg, rgba(255,255,255,.88) 0%, rgba(255,255,255,.72) 54%, rgba(177,214,240,.24) 100%);
    border: 1px solid rgba(255,255,255,.86);
    border-top-color: rgba(177,214,240,.78);
    border-radius: var(--radius);
    box-shadow: var(--shadow-3d);
    padding: 32px 32px 28px;
    overflow: hidden;
    backdrop-filter: blur(22px) saturate(150%);
    -webkit-backdrop-filter: blur(22px) saturate(150%);
}
.login-card::before {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    top: 0;
    height: 7px;
    background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 52%, rgba(255,255,255,.80) 100%);
}
.login-card::after {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: radial-gradient(circle at 50% 0%, rgba(255,255,255,.92), transparent 34%), linear-gradient(135deg, rgba(255,255,255,.55) 0%, transparent 45%);
    opacity: .78;
    z-index: 0;
}
.login-card > * { position: relative; z-index: 1; }
.brand { display: flex; align-items: center; justify-content: center; margin: 2px 0 24px; }
.brand-badge {
    width: 78px;
    height: 78px;
    border-radius: 28px;
    background: linear-gradient(145deg, #1772D3 0%, var(--primary) 58%, #0B4E9C 100%);
    border: 1px solid rgba(255,255,255,.42);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 20px 42px rgba(0, 32, 80, .28), inset 0 1px 0 rgba(255,255,255,.38), inset 0 -12px 22px rgba(0,32,80,.12);
}
.brand-badge img { display: block; width: 53px; height: 53px; object-fit: contain; filter: drop-shadow(0 5px 10px rgba(0,32,80,.18)); }
.login-title { margin: 0; text-align: center; font-size: 25px; line-height: 1.18; letter-spacing: -.045em; font-weight: 900; color: var(--text); }
.login-subtitle { margin: 10px 0 23px; text-align: center; font-size: 14px; line-height: 1.5; color: var(--muted); font-weight: 600; }
.form-group { margin-bottom: 16px; }
label { display: block; margin-bottom: 8px; color: var(--text); font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: .085em; }
.input-wrap { position: relative; }
.input-icon { position: absolute; left: 16px; top: 50%; width: 18px; height: 18px; transform: translateY(-50%); color: var(--secondary); pointer-events: none; }
.input-icon svg { display: block; width: 18px; height: 18px; stroke: currentColor; stroke-width: 1.95; fill: none; stroke-linecap: round; stroke-linejoin: round; }
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
    box-shadow: inset 0 1px 0 rgba(255,255,255,.80), 0 10px 22px rgba(0,32,80,.035);
}
input::placeholder { color: #7F94A8; font-weight: 600; }
input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(17,96,183,.15), inset 0 1px 0 rgba(255,255,255,.88), 0 14px 30px rgba(0,32,80,.08); background: rgba(255,255,255,.92); }
.login-button {
    width: 100%;
    min-height: 54px;
    border: 1px solid rgba(255,255,255,.38);
    border-radius: 18px;
    background: linear-gradient(145deg, #1772D3 0%, var(--primary) 58%, #0B4E9C 100%);
    color: #fff;
    font-family: var(--font);
    font-size: 14px;
    font-weight: 900;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow: 0 20px 38px rgba(17,96,183,.25), inset 0 1px 0 rgba(255,255,255,.28), inset 0 -12px 22px rgba(0,32,80,.13);
}
.login-button svg { width: 18px; height: 18px; stroke: currentColor; stroke-width: 2.2; fill: none; stroke-linecap: round; stroke-linejoin: round; }
.msg { border-radius: 18px; padding: 12px 14px; margin-bottom: 18px; font-size: 13px; font-weight: 800; line-height: 1.45; }
.err { background: rgba(255,244,241,.88); border: 1px solid var(--danger-border); color: var(--danger-text); }
.ok { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success-text); }
.back { display: block; text-align: center; margin-top: 18px; color: var(--primary); font-weight: 900; text-decoration: none; font-size: 13px; }
.back:hover { text-decoration: underline; }
.security-line { margin-top: 14px; display: flex; justify-content: center; align-items: center; gap: 8px; color: var(--secondary); font-size: 12px; font-weight: 800; }
.security-line svg { width: 16px; height: 16px; stroke: currentColor; stroke-width: 1.9; fill: none; stroke-linecap: round; stroke-linejoin: round; }
@media (max-width: 520px) {
    body { align-items: center; padding: 22px 0; }
    .login-shell { width: calc(100vw - 42px); max-width: 390px; }
    .login-card { padding: 26px 20px 23px; border-radius: 28px; }
    .brand-badge { width: 70px; height: 70px; border-radius: 24px; }
    .brand-badge img { width: 48px; height: 48px; }
    .login-title { font-size: 23px; }
}

/* ══ Design System v2.4 fixes — forgot_password ══ */
/* Aliniere cu login.php: flat, fara glassmorphism */

body.login {
    background: var(--pz-soft, #F8FAFC) !important;
}
body.login::before { display: none !important; }
body.login::after { display: none !important; }

.login-card {
    background: var(--pz-surf, #FFFFFF) !important;
    border: 1px solid var(--pz-line, #E2E8F0) !important;
    box-shadow: none !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
}
.login-card::before { display: none !important; }
.login-card::after { display: none !important; }

.brand-badge {
    background: var(--pz-bl, #2563EB) !important;
    box-shadow: none !important;
}
.brand-badge::before { display: none !important; }

.login-form input,
.login-form .input {
    background: var(--pz-surf, #FFFFFF) !important;
    border: 1px solid var(--pz-line, #E2E8F0) !important;
    box-shadow: none !important;
}
.login-form input:focus,
.login-form .input:focus {
    border-color: var(--pz-bl, #2563EB) !important;
    box-shadow: 0 0 0 3px var(--pz-bls, #EFF6FF) !important;
    background: var(--pz-surf, #FFFFFF) !important;
}

.btn-primary, .login-form .btn, button[type="submit"] {
    background: var(--pz-bl, #2563EB) !important;
    border: 1px solid var(--pz-bl, #2563EB) !important;
    box-shadow: none !important;
    color: #fff !important;
}
.btn-primary:hover, .login-form .btn:hover, button[type="submit"]:hover {
    background: var(--pz-bld, #1E3A8A) !important;
    border-color: var(--pz-bld, #1E3A8A) !important;
    transform: none !important;
}

</style>
</head>
<body>

<main class="login-shell">
    <div class="login-card">
        <div class="brand">
            <div class="brand-badge" aria-hidden="true">
                <img src="assets/brand-icon.png" alt="">
            </div>
        </div>

        <h1 class="login-title">Resetare parola</h1>
        <p class="login-subtitle">Introdu emailul contului. Dacă există un cont activ, vei primi un link pentru setarea unei parole noi.</p>

        <?php if ($error): ?><div class="msg err"><?= pr_h($error) ?></div><?php endif; ?>

        <?php if ($success): ?>
            <div class="msg ok">Dacă există un cont activ pentru acest email, am trimis instructiunile de resetare. Verifica inbox-ul si folderul Spam.</div>
        <?php else: ?>
            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="email">Email cont</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"></path><path d="m4 7 8 6 8-6"></path></svg></span>
                        <input id="email" type="email" name="email" required autocomplete="email" value="<?= pr_h($_POST['email'] ?? '') ?>" placeholder="email@domeniu.ro">
                    </div>
                </div>
                <button class="login-button" type="submit">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h12"></path><path d="m13 6 6 6-6 6"></path></svg>
                    <span>Trimite link resetare</span>
                </button>
            </form>
        <?php endif; ?>

        <a class="back" href="login.php">Înapoi la autentificare</a>
        <div class="security-line" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 5 3.2 8.5 7 10 3.8-1.5 7-5 7-10V6l-7-3Z"></path><path d="m9.5 12 1.8 1.8 3.6-4"></path></svg>
            <span>Link valabil 60 minute</span>
        </div>
    </div>
</main>

</body>
</html>