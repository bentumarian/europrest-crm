<?php
/**
 * FIX — adauga butoane "Oferta noua" si "Contract nou"
 * ----------------------------------------------------------------
 * Problema: formul de creare oferta/contract era ascuns pana cand
 * URL-ul avea ?new=1, dar nu exista buton vizibil care sa adauge ?new=1.
 *
 * Fix: adauga in antetul listei un buton "+ Oferta noua" / "+ Contract nou".
 *
 * Cum se foloseste:
 *   1. Incarca pe server langa config.php
 *   2. Deschide ca admin: https://app.pestzone.ro/fix_new_doc_buttons.php
 *   3. Click "Aplica fix"
 *   4. Mergi in Oferte → vezi butonul → click → formul se deschide
 *   5. Sterge fisierul dupa
 */

require_once __DIR__ . '/config.php';
if (function_exists('require_login')) require_login();
if (function_exists('is_admin') && !is_admin()) { http_response_code(403); exit('Doar admin.'); }

$ROOT = __DIR__;

$PATCHES = [
    [
        'file' => 'oferte.php',
        'name' => 'Buton "+ Oferta noua" in antetul listei',
        'find' => "                <div class=\"panel-head\">\n                    <div>\n                        <div class=\"panel-title\">Lista oferte</div>\n                        <div class=\"panel-subtitle\">Cauta dupa numar, client, CUI sau titlu.</div>\n                    </div>\n                </div>",
        'replace' => "                <div class=\"panel-head\">\n                    <div>\n                        <div class=\"panel-title\">Lista oferte</div>\n                        <div class=\"panel-subtitle\">Cauta dupa numar, client, CUI sau titlu.</div>\n                    </div>\n                    <a class=\"btn primary\" href=\"oferte.php?new=1\">+ Oferta noua</a>\n                </div>",
    ],
    [
        'file' => 'contracts.php',
        'name' => 'Buton "+ Contract nou" in antetul listei',
        'find' => "<div class=\"panel-title\">Lista contracte</div>",
        'replace' => "<div class=\"panel-title\">Lista contracte</div>",
        'extra_check' => true,  // tratat manual mai jos
    ],
    [
        'file' => 'procese_verbale.php',
        'name' => 'Buton "+ PV nou" in antetul listei',
        'find' => "<div class=\"panel-title\">Lista procese verbale</div>",
        'replace' => "<div class=\"panel-title\">Lista procese verbale</div>",
        'extra_check' => true,  // tratat manual mai jos
    ],
];

function backup(string $path): ?string {
    if (!is_file($path)) return null;
    $bak = $path . '.bak.' . date('Ymd_His');
    return @copy($path, $bak) ? $bak : null;
}

function patch_list_panel_head(string $path, string $listTitle, string $newButtonHtml): array {
    if (!is_file($path)) return ['ok' => false, 'msg' => 'Fisier inexistent'];
    $content = @file_get_contents($path);
    if ($content === false) return ['ok' => false, 'msg' => 'Nu pot citi'];

    // Verifica daca patch-ul e deja aplicat
    if (strpos($content, $newButtonHtml) !== false) {
        return ['ok' => true, 'msg' => 'Deja aplicat (buton prezent)', 'skip' => true];
    }

    // Cauta panel-head ce contine titlul listei + adauga butonul inainte de </div> care inchide panel-head
    // Pattern: <div class="panel-head"> ... <div class="panel-title">Lista X</div> ... </div> (sfarsitul lui panel-head)
    // Strategie: gaseste linia cu panel-title pentru lista, gaseste div-ul care inchide panel-head si insereaza butonul.

    $pos = strpos($content, $listTitle);
    if ($pos === false) {
        return ['ok' => false, 'msg' => 'Nu am gasit titlul listei: ' . $listTitle];
    }

    // Cauta inapoi <div class="panel-head"> cel mai apropiat
    $headStart = strrpos(substr($content, 0, $pos), '<div class="panel-head">');
    if ($headStart === false) {
        return ['ok' => false, 'msg' => 'Nu am gasit panel-head pentru lista'];
    }

    // Cauta inainte primul </div> care e la nivel "exterior" (care inchide panel-head)
    // Folosim un counter de div-uri
    $afterHead = $headStart + strlen('<div class="panel-head">');
    $depth = 1;
    $i = $afterHead;
    $len = strlen($content);
    while ($i < $len && $depth > 0) {
        $nextOpen = strpos($content, '<div', $i);
        $nextClose = strpos($content, '</div>', $i);
        if ($nextClose === false) break;
        if ($nextOpen !== false && $nextOpen < $nextClose) {
            $depth++;
            $i = $nextOpen + 4;
        } else {
            $depth--;
            if ($depth === 0) {
                // Aici se inchide panel-head. Inseram butonul EXACT inainte de acest </div>.
                $insertion = "\n                    " . $newButtonHtml . "\n                ";
                $newContent = substr($content, 0, $nextClose) . $insertion . substr($content, $nextClose);

                $bak = backup($path);
                if (!$bak) return ['ok' => false, 'msg' => 'Backup esuat'];
                if (@file_put_contents($path, $newContent) === false) return ['ok' => false, 'msg' => 'Scriere esuata'];

                return ['ok' => true, 'msg' => 'Buton adaugat. Backup: ' . basename($bak)];
            }
            $i = $nextClose + 6;
        }
    }

    return ['ok' => false, 'msg' => 'Nu am putut localiza inchiderea panel-head'];
}

