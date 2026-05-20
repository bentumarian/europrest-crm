<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/document_core.php';

if (!is_admin()) {
    header('Location: calendar.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| PestZone - șabloane documente
|--------------------------------------------------------------------------
| Pagina noua pentru administrarea șabloanelor folosite de motorul unic:
| - oferta
| - contract
| - proces verbal
|
| Reguli:
| - folosim doar document_templates.is_active
| - un singur șablon implicit per tip document
| - nu stergem șabloane folosite de documente emise/drafturi
|--------------------------------------------------------------------------
*/

pzdoc_require_schema($pdo);

function dtpl_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dtpl_redirect(array $params = []): void
{
    $base = 'document_templates.php';
    $query = $params ? ('?' . http_build_query($params)) : '';
    header('Location: ' . $base . $query);
    exit;
}

function dtpl_redirect_url(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function dtpl_types(): array
{
    return [
        'oferta' => 'Oferta',
        'contract' => 'Contract',
        'act_aditional' => 'Act adițional',
        'proces_verbal' => 'Proces verbal',
    ];
}

function dtpl_type_label(string $type): string
{
    $types = dtpl_types();
    $type = pzdoc_normalize_document_type($type);
    return $types[$type] ?? 'Document';
}

function dtpl_normalized_type_or_all(string $type): string
{
    $type = trim($type);
    if ($type === '' || $type === 'all') {
        return 'all';
    }

    $type = pzdoc_normalize_document_type($type);
    return array_key_exists($type, dtpl_types()) ? $type : 'all';
}

function dtpl_status_filter(string $status): string
{
    $status = trim($status);
    return in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'all';
}

function dtpl_template_used_count(PDO $pdo, int $templateId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE template_id = ?');
    $stmt->execute([$templateId]);
    return (int)$stmt->fetchColumn();
}

function dtpl_get_template(PDO $pdo, int $templateId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM document_templates WHERE id = ? LIMIT 1');
    $stmt->execute([$templateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dtpl_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '_', $text) ?: 'sablon';
    $text = trim($text, '_');
    return $text !== '' ? substr($text, 0, 160) : 'sablon';
}

function dtpl_unique_slug(PDO $pdo, string $base): string
{
    $base = dtpl_slugify($base);
    $slug = $base;
    $i = 2;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM document_templates WHERE slug = ?');
    while (true) {
        $stmt->execute([$slug]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '_' . $i;
        $i++;
    }
}

function dtpl_has_default(PDO $pdo, string $type): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM document_templates WHERE document_type = ? AND is_default = 1 AND is_active = 1');
    $stmt->execute([$type]);
    return (int)$stmt->fetchColumn() > 0;
}

function dtpl_create_template(PDO $pdo, string $type, string $name, ?string $description = null, ?string $content = null): int
{
    $type = pzdoc_validate_document_type($type);
    $name = trim($name) !== '' ? trim($name) : ('Șablon ' . dtpl_type_label($type));
    $description = $description !== null && trim($description) !== '' ? trim($description) : 'Șablon creat in motorul nou de documente.';
    $content = $content !== null && trim($content) !== '' ? $content : pzdoc_default_template_content($type);
    $slug = dtpl_unique_slug($pdo, $type . '_' . $name);
    $isDefault = dtpl_has_default($pdo, $type) ? 0 : 1;

    $stmt = $pdo->prepare("\n        INSERT INTO document_templates\n            (document_type, name, slug, description, content_html, is_default, is_active, created_by)\n        VALUES\n            (?, ?, ?, ?, ?, ?, 1, ?)\n    ");

    $stmt->execute([
        $type,
        $name,
        $slug,
        $description,
        $content,
        $isDefault,
        current_user_id(),
    ]);

    return (int)$pdo->lastInsertId();
}

function dtpl_set_default(PDO $pdo, int $templateId): void
{
    $template = dtpl_get_template($pdo, $templateId);
    if (!$template) {
        throw new RuntimeException('Șablonul nu a fost gasit.');
    }

    $type = pzdoc_validate_document_type((string)$template['document_type']);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE document_templates SET is_default = 0 WHERE document_type = ?');
        $stmt->execute([$type]);

        $stmt = $pdo->prepare('UPDATE document_templates SET is_default = 1, is_active = 1 WHERE id = ?');
        $stmt->execute([$templateId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function dtpl_stats(PDO $pdo): array
{
    $stats = [];
    foreach (array_keys(dtpl_types()) as $type) {
        $stmt = $pdo->prepare("\n            SELECT\n                COUNT(*) AS total,\n                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count,\n                SUM(CASE WHEN is_default = 1 THEN 1 ELSE 0 END) AS default_count\n            FROM document_templates\n            WHERE document_type = ?\n        ");
        $stmt->execute([$type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats[$type] = [
            'total' => (int)($row['total'] ?? 0),
            'active' => (int)($row['active_count'] ?? 0),
            'default' => (int)($row['default_count'] ?? 0),
        ];
    }
    return $stats;
}

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'seed_defaults') {
            pzdoc_install_document_schema($pdo);
            dtpl_redirect(['ok' => 'defaults']);
        }

        if ($action === 'create_quick') {
            $type = pzdoc_validate_document_type((string)($_POST['document_type'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $id = dtpl_create_template($pdo, $type, $name);
            dtpl_redirect_url('document_template_edit.php?id=' . $id . '&ok=created');
        }

        if ($action === 'duplicate') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            $template = dtpl_get_template($pdo, $templateId);
            if (!$template) {
                throw new RuntimeException('Șablonul nu a fost gasit.');
            }

            $newId = dtpl_create_template(
                $pdo,
                (string)$template['document_type'],
                (string)$template['name'] . ' - copie',
                (string)($template['description'] ?? ''),
                (string)($template['content_html'] ?? '')
            );
            dtpl_redirect_url('document_template_edit.php?id=' . $newId . '&ok=duplicated');
        }

        if ($action === 'set_default') {
            dtpl_set_default($pdo, (int)($_POST['template_id'] ?? 0));
            dtpl_redirect(['ok' => 'default']);
        }

        if ($action === 'toggle') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            $template = dtpl_get_template($pdo, $templateId);
            if (!$template) {
                throw new RuntimeException('Șablonul nu a fost gasit.');
            }

            $isDefault = (int)($template['is_default'] ?? 0) === 1;
            $isActive = (int)($template['is_active'] ?? 1) === 1;

            if ($isDefault && $isActive) {
                dtpl_redirect(['err' => 'default_toggle']);
            }

            $stmt = $pdo->prepare('UPDATE document_templates SET is_active = ? WHERE id = ?');
            $stmt->execute([$isActive ? 0 : 1, $templateId]);
            dtpl_redirect(['ok' => 'toggled']);
        }

        if ($action === 'delete') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            $template = dtpl_get_template($pdo, $templateId);
            if (!$template) {
                throw new RuntimeException('Șablonul nu a fost gasit.');
            }

            if ((int)($template['is_default'] ?? 0) === 1) {
                dtpl_redirect(['err' => 'default_delete']);
            }

            if (dtpl_template_used_count($pdo, $templateId) > 0) {
                dtpl_redirect(['err' => 'used_delete']);
            }

            $stmt = $pdo->prepare('DELETE FROM document_templates WHERE id = ?');
            $stmt->execute([$templateId]);
            dtpl_redirect(['ok' => 'deleted']);
        }
    } catch (Throwable $e) {
        error_log('PestZone document templates action error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$okMessages = [
    'defaults' => 'Șabloanele implicite au fost verificate.',
    'created' => 'Șablonul a fost creat.',
    'duplicated' => 'Șablonul a fost duplicat.',
    'default' => 'Șablonul implicit a fost actualizat.',
    'toggled' => 'Statusul șablonului a fost schimbat.',
    'deleted' => 'Șablonul a fost șters.',
];

$errorMessages = [
    'default_toggle' => 'Șablonul implicit nu poate fi dezactivat. Alege intai alt șablon implicit.',
    'default_delete' => 'Șablonul implicit nu poate fi șters. Alege intai alt șablon implicit.',
    'used_delete' => 'Șablonul este folosit de documente si nu poate fi șters.',
];

if (isset($_GET['ok'], $okMessages[(string)$_GET['ok']])) {
    $flash = $okMessages[(string)$_GET['ok']];
}

if (!$error && isset($_GET['err'], $errorMessages[(string)$_GET['err']])) {
    $error = $errorMessages[(string)$_GET['err']];
}

$typeFilter = dtpl_normalized_type_or_all((string)($_GET['type'] ?? 'all'));
$statusFilter = dtpl_status_filter((string)($_GET['status'] ?? 'all'));
$q = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($typeFilter !== 'all') {
    $where[] = 'document_type = ?';
    $params[] = $typeFilter;
}

if ($statusFilter === 'active') {
    $where[] = 'is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'is_active = 0';
}

if ($q !== '') {
    $where[] = '(name LIKE ? OR description LIKE ? OR slug LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$sql = "\n    SELECT *\n    FROM document_templates\n";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where) . "\n";
}

$sql .= " ORDER BY document_type ASC, is_default DESC, is_active DESC, name ASC, id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$usageCounts = [];
foreach ($templates as $template) {
    $usageCounts[(int)$template['id']] = dtpl_template_used_count($pdo, (int)$template['id']);
}

$stats = dtpl_stats($pdo);
$totalTemplates = array_sum(array_column($stats, 'total'));
$totalActive = array_sum(array_column($stats, 'active'));
$totalDefault = array_sum(array_column($stats, 'default'));
$isAdmin = is_admin();
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Șabloane documente - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

<style>
.document-topbar {
    align-items: center;
    padding: 12px 20px;
}

.document-toolbar {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}

.document-hero {
    background: var(--pz-brand, #12345A);
    color: #fff;
    border-radius: var(--radius-lg);
    padding: 22px 24px;
    box-shadow: var(--shadow-lg);
    margin-bottom: 14px;
    display: flex;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
    align-items: center;
}

.document-hero h1 {
    font-size: 24px;
    font-weight: 900;
    letter-spacing: -.03em;
    margin: 0;
}

.document-hero p {
    color: rgba(255, 255, 255, .72);
    margin: 4px 0 0;
    max-width: 850px;
}

.stats {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.stat-pill {
    background: rgba(255, 255, 255, .10);
    border: 1px solid rgba(255, 255, 255, .16);
    border-radius: 999px;
    padding: 8px 13px;
    color: #fff;
    font-weight: 900;
    font-size: 13px;
    white-space: nowrap;
}

.filter-card,
.template-card,
.create-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
}

.filter-card {
    padding: 12px;
    margin-bottom: 12px;
}

.filter-form {
    display: grid;
    grid-template-columns: minmax(220px, 1.2fr) minmax(160px, .6fr) minmax(160px, .6fr) auto;
    gap: 10px;
    align-items: end;
}

.field label {
    display: block;
    margin-bottom: 5px;
}

.field input,
.field select {
    width: 100%;
}

.create-card {
    padding: 14px;
    margin-bottom: 12px;
}

.create-grid {
    display: grid;
    grid-template-columns: minmax(180px, .7fr) minmax(220px, 1fr) auto;
    gap: 10px;
    align-items: end;
}

.templates-list {
    display: grid;
    gap: 10px;
}

.template-card {
    padding: 14px;
    display: grid;
    grid-template-columns: minmax(260px, 1.35fr) minmax(170px, .65fr) minmax(180px, .8fr) auto;
    gap: 12px;
    align-items: center;
}

.template-title {
    font-size: 15px;
    font-weight: 900;
    color: var(--text);
    overflow-wrap: anywhere;
}

.template-desc {
    color: var(--muted);
    font-size: 12px;
    margin-top: 4px;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.template-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}

.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    padding: 6px 9px;
    font-size: 11px;
    font-weight: 850;
    border: 1px solid var(--border2);
    background: var(--surface-soft);
    color: var(--muted);
    white-space: nowrap;
}

.badge.type {
    background: var(--accent-soft);
    border-color: var(--accent-soft-2);
    color: var(--accent-deep);
}

.badge.active {
    background: var(--success-soft);
    border-color: rgba(31, 111, 84, .18);
    color: var(--success);
}

.badge.inactive {
    background: var(--danger-soft);
    border-color: rgba(180, 35, 24, .18);
    color: var(--danger);
}

.badge.default {
    background: var(--warning-soft);
    border-color: rgba(154, 103, 0, .18);
    color: var(--warning);
}

.template-actions {
    display: flex;
    justify-content: flex-end;
    gap: 7px;
    flex-wrap: wrap;
}

.template-actions form {
    display: inline-flex;
    margin: 0;
}

.btn.small,
button.btn.small,
a.btn.small {
    min-height: 32px !important;
    padding-left: 9px !important;
    padding-right: 9px !important;
    font-size: 11.5px !important;
}

.empty-state {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 30px;
    text-align: center;
    color: var(--muted);
    font-weight: 800;
}

.code-preview {
    margin-top: 8px;
    background: var(--surface-soft);
    border: 1px solid var(--border2);
    border-radius: 10px;
    padding: 8px 10px;
    color: var(--muted);
    font-family: var(--mono);
    font-size: 11px;
    line-height: 1.35;
    max-height: 55px;
    overflow: hidden;
}

@media(max-width: 1120px) {
    .template-card {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .template-actions {
        justify-content: flex-start;
    }
}

@media(max-width: 860px) {
    .document-topbar {
        width: 100% !important;
        padding: 8px 10px 14px 10px !important;
        display: block !important;
    }

    .document-toolbar {
        display: grid !important;
        grid-template-columns: 1fr !important;
        gap: 8px !important;
    }

    .document-toolbar .btn,
    .document-toolbar form,
    .document-toolbar button {
        width: 100% !important;
    }

    .document-hero {
        padding: 18px;
    }

    .document-hero h1 {
        font-size: 22px;
    }

    .stats {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr;
    }

    .filter-form,
    .create-grid {
        grid-template-columns: 1fr;
    }

    .filter-form .btn,
    .create-grid .btn {
        width: 100%;
    }

    .template-actions {
        display: grid;
        grid-template-columns: 1fr;
    }

    .template-actions .btn,
    .template-actions form,
    .template-actions button {
        width: 100%;
    }
}
</style>
</head>

<body>
<div class="layout">
    <?php render_sidebar('document_templates', $isAdmin); ?>

    <main class="main">
        <div class="topbar document-topbar">
            <div class="document-toolbar">
                <a class="btn ghost" href="settings.php">Înapoi la Setări</a>
                <a class="btn" href="document_template_edit.php">+ Șablon nou</a>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="seed_defaults">
                    <button class="btn" type="submit">Verifica șabloane implicite</button>
                </form>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="notice notice-success"><?= dtpl_h($flash) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="notice notice-danger"><?= dtpl_h($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($_GET['edit'])): ?>
            <div class="notice notice-warning">
                Șablonul a fost creat. Deschide editorul: <a href="document_template_edit.php?id=<?= (int)$_GET['edit'] ?>">document_template_edit.php?id=<?= (int)$_GET['edit'] ?></a>
            </div>
        <?php endif; ?>

        <div class="content">
            <section class="document-hero">
                <div>
                    <h1>Șabloane documente</h1>
                    <p>
                        Administreaza șabloanele folosite de motorul unic pentru oferte, contracte, acte adiționale si procese verbale.
                        Emiterea documentelor va lua automat șablonul implicit al fiecarui tip.
                    </p>
                </div>

                <div class="stats">
                    <span class="stat-pill"><?= (int)$totalTemplates ?> șabloane</span>
                    <span class="stat-pill"><?= (int)$totalActive ?> active</span>
                    <span class="stat-pill"><?= (int)$totalDefault ?> implicite</span>
                </div>
            </section>

            <section class="create-card">
                <form class="create-grid" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_quick">

                    <div class="field">
                        <label>Tip document</label>
                        <select name="document_type" required>
                            <?php foreach (dtpl_types() as $type => $label): ?>
                                <option value="<?= dtpl_h($type) ?>"><?= dtpl_h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Nume șablon</label>
                        <input type="text" name="name" placeholder="Ex: Oferta standard corporate" required>
                    </div>

                    <button class="btn accent" type="submit">Creeaza rapid</button>
                </form>
            </section>

            <section class="filter-card">
                <form class="filter-form" method="get">
                    <div class="field">
                        <label>Căutare</label>
                        <input type="search" name="q" value="<?= dtpl_h($q) ?>" placeholder="Caută după nume, descriere sau slug">
                    </div>

                    <div class="field">
                        <label>Tip document</label>
                        <select name="type">
                            <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>Toate</option>
                            <?php foreach (dtpl_types() as $type => $label): ?>
                                <option value="<?= dtpl_h($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= dtpl_h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Status</label>
                        <select name="status">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Toate</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <button class="btn" type="submit">Filtreaza</button>
                </form>
            </section>

            <?php if (!$templates): ?>
                <div class="empty-state">
                    Nu există șabloane pentru filtrul selectat.
                </div>
            <?php else: ?>
                <section class="templates-list">
                    <?php foreach ($templates as $template): ?>
                        <?php
                            $templateId = (int)$template['id'];
                            $isActive = (int)($template['is_active'] ?? 1) === 1;
                            $isDefault = (int)($template['is_default'] ?? 0) === 1;
                            $usedCount = (int)($usageCounts[$templateId] ?? 0);
                            $content = trim(strip_tags((string)($template['content_html'] ?? '')));
                            if (function_exists('mb_substr')) {
                                $contentShort = mb_substr($content, 0, 260, 'UTF-8');
                            } else {
                                $contentShort = substr($content, 0, 260);
                            }
                        ?>

                        <article class="template-card">
                            <div>
                                <div class="template-title"><?= dtpl_h($template['name'] ?? 'Șablon') ?></div>
                                <div class="template-desc">
                                    <?= !empty($template['description']) ? dtpl_h($template['description']) : 'Fara descriere.' ?>
                                </div>
                                <?php if ($contentShort !== ''): ?>
                                    <div class="code-preview"><?= dtpl_h($contentShort) ?><?= strlen($content) > strlen($contentShort) ? '...' : '' ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="template-meta">
                                <span class="badge type"><?= dtpl_h(dtpl_type_label((string)$template['document_type'])) ?></span>
                                <span class="badge <?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? 'Activ' : 'Inactiv' ?></span>
                                <?php if ($isDefault): ?>
                                    <span class="badge default">Implicit</span>
                                <?php endif; ?>
                            </div>

                            <div class="template-meta">
                                <span class="badge">ID <?= $templateId ?></span>
                                <span class="badge"><?= $usedCount ?> documente</span>
                                <?php if (!empty($template['updated_at'])): ?>
                                    <span class="badge">Update <?= dtpl_h(date('d.m.Y', strtotime((string)$template['updated_at']))) ?></span>
                                <?php elseif (!empty($template['created_at'])): ?>
                                    <span class="badge">Creat <?= dtpl_h(date('d.m.Y', strtotime((string)$template['created_at']))) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="template-actions">
                                <a class="btn small" href="document_template_edit.php?id=<?= $templateId ?>">Editează</a>

                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="duplicate">
                                    <input type="hidden" name="template_id" value="<?= $templateId ?>">
                                    <button class="btn small" type="submit">Copiaza</button>
                                </form>

                                <?php if (!$isDefault): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_default">
                                        <input type="hidden" name="template_id" value="<?= $templateId ?>">
                                        <button class="btn small" type="submit">Set implicit</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="template_id" value="<?= $templateId ?>">
                                    <button class="btn small" type="submit"><?= $isActive ? 'Dezactiveaza' : 'Activeaza' ?></button>
                                </form>

                                <form method="post" onsubmit="return confirm('Sigur vrei sa stergi acest șablon?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="template_id" value="<?= $templateId ?>">
                                    <button class="btn small danger" type="submit">Șterge</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
