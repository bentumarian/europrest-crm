<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/stock_lib.php';
require_once __DIR__ . '/app_ui.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

function public_aviz_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function public_aviz_file_path(string $relative): ?string
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || str_contains($relative, '..')) {
        return null;
    }

    $full = realpath(__DIR__ . '/' . $relative);
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    if (!$full || !$uploadsRoot || !str_starts_with($full, $uploadsRoot) || !is_file($full)) {
        return null;
    }

    return $full;
}

function public_aviz_download(PDO $pdo, int $productId): void
{
    $stmt = $pdo->prepare("
        SELECT id, name, product_group, aviz_file
        FROM stock_products
        WHERE id = ?
          AND is_active = 1
          AND aviz_file IS NOT NULL
          AND TRIM(aviz_file) <> ''
        LIMIT 1
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product || !stock_is_biocide_group((string)($product['product_group'] ?? 'dezinsectie'))) {
        http_response_code(404);
        echo 'Fișierul nu a fost găsit.';
        exit;
    }

    $path = public_aviz_file_path((string)$product['aviz_file']);
    if (!$path) {
        http_response_code(404);
        echo 'Fișierul nu a fost găsit.';
        exit;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ][$ext] ?? 'application/octet-stream';
    $downloadName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string)$product['name']) ?: 'aviz-sanitar';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $downloadName . '.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if (isset($_GET['download'])) {
    public_aviz_download($pdo, max(0, (int)$_GET['download']));
}

$q = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = "
    is_active = 1
    AND aviz_file IS NOT NULL
    AND TRIM(aviz_file) <> ''
    AND product_group IN ('dezinsectie', 'dezinfectie', 'deratizare')
";
if ($q !== '') {
    $where .= " AND (name LIKE ? OR aviz_no LIKE ? OR active_substance LIKE ?)";
    $needle = '%' . $q . '%';
    $params = [$needle, $needle, $needle];
}

$stmt = $pdo->prepare("
    SELECT id, name, product_group, aviz_no, aviz_valid_until, active_substance, aviz_file
    FROM stock_products
    WHERE $where
    ORDER BY name ASC
");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Avize sanitare produse - PestZone</title>
<style>
    :root { color-scheme: light; --accent:#1160b7; --text:#102033; --muted:#64748b; --border:#dbe4ef; --soft:#f6f9fc; }
    * { box-sizing:border-box; }
    body { margin:0; font-family:Arial, Helvetica, sans-serif; background:#f8fafc; color:var(--text); }
    .hero { background:#fff; border-bottom:1px solid var(--border); }
    .wrap { max-width:1040px; margin:0 auto; padding:24px 18px; }
    h1 { margin:0; font-size:28px; letter-spacing:0; }
    p { margin:7px 0 0; color:var(--muted); line-height:1.45; }
    .search { display:flex; gap:10px; margin-top:18px; }
    .search input { flex:1; border:1px solid var(--border); border-radius:8px; padding:12px 13px; font-size:15px; background:#fff; }
    .btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border-radius:8px; border:1px solid var(--accent); background:var(--accent); color:#fff; text-decoration:none; font-weight:800; cursor:pointer; }
    .btn.secondary { background:#fff; color:var(--accent); }
    .list { display:grid; gap:10px; margin-top:18px; }
    .row { display:grid; grid-template-columns:minmax(220px,1fr) minmax(120px,.35fr) minmax(130px,.35fr) auto; gap:12px; align-items:center; background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px; }
    .name { font-weight:900; }
    .meta { color:var(--muted); font-size:13px; margin-top:3px; }
    .tag { display:inline-flex; border:1px solid var(--border); background:var(--soft); border-radius:999px; padding:6px 9px; font-size:12px; font-weight:800; color:#334155; }
    .empty { background:#fff; border:1px dashed var(--border); border-radius:8px; padding:24px; text-align:center; color:var(--muted); font-weight:700; }
    @media (max-width:720px) { .row { grid-template-columns:1fr; } .search { flex-direction:column; } h1 { font-size:23px; } }
</style>
<?php render_search_preview_assets(); ?>
</head>
<body>
<header class="hero">
    <div class="wrap">
        <h1>Avize sanitare produse</h1>
        <p>Descărcați avizele sanitare pentru produsele biocide utilizate în lucrările DDD.</p>
        <form class="search" method="get">
            <div class="pz-search-wrap" style="flex:1; min-width:240px;">
                <input type="search" id="avizeSearchInput" name="q" value="<?= public_aviz_h($q) ?>" placeholder="Caută produs, număr aviz sau substanță activă" autocomplete="off">
                <div class="pz-search-preview"></div>
            </div>
            <button class="btn" type="submit">Caută</button>
            <?php if ($q !== ''): ?><a class="btn secondary" href="avize_sanitare.php">Resetează</a><?php endif; ?>
        </form>
    </div>
</header>
<main class="wrap">
    <div class="list">
        <?php foreach ($products as $product): ?>
            <article class="row">
                <div>
                    <div class="name"><?= public_aviz_h($product['name'] ?? '') ?></div>
                    <div class="meta">
                        <?= public_aviz_h(stock_group_label((string)($product['product_group'] ?? ''))) ?>
                        <?php if (!empty($product['active_substance'])): ?> · <?= public_aviz_h($product['active_substance']) ?><?php endif; ?>
                    </div>
                </div>
                <div><span class="tag"><?= public_aviz_h($product['aviz_no'] ?: 'Aviz') ?></span></div>
                <div class="meta">Valabilitate: <?= public_aviz_h($product['aviz_valid_until'] ?: '-') ?></div>
                <div><a class="btn" href="avize_sanitare.php?download=<?= (int)$product['id'] ?>">Descarcă</a></div>
            </article>
        <?php endforeach; ?>
        <?php if (!$products): ?>
            <div class="empty">Nu există avize sanitare publicate momentan.</div>
        <?php endif; ?>
    </div>
</main>

<?php
// Preview live pentru bara „Caută produs" din pagina publică Avize.
$previewAvizeList = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'stock_products'")->fetch()) {
        $stmtPrev = $pdo->query("
            SELECT id, name, aviz_no, active_substance
            FROM stock_products
            WHERE aviz_no IS NOT NULL AND aviz_no <> ''
            ORDER BY name ASC LIMIT 2000
        ");
        while ($r = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
            $nm  = html_entity_decode((string)($r['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $av  = (string)($r['aviz_no'] ?? '');
            $as  = html_entity_decode((string)($r['active_substance'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $previewAvizeList[] = [
                'title'  => $nm,
                'url'    => 'avize_sanitare.php?download=' . (int)$r['id'],
                'type'   => 'document',
                'search' => $nm . ' ' . $av . ' ' . $as,
            ];
        }
    }
} catch (Throwable $e) { error_log('avize_sanitare.php preview: ' . $e->getMessage()); }
?>
<script>
(function () {
    var go = function () {
        if (!window.pzSearchPreview) { setTimeout(go, 30); return; }
        window.pzSearchPreview.attach('avizeSearchInput',
            <?= json_encode($previewAvizeList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            { minChars: 1, maxResults: 8 }
        );
    };
    go();
})();
</script>
