<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/document_core.php';
require_once __DIR__ . '/document_tokens.php';
require_once __DIR__ . '/document_access.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

$isAdmin = is_admin();
$isTeamUser = is_team_user();
$currentTeamId = current_team_id();

if (!$isAdmin && !$isTeamUser) {
    header('Location: calendar.php');
    exit;
}

try {
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    pzdoc_require_schema($pdo);
} catch (Throwable $e) {
    error_log('PestZone PV init error: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| AJAX endpoint - cauta ultimul PV pentru un client (pentru autofill)
|--------------------------------------------------------------------------
*/
if (isset($_GET['last_pv_for_client'])) {
    header('Content-Type: application/json; charset=utf-8');
    $clientId = (int)$_GET['last_pv_for_client'];
    if ($clientId <= 0) { echo json_encode(['found' => false]); exit; }

    try {
        $stmt = $pdo->prepare("
            SELECT id, document_date, client_location_id, executor_notes, payload_json
            FROM documents
            WHERE document_type = 'proces_verbal'
              AND client_id = ?
              AND status IN ('issued', 'emitted', 'finalized', 'sent')
            ORDER BY document_date DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) { echo json_encode(['found' => false]); exit; }

        $payload = [];
        if (!empty($row['payload_json'])) {
            $decoded = json_decode((string)$row['payload_json'], true);
            if (is_array($decoded)) $payload = $decoded;
        }

        $services = [];
        if (!empty($payload['pv_services']) && is_array($payload['pv_services'])) {
            $services = array_values($payload['pv_services']);
        }

        $dateStr = (string)($row['document_date'] ?? '');
        $dateLabel = $dateStr ? date('d.m.Y', strtotime($dateStr)) : '';

        echo json_encode([
            'found' => true,
            'date' => $dateStr,
            'date_label' => $dateLabel,
            'client_location_id' => (int)($row['client_location_id'] ?? 0),
            'surface_text' => (string)($payload['surface_text'] ?? ''),
            'workers_names' => (string)($payload['workers_names'] ?? ''),
            'services' => $services,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('PestZone PV last_pv autofill error: ' . $e->getMessage());
        echo json_encode(['found' => false]);
    }
    exit;
}

// Helper-i pz_pv_* extracși în pv_helpers.php (36 funcții).
require_once __DIR__ . '/pv_helpers.php';

$clients = pz_pv_fetch_clients($pdo);
$locations = pz_pv_fetch_locations($pdo);
$locationsById = pz_pv_locations_by_id($locations);
$services = pz_pv_fetch_services($pdo);
$pvServiceChoices = pz_pv_service_choices();
$templates = pz_pv_fetch_templates($pdo);
$contracts = pz_pv_fetch_contracts($pdo);
$contractsById = pz_pv_contracts_by_id($contracts);
$products = pz_pv_fetch_products($pdo);
$receipts = pz_pv_fetch_receipts($pdo);
$appointments = pz_pv_fetch_appointments($pdo, $isTeamUser ? (int)$currentTeamId : null);
$productsById = pz_pv_products_by_id($products);
$receiptsById = pz_pv_receipts_by_id($receipts);

/*
|--------------------------------------------------------------------------
| POST: salvare / emitere PV
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? '';
    if (in_array($action, ['save_draft', 'issue'], true)) {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $appointment = $appointmentId > 0 ? pz_pv_fetch_appointment($pdo, $appointmentId) : null;

        if ($isTeamUser) {
            if ($action !== 'issue') {
                pz_pv_redirect_with_error('In modul angajat poți doar emite PV-ul final.', $documentId);
            }
            if ($appointmentId <= 0 || !$appointment || !pzdoc_user_can_access_appointment_for_pv($pdo, $appointmentId, true)) {
                pz_pv_redirect_with_error('Nu poți emite PV pentru aceasta programare. Lucrarea trebuie sa fie finalizata si sa apartina tehnicianului tau.', $documentId);
            }
            if ($documentId > 0) {
                $existingForAccess = pzdoc_get_document($pdo, $documentId, false);
                if (!$existingForAccess || !pzdoc_user_can_access_document($pdo, $existingForAccess)) {
                    pz_pv_redirect_with_error('Nu ai acces la acest PV.', $documentId);
                }
            }
        }

        $clientId = (int)($_POST['client_id'] ?? 0);
        $locationId = !empty($_POST['client_location_id']) ? (int)$_POST['client_location_id'] : null;
        if ($appointment) {
            if ($clientId <= 0 && !empty($appointment['client_id'])) {
                $clientId = (int)$appointment['client_id'];
            }
            if (!$locationId && !empty($appointment['client_location_id'])) {
                $locationId = (int)$appointment['client_location_id'];
            }
        }

        $surfaceText = pz_pv_str($_POST['surface_text'] ?? ($appointment ? pz_pv_surface_from_appointment($appointment) : ''), 180);
        $selectedServices = pz_pv_normalize_selected_services($_POST['pv_services'] ?? []);
        $appointmentServiceRaw = $appointment ? pz_pv_str(pz_pv_service_from_appointment($appointment), 180) : '';
        if (!$selectedServices && $appointmentServiceRaw !== '') {
            $selectedServices = pz_pv_normalize_selected_services([$appointmentServiceRaw]);
        }
        $items = pz_pv_build_service_items_from_selected($selectedServices, $locationId, $surfaceText);
        if (!$items && $appointment && $appointmentServiceRaw !== '') {
            $items[] = [
                'item_type' => 'pv_service',
                'service_id' => null,
                'service_name' => $appointmentServiceRaw,
                'description' => '',
                'client_location_id' => $locationId ?: null,
                'location_name' => null,
                'location_address' => null,
                'quantity' => 1,
                'unit' => '',
                'unit_price' => 0,
                'vat_percent' => 0,
                'total_price' => 0,
                'currency' => 'RON',
                'frequency_text' => $surfaceText,
                'planned_date' => null,
                'sort_order' => 0,
            ];
        }

        $materialsEnabled = !empty($_POST['materials_enabled']);
        $deferStockConsumption = $isAdmin && !empty($_POST['stock_consumption_deferred']);
        $skipMaterialStrictValidation = $deferStockConsumption || $action === 'save_draft';
        try {
            $materials = $materialsEnabled ? pz_pv_build_materials_from_post($_POST['materials'] ?? [], $productsById, $receiptsById, $skipMaterialStrictValidation) : [];
        } catch (Throwable $e) {
            pz_pv_redirect_with_error($e->getMessage(), $documentId);
        }

        if ($clientId <= 0) {
            pz_pv_redirect_with_error('Selectează clientul pentru procesul verbal.', $documentId);
        }

        if (!$locationId) {
            pz_pv_redirect_with_error('Selectează o locație / punct de lucru din fișa clientului. Dacă serviciul se face la sediu, adauga sediul ca locație in fișa clientului.', $documentId);
        }

        if ($action === 'issue' && !$items) {
            pz_pv_redirect_with_error('Selectează cel puțin un serviciu prestat.', $documentId);
        }

        if ($action === 'issue' && !$materials) {
            pz_pv_redirect_with_error('Adaugă cel puțin un produs biocid / material utilizat.', $documentId);
        }

        if ($action === 'issue' && trim($surfaceText) === '') {
            pz_pv_redirect_with_error('Completează suprafața tratată.', $documentId);
        }

        if ($action === 'issue' && pz_pv_str($_POST['treated_areas'] ?? '') === '') {
            pz_pv_redirect_with_error('Completează zona/zonele tratate.', $documentId);
        }

        $basis = pz_pv_resolve_basis_from_post($_POST, $contracts, $contractsById, $clientId, $locationId);

        $payload = pz_pv_build_payload_from_post($_POST);
        $payload['stock_consumption_deferred'] = $deferStockConsumption ? '1' : '0';
        $payload['pv_services'] = $selectedServices;
        $payload['basis_type'] = $basis['basis_type'];
        $payload['basis_document'] = $basis['basis_document'];
        $payload['basis_manual_text'] = $basis['basis_manual_text'];
        $payload['contract_number'] = $basis['contract_number'];
        $payload['selected_contract_id'] = $basis['contract_id'] > 0 ? (string)$basis['contract_id'] : '';
        if ($appointment && empty($payload['workers_names']) && !empty($appointment['team_member_name'])) {
            $payload['workers_names'] = pz_pv_str($appointment['team_member_name']);
        }
        $payload['surface_text'] = $surfaceText;
        $payload['appointment_id'] = $appointmentId > 0 ? (string)$appointmentId : '';
        $documentDate = $_POST['document_date'] ?? date('Y-m-d');
        $documentTime = $_POST['document_time'] ?? date('H:i');
        if ($appointment) {
            if (trim((string)$documentDate) === '' && !empty($appointment['appointment_date'])) {
                $documentDate = $appointment['appointment_date'];
            }
            if (trim((string)$documentTime) === '' && !empty($appointment['start_time'])) {
                $documentTime = $appointment['start_time'];
            }
            if (empty($payload['start_time']) && !empty($appointment['start_time'])) {
                $payload['start_time'] = substr((string)$appointment['start_time'], 0, 5);
            }
            if (empty($payload['end_time']) && !empty($appointment['end_time'])) {
                $payload['end_time'] = substr((string)$appointment['end_time'], 0, 5);
            }
        }
        // Ștampila firmei: operatorii (team) au bifa automata; admin alege din checkbox.
        $applyStamp = 0;
        if (function_exists('is_team_user') && is_team_user()) {
            $applyStamp = 1;
        } elseif (!empty($_POST['apply_company_stamp'])) {
            $applyStamp = 1;
        }

        $data = [
            'template_id' => !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null,
            'appointment_id' => $appointmentId > 0 ? $appointmentId : null,
            'document_date' => $documentDate,
            'document_time' => $documentTime,
            'title' => pz_pv_str($_POST['title'] ?? '', 220),
            'client_id' => $clientId,
            'client_location_id' => $locationId,
            'contract_id' => !empty($basis['contract_id']) ? (int)$basis['contract_id'] : null,
            'vat_percent' => 0,
            'currency' => 'RON',
            'notes' => pz_pv_str($_POST['notes'] ?? ''),
            'executor_notes' => pz_pv_str($_POST['executor_notes'] ?? ''),
            'recommendations' => pz_pv_str($_POST['recommendations'] ?? ''),
            'client_notes' => pz_pv_str($_POST['client_notes'] ?? ''),
            'internal_notes' => pz_pv_str($_POST['internal_notes'] ?? ''),
            'apply_company_stamp' => $applyStamp,
            'payload_json' => $payload,
            'items' => $items,
            'materials' => $materials,
        ];

        try {
            if ($documentId > 0) {
                $existing = pzdoc_get_document($pdo, $documentId, false);
                if (!$existing || ($existing['document_type'] ?? '') !== 'proces_verbal') {
                    throw new RuntimeException('Proces verbal inexistent.');
                }
                pzdoc_update_document($pdo, $documentId, $data);
            } else {
                $documentId = pzdoc_create_document($pdo, 'proces_verbal', $data);
            }

            if ($action === 'issue') {
                pzdoc_issue_document($pdo, $documentId);
            }

            header('Location: document_view.php?id=' . (int)$documentId . '&saved=1' . (($action === 'issue' && $appointmentId > 0) ? '#clientSignatureCard' : ''));
            exit;
        } catch (Throwable $e) {
            error_log('PestZone PV save error: ' . $e->getMessage());
            pz_pv_redirect_with_error($e->getMessage(), $documentId);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Date pentru listare si formular
|--------------------------------------------------------------------------
*/
$errorMessage = $_SESSION['pz_pv_error'] ?? '';
unset($_SESSION['pz_pv_error']);

$editId = (int)($_GET['edit'] ?? 0);
$editingDocument = null;
$editingItems = [];
$editingMaterials = [];
$editingPayload = [];

if ($editId > 0) {
    $editingDocument = pzdoc_get_document($pdo, $editId, true);
    if (!$editingDocument || ($editingDocument['document_type'] ?? '') !== 'proces_verbal') {
        $editingDocument = null;
        $errorMessage = 'Procesul verbal nu a fost gasit.';
    } elseif (($editingDocument['status'] ?? '') !== 'draft') {
        header('Location: document_view.php?id=' . $editId);
        exit;
    } else {
        if ($isTeamUser && !pzdoc_user_can_access_document($pdo, $editingDocument)) {
            header('Location: calendar.php?error=1');
            exit;
        }
        $editingItems = $editingDocument['items'] ?? [];
        $editingMaterials = $editingDocument['materials'] ?? [];
        $editingPayload = pzdoc_json_decode($editingDocument['payload_json'] ?? null);
    }
}

if (!$editingItems) {
    $editingItems = [[
        'service_id' => null,
        'service_name' => '',
        'description' => '',
        'client_location_id' => null,
    ]];
}

if (!$editingMaterials) {
    $editingMaterials = [[
        'stock_product_id' => null,
        'stock_receipt_id' => null,
        'material_name' => '',
        'quantity' => '',
        'unit' => '',
        'lot_number' => '',
        'expiry_date' => '',
        'application_method' => '',
        'application_method_custom' => '',
        'application_area' => '',
        'work_concentration' => '',
        'aviz_no' => '',
        'safety_measures' => '',
        'notes' => '',
    ]];
}

$formDocument = $editingDocument ?: [
    'id' => 0,
    'document_date' => date('Y-m-d'),
    'document_time' => date('H:i'),
    'template_id' => null,
    'client_id' => 0,
    'client_location_id' => 0,
    'title' => '',
    'notes' => '',
    'executor_notes' => '',
    'recommendations' => '',
    'client_notes' => '',
    'internal_notes' => '',
];

if (!$editingDocument && !empty($_GET['client_id'])) {
    $formDocument['client_id'] = max(0, (int)$_GET['client_id']);
}

$selectedPvServices = pz_pv_normalize_selected_services($editingPayload['pv_services'] ?? []);
if (!$selectedPvServices && $editingItems) {
    $selectedPvServices = pz_pv_selected_services_from_items($editingItems);
}

$selectedAppointmentId = (int)($_GET['appointment_id'] ?? ($editingDocument['appointment_id'] ?? 0));
$activeAppointmentForPv = null;
if (!$editingDocument && $selectedAppointmentId > 0) {
    $selectedAppointment = pz_pv_fetch_appointment($pdo, $selectedAppointmentId);
    if ($selectedAppointment) {
        $activeAppointmentForPv = $selectedAppointment;
        if ($isTeamUser && !pzdoc_user_can_access_appointment_for_pv($pdo, $selectedAppointmentId, true)) {
            header('Location: calendar.php?error=1');
            exit;
        }
        $formDocument['appointment_id'] = $selectedAppointmentId;
        $formDocument['client_id'] = (int)($selectedAppointment['client_id'] ?? 0);
        $formDocument['client_location_id'] = (int)($selectedAppointment['client_location_id'] ?? 0);
        if (!empty($selectedAppointment['appointment_date'])) {
            $formDocument['document_date'] = $selectedAppointment['appointment_date'];
        }
        if (!empty($selectedAppointment['start_time'])) {
            $formDocument['document_time'] = substr((string)$selectedAppointment['start_time'], 0, 5);
            $editingPayload['start_time'] = substr((string)$selectedAppointment['start_time'], 0, 5);
        }
        if (!empty($selectedAppointment['end_time'])) {
            $editingPayload['end_time'] = substr((string)$selectedAppointment['end_time'], 0, 5);
        }
        $editingPayload['surface_text'] = pz_pv_surface_from_appointment($selectedAppointment);
        if (empty($editingPayload['workers_names']) && !empty($selectedAppointment['team_member_name'])) {
            $editingPayload['workers_names'] = (string)$selectedAppointment['team_member_name'];
        }
        $apptService = pz_pv_service_from_appointment($selectedAppointment);
        if ($apptService !== '') {
            $selectedPvServices = pz_pv_normalize_selected_services([$apptService]);
        }
    }
}

$formAppointmentId = (int)($formDocument['appointment_id'] ?? $selectedAppointmentId ?? 0);
if (!$activeAppointmentForPv && $formAppointmentId > 0) {
    $activeAppointmentForPv = pz_pv_fetch_appointment($pdo, $formAppointmentId);
}
$isQuickPvFromAppointment = (!$editingDocument && $formAppointmentId > 0 && $activeAppointmentForPv);

$q = pz_pv_str($_GET['q'] ?? '', 120);
$status = pz_pv_str($_GET['status'] ?? '', 30);
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [20, 50, 100], true)) {
    $perPage = 20;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$filters = [];
if ($q !== '') {
    $filters['q'] = $q;
}
if (in_array($status, ['draft', 'issued', 'cancelled'], true)) {
    $filters['status'] = $status;
}
$filterClientId = max(0, (int)($_GET['client_id'] ?? 0));
if ($filterClientId > 0) {
    $filters['client_id'] = $filterClientId;
}

$totalRows = pzdoc_count_documents($pdo, 'proces_verbal', $filters);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}
$documents = pzdoc_list_documents($pdo, 'proces_verbal', $filters, $perPage, $offset);

$clientsForJson = [];
foreach ($clients as $client) {
    $clientsForJson[] = [
        'id' => (int)$client['id'],
        'name' => (string)($client['name'] ?? ''),
        'fiscal_code' => (string)($client['fiscal_code'] ?? ''),
        'registry_number' => (string)($client['registry_number'] ?? ''),
        'address' => pz_pv_client_address($client),
        'representative' => trim((string)($client['legal_representative_name'] ?? '') . ' ' . (string)($client['legal_representative_role'] ?? '')),
        'email' => (string)($client['email'] ?? ''),
        'phone' => (string)($client['phone'] ?? ''),
    ];
}

$locationsForJson = [];
foreach ($locations as $location) {
    $surfaceText = '';
    if (($location['surface_value'] ?? '') !== '') {
        $surfaceText = trim((string)$location['surface_value'] . ' ' . (string)($location['surface_unit'] ?? ''));
    }
    $locationsForJson[] = [
        'id' => (int)$location['id'],
        'client_id' => (int)$location['client_id'],
        'location_name' => (string)($location['location_name'] ?? ''),
        'address' => (string)($location['address'] ?? ''),
        'contact_person' => (string)($location['contact_person'] ?? ''),
        'phone' => (string)($location['phone'] ?? ''),
        'surface_text' => $surfaceText,
    ];
}

$servicesForJson = [];
foreach ($services as $service) {
    $servicesForJson[] = [
        'id' => (int)$service['id'],
        'name' => (string)($service['name'] ?? ''),
    ];
}

$contractsForJson = [];
foreach ($contracts as $contract) {
    $contractsForJson[] = [
        'id' => (int)$contract['id'],
        'client_id' => (int)($contract['client_id'] ?? 0),
        'client_location_id' => (int)($contract['client_location_id'] ?? 0),
        'location_ids' => array_values(array_map('intval', $contract['location_ids'] ?? [])),
        'document_number' => (string)($contract['contract_number_label'] ?? $contract['document_number'] ?? ''),
        'document_date' => (string)($contract['document_date'] ?? ''),
        'title' => (string)($contract['title'] ?? ''),
        'label' => (string)($contract['contract_label'] ?? ''),
    ];
}

$productsForJson = [];
foreach ($products as $product) {
    $productsForJson[] = [
        'id' => (int)$product['id'],
        'name' => (string)($product['name'] ?? ''),
        'product_group' => (string)($product['product_group'] ?? ''),
        'unit' => (string)($product['unit_consumption'] ?? ''),
        'aviz_no' => (string)($product['aviz_no'] ?? ''),
        'default_application_method' => (string)($product['default_application_method'] ?? ''),
        'safety_measures' => (string)($product['safety_measures'] ?? ''),
        'product_concentration' => (string)($product['product_concentration'] ?? ''),
    ];
}

$receiptsForJson = [];
foreach ($receipts as $receipt) {
    $receiptsForJson[] = [
        'id' => (int)$receipt['id'],
        'product_id' => (int)$receipt['product_id'],
        'lot' => (string)($receipt['lot'] ?? ''),
        'expires_at' => (string)($receipt['expires_at'] ?? ''),
        'qty' => (string)($receipt['qty'] ?? ''),
        'reception_date' => (string)($receipt['reception_date'] ?? ''),
    ];
}

$appointmentsForJson = [];
foreach ($appointments as $appointment) {
    $appointmentsForJson[] = [
        'id' => (int)$appointment['id'],
        'client_id' => (int)($appointment['client_id'] ?? 0),
        'client_location_id' => (int)($appointment['client_location_id'] ?? 0),
        'appointment_date' => (string)($appointment['appointment_date'] ?? ''),
        'start_time' => substr((string)($appointment['start_time'] ?? ''), 0, 5),
        'end_time' => substr((string)($appointment['end_time'] ?? ''), 0, 5),
        'service_name' => pz_pv_service_from_appointment($appointment),
        'notes' => (string)($appointment['notes'] ?? ''),
        'client_name' => (string)($appointment['client_name'] ?? ''),
        'location_name' => (string)($appointment['location_name'] ?? ''),
        'location_address' => (string)($appointment['location_address'] ?? $appointment['address'] ?? ''),
        'location_contact' => (string)($appointment['location_contact_person'] ?? $appointment['contact_person'] ?? ''),
        'location_phone' => (string)($appointment['location_phone'] ?? $appointment['contact_phone'] ?? ''),
        'surface_text' => pz_pv_surface_from_appointment($appointment),
        'team_member_id' => (int)($appointment['team_member_id'] ?? 0),
        'team_member_name' => (string)($appointment['team_member_name'] ?? ''),
    ];
}

$materialsEnabled = !empty($editingMaterials) && (($editingMaterials[0]['material_name'] ?? '') !== '' || !empty($editingMaterials[0]['stock_product_id']));
if (($editingPayload['materials_enabled'] ?? '') === '1') {
    $materialsEnabled = true;
}
if (!empty($isQuickPvFromAppointment)) {
    $materialsEnabled = true;
}
$stockConsumptionDeferred = (($editingPayload['stock_consumption_deferred'] ?? '') === '1');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Procese verbale - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.4.3/dist/css/tom-select.css" rel="stylesheet">
<style>
.pv-topbar { align-items:center; padding:12px 20px; }
.pv-toolbar { width:100%; display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap; }
.pv-hero { background: var(--pz-bld, var(--accent-deep)); color:#fff; border-radius:var(--radius-lg); padding:22px 24px; box-shadow:var(--shadow-lg); margin-bottom:14px; display:flex; justify-content:space-between; gap:18px; flex-wrap:wrap; align-items:center; }
.pv-hero h1 { font-size:24px; font-weight:900; letter-spacing:-.03em; margin:0; }
.pv-hero p { color:rgba(255,255,255,.72); margin:4px 0 0; max-width:900px; }
.hero-actions { display:flex; gap:8px; flex-wrap:wrap; }
.panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); box-shadow:var(--shadow); margin-bottom:12px; }
.panel-head { padding:14px 16px; border-bottom:1px solid var(--border2); display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; }
.panel-title { font-size:16px; font-weight:900; color:var(--text); }
.panel-subtitle { font-size:12px; color:var(--muted); margin-top:2px; }
.pv-form-compact .panel-subtitle, .pv-form-compact .client-help { display:none !important; }
.pv-close-btn { width:40px; height:40px; min-width:40px; min-height:40px; padding:0; border-radius:999px; border:1.5px solid var(--accent-soft-2); background:rgba(255,255,255,.78); color:var(--accent-deep); box-shadow:0 8px 22px rgba(15,23,42,.08); font-size:20px; font-weight:900; line-height:1; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; transition:all .14s ease; }
.pv-close-btn:hover { border-color:var(--pz-reb, #FECACA); background:var(--pz-res, #FEF2F2); color:var(--tone-danger); box-shadow:none; }
.panel-body { padding:14px 16px; }
.alert { border-radius:14px; padding:11px 13px; margin-bottom:12px; font-weight:800; font-size:13px; }
.alert.error { background:var(--danger-soft); color:var(--danger); border:1px solid rgba(180,35,24,.16); }
.alert.success { background:var(--success-soft); color:var(--success); border:1px solid rgba(31,111,84,.16); }
.filter-form { display:grid; grid-template-columns:minmax(220px,1fr) minmax(150px,.45fr) minmax(130px,.35fr) auto; gap:10px; align-items:end; }
.pv-form-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
.pv-basis-grid { display:grid; grid-template-columns:minmax(150px,.35fr) minmax(220px,1fr); gap:8px; align-items:center; }
.field label { display:block; font-size:12px; font-weight:850; color:var(--muted); margin-bottom:5px; }
.field input,.field select,.field textarea { width:100%; border:1px solid var(--accent-soft-2); border-radius:12px; background:#fff; color:var(--text); padding:10px 11px; font-size:13px; outline:none; transition:border-color .14s ease, box-shadow .14s ease; }
.field input:hover:not(:focus), .field select:hover:not(:focus), .field textarea:hover:not(:focus) { border-color:var(--accent); }
.field input:focus, .field select:focus, .field textarea:focus { border-color:var(--accent); box-shadow:var(--focus-ring); }

/* === AUTOCOMPLETE - cautare client live === */
.pz-autocomplete { position:relative; }
.pz-autocomplete-input { width:100%; padding:10px 38px 10px 38px; border:1px solid var(--accent-soft-2); border-radius:12px; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748B' stroke-width='2.2' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='m21 21-4.3-4.3'/%3E%3C/svg%3E") no-repeat 12px center; font-size:13px; color:var(--text); outline:none; transition:border-color .14s ease, box-shadow .14s ease; }
.pz-autocomplete-input:hover { border-color:var(--accent); }
.pz-autocomplete-input:focus { border-color:var(--accent); box-shadow:var(--focus-ring); }
.pz-autocomplete-clear { position:absolute; right:10px; top:50%; transform:translateY(-50%); width:22px; height:22px; border-radius:50%; border:0; background:var(--surface-soft); color:var(--muted); cursor:pointer; font-size:14px; line-height:1; padding:0; display:none; align-items:center; justify-content:center; }
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

/* === TOGGLE PILLS pentru servicii === */
.pz-pills { display:flex; flex-wrap:wrap; gap:8px; }
.pz-pill { position:relative; min-height:36px; display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:8px 16px; border-radius:6px; border:1.5px solid #FED7AA; background:#FFF7ED; color:#9A3412; font-size:13px; font-weight:500; cursor:pointer; transition:background .14s ease, border-color .14s ease, color .14s ease, font-weight .14s ease; user-select:none; box-sizing:border-box; }
.pz-pill:hover { border-color:#FB923C; background:#FFEDD5; }
.pz-pill.is-active { background:var(--pz-bl, #2563EB); border-color:var(--pz-bld, #1D4ED8); color:#FFFFFF; font-weight:600; }
.pz-pill.is-active:hover { background:var(--pz-bld, #1D4ED8); border-color:var(--pz-bld, #1D4ED8); color:#FFFFFF; }
.pz-pill.is-active::before { content:''; display:inline-block; width:14px; height:14px; flex:0 0 14px; background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><polyline points='5 12 10 17 19 7'/></svg>"); background-repeat:no-repeat; background-position:center; background-size:14px 14px; }
.pz-pills.is-invalid .pz-pill:not(.is-active) { border-color:#EF4444; background:#FEF2F2; color:#991B1B; }
@media (max-width: 700px) {
    #servicesPills { flex-wrap: nowrap !important; gap: 5px !important; }
    #servicesPills .pz-pill { flex: 1 1 0; min-width: 0; padding: 6px 4px !important; font-size: 11px !important; white-space: nowrap; gap: 4px; }
    #servicesPills .pz-pill.is-active::before { width: 12px; height: 12px; background-size: 12px 12px; flex: 0 0 12px; }
}
#materialsPanel.is-invalid { outline:2px solid rgba(220,38,38,.55); outline-offset:6px; border-radius:12px; }

/* === LOCATIE smart - cazul cu o singura locație === */
.pz-location-info { display:flex; align-items:center; gap:10px; padding:10px 12px; background:var(--surface-soft); border:1px solid var(--border2); border-radius:12px; color:var(--text); font-size:13px; font-weight:600; }
.pz-location-info .pl-label { color:var(--muted); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }

/* === BANNER autofill din ultimul PV === */
.pz-autofill-banner { display:none; align-items:center; gap:12px; padding:11px 14px; background:var(--tone-info-bg); border:1px solid rgba(29,78,216,.22); border-left:3px solid var(--tone-info); border-radius:12px; color:var(--tone-info); font-size:13px; font-weight:600; margin:10px 0; }
.pz-autofill-banner.is-visible { display:flex; }
.pz-autofill-banner .pa-text { flex:1; }
.pz-autofill-banner .pa-actions { display:inline-flex; gap:6px; }
.pz-autofill-banner .pa-btn { padding:5px 11px; border-radius:8px; border:0; background:var(--tone-info); color:#fff; font-size:12px; font-weight:700; cursor:pointer; }
.pz-autofill-banner .pa-btn.secondary { background:#fff; color:var(--tone-info); border:1px solid rgba(29,78,216,.30); }

/* === SECTIUNI colapsabile (biocide) === */
.pz-collapsed .panel-body { display:none; }
.pz-collapse-hint { font-size:12px; font-weight:700; color:var(--muted); margin-left:8px; }
.field textarea { min-height:78px; resize:vertical; }
.field input:focus,.field select:focus,.field textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }
#pvServicesSelect { min-height:118px; padding:8px 10px; }
#pvServicesSelect option { padding:7px 8px; }
.field.full { grid-column:1 / -1; }
.field.span2 { grid-column:span 2; }
.client-help { margin-top:6px; color:var(--muted); font-size:12px; line-height:1.4; }
.items-wrap { overflow-x:auto; }
.items-table { width:100%; border-collapse:separate; border-spacing:0 8px; min-width:980px; }
.materials-table { min-width:1260px; }
.pv-material-cards { display:grid; gap:12px; }
.pv-material-card { border:1px solid var(--pz-line, #E2E8F0); background:var(--pz-surf, #FFFFFF); border-radius:18px; padding:12px; box-shadow:none; }
.pv-material-card-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
.pv-material-card-title { font-size:13px; font-weight:950; color:var(--accent-deep); }
.pv-material-grid { display:grid; grid-template-columns:1.4fr 1fr; gap:10px; }
.pv-material-mini-grid { display:grid; grid-template-columns:1fr .8fr .58fr 1fr; gap:10px; margin-top:10px; }
.pv-manual-extra { margin-top:12px; padding-top:12px; border-top:1px dashed rgba(100,116,139,.28); }
.pv-manual-extra-title { font-size:11px; font-weight:950; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; margin-bottom:8px; }
.pv-material-card label { display:block; font-size:11px; font-weight:900; color:var(--muted); margin-bottom:5px; text-transform:uppercase; letter-spacing:.035em; }
.pv-material-card input,.pv-material-card select { width:100%; border:1px solid var(--accent-soft-2); border-radius:12px; padding:9px 10px; background:#fff; color:var(--text); font-size:13px; outline:none; }
.pv-material-card input:focus,.pv-material-card select:focus { border-color:var(--accent); box-shadow:var(--focus-ring); }
.quantity-input { appearance:textfield; -moz-appearance:textfield; }
.quantity-input::-webkit-outer-spin-button,.quantity-input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
.pv-stock-hint { margin-top:7px; padding:8px 10px; border-radius:12px; background:rgba(17,96,183,.06); border:1px solid rgba(17,96,183,.12); color:var(--muted); font-size:11.5px; font-weight:750; line-height:1.35; }
.pv-quick-materials .pv-add-material-wrap { justify-content:center !important; }
@media (max-width:760px) { .pv-material-grid,.pv-material-mini-grid { grid-template-columns:1fr; } .pv-material-card { padding:11px; border-radius:16px; } }
.items-table th { text-align:left; font-size:11px; color:var(--muted); font-weight:900; padding:0 6px; text-transform:uppercase; letter-spacing:.04em; }
.items-table td { background:var(--surface-soft); border-top:1px solid var(--border2); border-bottom:1px solid var(--border2); padding:7px 6px; vertical-align:top; }
.items-table td:first-child { border-left:1px solid var(--border2); border-radius:12px 0 0 12px; }
.items-table td:last-child { border-right:1px solid var(--border2); border-radius:0 12px 12px 0; }
.items-table input,.items-table select { width:100%; border:1px solid var(--border); border-radius:10px; padding:8px 9px; background:#fff; font-size:12px; }
.items-table textarea { width:100%; min-height:39px; border:1px solid var(--border); border-radius:10px; padding:8px 9px; background:#fff; font-size:12px; resize:vertical; }
.form-actions { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; margin-top:14px; }
.form-actions .right { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:38px; border-radius:12px; padding:0 13px; border:1px solid var(--border); background:#fff; color:var(--text); font-size:13px; font-weight:900; text-decoration:none; cursor:pointer; white-space:nowrap; }
.btn:hover { border-color:var(--accent); color:var(--accent-deep); }
.btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn.primary:hover { background:var(--accent-strong); color:#fff; }
.btn.dark { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn.danger { color:var(--danger); border-color:rgba(180,35,24,.28); background:#fff; }
.btn.small { min-height:32px; padding:0 10px; font-size:12px; border-radius:10px; }
.switch-row { display:flex; align-items:center; gap:9px; font-weight:900; color:var(--text); }
.switch-row input { width:auto; }
.docs-list { display:grid; gap:10px; }
.doc-row { background:#fff; border:1px solid var(--border); border-radius:16px; box-shadow:var(--shadow); padding:13px 14px; display:grid; grid-template-columns:minmax(260px,1.15fr) minmax(150px,.45fr) minmax(150px,.45fr) minmax(120px,.35fr) auto; gap:12px; align-items:center; }
.doc-title { font-size:14px; font-weight:950; color:var(--text); overflow-wrap:anywhere; }
.doc-meta { color:var(--muted); font-size:12px; margin-top:4px; line-height:1.35; }
.badge { display:inline-flex; align-items:center; justify-content:center; border-radius:999px; padding:6px 9px; font-size:11px; font-weight:900; border:1px solid var(--border2); background:var(--surface-soft); color:var(--muted); white-space:nowrap; }
.badge.draft { background:var(--warning-soft); color:var(--warning); border-color:rgba(154,103,0,.18); }
.badge.issued { background:var(--success-soft); color:var(--success); border-color:rgba(31,111,84,.18); }
.badge.cancelled { background:var(--danger-soft); color:var(--danger); border-color:rgba(180,35,24,.16); }
.doc-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
.empty-state { padding:22px; text-align:center; color:var(--muted); font-weight:800; border:1px dashed var(--border); border-radius:16px; background:var(--surface-soft); }
.pagination { display:flex; gap:6px; justify-content:flex-end; align-items:center; flex-wrap:wrap; margin-top:12px; }
#materialsPanel.disabled { display:none; }
.pv-quick-hidden { display:none !important; }
.pv-quick-summary { border:1px solid var(--pz-blb, #BFDBFE); background:var(--pz-bls, #EFF6FF); border-radius:20px; padding:14px 16px; box-shadow:none; margin-bottom:14px; }
.pv-quick-summary-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
.pv-quick-summary-title { font-size:15px; font-weight:950; color:var(--accent-deep); }
.pv-quick-badge { display:inline-flex; align-items:center; justify-content:center; border-radius:999px; padding:6px 10px; background:rgba(17,96,183,.08); color:var(--accent); border:1px solid rgba(17,96,183,.16); font-size:11px; font-weight:900; white-space:nowrap; }
.pv-quick-summary-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px 14px; }
.pv-quick-item { min-width:0; }
.pv-quick-label { font-size:10.5px; text-transform:uppercase; letter-spacing:.04em; font-weight:900; color:var(--muted); margin-bottom:2px; }
.pv-quick-value { font-size:13px; font-weight:850; color:var(--text); overflow-wrap:anywhere; }
.pv-quick-materials .panel-head { padding-bottom:10px; }
.pv-quick-materials .panel-subtitle { display:none; }
.pv-quick-observatii .panel-subtitle { display:none; }
.pv-defer-consumption { margin:12px 0 14px; display:inline-flex; align-items:center; gap:9px; padding:8px 10px; border:1px solid rgba(220,38,38,.22); background:#fff7f7; color:#b91c1c; border-radius:8px; font-weight:850; font-size:12.5px; }
.pv-defer-consumption input { width:16px; height:16px; margin:0; accent-color:#dc2626; }
.pv-defer-consumption small { color:#b91c1c; font-weight:700; opacity:.78; }
@media (max-width:700px) { .pv-quick-summary-grid { grid-template-columns:1fr; } .pv-quick-summary { padding:13px; border-radius:18px; } }

/* === Secțiuni numerotate (aliniat cu Contracte/Oferte) === */
.contract-section { margin-bottom:22px; }
.contract-section:last-child { margin-bottom:0; }
.contract-section-head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px; }
.contract-section-titlewrap { display:flex; align-items:center; gap:10px; min-width:0; }
.contract-step-num { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:var(--pz-soft); border:1px solid var(--pz-line); color:var(--pz-fa); font-size:12px; font-weight:600; flex:0 0 22px; }
.contract-section-title { font-size:14px; font-weight:600; color:var(--pz-title); margin:0; }
.contract-section-hint { font-size:12px; color:var(--pz-mu); margin-left:6px; }

/* === Mobile optimizare zona 1. Document === */
@media (max-width:980px) {
    .pv-document-grid {
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:10px;
    }
    .pv-document-grid .pv-appointment-field,
    .pv-document-grid .pv-basis-field,
    .pv-document-grid .field.full {
        grid-column:1 / -1;
    }
    .pv-document-grid .pv-date-field,
    .pv-document-grid .pv-time-field,
    .pv-document-grid .pv-template-field,
    .pv-document-grid .pv-type-field {
        grid-column:span 1;
        min-width:0;
    }
    .pv-document-grid .field input,
    .pv-document-grid .field select {
        min-width:0;
    }
    .pv-basis-grid {
        grid-template-columns:minmax(172px, .58fr) minmax(0, .42fr);
        gap:8px;
    }
    #basisType {
        min-width:0;
        padding-left:9px;
        padding-right:26px;
        font-size:12.5px;
    }
    #contractSelect {
        min-width:0;
    }
}
@media (max-width:390px) {
    .pv-document-grid {
        gap:9px;
    }
    .pv-document-grid .field label {
        font-size:11.5px;
    }
    .pv-document-grid .field input,
    .pv-document-grid .field select {
        padding-left:8px;
        padding-right:8px;
        font-size:12px;
    }
    .pv-basis-grid {
        grid-template-columns:minmax(178px, .62fr) minmax(0, .38fr);
    }
}

@media (max-width:980px) { .filter-form,.pv-form-grid { grid-template-columns:1fr; } .field.span2 { grid-column:1; } .doc-row { grid-template-columns:1fr; } .doc-actions { justify-content:flex-start; } .pv-hero { padding:18px; } }

/* === Final mobile override - zona Document PV === */
@media (max-width:980px) {
    .pv-form-grid.pv-document-grid {
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:10px;
    }
    .pv-form-grid.pv-document-grid .pv-appointment-field,
    .pv-form-grid.pv-document-grid .pv-basis-field,
    .pv-form-grid.pv-document-grid .field.full {
        grid-column:1 / -1;
    }
    .pv-form-grid.pv-document-grid .pv-date-field,
    .pv-form-grid.pv-document-grid .pv-time-field,
    .pv-form-grid.pv-document-grid .pv-template-field,
    .pv-form-grid.pv-document-grid .pv-type-field {
        grid-column:span 1;
        min-width:0;
    }
    .pv-form-grid.pv-document-grid input,
    .pv-form-grid.pv-document-grid select {
        min-width:0;
    }
    .pv-form-grid.pv-document-grid .pv-basis-grid {
        grid-template-columns:minmax(172px, .58fr) minmax(0, .42fr);
        gap:8px;
    }
    .pv-form-grid.pv-document-grid #basisType {
        min-width:0;
        padding-left:9px;
        padding-right:26px;
        font-size:12.5px;
    }
    .pv-form-grid.pv-document-grid #contractSelect {
        min-width:0;
    }
}
@media (max-width:390px) {
    .pv-form-grid.pv-document-grid {
        gap:9px;
    }
    .pv-form-grid.pv-document-grid .field label {
        font-size:11.5px;
    }
    .pv-form-grid.pv-document-grid input,
    .pv-form-grid.pv-document-grid select {
        padding-left:8px;
        padding-right:8px;
        font-size:12px;
    }
    .pv-form-grid.pv-document-grid .pv-basis-grid {
        grid-template-columns:minmax(178px, .62fr) minmax(0, .38fr);
    }
}

</style>
<?php render_search_preview_assets(); ?>
</head>
<body>
<div class="layout">
    <?php render_sidebar('procese_verbale', $isAdmin); ?>

    <main class="main">
        <?php if (!$isAdmin): ?>
            <header class="topbar pv-topbar">
                <div class="pv-toolbar">
                    <a class="btn" href="calendar.php">Înapoi la calendar</a>
                </div>
            </header>
        <?php endif; ?>

        <div class="content">
            <?php if ($errorMessage): ?>
                <div class="alert error"><?= pz_pv_h($errorMessage) ?></div>
            <?php endif; ?>

            <?php if (!empty($_GET['saved'])): ?>
                <div class="alert success">Procesul verbal a fost salvat.</div>
            <?php endif; ?>

            <?php if (!empty($_GET['new']) || $editingDocument || $selectedAppointmentId > 0): ?>
                <section class="panel pv-form-compact" id="pvFormPanel">
                    <div class="panel-head">
                        <div>
                            <div class="panel-title"><?= !empty($isQuickPvFromAppointment) ? 'PV rapid din programare' : ($editingDocument ? 'Editează proces verbal draft' : 'Proces verbal nou') ?></div>
                            <div class="panel-subtitle"><?= !empty($isQuickPvFromAppointment) ? 'Completează produsele/materialele utilizate si observatiile. Datele lucrării sunt preluate automat.' : 'Completează datele PV, clientul, locatia, serviciile si materialele utilizate.' ?></div>
                        </div>
                        <a class="pv-close-btn" href="<?= $isTeamUser ? 'calendar.php' : 'service-reports' ?>" title="Inchide formularul" aria-label="Inchide formularul">&times;</a>
                    </div>
                    <div class="panel-body">
                        <form method="post" id="pvForm" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="document_id" value="<?= (int)($formDocument['id'] ?? 0) ?>">
                            <input type="hidden" name="title" value="<?= pz_pv_h($formDocument['title'] ?? '') ?>">
                            <input type="hidden" name="start_time" value="<?= pz_pv_h($editingPayload['start_time'] ?? '') ?>">
                            <input type="hidden" name="end_time" value="<?= pz_pv_h($editingPayload['end_time'] ?? '') ?>">
                            <?php if (!empty($isQuickPvFromAppointment)): ?>
                                <input type="hidden" id="pvQuickMode" value="1">
                            <?php endif; ?>

                            <?php /* Cardul „PV rapid din programare" cu rezumatul preluat automat a fost ascuns la cererea echipei – tehnicianul vede direct formularul. */ ?>

                            <div class="panel" style="box-shadow:none;">
                                <div class="panel-head">
                                    <div>
                                        <div class="panel-title" style="display:flex;align-items:center;gap:10px;"><span class="contract-step-num">1</span><span>Client și locație</span></div>
                                        <div class="panel-subtitle">Căutare după nume client, CUI, reprezentant, email sau telefon.</div>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <div class="pv-form-grid">
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

                                            <!-- Banner autofill din ultimul PV -->
                                            <div class="pz-autofill-banner" id="autofillBanner">
                                                <div class="pa-text" id="autofillText">Ultimul PV pentru acest client a fost emis recent.</div>
                                                <div class="pa-actions">
                                                    <button type="button" class="pa-btn" onclick="pzApplyAutofill()">Pre-completeaza</button>
                                                    <button type="button" class="pa-btn secondary" onclick="pzDismissAutofill()">Nu, multumesc</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="field span2" id="locationField">
                                            <label>Locație / punct de lucru</label>
                                            <!-- Wrapper - decizia de display se face in JS in functie de cate locații are clientul -->
                                            <select name="client_location_id" id="locationSelect" data-selected="<?= (int)($formDocument['client_location_id'] ?? 0) ?>" style="display:none;" required></select>
                                            <div class="pz-location-info" id="locationInfo" style="display:none;">
                                                <span class="pl-label">Folosim:</span>
                                                <span id="locationInfoText">Alege locație</span>
                                            </div>
                                            <div class="client-help" id="locationHelp">Selectează un client mai intai.</div>
                                        </div>
                                        <div class="field span2">
                                            <label>Zona/e tratate *</label>
                                            <?php
                                                $treatedAreasValue = (string)($editingPayload['treated_areas'] ?? '');
                                                if ($treatedAreasValue === '' && !empty($isQuickPvFromAppointment)) {
                                                    $treatedAreasValue = 'Întreaga locație';
                                                }
                                            ?>
                                            <input type="text" name="treated_areas" id="treatedAreas" value="<?= pz_pv_h($treatedAreasValue) ?>" placeholder="ex: bucătărie, depozit, grupuri sanitare" required>
                                        </div>
                                        <div class="field span2">
                                            <label>Suprafață *</label>
                                            <input type="text" name="surface_text" id="surfaceText" value="<?= pz_pv_h($editingPayload['surface_text'] ?? '') ?>" placeholder="ex: 250 mp interior + exterior" required>
                                        </div>
                                        <div class="field full">
                                            <label>Tehnician</label>
                                            <input type="text" name="workers_names" id="workersNames" value="<?= pz_pv_h($editingPayload['workers_names'] ?? '') ?>" placeholder="Tehnicianul care a executat lucrarea">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="panel <?= !empty($isQuickPvFromAppointment) ? 'pv-quick-hidden' : '' ?>" style="box-shadow:none; margin-top:14px;">
                                <div class="panel-head">
                                    <div>
                                        <div class="panel-title" style="display:flex;align-items:center;gap:10px;"><span class="contract-step-num">2</span><span>Document</span></div>
                                        <div class="panel-subtitle">Numarul PV se genereaza la emitere. Aici completezi data si ora.</div>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <div class="pv-form-grid pv-document-grid">
                                        <input type="hidden" name="appointment_id" id="appointmentSelect" value="<?= (int)($formDocument['appointment_id'] ?? $selectedAppointmentId ?? 0) ?>">
                                        <div class="field pv-date-field">
                                            <label>Data PV</label>
                                            <input type="date" name="document_date" value="<?= pz_pv_h($formDocument['document_date'] ?? date('Y-m-d')) ?>">
                                        </div>
                                        <div class="field pv-time-field">
                                            <label>Ora PV</label>
                                            <input type="time" name="document_time" value="<?= pz_pv_h(substr((string)($formDocument['document_time'] ?? date('H:i')), 0, 5)) ?>">
                                        </div>
                                        <?php
                                            $autoTemplateId = (int)($formDocument['template_id'] ?? 0);
                                            if ($autoTemplateId <= 0) {
                                                foreach ($templates as $template) {
                                                    if (!empty($template['is_default'])) { $autoTemplateId = (int)$template['id']; break; }
                                                }
                                                if ($autoTemplateId <= 0 && !empty($templates)) {
                                                    $autoTemplateId = (int)$templates[0]['id'];
                                                }
                                            }
                                        ?>
                                        <input type="hidden" name="template_id" value="<?= (int)$autoTemplateId ?>">
                                        <div class="field full pv-basis-field">
                                            <label>In baza</label>
                                            <?php
                                                $basisType = pz_pv_str($editingPayload['basis_type'] ?? '', 60);
                                                $selectedContractId = (int)($formDocument['contract_id'] ?? ($editingPayload['selected_contract_id'] ?? 0));
                                                if ($basisType === '' && $selectedContractId > 0) { $basisType = 'contract'; }
                                                if ($basisType === '') { $basisType = 'auto'; }
                                            ?>
                                            <div class="pv-basis-grid">
                                                <select name="basis_type" id="basisType" data-selected="<?= pz_pv_h($basisType) ?>">
                                                    <option value="auto" <?= $basisType === 'auto' ? 'selected' : '' ?>>Automat</option>
                                                    <option value="contract" <?= $basisType === 'contract' ? 'selected' : '' ?>>Contract existent</option>
                                                    <option value="nota_comanda" <?= $basisType === 'nota_comanda' ? 'selected' : '' ?>>Nota de comanda</option>
                                                    <option value="achizitie_directa" <?= $basisType === 'achizitie_directa' ? 'selected' : '' ?>>Achizitie directa</option>
                                                    <option value="manual" <?= $basisType === 'manual' ? 'selected' : '' ?>>Alta baza</option>
                                                </select>
                                                <select name="contract_id" id="contractSelect" data-selected="<?= (int)$selectedContractId ?>"></select>
                                                <input type="text" name="basis_manual_text" id="basisManualText" class="basis-manual" value="<?= pz_pv_h($editingPayload['basis_manual_text'] ?? '') ?>" placeholder="ex: Nota de comanda nr. 123 / Achizitie directa / alta baza">
                                            </div>
                                            <div class="client-help" id="basisHelp">Dacă există contract pentru client, se selecteaza automat. Altfel poți completa manual.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="panel" style="box-shadow:none; margin-top:14px;">
                                <div class="panel-head">
                                    <div>
                                        <div class="panel-title" style="display:flex;align-items:center;gap:10px;"><span class="contract-step-num">3</span><span>Servicii prestate</span></div>
                                        <div class="panel-subtitle">Selectează serviciile executate. În șablon folosește {{services_checks}} pentru afișarea cu bife.</div>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <div class="form-grid">
                                        <div class="field full">
                                            <label>Servicii executate</label>
                                            <div class="pz-pills" id="servicesPills" tabindex="-1">
                                                <?php foreach ($pvServiceChoices as $serviceKey => $serviceLabel):
                                                    $isActive = in_array($serviceKey, $selectedPvServices, true);
                                                ?>
                                                    <div class="pz-pill <?= $isActive ? 'is-active' : '' ?>"
                                                         data-key="<?= pz_pv_h($serviceKey) ?>"
                                                         onclick="pzTogglePill(this)"
                                                         role="checkbox"
                                                         aria-checked="<?= $isActive ? 'true' : 'false' ?>"
                                                         tabindex="0">
                                                        <?= pz_pv_h($serviceLabel) ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <!-- Hidden inputs - generate dinamic de JS la fiecare toggle -->
                                            <div id="servicesHidden">
                                                <?php foreach ($selectedPvServices as $serviceKey): ?>
                                                    <input type="hidden" name="pv_services[]" value="<?= pz_pv_h($serviceKey) ?>">
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="client-help">Click pe servicii pentru a le selecta. Vor apărea ca rând cu bife în PDF.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="panel pv-quick-materials" style="box-shadow:none; margin-top:14px;">
                                <div class="panel-head">
                                    <div>
                                        <div class="panel-title" style="display:flex;align-items:center;gap:10px;"><span class="contract-step-num">4</span><span>Produse / materiale utilizate</span></div>
                                        <div class="panel-subtitle">Aceste rânduri se transformă automat în tabelul {{materials_table}} din șablon.</div>
                                    </div>
                                    <?php if (!empty($isQuickPvFromAppointment)): ?>
                                        <input type="hidden" name="materials_enabled" id="materialsEnabled" value="1">
                                    <?php else: ?>
                                        <label class="switch-row">
                                            <input type="checkbox" name="materials_enabled" id="materialsEnabled" value="1" <?= $materialsEnabled ? 'checked' : '' ?> onchange="toggleMaterialsPanel()">
                                            Adaugă biocide / materiale
                                        </label>
                                    <?php endif; ?>
                                </div>
                                <div class="panel-body" id="materialsPanel" tabindex="-1">
                                    <?php if ($isAdmin): ?>
                                        <label class="pv-defer-consumption">
                                            <input type="checkbox" name="stock_consumption_deferred" value="1" <?= $stockConsumptionDeferred ? 'checked' : '' ?>>
                                            Emite fără consum stoc
                                            <small>Cantitatea se completează ulterior.</small>
                                        </label>
                                    <?php endif; ?>
                                    <?php if (!empty($isQuickPvFromAppointment)): ?>
                                        <div class="pv-material-cards" id="materialsBody">
                                            <?php foreach ($editingMaterials as $index => $material): ?>
                                                <?php
                                                    $isManualMaterialRow = empty($material['stock_product_id'])
                                                        && trim((string)($material['manual_material_name'] ?? ($material['material_name'] ?? ''))) !== '';
                                                ?>
                                                <?php if ($isManualMaterialRow): ?>
                                                <div class="material-row pv-material-card pv-manual-material-row">
                                                    <div class="pv-material-card-head">
                                                        <div class="pv-material-card-title">Produs fără stoc #<?= (int)$index + 1 ?></div>
                                                        <button type="button" class="btn small danger" onclick="removeMaterialRow(this)">Șterge</button>
                                                    </div>
                                                    <input type="hidden" name="materials[<?= (int)$index ?>][stock_product_id]" value="">
                                                    <input type="hidden" name="materials[<?= (int)$index ?>][stock_receipt_id]" value="">
                                                    <div class="pv-material-grid">
                                                        <div>
                                                            <label>Denumire</label>
                                                            <input type="text" name="materials[<?= (int)$index ?>][manual_material_name]" class="manual-material-name" value="<?= pz_pv_h($material['manual_material_name'] ?? ($material['material_name'] ?? '')) ?>" placeholder="produs fără stoc">
                                                        </div>
                                                        <div>
                                                            <label>Nr. aviz</label>
                                                            <input type="text" name="materials[<?= (int)$index ?>][manual_aviz_no]" value="<?= pz_pv_h($material['manual_aviz_no'] ?? ($material['aviz_no'] ?? '')) ?>" placeholder="opțional">
                                                        </div>
                                                    </div>
                                                    <div class="pv-material-mini-grid">
                                                        <div>
                                                            <label>Lot</label>
                                                            <input type="text" name="materials[<?= (int)$index ?>][manual_lot_number]" class="manual-lot-number" value="<?= pz_pv_h($material['manual_lot_number'] ?? ($material['lot_number'] ?? '')) ?>" placeholder="opțional">
                                                        </div>
                                                        <div>
                                                            <label>Valabilitate opțională</label>
                                                            <input type="text" name="materials[<?= (int)$index ?>][manual_expiry_date]" value="<?= pz_pv_h($material['manual_expiry_date'] ?? ($material['expiry_date'] ?? '')) ?>" placeholder="ex: 31.12.2027">
                                                        </div>
                                                        <div>
                                                            <label>Diluție</label>
                                                            <input type="text" name="materials[<?= (int)$index ?>][manual_work_concentration]" value="<?= pz_pv_h($material['manual_work_concentration'] ?? ($material['work_concentration'] ?? '')) ?>" placeholder="ex: 1%">
                                                        </div>
                                                    </div>
                                                    <div class="pv-material-mini-grid">
                                                        <div>
                                                            <label>Cantitate</label>
                                                            <input type="text" inputmode="decimal" class="quantity-input" name="materials[<?= (int)$index ?>][manual_quantity]" value="<?= pz_pv_h($material['manual_quantity'] ?? ($material['quantity'] ?? '')) ?>" placeholder="cant.">
                                                        </div>
                                                        <div>
                                                            <label>UM</label>
                                                            <select name="materials[<?= (int)$index ?>][manual_unit]" class="manual-unit">
                                                                <?php foreach (['' => 'Alege', 'ml' => 'ml', 'l' => 'l', 'g' => 'g', 'kg' => 'kg', 'buc' => 'buc', 'plic' => 'plic', 'capcana' => 'capcana', 'doza' => 'doza', 'set' => 'set'] as $value => $label): ?>
                                                                    <option value="<?= pz_pv_h($value) ?>" <?= (($material['manual_unit'] ?? ($material['unit'] ?? '')) === $value) ? 'selected' : '' ?>><?= pz_pv_h($label) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label>Aplicare</label>
                                                            <select name="materials[<?= (int)$index ?>][manual_application_method]">
                                                                <?php foreach (['' => 'Alege', 'pulverizare' => 'Pulverizare', 'aplicare directa' => 'Aplicare directa', 'nebulizare' => 'Nebulizare', 'amplasare' => 'Amplasare'] as $value => $label): ?>
                                                                    <option value="<?= pz_pv_h($value) ?>" <?= (($material['manual_application_method'] ?? ($material['application_method'] ?? '')) === $value) ? 'selected' : '' ?>><?= pz_pv_h($label) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <input type="hidden" name="materials[<?= (int)$index ?>][application_method_custom]" value="">
                                                    <input type="hidden" name="materials[<?= (int)$index ?>][application_area]" value="">
                                                    <input type="hidden" name="materials[<?= (int)$index ?>][notes]" value="">
                                                </div>
                                                <?php else: ?>
                                                <div class="material-row pv-material-card">
                                                    <div class="pv-material-card-head">
                                                        <div class="pv-material-card-title">Produs utilizat #<?= (int)$index + 1 ?></div>
                                                        <button type="button" class="btn small danger" onclick="removeMaterialRow(this)">Șterge</button>
                                                    </div>
                                                    <div class="pv-material-grid">
                                                        <div>
                                                            <label>Produs / material</label>
                                                            <select name="materials[<?= (int)$index ?>][stock_product_id]" class="product-select" data-selected="<?= (int)($material['stock_product_id'] ?? 0) ?>" onchange="syncProductRow(this)"></select>
                                                            <input type="hidden" name="materials[<?= (int)$index ?>][product_group]" class="product-group" value="<?= pz_pv_h($material['product_group'] ?? '') ?>">
                                                            <input type="hidden" name="materials[<?= (int)$index ?>][safety_measures]" class="safety-measures" value="<?= pz_pv_h($material['safety_measures'] ?? '') ?>">
                                                            <input type="hidden" class="material-unit-cache" value="<?= pz_pv_h($material['unit'] ?? '') ?>">
                                                            <input type="hidden" name="materials[<?= (int)$index ?>][aviz_no]" class="aviz-no" value="<?= pz_pv_h($material['aviz_no'] ?? '') ?>">
                                                            <input type="hidden" name="materials[<?= (int)$index ?>][expiry_date]" class="expiry-date" value="<?= pz_pv_h($material['expiry_date'] ?? '') ?>">
                                                        </div>
                                                        <div>
                                                            <label>Lot / stoc</label>
                                                            <select name="materials[<?= (int)$index ?>][stock_receipt_id]" class="receipt-select" data-selected="<?= (int)($material['stock_receipt_id'] ?? 0) ?>" onchange="syncLotRow(this)"></select>
                                                            <div class="pv-stock-hint">Datele din stoc se preiau automat: aviz, lot, valabilitate si UM.</div>
                                                        </div>
                                                    </div>
                                                    <div class="pv-material-mini-grid">
                                                        <div>
                                                            <label>Dilutie</label>
                                                            <input type="text" name="materials[<?= (int)$index ?>][work_concentration]" class="work-concentration" value="<?= pz_pv_h(!empty($material['stock_product_id']) ? ($material['work_concentration'] ?? '') : '') ?>" placeholder="ex: 1%">
                                                        </div>
                                                        <div>
                                                            <label>Cantitate</label>
                                                            <input type="text" inputmode="decimal" class="quantity-input" name="materials[<?= (int)$index ?>][quantity]" value="<?= pz_pv_h(!empty($material['stock_product_id']) ? ($material['quantity'] ?? '') : '') ?>" placeholder="cant.">
                                                        </div>
                                                        <div>
                                                            <label>UM</label>
                                                            <input type="text" name="materials[<?= (int)$index ?>][unit]" class="material-unit" value="<?= pz_pv_h($material['unit'] ?? '') ?>" readonly placeholder="-">
                                                        </div>
                                                        <div>
                                                            <label>Metoda aplicare</label>
                                                            <select name="materials[<?= (int)$index ?>][application_method]" class="application-method">
                                                                <?php foreach (['' => 'Alege', 'pulverizare' => 'Pulverizare', 'aplicare directa' => 'Aplicare directa', 'nebulizare' => 'Nebulizare', 'amplasare' => 'Amplasare'] as $value => $label): ?>
                                                                    <option value="<?= pz_pv_h($value) ?>" <?= (!empty($material['stock_product_id']) && (($material['application_method'] ?? '') === $value)) ? 'selected' : '' ?>><?= pz_pv_h($label) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <input type="hidden" name="materials[<?= (int)$index ?>][application_method_custom]" value="">
                                                    <input type="hidden" name="materials[<?= (int)$index ?>][application_area]" value="">
                                                    <input type="hidden" name="materials[<?= (int)$index ?>][notes]" value="">
                                                </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="items-wrap">
                                            <table class="items-table materials-table">
                                                <thead>
                                                    <tr>
                                                        <th style="width:260px;">Denumire produs/material</th>
                                                        <th style="width:150px;">Nr. aviz</th>
                                                        <th style="width:190px;">Lot</th>
                                                        <th style="width:145px;">Data valabilitate</th>
                                                        <th style="width:130px;">Dilutie</th>
                                                        <th style="width:125px;">Cantitate utilizata</th>
                                                        <th style="width:80px;">UM</th>
                                                        <th style="width:170px;">Metoda aplicare</th>
                                                        <th style="width:70px;"></th>
                                                    </tr>
                                                </thead>
                                                <tbody id="materialsBody">
                                                    <?php foreach ($editingMaterials as $index => $material): ?>
                                                        <?php
                                                            $isManualMaterialRow = empty($material['stock_product_id'])
                                                                && trim((string)($material['manual_material_name'] ?? ($material['material_name'] ?? ''))) !== '';
                                                        ?>
                                                        <?php if ($isManualMaterialRow): ?>
                                                            <tr class="material-row pv-manual-material-row">
                                                                <td>
                                                                    <input type="hidden" name="materials[<?= (int)$index ?>][stock_product_id]" value="">
                                                                    <input type="text" name="materials[<?= (int)$index ?>][manual_material_name]" class="manual-material-name" value="<?= pz_pv_h($material['manual_material_name'] ?? ($material['material_name'] ?? '')) ?>" placeholder="produs fără stoc">
                                                                </td>
                                                                <td><input type="text" name="materials[<?= (int)$index ?>][manual_aviz_no]" value="<?= pz_pv_h($material['manual_aviz_no'] ?? ($material['aviz_no'] ?? '')) ?>" placeholder="opțional"></td>
                                                                <td>
                                                                    <input type="hidden" name="materials[<?= (int)$index ?>][stock_receipt_id]" value="">
                                                                    <input type="text" name="materials[<?= (int)$index ?>][manual_lot_number]" class="manual-lot-number" value="<?= pz_pv_h($material['manual_lot_number'] ?? ($material['lot_number'] ?? '')) ?>" placeholder="opțional">
                                                                </td>
                                                                <td><input type="text" name="materials[<?= (int)$index ?>][manual_expiry_date]" value="<?= pz_pv_h($material['manual_expiry_date'] ?? ($material['expiry_date'] ?? '')) ?>" placeholder="ex: 31.12.2027"></td>
                                                                <td><input type="text" name="materials[<?= (int)$index ?>][manual_work_concentration]" value="<?= pz_pv_h($material['manual_work_concentration'] ?? ($material['work_concentration'] ?? '')) ?>" placeholder="ex: 1%"></td>
                                                                <td><input type="text" inputmode="decimal" class="quantity-input" name="materials[<?= (int)$index ?>][manual_quantity]" value="<?= pz_pv_h($material['manual_quantity'] ?? ($material['quantity'] ?? '')) ?>" placeholder="cant."></td>
                                                                <td>
                                                                    <select name="materials[<?= (int)$index ?>][manual_unit]" class="manual-unit">
                                                                        <?php foreach (['' => 'Alege', 'ml' => 'ml', 'l' => 'l', 'g' => 'g', 'kg' => 'kg', 'buc' => 'buc', 'plic' => 'plic', 'capcana' => 'capcana', 'doza' => 'doza', 'set' => 'set'] as $value => $label): ?>
                                                                            <option value="<?= pz_pv_h($value) ?>" <?= (($material['manual_unit'] ?? ($material['unit'] ?? '')) === $value) ? 'selected' : '' ?>><?= pz_pv_h($label) ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </td>
                                                                <td>
                                                                    <select name="materials[<?= (int)$index ?>][manual_application_method]">
                                                                        <?php foreach (['' => 'Alege', 'pulverizare' => 'Pulverizare', 'aplicare directa' => 'Aplicare directa', 'nebulizare' => 'Nebulizare', 'amplasare' => 'Amplasare'] as $value => $label): ?>
                                                                            <option value="<?= pz_pv_h($value) ?>" <?= (($material['manual_application_method'] ?? ($material['application_method'] ?? '')) === $value) ? 'selected' : '' ?>><?= pz_pv_h($label) ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </td>
                                                                <td>
                                                                    <input type="hidden" name="materials[<?= (int)$index ?>][application_method_custom]" value="">
                                                                    <input type="hidden" name="materials[<?= (int)$index ?>][application_area]" value="">
                                                                    <input type="hidden" name="materials[<?= (int)$index ?>][notes]" value="">
                                                                    <button type="button" class="btn small danger" onclick="removeMaterialRow(this)">Șterge</button>
                                                                </td>
                                                            </tr>
                                                        <?php else: ?>
                                                            <tr class="material-row">
                                                                <td>
                                                                    <select name="materials[<?= (int)$index ?>][stock_product_id]" class="product-select" data-selected="<?= (int)($material['stock_product_id'] ?? 0) ?>" onchange="syncProductRow(this)"></select>
                                                                    <input type="hidden" name="materials[<?= (int)$index ?>][product_group]" class="product-group" value="<?= pz_pv_h($material['product_group'] ?? '') ?>">
                                                                    <input type="hidden" name="materials[<?= (int)$index ?>][safety_measures]" class="safety-measures" value="<?= pz_pv_h($material['safety_measures'] ?? '') ?>">
                                                                    <input type="hidden" class="material-unit-cache" value="<?= pz_pv_h($material['unit'] ?? '') ?>">
                                                                </td>
                                                                <td><input type="text" name="materials[<?= (int)$index ?>][aviz_no]" class="aviz-no" value="<?= pz_pv_h($material['aviz_no'] ?? '') ?>"></td>
                                                                <td><select name="materials[<?= (int)$index ?>][stock_receipt_id]" class="receipt-select" data-selected="<?= (int)($material['stock_receipt_id'] ?? 0) ?>" onchange="syncLotRow(this)"></select></td>
                                                                <td><input type="date" name="materials[<?= (int)$index ?>][expiry_date]" class="expiry-date" value="<?= pz_pv_h($material['expiry_date'] ?? '') ?>"></td>
                                                                <td><input type="text" name="materials[<?= (int)$index ?>][work_concentration]" class="work-concentration" value="<?= pz_pv_h($material['work_concentration'] ?? '') ?>" placeholder="ex: 1%"></td>
                                                                <td><input type="text" inputmode="decimal" class="quantity-input" name="materials[<?= (int)$index ?>][quantity]" value="<?= pz_pv_h($material['quantity'] ?? '') ?>" placeholder="cant."></td>
                                                                <td><input type="text" name="materials[<?= (int)$index ?>][unit]" class="material-unit" value="<?= pz_pv_h($material['unit'] ?? '') ?>" readonly placeholder="-"></td>
                                                                <td>
                                                                    <select name="materials[<?= (int)$index ?>][application_method]" class="application-method">
                                                                        <?php foreach (['' => 'Alege', 'pulverizare' => 'Pulverizare', 'aplicare directa' => 'Aplicare directa', 'nebulizare' => 'Nebulizare', 'amplasare' => 'Amplasare'] as $value => $label): ?>
                                                                            <option value="<?= pz_pv_h($value) ?>" <?= (($material['application_method'] ?? '') === $value) ? 'selected' : '' ?>><?= pz_pv_h($label) ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </td>
                                                                <td>
                                                                    <input type="hidden" name="materials[<?= (int)$index ?>][application_method_custom]" value="">
                                                                    <input type="hidden" name="materials[<?= (int)$index ?>][application_area]" value="">
                                                                    <input type="hidden" name="materials[<?= (int)$index ?>][notes]" value="">
                                                                    <button type="button" class="btn small danger" onclick="removeMaterialRow(this)">Șterge</button>
                                                                </td>
                                                            </tr>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                    <div class="pv-add-material-wrap" style="margin-top:10px; display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap;">
                                        <button class="btn small danger" type="button" onclick="addManualMaterialRow()">+ Produs fără stoc</button>
                                        <button class="btn small primary" type="button" onclick="addMaterialRow()">+ Adaugă produs</button>
                                    </div>
                                </div>                            </div>

                            <div class="panel pv-quick-observatii" style="box-shadow:none; margin-top:14px;">
                                <div class="panel-head">
                                    <div>
                                        <div class="panel-title" style="display:flex;align-items:center;gap:10px;"><span class="contract-step-num">5</span><span>Observații executant</span></div>
                                        <div class="panel-subtitle">Singurul camp care apare pe procesul verbal.</div>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <div class="pv-form-grid">
                                        <div class="field full">
                                            <label>Observatii executant</label>
                                            <textarea name="executor_notes" placeholder="Ce a constatat / efectuat tehnicianul"><?= pz_pv_h($formDocument['executor_notes'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php
                                // Ștampila firmei: pre-bifata automat pentru operatori (team), neobligatoriu pentru admin.
                                $stampChecked = false;
                                if (isset($formDocument['apply_company_stamp'])) {
                                    $stampChecked = !empty($formDocument['apply_company_stamp']);
                                } elseif ($isTeamUser) {
                                    $stampChecked = true;
                                }
                            ?>
                            <?php if ($isTeamUser): ?>
                                <input type="hidden" name="apply_company_stamp" value="1">
                            <?php else: ?>
                                <div style="margin:14px 0;padding:12px 14px;border:1px solid var(--border);border-radius:12px;background:#f8fafc;">
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:700;color:var(--text);">
                                        <input type="checkbox" name="apply_company_stamp" value="1" <?= $stampChecked ? 'checked' : '' ?> style="width:18px;height:18px;">
                                        <span>Aplică ștampila firmei pe acest PV</span>
                                    </label>
                                    <div style="margin-top:6px;font-size:12px;color:var(--muted);padding-left:28px;">
                                        Ștampila încărcată în <em>Setări → Design documente</em> apare lângă semnătura emitent. Operatorii din teren primesc bifa automat.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="form-actions">
                                <a class="btn" href="<?= $isTeamUser ? 'calendar.php' : 'service-reports' ?>">Renunță</a>
                                <div class="right">
                                    <?php if ($isAdmin): ?>
                                        <button class="btn" type="submit" name="action" value="save_draft">Salvează draft</button>
                                    <?php endif; ?>
                                    <button class="btn primary" type="submit" name="action" value="issue" data-confirm-issue="Emiti procesul verbal si generezi numar?">Emite PV</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($isAdmin && empty($_GET['new']) && empty($editingDocument) && $selectedAppointmentId <= 0): ?>
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <div class="pz-page-eyebrow">Documente</div>
                        <div class="panel-title">Lista procese verbale</div>
                    </div>
                
                    <a class="pz-icon-btn primary lg" title="PV nou" aria-label="PV nou" href="service-reports?new=1<?= $filterClientId > 0 ? '&client_id=' . (int)$filterClientId : '' ?>"><?= app_icon_svg('plus') ?></a>
                </div>
                <div class="panel-body">
                    <form class="filter-form" method="get">
                        <?php if ($filterClientId > 0): ?>
                            <input type="hidden" name="client_id" value="<?= (int)$filterClientId ?>">
                        <?php endif; ?>
                        <div class="field">
                            <label>Căutare</label>
                            <div class="pz-search-wrap">
                                <input type="text" id="pvSearchInput" name="q" value="<?= pz_pv_h($q) ?>" placeholder="Caută" autocomplete="off">
                                <div class="pz-search-preview"></div>
                            </div>
                        </div>
                        <div class="field">
                            <label>Status</label>
                            <select name="status">
                                <option value="">Toate</option>
                                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="issued" <?= $status === 'issued' ? 'selected' : '' ?>>Emis</option>
                                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Anulat</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Rânduri</label>
                            <select name="per_page">
                                <?php foreach ([20, 50, 100] as $n): ?>
                                    <option value="<?= (int)$n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= (int)$n ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn primary" type="submit">Filtrează</button>
                    </form>
                </div>
            </section>

            <?php if (!$documents): ?>
                <div class="empty-state">Nu există procese verbale pentru filtrarea curenta.</div>
            <?php else: ?>
                <div class="docs-list">
                    <?php foreach ($documents as $doc): ?>
                        <article class="doc-row">
                            <div>
                                <div class="doc-title">
                                    <?= pz_pv_h($doc['document_number'] ?: 'PV draft #' . (int)$doc['id']) ?>
                                    <span class="badge <?= pz_pv_h(pz_pv_status_class($doc['status'] ?? 'draft')) ?>"><?= pz_pv_h(pz_pv_status_label($doc['status'] ?? 'draft')) ?></span>
                                </div>
                                <div class="doc-meta">
                                    <?= pz_pv_h($doc['client_name_snapshot'] ?: '-') ?><br>
                                    <?= pz_pv_h($doc['location_name_snapshot'] ?: ($doc['location_address_snapshot'] ?: '-')) ?>
                                </div>
                            </div>
                            <div class="doc-meta">
                                Data PV<br>
                                <strong><?= pz_pv_h(pz_pv_date_ro($doc['document_date'] ?? null)) ?>, <?= pz_pv_h(pz_pv_time_ro($doc['document_time'] ?? null)) ?></strong>
                            </div>
                            <div class="doc-meta">
                                Titlu<br>
                                <strong><?= pz_pv_h($doc['title'] ?: '-') ?></strong>
                            </div>
                            <div class="doc-meta">
                                Creat<br>
                                <strong><?= pz_pv_h(pz_pv_date_ro(substr((string)($doc['created_at'] ?? ''), 0, 10))) ?></strong>
                            </div>
                            <div class="doc-actions pz-actions">
                                <a class="pz-icon-btn" title="Vezi" aria-label="Vezi proces verbal" href="document_view.php?id=<?= (int)$doc['id'] ?>"><?= app_icon_svg('eye') ?></a>
                                <?php if (($doc['status'] ?? '') === 'draft'): ?>
                                    <a class="pz-icon-btn" title="Editează" aria-label="Editează draft" href="service-reports?edit=<?= (int)$doc['id'] ?>"><?= app_icon_svg('edit') ?></a>
                                <?php endif; ?>
                                <a class="pz-icon-btn" title="Deschide PDF" aria-label="Deschide PDF" href="document_pdf.php?id=<?= (int)$doc['id'] ?>&mode=inline" target="_blank"><?= app_icon_svg('pdf') ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a class="btn small" href="<?= pz_pv_h(pz_pv_current_url(['page' => $page - 1])) ?>">Înapoi</a>
                    <?php endif; ?>
                    <span class="badge">Pagina <?= (int)$page ?> / <?= (int)$totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a class="btn small" href="<?= pz_pv_h(pz_pv_current_url(['page' => $page + 1])) ?>">Înainte</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.4.3/dist/js/tom-select.complete.min.js"></script>
<script>
let clientTom = null;
let locationTom = null;
const clientsData = <?= json_encode($clientsForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const locationsData = <?= json_encode($locationsForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const servicesData = <?= json_encode($servicesForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const contractsData = <?= json_encode($contractsForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const productsData = <?= json_encode($productsForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const receiptsData = <?= json_encode($receiptsForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const appointmentsData = <?= json_encode($appointmentsForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function normalizeText(value) {
    return String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}
function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function(char) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char];
    });
}

function setSelectOptions(select, options, selectedValue) {
    if (!select) return;
    selectedValue = String(selectedValue || '');

    if (select.tomselect) {
        const ts = select.tomselect;
        ts.clear(true);
        ts.clearOptions();
        options.forEach(option => ts.addOption(option));
        ts.refreshOptions(false);
        ts.setValue(selectedValue, true);
        return;
    }

    select.innerHTML = '';
    options.forEach(option => {
        const opt = document.createElement('option');
        opt.value = option.value;
        opt.textContent = option.text;
        if (String(option.value) === selectedValue) opt.selected = true;
        select.appendChild(opt);
    });
}

/* === AUTOCOMPLETE CLIENT - cautare smart (nume + CUI + telefon + reprezentant + email) === */
let pzClientActiveIndex = -1;
let pzClientCurrentResults = [];

function pzNormalize(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

function pzClientHighlight(text, query) {
    if (!query) return escapeHtml(text);
    const norm = pzNormalize(text);
    const qNorm = pzNormalize(query);
    const idx = norm.indexOf(qNorm);
    if (idx < 0) return escapeHtml(text);
    return escapeHtml(text.slice(0, idx))
        + '<mark>' + escapeHtml(text.slice(idx, idx + query.length)) + '</mark>'
        + escapeHtml(text.slice(idx + query.length));
}

function pzClientSearch(query) {
    const q = pzNormalize(query);
    if (q.length < 1) return [];
    const results = [];
    for (const c of clientsData) {
        const haystack = pzNormalize(
            (c.name || '') + ' ' +
            (c.fiscal_code || '') + ' ' +
            (c.phone || '') + ' ' +
            (c.representative || '') + ' ' +
            (c.email || '')
        );
        if (haystack.indexOf(q) >= 0) {
            results.push(c);
            if (results.length >= 30) break;
        }
    }
    // Sortam: cei care incep cu query primii
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
        const meta = [
            c.fiscal_code ? 'CUI ' + escapeHtml(c.fiscal_code) : '',
            c.representative ? escapeHtml(c.representative) : '',
            c.phone ? escapeHtml(c.phone) : ''
        ].filter(Boolean).join(' · ');
        return '<div class="pz-autocomplete-result" data-index="' + i + '" onclick="pzClientPick(' + i + ')">'
            + '<div class="ar-name">' + pzClientHighlight(c.name || '', query) + '</div>'
            + (meta ? '<div class="ar-meta">' + meta + '</div>' : '')
            + '</div>';
    }).join('');
}

function pzClientPick(index) {
    const c = pzClientCurrentResults[index];
    if (!c) return;
    pzClientSetSelected(c);
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
    populateBasisContracts(true);
    pzCheckLastPv(client.id);
    pzValidateForm();

    // Dupa selectarea clientului, mutam focusul pe urmatorul camp obligatoriu liber.
    // De obicei "Zona/e tratate" (treatedAreas) e urmatorul, dar daca operatorul nu
    // a apucat sa-l completeze inca, sarim acolo. Daca exista, focus la el; altfel
    // incercam locationSelect / surfaceText ca fallback.
    setTimeout(function () {
        var nextTargets = ['treatedAreas', 'surfaceText'];
        for (var i = 0; i < nextTargets.length; i++) {
            var el = document.getElementById(nextTargets[i]);
            if (el && !el.value) { el.focus(); return; }
        }
        // Daca toate sunt deja completate, focus la prima ne-completata oricum.
        var el2 = document.getElementById('treatedAreas');
        if (el2) el2.focus();
    }, 50);
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
    pzDismissAutofill();
    populateLocationsSmart();
    populateBasisContracts(true);
    pzValidateForm();
}

function initClientAutocomplete() {
    const wrap = document.getElementById('clientAutocomplete');
    const input = document.getElementById('clientSearchInput');
    const hidden = document.getElementById('clientSelect');
    if (!wrap || !input || !hidden) return;

    // Dacă avem deja un client selectat (la editare draft sau după redirect)
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
            if (q.length < 1) {
                wrap.classList.remove('is-open');
                document.getElementById('clientResults').innerHTML = '';
                return;
            }
            const results = pzClientSearch(q);
            pzRenderClientResults(results, q);
            wrap.classList.add('is-open');
        }, 150);
    });

    input.addEventListener('keydown', (e) => {
        const results = pzClientCurrentResults;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            pzClientActiveIndex = Math.min(pzClientActiveIndex + 1, results.length - 1);
            pzHighlightActive();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            pzClientActiveIndex = Math.max(pzClientActiveIndex - 1, 0);
            pzHighlightActive();
        } else if (e.key === 'Enter') {
            if (pzClientActiveIndex >= 0) {
                e.preventDefault();
                pzClientPick(pzClientActiveIndex);
            }
        } else if (e.key === 'Escape') {
            wrap.classList.remove('is-open');
        }
    });

    document.addEventListener('click', (e) => {
        if (!wrap.contains(e.target)) wrap.classList.remove('is-open');
    });
}

function pzHighlightActive() {
    const container = document.getElementById('clientResults');
    if (!container) return;
    container.querySelectorAll('.pz-autocomplete-result').forEach(el => el.classList.remove('is-active'));
    const target = container.querySelector('[data-index="' + pzClientActiveIndex + '"]');
    if (target) {
        target.classList.add('is-active');
        target.scrollIntoView({block: 'nearest'});
    }
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
        locationSelect.innerHTML = '<option value="">Alege clientul mai intai</option>';
        locationSelect.value = '';
        if (locationInfo) locationInfo.style.display = 'none';
        if (help) help.textContent = 'Selectează un client mai intai.';
        return;
    }

    const locations = locationsData.filter(loc => Number(loc.client_id) === clientId);
    const selectedId = String(locationSelect.dataset.selected || locationSelect.value || '');

    if (locations.length === 0) {
        locationSelect.style.display = 'block';
        locationSelect.innerHTML = '<option value="">Clientul nu are locații salvate</option>';
        locationSelect.value = '';
        if (locationInfo) locationInfo.style.display = 'none';
        if (help) help.textContent = 'Adaugă o locație in fișa clientului. Dacă intervenția se face la sediu, adauga sediul ca locație.';
    } else if (locations.length === 1 && !selectedId) {
        const loc = locations[0];
        locationSelect.style.display = 'none';
        locationSelect.innerHTML = '<option value="' + loc.id + '" selected>' + (loc.location_name || 'Punct de lucru') + '</option>';
        locationSelect.value = String(loc.id);
        if (locationInfo) {
            locationInfo.style.display = 'flex';
            locationInfoText.textContent = (loc.location_name || 'Punct de lucru') + (loc.address ? ' - ' + loc.address : '');
        }
        if (help) help.textContent = 'Singura locație salvata pentru client. Aceasta va aparea in document.';
    } else {
        const options = [{value: '', text: 'Alege locatia'}];
        locations.forEach(loc => {
            options.push({
                value: String(loc.id),
                text: (loc.location_name || 'Punct de lucru') + (loc.address ? ' - ' + loc.address : '') + (loc.contact_person ? ' / ' + loc.contact_person : '')
            });
        });
        if (locationInfo) locationInfo.style.display = 'none';
        locationSelect.style.display = 'block';
        locationSelect.innerHTML = '';
        options.forEach(opt => {
            const o = document.createElement('option');
            o.value = opt.value;
            o.textContent = opt.text;
            if (String(opt.value) === selectedId) o.selected = true;
            locationSelect.appendChild(o);
        });
        if (help) help.textContent = 'Clientul are ' + locations.length + ' locații. Alege locatia unde se executa serviciul.';
    }

    updateLocationHelp();
}


/* === In baza - contract automat sau completare manuala === */
function getContractsForCurrentClient() {
    const clientSelect = document.getElementById('clientSelect');
    const locationSelect = document.getElementById('locationSelect');
    const clientId = clientSelect ? Number(clientSelect.value || 0) : 0;
    const locationId = locationSelect ? Number(locationSelect.value || 0) : 0;
    if (!clientId) return [];

    const clientContracts = contractsData.filter(c => Number(c.client_id) === clientId);
    if (!locationId) return clientContracts;

    const matching = clientContracts.filter(c => {
        const ids = Array.isArray(c.location_ids) ? c.location_ids.map(Number) : [];
        return ids.indexOf(locationId) >= 0 || Number(c.client_location_id || 0) === locationId;
    });

    return matching.length ? matching : clientContracts;
}

function populateBasisContracts(forceAuto) {
    const basisType = document.getElementById('basisType');
    const contractSelect = document.getElementById('contractSelect');
    const manualInput = document.getElementById('basisManualText');
    const help = document.getElementById('basisHelp');
    if (!basisType || !contractSelect || !manualInput) return;

    const contracts = getContractsForCurrentClient();
    let selected = String(contractSelect.dataset.selected || contractSelect.value || '');
    const currentType = basisType.value || basisType.dataset.selected || 'auto';

    contractSelect.innerHTML = '';
    if (contracts.length) {
        contracts.forEach(c => {
            const opt = document.createElement('option');
            opt.value = String(c.id);
            opt.textContent = c.label || ('Contract nr. ' + (c.document_number || c.id));
            if (String(c.id) === selected) opt.selected = true;
            contractSelect.appendChild(opt);
        });

        if ((!selected || !contracts.some(c => String(c.id) === selected)) && (forceAuto || currentType === 'auto' || currentType === 'contract')) {
            selected = String(contracts[0].id);
            contractSelect.value = selected;
            contractSelect.dataset.selected = selected;
            basisType.value = 'contract';
        } else if (selected && contracts.some(c => String(c.id) === selected)) {
            contractSelect.value = selected;
        }
    } else {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'Nu există contract emis pentru acest client';
        contractSelect.appendChild(opt);
        contractSelect.value = '';
        if (currentType === 'auto' || currentType === 'contract') {
            basisType.value = 'nota_comanda';
        }
    }

    const useContract = basisType.value === 'contract' && contracts.length > 0;
    contractSelect.style.display = useContract ? 'block' : 'none';
    manualInput.style.display = useContract ? 'none' : 'block';

    if (help) {
        if (useContract) {
            const selectedContract = contracts.find(c => String(c.id) === String(contractSelect.value));
            help.textContent = selectedContract ? ('Se va afișa: Contract nr. ' + (selectedContract.document_number || selectedContract.id)) : 'Contractul este selectat automat.';
        } else if (basisType.value === 'achizitie_directa') {
            help.textContent = 'Se va afișa Achizitie directa sau textul completat manual.';
        } else if (basisType.value === 'manual') {
            help.textContent = 'Completează baza documentului exact cum vrei sa apara in PV.';
        } else {
            help.textContent = 'Se va afișa Nota de comanda sau textul completat manual.';
        }
    }
}

function bindBasisControls() {
    const basisType = document.getElementById('basisType');
    const contractSelect = document.getElementById('contractSelect');
    if (basisType && !basisType.dataset.bound) {
        basisType.dataset.bound = '1';
        basisType.addEventListener('change', function() {
            populateBasisContracts(false);
        });
    }
    if (contractSelect && !contractSelect.dataset.bound) {
        contractSelect.dataset.bound = '1';
        contractSelect.addEventListener('change', function() {
            contractSelect.dataset.selected = contractSelect.value || '0';
            const help = document.getElementById('basisHelp');
            const selectedContract = contractsData.find(c => String(c.id) === String(contractSelect.value));
            if (help && selectedContract) {
                help.textContent = 'Se va afișa: Contract nr. ' + (selectedContract.document_number || selectedContract.id);
            }
        });
    }
}

/* === Toggle pills servicii === */
function pzTogglePill(pillEl) {
    pillEl.classList.toggle('is-active');
    pillEl.setAttribute('aria-checked', pillEl.classList.contains('is-active') ? 'true' : 'false');
    pzSyncServicesHidden();
    pzValidateForm();
}

function pzSyncServicesHidden() {
    const container = document.getElementById('servicesHidden');
    if (!container) return;
    container.innerHTML = '';
    document.querySelectorAll('#servicesPills .pz-pill.is-active').forEach(pill => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'pv_services[]';
        inp.value = pill.dataset.key;
        container.appendChild(inp);
    });
}

function pzHasMaterialRows() {
    const enabledInput = document.getElementById('materialsEnabled');
    const enabled = enabledInput ? (enabledInput.type === 'hidden' ? String(enabledInput.value || '') === '1' : enabledInput.checked) : true;
    if (!enabled) return false;

    let hasMaterial = false;
    document.querySelectorAll('#materialsBody .material-row').forEach(row => {
        const productSelect = row.querySelector('.product-select');
        const manualName = row.querySelector('.manual-material-name');
        if (productSelect && Number(productSelect.value || productSelect.dataset.selected || 0) > 0) {
            hasMaterial = true;
        }
        if (manualName && manualName.value.trim() !== '') {
            hasMaterial = true;
        }
    });
    return hasMaterial;
}

function pzFocusPvTarget(target) {
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => {
        if (typeof target.focus === 'function') {
            target.focus({ preventScroll: true });
        }
    }, 220);
}

function pzFirstMissingTarget() {
    const clientSelect = document.getElementById('clientSelect');
    const clientBox = document.getElementById('clientAutocomplete');
    const locationSelect = document.getElementById('locationSelect');
    const locationField = document.getElementById('locationField');
    const treatedAreas = document.getElementById('treatedAreas');
    const surfaceText = document.getElementById('surfaceText');
    const servicesPills = document.getElementById('servicesPills');
    const materialsEnabled = document.getElementById('materialsEnabled');
    const materialsPanel = document.getElementById('materialsPanel');
    const firstProductSelect = document.querySelector('#materialsBody .product-select');
    const firstManualName = document.querySelector('#materialsBody .manual-material-name');

    if (clientSelect && !clientSelect.value) return clientBox || clientSelect;
    if (locationSelect && locationSelect.required && !locationSelect.value) return locationSelect.offsetParent ? locationSelect : locationField;
    if (treatedAreas && treatedAreas.required && treatedAreas.value.trim() === '') return treatedAreas;
    if (surfaceText && surfaceText.required && surfaceText.value.trim() === '') return surfaceText;
    if (servicesPills && document.querySelectorAll('#servicesHidden input[name="pv_services[]"]').length === 0) return servicesPills;
    if (materialsEnabled && materialsEnabled.type !== 'hidden' && !materialsEnabled.checked) return materialsEnabled;
    if (!pzHasMaterialRows()) return firstProductSelect || firstManualName || materialsPanel;
    return null;
}

function pzValidateBeforeSubmit(event) {
    const submitter = event && event.submitter ? event.submitter : null;
    const action = submitter && submitter.name === 'action' ? submitter.value : '';
    if (action && action !== 'issue') return true;

    pzValidateForm();
    const target = pzFirstMissingTarget();
    if (!target) {
        const message = submitter ? submitter.getAttribute('data-confirm-issue') : '';
        return message ? confirm(message) : true;
    }
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    pzFocusPvTarget(target);
    return false;
}

/* === Wrappers vechi pentru compatibilitate cu codul existent === */
function populateClients() {
    initClientAutocomplete();
}
function populateLocations() {
    populateLocationsSmart();
}

/* === AUTOFILL din ultimul PV emis pentru client === */
let pzAutofillData = null;

function pzCheckLastPv(clientId) {
    if (!clientId) return;
    fetch('service-reports?last_pv_for_client=' + encodeURIComponent(clientId), {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.ok ? r.json() : null)
    .then(data => {
        if (!data || !data.found) {
            pzAutofillData = null;
            pzDismissAutofill();
            return;
        }
        pzAutofillData = data;
        const banner = document.getElementById('autofillBanner');
        const text = document.getElementById('autofillText');
        if (banner && text) {
            text.textContent = 'Ultimul PV pentru acest client a fost emis pe ' + (data.date_label || data.date || 'recent') + '. Vrei sa pre-completezi cu aceleasi date?';
            banner.classList.add('is-visible');
        }
    })
    .catch(() => { /* silent fail */ });
}

function pzApplyAutofill() {
    if (!pzAutofillData) return;
    const d = pzAutofillData;

    // Suprafață
    if (d.surface_text) {
        const surf = document.getElementById('surfaceText');
        if (surf && !surf.value) surf.value = d.surface_text;
    }
    // Operatori
    if (d.workers_names) {
        const w = document.getElementById('workersNames');
        if (w && !w.value) w.value = d.workers_names;
    }
    // Servicii (re-aplica pe toggle pills)
    if (Array.isArray(d.services) && d.services.length) {
        document.querySelectorAll('#servicesPills .pz-pill').forEach(pill => {
            if (d.services.indexOf(pill.dataset.key) >= 0) {
                pill.classList.add('is-active');
                pill.setAttribute('aria-checked', 'true');
            }
        });
        pzSyncServicesHidden();
    }
    // Locație (dacă e in lista)
    if (d.client_location_id) {
        const ls = document.getElementById('locationSelect');
        if (ls) {
            ls.dataset.selected = String(d.client_location_id);
            populateLocationsSmart();
        }
    }

    pzDismissAutofill();
    pzValidateForm();
}

function pzDismissAutofill() {
    const banner = document.getElementById('autofillBanner');
    if (banner) banner.classList.remove('is-visible');
}

/* === VALIDARE simpla - dezactiveaza Emite PV dacă lipsesc campuri obligatorii === */
function pzValidateForm() {
    const clientId = document.getElementById('clientSelect') ? document.getElementById('clientSelect').value : '';
    const treatedAreas = document.getElementById('treatedAreas');
    const surfaceText = document.getElementById('surfaceText');
    const hasServices = document.querySelectorAll('#servicesHidden input[name="pv_services[]"]').length > 0;
    const hasMaterials = pzHasMaterialRows();
    const hasTreatedAreas = !treatedAreas || !treatedAreas.required || treatedAreas.value.trim() !== '';
    const hasSurface = !surfaceText || !surfaceText.required || surfaceText.value.trim() !== '';
    const servicesPills = document.getElementById('servicesPills');
    const materialsPanel = document.getElementById('materialsPanel');
    if (servicesPills) servicesPills.classList.toggle('is-invalid', !hasServices);
    if (materialsPanel) materialsPanel.classList.toggle('is-invalid', !hasMaterials);

    const issueBtn = document.querySelector('button[name="action"][value="issue"]');
    if (!issueBtn) return;

    const valid = clientId && hasTreatedAreas && hasSurface && hasServices && hasMaterials;
    issueBtn.disabled = false;
    issueBtn.setAttribute('aria-disabled', valid ? 'false' : 'true');
    issueBtn.style.opacity = '1';
    issueBtn.style.cursor = 'pointer';
    issueBtn.title = valid ? '' : 'Completează clientul, zona/zonele tratate, suprafața, cel puțin un serviciu și cel puțin un produs/material.';
}

function updateClientHelp(client) {
    const help = document.getElementById('clientHelp');
    if (!help) return;
    if (!client) { help.textContent = 'Caută direct in lista după nume client, CUI, reprezentant, email sau telefon.'; return; }
    const parts = [];
    if (client.fiscal_code) parts.push('CUI: ' + client.fiscal_code);
    if (client.representative) parts.push('Reprezentant: ' + client.representative);
    if (client.email) parts.push('Email: ' + client.email);
    if (client.phone) parts.push('Telefon: ' + client.phone);
    help.textContent = parts.length ? parts.join(' | ') : 'Client selectat.';
}

function updateLocationHelp() {
    const locationSelect = document.getElementById('locationSelect');
    const help = document.getElementById('locationHelp');
    const surface = document.getElementById('surfaceText');
    if (!locationSelect || !help) return;
    const locationId = Number(locationSelect.value || 0);
    const loc = locationsData.find(item => Number(item.id) === locationId);
    if (!loc) {
        help.textContent = 'Selectează o locație salvata in fișa clientului.';
        return;
    }
    const parts = [];
    if (loc.address) parts.push('Adresa: ' + loc.address);
    if (loc.contact_person) parts.push('Contact: ' + loc.contact_person);
    if (loc.phone) parts.push('Tel: ' + loc.phone);
    if (loc.surface_text) parts.push('Suprafață: ' + loc.surface_text);
    help.textContent = parts.length ? parts.join(' | ') : 'Locație selectata.';
    if (surface && loc.surface_text) surface.value = loc.surface_text;
}

function initEnhancedSelects() {
    // Client si servicii nu mai folosesc TomSelect (sunt custom autocomplete + toggle pills).
    // Pentru locație folosim un select normal cu logica smart in populateLocationsSmart().
    // Restul de TomSelect-uri (product-select, receipt-select etc) raman nesh schimbate, le initializam in continuare in alta parte.

    // Atasam handler pe locationSelect pentru a actualiza row-uri si surface
    const locationSelect = document.getElementById('locationSelect');
    if (locationSelect && !locationSelect.dataset.bound) {
        locationSelect.dataset.bound = '1';
        locationSelect.addEventListener('change', function() {
            locationSelect.dataset.selected = locationSelect.value || '0';
            updateLocationHelp();
            populateRowLocations();
            populateBasisContracts(true);
            document.querySelectorAll('.row-location-hidden').forEach(input => {
                if (!input.value || input.value === '0') input.value = locationSelect.value || '0';
            });
        });
    }
}

function populateRowLocations() {
    const clientSelect = document.getElementById('clientSelect');
    const locationSelect = document.getElementById('locationSelect');
    const clientId = clientSelect ? Number(clientSelect.value || 0) : 0;
    const mainLocation = locationSelect ? Number(locationSelect.value || 0) : 0;
    document.querySelectorAll('.row-location').forEach(select => {
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
    let html = '<option value="">Alege</option>';
    servicesData.forEach(service => {
        html += '<option value="' + service.id + '" data-name="' + escapeHtml(service.name) + '">' + escapeHtml(service.name) + '</option>';
    });
    return html;
}
function nextItemIndex() { return document.querySelectorAll('#itemsBody .item-row').length; }
function addItemRow() {
    const body = document.getElementById('itemsBody');
    if (!body) return;
    const i = nextItemIndex();
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    const mainLocation = document.getElementById('locationSelect') ? (document.getElementById('locationSelect').value || '0') : '0';
    const defaultSurface = document.getElementById('surfaceText') ? document.getElementById('surfaceText').value : '';
    tr.innerHTML = `
        <td><select name="items[${i}][service_id]" class="service-select" onchange="syncServiceName(this)">${serviceOptionsHtml()}</select><input type="text" name="items[${i}][service_name]" class="service-name" placeholder="sau scrie serviciul manual" style="margin-top:6px;"><input type="hidden" name="items[${i}][client_location_id]" class="row-location-hidden" value="${escapeHtml(mainLocation)}"></td>
        <td><textarea name="items[${i}][description]" placeholder="Mențiuni / detalii serviciu prestat"></textarea></td>
        <td><input type="text" name="items[${i}][surface_text]" class="item-surface" placeholder="ex: 1500 mp" value="${escapeHtml(defaultSurface)}"></td>
        <td><button type="button" class="btn small danger" onclick="removeItemRow(this)">Șterge</button></td>`;
    body.appendChild(tr);
}
function removeItemRow(button) {
    const rows = document.querySelectorAll('#itemsBody .item-row');
    if (rows.length <= 1) {
        const row = button.closest('.item-row');
        if (row) {
            row.querySelectorAll('input, textarea').forEach(input => input.value = '');
            row.querySelectorAll('select').forEach(select => select.value = '');
        }
        return;
    }
    const row = button.closest('.item-row');
    if (row) row.remove();
}
function syncServiceName(select) {
    const row = select.closest('tr');
    const input = row ? row.querySelector('.service-name') : null;
    const selected = select.options[select.selectedIndex];
    if (input && selected && selected.dataset.name && !input.value) input.value = selected.dataset.name;
}

function productOptionsHtml(selectedId) {
    let html = '<option value="">Alege produs</option>';
    productsData.forEach(product => {
        html += '<option value="' + product.id + '"' + (Number(product.id) === Number(selectedId) ? ' selected' : '') + '>' + escapeHtml(product.name) + '</option>';
    });
    return html;
}
function methodLabelToValue(value) {
    const v = normalizeText(value);
    if (v.includes('pulver')) return 'pulverizare';
    if (v.includes('nebul')) return 'nebulizare';
    if (v.includes('amplas')) return 'amplasare';
    if (v.includes('direct')) return 'aplicare directa';
    return '';
}
function selectedSelectValue(select) {
    const current = Number(select.value || 0);
    if (current > 0) return current;
    return Number(select.dataset.selected || 0);
}
function populateProductSelects() {
    document.querySelectorAll('.product-select').forEach(select => {
        const selected = selectedSelectValue(select);
        select.innerHTML = productOptionsHtml(selected);
        populateReceiptSelect(select.closest('.material-row'));
    });
}
function populateReceiptSelect(row) {
    if (!row) return;
    const productSelect = row.querySelector('.product-select');
    const receiptSelect = row.querySelector('.receipt-select');
    if (!productSelect || !receiptSelect) return;
    const productId = Number(productSelect.value || 0);
    const selected = selectedSelectValue(receiptSelect);
    receiptSelect.innerHTML = '<option value="">Alege lot</option>';
    if (!productId) {
        receiptSelect.dataset.selected = '0';
        syncLotRow(receiptSelect);
        return;
    }
    receiptsData.filter(r => Number(r.product_id) === productId).forEach(r => {
        const option = document.createElement('option');
        option.value = r.id;
        option.textContent = r.lot ? String(r.lot) : 'Fără lot';
        option.title = (r.lot ? 'Lot ' + r.lot : 'Lot fără nume') + (r.expires_at ? ' / exp. ' + r.expires_at : '') + (r.qty ? ' / disponibil ' + r.qty : '');
        if (Number(r.id) === selected) option.selected = true;
        receiptSelect.appendChild(option);
    });
    if (!receiptSelect.value && receiptSelect.options.length > 1) receiptSelect.selectedIndex = 1;
    syncLotRow(receiptSelect);
}
function syncProductRow(select) {
    const row = select.closest('.material-row');
    if (!row) return;
    select.dataset.selected = select.value || '0';
    const product = productsData.find(p => Number(p.id) === Number(select.value || 0));
    if (product) {
        const unit = row.querySelector('.material-unit');
        const group = row.querySelector('.product-group');
        const aviz = row.querySelector('.aviz-no');
        const safety = row.querySelector('.safety-measures');
        const concentration = row.querySelector('.work-concentration');
        const method = row.querySelector('.application-method');
        if (unit) unit.value = product.unit_consumption || product.unit || '';
        if (group) group.value = product.product_group || '';
        if (aviz) aviz.value = product.aviz_no || '';
        if (safety) safety.value = product.safety_measures || '';
        if (concentration) concentration.value = product.product_concentration || '';
        if (method) method.value = methodLabelToValue(product.default_application_method || '');
    } else {
        ['.material-unit', '.product-group', '.aviz-no', '.safety-measures', '.work-concentration', '.expiry-date'].forEach(selector => {
            const input = row.querySelector(selector);
            if (input) input.value = '';
        });
    }
    const receiptSelect = row.querySelector('.receipt-select');
    if (receiptSelect) receiptSelect.dataset.selected = '0';
    populateReceiptSelect(row);
    pzValidateForm();
}
function syncLotRow(select) {
    const row = select.closest('.material-row');
    if (!row) return;
    const receipt = receiptsData.find(r => Number(r.id) === Number(select.value || 0));
    const expiry = row.querySelector('.expiry-date');
    select.dataset.selected = select.value || '0';
    if (receipt) {
        if (expiry) expiry.value = receipt.expires_at || '';
    } else if (expiry) {
        expiry.value = '';
    }
}
function nextMaterialIndex() { return document.querySelectorAll('#materialsBody .material-row').length; }
function isMaterialsCardMode() { const body = document.getElementById('materialsBody'); return !!(body && body.classList.contains('pv-material-cards')); }
function addMaterialRow() {
    const body = document.getElementById('materialsBody');
    if (!body) return;
    const i = nextMaterialIndex();
    const cardMode = isMaterialsCardMode();
    const row = document.createElement(cardMode ? 'div' : 'tr');
    row.className = cardMode ? 'material-row pv-material-card' : 'material-row';
    if (cardMode) {
        row.innerHTML = `
            <div class="pv-material-card-head"><div class="pv-material-card-title">Produs utilizat #${i + 1}</div><button type="button" class="btn small danger" onclick="removeMaterialRow(this)">Șterge</button></div>
            <div class="pv-material-grid">
                <div><label>Produs / material</label><select name="materials[${i}][stock_product_id]" class="product-select" data-selected="0" onchange="syncProductRow(this)"></select><input type="hidden" name="materials[${i}][product_group]" class="product-group"><input type="hidden" name="materials[${i}][safety_measures]" class="safety-measures"><input type="hidden" class="material-unit-cache"><input type="hidden" name="materials[${i}][aviz_no]" class="aviz-no"><input type="hidden" name="materials[${i}][expiry_date]" class="expiry-date"></div>
                <div><label>Lot / stoc</label><select name="materials[${i}][stock_receipt_id]" class="receipt-select" data-selected="0" onchange="syncLotRow(this)"></select><div class="pv-stock-hint">Datele din stoc se preiau automat: aviz, lot, valabilitate si UM.</div></div>
            </div>
            <div class="pv-material-mini-grid">
                <div><label>Dilutie</label><input type="text" name="materials[${i}][work_concentration]" class="work-concentration" placeholder="ex: 1%"></div>
                <div><label>Cantitate</label><input type="text" inputmode="decimal" class="quantity-input" name="materials[${i}][quantity]" placeholder="cant."></div>
                <div><label>UM</label><input type="text" name="materials[${i}][unit]" class="material-unit" readonly placeholder="-"></div>
                <div><label>Metoda aplicare</label><select name="materials[${i}][application_method]" class="application-method"><option value="">Alege</option><option value="pulverizare">Pulverizare</option><option value="aplicare directa">Aplicare directa</option><option value="nebulizare">Nebulizare</option><option value="amplasare">Amplasare</option></select></div>
            </div>
            <input type="hidden" name="materials[${i}][application_method_custom]" value=""><input type="hidden" name="materials[${i}][application_area]" value=""><input type="hidden" name="materials[${i}][notes]" value="">`;
    } else {
        row.innerHTML = `
            <td><select name="materials[${i}][stock_product_id]" class="product-select" data-selected="0" onchange="syncProductRow(this)"></select><input type="hidden" name="materials[${i}][product_group]" class="product-group"><input type="hidden" name="materials[${i}][safety_measures]" class="safety-measures"><input type="hidden" class="material-unit-cache"></td>
            <td><input type="text" name="materials[${i}][aviz_no]" class="aviz-no"></td>
            <td><select name="materials[${i}][stock_receipt_id]" class="receipt-select" data-selected="0" onchange="syncLotRow(this)"></select></td>
            <td><input type="date" name="materials[${i}][expiry_date]" class="expiry-date"></td>
            <td><input type="text" name="materials[${i}][work_concentration]" class="work-concentration" placeholder="ex: 1%"></td>
            <td><input type="text" inputmode="decimal" class="quantity-input" name="materials[${i}][quantity]" placeholder="cant."></td>
            <td><input type="text" name="materials[${i}][unit]" class="material-unit" readonly placeholder="-"></td>
            <td><select name="materials[${i}][application_method]" class="application-method"><option value="">Alege</option><option value="pulverizare">Pulverizare</option><option value="aplicare directa">Aplicare directa</option><option value="nebulizare">Nebulizare</option><option value="amplasare">Amplasare</option></select></td>
            <td><input type="hidden" name="materials[${i}][application_method_custom]" value=""><input type="hidden" name="materials[${i}][application_area]" value=""><input type="hidden" name="materials[${i}][notes]" value=""><button type="button" class="btn small danger" onclick="removeMaterialRow(this)">Șterge</button></td>`;
    }
    body.appendChild(row);
    populateProductSelects();
    pzValidateForm();
}
function addManualMaterialRow() {
    const body = document.getElementById('materialsBody');
    if (!body) return;
    const i = nextMaterialIndex();
    const cardMode = isMaterialsCardMode();
    const row = document.createElement(cardMode ? 'div' : 'tr');
    row.className = cardMode ? 'material-row pv-material-card pv-manual-material-row' : 'material-row pv-manual-material-row';
    if (cardMode) {
        row.innerHTML = `
            <div class="pv-material-card-head"><div class="pv-material-card-title">Produs fără stoc #${i + 1}</div><button type="button" class="btn small danger" onclick="removeMaterialRow(this)">Șterge</button></div>
            <input type="hidden" name="materials[${i}][stock_product_id]" value="">
            <input type="hidden" name="materials[${i}][stock_receipt_id]" value="">
            <div class="pv-material-grid">
                <div><label>Denumire</label><input type="text" name="materials[${i}][manual_material_name]" class="manual-material-name" placeholder="produs fără stoc"></div>
                <div><label>Nr. aviz</label><input type="text" name="materials[${i}][manual_aviz_no]" placeholder="opțional"></div>
            </div>
            <div class="pv-material-mini-grid">
                <div><label>Lot</label><input type="text" name="materials[${i}][manual_lot_number]" class="manual-lot-number" placeholder="opțional"></div>
                <div><label>Valabilitate opțională</label><input type="text" name="materials[${i}][manual_expiry_date]" placeholder="ex: 31.12.2027"></div>
                <div><label>Diluție</label><input type="text" name="materials[${i}][manual_work_concentration]" placeholder="ex: 1%"></div>
            </div>
            <div class="pv-material-mini-grid">
                <div><label>Cantitate</label><input type="text" inputmode="decimal" class="quantity-input" name="materials[${i}][manual_quantity]" placeholder="cant."></div>
                <div><label>UM</label><select name="materials[${i}][manual_unit]" class="manual-unit"><option value="">Alege</option><option value="ml">ml</option><option value="l">l</option><option value="g">g</option><option value="kg">kg</option><option value="buc">buc</option><option value="plic">plic</option><option value="capcana">capcana</option><option value="doza">doza</option><option value="set">set</option></select></div>
                <div><label>Aplicare</label><select name="materials[${i}][manual_application_method]"><option value="">Alege</option><option value="pulverizare">Pulverizare</option><option value="aplicare directa">Aplicare directă</option><option value="nebulizare">Nebulizare</option><option value="amplasare">Amplasare</option></select></div>
            </div>
            <input type="hidden" name="materials[${i}][application_method_custom]" value=""><input type="hidden" name="materials[${i}][application_area]" value=""><input type="hidden" name="materials[${i}][notes]" value="">`;
    } else {
        row.innerHTML = `
            <td><input type="hidden" name="materials[${i}][stock_product_id]" value=""><input type="text" name="materials[${i}][manual_material_name]" class="manual-material-name" placeholder="produs fără stoc"></td>
            <td><input type="text" name="materials[${i}][manual_aviz_no]" placeholder="opțional"></td>
            <td><input type="hidden" name="materials[${i}][stock_receipt_id]" value=""><input type="text" name="materials[${i}][manual_lot_number]" class="manual-lot-number" placeholder="opțional"></td>
            <td><input type="text" name="materials[${i}][manual_expiry_date]" placeholder="ex: 31.12.2027"></td>
            <td><input type="text" name="materials[${i}][manual_work_concentration]" placeholder="ex: 1%"></td>
            <td><input type="text" inputmode="decimal" class="quantity-input" name="materials[${i}][manual_quantity]" placeholder="cant."></td>
            <td><select name="materials[${i}][manual_unit]" class="manual-unit"><option value="">Alege</option><option value="ml">ml</option><option value="l">l</option><option value="g">g</option><option value="kg">kg</option><option value="buc">buc</option><option value="plic">plic</option><option value="capcana">capcana</option><option value="doza">doza</option><option value="set">set</option></select></td>
            <td><select name="materials[${i}][manual_application_method]"><option value="">Alege</option><option value="pulverizare">Pulverizare</option><option value="aplicare directa">Aplicare directă</option><option value="nebulizare">Nebulizare</option><option value="amplasare">Amplasare</option></select></td>
            <td><input type="hidden" name="materials[${i}][application_method_custom]" value=""><input type="hidden" name="materials[${i}][application_area]" value=""><input type="hidden" name="materials[${i}][notes]" value=""><button type="button" class="btn small danger" onclick="removeMaterialRow(this)">Șterge</button></td>`;
    }
    body.appendChild(row);
    pzValidateForm();
}
function removeMaterialRow(button) {
    const rows = document.querySelectorAll('#materialsBody .material-row');
    if (rows.length <= 1) {
        const row = button.closest('.material-row');
        if (row) {
            row.querySelectorAll('input, textarea').forEach(input => input.value = '');
            row.querySelectorAll('select').forEach(select => { select.value = ''; select.dataset.selected = '0'; });
        }
        pzValidateForm();
        return;
    }
    const row = button.closest('.material-row');
    if (row) row.remove();
    pzValidateForm();
}
function toggleMaterialsPanel() {
    const checkbox = document.getElementById('materialsEnabled');
    const panel = document.getElementById('materialsPanel');
    if (!panel || !checkbox) return;
    const enabled = checkbox.type === 'hidden' ? String(checkbox.value || '') === '1' : checkbox.checked;
    panel.classList.toggle('disabled', !enabled);
    pzValidateForm();
}

function pvServiceKeyFromText(text) {
    text = String(text || '').toLowerCase();
    if (text.includes('dezinsect') || text.includes('gandac') || text.includes('plosnit') || text.includes('puric') || text.includes('muste') || text.includes('tantar') || text.includes('viesp')) return 'dezinsectie';
    if (text.includes('dezinfect')) return 'dezinfectie';
    if (text.includes('derat') || text.includes('rozator') || text.includes('soarece') || text.includes('sobolan')) return 'deratizare';
    if (text.includes('monitor') || text.includes('inspect') || text.includes('capcan')) return 'monitorizare';
    return '';
}

function selectPvServiceFromText(text) {
    const key = pvServiceKeyFromText(text);
    if (!key) return;
    // Toggle on pill-ul corespunzator (noul mecanism)
    const pill = document.querySelector('#servicesPills .pz-pill[data-key="' + key + '"]');
    if (pill && !pill.classList.contains('is-active')) {
        pill.classList.add('is-active');
        pill.setAttribute('aria-checked', 'true');
        pzSyncServicesHidden();
        pzValidateForm();
    }
}

function syncAppointment(select) {
    const appointmentId = Number(select ? select.value : 0);
    const appt = appointmentsData.find(item => Number(item.id) === appointmentId);
    if (!appt) return;

    const clientSelect = document.getElementById('clientSelect');
    const locationSelect = document.getElementById('locationSelect');
    const dateInput = document.querySelector('input[name="document_date"]');
    const timeInput = document.querySelector('input[name="document_time"]');
    const startInput = document.querySelector('input[name="start_time"]');
    const endInput = document.querySelector('input[name="end_time"]');
    const surfaceInput = document.getElementById('surfaceText');
    const workersInput = document.getElementById('workersNames');

    if (dateInput && appt.appointment_date) dateInput.value = appt.appointment_date;
    if (timeInput && appt.start_time) timeInput.value = appt.start_time;
    if (startInput && appt.start_time) startInput.value = appt.start_time;
    if (endInput && appt.end_time) endInput.value = appt.end_time;
    if (surfaceInput && appt.surface_text) surfaceInput.value = appt.surface_text;
    if (workersInput && appt.team_member_name) workersInput.value = appt.team_member_name;

    if (clientSelect && appt.client_id) {
        const c = clientsData.find(item => Number(item.id) === Number(appt.client_id));
        if (c) {
            // Folosim noul mecanism custom autocomplete
            pzClientSetSelected(c);
            // Pre-setam locatia si re-aplicam smart logic
            if (locationSelect && appt.client_location_id) {
                locationSelect.dataset.selected = String(appt.client_location_id);
                populateLocationsSmart();
            }
        }
    }

    if (appt.service_name) {
        selectPvServiceFromText(appt.service_name);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const pvForm = document.getElementById('pvForm');
    if (pvForm) pvForm.addEventListener('submit', pzValidateBeforeSubmit);
    ['treatedAreas', 'surfaceText'].forEach(id => {
        const input = document.getElementById(id);
        if (input) input.addEventListener('input', pzValidateForm);
    });

    const appointmentSelect = document.getElementById('appointmentSelect');
    if (appointmentSelect) appointmentSelect.addEventListener('change', function() { syncAppointment(this); });

    // Init custom autocomplete client (inlocuieste TomSelect)
    initClientAutocomplete();
    initEnhancedSelects();
    bindBasisControls();
    populateLocationsSmart();
    populateBasisContracts(true);

    // Buton clear pe input search
    const clearBtn = document.getElementById('clientClearBtn');
    if (clearBtn) clearBtn.addEventListener('click', pzClientClear);

    if (appointmentSelect && appointmentSelect.value) syncAppointment(appointmentSelect);
    populateProductSelects();
    toggleMaterialsPanel();

    const materialsBody = document.getElementById('materialsBody');
    if (materialsBody) {
        materialsBody.addEventListener('input', pzValidateForm);
        materialsBody.addEventListener('change', pzValidateForm);
    }

    // Sync initial pentru servicii pills + valideaza form
    pzSyncServicesHidden();
    pzValidateForm();
});
</script>

<?php
// Preview live pentru bara „Caută PV".
$previewPvList = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'documents'")->fetch()) {
        $stmtPrev = $pdo->query("
            SELECT d.id, d.document_number, d.title, c.name AS client_name, c.fiscal_code
            FROM documents d
            LEFT JOIN clients c ON c.id = d.client_id
            WHERE d.document_type = 'proces_verbal'
            ORDER BY d.id DESC LIMIT 2000
        ");
        while ($r = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
            $nm  = html_entity_decode((string)($r['client_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cf  = html_entity_decode((string)($r['fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $num = trim((string)($r['document_number'] ?? ''));
            $ttl = html_entity_decode((string)($r['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $base = $num !== '' ? $num : ($ttl !== '' ? $ttl : ('PV #' . (int)$r['id']));
            $title = $base . ($nm !== '' ? ' · ' . $nm : '');
            $previewPvList[] = [
                'title'  => $title,
                'url'    => 'document_view.php?id=' . (int)$r['id'],
                'type'   => 'pv',
                'search' => $num . ' ' . $ttl . ' ' . $nm . ' ' . $cf,
            ];
        }
    }
} catch (Throwable $e) { error_log('procese_verbale.php preview: ' . $e->getMessage()); }
?>
<script>
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('pvSearchInput',
            <?= json_encode($previewPvList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
    };
    go();
})();
</script>
