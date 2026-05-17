<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/app_ui.php';
require_once __DIR__ . '/super_admin_guard.php';

pz_require_super_admin();

function reset_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function reset_collect_file_paths(PDO $pdo): array {
    $sources = [
        'document_email_logs' => ['attachment_path'],
        'document_files' => ['file_path', 'pdf_path', 'local_path', 'storage_path', 'path'],
        'generated_documents' => ['file_path', 'pdf_path', 'local_path', 'storage_path', 'path', 'generated_path'],
        'process_verbals' => ['file_path', 'pdf_path', 'signature_path'],
        'documents' => ['file_path', 'pdf_path', 'signature_path', 'attachment_path'],
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
                // Nu blocam resetul dacă un tabel vechi are o structura diferita.
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
        $messages[] = 'INFO: nu au fost gasite fișiere generate de șters sau nu erau in directoare permise.';
    }
    return $deleted;
}

// Date operationale care se sterg la reset.
// Se pastreaza: users, team_members, services, stock_products, document_templates,
// notification_templates, document_series, app_settings si setarile companiei.
$resetTables = [
    // loguri / sesiuni temporare
    'login_attempts',
    'password_resets',

    // notificări si emailuri trimise
    'notification_logs',
    'document_email_logs',

    // gestiune operationala - se pastreaza nomenclatorul stock_products, dar stocul revine la zero
    'stock_movements',
    'stock_receipts',

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
    'appointments',

    // contracte si clienți
    'contracts',
    'client_locations',
    'clients',
];

$resetTables = array_values(array_unique($resetTables));

$messages = [];
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenOk = hash_equals($_SESSION['platform_reset_token'] ?? '', $_POST['reset_token'] ?? '');
    $confirmText = strtoupper(trim((string)($_POST['confirm_text'] ?? '')));
    $backupChecked = isset($_POST['backup_confirm']);
    $finalChecked = isset($_POST['final_confirm']);

    if (!$tokenOk) {
        $errors[] = 'Token invalid. Reincarca pagina si incearca din nou.';
    }
    if ($confirmText !== 'RESET') {
        $errors[] = 'Pentru confirmare trebuie sa scrii exact RESET.';
    }
    if (!$backupChecked) {
        $errors[] = 'Trebuie sa confirmi ca ai facut backup înainte de reset.';
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

            // Reseteaza numerotarea seriilor, fara sa stearga seriile configurate.
            if (reset_table_exists($pdo, 'document_series')) {
                $pdo->exec('UPDATE document_series SET next_number = 1');
                $messages[] = 'OK: seriile documentelor au fost resetate la urmatorul numar 1.';
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->commit();

            // Ștergem doar fișiere generate/atașate, doar dacă sunt in directoare operationale permise.
            reset_delete_generated_files($generatedFilePaths, $messages);

            // Token nou după reset, ca sa evitam repetarea prin refresh.
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
                    <p>Șterge datele operationale introduse si readuce platforma la o stare curata, pastrand configurarea de baza.</p>
                </div>
            </div>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><?= reset_h($err) ?></div>
            <?php endforeach; ?>

            <?php if ($done): ?>
                <div class="alert alert-ok">Resetul a fost finalizat. Datele operationale au fost șterse.</div>
                <div class="log"><?php foreach($messages as $m) echo reset_h($m) . "\n"; ?></div>
            <?php endif; ?>

            <section class="danger-box">
                <h2 class="danger-title">Atentie: actiune ireversibila</h2>
                <p class="danger-text">Aceasta actiune sterge clienți, locații, contracte, sarcini, programări, documente/PV/oferte/contracte generate, statusurile de facturare, valori de intervenții, loguri de email/SMS si date operationale vechi de facturare. Nu apasa butonul fara backup complet al bazei de date si fișierelor.</p>
            </section>

            <section class="safe-box">
                <h2>Se pastreaza</h2>
                <div class="kept-list">
                    <div>Utilizatorii si parolele</div>
                    <div>Datele companiei si setarile platformei</div>
                    <div>Serviciile din nomenclator</div>
                    <div>Tehnicienii / operatorii</div>
                    <div>Produsele din nomenclatorul de stoc, dar fara intrari/miscari</div>
                    <div>Template-urile si designul documentelor</div>
                    <div>Template-urile SMS/email</div>
                    <div>Seriile documentelor, dar cu numerotarea resetata</div>
                </div>
                <div class="small-note">Codul platformei, fișierele PHP si configurarea tehnica nu sunt șterse. Se pot sterge doar fișiere generate/atașate documentelor, dacă se afla in directoare operationale permise.</div>
            </section>

            <section class="safe-box">
                <h2>Date care vor fi șterse</h2>
                <div class="grid-counts">
                    <?php foreach($counts as $table => $count): ?>
                        <div class="count-card">
                            <span><?= reset_h($table) ?></span>
                            <strong><?= (int)$count ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="danger-box">
                <h2 class="danger-title">Confirmare reset</h2>
                <form method="post" action="platform_reset.php" onsubmit="return confirm('Confirmi resetarea platformei? Datele șterse nu pot fi recuperate fara backup.');">
                    <input type="hidden" name="reset_token" value="<?= reset_h($_SESSION['platform_reset_token']) ?>">

                    <label class="check-row">
                        <input type="checkbox" name="backup_confirm" value="1">
                        <span>Confirm ca am facut backup complet la baza de date si fișiere.</span>
                    </label>

                    <label class="check-row">
                        <input type="checkbox" name="final_confirm" value="1">
                        <span>Confirm ca vreau sa sterg datele operationale din platforma.</span>
                    </label>

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
