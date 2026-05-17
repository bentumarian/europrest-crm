<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/notification_lib.php';

if (function_exists('require_login')) {
    require_login();
}

// Super Admin protection if your project has super_admin_guard.php.
if (file_exists(__DIR__ . '/super_admin_guard.php')) {
    require_once __DIR__ . '/super_admin_guard.php';
    if (function_exists('require_super_admin')) {
        require_super_admin();
    }
} elseif (function_exists('is_admin') && !is_admin()) {
    http_response_code(403);
    exit('Acces permis doar administratorului.');
}

pz_notify_init();

$success = [];
$errors = [];

function pz_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) {
        csrf_require();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $fields = [
            'sendgrid_api_key',
            'sendgrid_region',
            'email_from_address',
            'email_from_name',
            'email_reply_to',
            'smslink_connection_id',
            'smslink_password',
            'smslink_enabled',
            'sms_default_test_phone'
        ];

        foreach ($fields as $field) {
            $value = $_POST[$field] ?? '';
            if ($field === 'sendgrid_api_key' && trim($value) === '********') {
                continue;
            }
            if ($field === 'smslink_password' && trim($value) === '********') {
                continue;
            }
            pz_setting_set($field, $value);
        }

        $success[] = 'Setările au fost salvate.';
    }

    if ($action === 'test_email') {
        $to = trim($_POST['test_email_to'] ?? '');
        if ($to === '') {
            $errors[] = 'Completează adresa pentru emailul de test.';
        } else {
            $res = pz_sendgrid_send_email(
                $to,
                'Test email CRM',
                '<p>Acesta este un email de test trimis din CRM prin SendGrid.</p>',
                'Acesta este un email de test trimis din CRM prin SendGrid.',
                [],
                'test',
                null
            );
            $res['ok'] ? $success[] = 'Emailul de test a fost trimis.' : $errors[] = 'Email test esuat: ' . ($res['error'] ?? 'eroare necunoscuta');
        }
    }

    if ($action === 'test_sms') {
        $to = trim($_POST['test_sms_to'] ?? '');
        if ($to === '') {
            $errors[] = 'Completează telefonul pentru SMS-ul de test.';
        } else {
            // Param 6 = allowWithoutClient = true (e SMS de test, nu pentru un client anume)
            $res = pz_smslink_send_sms($to, 'EUROPREST: SMS de test trimis din CRM prin SMSLink.', 'test', null, null, true);
            $res['ok'] ? $success[] = 'SMS-ul de test a fost trimis catre gateway.' : $errors[] = 'SMS test esuat: ' . ($res['error'] ?? 'eroare necunoscuta');
        }
    }
}

