<?php
/**
 * Export CSV pentru modulul Gestiune.
 * Folosește UTF-8 BOM + separator ";" ca Excel să deschidă fișierele corect.
 *
 * Tipuri suportate:
 *   ?type=stock_current     - stoc curent pe produs
 *   ?type=receipts          - intrări (cu filtrele actuale)
 *   ?type=movements         - mișcări (cu filtrele actuale)
 *   ?type=card              - fișa magazie interval
 *   ?type=registry          - registru evidență lucrări biocide
 */
require_once 'config.php';
require_login();
require_once 'stock_lib.php';

if (!is_admin()) { http_response_code(403); exit('Acces interzis'); }
stock_ensure_schema($pdo);

$type = (string)($_GET['type'] ?? '');

function stock_csv_send_headers(string $filename): void
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 - Excel deschide automat ca UTF-8
}

function stock_csv_row(array $cols): void
{
    $out = fopen('php://output', 'w');
    fputcsv($out, $cols, ';', '"', '\\');
    fclose($out);
}

function stock_csv_format_qty($value): string
{
    return str_replace('.', ',', stock_fmt_qty($value));
}

if ($type === 'stock_current') {
    $rows = stock_current_by_product($pdo);
    stock_csv_send_headers('stoc_curent_' . date('Y-m-d') . '.csv');
    stock_csv_row(['Produs', 'Grupa', 'UM', 'Stoc curent', 'Stoc minim', 'Status', 'Aviz', 'Valabilitate aviz']);
    foreach ($rows as $r) {
        $isLow = (float)$r['min_qty'] > 0 && (float)$r['current_qty'] <= (float)$r['min_qty'];
        stock_csv_row([
            (string)$r['name'],
            stock_group_label((string)$r['product_group']),
            (string)$r['unit_consumption'],
            stock_csv_format_qty($r['current_qty'] ?? 0),
            stock_csv_format_qty($r['min_qty'] ?? 0),
            $isLow ? 'Sub minim' : 'OK',
            (string)($r['aviz_no'] ?? ''),
            (string)($r['aviz_valid_until'] ?? ''),
        ]);
    }
    exit;
}

if ($type === 'receipts') {
    // Reaplic filtrele din GET pentru a păstra contextul utilizatorului
    $filterSearch = trim((string)($_GET['q'] ?? ''));
    $filterStatus = trim((string)($_GET['status'] ?? ''));
    $filterFrom = trim((string)($_GET['from'] ?? ''));
    $filterTo = trim((string)($_GET['to'] ?? ''));
    $filterProduct = (int)($_GET['product_id'] ?? 0);

    $wh = ['1=1']; $pa = [];
    if ($filterSearch !== '') {
        $wh[] = '(p.name LIKE ? OR r.document_no LIKE ? OR r.supplier LIKE ? OR r.lot LIKE ?)';
        $like = '%' . $filterSearch . '%';
        $pa[] = $like; $pa[] = $like; $pa[] = $like; $pa[] = $like;
    }
    if ($filterStatus === 'active') { $wh[] = 'r.cancelled_at IS NULL'; }
    elseif ($filterStatus === 'cancelled') { $wh[] = 'r.cancelled_at IS NOT NULL'; }
    if ($filterFrom !== '') { $wh[] = 'r.reception_date >= ?'; $pa[] = $filterFrom; }
    if ($filterTo !== '') { $wh[] = 'r.reception_date <= ?'; $pa[] = $filterTo; }
    if ($filterProduct > 0) { $wh[] = 'r.product_id = ?'; $pa[] = $filterProduct; }

    $sql = 'SELECT r.*, p.name AS product_name, p.product_group, p.unit_consumption FROM stock_receipts r INNER JOIN stock_products p ON p.id = r.product_id WHERE ' . implode(' AND ', $wh) . ' ORDER BY r.reception_date DESC, r.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($pa);
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    stock_csv_send_headers('intrari_stoc_' . date('Y-m-d') . '.csv');
    stock_csv_row(['Data receptie', 'Produs', 'Grupa', 'Document', 'Furnizor', 'Cantitate', 'UM', 'Lot', 'Expirare', 'Status', 'Anulat la', 'Motiv anulare', 'Observatii']);
    foreach ($receipts as $r) {
        stock_csv_row([
            (string)$r['reception_date'],
            (string)$r['product_name'],
            stock_group_label((string)$r['product_group']),
            (string)$r['document_no'],
            (string)($r['supplier'] ?? ''),
            stock_csv_format_qty($r['qty']),
            (string)$r['unit_consumption'],
            (string)($r['lot'] ?? ''),
            (string)($r['expires_at'] ?? ''),
            !empty($r['cancelled_at']) ? 'Anulata' : 'Activa',
            (string)($r['cancelled_at'] ?? ''),
            (string)($r['cancel_reason'] ?? ''),
            (string)($r['notes'] ?? ''),
        ]);
    }
    exit;
}

