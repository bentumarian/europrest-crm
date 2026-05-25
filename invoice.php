<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/lib/smartbill_lib.php';
require_once __DIR__ . '/lib/billing/billing_lib.php';
require_once __DIR__ . '/lib/revenue_lib.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

pz_billing_ensure_schema($pdo);

function inv_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function inv_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function inv_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function inv_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!inv_column_exists($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            error_log('Facturi add column error: ' . $table . '.' . $column . ' - ' . $e->getMessage());
        }
    }
}

function inv_date(?string $date, ?string $fallback = null): string
{
    $fallback = $fallback ?: date('Y-m-d');
    $d = DateTime::createFromFormat('Y-m-d', (string)$date);
    return ($d && $d->format('Y-m-d') === (string)$date) ? (string)$date : $fallback;
}

function inv_vat_amount(float $net, string $vatCode): float
{
    if (is_numeric($vatCode)) {
        return round($net * ((float)$vatCode / 100), 2);
    }
    return 0.0;
}

function inv_money_label($value, string $currency = 'RON'): string
{
    return number_format(pz_smartbill_money($value), 2, ',', '.') . ' ' . $currency;
}

function inv_invoice_reference(array $invoice): string
{
    $series = trim((string)($invoice['smartbill_series'] ?? ''));
    $number = trim((string)($invoice['smartbill_number'] ?? ''));
    if ($series !== '' || $number !== '') {
        return trim($series . ' ' . $number);
    }
    return 'Draft #' . (int)($invoice['id'] ?? 0);
}

function inv_payment_link(array $invoice): string
{
    $query = ['invoice_id' => (int)($invoice['id'] ?? 0)];

    if (!empty($invoice['client_id'])) {
        $query['client_id'] = (int)$invoice['client_id'];
    } elseif (trim((string)($invoice['client_name'] ?? '')) !== '') {
        $query['q'] = (string)$invoice['client_name'];
    }

    return 'payment.php?' . http_build_query($query);
}

function inv_payments_report_link(array $invoice): string
{
    $query = [];

    if (!empty($invoice['client_id'])) {
        $query['client_id'] = (int)$invoice['client_id'];
    } elseif (trim((string)($invoice['client_name'] ?? '')) !== '') {
        $query['q'] = (string)$invoice['client_name'];
    }

    return 'payments.php' . ($query ? ('?' . http_build_query($query)) : '');
}

function inv_status_label(string $status): string
{
    return [
        'draft' => 'Draft',
        'issued' => 'Emisa',
        'error' => 'Eroare',
        'deleted' => 'Stearsa',
        'cancelled' => 'Anulata',
    ][$status] ?? ($status !== '' ? $status : 'Draft');
}

function inv_status_class(string $status): string
{
    return [
        'draft' => 'warn',
        'issued' => 'ok',
        'error' => 'err',
        'deleted' => 'muted',
        'cancelled' => 'muted',
    ][$status] ?? '';
}

/**
 * Best-effort parsare a unei adrese de tip ANAF (registered_address)
 * și extragere județ / oraș / restul (strada + numărul).
 *
 * Returnează ['county' => '', 'city' => '', 'street' => '']
 *
 * Folosit ca fallback când coloanele structurate billing_county/city/address_line
 * sunt goale în tabelul clients. Utilizatorul poate corecta manual ce nu prinde.
 */
function inv_parse_anaf_address(string $registered): array
{
    $out = ['county' => '', 'city' => '', 'street' => ''];
    $text = trim($registered);
    if ($text === '') {
        return $out;
    }
    // Normalizează spațiile și caracterele
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = (string)$text;

    $isBucuresti = (bool)preg_match('/\b(?:MUN\.?|MUNICIPIUL|MUN)\s+BUCURE[ȘS]TI\b|\bBUCURE[ȘS]TI\b/iu', $text);

    if ($isBucuresti) {
        $out['county'] = 'București';
        if (preg_match('/\b(?:SECTOR|SECTORUL)\s*(\d+)/iu', $text, $m)) {
            $out['city'] = 'Sector ' . (int)$m[1];
        }
    } else {
        // Județul: după „JUD" / „JUDEȚUL" / „Jud."
        if (preg_match('/\b(?:JUDEȚUL|JUDETUL|JUD\.?)\s+([A-ZĂÂÎȘȚa-zăâîșț\- ]+?)(?=\s+(?:MUN|MUNICIPIUL|ORA[ȘS]|ORA[ȘS]UL|SAT|SATUL|COM|COMUNA|STR|STRADA|BD|BULEVARDUL|SOS|[ȘS]OS|CAL|CALEA|ALEEA|NR|BL|$)|\s*,)/iu', $text, $m)) {
            $out['county'] = trim(mb_convert_case(mb_strtolower(trim($m[1])), MB_CASE_TITLE, 'UTF-8'));
        }
        // Oraș/comună/sat
        if (preg_match('/\b(?:MUNICIPIUL|MUN\.?|ORA[ȘS]UL|ORA[ȘS]\.?|COMUNA|COM\.?|SATUL|SAT\.?)\s+([A-ZĂÂÎȘȚa-zăâîșț\- ]+?)(?=\s+(?:JUDEȚUL|JUDETUL|JUD\.?|STR|STRADA|BD|BULEVARDUL|SOS|[ȘS]OS|CAL|CALEA|ALEEA|NR|BL|$)|\s*,)/iu', $text, $m)) {
            $out['city'] = trim(mb_convert_case(mb_strtolower(trim($m[1])), MB_CASE_TITLE, 'UTF-8'));
        }
    }

    // Strada și restul — începe cu Str/Strada/Bd/Sos/Cal/Aleea
    if (preg_match('/(\b(?:STR|STRADA|BD|BULEVARDUL|SOS|[ȘS]OSEAUA|CAL|CALEA|ALEEA|INTRAREA|PIA[ȚT]A|DRUMUL)\b[\.\s].+)$/iu', $text, $m)) {
        $out['street'] = trim($m[1]);
    } elseif ($out['county'] === '' && $out['city'] === '') {
        // Nu am putut parsa nimic — punem tot șirul ca adresă liberă
        $out['street'] = $text;
    }

    return $out;
}

function inv_parse_invoice_items(array $post, array $vatOptions, array $allowedVatCodes, string $defaultVatCode): array
{
    $rows = [];
    $errors = [];
    $descriptions = $post['item_description'] ?? null;

    if (!is_array($descriptions)) {
        $descriptions = [$post['description'] ?? ''];
        $post['item_product_code'] = [$post['product_code'] ?? ''];
        $post['item_product_description'] = [$post['product_description'] ?? ''];
        $post['item_quantity'] = [$post['quantity'] ?? '1'];
        $post['item_unit_name'] = [$post['unit_name'] ?? 'buc'];
        $post['item_unit_price'] = [$post['unit_price'] ?? '0'];
        $post['item_vat_code'] = [$post['vat_code'] ?? $defaultVatCode];
        $post['item_is_tax_included'] = !empty($post['is_tax_included']) ? [0 => '1'] : [];
        $post['item_is_service'] = [$post['is_service'] ?? '1'];
    }

    foreach ($descriptions as $idx => $rawDescription) {
        $description = trim((string)$rawDescription);
        $productCode = trim((string)($post['item_product_code'][$idx] ?? ''));
        $productDescription = trim((string)($post['item_product_description'][$idx] ?? ''));
        $quantity = max(0.001, (float)str_replace(',', '.', (string)($post['item_quantity'][$idx] ?? '1')));
        $unitName = trim((string)($post['item_unit_name'][$idx] ?? 'buc')) ?: 'buc';
        $unitPrice = pz_smartbill_money($post['item_unit_price'][$idx] ?? 0);
        $vatCode = trim((string)($post['item_vat_code'][$idx] ?? $defaultVatCode));
        if (!isset($vatOptions[$vatCode]) || !in_array($vatCode, $allowedVatCodes, true)) {
            $vatCode = $defaultVatCode;
        }
        $isTaxIncluded = !empty($post['item_is_tax_included'][$idx]) ? 1 : 0;
        $isService = array_key_exists($idx, (array)($post['item_is_service'] ?? [])) ? (int)!empty($post['item_is_service'][$idx]) : 1;

        $hasContent = ($description !== '' || $productCode !== '' || $productDescription !== '' || $unitPrice > 0);
        if (!$hasContent) {
            continue;
        }
        if ($description === '') {
            $errors[] = 'O pozitie de factura nu are descriere.';
            continue;
        }
        if ($unitPrice <= 0) {
            $errors[] = 'Pozitia "' . $description . '" nu are pret.';
            continue;
        }

        $lineValue = round($quantity * $unitPrice, 2);
        if ($isTaxIncluded && is_numeric($vatCode) && (float)$vatCode > 0) {
            $gross = $lineValue;
            $net = round($gross / (1 + ((float)$vatCode / 100)), 2);
            $vat = round($gross - $net, 2);
        } else {
            $net = $lineValue;
            $vat = inv_vat_amount($net, $vatCode);
            $gross = round($net + $vat, 2);
        }

        $rows[] = [
            'product_code' => $productCode,
            'description' => $description,
            'product_description' => $productDescription,
            'quantity' => $quantity,
            'unit_name' => $unitName,
            'unit_price' => $unitPrice,
            'vat_code' => $vatCode,
            'is_tax_included' => $isTaxIncluded,
            'is_service' => $isService,
            'line_total' => $net,
            'vat_amount' => $vat,
            'gross_amount' => $gross,
        ];
    }

    if (!$rows && !$errors) {
        $errors[] = 'Adaugă cel puțin o poziție pe factură.';
    }

    return ['items' => $rows, 'errors' => $errors];
}

pz_smartbill_ensure_schema($pdo);

