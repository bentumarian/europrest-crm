<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';

if (!is_admin()) {
    header('Location: calendar.php');
    exit;
}
function di_xlsx_col_letter(int $index): string
{
    $index++;
    $letters = '';

    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = (int)(($index - $mod) / 26);
    }

    return $letters;
}

function di_xlsx_xml($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function di_download_import_template(): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'Extensia PHP ZipArchive nu este activa pe server.';
        exit;
    }

    $headers = [
        '# ID',
        'Companie',
        'CUI/Serie și nr CI',
        'Nr. reg. com./CNP',
        'Telefon contact',
        'E-mail contact',
        'Județ livrare',
        'Oraș livrare',
        'Județ facturare',
        'Oraș facturare',
        'Stradă facturare',
        'Stradă livrare',
        'Nume contact',
        'Funcție contact',
        'Responsabili client',
    ];

    $rows = [
        [
            '1',
            'CLIENT EXEMPLU S.R.L.',
            'RO12345678',
            'J13/123/2024',
            '0722123456',
            'office@client-exemplu.ro',
            '',
            '',
            'Constanta',
            'Constanta',
            'Str. Exemplu nr. 10',
            '',
            'Popescu Ion',
            'Administrator',
            'Marian',
        ],
        [
            '2',
            'CLIENT CU PUNCT DE LUCRU S.R.L.',
            'RO87654321',
            'J40/999/2023',
            '0733123456',
            'contact@client.ro',
            'Ilfov',
            'Voluntari',
            'Bucuresti',
            'Bucuresti',
            'Str. Sediu Social nr. 1',
            'Bld. Pipera nr. 26',
            'Ionescu Maria',
            'Manager',
            'Birou Bucuresti',
        ],
        [
            '3',
            'PERSOANA FIZICA EXEMPLU',
            'CT123456',
            '',
            '0744123456',
            '',
            '',
            '',
            'Constanta',
            'Constanta',
            'Str. Client PF nr. 5',
            '',
            'Georgescu Andrei',
            '',
            '',
        ],
    ];

    $allRows = array_merge([$headers], $rows);

    $sheetData = '';

    foreach ($allRows as $r => $row) {
        $rowNumber = $r + 1;
        $sheetData .= '<row r="' . $rowNumber . '">';

        foreach ($row as $c => $value) {
            $cell = di_xlsx_col_letter($c) . $rowNumber;
            $sheetData .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . di_xlsx_xml($value) . '</t></is></c>';
        }

        $sheetData .= '</row>';
    }

    $colsXml = '';
    for ($i = 1; $i <= count($headers); $i++) {
        $width = in_array($i, [2, 11, 12, 13, 15], true) ? 28 : 18;
        $colsXml .= '<col min="' . $i . '" max="' . $i . '" width="' . $width . '" customWidth="1"/>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <cols>' . $colsXml . '</cols>
    <sheetData>' . $sheetData . '</sheetData>
</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Import clienti" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>
    <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
    <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>
    <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

    $tmp = tempnam(sys_get_temp_dir(), 'pz_import_template_');

    if ($tmp === false) {
        http_response_code(500);
        echo 'Nu pot crea fisier temporar.';
        exit;
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo 'Nu pot genera sablonul Excel.';
        exit;
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $relsXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->close();

    $filename = 'sablon_import_clienti_pestzone.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    readfile($tmp);
    @unlink($tmp);
    exit;
}

if (isset($_GET['download_template']) && (string)$_GET['download_template'] === '1') {
    di_download_import_template();
}


function di_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function di_csrf_field(): string
{
    return function_exists('csrf_field') ? csrf_field() : '';
}

function di_csrf_require(): void
{
    if (function_exists('csrf_require')) {
        csrf_require();
    }
}

function di_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function di_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function di_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];

        foreach ($rows as $row) {
            $cols[$row['Field']] = $row;
        }

        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function di_upload_dir(): string
{
    $dir = __DIR__ . '/tmp/imports';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function di_norm_header(string $s): string
{
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');

    $from = ['ă','â','î','ș','ş','ț','ţ'];
    $to   = ['a','a','i','s','s','t','t'];
    $s = str_replace($from, $to, $s);

    $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
    $s = trim($s, '_');

    return $s;
}

function di_norm_key($v): string
{
    $v = trim((string)$v);
    $v = mb_strtoupper($v, 'UTF-8');
    $v = str_replace([' ', '-', '.', '/', '\\', "\t", "\n", "\r"], '', $v);

    return $v;
}

function di_norm_fiscal($v): string
{
    $v = di_norm_key($v);
    $v = preg_replace('/[^A-Z0-9]/', '', $v);

    return $v;
}

function di_fiscal_variants(string $fiscal): array
{
    $n = di_norm_fiscal($fiscal);

    if ($n === '') {
        return [];
    }

    $noRo = preg_replace('/^RO/i', '', $n);
    $vars = [$n, $noRo, 'RO' . $noRo];

    return array_values(array_unique(array_filter($vars)));
}

function di_clean_phone($phone): string
{
    $phone = trim((string)$phone);

    if ($phone === '') {
        return '';
    }

    $phone = str_replace([' ', '-', '.', '(', ')'], '', $phone);

    if ($phone === '+40' || $phone === '40' || $phone === '+4' || $phone === '0040') {
        return '';
    }

    return $phone;
}

function di_clean_email($email): string
{
    $email = trim((string)$email);

    if ($email === '') {
        return '';
    }

    $email = mb_strtolower($email, 'UTF-8');

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function di_join_address($street, $city, $county): string
{
    $parts = [];

    foreach ([$street, $city, $county] as $part) {
        $part = trim((string)$part);

        if ($part !== '') {
            $parts[] = $part;
        }
    }

    return implode(', ', $parts);
}

function di_value(array $row, array $mapping, string $field): string
{
    $idx = $mapping[$field] ?? '';

    if ($idx === '' || !isset($row[(int)$idx])) {
        return '';
    }

    return trim((string)$row[(int)$idx]);
}

function di_read_zip_entry(ZipArchive $zip, string $name): string
{
    $data = $zip->getFromName($name);

    return $data === false ? '' : $data;
}

function di_col_letters(string $cellRef): string
{
    if (preg_match('/^([A-Z]+)/i', $cellRef, $m)) {
        return strtoupper($m[1]);
    }

    return '';
}

function di_col_index(string $letters): int
{
    $letters = strtoupper($letters);
    $num = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }

    return max(0, $num - 1);
}

function di_read_xlsx(string $file): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extensia PHP ZipArchive nu este activa pe server.');
    }

    $zip = new ZipArchive();

    if ($zip->open($file) !== true) {
        throw new RuntimeException('Fisierul Excel nu poate fi deschis.');
    }

    $sharedStrings = [];
    $sharedXml = di_read_zip_entry($zip, 'xl/sharedStrings.xml');

    if ($sharedXml !== '') {
        $sx = simplexml_load_string($sharedXml);

        if ($sx) {
            foreach ($sx->si as $si) {
                $text = '';

                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                }

                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = di_read_zip_entry($zip, 'xl/worksheets/sheet1.xml');

    if ($sheetXml === '') {
        throw new RuntimeException('Nu gasesc primul sheet in Excel.');
    }

    $sheet = simplexml_load_string($sheetXml);

    if (!$sheet) {
        throw new RuntimeException('Primul sheet nu poate fi citit.');
    }

    $allRows = [];

    foreach ($sheet->sheetData->row as $rowNode) {
        $row = [];
        $maxIndex = -1;

        foreach ($rowNode->c as $c) {
            $ref = (string)$c['r'];
            $type = (string)$c['t'];
            $col = di_col_index(di_col_letters($ref));
            $value = '';

            if ($type === 's') {
                $idx = (int)$c->v;
                $value = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                if (isset($c->is->t)) {
                    $value = (string)$c->is->t;
                }
            } else {
                $value = isset($c->v) ? (string)$c->v : '';
            }

            $row[$col] = trim($value);
            $maxIndex = max($maxIndex, $col);
        }

        if ($maxIndex >= 0) {
            for ($i = 0; $i <= $maxIndex; $i++) {
                if (!isset($row[$i])) {
                    $row[$i] = '';
                }
            }

            ksort($row);
            $row = array_values($row);

            $hasValue = false;
            foreach ($row as $cell) {
                if (trim((string)$cell) !== '') {
                    $hasValue = true;
                    break;
                }
            }

            if ($hasValue) {
                $allRows[] = $row;
            }
        }
    }

    $zip->close();

    if (!$allRows) {
        throw new RuntimeException('Excelul nu contine randuri.');
    }

    /*
    Detectam automat randul de antet.
    Unele exporturi Excel au un rand gol/tehnic inaintea antetului real.
    */
    $knownHeaders = [
        'id',
        'companie',
        'cui_serie_si_nr_ci',
        'cui',
        'nr_reg_com_cnp',
        'telefon_contact',
        'e_mail_contact',
        'email_contact',
        'judet_livrare',
        'oras_livrare',
        'judet_facturare',
        'oras_facturare',
        'strada_facturare',
        'strada_livrare',
        'nume_contact',
        'functie_contact',
        'responsabili_client',
    ];

    $headerIndex = 0;
    $bestScore = 0;
    $maxScan = min(15, count($allRows));

    for ($r = 0; $r < $maxScan; $r++) {
        $score = 0;

        foreach ($allRows[$r] as $cell) {
            $norm = di_norm_header((string)$cell);

            if (in_array($norm, $knownHeaders, true)) {
                $score++;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $headerIndex = $r;
        }
    }

    if ($bestScore < 3) {
        $headerIndex = 0;
    }

    $headers = $allRows[$headerIndex];
    $rows = array_slice($allRows, $headerIndex + 1);

    /*
    Egalizam numarul de coloane, ca selecturile si maparea sa ramana stabile.
    */
    $headerCount = count($headers);

    foreach ($rows as &$row) {
        for ($i = 0; $i < $headerCount; $i++) {
            if (!isset($row[$i])) {
                $row[$i] = '';
            }
        }

        $row = array_slice($row, 0, $headerCount);
    }
    unset($row);

    return [
        'headers' => $headers,
        'rows' => $rows,
    ];
}



function di_default_mapping(array $headers): array
{
    $map = [];

    $targets = [
        'old_id' => ['id', 'old_id'],
        'name' => ['companie', 'firma', 'client', 'denumire'],
        'fiscal_code' => ['cui_serie_si_nr_ci', 'cui_serie_nr_ci', 'cui', 'serie_si_nr_ci'],
        'registry_number' => ['nr_reg_com_cnp', 'nr_reg_com', 'cnp'],
        'phone' => ['telefon_contact', 'telefon'],
        'email' => ['e_mail_contact', 'email_contact', 'email', 'mail'],
        'billing_county' => ['judet_facturare'],
        'billing_city' => ['oras_facturare'],
        'billing_street' => ['strada_facturare'],
        'delivery_county' => ['judet_livrare'],
        'delivery_city' => ['oras_livrare'],
        'delivery_street' => ['strada_livrare'],
        'contact_person' => ['nume_contact', 'contact'],
        'contact_role' => ['functie_contact', 'functie'],
        'responsible' => ['responsabili_client', 'responsabil'],
    ];

    foreach ($headers as $i => $header) {
        $norm = di_norm_header((string)$header);

        foreach ($targets as $field => $names) {
            if (isset($map[$field])) {
                continue;
            }

            if (in_array($norm, $names, true)) {
                $map[$field] = (string)$i;
            }
        }
    }

    return $map;
}

function di_map_client_data(array $row, array $mapping): array
{
    $billingAddress = di_join_address(
        di_value($row, $mapping, 'billing_street'),
        di_value($row, $mapping, 'billing_city'),
        di_value($row, $mapping, 'billing_county')
    );

    $deliveryAddress = di_join_address(
        di_value($row, $mapping, 'delivery_street'),
        di_value($row, $mapping, 'delivery_city'),
        di_value($row, $mapping, 'delivery_county')
    );

    $oldId = di_value($row, $mapping, 'old_id');
    $responsible = di_value($row, $mapping, 'responsible');

    $notes = [];

    if ($oldId !== '') {
        $notes[] = 'ID soft vechi: ' . $oldId;
    }

    if ($responsible !== '') {
        $notes[] = 'Responsabili client: ' . $responsible;
    }

    return [
        'old_id' => $oldId,
        'name' => di_value($row, $mapping, 'name'),
        'fiscal_code' => di_value($row, $mapping, 'fiscal_code'),
        'registry_number' => di_value($row, $mapping, 'registry_number'),
        'phone' => di_clean_phone(di_value($row, $mapping, 'phone')),
        'email' => di_clean_email(di_value($row, $mapping, 'email')),
        'registered_address' => $billingAddress,
        'city' => di_value($row, $mapping, 'billing_city'),
        'contact_person' => di_value($row, $mapping, 'contact_person'),
        'legal_representative_name' => di_value($row, $mapping, 'contact_person'),
        'legal_representative_role' => di_value($row, $mapping, 'contact_role'),
        'notes' => implode("\n", $notes),
        'delivery_address' => $deliveryAddress,
        'delivery_city' => di_value($row, $mapping, 'delivery_city'),
        'delivery_county' => di_value($row, $mapping, 'delivery_county'),
    ];
}

function di_find_existing_client(PDO $pdo, array $data): ?array
{
    $cols = di_columns($pdo, 'clients');

    if (!$cols) {
        return null;
    }

    if (!empty($cols['fiscal_code']) && trim($data['fiscal_code']) !== '') {
        $variants = di_fiscal_variants($data['fiscal_code']);

        if ($variants) {
            $placeholders = implode(',', array_fill(0, count($variants), '?'));
            $stmt = $pdo->prepare("
                SELECT *
                FROM clients
                WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(fiscal_code, ' ', ''), '-', ''), '.', ''), '/', '')) IN ($placeholders)
                LIMIT 1
            ");
            $stmt->execute($variants);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($found) {
                return $found;
            }
        }
    }

    if (!empty($cols['name']) && trim($data['name']) !== '') {
        $stmt = $pdo->prepare("
            SELECT *
            FROM clients
            WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
            LIMIT 1
        ");
        $stmt->execute([$data['name']]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($found) {
            return $found;
        }
    }

    return null;
}

function di_client_insert(PDO $pdo, array $data): int
{
    $cols = di_columns($pdo, 'clients');

    $candidate = [
        'client_type' => 'PJ',
        'name' => $data['name'],
        'fiscal_code' => $data['fiscal_code'],
        'registry_number' => $data['registry_number'],
        'registered_address' => $data['registered_address'],
        'address' => $data['registered_address'],
        'city' => $data['city'],
        'phone' => $data['phone'],
        'email' => $data['email'],
        'contact_person' => $data['contact_person'],
        'legal_representative_name' => $data['legal_representative_name'],
        'legal_representative_role' => $data['legal_representative_role'],
        'notes' => $data['notes'],
        'active' => 1,
    ];

    $insert = [];

    foreach ($candidate as $col => $val) {
        if (isset($cols[$col])) {
            $insert[$col] = $val;
        }
    }

    if (!isset($insert['name'])) {
        throw new RuntimeException('Tabelul clients nu are coloana name.');
    }

    $names = array_keys($insert);
    $placeholders = implode(',', array_fill(0, count($names), '?'));

    $sql = "INSERT INTO clients (`" . implode('`,`', $names) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($insert));

    return (int)$pdo->lastInsertId();
}

