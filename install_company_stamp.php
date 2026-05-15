<?php
/**
 * Patcher Stampila Firmei pe PV
 * ----------------------------------------------------------------
 * Aplica automat modificarile in 4 fisiere CRM:
 *   - settings_lib.php
 *   - document_schema.php
 *   - document_core.php
 *   - document_design.php
 *
 * Cum se foloseste:
 *   1. Incarca acest fisier in radacina proiectului (langa config.php).
 *   2. Deschide in browser ca admin: https://domeniu.ro/install_company_stamp.php
 *   3. Apasa butonul "Aplica patch-urile".
 *   4. Sterge fisierul dupa ce ai verificat ca tot merge.
 *
 * Idempotent: rulat de mai multe ori, va sari peste patch-urile deja aplicate.
 * Backup automat: fiecare fisier modificat e salvat ca fisier.bak.YYYYMMDD_HHMMSS.
 */

require_once __DIR__ . '/config.php';

if (function_exists('require_login')) {
    require_login();
}
if (function_exists('is_admin') && !is_admin()) {
    http_response_code(403);
    exit('Doar administratorul poate rula acest patcher.');
}

$ROOT = __DIR__;

/**
 * Definitia patch-urilor.
 * Fiecare fisier are:
 *   - check: substring care, daca apare, inseamna ca patch-ul e deja aplicat
 *   - patches: lista de [find, replace]; toate trebuie sa potriveasca pentru a salva
 */
