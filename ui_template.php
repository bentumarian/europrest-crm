<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

if (!is_admin()) {
    header('Location: calendar.php');
    exit;
}

$pz_page_title = 'Template UI';
$pz_page_breadcrumbs = ['Identitate interna'];
$pz_topbar_opts = [
    'placeholder' => 'Caută in template...',
    'primary_label' => 'Client nou',
    'primary_href' => 'clients.php?open_create=1',
];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Template UI - PestZone</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700;750&display=swap" rel="stylesheet">

<?php app_theme_css(); ?>

<style>
:root {
    --pzui-bg: #F8FAFC;
    --pzui-surface: #FFFFFF;
    --pzui-soft: #F8FAFC;
    --pzui-line: #E2E8F0;
    --pzui-line-soft: #F1F5F9;
    --pzui-title: #0F172A;
    --pzui-text: #334155;
    --pzui-muted: #64748B;
    --pzui-faint: #94A3B8;
    --pzui-blue: #2563EB;
    --pzui-blue-dark: #1E3A8A;
    --pzui-blue-soft: #EFF6FF;
    --pzui-green: #166534;
    --pzui-green-soft: #F0FDF4;
    --pzui-orange: #9A3412;
    --pzui-orange-soft: #FFF7ED;
    --pzui-red: #991B1B;
    --pzui-red-soft: #FEF2F2;
}

body,
.layout,
.main,
.content {
    background: var(--pzui-bg);
}

.app-topbar {
    background: #FFFFFF !important;
    border-bottom: 1px solid var(--pzui-line) !important;
    box-shadow: none !important;
}

.app-topbar .tb-search,
.app-topbar .tb-iconbtn,
.app-topbar .tb-bell {
    border-radius: 4px !important;
    box-shadow: none !important;
    width: 34px !important;
    height: 34px !important;
}

.app-topbar .tb-search {
    width: auto !important;
    height: 34px !important;
}

.sidebar {
    background: #FFFFFF !important;
    border-right: 1px solid var(--pzui-line) !important;
    box-shadow: none !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
}

.sidebar::before,
.sidebar::after {
    display: none !important;
}

.sidebar-brand {
    border-bottom: 1px solid var(--pzui-line) !important;
    background: #FFFFFF !important;
}

.sidebar-nav {
    gap: 2px !important;
    padding: 12px 10px !important;
}

.nav-item,
.sidebar .nav-group-button,
.sidebar .nav-group-button.nav-item,
.nav-subitem {
    min-height: 36px !important;
    border: 1px solid transparent !important;
    border-radius: 6px !important;
    background: transparent !important;
    color: var(--pzui-text) !important;
    box-shadow: none !important;
    font-size: 13px !important;
    font-weight: 650 !important;
}

.nav-item:hover,
.sidebar .nav-group-button:hover,
.nav-subitem:hover {
    background: var(--pzui-soft) !important;
    border-color: var(--pzui-line-soft) !important;
    color: var(--pzui-title) !important;
}

.nav-item.active,
.sidebar .nav-group-button.active,
.sidebar .nav-group-button.open,
.sidebar .nav-group-button.active.open,
.nav-subitem.active {
    background: var(--pzui-blue-soft) !important;
    border-color: #BFDBFE !important;
    color: var(--pzui-blue-dark) !important;
    box-shadow: none !important;
}

.nav-item.active::before {
    width: 2px !important;
    background: var(--pzui-blue) !important;
    box-shadow: none !important;
}

.nav-item svg,
.sidebar .nav-group-button svg,
.nav-subitem svg,
.logout-btn svg {
    stroke: currentColor !important;
}

.nav-icon {
    color: inherit !important;
}

.nav-submenu {
    gap: 2px !important;
    margin: 2px 0 4px !important;
}

.nav-subitem {
    padding-left: 40px !important;
    font-size: 12.5px !important;
}

.nav-chevron {
    color: var(--pzui-muted) !important;
}

.sidebar-footer {
    border-top: 1px solid var(--pzui-line) !important;
    background: #FFFFFF !important;
}

