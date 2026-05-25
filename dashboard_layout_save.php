<?php
/*
|--------------------------------------------------------------------------
| Dashboard Layout Save
|--------------------------------------------------------------------------
| Endpoint AJAX care primește ordinea cardurilor de pe dashboard
| (4 KPI-uri + 6 carduri mari) și o salvează per utilizator în tabela
| `user_dashboard_layout`. Tabela e bootstrap-ată idempotent în dashboard.php.
|
| Request: POST application/json
| Body: {
|   "kpis":     ["kpi-revenue", "kpi-invoices", "kpi-today", "kpi-due"],
|   "bigCards": ["card-revchart", "card-statusdonut", "card-todayappts",
|                "card-topclients", "card-tasks", "card-reminders"]
| }
|
| Response: {"ok": true} sau {"ok": false, "error": "..."}
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_login();

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

function dls_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Setul fix de ID-uri valide — orice altceva e respins. */
const DLS_KPI_IDS = ['kpi-revenue', 'kpi-invoices', 'kpi-today', 'kpi-due'];
const DLS_BIG_IDS = [
    'card-revchart', 'card-statusdonut',
    'card-todayappts', 'card-topclients',
    'card-tasks', 'card-reminders',
];

function dls_validate_order(array $input, array $allowed): ?array
{
    if (count($input) !== count($allowed)) return null;
    $seen = [];
    foreach ($input as $id) {
        if (!is_string($id) || !in_array($id, $allowed, true)) return null;
        if (isset($seen[$id])) return null;
        $seen[$id] = true;
    }
    return $input;
}

/* ------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dls_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$userId = function_exists('current_user_id') ? (int)current_user_id() : 0;
if ($userId <= 0) {
    dls_json(['ok' => false, 'error' => 'no_user'], 401);
}

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) {
    dls_json(['ok' => false, 'error' => 'invalid_json'], 400);
}

$kpis = dls_validate_order(($body['kpis'] ?? []), DLS_KPI_IDS);
$bigs = dls_validate_order(($body['bigCards'] ?? []), DLS_BIG_IDS);

if ($kpis === null || $bigs === null) {
    dls_json(['ok' => false, 'error' => 'invalid_layout'], 400);
}

$layout = ['kpis' => $kpis, 'bigCards' => $bigs];
$layoutJson = json_encode($layout, JSON_UNESCAPED_UNICODE);

try {
    /** @var PDO $pdo */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_dashboard_layout (
            user_id INT NOT NULL PRIMARY KEY,
            layout_json TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare("
        INSERT INTO user_dashboard_layout (user_id, layout_json)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE layout_json = VALUES(layout_json)
    ");
    $stmt->execute([$userId, $layoutJson]);

    dls_json(['ok' => true]);
} catch (Throwable $e) {
    error_log('dashboard_layout_save: ' . $e->getMessage());
    dls_json(['ok' => false, 'error' => 'db_error'], 500);
}
