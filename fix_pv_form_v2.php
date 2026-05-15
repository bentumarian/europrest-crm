<?php
/**
 * FIX PV form v2 — 5 modificari cerute de user
 * ----------------------------------------------------------------
 * 1) Ascunde fieldul "Preia date din programare" cand nu sunt programari;
 *    cand exista, "PV de la zero/fara programare" devine "Fara programare"
 * 2) Centreaza textul in tabelul materiale/biocide (header + celule)
 * 3) DILUTIE → DILUTIE (%) in capul de tabel materiale
 * 4) Operator-ul DDD din PV se preia automat ca {{company_representative}}
 *    + nou token {{operator_ddd}}
 * 5) Serviciile prestate [DERATIZARE] etc. — fara bold, doar uppercase brackets
 */

require_once __DIR__ . '/config.php';
if (function_exists('require_login')) require_login();
if (function_exists('is_admin') && !is_admin()) { http_response_code(403); exit('Doar admin.'); }

$ROOT = __DIR__;

$PATCHES = [
    /* ============================================================
       PATCH 1: procese_verbale.php — ascunde appointment select
       ============================================================ */
    [
        'file' => 'procese_verbale.php',
        'name' => '1) Ascunde "Preia date din programare" daca nu sunt programari',
        'find' => "                                        <div class=\"field full\">\n                                            <label>Preia date din programare</label>\n                                            <select name=\"appointment_id\" id=\"appointmentSelect\" data-selected=\"<?= (int)(\$formDocument['appointment_id'] ?? \$selectedAppointmentId ?? 0) ?>\">\n                                                <option value=\"\">PV de la zero / fara programare</option>",
        'replace' => "                                        <?php if (!empty(\$appointments)): ?>\n                                        <div class=\"field full\">\n                                            <label>Preia date din programare</label>\n                                            <select name=\"appointment_id\" id=\"appointmentSelect\" data-selected=\"<?= (int)(\$formDocument['appointment_id'] ?? \$selectedAppointmentId ?? 0) ?>\">\n                                                <option value=\"\">Fara programare</option>",
    ],
    [
        'file' => 'procese_verbale.php',
        'name' => '1b) Inchide PHP-if pentru appointment field',
        'find' => "                                            <div class=\"client-help\">Optional. Daca alegi o programare, clientul, locatia, data si ora se completeaza automat.</div>\n                                        </div>\n                                        <div class=\"field\">\n                                            <label>Data PV</label>",
        'replace' => "                                            <div class=\"client-help\">Optional. Daca alegi o programare, clientul, locatia, data si ora se completeaza automat.</div>\n                                        </div>\n                                        <?php endif; ?>\n                                        <div class=\"field\">\n                                            <label>Data PV</label>",
    ],

    /* ============================================================
       PATCH 2 & 3: document_tokens.php — materials table styling
       ============================================================ */
    [
        'file' => 'document_tokens.php',
        'name' => '2) Centreaza celulele in tabelul materiale',
        'find' => "            .pzdoc-table th{background:#f3f4f6;border:1px solid #d1d5db;padding:5px 6px;text-align:left;font-weight:bold;}\n            .pzdoc-table td{border:1px solid #d1d5db;padding:5px 6px;vertical-align:top;}",
        'replace' => "            .pzdoc-table th{background:#f3f4f6;border:1px solid #d1d5db;padding:5px 6px;text-align:left;font-weight:bold;}\n            .pzdoc-table td{border:1px solid #d1d5db;padding:5px 6px;vertical-align:top;}\n            .pzdoc-materials-table th,.pzdoc-materials-table td{text-align:center !important;vertical-align:middle !important;}",
    ],
    [
        'file' => 'document_tokens.php',
        'name' => '3) DILUTIE → DILUTIE (%) in capul tabelului',
        'find' => "\$html .= '<th style=\"width:11%;\">DILUTIE</th>';",
        'replace' => "\$html .= '<th style=\"width:11%;\">DILUTIE (%)</th>';",
    ],

    /* ============================================================
       PATCH 5: document_tokens.php — fara bold pe serviciile [...]
       ============================================================ */
    [
        'file' => 'document_tokens.php',
        'name' => '5) Servicii prestate — fara <strong>',
        'find' => "                \$parts[] = '<strong style=\"white-space:nowrap;\">[' . pzdoc_h(\$label) . ']</strong>';",
        'replace' => "                \$parts[] = '<span style=\"white-space:nowrap;\">[' . pzdoc_h(\$label) . ']</span>';",
    ],

    /* ============================================================
       PATCH 4: document_tokens.php — operator_ddd token + override
       ============================================================ */
    [
        'file' => 'document_tokens.php',
        'name' => '4) Token {{operator_ddd}} + override {{company_representative}} pentru PV',
        'find' => "            'company_representative' => pzdoc_token_text(\$company['representative_name'] ?? ''),",
        'replace' => "            // PZ_PV_OPERATOR: pentru PV, daca avem operatori (workers_names) → ei devin reprezentant prestator.\n            'company_representative' => pzdoc_token_text(\n                (function () use (\$document, \$company) {\n                    \$type = function_exists('pzdoc_normalize_document_type')\n                        ? pzdoc_normalize_document_type((string)(\$document['document_type'] ?? ''))\n                        : (string)(\$document['document_type'] ?? '');\n                    if (\$type === 'proces_verbal') {\n                        \$payload = is_array(\$document['payload'] ?? null) ? \$document['payload'] : (function_exists('pzdoc_json_decode') ? pzdoc_json_decode(\$document['payload_json'] ?? null) : []);\n                        \$workers = trim((string)((\$payload['workers_names'] ?? '')));\n                        if (\$workers !== '') { return \$workers; }\n                    }\n                    return (string)(\$company['representative_name'] ?? '');\n                })()\n            ),\n            'operator_ddd' => pzdoc_token_text(\n                (function () use (\$document) {\n                    \$payload = is_array(\$document['payload'] ?? null) ? \$document['payload'] : (function_exists('pzdoc_json_decode') ? pzdoc_json_decode(\$document['payload_json'] ?? null) : []);\n                    return trim((string)(\$payload['workers_names'] ?? ''));\n                })()\n            ),",
    ],
    [
        'file' => 'document_tokens.php',
        'name' => '4b) Inregistreaza {{operator_ddd}} in lista de tokens disponibili',
        'find' => "                '{{company_representative}}', '{{company_representative_role}}', '{{company_authorizations}}', '{{company_provider_role}}',",
        'replace' => "                '{{company_representative}}', '{{company_representative_role}}', '{{company_authorizations}}', '{{company_provider_role}}', '{{operator_ddd}}',",
    ],
];

