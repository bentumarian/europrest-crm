<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/lib/smartbill_lib.php';

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

function pzsb_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$success = '';
$error = '';

try {
    pz_smartbill_ensure_schema($pdo);
} catch (Throwable $e) {
    error_log('SmartBill schema error: ' . $e->getMessage());
    $error = 'Nu am putut pregati tabelele SmartBill.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) {
        csrf_require();
    }

    try {
        pz_smartbill_save_settings($pdo, $_POST);
        pz_smartbill_ensure_schema($pdo);
        $success = 'Setările SmartBill au fost salvate.';
    } catch (Throwable $e) {
        error_log('SmartBill settings save error: ' . $e->getMessage());
        $error = 'Setările SmartBill nu au putut fi salvate.';
    }
}

$settings = pz_smartbill_settings($pdo);
$vatOptions = pz_smartbill_vat_options();
$allowedVatCodes = pz_smartbill_allowed_vat_codes($settings);
$defaultVatCode = (string)($settings['smartbill.default_vat_code'] ?? '21');
if (!isset($vatOptions[$defaultVatCode])) {
    $defaultVatCode = '21';
}
if (!in_array($defaultVatCode, $allowedVatCodes, true)) {
    $allowedVatCodes[] = $defaultVatCode;
}
$csrf = function_exists('csrf_field') ? csrf_field() : '';
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>SmartBill - Setări</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        .settings-module-page{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
        .module-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;background:var(--surface);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);padding:20px 22px}
        .module-head h1{font-size:24px;letter-spacing:-.035em;margin:0}
        .module-head p{margin:6px 0 0;color:var(--muted);font-weight:700}
        .muted{color:var(--muted)}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
        .card{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:18px;box-shadow:var(--shadow)}
        .card h2{margin:0 0 8px;font-size:17px;letter-spacing:-.015em}
        label{display:block;font-size:12px;font-weight:900;margin:12px 0 6px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        input,select,textarea{width:100%;border:1px solid var(--border);border-radius:12px;min-height:42px;padding:10px 12px;font:inherit;font-weight:700;box-sizing:border-box;background:#fff;color:var(--text)}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .alert{padding:12px 14px;border-radius:14px;font-weight:850}
        .ok{background:var(--success-soft);color:var(--success);border:1px solid rgba(4,120,87,.18)}
        .err{background:var(--danger-soft);color:var(--danger);border:1px solid rgba(220,38,38,.18)}
        .full{grid-column:1/-1}
        .check-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:10px}
        .check-item{display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:14px;padding:10px 12px;font-weight:850;color:var(--text);background:#fff}
        .check-item input{width:18px;height:18px;min-height:auto;padding:0;flex:0 0 auto}
        .pill{display:inline-flex;align-items:center;border-radius:999px;padding:7px 10px;background:#eef4ff;color:var(--accent);font-size:12px;font-weight:900}
        .info-list{margin:10px 0 0;padding:0;list-style:none;display:grid;gap:8px}
        .info-list li{padding-left:18px;position:relative;color:var(--muted);font-weight:750;line-height:1.45}
        .info-list li:before{content:"";position:absolute;left:0;top:.62em;width:7px;height:7px;border-radius:99px;background:var(--accent)}
        @media(max-width:860px){.grid,.row,.check-grid{grid-template-columns:1fr}.module-head{padding:16px}}
        /* DS v2.4 */
        .module-head,.card { border-radius:var(--pz-r) !important; box-shadow:none !important; }
        input,select,textarea { border-radius:var(--pz-rs) !important; }
        .alert { border-radius:var(--pz-rs) !important; font-weight:600 !important; }
        .alert.ok { background:var(--pz-grs) !important; color:var(--pz-gr) !important; }
        .alert.err { background:var(--pz-res) !important; color:var(--pz-re) !important; }
        .check-item { border-radius:var(--pz-r) !important; font-weight:600 !important; }
        .pill { font-weight:600 !important; font-size:11.5px !important; }
        h1,h2 { font-weight:700 !important; }
    </style>
</head>
<body>
<div class="layout">
    <?php
    $pz_page_title = 'Setări';
    $pz_page_breadcrumbs = ['SmartBill'];
    render_sidebar('smartbill_settings', true);
    ?>
    <main class="main">
        <div class="content settings-module-page">
            <?php pz_page_header([
                'back'     => ['href' => 'settings.php', 'label' => 'Înapoi la setări'],
                'kicker'   => 'ADMINISTRARE · INTEGRĂRI',
                'title'    => 'SmartBill',
                'subtitle' => 'Configurare pentru facturare, cote TVA și verificare status e-Factura / SPV.',
            ]); ?>

            <?php if ($success !== ''): ?><div class="alert ok"><?= pzsb_h($success) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert err"><?= pzsb_h($error) ?></div><?php endif; ?>

            <form method="post">
                <?= $csrf ?>
                <div class="grid">
                    <div class="card">
                        <h2>Conectare API</h2>
                        <p class="muted">Datele se iau din contul SmartBill, zona Integrări. Tokenul nu se afiseaza după salvare.</p>

                        <label class="check-item" style="margin-top:14px;text-transform:none;letter-spacing:0;color:var(--text)">
                            <input type="checkbox" name="smartbill_enabled" value="1" <?= ($settings['smartbill.enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            Integrare SmartBill activa
                        </label>

                        <label>Email API SmartBill</label>
                        <input type="email" name="smartbill_api_email" value="<?= pzsb_h($settings['smartbill.api_email'] ?? '') ?>" placeholder="email@firma.ro" autocomplete="off">

                        <label>Token API SmartBill</label>
                        <input type="password" name="smartbill_api_token" value="<?= trim((string)($settings['smartbill.api_token'] ?? '')) !== '' ? '********' : '' ?>" autocomplete="off">

                        <div class="row">
                            <div>
                                <label>CIF firma in SmartBill</label>
                                <input type="text" name="smartbill_company_vat_code" value="<?= pzsb_h($settings['smartbill.company_vat_code'] ?? '') ?>" placeholder="RO10135994">
                            </div>
                            <div>
                                <label>Serie factura</label>
                                <input type="text" name="smartbill_invoice_series" value="<?= pzsb_h($settings['smartbill.invoice_series'] ?? '') ?>" placeholder="ex: FCT">
                            </div>
                            <div>
                                <label>Serie chitanța</label>
                                <input type="text" name="smartbill_receipt_series" value="<?= pzsb_h($settings['smartbill.receipt_series'] ?? '') ?>" placeholder="optional">
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h2>Reguli de facturare</h2>
                        <p class="muted">Preturile din CRM raman fără TVA. SmartBill primeste suma neta si cota TVA aleasa.</p>

                        <div class="row">
                            <div>
                                <label>TVA implicit</label>
                                <select name="smartbill_default_vat_code">
                                    <?php foreach ($vatOptions as $code => $label): ?>
                                        <option value="<?= pzsb_h($code) ?>" <?= $defaultVatCode === $code ? 'selected' : '' ?>><?= pzsb_h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Termen plata implicit</label>
                                <input type="number" name="smartbill_payment_due_days" min="0" max="365" value="<?= (int)($settings['smartbill.payment_due_days'] ?? 15) ?>">
                            </div>
                        </div>

                        <label>Cote TVA selectabile</label>
                        <div class="check-grid">
                            <?php foreach ($vatOptions as $code => $label): ?>
                                <label class="check-item">
                                    <input type="checkbox" name="smartbill_allowed_vat_codes[]" value="<?= pzsb_h($code) ?>" <?= in_array($code, $allowedVatCodes, true) ? 'checked' : '' ?>>
                                    <?= pzsb_h($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="card">
                        <h2>Trimitere si status</h2>
                        <label class="check-item" style="text-transform:none;letter-spacing:0;color:var(--text)">
                            <input type="checkbox" name="smartbill_email_from_crm" value="1" <?= ($settings['smartbill.email_from_crm'] ?? '1') === '1' ? 'checked' : '' ?>>
                            CRM-ul trimite emailul cu factura
                        </label>
                        <label class="check-item" style="text-transform:none;letter-spacing:0;color:var(--text)">
                            <input type="checkbox" name="smartbill_efactura_auto_check" value="1" <?= ($settings['smartbill.efactura_auto_check'] ?? '1') === '1' ? 'checked' : '' ?>>
                            Verifica status e-Factura / SPV in CRM
                        </label>
                    </div>

                    <div class="card">
                        <h2>Flux stabilit</h2>
                        <span class="pill">Emitere doar la confirmare</span>
                        <ul class="info-list">
                            <li>Factura se va emite pentru fiecare intervenție confirmata manual.</li>
                            <li>CRM-ul va pastra factura, statusul SmartBill si statusul e-Factura.</li>
                            <li>Adresa clientului trebuie tinuta separat: țară, județ, oraș/localitate si adresa.</li>
                        </ul>
                    </div>

                    <div class="card full">
                        <button class="btn accent" type="submit">Salvează setarile SmartBill</button>
                    </div>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>
