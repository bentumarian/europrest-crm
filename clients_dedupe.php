<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

$isAdmin = is_admin();
if (!$isAdmin) { header('Location: clients.php'); exit; }

function cd_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Normalizare strictă pentru compararea numelor de reprezentanți.
 * Lowercase + trim + elimină diacritice + elimină punctuație + sortează cuvintele alfabetic.
 * Astfel „Ion Popescu" = „Popescu Ion" = „POPESCU, ION" = „ion-popescu".
 */
function cd_normalize_name(string $name): string {
    $s = trim($name);
    if ($s === '') return '';
    // Elimină diacritice (â/ă/î/ș/ț + alte)
    $diacritics = [
        'ă' => 'a', 'â' => 'a', 'Ă' => 'a', 'Â' => 'a',
        'î' => 'i', 'Î' => 'i',
        'ș' => 's', 'ş' => 's', 'Ș' => 's', 'Ş' => 's',
        'ț' => 't', 'ţ' => 't', 'Ț' => 't', 'Ţ' => 't',
    ];
    $s = strtr($s, $diacritics);
    $s = mb_strtolower($s, 'UTF-8');
    // Înlocuiește orice non-literă (semne, cifre, punctuație) cu spațiu
    $s = preg_replace('/[^a-z\s]+/u', ' ', $s);
    // Multiple spații → single space
    $s = preg_replace('/\s+/', ' ', $s);
    $s = trim($s);
    if ($s === '') return '';
    $parts = explode(' ', $s);
    sort($parts, SORT_STRING);
    return implode(' ', $parts);
}

/**
 * Normalizare email pentru comparare: lowercase + trim.
 * Email-urile invalide după trim sunt tratate la nivel de validator (vezi cd_field_validator).
 */
function cd_normalize_email(string $email): string {
    $s = trim($email);
    if ($s === '') return '';
    return mb_strtolower($s, 'UTF-8');
}

/**
 * Normalizare strictă pentru comparare numere de telefon (RO).
 * Scoate orice non-cifră, apoi normalizează prefixele „+40" / „0040" / „40" la „0".
 * Astfel „+40 721 123 456" = „0721-123-456" = „0040 721 123 456" = „0721123456".
 */
function cd_normalize_phone(string $phone): string {
    $s = trim($phone);
    if ($s === '') return '';
    // Păstrează doar cifrele
    $s = preg_replace('/\D+/', '', $s);
    if ($s === '') return '';
    // 0040... (13 cifre) → 0...
    if (strlen($s) >= 12 && substr($s, 0, 4) === '0040') {
        $s = '0' . substr($s, 4);
    }
    // 40... (11 cifre, venit din +40) → 0...
    if (strlen($s) === 11 && substr($s, 0, 2) === '40') {
        $s = '0' . substr($s, 2);
    }
    return $s;
}

/**
 * Validator per câmp pentru valoarea propusă (înainte de UPDATE).
 * Returnează false dacă valoarea nu trebuie scrisă în BD.
 */
