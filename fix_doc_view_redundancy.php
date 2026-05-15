<?php
/**
 * FIX document_view.php — elimina redundante in actions-card
 * ----------------------------------------------------------------
 * Probleme:
 *   1) Buton "Trimite email" duplicat (era in toolbar SI in actions-card)
 *   2) Formul de anulare (Motiv anulare + Anuleaza) prost aliniat din cauza
 *      vecinatatii cu butonul de email
 *
 * Fix: pastreaza un singur buton "Trimite email" (in toolbar-ul de sus),
 * iar in actions-card ramane doar formul de anulare — aliniat horizontal
 * prin CSS-ul existent (.actions-card form { display: flex; ... }).
 */

require_once __DIR__ . '/config.php';
if (function_exists('require_login')) require_login();
if (function_exists('is_admin') && !is_admin()) { http_response_code(403); exit('Doar admin.'); }

$ROOT = __DIR__;
$path = $ROOT . '/document_view.php';

$FIND = "                        <?php elseif (\$isIssued): ?>
                            <?php if (\$isAdmin): ?>
                                <a class=\"btn accent\" href=\"document_send_email.php?id=<?= (int)\$document['id'] ?>\">Trimite email</a>
                            <?php elseif (!\$hasClientEmail): ?>
                                <span class=\"btn disabled\">Email lipsa</span>
                            <?php elseif (\$teamEmailBlockedBySignature): ?>
                                <span class=\"btn disabled\">Email dupa semnatura</span>
                            <?php else: ?>
                                <button class=\"btn accent\" type=\"button\" onclick=\"sendQuickDocumentEmail(<?= (int)\$document['id'] ?>, this)\">Trimite email</button>
                            <?php endif; ?>
                            <?php if (\$isAdmin): ?>
                            <form method=\"post\" onsubmit=\"return confirm('Anulezi documentul emis? Numarul va ramane in registru ca anulat.');\">
                                <?= csrf_field() ?>
                                <input type=\"hidden\" name=\"action\" value=\"cancel\">
                                <input type=\"hidden\" name=\"document_id\" value=\"<?= (int)\$document['id'] ?>\">
                                <input type=\"text\" name=\"cancel_reason\" placeholder=\"Motiv anulare\">
                                <button class=\"btn danger\" type=\"submit\">Anuleaza</button>
                            </form>
                            <?php endif; ?>";

$REPLACE = "                        <?php elseif (\$isIssued && \$isAdmin): ?>
                            <form method=\"post\" onsubmit=\"return confirm('Anulezi documentul emis? Numarul va ramane in registru ca anulat.');\">
                                <?= csrf_field() ?>
                                <input type=\"hidden\" name=\"action\" value=\"cancel\">
                                <input type=\"hidden\" name=\"document_id\" value=\"<?= (int)\$document['id'] ?>\">
                                <input type=\"text\" name=\"cancel_reason\" placeholder=\"Motiv anulare (optional)\">
                                <button class=\"btn danger\" type=\"submit\">Anuleaza documentul</button>
                            </form>";

$run = isset($_POST['run']) && $_POST['run'] === '1';
$result = ['status' => 'NERULAT', 'msg' => ''];

if ($run) {
    if (!is_file($path)) {
        $result = ['status' => 'EROARE', 'msg' => 'document_view.php nu exista'];
    } else {
        $content = @file_get_contents($path);
        if ($content === false) {
            $result = ['status' => 'EROARE', 'msg' => 'Nu pot citi fisierul'];
        } elseif (strpos($content, $REPLACE) !== false && strpos($content, $FIND) === false) {
            $result = ['status' => 'DEJA APLICAT', 'msg' => 'Modificarea e deja prezenta'];
        } elseif (strpos($content, $FIND) === false) {
            $result = ['status' => 'ESUAT', 'msg' => 'Nu am gasit pattern-ul exact. Fisierul tau difera de versiunea asteptata.'];
        } else {
            $bak = $path . '.bak.' . date('Ymd_His');
            if (!@copy($path, $bak)) {
                $result = ['status' => 'EROARE', 'msg' => 'Backup esuat'];
            } else {
                $newContent = str_replace($FIND, $REPLACE, $content);
                if (@file_put_contents($path, $newContent) === false) {
                    $result = ['status' => 'EROARE', 'msg' => 'Scriere esuata'];
                } else {
                    $result = ['status' => 'OK', 'msg' => 'Backup: ' . basename($bak)];
                }
            }
        }
    }
}

function color($s) {
    if ($s === 'OK') return '#047857';
    if ($s === 'DEJA APLICAT') return '#1d4ed8';
    if (in_array($s, ['EROARE', 'ESUAT'], true)) return '#b91c1c';
    return '#64748b';
}
?>
<!doctype html>
<html lang="ro"><head><meta charset="utf-8"><title>Fix document_view.php — eliminare redundanta</title>
<style>
body{font-family:-apple-system,Arial;max-width:760px;margin:24px auto;padding:0 18px;color:#0f172a;background:#f8fafc}
h1{font-size:22px;margin:0 0 6px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin:14px 0}
.btn{display:inline-block;padding:12px 22px;border:0;border-radius:10px;background:#4f46e5;color:#fff;font-weight:700;cursor:pointer;font-size:14px;text-decoration:none}
.btn.ghost{background:#fff;color:#4f46e5;border:1px solid #4f46e5}
.status{display:inline-block;padding:4px 14px;border-radius:8px;color:#fff;font-weight:700;font-size:11px}
.warn{background:#fffbeb;border:1px solid #fcd34d;color:#78350f;padding:12px 14px;border-radius:10px;margin:12px 0;font-size:14px}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-family:ui-monospace,Menlo,monospace;font-size:12px}
</style></head><body>

<h1>Fix document_view.php — eliminare redundanta</h1>
<p style="color:#64748b">Elimina butonul duplicat "Trimite email" din zona de actiuni si lasa doar formul de anulare (aliniat orizontal cu input + buton pe acelasi rand).</p>

<?php if (!$run): ?>
    <div class="warn">
        <strong>Inainte:</strong> [Trimite email] [Motiv anulare ____] [Anuleaza] — 3 elemente in zona, "Trimite email" duplicat cu cel din toolbar-ul de sus.<br>
        <strong>Dupa:</strong> [Motiv anulare ___________________] [Anuleaza documentul] — 2 elemente curate, pe acelasi rand.
    </div>
    <div class="card">
        <form method="post"><input type="hidden" name="run" value="1"><button class="btn" type="submit">Aplica fix</button></form>
    </div>
<?php else: ?>
    <div class="card">
        <p>Status: <span class="status" style="background:<?= color($result['status']) ?>"><?= htmlspecialchars($result['status']) ?></span></p>
        <p><?= htmlspecialchars($result['msg']) ?></p>

        <?php if ($result['status'] === 'OK'): ?>
            <p><strong>Test:</strong> reincarca pagina unui PV emis → in zona "actions-card" ar trebui sa vezi doar formul de anulare aliniat orizontal. Butonul "Trimite email" ramane doar in toolbar-ul de sus.</p>
        <?php endif; ?>

        <p><a class="btn ghost" href="?">Inapoi</a></p>
    </div>
<?php endif; ?>

</body></html>