$PATCH_PLAN = [
    'settings_lib.php' => [
        'check' => "document.company_stamp_path",
        'patches' => [
            [
                "            'document.pv_body_font_size_pt' => '9.2',\n            'document.pv_line_height' => '1.18',\n        ];",
                "            'document.pv_body_font_size_pt' => '9.2',\n            'document.pv_line_height' => '1.18',\n            'document.company_stamp_path' => '',\n            'document.company_stamp_width_mm' => '36',\n            'document.company_stamp_height_mm' => '36',\n        ];",
            ],
        ],
    ],

    'document_schema.php' => [
        'check' => "apply_company_stamp",
        'patches' => [
            [
                "            'cancelled_at' => \"DATETIME NULL\",\n        ];",
                "            'cancelled_at' => \"DATETIME NULL\",\n            'apply_company_stamp' => \"TINYINT(1) NOT NULL DEFAULT 0\",\n        ];",
            ],
        ],
    ],

    'document_core.php' => [
        'check' => "':apply_company_stamp'",
        'patches' => [
            // INSERT: lista coloane
            [
                ", internal_notes, created_by",
                ", internal_notes, apply_company_stamp, created_by",
            ],
            // INSERT: lista placeholders
            [
                ", :internal_notes, :created_by",
                ", :internal_notes, :apply_company_stamp, :created_by",
            ],
            // INSERT: execute params
            [
                "            ':internal_notes' => pzdoc_str(\$data['internal_notes'] ?? null),\n            ':created_by' => pzdoc_current_user_id(),",
                "            ':internal_notes' => pzdoc_str(\$data['internal_notes'] ?? null),\n            ':apply_company_stamp' => (function_exists('is_team_user') && is_team_user()) || !empty(\$data['apply_company_stamp']) ? 1 : 0,\n            ':created_by' => pzdoc_current_user_id(),",
            ],
            // UPDATE: lista SET
            [
                "internal_notes = :internal_notes\n            WHERE id = :id",
                "internal_notes = :internal_notes,\n                apply_company_stamp = :apply_company_stamp\n            WHERE id = :id",
            ],
            // UPDATE: execute params (ancorat pe $document['internal_notes'] care exista doar in update)
            [
                "            ':internal_notes' => pzdoc_str(\$data['internal_notes'] ?? \$document['internal_notes'] ?? null),\n            ':id' => \$documentId,",
                "            ':internal_notes' => pzdoc_str(\$data['internal_notes'] ?? \$document['internal_notes'] ?? null),\n            ':apply_company_stamp' => array_key_exists('apply_company_stamp', \$data)\n                ? (!empty(\$data['apply_company_stamp']) ? 1 : 0)\n                : ((function_exists('is_team_user') && is_team_user()) ? 1 : (!empty(\$document['apply_company_stamp']) ? 1 : 0)),\n            ':id' => \$documentId,",
            ],
        ],
    ],

    'document_design.php' => [
        'check' => "company_stamp_path",
        'patches' => [
            // 4A: POST values + remove_stamp handling
            [
                "            'document.header_logo_path' => trim((string)(\$settings['document.header_logo_path'] ?? '')),\n        ];\n\n        if (!empty(\$_POST['remove_logo'])) {\n            \$values['document.header_logo_path'] = '';\n        }",
                "            'document.header_logo_path' => trim((string)(\$settings['document.header_logo_path'] ?? '')),\n            'document.company_stamp_path' => trim((string)(\$settings['document.company_stamp_path'] ?? '')),\n            'document.company_stamp_width_mm' => pzdd_num(\$_POST['stamp_width'] ?? '', 36, 18, 60),\n            'document.company_stamp_height_mm' => pzdd_num(\$_POST['stamp_height'] ?? '', 36, 18, 60),\n        ];\n\n        if (!empty(\$_POST['remove_logo'])) {\n            \$values['document.header_logo_path'] = '';\n        }\n\n        if (!empty(\$_POST['remove_stamp'])) {\n            \$values['document.company_stamp_path'] = '';\n        }",
            ],
            // 4B: handler upload stampila
            [
                "                    } else {\n                        \$values['document.header_logo_path'] = 'uploads/' . \$file;\n                        \$values['document.header_logo_enabled'] = '1';\n                    }\n                }\n            }\n        }\n    }\n\n    if (\$error === '') {",
                "                    } else {\n                        \$values['document.header_logo_path'] = 'uploads/' . \$file;\n                        \$values['document.header_logo_enabled'] = '1';\n                    }\n                }\n            }\n        }\n    }\n\n    // Upload stampila firmei (PNG cu fundal transparent recomandat)\n    if (isset(\$_FILES['stamp_file']) && is_array(\$_FILES['stamp_file']) && (int)(\$_FILES['stamp_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {\n        if ((int)\$_FILES['stamp_file']['error'] !== UPLOAD_ERR_OK) {\n            \$error = 'Stampila nu a putut fi incarcata.';\n        } elseif ((int)(\$_FILES['stamp_file']['size'] ?? 0) > 2 * 1024 * 1024) {\n            \$error = 'Stampila este prea mare. Maxim 2 MB.';\n        } else {\n            \$tmp = (string)\$_FILES['stamp_file']['tmp_name'];\n            \$ext = strtolower(pathinfo((string)\$_FILES['stamp_file']['name'], PATHINFO_EXTENSION));\n            if (!in_array(\$ext, ['png','jpg','jpeg','webp'], true) || !@getimagesize(\$tmp)) {\n                \$error = 'Format stampila invalid. Acceptat: PNG (cu fundal transparent), JPG, WEBP.';\n            } else {\n                \$dir = __DIR__ . '/uploads';\n                if (!is_dir(\$dir)) @mkdir(\$dir, 0755, true);\n                if (!is_writable(\$dir)) {\n                    \$error = 'Folderul uploads nu poate fi scris.';\n                } else {\n                    \$file = 'company_stamp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . (\$ext === 'jpeg' ? 'jpg' : \$ext);\n                    if (!move_uploaded_file(\$tmp, \$dir . '/' . \$file)) {\n                        \$error = 'Stampila nu a putut fi salvata.';\n                    } else {\n                        \$values['document.company_stamp_path'] = 'uploads/' . \$file;\n                    }\n                }\n            }\n        }\n    }\n\n    if (\$error === '') {",
            ],
            // 4C: variabile pentru form
            [
                "\$pvLineHeight = pzdd_num(\$settings['document.pv_line_height'] ?? 1.18, 1.18, 1.05, 1.55);",
                "\$pvLineHeight = pzdd_num(\$settings['document.pv_line_height'] ?? 1.18, 1.18, 1.05, 1.55);\n\$stampPath = trim((string)(\$settings['document.company_stamp_path'] ?? ''));\nif (\$stampPath !== '' && !is_file(__DIR__ . '/' . ltrim(\$stampPath, '/'))) { \$stampPath = ''; }\n\$stampWidth = pzdd_num(\$settings['document.company_stamp_width_mm'] ?? 36, 36, 18, 60);\n\$stampHeight = pzdd_num(\$settings['document.company_stamp_height_mm'] ?? 36, 36, 18, 60);",
            ],
            // 4D: sectiune noua in form
            [
                "                        <div class=\"dd-field full\"><span class=\"dd-help\">Pentru eliminarea spatiului gol din PV recomandat: margine sus 4 mm, spatiu antet 10 mm, footer PV debifat, font 9.2 pt si spatiere 1.18.</span></div>\n                    </div></div>\n                </form>",
                "                        <div class=\"dd-field full\"><span class=\"dd-help\">Pentru eliminarea spatiului gol din PV recomandat: margine sus 4 mm, spatiu antet 10 mm, footer PV debifat, font 9.2 pt si spatiere 1.18.</span></div>\n                    </div></div>\n\n                    <div class=\"dd-section\"><h3>Stampila firmei</h3><div class=\"dd-grid\">\n                        <div class=\"dd-field full\"><span class=\"dd-help\">Stampila incarcata aici apare langa semnatura emitent pe PV-uri (operatorii o primesc automat; admin o aplica cu butonul \"Adauga stampila\"). Recomandat: PNG cu fundal transparent, ~400x400px.</span></div>\n                        <div class=\"dd-field full\"><label>Imagine stampila</label><input type=\"file\" name=\"stamp_file\" accept=\"image/png,image/jpeg,image/webp\"><span class=\"dd-help\">Optional. Acceptat PNG, JPG, WEBP, maxim 2 MB.</span></div>\n                        <?php if (\$stampPath): ?>\n                            <div class=\"dd-field\"><label>Stampila curenta</label><div style=\"background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:10px;display:flex;align-items:center;justify-content:center;min-height:80px;\"><img src=\"<?= pzdd_h(\$stampPath) ?>\" alt=\"Stampila\" style=\"max-width:90px;max-height:90px;object-fit:contain;\"></div></div>\n                            <div class=\"dd-field\"><label>&nbsp;</label><label class=\"dd-check\"><input type=\"checkbox\" name=\"remove_stamp\" value=\"1\"> Sterge stampila curenta</label></div>\n                        <?php endif; ?>\n                        <div class=\"dd-field\"><label>Latime stampila, mm</label><input name=\"stamp_width\" type=\"number\" step=\"0.5\" min=\"18\" max=\"60\" value=\"<?= pzdd_h(\$stampWidth) ?>\"></div>\n                        <div class=\"dd-field\"><label>Inaltime stampila, mm</label><input name=\"stamp_height\" type=\"number\" step=\"0.5\" min=\"18\" max=\"60\" value=\"<?= pzdd_h(\$stampHeight) ?>\"></div>\n                    </div></div>\n                </form>",
            ],
        ],
    ],
];

