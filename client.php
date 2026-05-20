<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
} catch (Throwable $e) {
    // Nu blocam pagina daca serverul nu accepta explicit collation-ul.
}

$isAdmin = is_admin();
if (!$isAdmin) {
    header('Location: calendar.php');
    exit;
}

function cd_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cd_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function cd_client_type_label(string $type): string {
    return $type === 'individual' ? 'PF' : 'PJ';
}

function cd_client_contact_person(array $client): string {
    $type = (string)($client['client_type'] ?? 'company');
    $name = trim((string)($client['name'] ?? ''));
    $rep = trim((string)($client['legal_representative_name'] ?? ''));

    if ($type === 'individual') {
        return $name;
    }

    return $rep !== '' ? $rep : $name;
}

function cd_client_billing_address(array $client): string {
    $country = trim((string)($client['billing_country'] ?? ''));
    $county = trim((string)($client['billing_county'] ?? ''));
    $city = trim((string)($client['billing_city'] ?? ''));
    $sector = trim((string)($client['billing_sector'] ?? ''));
    $line = trim((string)($client['billing_address_line'] ?? ''));
    $postal = trim((string)($client['billing_postal_code'] ?? ''));

    $location = trim(implode(', ', array_filter([$county, $city, $sector], static fn($v) => $v !== '')));
    $address = trim(implode(', ', array_filter([$line, $location, $country], static fn($v) => $v !== '')));
    if ($postal !== '') {
        $address .= ($address !== '' ? ', ' : '') . 'CP ' . $postal;
    }

    if ($address !== '') {
        return $address;
    }

    return trim((string)($client['registered_address'] ?? '')) ?: trim((string)($client['address'] ?? ''));
}

function cd_money($value): string {
    return number_format((float)$value, 2, ',', '.') . ' lei';
}

function cd_date($value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d.m.Y', $ts) : $value;
}

function cd_status_label($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    $labels = [
        'draft' => 'Draft',
        'issued' => 'Emis',
        'sent' => 'Trimis',
        'cancelled' => 'Anulat',
        'activ' => 'Activ',
        'sezon' => 'Sezon',
        'de_programat' => 'De programat',
        'contactat' => 'Contactat',
        'amanat' => 'Amânat',
        'programat' => 'Programat',
        'finalizata' => 'Finalizată',
        'smartbill_issued' => 'Emisă',
        'paid' => 'Încasată',
        'partial' => 'Parțial',
    ];

    return $labels[$value] ?? ucfirst(str_replace('_', ' ', $value));
}

