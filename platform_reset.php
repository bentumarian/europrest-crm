<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/super_admin_guard.php';

pz_require_super_admin();

function reset_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reset_xlsx_col_letter(int $index): string {
    $index++;
    $letters = '';

    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = (int)(($index - $mod) / 26);
    }

    return $letters;
}

function reset_xlsx_xml($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['platform_reset_token'])) {
    $_SESSION['platform_reset_token'] = bin2hex(random_bytes(32));
}

function reset_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function reset_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function reset_safe_name(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function reset_table_count(PDO $pdo, string $table): ?int {
    if (!reset_table_exists($pdo, $table)) {
        return null;
    }
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM " . reset_safe_name($table))->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

function reset_export_clients(PDO $pdo): void {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'Extensia PHP ZipArchive nu este activa pe server.';
        exit;
    }

    $headers = [
        'DENUMIRE',
        'CUI',
        'NUMAR REGISTRU COMERTULUI',
        'TARA',
        'JUDET',
        'ORAS/SECTOR',
        'ADRESA',
        'REPREZENTANT',
        'FUNCTIE',
        'EMAIL',
        'NUMAR DE TELEFON',
    ];

    $rows = [];
    if (reset_table_exists($pdo, 'clients')) {
        $stmt = $pdo->query("
            SELECT name, fiscal_code, registry_number, billing_country, billing_county, billing_city,
                   billing_address_line, registered_address, legal_representative_name,
                   legal_representative_role, email, phone
            FROM clients
            ORDER BY active DESC, name ASC, id ASC
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $client) {
            $address = trim((string)($client['billing_address_line'] ?? ''));
            if ($address === '') {
                $address = trim((string)($client['registered_address'] ?? ''));
            }

            $rows[] = [
                (string)($client['name'] ?? ''),
                (string)($client['fiscal_code'] ?? ''),
                (string)($client['registry_number'] ?? ''),
                (string)($client['billing_country'] ?? 'Romania') ?: 'Romania',
                (string)($client['billing_county'] ?? ''),
                (string)($client['billing_city'] ?? ''),
                $address,
                (string)($client['legal_representative_name'] ?? ''),
                (string)($client['legal_representative_role'] ?? ''),
                (string)($client['email'] ?? ''),
                (string)($client['phone'] ?? ''),
            ];
        }
    }

    $allRows = array_merge([$headers], $rows);
    $sheetData = '';
    foreach ($allRows as $rowIndex => $row) {
        $rowNumber = $rowIndex + 1;
        $sheetData .= '<row r="' . $rowNumber . '">';
        foreach ($row as $colIndex => $value) {
            $cell = reset_xlsx_col_letter($colIndex) . $rowNumber;
            $style = $rowIndex === 0 ? ' s="1"' : '';
            $sheetData .= '<c r="' . $cell . '"' . $style . ' t="inlineStr"><is><t>' . reset_xlsx_xml($value) . '</t></is></c>';
        }
        $sheetData .= '</row>';
    }

    $colsXml = '';
    $widths = [34, 16, 28, 14, 18, 18, 42, 26, 18, 30, 20];
    foreach ($widths as $i => $width) {
        $col = $i + 1;
        $colsXml .= '<col min="' . $col . '" max="' . $col . '" width="' . $width . '" customWidth="1"/>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <cols>' . $colsXml . '</cols>
    <sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
    <sheetData>' . $sheetData . '</sheetData>
    <autoFilter ref="A1:K' . max(1, count($allRows)) . '"/>
</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets><sheet name="Clienti" sheetId="1" r:id="rId1"/></sheets>
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
    <fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>
    <fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEFF4FF"/><bgColor indexed="64"/></patternFill></fill></fills>
    <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>
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

    $tmp = tempnam(sys_get_temp_dir(), 'pz_clients_export_');
    if ($tmp === false) {
        http_response_code(500);
        echo 'Nu pot crea fisier temporar.';
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        http_response_code(500);
        echo 'Nu pot genera exportul Excel.';
        exit;
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $relsXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->close();

    $filename = 'clienti_pestzone_export_' . date('Y-m-d_H-i') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    readfile($tmp);
    @unlink($tmp);
    exit;
}

function reset_collect_file_paths(PDO $pdo): array {
    $sources = [
        'document_email_logs' => ['attachment_path'],
        'document_files' => ['file_path', 'pdf_path', 'local_path', 'storage_path', 'path'],
        'generated_documents' => ['file_path', 'pdf_path', 'local_path', 'storage_path', 'path', 'generated_path'],
        'process_verbals' => ['file_path', 'pdf_path', 'signature_path'],
        'documents' => ['file_path', 'pdf_path', 'signature_path', 'attachment_path'],
        'smartbill_invoices' => ['efactura_xml_path', 'efactura_pdf_path'],
        'smartbill_supplier_invoices' => ['xml_path', 'pdf_path'],
    ];

    $paths = [];
    foreach ($sources as $table => $columns) {
        if (!reset_table_exists($pdo, $table)) {
            continue;
        }

        $existing = [];
        foreach ($columns as $column) {
            if (reset_column_exists($pdo, $table, $column)) {
                $existing[] = $column;
            }
        }

        foreach ($existing as $column) {
            try {
                $sql = "SELECT " . reset_safe_name($column) . " AS path_value FROM " . reset_safe_name($table) . " WHERE " . reset_safe_name($column) . " IS NOT NULL AND " . reset_safe_name($column) . " <> ''";
                $stmt = $pdo->query($sql);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
                    $path = trim((string)$path);
                    if ($path !== '') {
                        $paths[] = $path;
                    }
                }
            } catch (Throwable $e) {
                // Nu blocam resetul daca un tabel vechi are o structura diferita.
            }
        }
    }

    return array_values(array_unique($paths));
}

function reset_path_is_url(string $path): bool {
    return (bool)preg_match('~^(https?:)?//~i', $path) || stripos($path, 'data:') === 0;
}

function reset_delete_file_if_safe(string $storedPath): bool {
    $storedPath = trim($storedPath);
    if ($storedPath === '' || reset_path_is_url($storedPath)) {
        return false;
    }

    $storedPath = str_replace('\\', '/', $storedPath);
    $storedPath = preg_replace('~[?#].*$~', '', $storedPath);

    $baseDir = realpath(__DIR__);
    if (!$baseDir) {
        return false;
    }

    $allowedDirs = [
        'uploads',
        'uploaded',
        'storage',
        'generated',
        'generated_documents',
        'document_files',
        'pdf',
        'pdfs',
        'tmp',
        'temp',
        'cache',
        'signatures',
    ];

    $candidate = null;

    if (preg_match('~^[a-zA-Z]:/~', $storedPath) || strpos($storedPath, '/') === 0) {
        $candidate = $storedPath;
    } else {
        $storedPath = ltrim($storedPath, '/');
        $firstPart = explode('/', $storedPath)[0] ?? '';
        if (!in_array($firstPart, $allowedDirs, true)) {
            return false;
        }
        $candidate = __DIR__ . '/' . $storedPath;
    }

    $realCandidate = realpath($candidate);
    if (!$realCandidate || !is_file($realCandidate)) {
        return false;
    }

    foreach ($allowedDirs as $dir) {
        $allowedBase = realpath(__DIR__ . '/' . $dir);
        if ($allowedBase && strpos($realCandidate, $allowedBase . DIRECTORY_SEPARATOR) === 0) {
            return @unlink($realCandidate);
        }
    }

    return false;
}

function reset_delete_generated_files(array $filePaths, array &$messages): int {
    $deleted = 0;
    foreach ($filePaths as $path) {
        if (reset_delete_file_if_safe($path)) {
            $deleted++;
        }
    }
    if ($deleted > 0) {
        $messages[] = 'OK: au fost șterse ' . $deleted . ' fișiere generate atașate documentelor.';
    } else {
        $messages[] = 'INFO: nu au fost găsite fișiere generate de șters sau nu erau în directoare permise.';
    }
    return $deleted;
}

// Date operationale care se sterg la reset.
// Se pastreaza: users, team_members, services, stock_products, document_templates,
// notification_templates, document_series, app_settings si setarile companiei.
$baseResetTables = [
    // loguri / sesiuni temporare
    'login_attempts',
    'password_resets',

    // notificari si emailuri trimise
    'notification_logs',
    'document_email_logs',
    'review_answers',
    'review_requests',

    // gestiune operationala - se pastreaza nomenclatorul stock_products, dar stocul revine la zero
    'stock_movements',
    'stock_receipts',
    'smartbill_invoice_payments',
    'smartbill_invoice_items',
    'smartbill_invoice_logs',
    'smartbill_recurring_invoices',
    'smartbill_supplier_invoices',
    'smartbill_invoices',

    // documente noi: contracte, oferte, PV si atasamente/loguri
    'document_links',
    'document_materials',
    'document_items',
    'document_numbers',
    'documents',

    // compatibilitate cu versiuni vechi
    'contract_locations',
    'contract_services',
    'process_verbal_items',
    'process_verbals',
    'document_files',
    'generated_documents',

    // flux operational
    'reports_generated',
    'task_recurrence_logs',
    'task_comments',
    'task_logs',
    'tasks',
    'appointment_teams',
    'appointments',

    // contracte
    'contracts',
];

$contactResetTables = [
    'client_locations',
    'clients',
];

$baseResetTables = array_values(array_unique($baseResetTables));
$contactResetTables = array_values(array_unique($contactResetTables));
$resetTables = $baseResetTables;

$messages = [];
$errors = [];
$done = false;
$deleteContacts = false;

if (($_GET['export_clients'] ?? '') === '1') {
    reset_export_clients($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenOk = hash_equals($_SESSION['platform_reset_token'] ?? '', $_POST['reset_token'] ?? '');
    $confirmText = strtoupper(trim((string)($_POST['confirm_text'] ?? '')));
    $backupChecked = isset($_POST['backup_confirm']);
    $finalChecked = isset($_POST['final_confirm']);
    $deleteContacts = isset($_POST['delete_contacts']);
    $resetTables = $deleteContacts
        ? array_values(array_unique(array_merge($baseResetTables, $contactResetTables)))
        : $baseResetTables;

    if (!$tokenOk) {
        $errors[] = 'Token invalid. Reincarca pagina si incearca din nou.';
    }
    if ($confirmText !== 'RESET') {
        $errors[] = 'Pentru confirmare trebuie sa scrii exact RESET.';
    }
    if (!$backupChecked) {
        $errors[] = 'Trebuie să confirmi că ai făcut backup înainte de reset.';
    }
    if (!$finalChecked) {
        $errors[] = 'Trebuie sa bifezi confirmarea finala de stergere.';
    }

    if (!$errors) {
        $generatedFilePaths = reset_collect_file_paths($pdo);

        try {
            $pdo->beginTransaction();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($resetTables as $table) {
                if (!reset_table_exists($pdo, $table)) {
                    $messages[] = 'SKIP: tabelul ' . $table . ' nu există.';
                    continue;
                }

                $safeTable = reset_safe_name($table);
                try {
                    $pdo->exec('TRUNCATE TABLE ' . $safeTable);
                    $messages[] = 'OK: tabelul ' . $table . ' a fost golit.';
                } catch (Throwable $e) {
                    // Fallback pentru tabele unde TRUNCATE poate fi blocat de FK/permisiuni.
                    $pdo->exec('DELETE FROM ' . $safeTable);
                    try { $pdo->exec('ALTER TABLE ' . $safeTable . ' AUTO_INCREMENT = 1'); } catch (Throwable $ignored) {}
                    $messages[] = 'OK: tabelul ' . $table . ' a fost șters prin DELETE.';
                }
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->commit();

            // Stergem doar fisiere generate/atasate, doar daca sunt in directoare operationale permise.
            reset_delete_generated_files($generatedFilePaths, $messages);
            if ($deleteContacts) {
                $messages[] = 'ATENTIE: contactele au fost sterse deoarece a fost bifata optiunea separata.';
            } else {
                $messages[] = 'INFO: contactele au fost pastrate. Tabelele clients si client_locations nu au fost golite.';
            }

            // Token nou dupa reset, ca sa evitam repetarea prin refresh.
            $_SESSION['platform_reset_token'] = bin2hex(random_bytes(32));
            $done = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Throwable $ignored) {}
            }
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $ignored) {}
            $errors[] = 'Resetul nu a fost finalizat: ' . $e->getMessage();
        }
    }
}

$counts = [];
foreach ($resetTables as $table) {
    $cnt = reset_table_count($pdo, $table);
    if ($cnt !== null) {
        $counts[$table] = $cnt;
    }
}
$contactCounts = [];
foreach ($contactResetTables as $table) {
    $cnt = reset_table_count($pdo, $table);
    if ($cnt !== null) {
        $contactCounts[$table] = $cnt;
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Reset platforma</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
.reset-page{max-width:980px;margin:0 auto;display:flex;flex-direction:column;gap:16px}.reset-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.reset-head h1{margin:0;font-size:26px;letter-spacing:-.03em}.reset-head p{margin:6px 0 0;color:var(--muted);font-weight:700;line-height:1.45}.danger-box{background:#fff;border:1px solid #fecaca;border-radius:18px;box-shadow:var(--shadow);padding:18px}.danger-title{font-size:18px;font-weight:900;color:#991b1b;margin:0 0 8px}.danger-text{color:#7f1d1d;font-weight:700;line-height:1.5;margin:0}.safe-box{background:#fff;border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);padding:18px}.safe-box h2{font-size:18px;margin:0 0 10px}.grid-counts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.count-card{border:1px solid var(--border2);border-radius:14px;padding:12px;background:var(--surface-soft)}.count-card span{display:block;color:var(--muted);font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em}.count-card strong{display:block;margin-top:4px;font-size:22px}.form-row{display:flex;flex-direction:column;gap:8px;margin-top:14px}.form-row label{font-weight:900}.form-row input[type=text]{height:46px;border:1px solid var(--border);border-radius:12px;padding:0 12px;font:inherit;font-weight:800}.check-row{display:flex;gap:10px;align-items:flex-start;margin-top:14px;font-weight:800;color:var(--text)}.check-row input{margin-top:3px}.actions{display:flex;justify-content:space-between;gap:12px;margin-top:18px;flex-wrap:wrap}.btn-danger{background:#b42318!important;border-color:#b42318!important;color:#fff!important}.btn-danger:hover{background:#8f1c13!important}.alert{border-radius:14px;padding:12px 14px;font-weight:800}.alert-ok{background:#ecfdf3;color:#027a48;border:1px solid #abefc6}.alert-error{background:#fef3f2;color:#b42318;border:1px solid #fecdca}.log{background:#0f172a;color:#e2e8f0;border-radius:14px;padding:14px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:13px;line-height:1.5;max-height:320px;overflow:auto;white-space:pre-wrap}.kept-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:8px;color:var(--muted);font-weight:700}.kept-list div{background:var(--surface-soft);border:1px solid var(--border2);border-radius:12px;padding:10px}.small-note{margin-top:10px;color:var(--muted);font-weight:700;line-height:1.45}@media(max-width:760px){.reset-head{flex-direction:column}.grid-counts{grid-template-columns:1fr}.kept-list{grid-template-columns:1fr}.actions .btn{width:100%}}
.reset-confirm-box{border-radius:14px;padding:20px}
.reset-confirm-form{display:grid;gap:14px}
.reset-checks{display:grid;gap:8px}
.reset-check{display:grid;grid-template-columns:18px minmax(0,1fr);gap:10px;align-items:start;margin:0;padding:10px 12px;border:1px solid #fee2e2;border-radius:10px;background:#fff7f7;color:#334155;font-size:13px;font-weight:750;line-height:1.35;text-transform:none;letter-spacing:0}
.reset-check input[type=checkbox]{appearance:auto;width:16px!important;height:16px!important;min-width:16px!important;min-height:16px!important;margin:1px 0 0!important;padding:0!important;border-radius:3px}
.reset-check.is-danger{border-color:#fecaca;background:#fff1f2;color:#7f1d1d}
.reset-confirm-box .form-row{margin-top:0}
.reset-confirm-box .form-row label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.reset-confirm-box .actions{align-items:center}
.section-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}
.section-title-row h2{margin:0}
.section-title-row .btn{min-height:34px;padding:7px 11px;font-size:12px}
@media(max-width:760px){.reset-confirm-box{padding:16px}.reset-check{grid-template-columns:18px 1fr}}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('settings', true); ?>
    <main class="main">
        <div class="topbar" style="padding:12px 20px;"><a class="btn ghost" href="settings.php">Înapoi la Setări</a></div>
        <div class="content reset-page">
            <div class="reset-head">
                <div>
                    <h1>Reset platforma</h1>
                    <p>Șterge datele operaționale introduse și readuce platforma la o stare curată, păstrând configurarea de bază.</p>
                </div>
            </div>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><?= reset_h($err) ?></div>
            <?php endforeach; ?>

            <?php if ($done): ?>
                <div class="alert alert-ok">Resetul a fost finalizat. Datele operaționale au fost șterse.</div>
                <div class="log"><?php foreach($messages as $m) echo reset_h($m) . "\n"; ?></div>
            <?php endif; ?>

            <section class="danger-box">
                <h2 class="danger-title">Atentie: actiune ireversibila</h2>
                <p class="danger-text">Aceasta actiune sterge contracte, sarcini, programari, documente/PV/oferte/contracte generate, statusurile de facturare, facturi/incasari locale, e-Factura, valori de interventii, loguri de email/SMS si date operationale. Contactele si punctele de lucru se pastreaza, cu exceptia cazului in care bifezi separat stergerea lor. Nu apasa butonul fara backup complet al bazei de date si fisierelor.</p>
            </section>

            <section class="safe-box">
                <h2>Se pastreaza</h2>
                <div class="kept-list">
                    <div>Utilizatorii si parolele</div>
                    <div>Contactele / clientii si punctele de lucru, daca nu bifezi stergerea separata</div>
                    <div>Datele companiei si setarile platformei</div>
                    <div>Serviciile din nomenclator</div>
                    <div>Tehnicienii / operatorii</div>
                    <div>Produsele din nomenclatorul de stoc, dar fara intrari/miscari</div>
                    <div>Template-urile si designul documentelor</div>
                    <div>Template-urile SMS/email</div>
                    <div>Seriile documentelor si numerotarea configurata</div>
                </div>
                <div class="small-note">Codul platformei, fișierele PHP și configurarea tehnică nu sunt șterse. Se pot șterge doar fișiere generate/atașate documentelor, dacă se află în directoare operaționale permise.</div>
            </section>

            <section class="safe-box">
                <h2>Date care vor fi șterse</h2>
                <p class="small-note">Resetarea standard pastreaza contactele. Tabelele de mai jos sunt date operationale care se golesc la reset.</p>
                <div class="grid-counts">
                    <?php foreach($counts as $table => $count): ?>
                        <div class="count-card">
                            <span><?= reset_h($table) ?></span>
                            <strong><?= (int)$count ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="safe-box">
                <div class="section-title-row">
                    <h2>Contacte pastrate implicit</h2>
                    <a class="btn accent" href="platform_reset.php?export_clients=1">Export clienti</a>
                </div>
                <p class="small-note">Aceste tabele se sterg doar daca bifezi separat optiunea din formularul de confirmare.</p>
                <div class="grid-counts">
                    <?php foreach($contactCounts as $table => $count): ?>
                        <div class="count-card">
                            <span><?= reset_h($table) ?></span>
                            <strong><?= (int)$count ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="danger-box reset-confirm-box">
                <h2 class="danger-title">Confirmare reset</h2>
                <form method="post" action="platform_reset.php" class="reset-confirm-form" onsubmit="return confirm('Confirmi resetarea platformei? Datele șterse nu pot fi recuperate fără backup.');">
                    <input type="hidden" name="reset_token" value="<?= reset_h($_SESSION['platform_reset_token']) ?>">

                    <div class="reset-checks">
                        <label class="reset-check">
                            <input type="checkbox" name="backup_confirm" value="1">
                            <span>Confirm că am făcut backup complet la baza de date și fișiere.</span>
                        </label>

                        <label class="reset-check">
                            <input type="checkbox" name="final_confirm" value="1">
                            <span>Confirm că vreau să șterg datele operaționale din platformă.</span>
                        </label>

                        <label class="reset-check is-danger">
                            <input type="checkbox" name="delete_contacts" value="1">
                            <span>Șterge și contactele: clienți și puncte de lucru. Fără această bifă, contactele rămân în platformă.</span>
                        </label>
                    </div>

                    <div class="form-row">
                        <label for="confirm_text">Scrie RESET pentru confirmare</label>
                        <input type="text" id="confirm_text" name="confirm_text" placeholder="RESET" autocomplete="off">
                    </div>

                    <div class="actions">
                        <a class="btn ghost" href="settings.php">Renunță</a>
                        <button class="btn btn-danger" type="submit">Reseteaza platforma</button>
                    </div>
                </form>
            </section>
        </div>
    </main>
</div>
</body>
</html>