/**
 * Aplica un singur patch (find/replace) pe continut.
 * Returneaza: ['ok'=>bool, 'msg'=>string, 'content'=>string|null]
 */
function patch_apply_one(string $content, string $find, string $replace): array {
    $count = 0;
    $newContent = '';
    $pos = strpos($content, $find);
    if ($pos === false) {
        return ['ok' => false, 'msg' => 'Pattern negasit', 'content' => null];
    }
    // Verificam ca apare o singura data ca sa evitam ambiguitati
    $second = strpos($content, $find, $pos + 1);
    if ($second !== false) {
        return ['ok' => false, 'msg' => 'Pattern apare de mai multe ori — necesita interventie manuala', 'content' => null];
    }
    $newContent = substr_replace($content, $replace, $pos, strlen($find));
    return ['ok' => true, 'msg' => 'OK', 'content' => $newContent];
}

/**
 * Cauta fisierul in radacina sau in subfoldere comune.
 */
function find_file(string $root, string $name): ?string {
    $candidates = [
        $root . '/' . $name,
        $root . '/lib/' . $name,
        $root . '/includes/' . $name,
        $root . '/src/' . $name,
        $root . '/app/' . $name,
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) return $c;
    }
    // Cautare 1 nivel mai jos
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
        $maybe = $sub . '/' . $name;
        if (is_file($maybe)) return $maybe;
    }
    return null;
}