/* ============================================================
   APLICARE PATCH-URI
   ============================================================ */

function backup(string $path): ?string {
    if (!is_file($path)) return null;
    $bak = $path . '.bak.' . date('Ymd_His');
    return @copy($path, $bak) ? $bak : null;
}

function apply_patch(string $rootDir, array $patch): array {
    $path = $rootDir . '/' . $patch['file'];
    if (!is_file($path)) {
        return ['ok' => false, 'msg' => 'Fisier inexistent'];
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        return ['ok' => false, 'msg' => 'Nu pot citi'];
    }

    // Deja aplicat?
    if (strpos($content, $patch['replace']) !== false && strpos($content, $patch['find']) === false) {
        return ['ok' => true, 'msg' => 'Deja aplicat', 'skip' => true];
    }

    if (strpos($content, $patch['find']) === false) {
        return ['ok' => false, 'msg' => 'Pattern negasit'];
    }

    if (substr_count($content, $patch['find']) > 1) {
        return ['ok' => false, 'msg' => 'Pattern apare de mai multe ori — manual'];
    }

    $newContent = str_replace($patch['find'], $patch['replace'], $content);
    $bak = backup($path);
    if (!$bak) return ['ok' => false, 'msg' => 'Backup esuat'];
    if (@file_put_contents($path, $newContent) === false) return ['ok' => false, 'msg' => 'Scriere esuata'];
    return ['ok' => true, 'msg' => 'OK. Backup: ' . basename($bak)];
}

