<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_lib.php';

if (function_exists('require_login')) {
    require_login();
}

if (file_exists(__DIR__ . '/super_admin_guard.php')) {
    require_once __DIR__ . '/super_admin_guard.php';
    if (function_exists('require_super_admin')) {
        require_super_admin();
    }
} elseif (function_exists('is_admin') && !is_admin()) {
    http_response_code(403);
    exit('Acces permis doar administratorului.');
}

function sms_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$success = [];
$errors = [];

try {
    pz_notify_init();
} catch (Throwable $e) {
    $errors[] = 'Eroare inițializare SMS: '.$e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (function_exists('csrf_require')) {
            csrf_require();
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'save_sms_settings') {
            pz_setting_set('sms_brand_name', trim($_POST['sms_brand_name'] ?? 'PestZone') ?: 'PestZone');
            // smslink_enabled NU mai e gestionat aici - se face in Comunicare / Integrari
            // (era duplicat in 2 locuri si crea confuzie)
            $success[] = 'Setările SMS au fost salvate.';
        }

        if ($action === 'save_templates') {
            $templates = $_POST['templates'] ?? [];
            $active = $_POST['template_active'] ?? [];

            foreach ($templates as $key => $body) {
                $isActive = isset($active[$key]) ? 1 : 0;
                $stmt = pz_db()->prepare("
                    UPDATE notification_templates
                    SET body = ?, active = ?, updated_at = NOW()
                    WHERE template_key = ? AND channel = 'sms'
                ");
                $stmt->execute([(string)$body, $isActive, (string)$key]);
            }

            $success[] = 'Șabloanele SMS au fost salvate.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Eroare salvare: '.$e->getMessage();
    }
}

$templates = [];
$logs = [];

try {
    $stmt = pz_db()->prepare("
        SELECT *
        FROM notification_templates
        WHERE channel = 'sms'
          AND template_key IN ('appointment_created_sms','task_expiring_7_sms')
        ORDER BY FIELD(template_key, 'appointment_created_sms','task_expiring_7_sms')
    ");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$templates) {
        pz_notify_init();
        $stmt->execute();
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $errors[] = 'Nu pot citi șabloanele SMS: '.$e->getMessage();
}

try {
    $logs = pz_db()->query("
        SELECT *
        FROM notification_logs
        WHERE channel = 'sms'
        ORDER BY id DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $logs = [];
}

$csrf = function_exists('csrf_field') ? csrf_field() : '';
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Șabloane SMS - PestZone</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root{--brand:#0071A3;--bg:#f5f7fa;--border:#dbe3ea;--text:#132238;--muted:#64748b;--ok:#027a48;--err:#b42318}
        body{margin:0;background:var(--bg);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;color:var(--text);font-size:14px}
        .wrap{max-width:1120px;margin:0 auto;padding:24px}
        .top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px}
        h1{font-size:22px;margin:0} h2{font-size:16px;margin:0 0 12px}
        .muted{color:var(--muted)}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:18px;box-shadow:0 1px 2px rgba(16,24,40,.03)}
        label{display:block;font-size:12px;font-weight:700;color:#334155;margin:12px 0 6px}
        input,select,textarea{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:12px;padding:10px 12px;font:inherit;background:#fff}
        textarea{min-height:118px;resize:vertical}
        .btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:12px;padding:10px 14px;background:#fff;color:var(--text);text-decoration:none;font-weight:700;cursor:pointer}
        .btn-primary{background:var(--brand);border-color:var(--brand);color:#fff}
        .alert{padding:12px 14px;border-radius:12px;margin-bottom:10px}
        .ok{background:#ecfdf3;color:var(--ok);border:1px solid #abefc6}.err{background:#fff1f3;color:var(--err);border:1px solid #fecdd3}
        .varlist{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.pill{font-size:12px;background:#f1f5f9;border:1px solid var(--border);border-radius:999px;padding:4px 8px;color:#475569}
        .preview{white-space:pre-wrap;background:#f8fafc;border:1px dashed var(--border);border-radius:12px;padding:12px;color:#334155;margin-top:10px}
        table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid var(--border);padding:9px;text-align:left;vertical-align:top}
        th{font-size:11px;text-transform:uppercase;color:var(--muted);letter-spacing:.04em}.full{grid-column:1/-1}
        @media(max-width:860px){.grid{grid-template-columns:1fr}.wrap{padding:16px}.top{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Șabloane SMS</h1>
            <div class="muted">Personalizează mesajele trimise prin SMSLink. SMS-urile nu creează programări fără apel.</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="communication_settings.php" class="btn">Comunicare</a>
            <a href="settings.php" class="btn">Setări</a>
        </div>
    </div>

    <?php foreach ($success as $msg): ?><div class="alert ok"><?= sms_h($msg) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $msg): ?><div class="alert err"><?= sms_h($msg) ?></div><?php endforeach; ?>

    <div class="grid">
        <form method="post" class="card">
            <?= $csrf ?>
            <input type="hidden" name="action" value="save_sms_settings">
            <h2>Setări generale SMS</h2>

            <label>Brand / prefix mesaj</label>
            <input type="text" name="sms_brand_name" maxlength="30" value="<?= sms_h(pz_setting_get('sms_brand_name', 'PestZone')) ?>">

            <p class="muted">Expeditorul afișat în telefon se setează în SMSLink ca Sender ID. Aici controlăm textul mesajului.</p>
            <p class="muted"><strong>Status trimitere SMS</strong> (activat / dezactivat global) se gestionează în <a href="communication_settings.php">Setări → Comunicare / Integrări</a>.</p>
            <button class="btn btn-primary" type="submit">Salvează setările SMS</button>
        </form>

        <div class="card">
            <h2>Variabile disponibile</h2>
            <p class="muted">Le poți folosi în mesajele SMS. Se înlocuiesc automat.</p>
            <div class="varlist">
                <span class="pill">{brand}</span><span class="pill">{client}</span><span class="pill">{service}</span><span class="pill">{date}</span>
                <span class="pill">{time}</span><span class="pill">{location}</span><span class="pill">{address}</span><span class="pill">{company_phone}</span>
            </div>
            <p class="muted" style="margin-top:14px">Recomandare: păstrează mesajele scurte, fără diacritice, pentru costuri și livrare mai predictibile.</p>
        </div>

        <form method="post" class="card full">
            <?= $csrf ?>
            <input type="hidden" name="action" value="save_templates">
            <h2>Mesaje SMS automate</h2>

            <?php if (!$templates): ?>
                <div class="alert err">Nu s-au găsit șabloane SMS. Reîncarcă pagina sau accesează sms_migrations.php.</div>
            <?php endif; ?>

            <?php foreach ($templates as $tpl): ?>
                <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px">
                    <label style="display:flex;align-items:center;gap:8px;margin-top:0">
                        <input style="width:auto" type="checkbox" name="template_active[<?= sms_h($tpl['template_key']) ?>]" value="1" <?= (int)$tpl['active'] ? 'checked' : '' ?>>
                        <?= sms_h($tpl['title']) ?>
                    </label>
                    <textarea name="templates[<?= sms_h($tpl['template_key']) ?>]" data-template-textarea><?= sms_h($tpl['body']) ?></textarea>
                    <div class="muted">Previzualizare exemplu:</div>
                    <div class="preview" data-preview></div>
                </div>
            <?php endforeach; ?>

            <div style="margin-top:16px">
                <button class="btn btn-primary" type="submit">Salvează șabloanele</button>
            </div>
        </form>

        <div class="card full">
            <h2>Ultimele SMS-uri</h2>
            <table>
                <thead><tr><th>Data</th><th>Destinatar</th><th>Status</th><th>Mesaj / răspuns</th></tr></thead>
                <tbody>
                <?php if (!$logs): ?><tr><td colspan="4" class="muted">Nu există SMS-uri trimise încă.</td></tr><?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= sms_h($log['created_at'] ?? '') ?></td>
                        <td><?= sms_h($log['recipient'] ?? '') ?></td>
                        <td><?= sms_h($log['status'] ?? '') ?> <?= $log['http_code'] ? '(' . (int)$log['http_code'] . ')' : '' ?></td>
                        <td>
                            <div><?= sms_h(mb_substr((string)($log['message'] ?? ''), 0, 160)) ?></div>
                            <div class="muted"><?= sms_h(mb_substr((string)($log['provider_response'] ?? ''), 0, 140)) ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
(function(){
    const example = {
        brand: document.querySelector('[name="sms_brand_name"]')?.value || 'PestZone',
        client: 'Client Demo SRL',
        service: 'Deratizare',
        date: '15.05.2026',
        time: '09:00-11:00',
        location: 'Magazin alimentar',
        address: 'Str. Exemplu nr. 1',
        company_phone: '07xxxxxxxx'
    };
    function render(text) {
        Object.keys(example).forEach(k => { text = text.split('{' + k + '}').join(example[k]); });
        return text;
    }
    document.querySelectorAll('[data-template-textarea]').forEach(textarea => {
        const box = textarea.parentElement.querySelector('[data-preview]');
        const update = () => box.textContent = render(textarea.value);
        textarea.addEventListener('input', update);
        update();
    });
})();
</script>
</body>
</html>
