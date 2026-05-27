<?php
/*
|--------------------------------------------------------------------------
| settings_gate.php
|--------------------------------------------------------------------------
| Gate cu parolă pentru pagina principală de Setări (settings.php).
|
| Cum se folosește:
|   În settings.php, după require_login() și app_ui.php:
|     require_once __DIR__ . '/settings_gate.php';
|     pz_settings_gate($pdo);
|
| Comportament:
|   1. Prima vizită (când nu există parolă) → cere setarea unei parole noi.
|   2. Vizite ulterioare → cere parola.
|   3. După deblocare → sesiunea rămâne unlocked 30 min (configurabil).
|   4. Parola se stochează ca hash bcrypt în app_settings.
|
| Nu afectează sub-paginile de setări - acestea își păstrează gardarea
| existentă pe is_admin().
|--------------------------------------------------------------------------
*/

if (!function_exists('pz_settings_gate')) {

    function pz_settings_gate_unlock_minutes(): int
    {
        return 30; // sesiunea rămâne deblocată 30 minute
    }

    function pz_settings_gate_ensure_schema(PDO $pdo): void
    {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS app_settings (
                    setting_key VARCHAR(120) PRIMARY KEY,
                    setting_value TEXT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            error_log('settings_gate ensure_schema: ' . $e->getMessage());
        }
    }

    function pz_settings_gate_get_hash(PDO $pdo): string
    {
        pz_settings_gate_ensure_schema($pdo);
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'settings.gate_password_hash' LIMIT 1");
            $stmt->execute();
            return (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            error_log('settings_gate get_hash: ' . $e->getMessage());
            return '';
        }
    }

    function pz_settings_gate_save_hash(PDO $pdo, string $hash): bool
    {
        pz_settings_gate_ensure_schema($pdo);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO app_settings (setting_key, setting_value)
                VALUES ('settings.gate_password_hash', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$hash]);
            return true;
        } catch (Throwable $e) {
            error_log('settings_gate save_hash: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gate principal — apelat la începutul settings.php.
     * Dacă utilizatorul nu are sesiunea deblocată, afișează formularul
     * și face exit. Altfel, return și pagina părinte continuă normal.
     */
    function pz_settings_gate(PDO $pdo): void
    {
        $hash = pz_settings_gate_get_hash($pdo);
        $unlockMinutes = pz_settings_gate_unlock_minutes();
        $isInitial = ($hash === '');

        // Verifică dacă sesiunea e deja deblocată și nu a expirat.
        $unlocked = !empty($_SESSION['settings_unlocked_until'])
            && (int)$_SESSION['settings_unlocked_until'] > time();

        if ($unlocked && !$isInitial) {
            return; // acces permis, pagina părinte continuă
        }

        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings_gate_action'])) {
            if (function_exists('csrf_require')) {
                csrf_require();
            }
            $action = (string)$_POST['settings_gate_action'];

            if ($action === 'set_initial' && $isInitial) {
                $pw1 = (string)($_POST['gate_pw1'] ?? '');
                $pw2 = (string)($_POST['gate_pw2'] ?? '');
                if (strlen($pw1) < 6) {
                    $error = 'Parola trebuie să aibă minim 6 caractere.';
                } elseif ($pw1 !== $pw2) {
                    $error = 'Cele două parole nu coincid.';
                } else {
                    $newHash = password_hash($pw1, PASSWORD_DEFAULT);
                    if (pz_settings_gate_save_hash($pdo, $newHash)) {
                        $_SESSION['settings_unlocked_until'] = time() + ($unlockMinutes * 60);
                        header('Location: ' . $_SERVER['REQUEST_URI']);
                        exit;
                    }
                    $error = 'Parola nu a putut fi salvată. Verifică log-urile.';
                }
            } elseif ($action === 'unlock' && !$isInitial) {
                $pw = (string)($_POST['gate_pw'] ?? '');
                if (password_verify($pw, $hash)) {
                    $_SESSION['settings_unlocked_until'] = time() + ($unlockMinutes * 60);
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                }
                $error = 'Parolă incorectă.';
                // delay mic anti brute-force (500ms)
                usleep(500000);
            }
        }

        pz_settings_gate_render($isInitial, $error, $unlockMinutes);
        exit;
    }

    function pz_settings_gate_render(bool $isInitial, string $error, int $unlockMinutes): void
    {
        $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        ?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title><?= $isInitial ? 'Stabilește parola Setări' : 'Acces Setări' ?> · <?= h(pz_app_name()) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php if (function_exists('app_theme_css')) app_theme_css(); ?>
<style>
.gate-bg {
    font-family: 'Satoshi', 'Inter', system-ui, -apple-system, sans-serif;
    background: var(--pz-bg);
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
}
.gate-box {
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    padding: 28px 26px;
    max-width: 400px; width: 100%;
}
.gate-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    background: var(--pz-bls); color: var(--pz-bld);
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 16px;
}
.gate-icon svg { width: 24px; height: 24px; }
.gate-title { font-size: 18px; font-weight: 500; color: var(--pz-title); margin: 0 0 6px; }
.gate-sub { font-size: 13px; color: var(--pz-mu); margin: 0 0 18px; line-height: 1.5; }
.gate-form { display: flex; flex-direction: column; gap: 12px; }
.gate-form label {
    font-size: 11px; font-weight: 500; color: var(--pz-mu);
    text-transform: uppercase; letter-spacing: 0.04em;
    display: block; margin-bottom: 4px;
}
.gate-form input {
    width: 100%;
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-rs);
    height: 38px;
    padding: 8px 12px;
    font-size: 14px;
    font-family: inherit;
}
.gate-form input:focus {
    border-color: var(--pz-bl);
    outline: none;
    box-shadow: 0 0 0 3px var(--pz-bls);
}
.gate-btn {
    width: 100%;
    background: var(--pz-bl); color: white;
    border: none;
    border-radius: var(--pz-rs);
    padding: 10px;
    font-size: 14px; font-weight: 500;
    cursor: pointer; font-family: inherit;
    margin-top: 4px;
}
.gate-btn:hover { background: var(--pz-bld); }
.gate-err {
    background: var(--pz-res); color: var(--pz-re);
    border: 1px solid var(--pz-reb);
    border-radius: var(--pz-rs);
    padding: 9px 12px;
    font-size: 13px;
    margin-bottom: 12px;
}
.gate-back {
    display: inline-block;
    margin-top: 16px;
    font-size: 12px;
    color: var(--pz-mu);
    text-decoration: none;
}
.gate-back:hover { color: var(--pz-bl); }
.gate-meta { font-size: 11px; color: var(--pz-fa); margin-top: 10px; }
</style>
</head>
<body class="gate-bg">
<div class="gate-box">
    <div class="gate-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
    </div>
    <?php if ($isInitial): ?>
        <h1 class="gate-title">Stabilește parola pentru Setări</h1>
        <p class="gate-sub">Prima accesare. Alege o parolă care va fi necesară pentru orice acces ulterior la zona de setări.</p>
    <?php else: ?>
        <h1 class="gate-title">Acces Setări</h1>
        <p class="gate-sub">Introdu parola pentru a accesa setările. Sesiunea rămâne deblocată <?= (int)$unlockMinutes ?> minute.</p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="gate-err"><?= $h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="gate-form" autocomplete="off">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <?php if ($isInitial): ?>
            <input type="hidden" name="settings_gate_action" value="set_initial">
            <div>
                <label for="gate_pw1">Parolă nouă (minim 6 caractere)</label>
                <input type="password" id="gate_pw1" name="gate_pw1" required minlength="6" autofocus autocomplete="new-password">
            </div>
            <div>
                <label for="gate_pw2">Confirmă parola</label>
                <input type="password" id="gate_pw2" name="gate_pw2" required minlength="6" autocomplete="new-password">
            </div>
            <button type="submit" class="gate-btn">Salvează și intră</button>
        <?php else: ?>
            <input type="hidden" name="settings_gate_action" value="unlock">
            <div>
                <label for="gate_pw">Parolă setări</label>
                <input type="password" id="gate_pw" name="gate_pw" required autofocus autocomplete="current-password">
            </div>
            <button type="submit" class="gate-btn">Intră</button>
        <?php endif; ?>
    </form>

    <a href="dashboard.php" class="gate-back">← Înapoi la Dashboard</a>
</div>
</body>
</html>
        <?php
    }
}