$run = isset($_POST['run']) && $_POST['run'] === '1';
$report = [];

if ($run) {
    // Backup unique per fisier (sa nu facem 5 backup-uri pentru document_tokens.php)
    $backedUp = [];
    foreach ($PATCHES as $p) {
        $path = $ROOT . '/' . $p['file'];
        if (!isset($backedUp[$path]) && is_file($path)) {
            $bak = backup($path);
            $backedUp[$path] = $bak;
        }
    }

    // Aplica fiecare patch (fara sa mai faca backup intern — deja avem unul)
    foreach ($PATCHES as $p) {
        $r = apply_patch($ROOT, $p);
        $r['name'] = $p['name'];
        $r['file'] = $p['file'];
        $report[] = $r;
    }
}

function color($r) {
    if (!empty($r['skip'])) return '#1d4ed8';
    return !empty($r['ok']) ? '#047857' : '#b91c1c';
}
function label($r) {
    if (!empty($r['skip'])) return 'DEJA APLICAT';
    return !empty($r['ok']) ? 'OK' : 'ESUAT';
}
?>
<!doctype html>
<html lang="ro"><head><meta charset="utf-8"><title>Fix PV form — 5 modificari</title>
<style>
body{font-family:-apple-system,Arial;max-width:920px;margin:24px auto;padding:0 18px;color:#0f172a;background:#f8fafc}
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

<h1>Fix PV form — 5 modificari</h1>
<p style="color:#64748b">Backup automat la fiecare fisier modificat.</p>

<?php if (!$run): ?>
    <div class="warn">
        <strong>Modificari aplicate:</strong>
        <ol>
            <li>Ascunde "Preia date din programare" daca nu exista programari (cand exista, optiunea default devine "Fara programare")</li>
            <li>Centreaza textul in tabelul materiale (header + celule)</li>
            <li>Coloana "DILUTIE" devine "DILUTIE (%)"</li>
            <li>Operatorii din PV ({{workers_names}}) devin automat <strong>reprezentant prestator</strong> in PDF + token nou <code>{{operator_ddd}}</code></li>
            <li>Serviciile prestate <code>[DERATIZARE]</code> — fara bold, doar uppercase cu paranteze</li>
        </ol>
    </div>
    <div class="card">
        <form method="post"><input type="hidden" name="run" value="1"><button class="btn" type="submit">Aplica fix-urile</button></form>
    </div>
<?php else: ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:16px">Raport</h2>
        <table>
            <thead><tr><th>Modificare</th><th>Fisier</th><th>Status</th><th>Detalii</th></tr></thead>
            <tbody>
            <?php foreach ($report as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><code><?= htmlspecialchars($r['file']) ?></code></td>
                    <td><span class="status" style="background:<?= color($r) ?>"><?= label($r) ?></span></td>
                    <td><?= htmlspecialchars($r['msg']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:16px"><strong>Test:</strong></p>
        <ol>
            <li>Click <strong>+ PV nou</strong> → daca nu sunt programari deschise, fieldul "Preia date" e ascuns</li>
            <li>Adauga 2-3 materiale biocide → genereaza PDF → verifica centrare in toate celulele + header "DILUTIE (%)"</li>
            <li>Verifica ca operatorul completat la "Operatori/executanti" apare ca reprezentant prestator (in sablonul tau, {{company_representative}} sau {{operator_ddd}})</li>
            <li>Verifica ca <code>[DERATIZARE]</code> nu mai apare bold in PDF</li>
            <li>Sterge fisierul de pe server</li>
        </ol>

        <p><a class="btn ghost" href="?">Inapoi</a></p>
    </div>
<?php endif; ?>

</body></html>
