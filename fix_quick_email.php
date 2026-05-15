<?php
/**
 * FIX UX email — quick send cu confirmare scurta + link "Editeaza"
 * ----------------------------------------------------------------
 * Inainte: Trimite email → pagina full form → click Send → confirm → trimite (3-4 pasi)
 * Dupa: Trimite email → confirm "Trimiti la X?" → trimite (2 pasi)
 *
 * Pentru cazuri rare (alt destinatar, subject editat) → link mic "Editeaza →"
 *
 * Modificari:
 *   1) Functia JS sendQuickDocumentEmail() primeste recipient ca al 3-lea param,
 *      afiseaza confirmare "Trimiti documentul pe email la X?"
 *   2) Butonul admin "Trimite email" devine quick send (nu mai naviga la full form)
 *   3) Adauga link mic "Editeaza →" pentru cazuri non-standard
 *   4) Pentru cazuri fara email client → trimite la full form (sa completeze manual)
 */

require_once __DIR__ . '/config.php';
if (function_exists('require_login')) require_login();
if (function_exists('is_admin') && !is_admin()) { http_response_code(403); exit('Doar admin.'); }

$ROOT = __DIR__;
$path = $ROOT . '/document_view.php';

/* ============================================================
   PATCH 1: JS function - adauga recipient + confirmare
   ============================================================ */
$FIND_1 = "async function sendQuickDocumentEmail(documentId, btn) {
    if (!documentId) { alert('PV-ul nu a fost identificat.'); return; }
    const originalText = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Se trimite...'; }
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('document_id', documentId);";

$REPLACE_1 = "async function sendQuickDocumentEmail(documentId, btn, recipientEmail) {
    if (!documentId) { alert('Documentul nu a fost identificat.'); return; }
    const confirmMsg = recipientEmail
        ? 'Trimiti documentul pe email la: ' + recipientEmail + ' ?'
        : 'Trimiti documentul pe email catre clientul curent?';
    if (!confirm(confirmMsg)) return;
    const originalText = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Se trimite...'; }
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('document_id', documentId);";

/* ============================================================
   PATCH 2: Toolbar email button — quick send + Editeaza link
   ============================================================ */
$FIND_2 = "                    <?php if (\$isIssued): ?>
                        <?php if (\$isAdmin): ?>
                            <a class=\"btn\" href=\"document_send_email.php?id=<?= (int)\$document['id'] ?>\">Trimite email</a>
                        <?php elseif (!\$hasClientEmail): ?>
                            <span class=\"btn disabled\">Email lipsa</span>
                        <?php elseif (\$teamEmailBlockedBySignature): ?>
                            <span class=\"btn disabled\">Email dupa semnatura</span>
                        <?php else: ?>
                            <button class=\"btn\" type=\"button\" onclick=\"sendQuickDocumentEmail(<?= (int)\$document['id'] ?>, this)\">Trimite email</button>
                        <?php endif; ?>
                    <?php elseif (\$isDraft): ?>
                        <span class=\"btn disabled\">Email dupa emitere</span>
                    <?php endif; ?>";

$REPLACE_2 = "                    <?php if (\$isIssued): ?>
                        <?php if (!\$hasClientEmail): ?>
                            <?php if (\$isAdmin): ?>
                                <a class=\"btn\" href=\"document_send_email.php?id=<?= (int)\$document['id'] ?>\" title=\"Clientul nu are email salvat — completeaza din pagina de email\">Trimite email (fara destinatar)</a>
                            <?php else: ?>
                                <span class=\"btn disabled\">Email lipsa</span>
                            <?php endif; ?>
                        <?php elseif (!\$isAdmin && \$teamEmailBlockedBySignature): ?>
                            <span class=\"btn disabled\">Email dupa semnatura</span>
                        <?php else: ?>
                            <button class=\"btn accent\" type=\"button\" onclick=\"sendQuickDocumentEmail(<?= (int)\$document['id'] ?>, this, '<?= dview_h(\$document['client_email_snapshot'] ?? '') ?>')\">Trimite email</button>
                            <?php if (\$isAdmin): ?>
                                <a class=\"link-muted\" href=\"document_send_email.php?id=<?= (int)\$document['id'] ?>\" style=\"font-size:12px;align-self:center;text-decoration:none;\">Editeaza &rarr;</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php elseif (\$isDraft): ?>
                        <span class=\"btn disabled\">Email dupa emitere</span>
                    <?php endif; ?>";

/* ============================================================
   Aplicare
   ============================================================ */

$run = isset($_POST['run']) && $_POST['run'] === '1';
$report = [];

