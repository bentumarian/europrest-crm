<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/document_core.php';

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
    error_log('PestZone documente init error: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| PestZone - Documente centralizat
|--------------------------------------------------------------------------
| Pagina centrala pentru toate documentele emise prin motorul nou:
| - oferte
| - contracte
| - procese verbale
|--------------------------------------------------------------------------
*/

function pz_docs_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pz_docs_types(): array
{
    return [
        'oferta' => 'Oferta',
        'contract' => 'Contract',
        'act_aditional' => 'Act adițional',
        'proces_verbal' => 'Proces verbal',
    ];
}

function pz_docs_type_label(?string $type): string
{
    $types = pz_docs_types();
    $type = (string)$type;
    return $types[$type] ?? 'Document';
}

function pz_docs_type_class(?string $type): string
{
    return [
        'oferta' => 'oferta',
        'contract' => 'contract',
        'proces_verbal' => 'proces-verbal',
    ][(string)$type] ?? 'document';
}

function pz_docs_type_new_url(string $type): string
{
    return [
        'oferta' => 'offers?new=1',
        'contract' => 'contracts.php?new=1',
        'proces_verbal' => 'service-reports?new=1',
    ][$type] ?? 'documents';
}

function pz_docs_status_label(?string $status): string
{
    return [
        'draft' => 'Draft',
        'issued' => 'Emis',
        'cancelled' => 'Anulat',
    ][(string)$status] ?? (string)$status;
}

function pz_docs_status_class(?string $status): string
{
    return [
        'draft' => 'draft',
        'issued' => 'issued',
        'cancelled' => 'cancelled',
    ][(string)$status] ?? 'draft';
}

function pz_docs_date_ro(?string $date): string
{
    if (!$date) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : '-';
}

function pz_docs_datetime_ro(?string $date): string
{
    if (!$date) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y H:i', $ts) : '-';
}

function pz_docs_money($value, string $currency = 'RON'): string
{
    return number_format((float)$value, 2, ',', '.') . ' ' . $currency;
}

function pz_docs_current_url(array $extra = []): string
{
    $params = $_GET;
    foreach ($extra as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'documents' . ($params ? '?' . http_build_query($params) : '');
}

function pz_docs_filter_type(string $type): string
{
    if ($type === '' || $type === 'all') {
        return 'all';
    }
    return array_key_exists($type, pz_docs_types()) ? $type : 'all';
}

function pz_docs_filter_status(string $status): string
{
    if ($status === '' || $status === 'all') {
        return 'all';
    }
    return in_array($status, ['draft', 'issued', 'cancelled'], true) ? $status : 'all';
}

function pz_docs_build_where(array $filters, array &$params): string
{
    $where = ['document_type IN (\'oferta\', \'contract\', \'act_aditional\', \'proces_verbal\')'];

    if (($filters['type'] ?? 'all') !== 'all') {
        $where[] = 'document_type = ?';
        $params[] = $filters['type'];
    }

    if (($filters['status'] ?? 'all') !== 'all') {
        $where[] = 'status = ?';
        $params[] = $filters['status'];
    }

    if (!empty($filters['date_from'])) {
        $where[] = 'document_date >= ?';
        $params[] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $where[] = 'document_date <= ?';
        $params[] = $filters['date_to'];
    }

    if (!empty($filters['q'])) {
        $q = '%' . trim((string)$filters['q']) . '%';
        $where[] = '(document_number LIKE ? OR title LIKE ? OR client_name_snapshot LIKE ? OR client_identifier_snapshot LIKE ? OR client_representative_snapshot LIKE ? OR client_email_snapshot LIKE ? OR location_name_snapshot LIKE ?)';
        array_push($params, $q, $q, $q, $q, $q, $q, $q);
    }

    return implode(' AND ', $where);
}

function pz_docs_count(PDO $pdo, array $filters): int
{
    $params = [];
    $where = pz_docs_build_where($filters, $params);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE ' . $where);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function pz_docs_fetch(PDO $pdo, array $filters, int $limit, int $offset): array
{
    $params = [];
    $where = pz_docs_build_where($filters, $params);
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);

    $sql = "
        SELECT *
        FROM documents
        WHERE {$where}
        ORDER BY
            CASE status WHEN 'draft' THEN 0 WHEN 'issued' THEN 1 ELSE 2 END,
            document_date DESC,
            id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pz_docs_stats(PDO $pdo): array
{
    $empty = [];
    foreach (pz_docs_types() as $type => $label) {
        $empty[$type] = [
            'total' => 0,
            'draft' => 0,
            'issued' => 0,
            'cancelled' => 0,
            'value' => 0.0,
        ];
    }

    $stmt = $pdo->query(" 
        SELECT document_type, status, COUNT(*) AS total_count, COALESCE(SUM(total_amount), 0) AS total_value
        FROM documents
        WHERE document_type IN ('oferta', 'contract', 'act_aditional', 'proces_verbal')
        GROUP BY document_type, status
    ");

    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $type = (string)$row['document_type'];
        $status = (string)$row['status'];
        if (!isset($empty[$type])) {
            continue;
        }
        $count = (int)$row['total_count'];
        $empty[$type]['total'] += $count;
        if (isset($empty[$type][$status])) {
            $empty[$type][$status] += $count;
        }
        if ($status === 'issued') {
            $empty[$type]['value'] += (float)$row['total_value'];
        }
    }

    return $empty;
}

$q = trim((string)($_GET['q'] ?? ''));
$type = pz_docs_filter_type((string)($_GET['type'] ?? 'all'));
$status = pz_docs_filter_status((string)($_GET['status'] ?? 'all'));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$perPageOptions = [20, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 20;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$filters = [
    'q' => $q,
    'type' => $type,
    'status' => $status,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

$errorMessage = '';
$documents = [];
$totalRows = 0;
$stats = [];

try {
    $totalRows = pz_docs_count($pdo, $filters);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $documents = pz_docs_fetch($pdo, $filters, $perPage, $offset);
    $stats = pz_docs_stats($pdo);
} catch (Throwable $e) {
    $errorMessage = 'Nu am putut incarca lista de documente.';
    $totalPages = 1;
    error_log('PestZone documente list error: ' . $e->getMessage());
}

$totalAll = 0;
$totalDraft = 0;
$totalIssued = 0;
$totalCancelled = 0;
foreach ($stats as $stat) {
    $totalAll += (int)$stat['total'];
    $totalDraft += (int)$stat['draft'];
    $totalIssued += (int)$stat['issued'];
    $totalCancelled += (int)$stat['cancelled'];
}
?>
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<title>Documente - PestZone</title>
<?php app_theme_css(); ?>
<?php render_search_preview_assets(); ?>
</head>
<body>
<div class="layout">
    <?php render_sidebar('documente', $isAdmin); ?>

    <main class="main">
        <header class="topbar docs-topbar">
            <div class="docs-toolbar">
                <a class="btn primary" href="offers?new=1">Ofertă nouă</a>
                <a class="btn primary" href="contracts.php?new=1">Contract nou</a>
            </div>
        </header>

        <div class="content docs-page">
            <?php if ($errorMessage): ?>
                <div class="alert error"><?= pz_docs_h($errorMessage) ?></div>
            <?php endif; ?>

            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total documente</div>
                    <div class="stat-value"><?= (int)$totalAll ?></div>
                    <div class="stat-sub"><?= (int)$totalIssued ?> emise / <?= (int)$totalDraft ?> drafturi</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Oferte</div>
                    <div class="stat-value"><?= (int)($stats['oferta']['total'] ?? 0) ?></div>
                    <div class="stat-sub">Valoare emisa: <?= pz_docs_money($stats['oferta']['value'] ?? 0) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Contracte</div>
                    <div class="stat-value"><?= (int)($stats['contract']['total'] ?? 0) ?></div>
                    <div class="stat-sub">Valoare emisa: <?= pz_docs_money($stats['contract']['value'] ?? 0) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Procese verbale</div>
                    <div class="stat-value"><?= (int)($stats['proces_verbal']['total'] ?? 0) ?></div>
                    <div class="stat-sub"><?= (int)($stats['proces_verbal']['issued'] ?? 0) ?> emise / <?= (int)($stats['proces_verbal']['draft'] ?? 0) ?> drafturi</div>
                </div>
            </section>

            <section class="quick-grid">
                <?php foreach (pz_docs_types() as $typeKey => $typeLabel): ?>
                    <div class="quick-card">
                        <div>
                            <strong><?= pz_docs_h($typeLabel) ?></strong>
                            <span><?= (int)($stats[$typeKey]['total'] ?? 0) ?> documente inregistrate</span>
                        </div>
                        <a class="btn small primary" href="<?= pz_docs_h(pz_docs_type_new_url($typeKey)) ?>">Document nou</a>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Filtrare documente</div>
                        <div class="panel-subtitle">Caută după client, CUI, reprezentant, numar document, titlu sau locație.</div>
                    </div>
                    <a class="btn small" href="documents">Reset</a>
                </div>
                <div class="panel-body">
                    <form method="get" class="filter-form">
                        <div class="field">
                            <label>Căutare</label>
                            <div class="pz-search-wrap">
                                <input type="search" id="documenteSearchInput" name="q" value="<?= pz_docs_h($q) ?>" placeholder="Client, CUI, numar, locație" autocomplete="off">
                                <div class="pz-search-preview"></div>
                            </div>
                        </div>
                        <div class="field">
                            <label>Tip</label>
                            <select name="type">
                                <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>Toate</option>
                                <?php foreach (pz_docs_types() as $typeKey => $typeLabel): ?>
                                    <option value="<?= pz_docs_h($typeKey) ?>" <?= $type === $typeKey ? 'selected' : '' ?>><?= pz_docs_h($typeLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Status</label>
                            <select name="status">
                                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Toate</option>
                                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="issued" <?= $status === 'issued' ? 'selected' : '' ?>>Emis</option>
                                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Anulat</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>De la</label>
                            <input type="date" name="date_from" value="<?= pz_docs_h($dateFrom) ?>">
                        </div>
                        <div class="field">
                            <label>Până la</label>
                            <input type="date" name="date_to" value="<?= pz_docs_h($dateTo) ?>">
                        </div>
                        <div class="field">
                            <label>Randuri</label>
                            <select name="per_page">
                                <?php foreach ($perPageOptions as $option): ?>
                                    <option value="<?= (int)$option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= (int)$option ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>&nbsp;</label>
                            <button class="btn dark" type="submit">Aplica</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="docs-list">
                <?php if (!$documents): ?>
                    <div class="empty-state">Nu există documente pentru filtrele selectate.</div>
                <?php else: ?>
                    <?php foreach ($documents as $doc): ?>
                        <?php
                            $docId = (int)$doc['id'];
                            $docStatus = (string)$doc['status'];
                            $docType = (string)$doc['document_type'];
                            $isDraft = $docStatus === 'draft';
                            $isIssued = $docStatus === 'issued';
                            $number = trim((string)($doc['document_number'] ?? ''));
                            $numberLabel = $number !== '' ? $number : ('Draft #' . $docId);
                            $clientName = trim((string)($doc['client_name_snapshot'] ?? ''));
                            $clientIdentifier = trim((string)($doc['client_identifier_snapshot'] ?? ''));
                            $locationName = trim((string)($doc['location_name_snapshot'] ?? ''));
                            $locationAddress = trim((string)($doc['location_address_snapshot'] ?? ''));
                            $emailCount = (int)($doc['email_sent_count'] ?? 0);
                        ?>
                        <article class="doc-row">
                            <div>
                                <div class="doc-title">
                                    <?= pz_docs_h($doc['title'] ?: pz_docs_type_label($docType)) ?>
                                </div>
                                <div class="doc-meta">
                                    <span class="badge <?= pz_docs_h(pz_docs_type_class($docType)) ?>"><?= pz_docs_h(pz_docs_type_label($docType)) ?></span>
                                    <span class="badge <?= pz_docs_h(pz_docs_status_class($docStatus)) ?>"><?= pz_docs_h(pz_docs_status_label($docStatus)) ?></span>
                                </div>
                            </div>

                            <div>
                                <div class="doc-number"><?= pz_docs_h($numberLabel) ?></div>
                                <div class="doc-meta"><?= pz_docs_h(pz_docs_date_ro($doc['document_date'] ?? null)) ?><?= !empty($doc['document_time']) ? ' / ' . pz_docs_h(substr((string)$doc['document_time'], 0, 5)) : '' ?></div>
                            </div>

                            <div>
                                <div class="doc-title"><?= pz_docs_h($clientName !== '' ? $clientName : 'Fara client') ?></div>
                                <div class="doc-meta">
                                    <?= $clientIdentifier !== '' ? 'CUI/CNP: ' . pz_docs_h($clientIdentifier) : 'Identificator lipsa' ?>
                                </div>
                            </div>

                            <div>
                                <div class="doc-title"><?= pz_docs_h($locationName !== '' ? $locationName : 'Locație principala') ?></div>
                                <div class="doc-meta"><?= pz_docs_h($locationAddress !== '' ? $locationAddress : 'Sediu social / domiciliu') ?></div>
                            </div>

                            <div>
                                <div class="doc-title"><?= pz_docs_h(pz_docs_money($doc['total_amount'] ?? 0, $doc['currency'] ?? 'RON')) ?></div>
                                <div class="email-state <?= $emailCount > 0 ? 'sent' : '' ?>">
                                    <?= $emailCount > 0 ? ('Email trimis x' . $emailCount) : 'Email netrimis' ?>
                                </div>
                            </div>

                            <div class="doc-actions">
                                <a class="btn small" href="document_view.php?id=<?= $docId ?>">Vezi</a>
                                <?php if ($isDraft): ?>
                                    <a class="btn small" href="document_edit.php?id=<?= $docId ?>">Editează</a>
                                <?php endif; ?>
                                <a class="btn small" href="document_pdf.php?id=<?= $docId ?>&mode=inline" target="_blank">PDF</a>
                                <?php if ($isIssued): ?>
                                    <a class="btn small primary" href="document_send_email.php?id=<?= $docId ?>">Email</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a class="btn small" href="<?= pz_docs_h(pz_docs_current_url(['page' => $page - 1])) ?>">Înapoi</a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a class="btn small <?= $i === $page ? 'primary' : '' ?>" href="<?= pz_docs_h(pz_docs_current_url(['page' => $i])) ?>"><?= (int)$i ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a class="btn small" href="<?= pz_docs_h(pz_docs_current_url(['page' => $page + 1])) ?>">Înainte</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php
// Preview live pentru bara „Caută document".
$previewDocsList = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'documents'")->fetch()) {
        $stmtPrev = $pdo->query("
            SELECT d.id, d.document_number, d.title, c.name AS client_name, c.fiscal_code
            FROM documents d
            LEFT JOIN clients c ON c.id = d.client_id
            ORDER BY d.id DESC LIMIT 2000
        ");
        while ($r = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
            $nm  = html_entity_decode((string)($r['client_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cf  = html_entity_decode((string)($r['fiscal_code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $num = trim((string)($r['document_number'] ?? ''));
            $ttl = html_entity_decode((string)($r['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $base = $num !== '' ? $num : ($ttl !== '' ? $ttl : ('Document #' . (int)$r['id']));
            $title = $base . ($nm !== '' ? ' · ' . $nm : '');
            $previewDocsList[] = [
                'title'  => $title,
                'url'    => 'document_view.php?id=' . (int)$r['id'],
                'type'   => 'document',
                'search' => $num . ' ' . $ttl . ' ' . $nm . ' ' . $cf,
            ];
        }
    }
} catch (Throwable $e) { error_log('documente.php preview: ' . $e->getMessage()); }
?>
<script>
(
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('documenteSearchInput',
            <?= json_encode($previewDocsList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
    };
    go();
})();
</script>