function cd_field_validator(string $field, string $value): bool {
    if ($value === '') return false;
    if ($field === 'email') {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
    return true;
}

/**
 * Configurația modurilor de grupare.
 * Fiecare mod definește:
 *  - cheia după care grupăm (key_field) și normalizatorul (normalizer)
 *  - câmpurile care pot fi propagate (propagate) în firmele unde lipsesc
 *  - filtrul SQL pentru SELECT (nu aducem rânduri fără cheia respectivă)
 *  - meta pentru UI (label, descriere, coloane afișate)
 */
function cd_modes(): array {
    return [
        'rep' => [
            'label'        => 'Reprezentant',
            'key_field'    => 'legal_representative_name',
            'normalizer'   => 'cd_normalize_name',
            'propagate'    => ['phone', 'email'],
            'sql_filter'   => "legal_representative_name IS NOT NULL AND TRIM(legal_representative_name) <> ''",
            'sql_order'    => "legal_representative_name ASC, name ASC",
            'description'  => 'Identifică firmele cu același reprezentant legal și completează telefonul/emailul lipsă pe baza fișelor unde aceste date există.',
            'key_label'    => 'Reprezentant',
            'extra_cols'   => ['phone' => 'Telefon', 'email' => 'Email'],
        ],
        'email' => [
            'label'        => 'Email',
            'key_field'    => 'email',
            'normalizer'   => 'cd_normalize_email',
            'propagate'    => ['phone', 'legal_representative_name'],
            'sql_filter'   => "email IS NOT NULL AND TRIM(email) <> ''",
            'sql_order'    => "email ASC, name ASC",
            'description'  => 'Identifică firmele cu același email și completează telefonul / numele reprezentantului lipsă pe baza fișelor unde aceste date există.',
            'key_label'    => 'Email',
            'extra_cols'   => ['legal_representative_name' => 'Reprezentant', 'phone' => 'Telefon'],
        ],
        'phone' => [
            'label'        => 'Telefon',
            'key_field'    => 'phone',
            'normalizer'   => 'cd_normalize_phone',
            'propagate'    => ['email', 'legal_representative_name'],
            'sql_filter'   => "phone IS NOT NULL AND TRIM(phone) <> ''",
            'sql_order'    => "phone ASC, name ASC",
            'description'  => 'Identifică firmele cu același număr de telefon și completează emailul / numele reprezentantului lipsă pe baza fișelor unde aceste date există.',
            'key_label'    => 'Telefon',
            'extra_cols'   => ['legal_representative_name' => 'Reprezentant', 'email' => 'Email'],
        ],
    ];
}

function cd_field_label(string $field): string {
    switch ($field) {
        case 'phone': return 'telefon';
        case 'email': return 'email';
        case 'legal_representative_name': return 'reprezentant';
    }
    return $field;
}

/**
 * Pentru un grup de clienți și o listă de câmpuri propagabile, returnează
 *   ['values' => [field => [val,...]], 'missing' => [field => [id,...]]]
 */
function cd_collect_group_state(array $clients, array $fields): array {
    $out = ['values' => [], 'missing' => []];
    foreach ($fields as $f) {
        $vals = array_unique(array_filter(array_map(static function($r) use ($f) {
            return trim((string)($r[$f] ?? ''));
        }, $clients), static fn($v) => $v !== ''));
        $missing = array_filter($clients, static fn($r) => trim((string)($r[$f] ?? '')) === '');
        $out['values'][$f] = array_values($vals);
        $out['missing'][$f] = array_values(array_map(static fn($r) => (int)$r['id'], $missing));
    }
    return $out;
}

/*
|--------------------------------------------------------------------------
| Selecție mod activ
|--------------------------------------------------------------------------
*/
$modes = cd_modes();
$mode = (string)($_GET['mode'] ?? $_POST['mode'] ?? 'rep');
if (!isset($modes[$mode])) $mode = 'rep';
$modeCfg = $modes[$mode];

/*
|--------------------------------------------------------------------------
| POST handler: aplicare propuneri
|--------------------------------------------------------------------------
*/
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'apply_group') {
            // Aplicăm o singură propunere de grup pentru modul curent.
            // POST: group_<field> (valoarea de copiat), targets_<field>[] (ids unde câmpul e gol).
            $updates = 0;
            foreach ($modeCfg['propagate'] as $field) {
                $value = trim((string)($_POST['group_' . $field] ?? ''));
                $targets = array_values(array_filter(array_map('intval', (array)($_POST['targets_' . $field] ?? []))));
                if ($value === '' || empty($targets)) continue;
                if (!cd_field_validator($field, $value)) continue;
                $in = implode(',', array_fill(0, count($targets), '?'));
                $params = array_merge([$value], $targets);
                $stmt = $pdo->prepare("UPDATE clients SET {$field} = ? WHERE id IN ($in) AND ({$field} IS NULL OR {$field} = '')");
                $stmt->execute($params);
                $updates += $stmt->rowCount();
            }
            $flashSuccess = "Aplicat. $updates fișe de client actualizate.";
        } elseif ($action === 'apply_all_safe') {
            // Aplică automat doar grupurile FĂRĂ conflicte pe modul curent.
            $cols = "id, name, fiscal_code, legal_representative_name, phone, email";
            $stmt = $pdo->query("SELECT $cols FROM clients WHERE active = 1 AND {$modeCfg['sql_filter']}");
            $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $normalizer = $modeCfg['normalizer'];
            $keyField = $modeCfg['key_field'];
            $groups = [];
            foreach ($all as $c) {
                $key = $normalizer((string)$c[$keyField]);
                if ($key === '') continue;
                $groups[$key][] = $c;
            }
            $totalUpdated = 0;
            foreach ($groups as $key => $clients) {
                if (count($clients) < 2) continue;
                $state = cd_collect_group_state($clients, $modeCfg['propagate']);
                // Skip dacă există vreun conflict
                $hasConflict = false;
                foreach ($modeCfg['propagate'] as $f) {
                    if (count($state['values'][$f]) > 1) { $hasConflict = true; break; }
                }
                if ($hasConflict) continue;
                foreach ($modeCfg['propagate'] as $f) {
                    if (count($state['values'][$f]) !== 1) continue;
                    $val = $state['values'][$f][0];
                    if (!cd_field_validator($f, $val)) continue;
                    $targets = $state['missing'][$f];
                    if (empty($targets)) continue;
                    $in = implode(',', array_fill(0, count($targets), '?'));
                    $params = array_merge([$val], $targets);
                    $stmt = $pdo->prepare("UPDATE clients SET {$f} = ? WHERE id IN ($in) AND ({$f} IS NULL OR {$f} = '')");
                    $stmt->execute($params);
                    $totalUpdated += $stmt->rowCount();
                }
            }
            $flashSuccess = "Aplicat în masă. $totalUpdated câmpuri completate automat.";
        } elseif ($action === 'download_backup') {
            // Generăm CSV cu starea curentă a tuturor clienților activi (independent de mod)
            $stmt = $pdo->query("SELECT id, name, fiscal_code, legal_representative_name, phone, email FROM clients WHERE active = 1 ORDER BY id ASC");
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="clients_backup_' . date('Y-m-d_His') . '.csv"');
            $out = fopen('php://output', 'w');
            // BOM pentru Excel
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'name', 'fiscal_code', 'legal_representative_name', 'phone', 'email']);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [$row['id'], $row['name'], $row['fiscal_code'], $row['legal_representative_name'], $row['phone'], $row['email']]);
            }
            fclose($out);
            exit;
        }
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
    }

    header('Location: clients_dedupe.php?' . http_build_query(array_filter([
        'mode' => $mode,
        'flash' => $flashSuccess,
        'flash_err' => $flashError,
    ])));
    exit;
}

