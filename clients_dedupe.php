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
            // Aplicăm o singură propunere de grup.
            // POST: group_phone (telefonul de copiat), group_email (emailul de copiat),
            //       targets_phone[] (id-uri clienți la care se copiază telefonul),
            //       targets_email[] (id-uri la care se copiază emailul)
            $phoneValue = trim((string)($_POST['group_phone'] ?? ''));
            $emailValue = trim((string)($_POST['group_email'] ?? ''));
            $targetsPhone = array_filter(array_map('intval', (array)($_POST['targets_phone'] ?? [])));
            $targetsEmail = array_filter(array_map('intval', (array)($_POST['targets_email'] ?? [])));

            $updates = 0;
            if ($phoneValue !== '' && !empty($targetsPhone)) {
                $in = implode(',', array_fill(0, count($targetsPhone), '?'));
                $params = array_merge([$phoneValue], $targetsPhone);
                $stmt = $pdo->prepare("UPDATE clients SET phone = ? WHERE id IN ($in) AND (phone IS NULL OR phone = '')");
                $stmt->execute($params);
                $updates += $stmt->rowCount();
            }
            if ($emailValue !== '' && filter_var($emailValue, FILTER_VALIDATE_EMAIL) && !empty($targetsEmail)) {
                $in = implode(',', array_fill(0, count($targetsEmail), '?'));
                $params = array_merge([$emailValue], $targetsEmail);
                $stmt = $pdo->prepare("UPDATE clients SET email = ? WHERE id IN ($in) AND (email IS NULL OR email = '')");
                $stmt->execute($params);
                $updates += $stmt->rowCount();
            }
            $flashSuccess = "Aplicat. $updates fișe de client actualizate.";
        } elseif ($action === 'apply_all_safe') {
            // Aplică automat doar grupurile FĂRĂ conflicte (o singură valoare unică per câmp).
            $stmt = $pdo->query("SELECT id, name, legal_representative_name, phone, email FROM clients WHERE active = 1 AND legal_representative_name IS NOT NULL AND legal_representative_name <> ''");
            $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $groups = [];
            foreach ($all as $c) {
                $key = cd_normalize_name((string)$c['legal_representative_name']);
                if ($key === '') continue;
                $groups[$key][] = $c;
            }
            $totalUpdated = 0;
            foreach ($groups as $key => $clients) {
                if (count($clients) < 2) continue;
                // Phone: găsește toate valorile unice non-empty
                $phoneValues = array_unique(array_filter(array_map(static function($r) { return trim((string)$r['phone']); }, $clients), static fn($v) => $v !== ''));
                $emailValues = array_unique(array_filter(array_map(static function($r) { return trim((string)$r['email']); }, $clients), static fn($v) => $v !== ''));

                if (count($phoneValues) === 1) {
                    $phoneVal = reset($phoneValues);
                    $targets = array_values(array_filter(array_map(static fn($r) => trim((string)$r['phone']) === '' ? (int)$r['id'] : 0, $clients)));
                    $targets = array_filter($targets);
                    if (!empty($targets)) {
                        $in = implode(',', array_fill(0, count($targets), '?'));
                        $params = array_merge([$phoneVal], $targets);
                        $stmt = $pdo->prepare("UPDATE clients SET phone = ? WHERE id IN ($in) AND (phone IS NULL OR phone = '')");
                        $stmt->execute($params);
                        $totalUpdated += $stmt->rowCount();
                    }
                }
                if (count($emailValues) === 1) {
                    $emailVal = reset($emailValues);
                    if (filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
                        $targets = array_values(array_filter(array_map(static fn($r) => trim((string)$r['email']) === '' ? (int)$r['id'] : 0, $clients)));
                        $targets = array_filter($targets);
                        if (!empty($targets)) {
                            $in = implode(',', array_fill(0, count($targets), '?'));
                            $params = array_merge([$emailVal], $targets);
                            $stmt = $pdo->prepare("UPDATE clients SET email = ? WHERE id IN ($in) AND (email IS NULL OR email = '')");
                            $stmt->execute($params);
                            $totalUpdated += $stmt->rowCount();
                        }
                    }
                }
            }
            $flashSuccess = "Aplicat în masă. $totalUpdated câmpuri completate automat.";
        } elseif ($action === 'download_backup') {
            // Generăm CSV cu starea curentă a tuturor clienților cu reprezentant
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
        'flash' => $flashSuccess,
        'flash_err' => $flashError,
    ])));
    exit;
}

