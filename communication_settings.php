<?php
require_once __DIR__ . '/config.php';
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
            $res['ok'] ? $success[] = 'Emailul de test a fost trimis.' : $errors[] = 'Email test eșuat: ' . ($res['error'] ?? 'eroare necunoscută');
        }
    }

    if ($action === 'test_sms') {
        $to = trim($_POST['test_sms_to'] ?? '');
        if ($to === '') {
            $errors[] = 'Completează telefonul pentru SMS-ul de test.';
        } else {
            // Param 6 = allowWithoutClient = true (e SMS de test, nu pentru un client anume)
            $res = pz_smslink_send_sms($to, 'EUROPREST: SMS de test trimis din CRM prin SMSLink.', 'test', null, null, true);
            $res['ok'] ? $success[] = 'SMS-ul de test a fost trimis către gateway.' : $errors[] = 'SMS test eșuat: ' . ($res['error'] ?? 'eroare necunoscută');
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
    <style>
        :root{--brand:#0071A3;--bg:#f5f7fa;--border:#dbe3ea;--text:#132238;--muted:#64748b}
        body{margin:0;background:var(--bg);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;color:var(--text);font-size:14px}
        .wrap{max-width:1180px;margin:0 auto;padding:24px}
        .top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:18px}
        h1{font-size:22px;margin:0}
        .muted{color:var(--muted)}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
        .card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:18px;box-shadow:0 1px 2px rgba(16,24,40,.03)}
        label{display:block;font-size:12px;font-weight:700;margin:12px 0 6px;color:#334155}
        input,select,textarea{width:100%;border:1px solid var(--border);border-radius:12px;padding:10px 12px;font:inherit;box-sizing:border-box;background:#fff}
        .btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:12px;padding:10px 14px;background:#fff;color:var(--text);text-decoration:none;font-weight:700;cursor:pointer}
        .btn-primary{background:var(--brand);border-color:var(--brand);color:#fff}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .alert{padding:12px 14px;border-radius:12px;margin-bottom:10px}
        .ok{background:#ecfdf3;color:#027a48;border:1px solid #abefc6}
        .err{background:#fff1f3;color:#b42318;border:1px solid #fecdd3}
        table{width:100%;border-collapse:collapse;font-size:13px}
        th,td{border-bottom:1px solid var(--border);padding:9px;text-align:left;vertical-align:top}
        th{font-size:11px;text-transform:uppercase;color:var(--muted);letter-spacing:.04em}
        .full{grid-column:1/-1}
        @media(max-width:860px){.grid{grid-template-columns:1fr}.row{grid-template-columns:1fr}.wrap{padding:16px}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Comunicare / Integrări</h1>
            <div class="muted">SendGrid pentru email și SMSLink.ro pentru SMS-uri tranzacționale.</div>
        </div>
        <a href="settings.php" class="btn">Înapoi la Setări</a>
    </div>

    <?php foreach ($success as $msg): ?><div class="alert ok"><?= pz_h($msg) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $msg): ?><div class="alert err"><?= pz_h($msg) ?></div><?php endforeach; ?>

    <form method="post">
        <?= $csrf ?>
        <input type="hidden" name="action" value="save">
        <div class="grid">
            <div class="card">
                <h2 style="margin-top:0;font-size:17px">Email — SendGrid</h2>
                <p class="muted">Folosit pentru resetare parolă, contracte, procese verbale, notificări.</p>

                <label>API Key SendGrid</label>
                <input type="password" name="sendgrid_api_key" value="<?= pz_setting_get('sendgrid_api_key') ? '********' : '' ?>" autocomplete="off">

                <label>Regiune SendGrid</label>
                <select name="sendgrid_region">
                    <?php $region = pz_setting_get('sendgrid_region', 'global'); ?>
                    <option value="global" <?= $region === 'global' ? 'selected' : '' ?>>Global — api.sendgrid.com</option>
                    <option value="eu" <?= $region === 'eu' ? 'selected' : '' ?>>EU — api.eu.sendgrid.com</option>
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
                <h2 style="margin-top:0;font-size:17px">SMS — SMSLink.ro</h2>
                <p class="muted">Folosit pentru confirmări și remindere programări.</p>

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
                <button class="btn btn-primary" type="submit">Salvează setările</button>
            </div>
        </div>
    </form>

    <div class="grid" style="margin-top:18px">
        <div class="card">
            <h2 style="margin-top:0;font-size:17px">Test email</h2>
            <form method="post">
                <?= $csrf ?>
                <input type="hidden" name="action" value="test_email">
                <label>Trimite email de test către</label>
                <input type="email" name="test_email_to" value="<?= pz_h(pz_setting_get('email_reply_to')) ?>" placeholder="email@domeniu.ro">
                <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Trimite test email</button></div>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-top:0;font-size:17px">Test SMS</h2>
            <form method="post">
                <?= $csrf ?>
                <input type="hidden" name="action" value="test_sms">
                <label>Trimite SMS de test către</label>
                <input type="text" name="test_sms_to" value="<?= pz_h(pz_setting_get('sms_default_test_phone')) ?>" placeholder="07xxxxxxxx">
                <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Trimite test SMS</button></div>
            </form>
        </div>

        <div class="card full">
            <h2 style="margin-top:0;font-size:17px">Ultimele notificări</h2>
            <table>
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Canal</th>
                    <th>Destinatar</th>
                    <th>Status</th>
                    <th>Răspuns</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="5" class="muted">Nu există notificări trimise încă.</td></tr>
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
</body>
</html>