$flashSuccess = trim((string)($_GET['flash'] ?? ''));
$flashError = trim((string)($_GET['flash_err'] ?? ''));

/*
|--------------------------------------------------------------------------
| Construim grupurile pentru modul curent
|--------------------------------------------------------------------------
*/
$cols = "id, name, fiscal_code, legal_representative_name, legal_representative_role, phone, email";
$stmt = $pdo->query("SELECT $cols FROM clients WHERE active = 1 AND {$modeCfg['sql_filter']} ORDER BY {$modeCfg['sql_order']}");
$allClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$normalizer = $modeCfg['normalizer'];
$keyField = $modeCfg['key_field'];
$groups = [];
foreach ($allClients as $c) {
    $key = $normalizer((string)$c[$keyField]);
    if ($key === '') continue;
    $groups[$key][] = $c;
}

// Doar grupurile cu >=2 clienți
$multiGroups = array_filter($groups, static fn($g) => count($g) >= 2);

// Clasificare per grup
$proposalsClean = [];
$proposalsConflict = [];
$proposalsComplete = [];

foreach ($multiGroups as $key => $clients) {
    $state = cd_collect_group_state($clients, $modeCfg['propagate']);

    $anyNeeds = false;
    $anyConflict = false;
    foreach ($modeCfg['propagate'] as $f) {
        $vals = $state['values'][$f];
        $missing = $state['missing'][$f];
        if (count($missing) > 0 && count($vals) >= 1) $anyNeeds = true;
        if (count($vals) > 1) $anyConflict = true;
    }

    if (!$anyNeeds && !$anyConflict) {
        $proposalsComplete[$key] = $clients;
    } elseif ($anyConflict) {
        $proposalsConflict[$key] = [
            'clients' => $clients,
            'values'  => $state['values'],
            'missing' => $state['missing'],
        ];
    } else {
        $singleValues = [];
        foreach ($modeCfg['propagate'] as $f) {
            $singleValues[$f] = count($state['values'][$f]) === 1 ? $state['values'][$f][0] : '';
        }
        $proposalsClean[$key] = [
            'clients' => $clients,
            'values'  => $singleValues,
            'missing' => $state['missing'],
        ];
    }
}