function di_client_update_missing(PDO $pdo, int $clientId, array $existing, array $data): bool
{
    $cols = di_columns($pdo, 'clients');
    $candidate = [
        'fiscal_code' => $data['fiscal_code'],
        'registry_number' => $data['registry_number'],
        'registered_address' => $data['registered_address'],
        'address' => $data['registered_address'],
        'city' => $data['city'],
        'phone' => $data['phone'],
        'email' => $data['email'],
        'contact_person' => $data['contact_person'],
        'legal_representative_name' => $data['legal_representative_name'],
        'legal_representative_role' => $data['legal_representative_role'],
    ];

    $set = [];
    $params = [];

    foreach ($candidate as $col => $val) {
        if (!isset($cols[$col])) {
            continue;
        }

        $current = trim((string)($existing[$col] ?? ''));

        if ($current === '' && trim((string)$val) !== '') {
            $set[] = "`$col` = ?";
            $params[] = $val;
        }
    }

    if (isset($cols['notes']) && trim((string)$data['notes']) !== '') {
        $currentNotes = trim((string)($existing['notes'] ?? ''));

        if ($currentNotes === '') {
            $set[] = "`notes` = ?";
            $params[] = $data['notes'];
        } elseif (strpos($currentNotes, (string)$data['old_id']) === false) {
            $set[] = "`notes` = ?";
            $params[] = $currentNotes . "\n" . $data['notes'];
        }
    }

    if (!$set) {
        return false;
    }

    $params[] = $clientId;
    $stmt = $pdo->prepare("UPDATE clients SET " . implode(', ', $set) . " WHERE id = ?");
    $stmt->execute($params);

    return true;
}