$flashSuccess = trim((string)($_GET['flash'] ?? ''));
$flashError = trim((string)($_GET['flash_err'] ?? ''));

/*
|--------------------------------------------------------------------------
| Construim grupurile de reprezentanți
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT id, name, fiscal_code, legal_representative_name, legal_representative_role, phone, email
    FROM clients
    WHERE active = 1
      AND legal_representative_name IS NOT NULL
      AND TRIM(legal_representative_name) <> ''
    ORDER BY legal_representative_name ASC, name ASC
");
$allClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
foreach ($allClients as $c) {
    $key = cd_normalize_name((string)$c['legal_representative_name']);
    if ($key === '') continue;
    $groups[$key][] = $c;
}

// Doar grupurile cu >=2 clienți (potențial deduplicate)
$multiGroups = array_filter($groups, static fn($g) => count($g) >= 2);

// Clasificare per grup
$proposalsClean = []; // toate valorile completate sunt identice → propunere automată sigură
$proposalsConflict = []; // valori diferite în grup → user trebuie să aleagă
$proposalsComplete = []; // toate firmele din grup au telefon și email - nimic de făcut

foreach ($multiGroups as $key => $clients) {
    $phoneValues = array_unique(array_filter(array_map(static fn($r) => trim((string)$r['phone']), $clients), static fn($v) => $v !== ''));
    $emailValues = array_unique(array_filter(array_map(static fn($r) => trim((string)$r['email']), $clients), static fn($v) => $v !== ''));
    $missingPhone = array_filter($clients, static fn($r) => trim((string)$r['phone']) === '');
    $missingEmail = array_filter($clients, static fn($r) => trim((string)$r['email']) === '');

    $needsPhone = count($missingPhone) > 0 && count($phoneValues) >= 1;
    $needsEmail = count($missingEmail) > 0 && count($emailValues) >= 1;
    $hasPhoneConflict = count($phoneValues) > 1;
    $hasEmailConflict = count($emailValues) > 1;

    if (!$needsPhone && !$needsEmail) {
        $proposalsComplete[$key] = $clients;
    } elseif ($hasPhoneConflict || $hasEmailConflict) {
        $proposalsConflict[$key] = [
            'clients' => $clients,
            'phone_values' => array_values($phoneValues),
            'email_values' => array_values($emailValues),
            'missing_phone' => array_column($missingPhone, 'id'),
            'missing_email' => array_column($missingEmail, 'id'),
        ];
    } else {
        $proposalsClean[$key] = [
            'clients' => $clients,
            'phone_value' => count($phoneValues) === 1 ? reset($phoneValues) : '',
            'email_value' => count($emailValues) === 1 ? reset($emailValues) : '',
            'missing_phone' => array_column($missingPhone, 'id'),
            'missing_email' => array_column($missingEmail, 'id'),
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
$totalClients = array_sum(array_map('count', $multiGroups));
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Corelare reprezentanți - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php app_theme_css(); ?>
<style>
* { font-family: 'Inter', system-ui, -apple-system, sans-serif !important; }

.cd-page { max-width: 1200px; margin: 0 auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
.cd-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.cd-header h1 { font-size: 22px; font-weight: 700; color: var(--pz-title); margin: 0; }
.cd-header .sub { font-size: 13px; color: var(--pz-mu); margin-top: 2px; }
.cd-actions { display: flex; gap: 8px; flex-wrap: wrap; }

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
                    <h1>Corelare reprezentanți</h1>
                    <div class="sub">Identifică firmele cu același reprezentant legal și completează telefonul/emailul lipsă pe baza fișelor unde aceste date există.</div>
                </div>
                <div class="cd-actions">
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="download_backup">
                        <button class="btn" type="submit">⬇ Backup CSV</button>
                    </form>
                    <?php if ($statsClean > 0): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Vei aplica AUTOMAT toate propunerile fără conflict (<?= $statsClean ?> grupuri). Ai descărcat backup-ul CSV?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="apply_all_safe">
                            <button class="btn accent" type="submit">⚡ Aplică toate propunerile fără conflict</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($flashSuccess !== ''): ?><div class="notice notice-success"><?= cd_h($flashSuccess) ?></div><?php endif; ?>
            <?php if ($flashError !== ''): ?><div class="notice notice-danger"><?= cd_h($flashError) ?></div><?php endif; ?>

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
                <a class="cd-tab <?= $tab === 'clean' ? 'is-active' : '' ?>" href="clients_dedupe.php?tab=clean">
                    Propuneri automate <span class="count"><?= $statsClean ?></span>
                </a>
                <a class="cd-tab <?= $tab === 'conflict' ? 'is-active' : '' ?>" href="clients_dedupe.php?tab=conflict">
                    Conflicte <span class="count"><?= $statsConflict ?></span>
                </a>
                <a class="cd-tab <?= $tab === 'complete' ? 'is-active' : '' ?>" href="clients_dedupe.php?tab=complete">
                    Deja complete <span class="count"><?= $statsComplete ?></span>
                </a>
            </div>

            <?php if ($tab === 'clean'): ?>
                <?php if (empty($proposalsClean)): ?>
                    <div class="cd-empty-state">Nicio propunere de aplicat automat. 🎉</div>
                <?php else: ?>
                    <?php foreach ($proposalsClean as $key => $g):
                        $clients = $g['clients'];
                        $phoneValue = $g['phone_value'];
                        $emailValue = $g['email_value'];
                        $missingPhone = $g['missing_phone'];
                        $missingEmail = $g['missing_email'];
                        $repName = (string)$clients[0]['legal_representative_name'];
                    ?>
                        <div class="cd-group">
                            <h3><?= cd_h($repName) ?></h3>
                            <div class="meta"><?= count($clients) ?> firme cu acest reprezentant</div>
                            <table class="cd-group-tbl">
                                <thead>
                                    <tr>
                                        <th>Firmă</th>
                                        <th>CIF</th>
                                        <th>Telefon</th>
                                        <th>Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($clients as $c): ?>
                                    <tr>
                                        <td><a href="client.php?id=<?= (int)$c['id'] ?>" target="_blank"><?= cd_h($c['name']) ?></a></td>
                                        <td><?= cd_h($c['fiscal_code']) ?></td>
                                        <td><?php $p = trim((string)$c['phone']); echo $p === '' ? '<span class="cd-empty-cell">(lipsă)</span>' : '<span class="cd-filled-cell">' . cd_h($p) . '</span>'; ?></td>
                                        <td><?php $e2 = trim((string)$c['email']); echo $e2 === '' ? '<span class="cd-empty-cell">(lipsă)</span>' : '<span class="cd-filled-cell">' . cd_h($e2) . '</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="cd-proposal">
                                <strong>Propunere:</strong>
                                <?php $proposals = []; ?>
                                <?php if ($phoneValue !== '' && !empty($missingPhone)): $proposals[] = 'completează <strong>telefon</strong> ' . cd_h($phoneValue) . ' la ' . count($missingPhone) . ' firmă/firme'; endif; ?>
                                <?php if ($emailValue !== '' && !empty($missingEmail)): $proposals[] = 'completează <strong>email</strong> ' . cd_h($emailValue) . ' la ' . count($missingEmail) . ' firmă/firme'; endif; ?>
                                <?= implode(' · ', $proposals) ?>
                            </div>
                            <form method="post" class="cd-group-actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="apply_group">
                                <input type="hidden" name="group_phone" value="<?= cd_h($phoneValue) ?>">
                                <input type="hidden" name="group_email" value="<?= cd_h($emailValue) ?>">
                                <?php foreach ($missingPhone as $id): ?>
                                    <input type="hidden" name="targets_phone[]" value="<?= (int)$id ?>">
                                <?php endforeach; ?>
                                <?php foreach ($missingEmail as $id): ?>
                                    <input type="hidden" name="targets_email[]" value="<?= (int)$id ?>">
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
                        $phoneValues = $g['phone_values'];
                        $emailValues = $g['email_values'];
                        $missingPhone = $g['missing_phone'];
                        $missingEmail = $g['missing_email'];
                        $repName = (string)$clients[0]['legal_representative_name'];
                        $hasPhoneConflict = count($phoneValues) > 1;
                        $hasEmailConflict = count($emailValues) > 1;
                        $formId = 'form-' . md5($key);
                    ?>
                        <div class="cd-group is-conflict">
                            <h3>⚠ <?= cd_h($repName) ?></h3>
                            <div class="meta"><?= count($clients) ?> firme cu acest reprezentant · valori diferite găsite</div>
                            <table class="cd-group-tbl">
                                <thead>
                                    <tr>
                                        <th>Firmă</th>
                                        <th>CIF</th>
                                        <th>Telefon</th>
                                        <th>Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($clients as $c): ?>
                                    <tr>
                                        <td><a href="client.php?id=<?= (int)$c['id'] ?>" target="_blank"><?= cd_h($c['name']) ?></a></td>
                                        <td><?= cd_h($c['fiscal_code']) ?></td>
                                        <td><?php $p = trim((string)$c['phone']); echo $p === '' ? '<span class="cd-empty-cell">(lipsă)</span>' : '<span class="cd-filled-cell">' . cd_h($p) . '</span>'; ?></td>
                                        <td><?php $e2 = trim((string)$c['email']); echo $e2 === '' ? '<span class="cd-empty-cell">(lipsă)</span>' : '<span class="cd-filled-cell">' . cd_h($e2) . '</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <form method="post" id="<?= $formId ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="apply_group">
                                <?php foreach ($missingPhone as $id): ?>
                                    <input type="hidden" name="targets_phone[]" value="<?= (int)$id ?>">
                                <?php endforeach; ?>
                                <?php foreach ($missingEmail as $id): ?>
                                    <input type="hidden" name="targets_email[]" value="<?= (int)$id ?>">
                                <?php endforeach; ?>
                                <?php if ($hasPhoneConflict && !empty($missingPhone)): ?>
                                    <div class="cd-conflict-choice">
                                        <strong>Telefon - alege valoarea de copiat:</strong>
                                        <label><input type="radio" name="group_phone" value=""> Skip (nu copia telefonul)</label>
                                        <?php foreach ($phoneValues as $val): ?>
                                            <label><input type="radio" name="group_phone" value="<?= cd_h($val) ?>"> <?= cd_h($val) ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <input type="hidden" name="group_phone" value="<?= cd_h(count($phoneValues) === 1 ? reset($phoneValues) : '') ?>">
                                <?php endif; ?>
                                <?php if ($hasEmailConflict && !empty($missingEmail)): ?>
                                    <div class="cd-conflict-choice">
                                        <strong>Email - alege valoarea de copiat:</strong>
                                        <label><input type="radio" name="group_email" value=""> Skip (nu copia emailul)</label>
                                        <?php foreach ($emailValues as $val): ?>
                                            <label><input type="radio" name="group_email" value="<?= cd_h($val) ?>"> <?= cd_h($val) ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <input type="hidden" name="group_email" value="<?= cd_h(count($emailValues) === 1 ? reset($emailValues) : '') ?>">
                                <?php endif; ?>
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
                        $repName = (string)$clients[0]['legal_representative_name'];
                    ?>
                        <div class="cd-group" style="opacity:.85">
                            <h3>✓ <?= cd_h($repName) ?></h3>
                            <div class="meta"><?= count($clients) ?> firme · toate au telefon și email</div>
                            <table class="cd-group-tbl">
                                <thead>
                                    <tr><th>Firmă</th><th>CIF</th><th>Telefon</th><th>Email</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($clients as $c): ?>
                                    <tr>
                                        <td><a href="client.php?id=<?= (int)$c['id'] ?>" target="_blank"><?= cd_h($c['name']) ?></a></td>
                                        <td><?= cd_h($c['fiscal_code']) ?></td>
                                        <td><span class="cd-filled-cell"><?= cd_h($c['phone']) ?></span></td>
                                        <td><span class="cd-filled-cell"><?= cd_h($c['email']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
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