$report = [];
$run = isset($_POST['run']) && $_POST['run'] === '1';

if ($run) {
    foreach ($PATCH_PLAN as $filename => $plan) {
        $entry = ['file' => $filename, 'path' => null, 'status' => 'NEPATCHAT', 'details' => []];
        $path = find_file($ROOT, $filename);
        if (!$path) {
            $entry['status'] = 'FISIER NEGASIT';
            $entry['details'][] = 'Cautat in: ' . $ROOT . ', ' . $ROOT . '/lib, ' . $ROOT . '/includes, ' . $ROOT . '/src, ' . $ROOT . '/app si in subfoldere directe.';
            $report[] = $entry;
            continue;
        }
        $entry['path'] = $path;
        $content = @file_get_contents($path);
        if ($content === false) {
            $entry['status'] = 'EROARE CITIRE';
            $report[] = $entry;
            continue;
        }
        if (strpos($content, $plan['check']) !== false) {
            $entry['status'] = 'DEJA APLICAT';
            $entry['details'][] = 'Marker "' . $plan['check'] . '" deja prezent. Sarit.';
            $report[] = $entry;
            continue;
        }

        // Aplica toate patch-urile in memorie; daca vreunul esueaza, NU salvam
        $newContent = $content;
        $allOk = true;
        foreach ($plan['patches'] as $i => $patch) {
            [$find, $replace] = $patch;
            $res = patch_apply_one($newContent, $find, $replace);
            if (!$res['ok']) {
                $entry['details'][] = 'Patch #' . ($i + 1) . ': ' . $res['msg'];
                $allOk = false;
                break;
            }
            $newContent = $res['content'];
            $entry['details'][] = 'Patch #' . ($i + 1) . ': aplicat';
        }

        if (!$allOk) {
            $entry['status'] = 'ESUAT';
            $report[] = $entry;
            continue;
        }

        // Backup
        $backup = $path . '.bak.' . date('Ymd_His');
        if (!@copy($path, $backup)) {
            $entry['status'] = 'EROARE BACKUP';
            $entry['details'][] = 'Nu am putut crea backup: ' . $backup;
            $report[] = $entry;
            continue;
        }
        $entry['details'][] = 'Backup creat: ' . basename($backup);

        // Scriere
        if (@file_put_contents($path, $newContent) === false) {
            $entry['status'] = 'EROARE SCRIERE';
            $entry['details'][] = 'Nu am putut scrie ' . $path;
            $report[] = $entry;
            continue;
        }

        $entry['status'] = 'OK';
        $report[] = $entry;
    }

    // Migrare schema (adauga coloana apply_company_stamp).
    // Strategie:
    //   1) Daca putem incarca document_schema.php / document_core.php, folosim functia oficiala.
    //   2) Altfel, executam direct ALTER TABLE pe PDO existent (fail-safe daca coloana exista deja).
    $schemaResult = ['status' => 'NERULAT', 'details' => []];
    try {
        // Incearca sa incarce fisierele care definesc functia
        if (!function_exists('pzdoc_install_document_schema')) {
            $loaderCandidates = ['document_schema.php', 'document_core.php'];
            foreach ($loaderCandidates as $loaderName) {
                $loaderPath = find_file($ROOT, $loaderName);
                if ($loaderPath) {
                    @require_once $loaderPath;
                    $schemaResult['details'][] = 'Inclus: ' . $loaderName;
                    if (function_exists('pzdoc_install_document_schema')) {
                        break;
                    }
                }
            }
        }

        if (function_exists('pzdoc_install_document_schema')) {
            pzdoc_install_document_schema($pdo);
            $schemaResult['status'] = 'OK';
            $schemaResult['details'][] = 'pzdoc_install_document_schema() rulat cu succes — coloana apply_company_stamp adaugata daca lipsea.';
        } else {
            // Fallback: ALTER TABLE direct
            $schemaResult['details'][] = 'Functia pzdoc_install_document_schema nu este disponibila — folosesc ALTER TABLE direct.';

            // Verifica daca PDO e disponibil
            if (!isset($pdo) || !($pdo instanceof PDO)) {
                throw new RuntimeException('PDO nu este disponibil in scope-ul global. Verifica config.php.');
            }

            // Verifica daca coloana exista deja
            $existsStmt = $pdo->prepare("\n                SELECT COUNT(*) AS cnt\n                FROM INFORMATION_SCHEMA.COLUMNS\n                WHERE TABLE_SCHEMA = DATABASE()\n                  AND TABLE_NAME = 'documents'\n                  AND COLUMN_NAME = 'apply_company_stamp'\n            ");
            $existsStmt->execute();
            $exists = (int)($existsStmt->fetchColumn() ?: 0) > 0;

            if ($exists) {
                $schemaResult['status'] = 'OK';
                $schemaResult['details'][] = 'Coloana apply_company_stamp exista deja in documents.';
            } else {
                $pdo->exec("ALTER TABLE documents ADD COLUMN apply_company_stamp TINYINT(1) NOT NULL DEFAULT 0");
                $schemaResult['status'] = 'OK';
                $schemaResult['details'][] = 'ALTER TABLE rulat — coloana apply_company_stamp adaugata in tabela documents.';
            }
        }
    } catch (Throwable $e) {
        $schemaResult['status'] = 'EROARE';
        $schemaResult['details'][] = $e->getMessage();
    }
    $report['__schema__'] = $schemaResult;
}