function di_same_address(string $a, string $b): bool
{
    $a = di_norm_key($a);
    $b = di_norm_key($b);

    return $a !== '' && $b !== '' && $a === $b;
}

function di_location_exists(PDO $pdo, int $clientId, string $address): bool
{
    if (!di_table_exists($pdo, 'client_locations') || !di_column_exists($pdo, 'client_locations', 'address')) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT id, address FROM client_locations WHERE client_id = ?");
    $stmt->execute([$clientId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (di_same_address((string)$row['address'], $address)) {
            return true;
        }
    }

    return false;
}

function di_location_insert(PDO $pdo, int $clientId, array $data): bool
{
    if (!di_table_exists($pdo, 'client_locations')) {
        return false;
    }

    $address = trim((string)$data['delivery_address']);

    if ($address === '') {
        return false;
    }

    if (di_same_address($address, (string)$data['registered_address'])) {
        return false;
    }

    if (di_location_exists($pdo, $clientId, $address)) {
        return false;
    }

    $cols = di_columns($pdo, 'client_locations');

    $candidate = [
        'client_id' => $clientId,
        'location_name' => 'Punct de lucru',
        'name' => 'Punct de lucru',
        'address' => $address,
        'contact_person' => $data['contact_person'],
        'phone' => $data['phone'],
        'email' => $data['email'],
        'notes' => 'Import Excel: adresa de livrare din softul vechi.',
        'active' => 1,
    ];

    $insert = [];

    foreach ($candidate as $col => $val) {
        if (isset($cols[$col])) {
            $insert[$col] = $val;
        }
    }

    if (!isset($insert['client_id']) || !isset($insert['address'])) {
        return false;
    }

    $names = array_keys($insert);
    $placeholders = implode(',', array_fill(0, count($names), '?'));

    $sql = "INSERT INTO client_locations (`" . implode('`,`', $names) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($insert));

    return true;
}

