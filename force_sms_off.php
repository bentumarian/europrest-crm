<?php
/**
 * Forteaza smslink_enabled = "0" direct in DB.
 * Bypass UI — doar SQL UPDATE.
 *
 * Cum se foloseste:
 *   1. Incarca pe server langa config.php
 *   2. Deschide ca admin: https://app.pestzone.ro/force_sms_off.php
 *   3. Verifica mesajul
 *   4. Sterge fisierul dupa
 */

require_once __DIR__ . '/config.php';

if (function_exists('require_login')) require_login();
if (function_exists('is_admin') && !is_admin()) { http_response_code(403); exit('Doar admin.'); }

$action = $_POST['action'] ?? '';
$message = '';
$messageType = 'info';
$currentValue = null;

try {
    $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'smslink_enabled' LIMIT 1");
    $stmt->execute();
    $currentValue = $stmt->fetchColumn();
} catch (Throwable $e) {
    $message = 'Eroare citire: ' . $e->getMessage();
    $messageType = 'err';
}

if ($action === 'force_off') {
    try {
        // INSERT sau UPDATE — sigur ca exista cheia cu valoarea "0"
        $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('smslink_enabled', '0', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0', updated_at = NOW()");
        $stmt->execute();

        // Citeste din nou ca sa verifici
        $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'smslink_enabled' LIMIT 1");
        $stmt->execute();
        $newValue = $stmt->fetchColumn();

        if ($newValue === '0') {
            $message = 'SMS DEZACTIVAT IN DB. Valoare confirmata: "' . $newValue . '". Toate SMS-urile vor fi blocate de aici inainte.';
            $messageType = 'ok';
            $currentValue = $newValue;
        } else {
            $message = 'UPDATE-ul a rulat dar valoarea curenta este "' . $newValue . '". Ceva o reseteaza inapoi la "1" — bug in cod.';
            $messageType = 'err';
        }
    } catch (Throwable $e) {
        $message = 'Eroare: ' . $e->getMessage();
        $messageType = 'err';
    }
}

if ($action === 'force_on') {
    try {
        $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('smslink_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW()");
        $stmt->execute();
        $message = 'SMS REACTIVAT.';
        $messageType = 'ok';
        $currentValue = '1';
    } catch (Throwable $e) {
        $message = 'Eroare: ' . $e->getMessage();
        $messageType = 'err';
    }
}
?>
<!doctype html>
<html lang="ro"><head><meta charset="utf-8"><title>Force SMS Off</title>
<style>
body{font-family:-apple-system,Arial;max-width:680px;margin:48px auto;padding:0 18px;background:#f8fafc;color:#0f172a}
h1{font-size:22px;margin:0 0 6px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;margin-top:18px}
.btn{display:inline-block;padding:12px 22px;border-radius:10px;border:0;font-weight:700;cursor:pointer;font-size:14px;margin-right:8px}
.btn.danger{background:#dc2626;color:#fff}
.btn.success{background:#059669;color:#fff}
.muted{color:#64748b;font-size:13px}
.status{display:inline-block;padding:4px 12px;border-radius:8px;font-family:ui-monospace,Menlo,monospace;font-weight:700;font-size:14px}
.status.on{background:#fee2e2;color:#991b1b}
.status.off{background:#dcfce7;color:#166534}
.alert{padding:12px 14px;border-radius:10px;margin:14px 0;font-weight:600;font-size:14px}
.alert.ok{background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7}
.alert.err{background:#fef2f2;color:#991b1b;border:1px solid #fca5a5}
.alert.info{background:#eff6ff;color:#1e40af;border:1px solid #93c5fd}
</style></head><body>

<h1>Forteaza SMS Off in DB</h1>
<p class="muted">Bypass UI — scrie direct "0" in <code>app_settings.smslink_enabled</code>.</p>

<div class="card">
    <p>Valoare curenta in DB: <span class="status <?= $currentValue === '0' ? 'off' : 'on' ?>"><?= htmlspecialchars($currentValue ?? '(niciuna)') ?></span></p>

    <?php if ($message): ?>
        <div class="alert <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="post">
        <button class="btn danger" type="submit" name="action" value="force_off">DEZACTIVEAZA SMS (forteaza "0")</button>
        <button class="btn success" type="submit" name="action" value="force_on">REACTIVEAZA SMS</button>
    </form>

    <p class="muted" style="margin-top:18px"><strong>Pas urmator dupa "DEZACTIVEAZA":</strong></p>
    <ol class="muted">
        <li>Creaza o programare noua in calendar (declanseaza SMS de confirmare).</li>
        <li>Verifica in <code>notification_logs</code> ultimul rand:
            <ul>
                <li>Status <code>skipped</code> + mesaj "Trimiterea SMS este dezactivata global" → <strong>codul functioneaza</strong>. Bug-ul e in formul Comunicare/Integrari (UI nu salveaza).</li>
                <li>Status <code>sent</code> → <strong>codul e ocolit</strong>. Trebuie sa investigam mai adanc.</li>
            </ul>
        </li>
        <li>Sterge acest fisier de pe server.</li>
    </ol>
</div>

</body></html>