$tab = (string)($_GET['tab'] ?? 'clean');
if (!in_array($tab, ['clean', 'conflict', 'complete'], true)) $tab = 'clean';

// Stats
$statsTotal = count($multiGroups);
$statsClean = count($proposalsClean);
$statsConflict = count($proposalsConflict);
$statsComplete = count($proposalsComplete);

// Helper pentru construirea url-urilor de tab cu mode-ul curent păstrat
$tabUrl = function(string $tabName) use ($mode) {
    return 'clients_dedupe.php?' . http_build_query(['mode' => $mode, 'tab' => $tabName]);
};
$modeUrl = function(string $m) {
    return 'clients_dedupe.php?' . http_build_query(['mode' => $m, 'tab' => 'clean']);
};
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Corelare clienți - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
* { font-family: 'Inter', system-ui, -apple-system, sans-serif !important; }

.cd-page { max-width: 1200px; margin: 0 auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
.cd-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.cd-header h1 { font-size: 22px; font-weight: 700; color: var(--pz-title); margin: 0; }
.cd-header .sub { font-size: 13px; color: var(--pz-mu); margin-top: 2px; }
.cd-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.cd-mode-bar { display: flex; align-items: center; gap: 10px; background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); padding: 8px 12px; flex-wrap: wrap; }
.cd-mode-bar .lbl { font-size: 12px; font-weight: 700; color: var(--pz-mu); text-transform: uppercase; letter-spacing: .04em; }
.cd-mode-toggle { display: flex; gap: 4px; }
.cd-mode-btn { padding: 6px 12px; border-radius: var(--pz-rs); font-size: 12.5px; font-weight: 600; color: var(--pz-mu); text-decoration: none; transition: background .15s, color .15s; }
.cd-mode-btn:hover { background: var(--pz-soft); color: var(--pz-title); }
.cd-mode-btn.is-active { background: var(--pz-bls); color: var(--pz-bld); }

.cd-kpi { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.cd-kpi-card { background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); padding: 12px 14px; }
.cd-kpi-card .lbl { font-size: 10px; font-weight: 700; color: var(--pz-mu); text-transform: uppercase; letter-spacing: .04em; }
.cd-kpi-card .val { font-size: 26px; font-weight: 700; color: var(--pz-title); margin-top: 3px; }
.cd-kpi-card.tone-success .lbl { color: var(--pz-gr); }
.cd-kpi-card.tone-success .val { color: var(--pz-gr); }
.cd-kpi-card.tone-warning .lbl { color: var(--pz-or); }
.cd-kpi-card.tone-warning .val { color: var(--pz-or); }

.cd-tabs { display: flex; gap: 6px; background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); padding: 6px; }
.cd-tab { padding: 8px 14px; border-radius: var(--pz-rs); font-size: 13px; font-weight: 600; color: var(--pz-mu); display: inline-flex; align-items: center; gap: 8px; text-decoration: none; cursor: pointer; transition: background .15s, color .15s; border: 0; background: transparent; }
.cd-tab:hover { background: var(--pz-soft); color: var(--pz-title); }
.cd-tab.is-active { background: var(--pz-bls); color: var(--pz-bld); }
.cd-tab .count { font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 99px; background: var(--pz-line); color: var(--pz-mu); }
.cd-tab.is-active .count { background: var(--pz-blb); color: var(--pz-bld); }