function di_save_uploaded_file(): string
{
    if (empty($_FILES['import_file']['tmp_name'])) {
        throw new RuntimeException('Incarca un fisier Excel.');
    }

    $name = (string)($_FILES['import_file']['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($ext !== 'xlsx') {
        throw new RuntimeException('Momentan importul accepta doar fisiere .xlsx.');
    }

    $token = bin2hex(random_bytes(12));
    $path = di_upload_dir() . '/' . $token . '.xlsx';

    if (!move_uploaded_file($_FILES['import_file']['tmp_name'], $path)) {
        throw new RuntimeException('Fisierul nu a putut fi salvat.');
    }

    return $token;
}

function di_file_from_token(string $token): string
{
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($token));
    $path = di_upload_dir() . '/' . $token . '.xlsx';

    if ($token === '' || !is_file($path)) {
        throw new RuntimeException('Fisierul de import nu mai exista. Incarca din nou Excelul.');
    }

    return $path;
}

function di_preview_rows(PDO $pdo, array $rows, array $mapping, int $limit = 30): array
{
    $out = [];
    $i = 0;

    foreach ($rows as $row) {
        $data = di_map_client_data($row, $mapping);

        if (trim($data['name']) === '') {
            continue;
        }

        $existing = di_find_existing_client($pdo, $data);

        $out[] = [
            'data' => $data,
            'status' => $existing ? 'update' : 'new',
            'existing_id' => $existing['id'] ?? '',
            'will_location' => trim($data['delivery_address']) !== '' && !di_same_address($data['delivery_address'], $data['registered_address']),
        ];

        $i++;

        if ($i >= $limit) {
            break;
        }
    }

    return $out;
}

