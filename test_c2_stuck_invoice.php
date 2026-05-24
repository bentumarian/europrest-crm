<?php
/*
|--------------------------------------------------------------------------
| Test C2 - Concurency + Stuck invoice SmartBill
|--------------------------------------------------------------------------
| Verifică că:
| 1. Schema nouă (coloane smartbill_sent_at + idempotency_key) este aplicată.
| 2. Funcția pz_smartbill_is_stuck_sending detectează corect facturi stuck.
| 3. UPDATE-ul atomic la „sending" funcționează idempotent în concurrent.
| 4. pz_smartbill_mark_manually_issued reconciliează corect.
| 5. pz_smartbill_reset_stuck_to_error eliberează lock-ul.
|
| ATENȚIE:
| - Scriptul folosește SAVEPOINT/ROLLBACK SQL — NU face apeluri reale la SmartBill.
| - Toate inserturile de test sunt rollback-uite la final.
| - Sigur de rulat pe producție.
|
| Cum se rulează:
|   php test_c2_stuck_invoice.php
|--------------------------------------------------------------------------
*/

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/smartbill_lib.php';

$isCli = (php_sapi_name() === 'cli');
function tprint(string $msg, bool $isCli): void
{
    echo $msg . ($isCli ? "\n" : "<br>\n");
}
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$sep = str_repeat('-', 60);
tprint("=== TEST C2 — Tranzacție SmartBill (concurrency + stuck)", $isCli);
tprint($sep, $isCli);

// ---------------------------------------------------------------
// PAS 0: ensure_schema rulează și coloanele noi sunt create
// ---------------------------------------------------------------
tprint("PAS 0: ensure_schema + verificare coloane noi...", $isCli);
pz_smartbill_ensure_schema($pdo);

$cols = ['smartbill_sent_at' => 'DATETIME', 'idempotency_key' => 'VARCHAR'];
foreach ($cols as $colName => $expectedTypePrefix) {
    $stmt = $pdo->query("SHOW COLUMNS FROM smartbill_invoices LIKE " . $pdo->quote($colName));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        tprint("  ✗ FAIL — coloana $colName lipsește din smartbill_invoices", $isCli);
        exit(1);
    }
    tprint("  ✓ Coloana $colName există (Type: {$row['Type']})", $isCli);
}
tprint("", $isCli);

