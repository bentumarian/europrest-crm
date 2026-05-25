<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/document_core.php';
require_once __DIR__ . '/document_tokens.php';
require_once __DIR__ . '/lib/contract_flow_lib.php';

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
    error_log('PestZone contracts init error: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Helpers locale pentru pagina Contracte
|--------------------------------------------------------------------------
*/
function pz_contract_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pz_contract_str($value, int $max = 0): string {
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

function pz_contract_decimal($value, float $default = 0.0): float {
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_string($value)) {
        $value = str_replace([' ', ','], ['', '.'], $value);
    }
    return is_numeric($value) ? (float)$value : $default;
}

function pz_contract_money($value, string $currency = 'RON'): string {
    return number_format((float)$value, 2, ',', '.') . ' ' . $currency;
}

function pz_contract_date_ro(?string $date): string {
    if (!$date) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : '-';
}

function pz_contract_status_label(string $status): string {
    return [
        'draft' => 'Draft',
        'issued' => 'Emis',
        'cancelled' => 'Anulat',
    ][$status] ?? $status;
}

function pz_contract_status_class(string $status): string {
    return [
        'draft' => 'draft',
        'issued' => 'issued',
        'cancelled' => 'cancelled',
    ][$status] ?? 'draft';
}

function pz_contract_current_url(array $extra = []): string {
    $params = $_GET;
    foreach ($extra as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'contracts.php' . ($params ? '?' . http_build_query($params) : '');
}

function pz_contract_fetch_clients(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'clients')) {
        return [];
    }

    $stmt = $pdo->query("\n        SELECT *\n        FROM clients\n        WHERE COALESCE(active, 1) = 1\n        ORDER BY name ASC\n        LIMIT 1500\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_contract_fetch_locations(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'client_locations')) {
        return [];
    }

    $stmt = $pdo->query("\n        SELECT id, client_id, location_name, address, contact_person, phone,\n               surface_value, surface_unit, active, sort_order\n        FROM client_locations\n        WHERE COALESCE(active, 1) = 1\n        ORDER BY client_id ASC, sort_order ASC, location_name ASC\n        LIMIT 5000\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_contract_fetch_services(PDO $pdo): array {
    if (!pzdoc_table_exists($pdo, 'services')) {
        return [];
    }

    $stmt = $pdo->query("\n        SELECT id, name, description, active, sort_order\n        FROM services\n        WHERE COALESCE(active, 1) = 1\n        ORDER BY sort_order ASC, name ASC\n        LIMIT 500\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_contract_fetch_templates(PDO $pdo): array {
    $stmt = $pdo->prepare("\n        SELECT id, name, is_default\n        FROM document_templates\n        WHERE document_type = 'contract'\n          AND is_active = 1\n        ORDER BY is_default DESC, name ASC\n    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_contract_locations_by_id(array $locations): array {
    $map = [];
    foreach ($locations as $location) {
        $map[(int)$location['id']] = $location;
    }
    return $map;
}

function pz_contract_clients_by_id(array $clients): array {
    $map = [];
    foreach ($clients as $client) {
        $map[(int)$client['id']] = $client;
    }
    return $map;
}

function pz_contract_build_client_address(array $client): string {
    $line = pz_contract_str($client['billing_address_line'] ?? '');
    $county = pz_contract_str($client['billing_county'] ?? '');
    $city = pz_contract_str($client['billing_city'] ?? '');
    $sector = pz_contract_str($client['billing_sector'] ?? '');
    $country = pz_contract_str($client['billing_country'] ?? '');
    $postal = pz_contract_str($client['billing_postal_code'] ?? '');

    $location = trim(implode(', ', array_filter([$county, $city, $sector], static fn($value) => $value !== '')));
    $address = trim(implode(', ', array_filter([$line, $location, $country], static fn($value) => $value !== '')));

    if ($postal !== '') {
        $address .= ($address !== '' ? ', ' : '') . 'CP ' . $postal;
    }

    return $address;
}

function pz_contract_client_address(array $client): string {
    $billingAddress = pz_contract_build_client_address($client);
    if ($billingAddress !== '') {
        return $billingAddress;
    }

    return pz_contract_str(($client['registered_address'] ?? '') ?: ($client['address'] ?? ''));
}

function pz_contract_payment_due_days(array $payload, int $default = 5): int {
    foreach (['payment_due_days', 'termen_plata_zile', 'payment_days', 'due_days'] as $key) {
        if (isset($payload[$key]) && (int)$payload[$key] > 0) {
            return (int)$payload[$key];
        }
    }
    $terms = trim((string)($payload['payment_terms'] ?? ''));
    if ($terms !== '' && preg_match('/\b(\d{1,3})\b/u', $terms, $m) && (int)$m[1] > 0) {
        return (int)$m[1];
    }
    return max(1, $default);
}


function pz_contract_frequency_options(): array {
    return [
        'lunar' => 'Lunar',
        'trimestrial' => 'Trimestrial',
        'semestrial' => 'Semestrial',
        'int_unica' => 'Int. unica',
    ];
}

function pz_contract_normalize_frequency($value): string {
    $v = strtolower(trim((string)$value));
    $v = str_replace(['_', '-'], ' ', $v);
    if ($v === '') {
        return 'int_unica';
    }
    if (strpos($v, 'lunar') !== false || strpos($v, 'luna') !== false) {
        return 'lunar';
    }
    if (strpos($v, 'trimes') !== false || strpos($v, '3 luni') !== false || strpos($v, 'trei luni') !== false) {
        return 'trimestrial';
    }
    if (strpos($v, 'semes') !== false || strpos($v, '6 luni') !== false || strpos($v, 'sase luni') !== false) {
        return 'semestrial';
    }
    if (strpos($v, 'unic') !== false || strpos($v, 'singur') !== false || strpos($v, 'o singura') !== false || strpos($v, 'int') !== false) {
        return 'int_unica';
    }
    return array_key_exists($v, pz_contract_frequency_options()) ? $v : 'int_unica';
}

function pz_contract_frequency_label($value): string {
    $key = pz_contract_normalize_frequency($value);
    $options = pz_contract_frequency_options();
    return $options[$key] ?? 'Int. unica';
}

function pz_contract_build_items_from_post(array $postItems, array $locationsById, array $clientsById, int $clientId, float $vatPercent, string $currency): array {
    $items = [];
    $sort = 0;
    $client = $clientsById[$clientId] ?? [];
    $clientAddress = $client ? pz_contract_client_address($client) : '';

    foreach ($postItems as $row) {
        if (!is_array($row)) {
            continue;
        }

        $serviceId = !empty($row['service_id']) ? (int)$row['service_id'] : null;
        $serviceName = pz_contract_str($row['service_name'] ?? '', 220);
        $description = pz_contract_str($row['description'] ?? '');
        $rawFrequency = trim((string)($row['frequency_text'] ?? ''));
        $frequency = $rawFrequency === '' ? '' : pz_contract_frequency_label($rawFrequency);
        $unitPrice = max(0, pz_contract_decimal($row['unit_price'] ?? 0, 0));

        if (!$serviceId && $serviceName === '' && $description === '' && $unitPrice <= 0) {
            continue;
        }

        $locationId = !empty($row['client_location_id']) ? (int)$row['client_location_id'] : null;
        $location = ($locationId && isset($locationsById[$locationId])) ? $locationsById[$locationId] : null;
        $locationName = $location ? pz_contract_str($location['location_name'] ?? '', 220) : '';
        $locationAddress = $location ? pz_contract_str($location['address'] ?? '') : '';

        $surface = max(0, pz_contract_decimal($row['quantity'] ?? 0, 0));
        $unit = pz_contract_str($row['unit'] ?? 'mp', 30) ?: 'mp';

        // In contract, cantitatea reprezinta suprafata (mp), iar pretul este pret / intervenție.
        // De aceea valoarea randului pentru totalizare este pretul intervenției, nu suprafata x pret.
        $totalPrice = $unitPrice;

        $items[] = [
            'item_type' => 'contract_service',
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'description' => $description,
            'client_location_id' => $locationId,
            'location_name' => $locationName,
            'location_address' => $locationAddress,
            'quantity' => $surface,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'vat_percent' => $vatPercent,
            'total_price' => $totalPrice,
            'currency' => $currency,
            'frequency_text' => $frequency,
            'planned_date' => !empty($row['planned_date']) ? pzdoc_date($row['planned_date']) : null,
            'sort_order' => $sort,
        ];
        $sort++;
    }

    return $items;
}

function pz_contract_build_payload_from_post(array $post, string $currency): array {
    $contractValueRaw = pz_contract_decimal($post['contract_value'] ?? 0, 0);
    $autoRenewal = !empty($post['auto_renewal']);
    $noticeDays = (int)($post['renewal_notice_days'] ?? 30);
    if ($noticeDays < 0) {
        $noticeDays = 0;
    }

    $paymentDueDays = (int)($post['payment_due_days'] ?? 5);
    if ($paymentDueDays <= 0) {
        $paymentDueDays = 5;
    }
    $paymentTerms = 'Plata se efectueaza in termen de ' . $paymentDueDays . ' zile calendaristice de la data emiterii facturii.';

    return [
        'contract_start_date' => pz_contract_str($post['contract_start_date'] ?? '', 40),
        'contract_end_date' => pz_contract_str($post['contract_end_date'] ?? '', 40),
        'contract_value' => $contractValueRaw > 0 ? pz_contract_money($contractValueRaw, $currency) : '',
        'contract_value_raw' => $contractValueRaw,
        'auto_renewal' => $autoRenewal ? '1' : '0',
        'auto_renewal_text' => $autoRenewal ? 'Contractul se reinnoieste automat, dacă niciuna dintre parti nu notifica incetarea conform termenilor contractuali.' : 'Contract cu durata fixa, fara reinnoire automata.',
        'renewal_notice_days' => $noticeDays,
        'payment_due_days' => $paymentDueDays,
        'termen_plata_zile' => $paymentDueDays,
        'payment_terms' => $paymentTerms,
        'contract_object' => pz_contract_str($post['contract_object'] ?? ''),
        'execution_terms' => pz_contract_str($post['execution_terms'] ?? ''),
        'special_clauses' => pz_contract_str($post['special_clauses'] ?? ''),
        'contract_type' => (($post['contract_type'] ?? 'recurrent') === 'execution') ? 'execution' : 'recurrent',
    ];
}

function pz_contract_redirect_with_error(string $message, int $editId = 0): void {
    $_SESSION['pz_contract_error'] = $message;
    $url = 'contracts.php';
    if ($editId > 0) {
        $url .= '?edit=' . (int)$editId;
    } else {
        $url .= '?new=1';
    }
    header('Location: ' . $url);
    exit;
}

$clients = pz_contract_fetch_clients($pdo);
$locations = pz_contract_fetch_locations($pdo);
$locationsById = pz_contract_locations_by_id($locations);
$clientsById = pz_contract_clients_by_id($clients);
$services = pz_contract_fetch_services($pdo);
$templates = pz_contract_fetch_templates($pdo);

/**
 * Notificare expirare contracte: aducem contractele active al caror end_date
 * cade in urmatoarele 30 de zile si pentru care nu exista deja un act adițional
 * emis. Folosim tabela operationala `contracts` (populata de pz_flow_sync_issued_contract).
 */
function pz_contract_fetch_expiring(PDO $pdo, int $days = 30): array
{
    if (!pzdoc_table_exists($pdo, 'contracts')) {
        return [];
    }
    try {
        $sql = "
            SELECT c.id, c.contract_number, c.end_date, c.client_id,
                   c.source_document_id, c.title,
                   cl.name AS client_name
            FROM contracts c
            LEFT JOIN clients cl ON cl.id = c.client_id
            LEFT JOIN documents adn
                ON adn.document_type = 'act_aditional'
                AND adn.status = 'issued'
                AND adn.source_document_id = c.source_document_id
            WHERE c.status = 'activ'
              AND c.end_date IS NOT NULL
              AND c.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
              AND adn.id IS NULL
            ORDER BY c.end_date ASC, c.id ASC
            LIMIT 50
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('PestZone contracts expiring fetch error: ' . $e->getMessage());
        return [];
    }
}

$expiringContracts = pz_contract_fetch_expiring($pdo, 30);

/*
|--------------------------------------------------------------------------
| POST: salvare / emitere contract
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? '';
    if (in_array($action, ['save_draft', 'issue'], true)) {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        $locationId = !empty($_POST['client_location_id']) ? (int)$_POST['client_location_id'] : null;
        $vatPercent = 0.0;
        $currency = pz_contract_str($_POST['currency'] ?? 'RON', 10) ?: 'RON';
        $contractType = (($_POST['contract_type'] ?? 'recurrent') === 'execution') ? 'execution' : 'recurrent';
        $items = pz_contract_build_items_from_post($_POST['items'] ?? [], $locationsById, $clientsById, $clientId, $vatPercent, $currency);

        if ($clientId <= 0) {
            pz_contract_redirect_with_error('Selectează clientul pentru contract.', $documentId);
        }

        if ($contractType === 'recurrent') {
            if (!$items) {
                pz_contract_redirect_with_error('Adaugă cel puțin un serviciu / locație in contract.', $documentId);
            }
            foreach ($items as $item) {
                if (empty($item['client_location_id'])) {
                    pz_contract_redirect_with_error('Selectează locatia pentru fiecare serviciu contractat. Dacă serviciul se face la sediu, adauga sediul ca locație in fișa clientului.', $documentId);
                }
                if (trim((string)($item['frequency_text'] ?? '')) === '') {
                    pz_contract_redirect_with_error('Selectează frecventa pentru fiecare serviciu contractat.', $documentId);
                }
            }
            if (!$locationId && !empty($items[0]['client_location_id'])) {
                $locationId = (int)$items[0]['client_location_id'];
            }
        } else {
            // Contract de execuție: nu folosim tabel servicii.
            $items = [];
            if (trim((string)($_POST['contract_object'] ?? '')) === '') {
                pz_contract_redirect_with_error('Completează obiectul contractului pentru contractul de execuție.', $documentId);
            }
        }

        $startDate = pz_contract_str($_POST['contract_start_date'] ?? '', 40);
        $endDate = pz_contract_str($_POST['contract_end_date'] ?? '', 40);
        if ($startDate === '') {
            pz_contract_redirect_with_error('Completează data de inceput a contractului.', $documentId);
        }
        if ($endDate !== '' && strtotime($endDate) && strtotime($startDate) && strtotime($endDate) < strtotime($startDate)) {
            pz_contract_redirect_with_error('Data de sfarsit nu poate fi înainte de data de inceput.', $documentId);
        }

        $payload = pz_contract_build_payload_from_post($_POST, $currency);
        $defaultObject = 'Prestari servicii DDD conform locațiilor, serviciilor si frecventelor agreate de parti.';
        $data = [
            'template_id' => !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null,
            'document_date' => $_POST['document_date'] ?? date('Y-m-d'),
            'document_time' => null,
            'title' => pz_contract_str($_POST['title'] ?? '', 220),
            'client_id' => $clientId,
            'client_location_id' => $locationId,
            'vat_percent' => $vatPercent,
            'currency' => $currency,
            'notes' => pz_contract_str($_POST['notes'] ?? ($payload['contract_object'] ?: $defaultObject)),
            'internal_notes' => pz_contract_str($_POST['internal_notes'] ?? ''),
            'payload_json' => $payload,
            'items' => $items,
        ];

        try {
            if ($documentId > 0) {
                $existing = pzdoc_get_document($pdo, $documentId, false);
                if (!$existing || ($existing['document_type'] ?? '') !== 'contract') {
                    throw new RuntimeException('Contract inexistent.');
                }
                pzdoc_update_document($pdo, $documentId, $data);
            } else {
                $documentId = pzdoc_create_document($pdo, 'contract', $data);
            }

            if ($action === 'issue') {
                pzdoc_issue_document($pdo, $documentId);
                if (function_exists('pz_flow_sync_issued_contract')) {
                    pz_flow_sync_issued_contract($pdo, $documentId);
                }
                header('Location: document_view.php?id=' . (int)$documentId . '&issued=1');
                exit;
            }

            header('Location: document_view.php?id=' . (int)$documentId . '&saved=1');
            exit;
        } catch (Throwable $e) {
            error_log('PestZone contract save error: ' . $e->getMessage());
            pz_contract_redirect_with_error('Contractul nu a putut fi salvat: ' . $e->getMessage(), $documentId);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Pregatire editare / lista
|--------------------------------------------------------------------------
*/
$errorMessage = $_SESSION['pz_contract_error'] ?? '';
unset($_SESSION['pz_contract_error']);

$editId = (int)($_GET['edit'] ?? 0);
$editingDocument = null;
$editingItems = [];
$editingPayload = [];

if ($editId > 0) {
    $editingDocument = pzdoc_get_document($pdo, $editId, true);
    if (!$editingDocument || ($editingDocument['document_type'] ?? '') !== 'contract') {
        $errorMessage = 'Contractul solicitat nu există.';
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
$totalRows = pzdoc_count_documents($pdo, 'contract', $filters);
$documents = pzdoc_list_documents($pdo, 'contract', $filters, $perPage, $offset);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$formPayload = $editingPayload ?: [];
$formDocument = $editingDocument ?: [
    'id' => 0,
    'template_id' => null,
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

if (!$formPayload) {
    $formPayload = [
        'contract_start_date' => date('Y-m-d'),
        'contract_end_date' => date('Y-m-d', strtotime('+12 months')),
        'contract_value_raw' => 0,
        'auto_renewal' => '1',
        'renewal_notice_days' => 30,
        'payment_due_days' => 5,
        'payment_terms' => 'Plata se efectueaza in termen de 5 zile calendaristice de la data emiterii facturii.',
        'contract_object' => 'Prestari servicii DDD conform locațiilor, serviciilor si frecventelor agreate de parti.',
        'execution_terms' => '',
        'special_clauses' => '',
        'contract_type' => 'recurrent',
    ];
}

$contractTypeValue   = (($formPayload['contract_type'] ?? 'recurrent') === 'execution') ? 'execution' : 'recurrent';
$contractObjectDDD   = 'Prestari servicii DDD conform locațiilor, serviciilor si frecventelor agreate de parti.';
$contractObjectValue = (string)($formPayload['contract_object'] ?? $contractObjectDDD);

if (!$editingItems) {
    $editingItems = [[
        'service_id' => '',
        'service_name' => '',
        'description' => '',
        'client_location_id' => $formDocument['client_location_id'] ?? '',
        'quantity' => 0,
        'unit' => 'mp',
        'unit_price' => 0,
        'total_price' => 0,
        'frequency_text' => '',
        'planned_date' => '',
    ]];
}

$clientsForJson = [];
foreach ($clients as $client) {
    $clientsForJson[] = [
        'id' => (int)$client['id'],
        'name' => (string)($client['name'] ?? ''),
        'fiscal_code' => (string)($client['fiscal_code'] ?? ''),
        'representative' => (string)($client['legal_representative_name'] ?? ''),
        'representative_role' => (string)($client['legal_representative_role'] ?? ''),
        'registry_number' => (string)($client['registry_number'] ?? ''),
        'address' => pz_contract_client_address($client),
        'billing_country' => (string)($client['billing_country'] ?? ''),
        'billing_county' => (string)($client['billing_county'] ?? ''),
        'billing_city' => (string)($client['billing_city'] ?? ''),
        'billing_sector' => (string)($client['billing_sector'] ?? ''),
        'billing_address_line' => (string)($client['billing_address_line'] ?? ''),
        'billing_postal_code' => (string)($client['billing_postal_code'] ?? ''),
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
        'surface_value' => (string)($location['surface_value'] ?? ''),
        'surface_unit' => (string)($location['surface_unit'] ?? ''),
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
<title>Contracte - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.contract-topbar { align-items:center; padding:12px 20px; }
.contract-toolbar { width:100%; display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap; }
.contract-hero {
    background: var(--pz-brand);
    color:#fff; border-radius:var(--pz-r); padding:18px 20px; box-shadow:none;
    margin-bottom:14px; display:flex; justify-content:space-between; gap:18px; flex-wrap:wrap; align-items:center;
}
.contract-hero h1 { font-size:24px; font-weight:900; letter-spacing:-.03em; margin:0; }
.contract-hero p { color:rgba(255,255,255,.72); margin:4px 0 0; max-width:900px; }
.hero-actions { display:flex; gap:8px; flex-wrap:wrap; }
.panel { background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:var(--pz-r); box-shadow:none; margin-bottom:12px; }
.panel-head { padding:14px 16px; border-bottom:1px solid var(--border2); display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; }
.panel-title { font-size:16px; font-weight:900; color:var(--text); }
.panel-subtitle { font-size:12px; color:var(--muted); margin-top:2px; }
.panel-body { padding:14px 16px; }
.alert { border-radius:var(--pz-rs); padding:10px 13px; margin-bottom:12px; font-weight:600; font-size:12.5px; }
.alert.error   { background:var(--pz-res); color:var(--pz-re); border:1px solid var(--pz-reb); }
.alert.success { background:var(--pz-grs); color:var(--pz-gr); border:1px solid var(--pz-grb); }
.filter-form { display:grid; grid-template-columns:minmax(220px,1fr) minmax(150px,.45fr) minmax(130px,.35fr) auto; gap:10px; align-items:end; }
.contract-form-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
.field label { display:block; font-size:12px; font-weight:850; color:var(--muted); margin-bottom:5px; }
.field input, .field select, .field textarea { width:100%; border:1px solid var(--pz-line); border-radius:var(--pz-rs); background:#fff; color:var(--pz-text); padding:7px 10px; font-size:12.5px; outline:none; transition:border-color .14s; }
.field input:focus, .field select:focus, .field textarea:focus { border-color:var(--pz-bl); }

/* === AUTOCOMPLETE client + locație smart === */
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
.field textarea { min-height:84px; resize:vertical; }
.field input:focus, .field select:focus, .field textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }
.field.full { grid-column:1 / -1; }
.field.span2 { grid-column:span 2; }
.checkline { display:flex; align-items:center; gap:8px; min-height:42px; padding:9px 11px; border:1px solid var(--border); border-radius:12px; background:#fff; }
.checkline input { width:auto; }
.client-help { margin-top:6px; color:var(--muted); font-size:12px; line-height:1.4; }
.items-wrap { overflow-x:auto; }
.items-table { width:100%; border-collapse:separate; border-spacing:0 8px; min-width:980px; }
.items-table th { text-align:left; font-size:11px; color:var(--muted); font-weight:900; padding:0 6px; text-transform:uppercase; letter-spacing:.04em; }
.items-table td { background:var(--surface-soft); border-top:1px solid var(--border2); border-bottom:1px solid var(--border2); padding:7px 6px; vertical-align:top; }
.items-table td:first-child { border-left:1px solid var(--border2); border-radius:12px 0 0 12px; }
.items-table td:last-child { border-right:1px solid var(--border2); border-radius:0 12px 12px 0; }
.items-table input, .items-table select { width:100%; border:1px solid var(--border); border-radius:10px; padding:8px 9px; background:#fff; font-size:12px; }
.items-table textarea { width:100%; min-height:39px; border:1px solid var(--border); border-radius:10px; padding:8px 9px; background:#fff; font-size:12px; resize:vertical; }
.row-total { font-weight:900; color:var(--text); text-align:right; padding-top:8px; white-space:nowrap; }
.form-actions { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; margin-top:14px; }
.form-actions .right { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:34px; border-radius:var(--pz-rs); padding:0 11px; border:1px solid var(--border); background:#fff; color:var(--text); font-size:12.5px; font-weight:600; text-decoration:none; cursor:pointer; white-space:nowrap; box-shadow:none; }
.btn:hover { border-color:var(--accent); color:var(--accent-deep); }
.btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn.primary:hover { background:var(--accent-strong); color:#fff; }
.btn.dark { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn.danger { color:var(--danger); border-color:rgba(180,35,24,.28); background:#fff; }
.btn.small { min-height:32px; padding:0 10px; font-size:12px; border-radius:10px; }
.btn.ghost { background:transparent; }
.docs-list { display:grid; gap:10px; }
.doc-row { background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:var(--pz-r); box-shadow:none; padding:12px 14px; display:grid; grid-template-columns:minmax(260px,1.2fr) minmax(150px,.45fr) minmax(150px,.45fr) minmax(120px,.35fr) auto; gap:12px; align-items:center; }
.doc-title { font-size:14px; font-weight:600; color:var(--pz-title); overflow-wrap:anywhere; }
.doc-meta { color:var(--muted); font-size:12px; margin-top:4px; line-height:1.35; }
.badge { display:inline-flex; align-items:center; justify-content:center; border-radius:var(--pz-rs); padding:3px 8px; font-size:11px; font-weight:600; border:1px solid var(--pz-line); background:var(--pz-soft); color:var(--pz-mu); white-space:nowrap; }
.badge.draft   { background:var(--pz-ors); color:var(--pz-or); border-color:var(--pz-orb); }
.badge.issued  { background:var(--pz-grs); color:var(--pz-gr); border-color:var(--pz-grb); }
.badge.cancelled { background:var(--pz-res); color:var(--pz-re); border-color:var(--pz-reb); }
.doc-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
.empty-state { padding:22px; text-align:center; color:var(--pz-mu); font-weight:600; border:1px dashed var(--pz-line); border-radius:var(--pz-r); background:var(--pz-soft); }
.pagination { display:flex; gap:6px; justify-content:flex-end; align-items:center; flex-wrap:wrap; margin-top:12px; }
.contract-steps { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:8px; margin-bottom:12px; }
.contract-step { border:1px solid var(--border2); background:var(--surface-soft); border-radius:14px; padding:10px 12px; }
.contract-step b { display:block; font-size:12px; color:var(--text); }
.contract-step span { display:block; font-size:11px; color:var(--muted); margin-top:2px; }
.quick-note { background:var(--accent-soft); border:1px solid var(--accent-soft-2); color:var(--text); border-radius:14px; padding:10px 12px; font-size:12px; font-weight:800; margin-bottom:12px; }
.row-location-address { margin-top:5px; color:var(--muted); font-size:11px; line-height:1.25; }
.expiring-banner { border-color:var(--pz-orb); background:var(--pz-ors); }
.expiring-banner .panel-head { border-bottom-color:var(--pz-orb); }
.expiring-banner .panel-title { color:var(--pz-or); }
.expiring-list { display:grid; gap:8px; }
.expiring-row { display:flex; gap:12px; justify-content:space-between; align-items:center; flex-wrap:wrap; padding:10px 12px; background:#fff; border:1px solid var(--pz-orb); border-radius:12px; }
.expiring-main { flex:1; min-width:220px; }
.expiring-title { font-size:13px; font-weight:700; color:var(--text); }
.expiring-meta { font-size:11.5px; color:var(--muted); margin-top:3px; }
.expiring-meta strong { color:var(--pz-or); font-weight:800; }
.expiring-actions { display:flex; gap:6px; flex-wrap:wrap; }
@media (max-width: 980px) {
    .filter-form, .contract-form-grid, .contract-steps { grid-template-columns:1fr; }
    .field.span2 { grid-column:1; }
    .doc-row { grid-template-columns:1fr; }
    .doc-actions { justify-content:flex-start; }
    .contract-hero { padding:18px; }
}

/* === Selector „Tip contract" === */
.ctype-picker { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; }
.ctype-card { position:relative; display:flex; gap:10px; align-items:flex-start; padding:12px 14px; border:1.5px solid var(--border2); background:var(--surface-soft); border-radius:14px; cursor:pointer; transition:border-color .15s, background .15s, box-shadow .15s; }
.ctype-card:hover { border-color:var(--accent-pale); background:#fff; }
.ctype-card.is-active { border-color:var(--pz-bl); background:var(--pz-bls); box-shadow:0 0 0 3px rgba(37,99,235,.08); }
.ctype-card input[type="radio"] { position:absolute; opacity:0; pointer-events:none; }
.ctype-card .ctype-radio { flex:0 0 18px; width:18px; height:18px; border-radius:50%; border:1.5px solid var(--border); background:#fff; margin-top:2px; position:relative; }
.ctype-card.is-active .ctype-radio { border-color:var(--pz-bl); }
.ctype-card.is-active .ctype-radio::after { content:""; position:absolute; inset:3px; border-radius:50%; background:var(--pz-bl); }
.ctype-card .ctype-text strong { display:block; font-size:13px; font-weight:700; color:var(--text); }
.ctype-card .ctype-text span { display:block; font-size:11.5px; color:var(--muted); margin-top:3px; line-height:1.35; }
.ctype-textarea { width:100%; min-height:160px; padding:10px 12px; border:1px solid var(--border); border-radius:8px; background:#fff; font-family:inherit; font-size:13px; color:var(--text); resize:vertical; line-height:1.5; }
.ctype-textarea:focus { outline:none; border-color:var(--pz-bl); box-shadow:0 0 0 3px rgba(37,99,235,.10); }
.ctype-value-row { display:grid; grid-template-columns:minmax(240px, 320px) 1fr; gap:12px; margin-bottom:12px; }
.ctype-value-input { display:flex; gap:6px; align-items:stretch; }
.ctype-value-input input[type="number"] { flex:1; min-width:0; padding:8px 10px; border:1px solid var(--border); border-radius:8px; background:#fff; font-family:inherit; font-size:13px; color:var(--text); }
.ctype-value-input input[type="number"]:focus { outline:none; border-color:var(--pz-bl); box-shadow:0 0 0 3px rgba(37,99,235,.10); }
.ctype-value-input select { flex:0 0 78px; padding:8px 8px; border:1px solid var(--border); border-radius:8px; background:var(--surface-soft); font-family:inherit; font-size:13px; color:var(--text); cursor:pointer; }
.ctype-value-input select:focus { outline:none; border-color:var(--pz-bl); box-shadow:0 0 0 3px rgba(37,99,235,.10); }
.ctype-label { display:block; font-size:11.5px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; margin-bottom:5px; }
@media (max-width: 980px) {
    .ctype-picker { grid-template-columns:1fr; }
}

/* === Tabs Tip contract (înlocuiește .ctype-picker pe layout-ul nou) === */
.ctype-tabs { display:inline-flex; background:var(--pz-surf); border:1px solid var(--pz-line); border-radius:6px; padding:3px; gap:2px; }
.ctype-tabs .ctype-tab { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; background:transparent; border:0; border-radius:4px; font-size:13px; font-weight:500; color:var(--pz-text); cursor:pointer; transition:background .12s, color .12s; line-height:1; }
.ctype-tabs .ctype-tab:hover { color:var(--pz-title); }
.ctype-tabs .ctype-tab.is-active, .ctype-tabs .ctype-tab.is-active * { background:var(--pz-bl); color:#fff !important; }
.ctype-tabs .ctype-tab.is-active .ctype-tab-icon svg { stroke:#fff !important; }
.ctype-tab-icon { display:inline-flex; width:14px; height:14px; }
.ctype-tab-icon svg { width:14px; height:14px; stroke:currentColor; fill:none; stroke-width:1.6; stroke-linecap:round; stroke-linejoin:round; }
.ctype-tabs-row { background:var(--pz-soft); border-bottom:1px solid var(--pz-lines); padding:14px 18px; margin:-14px -16px 16px; }
.ctype-tabs-label { font-size:11px; font-weight:600; color:var(--pz-mu); letter-spacing:.08em; text-transform:uppercase; margin-bottom:8px; }
.ctype-tabs-help { font-size:12px; color:var(--pz-mu); margin-top:8px; line-height:1.4; }

/* === Secțiuni numerotate cu bilă progres === */
.contract-section { margin-bottom:22px; }
.contract-section:last-child { margin-bottom:0; }
.contract-section-head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px; }
.contract-section-titlewrap { display:flex; align-items:center; gap:10px; min-width:0; }
.contract-step-num { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:var(--pz-soft); border:1px solid var(--pz-line); color:var(--pz-fa); font-size:12px; font-weight:600; flex:0 0 22px; }
.contract-section-title { font-size:14px; font-weight:600; color:var(--pz-title); margin:0; }
.contract-section-hint { font-size:12px; color:var(--pz-mu); margin-left:6px; }
.contract-section-body { padding-left:32px; }
@media (max-width: 720px) {
    .contract-section-body { padding-left:0; }
}

/* === Grid câmpuri în secțiune Perioadă: 4 coloane === */
.contract-period-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
.contract-period-grid .field.full { grid-column:1 / -1; }
@media (max-width: 980px) {
    .contract-period-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 540px) {
    .contract-period-grid { grid-template-columns:1fr; }
}
</style>
<?php render_search_preview_assets(); ?>
</head>
<body>
<div class="layout">
    <?php render_sidebar('contracts', $isAdmin); ?>

    <main class="main">
        <div class="content">
            <?php if (!empty($expiringContracts)): ?>
                <section class="panel expiring-banner">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title">
                                <?= count($expiringContracts) ?>
                                <?= count($expiringContracts) === 1 ? 'contract expira' : 'contracte expira' ?>
                                in urmatoarele 30 de zile
                            </div>
                            <div class="panel-subtitle">Emite act adițional pentru fiecare contract pe care vrei sa-l prelungesti.</div>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="expiring-list">
                            <?php foreach ($expiringContracts as $row): ?>
                                <?php
                                    $endDate = $row['end_date'] ?? null;
                                    $daysLeft = $endDate ? (int)floor((strtotime($endDate) - strtotime(date('Y-m-d'))) / 86400) : null;
                                    $daysLabel = $daysLeft === null ? '-' : ($daysLeft <= 0 ? 'expira azi' : ($daysLeft . ' ' . ($daysLeft === 1 ? 'zi' : 'zile')));
                                    $sourceDocId = (int)($row['source_document_id'] ?? 0);
                                ?>
                                <div class="expiring-row">
                                    <div class="expiring-main">
                                        <div class="expiring-title">
                                            <?= pz_contract_h($row['contract_number'] ?: ('Contract #' . $row['id'])) ?>
                                            — <?= pz_contract_h($row['client_name'] ?: ($row['title'] ?: 'Client necunoscut')) ?>
                                        </div>
                                        <div class="expiring-meta">
                                            Expira la <?= pz_contract_h(pz_contract_date_ro($endDate)) ?>
                                            · ramas: <strong><?= pz_contract_h($daysLabel) ?></strong>
                                        </div>
                                    </div>
                                    <div class="expiring-actions">
                                        <?php if ($sourceDocId > 0): ?>
                                            <a class="btn small primary" href="addenda.php?new=1&amp;parent=<?= $sourceDocId ?>">Emite act adițional</a>
                                            <a class="btn small" href="document_view.php?id=<?= $sourceDocId ?>">Vezi contract</a>
                                        <?php else: ?>
                                            <span class="badge">Fara document sursa</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="alert error"><?= pz_contract_h($errorMessage) ?></div>
            <?php endif; ?>

            <?php if (!empty($_GET['created']) || !empty($_GET['saved'])): ?>
                <div class="alert success">Contractul a fost salvat.</div>
            <?php endif; ?>

            <?php if (!empty($_GET['new']) || $editingDocument): ?>
                <section class="panel" id="contractFormPanel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title"><?= $editingDocument ? 'Editează contract draft' : 'Contract nou' ?></div>
                            <div class="panel-subtitle">Completează clientul, perioada, locațiile si serviciile contractate.</div>
                        </div>
                        <a class="btn small" href="contracts.php">Inchide formularul</a>
                    </div>
                    <div class="panel-body">
                        <form method="post" id="contractForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="document_id" value="<?= (int)($formDocument['id'] ?? 0) ?>">

                            <input type="hidden" name="currency" id="currency" value="<?= pz_contract_h($formDocument['currency'] ?? 'RON') ?>">
                            <input type="hidden" name="vat_percent" value="0">
                            <input type="hidden" name="renewal_notice_days" value="30">
                            <input type="hidden" name="contract_value" id="contractValue" value="<?= pz_contract_h((string)($formPayload['contract_value_raw'] ?? 0)) ?>">
                            <input type="hidden" name="payment_terms" id="paymentTermsHidden" value="<?= pz_contract_h($formPayload['payment_terms'] ?? '') ?>">
                            <input type="hidden" name="title" value="<?= pz_contract_h($formDocument['title'] ?? '') ?>">
                            <input type="hidden" name="client_location_id" id="mainLocationId" value="<?= (int)($formDocument['client_location_id'] ?? 0) ?>">
                            <input type="hidden" name="execution_terms" value="<?= pz_contract_h($formPayload['execution_terms'] ?? '') ?>">
                            <input type="hidden" name="special_clauses" value="<?= pz_contract_h($formPayload['special_clauses'] ?? '') ?>">
                            <input type="hidden" name="notes" value="<?= pz_contract_h($formDocument['notes'] ?? '') ?>">
                            <input type="hidden" name="internal_notes" value="<?= pz_contract_h($formDocument['internal_notes'] ?? '') ?>">

                            <div class="ctype-tabs-row">
                                <div class="ctype-tabs-label">Tip contract</div>
                                <div class="ctype-tabs" id="contractTypePicker" role="tablist">
                                    <label class="ctype-tab<?= $contractTypeValue === 'recurrent' ? ' is-active' : '' ?>" data-value="recurrent" role="tab">
                                        <input type="radio" name="contract_type" value="recurrent"<?= $contractTypeValue === 'recurrent' ? ' checked' : '' ?> style="position:absolute;opacity:0;pointer-events:none;">
                                        <span class="ctype-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M17 2l4 4-4 4"/><path d="M3 12v-2a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 12v2a4 4 0 0 1-4 4H3"/></svg></span>
                                        DDD recurent
                                    </label>
                                    <label class="ctype-tab<?= $contractTypeValue === 'execution' ? ' is-active' : '' ?>" data-value="execution" role="tab">
                                        <input type="radio" name="contract_type" value="execution"<?= $contractTypeValue === 'execution' ? ' checked' : '' ?> style="position:absolute;opacity:0;pointer-events:none;">
                                        <span class="ctype-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg></span>
                                        Standard
                                    </label>
                                </div>
                                <div class="ctype-tabs-help" data-contract-mode="recurrent"<?= $contractTypeValue === 'execution' ? ' style="display:none"' : '' ?>>Tabel locații × servicii × frecvență. Generează automat sarcini periodice.</div>
                                <div class="ctype-tabs-help" data-contract-mode="execution"<?= $contractTypeValue === 'execution' ? '' : ' style="display:none"' ?>>Contract standard cu obiect descris manual. Fără tabel servicii.</div>
                            </div>

                            <div class="contract-section" data-contract-step="1">
                                <div class="contract-section-head">
                                    <div class="contract-section-titlewrap">
                                        <span class="contract-step-num" data-step-num="1">1</span>
                                        <h3 class="contract-section-title">Client</h3>
                                    </div>
                                </div>
                                <div class="contract-section-body">
                                    <div class="field">
                                        <input type="hidden" name="client_id" id="clientSelect" value="<?= (int)($formDocument['client_id'] ?? 0) ?>" data-selected="<?= (int)($formDocument['client_id'] ?? 0) ?>">
                                        <div class="pz-autocomplete" id="clientAutocomplete">
                                            <input type="text" class="pz-autocomplete-input" id="clientSearchInput" placeholder="Caută" autocomplete="off" autofocus>
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
                                        <div class="client-help" id="clientHelp">Minimum 2 caractere.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="contract-section" data-contract-step="2">
                                <div class="contract-section-head">
                                    <div class="contract-section-titlewrap">
                                        <span class="contract-step-num" data-step-num="2">2</span>
                                        <h3 class="contract-section-title">Perioadă și șablon</h3>
                                    </div>
                                </div>
                                <div class="contract-section-body">
                                    <div class="contract-period-grid">
                                        <div class="field">
                                            <label>Data contract</label>
                                            <input type="date" name="document_date" value="<?= pz_contract_h($formDocument['document_date'] ?? date('Y-m-d')) ?>">
                                        </div>

                                        <div class="field">
                                            <label>Data început</label>
                                            <input type="date" name="contract_start_date" id="contractStartDate" value="<?= pz_contract_h($formPayload['contract_start_date'] ?? date('Y-m-d')) ?>">
                                        </div>

                                        <div class="field">
                                            <label>Data sfârșit</label>
                                            <input type="date" name="contract_end_date" id="contractEndDate" value="<?= pz_contract_h($formPayload['contract_end_date'] ?? '') ?>">
                                        </div>

                                        <div class="field">
                                            <label>Termen plată (zile)</label>
                                            <input type="number" min="1" step="1" name="payment_due_days" id="paymentDueDays" value="<?= (int)pz_contract_payment_due_days($formPayload, 5) ?>">
                                        </div>

                                        <div class="field full">
                                            <label>Șablon *</label>
                                            <select name="template_id" required>
                                                <option value="" disabled <?= empty($formDocument['template_id']) ? 'selected' : '' ?>>Alege șablon...</option>
                                                <?php foreach ($templates as $template): ?>
                                                    <option value="<?= (int)$template['id'] ?>" <?= (int)($formDocument['template_id'] ?? 0) === (int)$template['id'] ? 'selected' : '' ?>>
                                                        <?= pz_contract_h($template['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="contract-section" data-contract-step="3" data-contract-mode="recurrent"<?= $contractTypeValue === 'execution' ? ' style="display:none"' : '' ?>>
                                <div class="contract-section-head">
                                    <div class="contract-section-titlewrap">
                                        <span class="contract-step-num" data-step-num="3">3</span>
                                        <h3 class="contract-section-title">Locații și servicii</h3>
                                        <span class="contract-section-hint">locație × serviciu × frecvență</span>
                                    </div>
                                    <button type="button" class="btn small primary" onclick="addItemRow()"><i style="display:inline-block;font-style:normal;margin-right:2px;">+</i> Adaugă rând</button>
                                </div>
                                <div class="contract-section-body">
                            <div class="panel" style="margin-top:0; box-shadow:none;border:1px solid var(--pz-line);">
                                <div class="panel-head" style="display:none;">
                                    <div></div>
                                </div>
                                <div class="panel-body">
                                    <div class="items-wrap">
                                        <table class="items-table">
                                            <thead>
                                            <tr>
                                                <th style="width:46px;">Nr.</th>
                                                <th style="width:230px;">Locație</th>
                                                <th style="width:260px;">Serviciu contractat</th>
                                                <th style="width:100px;">m.p.</th>
                                                <th style="width:160px;">Frecvență</th>
                                                <th style="width:150px;">Pret / intervenție</th>
                                                <th style="width:80px;"></th>
                                            </tr>
                                            </thead>
                                            <tbody id="itemsBody">
                                            <?php foreach ($editingItems as $idx => $item): ?>
                                                <tr class="item-row">
                                                    <td class="row-index"><?= (int)$idx + 1 ?></td>
                                                    <td>
                                                        <select name="items[<?= (int)$idx ?>][client_location_id]" class="row-location" data-selected="<?= (int)($item['client_location_id'] ?? 0) ?>" onchange="onRowLocationChange(this)" required></select>
                                                        <div class="row-location-address"></div>
                                                    </td>
                                                    <td>
                                                        <select name="items[<?= (int)$idx ?>][service_id]" class="service-select" onchange="syncServiceName(this)">
                                                            <option value="">Alege din nomenclator</option>
                                                            <?php foreach ($services as $service): ?>
                                                                <option value="<?= (int)$service['id'] ?>" data-name="<?= pz_contract_h($service['name']) ?>" data-description="<?= pz_contract_h($service['description'] ?? '') ?>" <?= (int)($item['service_id'] ?? 0) === (int)$service['id'] ? 'selected' : '' ?>><?= pz_contract_h($service['name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input type="hidden" name="items[<?= (int)$idx ?>][service_name]" class="service-name" value="<?= pz_contract_h($item['service_name'] ?? '') ?>">
                                                        <input type="hidden" name="items[<?= (int)$idx ?>][description]" class="service-description" value="<?= pz_contract_h($item['description'] ?? '') ?>">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.01" min="0" name="items[<?= (int)$idx ?>][quantity]" class="qty" value="<?= pz_contract_h((string)($item['quantity'] ?? 0)) ?>" placeholder="mp" oninput="recalculateRows()">
                                                        <input type="hidden" name="items[<?= (int)$idx ?>][unit]" value="mp">
                                                    </td>
                                                    <td>
                                                        <select name="items[<?= (int)$idx ?>][frequency_text]" required>
                                                            <?php $rawFrequency = trim((string)($item['frequency_text'] ?? '')); ?>
                                                            <?php $selectedFrequency = $rawFrequency === '' ? '' : pz_contract_normalize_frequency($rawFrequency); ?>
                                                            <option value="" disabled <?= $selectedFrequency === '' ? 'selected' : '' ?>>Alege frecventa</option>
                                                            <?php foreach (pz_contract_frequency_options() as $frequencyKey => $frequencyLabel): ?>
                                                                <option value="<?= pz_contract_h($frequencyKey) ?>" <?= $selectedFrequency === $frequencyKey ? 'selected' : '' ?>><?= pz_contract_h($frequencyLabel) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.01" min="0" name="items[<?= (int)$idx ?>][unit_price]" class="price" value="<?= pz_contract_h((string)($item['unit_price'] ?? 0)) ?>" placeholder="lei fără TVA" oninput="recalculateRows()">
                                                        <input type="hidden" name="items[<?= (int)$idx ?>][total_price]" class="line-total-input" value="<?= pz_contract_h((string)($item['total_price'] ?? ($item['unit_price'] ?? 0))) ?>">
                                                        <input type="hidden" name="items[<?= (int)$idx ?>][planned_date]" value="<?= pz_contract_h($item['planned_date'] ?? '') ?>">
                                                    </td>
                                                    <td><button type="button" class="btn small danger" onclick="removeItemRow(this)">Șterge</button></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="client-help">Prețurile sunt fără TVA. În contract, coloana de preț apare ca preț / intervenție.</div>
                                </div>
                            </div>
                                </div>
                            </div>

                            <div class="contract-section" data-contract-step="3" data-contract-mode="execution"<?= $contractTypeValue === 'execution' ? '' : ' style="display:none"' ?>>
                                <div class="contract-section-head">
                                    <div class="contract-section-titlewrap">
                                        <span class="contract-step-num" data-step-num="3">3</span>
                                        <h3 class="contract-section-title">Obiectul contractului</h3>
                                        <span class="contract-section-hint">descriere liberă</span>
                                    </div>
                                </div>
                                <div class="contract-section-body">
                            <div class="panel" style="margin-top:0; box-shadow:none;border:1px solid var(--pz-line);">
                                <div class="panel-head">
                                    <div>
                                        <div class="panel-title">Obiectul contractului</div>
                                        <div class="panel-subtitle">Descrie obiectul contractului: servicii, lucrări, condiții punctuale. Acest text alimentează variabila <code>{{document_object}}</code> din șablon.</div>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <div class="ctype-value-row">
                                        <div class="field">
                                            <label for="contractValueManual">Valoare contract</label>
                                            <div class="ctype-value-input">
                                                <input type="number" id="contractValueManual" min="0" step="0.01" placeholder="0.00" value="<?= pz_contract_h((string)($formPayload['contract_value_raw'] ?? 0)) ?>">
                                                <select id="contractCurrencyManual">
                                                    <?php $curVal = (string)($formDocument['currency'] ?? 'RON'); ?>
                                                    <?php foreach (['RON','EUR','USD'] as $cur): ?>
                                                        <option value="<?= $cur ?>"<?= $curVal === $cur ? ' selected' : '' ?>><?= $cur ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="client-help" style="margin-top:4px;">Sumă totală fără TVA. Apare în PDF prin tokenul <code>{{contract_value}}</code>.</div>
                                        </div>
                                    </div>
                                    <label for="contractObjectTextarea" class="ctype-label">Descriere obiect</label>
                                    <textarea name="contract_object" id="contractObjectTextarea" class="ctype-textarea" rows="10" placeholder="Ex: Prestari servicii de execuție lucrări de dezinsecție generală a imobilului situat la adresa ..., conform ofertei nr. ... din data de ..."><?= pz_contract_h($contractObjectValue) ?></textarea>
                                    <div class="client-help" style="margin-top:6px;">Textul intră ca atare în PDF, prin tokenul <code>{{document_object}}</code> din șablon. Același token funcționează și pentru acte adiționale sau alte tipizate.</div>
                                </div>
                            </div>
                                </div>
                            </div>

                            <input type="hidden" name="contract_object_default" id="contractObjectDefault" value="<?= pz_contract_h($contractObjectDDD) ?>">

                            <div class="contract-section" data-contract-step="4">
                                <div class="contract-section-head">
                                    <div class="contract-section-titlewrap">
                                        <span class="contract-step-num" data-step-num="4">4</span>
                                        <h3 class="contract-section-title">Emitere</h3>
                                        <span class="contract-section-hint">salvezi draft sau emiți cu număr</span>
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <div>
                                    <?php if (!empty($formDocument['id'])): ?>
                                        <a class="btn" href="document_view.php?id=<?= (int)$formDocument['id'] ?>">Vezi document</a>
                                    <?php endif; ?>
                                </div>
                                <div class="right">
                                    <button type="submit" name="action" value="save_draft" class="btn">Salvează draft</button>
                                    <button type="submit" name="action" value="issue" class="btn primary" onclick="return confirm('Emiti contractul si aloci numar? După emitere documentul se blocheaza.')">Emite contract</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (empty($_GET['new']) && empty($editingDocument)): ?>
            <?php
                /*
                |------------------------------------------------------------
                | Header unificat PestZone — înlocuiește panel-head + filter
                | form vechi pentru lista contracte.
                | Tabs principale = 5 sub-pagini Documente.
                | Toolbar = search + popover (Status + Rânduri/pagină).
                | Actions = Contract nou (primary).
                |------------------------------------------------------------
                */
                $contractsTabs = [
                    ['label' => 'Procese verbale',  'href' => 'service-reports'],
                    ['label' => 'Contracte',        'href' => 'contracts.php', 'active' => true],
                    ['label' => 'Oferte',           'href' => 'oferte.php'],
                    ['label' => 'Acte adiționale',  'href' => 'addenda.php'],
                    ['label' => 'Arhivă documente', 'href' => 'documents'],
                ];

                $contractsActiveFilters = 0;
                if (!empty($filters['status'])) $contractsActiveFilters++;
                if ($perPage !== 20)            $contractsActiveFilters++;

                $contractsSubtitle = (int)($totalDocs ?? count($documents)) . ' contracte';
                if (!empty($filters['client_id'])) {
                    $contractsSubtitle .= ' · filtrate pentru client #' . (int)$filters['client_id'];
                }
                if (!empty($filters['q'])) {
                    $contractsSubtitle .= ' · căutare: „' . pz_contract_h($filters['q']) . '"';
                }
                if (!empty($expiringContracts)) {
                    $contractsSubtitle .= ' · ' . count($expiringContracts) . ' expiră în 30 zile';
                }

                $contractNewHref = 'contracts.php?new=1' . (!empty($filters['client_id']) ? '&client_id=' . (int)$filters['client_id'] : '');

                ob_start();
                ?>
                <form method="get" id="contractsFilterForm" class="pz-fb">
                    <?php if (!empty($filters['client_id'])): ?>
                        <input type="hidden" name="client_id" value="<?= (int)$filters['client_id'] ?>">
                    <?php endif; ?>

                    <div class="pz-fb-search">
                        <i class="ti ti-search" aria-hidden="true"></i>
                        <input type="text" id="contractsSearchInput" name="q" value="<?= pz_contract_h($filters['q']) ?>" placeholder="Caută" autocomplete="off">
                        <div class="pz-search-preview"></div>
                    </div>

                    <div class="pz-fb-spacer"></div>

                    <a class="pz-fb-nav-btn" href="contracts.php" title="Resetare filtre">↻</a>

                    <div class="pz-fb-popover-wrap">
                        <button type="button" class="pz-fb-filter-btn" id="contractsFiltersToggle" aria-haspopup="true" aria-expanded="false">
                            <i class="ti ti-adjustments-horizontal" aria-hidden="true"></i>
                            Filtre
                            <?php if ($contractsActiveFilters > 0): ?>
                                <span class="badge"><?= (int)$contractsActiveFilters ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="pz-fb-popover" id="contractsFiltersPopover" role="dialog" aria-label="Filtre suplimentare contracte">
                            <div class="pf-row">
                                <label for="contractsStatusSelect">Status</label>
                                <select id="contractsStatusSelect" name="status">
                                    <option value="">Toate</option>
                                    <?php foreach (['draft' => 'Draft', 'issued' => 'Emise', 'cancelled' => 'Anulate'] as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pf-row">
                                <label for="contractsPerPageSelect">Rânduri pe pagină</label>
                                <select id="contractsPerPageSelect" name="per_page">
                                    <?php foreach ([20, 50, 100] as $nr): ?>
                                        <option value="<?= $nr ?>" <?= $perPage === $nr ? 'selected' : '' ?>><?= $nr ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pf-actions">
                                <button type="button" class="pz-ph-btn ghost" onclick="document.getElementById('contractsFiltersPopover').classList.remove('is-open'); document.getElementById('contractsFiltersToggle').setAttribute('aria-expanded','false');">Anulează</button>
                                <button type="submit" class="pz-ph-btn primary">Aplică</button>
                            </div>
                        </div>
                    </div>
                </form>
                <?php
                $contractsToolbarHtml = ob_get_clean();

                pz_page_header([
                    'kicker'   => 'Documente',
                    'title'    => 'Contracte',
                    'subtitle' => $contractsSubtitle,
                    'actions'  => [[
                        'label'   => 'Contract nou',
                        'href'    => $contractNewHref,
                        'variant' => 'primary',
                        'icon'    => 'ti-plus',
                    ]],
                    'tabs'     => $contractsTabs,
                    'toolbar'  => $contractsToolbarHtml,
                ]);
                ?>
                <script>
                (function() {
                    var btn = document.getElementById('contractsFiltersToggle');
                    var pop = document.getElementById('contractsFiltersPopover');
                    if (!btn || !pop) return;
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var open = pop.classList.toggle('is-open');
                        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });
                    document.addEventListener('click', function(e) {
                        if (!pop.classList.contains('is-open')) return;
                        if (pop.contains(e.target) || btn.contains(e.target)) return;
                        pop.classList.remove('is-open');
                        btn.setAttribute('aria-expanded', 'false');
                    });
                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape' && pop.classList.contains('is-open')) {
                            pop.classList.remove('is-open');
                            btn.setAttribute('aria-expanded', 'false');
                            btn.focus();
                        }
                    });
                })();
                </script>

            <section class="panel">
                <div class="panel-body">
                    <div class="docs-list" style="margin-top:12px;">
                        <?php if (!$documents): ?>
                            <div class="empty-state">Nu există contracte inca.</div>
                        <?php else: ?>
                            <?php foreach ($documents as $doc): ?>
                                <?php $payload = pzdoc_json_decode($doc['payload_json'] ?? null); ?>
                                <div class="doc-row">
                                    <div>
                                        <div class="doc-title">
                                            <?= pz_contract_h(($doc['document_number'] ?: 'Draft') . ' - ' . ($doc['client_name_snapshot'] ?: $doc['title'])) ?>
                                        </div>
                                        <div class="doc-meta">
                                            Data emitere: <?= pz_contract_h(pz_contract_date_ro($doc['document_date'] ?? null)) ?>
                                            <?php if (!empty($payload['contract_start_date'])): ?>
                                                | Perioada: <?= pz_contract_h(pz_contract_date_ro($payload['contract_start_date'])) ?> - <?= pz_contract_h(pz_contract_date_ro($payload['contract_end_date'] ?? null)) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($doc['client_identifier_snapshot'])): ?>
                                                | CUI: <?= pz_contract_h($doc['client_identifier_snapshot']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="badge <?= pz_contract_h(pz_contract_status_class($doc['status'])) ?>"><?= pz_contract_h(pz_contract_status_label($doc['status'])) ?></span>
                                    </div>
                                    <div class="doc-meta">
                                        <?= pz_contract_h($doc['location_name_snapshot'] ?: 'Locații in contract') ?><br>
                                        <?= pz_contract_h($doc['location_address_snapshot'] ?: 'Vezi tabelul din contract') ?>
                                    </div>
                                    <div style="font-weight:950; text-align:right;">
                                        <?= pz_contract_money($doc['total_amount'] ?? 0, $doc['currency'] ?? 'RON') ?>
                                    </div>
                                    <div class="doc-actions pz-actions">
                                        <a class="pz-icon-btn" title="Vezi" aria-label="Vezi contract" href="document_view.php?id=<?= (int)$doc['id'] ?>"><?= app_icon_svg('eye') ?></a>
                                        <?php if (($doc['status'] ?? '') === 'draft'): ?>
                                            <a class="pz-icon-btn" title="Editează" aria-label="Editează contract" href="contracts.php?edit=<?= (int)$doc['id'] ?>"><?= app_icon_svg('edit') ?></a>
                                        <?php endif; ?>
                                        <a class="pz-icon-btn" title="Deschide PDF" aria-label="Deschide PDF" target="_blank" href="document_pdf.php?id=<?= (int)$doc['id'] ?>&mode=inline"><?= app_icon_svg('pdf') ?></a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a class="btn small" href="<?= pz_contract_h(pz_contract_current_url(['page' => $page - 1])) ?>">&lt;</a>
                            <?php endif; ?>
                            <span class="badge">Pagina <?= (int)$page ?> / <?= (int)$totalPages ?></span>
                            <?php if ($page < $totalPages): ?>
                                <a class="btn small" href="<?= pz_contract_h(pz_contract_current_url(['page' => $page + 1])) ?>">&gt;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
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

/* === AUTOCOMPLETE CLIENT - cautare smart === */
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
    if (q.length < 1) return [];
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
    populateRowLocations();
    recalculateRows();
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
    recalculateRows();
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
            if (q.length < 1) { wrap.classList.remove('is-open'); document.getElementById('clientResults').innerHTML = ''; return; }
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

/* === LOCATII PE RANDURI === */
function populateLocationsSmart() {
    // Compatibilitate cu codul vechi. Locatia nu mai este camp separat in formular;
    // fiecare rand din tabel are propria locație.
    populateRowLocations();
}

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
    if (client.registry_number) parts.push('Reg. Com.: ' + client.registry_number);
    if (client.representative) parts.push('Reprezentant: ' + client.representative);
    if (client.email) parts.push('Email: ' + client.email);
    if (client.phone) parts.push('Telefon: ' + client.phone);
    help.textContent = parts.length ? parts.join(' | ') : 'Client selectat.';
}

function selectedClient() {
    const clientSelect = document.getElementById('clientSelect');
    const clientId = clientSelect ? Number(clientSelect.value || 0) : 0;
    return clientsData.find(item => Number(item.id) === clientId) || null;
}

function rowLocationOptions(selectedValue) {
    const client = selectedClient();
    if (!client) return '<option value="">Alege clientul mai intai</option>';
    const locations = locationsData.filter(loc => Number(loc.client_id) === Number(client.id));
    if (!locations.length) {
        return '<option value="">Clientul nu are locații salvate</option>';
    }
    let html = '<option value="">Alege locatia</option>';
    locations.forEach(loc => {
        const selected = String(loc.id) === String(selectedValue || '') ? ' selected' : '';
        const label = (loc.location_name || 'Punct de lucru') + (loc.address ? ' - ' + loc.address : '');
        html += '<option value="' + loc.id + '" data-address="' + escapeHtml(loc.address || '') + '" data-surface="' + escapeHtml(loc.surface_value || '') + '"' + selected + '>' + escapeHtml(label) + '</option>';
    });
    return html;
}

function populateRowLocations() {
    document.querySelectorAll('.row-location').forEach(select => {
        const selected = select.dataset.selected || select.value || '';
        select.innerHTML = rowLocationOptions(selected);
        select.value = selected;
        updateRowLocationInfo(select);
    });
    updateMainLocationId();
}

function updateRowLocationInfo(select) {
    const row = select.closest('tr');
    if (!row) return;
    const info = row.querySelector('.row-location-address');
    const qty = row.querySelector('.qty');
    const client = selectedClient();
    let address = '';
    let surface = '';
    if (select.value) {
        const loc = locationsData.find(item => String(item.id) === String(select.value));
        if (loc) {
            address = loc.address || '';
            surface = loc.surface_value || '';
        }
    }
    if (info) info.textContent = address;
    if (qty && (!qty.value || Number(qty.value) === 0) && surface) {
        qty.value = surface;
    }
}

function onRowLocationChange(select) {
    select.dataset.selected = select.value || '';
    updateRowLocationInfo(select);
    updateMainLocationId();
}

function serviceOptionsHtml() {
    let html = '<option value="">Alege din nomenclator</option>';
    servicesData.forEach(service => {
        html += '<option value="' + service.id + '" data-name="' + escapeHtml(service.name) + '" data-description="' + escapeHtml(service.description || '') + '">' + escapeHtml(service.name) + '</option>';
    });
    return html;
}

function nextItemIndex() {
    if (typeof window.pzNextContractItemIndex === 'undefined') {
        window.pzNextContractItemIndex = document.querySelectorAll('#itemsBody .item-row').length;
    }
    return window.pzNextContractItemIndex++;
}

function addItemRow() {
    const body = document.getElementById('itemsBody');
    if (!body) return;
    const i = nextItemIndex();
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="row-index"></td>
        <td>
            <select name="items[${i}][client_location_id]" class="row-location" data-selected="" onchange="onRowLocationChange(this)" required></select>
            <div class="row-location-address"></div>
        </td>
        <td>
            <select name="items[${i}][service_id]" class="service-select" onchange="syncServiceName(this)">${serviceOptionsHtml()}</select>
            <input type="hidden" name="items[${i}][service_name]" class="service-name">
            <input type="hidden" name="items[${i}][description]" class="service-description" value="">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="items[${i}][quantity]" class="qty" value="0" placeholder="mp" oninput="recalculateRows()">
            <input type="hidden" name="items[${i}][unit]" value="mp">
        </td>
        <td>
            <select name="items[${i}][frequency_text]" required>
                <option value="" disabled selected>Alege frecventa</option>
                <option value="lunar">Lunar</option>
                <option value="trimestrial">Trimestrial</option>
                <option value="semestrial">Semestrial</option>
                <option value="int_unica">Int. unica</option>
            </select>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="items[${i}][unit_price]" class="price" value="0" placeholder="lei fără TVA" oninput="recalculateRows()">
            <input type="hidden" name="items[${i}][total_price]" class="line-total-input" value="0">
            <input type="hidden" name="items[${i}][planned_date]" value="">
        </td>
        <td><button type="button" class="btn small danger" onclick="removeItemRow(this)">Șterge</button></td>
    `;
    body.appendChild(tr);
    populateRowLocations();
    recalculateRows();
}

function removeItemRow(button) {
    const rows = document.querySelectorAll('#itemsBody .item-row');
    if (rows.length <= 1) {
        const row = button.closest('tr');
        if (row) {
            row.querySelectorAll('input, textarea').forEach(input => {
                if (input.classList.contains('qty')) input.value = '0';
                else if (input.classList.contains('price') || input.classList.contains('line-total-input')) input.value = '0';
                else input.value = '';
            });
            row.querySelectorAll('select').forEach(select => select.value = '');
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
    const desc = row ? row.querySelector('.service-description') : null;
    const selected = select.options[select.selectedIndex];
    if (input && selected && selected.dataset.name) {
        input.value = selected.dataset.name;
    }
    if (desc && selected && typeof selected.dataset.description !== 'undefined') {
        desc.value = selected.dataset.description || '';
    }
}

function updateMainLocationId() {
    const hidden = document.getElementById('mainLocationId');
    if (!hidden) return;
    const first = document.querySelector('#itemsBody .row-location');
    hidden.value = first && first.value ? first.value : '';
}

function updatePaymentTermsHidden() {
    const due = document.getElementById('paymentDueDays');
    const hidden = document.getElementById('paymentTermsHidden');
    if (!due || !hidden) return;
    const days = Math.max(1, parseInt(due.value || '5', 10) || 5);
    hidden.value = 'Plata se efectueaza in termen de ' + days + ' zile calendaristice de la data emiterii facturii.';
}

function updateRowNumbers() {
    document.querySelectorAll('#itemsBody .item-row').forEach((row, idx) => {
        const cell = row.querySelector('.row-index');
        if (cell) cell.textContent = String(idx + 1);
    });
}

function recalculateRows() {
    const currency = document.getElementById('currency') ? document.getElementById('currency').value : 'RON';
    let total = 0;
    document.querySelectorAll('#itemsBody .item-row').forEach(row => {
        const price = parseFloat((row.querySelector('.price') || {}).value || '0') || 0;
        const line = Math.round(price * 100) / 100;
        total += line;
        const hidden = row.querySelector('.line-total-input');
        if (hidden) hidden.value = line.toFixed(2);
    });
    const grand = document.getElementById('grandTotal');
    if (grand) grand.textContent = total.toFixed(2) + ' ' + currency;
    const contractValue = document.getElementById('contractValue');
    if (contractValue) contractValue.value = total.toFixed(2);
    updatePaymentTermsHidden();
    updateRowNumbers();
    updateMainLocationId();
}

document.addEventListener('DOMContentLoaded', function() {
    const currency = document.getElementById('currency');
    const clearBtn = document.getElementById('clientClearBtn');
    const dueDays = document.getElementById('paymentDueDays');
    const startDate = document.getElementById('contractStartDate');
    const endDate = document.getElementById('contractEndDate');

    if (currency) currency.addEventListener('change', recalculateRows);
    if (clearBtn) clearBtn.addEventListener('click', pzClientClear);
    if (dueDays) dueDays.addEventListener('input', recalculateRows);
    if (startDate && endDate) {
        startDate.addEventListener('change', function() {
            if (!endDate.value && startDate.value) {
                const d = new Date(startDate.value + 'T00:00:00');
                d.setFullYear(d.getFullYear() + 1);
                endDate.value = d.toISOString().slice(0, 10);
            }
        });
    }

    initClientAutocomplete();
    populateLocationsSmart();
    recalculateRows();
    initContractTypePicker();

    const clientSearchInput = document.getElementById('clientSearchInput');
    const clientHidden = document.getElementById('clientSelect');
    if (clientSearchInput && clientHidden && !Number(clientHidden.value || 0)) {
        setTimeout(function() {
            clientSearchInput.focus();
        }, 120);
    }
});

/* === Selector tip contract: toggle vizibilitate panel-uri + reset textarea + sync valoare === */
function initContractTypePicker() {
    const picker = document.getElementById('contractTypePicker');
    if (!picker) return;
    const radios = picker.querySelectorAll('input[name="contract_type"]');
    const textarea = document.getElementById('contractObjectTextarea');
    const defaultObj = (document.getElementById('contractObjectDefault') || {}).value || '';

    // Sync valoare manuală -> hidden contract_value
    const valueManual    = document.getElementById('contractValueManual');
    const valueHidden    = document.getElementById('contractValue');
    const currencyManual = document.getElementById('contractCurrencyManual');
    const currencyHidden = document.getElementById('currency');

    if (valueManual && valueHidden) {
        valueManual.addEventListener('input', () => {
            const v = parseFloat(String(valueManual.value).replace(',', '.')) || 0;
            valueHidden.value = v.toFixed(2);
        });
    }
    if (currencyManual && currencyHidden) {
        currencyManual.addEventListener('change', () => {
            currencyHidden.value = currencyManual.value;
            // Doar în DDD recurent re-sumăm tabelul; în execuție recalculateRows ar
            // suprascrie valoarea manuală cu 0 (tabel gol).
            const checked = document.querySelector('input[name="contract_type"]:checked');
            if (checked && checked.value === 'recurrent' && typeof recalculateRows === 'function') {
                recalculateRows();
            }
        });
    }

    function applyMode(mode) {
        // Marchează vizual tab-ul activ
        picker.querySelectorAll('.ctype-tab').forEach(tab => {
            tab.classList.toggle('is-active', tab.dataset.value === mode);
        });
        // Arată / ascunde panel-urile + cele 2 sub-headere de pași
        document.querySelectorAll('[data-contract-mode]').forEach(el => {
            const isActive = (el.dataset.contractMode === mode);
            el.style.display = isActive ? '' : 'none';
            // CRITIC: dezactivează inputurile din panel-ul ascuns ca să nu blocheze
            // HTML5 validation pe câmpuri required invizibile (ex: items[i][client_location_id])
            // și nici să nu fie trimise la submit.
            el.querySelectorAll('input, select, textarea, button').forEach(field => {
                if (isActive) {
                    field.disabled = false;
                } else {
                    // Nu dezactivez butoanele de tip submit (vor fi vizibile oricum în form-actions, care nu are data-contract-mode)
                    if (field.type !== 'submit') field.disabled = true;
                }
            });
        });
        // Resetează / curăță textarea în funcție de mod
        if (textarea) {
            if (mode === 'recurrent') {
                // pentru DDD recurent textarea nu mai e relevant, dar valoarea ei merge în payload
                // o ducem la default-ul DDD ca să nu rămână text orfan din execuție
                textarea.value = defaultObj;
            } else if (mode === 'execution') {
                // dacă utilizatorul vine din DDD recurent și textul e exact default-ul, golim ca să scrie de la zero
                if (textarea.value.trim() === defaultObj.trim()) {
                    textarea.value = '';
                }
                setTimeout(() => textarea.focus(), 80);
            }
        }
        // Sincronizare valoare contract între moduri
        if (mode === 'execution') {
            // Aducem în input-ul vizibil ce avem în hidden (sumă din tabel sau valoare salvată)
            if (valueManual && valueHidden) {
                const cur = parseFloat(valueHidden.value || '0') || 0;
                valueManual.value = cur > 0 ? cur.toFixed(2) : '';
            }
            if (currencyManual && currencyHidden) {
                currencyManual.value = currencyHidden.value || 'RON';
            }
        } else if (mode === 'recurrent') {
            // Recalculează din tabel ca să fie suma actuală a items-urilor
            if (typeof recalculateRows === 'function') recalculateRows();
        }
    }

    radios.forEach(r => r.addEventListener('change', () => applyMode(r.value)));

    // Aplică modul inițial (în caz că HTML-ul are inconsistențe de stare)
    const checked = picker.querySelector('input[name="contract_type"]:checked');
    if (checked) applyMode(checked.value);
}

</script>

<?php
// Preview live pentru bara „Caută contract".
$previewContractsList = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'contracts'")->fetch()) {
        $stmtPrev = $pdo->query("
            SELECT con.id, con.contract_number, c.name AS client_name, c.fiscal_code
            FROM contracts con
            LEFT JOIN clients c ON c.id = con.client_id
            ORDER BY con.id DESC LIMIT 2000
        ");
        while ($r = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
            $nm  = html_entity_decode((string)($r['client_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cf  = html_entity_decode((string)($r['fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $num = trim((string)($r['contract_number'] ?? ''));
            $title = ($num !== '' ? ($num . ' · ') : '') . ($nm !== '' ? $nm : ('Contract #' . (int)$r['id']));
            $previewContractsList[] = [
                'title'  => $title,
                'url'    => 'contracts.php?q=' . urlencode($num !== '' ? $num : $nm),
                'type'   => 'contract',
                'search' => $num . ' ' . $nm . ' ' . $cf,
            ];
        }
    }
} catch (Throwable $e) { error_log('contracts.php preview: ' . $e->getMessage()); }
?>
<script>
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('contractsSearchInput',
            <?= json_encode($previewContractsList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
    };
    go();
})();
</script>           