.cd-group { background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); padding: 14px 16px; }
.cd-group + .cd-group { margin-top: 10px; }
.cd-group h3 { font-size: 15px; font-weight: 700; color: var(--pz-title); margin: 0 0 4px 0; }
.cd-group .meta { font-size: 12px; color: var(--pz-mu); margin-bottom: 10px; }
.cd-group.is-conflict { border-color: var(--pz-orb); background: #FFFBEB; }
.cd-group-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.cd-group-tbl th { text-align: left; font-size: 10.5px; color: var(--pz-mu); font-weight: 700; text-transform: uppercase; padding: 6px 8px; border-bottom: 1px solid var(--pz-line); }
.cd-group-tbl td { padding: 7px 8px; border-bottom: 1px solid var(--pz-lines); vertical-align: top; }
.cd-group-tbl tr:last-child td { border-bottom: 0; }
.cd-empty-cell { color: var(--pz-re); font-style: italic; font-weight: 600; }
.cd-filled-cell { color: var(--pz-gr); font-weight: 600; font-family: var(--mono, ui-monospace, monospace); font-size: 12px; }
.cd-proposal { margin-top: 12px; padding: 10px 12px; background: var(--pz-bls); border: 1px solid var(--pz-blb); border-radius: var(--pz-rs); font-size: 12.5px; color: var(--pz-bld); }
.cd-proposal strong { font-weight: 700; }
.cd-group-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }

.cd-conflict-choice { background: #FEF3C7; border: 1px solid #F0C36D; padding: 10px 12px; border-radius: var(--pz-rs); margin-top: 10px; font-size: 12.5px; color: #92400E; }
.cd-conflict-choice label { display: flex; align-items: center; gap: 8px; padding: 4px 0; cursor: pointer; }
.cd-conflict-choice label input { width: auto; margin: 0; }

.cd-empty-state { padding: 60px 20px; text-align: center; color: var(--pz-mu); font-size: 14px; }
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('clients_dedupe', true); ?>
    <main class="main">
        <div class="content cd-page">

            <div class="cd-header">
                <div>
                    <h1>Corelare clienți</h1>
                    <div class="sub"><?= cd_h($modeCfg['description']) ?></div>
                </div>
                <div class="cd-actions">
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="download_backup">
                        <input type="hidden" name="mode" value="<?= cd_h($mode) ?>">
                        <button class="btn" type="submit">⬇ Backup CSV</button>
                    </form>
                    <?php if ($statsClean > 0): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Vei aplica AUTOMAT toate propunerile fără conflict pentru gruparea după <?= cd_h(mb_strtolower($modeCfg['label'])) ?> (<?= $statsClean ?> grupuri). Ai descărcat backup-ul CSV?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="apply_all_safe">
                            <input type="hidden" name="mode" value="<?= cd_h($mode) ?>">
                            <button class="btn accent" type="submit">⚡ Aplică toate propunerile fără conflict</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($flashSuccess !== ''): ?><div class="notice notice-success"><?= cd_h($flashSuccess) ?></div><?php endif; ?>
            <?php if ($flashError !== ''): ?><div class="notice notice-danger"><?= cd_h($flashError) ?></div><?php endif; ?>

            <div class="cd-mode-bar">
                <span class="lbl">Grupare după:</span>
                <div class="cd-mode-toggle">
                    <?php foreach ($modes as $mKey => $mCfg): ?>
                        <a class="cd-mode-btn <?= $mKey === $mode ? 'is-active' : '' ?>" href="<?= cd_h($modeUrl($mKey)) ?>"><?= cd_h($mCfg['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="cd-kpi">
                <div class="cd-kpi-card">
                    <div class="lbl">Grupuri găsite</div>
                    <div class="val"><?= $statsTotal ?></div>
                </div>
                <div class="cd-kpi-card tone-success">
                    <div class="lbl">Cu propunere automată</div>
                    <div class="val"><?= $statsClean ?></div>
                </div>
                <div class="cd-kpi-card tone-warning">
                    <div class="lbl">Cu conflict (review manual)</div>
                    <div class="val"><?= $statsConflict ?></div>
                </div>
                <div class="cd-kpi-card">
                    <div class="lbl">Deja complete</div>
                    <div class="val"><?= $statsComplete ?></div>
                </div>
            </div>

            <div class="cd-tabs">
                <a class="cd-tab <?= $tab === 'clean' ? 'is-active' : '' ?>" href="<?= cd_h($tabUrl('clean')) ?>">
                    Propuneri automate <span class="count"><?= $statsClean ?></span>
                </a>
                <a class="cd-tab <?= $tab === 'conflict' ? 'is-active' : '' ?>" href="<?= cd_h($tabUrl('conflict')) ?>">
                    Conflicte <span class="count"><?= $statsConflict ?></span>
                </a>
                <a class="cd-tab <?= $tab === 'complete' ? 'is-active' : '' ?>" href="<?= cd_h($tabUrl('complete')) ?>">
                    Deja complete <span class="count"><?= $statsComplete ?></span>
                </a>
            </div>

            <?php
            /**
             * Closure care randează header-ul tabelului unui grup în funcție de coloanele
             * specifice modului curent (extra_cols).
             */
            $renderTblHead = function() use ($modeCfg) {
                echo '<thead><tr><th>Firmă</th><th>CIF</th>';
                foreach ($modeCfg['extra_cols'] as $col => $label) {
                    echo '<th>' . cd_h($label) . '</th>';
                }
                echo '</tr></thead>';
            };
            /**
             * Closure care randează un rând din tabel pentru un client, pe coloanele modului curent.
             */
            $renderTblRow = function(array $c) use ($modeCfg) {
                echo '<tr>';
                echo '<td><a href="client.php?id=' . (int)$c['id'] . '" target="_blank">' . cd_h($c['name']) . '</a></td>';
                echo '<td>' . cd_h($c['fiscal_code']) . '</td>';
                foreach ($modeCfg['extra_cols'] as $col => $label) {
                    $v = trim((string)($c[$col] ?? ''));
                    if ($v === '') {
                        echo '<td><span class="cd-empty-cell">(lipsă)</span></td>';
                    } else {
                        // Reprezentantul nu se afișează ca mono; telefon/email da.
                        if ($col === 'legal_representative_name') {
                            echo '<td>' . cd_h($v) . '</td>';
                        } else {
                            echo '<td><span class="cd-filled-cell">' . cd_h($v) . '</span></td>';
                        }
                    }
                }
                echo '</tr>';
            };
            ?>

            <?php if ($tab === 'clean'): ?>
                <?php if (empty($proposalsClean)): ?>
                    <div class="cd-empty-state">Nicio propunere de aplicat automat. 🎉</div>
                <?php else: ?>
                    <?php foreach ($proposalsClean as $key => $g):
                        $clients = $g['clients'];
                        $values = $g['values'];
                        $missing = $g['missing'];
                        // Titlul grupului = valoarea cheii din primul client
                        $titleVal = (string)($clients[0][$modeCfg['key_field']] ?? '');
                    ?>
                        <div class="cd-group">
                            <h3><?= cd_h($titleVal) ?></h3>
                            <div class="meta"><?= count($clients) ?> firme cu acest/această <?= cd_h(mb_strtolower($modeCfg['key_label'])) ?></div>
                            <table class="cd-group-tbl">
                                <?php $renderTblHead(); ?>
                                <tbody>
                                <?php foreach ($clients as $c) { $renderTblRow($c); } ?>
                                </tbody>
                            </table>
                            <?php
                            // Construim textul propunerii doar pentru câmpurile care au valoare și targets ne-vide
                            $proposalParts = [];
                            foreach ($modeCfg['propagate'] as $f) {
                                $v = $values[$f] ?? '';
                                $m = $missing[$f] ?? [];
                                if ($v !== '' && !empty($m)) {
                                    $proposalParts[] = 'completează <strong>' . cd_h(cd_field_label($f)) . '</strong> ' . cd_h($v) . ' la ' . count($m) . ' firmă/firme';
                                }
                            }
                            ?>
                            <?php if (!empty($proposalParts)): ?>
                                <div class="cd-proposal">
                                    <strong>Propunere:</strong> <?= implode(' · ', $proposalParts) ?>
                                </div>
                            <?php endif; ?>
                            <form method="post" class="cd-group-actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="apply_group">
                                <input type="hidden" name="mode" value="<?= cd_h($mode) ?>">
                                <?php foreach ($modeCfg['propagate'] as $f): ?>
                                    <input type="hidden" name="group_<?= cd_h($f) ?>" value="<?= cd_h($values[$f] ?? '') ?>">
                                    <?php foreach (($missing[$f] ?? []) as $id): ?>
                                        <input type="hidden" name="targets_<?= cd_h($f) ?>[]" value="<?= (int)$id ?>">
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <button class="btn accent" type="submit">✓ Aplică pentru acest grup</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php elseif ($tab === 'conflict'): ?>
                <?php if (empty($proposalsConflict)): ?>
                    <div class="cd-empty-state">Niciun conflict de rezolvat. 🎉</div>
                <?php else: ?>
                    <?php foreach ($proposalsConflict as $key => $g):
                        $clients = $g['clients'];
                        $values = $g['values'];
                        $missing = $g['missing'];
                        $titleVal = (string)($clients[0][$modeCfg['key_field']] ?? '');
                        $formId = 'form-' . md5($mode . '|' . $key);
                    ?>
                        <div class="cd-group is-conflict">
                            <h3>⚠ <?= cd_h($titleVal) ?></h3>
                            <div class="meta"><?= count($clients) ?> firme cu acest/această <?= cd_h(mb_strtolower($modeCfg['key_label'])) ?> · valori diferite găsite</div>
                            <table class="cd-group-tbl">
                                <?php $renderTblHead(); ?>
                                <tbody>
                                <?php foreach ($clients as $c) { $renderTblRow($c); } ?>
                                </tbody>
                            </table>
                            <form method="post" id="<?= $formId ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="apply_group">
                                <input type="hidden" name="mode" value="<?= cd_h($mode) ?>">
                                <?php foreach ($modeCfg['propagate'] as $f):
                                    $vals = $values[$f] ?? [];
                                    $miss = $missing[$f] ?? [];
                                    $hasFieldConflict = count($vals) > 1;
                                    ?>
                                    <?php foreach ($miss as $id): ?>
                                        <input type="hidden" name="targets_<?= cd_h($f) ?>[]" value="<?= (int)$id ?>">
                                    <?php endforeach; ?>
                                    <?php if ($hasFieldConflict && !empty($miss)): ?>
                                        <div class="cd-conflict-choice">
                                            <strong><?= cd_h(ucfirst(cd_field_label($f))) ?> - alege valoarea de copiat:</strong>
                                            <label><input type="radio" name="group_<?= cd_h($f) ?>" value=""> Skip (nu copia <?= cd_h(cd_field_label($f)) ?>)</label>
                                            <?php foreach ($vals as $val): ?>
                                                <label><input type="radio" name="group_<?= cd_h($f) ?>" value="<?= cd_h($val) ?>"> <?= cd_h($val) ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <input type="hidden" name="group_<?= cd_h($f) ?>" value="<?= cd_h(count($vals) === 1 ? $vals[0] : '') ?>">
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <div class="cd-group-actions">
                                    <button class="btn accent" type="submit">✓ Aplică pentru acest grup</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php else: /* complete */ ?>
                <?php if (empty($proposalsComplete)): ?>
                    <div class="cd-empty-state">Niciun grup complet încă.</div>
                <?php else: ?>
                    <?php foreach ($proposalsComplete as $key => $clients):
                        $titleVal = (string)($clients[0][$modeCfg['key_field']] ?? '');
                    ?>
                        <div class="cd-group" style="opacity:.85">
                            <h3>✓ <?= cd_h($titleVal) ?></h3>
                            <div class="meta"><?= count($clients) ?> firme · toate au datele necesare</div>
                            <table class="cd-group-tbl">
                                <?php $renderTblHead(); ?>
                                <tbody>
                                <?php foreach ($clients as $c) { $renderTblRow($c); } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </main>
</div>
</body>
</html>
