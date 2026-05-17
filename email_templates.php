<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/notification_lib.php';

if (file_exists(__DIR__ . '/super_admin_guard.php')) {
    require_once __DIR__ . '/super_admin_guard.php';
    if (function_exists('require_super_admin')) {
        require_super_admin();
    }
} elseif (!is_admin()) {
    http_response_code(403);
    exit('Acces permis doar administratorului.');
}

function et_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function et_seed_email_templates_ascii(PDO $pdo): void
{
    pz_notify_init();

    $templates = [
        [
            'contract_send_email',
            'email',
            'Trimitere contract',
            'Contract {contract_number} - PestZone',
            '<p>Bună ziua,</p><p>Va transmitem contractul <strong>{contract_number}</strong>.</p><p>Vă rugăm sa verificati documentul si sa ne transmiteti eventualele observatii.</p><p>Cu stima,<br>PestZone</p>'
        ],
        [
            'password_reset_email',
            'email',
            'Resetare parola',
            'Resetare parola PestZone',
            '<p>Bună ziua,</p><p>Ati solicitat resetarea parolei pentru PestZone.</p><p><a href="{reset_link}">Apasa aici pentru resetarea parolei</a></p><p>Linkul este valabil 60 de minute.</p>'
        ],
        [
            'appointment_email',
            'email',
            'Notificare programare',
            'Programare {date} - PestZone',
            '<p>Bună ziua,</p><p>Programarea pentru <strong>{service}</strong> a fost stabilita pentru data de <strong>{date}</strong>, interval <strong>{time}</strong>, la locatia <strong>{location}</strong>.</p><p>Cu stima,<br>PestZone</p>'
        ],
        [
            'process_verbal_email',
            'email',
            'Trimitere proces-verbal',
            'Proces-verbal {document_number} - PestZone',
            '<p>Bună ziua,</p><p>Va transmitem procesul-verbal <strong>{document_number}</strong> aferent lucrării efectuate in data de <strong>{date}</strong>.</p><p>Cu stima,<br>PestZone</p>'
        ],
        [
            'invoice_email',
            'email',
            'Trimitere factura / link factura',
            'Factura {invoice_number} - PestZone',
            '<p>Bună ziua,</p><p>Va transmitem factura <strong>{invoice_number}</strong>.</p><p>{invoice_link}</p><p>Cu stima,<br>PestZone</p>'
        ],
        [
            'task_expiring_email',
            'email',
            'Reminder scadență',
            'Reminder scadență - PestZone',
            '<p>Bună ziua,</p><p>Va reamintim ca valabilitatea documentului / procesului-verbal expira in curand. Vă rugăm sa ne contactati pentru programarea urmatoarei intervenții.</p><p>Cu stima,<br>PestZone</p>'
        ],
    ];

    $insert = $pdo->prepare("
        INSERT IGNORE INTO notification_templates
        (template_key, channel, title, subject, body)
        VALUES (?, ?, ?, ?, ?)
    ");

    $update = $pdo->prepare("
        UPDATE notification_templates
        SET title = ?, subject = ?, body = ?, updated_at = NOW()
        WHERE template_key = ? AND channel = 'email'
    ");

    foreach ($templates as $tpl) {
        $insert->execute($tpl);
        $update->execute([$tpl[2], $tpl[3], $tpl[4], $tpl[0]]);
    }
}

et_seed_email_templates_ascii($pdo);

$success = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_require();

        $templates = $_POST['templates'] ?? [];
        $active = $_POST['template_active'] ?? [];

        foreach ($templates as $key => $data) {
            $subject = trim((string)($data['subject'] ?? ''));
            $body = (string)($data['body'] ?? '');
            $isActive = isset($active[$key]) ? 1 : 0;

            $stmt = $pdo->prepare("
                UPDATE notification_templates
                SET subject = ?, body = ?, active = ?, updated_at = NOW()
                WHERE template_key = ? AND channel = 'email'
            ");
            $stmt->execute([$subject, $body, $isActive, (string)$key]);
        }

        $success[] = 'Șabloanele email au fost salvate.';
    } catch (Throwable $e) {
        $errors[] = 'Eroare salvare: ' . $e->getMessage();
    }
}

$stmt = $pdo->prepare("
    SELECT *
    FROM notification_templates
    WHERE channel = 'email'
      AND template_key IN (
        'contract_send_email',
        'password_reset_email',
        'appointment_email',
        'process_verbal_email',
        'invoice_email',
        'task_expiring_email'
      )
    ORDER BY FIELD(
        template_key,
        'contract_send_email',
        'password_reset_email',
        'appointment_email',
        'process_verbal_email',
        'invoice_email',
        'task_expiring_email'
    )
");
$stmt->execute();
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$logs = [];
try {
    $logs = $pdo->query("
        SELECT *
        FROM notification_logs
        WHERE channel = 'email'
        ORDER BY id DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Șabloane email - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.email-template-page{max-width:1160px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.page-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.page-head h1{margin:0;font-size:24px;letter-spacing:-.035em}
.card{background:#fff;border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);padding:18px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
label{display:block;font-size:12px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin:12px 0 6px}
input,textarea{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:12px;padding:10px 12px;font:inherit;background:#fff;color:var(--text)}
textarea{min-height:170px;resize:vertical;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:13px}
.notice{border-radius:14px;padding:12px 14px;font-weight:800}
.notice.ok{background:#ecfdf3;color:#027a48;border:1px solid #abefc6}
.notice.err{background:#fff1f3;color:#b42318;border:1px solid #fecdd3}
.varlist{display:flex;gap:6px;flex-wrap:wrap}
.pill{font-size:12px;background:#f1f5f9;border:1px solid var(--border);border-radius:999px;padding:5px 8px;color:#475569;font-weight:700}
.template-block{border-top:1px solid var(--border2);padding-top:18px;margin-top:18px}
.template-title{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.template-title strong{font-size:16px}
.checkbox-row{display:inline-flex;gap:8px;align-items:center;font-weight:800;color:var(--muted)}
.checkbox-row input{width:auto}
.preview{background:#f8fafc;border:1px dashed var(--border);border-radius:12px;padding:12px;margin-top:10px;color:#334155;font-size:13px;line-height:1.5}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{border-bottom:1px solid var(--border2);padding:9px;text-align:left;vertical-align:top}
th{font-size:11px;text-transform:uppercase;color:var(--muted);letter-spacing:.04em}
@media(max-width:900px){.grid{grid-template-columns:1fr}.email-template-page{padding:0}}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('settings', true); ?>
    <main class="main">
        <div class="topbar" style="padding:12px 20px;"><a class="btn ghost" href="settings.php">Înapoi la Setări</a></div>
        <div class="content email-template-page">
            <div class="page-head">
                <div>
                    <h1>Șabloane email</h1>
                    <p class="muted" style="margin:6px 0 0">Texte editabile pentru emailurile trimise prin SendGrid.</p>
                </div>
            </div>

            <?php foreach ($success as $msg): ?><div class="notice ok"><?= et_h($msg) ?></div><?php endforeach; ?>
            <?php foreach ($errors as $msg): ?><div class="notice err"><?= et_h($msg) ?></div><?php endforeach; ?>

            <section class="card">
                <h2 style="margin:0 0 10px;font-size:17px">Variabile disponibile</h2>
                <div class="varlist">
                    <span class="pill">{client}</span>
                    <span class="pill">{contract_number}</span>
                    <span class="pill">{document_number}</span>
                    <span class="pill">{invoice_number}</span>
                    <span class="pill">{invoice_link}</span>
                    <span class="pill">{date}</span>
                    <span class="pill">{time}</span>
                    <span class="pill">{service}</span>
                    <span class="pill">{location}</span>
                    <span class="pill">{reset_link}</span>
                    <span class="pill">{company_phone}</span>
                </div>
            </section>

            <form method="post" class="card">
                <?= csrf_field() ?>

                <?php foreach ($templates as $tpl): ?>
                    <div class="template-block">
                        <div class="template-title">
                            <strong><?= et_h($tpl['title']) ?></strong>
                            <label class="checkbox-row" style="margin:0;text-transform:none;letter-spacing:0">
                                <input type="checkbox" name="template_active[<?= et_h($tpl['template_key']) ?>]" value="1" <?= (int)$tpl['active'] ? 'checked' : '' ?>>
                                Activ
                            </label>
                        </div>

                        <label>Subiect</label>
                        <input type="text" name="templates[<?= et_h($tpl['template_key']) ?>][subject]" value="<?= et_h($tpl['subject'] ?? '') ?>">

                        <label>Continut HTML</label>
                        <textarea name="templates[<?= et_h($tpl['template_key']) ?>][body]" data-email-template><?= et_h($tpl['body'] ?? '') ?></textarea>

                        <div class="muted">Previzualizare exemplu:</div>
                        <div class="preview" data-preview></div>
                    </div>
                <?php endforeach; ?>

                <div style="margin-top:18px">
                    <button class="btn accent" type="submit">Salvează șabloanele email</button>
                </div>
            </form>

            <section class="card">
                <h2 style="margin:0 0 12px;font-size:17px">Ultimele emailuri trimise</h2>
                <table>
                    <thead><tr><th>Data</th><th>Destinatar</th><th>Status</th><th>Subiect</th><th>Tip</th></tr></thead>
                    <tbody>
                    <?php if (!$logs): ?>
                        <tr><td colspan="5" class="muted">Nu există emailuri trimise inca.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= et_h($log['created_at'] ?? '') ?></td>
                            <td><?= et_h($log['recipient'] ?? '') ?></td>
                            <td><?= et_h($log['status'] ?? '') ?> <?= $log['http_code'] ? '(' . (int)$log['http_code'] . ')' : '' ?></td>
                            <td><?= et_h($log['subject'] ?? '') ?></td>
                            <td><?= et_h(($log['related_type'] ?? '') . ' #' . ($log['related_id'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>
</div>
<script>
(function(){
    const vars = {
        client: 'Client Demo SRL',
        contract_number: 'CTR 0001/01.05.2026',
        document_number: 'PV 0001/01.05.2026',
        invoice_number: 'FCT 0001',
        invoice_link: '<a href="#">Link factura</a>',
        date: '15.05.2026',
        time: '09:00-11:00',
        service: 'Deratizare',
        location: 'Magazin alimentar',
        reset_link: '<a href="#">Resetare parola</a>',
        company_phone: '07xxxxxxxx'
    };
    function render(t) {
        Object.keys(vars).forEach(k => t = t.split('{'+k+'}').join(vars[k]));
        return t;
    }
    document.querySelectorAll('[data-email-template]').forEach(t => {
        const box = t.parentElement.querySelector('[data-preview]');
        const update = () => box.innerHTML = render(t.value);
        t.addEventListener('input', update);
        update();
    });
})();
</script>
</body>
</html>
