<?php
/*
|--------------------------------------------------------------------------
| Setări ANAF e-Factura
|--------------------------------------------------------------------------
| Configurarea conexiunii directe la SPV ANAF:
|  - Client ID / Client Secret (obținute din portalul anaf.ro)
|  - CIF firma
|  - Buton „Conectează la ANAF" → autorizare OAuth cu certificat digital
|  - Vizualizare status token + buton reconectare
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/anaf_efactura_lib.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

anaf_efactura_ensure_schema($pdo);

function efs_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) {
        csrf_require();
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        anaf_efactura_save_settings($pdo, $_POST);
        header('Location: efactura_settings.php?saved=1');
        exit;
    }

    if ($action === 'connect_anaf') {
        $settings = anaf_efactura_settings($pdo);
        if (($settings['anaf_efactura.client_id'] ?? '') === '' || ($settings['anaf_efactura.client_secret'] ?? '') === '' || ($settings['anaf_efactura.cif'] ?? '') === '') {
            header('Location: efactura_settings.php?error=' . urlencode('Completeaza si salveaza Client ID, Client Secret si CIF inainte de conectare.'));
            exit;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['anaf_oauth_state'] = $state;
        header('Location: ' . anaf_efactura_auth_url($settings, $state));
        exit;
    }

    if ($action === 'disconnect_anaf') {
        $settings = anaf_efactura_settings($pdo);
        $stmt = $pdo->prepare("DELETE FROM anaf_oauth_tokens WHERE cif = ?");
        $stmt->execute([(string)($settings['anaf_efactura.cif'] ?? '')]);
        header('Location: efactura_settings.php?disconnected=1');
        exit;
    }
}

if (isset($_GET['saved'])) $success = 'Setarile au fost salvate.';
if (isset($_GET['oauth_success'])) $success = 'Conexiunea ANAF a fost stabilita cu succes.';
if (isset($_GET['disconnected'])) $success = 'Conexiunea ANAF a fost deconectata.';
if (isset($_GET['error'])) $error = (string)$_GET['error'];
if (isset($_GET['oauth_error'])) $error = 'Eroare OAuth: ' . (string)$_GET['oauth_error'];

$settings = anaf_efactura_settings($pdo);
$tokenStatus = anaf_efactura_token_status($pdo, (string)($settings['anaf_efactura.cif'] ?? ''));
$redirectUri = anaf_efactura_redirect_uri();
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Setări ANAF e-Factura</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        .ef-set-page{max-width:none;margin:0;display:grid;gap:10px}
        .hero{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:4px 0 2px}
        .hero h1{margin:0;font-size:22px;font-weight:700;color:var(--text);letter-spacing:-.02em}
        .hero p{margin:4px 0 0;color:var(--pz-mu);font-weight:600;font-size:12px}

        .panel{background:var(--pz-surf);border:1px solid var(--pz-line);border-radius:var(--pz-r);box-shadow:none}
        .panel-head{padding:14px 16px;border-bottom:1px solid var(--pz-lines);display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
        .panel-title{font-size:14px;font-weight:800;color:var(--text)}
        .panel-subtitle{font-size:12px;color:var(--pz-mu);margin-top:2px}
        .panel-body{padding:14px 16px}

        .alert{border-radius:var(--pz-rs);padding:10px 13px;font-weight:600;font-size:12.5px;margin-bottom:0}
        .alert.ok{background:var(--pz-grs);color:var(--pz-gr);border:1px solid var(--pz-grb)}
        .alert.err{background:var(--pz-res);color:var(--pz-re);border:1px solid var(--pz-reb)}

        .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .full{grid-column:1/-1}
        label{display:block;font-size:10px;font-weight:800;margin:3px 0 4px;color:var(--pz-mu);text-transform:uppercase;letter-spacing:.04em}
        input,select{width:100%;min-height:34px;border:1px solid var(--pz-line);border-radius:var(--pz-rs);padding:7px 10px;font:inherit;font-size:12.5px;font-weight:600;background:#fff;color:var(--text)}
        input:focus,select:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 3px var(--accent-soft)}
        .form-help{font-size:11.5px;color:var(--pz-mu);font-weight:500;margin-top:3px}

        .status-card{display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;padding:14px 16px;border-radius:var(--pz-r);border:1px solid var(--pz-line);background:var(--pz-surf)}
        .status-dot{width:14px;height:14px;border-radius:50%;background:var(--pz-mu)}
        .status-dot.connected{background:var(--pz-gr)}
        .status-dot.expired{background:var(--pz-or)}
        .status-text strong{font-size:14px;font-weight:800;color:var(--text)}
        .status-text span{display:block;font-size:12px;color:var(--pz-mu);font-weight:600;margin-top:2px}

        .steps{display:grid;gap:10px;counter-reset:step}
        .step{display:grid;grid-template-columns:32px 1fr;gap:10px;align-items:start;padding:12px;background:var(--pz-soft);border-radius:var(--pz-r);border:1px solid var(--pz-line)}
        .step::before{counter-increment:step;content:counter(step);display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:var(--accent);color:#fff;font-weight:800;font-size:13px}
        .step strong{display:block;font-size:13px;color:var(--text);margin-bottom:4px}
        .step p{margin:0;font-size:12px;color:var(--pz-mu);line-height:1.5;font-weight:600}
        .step code{background:var(--pz-surf);border:1px solid var(--pz-line);padding:2px 6px;border-radius:4px;font-size:11.5px;color:var(--accent-deep);word-break:break-all}
        .step a{color:var(--accent-deep);font-weight:700;text-decoration:underline}

        @media(max-width:720px){.grid{grid-template-columns:1fr}.status-card{grid-template-columns:auto 1fr;row-gap:8px}.status-card .actions{grid-column:1/-1}}
    </style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('efactura', true); ?>
    <main class="main">
        <div class="content ef-set-page">
            <section class="hero">
                <div>
                    <h1>Setări ANAF e-Factura</h1>
                    <p>Configurarea conexiunii directe la SPV ANAF pentru descărcarea facturilor primite și sincronizarea celor trimise.</p>
                </div>
                <a class="btn ghost" href="efactura.php">Înapoi la e-Factura</a>
            </section>

            <?php render_billing_module_nav('efactura'); ?>

            <?php if ($success !== ''): ?><div class="alert ok"><?= efs_h($success) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert err"><?= efs_h($error) ?></div><?php endif; ?>

            <!-- Status conexiune -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Status conexiune</div>
                        <div class="panel-subtitle">Token-ul OAuth obținut prin autentificare cu certificat digital pe ANAF.</div>
                    </div>
                </div>
                <div class="panel-body">
                    <?php
                    $dotClass = 'disconnected';
                    $statusText = 'Neconectat';
                    $statusDetail = 'Nu există un token activ. Completează Client ID, Client Secret și CIF, apoi apasă „Conectează la ANAF".';
                    if (!empty($tokenStatus['connected'])) {
                        $daysLeft = (int)($tokenStatus['days_left'] ?? 0);
                        if ($daysLeft > 5) {
                            $dotClass = 'connected';
                            $statusText = 'Conectat';
                            $statusDetail = 'Token valid încă ' . $daysLeft . ' zile (până la ' . efs_h($tokenStatus['expires_at']) . ').';
                        } else {
                            $dotClass = 'expired';
                            $statusText = $daysLeft <= 0 ? 'Token expirat' : 'Token expiră curând';
                            $statusDetail = 'Token expiră în ' . $daysLeft . ' zile. Cron-ul de sincronizare va încerca refresh automat.';
                        }
                    }
                    ?>
                    <div class="status-card">
                        <span class="status-dot <?= efs_h($dotClass) ?>"></span>
                        <div class="status-text">
                            <strong><?= efs_h($statusText) ?></strong>
                            <span><?= efs_h($statusDetail) ?></span>
                        </div>
                        <div class="actions" style="display:flex;gap:6px;flex-wrap:wrap">
                            <?php if (!empty($tokenStatus['connected'])): ?>
                                <form method="post" style="margin:0">
                                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                    <input type="hidden" name="action" value="connect_anaf">
                                    <button class="btn ghost" type="submit">Reconectează</button>
                                </form>
                                <form method="post" style="margin:0" onsubmit="return confirm('Sigur deconectezi CRM-ul de la ANAF? Cron-ul de sincronizare nu va mai funcționa pana la o nouă autentificare.');">
                                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                    <input type="hidden" name="action" value="disconnect_anaf">
                                    <button class="btn ghost" type="submit" style="color:var(--pz-re)">Deconectează</button>
                                </form>
                            <?php else: ?>
                                <form method="post" style="margin:0">
                                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                    <input type="hidden" name="action" value="connect_anaf">
                                    <button class="btn accent" type="submit">Conectează la ANAF</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Pașii de configurare la ANAF -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Cum obții Client ID și Client Secret de la ANAF</div>
                        <div class="panel-subtitle">Procedura de înregistrare a aplicației — durează 1-5 zile lucrătoare.</div>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="steps">
                        <div class="step">
                            <div>
                                <strong>Loghează-te pe anaf.ro cu certificatul digital</strong>
                                <p>Accesează <a href="https://www.anaf.ro/InregOauth/" target="_blank" rel="noopener">https://www.anaf.ro/InregOauth/</a> și autentifică-te cu certificatul tău digital calificat (certSign, Trans Sped, DigiSign etc.) instalat în browser.</p>
                            </div>
                        </div>
                        <div class="step">
                            <div>
                                <strong>Înregistrează o aplicație OAuth nouă</strong>
                                <p>În portal: <em>Servicii online → Înregistrare utilizatori → Oauth → Înregistrare aplicații</em>. Completezi:<br>
                                – <strong>Nume aplicație:</strong> PestZone CRM<br>
                                – <strong>Callback URL:</strong> <code><?= efs_h($redirectUri) ?></code><br>
                                – <strong>Servicii bifate:</strong> RO e-Factura</p>
                            </div>
                        </div>
                        <div class="step">
                            <div>
                                <strong>Așteaptă aprobarea</strong>
                                <p>ANAF verifică cererea manual și aprobă aplicația în 1-5 zile lucrătoare. Vei primi prin email Client ID și Client Secret.</p>
                            </div>
                        </div>
                        <div class="step">
                            <div>
                                <strong>Completează aici Client ID, Client Secret și CIF</strong>
                                <p>După ce primești datele de la ANAF, le pui în formularul de mai jos și salvezi. Apoi apeși „Conectează la ANAF" — vei fi redirecționat să te loghezi cu certificatul. După confirmare, cron-ul preia automat facturile primite zi de zi.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Formular setări -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Credentiale aplicație ANAF</div>
                        <div class="panel-subtitle">Aceste date sunt confidențiale. Client Secret se afișează mascat dacă există deja.</div>
                    </div>
                </div>
                <div class="panel-body">
                    <form method="post">
                        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                        <input type="hidden" name="action" value="save_settings">
                        <div class="grid">
                            <div class="full">
                                <label><input type="checkbox" name="anaf_enabled" value="1" <?= ($settings['anaf_efactura.enabled'] ?? '0') === '1' ? 'checked' : '' ?>> Activează integrarea ANAF e-Factura</label>
                                <div class="form-help">Când e dezactivată, cron-ul nu rulează și butoanele de sincronizare sunt blocate.</div>
                            </div>
                            <div>
                                <label>CIF firmă *</label>
                                <input type="text" name="anaf_cif" value="<?= efs_h($settings['anaf_efactura.cif'] ?? '') ?>" placeholder="ex: 12345678" required>
                                <div class="form-help">Doar cifre, fără „RO". Trebuie să fie CIF înrolat în SPV.</div>
                            </div>
                            <div>
                                <label>Mediu</label>
                                <select name="anaf_environment">
                                    <option value="prod" <?= ($settings['anaf_efactura.environment'] ?? 'prod') === 'prod' ? 'selected' : '' ?>>Producție</option>
                                    <option value="test" <?= ($settings['anaf_efactura.environment'] ?? '') === 'test' ? 'selected' : '' ?>>Test (sandbox ANAF)</option>
                                </select>
                                <div class="form-help">Pentru integrare reală, lasă „Producție". „Test" servește validării.</div>
                            </div>
                            <div class="full">
                                <label>Client ID *</label>
                                <input type="text" name="anaf_client_id" value="<?= efs_h($settings['anaf_efactura.client_id'] ?? '') ?>" placeholder="UUID primit de la ANAF" autocomplete="off">
                            </div>
                            <div class="full">
                                <label>Client Secret *</label>
                                <input type="password" name="anaf_client_secret" value="<?= (string)($settings['anaf_efactura.client_secret'] ?? '') !== '' ? '********' : '' ?>" placeholder="Secret primit de la ANAF" autocomplete="off">
                                <div class="form-help">Lasă „********" dacă nu vrei să schimbi secretul existent.</div>
                            </div>
                            <div>
                                <label>Cron — zile sincronizare</label>
                                <input type="number" name="anaf_sync_days" min="1" max="60" value="<?= efs_h($settings['anaf_efactura.sync_days'] ?? '30') ?>">
                                <div class="form-help">Câte zile în urmă se citesc mesajele ANAF la fiecare rulare cron. Maxim 60.</div>
                            </div>
                            <div>
                                <label><input type="checkbox" name="anaf_auto_sync" value="1" <?= ($settings['anaf_efactura.auto_sync'] ?? '1') === '1' ? 'checked' : '' ?>> Sincronizare automată (cron)</label>
                                <div class="form-help">Bifa permite rularea zilnică din cron. Dacă debifezi, sincronizezi doar manual.</div>
                            </div>
                            <div class="full">
                                <label>Callback URL (pentru aplicația ANAF)</label>
                                <input type="text" value="<?= efs_h($redirectUri) ?>" readonly onclick="this.select()">
                                <div class="form-help">Copiază această valoare exact în câmpul „Callback URL" din portalul ANAF la înregistrarea aplicației.</div>
                            </div>
                        </div>
                        <div style="margin-top:14px;text-align:right">
                            <button class="btn accent" type="submit">Salvează setările</button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Configurare cron -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Configurare cron pe cPanel</div>
                        <div class="panel-subtitle">Cron-ul ține sincronizarea actualizată zi de zi. Recomandat: de 2 ori pe zi.</div>
                    </div>
                </div>
                <div class="panel-body">
                    <p style="font-size:12.5px;color:var(--pz-mu);font-weight:600;margin:0 0 10px">În cPanel → <em>Advanced → Cron Jobs</em>, adaugă:</p>
                    <pre style="background:var(--pz-soft);border:1px solid var(--pz-line);border-radius:var(--pz-rs);padding:10px 12px;font-size:12px;overflow-x:auto;margin:0">0 6,14 * * * /usr/bin/php <?= efs_h(__DIR__) ?>/cron_efactura_sync.php > /dev/null 2>&1</pre>
                    <p style="font-size:11.5px;color:var(--pz-mu);font-weight:500;margin:8px 0 0">Frecvența: la 06:00 și 14:00 zilnic. Schimbă orele dacă vrei o altă cadență.</p>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