if (inv_table_exists($pdo, 'clients')) {
    inv_ensure_column($pdo, 'clients', 'billing_country', "VARCHAR(80) NULL");
    inv_ensure_column($pdo, 'clients', 'billing_county', "VARCHAR(120) NULL");
    inv_ensure_column($pdo, 'clients', 'billing_city', "VARCHAR(120) NULL");
    inv_ensure_column($pdo, 'clients', 'billing_address_line', "VARCHAR(255) NULL");
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

$success = '';
$error = '';
$invoiceSearch = trim((string)($_GET['invoice_q'] ?? ''));
$invoiceStatusFilter = trim((string)($_GET['invoice_status'] ?? 'all'));
$invoiceClientFilter = max(0, (int)($_GET['client_id'] ?? 0));
if (!in_array($invoiceStatusFilter, ['all', 'draft', 'issued', 'error'], true)) {
    $invoiceStatusFilter = 'all';
}
$invoiceIdFromRequest = max(0, (int)($_GET['id'] ?? $_POST['invoice_id'] ?? 0));
$appointmentId = max(0, (int)($_GET['appointment_id'] ?? $_POST['appointment_id'] ?? 0));

// Flux nou: preview pentru emitere factură pentru poziții de facturat selectate.
$billingItemIdsFromGet = [];
if (isset($_GET['billing_item_ids'])) {
    $rawIds = (array)$_GET['billing_item_ids'];
    $billingItemIdsFromGet = array_values(array_unique(array_map('intval', $rawIds)));
    $billingItemIdsFromGet = array_values(array_filter($billingItemIdsFromGet, static fn($v) => $v > 0));
}

if ($billingItemIdsFromGet && $invoiceIdFromRequest <= 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $validation = pz_billing_validate_invoice_selection($pdo, $billingItemIdsFromGet);
    $smartbillEnabled = ((string)($settings['smartbill.enabled'] ?? '0') === '1');
    $previewItems = $validation['items'] ?? [];
    $previewTotals = pz_billing_calculate_totals($previewItems);
    $previewError = empty($validation['ok']) ? (string)($validation['error'] ?? '') : '';
    $previewClient = pz_billing_collect_client_snapshot($pdo, (int)$validation['client_id']);
    $previewInvoiceDate = date('Y-m-d');
    $previewDueDate = date('Y-m-d', strtotime($previewInvoiceDate . ' +' . max(0, (int)($settings['smartbill.payment_due_days'] ?? 15)) . ' days'));

    ?><!DOCTYPE html>
    <html lang="ro">
    <head>
        <meta charset="UTF-8">
        <title>Emite factură</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <?php app_theme_css(); ?>
        <style>
            .bill-wrap { padding: 18px 22px; }
            .bill-card { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 18px; max-width: 980px; }
            .bill-card h1 { font-size: 20px; font-weight: 800; margin: 0 0 6px; }
            .bill-meta { color: var(--muted); font-weight: 700; margin-bottom: 14px; font-size: 13px; }
            .bill-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px; }
            .bill-grid label { display: block; font-size: 11px; font-weight: 900; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
            .bill-grid input, .bill-grid textarea { width: 100%; border: 1px solid var(--border); border-radius: 4px; padding: 8px; font-weight: 700; }
            .bill-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
            .bill-table th, .bill-table td { border-bottom: 1px solid var(--border); padding: 8px; font-size: 12.5px; text-align: left; }
            .bill-table th { background: var(--pz-soft); font-size: 10px; font-weight: 800; text-transform: uppercase; }
            .bill-totals { display: flex; gap: 18px; justify-content: flex-end; margin-bottom: 18px; font-weight: 800; }
            .bill-totals span { color: var(--muted); margin-right: 6px; }
            .bill-actions { display: flex; gap: 10px; flex-wrap: wrap; }
            .bill-auto-receipt { margin-bottom: 12px; padding: 10px 14px; background: var(--surface-soft); border: 1px solid var(--border); border-radius: 6px; }
            .bill-auto-receipt label { display: inline-flex; gap: 8px; align-items: center; font-weight: 700; font-size: 13px; color: var(--text); cursor: pointer; margin: 0; text-transform: none; letter-spacing: 0; }
            .bill-auto-receipt input { width: auto; margin: 0; }
            .bill-error { background: var(--tone-warning-bg); border: 1px solid rgba(180,83,9,.24); color: var(--tone-warning); padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-weight: 800; }
            .bill-info { background: var(--surface-soft); border: 1px solid var(--border); color: var(--text); padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; font-size: 12.5px; }
        </style>
    </head>
    <body>
    <div class="layout">
        <?php render_sidebar('facturi', $isAdmin); ?>
        <main class="main">
            <div class="bill-wrap">
                <a class="btn" href="work_billing.php" style="margin-bottom:12px;display:inline-flex">← Înapoi la „De facturat"</a>
                <div class="bill-card">
                    <h1>Emite factură</h1>
                    <div class="bill-meta"><?= (int)count($previewItems) ?> poziție(i) selectate</div>

                    <?php if ($previewError !== ''): ?>
                        <div class="bill-error"><?= inv_h($previewError) ?></div>
                    <?php endif; ?>

                    <?php if (!$smartbillEnabled): ?>
                        <div class="bill-info">SmartBill este <strong>inactiv</strong>. Factura va fi salvată ca <strong>draft local</strong>. Pozițiile vor rămâne „de facturat" până când se completează seria/numărul în factură sau se activează SmartBill.</div>
                    <?php endif; ?>

                    <?php if (!$previewError && $previewItems): ?>
                        <div class="bill-meta">
                            <strong>Client:</strong> <?= inv_h($previewClient['client_name'] ?? '') ?>
                            <?php if (!empty($previewClient['client_fiscal_code'])): ?> · CUI: <?= inv_h($previewClient['client_fiscal_code']) ?><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <table class="bill-table">
                        <thead>
                            <tr>
                                <th>Descriere</th>
                                <th>Dată lucrare</th>
                                <th style="text-align:right">Cantitate</th>
                                <th style="text-align:right">Preț net</th>
                                <th>TVA</th>
                                <th style="text-align:right">Total net</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($previewItems as $item):
                            $itemQty = max(1.0, (float)($item['quantity'] ?? 1));
                            $itemUnit = pz_billing_money($item['unit_price_net'] ?? ($item['total_net'] ?? 0) / $itemQty);
                            $itemTotal = pz_billing_money($item['total_net'] ?? 0);
                        ?>
                            <tr>
                                <td><?= inv_h($item['description']) ?></td>
                                <td><?= inv_h($item['work_date']) ?></td>
                                <td style="text-align:right"><?= number_format($itemQty, 3, ',', '.') ?></td>
                                <td style="text-align:right"><?= number_format($itemUnit, 2, ',', '.') ?></td>
                                <td><?= inv_h((string)$item['vat_code']) ?>%</td>
                                <td style="text-align:right"><?= number_format($itemTotal, 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="bill-totals">
                        <div><span>Net:</span> <?= number_format($previewTotals['net'], 2, ',', '.') ?> lei</div>
                        <div><span>TVA:</span> <?= number_format($previewTotals['vat'], 2, ',', '.') ?> lei</div>
                        <div><span>Total:</span> <strong><?= number_format($previewTotals['gross'], 2, ',', '.') ?> lei</strong></div>
                    </div>

                    <?php if ($previewError === '' && $previewItems): ?>
                    <form method="post" action="invoice.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="issue_from_billing_items">
                        <?php foreach ($billingItemIdsFromGet as $bid): ?>
                            <input type="hidden" name="billing_item_ids[]" value="<?= (int)$bid ?>">
                        <?php endforeach; ?>

                        <div class="bill-grid">
                            <div>
                                <label>Data facturii</label>
                                <input type="date" name="invoice_date" value="<?= inv_h($previewInvoiceDate) ?>" required>
                            </div>
                            <div>
                                <label>Scadență</label>
                                <input type="date" name="due_date" value="<?= inv_h($previewDueDate) ?>" required>
                            </div>
                            <div style="grid-column:1/-1">
                                <label>Mențiuni (opțional)</label>
                                <input type="text" name="mentions" value="" placeholder="Mențiuni vizibile pe factură">
                            </div>
                            <div style="grid-column:1/-1">
                                <label>Observații (opțional)</label>
                                <input type="text" name="observations" value="" placeholder="Observații vizibile pe factură">
                            </div>
                        </div>

                        <?php if ($smartbillEnabled): ?>
                            <div class="bill-auto-receipt">
                                <label><input type="checkbox" name="auto_receipt" value="1"> Încasează acum (emite chitanță în SmartBill)</label>
                            </div>
                        <?php endif; ?>

                        <div class="bill-actions">
                            <?php if ($smartbillEnabled): ?>
                                <button class="btn accent" type="submit" name="send_to_smartbill" value="1">Emite în SmartBill</button>
                                <button class="btn" type="submit">Salvează draft local</button>
                            <?php else: ?>
                                <button class="btn accent" type="submit">Salvează draft local</button>
                            <?php endif; ?>
                            <a class="btn light" href="work_billing.php">Anulează</a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$prefill = [
    'client_id' => 0,
    'client_name' => '',
    'client_fiscal_code' => '',
    'client_reg_com' => '',
    'client_contact' => '',
    'client_email' => '',
    'client_phone' => '',
    'client_bank' => '',
    'client_iban' => '',
    'client_country' => 'Romania',
    'client_county' => '',
    'client_city' => '',
    'client_address' => '',
    'description' => '',
    'product_code' => '',
    'product_description' => '',
    'quantity' => '1.000',
    'unit_name' => 'buc',
    'unit_price' => '0.00',
    'vat_code' => $defaultVatCode,
    'is_tax_included' => '0',
    'is_service' => '1',
    'currency' => 'RON',
    'invoice_language' => 'RO',
    'issue_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+' . max(0, (int)($settings['smartbill.payment_due_days'] ?? 15)) . ' days')),
    'mentions' => '',
    'observations' => '',
    'notes' => '',
    'revenue_category' => 'ddd',
];

$loadedInvoice = null;
if ($invoiceIdFromRequest > 0) {
    $loadedInvoice = pz_smartbill_fetch_invoice($pdo, $invoiceIdFromRequest);
    if ($loadedInvoice) {
        $appointmentId = (int)($loadedInvoice['appointment_id'] ?? 0);
        $firstItem = $loadedInvoice['items'][0] ?? [];
        $prefill['client_id'] = (int)($loadedInvoice['client_id'] ?? 0);
        $prefill['client_name'] = (string)($loadedInvoice['client_name'] ?? '');
        $prefill['client_fiscal_code'] = (string)($loadedInvoice['client_fiscal_code'] ?? '');
        $prefill['client_reg_com'] = (string)($loadedInvoice['client_reg_com'] ?? '');
        $prefill['client_contact'] = (string)($loadedInvoice['client_contact'] ?? '');
        $prefill['client_email'] = (string)($loadedInvoice['client_email'] ?? '');
        $prefill['client_phone'] = (string)($loadedInvoice['client_phone'] ?? '');
        $prefill['client_bank'] = (string)($loadedInvoice['client_bank'] ?? '');
        $prefill['client_iban'] = (string)($loadedInvoice['client_iban'] ?? '');
        $prefill['client_country'] = (string)($loadedInvoice['client_country'] ?? 'Romania') ?: 'Romania';
        $prefill['client_county'] = (string)($loadedInvoice['client_county'] ?? '');
        $prefill['client_city'] = (string)($loadedInvoice['client_city'] ?? '');
        $prefill['client_address'] = (string)($loadedInvoice['client_address'] ?? '');
        $prefill['description'] = (string)($firstItem['description'] ?? '');
        $prefill['product_code'] = (string)($firstItem['product_code'] ?? '');
        $prefill['product_description'] = (string)($firstItem['product_description'] ?? '');
        $prefill['quantity'] = (string)($firstItem['quantity'] ?? '1.000');
        $prefill['unit_name'] = (string)($firstItem['unit_name'] ?? 'buc');
        $prefill['unit_price'] = number_format(pz_smartbill_money($firstItem['unit_price'] ?? 0), 2, '.', '');
        $prefill['vat_code'] = (string)($firstItem['vat_code'] ?? ($loadedInvoice['vat_code'] ?? $defaultVatCode));
        $prefill['is_tax_included'] = !empty($firstItem['is_tax_included']) ? '1' : '0';
        $prefill['is_service'] = !array_key_exists('is_service', $firstItem) || !empty($firstItem['is_service']) ? '1' : '0';
        $prefill['currency'] = (string)($loadedInvoice['currency'] ?? 'RON') ?: 'RON';
        $prefill['invoice_language'] = (string)($loadedInvoice['invoice_language'] ?? 'RO') ?: 'RO';
        $prefill['issue_date'] = (string)($loadedInvoice['invoice_date'] ?? date('Y-m-d'));
        $prefill['due_date'] = (string)($loadedInvoice['due_date'] ?? $prefill['due_date']);
        $prefill['mentions'] = (string)($loadedInvoice['mentions'] ?? '');
        $prefill['observations'] = (string)($loadedInvoice['observations'] ?? '');
        $prefill['notes'] = (string)($loadedInvoice['notes'] ?? '');
        $prefill['revenue_category'] = pz_revenue_category_normalize(
            (string)($loadedInvoice['revenue_category'] ?? 'ddd'),
            'ddd'
        );
    }
}

$clients = [];
if (inv_table_exists($pdo, 'clients')) {
    $clients = $pdo->query("
        SELECT id, name, fiscal_code, registry_number, email, phone, bank_name, bank_account,
               legal_representative_name, registered_address, billing_country, billing_county, billing_city, billing_address_line
        FROM clients
        WHERE active = 1
        ORDER BY name ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if ($appointmentId > 0 && inv_table_exists($pdo, 'appointments')) {
    $stmt = $pdo->prepare("
        SELECT a.*, c.name AS client_name, c.fiscal_code, c.registry_number, c.email, c.phone,
               c.bank_name, c.bank_account, c.legal_representative_name,
               c.registered_address, c.billing_country, c.billing_county, c.billing_city, c.billing_address_line
        FROM appointments a
        LEFT JOIN clients c ON c.id = a.client_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($appointment) {
        $prefill['client_id'] = (int)($appointment['client_id'] ?? 0);
        $prefill['client_name'] = (string)($appointment['client_name'] ?? '');
        $prefill['client_fiscal_code'] = (string)($appointment['fiscal_code'] ?? '');
        $prefill['client_reg_com'] = (string)($appointment['registry_number'] ?? '');
        $prefill['client_contact'] = (string)($appointment['legal_representative_name'] ?? '');
        $prefill['client_email'] = (string)($appointment['email'] ?? '');
        $prefill['client_phone'] = (string)($appointment['phone'] ?? '');
        $prefill['client_bank'] = (string)($appointment['bank_name'] ?? '');
        $prefill['client_iban'] = (string)($appointment['bank_account'] ?? '');
        $prefill['client_country'] = (string)($appointment['billing_country'] ?? 'Romania') ?: 'Romania';
        $prefill['client_county'] = (string)($appointment['billing_county'] ?? '');
        $prefill['client_city'] = (string)($appointment['billing_city'] ?? '');
        $prefill['client_address'] = trim((string)($appointment['billing_address_line'] ?? ''));

        // Fallback: dacă datele structurate de facturare lipsesc, încearcă să le extragi
        // din adresa fiscală preluată din ANAF (registered_address).
        if ($prefill['client_county'] === '' || $prefill['client_city'] === '' || $prefill['client_address'] === '') {
            $registered = trim((string)($appointment['registered_address'] ?? ''));
            if ($registered !== '') {
                $parsed = inv_parse_anaf_address($registered);
                if ($prefill['client_county'] === '' && $parsed['county'] !== '') {
                    $prefill['client_county'] = $parsed['county'];
                }
                if ($prefill['client_city'] === '' && $parsed['city'] !== '') {
                    $prefill['client_city'] = $parsed['city'];
                }
                if ($prefill['client_address'] === '') {
                    $prefill['client_address'] = $parsed['street'] !== '' ? $parsed['street'] : $registered;
                }
            }
        }

        $prefill['description'] = trim((string)($appointment['service_type'] ?? '')) ?: 'Servicii DDD';
        $prefill['unit_price'] = number_format(pz_smartbill_money($appointment['billing_amount'] ?? 0), 2, '.', '');
        $prefill['vat_code'] = (string)($appointment['billing_vat_code'] ?? $defaultVatCode);
        $prefill['currency'] = (string)($appointment['currency'] ?? 'RON') ?: 'RON';
        $prefill['notes'] = 'Factura generata din lucrarea #' . $appointmentId;
    }
}

if (!$loadedInvoice && $appointmentId <= 0 && $invoiceClientFilter > 0) {
    foreach ($clients as $client) {
        if ((int)$client['id'] !== $invoiceClientFilter) {
            continue;
        }
        $prefill['client_id'] = (int)$client['id'];
        $prefill['client_name'] = (string)($client['name'] ?? '');
        $prefill['client_fiscal_code'] = (string)($client['fiscal_code'] ?? '');
        $prefill['client_reg_com'] = (string)($client['registry_number'] ?? '');
        $prefill['client_contact'] = (string)($client['legal_representative_name'] ?? '');
        $prefill['client_email'] = (string)($client['email'] ?? '');
        $prefill['client_phone'] = (string)($client['phone'] ?? '');
        $prefill['client_bank'] = (string)($client['bank_name'] ?? '');
        $prefill['client_iban'] = (string)($client['bank_account'] ?? '');
        $prefill['client_country'] = (string)($client['billing_country'] ?? 'Romania') ?: 'Romania';
        $prefill['client_county'] = (string)($client['billing_county'] ?? '');
        $prefill['client_city'] = (string)($client['billing_city'] ?? '');
        $prefill['client_address'] = trim((string)($client['billing_address_line'] ?? ''));

        // Fallback la registered_address (ANAF) dacă datele structurate lipsesc
        if ($prefill['client_county'] === '' || $prefill['client_city'] === '' || $prefill['client_address'] === '') {
            $registered = trim((string)($client['registered_address'] ?? ''));
            if ($registered !== '') {
                $parsed = inv_parse_anaf_address($registered);
                if ($prefill['client_county'] === '' && $parsed['county'] !== '') {
                    $prefill['client_county'] = $parsed['county'];
                }
                if ($prefill['client_city'] === '' && $parsed['city'] !== '') {
                    $prefill['client_city'] = $parsed['city'];
                }
                if ($prefill['client_address'] === '') {
                    $prefill['client_address'] = $parsed['street'] !== '' ? $parsed['street'] : $registered;
                }
            }
        }
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) {
        csrf_require();
    }

    $action = (string)($_POST['action'] ?? 'save');

    // Flux nou: emitere factură pentru una sau mai multe poziții de facturat (billing_items).
    if ($action === 'issue_from_billing_items') {
        $rawIds = (array)($_POST['billing_item_ids'] ?? []);
        $itemIds = array_values(array_unique(array_map('intval', $rawIds)));
        $itemIds = array_values(array_filter($itemIds, static fn($v) => $v > 0));

        $opts = [
            'invoice_date'      => inv_date($_POST['invoice_date'] ?? null),
            'due_date'          => inv_date($_POST['due_date'] ?? null, inv_date($_POST['invoice_date'] ?? null)),
            'mentions'          => (string)($_POST['mentions'] ?? ''),
            'observations'      => (string)($_POST['observations'] ?? ''),
            'notes'             => (string)($_POST['notes'] ?? ''),
            'send_to_smartbill' => !empty($_POST['send_to_smartbill']),
        ];

        $result = pz_billing_issue_invoice($pdo, $itemIds, $opts);
        if (!empty($result['ok']) && (int)($result['invoice_id'] ?? 0) > 0) {
            $invoiceId = (int)$result['invoice_id'];
            $flag = !empty($result['draft']) ? 'draft' : 'issued';

            // Daca factura a fost emisa in SmartBill si utilizatorul a bifat „Incaseaza acum",
            // emite si chitanta pentru valoarea totala - acelasi flow ca la action='issue'.
            $autoReceipt = !empty($_POST['auto_receipt']) && (string)$_POST['auto_receipt'] === '1';
            if ($flag === 'issued' && $autoReceipt) {
                $issuedInvoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
                $gross = $issuedInvoice ? pz_smartbill_money($issuedInvoice['gross_amount'] ?? 0) : 0.0;
                if ($gross > 0) {
                    $receiptSettings = pz_smartbill_settings($pdo);
                    $receiptData = [
                        'payment_type'    => 'chitanta',
                        'amount'          => $gross,
                        'payment_date'    => date('Y-m-d'),
                        'currency'        => trim((string)($issuedInvoice['currency'] ?? 'RON')) ?: 'RON',
                        'document_series' => trim((string)($receiptSettings['smartbill.receipt_series'] ?? '')),
                    ];
                    $receiptResult = pz_smartbill_issue_payment($pdo, $invoiceId, $receiptData);
                    if (!empty($receiptResult['ok'])) {
                        header('Location: invoice.php?issued=1&payment_issued=1&id=' . $invoiceId);
                        exit;
                    }
                    $receiptError = (string)($receiptResult['error'] ?? 'Chitanța nu a putut fi emisă.');
                    header('Location: invoice.php?issued=1&receipt_error=' . urlencode($receiptError) . '&id=' . $invoiceId);
                    exit;
                }
                header('Location: invoice.php?issued=1&receipt_error=' . urlencode('Chitanța nu a putut fi emisă: valoarea facturii este 0.') . '&id=' . $invoiceId);
                exit;
            }

            header('Location: invoice.php?' . ($flag === 'draft' ? 'saved_draft=1' : 'issued=1') . '&id=' . $invoiceId);
            exit;
        }
        $errMsg = (string)($result['error'] ?? 'Factura nu a putut fi creată.');
        $invoiceParam = !empty($result['invoice_id']) ? '&id=' . (int)$result['invoice_id'] : '';
        header('Location: invoice.php?issue_error=' . urlencode($errMsg) . $invoiceParam);
        exit;
    }

    if ($action === 'issue') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        $autoReceipt = !empty($_POST['auto_receipt']) && (string)$_POST['auto_receipt'] === '1';
        if ($invoiceId <= 0) {
            $error = 'Factura nu a fost găsită.';
        } else {
            $result = pz_smartbill_issue_invoice($pdo, $invoiceId);
            if (!empty($result['ok'])) {
                // Dacă utilizatorul a bifat „Încasează acum", emite și chitanța (cash) pentru valoarea totală
                if ($autoReceipt) {
                    $issuedInvoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
                    $gross = $issuedInvoice ? pz_smartbill_money($issuedInvoice['gross_amount'] ?? 0) : 0.0;
                    if ($gross > 0) {
                        $receiptSettings = pz_smartbill_settings($pdo);
                        $receiptData = [
                            'payment_type' => 'chitanta',
                            'amount' => $gross,
                            'payment_date' => date('Y-m-d'),
                            'currency' => trim((string)($issuedInvoice['currency'] ?? 'RON')) ?: 'RON',
                            'document_series' => trim((string)($receiptSettings['smartbill.receipt_series'] ?? '')),
                        ];
                        $receiptResult = pz_smartbill_issue_payment($pdo, $invoiceId, $receiptData);
                        if (!empty($receiptResult['ok'])) {
                            header('Location: invoice.php?issued=1&payment_issued=1&id=' . $invoiceId);
                            exit;
                        }
                        $receiptError = (string)($receiptResult['error'] ?? 'Chitanța nu a putut fi emisă.');
                        header('Location: invoice.php?issued=1&receipt_error=' . urlencode($receiptError) . '&id=' . $invoiceId);
                        exit;
                    }
                    header('Location: invoice.php?issued=1&receipt_error=' . urlencode('Chitanța nu a putut fi emisă: valoarea facturii este 0.') . '&id=' . $invoiceId);
                    exit;
                }
                header('Location: invoice.php?issued=1&id=' . $invoiceId);
                exit;
            }
            header('Location: invoice.php?issue_error=' . urlencode((string)($result['error'] ?? 'Factura nu a putut fi emisă.')) . '&id=' . $invoiceId);
            exit;
        }
    }

    // C2 — reconciliere manuală pentru facturi blocate în „sending".
    if ($action === 'manual_reconcile') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        $series = trim((string)($_POST['manual_series'] ?? ''));
        $number = trim((string)($_POST['manual_number'] ?? ''));
        $url = trim((string)($_POST['manual_url'] ?? ''));
        if ($invoiceId <= 0) {
            $error = 'Factura nu a fost găsită.';
        } else {
            $result = pz_smartbill_mark_manually_issued($pdo, $invoiceId, $series, $number, $url);
            if (!empty($result['ok'])) {
                header('Location: invoice.php?reconciled=1&id=' . $invoiceId);
                exit;
            }
            header('Location: invoice.php?reconcile_error=' . urlencode((string)($result['error'] ?? 'Reconciliere eșuată.')) . '&id=' . $invoiceId);
            exit;
        }
    }

    // C2 — reset factură stuck la 'error' ca să poată fi re-emisă.
    if ($action === 'reset_stuck') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        if ($invoiceId <= 0) {
            $error = 'Factura nu a fost găsită.';
        } else {
            $result = pz_smartbill_reset_stuck_to_error($pdo, $invoiceId);
            if (!empty($result['ok'])) {
                header('Location: invoice.php?reset_ok=1&id=' . $invoiceId);
                exit;
            }
            header('Location: invoice.php?reset_error=' . urlencode((string)($result['error'] ?? 'Reset eșuat.')) . '&id=' . $invoiceId);
            exit;
        }
    }

    if ($action === 'reverse_invoice') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        if ($invoiceId <= 0) {
            $error = 'Factura nu a fost gasita.';
        } else {
            $result = pz_smartbill_reverse_invoice($pdo, $invoiceId, inv_date($_POST['reverse_issue_date'] ?? null));
            if (!empty($result['ok'])) {
                header('Location: invoice.php?storno_added=1&id=' . $invoiceId);
                exit;
            }
            header('Location: invoice.php?issue_error=' . urlencode((string)($result['error'] ?? 'Storno nu a putut fi adaugat.')) . '&id=' . $invoiceId);
            exit;
        }
    }

    if ($action === 'issue_from_estimate') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        if ($invoiceId <= 0) {
            $error = 'Salveaza mai intai factura draft, apoi emite pe baza de proforma.';
        } else {
            $result = pz_smartbill_issue_invoice_from_estimate($pdo, $invoiceId, (string)($_POST['estimate_series'] ?? ''), (string)($_POST['estimate_number'] ?? ''));
            if (!empty($result['ok'])) {
                header('Location: invoice.php?estimate_issued=1&id=' . $invoiceId);
                exit;
            }
            header('Location: invoice.php?issue_error=' . urlencode((string)($result['error'] ?? 'Factura pe baza de proforma nu a putut fi emisa.')) . '&id=' . $invoiceId);
            exit;
        }
    }

    if ($action === 'add_payment') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        if ($invoiceId <= 0) {
            $error = 'Factura nu a fost găsită.';
        } else {
            $result = pz_smartbill_issue_payment($pdo, $invoiceId, $_POST);
            if (!empty($result['ok'])) {
                header('Location: invoice.php?payment_issued=1&id=' . $invoiceId);
                exit;
            }
            header('Location: invoice.php?payment_error=' . urlencode((string)($result['error'] ?? 'Încasarea nu a putut fi emisă.')) . '&id=' . $invoiceId);
            exit;
        }
    }

    if ($action === 'delete_receipt') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        $paymentId = max(0, (int)($_POST['payment_id'] ?? 0));
        if ($invoiceId <= 0 || $paymentId <= 0) {
            $error = 'Chitanța nu a fost găsită.';
        } else {
            $result = pz_smartbill_delete_receipt($pdo, $paymentId);
            if (!empty($result['ok'])) {
                header('Location: invoice.php?receipt_deleted=1&id=' . $invoiceId);
                exit;
            }
            header('Location: invoice.php?payment_error=' . urlencode((string)($result['error'] ?? 'Chitanța nu a putut fi ștearsă.')) . '&id=' . $invoiceId);
            exit;
        }
    }

    if ($action === 'delete_invoice') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        $redirectTarget = (string)($_POST['return_to'] ?? 'invoices.php');
        // Permitem doar URL-uri relative din aplicatie ca redirect
        if (!preg_match('#^[A-Za-z0-9_./?=&-]+$#', $redirectTarget) || strpos($redirectTarget, '://') !== false) {
            $redirectTarget = 'invoices.php';
        }
        if ($invoiceId <= 0) {
            header('Location: ' . $redirectTarget . (strpos($redirectTarget, '?') === false ? '?' : '&') . 'delete_error=' . urlencode('Factură invalidă.'));
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT id, smartbill_number FROM smartbill_invoices WHERE id = ? LIMIT 1");
            $stmt->execute([$invoiceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                header('Location: ' . $redirectTarget . (strpos($redirectTarget, '?') === false ? '?' : '&') . 'delete_error=' . urlencode('Factura nu există.'));
                exit;
            }
            if (trim((string)($row['smartbill_number'] ?? '')) !== '') {
                header('Location: ' . $redirectTarget . (strpos($redirectTarget, '?') === false ? '?' : '&') . 'delete_error=' . urlencode('Factura este deja emisă în SmartBill. Pentru anulare, folosește storno.'));
                exit;
            }
            // Stergere fizica - factura este draft, nu a fost trimisa la SmartBill
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM smartbill_invoice_payments WHERE smartbill_invoice_id = ?")->execute([$invoiceId]);
            $pdo->prepare("DELETE FROM smartbill_invoices WHERE id = ?")->execute([$invoiceId]);
            $pdo->commit();
            header('Location: ' . $redirectTarget . (strpos($redirectTarget, '?') === false ? '?' : '&') . 'deleted=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('delete_invoice error: ' . $e->getMessage());
            header('Location: ' . $redirectTarget . (strpos($redirectTarget, '?') === false ? '?' : '&') . 'delete_error=' . urlencode('Factura nu a putut fi ștearsă.'));
            exit;
        }
    }

    if ($action === 'check_payment_status') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        if ($invoiceId <= 0) {
            $error = 'Factura nu a fost găsită.';
        } else {
            $result = pz_smartbill_sync_invoice_payment_status($pdo, $invoiceId);
            if (!empty($result['ok'])) {
                header('Location: invoice.php?payment_checked=1&id=' . $invoiceId);
                exit;
            }
            header('Location: invoice.php?payment_error=' . urlencode((string)($result['error'] ?? 'Statusul încasării nu a putut fi verificat.')) . '&id=' . $invoiceId);
            exit;
        }
    }

    if ($action === 'send_invoice_email') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        if ($invoiceId <= 0) {
            $error = 'Factura nu a fost găsită.';
        } else {
            $result = pz_smartbill_send_invoice_email($pdo, $invoiceId, (string)($_POST['email_to'] ?? ''));
            if (!empty($result['ok'])) {
                header('Location: invoice.php?email_sent=1&id=' . $invoiceId);
                exit;
            }
            header('Location: invoice.php?email_error=' . urlencode((string)($result['error'] ?? 'Factura nu a putut fi trimisă pe email.')) . '&id=' . $invoiceId);
            exit;
        }
    }

    if ($action === 'create_recurring') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        if ($invoiceId <= 0) {
            $error = 'Factura model nu a fost găsită.';
        } else {
            $result = pz_smartbill_create_recurring_schedule($pdo, $invoiceId, $_POST);
            if (!empty($result['ok'])) {
                header('Location: recurring_invoices.php?created=1');
                exit;
            }
            header('Location: invoice.php?recurring_error=' . urlencode((string)($result['error'] ?? 'Recurența nu a putut fi creată.')) . '&id=' . $invoiceId);
            exit;
        }
    }

    if ($action === 'update_revenue_category') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        $newCategory = pz_revenue_category_normalize($_POST['revenue_category'] ?? '', 'ddd');
        if ($invoiceId > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE smartbill_invoices SET revenue_category = ? WHERE id = ?");
                $stmt->execute([$newCategory, $invoiceId]);
                header('Location: invoice.php?id=' . $invoiceId . '&revenue_updated=1');
                exit;
            } catch (Throwable $e) {
                error_log('update_revenue_category: ' . $e->getMessage());
                header('Location: invoice.php?id=' . $invoiceId . '&revenue_error=' . urlencode('Nu s-a putut salva categoria.'));
                exit;
            }
        }
        header('Location: invoice.php');
        exit;
    }

    $clientId = max(0, (int)($_POST['client_id'] ?? 0));
    $clientName = trim((string)($_POST['client_name'] ?? ''));
    $clientFiscalCode = trim((string)($_POST['client_fiscal_code'] ?? ''));
    $clientRegCom = trim((string)($_POST['client_reg_com'] ?? ''));
    $clientContact = trim((string)($_POST['client_contact'] ?? ''));
    $clientEmail = trim((string)($_POST['client_email'] ?? ''));
    $clientPhone = trim((string)($_POST['client_phone'] ?? ''));
    $clientBank = trim((string)($_POST['client_bank'] ?? ''));
    $clientIban = trim((string)($_POST['client_iban'] ?? ''));
    $clientCountry = trim((string)($_POST['client_country'] ?? 'Romania')) ?: 'Romania';
    $clientCounty = trim((string)($_POST['client_county'] ?? ''));
    $clientCity = trim((string)($_POST['client_city'] ?? ''));
    $clientAddress = trim((string)($_POST['client_address'] ?? ''));
    $parsedItems = inv_parse_invoice_items($_POST, $vatOptions, $allowedVatCodes, $defaultVatCode);
    $invoiceItemsToSave = $parsedItems['items'];
    $itemErrors = $parsedItems['errors'];
    $vatCode = (string)($invoiceItemsToSave[0]['vat_code'] ?? $defaultVatCode);
    $currency = trim((string)($_POST['currency'] ?? 'RON')) ?: 'RON';
    $invoiceLanguage = trim((string)($_POST['invoice_language'] ?? 'RO')) ?: 'RO';
    $issueDate = inv_date($_POST['issue_date'] ?? null);
    $dueDate = inv_date($_POST['due_date'] ?? null, $issueDate);
    $mentions = trim((string)($_POST['mentions'] ?? ''));
    $observations = trim((string)($_POST['observations'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $revenueCategoryPost = pz_revenue_category_normalize($_POST['revenue_category'] ?? 'ddd', 'ddd');

    if ($clientName === '' || $clientFiscalCode === '' || $clientCountry === '' || $clientCounty === '' || $clientCity === '' || $clientAddress === '') {
        $error = 'Completează datele clientului pentru factură.';
    } elseif ($itemErrors) {
        $error = implode(' ', $itemErrors);
    } else {
        $net = 0.0;
        $vat = 0.0;
        $gross = 0.0;
        foreach ($invoiceItemsToSave as $itemToSave) {
            $net += pz_smartbill_money($itemToSave['line_total'] ?? 0);
            $vat += pz_smartbill_money($itemToSave['vat_amount'] ?? 0);
            $gross += pz_smartbill_money($itemToSave['gross_amount'] ?? 0);
        }
        $net = round($net, 2);
        $vat = round($vat, 2);
        $gross = round($gross, 2);

        $existingId = $invoiceIdFromRequest;
        if ($appointmentId > 0) {
            $stmt = $pdo->prepare("SELECT id FROM smartbill_invoices WHERE appointment_id = ? LIMIT 1");
            $stmt->execute([$appointmentId]);
            $existingByAppointment = (int)($stmt->fetchColumn() ?: 0);
            if ($existingByAppointment > 0) {
                $existingId = $existingByAppointment;
            }
        }

        if ($existingId > 0) {
            $stmt = $pdo->prepare("
                UPDATE smartbill_invoices
                SET source_type = ?, client_id = ?, client_name = ?, client_fiscal_code = ?, client_reg_com = ?,
                    client_contact = ?, client_email = ?, client_phone = ?, client_bank = ?, client_iban = ?,
                    client_country = ?, client_county = ?, client_city = ?, client_address = ?,
                    invoice_date = ?, due_date = ?, currency = ?, net_amount = ?, vat_code = ?, vat_amount = ?,
                    gross_amount = ?, invoice_language = ?, mentions = ?, observations = ?, notes = ?, smartbill_status = 'draft', updated_at = NOW(),
                    revenue_category = ?
                WHERE id = ?
            ");
            $stmt->execute([$appointmentId > 0 ? 'appointment' : 'manual', $clientId ?: null, $clientName, $clientFiscalCode, $clientRegCom, $clientContact, $clientEmail, $clientPhone, $clientBank, $clientIban, $clientCountry, $clientCounty, $clientCity, $clientAddress, $issueDate, $dueDate, $currency, $net, $vatCode, $vat, $gross, $invoiceLanguage, $mentions, $observations, $notes, $revenueCategoryPost, $existingId]);
            $invoiceId = $existingId;
            $pdo->prepare("DELETE FROM smartbill_invoice_items WHERE smartbill_invoice_id = ?")->execute([$invoiceId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO smartbill_invoices
                    (source_type, appointment_id, client_id, client_location_id, client_name, client_fiscal_code, client_reg_com,
                     client_contact, client_email, client_phone, client_bank, client_iban, client_country, client_county,
                     client_city, client_address, invoice_date, due_date, currency, net_amount, vat_code, vat_amount,
                     gross_amount, invoice_language, mentions, observations, smartbill_status, notes, created_by, revenue_category)
                VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?)
            ");
            $stmt->execute([
                $appointmentId > 0 ? 'appointment' : 'manual',
                $appointmentId ?: null,
                $clientId ?: null,
                $clientName,
                $clientFiscalCode,
                $clientRegCom,
                $clientContact,
                $clientEmail,
                $clientPhone,
                $clientBank,
                $clientIban,
                $clientCountry,
                $clientCounty,
                $clientCity,
                $clientAddress,
                $issueDate,
                $dueDate,
                $currency,
                $net,
                $vatCode,
                $vat,
                $gross,
                $invoiceLanguage,
                $mentions,
                $observations,
                $notes,
                function_exists('current_user_id') ? current_user_id() : null,
                $revenueCategoryPost,
            ]);
            $invoiceId = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("
            INSERT INTO smartbill_invoice_items
                (smartbill_invoice_id, appointment_id, product_code, description, product_description, quantity, unit_name,
                 unit_price, vat_code, is_tax_included, is_service, line_total, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $sortOrder = 1;
        foreach ($invoiceItemsToSave as $itemToSave) {
            $stmt->execute([
                $invoiceId,
                $appointmentId ?: null,
                (string)$itemToSave['product_code'],
                (string)$itemToSave['description'],
                (string)$itemToSave['product_description'],
                (float)$itemToSave['quantity'],
                (string)$itemToSave['unit_name'],
                pz_smartbill_money($itemToSave['unit_price']),
                (string)$itemToSave['vat_code'],
                !empty($itemToSave['is_tax_included']) ? 1 : 0,
                !empty($itemToSave['is_service']) ? 1 : 0,
                pz_smartbill_money($itemToSave['line_total']),
                $sortOrder++,
            ]);
        }

        $pdo->prepare("
            INSERT INTO smartbill_invoice_logs (smartbill_invoice_id, appointment_id, action, status, message, created_by)
            VALUES (?, ?, 'draft_saved', 'draft', 'Factura pregătită în CRM.', ?)
        ")->execute([$invoiceId, $appointmentId ?: null, function_exists('current_user_id') ? current_user_id() : null]);

        // Dacă utilizatorul a bifat „Încasează acum", emite imediat factura și chitanța în SmartBill
        $autoReceiptOnSave = !empty($_POST['auto_receipt']) && (string)$_POST['auto_receipt'] === '1';
        if ($autoReceiptOnSave) {
            $issueResult = pz_smartbill_issue_invoice($pdo, $invoiceId);
            if (empty($issueResult['ok'])) {
                header('Location: invoice.php?saved=1&issue_error=' . urlencode((string)($issueResult['error'] ?? 'Factura draft a fost salvată, dar emiterea în SmartBill a eșuat.')) . '&id=' . $invoiceId);
                exit;
            }
            $issuedInvoice = pz_smartbill_fetch_invoice($pdo, $invoiceId);
            $gross = $issuedInvoice ? pz_smartbill_money($issuedInvoice['gross_amount'] ?? 0) : 0.0;
            if ($gross > 0) {
                $receiptSettings = pz_smartbill_settings($pdo);
                $receiptData = [
                    'payment_type' => 'chitanta',
                    'amount' => $gross,
                    'payment_date' => date('Y-m-d'),
                    'currency' => trim((string)($issuedInvoice['currency'] ?? 'RON')) ?: 'RON',
                    'document_series' => trim((string)($receiptSettings['smartbill.receipt_series'] ?? '')),
                ];
                $receiptResult = pz_smartbill_issue_payment($pdo, $invoiceId, $receiptData);
                if (!empty($receiptResult['ok'])) {
                    header('Location: invoice.php?issued=1&payment_issued=1&id=' . $invoiceId);
                    exit;
                }
                header('Location: invoice.php?issued=1&receipt_error=' . urlencode((string)($receiptResult['error'] ?? 'Chitanța nu a putut fi emisă.')) . '&id=' . $invoiceId);
                exit;
            }
            header('Location: invoice.php?issued=1&receipt_error=' . urlencode('Chitanța nu a putut fi emisă: valoarea facturii este 0.') . '&id=' . $invoiceId);
            exit;
        }

        header('Location: invoice.php?saved=1&id=' . $invoiceId);
        exit;
    }
}

$recent = [];
try {
    $whereRecent = ["source_type <> 'receipt'"];
    $paramsRecent = [];
    if ($invoiceSearch !== '') {
        $whereRecent[] = "(client_name LIKE ? OR client_fiscal_code LIKE ? OR smartbill_series LIKE ? OR smartbill_number LIKE ?)";
        $likeRecent = '%' . $invoiceSearch . '%';
        array_push($paramsRecent, $likeRecent, $likeRecent, $likeRecent, $likeRecent);
    }
    if ($invoiceClientFilter > 0) {
        $whereRecent[] = "client_id = ?";
        $paramsRecent[] = $invoiceClientFilter;
    }
    if ($invoiceStatusFilter !== 'all') {
        $whereRecent[] = "smartbill_status = ?";
        $paramsRecent[] = $invoiceStatusFilter;
    }
    $stmt = $pdo->prepare("SELECT * FROM smartbill_invoices WHERE " . implode(' AND ', $whereRecent) . " ORDER BY id DESC LIMIT 40");
    $stmt->execute($paramsRecent);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recent = [];
}
$recentStatus = [];
foreach ($recent as $row) {
    $full = pz_smartbill_fetch_invoice($pdo, (int)$row['id']);
    if ($full) {
        $recentStatus[(int)$row['id']] = [
            'paid' => pz_smartbill_paid_amount($full),
            'status' => pz_smartbill_payment_status($full),
        ];
    }
}

$loadedInvoice = $invoiceIdFromRequest > 0 ? pz_smartbill_fetch_invoice($pdo, $invoiceIdFromRequest) : $loadedInvoice;
$loadedPaid = $loadedInvoice ? pz_smartbill_paid_amount($loadedInvoice) : 0.0;
$loadedGross = $loadedInvoice ? pz_smartbill_money($loadedInvoice['gross_amount'] ?? 0) : 0.0;
$loadedRemaining = max(0, round($loadedGross - $loadedPaid, 2));
$loadedPaymentStatus = $loadedInvoice ? pz_smartbill_payment_status($loadedInvoice) : 'neincasata';

// Mod de afișare: 'edit' (formular) sau 'preview' (schiță read-only)
// Default după save: preview. Trecere la edit prin ?mode=edit.
$invoiceMode = (string)($_GET['mode'] ?? '');
$invoiceIsIssued = $loadedInvoice && trim((string)($loadedInvoice['smartbill_number'] ?? '')) !== '';
$invoiceIsDraft = $loadedInvoice && !$invoiceIsIssued;
$showInvoicePreview = $invoiceIsDraft && $invoiceMode !== 'edit';
$paymentTypes = pz_smartbill_payment_types();
$primaryPaymentTypes = array_intersect_key($paymentTypes, array_flip(['chitanta', 'card', 'transfer_bancar']));
$invoiceUnitOptions = ['buc', 'kg', 'l', 'ml', 'mp'];

$clientsForJs = [];
$invDecode = static function ($v): string {
    return html_entity_decode((string)($v ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
foreach ($clients as $client) {
    $clientsForJs[(int)$client['id']] = [
        'name' => $invDecode($client['name'] ?? ''),
        'fiscal_code' => $invDecode($client['fiscal_code'] ?? ''),
        'reg_com' => $invDecode($client['registry_number'] ?? ''),
        'contact' => $invDecode($client['legal_representative_name'] ?? ''),
        'email' => $invDecode($client['email'] ?? ''),
        'phone' => $invDecode($client['phone'] ?? ''),
        'bank' => $invDecode($client['bank_name'] ?? ''),
        'iban' => $invDecode($client['bank_account'] ?? ''),
        'country' => $invDecode($client['billing_country'] ?? 'Romania') ?: 'Romania',
        'county' => $invDecode($client['billing_county'] ?? ''),
        'city' => $invDecode($client['billing_city'] ?? ''),
        'address' => trim($invDecode($client['billing_address_line'] ?? '')),
    ];
}

$invoiceItems = [];
if ($loadedInvoice && !empty($loadedInvoice['items'])) {
    foreach ($loadedInvoice['items'] as $item) {
        $invoiceItems[] = [
            'description' => (string)($item['description'] ?? ''),
            'product_code' => (string)($item['product_code'] ?? ''),
            'product_description' => (string)($item['product_description'] ?? ''),
            'quantity' => (string)($item['quantity'] ?? '1.000'),
            'unit_name' => (string)($item['unit_name'] ?? 'buc'),
            'unit_price' => number_format(pz_smartbill_money($item['unit_price'] ?? 0), 2, '.', ''),
            'vat_code' => (string)($item['vat_code'] ?? $defaultVatCode),
            'is_tax_included' => !empty($item['is_tax_included']) ? '1' : '0',
            'is_service' => !array_key_exists('is_service', $item) || !empty($item['is_service']) ? '1' : '0',
        ];
    }
}
if (!$invoiceItems) {
    $invoiceItems[] = [
        'description' => (string)$prefill['description'],
        'product_code' => (string)$prefill['product_code'],
        'product_description' => (string)$prefill['product_description'],
        'quantity' => (string)$prefill['quantity'],
        'unit_name' => (string)$prefill['unit_name'],
        'unit_price' => (string)$prefill['unit_price'],
        'vat_code' => (string)$prefill['vat_code'],
        'is_tax_included' => (string)$prefill['is_tax_included'],
        'is_service' => (string)$prefill['is_service'],
    ];
}
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>Factura</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        /* Pagina factura — aliniata cu paleta pz-* si pattern-ul panel/alert/btn din restul aplicatiei.
           HTML, JS si PHP nu se ating; doar CSS-ul shell. */

        /* Layout principal */
        .invoice-page { max-width:1460px; margin:0 auto; display:grid; grid-template-columns:1fr; gap:10px; }

        /* Hero (titlu + butoane) */
        .hero { grid-column:1/-1; display:flex; justify-content:space-between; gap:14px; align-items:center; flex-wrap:wrap; padding:4px 0 2px; }
        .hero h1 { margin:0; font-size:22px; font-weight:700; color:var(--text); letter-spacing:-.02em; }
        .hero p { margin:4px 0 0; color:var(--pz-mu); font-weight:600; font-size:12px; }

        /* Card generic (folosit ca panel) */
        .card { background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:var(--pz-r); box-shadow:none; padding:14px; }
        .card h2 { margin:0 0 12px; font-size:14px; font-weight:800; color:var(--text); letter-spacing:0; }

        /* Form grid generic */
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .full { grid-column:1/-1; }

        /* Labels & inputs generice */
        label { display:block; font-size:10.5px; font-weight:700; margin:8px 0 4px; color:var(--pz-mu); text-transform:uppercase; letter-spacing:.04em; }
        input, select, textarea { width:100%; min-height:34px; border:1px solid var(--pz-line); border-radius:var(--pz-rs); padding:7px 10px; font:inherit; font-size:12.5px; font-weight:600; background:#fff; color:var(--text); box-sizing:border-box; }
        input:focus, select:focus, textarea:focus { border-color:var(--accent); outline:none; box-shadow:0 0 0 3px var(--accent-soft); }
        textarea { min-height:70px; resize:vertical; }

        /* Editor factura (panel cu head + body) */
        .invoice-editor { padding:0; overflow:hidden; background:#fff; }
        .invoice-editor-head { display:flex; align-items:center; justify-content:space-between; gap:12px; border-bottom:1px solid var(--pz-lines); padding:12px 14px; background:var(--pz-soft, #F8FAFC); }
        .invoice-editor-head h2 { font-size:15px; margin:0; }
        .invoice-editor-body { padding:14px; }
        .invoice-editor .form-grid { grid-template-columns:minmax(220px,1.35fr) minmax(120px,.75fr) minmax(120px,.75fr) minmax(120px,.75fr); gap:8px 12px; }
        .invoice-editor label { font-size:10.5px; margin:6px 0 4px; }
        .invoice-editor input, .invoice-editor select { min-height:32px; padding:6px 9px; font-size:12.5px; }
        .invoice-editor textarea { min-height:52px; padding:7px 9px; font-size:12.5px; }
        .invoice-editor .btn { min-height:32px; padding:6px 11px; }

        .invoice-save-bar { display:flex; justify-content:flex-end; border-top:1px solid var(--pz-lines); padding:12px 14px; background:var(--pz-soft, #F8FAFC); }
        .invoice-save-actions { display:flex; justify-content:flex-end; gap:8px; }

        /* ── Preview schiță factură ── */
        .invoice-preview { padding:0; overflow:hidden; background:#fff; border:1px solid var(--pz-line); border-radius:var(--pz-r); }
        .invoice-preview-head { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:14px 16px; border-bottom:1px solid var(--pz-lines); background:var(--pz-soft, #F8FAFC); flex-wrap:wrap; }
        .invoice-preview-head h2 { margin:0; font-size:16px; font-weight:800; color:var(--text); letter-spacing:0; }
        .invoice-preview-head .muted { color:var(--pz-mu); font-weight:600; font-size:12px; }
        .invoice-preview-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .preview-badge { display:inline-flex; align-items:center; padding:3px 9px; border-radius:999px; background:var(--pz-ors); color:var(--pz-or); border:1px solid var(--pz-orb); font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
        .invoice-preview-body { padding:16px; display:grid; gap:14px; }
        .preview-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
        .preview-box { border:1px solid var(--pz-line); border-radius:var(--pz-rs); background:var(--pz-soft, #F8FAFC); padding:10px 12px; }
        .preview-box > span { display:block; color:var(--pz-mu); font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
        .preview-box > strong { display:block; margin-top:4px; font-size:13px; font-weight:700; color:var(--text); }
        .preview-box .muted { display:block; margin-top:3px; color:var(--pz-mu); font-weight:600; font-size:11.5px; }
        .preview-items { width:100%; border-collapse:collapse; font-size:12.5px; border:1px solid var(--pz-line); border-radius:var(--pz-rs); overflow:hidden; }
        .preview-items th { background:var(--pz-soft, #F8FAFC); color:var(--pz-mu); font-size:10.5px; text-transform:uppercase; font-weight:700; letter-spacing:.04em; padding:8px 10px; text-align:left; border-bottom:1px solid var(--pz-lines); }
        .preview-items td { padding:9px 10px; border-bottom:1px solid var(--pz-lines); vertical-align:top; }
        .preview-items tbody tr:last-child td { border-bottom:0; }
        .preview-items strong { color:var(--text); font-weight:700; font-size:12.5px; }
        .preview-items .muted { display:block; color:var(--pz-mu); font-weight:500; font-size:11.5px; margin-top:2px; }
        .preview-totals { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; }
        .preview-totals > div { border:1px solid var(--pz-line); border-radius:var(--pz-rs); padding:10px 12px; background:var(--pz-soft, #F8FAFC); }
        .preview-totals span { display:block; color:var(--pz-mu); font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
        .preview-totals strong { display:block; margin-top:5px; font-size:14px; font-weight:700; color:var(--text); font-variant-numeric:tabular-nums; }
        .preview-totals .preview-grand { background:var(--accent-soft); border-color:var(--accent-soft-2); }
        .preview-totals .preview-grand strong { color:var(--accent-deep); font-size:16px; font-weight:800; }
        .preview-mentions { border:1px solid var(--pz-line); border-radius:var(--pz-rs); padding:10px 12px; background:#fff; }
        .preview-mentions > span { display:block; color:var(--pz-mu); font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
        .preview-mentions > p { margin:0; font-size:12.5px; color:var(--text); line-height:1.5; }
        @media(max-width:980px) { .preview-grid { grid-template-columns:1fr; } .preview-totals { grid-template-columns:1fr; } }

        /* ── Modal facturare recurentă ── */
        .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:flex-start; justify-content:center; padding:6vh 16px; z-index:1000; overflow-y:auto; }
        .modal-overlay[hidden] { display:none; }
        .modal-overlay.is-open { display:flex; }
        .modal-card { width:100%; max-width:760px; background:#fff; border:1px solid var(--pz-line); border-radius:var(--pz-r); box-shadow:0 24px 60px rgba(15,23,42,.20); overflow:hidden; }
        .modal-head { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; padding:14px 16px; background:var(--pz-soft, #F8FAFC); border-bottom:1px solid var(--pz-lines); }
        .modal-head h2 { margin:0; font-size:15px; font-weight:800; letter-spacing:0; color:var(--text); }
        .modal-close { width:32px; height:32px; min-height:32px; border:1px solid var(--pz-line); border-radius:50%; background:#fff; color:var(--pz-mu); font-size:18px; line-height:1; cursor:pointer; padding:0; }
        .modal-close:hover { color:var(--text); border-color:var(--accent); }
        .modal-body { padding:16px; }
        .modal-foot { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:8px; flex-wrap:wrap; }

        /* SmartBill grid (header sectiune) + client compact */
        .smartbill-grid { display:grid; grid-template-columns:minmax(220px,1.25fr) 160px 160px minmax(150px,.9fr); gap:12px 20px; align-items:end; }
        .smartbill-grid .wide { grid-column:span 2; }
        .compact-client { display:grid; grid-template-columns:1fr 1fr; gap:8px; }

        /* Compact product / items */
        .compact-product { border:1px solid var(--pz-line); border-radius:var(--pz-r); background:#fff; margin:12px 0 0; padding:12px 14px; }
        .compact-product-title { font-size:11px; font-weight:800; color:var(--pz-mu); text-transform:uppercase; letter-spacing:.04em; margin:0 0 8px; }
        .items-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin:0 0 8px; }
        .items-head h2 { font-size:14px; margin:0; }
        .items-head .btn { display:inline-flex; align-items:center; justify-content:center; line-height:1; }

        .invoice-items { display:grid; gap:8px; margin-top:0; padding:14px 22px; }
        .invoice-item { border:0; border-radius:0; background:transparent; padding:16px 24px; width:fit-content; max-width:100%; margin-left:auto; margin-right:auto; }
        .invoice-item-grid { display:grid; grid-template-columns:minmax(180px,360px) 70px 100px 84px 110px 110px 80px; gap:12px; align-items:end; }
        .invoice-item-extra { display:grid; grid-template-columns:minmax(180px,360px) 110px 160px; gap:12px; align-items:end; margin:7px 0 10px 0; }
        .invoice-line-head { display:grid; grid-template-columns:minmax(180px,360px) 76px 112px 92px 120px 120px 86px; gap:12px; background:var(--pz-brand); color:#fff; font-size:11px; font-weight:700; padding:8px 22px; margin-top:10px; justify-content:center; }
        .invoice-line-hint { display:flex; align-items:center; justify-content:center; gap:8px; color:var(--pz-mu); font-size:12px; font-weight:600; padding:10px; border-bottom:1px solid var(--pz-lines); }

        .item-remove { min-width:42px; align-self:end; min-height:32px; height:32px; padding:0 8px; line-height:1; }

        /* Tax label checkbox */
        .tax-included-label { display:flex; align-items:center; gap:6px; margin:0; height:32px; text-transform:none; letter-spacing:0; color:var(--text); white-space:nowrap; font-weight:600; font-size:12px; }
        .tax-included-label input { width:auto; min-height:0; margin:0; }

        /* Extra panel (details/summary) */
        .invoice-extra-panel { border-top:1px solid var(--pz-lines); padding-top:14px; margin-top:14px; }
        .invoice-extra-panel details { border:1px solid var(--pz-line); border-radius:var(--pz-r); margin-bottom:10px; background:#fff; }
        .invoice-extra-panel summary { cursor:pointer; padding:10px 12px; font-size:12.5px; font-weight:700; color:var(--accent-deep); list-style:none; }
        .invoice-extra-panel summary::-webkit-details-marker { display:none; }
        .invoice-extra-content { padding:0 12px 12px; }

        /* Invoice options dropdown */
        .invoice-options { position:relative; }
        .invoice-options-toggle { cursor:pointer; border:1px solid var(--pz-line); background:#fff; border-radius:var(--pz-rs); min-height:32px; padding:6px 10px; font-size:12.5px; font-weight:700; color:var(--text); }
        .invoice-options-toggle:hover { border-color:var(--accent); }
        .invoice-options.open .invoice-options-toggle { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }
        .invoice-options-menu { position:absolute; right:0; top:36px; z-index:20; width:230px; border:1px solid var(--pz-line); border-radius:var(--pz-rs); background:#fff; box-shadow:0 8px 20px rgba(15,23,42,.08); padding:6px; display:none; gap:4px; }
        .invoice-options.open .invoice-options-menu { display:grid; }
        .invoice-options-menu button { border:0; background:#fff; text-align:left; border-radius:var(--pz-rs); padding:8px 10px; font-weight:700; font-size:12px; cursor:pointer; color:var(--text); }
        .invoice-options-menu button:hover { background:var(--accent-soft); color:var(--accent-deep); }

        /* Alerts */
        .alert { grid-column:1/-1; border-radius:var(--pz-rs); padding:10px 13px; font-weight:600; font-size:12.5px; }
        .alert.ok { background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); }
        .alert.err { background:var(--pz-res); color:var(--pz-re); border:1px solid var(--pz-reb); }
        .alert.warn { background:#FFF7E6; color:#9A6700; border:1px solid #F0C36D; }

        /* Issue + auto-receipt */
        .issue-with-receipt { display:inline-flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .auto-receipt-label { display:inline-flex; align-items:center; gap:6px; font-size:12.5px; font-weight:600; color:var(--pz-mu); cursor:pointer; user-select:none; }
        .auto-receipt-label input[type=checkbox] { margin:0; }

        /* Tables */
        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th, td { padding:9px 10px; border-bottom:1px solid var(--pz-lines); text-align:left; vertical-align:top; }
        th { font-size:10.5px; text-transform:uppercase; color:var(--pz-mu); font-weight:700; letter-spacing:.04em; background:var(--pz-soft, #F8FAFC); }
        tbody tr:last-child td { border-bottom:0; }

        /* Pills */
        .pill { display:inline-flex; align-items:center; border-radius:var(--pz-rs); padding:4px 9px; background:var(--pz-soft); font-weight:600; color:var(--pz-mu); font-size:11.5px; }
        .pill.ok { background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); }
        .pill.warn { background:var(--pz-ors); color:var(--pz-or); border:1px solid var(--pz-orb); }
        .pill.err { background:var(--pz-res); color:var(--pz-re); border:1px solid var(--pz-reb); }

        /* Payments summary */
        .payment-summary { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; margin:12px 0; }
        .payment-box { border:1px solid var(--pz-line); border-radius:var(--pz-r); background:var(--pz-surf); padding:10px 12px; }
        .payment-box span { display:block; color:var(--pz-mu); font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
        .payment-box strong { display:block; margin-top:4px; font-size:16px; font-weight:700; color:var(--text); }
        .payment-table { margin-top:12px; }

        /* Payment form */
        .payment-card { border:1px solid var(--pz-line); border-radius:var(--pz-r); background:#fff; padding:11px; margin-top:8px; }
        /* Toggle pentru formul de incasare manuala - ascuns implicit, expand la click. */
        .payment-toggle { margin-top:8px; }
        .payment-toggle > summary {
            display:inline-flex;
            align-items:center;
            gap:6px;
            cursor:pointer;
            list-style:none;
            border:1px solid var(--pz-line);
            background:var(--surface, #fff);
            color:var(--text);
            border-radius:var(--pz-rs);
            padding:7px 13px;
            font-size:12.5px;
            font-weight:700;
            user-select:none;
        }
        .payment-toggle > summary::-webkit-details-marker { display:none; }
        .payment-toggle > summary::before { content:"+"; font-weight:900; font-size:14px; color:var(--pz-mu); }
        .payment-toggle[open] > summary::before { content:"−"; }
        .payment-toggle > summary:hover { background:var(--pz-soft); }
        .payment-compact { display:grid; grid-template-columns:1fr 110px 110px; gap:8px; align-items:end; }
        .payment-methods { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:7px; }
        .payment-methods label { display:flex; align-items:center; gap:7px; border:1px solid var(--pz-line); border-radius:var(--pz-rs); padding:8px; margin:0; text-transform:none; letter-spacing:0; color:var(--text); font-size:12.5px; font-weight:600; cursor:pointer; }
        .payment-methods input { width:auto; min-height:0; margin:0; }
        .payment-methods label:has(input:checked) { border-color:var(--pz-gr); background:var(--pz-grs); color:var(--pz-gr); }
        .payment-extra { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px; }
        .payment-submit { display:flex; justify-content:flex-end; margin-top:8px; }

        /* Issued card (badge verde mare cu numarul facturii) */
        .issued-card { border:1px solid var(--pz-grb); background:var(--pz-grs); color:var(--pz-gr); border-radius:var(--pz-r); padding:11px 14px; margin:10px 0; display:grid; gap:4px; }
        .issued-card strong { font-size:15px; color:var(--pz-gr); font-weight:700; }
        .issued-card span { font-size:12px; font-weight:600; }
        .issued-lock { display:inline-flex; align-items:center; justify-content:center; min-height:30px; border-radius:var(--pz-rs); background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); font-weight:700; padding:6px 11px; font-size:12px; }

        /* Action panel */
        .action-panel { display:grid; gap:10px; grid-template-columns:1fr; }
        .action-buttons { display:flex; gap:6px; flex-wrap:wrap; margin-top:2px; }
        .action-buttons .btn { min-height:30px; padding:6px 10px; font-size:12px; }

        /* Lista facturi (sidebar dreapta) */
        .invoice-list-filter { display:grid; grid-template-columns:1fr 130px auto; gap:8px; align-items:end; margin:12px 0 14px; }
        .invoice-list { display:grid; gap:10px; }
        .invoice-list-empty { padding:18px; border:1px dashed var(--pz-line); border-radius:var(--pz-r); background:var(--pz-soft); color:var(--pz-mu); font-weight:600; text-align:center; }
        .invoice-card { border:1px solid var(--pz-line); border-radius:var(--pz-r); background:#fff; padding:12px; display:grid; gap:10px; box-shadow:none; }
        .invoice-card:hover { border-color:var(--accent); }
        .invoice-card-top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
        .invoice-client { font-size:13px; font-weight:700; color:var(--text); line-height:1.25; overflow-wrap:anywhere; }
        .invoice-ref { margin-top:3px; color:var(--pz-mu); font-size:11.5px; font-weight:600; }
        .invoice-card-money { font-size:15px; font-weight:700; text-align:right; white-space:nowrap; color:var(--text); }
        .invoice-card-money span { display:block; margin-top:3px; color:var(--pz-mu); font-size:10.5px; font-weight:600; }
        .invoice-card-meta { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
        .invoice-meta-box { border:1px solid var(--pz-line); border-radius:var(--pz-rs); background:var(--pz-soft); padding:8px; }
        .invoice-meta-box span { display:block; font-size:10px; text-transform:uppercase; letter-spacing:.04em; color:var(--pz-mu); font-weight:700; }
        .invoice-meta-box strong { display:block; margin-top:3px; font-size:12px; color:var(--text); font-weight:700; }
        .invoice-card-actions { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        .invoice-card-actions form { margin:0; }
        .invoice-card-actions .btn { min-height:28px; padding:5px 8px; font-size:11.5px; }

        /* Muted text generic */
        .muted { color:var(--pz-mu); font-weight:600; }

        /* Responsive consolidat */
        @media(max-width:1280px) { .invoice-page { grid-template-columns:1fr; } }
        @media(max-width:1120px) {
            .smartbill-grid, .invoice-item-grid, .invoice-item-extra, .invoice-line-head,
            .invoice-list-filter, .invoice-editor .form-grid { grid-template-columns:1fr 1fr; }
            .invoice-line-head { display:none; }
            .item-remove { width:100%; }
            .smartbill-grid .wide { grid-column:auto; }
            .invoice-item { width:auto; max-width:none; }
        }
        @media(max-width:980px) { .form-grid { grid-template-columns:1fr; } }
        @media(max-width:720px) { .payment-compact, .payment-methods, .payment-extra { grid-template-columns:1fr; } }
        @media(max-width:640px) {
            .invoice-editor .form-grid, .invoice-item-grid, .invoice-item-extra, .invoice-list-filter { grid-template-columns:1fr; }
            .invoice-card-top, .invoice-card-meta { grid-template-columns:1fr; display:grid; }
            .invoice-card-money { text-align:left; }
        }

    </style>
    <?php render_search_preview_assets(); ?>
</head>
<body>
<div class="layout">
    <?php render_sidebar('factura', true); ?>
    <main class="main">
        <div class="content invoice-page">
            <section class="hero">
                <div>
                    <h1><?php
                        if ($invoiceIsIssued) {
                            echo 'Factură emisă';
                        } elseif ($appointmentId > 0) {
                            echo 'Factură din lucrare';
                        } else {
                            echo 'Factură nouă';
                        }
                    ?></h1>
                    <p><?php
                        if ($invoiceIsIssued) {
                            echo 'Factura a fost emisă cu succes în SmartBill. Vezi detaliile mai jos sau emite alta nouă.';
                        } elseif ($appointmentId > 0) {
                            echo 'Datele sunt preluate din lucrare și pot fi verificate înainte de emitere.';
                        } else {
                            echo 'Emitere factură manuală, separată de registrul lucrărilor.';
                        }
                    ?></p>
                </div>
                <div class="action-buttons">
                    <a class="btn ghost" href="invoices.php">Facturi</a>
                    <a class="btn accent" href="invoice.php">+ Factură</a>
                    <a class="btn ghost" href="work_billing.php">Lucrări</a>
                </div>
            </section>

            <?php render_billing_module_nav('facturi'); ?>

            <?php if (isset($_GET['saved'])): ?><div class="alert ok">Factura a fost pregătită în CRM.</div><?php endif; ?>
            <?php if (isset($_GET['issued'])): ?><div class="alert ok">Factura a fost emisă în SmartBill.</div><?php endif; ?>
            <?php if (isset($_GET['storno_added'])): ?><div class="alert ok">Factura storno a fost adăugată în SmartBill.</div><?php endif; ?>
            <?php if (isset($_GET['estimate_issued'])): ?><div class="alert ok">Factura a fost emisă pe baza proformei SmartBill.</div><?php endif; ?>
            <?php if (isset($_GET['payment_issued'])): ?><div class="alert ok">Încasarea a fost emisă în SmartBill și salvată în CRM.</div><?php endif; ?>
            <?php if (isset($_GET['receipt_deleted'])): ?><div class="alert ok">Chitanța a fost ștearsă din SmartBill și marcată în CRM.</div><?php endif; ?>
            <?php if (isset($_GET['payment_checked'])): ?><div class="alert ok">Statusul încasării a fost verificat în SmartBill.</div><?php endif; ?>
            <?php if (isset($_GET['email_sent'])): ?><div class="alert ok">Factura a fost trimisă pe email prin SmartBill.</div><?php endif; ?>
            <?php if (isset($_GET['issue_error'])): ?><div class="alert err"><?= inv_h($_GET['issue_error']) ?></div><?php endif; ?>
            <?php if (isset($_GET['payment_error'])): ?><div class="alert err"><?= inv_h($_GET['payment_error']) ?></div><?php endif; ?>
            <?php if (isset($_GET['receipt_error'])): ?><div class="alert warn">Factura a fost emisă, dar chitanța NU a putut fi emisă: <?= inv_h($_GET['receipt_error']) ?>. Poți încasa manual din butonul „Încasează".</div><?php endif; ?>
            <?php if (isset($_GET['email_error'])): ?><div class="alert err"><?= inv_h($_GET['email_error']) ?></div><?php endif; ?>
            <?php if (isset($_GET['recurring_error'])): ?><div class="alert err"><?= inv_h($_GET['recurring_error']) ?></div><?php endif; ?>
            <?php if (isset($_GET['revenue_updated'])): ?><div class="alert ok">Categoria veniturilor a fost actualizată.</div><?php endif; ?>
            <?php if (isset($_GET['revenue_error'])): ?><div class="alert err"><?= inv_h($_GET['revenue_error']) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert err"><?= inv_h($error) ?></div><?php endif; ?>

            <?php if ($showInvoicePreview):
                $previewClientName = trim((string)($loadedInvoice['client_name'] ?? ''));
                $previewClientFiscal = trim((string)($loadedInvoice['client_fiscal_code'] ?? ''));
                $previewClientReg = trim((string)($loadedInvoice['client_reg_com'] ?? ''));
                $previewClientContact = trim((string)($loadedInvoice['client_contact'] ?? ''));
                $previewClientEmail = trim((string)($loadedInvoice['client_email'] ?? ''));
                $previewClientPhone = trim((string)($loadedInvoice['client_phone'] ?? ''));
                $previewAddressParts = array_filter([
                    trim((string)($loadedInvoice['client_address'] ?? '')),
                    trim((string)($loadedInvoice['client_city'] ?? '')),
                    trim((string)($loadedInvoice['client_county'] ?? '')),
                    trim((string)($loadedInvoice['client_country'] ?? '')),
                ], static fn($v) => $v !== '');
                $previewCurrency = (string)($loadedInvoice['currency'] ?? 'RON');
                $previewNet = pz_smartbill_money($loadedInvoice['net_amount'] ?? 0);
                $previewVat = pz_smartbill_money($loadedInvoice['vat_amount'] ?? 0);
                $previewGross = pz_smartbill_money($loadedInvoice['gross_amount'] ?? 0);
            ?>
            <?php
                // C2 — verificare dacă factura e blocată în starea „sending".
                $isStuckSending = function_exists('pz_smartbill_is_stuck_sending')
                    ? pz_smartbill_is_stuck_sending($loadedInvoice, 5)
                    : false;
                $isCurrentlySending = strtolower(trim((string)($loadedInvoice['smartbill_status'] ?? ''))) === 'sending' && !$isStuckSending;
            ?>
            <?php if ($isStuckSending): ?>
                <section class="card" style="border-left:4px solid #d97706;background:#fff7ed">
                    <h3 style="margin:0 0 8px">⚠ Factură blocată în transmitere SmartBill</h3>
                    <p style="margin:0 0 10px">
                        Apelul către SmartBill a început la
                        <strong><?= inv_h((string)($loadedInvoice['smartbill_sent_at'] ?? '?')) ?></strong>
                        dar nu s-a confirmat. Există 2 posibilități:
                    </p>
                    <ol style="margin:6px 0 14px 18px;line-height:1.5">
                        <li>Factura <strong>a fost emisă</strong> în SmartBill, dar răspunsul nu a ajuns la CRM (timeout rețea).</li>
                        <li>Factura <strong>NU a fost emisă</strong> (cererea nu a ajuns la SmartBill sau a fost respinsă).</li>
                    </ol>
                    <p style="margin:0 0 12px">
                        <strong>Pas obligatoriu:</strong> intră în <a href="https://cloud.smartbill.ro" target="_blank" rel="noopener">SmartBill</a>
                        și caută factura după data
                        <strong><?= inv_h((string)($loadedInvoice['invoice_date'] ?? '?')) ?></strong>,
                        client <strong><?= inv_h((string)($loadedInvoice['client_name'] ?? '?')) ?></strong>,
                        total <strong><?= number_format(pz_smartbill_money($loadedInvoice['gross_amount'] ?? 0), 2, ',', '.') ?> <?= inv_h((string)($loadedInvoice['currency'] ?? 'RON')) ?></strong>.
                    </p>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start">
                        <details style="flex:1;min-width:280px">
                            <summary class="btn accent" style="cursor:pointer">Am găsit-o — marchează ca emisă</summary>
                            <form method="post" style="margin-top:10px;padding:12px;background:#fff;border:1px solid #e5e7eb;border-radius:6px">
                                <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                <input type="hidden" name="action" value="manual_reconcile">
                                <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                                <label style="display:block;margin-bottom:8px">
                                    Seria din SmartBill *
                                    <input type="text" name="manual_series" required placeholder="ex: EUR" style="width:100%;padding:6px;margin-top:2px">
                                </label>
                                <label style="display:block;margin-bottom:8px">
                                    Numărul din SmartBill *
                                    <input type="text" name="manual_number" required placeholder="ex: 00123" style="width:100%;padding:6px;margin-top:2px">
                                </label>
                                <label style="display:block;margin-bottom:8px">
                                    URL factură (opțional)
                                    <input type="url" name="manual_url" placeholder="https://cloud.smartbill.ro/..." style="width:100%;padding:6px;margin-top:2px">
                                </label>
                                <button class="btn accent" type="submit" onclick="return confirm('Confirmi că ai verificat în SmartBill că această factură există cu seria și numărul introdus?');">Salvează ca emisă</button>
                            </form>
                        </details>
                        <form method="post" style="margin:0">
                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                            <input type="hidden" name="action" value="reset_stuck">
                            <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                            <button class="btn ghost" type="submit" onclick="return confirm('Confirmi că NU ai găsit factura în SmartBill? Resetează la error pentru a o emite din nou.');">Nu am găsit-o — resetează pentru retry</button>
                        </form>
                    </div>
                </section>
            <?php elseif ($isCurrentlySending): ?>
                <section class="card" style="border-left:4px solid #2563eb;background:#eff6ff">
                    <h3 style="margin:0 0 6px">Emitere în curs către SmartBill...</h3>
                    <p class="muted" style="margin:0">
                        Apel început la <strong><?= inv_h((string)($loadedInvoice['smartbill_sent_at'] ?? '?')) ?></strong>.
                        Așteaptă 1-2 minute și reîncarcă pagina. Dacă starea rămâne „sending" mai mult de 5 minute, vei vedea opțiuni de reconciliere.
                    </p>
                </section>
            <?php endif; ?>
            <section class="card invoice-preview">
                <div class="invoice-preview-head">
                    <div>
                        <span class="preview-badge">Schiță factură</span>
                        <h2 style="margin-top:6px">Verifică datele înainte de emitere</h2>
                        <p class="muted" style="margin:2px 0 0">Salvată local în CRM. Nu a fost încă trimisă în SmartBill. Apasă „Editează" pentru modificări sau „Emite factura" pentru a o transmite.</p>
                    </div>
                    <div class="invoice-preview-actions">
                        <a class="btn ghost" href="invoice.php?id=<?= (int)$loadedInvoice['id'] ?>&amp;mode=edit">Editează</a>
                        <form method="post" style="margin:0" onsubmit="return confirm('Emiți factura în SmartBill? Aceasta va primi serie și număr și nu va mai putea fi editată.');">
                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                            <input type="hidden" name="action" value="issue">
                            <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                            <button class="btn accent" type="submit"<?= ($isCurrentlySending || $isStuckSending) ? ' disabled style="opacity:.5;cursor:not-allowed"' : '' ?>>Emite factura</button>
                        </form>
                    </div>
                </div>

                <div class="invoice-preview-body">
                    <div class="preview-grid">
                        <div class="preview-box">
                            <span>Client</span>
                            <strong><?= inv_h($previewClientName ?: '-') ?></strong>
                            <?php if ($previewClientFiscal !== ''): ?><div class="muted">CIF/CNP: <?= inv_h($previewClientFiscal) ?></div><?php endif; ?>
                            <?php if ($previewClientReg !== ''): ?><div class="muted">Reg. Com.: <?= inv_h($previewClientReg) ?></div><?php endif; ?>
                            <?php if ($previewClientContact !== ''): ?><div class="muted">Contact: <?= inv_h($previewClientContact) ?></div><?php endif; ?>
                            <?php if ($previewClientEmail !== '' || $previewClientPhone !== ''): ?>
                                <div class="muted"><?= inv_h(trim($previewClientEmail . ($previewClientEmail && $previewClientPhone ? ' · ' : '') . $previewClientPhone)) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="preview-box">
                            <span>Adresă facturare</span>
                            <strong><?= inv_h(implode(', ', $previewAddressParts) ?: '-') ?></strong>
                        </div>
                        <div class="preview-box">
                            <span>Date factură</span>
                            <strong>Emitere: <?= inv_h($loadedInvoice['invoice_date'] ?? '-') ?></strong>
                            <div class="muted">Scadență: <?= inv_h($loadedInvoice['due_date'] ?? '-') ?></div>
                            <div class="muted">Moneda: <?= inv_h($previewCurrency) ?> · Limba: <?= inv_h($loadedInvoice['invoice_language'] ?? 'RO') ?></div>
                        </div>
                    </div>

                    <table class="preview-items">
                        <thead>
                            <tr>
                                <th>Descriere</th>
                                <th style="text-align:right">Cant.</th>
                                <th>UM</th>
                                <th style="text-align:right">Preț</th>
                                <th style="text-align:right">TVA</th>
                                <th style="text-align:right">Total fără TVA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$invoiceItems): ?>
                                <tr><td colspan="6" class="muted" style="text-align:center;padding:14px">Nu există poziții. Apasă „Editează" pentru a adăuga.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($invoiceItems as $item): ?>
                                <?php
                                    $qty = (float)($item['quantity'] ?? 1);
                                    $price = pz_smartbill_money($item['unit_price'] ?? 0);
                                    $lineTotal = pz_smartbill_money($item['line_total'] ?? ($qty * $price));
                                    $vatLabel = $vatOptions[$item['vat_code'] ?? $defaultVatCode] ?? ($item['vat_code'] ?? '');
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= inv_h($item['description'] ?? '-') ?></strong>
                                        <?php if (!empty($item['product_description'])): ?>
                                            <div class="muted"><?= inv_h($item['product_description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right"><?= inv_h(rtrim(rtrim(number_format($qty, 3, ',', '.'), '0'), ',')) ?></td>
                                    <td><?= inv_h($item['unit_name'] ?? 'buc') ?></td>
                                    <td style="text-align:right"><?= inv_h(number_format($price, 2, ',', '.')) ?> <?= inv_h($previewCurrency) ?></td>
                                    <td style="text-align:right"><?= inv_h($vatLabel) ?></td>
                                    <td style="text-align:right"><?= inv_h(number_format($lineTotal, 2, ',', '.')) ?> <?= inv_h($previewCurrency) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="preview-totals">
                        <div><span>Total fără TVA</span><strong><?= inv_h(number_format($previewNet, 2, ',', '.')) ?> <?= inv_h($previewCurrency) ?></strong></div>
                        <div><span>TVA</span><strong><?= inv_h(number_format($previewVat, 2, ',', '.')) ?> <?= inv_h($previewCurrency) ?></strong></div>
                        <div class="preview-grand"><span>Total cu TVA</span><strong><?= inv_h(number_format($previewGross, 2, ',', '.')) ?> <?= inv_h($previewCurrency) ?></strong></div>
                    </div>

                    <?php if (!empty($loadedInvoice['mentions'])): ?>
                        <div class="preview-mentions">
                            <span>Mențiuni pe factură</span>
                            <p><?= nl2br(inv_h($loadedInvoice['mentions'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if (!$showInvoicePreview && !$invoiceIsIssued): ?>
            <section class="card invoice-editor">
                <form method="post" id="invoiceForm">
                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="invoice_id" value="<?= (int)$invoiceIdFromRequest ?>">
                    <input type="hidden" name="appointment_id" value="<?= (int)$appointmentId ?>">
                    <div class="invoice-editor-head">
                        <h2>Emitere factură</h2>
                        <div class="invoice-options" id="invoiceOptions">
                            <button class="invoice-options-toggle" type="button" onclick="toggleInvoiceOptions(event)">Optiuni factura</button>
                            <div class="invoice-options-menu">
                                <button type="button" onclick="submitStorno()">Adăugare storno</button>
                                <button type="button" onclick="submitFromEstimate()">Creează pe bază de proformă</button>
                                <button type="button" onclick="recalculateInvoiceTotals()">Recalculează totaluri</button>
                            </div>
                        </div>
                    </div>
                    <div class="invoice-editor-body">
                    <div class="form-grid">
                        <div style="display:none">
                            <select name="client_id" id="client_id" onchange="applyClientData()">
                                <option value="">-</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= (int)$client['id'] ?>" <?= (int)$prefill['client_id'] === (int)$client['id'] ? 'selected' : '' ?>><?= inv_h($client['name'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="grid-column:span 2;">
                            <label>Nume client *</label>
                            <div class="pz-search-wrap">
                                <input name="client_name" id="client_name" value="<?= inv_h($prefill['client_name']) ?>" required autocomplete="off" placeholder="Caută client (nume sau CIF)..." autofocus>
                                <div class="pz-search-preview"></div>
                            </div>
                        </div>
                        <div><label>Data emiterii</label><input type="date" name="issue_date" value="<?= inv_h($prefill['issue_date']) ?>"></div>
                        <div><label>Moneda factura</label><input name="currency" value="<?= inv_h($prefill['currency']) ?>"></div>
                        <div>
                            <label>CIF/CNP *</label>
                            <input name="client_fiscal_code" id="client_fiscal_code" value="<?= inv_h($prefill['client_fiscal_code']) ?>" required autocomplete="off" placeholder="Auto-completat din client">
                        </div>
                        <div><label>Reg. Com. / Serie CI</label><input name="client_reg_com" id="client_reg_com" value="<?= inv_h($prefill['client_reg_com']) ?>"></div>
                        <div><label>Persoană contact</label><input name="client_contact" id="client_contact" value="<?= inv_h($prefill['client_contact']) ?>"></div>
                        <div><label>Email</label><input type="email" name="client_email" id="client_email" value="<?= inv_h($prefill['client_email']) ?>"></div>
                        <div><label>Telefon</label><input name="client_phone" id="client_phone" value="<?= inv_h($prefill['client_phone']) ?>"></div>
                        <div><label>Banca</label><input name="client_bank" id="client_bank" value="<?= inv_h($prefill['client_bank']) ?>"></div>
                        <div><label>IBAN</label><input name="client_iban" id="client_iban" value="<?= inv_h($prefill['client_iban']) ?>"></div>
                        <div><label>Țară *</label><input name="client_country" id="client_country" value="<?= inv_h($prefill['client_country']) ?>" required></div>
                        <div class="full" style="display:grid;grid-template-columns:minmax(140px,1fr) minmax(140px,1fr) minmax(220px,2.4fr);gap:8px 12px;">
                            <div><label>Județ *</label><input name="client_county" id="client_county" value="<?= inv_h($prefill['client_county']) ?>" required></div>
                            <div><label>Oraș / localitate *</label><input name="client_city" id="client_city" value="<?= inv_h($prefill['client_city']) ?>" required></div>
                            <div><label>Adresa *</label><input name="client_address" id="client_address" value="<?= inv_h($prefill['client_address']) ?>" required></div>
                        </div>
                        <div><label>Termen plată / scadență</label><input type="date" name="due_date" value="<?= inv_h($prefill['due_date']) ?>"></div>
                        <div><label>Limba</label><select name="invoice_language"><option value="RO" <?= $prefill['invoice_language'] === 'RO' ? 'selected' : '' ?>>RO</option><option value="EN" <?= $prefill['invoice_language'] === 'EN' ? 'selected' : '' ?>>EN</option></select></div>
                        <div><label>Categorie venit</label>
                            <select name="revenue_category">
                                <?php foreach (pz_revenue_categories() as $code => $info): ?>
                                    <option value="<?= inv_h($code) ?>" <?= $prefill['revenue_category'] === $code ? 'selected' : '' ?>><?= inv_h($info['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="full compact-product">
                            <div class="items-head">
                                <h2 style="font-size:18px">Poziții factură</h2>
                                <button class="btn ghost" type="button" onclick="addInvoiceItem()">+ Adaugă poziție</button>
                            </div>
                            <div class="invoice-line-head">
                                <span>Denumire produs/serviciu</span>
                                <span>Cod</span>
                                <span>Cant.</span>
                                <span>U.M.</span>
                                <span>Preț</span>
                                <span>TVA</span>
                                <span></span>
                            </div>
                            <div class="invoice-items" id="invoiceItems">
                                <?php foreach ($invoiceItems as $idx => $item): ?>
                                    <div class="invoice-item" data-item-row>
                                        <div class="invoice-item-grid">
                                            <div><label>Denumire *</label><input name="item_description[<?= (int)$idx ?>]" value="<?= inv_h($item['description'] ?? '') ?>" required></div>
                                            <div><label>Cod</label><input name="item_product_code[<?= (int)$idx ?>]" value="<?= inv_h($item['product_code'] ?? '') ?>"></div>
                                            <div><label>Cantitate</label><input type="number" step="0.001" min="0.001" name="item_quantity[<?= (int)$idx ?>]" value="<?= inv_h($item['quantity'] ?? '1') ?>"></div>
                                            <div><label>UM</label><select name="item_unit_name[<?= (int)$idx ?>]"><?php $selectedUnit = (string)($item['unit_name'] ?? 'buc'); ?><?php foreach ($invoiceUnitOptions as $unitOption): ?><option value="<?= inv_h($unitOption) ?>" <?= $selectedUnit === $unitOption ? 'selected' : '' ?>><?= inv_h($unitOption) ?></option><?php endforeach; ?></select></div>
                                            <div><label>Preț</label><input type="number" step="0.01" min="0" name="item_unit_price[<?= (int)$idx ?>]" value="<?= inv_h($item['unit_price'] ?? '0.00') ?>" required></div>
                                            <div><label>TVA</label><select name="item_vat_code[<?= (int)$idx ?>]"><?php foreach ($allowedVatCodes as $code): ?><?php if (isset($vatOptions[$code])): ?><option value="<?= inv_h($code) ?>" <?= ($item['vat_code'] ?? $defaultVatCode) === $code ? 'selected' : '' ?>><?= inv_h($vatOptions[$code]) ?></option><?php endif; ?><?php endforeach; ?></select></div>
                                            <button class="btn ghost item-remove" type="button" onclick="removeInvoiceItem(this)">X</button>
                                        </div>
                                        <div class="invoice-item-extra">
                                            <div><label>Descriere</label><input name="item_product_description[<?= (int)$idx ?>]" value="<?= inv_h($item['product_description'] ?? '') ?>"></div>
                                            <div><label>Tip</label><select name="item_is_service[<?= (int)$idx ?>]"><option value="1" <?= ($item['is_service'] ?? '1') === '1' ? 'selected' : '' ?>>Serviciu</option><option value="0" <?= ($item['is_service'] ?? '1') === '0' ? 'selected' : '' ?>>Produs</option></select></div>
                                            <label class="tax-included-label"><input type="checkbox" name="item_is_tax_included[<?= (int)$idx ?>]" value="1" <?= ($item['is_tax_included'] ?? '0') === '1' ? 'checked' : '' ?>> Preț cu TVA inclus</label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="full"><label>Mențiuni pe factură</label><textarea name="mentions"><?= inv_h($prefill['mentions']) ?></textarea></div>
                        <input type="hidden" name="observations" value="<?= inv_h($prefill['observations']) ?>">
                        <input type="hidden" name="notes" value="<?= inv_h($prefill['notes']) ?>">
                        <div class="full">
                            <?php if ($loadedInvoice && trim((string)($loadedInvoice['smartbill_number'] ?? '')) !== ''): ?>
                                <span class="issued-lock">Factură emisă - modificarea draftului este blocată</span>
                            <?php else: ?>
                                <div class="invoice-save-actions">
                                    <a class="btn ghost" href="<?= $invoiceIsDraft ? 'invoice.php?id=' . (int)$loadedInvoice['id'] : 'invoices.php' ?>">Renunță</a>
                                    <label class="auto-receipt-label"><input type="checkbox" name="auto_receipt" value="1"> Încasează acum (chitanță)</label>
                                    <button class="btn accent" type="submit"><?= $invoiceIsDraft ? 'Salvează modificările' : 'Salvează' ?></button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>
                </form>
            </section>
            <?php endif; // !$showInvoicePreview && !$invoiceIsIssued - form ascuns dupa emiterea facturii ?>

            <?php if ($loadedInvoice && !$showInvoicePreview): ?>
            <section class="card action-panel">
                <h2>Acțiuni factură</h2>
                <?php if (trim((string)($loadedInvoice['smartbill_number'] ?? '')) !== ''): ?>
                    <div class="issued-card">
                        <strong>Factura emisă în SmartBill</strong>
                        <span><?= inv_h(inv_invoice_reference($loadedInvoice)) ?> · <?= inv_h($loadedInvoice['invoice_date'] ?? '') ?></span>
                    </div>
                <?php endif; ?>
                <div class="payment-summary">
                    <div class="payment-box"><span>Status</span><strong><?= inv_h(inv_status_label((string)($loadedInvoice['smartbill_status'] ?? 'draft'))) ?></strong></div>
                    <div class="payment-box"><span>Total</span><strong><?= inv_h(inv_money_label($loadedGross, (string)($loadedInvoice['currency'] ?? 'RON'))) ?></strong></div>
                    <div class="payment-box"><span>Încasare</span><strong><?= inv_h(pz_smartbill_payment_status_label($loadedPaymentStatus)) ?></strong></div>
                </div>
                <div class="action-buttons">
                    <?php if (trim((string)($loadedInvoice['smartbill_number'] ?? '')) === ''): ?>
                        <form method="post" class="issue-with-receipt" style="margin:0">
                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                            <input type="hidden" name="action" value="issue">
                            <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                            <label class="auto-receipt-label"><input type="checkbox" name="auto_receipt" value="1"> Încasează acum (chitanță)</label>
                            <button class="btn accent" type="submit">Emite în SmartBill</button>
                        </form>
                    <?php else: ?>
                        <a class="btn ghost" href="invoice_pdf.php?id=<?= (int)$loadedInvoice['id'] ?>" target="_blank" rel="noopener">Vezi PDF</a>
                        <a class="btn ghost" href="invoice_pdf.php?id=<?= (int)$loadedInvoice['id'] ?>&download=1">Descarcă PDF</a>
                        <a class="btn ghost" href="efactura.php">E-Factura</a>
                        <?php if ($loadedRemaining > 0): ?>
                            <a class="btn accent" href="<?= inv_h(inv_payment_link($loadedInvoice)) ?>">Încasează</a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <a class="btn ghost" href="<?= inv_h(inv_payments_report_link($loadedInvoice)) ?>">Vezi încasări</a>
                    <a class="btn ghost" href="invoice.php">+ Factură</a>
                    <button class="btn ghost" type="button" onclick="openRecurringModal()">Recurentă</button>
                </div>

                <?php
                    $currentRevenueCategory = pz_revenue_category_normalize(
                        (string)($loadedInvoice['revenue_category'] ?? 'ddd'),
                        'ddd'
                    );
                    $revenueCategoriesUI = pz_revenue_categories();
                ?>
                <div class="revenue-category-block" style="margin-top:14px;padding:12px 14px;border:0.5px solid var(--pz-line);border-radius:10px;background:var(--pz-bg);">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                        <div>
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:var(--pz-muted);font-weight:600;">Categorie venit</div>
                            <div style="font-size:12px;color:var(--pz-muted);margin-top:2px;">Determină pe ce linie de business apare factura în rapoarte. Schimbabil oricând.</div>
                        </div>
                        <?= pz_revenue_render_badge($currentRevenueCategory, ['size' => 'md']) ?>
                    </div>
                    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                        <input type="hidden" name="action" value="update_revenue_category">
                        <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                        <select name="revenue_category" style="flex:1;min-width:140px;max-width:240px;">
                            <?php foreach ($revenueCategoriesUI as $code => $info): ?>
                                <option value="<?= inv_h($code) ?>" <?= $currentRevenueCategory === $code ? 'selected' : '' ?>>
                                    <?= inv_h($info['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn ghost">Schimbă categoria</button>
                    </form>
                </div>
            </section>

            <?php endif; ?>

            <?php if ($loadedInvoice && !$showInvoicePreview): ?>
            <!-- Modal Facturare recurentă (ascuns; deschis prin butonul „Recurentă") -->
            <div class="modal-overlay" id="recurringModal" role="dialog" aria-modal="true" aria-labelledby="recurringModalTitle" hidden>
                <div class="modal-card" role="document">
                    <div class="modal-head">
                        <div>
                            <h2 id="recurringModalTitle">Facturare recurentă</h2>
                            <p class="muted" style="margin:2px 0 0">Folosește factura curentă ca model și generează periodic facturi noi cu aceleași poziții și date fiscale.</p>
                        </div>
                        <button type="button" class="modal-close" onclick="closeRecurringModal()" aria-label="Închide">×</button>
                    </div>
                    <form method="post" class="form-grid modal-body">
                        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                        <input type="hidden" name="action" value="create_recurring">
                        <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                        <div><label>Denumire recurenta</label><input name="title" value="Abonament <?= inv_h($loadedInvoice['client_name'] ?? '') ?>"></div>
                        <div><label>Frecvență</label><select name="frequency"><option value="monthly">Lunar</option><option value="quarterly">Trimestrial</option><option value="yearly">Anual</option><option value="weekly">Săptămânal</option></select></div>
                        <div><label>Interval</label><input type="number" min="1" max="24" name="interval_value" value="1"></div>
                        <div><label>Ziua lunii</label><input type="number" min="1" max="31" name="day_of_month" value="<?= (int)date('d') ?>"></div>
                        <div><label>Prima emitere</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>"></div>
                        <div><label>Data final optionala</label><input type="date" name="end_date"></div>
                        <div class="full"><label style="text-transform:none;letter-spacing:0;color:var(--text)"><input type="checkbox" name="auto_issue" value="1" style="width:auto;min-height:0"> Emite automat în SmartBill când se generează</label></div>
                        <div class="full"><label style="text-transform:none;letter-spacing:0;color:var(--text)"><input type="checkbox" name="auto_email" value="1" style="width:auto;min-height:0"> Trimite automat email după emitere</label></div>
                        <div class="full"><label>Note recurenta</label><textarea name="notes"></textarea></div>
                        <div class="full modal-foot">
                            <a class="btn ghost" href="recurring_invoices.php">Vezi toate recurentele</a>
                            <div style="display:flex;gap:8px">
                                <button type="button" class="btn ghost" onclick="closeRecurringModal()">Renunță</button>
                                <button class="btn accent" type="submit">Creează recurență</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($loadedInvoice && trim((string)($loadedInvoice['smartbill_number'] ?? '')) !== ''): ?>
            <section class="card">
                <h2>Document SmartBill</h2>
                <p class="muted">Factura <?= inv_h(trim((string)(($loadedInvoice['smartbill_series'] ?? '') . ' ' . ($loadedInvoice['smartbill_number'] ?? '')))) ?> este emisă în SmartBill.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                    <a class="btn ghost" href="invoice_pdf.php?id=<?= (int)$loadedInvoice['id'] ?>" target="_blank" rel="noopener">Vezi PDF</a>
                    <a class="btn ghost" href="invoice_pdf.php?id=<?= (int)$loadedInvoice['id'] ?>&download=1">Descarcă PDF</a>
                    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin:0;align-items:center">
                        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                        <input type="hidden" name="action" value="send_invoice_email">
                        <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                        <input type="email" name="email_to" value="<?= inv_h($loadedInvoice['client_email'] ?? '') ?>" placeholder="email client" style="width:240px">
                        <button class="btn accent" type="submit">Trimite email</button>
                    </form>
                </div>
                <?php if (!empty($loadedInvoice['email_sent_at'])): ?>
                    <p class="muted">Ultima trimitere: <?= inv_h($loadedInvoice['email_sent_at']) ?> catre <?= inv_h($loadedInvoice['email_sent_to'] ?? '') ?></p>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Încasare factură</h2>
                <div class="payment-summary">
                    <div class="payment-box"><span>Total factura</span><strong><?= inv_h(inv_money_label($loadedGross, (string)($loadedInvoice['currency'] ?? 'RON'))) ?></strong></div>
                    <div class="payment-box"><span>Încasat</span><strong><?= inv_h(inv_money_label($loadedPaid, (string)($loadedInvoice['currency'] ?? 'RON'))) ?></strong></div>
                    <div class="payment-box"><span>Sold ramas</span><strong><?= inv_h(inv_money_label($loadedRemaining, (string)($loadedInvoice['currency'] ?? 'RON'))) ?></strong></div>
                </div>
                <p class="muted">Status: <?= inv_h(pz_smartbill_payment_status_label($loadedPaymentStatus)) ?>. Încasezi direct în SmartBill prin chitanță, card sau transfer bancar.</p>
                <form method="post" style="margin:0 0 12px">
                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                    <input type="hidden" name="action" value="check_payment_status">
                    <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                    <button class="btn ghost" type="submit">Verifica status SmartBill</button>
                    <?php if (!empty($loadedInvoice['last_status_check_at'])): ?>
                        <span class="muted">Ultima verificare: <?= inv_h($loadedInvoice['last_status_check_at']) ?></span>
                    <?php endif; ?>
                </form>

                <?php if ($loadedRemaining > 0): ?>
                <details class="payment-toggle">
                    <summary>Înregistrează încasare manuală</summary>
                    <form method="post" class="payment-card" style="margin-top:12px;">
                        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                        <input type="hidden" name="action" value="add_payment">
                        <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                        <div class="payment-methods">
                            <?php foreach ($primaryPaymentTypes as $type => $label): ?>
                                <label><input type="radio" name="payment_type" value="<?= inv_h($type) ?>" <?= $type === 'chitanta' ? 'checked' : '' ?>> <?= inv_h($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="payment-compact">
                            <div><label>Suma incasata</label><input type="number" step="0.01" min="0.01" max="<?= inv_h(number_format($loadedRemaining, 2, '.', '')) ?>" name="amount" value="<?= inv_h(number_format($loadedRemaining, 2, '.', '')) ?>"></div>
                            <div><label>Data</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"></div>
                            <div><label>Moneda</label><input name="currency" value="<?= inv_h($loadedInvoice['currency'] ?? 'RON') ?>"></div>
                        </div>
                        <div class="payment-extra">
                            <div><label>Serie document</label><input name="document_series" value="<?= inv_h($settings['smartbill.receipt_series'] ?? '') ?>" placeholder="chitanta"></div>
                            <div><label>Numar / referinta</label><input name="document_number" placeholder="OP / tranzactie card"></div>
                            <div><label>Banca</label><input name="bank_name" placeholder="optional"></div>
                            <div><label>Cont bancar</label><input name="bank_account" placeholder="optional"></div>
                        </div>
                        <div><label>Observatii incasare</label><textarea name="notes"></textarea></div>
                        <div class="payment-submit"><button class="btn accent" type="submit">Emite incasarea in SmartBill</button></div>
                    </form>
                </details>
                <?php endif; ?>

                <table class="payment-table">
                    <thead><tr><th>Data</th><th>Tip</th><th>Suma</th><th>Document</th><th>Status</th><th>Acțiuni</th></tr></thead>
                    <tbody>
                    <?php if (empty($loadedInvoice['payments'])): ?><tr><td colspan="6" class="muted">Nu există încasări în CRM.</td></tr><?php endif; ?>
                    <?php foreach (($loadedInvoice['payments'] ?? []) as $payment): ?>
                        <?php $paymentDoc = trim((string)(($payment['document_series'] ?? '') . ' ' . ($payment['document_number'] ?? ''))); ?>
                        <tr>
                            <td><?= inv_h($payment['payment_date'] ?? '') ?></td>
                            <td><?= inv_h(pz_smartbill_payment_label((string)($payment['payment_type'] ?? 'alta'))) ?></td>
                            <td><?= inv_h(inv_money_label($payment['amount'] ?? 0, (string)($payment['currency'] ?? 'RON'))) ?></td>
                            <td><?= inv_h($paymentDoc ?: '-') ?></td>
                            <td>
                                <span class="pill <?= ($payment['smartbill_status'] ?? '') === 'issued' ? 'ok' : '' ?>"><?= inv_h($payment['smartbill_status'] ?? 'manual') ?></span>
                                <?php if (!empty($payment['error_message'])): ?><div class="muted"><?= inv_h($payment['error_message']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?php if (($payment['payment_type'] ?? '') === 'chitanta' && ($payment['smartbill_status'] ?? '') === 'issued' && $paymentDoc !== ''): ?>
                                    <form method="post" style="margin:0" onsubmit="return confirm('Ștergi chitanța din SmartBill? SmartBill permite de regulă ștergerea doar pentru ultima chitanță din serie.');">
                                        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                        <input type="hidden" name="action" value="delete_receipt">
                                        <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                                        <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                                        <button class="btn ghost" style="min-height:32px;padding:6px 9px;font-size:12px;color:var(--danger)" type="submit">Șterge</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>

        </div>
    </main>
</div>
<script>
const clientsData = <?= json_encode($clientsForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const invoiceVatOptions = <?= json_encode(array_values(array_map(static function ($code) use ($vatOptions) {
    return ['code' => (string)$code, 'label' => (string)($vatOptions[$code] ?? $code)];
}, array_values(array_filter($allowedVatCodes, static function ($code) use ($vatOptions) {
    return isset($vatOptions[$code]);
})))), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const invoiceUnitOptions = <?= json_encode($invoiceUnitOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
let invoiceItemIndex = <?= count($invoiceItems) ?>;
function setInvField(id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value || '';
}
function applyClientData() {
    const id = document.getElementById('client_id')?.value || '';
    const c = clientsData[id];
    if (!c) return;
    setInvField('client_name', c.name);
    setInvField('client_fiscal_code', c.fiscal_code);
    setInvField('client_reg_com', c.reg_com);
    setInvField('client_contact', c.contact);
    setInvField('client_email', c.email);
    setInvField('client_phone', c.phone);
    setInvField('client_bank', c.bank);
    setInvField('client_iban', c.iban);
    setInvField('client_country', c.country || 'Romania');
    setInvField('client_county', c.county);
    setInvField('client_city', c.city);
    setInvField('client_address', c.address);
}
function syncClientByName(name) {
    const entry = Object.entries(clientsData).find(([, c]) => c.name === name);
    if (!entry) return;
    const [id, c] = entry;
    document.getElementById('client_id').value = id;
    setInvField('client_fiscal_code', c.fiscal_code);
    setInvField('client_reg_com', c.reg_com);
    setInvField('client_contact', c.contact);
    setInvField('client_email', c.email);
    setInvField('client_phone', c.phone);
    setInvField('client_bank', c.bank);
    setInvField('client_iban', c.iban);
    setInvField('client_country', c.country || 'Romania');
    setInvField('client_county', c.county);
    setInvField('client_city', c.city);
    setInvField('client_address', c.address);
}
function syncClientByCif(cif) {
    const entry = Object.entries(clientsData).find(([, c]) => c.fiscal_code === cif);
    if (!entry) return;
    const [id, c] = entry;
    document.getElementById('client_id').value = id;
    setInvField('client_name', c.name);
    setInvField('client_reg_com', c.reg_com);
    setInvField('client_contact', c.contact);
    setInvField('client_email', c.email);
    setInvField('client_phone', c.phone);
    setInvField('client_bank', c.bank);
    setInvField('client_iban', c.iban);
    setInvField('client_country', c.country || 'Romania');
    setInvField('client_county', c.county);
    setInvField('client_city', c.city);
    setInvField('client_address', c.address);
}
/* ============================================================
 * Autocomplete client pentru CIF + Nume — foloseste modulul
 * partajat pzSearchPreview (definit in app_ui.php). La click pe
 * un rezultat, completam toate campurile clientului in formular.
 * ============================================================ */
function invoiceFillClientFromPreview(clientId) {
    const c = clientsData[clientId];
    if (!c) return;
    const idSel = document.getElementById('client_id');
    if (idSel) idSel.value = clientId;
    setInvField('client_name', c.name);
    setInvField('client_fiscal_code', c.fiscal_code);
    setInvField('client_reg_com', c.reg_com);
    setInvField('client_contact', c.contact);
    setInvField('client_email', c.email);
    setInvField('client_phone', c.phone);
    setInvField('client_bank', c.bank);
    setInvField('client_iban', c.iban);
    setInvField('client_country', c.country || 'Romania');
    setInvField('client_county', c.county);
    setInvField('client_city', c.city);
    setInvField('client_address', c.address);
}
(function () {
    function go() {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        const items = Object.entries(clientsData).map(function (entry) {
            const id = entry[0], c = entry[1];
            return {
                title: c.name || '(fără nume)',
                type: 'client',
                search: (c.name || '') + ' ' + (c.fiscal_code || ''),
                _clientId: id
            };
        });

        /* Cautarea se face doar din Nume client; la selectie, completam tot formularul. */
        window.pzSearchPreview.attach('client_name', items, {
            minChars: 1,
            maxResults: 8,
            emptyText: 'Niciun client găsit',
            onSelect: function (item) {
                if (item && item._clientId) {
                    invoiceFillClientFromPreview(item._clientId);
                }
            }
        });
    }
    go();
})();

function invoiceVatOptionsHtml(selected = '<?= inv_h($defaultVatCode) ?>') {
    return invoiceVatOptions.map(opt => `<option value="${String(opt.code).replace(/"/g, '&quot;')}" ${opt.code === selected ? 'selected' : ''}>${String(opt.label).replace(/</g, '&lt;')}</option>`).join('');
}
function invoiceUnitOptionsHtml(selected = 'buc') {
    return invoiceUnitOptions.map(unit => `<option value="${String(unit).replace(/"/g, '&quot;')}" ${unit === selected ? 'selected' : ''}>${String(unit).replace(/</g, '&lt;')}</option>`).join('');
}
function addInvoiceItem() {
    const box = document.getElementById('invoiceItems');
    if (!box) return;
    const idx = invoiceItemIndex++;
    const row = document.createElement('div');
    row.className = 'invoice-item';
    row.setAttribute('data-item-row', '1');
    row.innerHTML = `
        <div class="invoice-item-grid">
            <div><label>Denumire *</label><input name="item_description[${idx}]" required></div>
            <div><label>Cod</label><input name="item_product_code[${idx}]"></div>
            <div><label>Cantitate</label><input type="number" step="0.001" min="0.001" name="item_quantity[${idx}]" value="1"></div>
            <div><label>UM</label><select name="item_unit_name[${idx}]">${invoiceUnitOptionsHtml('buc')}</select></div>
            <div><label>Preț</label><input type="number" step="0.01" min="0" name="item_unit_price[${idx}]" value="0.00" required></div>
            <div><label>TVA</label><select name="item_vat_code[${idx}]">${invoiceVatOptionsHtml()}</select></div>
            <button class="btn ghost item-remove" type="button" onclick="removeInvoiceItem(this)">X</button>
        </div>
        <div class="invoice-item-extra">
            <div><label>Descriere</label><input name="item_product_description[${idx}]"></div>
            <div><label>Tip</label><select name="item_is_service[${idx}]"><option value="1" selected>Serviciu</option><option value="0">Produs</option></select></div>
            <label class="tax-included-label"><input type="checkbox" name="item_is_tax_included[${idx}]" value="1"> Preț cu TVA inclus</label>
        </div>
    `;
    box.appendChild(row);
}
function removeInvoiceItem(button) {
    const box = document.getElementById('invoiceItems');
    const rows = box ? box.querySelectorAll('[data-item-row]') : [];
    if (rows.length <= 1) return;
    button.closest('[data-item-row]')?.remove();
}
function toggleInvoiceOptions(event) {
    event.stopPropagation();
    document.getElementById('invoiceOptions')?.classList.toggle('open');
}
function closeInvoiceOptions() {
    document.getElementById('invoiceOptions')?.classList.remove('open');
}
function invoiceFormEl() {
    return document.getElementById('invoiceForm');
}
function invoiceIdValue() {
    return Number(invoiceFormEl()?.querySelector('input[name="invoice_id"]')?.value || 0);
}
function ensureHiddenField(form, name, value) {
    let input = form.querySelector(`input[name="${name}"]`);
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        form.appendChild(input);
    }
    input.value = value;
}
function setInvoiceAction(action) {
    const form = invoiceFormEl();
    if (!form) return null;
    const actionInput = form.querySelector('input[name="action"]');
    if (actionInput) actionInput.value = action;
    return form;
}
function submitStorno() {
    closeInvoiceOptions();
    const id = invoiceIdValue();
    if (!id) {
        alert('Salveaza mai intai factura, apoi poti adauga storno.');
        return;
    }
    if (!confirm('STORNO factura curenta?\n\nSe va emite in SmartBill o factura de stornare cu valori negative care anuleaza contabil factura. Operatiunea NU poate fi anulata.\n\nContinui?')) {
        return;
    }
    const form = setInvoiceAction('reverse_invoice');
    if (form) form.submit();
}

// Inchide meniul de optiuni cand se da click in afara lui.
document.addEventListener('click', function (e) {
    const menu = document.getElementById('invoiceOptions');
    if (!menu || !menu.classList.contains('open')) return;
    if (menu.contains(e.target)) return;
    const trigger = e.target.closest('[onclick*="toggleInvoiceOptions"]');
    if (trigger) return;
    menu.classList.remove('open');
});
</script>
</body>
</html>
