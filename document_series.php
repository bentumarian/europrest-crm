<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

if (!is_admin()) {
    header('Location: calendar.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers Serii Documente
|--------------------------------------------------------------------------
*/
function ds_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ds_valid_identifier(string $identifier): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_]+$/', $identifier);
}

function ds_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['total'] ?? 0) > 0;
}

function ds_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['total'] ?? 0) > 0;
}

function ds_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $index]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['total'] ?? 0) > 0;
}

function ds_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!ds_valid_identifier($table) || !ds_valid_identifier($column)) {
        return;
    }

    if (!ds_table_exists($pdo, $table)) {
        return;
    }

    if (!ds_column_exists($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (Throwable $e) {
            error_log('PestZone series column error: ' . $table . '.' . $column . ' - ' . $e->getMessage());
        }
    }
}

function ds_ensure_index(PDO $pdo, string $table, string $index, string $sql): void
{
    if (!ds_valid_identifier($table) || !ds_valid_identifier($index)) {
        return;
    }

    if (!ds_table_exists($pdo, $table) || ds_index_exists($pdo, $table, $index)) {
        return;
    }

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        error_log('PestZone series index error: ' . $table . '.' . $index . ' - ' . $e->getMessage());
    }
}

function ds_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_series (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_type VARCHAR(50) NOT NULL,
            name VARCHAR(150) NOT NULL,
            series_code VARCHAR(50) NOT NULL,
            format_pattern VARCHAR(120) NOT NULL DEFAULT '{N}/{DD}.{MM}.{YYYY}',
            year INT NULL,
            next_number INT NOT NULL DEFAULT 1,
            padding INT NOT NULL DEFAULT 1,
            reset_yearly TINYINT(1) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $seriesColumns = [
        'document_type' => "VARCHAR(50) NOT NULL",
        'name' => "VARCHAR(150) NOT NULL",
        'series_code' => "VARCHAR(50) NOT NULL",
        'format_pattern' => "VARCHAR(120) NOT NULL DEFAULT '{N}/{DD}.{MM}.{YYYY}'",
        'year' => "INT NULL",
        'next_number' => "INT NOT NULL DEFAULT 1",
        'padding' => "INT NOT NULL DEFAULT 1",
        'reset_yearly' => "TINYINT(1) NOT NULL DEFAULT 0",
        'is_default' => "TINYINT(1) NOT NULL DEFAULT 0",
        'active' => "TINYINT(1) NOT NULL DEFAULT 1",
        'notes' => "TEXT NULL",
        'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
    ];

    foreach ($seriesColumns as $column => $definition) {
        ds_ensure_column($pdo, 'document_series', $column, $definition);
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_numbers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_type VARCHAR(50) NOT NULL,
            document_id INT NOT NULL,
            series_id INT NOT NULL,
            series_code VARCHAR(50) NOT NULL,
            number_int INT NOT NULL,
            full_number VARCHAR(120) NOT NULL,
            issued_date DATE NOT NULL,
            year INT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'emis',
            issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            issued_by INT NULL,
            notes TEXT NULL,
            UNIQUE KEY uniq_full_number (full_number),
            UNIQUE KEY uniq_document_ref (document_type, document_id),
            UNIQUE KEY uniq_series_number (series_id, number_int),
            INDEX idx_document_numbers_type_date (document_type, issued_date),
            INDEX idx_document_numbers_series (series_id)
        )
    ");

    $numberColumns = [
        'document_type' => "VARCHAR(50) NOT NULL",
        'document_id' => "INT NOT NULL",
        'series_id' => "INT NOT NULL",
        'series_code' => "VARCHAR(50) NOT NULL",
        'number_int' => "INT NOT NULL",
        'full_number' => "VARCHAR(120) NOT NULL",
        'issued_date' => "DATE NOT NULL",
        'year' => "INT NOT NULL",
        'status' => "VARCHAR(30) NOT NULL DEFAULT 'emis'",
        'issued_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'issued_by' => "INT NULL",
        'notes' => "TEXT NULL",
    ];

    foreach ($numberColumns as $column => $definition) {
        ds_ensure_column($pdo, 'document_numbers', $column, $definition);
    }

    ds_ensure_index($pdo, 'document_series', 'idx_document_series_type_active', "CREATE INDEX idx_document_series_type_active ON document_series (document_type, active, is_default)");
    ds_ensure_index($pdo, 'document_series', 'idx_document_series_code', "CREATE INDEX idx_document_series_code ON document_series (series_code)");
    ds_ensure_index($pdo, 'document_numbers', 'idx_document_numbers_type_date', "CREATE INDEX idx_document_numbers_type_date ON document_numbers (document_type, issued_date)");
    ds_ensure_index($pdo, 'document_numbers', 'idx_document_numbers_series', "CREATE INDEX idx_document_numbers_series ON document_numbers (series_id)");

    if (ds_table_exists($pdo, 'contracts')) {
        ds_ensure_column($pdo, 'contracts', 'document_series_id', "INT NULL");
        ds_ensure_column($pdo, 'contracts', 'document_number_id', "INT NULL");
        ds_ensure_column($pdo, 'contracts', 'issued_at', "DATETIME NULL");
        ds_ensure_column($pdo, 'contracts', 'issued_by', "INT NULL");
    }
    // Nu mai recream automat seriile șterse.
    // Seriile se gestioneaza manual din interfata: Oferte, Contracte, Procese verbale.
}