if ($type === 'movements') {
    $filterSearch = trim((string)($_GET['q'] ?? ''));
    $filterType = trim((string)($_GET['type_filter'] ?? $_GET['movement_type_filter'] ?? ''));
    // Note: am redenumit parametrul în URL pentru a evita coliziunea cu type=movements
    $filterProduct = (int)($_GET['product_id'] ?? 0);
    $filterFrom = trim((string)($_GET['from'] ?? ''));
    $filterTo = trim((string)($_GET['to'] ?? ''));

    $wh = ['1=1']; $pa = [];
    if ($filterSearch !== '') {
        $wh[] = '(p.name LIKE ? OR m.notes LIKE ? OR r.lot LIKE ?)';
        $like = '%' . $filterSearch . '%';
        $pa[] = $like; $pa[] = $like; $pa[] = $like;
    }
    if ($filterType !== '' && array_key_exists($filterType, stock_movement_labels())) {
        $wh[] = 'm.movement_type = ?'; $pa[] = $filterType;
    }
    if ($filterProduct > 0) { $wh[] = 'm.product_id = ?'; $pa[] = $filterProduct; }
    if ($filterFrom !== '') { $wh[] = 'DATE(m.created_at) >= ?'; $pa[] = $filterFrom; }
    if ($filterTo !== '') { $wh[] = 'DATE(m.created_at) <= ?'; $pa[] = $filterTo; }

    $sql = 'SELECT m.*, p.name AS product_name, p.unit_consumption, r.lot, r.expires_at FROM stock_movements m INNER JOIN stock_products p ON p.id=m.product_id LEFT JOIN stock_receipts r ON r.id=m.receipt_id WHERE ' . implode(' AND ', $wh) . ' ORDER BY m.created_at DESC, m.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($pa);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    stock_csv_send_headers('miscari_stoc_' . date('Y-m-d') . '.csv');
    stock_csv_row(['Data', 'Produs', 'Lot', 'Tip miscare', 'Cantitate', 'UM', 'Referinta', 'Observatii']);
    foreach ($rows as $r) {
        stock_csv_row([
            (string)$r['created_at'],
            (string)$r['product_name'],
            (string)($r['lot'] ?? ''),
            stock_movement_label((string)$r['movement_type']),
            stock_csv_format_qty($r['qty']),
            (string)$r['unit_consumption'],
            ($r['reference_type'] ?: '') . ($r['reference_id'] ? ' #' . $r['reference_id'] : ''),
            (string)($r['notes'] ?? ''),
        ]);
    }
    exit;
}

if ($type === 'card') {
    $dateFrom = stock_date_or_default($_GET['date_from'] ?? '', date('Y-m-01'));
    $dateTo = stock_date_or_default($_GET['date_to'] ?? '', date('Y-m-t'));
    $productId = (int)($_GET['product_id'] ?? 0);
    $group = trim((string)($_GET['group'] ?? ''));
    if ($group !== '' && !array_key_exists($group, stock_group_options())) { $group = ''; }
    $rows = stock_stock_summary_interval($pdo, $dateFrom, $dateTo, $productId, $group);

    stock_csv_send_headers('fisa_magazie_' . $dateFrom . '_' . $dateTo . '.csv');
    stock_csv_row(['Produs', 'Grupa', 'UM', 'Stoc initial', 'Intrari', 'Consum/iesiri', 'Stoc final', 'Stoc minim', 'Status']);
    foreach ($rows as $r) {
        $low = (float)$r['min_qty'] > 0 && (float)$r['final_qty'] <= (float)$r['min_qty'];
        stock_csv_row([
            (string)$r['name'],
            stock_group_label((string)$r['product_group']),
            (string)$r['unit_consumption'],
            stock_csv_format_qty($r['initial_qty']),
            stock_csv_format_qty($r['in_qty']),
            stock_csv_format_qty($r['out_qty']),
            stock_csv_format_qty($r['final_qty']),
            stock_csv_format_qty($r['min_qty'] ?? 0),
            $low ? 'Sub minim' : 'OK',
        ]);
    }
    exit;
}

if ($type === 'registry') {
    $dateFrom = stock_date_or_default($_GET['date_from'] ?? '', date('Y-m-01'));
    $dateTo = stock_date_or_default($_GET['date_to'] ?? '', date('Y-m-t'));
    $rows = stock_registry_rows($pdo, $dateFrom, $dateTo);

    stock_csv_send_headers('registru_lucrari_' . $dateFrom . '_' . $dateTo . '.csv');
    stock_csv_row(['Data', 'Beneficiar', 'Procedura', 'Produs biocid', 'Nr. aviz', 'Lot', 'Cantitate', 'UM', 'Concentratie', 'Nr. PV', 'Lucratori']);
    foreach ($rows as $r) {
        stock_csv_row([
            $r['procedure_date'] ? date('d.m.Y H:i', strtotime($r['procedure_date'])) : '',
            (string)($r['beneficiary_name'] ?? ''),
            stock_group_label((string)$r['procedure_type']),
            (string)$r['product_name'],
            (string)($r['aviz_no'] ?? ''),
            (string)($r['lot'] ?? ''),
            stock_csv_format_qty($r['qty']),
            (string)$r['unit_consumption'],
            (string)($r['work_concentration'] ?? ''),
            (string)($r['pv_no'] ?? ''),
            (string)($r['workers_names'] ?? ''),
        ]);
    }
    exit;
}

http_response_code(400);
header('Content-Type: text/plain; charset=utf-8');
echo "Tip export invalid. Folosește: ?type=stock_current|receipts|movements|card|registry\n";