$logs = [];
try {
    $logs = pz_db()->query("SELECT * FROM notification_logs ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$csrf = function_exists('csrf_field') ? csrf_field() : '';
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Comunicare / Integrări</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
</head>
<body>
<div class="layout">
    <?php
    $pz_page_title = 'Setări';
    $pz_page_breadcrumbs = ['Comunicare / Integrări'];
    render_sidebar('communication_settings', true);
    ?>
    <main class="main">
        <div class="topbar" style="padding:12px 20px;"><a href="settings.php" class="btn ghost">Înapoi la Setări</a></div>
        <div class="content settings-module-page">
    <div class="module-head">
        <div>
            <h1>Comunicare / Integrări</h1>
            <p>SendGrid pentru email si SMSLink.ro pentru SMS-uri tranzactionale.</p>
        </div>
    </div>

    <?php foreach ($success as $msg): ?><div class="alert ok"><?= pz_h($msg) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $msg): ?><div class="alert err"><?= pz_h($msg) ?></div><?php endforeach; ?>

    <form method="post">
        <?= $csrf ?>
        <input type="hidden" name="action" value="save">
        <div class="grid">
            <div class="card">
                <h2>Email - SendGrid</h2>
                <p class="muted">Folosit pentru resetare parola, contracte, procese verbale si notificări.</p>

                <label>API Key SendGrid</label>
                <input type="password" name="sendgrid_api_key" value="<?= pz_setting_get('sendgrid_api_key') ? '********' : '' ?>" autocomplete="off">

                <label>Regiune SendGrid</label>
                <select name="sendgrid_region">
                    <?php $region = pz_setting_get('sendgrid_region', 'global'); ?>
                    <option value="global" <?= $region === 'global' ? 'selected' : '' ?>>Global - api.sendgrid.com</option>
                    <option value="eu" <?= $region === 'eu' ? 'selected' : '' ?>>EU - api.eu.sendgrid.com</option>
                </select>

                <div class="row">
                    <div>
                        <label>Email expeditor</label>
                        <input type="email" name="email_from_address" value="<?= pz_h(pz_setting_get('email_from_address')) ?>" placeholder="office@domeniu.ro">
                    </div>
                    <div>
                        <label>Nume expeditor</label>
                        <input type="text" name="email_from_name" value="<?= pz_h(pz_setting_get('email_from_name', 'EuroPrest')) ?>">
                    </div>
                </div>

                <label>Reply-To</label>
                <input type="email" name="email_reply_to" value="<?= pz_h(pz_setting_get('email_reply_to')) ?>" placeholder="office@domeniu.ro">
            </div>

            <div class="card">
                <h2>SMS - SMSLink.ro</h2>
                <p class="muted">Folosit pentru confirmari si remindere programări.</p>

                <label>Status SMS</label>
                <?php $enabled = pz_setting_get('smslink_enabled', '1'); ?>
                <select name="smslink_enabled">
                    <option value="1" <?= $enabled === '1' ? 'selected' : '' ?>>Activ</option>
                    <option value="0" <?= $enabled === '0' ? 'selected' : '' ?>>Dezactivat</option>
                </select>

                <label>Connection ID</label>
                <input type="text" name="smslink_connection_id" value="<?= pz_h(pz_setting_get('smslink_connection_id')) ?>">

                <label>Password SMS Gateway</label>
                <input type="password" name="smslink_password" value="<?= pz_setting_get('smslink_password') ? '********' : '' ?>" autocomplete="off">

                <label>Telefon test implicit</label>
                <input type="text" name="sms_default_test_phone" value="<?= pz_h(pz_setting_get('sms_default_test_phone')) ?>" placeholder="07xxxxxxxx">
            </div>

            <div class="card full">
                <button class="btn accent" type="submit">Salvează setarile</button>
            </div>
        </div>
    </form>

    <div class="grid" style="margin-top:18px">
        <div class="card">
            <h2>Test email</h2>
            <form method="post">
                <?= $csrf ?>
                <input type="hidden" name="action" value="test_email">
                <label>Trimite email de test catre</label>
                <input type="email" name="test_email_to" value="<?= pz_h(pz_setting_get('email_reply_to')) ?>" placeholder="email@domeniu.ro">
                <div style="margin-top:12px"><button class="btn accent" type="submit">Trimite test email</button></div>
            </form>
        </div>

        <div class="card">
            <h2>Test SMS</h2>
            <form method="post">
                <?= $csrf ?>
                <input type="hidden" name="action" value="test_sms">
                <label>Trimite SMS de test catre</label>
                <input type="text" name="test_sms_to" value="<?= pz_h(pz_setting_get('sms_default_test_phone')) ?>" placeholder="07xxxxxxxx">
                <div style="margin-top:12px"><button class="btn accent" type="submit">Trimite test SMS</button></div>
            </form>
        </div>

        <div class="card full">
            <h2>Ultimele notificări</h2>
            <table>
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Canal</th>
                    <th>Destinatar</th>
                    <th>Status</th>
                    <th>Raspuns</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="5" class="muted">Nu există notificări trimise inca.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= pz_h($log['created_at'] ?? '') ?></td>
                        <td><?= pz_h($log['channel'] ?? '') ?> / <?= pz_h($log['provider'] ?? '') ?></td>
                        <td><?= pz_h($log['recipient'] ?? '') ?></td>
                        <td><?= pz_h($log['status'] ?? '') ?> <?= $log['http_code'] ? '(' . (int)$log['http_code'] . ')' : '' ?></td>
                        <td><?= pz_h(mb_substr((string)($log['provider_response'] ?? ''), 0, 180)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
        </div>
    </main>
</div>
</body>
</html>
