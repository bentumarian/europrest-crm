<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/document_core.php';
require_once __DIR__ . '/document_tokens.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

$isAdmin = is_admin();
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

try {
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    pzdoc_require_schema($pdo);
} catch (Throwable $e) {
    error_log('PestZone oferte init error: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Helpers locale pentru pagina Oferte
|--------------------------------------------------------------------------
*/
function pz_offer_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pz_offer_str($value, int $max = 0): string {
    $value = trim((string)$value);
    if ($max > 0) {
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $max, 'UTF-8');
        } else {
            $value = substr($value, 0, $max);
        }
    }
    return $value;
}

function pz_offer_decimal($value, float $default = 0.0): float {
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_string($value)) {
        $value = str_replace([' ', ','], ['', '.'], $value);
    }
    return is_numeric($value) ? (float)$value : $default;
}

function pz_offer_money($value, string $currency = 'RON'): string {
    return number_format((float)$value, 2, ',', '.') . ' ' . $currency;
}


function pz_offer_unit_value($value): string {
    $value = strtolower(trim((string)$value));
    if (in_array($value, ['mp', 'm2', 'm²', 'metru patrat', 'metri patrati'], true)) {
        return 'mp';
    }
    return 'buc';
}

function pz_offer_unit_options($selected): string {
    $selected = pz_offer_unit_value($selected);
    $options = [
        'buc' => 'Bucata',
        'mp' => 'Metru patrat',
    ];
    $html = '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . pz_offer_h($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . pz_offer_h($label) . '</option>';
    }
    return $html;
}

function pz_offer_date_ro(?string $date): string {
    if (!$date) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : '-';
}

function pz_offer_status_label(string $status): string {
    return [
        'draft' => 'Draft',
        'issued' => 'Emisa',
        'cancelled' => 'Anulata',
    ][$status] ?? $status;
}

function pz_offer_status_class(string $status): string {
    return [
        'draft' => 'draft',
        'issued' => 'issued',
        'cancelled' => 'cancelled',
    ][$status] ?? 'draft';
}

function pz_offer_current_url(array $extra = []): string {
    $params = $_GET;
    foreach ($extra as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'oferte.php' . ($params ? '?' . http_build_query($params) : '');
}

function pz_offer_fetch_clients(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'clients')) {
        return [];
    }

    $stmt = $pdo->query("\n        SELECT *\n        FROM clients\n        WHERE COALESCE(active, 1) = 1\n        ORDER BY name ASC\n        LIMIT 1500\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_offer_client_address(array $client): string {
    $line = trim((string)($client['billing_address_line'] ?? ''));
    $county = trim((string)($client['billing_county'] ?? ''));
    $city = trim((string)($client['billing_city'] ?? ''));
    $country = trim((string)($client['billing_country'] ?? ''));
    $postal = trim((string)($client['billing_postal_code'] ?? ''));
    $address = trim(implode(', ', array_filter([$line, $county, $city, $country], static fn($value) => $value !== '')));
    if ($postal !== '') {
        $address .= ($address !== '' ? ', ' : '') . 'CP ' . $postal;
    }
    if ($address !== '') {
        return $address;
    }

    return trim((string)(($client['registered_address'] ?? '') ?: ($client['address'] ?? '')));
}