$run = isset($_POST['run']) && $_POST['run'] === '1';
$report = [];

if ($run) {
    $report[] = patch_list_panel_head(
        $ROOT . '/oferte.php',
        '<div class="panel-title">Lista oferte</div>',
        '<a class="btn primary" href="oferte.php?new=1">+ Oferta noua</a>'
    ) + ['file' => 'oferte.php'];

    $report[] = patch_list_panel_head(
        $ROOT . '/contracts.php',
        '<div class="panel-title">Lista contracte</div>',
        '<a class="btn primary" href="contracts.php?new=1">+ Contract nou</a>'
    ) + ['file' => 'contracts.php'];

    $report[] = patch_list_panel_head(
        $ROOT . '/procese_verbale.php',
        '<div class="panel-title">Lista procese verbale</div>',
        '<a class="btn primary" href="procese_verbale.php?new=1">+ PV nou</a>'
    ) + ['file' => 'procese_verbale.php'];
}

function color($s) {
    if (!empty($s['skip'])) return '#1d4ed8';
    return !empty($s['ok']) ? '#047857' : '#b91c1c';
}
function label($s) {
    if (!empty($s['skip'])) return 'DEJA APLICAT';
    return !empty($s['ok']) ? 'OK' : 'ESUAT';
}
?>
<!doctype html>
<html lang="ro"><head><meta charset="utf-8"><title>Fix — Butoane "Document nou"</title>
<style>
body{font-family:-apple-system,Arial;max-width:780px;margin:32px auto;padding:0 18px;color:#0f172a;background:#f8fafc}
h1{font-size:22px;margin:0 0 6px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin:14px 0}
.btn{display:inline-block;padding:12px 22px;border:0;border-radius:10px;background:#4f46e5;color:#fff;font-weight:700;cursor:pointer;font-size:14px;text-decoration:none}
.btn.ghost{background:#fff;color:#4f46e5;border:1px solid #4f46e5}
.status{display:inline-block;padding:4px 14px;border-radius:8px;color:#fff;font-weight:700;font-size:11px}
.warn{background:#fffbeb;border:1px solid #fcd34d;color:#78350f;padding:12px 14px;border-radius:10px;margin:12px 0;font-size:14px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px}
th,td{padding:8px 10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}
th{background:#f8fafc;font-size:11px;text-transform:uppercase;color:#475569}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-family:ui-monospace,Menlo,monospace;font-size:12px}
</style></head><body>

<h1>Fix — Butoane "Document nou"</h1>
<p style="color:#64748b">Adauga in antetul listei butoanele <strong>+ Oferta noua</strong>, <strong>+ Contract nou</strong>, <strong>+ PV nou</strong>.</p>

<?php if (!$run): ?>
    <div class="warn">
        <strong>Patch-ul detecteaza automat antetul listei pentru fiecare pagina si insereaza butonul corect. Backup la fiecare fisier.</strong>
    </div>
    <div class="card">
        <form method="post"><input type="hidden" name="run" value="1"><button class="btn" type="submit">Aplica fix</button></form>
    </div>
<?php else: ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:16px">Raport</h2>
        <table>
            <thead><tr><th>Fisier</th><th>Status</th><th>Detalii</th></tr></thead>
            <tbody>
            <?php foreach ($report as $r): ?>
                <tr>
                    <td><code><?= htmlspecialchars($r['file']) ?></code></td>
                    <td><span class="status" style="background:<?= color($r) ?>"><?= label($r) ?></span></td>
                    <td><?= htmlspecialchars($r['msg']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:16px"><strong>Test:</strong></p>
        <ol>
            <li>Mergi in <strong>Oferte</strong> → ar trebui sa vezi <strong>+ Oferta noua</strong> in antetul listei</li>
            <li>Click pe el → formul se deschide cu butoanele "Salveaza draft" si "Emite oferta"</li>
            <li>La fel pentru <strong>Contracte</strong> si <strong>Procese verbale</strong></li>
            <li>Sterge acest fisier de pe server</li>
        </ol>

        <p><a class="btn ghost" href="?">Inapoi</a></p>
    </div>
<?php endif; ?>

</body></html>