function ds_default_series_code(string $type): string
{
    $map = [
        'contract' => 'CTR',
        'oferta' => 'OF',
        'proces_verbal' => 'PV',
    ];

    return $map[$type] ?? 'CTR';
}

function ds_default_series_name(string $type): string
{
    $map = [
        'contract' => 'Contracte',
        'oferta' => 'Oferte',
        'proces_verbal' => 'Procese verbale',
    ];

    return $map[$type] ?? 'Contracte';
}

function ds_default_pattern(string $type): string
{
    if ($type === 'contract') {
        return '{N}/{DD}.{MM}.{YYYY}';
    }

    return '{SERIE} {N}/{DD}.{MM}.{YYYY}';
}

function ds_seed_default_series(PDO $pdo): void
{
    $defaults = [
        [
            'document_type' => 'contract',
            'name' => 'Contracte EuroPrest',
            'series_code' => 'CTR',
            'format_pattern' => '{N}/{DD}.{MM}.{YYYY}',
            'padding' => 1,
        ],
        [
            'document_type' => 'proces_verbal',
            'name' => 'Procese verbale EuroPrest',
            'series_code' => 'PV',
            'format_pattern' => '{SERIE} {N}/{DD}.{MM}.{YYYY}',
            'padding' => 1,
        ],
    ];

    foreach ($defaults as $default) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM document_series
            WHERE document_type = ?
        ");
        $stmt->execute([$default['document_type']]);
        $exists = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) > 0;

        if (!$exists) {
            $stmt = $pdo->prepare("
                INSERT INTO document_series
                (document_type, name, series_code, format_pattern, next_number, padding, reset_yearly, is_default, active)
                VALUES (?, ?, ?, ?, 1, ?, 0, 1, 1)
            ");
            $stmt->execute([
                $default['document_type'],
                $default['name'],
                $default['series_code'],
                $default['format_pattern'],
                $default['padding'],
            ]);
        }
    }
}

function ds_document_type_label(string $type): string
{
    $labels = [
        'contract' => 'Contract',
        'oferta' => 'Oferta',
        'proces_verbal' => 'Proces verbal',
    ];

    return $labels[$type] ?? 'Contract';
}

function ds_clean_document_type(string $type): string
{
    $type = trim($type);
    $allowed = ['contract', 'oferta', 'proces_verbal'];

    return in_array($type, $allowed, true) ? $type : 'contract';
}

function ds_clean_series_code(string $code, string $type = 'altul'): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9_\-]/', '', $code);
    return substr($code ?: ds_default_series_code($type), 0, 50);
}

function ds_clean_pattern(string $pattern, string $type = 'contract'): string
{
    $pattern = trim($pattern);
    return $pattern !== '' ? substr($pattern, 0, 120) : ds_default_pattern($type);
}

function ds_format_number(array $series, ?int $number = null, ?string $date = null): string
{
    $date = $date ?: date('Y-m-d');
    $dt = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime();

    $number = $number ?? (int)($series['next_number'] ?? 1);
    $padding = max(1, min(10, (int)($series['padding'] ?? 1)));
    $pattern = (string)($series['format_pattern'] ?? '{N}/{DD}.{MM}.{YYYY}');
    $seriesCode = (string)($series['series_code'] ?? '');

    $replacements = [
        '{SERIE}' => $seriesCode,
        '{SERIES}' => $seriesCode,
        '{NR}' => str_pad((string)$number, $padding, '0', STR_PAD_LEFT),
        '{N}' => (string)$number,
        '{YYYY}' => $dt->format('Y'),
        '{YY}' => $dt->format('y'),
        '{MM}' => $dt->format('m'),
        '{DD}' => $dt->format('d'),
    ];

    return strtr($pattern, $replacements);
}