function statusColor(string $s): string {
    if ($s === 'OK') return '#047857';
    if ($s === 'DEJA APLICAT') return '#1d4ed8';
    if (in_array($s, ['ESUAT','EROARE BACKUP','EROARE SCRIERE','EROARE CITIRE','FISIER NEGASIT','EROARE'], true)) return '#b91c1c';
    return '#64748b';
}
?>
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title>Patcher Stampila Firmei</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; max-width: 880px; margin: 24px auto; padding: 0 18px; color: #0f172a; background: #f8fafc; }
h1 { font-size: 22px; margin: 0 0 6px; }
p.lead { color: #475569; margin: 0 0 18px; }
.card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; box-shadow: 0 1px 2px rgba(15,23,42,.04); margin-bottom: 14px; }
.btn { display: inline-block; padding: 10px 18px; border-radius: 10px; border: 1px solid #4f46e5; background: #4f46e5; color: #fff; font-weight: 700; cursor: pointer; text-decoration: none; }
.btn.ghost { background: #fff; color: #4f46e5; }
.muted { color: #64748b; font-size: 13px; }
table { width: 100%; border-collapse: collapse; font-size: 13.5px; margin-top: 10px; }
th, td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
th { color: #475569; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.status { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; }
ul.det { margin: 4px 0 0 18px; padding: 0; font-size: 12px; color: #475569; }
ul.det li { margin: 2px 0; }
code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; font-family: ui-monospace, Menlo, monospace; font-size: 12px; }
.warn { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; padding: 12px 14px; border-radius: 10px; margin-bottom: 14px; }
</style>
</head>
<body>
<h1>Patcher Stampila Firmei pe PV</h1>
<p class="lead">Aplica modificarile in 4 fisiere CRM pentru stampila firmei pe procese verbale.</p>

<?php if (!$run): ?>
    <div class="warn">
        <strong>Inainte de a rula:</strong> verifica ca ai backup la baza de date si la fisiere. Patcher-ul face automat backup la fiecare fisier (<code>.bak.YYYYMMDD_HHMMSS</code>), dar pe responsabilitatea ta.
    </div>
    <div class="card">
        <h2 style="margin-top:0;font-size:16px;">Ce face acest patcher</h2>
        <ol style="margin:0;padding-left:20px;color:#334155;">
            <li>Cauta cele 4 fisiere in radacina si subfoldere comune.</li>
            <li>Verifica daca patch-ul e deja aplicat (sare peste).</li>
            <li>Face backup la fiecare fisier modificat.</li>
            <li>Aplica modificarile.</li>
            <li>Ruleaza migrarea schema (adauga coloana <code>apply_company_stamp</code>).</li>
        </ol>
        <h3 style="font-size:14px;margin-top:14px;">Fisiere afectate:</h3>
        <ul style="margin:0;padding-left:20px;color:#334155;">
            <li><code>settings_lib.php</code> — defaults pentru stampila</li>
            <li><code>document_schema.php</code> — coloana noua in tabela documents</li>
            <li><code>document_core.php</code> — INSERT/UPDATE includ flag-ul</li>
            <li><code>document_design.php</code> — UI upload stampila</li>
        </ul>
        <p class="muted" style="margin-top:14px;">
            <strong>Nu uita:</strong> dupa rulare, sterge acest fisier de pe server.
        </p>
        <form method="post" style="margin-top:16px;">
            <input type="hidden" name="run" value="1">
            <button class="btn" type="submit">Aplica patch-urile</button>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:16px;">Raport executie</h2>
        <table>
            <thead>
                <tr><th>Fisier</th><th>Status</th><th>Detalii</th></tr>
            </thead>
            <tbody>
            <?php foreach ($report as $key => $r): ?>
                <?php if ($key === '__schema__'): ?>
                    <tr style="background:#f8fafc;">
                        <td><strong>Migrare schema DB</strong></td>
                        <td><span class="status" style="background:<?= statusColor($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                        <td>
                            <?php if ($r['details']): ?>
                                <ul class="det">
                                    <?php foreach ($r['details'] as $d): ?>
                                        <li><?= htmlspecialchars($d) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($r['file']) ?></strong>
                            <?php if ($r['path']): ?>
                                <div class="muted"><?= htmlspecialchars($r['path']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="status" style="background:<?= statusColor($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                        <td>
                            <?php if ($r['details']): ?>
                                <ul class="det">
                                    <?php foreach ($r['details'] as $d): ?>
                                        <li><?= htmlspecialchars($d) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="font-size:14px;margin-top:18px;">Pasii urmatori</h3>
        <ol style="margin:0;padding-left:20px;color:#334155;">
            <li>Mergi in <strong>Setari → Design documente</strong>, scroll la "Stampila firmei", incarca PNG-ul cu stampila si salveaza.</li>
            <li>Deschide un PV ca admin → click <strong>"Adauga stampila"</strong> in toolbar → click "PDF" pentru verificare.</li>
            <li>Daca vreun fisier are status <strong>ESUAT</strong> sau <strong>FISIER NEGASIT</strong>, aplica patch-ul manual din <code>STAMPILA_PATCH.md</code>.</li>
            <li><strong>Sterge</strong> acest fisier (<code>install_company_stamp.php</code>) de pe server.</li>
        </ol>

        <p style="margin-top:16px;"><a class="btn ghost" href="?">Inapoi</a></p>
    </div>
<?php endif; ?>

</body>
</html>