// ---------------------------------------------------------------
// PAS 1: Creează o factură de test într-o tranzacție (rollback la final)
// ---------------------------------------------------------------
tprint("PAS 1: Creare factură test în tranzacție...", $isCli);
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("
        INSERT INTO smartbill_invoices
            (source_type, client_id, client_name, client_fiscal_code,
             invoice_date, due_date, currency,
             net_amount, vat_code, vat_amount, gross_amount,
             smartbill_status, notes)
        VALUES
            ('manual', 1, 'CLIENT TEST C2 - rollback la final', 'RO99999999',
             CURDATE(), CURDATE(), 'RON',
             100.00, '21', 19.00, 119.00,
             'draft', 'Test C2 — se șterge automat la final')
    ");
    $stmt->execute();
    $testInvoiceId = (int)$pdo->lastInsertId();
    tprint("  ✓ Creat invoice_id = $testInvoiceId (status=draft)", $isCli);
    tprint("", $isCli);

    // ---------------------------------------------------------------
    // TEST A: is_stuck_sending pe draft = false
    // ---------------------------------------------------------------
    tprint("TEST A: is_stuck_sending() pe draft trebuie să fie FALSE...", $isCli);
    $stmt = $pdo->prepare("SELECT * FROM smartbill_invoices WHERE id = ?");
    $stmt->execute([$testInvoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    $isStuck = pz_smartbill_is_stuck_sending($inv, 5);
    if ($isStuck === false) {
        tprint("  ✓ PASS", $isCli);
    } else {
        tprint("  ✗ FAIL — returned " . var_export($isStuck, true), $isCli);
        $pdo->rollBack();
        exit(1);
    }
    tprint("", $isCli);

    // ---------------------------------------------------------------
    // TEST B: Setează manual sending cu sent_at vechi → is_stuck_sending = true
    // ---------------------------------------------------------------
    tprint("TEST B: setez sending cu sent_at acum-10min, is_stuck_sending trebuie să fie TRUE...", $isCli);
    $pdo->prepare("
        UPDATE smartbill_invoices
        SET smartbill_status = 'sending',
            smartbill_sent_at = (NOW() - INTERVAL 10 MINUTE),
            idempotency_key = 'test_key_001'
        WHERE id = ?
    ")->execute([$testInvoiceId]);

    $stmt->execute([$testInvoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    $isStuck = pz_smartbill_is_stuck_sending($inv, 5);
    if ($isStuck === true) {
        tprint("  ✓ PASS — detectat ca stuck", $isCli);
    } else {
        tprint("  ✗ FAIL — returned " . var_export($isStuck, true), $isCli);
        $pdo->rollBack();
        exit(1);
    }
    tprint("", $isCli);

    // ---------------------------------------------------------------
    // TEST C: UPDATE atomic - simulez 2 apeluri concurente
    // (folosim aceleași condiții ca în pz_smartbill_issue_invoice)
    // ---------------------------------------------------------------
    tprint("TEST C: simulez 2 UPDATE-uri concurente pe sending stuck...", $isCli);

    // Primul apel: ar trebui să reușească (sending stuck > 5min)
    $upd1 = $pdo->prepare("
        UPDATE smartbill_invoices
        SET smartbill_status = 'sending',
            smartbill_sent_at = NOW(),
            idempotency_key = 'test_key_002a',
            error_message = NULL
        WHERE id = ?
          AND (smartbill_number IS NULL OR smartbill_number = '')
          AND (
                smartbill_status IN ('draft', 'error')
                OR (smartbill_status = 'sending' AND (smartbill_sent_at IS NULL OR smartbill_sent_at < (NOW() - INTERVAL 5 MINUTE)))
          )
    ");
    $upd1->execute([$testInvoiceId]);
    $rows1 = $upd1->rowCount();

    // Al doilea apel imediat: ar trebui să eșueze (sending fresh, < 5min)
    $upd2 = $pdo->prepare("
        UPDATE smartbill_invoices
        SET smartbill_status = 'sending',
            smartbill_sent_at = NOW(),
            idempotency_key = 'test_key_002b',
            error_message = NULL
        WHERE id = ?
          AND (smartbill_number IS NULL OR smartbill_number = '')
          AND (
                smartbill_status IN ('draft', 'error')
                OR (smartbill_status = 'sending' AND (smartbill_sent_at IS NULL OR smartbill_sent_at < (NOW() - INTERVAL 5 MINUTE)))
          )
    ");
    $upd2->execute([$testInvoiceId]);
    $rows2 = $upd2->rowCount();

    if ($rows1 === 1 && $rows2 === 0) {
        tprint("  ✓ PASS — primul UPDATE a afectat 1 rând, al doilea 0 rânduri (blocking funcționează)", $isCli);
    } else {
        tprint("  ✗ FAIL — primul: $rows1 rânduri, al doilea: $rows2 (așteptam 1, 0)", $isCli);
        $pdo->rollBack();
        exit(1);
    }
    tprint("", $isCli);

    // ---------------------------------------------------------------
    // TEST D: reset_stuck_to_error pe sending stuck
    // ---------------------------------------------------------------
    tprint("TEST D: reset_stuck_to_error pe factura stuck...", $isCli);
    // Întâi reseteaz manual sent_at la 10 min în trecut ca să fie stuck
    $pdo->prepare("UPDATE smartbill_invoices SET smartbill_sent_at = (NOW() - INTERVAL 10 MINUTE) WHERE id = ?")->execute([$testInvoiceId]);

    $result = pz_smartbill_reset_stuck_to_error($pdo, $testInvoiceId);
    if (!empty($result['ok'])) {
        $stmt->execute([$testInvoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv['smartbill_status'] === 'error' && $inv['smartbill_sent_at'] === null) {
            tprint("  ✓ PASS — status='error', sent_at=NULL", $isCli);
        } else {
            tprint("  ✗ FAIL — status={$inv['smartbill_status']}, sent_at={$inv['smartbill_sent_at']}", $isCli);
            $pdo->rollBack();
            exit(1);
        }
    } else {
        tprint("  ✗ FAIL — ", $isCli);
        tprint("    " . json_encode($result, JSON_UNESCAPED_UNICODE), $isCli);
        $pdo->rollBack();
        exit(1);
    }
    tprint("", $isCli);

    // ---------------------------------------------------------------
    // TEST E: mark_manually_issued pe factura după reset
    // ---------------------------------------------------------------
    tprint("TEST E: mark_manually_issued cu seria+număr custom...", $isCli);
    // Punem-o înapoi în sending stuck ca să testăm marcarea manuală
    $pdo->prepare("UPDATE smartbill_invoices SET smartbill_status='sending', smartbill_sent_at=(NOW() - INTERVAL 10 MINUTE) WHERE id = ?")->execute([$testInvoiceId]);

    $result = pz_smartbill_mark_manually_issued($pdo, $testInvoiceId, 'TST', 'C2-' . $testInvoiceId, 'https://test.example/inv');
    if (!empty($result['ok'])) {
        $stmt->execute([$testInvoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv['smartbill_status'] === 'issued'
            && $inv['smartbill_series'] === 'TST'
            && $inv['smartbill_number'] === 'C2-' . $testInvoiceId
            && $inv['smartbill_sent_at'] === null) {
            tprint("  ✓ PASS — status=issued, series=TST, number=C2-$testInvoiceId, sent_at=NULL", $isCli);
        } else {
            tprint("  ✗ FAIL — date neașteptate:", $isCli);
            tprint("    " . json_encode([
                'status' => $inv['smartbill_status'],
                'series' => $inv['smartbill_series'],
                'number' => $inv['smartbill_number'],
                'sent_at' => $inv['smartbill_sent_at'],
            ], JSON_UNESCAPED_UNICODE), $isCli);
            $pdo->rollBack();
            exit(1);
        }
    } else {
        tprint("  ✗ FAIL — " . json_encode($result, JSON_UNESCAPED_UNICODE), $isCli);
        $pdo->rollBack();
        exit(1);
    }
    tprint("", $isCli);

    // ---------------------------------------------------------------
    // TEST F: mark_manually_issued refuză când factura are deja serie+număr
    // ---------------------------------------------------------------
    tprint("TEST F: mark_manually_issued trebuie să refuze re-marcarea unei facturi deja emise...", $isCli);
    $result = pz_smartbill_mark_manually_issued($pdo, $testInvoiceId, 'TST2', 'C2-NEW');
    if (empty($result['ok']) && strpos((string)$result['error'], 'deja serie') !== false) {
        tprint("  ✓ PASS — corect refuzat: {$result['error']}", $isCli);
    } else {
        tprint("  ✗ FAIL — ar fi trebuit să refuze: " . json_encode($result, JSON_UNESCAPED_UNICODE), $isCli);
        $pdo->rollBack();
        exit(1);
    }
    tprint("", $isCli);

    // ---------------------------------------------------------------
    // ROLLBACK toate modificările
    // ---------------------------------------------------------------
    $pdo->rollBack();
    tprint("PAS FINAL: ROLLBACK aplicat — toate modificările de test au fost anulate.", $isCli);
    tprint("", $isCli);

    // Verifică post-rollback că factura test nu mai există
    $check = $pdo->prepare("SELECT COUNT(*) FROM smartbill_invoices WHERE id = ?");
    $check->execute([$testInvoiceId]);
    if ((int)$check->fetchColumn() === 0) {
        tprint("  ✓ Confirm: factura test (id=$testInvoiceId) nu mai există în DB.", $isCli);
    } else {
        tprint("  ⚠ ATENȚIE: factura test încă există! Șterge-o manual: DELETE FROM smartbill_invoices WHERE id = $testInvoiceId;", $isCli);
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    tprint("✗ EXCEPȚIE NEAȘTEPTATĂ: " . $e->getMessage(), $isCli);
    tprint("    " . $e->getFile() . ':' . $e->getLine(), $isCli);
    exit(1);
}

tprint("", $isCli);
tprint($sep, $isCli);
tprint("✅ TOATE TESTELE AU TRECUT — C2 (tranzacție SmartBill) este remediat.", $isCli);
tprint($sep, $isCli);
tprint("", $isCli);
tprint("Recomandare: șterge fișierul acesta:", $isCli);
tprint("  rm test_c2_stuck_invoice.php", $isCli);