function cd_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$clientId = (int)($_GET['id'] ?? $_GET['client_id'] ?? 0);
if ($clientId <= 0) {
    header('Location: clients.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
$stmt->execute([$clientId]);
$client = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$client) {
    http_response_code(404);
    $pz_page_title = 'Client negăsit';
}

$locations = [];
$appointments = [];
$tasks = [];
$contracts = [];
$offers = [];
$processes = [];
$invoices = [];
$counts = [
    'locations' => 0,
    'tasks' => 0,
    'appointments' => 0,
    'contracts' => 0,
    'offers' => 0,
    'processes' => 0,
    'invoices' => 0,
    'payments' => 0,
];

if ($client) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM client_locations
        WHERE client_id = ?
        ORDER BY active DESC, sort_order ASC, location_name ASC, id ASC
    ");
    $stmt->execute([$clientId]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts['locations'] = count($locations);

    if (cd_table_exists($pdo, 'tasks')) {
        $counts['tasks'] = cd_count($pdo, "SELECT COUNT(*) FROM tasks WHERE client_id = ?", [$clientId]);
        $stmt = $pdo->prepare("
            SELECT t.*, l.location_name
            FROM tasks t
            LEFT JOIN client_locations l ON l.id = t.client_location_id
            WHERE t.client_id = ?
            ORDER BY t.due_date DESC, t.id DESC
            LIMIT 8
        ");
        $stmt->execute([$clientId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (cd_table_exists($pdo, 'appointments')) {
        $counts['appointments'] = cd_count($pdo, "SELECT COUNT(*) FROM appointments WHERE client_id = ?", [$clientId]);
        $teamJoin = cd_table_exists($pdo, 'team_members') ? "LEFT JOIN team_members tm ON tm.id = a.team_member_id" : "";
        $teamSelect = cd_table_exists($pdo, 'team_members') ? "tm.name AS team_name," : "NULL AS team_name,";
        $stmt = $pdo->prepare("
            SELECT a.*, {$teamSelect} l.location_name
            FROM appointments a
            {$teamJoin}
            LEFT JOIN client_locations l ON l.id = a.client_location_id
            WHERE a.client_id = ?
            ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC
            LIMIT 8
        ");
        $stmt->execute([$clientId]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (cd_table_exists($pdo, 'contracts')) {
        $counts['contracts'] = cd_count($pdo, "SELECT COUNT(*) FROM contracts WHERE client_id = ?", [$clientId]);
        $stmt = $pdo->prepare("
            SELECT *
            FROM contracts
            WHERE client_id = ?
            ORDER BY contract_date DESC, id DESC
            LIMIT 8
        ");
        $stmt->execute([$clientId]);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (cd_table_exists($pdo, 'documents')) {
        $counts['offers'] = cd_count($pdo, "SELECT COUNT(*) FROM documents WHERE client_id = ? AND document_type = 'oferta'", [$clientId]);
        $stmt = $pdo->prepare("
            SELECT id, document_type, status, document_number, document_date, title, total_amount, currency
            FROM documents
            WHERE client_id = ?
              AND document_type = 'oferta'
            ORDER BY document_date DESC, id DESC
            LIMIT 6
        ");
        $stmt->execute([$clientId]);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts['processes'] = cd_count($pdo, "SELECT COUNT(*) FROM documents WHERE client_id = ? AND document_type = 'proces_verbal'", [$clientId]);
        $stmt = $pdo->prepare("
            SELECT id, document_type, status, document_number, document_date, title, total_amount, currency, location_name_snapshot
            FROM documents
            WHERE client_id = ?
              AND document_type = 'proces_verbal'
            ORDER BY document_date DESC, id DESC
            LIMIT 6
        ");
        $stmt->execute([$clientId]);
        $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (cd_table_exists($pdo, 'smartbill_invoices')) {
        $counts['invoices'] = cd_count($pdo, "SELECT COUNT(*) FROM smartbill_invoices WHERE client_id = ?", [$clientId]);
        if (cd_table_exists($pdo, 'smartbill_invoice_payments')) {
            $counts['payments'] = cd_count($pdo, "
                SELECT COUNT(*)
                FROM smartbill_invoice_payments p
                INNER JOIN smartbill_invoices i ON i.id = p.smartbill_invoice_id
                WHERE i.client_id = ?
            ", [$clientId]);
        }
        $stmt = $pdo->prepare("
            SELECT *
            FROM smartbill_invoices
            WHERE client_id = ?
            ORDER BY invoice_date DESC, id DESC
            LIMIT 8
        ");
        $stmt->execute([$clientId]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $pz_page_title = 'Dosar client';
    $pz_page_breadcrumbs = ['Contacte', (string)($client['name'] ?? 'Client')];
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title><?= $client ? cd_h($client['name']) . ' - Dosar client' : 'Client negăsit' ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<?php app_theme_css(); ?>
<style>
.client-dossier { max-width: 1580px; margin: 0 auto; display: grid; gap: 14px; }
.dossier-header { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 18px; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 16px; align-items: start; }
.dossier-title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.dossier-title { margin: 0; color: var(--text); font-size: 28px; line-height: 1.1; font-weight: 850; letter-spacing: 0; }
.dossier-sub { margin-top: 6px; color: var(--muted); font-size: 14px; font-weight: 650; }
.dossier-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.dossier-actions .btn { min-height: 34px; padding: 7px 11px; border-radius: 4px; font-size: 13px; }
.type-chip, .status-chip { display: inline-flex; align-items: center; height: 28px; padding: 0 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface-soft); color: var(--text); font-size: 12px; font-weight: 800; }
.status-chip.off { color: #991B1B; background: #FEF2F2; border-color: #FECACA; }
.dossier-tabs { display: grid; grid-template-columns: repeat(11, minmax(78px, 1fr)); gap: 8px; }
.dossier-tab { min-height: 78px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); text-decoration: none; display: grid; place-items: center; align-content: center; gap: 7px; font-size: 12px; font-weight: 800; position: relative; }
.dossier-tab:hover { border-color: var(--accent); color: var(--accent); }
.dossier-tab svg { width: 21px; height: 21px; stroke: currentColor; }
.tab-count { position: absolute; top: 7px; right: 9px; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--surface-soft); color: var(--muted); font: 800 11px/20px "DM Sans", sans-serif; text-align: center; }
.dossier-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 14px; align-items: start; }
.dossier-main, .dossier-side { display: grid; gap: 14px; }
.dossier-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.dossier-card-head { min-height: 50px; padding: 13px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.dossier-card-title { color: var(--text); font-size: 16px; font-weight: 850; }
.dossier-card-body { padding: 16px; }
.profile-fields { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 10px 16px; }
.field-label { color: var(--muted); font-size: 13px; font-weight: 750; }
.field-value { color: var(--text); font-size: 14px; font-weight: 750; min-width: 0; overflow-wrap: anywhere; }
.field-value a { color: var(--accent); text-decoration: none; }
.address-line { display: flex; gap: 10px; align-items: flex-start; color: var(--text); font-size: 14px; font-weight: 750; line-height: 1.45; }
.address-line svg { width: 18px; height: 18px; flex: 0 0 auto; color: var(--muted); }
.location-list, .timeline-list { display: grid; gap: 9px; }
.location-item, .timeline-item { border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: #fff; }
.item-top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
.item-title { color: var(--text); font-size: 14px; font-weight: 850; }
.item-meta { margin-top: 4px; color: var(--muted); font-size: 12.5px; line-height: 1.45; font-weight: 650; }
.mini-badge { display: inline-flex; align-items: center; height: 24px; padding: 0 8px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface-soft); color: var(--muted); font-size: 11px; font-weight: 850; white-space: nowrap; }
.mini-badge.good { background: #ECFDF5; border-color: #BBF7D0; color: #166534; }
.mini-badge.warn { background: #FFF7ED; border-color: #FED7AA; color: #9A3412; }
.kpi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.kpi { border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface-soft); }
.kpi-value { color: var(--text); font-size: 22px; font-weight: 900; line-height: 1; }
.kpi-label { margin-top: 6px; color: var(--muted); font-size: 12px; font-weight: 750; }
.empty-state { border: 1px dashed var(--border); border-radius: 8px; padding: 14px; color: var(--muted); font-size: 13px; font-weight: 700; background: var(--surface-soft); }
.danger-zone { display: flex; gap: 8px; flex-wrap: wrap; }
.danger-zone .btn { min-height: 34px; font-size: 12px; border-radius: 4px; }
.muted-link { color: var(--muted); text-decoration: none; font-weight: 800; font-size: 13px; }
@media(max-width: 1180px) {
    .dossier-grid { grid-template-columns: 1fr; }
    .dossier-tabs { grid-template-columns: repeat(5, minmax(86px, 1fr)); }
}
@media(max-width: 760px) {
    .client-dossier { gap: 10px; padding: 12px !important; }
    .dossier-header { grid-template-columns: 1fr; padding: 14px; border-radius: 8px; gap: 12px; }
    .dossier-title { font-size: 28px !important; line-height: 1.06; overflow-wrap: anywhere; }
    .dossier-title-row { gap: 8px; }
    .type-chip, .status-chip { height: 30px; border-radius: 6px; font-size: 12px; padding: 0 10px; }
    .dossier-sub { font-size: 14px; line-height: 1.35; }
    .dossier-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; justify-content: stretch; }
    .dossier-actions .btn { width: 100%; min-height: 38px; justify-content: center; font-size: 12px; padding: 7px 8px; }
    .dossier-actions .btn.accent { grid-column: 1 / -1; }
    .dossier-tabs { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
    .dossier-tab { min-height: 58px; border-radius: 8px; gap: 5px; font-size: 11px; padding: 8px 6px; }
    .dossier-tab svg { width: 18px; height: 18px; }
    .tab-count { top: 6px; right: 6px; min-width: 18px; height: 18px; padding: 0 5px; font-size: 10px; line-height: 18px; }
    .dossier-card { border-radius: 8px; }
    .dossier-card-head { min-height: 44px; padding: 11px 12px; }
    .dossier-card-title { font-size: 15px; }
    .dossier-card-body { padding: 12px; }
    .profile-fields { grid-template-columns: 1fr; gap: 4px; }
    .field-value { margin-bottom: 8px; }
    .kpi-grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('clients', true); ?>
    <main class="main">
        <div class="content client-dossier">
            <?php if (!$client): ?>
                <section class="dossier-card">
                    <div class="dossier-card-body">
                        <div class="empty-state">Clientul nu a fost găsit.</div>
                        <p><a class="btn" href="clients.php">Înapoi la contacte</a></p>
                    </div>
                </section>
            <?php else: ?>
                <section class="dossier-header">
                    <div>
                        <div class="dossier-title-row">
                            <h1 class="dossier-title"><?= cd_h($client['name'] ?? 'Client') ?></h1>
                            <span class="type-chip"><?= cd_h(cd_client_type_label((string)($client['client_type'] ?? 'company'))) ?></span>
                            <span class="status-chip <?= (int)($client['active'] ?? 1) === 1 ? '' : 'off' ?>"><?= (int)($client['active'] ?? 1) === 1 ? 'Activ' : 'Inactiv' ?></span>
                        </div>
                        <div class="dossier-sub">
                            <?= cd_h(cd_client_contact_person($client) ?: '-') ?>
                            <?php if (!empty($client['phone'])): ?> · <?= cd_h($client['phone']) ?><?php endif; ?>
                            <?php if (!empty($client['email'])): ?> · <?= cd_h($client['email']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="dossier-actions">
                        <a class="btn" href="clients.php">Listă contacte</a>
                        <a class="btn" href="clients.php?client_id=<?= (int)$clientId ?>">Editează</a>
                        <a class="btn" href="contracts.php?new=1&client_id=<?= (int)$clientId ?>">Contract</a>
                        <a class="btn" href="tasks.php?client_id=<?= (int)$clientId ?>&open_create=1&return_to=client">Sarcină</a>
                        <a class="btn accent" href="calendar.php?client_id=<?= (int)$clientId ?>&open_create=1">Programare</a>
                    </div>
                </section>

                <nav class="dossier-tabs" aria-label="Dosar client">
                    <a class="dossier-tab" href="#profil"><?= app_icon_svg('clients') ?><span>Profil</span></a>
                    <a class="dossier-tab" href="#locatii"><?= app_icon_svg('calendar') ?><span>Locații</span><span class="tab-count"><?= (int)$counts['locations'] ?></span></a>
                    <a class="dossier-tab" href="offers?client_id=<?= (int)$clientId ?>"><?= app_icon_svg('offers') ?><span>Oferte</span><span class="tab-count"><?= (int)$counts['offers'] ?></span></a>
                    <a class="dossier-tab" href="contracts.php?client_id=<?= (int)$clientId ?>"><?= app_icon_svg('contracts') ?><span>Contracte</span><span class="tab-count"><?= (int)$counts['contracts'] ?></span></a>
                    <a class="dossier-tab" href="#sarcini"><?= app_icon_svg('tasks') ?><span>Sarcini</span><span class="tab-count"><?= (int)$counts['tasks'] ?></span></a>
                    <a class="dossier-tab" href="#programari"><?= app_icon_svg('calendar') ?><span>Programări</span><span class="tab-count"><?= (int)$counts['appointments'] ?></span></a>
                    <a class="dossier-tab" href="service-reports?client_id=<?= (int)$clientId ?>"><?= app_icon_svg('processes') ?><span>Proc. verb.</span><span class="tab-count"><?= (int)$counts['processes'] ?></span></a>
                    <a class="dossier-tab" href="invoices.php?client_id=<?= (int)$clientId ?>"><?= app_icon_svg('invoice') ?><span>Facturi</span><span class="tab-count"><?= (int)$counts['invoices'] ?></span></a>
                    <a class="dossier-tab" href="payments.php?client_id=<?= (int)$clientId ?>"><?= app_icon_svg('invoice') ?><span>Încasări</span><span class="tab-count"><?= (int)$counts['payments'] ?></span></a>
                    <a class="dossier-tab" href="#fisiere"><?= app_icon_svg('documents') ?><span>Fișiere</span></a>
                    <a class="dossier-tab" href="#note"><?= app_icon_svg('star') ?><span>Note</span></a>
                    <a class="dossier-tab" href="#rezumat"><?= app_icon_svg('reports') ?><span>Rezumat</span></a>
                </nav>

                <div class="dossier-grid">
                    <div class="dossier-main">
                        <section class="dossier-card" id="profil">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Informații client</div>
                            </div>
                            <div class="dossier-card-body">
                                <div class="profile-fields">
                                    <div class="field-label">Denumire / nume</div>
                                    <div class="field-value"><?= cd_h($client['name'] ?? '-') ?></div>
                                    <div class="field-label">CUI / CNP</div>
                                    <div class="field-value"><?= cd_h($client['fiscal_code'] ?: '-') ?></div>
                                    <div class="field-label">Nr. Reg. Com. / Serie CI</div>
                                    <div class="field-value"><?= cd_h($client['registry_number'] ?: '-') ?></div>
                                    <div class="field-label">Reprezentant</div>
                                    <div class="field-value"><?= cd_h(cd_client_contact_person($client) ?: '-') ?></div>
                                    <div class="field-label">Calitate reprezentant</div>
                                    <div class="field-value"><?= cd_h($client['legal_representative_role'] ?: '-') ?></div>
                                    <div class="field-label">Telefon</div>
                                    <div class="field-value"><?= cd_h($client['phone'] ?: '-') ?></div>
                                    <div class="field-label">Email</div>
                                    <div class="field-value"><?= !empty($client['email']) ? '<a href="mailto:' . cd_h($client['email']) . '">' . cd_h($client['email']) . '</a>' : '-' ?></div>
                                    <div class="field-label">Bancă</div>
                                    <div class="field-value"><?= cd_h($client['bank_name'] ?: '-') ?></div>
                                    <div class="field-label">Cont bancar</div>
                                    <div class="field-value"><?= cd_h($client['bank_account'] ?: '-') ?></div>
                                </div>
                            </div>
                        </section>

                        <section class="dossier-card">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Adresă e-Factura</div>
                            </div>
                            <div class="dossier-card-body">
                                <div class="address-line">
                                    <?= app_icon_svg('calendar') ?>
                                    <div><?= cd_h(cd_client_billing_address($client) ?: '-') ?></div>
                                </div>
                                <div class="profile-fields" style="margin-top:14px;">
                                    <div class="field-label">Țară</div>
                                    <div class="field-value"><?= cd_h($client['billing_country'] ?: 'Romania') ?></div>
                                    <div class="field-label">Județ</div>
                                    <div class="field-value"><?= cd_h($client['billing_county'] ?: '-') ?></div>
                                    <div class="field-label">Oraș / sector</div>
                                    <div class="field-value"><?= cd_h($client['billing_city'] ?: '-') ?></div>
                                    <div class="field-label">Cod poștal</div>
                                    <div class="field-value"><?= cd_h($client['billing_postal_code'] ?: '-') ?></div>
                                </div>
                            </div>
                        </section>

                        <section class="dossier-card" id="locatii">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Locații</div>
                                <a class="btn" href="clients.php?client_id=<?= (int)$clientId ?>&open_edit=1">+ Adaugă locație</a>
                            </div>
                            <div class="dossier-card-body">
                                <?php if (!$locations): ?>
                                    <div class="empty-state">Nu există puncte de lucru adăugate.</div>
                                <?php else: ?>
                                    <div class="location-list">
                                        <?php foreach ($locations as $location): ?>
                                            <article class="location-item">
                                                <div class="item-top">
                                                    <div>
                                                        <div class="item-title"><?= cd_h($location['location_name'] ?: 'Punct de lucru') ?></div>
                                                        <div class="item-meta">
                                                            <?= cd_h($location['address'] ?: '-') ?><br>
                                                            Contact: <?= cd_h($location['contact_person'] ?: '-') ?><?= !empty($location['phone']) ? ' · ' . cd_h($location['phone']) : '' ?>
                                                            <?php if (!empty($location['surface_value'])): ?><br>Suprafață: <?= cd_h(rtrim(rtrim(number_format((float)$location['surface_value'], 2, '.', ''), '0'), '.')) ?> <?= cd_h($location['surface_unit'] ?: 'mp') ?><?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <span class="mini-badge <?= (int)($location['active'] ?? 1) === 1 ? 'good' : '' ?>"><?= (int)($location['active'] ?? 1) === 1 ? 'Activ' : 'Inactiv' ?></span>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="dossier-card" id="sarcini">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Sarcini</div>
                                <a class="muted-link" href="tasks.php?client_id=<?= (int)$clientId ?>">Vezi toate</a>
                            </div>
                            <div class="dossier-card-body">
                                <?php if (!$tasks): ?>
                                    <div class="empty-state">Nu există sarcini pentru acest client.</div>
                                <?php else: ?>
                                    <div class="timeline-list">
                                        <?php foreach ($tasks as $task): ?>
                                            <article class="timeline-item">
                                                <div class="item-top">
                                                    <div>
                                                        <div class="item-title"><?= cd_h($task['service_type'] ?: 'Sarcină') ?></div>
                                                        <div class="item-meta"><?= cd_date($task['due_date'] ?? '') ?> · <?= cd_h($task['location_name'] ?: 'Sediu / domiciliu') ?></div>
                                                    </div>
                                                    <span class="mini-badge warn"><?= cd_h(cd_status_label($task['status'] ?? '')) ?></span>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="dossier-card" id="programari">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Programări</div>
                                <a class="muted-link" href="calendar.php?client_id=<?= (int)$clientId ?>">Calendar</a>
                            </div>
                            <div class="dossier-card-body">
                                <?php if (!$appointments): ?>
                                    <div class="empty-state">Nu există programări.</div>
                                <?php else: ?>
                                    <div class="timeline-list">
                                        <?php foreach ($appointments as $appointment): ?>
                                            <article class="timeline-item">
                                                <div class="item-top">
                                                    <div>
                                                        <div class="item-title"><?= cd_h($appointment['service_type'] ?: 'Lucrare') ?></div>
                                                        <div class="item-meta">
                                                            <?= cd_date($appointment['appointment_date'] ?? '') ?> · <?= cd_h(substr((string)($appointment['start_time'] ?? ''), 0, 5) ?: '-') ?> · <?= cd_h($appointment['team_name'] ?: '-') ?><br>
                                                            <?= cd_h($appointment['location_name'] ?: 'Sediu / domiciliu') ?>
                                                        </div>
                                                    </div>
                                                    <span class="mini-badge"><?= cd_h(cd_status_label($appointment['status'] ?? '')) ?></span>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="dossier-card" id="oferte">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Oferte</div>
                                <a class="muted-link" href="offers?client_id=<?= (int)$clientId ?>">Vezi toate</a>
                            </div>
                            <div class="dossier-card-body">
                                <?php if (!$offers): ?>
                                    <div class="empty-state">Nu există oferte pentru acest client.</div>
                                <?php else: ?>
                                    <div class="timeline-list">
                                        <?php foreach ($offers as $offer): ?>
                                            <article class="timeline-item">
                                                <div class="item-top">
                                                    <div>
                                                        <div class="item-title"><?= cd_h($offer['title'] ?: 'Ofertă') ?></div>
                                                        <div class="item-meta"><?= cd_date($offer['document_date'] ?? '') ?> · <?= cd_h($offer['document_number'] ?: 'Fără număr') ?> · <?= cd_money($offer['total_amount'] ?? 0) ?></div>
                                                    </div>
                                                    <a class="mini-badge" href="document_view.php?id=<?= (int)$offer['id'] ?>">Deschide</a>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="dossier-card" id="contracte">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Contracte</div>
                                <a class="muted-link" href="contracts.php?client_id=<?= (int)$clientId ?>">Vezi toate</a>
                            </div>
                            <div class="dossier-card-body">
                                <?php if (!$contracts): ?>
                                    <div class="empty-state">Nu există contracte salvate.</div>
                                <?php else: ?>
                                    <div class="timeline-list">
                                        <?php foreach ($contracts as $contract): ?>
                                            <article class="timeline-item">
                                                <div class="item-top">
                                                    <div>
                                                        <div class="item-title"><?= cd_h($contract['title'] ?: ('Contract ' . ($contract['contract_number'] ?: '#' . $contract['id']))) ?></div>
                                                        <div class="item-meta"><?= cd_date($contract['contract_date'] ?? '') ?> · <?= cd_h($contract['contract_number'] ?: '-') ?></div>
                                                    </div>
                                                    <span class="mini-badge good"><?= cd_h(cd_status_label($contract['status'] ?? '')) ?></span>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="dossier-card" id="procese">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Procese verbale</div>
                                <a class="muted-link" href="service-reports?client_id=<?= (int)$clientId ?>">Vezi toate</a>
                            </div>
                            <div class="dossier-card-body">
                                <?php if (!$processes): ?>
                                    <div class="empty-state">Nu există procese verbale pentru acest client.</div>
                                <?php else: ?>
                                    <div class="timeline-list">
                                        <?php foreach ($processes as $document): ?>
                                            <article class="timeline-item">
                                                <div class="item-top">
                                                    <div>
                                                        <div class="item-title"><?= cd_h($document['title'] ?: 'Proces verbal') ?></div>
                                                        <div class="item-meta">
                                                            <?= cd_date($document['document_date'] ?? '') ?> · <?= cd_h($document['document_number'] ?: 'Fără număr') ?>
                                                            <?php if (!empty($document['location_name_snapshot'])): ?> · <?= cd_h($document['location_name_snapshot']) ?><?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <a class="mini-badge" href="document_view.php?id=<?= (int)$document['id'] ?>">Deschide</a>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="dossier-card" id="facturi">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Facturi</div>
                                <a class="muted-link" href="invoices.php?client_id=<?= (int)$clientId ?>">Vezi toate</a>
                            </div>
                            <div class="dossier-card-body">
                                <?php if (!$invoices): ?>
                                    <div class="empty-state">Nu există facturi emise pentru acest client.</div>
                                <?php else: ?>
                                    <div class="timeline-list">
                                        <?php foreach ($invoices as $invoice): ?>
                                            <article class="timeline-item">
                                                <div class="item-top">
                                                    <div>
                                                        <div class="item-title"><?= cd_h(trim(($invoice['smartbill_series'] ?? '') . ' ' . ($invoice['smartbill_number'] ?? '')) ?: 'Factură') ?></div>
                                                        <div class="item-meta"><?= cd_date($invoice['invoice_date'] ?? '') ?> · <?= cd_money($invoice['gross_amount'] ?? 0) ?></div>
                                                    </div>
                                                    <a class="mini-badge" href="invoice.php?id=<?= (int)$invoice['id'] ?>">Deschide</a>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section class="dossier-card" id="fisiere">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Fișiere</div>
                            </div>
                            <div class="dossier-card-body">
                                <div class="empty-state">Zona de fișiere rămâne pregătită pentru atașamentele clientului.</div>
                            </div>
                        </section>

                        <section class="dossier-card" id="note">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Note</div>
                            </div>
                            <div class="dossier-card-body">
                                <?php if (!empty($client['notes'])): ?>
                                    <div class="field-value"><?= nl2br(cd_h($client['notes'])) ?></div>
                                <?php else: ?>
                                    <div class="empty-state">Nu există note salvate.</div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <aside class="dossier-side">
                        <section class="dossier-card" id="rezumat">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Rezumat</div>
                            </div>
                            <div class="dossier-card-body">
                                <div class="kpi-grid">
                                    <div class="kpi"><div class="kpi-value"><?= (int)$counts['locations'] ?></div><div class="kpi-label">locații</div></div>
                                    <div class="kpi"><div class="kpi-value"><?= (int)$counts['tasks'] ?></div><div class="kpi-label">sarcini</div></div>
                                    <div class="kpi"><div class="kpi-value"><?= (int)$counts['appointments'] ?></div><div class="kpi-label">programări</div></div>
                                    <div class="kpi"><div class="kpi-value"><?= (int)$counts['offers'] ?></div><div class="kpi-label">oferte</div></div>
                                    <div class="kpi"><div class="kpi-value"><?= (int)$counts['contracts'] ?></div><div class="kpi-label">contracte</div></div>
                                    <div class="kpi"><div class="kpi-value"><?= (int)$counts['processes'] ?></div><div class="kpi-label">procese verbale</div></div>
                                    <div class="kpi"><div class="kpi-value"><?= (int)$counts['invoices'] ?></div><div class="kpi-label">facturi</div></div>
                                    <div class="kpi"><div class="kpi-value"><?= (int)$counts['payments'] ?></div><div class="kpi-label">încasări</div></div>
                                </div>
                            </div>
                        </section>

                        <section class="dossier-card">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Status</div>
                            </div>
                            <div class="dossier-card-body">
                                <div class="profile-fields" style="grid-template-columns: 130px 1fr;">
                                    <div class="field-label">Client</div>
                                    <div class="field-value"><?= (int)($client['active'] ?? 1) === 1 ? 'Activ' : 'Inactiv' ?></div>
                                    <div class="field-label">SMS</div>
                                    <div class="field-value"><?= (int)($client['sms_enabled'] ?? 1) === 1 ? 'Activ' : 'Oprit' ?></div>
                                    <div class="field-label">ANAF</div>
                                    <div class="field-value"><?= cd_h($client['anaf_last_lookup_at'] ?: '-') ?></div>
                                </div>
                            </div>
                        </section>

                        <section class="dossier-card">
                            <div class="dossier-card-head">
                                <div class="dossier-card-title">Acțiuni</div>
                            </div>
                            <div class="dossier-card-body">
                                <div class="danger-zone">
                                    <a class="btn" href="offers?new=1&client_id=<?= (int)$clientId ?>">Ofertă</a>
                                    <a class="btn" href="service-reports?new=1&client_id=<?= (int)$clientId ?>">PV</a>
                                    <a class="btn" href="invoice.php?client_id=<?= (int)$clientId ?>">Factură</a>
                                    <a class="btn" href="payment.php?client_id=<?= (int)$clientId ?>">Încasare</a>
                                </div>
                            </div>
                        </section>
                    </aside>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
