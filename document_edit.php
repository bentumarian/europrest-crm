<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/document_core.php';

require_login();

/*
|--------------------------------------------------------------------------
| PestZone - document edit router
|--------------------------------------------------------------------------
| Acest fișier nu editeaza direct documentul.
| El citeste tipul documentului si trimite utilizatorul catre pagina corecta:
| - oferta          -> offers?edit=ID
| - contract        -> contracts.php?edit=ID
| - proces_verbal   -> service-reports?edit=ID
|
| Regula importanta:
| - doar documentele draft se pot edita
| - documentele emise/anulate merg in pagina de vizualizare
|--------------------------------------------------------------------------
*/

function pz_document_edit_h($value): string
{
    if (function_exists('e')) {
        return e($value);
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pz_document_edit_back_url(array $document): string
{
    $type = (string)($document['document_type'] ?? '');

    if ($type === 'oferta') {
        return 'offers';
    }
    if ($type === 'contract') {
        return 'contracts.php';
    }
    if ($type === 'proces_verbal') {
        return 'service-reports';
    }

    return 'dashboard.php';
}

function pz_document_edit_target_url(array $document): string
{
    $id = (int)($document['id'] ?? 0);
    $type = (string)($document['document_type'] ?? '');

    if ($type === 'oferta') {
        return 'offers?edit=' . $id;
    }
    if ($type === 'contract') {
        return 'contracts.php?edit=' . $id;
    }
    if ($type === 'proces_verbal') {
        return 'service-reports?edit=' . $id;
    }

    return 'document_view.php?id=' . $id;
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    ?>
    <!doctype html>
    <html lang="ro">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Document invalid</title>
        <style>
            body { margin: 0; font-family: Arial, sans-serif; background: #f6f7fb; color: #111827; }
            .wrap { max-width: 760px; margin: 60px auto; padding: 0 18px; }
            .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px; }
            .btn { display: inline-block; margin-top: 14px; padding: 10px 14px; border-radius: 10px; background: #111827; color: #fff; text-decoration: none; font-weight: 700; }
        </style>
    </head>
    <body>
        <div class="wrap">
            <div class="card">
                <h1>Document invalid</h1>
                <p>Lipseste ID-ul documentului.</p>
                <a class="btn" href="dashboard.php">Înapoi la dashboard</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$document = pzdoc_get_document($pdo, $id, false);

if (!$document) {
    http_response_code(404);
    ?>
    <!doctype html>
    <html lang="ro">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Document negasit</title>
        <style>
            body { margin: 0; font-family: Arial, sans-serif; background: #f6f7fb; color: #111827; }
            .wrap { max-width: 760px; margin: 60px auto; padding: 0 18px; }
            .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px; }
            .btn { display: inline-block; margin-top: 14px; padding: 10px 14px; border-radius: 10px; background: #111827; color: #fff; text-decoration: none; font-weight: 700; }
        </style>
    </head>
    <body>
        <div class="wrap">
            <div class="card">
                <h1>Document negasit</h1>
                <p>Documentul solicitat nu există sau a fost șters.</p>
                <a class="btn" href="dashboard.php">Înapoi la dashboard</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$status = (string)($document['status'] ?? '');

if ($status !== 'draft') {
    header('Location: document_view.php?id=' . $id . '&notice=locked');
    exit;
}

$target = pz_document_edit_target_url($document);
header('Location: ' . $target);
exit;