.sidebar-user {
    color: var(--pzui-muted) !important;
    font-size: 12px !important;
    font-weight: 650 !important;
}

.sidebar-user-label {
    color: var(--pzui-muted) !important;
    font-size: 11px !important;
}

.sidebar-user-name {
    color: var(--pzui-title) !important;
    font-size: 13px !important;
    font-weight: 750 !important;
}

.logout-btn {
    min-height: 34px !important;
    border: 1px solid var(--pzui-line) !important;
    border-radius: 6px !important;
    background: #FFFFFF !important;
    color: var(--pzui-text) !important;
    box-shadow: none !important;
}

.logout-btn:hover {
    background: var(--pzui-soft) !important;
    border-color: var(--pzui-line) !important;
    color: var(--pzui-title) !important;
}

.pzui-page {
    --radius: 8px;
    max-width: 1440px;
    margin: 0 auto;
    display: grid;
    gap: 14px;
    color: var(--pzui-text);
    font-family: "IBM Plex Sans", "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.pzui-page *,
.pzui-page *::before,
.pzui-page *::after {
    letter-spacing: 0;
    box-shadow: none;
}

.pzui-panel,
.pzui-card,
.pzui-table-card,
.pzui-page-header {
    background: var(--pzui-surface);
    border: 1px solid var(--pzui-line);
    border-radius: var(--radius);
}

.pzui-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 18px;
}

