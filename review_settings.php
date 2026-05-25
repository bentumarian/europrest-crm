<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/lib/review_lib.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Acces permis doar administratorului.');
}

pz_review_init();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    pz_review_setting_set('review_enabled', isset($_POST['review_enabled']) ? '1' : '0');
    pz_review_setting_set('review_only_first_appointment', isset($_POST['review_only_first_appointment']) ? '1' : '0');
    pz_review_setting_set('review_google_url', trim((string)($_POST['review_google_url'] ?? '')));
    pz_review_setting_set('review_alert_email', trim((string)($_POST['review_alert_email'] ?? '')));
    pz_review_setting_set('review_public_base_url', trim((string)($_POST['review_public_base_url'] ?? '')));
    pz_review_setting_set('review_scan_days', (string)max(1, min(365, (int)($_POST['review_scan_days'] ?? 7))));
    pz_review_setting_set('review_sms_template', trim((string)($_POST['review_sms_template'] ?? '')));
    pz_review_setting_set('review_email_subject', trim((string)($_POST['review_email_subject'] ?? '')));
    pz_review_setting_set('review_email_template', trim((string)($_POST['review_email_template'] ?? '')));
    header('Location: review_settings.php?saved=1');
    exit;
}

$enabled = pz_review_setting_get('review_enabled', '0');
$onlyFirst = pz_review_setting_get('review_only_first_appointment', '1');
$googleUrl = pz_review_setting_get('review_google_url', '');
$alertEmail = pz_review_setting_get('review_alert_email', '');
$baseUrl = pz_review_setting_get('review_public_base_url', '');
$scanDays = pz_review_setting_get('review_scan_days', '7');
$smsTemplate = pz_review_setting_get('review_sms_template', '');
$emailSubject = pz_review_setting_get('review_email_subject', '');
$emailTemplate = pz_review_setting_get('review_email_template', '');
$cronKey = pz_review_setting_get('review_cron_key', '');
$autoBase = pz_review_public_base_url();
$cronUrl = ($autoBase !== '') ? ($autoBase . '/cron_review_requests.php?key=' . urlencode($cronKey)) : ('cron_review_requests.php?key=' . urlencode($cronKey));
$demoLowUrl = ($autoBase !== '') ? ($autoBase . '/feedback.php?demo=low') : 'feedback.php?demo=low';
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Review si satisfactie</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.page{max-width:1040px;margin:0 auto;display:flex;flex-direction:column;gap:16px}.hero{background:var(--surface);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);padding:22px 24px}.hero h1{margin:0;font-size:26px;letter-spacing:-.04em}.hero p{margin:6px 0 0;color:var(--muted)}.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);padding:20px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.field label{display:block;font-weight:700;margin-bottom:8px}.field input[type="text"],.field input[type="email"],.field input[type="number"],.field textarea{width:100%;border:1px solid var(--border);border-radius:14px;padding:12px 14px;font:inherit;background:#fff}.field textarea{min-height:110px;resize:vertical}.check{display:flex;align-items:flex-start;gap:10px;background:var(--surface-soft);border:1px solid var(--border);border-radius:14px;padding:12px}.check input{margin-top:3px}.help{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.45}.notice{border-radius:14px;padding:12px 14px;font-weight:650}.notice.ok{background:var(--success-soft);color:var(--success);border:1px solid rgba(4,120,87,.18)}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.btn{border:0;border-radius:14px;background:var(--accent);color:#fff;font-weight:800;padding:12px 16px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.btn.ghost{background:#fff;color:var(--text);border:1px solid var(--border)}.code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:12px;overflow:auto;font-size:12px}@media(max-width:760px){.grid{grid-template-columns:1fr}.hero,.card{padding:16px}}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('settings', true); ?>
    <main class="main">
        <div class="content page">
            <?php pz_page_header([
                'back'     => ['href' => 'settings.php', 'label' => 'Înapoi la setări'],
                'kicker'   => 'Setări',
                'title'    => 'Review și satisfacție clienți',
                'subtitle' => 'Prima intervenție finalizată trimite SMS. Intervențiile următoare trimit doar email. Google Review apare doar la prima intervenție, dacă nota este 5 stele.',
            ]); ?>

            <?php if (isset($_GET['saved'])): ?><div class="notice ok">Setările au fost salvate.</div><?php endif; ?>

            <form method="post" class="card">
                <?= csrf_field() ?>
                <div class="grid">
                    <div class="check">
                        <input type="checkbox" name="review_enabled" value="1" <?= $enabled === '1' ? 'checked' : '' ?>>
                        <div><strong>Activeaza trimiterea automata</strong><div class="help">Dacă este activa, cronul/manualul trimite formularul de satisfactie după lucrările finalizate.</div></div>
                    </div>
                    <div class="check">
                        <input type="checkbox" name="review_only_first_appointment" value="1" <?= $onlyFirst === '1' ? 'checked' : '' ?>>
                        <div><strong>Google Review doar la prima intervenție</strong><div class="help">Recomandat: prima intervenție trimite SMS; intervențiile urmatoare trimit doar email si nu afiseaza Google Review.</div></div>
                    </div>
                </div>

                <div class="grid" style="margin-top:16px;">
                    <div class="field">
                        <label>Link Google Review</label>
                        <input type="text" name="review_google_url" value="<?= pz_review_h($googleUrl) ?>" placeholder="https://g.page/r/...">
                        <div class="help">Clientul este trimis aici doar la prima intervenție, dacă acorda 5 stele.</div>
                    </div>
                    <div class="field">
                        <label>Email alerta interna</label>
                        <input type="email" name="review_alert_email" value="<?= pz_review_h($alertEmail) ?>" placeholder="office@firma.ro">
                        <div class="help">Aici se trimit formularele cu feedback sub 5 stele.</div>
                    </div>
                    <div class="field">
                        <label>URL public platforma</label>
                        <input type="text" name="review_public_base_url" value="<?= pz_review_h($baseUrl) ?>" placeholder="<?= pz_review_h($autoBase) ?>">
                        <div class="help">Optional. Dacă rămâne gol, platforma incearca sa il detecteze automat.</div>
                    </div>
                    <div class="field">
                        <label>Lucrări scanate in ultimele X zile</label>
                        <input type="number" name="review_scan_days" min="1" max="365" value="<?= pz_review_h($scanDays) ?>">
                        <div class="help">Previne trimiterea catre lucrări foarte vechi.</div>
                    </div>
                </div>

                <div class="field" style="margin-top:16px;">
                    <label>Text SMS pentru prima intervenție</label>
                    <textarea name="review_sms_template"><?= pz_review_h($smsTemplate) ?></textarea>
                    <div class="help">Se folosește doar la prima intervenție finalizata. Variabile disponibile: {brand}, {client}, {feedback_link}</div>
                </div>

                <div class="grid" style="margin-top:16px;">
                    <div class="field">
                        <label>Subiect email pentru intervenții ulterioare</label>
                        <input type="text" name="review_email_subject" value="<?= pz_review_h($emailSubject) ?>" placeholder="Formular satisfactie intervenție {brand}">
                        <div class="help">Se folosește după intervențiile ulterioare. Variabile: {brand}, {client}, {feedback_link}</div>
                    </div>
                    <div class="field">
                        <label>Text email pentru intervenții ulterioare</label>
                        <textarea name="review_email_template"><?= pz_review_h($emailTemplate) ?></textarea>
                        <div class="help">Clientul primeste doar formular intern de satisfactie, fara Google Review.</div>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn" type="submit">Salvează setarile</button>
                    <a class="btn ghost" href="review_feedback.php">Vezi feedback clienți</a>
                    <a class="btn ghost" target="_blank" href="<?= pz_review_h($demoLowUrl) ?>">Vezi formular client nemultumit</a>
                </div>
            </form>

            <section class="card">
                <h2 style="margin-top:0;">Cron automat</h2>
                <p class="help">Pentru automatizare completa, seteaza un cron in cPanel care ruleaza periodic fișierul <strong>cron_review_requests.php</strong>. Dacă il rulezi prin URL, folosește cheia de mai jos.</p>
                <div class="code"><?= pz_review_h($cronUrl) ?></div>
                <p class="help">Recomandare: o data pe ora sau la 30 de minute. Alternativ, poți rula verificarea manual din pagina Feedback clienți. Regula de expediere este: prima intervenție prin SMS, intervenții urmatoare prin email.</p>
            </section>
        </div>
    </main>
</div>
</body>
</html>
