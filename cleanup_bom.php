<?php
/*
|--------------------------------------------------------------------------
| cleanup_bom.php — curăță UTF-8 BOM din fișierele PHP
|--------------------------------------------------------------------------
| BOM-ul (3 bytes 0xEF 0xBB 0xBF la începutul fișierului) este invizibil
| în editor dar PHP îl trimite ca output înainte de a interpreta `<?php`.
| Asta cauzează „headers already sent" și pe Romarg → eroare 400.
|
| Rulare:
|   - din CLI:    php cleanup_bom.php
|   - din browser: https://app.pestzone.ro/cleanup_bom.php?key=CHEIE_AICI
|
| Dry-run (doar listează, nu modifică):
|   - din CLI:    php cleanup_bom.php --dry
|   - din browser: ?key=…&dry=1
|
| După rulare, ȘTERGE acest fișier de pe server pentru securitate.
|--------------------------------------------------------------------------
*/

// === CONFIGURARE ===
$SECRET_KEY = 'pestzone_bom_cleanup_2026';  // schimbă cu o cheie a ta și folosește-o în URL
$ROOT_DIR   = __DIR__;                       // folderul de scanat (default: folderul curent)
$EXTS       = ['php', 'phtml', 'inc'];       // extensii de verificat

$isCli = (PHP_SAPI === 'cli');
$isDry = false;

if ($isCli) {
    foreach ($argv ?? [] as $a) {
        if ($a === '--dry' || $a === '-n') $isDry = true;
    }
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string)($_GET['key'] ?? '');
    if (!hash_equals($SECRET_KEY, $key)) {
        http_response_code(403);
        echo "Forbidden — cheie greșită sau lipsă.\n";
        echo "Folosește: ?key=CHEIA_DIN_FISIER\n";
        exit;
    }
    $isDry = !empty($_GET['dry']);
}

echo "=================================================\n";
echo " cleanup_bom.php — " . ($isDry ? "DRY-RUN (nu modifică)" : "MODIFICĂ fișierele") . "\n";
echo " Root: {$ROOT_DIR}\n";
echo "=================================================\n\n";

$BOM = "\xEF\xBB\xBF";
$found = 0;
$fixed = 0;
$errors = 0;
$skippedNoPerm = 0;

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($rii as $file) {
    if (!$file->isFile()) continue;

    $ext = strtolower($file->getExtension());
    if (!in_array($ext, $EXTS, true)) continue;

    $path = $file->getPathname();

    // Sari peste vendor, node_modules, .git
    if (preg_match('#/(vendor|node_modules|\.git|uploads)/#', str_replace('\\', '/', $path))) continue;

    // Citește primii 3 bytes
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        echo "  ! NU POT CITI: {$path}\n";
        $errors++;
        continue;
    }
    $head = fread($fh, 3);
    fclose($fh);

    if ($head !== $BOM) continue;

    $found++;
    $rel = ltrim(str_replace($ROOT_DIR, '', $path), DIRECTORY_SEPARATOR);
    echo "  BOM găsit: {$rel}\n";

    if ($isDry) continue;

    // Citește restul, scrie fără BOM
    $content = @file_get_contents($path);
    if ($content === false) {
        echo "    ! Eroare la citire conținut\n";
        $errors++;
        continue;
    }
    if (substr($content, 0, 3) === $BOM) {
        $newContent = substr($content, 3);

        // Verifică drepturi de scriere
        if (!is_writable($path)) {
            echo "    ! Fișier read-only — skip (chmod 644)\n";
            $skippedNoPerm++;
            continue;
        }

        // Scrie cu lock atomic via tmp file ca să evităm corupție
        $tmp = $path . '.bomtmp';
        if (@file_put_contents($tmp, $newContent, LOCK_EX) === false) {
            echo "    ! Eroare la scriere tmp\n";
            $errors++;
            continue;
        }

        // Păstrează permisiunile vechi
        @chmod($tmp, fileperms($path) & 0777);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            echo "    ! Eroare la rename\n";
            $errors++;
            continue;
        }

        echo "    ✓ BOM eliminat\n";
        $fixed++;
    }
}

echo "\n=================================================\n";
echo " REZULTAT:\n";
echo "   Fișiere cu BOM găsite : {$found}\n";
echo "   Fișiere curățate      : {$fixed}\n";
echo "   Skip (permisiuni)     : {$skippedNoPerm}\n";
echo "   Erori                 : {$errors}\n";
echo "=================================================\n";

if (!$isDry && $fixed > 0) {
    echo "\nAtenție: după ce verifici că totul merge, ȘTERGE acest fișier\n";
    echo "de pe server (cleanup_bom.php) pentru securitate.\n";
}