if ($run) {
    if (!is_file($path)) {
        $report[] = ['name' => 'Verificare fisier', 'status' => 'EROARE', 'msg' => 'document_view.php nu exista'];
    } else {
        $content = @file_get_contents($path);
        if ($content === false) {
            $report[] = ['name' => 'Citire', 'status' => 'EROARE', 'msg' => 'Nu pot citi fisierul'];
        } else {
            $original = $content;
            $changes = 0;
            $errors = [];

            // Patch 1
            if (strpos($content, $REPLACE_1) !== false && strpos($content, $FIND_1) === false) {
                $report[] = ['name' => 'Confirmare in sendQuickDocumentEmail()', 'status' => 'DEJA APLICAT', 'msg' => 'Deja prezent'];
            } elseif (strpos($content, $FIND_1) === false) {
                $report[] = ['name' => 'Confirmare in sendQuickDocumentEmail()', 'status' => 'ESUAT', 'msg' => 'Pattern negasit'];
                $errors[] = 'patch 1';
            } else {
                $content = str_replace($FIND_1, $REPLACE_1, $content);
                $report[] = ['name' => 'Confirmare in sendQuickDocumentEmail()', 'status' => 'OK', 'msg' => 'Aplicat'];
                $changes++;
            }

            // Patch 2
            if (strpos($content, $REPLACE_2) !== false && strpos($content, $FIND_2) === false) {
                $report[] = ['name' => 'Buton toolbar quick send + Editeaza', 'status' => 'DEJA APLICAT', 'msg' => 'Deja prezent'];
            } elseif (strpos($content, $FIND_2) === false) {
                $report[] = ['name' => 'Buton toolbar quick send + Editeaza', 'status' => 'ESUAT', 'msg' => 'Pattern negasit (toolbar de email modificat altcumva)'];
                $errors[] = 'patch 2';
            } else {
                $content = str_replace($FIND_2, $REPLACE_2, $content);
                $report[] = ['name' => 'Buton toolbar quick send + Editeaza', 'status' => 'OK', 'msg' => 'Aplicat'];
                $changes++;
            }

            if ($changes > 0 && $content !== $original) {
                $bak = $path . '.bak.' . date('Ymd_His');
                if (!@copy($path, $bak)) {
                    $report[] = ['name' => 'Backup', 'status' => 'EROARE', 'msg' => 'Nu am putut crea backup'];
                } elseif (@file_put_contents($path, $content) === false) {
                    $report[] = ['name' => 'Scriere', 'status' => 'EROARE', 'msg' => 'Nu am putut scrie fisierul'];
                } else {
                    $report[] = ['name' => 'Backup + scriere', 'status' => 'OK', 'msg' => 'Backup: ' . basename($bak) . ' | Patch-uri aplicate: ' . $changes];
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
<html lang="ro"><head><meta charset="utf-8"><title>Fix UX — Quick email send</title>
<style>
body{font-family:-apple-system,Arial;max-width:820px;margin:24px auto;padding:0 18px;color:#0f172a;background:#f8fafc}
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

<h1>Fix UX — Quick email send</h1>
<p style="color:#64748b">Implementeaza Varianta A: click "Trimite email" → confirmare scurta cu destinatarul → trimite. Link "Editeaza &rarr;" pentru cazuri rare.</p>

<?php if (!$run): ?>
    <div class="warn">
        <strong>Inainte (4 pasi):</strong> click Trimite email → load full page → click Trimite → confirm → trimite<br>
        <strong>Dupa (2 pasi):</strong> click Trimite email → confirm "Trimiti la email@client.ro ?" → trimite instant (AJAX)<br><br>
        <strong>Pentru cazuri rare:</strong> link mic "Editeaza &rarr;" langa buton → te duce la full form (subject / body / alt destinatar)
    </div>
    <div class="card">
        <form method="post"><input type="hidden" name="run" value="1"><button class="btn" type="submit">Aplica fix</button></form>
    </div>
<?php else: ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:16px">Raport</h2>
        <table>
            <thead><tr><th>Modificare</th><th>Status</th><th>Detalii</th></tr></thead>
            <tbody>
            <?php foreach ($report as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><span class="status" style="background:<?= color($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td><?= htmlspecialchars($r['msg']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:16px"><strong>Test:</strong></p>
        <ol>
            <li>Deschide un PV emis cu client cu email valid</li>
            <li>In toolbar vezi <strong>"Trimite email"</strong> + langa el <strong>"Editeaza &rarr;"</strong></li>
            <li>Click "Trimite email" → confirmare "Trimiti documentul pe email la X?" → OK → primesti notificare success</li>
            <li>Click "Editeaza" (pentru cazul rar cand vrei sa schimbi destinatar/text) → full form ca inainte</li>
            <li>Sterge acest fisier de pe server</li>
        </ol>

        <p><a class="btn ghost" href="?">Inapoi</a></p>
    </div>
<?php endif; ?>

</body></html>