.pzui-kicker {
    margin: 0 0 5px;
    color: var(--pzui-muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.pzui-page h1,
.pzui-page h2,
.pzui-page h3 {
    margin: 0;
    color: var(--pzui-title);
}

.pzui-page h1 {
    font-size: 24px;
    line-height: 1.15;
    font-weight: 700;
}

.pzui-page h2 {
    font-size: 16px;
    line-height: 1.25;
    font-weight: 700;
}

.pzui-page h3 {
    font-size: 13px;
    line-height: 1.3;
    font-weight: 700;
}

.pzui-page p {
    margin: 5px 0 0;
    color: var(--pzui-muted);
    font-size: 12.5px;
    line-height: 1.45;
}

.pzui-actions {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

.pzui-btn {
    min-height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 7px 11px;
    border: 1px solid var(--pzui-line);
    border-radius: 4px;
    background: #fff;
    color: var(--pzui-text);
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.pzui-btn.primary {
    background: var(--pzui-blue);
    border-color: var(--pzui-blue);
    color: #fff;
}

.pzui-btn.soft {
    background: var(--pzui-soft);
}

.pzui-btn svg,
.pzui-icon-btn svg {
    width: 14px;
    height: 14px;
    stroke: currentColor;
    stroke-width: 1.8;
    fill: none;
}

.pzui-icon-btn {
    width: 34px;
    min-width: 34px;
    padding: 0;
}

.pzui-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

.pzui-stat {
    padding: 13px 14px;
}

.pzui-stat-label {
    color: var(--pzui-muted);
    font-size: 11.5px;
    font-weight: 700;
}

.pzui-stat-value {
    margin-top: 6px;
    color: var(--pzui-title);
    font-size: 24px;
    line-height: 1;
    font-weight: 750;
}

.pzui-stat-note {
    margin-top: 5px;
    color: var(--pzui-muted);
    font-size: 11.5px;
    font-weight: 600;
}

.pzui-workspace {
    display: grid;
    grid-template-columns: minmax(0, .78fr) minmax(0, 1.22fr);
    gap: 14px;
    align-items: start;
}

.pzui-card-head {
    min-height: 52px;
    padding: 12px 14px;
    border-bottom: 1px solid var(--pzui-line-soft);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.pzui-card-body {
    padding: 14px;
}

.pzui-search {
    display: grid;
    grid-template-columns: 72px 34px 34px minmax(180px, 1fr);
    gap: 6px;
    padding: 10px;
    border-bottom: 1px solid var(--pzui-line-soft);
    background: var(--pzui-soft);
}

.pzui-searchbox {
    position: relative;
    min-width: 0;
}

.pzui-searchbox svg {
    position: absolute;
    left: 10px;
    top: 50%;
    width: 14px;
    height: 14px;
    transform: translateY(-50%);
    stroke: var(--pzui-muted);
    stroke-width: 1.8;
    fill: none;
    pointer-events: none;
}

.pzui-searchbox .pzui-input {
    padding-left: 31px;
}

.pzui-field {
    display: grid;
    gap: 5px;
}

.pzui-label {
    color: var(--pzui-muted);
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
}

.pzui-input,
.pzui-select,
.pzui-textarea {
    width: 100%;
    min-height: 34px;
    border: 1px solid var(--pzui-line);
    border-radius: 4px;
    background: #fff;
    color: var(--pzui-text);
    padding: 7px 9px;
    font-size: 12.5px;
    font-weight: 600;
    outline: none;
}

.pzui-input::placeholder,
.pzui-textarea::placeholder {
    color: var(--pzui-faint);
}

.pzui-textarea {
    min-height: 82px;
    resize: vertical;
}

.pzui-input:focus,
.pzui-select:focus,
.pzui-textarea:focus {
    border-color: var(--pzui-blue);
}

.pzui-client-list {
    display: grid;
}

.pzui-client-row {
    min-height: 40px;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--pzui-line-soft);
    background: #fff;
}

.pzui-client-row:last-child {
    border-bottom: 0;
}

.pzui-client-row.active {
    background: var(--pzui-blue-soft);
}

.pzui-title-line {
    color: var(--pzui-title);
    font-size: 13px;
    font-weight: 750;
    overflow-wrap: anywhere;
}

.pzui-meta {
    margin-top: 3px;
    color: var(--pzui-muted);
    font-size: 12px;
    line-height: 1.35;
}

.pzui-pill {
    min-height: 21px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 7px;
    border-radius: 4px;
    border: 1px solid var(--pzui-line);
    background: #fff;
    color: var(--pzui-muted);
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.pzui-pill.green {
    border-color: #BBF7D0;
    background: var(--pzui-green-soft);
    color: var(--pzui-green);
}

.pzui-pill.orange {
    border-color: #FED7AA;
    background: var(--pzui-orange-soft);
    color: var(--pzui-orange);
}

.pzui-profile-top {
    padding: 14px;
    border-bottom: 1px solid var(--pzui-line-soft);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.pzui-profile-name {
    color: var(--pzui-title);
    font-size: 20px;
    line-height: 1.15;
    font-weight: 750;
}

.pzui-tabs {
    display: flex;
    gap: 2px;
    padding: 0 12px;
    border-bottom: 1px solid var(--pzui-line-soft);
    overflow-x: auto;
    background: #fff;
}

.pzui-tab {
    min-height: 38px;
    display: inline-flex;
    align-items: center;
    padding: 0 10px;
    border-bottom: 2px solid transparent;
    color: var(--pzui-muted);
    font-size: 12.5px;
    font-weight: 750;
    white-space: nowrap;
}

.pzui-tab.active {
    border-bottom-color: var(--pzui-blue);
    color: var(--pzui-blue);
}

.pzui-grid-2,
.pzui-grid-3 {
    display: grid;
    gap: 10px;
}

.pzui-grid-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.pzui-grid-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.pzui-info {
    padding: 10px;
    border: 1px solid var(--pzui-line-soft);
    border-radius: 6px;
    background: var(--pzui-soft);
}

.pzui-info-label {
    color: var(--pzui-muted);
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
}

.pzui-info-value {
    margin-top: 3px;
    color: var(--pzui-title);
    font-size: 12.5px;
    font-weight: 700;
    line-height: 1.35;
}

.pzui-section {
    display: grid;
    gap: 10px;
}

.pzui-section + .pzui-section {
    margin-top: 16px;
}

.pzui-table {
    width: 100%;
    border-collapse: collapse;
}

.pzui-table th,
.pzui-table td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--pzui-line-soft);
    text-align: left;
    vertical-align: middle;
}

.pzui-table th {
    background: var(--pzui-soft);
    color: var(--pzui-muted);
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
}

.pzui-table td {
    color: var(--pzui-text);
    font-size: 12.5px;
    font-weight: 600;
}

.pzui-dropzone {
    min-height: 112px;
    border: 1px dashed #CBD5E1;
    border-radius: 6px;
    background: var(--pzui-soft);
    display: grid;
    place-items: center;
    padding: 16px;
    text-align: center;
}

.pzui-dropzone strong {
    display: block;
    color: var(--pzui-title);
    font-size: 13px;
}

.pzui-dropzone span {
    display: block;
    margin-top: 5px;
    color: var(--pzui-muted);
    font-size: 12px;
}

.pzui-form-preview {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 14px;
}

.pzui-note {
    padding: 10px 12px;
    border: 1px solid #BFDBFE;
    border-radius: 6px;
    background: var(--pzui-blue-soft);
    color: var(--pzui-blue-dark);
    font-size: 12.5px;
    font-weight: 650;
}

.pzui-rules {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, .85fr);
    gap: 14px;
}

.pzui-rule-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}

.pzui-rule-grid.three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.pzui-rule {
    min-height: 38px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 10px;
    border: 1px solid var(--pzui-line-soft);
    border-radius: 6px;
    background: var(--pzui-soft);
    color: var(--pzui-text);
    font-size: 12.5px;
    font-weight: 650;
}

.pzui-rule-mark {
    width: 6px;
    height: 6px;
    flex: 0 0 6px;
    border-radius: 50%;
    background: var(--pzui-blue);
}

.pzui-font-sample {
    padding: 14px;
    border: 1px solid var(--pzui-line-soft);
    border-radius: 6px;
    background: var(--pzui-soft);
}

.pzui-font-big {
    color: var(--pzui-title);
    font-size: 25px;
    line-height: 1;
    font-weight: 750;
}

.pzui-font-row {
    margin-top: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    color: var(--pzui-muted);
    font-size: 12px;
    font-weight: 650;
}

.pzui-palette {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 10px;
}

.pzui-swatch {
    overflow: hidden;
    border: 1px solid var(--pzui-line);
    border-radius: 6px;
    background: #fff;
}

.pzui-swatch-color {
    height: 48px;
    border-bottom: 1px solid var(--pzui-line-soft);
}

.pzui-swatch-body {
    padding: 8px;
}

.pzui-swatch-name {
    color: var(--pzui-title);
    font-size: 12px;
    font-weight: 750;
}

.pzui-swatch-code {
    margin-top: 2px;
    color: var(--pzui-muted);
    font-size: 11px;
    font-weight: 650;
}

@media (max-width: 1180px) {
    .pzui-workspace,
    .pzui-form-preview,
    .pzui-rules {
        grid-template-columns: 1fr;
    }

    .pzui-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .pzui-palette {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .pzui-page-header,
    .pzui-profile-top {
        display: grid;
    }

    .pzui-actions {
        justify-content: flex-start;
    }

    .pzui-btn {
        min-height: 32px;
        padding: 6px 9px;
    }

    .pzui-search {
        grid-template-columns: 68px 32px 32px minmax(0, 1fr);
        overflow-x: auto;
    }

    .pzui-summary,
    .pzui-grid-2,
    .pzui-grid-3,
    .pzui-rule-grid,
    .pzui-palette {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('ui_template', true); ?>
    <style>
    .sidebar {
        background: #12345A !important;
        border-right: 1px solid #0E2A49 !important;
        box-shadow: none !important;
        backdrop-filter: none !important;
        -webkit-backdrop-filter: none !important;
    }

    .sidebar::before,
    .sidebar::after {
        display: none !important;
    }

    .sidebar-brand {
        background: #12345A !important;
        border-bottom: 1px solid rgba(255, 255, 255, .10) !important;
    }

    .sidebar-nav {
        gap: 2px !important;
        padding: 12px 10px !important;
    }

    .nav-item,
    .sidebar .nav-group-button,
    .sidebar .nav-group-button.nav-item,
    .nav-subitem {
        min-height: 36px !important;
        border: 1px solid transparent !important;
        border-radius: 6px !important;
        background: transparent !important;
        color: rgba(255, 255, 255, .78) !important;
        box-shadow: none !important;
        font-size: 13px !important;
        font-weight: 650 !important;
    }

    .nav-item:hover,
    .sidebar .nav-group-button:hover,
    .nav-subitem:hover {
        background: rgba(255, 255, 255, .08) !important;
        border-color: rgba(255, 255, 255, .08) !important;
        color: #FFFFFF !important;
    }

    .nav-item.active,
    .sidebar .nav-group-button.active,
    .sidebar .nav-group-button.open,
    .sidebar .nav-group-button.active.open,
    .nav-subitem.active {
        background: rgba(255, 255, 255, .13) !important;
        border-color: rgba(255, 255, 255, .16) !important;
        color: #FFFFFF !important;
        box-shadow: none !important;
    }

    .nav-item.active::before {
        width: 2px !important;
        background: #60A5FA !important;
        box-shadow: none !important;
    }

    .nav-item svg,
    .sidebar .nav-group-button svg,
    .nav-subitem svg,
    .logout-btn svg {
        stroke: currentColor !important;
    }

    .nav-icon {
        color: inherit !important;
    }

    .nav-submenu {
        gap: 2px !important;
        margin: 2px 0 4px !important;
    }

    .nav-subitem {
        padding-left: 40px !important;
        font-size: 12.5px !important;
        color: rgba(255, 255, 255, .68) !important;
    }

    .nav-chevron {
        color: rgba(255, 255, 255, .58) !important;
    }

    .sidebar-footer {
        border-top: 1px solid rgba(255, 255, 255, .10) !important;
        background: #12345A !important;
    }

    .sidebar-user {
        color: rgba(255, 255, 255, .68) !important;
        font-size: 12px !important;
        font-weight: 650 !important;
    }

    .sidebar-user-label {
        color: rgba(255, 255, 255, .58) !important;
        font-size: 11px !important;
    }

    .sidebar-user-name {
        color: #FFFFFF !important;
        font-size: 13px !important;
        font-weight: 750 !important;
    }

    .logout-btn {
        min-height: 34px !important;
        border: 1px solid rgba(255, 255, 255, .14) !important;
        border-radius: 6px !important;
        background: rgba(255, 255, 255, .06) !important;
        color: rgba(255, 255, 255, .84) !important;
        box-shadow: none !important;
    }

    .logout-btn:hover {
        background: rgba(255, 255, 255, .10) !important;
        border-color: rgba(255, 255, 255, .18) !important;
        color: #FFFFFF !important;
    }
    </style>

    <main class="main">
        <div class="content">
            <div class="pzui-page">
                <section class="pzui-page-header">
                    <div>
                        <div class="pzui-kicker">Identitate interna</div>
                        <h1>Template CRM curat</h1>
                        <p>Directie vizuala inspirata de MEFI CRM si SmartBill: pagini aerisite, tabele clare, formulare scurte si actiuni evidente.</p>
                    </div>
                    <div class="pzui-actions">
                        <a class="pzui-btn soft" href="clients.php">Înapoi la clienți</a>
                        <a class="pzui-btn primary" href="clients.php?open_create=1">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
                            Client nou
                        </a>
                    </div>
                </section>

                <section class="pzui-summary">
                    <div class="pzui-card pzui-stat">
                        <div class="pzui-stat-label">Clienți activi</div>
                        <div class="pzui-stat-value">128</div>
                        <div class="pzui-stat-note">+12 in ultimele 30 zile</div>
                    </div>
                    <div class="pzui-card pzui-stat">
                        <div class="pzui-stat-label">Locații monitorizate</div>
                        <div class="pzui-stat-value">342</div>
                        <div class="pzui-stat-note">puncte de lucru active</div>
                    </div>
                    <div class="pzui-card pzui-stat">
                        <div class="pzui-stat-label">Lucrări luna</div>
                        <div class="pzui-stat-value">214</div>
                        <div class="pzui-stat-note">intervenții si rapeluri</div>
                    </div>
                    <div class="pzui-card pzui-stat">
                        <div class="pzui-stat-label">Facturi emise</div>
                        <div class="pzui-stat-value">89</div>
                        <div class="pzui-stat-note">status SmartBill sincronizat</div>
                    </div>
                </section>

                <section class="pzui-workspace">
                    <aside class="pzui-table-card">
                        <div class="pzui-card-head">
                            <div>
                                <h2>Clienți</h2>
                                <p>Lista simpla, fara elemente decorative inutile.</p>
                            </div>
                            <span class="pzui-pill">128 rezultate</span>
                        </div>
                        <div class="pzui-search">
                            <select class="pzui-select" aria-label="Randuri pe pagina">
                                <option>25</option>
                                <option>50</option>
                                <option>100</option>
                            </select>
                            <button class="pzui-btn pzui-icon-btn" type="button" aria-label="Filtre">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16"></path><path d="M7 12h10"></path><path d="M10 18h4"></path></svg>
                            </button>
                            <button class="pzui-btn pzui-icon-btn" type="button" aria-label="Refresh">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7"></path><path d="M20 5v6h-6"></path></svg>
                            </button>
                            <label class="pzui-searchbox">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
                                <input class="pzui-input" type="search" placeholder="Caută după nume, CUI, telefon...">
                            </label>
                        </div>
                        <div class="pzui-client-list">
                            <div class="pzui-client-row active">
                                <div>
                                    <div class="pzui-title-line">SKY CERT GLOBAL S.R.L.</div>
                                    <div class="pzui-meta">CUI 12345678 · Constanta · 3 locații</div>
                                </div>
                                <span class="pzui-pill green">Activ</span>
                            </div>
                            <div class="pzui-client-row">
                                <div>
                                    <div class="pzui-title-line">EUROPREST TEAM 98 S.R.L.</div>
                                    <div class="pzui-meta">CUI 10135994 · Bucuresti · 8 locații</div>
                                </div>
                                <span class="pzui-pill green">Activ</span>
                            </div>
                            <div class="pzui-client-row">
                                <div>
                                    <div class="pzui-title-line">CRISTMAR BARBER SHOP S.R.L.</div>
                                    <div class="pzui-meta">CUI 52652511 · Constanta · 1 locație</div>
                                </div>
                                <span class="pzui-pill orange">Interventie</span>
                            </div>
                        </div>
                    </aside>

                    <article class="pzui-panel">
                        <div class="pzui-profile-top">
                            <div>
                                <div class="pzui-profile-name">SKY CERT GLOBAL S.R.L.</div>
                                <p>CUI 12345678 · J13/0000/2026 · Client activ</p>
                            </div>
                            <div class="pzui-actions">
                                <button class="pzui-btn">Programeaza</button>
                                <button class="pzui-btn">Factura</button>
                                <button class="pzui-btn primary">Editează</button>
                            </div>
                        </div>

                        <nav class="pzui-tabs" aria-label="Fișa client">
                            <span class="pzui-tab active">Profil</span>
                            <span class="pzui-tab">Contacte</span>
                            <span class="pzui-tab">Locații</span>
                            <span class="pzui-tab">Fisiere</span>
                            <span class="pzui-tab">Lucrări</span>
                            <span class="pzui-tab">Facturi</span>
                            <span class="pzui-tab">Istoric</span>
                        </nav>

                        <div class="pzui-card-body">
                            <div class="pzui-section">
                                <h2>Date firma</h2>
                                <div class="pzui-grid-3">
                                    <div class="pzui-info">
                                        <div class="pzui-info-label">Denumire</div>
                                        <div class="pzui-info-value">SKY CERT GLOBAL S.R.L.</div>
                                    </div>
                                    <div class="pzui-info">
                                        <div class="pzui-info-label">CUI</div>
                                        <div class="pzui-info-value">12345678</div>
                                    </div>
                                    <div class="pzui-info">
                                        <div class="pzui-info-label">Telefon</div>
                                        <div class="pzui-info-value">0734 000 999</div>
                                    </div>
                                </div>
                            </div>

                            <div class="pzui-section">
                                <h2>Locații</h2>
                                <table class="pzui-table">
                                    <thead>
                                        <tr>
                                            <th>Nume</th>
                                            <th>Adresa</th>
                                            <th>Contact</th>
                                            <th>Suprafață</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Restaurant Tomis</td>
                                            <td>Constanta, b-dul Tomis, Nr. 46</td>
                                            <td>Memet Costel · 0734 000 999</td>
                                            <td>180 mp</td>
                                            <td><span class="pzui-pill green">Activ</span></td>
                                        </tr>
                                        <tr>
                                            <td>Depozit aprovizionare</td>
                                            <td>Constanta, zona industriala</td>
                                            <td>Amalia Zelca · 0734 000 999</td>
                                            <td>420 mp</td>
                                            <td><span class="pzui-pill green">Activ</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="pzui-section">
                                <h2>Fisiere client</h2>
                                <div class="pzui-dropzone">
                                    <div>
                                        <strong>Incarca documente pentru client</strong>
                                        <span>Contracte, poze, autorizatii, schite, documente receptionate.</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                </section>

                <section class="pzui-form-preview">
                    <div class="pzui-panel">
                        <div class="pzui-card-head">
                            <div>
                                <h2>Adaugăre client</h2>
                                <p>Varianta scurta pentru lucru zilnic.</p>
                            </div>
                        </div>
                        <div class="pzui-card-body">
                            <div class="pzui-note">Flux propus: introduci CUI, preiei ANAF, apoi completezi doar ce lipseste.</div>
                            <div class="pzui-section" style="margin-top:14px;">
                                <div class="pzui-grid-2">
                                    <label class="pzui-field">
                                        <span class="pzui-label">CUI firma</span>
                                        <input class="pzui-input" value="12345678">
                                    </label>
                                    <label class="pzui-field">
                                        <span class="pzui-label">Denumire</span>
                                        <input class="pzui-input" value="SKY CERT GLOBAL S.R.L.">
                                    </label>
                                    <label class="pzui-field">
                                        <span class="pzui-label">Reprezentant</span>
                                        <input class="pzui-input" value="Amalia Zelca">
                                    </label>
                                    <label class="pzui-field">
                                        <span class="pzui-label">Telefon</span>
                                        <input class="pzui-input" value="0734 000 999">
                                    </label>
                                    <label class="pzui-field">
                                        <span class="pzui-label">Banca</span>
                                        <input class="pzui-input" placeholder="Optional">
                                    </label>
                                    <label class="pzui-field">
                                        <span class="pzui-label">IBAN</span>
                                        <input class="pzui-input" placeholder="Optional">
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pzui-panel">
                        <div class="pzui-card-head">
                            <div>
                                <h2>Prima locație</h2>
                                <p>Obligatorie pentru orice client operational.</p>
                            </div>
                        </div>
                        <div class="pzui-card-body">
                            <div class="pzui-grid-2">
                                <label class="pzui-field">
                                    <span class="pzui-label">Nume locație</span>
                                    <input class="pzui-input" value="Restaurant Tomis">
                                </label>
                                <label class="pzui-field">
                                    <span class="pzui-label">Suprafață</span>
                                    <input class="pzui-input" value="180 mp">
                                </label>
                                <label class="pzui-field">
                                    <span class="pzui-label">Persoană contact</span>
                                    <input class="pzui-input" value="Memet Costel">
                                </label>
                                <label class="pzui-field">
                                    <span class="pzui-label">Telefon locație</span>
                                    <input class="pzui-input" value="0734 000 999">
                                </label>
                            </div>
                            <label class="pzui-field" style="margin-top:12px;">
                                <span class="pzui-label">Adresa locație</span>
                                <textarea class="pzui-textarea">Constanta, b-dul Tomis, Nr. 46</textarea>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="pzui-rules">
                    <div class="pzui-panel">
                        <div class="pzui-card-head">
                            <div>
                                <h2>Reguli UI</h2>
                                <p>Standard intern pentru interfata.</p>
                            </div>
                        </div>
                        <div class="pzui-card-body">
                            <div class="pzui-rule-grid">
                                <div class="pzui-rule"><span class="pzui-rule-mark"></span>Texte scurte</div>
                                <div class="pzui-rule"><span class="pzui-rule-mark"></span>Fara explicatii evidente</div>
                                <div class="pzui-rule"><span class="pzui-rule-mark"></span>Iconuri pentru actiuni rapide</div>
                                <div class="pzui-rule"><span class="pzui-rule-mark"></span>Contur fin, fara umbre</div>
                                <div class="pzui-rule"><span class="pzui-rule-mark"></span>Formulare compacte</div>
                                <div class="pzui-rule"><span class="pzui-rule-mark"></span>Ajutor doar unde conteaza</div>
                            </div>
                        </div>
                    </div>

                    <div class="pzui-panel">
                        <div class="pzui-card-head">
                            <div>
                                <h2>Font</h2>
                                <p>Propunere: IBM Plex Sans.</p>
                            </div>
                            <span class="pzui-pill">Digital</span>
                        </div>
                        <div class="pzui-card-body">
                            <div class="pzui-font-sample">
                                <div class="pzui-font-big">PestZone CRM</div>
                                <div class="pzui-font-row">
                                    <span>Clienți · Lucrări · Facturare</span>
                                    <span>12.5px / 34px</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pzui-panel">
                    <div class="pzui-card-head">
                        <div>
                            <h2>Spatiere & mobil</h2>
                            <p>Reguli pentru implementarea globala.</p>
                        </div>
                    </div>
                    <div class="pzui-card-body">
                        <div class="pzui-rule-grid three">
                            <div class="pzui-rule"><span class="pzui-rule-mark"></span>Grid pagina: 14px</div>
                            <div class="pzui-rule"><span class="pzui-rule-mark"></span>Card padding: 12-14px</div>
                            <div class="pzui-rule"><span class="pzui-rule-mark"></span>Input/button: 32-36px</div>
                            <div class="pzui-rule"><span class="pzui-rule-mark"></span>Tabel rand: 38-40px</div>
                            <div class="pzui-rule"><span class="pzui-rule-mark"></span>Mobil: actiuni icon</div>
                            <div class="pzui-rule"><span class="pzui-rule-mark"></span>Mobil: o coloana</div>
                            <div class="pzui-rule"><span class="pzui-rule-mark"></span>Fara text blocat</div>
                            <div class="pzui-rule"><span class="pzui-rule-mark"></span>Fara overflow lateral</div>
                            <div class="pzui-rule"><span class="pzui-rule-mark"></span>Touch target minim 32px</div>
                        </div>
                    </div>
                </section>

                <section class="pzui-panel">
                    <div class="pzui-card-head">
                        <div>
                            <h2>Paleta</h2>
                            <p>Culori standard pentru platforma.</p>
                        </div>
                    </div>
                    <div class="pzui-card-body">
                        <div class="pzui-palette">
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#12345A"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Brand</div>
                                    <div class="pzui-swatch-code">#12345A</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#2563EB"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Actiune</div>
                                    <div class="pzui-swatch-code">#2563EB</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#F8FAFC"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Fundal</div>
                                    <div class="pzui-swatch-code">#F8FAFC</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#FFFFFF"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Suprafață</div>
                                    <div class="pzui-swatch-code">#FFFFFF</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#E2E8F0"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Contur</div>
                                    <div class="pzui-swatch-code">#E2E8F0</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#0F172A"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Titlu</div>
                                    <div class="pzui-swatch-code">#0F172A</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#334155"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Text</div>
                                    <div class="pzui-swatch-code">#334155</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#64748B"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Secundar</div>
                                    <div class="pzui-swatch-code">#64748B</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#F0FDF4"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Succes bg</div>
                                    <div class="pzui-swatch-code">#F0FDF4</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#166534"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Succes text</div>
                                    <div class="pzui-swatch-code">#166534</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#FFF7ED"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Atentie bg</div>
                                    <div class="pzui-swatch-code">#FFF7ED</div>
                                </div>
                            </div>
                            <div class="pzui-swatch">
                                <div class="pzui-swatch-color" style="background:#9A3412"></div>
                                <div class="pzui-swatch-body">
                                    <div class="pzui-swatch-name">Atentie text</div>
                                    <div class="pzui-swatch-code">#9A3412</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
</body>
</html>