function ds_set_default(PDO $pdo, int $seriesId): bool
{
    $stmt = $pdo->prepare("SELECT * FROM document_series WHERE id = ? LIMIT 1");
    $stmt->execute([$seriesId]);
    $series = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$series) {
        return false;
    }

    $pdo->beginTransaction();

    try {
        $pdo->prepare("UPDATE document_series SET is_default = 0 WHERE document_type = ?")
            ->execute([(string)$series['document_type']]);

        $pdo->prepare("UPDATE document_series SET is_default = 1, active = 1 WHERE id = ?")
            ->execute([$seriesId]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('PestZone set default series error: ' . $e->getMessage());
        return false;
    }
}

ds_ensure_schema($pdo);

/*
|--------------------------------------------------------------------------
| POST handlers
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_series' || $action === 'update_series') {
        $seriesId = (int)($_POST['series_id'] ?? 0);
        $documentType = ds_clean_document_type((string)($_POST['document_type'] ?? 'contract'));

        if ($action === 'update_series' && $seriesId > 0) {
            $stmt = $pdo->prepare("SELECT document_type, series_code, padding, year, reset_yearly FROM document_series WHERE id = ? LIMIT 1");
            $stmt->execute([$seriesId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $documentType = ds_clean_document_type((string)$existing['document_type']);
            }
        } else {
            $existing = null;
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $seriesCode = ds_clean_series_code((string)($_POST['series_code'] ?? ($existing['series_code'] ?? '')), $documentType);
        $formatPattern = ds_clean_pattern((string)($_POST['format_pattern'] ?? ''), $documentType);
        $nextNumber = max(1, (int)($_POST['next_number'] ?? 1));

        $padding = (int)($existing['padding'] ?? 1);
        if ($padding < 1 || $padding > 10) {
            $padding = 1;
        }

        $year = $existing['year'] ?? null;
        $resetYearly = (int)($existing['reset_yearly'] ?? 0);
        // Interfata nu mai folosește serie implicita/secundara.
        // Pastram is_default intern, dar orice serie salvata devine seria activa principala pentru tipul ei.
        $isDefault = 1;
        $active = !empty($_POST['active']) ? 1 : 0;
        $notes = null;

        if ($name === '') {
            $name = ds_default_series_name($documentType);
        }

        if ($action === 'update_series' && $seriesId > 0) {
            $stmt = $pdo->prepare("SELECT MAX(number_int) AS max_number FROM document_numbers WHERE series_id = ?");
            $stmt->execute([$seriesId]);
            $maxIssued = (int)($stmt->fetch(PDO::FETCH_ASSOC)['max_number'] ?? 0);

            if ($maxIssued > 0 && $nextNumber <= $maxIssued) {
                $nextNumber = $maxIssued + 1;
            }
        }

        try {
            $pdo->beginTransaction();

            if ($isDefault) {
                $pdo->prepare("UPDATE document_series SET is_default = 0 WHERE document_type = ?")
                    ->execute([$documentType]);
            }

            if ($action === 'create_series') {
                $stmt = $pdo->prepare("
                    INSERT INTO document_series
                    (document_type, name, series_code, format_pattern, year, next_number, padding, reset_yearly, is_default, active, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $documentType,
                    $name,
                    $seriesCode,
                    $formatPattern,
                    $year !== '' && $year !== null ? (int)$year : null,
                    $nextNumber,
                    $padding,
                    $resetYearly,
                    $isDefault,
                    $active,
                    $notes,
                ]);
            } elseif ($seriesId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE document_series
                    SET document_type = ?,
                        name = ?,
                        series_code = ?,
                        format_pattern = ?,
                        year = ?,
                        next_number = ?,
                        padding = ?,
                        reset_yearly = ?,
                        is_default = ?,
                        active = ?,
                        notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $documentType,
                    $name,
                    $seriesCode,
                    $formatPattern,
                    $year !== '' && $year !== null ? (int)$year : null,
                    $nextNumber,
                    $padding,
                    $resetYearly,
                    $isDefault,
                    $active,
                    $notes,
                    $seriesId,
                ]);
            }

            $pdo->commit();

            header('Location: document_series.php?' . ($action === 'create_series' ? 'created=1' : 'updated=1'));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('PestZone save series error: ' . $e->getMessage());
            header('Location: document_series.php?error=series');
            exit;
        }
    }
    if ($action === 'toggle_series') {
        $seriesId = (int)($_POST['series_id'] ?? 0);

        if ($seriesId > 0) {
            $pdo->prepare("
                UPDATE document_series
                SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END
                WHERE id = ?
            ")->execute([$seriesId]);

            header('Location: document_series.php?toggled=1');
            exit;
        }

        header('Location: document_series.php?error=toggle');
        exit;
    }

    if ($action === 'delete_series') {
        $seriesId = (int)($_POST['series_id'] ?? 0);

        if ($seriesId <= 0) {
            header('Location: document_series.php?error=delete');
            exit;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_numbers WHERE series_id = ?");
        $stmt->execute([$seriesId]);
        $issuedCount = (int)$stmt->fetchColumn();

        if ($issuedCount > 0) {
            header('Location: document_series.php?error=delete_used');
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM document_series WHERE id = ?");
            $stmt->execute([$seriesId]);

            header('Location: document_series.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            error_log('PestZone delete series error: ' . $e->getMessage());
            header('Location: document_series.php?error=delete');
            exit;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Date pagina
|--------------------------------------------------------------------------
*/
$editId = !empty($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editSeries = null;

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM document_series WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $editSeries = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$seriesList = $pdo->query("
    SELECT ds.*,
           (SELECT COUNT(*) FROM document_numbers dn WHERE dn.series_id = ds.id) AS issued_count,
           (SELECT MAX(dn.number_int) FROM document_numbers dn WHERE dn.series_id = ds.id) AS last_number
    FROM document_series ds
    ORDER BY ds.document_type ASC, ds.is_default DESC, ds.active DESC, ds.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$documentTypes = [
    'contract' => 'Contract',
    'oferta' => 'Oferta',
    'proces_verbal' => 'Proces verbal',
];

$formSeries = $editSeries ?: [
    'id' => 0,
    'document_type' => 'contract',
    'name' => 'Contracte EuroPrest',
    'series_code' => 'CTR',
    'format_pattern' => '{N}/{DD}.{MM}.{YYYY}',
    'year' => null,
    'next_number' => 1,
    'padding' => 1,
    'reset_yearly' => 0,
    'is_default' => 0,
    'active' => 1,
    'notes' => '',
];

if (!$editSeries && (string)($formSeries['document_type'] ?? '') === 'contract') {
    $formSeries['format_pattern'] = '{N}/{DD}.{MM}.{YYYY}';
}

$preview = ds_format_number($formSeries, (int)($formSeries['next_number'] ?? 1), date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Serii documente - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.series-page { display: grid; gap: 14px; }
.series-hero {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 20px 22px;
    box-shadow: var(--shadow);
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    flex-wrap: wrap;
}
.series-hero h1 { margin: 0 0 5px; font-size: 25px; font-weight: 650; letter-spacing: -.035em; }
.series-hero p { margin: 0; color: var(--muted); max-width: 840px; line-height: 1.45; }
.series-grid { display: grid; grid-template-columns: minmax(320px, 420px) minmax(0, 1fr); gap: 14px; align-items: start; }
.card { min-width: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 18px; box-shadow: var(--shadow); overflow: hidden; }
.card-head { padding: 14px 16px; border-bottom: 1px solid var(--border2); display:flex; justify-content:space-between; gap:10px; align-items:center; }
.card-head h2 { margin: 0; font-size: 16px; font-weight: 650; letter-spacing: -.02em; }
.card-body { padding: 16px; display: grid; gap: 12px; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.form-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { color: var(--muted); font-size: 11px; font-weight: 650; text-transform: uppercase; letter-spacing: .04em; }
.form-field input, .form-field select {
    width: 100%;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: #fff;
    min-height: 42px;
    padding: 10px 11px;
    color: var(--text);
    font-weight: 400;
    outline: none;
}
.readonly-box {
    min-height: 42px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-soft);
    padding: 10px 11px;
    color: var(--text);
    font-weight: 500;
    display:flex;
    align-items:center;
}
.checkbox-row { display: flex; align-items: center; gap: 8px; font-weight: 500; color: var(--text); }
.checkbox-row input { width: auto; min-height: 0; }
.actions-row { margin-top: 2px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 5px 9px; font-size: 12px; font-weight: 600; background: var(--surface-soft); border: 1px solid var(--border2); color: var(--muted); }
.badge.success { background: var(--success-soft); color: var(--success); border-color: rgba(31,111,84,.16); }
.badge.danger { background: var(--danger-soft); color: var(--danger); border-color: rgba(180,35,24,.16); }
.badge.neutral { background: var(--accent-soft); color: var(--accent); border-color: rgba(22,59,99,.16); }
.preview-box { background: var(--surface-soft); border: 1px dashed var(--border); border-radius: 14px; padding: 13px; font-weight: 650; color: var(--accent); margin-top: 2px; }
.help-text { color: var(--muted); font-size: 12px; line-height: 1.4; }
.table-wrap { width: 100%; overflow-x: auto; }
.series-table { width: 100%; border-collapse: collapse; min-width: 780px; }
.series-table th, .series-table td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--border2); vertical-align: middle; }
.series-table th { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; font-weight: 650; background: var(--surface-soft); }
.series-table td { color: var(--text); font-size: 14px; font-weight: 400; }
.series-number { font-weight: 650; color: var(--accent); white-space: nowrap; }
.name-main { font-weight: 600; color: var(--text); }
.name-sub { color: var(--muted); font-size: 12px; margin-top: 3px; }
.row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.notice { margin:0; }
.empty-state { background: var(--surface); border: 1px dashed var(--border); border-radius: 14px; padding: 24px; color: var(--muted); font-weight: 500; text-align: center; }
@media(max-width: 1100px) { .series-grid { grid-template-columns: 1fr; } }
@media(max-width: 720px) { .form-grid { grid-template-columns: 1fr; } .actions-row .btn, .row-actions .btn { width: 100%; justify-content:center; } .series-hero { padding: 18px; } }
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('document_series', true); ?>

    <main class="main">
        <div class="topbar" style="padding:12px 20px;">
            <a class="btn ghost" href="settings.php">Înapoi la Setări</a>
        </div>
        <div class="content series-page">
            <section class="series-hero">
                <div>
                    <h1>Serii documente</h1>
                    <p>Seteaza numerotarea documentelor. Pentru contracte, formatul recomandat este simplu: <strong>{N}/{DD}.{MM}.{YYYY}</strong>, adica <?= ds_h(date('j/d.m.Y')) ?>.</p>
                </div>
            </section>

            <?php if (isset($_GET['created']) || isset($_GET['updated']) || isset($_GET['default']) || isset($_GET['toggled'])): ?>
                <div class="notice notice-success">Modificarile au fost salvate.</div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="notice notice-danger">Operatiunea nu a putut fi finalizata. Verifica datele introduse.</div>
            <?php endif; ?>

            <div class="series-grid">
                <section class="card">
                    <div class="card-head">
                        <h2><?= $editSeries ? 'Editează seria' : 'Serie noua' ?></h2>
                    </div>

                    <form method="post" class="card-body">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= $editSeries ? 'update_series' : 'create_series' ?>">
                        <input type="hidden" name="series_id" value="<?= (int)($formSeries['id'] ?? 0) ?>">

                        <div class="form-grid">
                            <div class="form-field full">
                                <label>Tip document</label>
                                <?php if ($editSeries): ?>
                                    <input type="hidden" name="document_type" value="<?= ds_h($formSeries['document_type']) ?>">
                                    <div class="readonly-box"><?= ds_h(ds_document_type_label((string)$formSeries['document_type'])) ?></div>
                                    <div class="help-text">Tipul documentului este blocat la editare, ca sa nu fie schimbat accidental.</div>
                                <?php else: ?>
                                    <select name="document_type" id="documentTypeSelect" required>
                                        <?php foreach ($documentTypes as $value => $label): ?>
                                            <option value="<?= ds_h($value) ?>" <?= $value === (string)($formSeries['document_type'] ?? '') ? 'selected' : '' ?>><?= ds_h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>

                            <div class="form-field full">
                                <label>Nume serie</label>
                                <input type="text" name="name" value="<?= ds_h($formSeries['name'] ?? '') ?>" placeholder="Ex: Contracte EuroPrest" required>
                            </div>

                            <div class="form-field">
                                <label>Serie / prefix</label>
                                <input type="text" name="series_code" id="seriesCodeInput" value="<?= ds_h($formSeries['series_code'] ?? ds_default_series_code((string)$formSeries['document_type'])) ?>" placeholder="Ex: OF, CTR, PV">
                                <div class="help-text">Prefixul se afiseaza doar dacă formatul contine {SERIE}.</div>
                            </div>

                            <div class="form-field">
                                <label>Urmatorul numar</label>
                                <input type="number" min="1" name="next_number" value="<?= (int)($formSeries['next_number'] ?? 1) ?>" required>
                            </div>

                            <div class="form-field">
                                <label>Status</label>
                                <label class="checkbox-row" style="min-height:42px;">
                                    <input type="checkbox" name="active" value="1" <?= !empty($formSeries['active']) ? 'checked' : '' ?>>
                                    Serie activa
                                </label>
                            </div>

                            <div class="form-field full">
                                <label>Format numar</label>
                                <input type="text" name="format_pattern" value="<?= ds_h($formSeries['format_pattern'] ?? '{N}/{DD}.{MM}.{YYYY}') ?>" required>
                                <div class="preview-box">Preview: <?= ds_h($preview) ?></div>
                                <div class="help-text">
                                    Pentru contracte folosește <strong>{N}/{DD}.{MM}.{YYYY}</strong>. 
                                    Variabile disponibile: {N}, {NR}, {DD}, {MM}, {YYYY}, {SERIE}.
                                </div>
                            </div>
                        </div>

                        <div class="actions-row">
                            <button class="btn accent" type="submit"><?= $editSeries ? 'Salvează seria' : 'Adaugă seria' ?></button>
                            <?php if ($editSeries): ?>
                                <a class="btn" href="document_series.php">Renunță</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </section>

                <section class="card">
                    <div class="card-head">
                        <h2>Serii existente</h2>
                    </div>

                    <div class="card-body">
                        <?php if (!$seriesList): ?>
                            <div class="empty-state">Nu există serii documente.</div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="series-table">
                                    <thead>
                                        <tr>
                                            <th>Tip document</th>
                                            <th>Nume serie</th>
                                            <th>Urmatorul numar</th>
                                            <th>Status</th>
                                            <th>Emise</th>
                                            <th>Acțiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($seriesList as $series): ?>
                                        <?php $nextPreview = ds_format_number($series, (int)$series['next_number'], date('Y-m-d')); ?>
                                        <tr>
                                            <td><?= ds_h(ds_document_type_label((string)$series['document_type'])) ?></td>
                                            <td>
                                                <div class="name-main"><?= ds_h($series['name']) ?></div>
                                                <div class="name-sub"><?= ds_h($series['format_pattern']) ?></div>
                                            </td>
                                            <td><span class="series-number"><?= ds_h($nextPreview) ?></span></td>
                                            <td>
                                                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                                    <span class="badge <?= !empty($series['active']) ? 'success' : 'danger' ?>"><?= !empty($series['active']) ? 'Activa' : 'Inactiva' ?></span>
                                                </div>
                                            </td>
                                            <td><?= (int)($series['issued_count'] ?? 0) ?></td>
                                            <td>
                                                <div class="row-actions">
                                                    <a class="btn" href="document_series.php?edit_id=<?= (int)$series['id'] ?>">Editează</a>

                                                    <form method="post">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_series">
                                                        <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                                                        <button class="btn ghost" type="submit"><?= !empty($series['active']) ? 'Dezactiveaza' : 'Activeaza' ?></button>
                                                    </form>

                                                    <form method="post" onsubmit="return confirm('Stergi aceasta serie? Se poate sterge doar dacă nu are documente emise.');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_series">
                                                        <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                                                        <button class="btn ghost danger" type="submit">Șterge</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
<script>
(function(){
    const typeSelect = document.getElementById('documentTypeSelect');
    const seriesInput = document.getElementById('seriesCodeInput');
    const formatInput = document.querySelector('input[name="format_pattern"]');

    if (!typeSelect || !seriesInput || !formatInput) {
        return;
    }

    const defaults = {
        contract: { code: 'CTR', pattern: '{N}/{DD}.{MM}.{YYYY}' },
        oferta: { code: 'OF', pattern: '{SERIE} {N}/{DD}.{MM}.{YYYY}' },
        proces_verbal: { code: 'PV', pattern: '{SERIE} {N}/{DD}.{MM}.{YYYY}' },
        raport: { code: 'RAP', pattern: '{SERIE} {N}/{DD}.{MM}.{YYYY}' },
        altul: { code: 'DOC', pattern: '{SERIE} {N}/{DD}.{MM}.{YYYY}' }
    };

    typeSelect.addEventListener('change', function(){
        const cfg = defaults[this.value] || defaults.altul;
        seriesInput.value = cfg.code;
        formatInput.value = cfg.pattern;
    });
})();
</script>
</body>
</html>
