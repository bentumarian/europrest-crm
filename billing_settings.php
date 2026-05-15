<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/oblio_api_lib.php';

if (!is_admin()) { http_response_code(403); exit('Acces permis doar administratorului.'); }

function bs_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bs_checked($v): string { return !empty($v) && (string)$v !== '0' ? 'checked' : ''; }

oblio_ensure_settings_schema($pdo);

$error = '';
$success = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) csrf_require();

    try {
        $action = (string)($_POST['action'] ?? 'save');
        $current = oblio_settings($pdo);
        $newSecret = trim((string)($_POST['oblio_client_secret'] ?? ''));

        $values = [
            'billing.provider' => 'oblio',
            'billing.enabled' => !empty($_POST['billing_enabled']) ? '1' : '0',
            'oblio.client_id' => trim((string)($_POST['oblio_client_id'] ?? '')),
            'oblio.company_cif' => oblio_clean_cif((string)($_POST['oblio_company_cif'] ?? '')),
            'oblio.invoice_series' => trim((string)($_POST['oblio_invoice_series'] ?? '')),
            'oblio.proforma_series' => trim((string)($_POST['oblio_proforma_series'] ?? '')),
            'oblio.receipt_series' => trim((string)($_POST['oblio_receipt_series'] ?? '')),
            'oblio.vat_name' => trim((string)($_POST['oblio_vat_name'] ?? 'Normala')),
            'oblio.vat_percentage' => trim((string)($_POST['oblio_vat_percentage'] ?? '21')),
            'oblio.vat_included' => !empty($_POST['oblio_vat_included']) ? '1' : '0',
            'oblio.currency' => trim((string)($_POST['oblio_currency'] ?? 'RON')),
            'oblio.language' => trim((string)($_POST['oblio_language'] ?? 'RO')),
            'oblio.precision' => trim((string)($_POST['oblio_precision'] ?? '2')),
            'oblio.default_due_days' => trim((string)($_POST['oblio_default_due_days'] ?? '15')),
            'oblio.work_station' => trim((string)($_POST['oblio_work_station'] ?? 'Sediu')),
            'oblio.use_stock' => !empty($_POST['oblio_use_stock']) ? '1' : '0',
            'oblio.send_email' => !empty($_POST['oblio_send_email']) ? '1' : '0',
            'oblio.spv_extern' => !empty($_POST['oblio_spv_extern']) ? '1' : '0',
            'oblio.issuer_name' => trim((string)($_POST['oblio_issuer_name'] ?? '')),
            'oblio.default_product_name' => trim((string)($_POST['oblio_default_product_name'] ?? 'Servicii DDD conform contract / lucrare')),
            'oblio.default_measuring_unit' => trim((string)($_POST['oblio_default_measuring_unit'] ?? 'buc')),
            'oblio.sync_days_back' => trim((string)($_POST['oblio_sync_days_back'] ?? '30')),
            'oblio.cron_key' => trim((string)($_POST['oblio_cron_key'] ?? '')),
        ];

        $values['oblio.client_secret'] = $newSecret !== '' ? $newSecret : (string)($current['oblio.client_secret'] ?? '');

        oblio_set_settings($pdo, $values);
        $success = 'Setarile au fost salvate.';

        if ($action === 'test') {
            $result = oblio_get_companies($pdo);
            $success = !empty($result['ok']) ? 'Conexiunea Oblio functioneaza.' : '';
            $error = empty($result['ok']) ? ($result['error'] ?? 'Test esuat.') : '';
        }

        if ($action === 'series') {
            $vatRates = oblio_get_vat_rates($pdo);
            if (!empty($vatRates['ok']) && is_array($vatRates['data'] ?? null) && function_exists('oblio_cache_vat_rates')) {
                oblio_cache_vat_rates($pdo, $vatRates['data']);
            }
            $result = [
                'companies' => oblio_get_companies($pdo),
                'series' => oblio_get_series($pdo),
                'vat_rates' => $vatRates,
            ];
            $success = 'Date preluate din Oblio.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$s = oblio_settings($pdo);
$maskedSecret = oblio_mask_secret((string)($s['oblio.client_secret'] ?? ''));
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Setari Oblio - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.page{max-width:1100px;margin:0 auto;display:grid;gap:14px}.hero,.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow)}
.hero{padding:18px 20px}.hero h1{margin:0 0 5px;font-size:25px;font-weight:650;letter-spacing:-.035em}.hero p{margin:0;color:var(--muted)}
.card{overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid var(--border2);display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.card-head h2{margin:0;font-size:16px;font-weight:650}.card-body{padding:16px;display:grid;gap:14px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
label{display:block;margin-bottom:5px;color:var(--muted);font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.04em}
input,select{width:100%;border:1px solid var(--border);border-radius:12px;padding:10px 11px;min-height:42px;background:#fff;color:var(--text);font:inherit;outline:none}.check{display:flex;align-items:center;gap:8px;font-weight:700}.check input{width:18px;height:18px;min-height:0}.notice{margin:0}pre{white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px;font-size:12px;line-height:1.45;overflow:auto}@media(max-width:900px){.grid-2,.grid-3{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('billing_settings', true); ?>
<main class="main">
<div class="topbar"><strong>Setari Oblio</strong></div>
<div class="content page">
<section class="hero"><h1>Integrare facturare Oblio</h1><p>Oblio este sursa fiscala oficiala. CRM salveaza local documentele si PDF-urile pentru lucru rapid.</p></section>
<?php if($success): ?><div class="notice notice-success"><?= bs_h($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="notice notice-danger"><?= bs_h($error) ?></div><?php endif; ?>

<form method="post" class="card">
<?= function_exists('csrf_field') ? csrf_field() : '' ?>
<div class="card-head"><h2>Conexiune API</h2><span>Provider: Oblio</span></div>
<div class="card-body">
<label class="check"><input type="checkbox" name="billing_enabled" value="1" <?= bs_checked($s['billing.enabled'] ?? '0') ?>> Activeaza integrarea</label>
<div class="grid-2">
<div><label>Email cont Oblio</label><input type="email" name="oblio_client_id" value="<?= bs_h($s['oblio.client_id'] ?? '') ?>"></div>
<div><label>API secret</label><input type="password" name="oblio_client_secret" placeholder="<?= $maskedSecret ? 'Salvat: '.bs_h($maskedSecret) : 'Introdu API secret' ?>"></div>
</div>
<div class="grid-3">
<div><label>CUI/CIF firma</label><input name="oblio_company_cif" value="<?= bs_h($s['oblio.company_cif'] ?? '') ?>"></div>
<div><label>Serie factura</label><input name="oblio_invoice_series" value="<?= bs_h($s['oblio.invoice_series'] ?? '') ?>"></div>
<div><label>Serie proforma</label><input name="oblio_proforma_series" value="<?= bs_h($s['oblio.proforma_series'] ?? '') ?>"></div>
</div>
<div class="grid-3">
<div><label>Serie chitanta</label><input name="oblio_receipt_series" value="<?= bs_h($s['oblio.receipt_series'] ?? '') ?>"></div>
<div><label>TVA nume</label><input name="oblio_vat_name" value="<?= bs_h($s['oblio.vat_name'] ?? 'Normala') ?>"></div>
<div><label>TVA %</label><input type="number" step="0.01" name="oblio_vat_percentage" value="<?= bs_h($s['oblio.vat_percentage'] ?? '21') ?>"></div>
</div>
<div class="grid-3">
<div><label>Moneda</label><input name="oblio_currency" value="<?= bs_h($s['oblio.currency'] ?? 'RON') ?>"></div>
<div><label>Limba</label><input name="oblio_language" value="<?= bs_h($s['oblio.language'] ?? 'RO') ?>"></div>
<div><label>Scadenta zile</label><input type="number" name="oblio_default_due_days" value="<?= bs_h($s['oblio.default_due_days'] ?? '15') ?>"></div>
</div>
<div class="grid-2">
<div><label>Punct lucru</label><input name="oblio_work_station" value="<?= bs_h($s['oblio.work_station'] ?? 'Sediu') ?>"></div>
<div><label>Intocmit de</label><input name="oblio_issuer_name" value="<?= bs_h($s['oblio.issuer_name'] ?? '') ?>"></div>
</div>
<div class="grid-2">
<div><label>Serviciu implicit</label><input name="oblio_default_product_name" value="<?= bs_h($s['oblio.default_product_name'] ?? 'Servicii DDD conform contract / lucrare') ?>"></div>
<div><label>UM implicita</label><input name="oblio_default_measuring_unit" value="<?= bs_h($s['oblio.default_measuring_unit'] ?? 'buc') ?>"></div>
</div>
<div class="grid-2">
<label class="check"><input type="checkbox" name="oblio_vat_included" value="1" <?= bs_checked($s['oblio.vat_included'] ?? '0') ?>> Preturi cu TVA inclus</label>
<label class="check"><input type="checkbox" name="oblio_send_email" value="1" <?= bs_checked($s['oblio.send_email'] ?? '0') ?>> Oblio trimite email automat</label>
<label class="check"><input type="checkbox" name="oblio_spv_extern" value="1" <?= bs_checked($s['oblio.spv_extern'] ?? '0') ?>> Trimite in SPV prin Oblio</label>
<label class="check"><input type="checkbox" name="oblio_use_stock" value="1" <?= bs_checked($s['oblio.use_stock'] ?? '0') ?>> Foloseste stoc Oblio</label>
</div>
<div class="grid-2">
<div><label>Zile sincronizare inapoi</label><input type="number" name="oblio_sync_days_back" value="<?= bs_h($s['oblio.sync_days_back'] ?? '30') ?>"></div>
<div><label>Cheie cron browser</label><input name="oblio_cron_key" value="<?= bs_h($s['oblio.cron_key'] ?? '') ?>" placeholder="optional, pentru cron URL"></div>
</div>
<div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;">
<button class="btn" name="action" value="save">Salveaza</button>
<button class="btn" name="action" value="test">Testeaza conexiunea</button>
<button class="btn accent" name="action" value="series">Preia companii/serii</button>
</div>
</div>
</form>

<?php if($result): ?><section class="card"><div class="card-head"><h2>Raspuns Oblio</h2></div><div class="card-body"><pre><?= bs_h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></div></section><?php endif; ?>

</div>
</main>
</div>
</body>
</html>