function di_import_rows(PDO $pdo, array $rows, array $mapping): array
{
    $stats = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'locations_created' => 0,
        'errors' => [],
    ];

    $pdo->beginTransaction();

    try {
        foreach ($rows as $line => $row) {
            $data = di_map_client_data($row, $mapping);

            if (trim($data['name']) === '') {
                $stats['skipped']++;
                continue;
            }

            $existing = di_find_existing_client($pdo, $data);

            if ($existing) {
                $clientId = (int)$existing['id'];

                if (di_client_update_missing($pdo, $clientId, $existing, $data)) {
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } else {
                $clientId = di_client_insert($pdo, $data);
                $stats['created']++;
            }

            if (di_location_insert($pdo, $clientId, $data)) {
                $stats['locations_created']++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $stats;
}

$action = (string)($_POST['action'] ?? '');
$error = '';
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$headers = [];
$rows = [];
$mapping = [];
$preview = [];
$result = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        di_csrf_require();

        if ($action === 'upload') {
            $token = di_save_uploaded_file();
        }

        if (in_array($action, ['upload', 'preview', 'import'], true)) {
            $file = di_file_from_token($token);
            $data = di_read_xlsx($file);
            $headers = $data['headers'];
            $rows = $data['rows'];

            if ($action === 'upload') {
                $mapping = di_default_mapping($headers);
            } else {
                $mapping = is_array($_POST['mapping'] ?? null) ? $_POST['mapping'] : [];
            }

            if ($action === 'preview') {
                $preview = di_preview_rows($pdo, $rows, $mapping, count($rows));
            }

            if ($action === 'import') {
                $result = di_import_rows($pdo, $rows, $mapping);
                $preview = [];
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$fields = [
    'old_id' => 'ID vechi',
    'name' => 'Denumire client',
    'fiscal_code' => 'CUI / CI',
    'registry_number' => 'Reg. Com. / CNP',
    'phone' => 'Telefon',
    'email' => 'Email',
    'billing_county' => 'Judet facturare',
    'billing_city' => 'Oras facturare',
    'billing_street' => 'Strada facturare',
    'delivery_county' => 'Judet livrare',
    'delivery_city' => 'Oras livrare',
    'delivery_street' => 'Strada livrare',
    'contact_person' => 'Nume contact',
    'contact_role' => 'Functie contact',
    'responsible' => 'Responsabili client',
];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Import date - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
.import-page { display:grid; gap:14px; }
.card { background:var(--surface); border:1px solid var(--border); border-radius:18px; box-shadow:var(--shadow); overflow:hidden; }
.card-head { padding:14px 16px; border-bottom:1px solid var(--border2); display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap; }
.card-head h2 { margin:0; font-size:16px; font-weight:650; }
.card-body { padding:16px; display:grid; gap:14px; }
.hero { background:var(--surface); border:1px solid var(--border); border-radius:18px; box-shadow:var(--shadow); padding:18px 20px; }
.hero h1 { margin:0 0 5px; font-size:25px; font-weight:650; letter-spacing:-.035em; }
.hero p { margin:0; color:var(--muted); }
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
label { display:block; margin-bottom:5px; color:var(--muted); font-size:11px; font-weight:650; text-transform:uppercase; letter-spacing:.04em; }
input, select { width:100%; border:1px solid var(--border); border-radius:12px; padding:10px 11px; min-height:42px; background:#fff; color:var(--text); font:inherit; outline:none; }
.table-wrap { overflow:auto; }
table { width:100%; border-collapse:collapse; min-width:900px; }
th, td { border-bottom:1px solid var(--border2); padding:9px; vertical-align:top; font-size:13px; }
th { background:var(--surface-soft); color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.04em; text-align:left; }
.badge { display:inline-flex; padding:4px 8px; border-radius:999px; font-size:11px; font-weight:700; border:1px solid var(--border); }
.badge.new { color:#0f766e; background:#ecfdf5; border-color:#bbf7d0; }
.badge.update { color:#92400e; background:#fffbeb; border-color:#fde68a; }
.notice { margin:0; }
.small-muted { color:var(--muted); font-size:13px; }
@media(max-width:800px){ .grid-2{ grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('data_import', true); ?>

    <main class="main">
        <div class="topbar">
            <strong>Import date</strong>
        </div>

        <div class="content import-page">
            <section class="hero">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                    <div>
                        <h1>Import date</h1>
                        <p>Import sigur pentru clienti si puncte de lucru din Excel. Datele sunt previzualizate inainte de import.</p>
                    </div>
                    <a class="btn" href="data_import.php?download_template=1">Descarca sablon Excel</a>
                </div>
            </section>

            <?php if ($error): ?>
                <div class="notice notice-danger"><?= di_h($error) ?></div>
            <?php endif; ?>

            <?php if ($result): ?>
                <div class="card">
                    <div class="card-head"><h2>Raport import</h2></div>
                    <div class="card-body">
                        <div class="grid-2">
                            <div><strong>Clienti creati:</strong> <?= (int)$result['created'] ?></div>
                            <div><strong>Clienti actualizati:</strong> <?= (int)$result['updated'] ?></div>
                            <div><strong>Randuri sarite:</strong> <?= (int)$result['skipped'] ?></div>
                            <div><strong>Locatii create:</strong> <?= (int)$result['locations_created'] ?></div>
                        </div>
                        <a class="btn accent" href="clients.php">Mergi la Contacte</a>
                    </div>
                </div>
            <?php endif; ?>

            <section class="card">
                <div class="card-head">
                    <h2>1. Incarca Excel</h2>
                </div>
                <form method="post" enctype="multipart/form-data" class="card-body">
                    <?= di_csrf_field() ?>
                    <input type="hidden" name="action" value="upload">
                    <div>
                        <label>Fisier Excel .xlsx</label>
                        <input type="file" name="import_file" accept=".xlsx" required>
                    </div>
                    <button class="btn accent" type="submit">Incarca si citeste coloanele</button>
                </form>
            </section>

            <?php if ($token && $headers): ?>
                <section class="card">
                    <div class="card-head">
                        <h2>2. Mapare coloane</h2>
                        <span class="small-muted"><?= count($rows) ?> randuri gasite</span>
                    </div>

                    <form method="post" class="card-body">
                        <?= di_csrf_field() ?>
                        <input type="hidden" name="token" value="<?= di_h($token) ?>">

                        <div class="grid-2">
                            <?php foreach ($fields as $field => $label): ?>
                                <div>
                                    <label><?= di_h($label) ?></label>
                                    <select name="mapping[<?= di_h($field) ?>]">
                                        <option value="">Nu importa</option>
                                        <?php foreach ($headers as $i => $header): ?>
                                            <option value="<?= (int)$i ?>" <?= (string)($mapping[$field] ?? '') === (string)$i ? 'selected' : '' ?>>
                                                <?= di_h($header ?: ('Coloana ' . ($i + 1))) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                            <button class="btn" type="submit" name="action" value="preview">Previzualizeaza</button>
                            <button class="btn accent" type="submit" name="action" value="import" onclick="return confirm('Confirmi importul final in baza de date?');">Import final</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($preview): ?>
                <section class="card">
                    <div class="card-head">
                        <h2>Previzualizare import</h2>
                        <span class="small-muted"><?= count($preview) ?> randuri valide afisate</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Client</th>
                                    <th>CUI / CI</th>
                                    <th>Telefon</th>
                                    <th>Email</th>
                                    <th>Sediu</th>
                                    <th>Locatie separata</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($preview as $p): ?>
                                <?php $d = $p['data']; ?>
                                <tr>
                                    <td>
                                        <span class="badge <?= di_h($p['status']) ?>">
                                            <?= $p['status'] === 'new' ? 'Nou' : 'Actualizare ID ' . di_h($p['existing_id']) ?>
                                        </span>
                                    </td>
                                    <td><?= di_h($d['name']) ?></td>
                                    <td><?= di_h($d['fiscal_code']) ?></td>
                                    <td><?= di_h($d['phone']) ?></td>
                                    <td><?= di_h($d['email']) ?></td>
                                    <td><?= di_h($d['registered_address']) ?></td>
                                    <td><?= $p['will_location'] ? di_h($d['delivery_address']) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>