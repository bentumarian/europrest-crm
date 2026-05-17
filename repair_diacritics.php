<?php
require_once __DIR__ . '/config.php';
require_login();

if (!is_admin()) {
    http_response_code(403);
    exit('Acces interzis.');
}

function rd_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rd_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function rd_repair_text(string $value): string
{
    if ($value === '') {
        return '';
    }

    $value = strtr($value, [
        'Äƒ' => 'ă', 'Ä‚' => 'Ă',
        'Ã¢' => 'â', 'Ã‚' => 'Â',
        'Ã®' => 'î', 'ÃŽ' => 'Î',
        'È™' => 'ș', 'È˜' => 'Ș',
        'È›' => 'ț', 'Èš' => 'Ț',
        'ÅŸ' => 'ș', 'Åž' => 'Ș',
        'Å£' => 'ț', 'Å¢' => 'Ț',
        'Ã©' => 'é', 'Ã‰' => 'É',
    ]);

    $map = [
        '?os.' => 'Șos.', '?OS.' => 'ȘOS.', '?oseaua' => 'Șoseaua', '?OSEAUA' => 'ȘOSEAUA',
        'Bucure?ti' => 'București', 'BUCURE?TI' => 'BUCUREȘTI', 'bucure?ti' => 'bucurești',
        'Ploie?ti' => 'Ploiești', 'PLOIE?TI' => 'PLOIEȘTI', 'ploie?ti' => 'ploiești',
        'Constan?a' => 'Constanța', 'CONSTAN?A' => 'CONSTANȚA', 'constan?a' => 'constanța',
        'N?vodari' => 'Năvodari', 'N?VODARI' => 'NĂVODARI', 'n?vodari' => 'năvodari',
        'Ia?i' => 'Iași', 'IA?I' => 'IAȘI', 'ia?i' => 'iași',
        'Timi?oara' => 'Timișoara', 'TIMI?OARA' => 'TIMIȘOARA', 'timi?oara' => 'timișoara',
        'Bra?ov' => 'Brașov', 'BRA?OV' => 'BRAȘOV', 'bra?ov' => 'brașov',
        'Gala?i' => 'Galați', 'GALA?I' => 'GALAȚI', 'gala?i' => 'galați',
        'Bistri?a' => 'Bistrița', 'BISTRI?A' => 'BISTRIȚA', 'bistri?a' => 'bistrița',
        'Dambovi?a' => 'Dâmbovița', 'DAMBOVI?A' => 'DÂMBOVIȚA', 'dambovi?a' => 'dâmbovița',
        'D?mbovi?a' => 'Dâmbovița', 'D?MBOVI?A' => 'DÂMBOVIȚA',
        'Ialomi?a' => 'Ialomița', 'IALOMI?A' => 'IALOMIȚA', 'ialomi?a' => 'ialomița',
        'Arge?' => 'Argeș', 'ARGE?' => 'ARGEȘ', 'arge?' => 'argeș',
        'Mure?' => 'Mureș', 'MURE?' => 'MUREȘ', 'mure?' => 'mureș',
        'Maramure?' => 'Maramureș', 'MARAMURE?' => 'MARAMUREȘ',
        'C?l?ra?i' => 'Călărași', 'C?L?RA?I' => 'CĂLĂRAȘI',
        'M?r??e?ti' => 'Mărășești', 'M?R??E?TI' => 'MĂRĂȘEȘTI',
        'M?r??ti' => 'Mărăști', 'M?R??TI' => 'MĂRĂȘTI',
        '?tefan' => 'Ștefan', '?TEFAN' => 'ȘTEFAN',
        '?iglinei' => 'Țiglinei', '?IGLINEI' => 'ȚIGLINEI',
        '?iglina' => 'Țiglina', '?IGLINA' => 'ȚIGLINA',
        'Vod?' => 'Vodă', 'VOD?' => 'VODĂ',
        'str?' => 'stră', 'Str?' => 'Stră',
        'f?r?' => 'fără', 'F?r?' => 'Fără',
    ];

    $value = strtr($value, $map);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function rd_text_columns(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext')
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");

    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function rd_primary_keys(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'PRIMARY'
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");

    $keys = [];
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $column = (string)($row['COLUMN_NAME'] ?? '');
        if ($table !== '' && $column !== '') {
            $keys[$table][] = $column;
        }
    }

    return $keys;
}

function rd_ensure_log(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS diacritics_repair_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(190) NOT NULL,
            column_name VARCHAR(190) NOT NULL,
            row_id VARCHAR(190) NOT NULL,
            old_value LONGTEXT NULL,
            new_value LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_repair_log_table (table_name, column_name),
            INDEX idx_repair_log_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function rd_scan(PDO $pdo, bool $apply = false): array
{
    rd_ensure_log($pdo);

    $columns = rd_text_columns($pdo);
    $primaryKeys = rd_primary_keys($pdo);
    $changes = [];
    $updated = 0;

    foreach ($columns as $columnInfo) {
        $table = (string)($columnInfo['TABLE_NAME'] ?? '');
        $column = (string)($columnInfo['COLUMN_NAME'] ?? '');
        $pkList = $primaryKeys[$table] ?? [];
        if ($table === '' || $column === '' || count($pkList) !== 1) {
            continue;
        }

        $pk = $pkList[0];
        $sql = 'SELECT ' . rd_ident($pk) . ' AS rd_pk, ' . rd_ident($column) . ' AS rd_value FROM ' . rd_ident($table)
            . ' WHERE ' . rd_ident($column) . " IS NOT NULL AND " . rd_ident($column) . " <> '' LIMIT 10000";

        try {
            $stmt = $pdo->query($sql);
        } catch (Throwable $e) {
            error_log('PestZone diacritics scan error: ' . $e->getMessage());
            continue;
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $old = (string)($row['rd_value'] ?? '');
            $new = rd_repair_text($old);
            if ($new === $old) {
                continue;
            }

            $pkValue = (string)($row['rd_pk'] ?? '');
            $changes[] = [
                'table' => $table,
                'column' => $column,
                'row_id' => $pkValue,
                'old' => $old,
                'new' => $new,
            ];

            if ($apply) {
                $log = $pdo->prepare("
                    INSERT INTO diacritics_repair_log (table_name, column_name, row_id, old_value, new_value)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $log->execute([$table, $column, $pkValue, $old, $new]);

                $update = $pdo->prepare('UPDATE ' . rd_ident($table) . ' SET ' . rd_ident($column) . ' = ? WHERE ' . rd_ident($pk) . ' = ?');
                $update->execute([$new, $pkValue]);
                $updated++;
            }
        }
    }

    return ['changes' => $changes, 'updated' => $updated];
}

$applied = false;
$result = ['changes' => [], 'updated' => 0];
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        $result = rd_scan($pdo, true);
        $applied = true;
    } else {
        $result = rd_scan($pdo, false);
    }
} catch (Throwable $e) {
    error_log('PestZone diacritics repair error: ' . $e->getMessage());
    $error = $e->getMessage();
}

$changes = $result['changes'] ?? [];
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reparare diacritice</title>
    <style>
        body { margin: 0; background: #f8fafc; color: #0f172a; font-family: Arial, sans-serif; }
        main { max-width: 1180px; margin: 0 auto; padding: 28px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: 24px; }
        h2 { margin: 0 0 12px; font-size: 17px; }
        p { color: #334155; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border-top: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; vertical-align: top; }
        th { color: #64748b; text-transform: uppercase; font-size: 11px; }
        .notice { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; }
        .ok { background: #dcfce7; color: #166534; }
        .bad { background: #fee2e2; color: #991b1b; }
        .muted { color: #64748b; }
        button, a.btn { display: inline-flex; align-items: center; min-height: 34px; border: 1px solid #2563eb; background: #2563eb; color: #fff; border-radius: 4px; padding: 0 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        a.btn { background: #fff; color: #2563eb; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .text-cell { max-width: 380px; word-break: break-word; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>Reparare diacritice</h1>
        <p>Pagina scanează textele vechi afectate de encoding. Repararea salvează înainte/după în <strong>diacritics_repair_log</strong>.</p>
        <div class="actions">
            <a class="btn" href="utf8_check.php">Verificare UTF-8</a>
            <a class="btn" href="settings.php">Înapoi</a>
            <?php if (!$applied && count($changes) > 0): ?>
                <form method="post" onsubmit="return confirm('Aplici reparațiile găsite? Backup-ul bazei trebuie să fie deja făcut.');">
                    <?= csrf_field() ?>
                    <button type="submit">Aplică reparațiile</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="notice bad"><?= rd_h($error) ?></div>
    <?php elseif ($applied): ?>
        <div class="notice ok">Reparații aplicate: <?= (int)($result['updated'] ?? 0) ?>.</div>
    <?php elseif (count($changes) === 0): ?>
        <div class="notice ok">Nu am găsit texte care necesită reparații.</div>
    <?php else: ?>
        <div class="notice bad">Am găsit <?= count($changes) ?> valori care pot fi reparate. Verifică exemplele înainte să aplici.</div>
    <?php endif; ?>

    <section class="card">
        <h2>Valori găsite</h2>
        <table>
            <thead>
            <tr>
                <th>Tabel</th>
                <th>Coloană</th>
                <th>ID</th>
                <th>Înainte</th>
                <th>După</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($changes, 0, 250) as $change): ?>
                <tr>
                    <td><?= rd_h($change['table']) ?></td>
                    <td><?= rd_h($change['column']) ?></td>
                    <td><?= rd_h($change['row_id']) ?></td>
                    <td class="text-cell"><?= rd_h($change['old']) ?></td>
                    <td class="text-cell"><?= rd_h($change['new']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($changes) === 0): ?>
                <tr><td colspan="5" class="muted">Nimic de afișat.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if (count($changes) > 250): ?>
            <p class="muted">Se afișează primele 250 valori din <?= count($changes) ?>.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