function pz_offer_fetch_locations(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'client_locations')) {
        return [];
    }

    $stmt = $pdo->query("\n        SELECT id, client_id, location_name, address, contact_person, phone,\n               surface_value, surface_unit, active, sort_order\n        FROM client_locations\n        WHERE COALESCE(active, 1) = 1\n        ORDER BY client_id ASC, sort_order ASC, location_name ASC\n        LIMIT 5000\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_offer_fetch_services(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'services')) {
        return [];
    }

    $stmt = $pdo->query("\n        SELECT id, name, description, active, sort_order\n        FROM services\n        WHERE COALESCE(active, 1) = 1\n        ORDER BY sort_order ASC, name ASC\n        LIMIT 500\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_offer_fetch_templates(PDO $pdo): array {
    $stmt = $pdo->prepare("\n        SELECT id, name, is_default\n        FROM document_templates\n        WHERE document_type = 'oferta'\n          AND is_active = 1\n        ORDER BY is_default DESC, name ASC\n    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_offer_locations_by_id(array $locations): array {
    $map = [];
    foreach ($locations as $location) {
        $map[(int)$location['id']] = $location;
    }
    return $map;
}

function pz_offer_build_items_from_post(array $postItems, array $locationsById, ?int $mainLocationId, float $vatPercent, string $currency): array {
    $items = [];
    $sort = 0;

    foreach ($postItems as $row) {
        if (!is_array($row)) {
            continue;
        }

        $serviceId = !empty($row['service_id']) ? (int)$row['service_id'] : null;
        $serviceName = pz_offer_str($row['service_name'] ?? '', 220);
        $description = pz_offer_str($row['description'] ?? '');

        if (!$serviceId && $serviceName === '' && $description === '') {
            continue;
        }

        $locationId = !empty($row['client_location_id']) ? (int)$row['client_location_id'] : ($mainLocationId ?: null);
        $location = ($locationId && isset($locationsById[$locationId])) ? $locationsById[$locationId] : null;

        $quantity = max(0, pz_offer_decimal($row['quantity'] ?? 1, 1));
        if ($quantity <= 0) {
            $quantity = 1;
        }
        $unitPrice = max(0, round(pz_offer_decimal($row['unit_price'] ?? 0, 0), 2));
        // Ofertele lucreaza cu preturi nete fixe, fără TVA. Valoarea liniei se recalculeaza pe server
        // din cantitate x pret unitar, ca sa nu depindem de un camp hidden sau de calcule vechi din browser.
        $totalPrice = round($quantity * $unitPrice, 2);

        $items[] = [
            'item_type' => 'offer_service',
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'description' => $description,
            'client_location_id' => $locationId,
            'location_name' => $location ? ($location['location_name'] ?? null) : null,
            'location_address' => $location ? ($location['address'] ?? null) : null,
            'quantity' => $quantity,
            'unit' => pz_offer_unit_value($row['unit'] ?? 'buc'),
            'unit_price' => $unitPrice,
            'vat_percent' => $vatPercent,
            'total_price' => $totalPrice,
            'currency' => $currency,
            'frequency_text' => pz_offer_str($row['frequency_text'] ?? '', 255),
            'sort_order' => $sort,
        ];
        $sort++;
    }

    return $items;
}

function pz_offer_build_payload_from_post(array $post): array {
    $documentDate = pz_offer_str($post['document_date'] ?? date('Y-m-d'), 20) ?: date('Y-m-d');
    $validDays = max(0, (int)($post['valid_days'] ?? 15));
    $validUntil = '';
    if ($validDays > 0) {
        $ts = strtotime($documentDate . ' +' . $validDays . ' days');
        if ($ts) {
            $validUntil = date('Y-m-d', $ts);
        }
    }

    $discountType = pz_offer_str($post['discount_type'] ?? 'none', 20);
    if (!in_array($discountType, ['none', 'percent', 'value'], true)) {
        $discountType = 'none';
    }
    $discountValue = max(0, pz_offer_decimal($post['discount_value'] ?? 0, 0));
    if ($discountType === 'none') {
        $discountValue = 0;
    }

    return [
        'valid_days' => $validDays,
        'valid_until' => $validUntil,
        'payment_terms' => pz_offer_str($post['payment_terms'] ?? '', 255),
        'delivery_terms' => pz_offer_str($post['delivery_terms'] ?? '', 255),
        'offer_intro' => pz_offer_str($post['offer_intro'] ?? ''),
        'offer_footer' => pz_offer_str($post['offer_footer'] ?? ''),
        'discount_type' => $discountType,
        'discount_value' => $discountValue,
    ];
}

function pz_offer_default_template_html(): string {
    return '<h1 style="text-align:center;">{{document_title}}</h1>
'
        . '<p style="text-align:center;"><strong>Oferta nr. {{document_number}} / {{document_date}}</strong><br>Valabila {{valid_days}} zile de la data emiterii</p>
'
        . '<p><strong>Catre:</strong><br>{{client_block}}</p>
'
        . '<p>{{offer_intro}}</p>
'
        . '{{items_table}}
'
        . '<p><strong>Subtotal servicii:</strong> {{subtotal_without_vat}}</p>
'
        . '{{discount_block}}
'
        . '<p><strong>Total oferta:</strong> {{total_without_vat}}</p>
'
        . '<p><em>{{prices_without_vat_note}}</em></p>
'
        . '<p><strong>Conditii de plata:</strong><br>{{payment_terms}}</p>
'
        . '<p><strong>Observații:</strong><br>{{notes}}</p>
'
        . '<p>Acceptarea prezentei oferte se poate face prin semnare, comanda ferma sau confirmare scrisa transmisa pe email.</p>
'
        . '<table width="100%" cellspacing="0" cellpadding="0" style="margin-top:25px;"><tr>'
        . '<td width="50%"><strong>Prestator,</strong><br>{{company_name}}<br>{{company_representative}}<br>{{company_stamp}}</td>'
        . '<td width="50%"><strong>Beneficiar,</strong><br>{{client_name}}<br>{{client_representative}}</td>'
        . '</tr></table>';
}

function pz_offer_ensure_default_template(PDO $pdo): void {
    if (!pzdoc_table_exists($pdo, 'document_templates')) {
        return;
    }

    $slug = 'default_oferta_comerciala_simplificata';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_templates WHERE document_type = 'oferta' AND slug = ?");
    $stmt->execute([$slug]);
    $exists = (int)$stmt->fetchColumn() > 0;

    if (!$exists) {
        $pdo->prepare("UPDATE document_templates SET is_default = 0 WHERE document_type = 'oferta'")->execute();
        $insert = $pdo->prepare("
            INSERT INTO document_templates
                (document_type, name, slug, description, content_html, is_default, is_active, created_by)
            VALUES
                ('oferta', ?, ?, ?, ?, 1, 1, NULL)
        ");
        $insert->execute([
            'Oferta comerciala simplificata',
            $slug,
            'Șablon oferta B2B fără TVA, cu servicii, descrieri si discount.',
            pz_offer_default_template_html(),
        ]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, is_default FROM document_templates WHERE document_type = 'oferta' AND slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && empty($row['is_default'])) {
        $pdo->prepare("UPDATE document_templates SET is_default = 0 WHERE document_type = 'oferta'")->execute();
        $pdo->prepare("UPDATE document_templates SET is_default = 1, is_active = 1 WHERE id = ?")->execute([(int)$row['id']]);
    }

    try {
        $stmt = $pdo->prepare("SELECT id, content_html FROM document_templates WHERE document_type = 'oferta' AND slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        $content = (string)($tpl['content_html'] ?? '');
        if ($tpl) {
            $updated = $content;
            $updated = str_replace('<p><strong>Conditii / termen executie:</strong><br>{{delivery_terms}}</p>', '', $updated);
            $updated = str_replace('<p>{{offer_footer}}</p>', '<p>Acceptarea prezentei oferte se poate face prin semnare, comanda ferma sau confirmare scrisa transmisa pe email.</p>', $updated);
            if (stripos($updated, '{{company_stamp}}') === false) {
                $updated = str_replace('{{company_name}}<br>{{company_representative}}</td>', '{{company_name}}<br>{{company_representative}}<br>{{company_stamp}}</td>', $updated);
            }
            if ($updated !== $content) {
                $pdo->prepare("UPDATE document_templates SET content_html = ? WHERE id = ?")->execute([$updated, (int)$tpl['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('PestZone offer stamp template update error: ' . $e->getMessage());
    }
}

function pz_offer_redirect_with_error(string $message, int $editId = 0): void {
    $_SESSION['pz_offer_error'] = $message;
    $url = 'oferte.php';
    if ($editId > 0) {
        $url .= '?edit=' . (int)$editId;
    }
    header('Location: ' . $url);
    exit;
}

try {
    pz_offer_ensure_default_template($pdo);
} catch (Throwable $e) {
    error_log('PestZone offer template ensure error: ' . $e->getMessage());
}

$clients = pz_offer_fetch_clients($pdo);
$locations = pz_offer_fetch_locations($pdo);
$locationsById = pz_offer_locations_by_id($locations);
$services = pz_offer_fetch_services($pdo);
$templates = pz_offer_fetch_templates($pdo);

/*
|--------------------------------------------------------------------------
| POST: salvare / emitere oferta
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? '';
    if (in_array($action, ['save_draft', 'issue'], true)) {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        $locationId = !empty($_POST['client_location_id']) ? (int)$_POST['client_location_id'] : null;
        $vatPercent = 0.0; // Ofertele B2B se lucreaza fără TVA in document
        $currency = pz_offer_str($_POST['currency'] ?? 'RON', 10) ?: 'RON';
        $items = pz_offer_build_items_from_post($_POST['items'] ?? [], $locationsById, $locationId, $vatPercent, $currency);

        if ($clientId <= 0) {
            pz_offer_redirect_with_error('Selectează clientul pentru oferta.', $documentId);
        }

        if (!$items) {
            pz_offer_redirect_with_error('Adaugă cel puțin un serviciu in oferta.', $documentId);
        }

        $payload = pz_offer_build_payload_from_post($_POST);
        $data = [
            'template_id' => !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null,
            'document_date' => $_POST['document_date'] ?? date('Y-m-d'),
            'document_time' => null,
            'title' => pz_offer_str($_POST['title'] ?? '', 220),
            'client_id' => $clientId,
            'client_location_id' => $locationId,
            'vat_percent' => $vatPercent,
            'currency' => $currency,
            'notes' => pz_offer_str($_POST['notes'] ?? ''),
            'internal_notes' => pz_offer_str($_POST['internal_notes'] ?? ''),
            'payload_json' => $payload,
            'items' => $items,
        ];

        try {
            if ($documentId > 0) {
                $existing = pzdoc_get_document($pdo, $documentId, false);
                if (!$existing || ($existing['document_type'] ?? '') !== 'oferta') {
                    throw new RuntimeException('Oferta inexistenta.');
                }
                pzdoc_update_document($pdo, $documentId, $data);
            } else {
                $documentId = pzdoc_create_document($pdo, 'oferta', $data);
            }

            if ($action === 'issue') {
                pzdoc_issue_document($pdo, $documentId);
                header('Location: document_view.php?id=' . (int)$documentId . '&issued=1');
                exit;
            }

            header('Location: document_view.php?id=' . (int)$documentId . '&saved=1');
            exit;
        } catch (Throwable $e) {
            error_log('PestZone oferta save error: ' . $e->getMessage());
            pz_offer_redirect_with_error('Oferta nu a putut fi salvata: ' . $e->getMessage(), $documentId);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Pregatire editare / lista
|--------------------------------------------------------------------------
*/
$errorMessage = $_SESSION['pz_offer_error'] ?? '';
unset($_SESSION['pz_offer_error']);

$editId = (int)($_GET['edit'] ?? 0);
$editingDocument = null;
$editingItems = [];
$editingPayload = [];

if ($editId > 0) {
    $editingDocument = pzdoc_get_document($pdo, $editId, true);
    if (!$editingDocument || ($editingDocument['document_type'] ?? '') !== 'oferta') {
        $errorMessage = 'Oferta solicitata nu există.';
        $editingDocument = null;
    } elseif (($editingDocument['status'] ?? '') !== 'draft') {
        header('Location: document_view.php?id=' . $editId);
        exit;
    } else {
        $editingItems = $editingDocument['items'] ?? [];
        $editingPayload = pzdoc_json_decode($editingDocument['payload_json'] ?? null);
    }
}

$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'client_id' => max(0, (int)($_GET['client_id'] ?? 0)),
];
$perPage = (int)($_GET['per_page'] ?? 20);
$perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalRows = pzdoc_count_documents($pdo, 'oferta', $filters);
$documents = pzdoc_list_documents($pdo, 'oferta', $filters, $perPage, $offset);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$formDocument = $editingDocument ?: [
    'id' => 0,
    'template_id' => $templates[0]['id'] ?? null,
    'document_date' => date('Y-m-d'),
    'title' => '',
    'client_id' => null,
    'client_location_id' => null,
    'vat_percent' => 0,
    'currency' => 'RON',
    'notes' => '',
    'internal_notes' => '',
];

if (!$editingDocument && !empty($_GET['client_id'])) {
    $formDocument['client_id'] = max(0, (int)$_GET['client_id']);
}

$formValidDays = (int)($editingPayload['valid_days'] ?? 15);
if ($formValidDays <= 0 && !empty($editingPayload['valid_until']) && !empty($formDocument['document_date'])) {
    $startTs = strtotime((string)$formDocument['document_date']);
    $endTs = strtotime((string)$editingPayload['valid_until']);
    if ($startTs && $endTs && $endTs >= $startTs) {
        $formValidDays = (int)floor(($endTs - $startTs) / 86400);
    }
}
if ($formValidDays <= 0) {
    $formValidDays = 15;
}
$formDiscountType = (string)($editingPayload['discount_type'] ?? 'none');
if (!in_array($formDiscountType, ['none', 'percent', 'value'], true)) {
    $formDiscountType = 'none';
}
$formDiscountValue = (float)($editingPayload['discount_value'] ?? 0);

$isOfferNew = !$editingDocument;
$formOfferIntro = (string)($editingPayload['offer_intro'] ?? '');
$formPaymentTerms = (string)($editingPayload['payment_terms'] ?? '');
$formDeliveryTerms = (string)($editingPayload['delivery_terms'] ?? '');
$formOfferFooter = (string)($editingPayload['offer_footer'] ?? '');
$formNotes = (string)($formDocument['notes'] ?? '');
if ($isOfferNew) {
    if ($formOfferIntro === '') {
        $formOfferIntro = 'Va transmitem prezenta oferta comerciala pentru prestarea serviciilor detaliate mai jos:';
    }
    if ($formPaymentTerms === '') {
        $formPaymentTerms = 'Plata se efectueaza in termen de 5 zile de la emiterea facturii.';
    }
    if ($formNotes === '') {
        $formNotes = 'Serviciile suplimentare care nu sunt mentionate expres in prezenta oferta se vor factura separat, doar cu acordul beneficiarului.';
    }
}

if (!$editingItems) {
    $editingItems = [[
        'service_id' => '',
        'service_name' => '',
        'description' => '',
        'client_location_id' => $formDocument['client_location_id'] ?? '',
        'quantity' => 1,
        'unit' => 'buc',
        'unit_price' => 0,
        'total_price' => 0,
        'frequency_text' => '',
    ]];
}

$clientsForJson = [];
foreach ($clients as $client) {
    $clientsForJson[] = [
        'id' => (int)$client['id'],
        'name' => (string)($client['name'] ?? ''),
        'fiscal_code' => (string)($client['fiscal_code'] ?? ''),
        'representative' => (string)($client['legal_representative_name'] ?? ''),
        'address' => pz_offer_client_address($client),
        'email' => (string)($client['email'] ?? ''),
        'phone' => (string)($client['phone'] ?? ''),
    ];
}

$locationsForJson = [];
foreach ($locations as $location) {
    $locationsForJson[] = [
        'id' => (int)$location['id'],
        'client_id' => (int)$location['client_id'],
        'location_name' => (string)($location['location_name'] ?? ''),
        'address' => (string)($location['address'] ?? ''),
        'contact_person' => (string)($location['contact_person'] ?? ''),
        'phone' => (string)($location['phone'] ?? ''),
    ];
}

$servicesForJson = [];
foreach ($services as $service) {
    $servicesForJson[] = [
        'id' => (int)$service['id'],
        'name' => (string)($service['name'] ?? ''),
        'description' => (string)($service['description'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Oferte - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.offer-topbar { align-items:center; padding:12px 20px; }
.offer-toolbar { width:100%; display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap; }
.offer-hero {
    background: linear-gradient(135deg, var(--accent-deep), var(--accent-strong));
    color:#fff; border-radius:var(--radius-lg); padding:22px 24px; box-shadow:var(--shadow-lg);
    margin-bottom:14px; display:flex; justify-content:space-between; gap:18px; flex-wrap:wrap; align-items:center;
}
.offer-hero h1 { font-size:24px; font-weight:900; letter-spacing:-.03em; margin:0; }
.offer-hero p { color:rgba(255,255,255,.72); margin:4px 0 0; max-width:850px; }
.hero-actions { display:flex; gap:8px; flex-wrap:wrap; }
.panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); box-shadow:var(--shadow); margin-bottom:12px; }
.panel-head { padding:14px 16px; border-bottom:1px solid var(--border2); display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; }
.panel-title { font-size:16px; font-weight:900; color:var(--text); }
.panel-subtitle { font-size:12px; color:var(--muted); margin-top:2px; }
.panel-body { padding:14px 16px; }
.alert { border-radius:14px; padding:11px 13px; margin-bottom:12px; font-weight:800; font-size:13px; }
.alert.error { background:var(--danger-soft); color:var(--danger); border:1px solid rgba(180,35,24,.16); }
.alert.success { background:var(--success-soft); color:var(--success); border:1px solid rgba(31,111,84,.16); }
.filter-form { display:grid; grid-template-columns:minmax(220px,1fr) minmax(150px,.45fr) minmax(130px,.35fr) auto; gap:10px; align-items:end; }
.offer-form-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
.field label { display:block; font-size:12px; font-weight:850; color:var(--muted); margin-bottom:5px; }
.field input, .field select, .field textarea { width:100%; border:1px solid var(--accent-soft-2); border-radius:12px; background:#fff; color:var(--text); padding:10px 11px; font-size:13px; outline:none; transition:border-color .14s ease, box-shadow .14s ease; }
.field input:hover:not(:focus), .field select:hover:not(:focus), .field textarea:hover:not(:focus) { border-color:var(--accent); }
.field textarea { min-height:82px; resize:vertical; }
.field input:focus, .field select:focus, .field textarea:focus { border-color:var(--accent); box-shadow:var(--focus-ring); }

/* === AUTOCOMPLETE client + locație smart (acelasi pattern ca PV) === */
.pz-autocomplete { position:relative; }
.pz-autocomplete-input { width:100%; padding:10px 38px 10px 38px; border:1px solid var(--accent-soft-2); border-radius:12px; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748B' stroke-width='2.2' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='m21 21-4.3-4.3'/%3E%3C/svg%3E") no-repeat 12px center; font-size:13px; color:var(--text); outline:none; transition:border-color .14s ease, box-shadow .14s ease; }
.pz-autocomplete-input:hover { border-color:var(--accent); }
.pz-autocomplete-input:focus { border-color:var(--accent); box-shadow:var(--focus-ring); }
.pz-autocomplete-clear { position:absolute; right:10px; top:50%; transform:translateY(-50%); width:22px; height:22px; border-radius:50%; border:0; background:var(--surface-soft); color:var(--muted); cursor:pointer; font-size:14px; padding:0; display:none; align-items:center; justify-content:center; }
.pz-autocomplete.has-value .pz-autocomplete-clear { display:inline-flex; }
.pz-autocomplete-clear:hover { background:var(--tone-danger-soft); color:var(--tone-danger); }
.pz-autocomplete-results { display:none; position:absolute; left:0; right:0; top:calc(100% + 4px); max-height:320px; overflow-y:auto; background:#fff; border:1px solid var(--border); border-radius:12px; box-shadow:var(--shadow-lg); z-index:50; padding:4px; }
.pz-autocomplete.is-open .pz-autocomplete-results { display:block; }
.pz-autocomplete-result { padding:9px 11px; border-radius:8px; cursor:pointer; transition:background .12s ease; }
.pz-autocomplete-result:hover, .pz-autocomplete-result.is-active { background:var(--accent-soft); }
.pz-autocomplete-result .ar-name { font-size:13px; font-weight:700; color:var(--text); }
.pz-autocomplete-result .ar-meta { font-size:11px; color:var(--muted); margin-top:2px; }
.pz-autocomplete-result mark { background:rgba(79,70,229,.18); color:var(--accent-strong); padding:0 1px; border-radius:2px; }
.pz-autocomplete-empty { padding:14px 12px; text-align:center; color:var(--muted); font-size:12px; }
.pz-autocomplete-selected { display:none; align-items:center; gap:10px; padding:9px 11px 9px 12px; background:var(--accent-soft); border:1px solid var(--accent-soft-2); border-radius:12px; color:var(--text); font-size:13px; font-weight:700; }
.pz-autocomplete.has-value .pz-autocomplete-selected { display:flex; }
.pz-autocomplete.has-value .pz-autocomplete-input { display:none; }
.pz-autocomplete-selected .ps-meta { color:var(--muted); font-weight:500; font-size:11.5px; margin-top:2px; }
.pz-autocomplete-selected .ps-clear { margin-left:auto; width:26px; height:26px; border-radius:8px; border:0; background:#fff; color:var(--muted); cursor:pointer; font-size:14px; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
.pz-autocomplete-selected .ps-clear:hover { background:var(--tone-danger-soft); color:var(--tone-danger); }
.pz-location-info { display:flex; align-items:center; gap:10px; padding:10px 12px; background:var(--surface-soft); border:1px solid var(--border2); border-radius:12px; color:var(--text); font-size:13px; font-weight:600; }
.pz-location-info .pl-label { color:var(--muted); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.field.full { grid-column:1 / -1; }
.field.span2 { grid-column:span 2; }
.client-help { margin-top:6px; color:var(--muted); font-size:12px; line-height:1.4; }
.items-wrap { overflow-x:auto; }
.items-table { width:100%; border-collapse:separate; border-spacing:0 8px; min-width:940px; }
.items-table th { text-align:left; font-size:11px; color:var(--muted); font-weight:900; padding:0 6px; text-transform:uppercase; letter-spacing:.04em; }
.items-table td { background:var(--surface-soft); border-top:1px solid var(--border2); border-bottom:1px solid var(--border2); padding:7px 6px; vertical-align:top; }
.items-table td:first-child { border-left:1px solid var(--border2); border-radius:12px 0 0 12px; }
.items-table td:last-child { border-right:1px solid var(--border2); border-radius:0 12px 12px 0; }
.items-table input, .items-table select { width:100%; border:1px solid var(--border); border-radius:10px; padding:8px 9px; background:#fff; font-size:12px; }
.items-table textarea { width:100%; min-height:39px; border:1px solid var(--border); border-radius:10px; padding:8px 9px; background:#fff; font-size:12px; resize:vertical; }
.offer-item-main { display:grid; grid-template-columns: minmax(160px, 230px) minmax(180px, 1fr); gap:8px; align-items:start; }
.offer-item-main textarea { grid-column:1 / -1; min-height:46px; }
.row-number { font-weight:900; color:var(--text); padding-top:15px !important; }
@media (max-width: 900px) { .offer-item-main { grid-template-columns: 1fr; } }
.row-total { font-weight:900; color:var(--text); text-align:right; padding-top:8px; white-space:nowrap; }
.offer-discount-box { margin-top:12px; display:flex; justify-content:space-between; gap:14px; align-items:flex-start; flex-wrap:wrap; }
.offer-discount-controls { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
.offer-discount-controls .field { min-width:170px; }
.offer-total-box { margin-left:auto; min-width:280px; background:#fff; border:1px solid var(--border2); border-radius:14px; padding:12px 14px; color:var(--text); font-size:13px; line-height:1.75; }
.offer-total-box .final-total { font-size:15px; font-weight:950; border-top:1px solid var(--border2); margin-top:6px; padding-top:6px; }
.offer-total-box .final-total span { color:var(--muted); font-size:12px; font-weight:800; }
.offer-total-box .tax-note { color:var(--muted); font-size:11.5px; line-height:1.35; margin-top:3px; }
.offer-fill-map { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:8px; margin:0 0 14px; }
.offer-fill-step { background:#fff; border:1px solid var(--border2); border-radius:14px; padding:10px 11px; min-height:74px; }
.offer-fill-step strong { display:flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:999px; background:var(--accent-soft); color:var(--accent-deep); font-size:12px; margin-bottom:6px; }
.offer-fill-step div { font-size:12px; font-weight:900; color:var(--text); line-height:1.25; }
.offer-fill-step span { display:block; font-size:11px; color:var(--muted); line-height:1.25; margin-top:2px; font-weight:700; }
.offer-section-title { display:flex; align-items:center; gap:8px; margin:14px 0 10px; color:var(--text); font-size:13px; font-weight:950; letter-spacing:-.01em; }
.offer-section-title:before { content:''; width:7px; height:24px; border-radius:999px; background:var(--accent); display:inline-block; }
.offer-mini-note { border:1px solid var(--accent-soft-2); background:var(--accent-soft); color:var(--accent-deep); border-radius:14px; padding:10px 12px; font-size:12px; font-weight:800; line-height:1.45; margin:0 0 12px; }
.offer-template-vars { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
.offer-template-vars code { background:#fff; border:1px solid var(--border2); border-radius:999px; padding:4px 8px; color:var(--muted); font-size:11px; }
.form-actions { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; margin-top:14px; }
.form-actions .right { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:38px; border-radius:12px; padding:0 13px; border:1px solid var(--border); background:#fff; color:var(--text); font-size:13px; font-weight:900; text-decoration:none; cursor:pointer; white-space:nowrap; }
.btn:hover { border-color:var(--accent); color:var(--accent-deep); }
.btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn.primary:hover { background:var(--accent-strong); color:#fff; }
.btn.dark { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn.danger { color:var(--danger); border-color:rgba(180,35,24,.28); background:#fff; }
.btn.small { min-height:32px; padding:0 10px; font-size:12px; border-radius:10px; }
.btn.ghost { background:transparent; }
.docs-list { display:grid; gap:10px; }
.doc-row { background:#fff; border:1px solid var(--border); border-radius:16px; box-shadow:var(--shadow); padding:13px 14px; display:grid; grid-template-columns:minmax(260px,1.2fr) minmax(150px,.45fr) minmax(150px,.45fr) minmax(120px,.35fr) auto; gap:12px; align-items:center; }
.doc-title { font-size:14px; font-weight:950; color:var(--text); overflow-wrap:anywhere; }
.doc-meta { color:var(--muted); font-size:12px; margin-top:4px; line-height:1.35; }
.badge { display:inline-flex; align-items:center; justify-content:center; border-radius:999px; padding:6px 9px; font-size:11px; font-weight:900; border:1px solid var(--border2); background:var(--surface-soft); color:var(--muted); white-space:nowrap; }
.badge.draft { background:var(--warning-soft); color:var(--warning); border-color:rgba(154,103,0,.18); }
.badge.issued { background:var(--success-soft); color:var(--success); border-color:rgba(31,111,84,.18); }
.badge.cancelled { background:var(--danger-soft); color:var(--danger); border-color:rgba(180,35,24,.16); }
.doc-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
.empty-state { padding:22px; text-align:center; color:var(--muted); font-weight:800; border:1px dashed var(--border); border-radius:16px; background:var(--surface-soft); }
.pagination { display:flex; gap:6px; justify-content:flex-end; align-items:center; flex-wrap:wrap; margin-top:12px; }
@media (max-width: 980px) {
    .filter-form, .offer-form-grid { grid-template-columns:1fr; }
    .field.span2 { grid-column:1; }
    .doc-row { grid-template-columns:1fr; }
    .doc-actions { justify-content:flex-start; }
    .offer-hero { padding:18px; }
    .offer-fill-map { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('oferte', $isAdmin); ?>

    <main class="main">
        <div class="content">
            <?php if ($errorMessage): ?>
                <div class="alert error"><?= pz_offer_h($errorMessage) ?></div>
            <?php endif; ?>

            <?php if (!empty($_GET['created']) || !empty($_GET['saved'])): ?>
                <div class="alert success">Oferta a fost salvata.</div>
            <?php endif; ?>

            <?php if (!empty($_GET['new']) || $editingDocument): ?>
                <section class="panel" id="offerFormPanel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title"><?= $editingDocument ? 'Editează oferta draft' : 'Ofertă nouă' ?></div>
                            <div class="panel-subtitle">Completează oferta in ordinea șablonului: date, client, servicii, discount si conditii de plata.</div>
                        </div>
                        <a class="btn small" href="oferte.php">Inchide formularul</a>
                    </div>
                    <div class="panel-body">
                        <form method="post" id="offerForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="document_id" value="<?= (int)($formDocument['id'] ?? 0) ?>">
                        <input type="hidden" name="offer_intro" value="<?= pz_offer_h($formOfferIntro) ?>">
                        <input type="hidden" name="delivery_terms" value="<?= pz_offer_h($formDeliveryTerms) ?>">
                        <input type="hidden" name="offer_footer" value="<?= pz_offer_h($formOfferFooter) ?>">

                            <div class="offer-mini-note">
                                Completează oferta in aceeași ordine in care apare in șablon: denumire, data/numar/valabilitate, client, servicii cu descriere, discount, total fără TVA, conditii de plata si semnaturi.
                                <div class="offer-template-vars">
                                    <code>{{document_title}}</code><code>{{client_block}}</code><code>{{items_table}}</code><code>{{discount_block}}</code><code>{{total_without_vat}}</code>
                                </div>
                            </div>

                            <div class="offer-fill-map" aria-label="Ordine completare oferta">
                                <div class="offer-fill-step"><strong>1</strong><div>Date oferta</div><span>Denumire, data, valabilitate, moneda</span></div>
                                <div class="offer-fill-step"><strong>2</strong><div>Client</div><span>Beneficiar</span></div>
                                <div class="offer-fill-step"><strong>3</strong><div>Servicii</div><span>Nomenclator, descriere, pret, cantitate</span></div>
                                <div class="offer-fill-step"><strong>4</strong><div>Discount si total</div><span>Subtotal, discount, total fără TVA</span></div>
                            </div>

                            <div class="offer-section-title">1. Date oferta si beneficiar</div>
                            <div class="offer-form-grid">
                                <div class="field span2">
                                    <label>Client *</label>
                                    <input type="hidden" name="client_id" id="clientSelect" required value="<?= (int)($formDocument['client_id'] ?? 0) ?>" data-selected="<?= (int)($formDocument['client_id'] ?? 0) ?>">
                                    <div class="pz-autocomplete" id="clientAutocomplete">
                                        <input type="text" class="pz-autocomplete-input" id="clientSearchInput" placeholder="Caută după nume client, CUI, telefon, reprezentant…" autocomplete="off" autofocus>
                                        <button type="button" class="pz-autocomplete-clear" id="clientClearBtn" title="Șterge">&times;</button>
                                        <div class="pz-autocomplete-selected" id="clientSelectedBox">
                                            <div>
                                                <div class="ps-name"></div>
                                                <div class="ps-meta"></div>
                                            </div>
                                            <button type="button" class="ps-clear" onclick="pzClientClear()" title="Schimba clientul">&times;</button>
                                        </div>
                                        <div class="pz-autocomplete-results" id="clientResults" role="listbox"></div>
                                    </div>
                                    <div class="client-help" id="clientHelp">Tasteaza minimum 2 caractere pentru cautare.</div>
                                </div>

                                <div class="field">
                                    <label>Șablon</label>
                                    <select name="template_id">
                                        <?php foreach ($templates as $template): ?>
                                            <option value="<?= (int)$template['id'] ?>" <?= (int)($formDocument['template_id'] ?? 0) === (int)$template['id'] ? 'selected' : '' ?>>
                                                <?= pz_offer_h($template['name']) ?><?= !empty($template['is_default']) ? ' - implicit' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="field">
                                    <label>Denumire oferta</label>
                                    <input type="text" name="title" value="<?= pz_offer_h($formDocument['title'] ?? '') ?>" placeholder="ex: Oferta servicii DDD trimestriale">
                                </div>

                                <div class="field">
                                    <label>Data oferta</label>
                                    <input type="date" name="document_date" value="<?= pz_offer_h($formDocument['document_date'] ?? date('Y-m-d')) ?>">
                                </div>

                                <div class="field">
                                    <label>Valabilitate (zile)</label>
                                    <input type="number" step="1" min="1" name="valid_days" id="validDays" value="<?= pz_offer_h($formValidDays) ?>">
                                </div>

                                <div class="field">
                                    <label>Moneda</label>
                                    <select name="currency" id="currency">
                                        <?php foreach (['RON', 'EUR', 'USD'] as $currency): ?>
                                            <option value="<?= pz_offer_h($currency) ?>" <?= ($formDocument['currency'] ?? 'RON') === $currency ? 'selected' : '' ?>><?= pz_offer_h($currency) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                            </div>

                            <div class="offer-section-title">2. Servicii ofertate si descrieri</div>
                            <div class="panel" style="margin-top:10px;">
                                <div class="panel-head">
                                    <div>
                                        <div class="panel-title">Servicii ofertate</div>
                                        <div class="panel-subtitle">Alege din nomenclator sau scrie manual. Descrierea se precompleteaza din serviciu si rămâne editabila.</div>
                                    </div>
                                    <button class="btn small primary" type="button" onclick="addItemRow()">+ Adaugă serviciu</button>
                                </div>
                                <div class="panel-body">
                                    <div class="items-wrap">
                                        <table class="items-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:70px;">Nr. crt.</th>
                                                    <th>Denumire</th>
                                                    <th style="width:90px;">Cant.</th>
                                                    <th style="width:95px;">U.M.</th>
                                                    <th style="width:130px;">Pret unitar</th>
                                                    <th style="width:140px;">Valoare totala</th>
                                                    <th style="width:70px;"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="itemsBody">
                                                <?php foreach ($editingItems as $index => $item): ?>
                                                    <tr class="item-row">
                                                        <td class="row-number center"><?= (int)$index + 1 ?></td>
                                                        <td>
                                                            <div class="offer-item-main">
                                                                <select name="items[<?= (int)$index ?>][service_id]" class="service-select" onchange="syncServiceName(this)">
                                                                    <option value="">Alege din nomenclator</option>
                                                                    <?php foreach ($services as $service): ?>
                                                                        <option value="<?= (int)$service['id'] ?>" data-name="<?= pz_offer_h($service['name']) ?>" data-description="<?= pz_offer_h($service['description'] ?? '') ?>" <?= (int)($item['service_id'] ?? 0) === (int)$service['id'] ? 'selected' : '' ?>><?= pz_offer_h($service['name']) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <input type="text" name="items[<?= (int)$index ?>][service_name]" class="service-name" value="<?= pz_offer_h($item['service_name'] ?? '') ?>" placeholder="Denumire serviciu">
                                                                <textarea name="items[<?= (int)$index ?>][description]" class="service-description" placeholder="Descriere serviciu - apare sub denumire in oferta"><?= pz_offer_h($item['description'] ?? '') ?></textarea>
                                                            </div>
                                                        </td>
                                                        <td><input type="number" step="0.001" min="0" name="items[<?= (int)$index ?>][quantity]" class="qty" value="<?= pz_offer_h($item['quantity'] ?? 1) ?>" oninput="recalculateRows()"></td>
                                                        <td><select name="items[<?= (int)$index ?>][unit]" class="unit-select"><?= pz_offer_unit_options($item['unit'] ?? 'buc') ?></select></td>
                                                        <td><input type="number" step="0.01" min="0" name="items[<?= (int)$index ?>][unit_price]" class="price" value="<?= pz_offer_h($item['unit_price'] ?? 0) ?>" oninput="recalculateRows()"></td>
                                                        <td>
                                                            <input type="hidden" name="items[<?= (int)$index ?>][total_price]" class="line-total-input" value="<?= pz_offer_h($item['total_price'] ?? 0) ?>">
                                                            <div class="row-total">0.00</div>
                                                        </td>
                                                        <td><button type="button" class="btn small danger" onclick="removeItemRow(this)">Șterge</button></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="offer-section-title">3. Discount si total fără TVA</div>
                                    <div class="offer-discount-box">
                                        <div class="offer-discount-controls">
                                            <div class="field">
                                                <label>Discount</label>
                                                <select name="discount_type" id="discountType">
                                                    <option value="none" <?= $formDiscountType === 'none' ? 'selected' : '' ?>>Fara discount</option>
                                                    <option value="percent" <?= $formDiscountType === 'percent' ? 'selected' : '' ?>>Procentual (%)</option>
                                                    <option value="value" <?= $formDiscountType === 'value' ? 'selected' : '' ?>>Valoric</option>
                                                </select>
                                            </div>
                                            <div class="field">
                                                <label>Valoare discount</label>
                                                <input type="number" step="0.01" min="0" name="discount_value" id="discountValue" value="<?= pz_offer_h($formDiscountValue) ?>">
                                            </div>
                                        </div>
                                        <div class="offer-total-box">
                                            <div>Subtotal servicii: <strong id="subtotalTotal">0.00 RON</strong></div>
                                            <div>Discount: <strong id="discountAmount">0.00 RON</strong></div>
                                            <div class="final-total">Total oferta: <strong id="grandTotal">0.00 RON</strong> <span>fără TVA</span></div>
                                            <div class="tax-note">Toate preturile sunt exprimate fără TVA.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="offer-section-title">4. Conditii comerciale si observatii</div>
                            <div class="offer-form-grid">
                                <div class="field span2">
                                    <label>Conditii de plata</label>
                                    <input type="text" name="payment_terms" value="<?= pz_offer_h($formPaymentTerms) ?>" placeholder="ex: 5 zile de la emiterea facturii">
                                    <div class="client-help">Apare in șablon prin {{payment_terms}}.</div>
                                </div>
                                <div class="field span2">
                                    <label>Observații oferta</label>
                                    <textarea name="notes" placeholder="Observații vizibile in document prin {{notes}}"><?= pz_offer_h($formNotes) ?></textarea>
                                </div>
                                <div class="field full">
                                    <label>Note interne</label>
                                    <textarea name="internal_notes" placeholder="Nu apar in document dacă șablonul nu folosește {{internal_notes}}."><?= pz_offer_h($formDocument['internal_notes'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <div class="form-actions">
                                <div>
                                    <?php if ($editingDocument): ?>
                                        <a class="btn" href="document_view.php?id=<?= (int)$editingDocument['id'] ?>">Previzualizare</a>
                                    <?php endif; ?>
                                </div>
                                <div class="right">
                                    <button class="btn" type="submit" name="action" value="save_draft">Salvează draft</button>
                                    <button class="btn primary" type="submit" name="action" value="issue" onclick="return confirm('Emiti oferta si aloci numar? După emitere documentul se blocheaza.');">Emite oferta</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Lista oferte</div>
                        <div class="panel-subtitle">Caută după numar, client, CUI sau titlu.</div>
                    </div>
                
                    <a class="btn primary" href="oferte.php?new=1<?= !empty($filters['client_id']) ? '&client_id=' . (int)$filters['client_id'] : '' ?>">+ Ofertă nouă</a>
                </div>
                <div class="panel-body">
                    <form class="filter-form" method="get">
                        <?php if (!empty($filters['client_id'])): ?>
                            <input type="hidden" name="client_id" value="<?= (int)$filters['client_id'] ?>">
                        <?php endif; ?>
                        <div class="field">
                            <label>Căutare</label>
                            <input type="text" name="q" value="<?= pz_offer_h($filters['q']) ?>" placeholder="Client, CUI, numar oferta">
                        </div>
                        <div class="field">
                            <label>Status</label>
                            <select name="status">
                                <option value="">Toate</option>
                                <?php foreach (['draft' => 'Draft', 'issued' => 'Emise', 'cancelled' => 'Anulate'] as $value => $label): ?>
                                    <option value="<?= pz_offer_h($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= pz_offer_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Pe pagina</label>
                            <select name="per_page">
                                <?php foreach ([20, 50, 100] as $value): ?>
                                    <option value="<?= (int)$value ?>" <?= $perPage === $value ? 'selected' : '' ?>><?= (int)$value ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <button class="btn primary" type="submit">Filtreaza</button>
                        </div>
                    </form>
                </div>
            </section>

            <?php if (!$documents): ?>
                <div class="empty-state">Nu există oferte pentru filtrele selectate.</div>
            <?php else: ?>
                <div class="docs-list">
                    <?php foreach ($documents as $doc): ?>
                        <article class="doc-row">
                            <div>
                                <div class="doc-title">
                                    <?= pz_offer_h($doc['document_number'] ?: 'Draft') ?> - <?= pz_offer_h($doc['client_name_snapshot'] ?: 'Client nespecificat') ?>
                                </div>
                                <div class="doc-meta">
                                    <?= pz_offer_h($doc['title'] ?: 'Oferta') ?><br>
                                    CUI: <?= pz_offer_h($doc['client_identifier_snapshot'] ?: '-') ?>
                                </div>
                            </div>
                            <div>
                                <span class="badge <?= pz_offer_h(pz_offer_status_class((string)$doc['status'])) ?>"><?= pz_offer_h(pz_offer_status_label((string)$doc['status'])) ?></span>
                            </div>
                            <div class="doc-meta">
                                Data: <strong><?= pz_offer_h(pz_offer_date_ro($doc['document_date'] ?? null)) ?></strong><br>
                                ID: <?= (int)$doc['id'] ?>
                            </div>
                            <div class="doc-meta">
                                Total fără TVA<br>
                                <strong><?= pz_offer_h(pz_offer_money($doc['total_amount'] ?? 0, $doc['currency'] ?? 'RON')) ?></strong>
                            </div>
                            <div class="doc-actions">
                                <a class="btn small" href="document_view.php?id=<?= (int)$doc['id'] ?>">Vezi</a>
                                <?php if (($doc['status'] ?? '') === 'draft'): ?>
                                    <a class="btn small" href="oferte.php?edit=<?= (int)$doc['id'] ?>">Editează</a>
                                <?php endif; ?>
                                <a class="btn small" href="document_pdf.php?id=<?= (int)$doc['id'] ?>&mode=inline" target="_blank">PDF</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a class="btn small" href="<?= pz_offer_h(pz_offer_current_url(['page' => $page - 1])) ?>">Înapoi</a>
                    <?php endif; ?>
                    <span class="badge">Pagina <?= (int)$page ?> / <?= (int)$totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a class="btn small" href="<?= pz_offer_h(pz_offer_current_url(['page' => $page + 1])) ?>">Înainte</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
const clientsData = <?= json_encode($clientsForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const locationsData = <?= json_encode($locationsForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const servicesData = <?= json_encode($servicesForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function normalizeText(value) {
    return String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

/* === AUTOCOMPLETE CLIENT - cautare smart (acelasi pattern ca PV) === */
let pzClientActiveIndex = -1;
let pzClientCurrentResults = [];

function pzNormalize(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}
function escapeHtml(str) {
    return String(str || '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
}
function pzClientHighlight(text, query) {
    if (!query) return escapeHtml(text);
    const norm = pzNormalize(text);
    const qNorm = pzNormalize(query);
    const idx = norm.indexOf(qNorm);
    if (idx < 0) return escapeHtml(text);
    return escapeHtml(text.slice(0, idx)) + '<mark>' + escapeHtml(text.slice(idx, idx + query.length)) + '</mark>' + escapeHtml(text.slice(idx + query.length));
}
function pzClientSearch(query) {
    const q = pzNormalize(query);
    if (q.length < 2) return [];
    const results = [];
    for (const c of clientsData) {
        const haystack = pzNormalize((c.name || '') + ' ' + (c.fiscal_code || '') + ' ' + (c.phone || '') + ' ' + (c.representative || '') + ' ' + (c.email || ''));
        if (haystack.indexOf(q) >= 0) {
            results.push(c);
            if (results.length >= 30) break;
        }
    }
    results.sort((a, b) => {
        const aStarts = pzNormalize(a.name).startsWith(q) ? 0 : 1;
        const bStarts = pzNormalize(b.name).startsWith(q) ? 0 : 1;
        if (aStarts !== bStarts) return aStarts - bStarts;
        return pzNormalize(a.name).localeCompare(pzNormalize(b.name));
    });
    return results;
}
function pzRenderClientResults(results, query) {
    const container = document.getElementById('clientResults');
    if (!container) return;
    pzClientCurrentResults = results;
    pzClientActiveIndex = -1;
    if (!results.length) {
        container.innerHTML = '<div class="pz-autocomplete-empty">Niciun client gasit pentru "' + escapeHtml(query) + '"</div>';
        return;
    }
    container.innerHTML = results.map((c, i) => {
        const meta = [c.fiscal_code ? 'CUI ' + escapeHtml(c.fiscal_code) : '', c.representative ? escapeHtml(c.representative) : '', c.phone ? escapeHtml(c.phone) : ''].filter(Boolean).join(' · ');
        return '<div class="pz-autocomplete-result" data-index="' + i + '" onclick="pzClientPick(' + i + ')"><div class="ar-name">' + pzClientHighlight(c.name || '', query) + '</div>' + (meta ? '<div class="ar-meta">' + meta + '</div>' : '') + '</div>';
    }).join('');
}
function pzClientPick(index) {
    const c = pzClientCurrentResults[index];
    if (c) pzClientSetSelected(c);
}
function pzClientSetSelected(client) {
    const wrap = document.getElementById('clientAutocomplete');
    const hidden = document.getElementById('clientSelect');
    const selectedBox = document.getElementById('clientSelectedBox');
    if (!wrap || !hidden || !selectedBox) return;
    hidden.value = String(client.id);
    hidden.dataset.selected = String(client.id);
    wrap.classList.add('has-value');
    wrap.classList.remove('is-open');
    selectedBox.querySelector('.ps-name').textContent = client.name || 'Client';
    const metaParts = [];
    if (client.fiscal_code) metaParts.push('CUI ' + client.fiscal_code);
    if (client.representative) metaParts.push(client.representative);
    if (client.phone) metaParts.push(client.phone);
    selectedBox.querySelector('.ps-meta').textContent = metaParts.join(' · ');
    updateClientHelp(client);
    populateLocationsSmart();
}
function pzClientClear() {
    const wrap = document.getElementById('clientAutocomplete');
    const hidden = document.getElementById('clientSelect');
    const input = document.getElementById('clientSearchInput');
    if (!wrap || !hidden) return;
    hidden.value = '';
    hidden.dataset.selected = '0';
    wrap.classList.remove('has-value');
    if (input) { input.value = ''; input.focus(); }
    document.getElementById('clientResults').innerHTML = '';
    populateLocationsSmart();
}
function initClientAutocomplete() {
    const wrap = document.getElementById('clientAutocomplete');
    const input = document.getElementById('clientSearchInput');
    const hidden = document.getElementById('clientSelect');
    if (!wrap || !input || !hidden) return;
    const initialId = Number(hidden.value || hidden.dataset.selected || 0);
    if (initialId > 0) {
        const c = clientsData.find(x => Number(x.id) === initialId);
        if (c) pzClientSetSelected(c);
    }
    let debounceT;
    input.addEventListener('input', () => {
        clearTimeout(debounceT);
        debounceT = setTimeout(() => {
            const q = input.value.trim();
            if (q.length < 2) { wrap.classList.remove('is-open'); document.getElementById('clientResults').innerHTML = ''; return; }
            pzRenderClientResults(pzClientSearch(q), q);
            wrap.classList.add('is-open');
        }, 150);
    });
    input.addEventListener('keydown', (e) => {
        const results = pzClientCurrentResults;
        if (e.key === 'ArrowDown') { e.preventDefault(); pzClientActiveIndex = Math.min(pzClientActiveIndex + 1, results.length - 1); pzHighlightActive(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); pzClientActiveIndex = Math.max(pzClientActiveIndex - 1, 0); pzHighlightActive(); }
        else if (e.key === 'Enter') { if (pzClientActiveIndex >= 0) { e.preventDefault(); pzClientPick(pzClientActiveIndex); } }
        else if (e.key === 'Escape') { wrap.classList.remove('is-open'); }
    });
    document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) wrap.classList.remove('is-open'); });
}
function pzHighlightActive() {
    const container = document.getElementById('clientResults');
    if (!container) return;
    container.querySelectorAll('.pz-autocomplete-result').forEach(el => el.classList.remove('is-active'));
    const target = container.querySelector('[data-index="' + pzClientActiveIndex + '"]');
    if (target) { target.classList.add('is-active'); target.scrollIntoView({block: 'nearest'}); }
}

/* === LOCATIE smart - 0/1/2+ locații === */
function populateLocationsSmart() {
    const clientSelect = document.getElementById('clientSelect');
    const locationSelect = document.getElementById('locationSelect');
    const locationInfo = document.getElementById('locationInfo');
    const locationInfoText = document.getElementById('locationInfoText');
    const help = document.getElementById('locationHelp');
    if (!clientSelect || !locationSelect) return;

    const clientId = Number(clientSelect.value || 0);
    if (!clientId) {
        locationSelect.style.display = 'none';
        if (locationInfo) locationInfo.style.display = 'none';
        if (help) help.textContent = 'Selectează un client mai intai.';
        return;
    }
    const client = clientsData.find(item => Number(item.id) === clientId);
    const locations = locationsData.filter(loc => Number(loc.client_id) === clientId);
    const selectedId = String(locationSelect.dataset.selected || locationSelect.value || '');

    if (locations.length === 0) {
        locationSelect.style.display = 'none';
        if (locationInfo) {
            locationInfo.style.display = 'flex';
            locationInfoText.textContent = 'Sediu social / domiciliu' + (client && client.address ? ' - ' + client.address : '');
        }
        locationSelect.value = '';
        if (help) help.textContent = 'Clientul are doar sediul social. Oferta folosește aceasta adresa.';
    } else if (locations.length === 1 && !selectedId) {
        const loc = locations[0];
        locationSelect.style.display = 'none';
        locationSelect.innerHTML = '<option value="' + loc.id + '" selected>' + (loc.location_name || 'Punct de lucru') + '</option>';
        locationSelect.value = String(loc.id);
        if (locationInfo) {
            locationInfo.style.display = 'flex';
            locationInfoText.textContent = (loc.location_name || 'Punct de lucru') + (loc.address ? ' - ' + loc.address : '');
        }
        if (help) help.textContent = 'Singura locație a clientului. Click aici pentru a folosi sediul social in schimb.';
    } else {
        const options = [{value: '', text: client && client.address ? 'Sediu social / domiciliu - ' + client.address : 'Sediu social / domiciliu'}];
        locations.forEach(loc => options.push({value: String(loc.id), text: (loc.location_name || 'Punct de lucru') + (loc.address ? ' - ' + loc.address : '')}));
        if (locationInfo) locationInfo.style.display = 'none';
        locationSelect.style.display = 'block';
        locationSelect.innerHTML = '';
        options.forEach(opt => {
            const o = document.createElement('option');
            o.value = opt.value; o.textContent = opt.text;
            if (String(opt.value) === selectedId) o.selected = true;
            locationSelect.appendChild(o);
        });
        if (help) help.textContent = 'Clientul are ' + locations.length + ' locații. Alege una sau lasa pe sediul social.';
    }
}

/* === Wrappers vechi pentru compatibilitate === */
function populateClients() { initClientAutocomplete(); }
function populateLocations() { populateLocationsSmart(); }

function updateClientHelp(client) {
    const help = document.getElementById('clientHelp');
    if (!help) return;
    if (!client) {
        help.textContent = 'Selectează clientul din lista de mai jos.';
        return;
    }
    const parts = [];
    if (client.fiscal_code) parts.push('CUI: ' + client.fiscal_code);
    if (client.representative) parts.push('Reprezentant: ' + client.representative);
    if (client.email) parts.push('Email: ' + client.email);
    if (client.phone) parts.push('Telefon: ' + client.phone);
    help.textContent = parts.length ? parts.join(' | ') : 'Client selectat.';
}

function populateRowLocations() {
    const clientSelect = document.getElementById('clientSelect');
    const locationSelect = document.getElementById('locationSelect');
    const clientId = clientSelect ? Number(clientSelect.value || 0) : 0;
    const mainLocation = locationSelect ? Number(locationSelect.value || 0) : 0;
    const rowSelects = document.querySelectorAll('.row-location');

    rowSelects.forEach(select => {
        const selected = Number(select.dataset.selected || select.value || mainLocation || 0);
        select.innerHTML = '<option value="">Locatia principala</option>';
        locationsData.filter(loc => Number(loc.client_id) === clientId).forEach(loc => {
            const option = document.createElement('option');
            option.value = loc.id;
            option.textContent = loc.location_name || 'Punct de lucru';
            if (Number(loc.id) === selected) option.selected = true;
            select.appendChild(option);
        });
        select.dataset.selected = select.value || '0';
    });
}

function serviceOptionsHtml() {
    let html = '<option value="">Alege din nomenclator</option>';
    servicesData.forEach(service => {
        html += '<option value="' + service.id + '" data-name="' + escapeHtml(service.name) + '" data-description="' + escapeHtml(service.description || '') + '">' + escapeHtml(service.name) + '</option>';
    });
    return html;
}

function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function(char) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char];
    });
}

function nextItemIndex() {
    return document.querySelectorAll('#itemsBody .item-row').length;
}

function addItemRow() {
    const body = document.getElementById('itemsBody');
    if (!body) return;
    const i = nextItemIndex();
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="row-number center">${i + 1}</td>
        <td>
            <div class="offer-item-main">
                <select name="items[${i}][service_id]" class="service-select" onchange="syncServiceName(this)">${serviceOptionsHtml()}</select>
                <input type="text" name="items[${i}][service_name]" class="service-name" placeholder="Denumire serviciu">
                <textarea name="items[${i}][description]" class="service-description" placeholder="Descriere serviciu - apare sub denumire in oferta"></textarea>
            </div>
        </td>
        <td><input type="number" step="0.001" min="0" name="items[${i}][quantity]" class="qty" value="1" oninput="recalculateRows()"></td>
        <td><select name="items[${i}][unit]" class="unit-select"><option value="buc" selected>Bucata</option><option value="mp">Metru patrat</option></select></td>
        <td><input type="number" step="0.01" min="0" name="items[${i}][unit_price]" class="price" value="0" oninput="recalculateRows()"></td>
        <td><input type="hidden" name="items[${i}][total_price]" class="line-total-input" value="0"><div class="row-total">0.00</div></td>
        <td><button type="button" class="btn small danger" onclick="removeItemRow(this)">Șterge</button></td>
    `;
    body.appendChild(tr);
    recalculateRows();
}

function removeItemRow(button) {
    const rows = document.querySelectorAll('#itemsBody .item-row');
    if (rows.length <= 1) {
        const row = button.closest('tr');
        if (row) {
            row.querySelectorAll('input, textarea').forEach(input => {
                if (input.classList.contains('qty')) input.value = '1';
                else if (input.classList.contains('price') || input.classList.contains('line-total-input')) input.value = '0';
                else input.value = '';
            });
            row.querySelectorAll('select').forEach(select => {
                if (select.classList.contains('unit-select')) select.value = 'buc';
                else select.value = '';
            });
            recalculateRows();
        }
        return;
    }
    button.closest('tr').remove();
    recalculateRows();
}

function syncServiceName(select) {
    const row = select.closest('tr');
    const input = row ? row.querySelector('.service-name') : null;
    const description = row ? row.querySelector('.service-description') : null;
    const selected = select.options[select.selectedIndex];
    if (input && selected && selected.dataset.name && !input.value) {
        input.value = selected.dataset.name;
    }
    if (description && selected && selected.dataset.description && !description.value) {
        description.value = selected.dataset.description;
    }
}

function updateRowNumbers() {
    document.querySelectorAll('#itemsBody .item-row .row-number').forEach((cell, idx) => {
        cell.textContent = String(idx + 1);
    });
}

function recalculateRows() {
    updateRowNumbers();
    const currency = document.getElementById('currency') ? document.getElementById('currency').value : 'RON';
    let subtotal = 0;
    document.querySelectorAll('#itemsBody .item-row').forEach(row => {
        const qty = parseFloat((row.querySelector('.qty') || {}).value || '0') || 0;
        const price = parseFloat((row.querySelector('.price') || {}).value || '0') || 0;
        const line = Math.round(qty * price * 100) / 100;
        subtotal += line;
        const hidden = row.querySelector('.line-total-input');
        const label = row.querySelector('.row-total');
        if (hidden) hidden.value = line.toFixed(2);
        if (label) label.textContent = line.toFixed(2) + ' ' + currency;
    });

    const discountType = document.getElementById('discountType') ? document.getElementById('discountType').value : 'none';
    const discountRaw = document.getElementById('discountValue') ? parseFloat(document.getElementById('discountValue').value || '0') || 0 : 0;
    let discountAmount = 0;
    if (discountType === 'percent') {
        discountAmount = subtotal * Math.min(Math.max(discountRaw, 0), 100) / 100;
    } else if (discountType === 'value') {
        discountAmount = Math.min(Math.max(discountRaw, 0), subtotal);
    }
    discountAmount = Math.round(discountAmount * 100) / 100;
    const total = Math.max(0, Math.round((subtotal - discountAmount) * 100) / 100);

    const subtotalEl = document.getElementById('subtotalTotal');
    const discountEl = document.getElementById('discountAmount');
    const grand = document.getElementById('grandTotal');
    if (subtotalEl) subtotalEl.textContent = subtotal.toFixed(2) + ' ' + currency;
    if (discountEl) discountEl.textContent = discountAmount.toFixed(2) + ' ' + currency;
    if (grand) grand.textContent = total.toFixed(2) + ' ' + currency;
}

document.addEventListener('DOMContentLoaded', function() {
    const currency = document.getElementById('currency');
    const clearBtn = document.getElementById('clientClearBtn');

    if (currency) currency.addEventListener('change', recalculateRows);
    const discountType = document.getElementById('discountType');
    const discountValue = document.getElementById('discountValue');
    if (discountType) discountType.addEventListener('change', recalculateRows);
    if (discountValue) discountValue.addEventListener('input', recalculateRows);
    if (clearBtn) clearBtn.addEventListener('click', pzClientClear);

    initClientAutocomplete();
    populateLocationsSmart();
    recalculateRows();

    const clientSearchInput = document.getElementById('clientSearchInput');
    const clientHidden = document.getElementById('clientSelect');
    if (clientSearchInput && clientHidden && Number(clientHidden.value || 0) === 0) {
        setTimeout(() => clientSearchInput.focus(), 120);
    }
});
</script>
</body>
</html>
