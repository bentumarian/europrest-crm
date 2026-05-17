<?php
require_once __DIR__ . '/config.php';
require_login();

if (!is_admin()) {
    http_response_code(403);
    exit('Acces interzis.');
}

function utf8_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function utf8_tables(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT TABLE_NAME, TABLE_COLLATION
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ");

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('PestZone UTF-8 table check error: ' . $e->getMessage());
        return [];
    }
}

function utf8_connection(PDO $pdo): array
{
    $vars = [
        'character_set_client',
        'character_set_connection',
        'character_set_database',
        'character_set_results',
        'collation_connection',
        'collation_database',
    ];

    $result = [];
    foreach ($vars as $var) {
        try {
            $safeVar = preg_replace('/[^a-z0-9_]/i', '', $var);
            $stmt = $pdo->query("SHOW VARIABLES LIKE " . $pdo->quote($safeVar));
            $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
            $result[$var] = (string)($row['Value'] ?? '');
        } catch (Throwable $e) {
            error_log('PestZone UTF-8 variable check error: ' . $e->getMessage());
            $result[$var] = 'nu se poate verifica';
        }
    }

    return $result;
}

$success = '';
$error = '';
$converted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    try {
        $pdo->exec("ALTER DATABASE `" . str_replace('`', '``', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        foreach (utf8_tables($pdo) as $table) {
            $name = (string)($table['TABLE_NAME'] ?? '');
            if ($name === '') {
                continue;
            }

            $quoted = '`' . str_replace('`', '``', $name) . '`';
            $pdo->exec("ALTER TABLE {$quoted} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $converted[] = $name;
        }

        $success = 'Conversia UTF-8 a fost rulata.';
    } catch (Throwable $e) {
        error_log('PestZone UTF-8 conversion error: ' . $e->getMessage());
        $error = 'Conversia nu a putut fi finalizata: ' . $e->getMessage();
    }
}

$connection = utf8_connection($pdo);
$tables = utf8_tables($pdo);
$badTables = array_values(array_filter($tables, static function ($table): bool {
    return stripos((string)($table['TABLE_COLLATION'] ?? ''), 'utf8mb4') !== 0;
}));
$testText = 'Șoseaua București, Constanța, Iași, Țiglina, Mărășești';
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificare UTF-8</title>
    <style>
        body { margin: 0; background: #f8fafc; color: #0f172a; font-family: Arial, sans-serif; }
        main { max-width: 980px; margin: 0 auto; padding: 28px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: 24px; }
        h2 { margin: 0 0 12px; font-size: 17px; }
        p { color: #334155; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border-top: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; }
        th { color: #64748b; text-transform: uppercase; font-size: 11px; }
        .ok { color: #166534; font-weight: 700; }
        .bad { color: #b91c1c; font-weight: 700; }
        .notice { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; }
        .notice.ok { background: #dcfce7; color: #166534; }
        .notice.bad { background: #fee2e2; color: #991b1b; }
        button, a.btn { display: inline-flex; align-items: center; min-height: 34px; border: 1px solid #2563eb; background: #2563eb; color: #fff; border-radius: 4px; padding: 0 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        a.btn { background: #fff; color: #2563eb; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        code { background: #f1f5f9; padding: 2px 5px; border-radius: 4px; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>Verificare UTF-8</h1>
        <p>Text test: <strong><?= utf8_h($testText) ?></strong></p>
        <p>Dacă textul de mai sus se vede corect, pagina si browserul primesc UTF-8.</p>
        <div class="actions">
            <a class="btn" href="settings.php">Înapoi</a>
            <form method="post" onsubmit="return confirm('Rulezi conversia tuturor tabelelor la utf8mb4? Fa asta doar după backup.');">
                <?= csrf_field() ?>
                <button type="submit">Converteste baza la UTF-8</button>
            </form>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="notice ok"><?= utf8_h($success) ?> Tabele convertite: <?= count($converted) ?>.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="notice bad"><?= utf8_h($error) ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Conexiune baza de date</h2>
        <table>
            <tbody>
            <?php foreach ($connection as $key => $value): ?>
                <tr>
                    <th><?= utf8_h($key) ?></th>
                    <td class="<?= stripos($value, 'utf8mb4') === 0 ? 'ok' : '' ?>"><?= utf8_h($value) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Tabele</h2>
        <p><?= count($badTables) === 0 ? 'Toate tabelele verificate sunt pe utf8mb4.' : 'Există tabele care nu sunt pe utf8mb4.' ?></p>
        <table>
            <thead>
            <tr>
                <th>Tabela</th>
                <th>Collation</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tables as $table): ?>
                <?php
                $collation = (string)($table['TABLE_COLLATION'] ?? '');
                $ok = stripos($collation, 'utf8mb4') === 0;
                ?>
                <tr>
                    <td><?= utf8_h($table['TABLE_NAME'] ?? '') ?></td>
                    <td><code><?= utf8_h($collation) ?></code></td>
                    <td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'Necesita conversie' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
