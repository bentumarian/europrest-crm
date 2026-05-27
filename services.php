<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';
require_once __DIR__ . '/lib/revenue_lib.php';

$isAdmin = is_admin();

if (!$isAdmin) {
    header("Location: calendar.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
// h() este definit global în app_helpers.php (inclus prin app_ui.php).

function table_exists_services(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");

    $stmt->execute([$table]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0) > 0;
}

function column_exists_services(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");

    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0) > 0;
}

function ensure_column_services(PDO $pdo, string $table, string $column, string $definition): void {
    if (!column_exists_services($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Exception $e) {
            // Nu blocam pagina dacă acea coloana există deja sau ALTER-ul nu poate rula.
        }
    }
}

/*
|--------------------------------------------------------------------------
| Tabel servicii
|--------------------------------------------------------------------------
*/
$pdo->exec("
    CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        description TEXT NULL,
        default_duration INT NOT NULL DEFAULT 60,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

ensure_column_services($pdo, 'services', 'name', "VARCHAR(150) NULL");
ensure_column_services($pdo, 'services', 'description', "TEXT NULL");
ensure_column_services($pdo, 'services', 'default_duration', "INT NOT NULL DEFAULT 60");
ensure_column_services($pdo, 'services', 'active', "TINYINT(1) NOT NULL DEFAULT 1");
ensure_column_services($pdo, 'services', 'sort_order', "INT NOT NULL DEFAULT 0");
ensure_column_services($pdo, 'services', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

// Categoria veniturilor (linie de business): ddd / ignifugari / chirii / altele.
pz_revenue_ensure_column($pdo, 'services', 'ddd');

/*
|--------------------------------------------------------------------------
| Servicii implicite
|--------------------------------------------------------------------------
*/
$countServices = (int)($pdo->query("SELECT COUNT(*) AS total FROM services")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

if ($countServices === 0) {
    $stmt = $pdo->prepare("
        INSERT INTO services (name, description, default_duration, active, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ");

    $defaultServices = [
        ['Deratizare', 'Servicii de combatere rozatoare', 60, 1, 1],
        ['Dezinsectie', 'Servicii de combatere insecte', 60, 1, 2],
        ['Dezinfectie', 'Servicii de dezinfectie spatii', 60, 1, 3],
        ['Monitorizare capcane', 'Verificare si monitorizare capcane', 30, 1, 4],
        ['Tratament plosnite', 'Tratament impotriva plosnitelor', 120, 1, 5],
        ['Alt serviciu', 'Serviciu personalizat', 60, 1, 99],
    ];

    foreach ($defaultServices as $service) {
        $stmt->execute($service);
    }
}

/*
|--------------------------------------------------------------------------
| POST handler
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $defaultDuration = max(15, (int)($_POST['default_duration'] ?? 60));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $active = !empty($_POST['active']) ? 1 : 0;
        $revenueCategory = pz_revenue_category_normalize($_POST['revenue_category'] ?? 'ddd', 'ddd');

        if ($name === '') {
            header("Location: services.php?error=1");
            exit;
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare("
                INSERT INTO services
                (name, description, default_duration, active, sort_order, revenue_category)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $name,
                $description ?: null,
                $defaultDuration,
                $active,
                $sortOrder,
                $revenueCategory,
            ]);

            header("Location: services.php?success=1");
            exit;
        }

        if ($action === 'update' && $serviceId > 0) {
            $stmt = $pdo->prepare("
                UPDATE services
                SET name = ?,
                    description = ?,
                    default_duration = ?,
                    active = ?,
                    sort_order = ?,
                    revenue_category = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $name,
                $description ?: null,
                $defaultDuration,
                $active,
                $sortOrder,
                $revenueCategory,
                $serviceId,
            ]);

            header("Location: services.php?updated=1");
            exit;
        }

        header("Location: services.php?error=1");
        exit;
    }

    if ($action === 'toggle') {
        $serviceId = (int)($_POST['service_id'] ?? 0);

        if ($serviceId > 0) {
            $stmt = $pdo->prepare("
                UPDATE services
                SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END
                WHERE id = ?
            ");
            $stmt->execute([$serviceId]);

            header("Location: services.php?toggled=1");
            exit;
        }

        header("Location: services.php?error=1");
        exit;
    }

    if ($action === 'delete') {
        $serviceId = (int)($_POST['service_id'] ?? 0);

        if ($serviceId > 0) {
            $stmt = $pdo->prepare("
                SELECT name
                FROM services
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$serviceId]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$service) {
                header("Location: services.php?error=1");
                exit;
            }

            $serviceName = $service['name'];

            $usedInTasks = 0;
            $usedInAppointments = 0;

            if (table_exists_services($pdo, 'tasks')) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS total
                    FROM tasks
                    WHERE service_type = ?
                ");
                $stmt->execute([$serviceName]);
                $usedInTasks = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
            }

            if (table_exists_services($pdo, 'appointments')) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS total
                    FROM appointments
                    WHERE service_type = ?
                ");
                $stmt->execute([$serviceName]);
                $usedInAppointments = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
            }

            if ($usedInTasks > 0 || $usedInAppointments > 0) {
                $stmt = $pdo->prepare("
                    UPDATE services
                    SET active = 0
                    WHERE id = ?
                ");
                $stmt->execute([$serviceId]);

                header("Location: services.php?delete_blocked=1");
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
            $stmt->execute([$serviceId]);

            header("Location: services.php?deleted=1");
            exit;
        }

        header("Location: services.php?error=1");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Query servicii
|--------------------------------------------------------------------------
*/
$filterCategory = strtolower(trim((string)($_GET['cat'] ?? '')));
$allowedFilterCategories = pz_revenue_category_keys();
$hasFilterCategory = in_array($filterCategory, $allowedFilterCategories, true);

if ($hasFilterCategory) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM services
        WHERE revenue_category = ?
        ORDER BY sort_order ASC, name ASC
    ");
    $stmt->execute([$filterCategory]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->query("
        SELECT *
        FROM services
        ORDER BY sort_order ASC, name ASC
    ");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Tot setul pentru count-uri (independent de filtru).
$allServices = $pdo->query("SELECT id, active, revenue_category FROM services")->fetchAll(PDO::FETCH_ASSOC);
$totalServices = count($allServices);
$activeServices = 0;
$inactiveServices = 0;
$categoryCounts = array_fill_keys($allowedFilterCategories, 0);

foreach ($allServices as $row) {
    if ((int)$row['active'] === 1) {
        $activeServices++;
    } else {
        $inactiveServices++;
    }
    $catKey = pz_revenue_category_normalize((string)($row['revenue_category'] ?? 'ddd'), 'ddd');
    $categoryCounts[$catKey] = ($categoryCounts[$catKey] ?? 0) + 1;
}

$servicesForJs = [];

foreach ($services as $service) {
    $servicesForJs[(int)$service['id']] = [
        'id' => (int)$service['id'],
        'name' => $service['name'] ?? '',
        'description' => $service['description'] ?? '',
        'default_duration' => (int)($service['default_duration'] ?? 60),
        'active' => (int)($service['active'] ?? 1),
        'sort_order' => (int)($service['sort_order'] ?? 0),
        'revenue_category' => pz_revenue_category_normalize($service['revenue_category'] ?? 'ddd', 'ddd'),
    ];
}

$revenueCategories = pz_revenue_categories();

$durationOptions = [
    15  => '15 minute',
    30  => '30 minute',
    45  => '45 minute',
    60  => '1 ora',
    90  => '1h 30min',
    120 => '2 ore',
    180 => '3 ore',
    240 => '4 ore',
];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Servicii - <?= h(pz_app_name()) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

</head>

<body>
<div class="layout">

    <?php render_sidebar('services', $isAdmin); ?>

    <main class="main">

<?php /* Topbar vechi eliminat — înlocuit cu pz_page_header mai jos. */ ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="notice notice-success">Serviciul a fost adaugat.</div>
        <?php endif; ?>

        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success">Serviciul a fost actualizat.</div>
        <?php endif; ?>

        <?php if (isset($_GET['toggled'])): ?>
            <div class="notice notice-success">Statusul serviciului a fost schimbat.</div>
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
            <div class="notice notice-warning">Serviciul a fost șters.</div>
        <?php endif; ?>

        <?php if (isset($_GET['delete_blocked'])): ?>
            <div class="notice notice-warning">Serviciul este folosit in sarcini sau programări si a fost dezactivat in loc sa fie șters.</div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notice notice-danger">Completează numele serviciului.</div>
        <?php endif; ?>

        <div class="content">

            <?php pz_page_header([
                'back'     => ['href' => 'settings.php', 'label' => 'Înapoi la setări'],
                'kicker'   => 'ADMINISTRARE · OPERAȚIONAL',
                'title'    => 'Servicii',
                'subtitle' => 'Gestionează serviciile disponibile în programări și sarcini. Serviciile active apar automat în formulare.',
                'actions'  => [[
                    'label'   => 'Serviciu nou',
                    'icon'    => 'ti-plus',
                    'variant' => 'primary',
                    'iconOnly' => true,
                    'type'    => 'button',
                    'onclick' => 'openCreateServiceModal()',
                ]],
                'kpis'     => [
                    ['label' => 'Total',    'value' => (int)$totalServices],
                    ['label' => 'Active',   'value' => (int)$activeServices,   'tone' => 'success'],
                    ['label' => 'Inactive', 'value' => (int)$inactiveServices, 'tone' => 'warning'],
                ],
            ]); ?>

            <div class="services-cat-filter" style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 14px;align-items:center;">
                <span style="font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:var(--pz-muted);font-weight:600;margin-right:2px;">Filtrează:</span>
                <a href="services.php" class="cat-chip <?= !$hasFilterCategory ? 'active' : '' ?>"
                   style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;text-decoration:none;font-size:12px;font-weight:500;border:0.5px solid var(--pz-line);background:<?= !$hasFilterCategory ? 'var(--pz-navy)' : 'var(--pz-surface)' ?>;color:<?= !$hasFilterCategory ? '#FFF' : 'var(--pz-text)' ?>;">
                    Toate <span style="opacity:0.6"><?= (int)$totalServices ?></span>
                </a>
                <?php foreach ($revenueCategories as $code => $info): ?>
                    <?php $isActive = $filterCategory === $code; ?>
                    <a href="services.php?cat=<?= h($code) ?>" class="cat-chip <?= $isActive ? 'active' : '' ?>"
                       style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;text-decoration:none;font-size:12px;font-weight:500;border:0.5px solid <?= h($info['border']) ?>;background:<?= $isActive ? h($info['color']) : h($info['bg']) ?>;color:<?= $isActive ? '#FFF' : h($info['color']) ?>;">
                        <?= h($info['label']) ?> <span style="opacity:0.65"><?= (int)($categoryCounts[$code] ?? 0) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$services): ?>
                <div class="empty-state">
                    <?php if ($hasFilterCategory): ?>
                        Nu există servicii în categoria <strong><?= h(pz_revenue_category_label($filterCategory)) ?></strong>.
                    <?php else: ?>
                        Nu există servicii definite.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <section class="services-grid">
                    <?php foreach ($services as $service): ?>
                        <?php
                            $serviceId = (int)$service['id'];
                            $isActive = (int)$service['active'] === 1;
                        ?>

                        <article class="service-card <?= $isActive ? '' : 'inactive' ?>">
                            <div class="service-title-row">
                                <div>
                                    <div class="service-title"><?= h($service['name']) ?></div>
                                    <div class="service-desc">
                                        <?= !empty($service['description']) ? h($service['description']) : 'Fara descriere.' ?>
                                    </div>
                                </div>
                            </div>

                            <div class="service-meta">
                                <span class="service-pill active">
                                    <?= $isActive ? 'Activ' : 'Inactiv' ?>
                                </span>

                                <span class="service-pill">
                                    <?= (int)$service['default_duration'] ?> min
                                </span>

                                <span class="service-pill">
                                    Ordine <?= (int)$service['sort_order'] ?>
                                </span>

                                <?= pz_revenue_render_badge(
                                    (string)($service['revenue_category'] ?? 'ddd'),
                                    ['size' => 'sm']
                                ) ?>
                            </div>

                            <div class="service-actions">
                                <button class="btn" type="button" onclick="openEditServiceModal(<?= $serviceId ?>)">
                                    Editează
                                </button>

                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="service_id" value="<?= $serviceId ?>">
                                    <button class="btn" type="submit">
                                        <?= $isActive ? 'Dezactiveaza' : 'Activeaza' ?>
                                    </button>
                                </form>

                                <button class="btn danger" type="button" onclick="deleteService(<?= $serviceId ?>)">
                                    Șterge
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

        </div>
    </main>
</div>

<div class="modal" id="createServiceModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Serviciu nou</h2>
            <button class="modal-close" type="button" onclick="closeModal('createServiceModal')">&times;</button>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="form-grid">
                <div>
                    <label>Nume serviciu *</label>
                    <input type="text" name="name" required placeholder="Ex: Dezinsectie">
                </div>

                <div>
                    <label>Durata implicita</label>
                    <select name="default_duration">
                        <?php foreach ($durationOptions as $minutes => $label): ?>
                            <option value="<?= (int)$minutes ?>" <?= (int)$minutes === 60 ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Ordine afișare</label>
                    <input type="number" name="sort_order" value="0">
                </div>

                <div>
                    <label>Status</label>
                    <label class="service-checkbox">
                        <input type="checkbox" name="active" value="1" checked>
                        Activ
                    </label>
                </div>

                <div class="form-group full">
                    <label>Categorie venit</label>
                    <select name="revenue_category">
                        <?php foreach ($revenueCategories as $code => $info): ?>
                            <option value="<?= h($code) ?>" <?= $code === 'ddd' ? 'selected' : '' ?>>
                                <?= h($info['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11.5px;color:var(--pz-muted);margin-top:4px">
                        Determină pe ce linie de business apare venitul în rapoarte.
                    </div>
                </div>

                <div class="form-group full">
                    <label>Descriere</label>
                    <textarea name="description" placeholder="Descriere scurta pentru uz intern..."></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('createServiceModal')">Renunță</button>
                    <button class="btn accent" type="submit">Salvează serviciul</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="editServiceModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Editează serviciu</h2>
            <button class="modal-close" type="button" onclick="closeModal('editServiceModal')">&times;</button>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="service_id" id="edit_service_id">

            <div class="form-grid">
                <div>
                    <label>Nume serviciu *</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>

                <div>
                    <label>Durata implicita</label>
                    <select name="default_duration" id="edit_default_duration">
                        <?php foreach ($durationOptions as $minutes => $label): ?>
                            <option value="<?= (int)$minutes ?>">
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Ordine afișare</label>
                    <input type="number" name="sort_order" id="edit_sort_order">
                </div>

                <div>
                    <label>Status</label>
                    <label class="service-checkbox">
                        <input type="checkbox" name="active" value="1" id="edit_active">
                        Activ
                    </label>
                </div>

                <div class="form-group full">
                    <label>Categorie venit</label>
                    <select name="revenue_category" id="edit_revenue_category">
                        <?php foreach ($revenueCategories as $code => $info): ?>
                            <option value="<?= h($code) ?>"><?= h($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11.5px;color:var(--pz-muted);margin-top:4px">
                        Determină pe ce linie de business apare venitul în rapoarte.
                    </div>
                </div>

                <div class="form-group full">
                    <label>Descriere</label>
                    <textarea name="description" id="edit_description"></textarea>
                </div>
            </div>

            <div class="actions-row">
                <div></div>

                <div class="actions-right">
                    <button class="btn" type="button" onclick="closeModal('editServiceModal')">Renunță</button>
                    <button class="btn accent" type="submit">Salvează modificarile</button>
                </div>
            </div>
        </form>
    </div>
</div>

<form method="post" id="deleteServiceForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="service_id" id="delete_service_id">
</form>

<script>
const servicesData = <?= json_encode($servicesForJs, JSON_UNESCAPED_UNICODE) ?>;

function openModal(id) {
    document.getElementById(id)?.classList.add('open');
}

function closeModal(id) {
    document.getElementById(id)?.classList.remove('open');
}

function openCreateServiceModal() {
    openModal('createServiceModal');
}

function openEditServiceModal(id) {
    const service = servicesData[id];

    if (!service) {
        alert('Serviciul nu a fost gasit.');
        return;
    }

    document.getElementById('edit_service_id').value = service.id || '';
    document.getElementById('edit_name').value = service.name || '';
    document.getElementById('edit_description').value = service.description || '';
    document.getElementById('edit_default_duration').value = service.default_duration || 60;
    document.getElementById('edit_sort_order').value = service.sort_order || 0;
    document.getElementById('edit_active').checked = Number(service.active) === 1;
    document.getElementById('edit_revenue_category').value = service.revenue_category || 'ddd';

    openModal('editServiceModal');
}

function deleteService(id) {
    if (confirm('Sigur vrei sa stergi acest serviciu? Dacă este folosit, va fi dezactivat in loc sa fie șters.')) {
        document.getElementById('delete_service_id').value = id;
        document.getElementById('deleteServiceForm').submit();
    }
}

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', event => {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.open').forEach(modal => modal.classList.remove('open'));
    }
});
</script>
</body>
</html>
