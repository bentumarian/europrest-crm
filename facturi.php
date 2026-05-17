<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/smartbill_lib.php';

$isAdmin = function_exists('is_admin') ? is_admin() : true;
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

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
        $errors[] = 'Adaugă cel puțin o pozitie pe factura.';
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
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_require')) {
        csrf_require();
    }

    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'issue') {
        $invoiceId = max(0, (int)($_POST['invoice_id'] ?? 0));
        if ($invoiceId <= 0) {
            $error = 'Factura nu a fost găsită.';
        } else {
            $result = pz_smartbill_issue_invoice($pdo, $invoiceId);
            if (!empty($result['ok'])) {
                header('Location: facturi.php?issued=1&id=' . $invoiceId);
                exit;
            }
            header('Location: facturi.php?issue_error=' . urlencode((string)($result['error'] ?? 'Factura nu a putut fi emisă.')) . '&id=' . $invoiceId);
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
                header('Location: facturi.php?payment_issued=1&id=' . $invoiceId);
                exit;
            }
            header('Location: facturi.php?payment_error=' . urlencode((string)($result['error'] ?? 'Încasarea nu a putut fi emisă.')) . '&id=' . $invoiceId);
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
                header('Location: facturi.php?receipt_deleted=1&id=' . $invoiceId);
                exit;
            }
            header('Location: facturi.php?payment_error=' . urlencode((string)($result['error'] ?? 'Chitanța nu a putut fi ștearsă.')) . '&id=' . $invoiceId);
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
                header('Location: facturi.php?payment_checked=1&id=' . $invoiceId);
                exit;
            }
            header('Location: facturi.php?payment_error=' . urlencode((string)($result['error'] ?? 'Statusul încasării nu a putut fi verificat.')) . '&id=' . $invoiceId);
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
                header('Location: facturi.php?email_sent=1&id=' . $invoiceId);
                exit;
            }
            header('Location: facturi.php?email_error=' . urlencode((string)($result['error'] ?? 'Factura nu a putut fi trimisă pe email.')) . '&id=' . $invoiceId);
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
                header('Location: facturi_recurente.php?created=1');
                exit;
            }
            header('Location: facturi.php?recurring_error=' . urlencode((string)($result['error'] ?? 'Recurența nu a putut fi creată.')) . '&id=' . $invoiceId);
            exit;
        }
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

    if ($clientName === '' || $clientFiscalCode === '' || $clientCountry === '' || $clientCounty === '' || $clientCity === '' || $clientAddress === '') {
        $error = 'Completează datele clientului pentru factura.';
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
                    gross_amount = ?, invoice_language = ?, mentions = ?, observations = ?, notes = ?, smartbill_status = 'draft', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$appointmentId > 0 ? 'appointment' : 'manual', $clientId ?: null, $clientName, $clientFiscalCode, $clientRegCom, $clientContact, $clientEmail, $clientPhone, $clientBank, $clientIban, $clientCountry, $clientCounty, $clientCity, $clientAddress, $issueDate, $dueDate, $currency, $net, $vatCode, $vat, $gross, $invoiceLanguage, $mentions, $observations, $notes, $existingId]);
            $invoiceId = $existingId;
            $pdo->prepare("DELETE FROM smartbill_invoice_items WHERE smartbill_invoice_id = ?")->execute([$invoiceId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO smartbill_invoices
                    (source_type, appointment_id, client_id, client_location_id, client_name, client_fiscal_code, client_reg_com,
                     client_contact, client_email, client_phone, client_bank, client_iban, client_country, client_county,
                     client_city, client_address, invoice_date, due_date, currency, net_amount, vat_code, vat_amount,
                     gross_amount, invoice_language, mentions, observations, smartbill_status, notes, created_by)
                VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
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
            VALUES (?, ?, 'draft_saved', 'draft', 'Factura pregătita in CRM.', ?)
        ")->execute([$invoiceId, $appointmentId ?: null, function_exists('current_user_id') ? current_user_id() : null]);

        header('Location: facturi.php?saved=1&id=' . $invoiceId);
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
$paymentTypes = pz_smartbill_payment_types();

$clientsForJs = [];
foreach ($clients as $client) {
    $clientsForJs[(int)$client['id']] = [
        'name' => (string)($client['name'] ?? ''),
        'fiscal_code' => (string)($client['fiscal_code'] ?? ''),
        'reg_com' => (string)($client['registry_number'] ?? ''),
        'contact' => (string)($client['legal_representative_name'] ?? ''),
        'email' => (string)($client['email'] ?? ''),
        'phone' => (string)($client['phone'] ?? ''),
        'bank' => (string)($client['bank_name'] ?? ''),
        'iban' => (string)($client['bank_account'] ?? ''),
        'country' => (string)($client['billing_country'] ?? 'Romania') ?: 'Romania',
        'county' => (string)($client['billing_county'] ?? ''),
        'city' => (string)($client['billing_city'] ?? ''),
        'address' => trim((string)($client['billing_address_line'] ?? '')),
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
    <title>Facturi</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php app_theme_css(); ?>
    <style>
        .invoice-page{max-width:1220px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:18px}
        .hero,.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);padding:18px}
        .hero{grid-column:1/-1;display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}
        h1,h2{margin:0;letter-spacing:-.035em}.hero p,.muted{color:var(--muted);font-weight:700}
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .full{grid-column:1/-1}
        label{display:block;font-size:12px;font-weight:900;margin:10px 0 6px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        input,select,textarea{width:100%;min-height:42px;border:1px solid var(--border);border-radius:12px;padding:10px 12px;font:inherit;font-weight:750;background:#fff;box-sizing:border-box;color:var(--text)}
        textarea{min-height:82px;resize:vertical}
        .alert{grid-column:1/-1;border-radius:14px;padding:12px 14px;font-weight:850}
        .ok{background:var(--success-soft);color:var(--success);border:1px solid rgba(4,120,87,.18)}
        .err{background:var(--danger-soft);color:var(--danger);border:1px solid rgba(220,38,38,.18)}
        table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:9px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:var(--muted)}
        .pill{display:inline-flex;border-radius:999px;padding:5px 9px;background:var(--surface-soft);font-weight:900;color:var(--muted);font-size:12px}
        .payment-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:12px 0}
        .payment-box{border:1px solid var(--border);border-radius:14px;background:var(--surface-soft);padding:10px}
        .payment-box span{display:block;color:var(--muted);font-size:11px;font-weight:900;text-transform:uppercase}
        .payment-box strong{display:block;margin-top:4px;font-size:16px}
        .payment-table{margin-top:12px}
        .pill.ok{background:var(--success-soft);color:var(--success)}
        .pill.warn{background:var(--warning-soft, #fff7ed);color:var(--warning, #9a6700)}
        .items-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px}
        .invoice-items{display:grid;gap:12px;margin-top:10px}
        .invoice-item{border:1px solid var(--border);border-radius:14px;background:var(--surface-soft);padding:12px}
        .invoice-item-grid{display:grid;grid-template-columns:1.5fr .8fr .7fr .7fr .8fr .9fr auto;gap:10px;align-items:end}
        .invoice-item-extra{display:grid;grid-template-columns:1fr 180px 160px;gap:10px;align-items:end;margin-top:8px}
        .item-remove{min-width:42px}
        .invoice-list-filter{display:grid;grid-template-columns:1fr 130px auto;gap:8px;align-items:end;margin:12px 0}
        @media(max-width:1280px){.invoice-page{grid-template-columns:1fr}.invoice-page .card{min-width:0}}
        @media(max-width:980px){.form-grid{grid-template-columns:1fr}}
        @media(max-width:1120px){.invoice-item-grid,.invoice-item-extra,.invoice-list-filter{grid-template-columns:1fr 1fr}.item-remove{width:100%}}
    </style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('facturi', true); ?>
    <main class="main">
        <div class="content invoice-page">
            <section class="hero">
                <div>
                    <h1><?= $appointmentId > 0 ? 'Factura din lucrare' : 'Factura noua' ?></h1>
                    <p><?= $appointmentId > 0 ? 'Datele sunt preluate din lucrare, dar pot fi verificate înainte de emitere.' : 'Factura la liber, fara intervenție legata.' ?></p>
                </div>
                <a class="btn ghost" href="interventii_facturare.php">Lucrări</a>
            </section>

            <?php render_billing_module_nav('facturi'); ?>

            <?php if (isset($_GET['saved'])): ?><div class="alert ok">Factura a fost pregătita in CRM.</div><?php endif; ?>
            <?php if (isset($_GET['issued'])): ?><div class="alert ok">Factura a fost emisă în SmartBill.</div><?php endif; ?>
            <?php if (isset($_GET['payment_issued'])): ?><div class="alert ok">Încasarea a fost emisă în SmartBill și salvată in CRM.</div><?php endif; ?>
            <?php if (isset($_GET['receipt_deleted'])): ?><div class="alert ok">Chitanța a fost ștearsă dîn SmartBill și marcată in CRM.</div><?php endif; ?>
            <?php if (isset($_GET['payment_checked'])): ?><div class="alert ok">Statusul încasării a fost verificat în SmartBill.</div><?php endif; ?>
            <?php if (isset($_GET['email_sent'])): ?><div class="alert ok">Factura a fost trimisă pe email prîn SmartBill.</div><?php endif; ?>
            <?php if (isset($_GET['issue_error'])): ?><div class="alert err"><?= inv_h($_GET['issue_error']) ?></div><?php endif; ?>
            <?php if (isset($_GET['payment_error'])): ?><div class="alert err"><?= inv_h($_GET['payment_error']) ?></div><?php endif; ?>
            <?php if (isset($_GET['email_error'])): ?><div class="alert err"><?= inv_h($_GET['email_error']) ?></div><?php endif; ?>
            <?php if (isset($_GET['recurring_error'])): ?><div class="alert err"><?= inv_h($_GET['recurring_error']) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert err"><?= inv_h($error) ?></div><?php endif; ?>

            <section class="card">
                <h2>Date factura</h2>
                <form method="post">
                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="invoice_id" value="<?= (int)$invoiceIdFromRequest ?>">
                    <input type="hidden" name="appointment_id" value="<?= (int)$appointmentId ?>">
                    <div class="form-grid">
                        <div class="full">
                            <label>Client existent</label>
                            <select name="client_id" id="client_id" onchange="applyClientData()">
                                <option value="">Alege client sau completeaza manual</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= (int)$client['id'] ?>" <?= (int)$prefill['client_id'] === (int)$client['id'] ? 'selected' : '' ?>><?= inv_h($client['name'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Nume client *</label><input name="client_name" id="client_name" value="<?= inv_h($prefill['client_name']) ?>" required></div>
                        <div><label>CUI / CNP *</label><input name="client_fiscal_code" id="client_fiscal_code" value="<?= inv_h($prefill['client_fiscal_code']) ?>" required></div>
                        <div><label>Reg. Com. / Serie CI</label><input name="client_reg_com" id="client_reg_com" value="<?= inv_h($prefill['client_reg_com']) ?>"></div>
                        <div><label>Persoană contact</label><input name="client_contact" id="client_contact" value="<?= inv_h($prefill['client_contact']) ?>"></div>
                        <div><label>Email</label><input type="email" name="client_email" id="client_email" value="<?= inv_h($prefill['client_email']) ?>"></div>
                        <div><label>Telefon</label><input name="client_phone" id="client_phone" value="<?= inv_h($prefill['client_phone']) ?>"></div>
                        <div><label>Banca</label><input name="client_bank" id="client_bank" value="<?= inv_h($prefill['client_bank']) ?>"></div>
                        <div><label>IBAN</label><input name="client_iban" id="client_iban" value="<?= inv_h($prefill['client_iban']) ?>"></div>
                        <div><label>Țară *</label><input name="client_country" id="client_country" value="<?= inv_h($prefill['client_country']) ?>" required></div>
                        <div><label>Județ *</label><input name="client_county" id="client_county" value="<?= inv_h($prefill['client_county']) ?>" required></div>
                        <div><label>Oraș / localitate *</label><input name="client_city" id="client_city" value="<?= inv_h($prefill['client_city']) ?>" required></div>
                        <div class="full"><label>Adresa *</label><input name="client_address" id="client_address" value="<?= inv_h($prefill['client_address']) ?>" required></div>
                        <div><label>Data emitere</label><input type="date" name="issue_date" value="<?= inv_h($prefill['issue_date']) ?>"></div>
                        <div><label>Scadenta</label><input type="date" name="due_date" value="<?= inv_h($prefill['due_date']) ?>"></div>
                        <div><label>Limba</label><select name="invoice_language"><option value="RO" <?= $prefill['invoice_language'] === 'RO' ? 'selected' : '' ?>>RO</option><option value="EN" <?= $prefill['invoice_language'] === 'EN' ? 'selected' : '' ?>>EN</option></select></div>
                        <div class="full">
                            <div class="items-head">
                                <h2 style="font-size:18px">Pozitii factura</h2>
                                <button class="btn ghost" type="button" onclick="addInvoiceItem()">+ Adaugă pozitie</button>
                            </div>
                            <div class="invoice-items" id="invoiceItems">
                                <?php foreach ($invoiceItems as $idx => $item): ?>
                                    <div class="invoice-item" data-item-row>
                                        <div class="invoice-item-grid">
                                            <div><label>Descriere *</label><input name="item_description[<?= (int)$idx ?>]" value="<?= inv_h($item['description'] ?? '') ?>" required></div>
                                            <div><label>Cod</label><input name="item_product_code[<?= (int)$idx ?>]" value="<?= inv_h($item['product_code'] ?? '') ?>"></div>
                                            <div><label>Cantitate</label><input type="number" step="0.001" min="0.001" name="item_quantity[<?= (int)$idx ?>]" value="<?= inv_h($item['quantity'] ?? '1') ?>"></div>
                                            <div><label>UM</label><input name="item_unit_name[<?= (int)$idx ?>]" value="<?= inv_h($item['unit_name'] ?? 'buc') ?>"></div>
                                            <div><label>Pret</label><input type="number" step="0.01" min="0" name="item_unit_price[<?= (int)$idx ?>]" value="<?= inv_h($item['unit_price'] ?? '0.00') ?>" required></div>
                                            <div><label>TVA</label><select name="item_vat_code[<?= (int)$idx ?>]"><?php foreach ($allowedVatCodes as $code): ?><?php if (isset($vatOptions[$code])): ?><option value="<?= inv_h($code) ?>" <?= ($item['vat_code'] ?? $defaultVatCode) === $code ? 'selected' : '' ?>><?= inv_h($vatOptions[$code]) ?></option><?php endif; ?><?php endforeach; ?></select></div>
                                            <button class="btn ghost item-remove" type="button" onclick="removeInvoiceItem(this)">X</button>
                                        </div>
                                        <div class="invoice-item-extra">
                                            <div><label>Descriere detaliata</label><input name="item_product_description[<?= (int)$idx ?>]" value="<?= inv_h($item['product_description'] ?? '') ?>"></div>
                                            <div><label>Tip</label><select name="item_is_service[<?= (int)$idx ?>]"><option value="1" <?= ($item['is_service'] ?? '1') === '1' ? 'selected' : '' ?>>Serviciu</option><option value="0" <?= ($item['is_service'] ?? '1') === '0' ? 'selected' : '' ?>>Produs</option></select></div>
                                            <label style="text-transform:none;letter-spacing:0;color:var(--text);margin-top:22px"><input type="checkbox" name="item_is_tax_included[<?= (int)$idx ?>]" value="1" <?= ($item['is_tax_included'] ?? '0') === '1' ? 'checked' : '' ?> style="width:auto;min-height:0"> Pret cu TVA inclus</label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div><label>Moneda</label><input name="currency" value="<?= inv_h($prefill['currency']) ?>"></div>
                        <div class="full"><label>Mențiuni pe factura</label><textarea name="mentions"><?= inv_h($prefill['mentions']) ?></textarea></div>
                        <div class="full"><label>Observații SmartBill</label><textarea name="observations"><?= inv_h($prefill['observations']) ?></textarea></div>
                        <div class="full"><label>Note interne</label><textarea name="notes"><?= inv_h($prefill['notes']) ?></textarea></div>
                        <div class="full"><button class="btn accent" type="submit">Salvează draft factura</button></div>
                    </div>
                </form>
            </section>

            <?php if ($loadedInvoice): ?>
            <section class="card">
                <h2>Acțiuni factura</h2>
                <div class="payment-summary">
                    <div class="payment-box"><span>Status</span><strong><?= inv_h($loadedInvoice['smartbill_status'] ?? 'draft') ?></strong></div>
                    <div class="payment-box"><span>Total</span><strong><?= inv_h(inv_money_label($loadedGross, (string)($loadedInvoice['currency'] ?? 'RON'))) ?></strong></div>
                    <div class="payment-box"><span>Încasare</span><strong><?= inv_h(pz_smartbill_payment_status_label($loadedPaymentStatus)) ?></strong></div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                    <?php if (trim((string)($loadedInvoice['smartbill_number'] ?? '')) === ''): ?>
                        <form method="post" style="margin:0">
                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                            <input type="hidden" name="action" value="issue">
                            <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                            <button class="btn accent" type="submit">Emite în SmartBill</button>
                        </form>
                    <?php else: ?>
                        <a class="btn ghost" href="facturi_pdf.php?id=<?= (int)$loadedInvoice['id'] ?>" target="_blank" rel="noopener">Vezi PDF</a>
                        <a class="btn ghost" href="efactura.php">E-Factura</a>
                    <?php endif; ?>
                    <a class="btn ghost" href="incasari.php">Vezi încasări</a>
                    <a class="btn ghost" href="facturi_recurente.php">Recurente</a>
                </div>
            </section>

            <?php endif; ?>

            <?php if ($loadedInvoice): ?>
            <section class="card">
                <h2>Facturare recurenta</h2>
                <p class="muted">Foloseste factura curenta ca model și genereaza periodic facturi noi cu aceleasi pozitii și date fiscale.</p>
                <form method="post" class="form-grid">
                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                    <input type="hidden" name="action" value="create_recurring">
                    <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                    <div><label>Denumire recurenta</label><input name="title" value="Abonament <?= inv_h($loadedInvoice['client_name'] ?? '') ?>"></div>
                    <div><label>Frecvență</label><select name="frequency"><option value="monthly">Lunar</option><option value="quarterly">Trimestrial</option><option value="yearly">Anual</option><option value="weekly">Săptămânal</option></select></div>
                    <div><label>Interval</label><input type="number" min="1" max="24" name="interval_value" value="1"></div>
                    <div><label>Ziua lunii</label><input type="number" min="1" max="31" name="day_of_month" value="<?= (int)date('d') ?>"></div>
                    <div><label>Prima emitere</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>"></div>
                    <div><label>Data final optionala</label><input type="date" name="end_date"></div>
                    <div class="full"><label style="text-transform:none;letter-spacing:0;color:var(--text)"><input type="checkbox" name="auto_issue" value="1" style="width:auto;min-height:0"> Emite automat în SmartBill cand se genereaza</label></div>
                    <div class="full"><label style="text-transform:none;letter-spacing:0;color:var(--text)"><input type="checkbox" name="auto_email" value="1" style="width:auto;min-height:0"> Trimite automat email după emitere</label></div>
                    <div class="full"><label>Note recurenta</label><textarea name="notes"></textarea></div>
                    <div class="full"><button class="btn accent" type="submit">Creeaza recurenta</button> <a class="btn ghost" href="facturi_recurente.php">Vezi recurente</a></div>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($loadedInvoice && trim((string)($loadedInvoice['smartbill_number'] ?? '')) !== ''): ?>
            <section class="card">
                <h2>Document SmartBill</h2>
                <p class="muted">Factura <?= inv_h(trim((string)(($loadedInvoice['smartbill_series'] ?? '') . ' ' . ($loadedInvoice['smartbill_number'] ?? '')))) ?> este emisa în SmartBill.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                    <a class="btn ghost" href="facturi_pdf.php?id=<?= (int)$loadedInvoice['id'] ?>" target="_blank" rel="noopener">Vezi PDF</a>
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
                <h2>Incasari</h2>
                <div class="payment-summary">
                    <div class="payment-box"><span>Total factura</span><strong><?= inv_h(inv_money_label($loadedGross, (string)($loadedInvoice['currency'] ?? 'RON'))) ?></strong></div>
                    <div class="payment-box"><span>Incasat</span><strong><?= inv_h(inv_money_label($loadedPaid, (string)($loadedInvoice['currency'] ?? 'RON'))) ?></strong></div>
                    <div class="payment-box"><span>Sold ramas</span><strong><?= inv_h(inv_money_label($loadedRemaining, (string)($loadedInvoice['currency'] ?? 'RON'))) ?></strong></div>
                </div>
                <p class="muted">Status: <?= inv_h(pz_smartbill_payment_status_label($loadedPaymentStatus)) ?>. O factură poate avea mai multe încasări: chitanța, OP, transfer bancar, card sau încasare partiala.</p>
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
                <form method="post" class="form-grid">
                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                    <input type="hidden" name="action" value="add_payment">
                    <input type="hidden" name="invoice_id" value="<?= (int)$loadedInvoice['id'] ?>">
                    <div>
                        <label>Tip încasare</label>
                        <select name="payment_type">
                            <?php foreach ($paymentTypes as $type => $label): ?>
                                <option value="<?= inv_h($type) ?>"><?= inv_h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Data încasare</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"></div>
                    <div><label>Suma incasata</label><input type="number" step="0.01" min="0.01" max="<?= inv_h(number_format($loadedRemaining, 2, '.', '')) ?>" name="amount" value="<?= inv_h(number_format($loadedRemaining, 2, '.', '')) ?>"></div>
                    <div><label>Moneda</label><input name="currency" value="<?= inv_h($loadedInvoice['currency'] ?? 'RON') ?>"></div>
                    <div><label>Serie document</label><input name="document_series" placeholder="ex: CSB"></div>
                    <div><label>Numar document / OP</label><input name="document_number" placeholder="ex: 0001 / OP123"></div>
                    <div><label>Banca</label><input name="bank_name" placeholder="optional"></div>
                    <div><label>Cont bancar</label><input name="bank_account" placeholder="optional"></div>
                    <div class="full"><label>Observații încasare</label><textarea name="notes"></textarea></div>
                    <div class="full"><button class="btn accent" type="submit">Emite încasarea în SmartBill</button></div>
                </form>
                <?php endif; ?>

                <table class="payment-table">
                    <thead><tr><th>Data</th><th>Tip</th><th>Suma</th><th>Document</th><th>Status</th><th>Acțiuni</th></tr></thead>
                    <tbody>
                    <?php if (empty($loadedInvoice['payments'])): ?><tr><td colspan="6" class="muted">Nu există încasări in CRM.</td></tr><?php endif; ?>
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
                                    <form method="post" style="margin:0" onsubmit="return confirm('Stergi chitanța dîn SmartBill? SmartBill permite de regulă ștergerea doar pentru ultima chitanța din serie.');">
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

            <aside class="card">
                <h2>Ultimele facturi</h2>
                <form method="get" class="invoice-list-filter">
                    <?php if ($invoiceClientFilter > 0): ?>
                        <input type="hidden" name="client_id" value="<?= (int)$invoiceClientFilter ?>">
                    <?php endif; ?>
                    <div><label>Căutare</label><input type="search" name="invoice_q" value="<?= inv_h($invoiceSearch) ?>" placeholder="Client, CUI, numar"></div>
                    <div><label>Status</label><select name="invoice_status"><option value="all">Toate</option><option value="draft" <?= $invoiceStatusFilter === 'draft' ? 'selected' : '' ?>>Draft</option><option value="issued" <?= $invoiceStatusFilter === 'issued' ? 'selected' : '' ?>>Emise</option><option value="error" <?= $invoiceStatusFilter === 'error' ? 'selected' : '' ?>>Erori</option></select></div>
                    <button class="btn ghost" type="submit">Caută</button>
                </form>
                <table>
                    <thead><tr><th>ID</th><th>Client</th><th>Total</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$recent): ?><tr><td colspan="4" class="muted">Nu există facturi.</td></tr><?php endif; ?>
                    <?php foreach ($recent as $row): ?>
                        <?php
                            $pay = $recentStatus[(int)$row['id']] ?? ['paid' => 0, 'status' => 'neincasata'];
                            $payClass = $pay['status'] === 'incasata' ? 'ok' : ($pay['status'] === 'partial' ? 'warn' : '');
                        ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?></td>
                            <td><?= inv_h($row['client_name'] ?? '-') ?></td>
                            <td>
                                <?= number_format((float)($row['gross_amount'] ?? 0), 2, ',', '.') ?> <?= inv_h($row['currency'] ?? 'RON') ?>
                                <div class="muted">Incasat: <?= inv_h(inv_money_label($pay['paid'] ?? 0, (string)($row['currency'] ?? 'RON'))) ?></div>
                            </td>
                            <td>
                                <span class="pill"><?= inv_h($row['smartbill_status'] ?? 'draft') ?></span>
                                <span class="pill <?= inv_h($payClass) ?>"><?= inv_h(pz_smartbill_payment_status_label((string)($pay['status'] ?? 'neincasata'))) ?></span>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:7px">
                                    <a class="btn ghost" style="min-height:32px;padding:6px 9px;font-size:12px" href="facturi.php?id=<?= (int)$row['id'] ?>">Deschide</a>
                                    <?php if (trim((string)($row['smartbill_number'] ?? '')) === ''): ?>
                                        <form method="post" style="margin:0">
                                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                            <input type="hidden" name="action" value="issue">
                                            <input type="hidden" name="invoice_id" value="<?= (int)$row['id'] ?>">
                                            <button class="btn accent" style="min-height:32px;padding:6px 9px;font-size:12px" type="submit">Emite</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted"><?= inv_h(trim((string)(($row['smartbill_series'] ?? '') . ' ' . ($row['smartbill_number'] ?? '')))) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </aside>
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
function invoiceVatOptionsHtml(selected = '<?= inv_h($defaultVatCode) ?>') {
    return invoiceVatOptions.map(opt => `<option value="${String(opt.code).replace(/"/g, '&quot;')}" ${opt.code === selected ? 'selected' : ''}>${String(opt.label).replace(/</g, '&lt;')}</option>`).join('');
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
            <div><label>Descriere *</label><input name="item_description[${idx}]" required></div>
            <div><label>Cod</label><input name="item_product_code[${idx}]"></div>
            <div><label>Cantitate</label><input type="number" step="0.001" min="0.001" name="item_quantity[${idx}]" value="1"></div>
            <div><label>UM</label><input name="item_unit_name[${idx}]" value="buc"></div>
            <div><label>Pret</label><input type="number" step="0.01" min="0" name="item_unit_price[${idx}]" value="0.00" required></div>
            <div><label>TVA</label><select name="item_vat_code[${idx}]">${invoiceVatOptionsHtml()}</select></div>
            <button class="btn ghost item-remove" type="button" onclick="removeInvoiceItem(this)">X</button>
        </div>
        <div class="invoice-item-extra">
            <div><label>Descriere detaliata</label><input name="item_product_description[${idx}]"></div>
            <div><label>Tip</label><select name="item_is_service[${idx}]"><option value="1" selected>Serviciu</option><option value="0">Produs</option></select></div>
            <label style="text-transform:none;letter-spacing:0;color:var(--text);margin-top:22px"><input type="checkbox" name="item_is_tax_included[${idx}]" value="1" style="width:auto;min-height:0"> Pret cu TVA inclus</label>
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
</script>
</body>
</html>
