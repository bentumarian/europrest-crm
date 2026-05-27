<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

if (!is_admin()) {
    header('Location: calendar.php');
    exit;
}

$pz_page_title       = 'Design System';
$pz_page_breadcrumbs = ['Setări', 'Design System'];
$pz_topbar_opts      = ['placeholder' => 'Caută în design system...'];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Design System · <?= h(pz_app_name()) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php app_theme_css(); ?>
<style>

/* ═══════════════════════════════════════════════════════════════
   DESIGN TOKENS — sursa unică de adevăr pentru toată platforma
   Orice culoare, spacing sau border trebuie să vină de aici.
═══════════════════════════════════════════════════════════════ */
:root {
    /* Fundaluri */
    --pz-bg:       #F8FAFC;   /* pagina */
    --pz-surf:     #FFFFFF;   /* card / panel */
    --pz-soft:     #F8FAFC;   /* fundal subtil (input bg, table header) */

    /* Borduri */
    --pz-line:     #E2E8F0;   /* contur principal */
    --pz-lines:    #F1F5F9;   /* separator intern (între rânduri) */

    /* Text */
    --pz-title:    #0F172A;   /* titluri și valori importante */
    --pz-text:     #334155;   /* text normal */
    --pz-mu:       #64748B;   /* text secundar / muted */
    --pz-fa:       #94A3B8;   /* placeholder / hint */

    /* Acțiune (albastru) */
    --pz-bl:       #2563EB;   /* buton primar, link, accent */
    --pz-bld:      #1E3A8A;   /* text pe fundal albastru deschis */
    --pz-bls:      #EFF6FF;   /* fundal info */
    --pz-blb:      #BFDBFE;   /* bordură info */

    /* Succes (verde) */
    --pz-gr:       #166534;   /* text pe fundal verde */
    --pz-grs:      #F0FDF4;   /* fundal succes */
    --pz-grb:      #BBF7D0;   /* bordură succes */
    --pz-gr-acc:   #22C55E;   /* accent bar / dot verde */

    /* Atenție (portocaliu) */
    --pz-or:       #9A3412;   /* text pe fundal portocaliu */
    --pz-ors:      #FFF7ED;   /* fundal atenție */
    --pz-orb:      #FED7AA;   /* bordură atenție */
    --pz-or-acc:   #F97316;   /* accent bar / dot portocaliu */

    /* Pericol (roșu) */
    --pz-re:       #991B1B;   /* text pe fundal roșu */
    --pz-res:      #FEF2F2;   /* fundal pericol */
    --pz-reb:      #FECACA;   /* bordură pericol */
    --pz-re-acc:   #EF4444;   /* accent bar / dot roșu */

    /* Brand */
    --pz-brand:    #12345A;   /* sidebar navy */

    /* Geometrie */
    --pz-r:        8px;       /* border-radius card */
    --pz-rs:       4px;       /* border-radius buton / input */
    --pz-gap:      14px;      /* gap grid pagină */
}

/* ── Reset de bază ──────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body, .layout, .main, .content { background: var(--pz-bg); }

/* ── Sidebar navy (suprascrie tema globală) ─────────────────── */
.sidebar {
    background: var(--pz-brand) !important;
    border-right: 1px solid #0E2A49 !important;
    box-shadow: none !important;
    backdrop-filter: none !important;
}
.sidebar::before, .sidebar::after { display: none !important; }
.sidebar-brand {
    background: var(--pz-brand) !important;
    border-bottom: 1px solid rgba(255,255,255,.10) !important;
}
.nav-item,
.sidebar .nav-group-button,
.nav-subitem {
    color: rgba(255,255,255,.78) !important;
    border: 1px solid transparent !important;
    border-radius: 6px !important;
    background: transparent !important;
    box-shadow: none !important;
    font-size: 13px !important;
    font-weight: 600 !important;
}
.nav-item:hover, .sidebar .nav-group-button:hover, .nav-subitem:hover {
    background: rgba(255,255,255,.08) !important;
    color: #fff !important;
}
.nav-item.active, .sidebar .nav-group-button.active, .nav-subitem.active {
    background: rgba(255,255,255,.13) !important;
    border-color: rgba(255,255,255,.16) !important;
    color: #fff !important;
    box-shadow: none !important;
}
.nav-item.active::before { width: 2px !important; background: #60A5FA !important; box-shadow: none !important; }
.nav-icon { color: inherit !important; }
.nav-subitem { padding-left: 40px !important; font-size: 12.5px !important; color: rgba(255,255,255,.68) !important; }
.nav-chevron { color: rgba(255,255,255,.50) !important; }
.sidebar-footer { border-top: 1px solid rgba(255,255,255,.10) !important; background: var(--pz-brand) !important; }
.sidebar-user-name { color: #fff !important; font-size: 13px !important; font-weight: 700 !important; }
.sidebar-user-label { color: rgba(255,255,255,.55) !important; font-size: 11px !important; }
.logout-btn {
    border: 1px solid rgba(255,255,255,.14) !important;
    border-radius: 6px !important;
    background: rgba(255,255,255,.06) !important;
    color: rgba(255,255,255,.84) !important;
    box-shadow: none !important;
}
.logout-btn:hover { background: rgba(255,255,255,.10) !important; color: #fff !important; }

/* ── Topbar ──────────────────────────────────────────────────── */
.app-topbar {
    background: #fff !important;
    border-bottom: 1px solid var(--pz-line) !important;
    box-shadow: none !important;
}

/* ════════════════════════════════════════════════════════════════
   PAGINA DESIGN SYSTEM
════════════════════════════════════════════════════════════════ */
.ds {
    font-family: 'Satoshi', 'Inter', system-ui, -apple-system, sans-serif;
    font-size: 13px;
    color: var(--pz-text);
    max-width: 1400px;
    margin: 0 auto;
    display: grid;
    gap: var(--pz-gap);
    padding: 14px;
}
.ds *, .ds *::before, .ds *::after { box-shadow: none; }

/* ── Page header ─────────────────────────────────────────────── */
.ds-header {
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    padding: 20px 22px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}
.ds-header-kicker {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--pz-mu);
    margin-bottom: 6px;
}
.ds-header h1 {
    font-size: 22px;
    font-weight: 700;
    color: var(--pz-title);
    line-height: 1.2;
}
.ds-header p {
    margin-top: 6px;
    font-size: 13px;
    color: var(--pz-mu);
    line-height: 1.5;
    max-width: 560px;
}
.ds-header-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 5px;
    flex-shrink: 0;
}
.ds-version {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: var(--pz-rs);
    background: var(--pz-bls);
    color: var(--pz-bld);
    border: 1px solid var(--pz-blb);
}
.ds-date { font-size: 11px; color: var(--pz-mu); }

/* ── Table of contents ───────────────────────────────────────── */
.ds-toc {
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.ds-toc-label {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--pz-mu);
    letter-spacing: .4px;
    margin-right: 4px;
}
.ds-toc-link {
    font-size: 12px;
    font-weight: 600;
    color: var(--pz-bl);
    text-decoration: none;
    padding: 3px 8px;
    border-radius: 3px;
    background: var(--pz-bls);
    border: 1px solid var(--pz-blb);
}
.ds-toc-link:hover { background: var(--pz-blb); }

/* ── Chapter separator ───────────────────────────────────────── */
.ds-chapter {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 2px 0;
}
.ds-chapter-num {
    font-size: 10px;
    font-weight: 800;
    color: var(--pz-surf);
    background: var(--pz-mu);
    border-radius: 3px;
    padding: 2px 6px;
    letter-spacing: .2px;
}
.ds-chapter-title {
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
    color: var(--pz-mu);
    letter-spacing: .5px;
}
.ds-chapter::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--pz-line);
}

/* ── Card / Panel ────────────────────────────────────────────── */
.ds-card {
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    overflow: hidden;
}
.ds-card-head {
    padding: 12px 16px;
    border-bottom: 1px solid var(--pz-lines);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.ds-card-head h2 {
    font-size: 14px;
    font-weight: 700;
    color: var(--pz-title);
}
.ds-card-head p {
    font-size: 11.5px;
    color: var(--pz-mu);
    margin-top: 2px;
}
.ds-card-body { padding: 16px; }
.ds-card-body + .ds-card-body { border-top: 1px solid var(--pz-lines); }

/* ── Two-column grid ─────────────────────────────────────────── */
.ds-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: var(--pz-gap); }
.ds-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: var(--pz-gap); }
.ds-grid-4 { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px; }
.ds-grid-6 { display: grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap: 10px; }

/* ── Rule list (bullets) ─────────────────────────────────────── */
.ds-rules { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 7px; }
.ds-rules.col-2 { grid-template-columns: repeat(2, minmax(0,1fr)); }
.ds-rule {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 9px 11px;
    border: 1px solid var(--pz-lines);
    border-radius: 6px;
    background: var(--pz-soft);
    font-size: 12px;
    font-weight: 500;
    color: var(--pz-text);
    line-height: 1.4;
}
.ds-rule-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--pz-bl);
    flex-shrink: 0;
    margin-top: 4px;
}
.ds-rule.danger .ds-rule-dot { background: var(--pz-re-acc); }
.ds-rule.warning .ds-rule-dot { background: var(--pz-or-acc); }
.ds-rule.success .ds-rule-dot { background: var(--pz-gr-acc); }

/* ── Demo block ──────────────────────────────────────────────── */
.ds-demo {
    padding: 16px;
    border: 1px solid var(--pz-lines);
    border-radius: 6px;
    background: var(--pz-soft);
}
.ds-demo-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: var(--pz-fa);
    margin-bottom: 10px;
}

/* ── Token inline code ───────────────────────────────────────── */
.ds-token {
    display: inline-block;
    font-family: 'Courier New', Consolas, monospace;
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 3px;
    background: var(--pz-soft);
    border: 1px solid var(--pz-line);
    color: var(--pz-bld);
}

/* ════ 01 FUNDAȚIE ═══════════════════════════════════════════ */

/* Color swatches */
.ds-swatch {
    border: 1px solid var(--pz-line);
    border-radius: 6px;
    overflow: hidden;
    background: var(--pz-surf);
}
.ds-swatch-color { height: 44px; }
.ds-swatch-body { padding: 7px 9px; }
.ds-swatch-name { font-size: 11.5px; font-weight: 700; color: var(--pz-title); }
.ds-swatch-hex  { font-size: 10.5px; font-family: 'Courier New', monospace; color: var(--pz-mu); margin-top: 1px; }
.ds-swatch-use  { font-size: 10px; color: var(--pz-fa); margin-top: 2px; line-height: 1.3; }

/* Typography scale */
.ds-type-row {
    display: flex;
    align-items: baseline;
    gap: 16px;
    padding: 8px 0;
    border-bottom: 1px solid var(--pz-lines);
}
.ds-type-row:last-child { border-bottom: 0; }
.ds-type-meta { min-width: 140px; flex-shrink: 0; }
.ds-type-meta .ds-token { font-size: 10px; }
.ds-type-size { font-size: 10.5px; color: var(--pz-mu); margin-top: 3px; }
.ds-type-sample { color: var(--pz-title); }

/* Spacing scale */
.ds-space-row { display: flex; align-items: center; gap: 14px; padding: 6px 0; border-bottom: 1px solid var(--pz-lines); }
.ds-space-row:last-child { border-bottom: 0; }
.ds-space-bar { background: var(--pz-bl); height: 6px; border-radius: 3px; flex-shrink: 0; }
.ds-space-name { font-size: 12px; font-weight: 600; color: var(--pz-title); min-width: 80px; }
.ds-space-val  { font-size: 11.5px; color: var(--pz-mu); }
.ds-space-use  { font-size: 11px; color: var(--pz-fa); }

/* ════ 02 STATUS ═════════════════════════════════════════════ */

.ds-status-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 10px; }
.ds-status-block { border-radius: 8px; border: 1px solid var(--pz-line); overflow: hidden; }
.ds-status-head {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 13px;
    font-size: 12px; font-weight: 700; color: var(--pz-title);
    background: var(--pz-surf);
    border-bottom: 1px solid var(--pz-lines);
}
.ds-status-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.ds-status-body { padding: 11px 13px; }
.ds-status-when { font-size: 11px; font-weight: 600; color: var(--pz-mu); line-height: 1.55; margin-bottom: 9px; }
.ds-status-tokens { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 9px; }
.ds-status-token {
    padding: 2px 6px; border-radius: 3px;
    font-size: 9.5px; font-weight: 700;
    font-family: 'Courier New', monospace;
    background: rgba(255,255,255,.7);
    border: 1px solid rgba(0,0,0,.08);
    color: #333;
}
.ds-status-examples { display: flex; flex-wrap: wrap; gap: 5px; }

/* Badges */
.ds-badge {
    display: inline-flex; align-items: center;
    padding: 2px 8px; border-radius: 3px;
    font-size: 10.5px; font-weight: 700;
    white-space: nowrap; border: 1px solid;
}
.ds-badge.bl  { background: var(--pz-bls); color: var(--pz-bld); border-color: var(--pz-blb); }
.ds-badge.gr  { background: var(--pz-grs); color: var(--pz-gr);  border-color: var(--pz-grb); }
.ds-badge.or  { background: var(--pz-ors); color: var(--pz-or);  border-color: var(--pz-orb); }
.ds-badge.re  { background: var(--pz-res); color: var(--pz-re);  border-color: var(--pz-reb); }
.ds-badge.neu { background: var(--pz-soft); color: var(--pz-mu); border-color: var(--pz-line); }

/* Pills (rounded) */
.ds-pill {
    display: inline-flex; align-items: center;
    padding: 3px 9px; border-radius: 20px;
    font-size: 11px; font-weight: 700;
    white-space: nowrap; border: 1px solid;
}
.ds-pill.bl  { background: var(--pz-bls); color: var(--pz-bld); border-color: var(--pz-blb); }
.ds-pill.gr  { background: var(--pz-grs); color: var(--pz-gr);  border-color: var(--pz-grb); }
.ds-pill.or  { background: var(--pz-ors); color: var(--pz-or);  border-color: var(--pz-orb); }
.ds-pill.re  { background: var(--pz-res); color: var(--pz-re);  border-color: var(--pz-reb); }
.ds-pill.neu { background: var(--pz-soft); color: var(--pz-mu); border-color: var(--pz-line); }

/* ════ 03 COMPONENTE ═════════════════════════════════════════ */

/* Buttons */
.ds-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    min-height: 34px; padding: 7px 12px;
    border-radius: var(--pz-rs); border: 1px solid var(--pz-line);
    font-family: inherit; font-size: 12.5px; font-weight: 600;
    cursor: pointer; text-decoration: none; white-space: nowrap;
}
.ds-btn.primary   { background: var(--pz-bl);  border-color: var(--pz-bl);  color: #fff; }
.ds-btn.secondary { background: var(--pz-surf); border-color: var(--pz-line); color: var(--pz-text); }
.ds-btn.soft      { background: var(--pz-bls);  border-color: var(--pz-blb); color: var(--pz-bld); }
.ds-btn.danger    { background: var(--pz-res);  border-color: var(--pz-reb); color: var(--pz-re); }
.ds-btn.ghost     { background: transparent;    border-color: transparent;   color: var(--pz-bl); }
.ds-btn.sm { min-height: 28px; padding: 4px 9px; font-size: 11.5px; }
.ds-btn.icon { width: 34px; min-width: 34px; padding: 0; }

/* Inputs */
.ds-input, .ds-select, .ds-textarea {
    width: 100%; min-height: 34px;
    border: 1px solid var(--pz-line); border-radius: var(--pz-rs);
    background: var(--pz-surf); color: var(--pz-text);
    padding: 7px 10px; font-family: inherit; font-size: 12.5px; font-weight: 500;
    outline: none;
}
.ds-input:focus, .ds-select:focus, .ds-textarea:focus { border-color: var(--pz-bl); }
.ds-input::placeholder { color: var(--pz-fa); }
.ds-textarea { min-height: 80px; resize: vertical; }
.ds-label {
    display: block; font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .3px;
    color: var(--pz-mu); margin-bottom: 5px;
}
.ds-field { display: grid; gap: 5px; }

/* KPI cards */
.ds-kpi {
    padding: 12px 14px 14px;
    border: 1px solid var(--pz-line); border-left-width: 3px; border-left-style: solid;
    border-radius: var(--pz-r); background: var(--pz-surf);
    display: flex; flex-direction: column;
}
.ds-kpi.bl { border-left-color: var(--pz-bl); }
.ds-kpi.or { border-left-color: var(--pz-or-acc); }
.ds-kpi.re { border-left-color: var(--pz-re-acc); }
.ds-kpi.gr { border-left-color: var(--pz-gr-acc); }
.ds-kpi-lbl { font-size: 10px; font-weight: 700; color: var(--pz-mu); text-transform: uppercase; letter-spacing: .3px; }
.ds-kpi-val { font-size: 22px; font-weight: 700; color: var(--pz-title); margin-top: 5px; line-height: 1.1; }
.ds-kpi-val sub { font-size: 13px; font-weight: 500; color: var(--pz-mu); }
.ds-kpi-sub { font-size: 11px; color: var(--pz-mu); margin-top: auto; padding-top: 6px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }

/* Panel component */
.ds-panel { background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); overflow: hidden; display: flex; flex-direction: column; }
.ds-panel-head { padding: 9px 12px; border-bottom: 1px solid var(--pz-lines); display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.ds-panel-hc { flex: 1; display: flex; align-items: center; justify-content: space-between; gap: 8px; min-width: 0; }
.ds-panel-title { font-size: 12.5px; font-weight: 600; color: var(--pz-title); }
.ds-panel-meta  { font-size: 11px; color: var(--pz-mu); margin-top: 1px; }
.ds-drag { color: var(--pz-fa); cursor: grab; font-size: 15px; line-height: 1; flex-shrink: 0; user-select: none; }
.ds-panel-item {
    padding: 8px 12px; border-bottom: 1px solid var(--pz-lines);
    display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;
}
.ds-panel-item:last-child { border-bottom: 0; }
.ds-panel-item.warn { background: var(--pz-ors); }
.ds-panel-item.danger { background: var(--pz-res); }
.ds-item-n  { font-size: 12px; font-weight: 600; color: var(--pz-title); }
.ds-item-s  { font-size: 11px; color: var(--pz-mu); margin-top: 1px; }
.ds-item-v  { font-size: 12px; font-weight: 700; white-space: nowrap; }
.ds-item-v.re { color: var(--pz-re); }
.ds-item-v.or { color: var(--pz-or); }
.ds-item-spacer { flex: 1; }
.ds-panel-foot {
    padding: 8px 12px; font-size: 11px; font-weight: 600;
    color: var(--pz-bl); background: var(--pz-bls);
    border-top: 1px solid var(--pz-blb); flex-shrink: 0;
}

/* Table */
.ds-table { width: 100%; border-collapse: collapse; }
.ds-table th, .ds-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--pz-lines);
    text-align: left; vertical-align: middle;
}
.ds-table th {
    background: var(--pz-soft); color: var(--pz-mu);
    font-size: 10.5px; font-weight: 700; text-transform: uppercase;
}
.ds-table td { font-size: 12.5px; font-weight: 500; color: var(--pz-text); }
.ds-table tbody tr:hover { background: var(--pz-soft); }

/* Tabs */
.ds-tabs { display: flex; gap: 0; border-bottom: 1px solid var(--pz-lines); background: var(--pz-surf); overflow-x: auto; }
.ds-tab {
    min-height: 38px; display: inline-flex; align-items: center;
    padding: 0 12px; border-bottom: 2px solid transparent;
    font-size: 12.5px; font-weight: 600; color: var(--pz-mu);
    white-space: nowrap; cursor: pointer;
}
.ds-tab.active { border-bottom-color: var(--pz-bl); color: var(--pz-bl); }

/* Progress bar */
.ds-bar-wrap { height: 5px; background: var(--pz-lines); border-radius: 10px; overflow: hidden; }
.ds-bar { height: 100%; border-radius: 10px; }

/* Avatar */
.ds-avatar {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 700; color: #fff; flex-shrink: 0;
}

/* Note / info box */
.ds-note {
    padding: 10px 13px;
    border-left: 3px solid var(--pz-bl);
    border-radius: 0 6px 6px 0;
    background: var(--pz-bls);
    font-size: 12px; font-weight: 500; color: var(--pz-bld);
    line-height: 1.5;
}
.ds-note.warn  { border-left-color: var(--pz-or-acc); background: var(--pz-ors); color: var(--pz-or); }
.ds-note.danger { border-left-color: var(--pz-re-acc); background: var(--pz-res); color: var(--pz-re); }

/* Breakpoint table */
.ds-bp-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ds-bp-table th, .ds-bp-table td {
    padding: 9px 12px; border-bottom: 1px solid var(--pz-lines);
    text-align: left; vertical-align: top;
}
.ds-bp-table th { background: var(--pz-soft); color: var(--pz-mu); font-size: 10.5px; font-weight: 700; text-transform: uppercase; }
.ds-bp-badge { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 11px; font-weight: 700; font-family: 'Courier New', monospace; background: var(--pz-bls); color: var(--pz-bld); border: 1px solid var(--pz-blb); }

/* CSS var reference */
.ds-var-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 6px; }
.ds-var-row { display: flex; align-items: center; gap: 10px; padding: 7px 11px; border: 1px solid var(--pz-lines); border-radius: 6px; background: var(--pz-soft); }
.ds-var-dot { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; border: 1px solid rgba(0,0,0,.08); }
.ds-var-name { font-family: 'Courier New', monospace; font-size: 11px; font-weight: 700; color: var(--pz-bld); flex: 1; }
.ds-var-desc { font-size: 11px; color: var(--pz-mu); font-weight: 500; }

/* Checklist */
.ds-checklist { display: grid; gap: 6px; }
.ds-check-item { display: flex; align-items: flex-start; gap: 9px; padding: 8px 12px; border: 1px solid var(--pz-lines); border-radius: 6px; background: var(--pz-soft); font-size: 12px; }
.ds-check-icon { font-size: 14px; flex-shrink: 0; margin-top: 1px; }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 1100px) {
    .ds-grid-4, .ds-status-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
    .ds-grid-6 { grid-template-columns: repeat(3, minmax(0,1fr)); }
    .ds-rules { grid-template-columns: repeat(2, minmax(0,1fr)); }
    .ds-var-grid { grid-template-columns: 1fr; }
}
@media (max-width: 720px) {
    .ds-grid-2, .ds-grid-3, .ds-grid-4, .ds-status-grid { grid-template-columns: 1fr; }
    .ds-grid-6 { grid-template-columns: repeat(2, minmax(0,1fr)); }
    .ds-rules, .ds-rules.col-2 { grid-template-columns: 1fr; }
    .ds-header { flex-direction: column; }
    .ds-header-meta { align-items: flex-start; }
    .ds-bp-table { font-size: 11px; }
}

/* ════ 06 TABELE ════════════════════════════════════════════ */

/* Wrapper scroll orizontal pe mobil */
.ds-table-wrap { overflow-x: auto; width: 100%; -webkit-overflow-scrolling: touch; }

/* Extensii aliniere coloane */
.ds-table th.right, .ds-table td.right  { text-align: right; }
.ds-table th.center, .ds-table td.center { text-align: center; }
.ds-table th.nowrap, .ds-table td.nowrap { white-space: nowrap; }
.ds-table td.mono   { font-family: 'Courier New', monospace; font-size: 12px; }
.ds-table td.muted  { color: var(--pz-mu); }
.ds-table td.tabnum { font-variant-numeric: tabular-nums; }
.ds-table tbody tr:last-child td { border-bottom: 0; }

/* Coloane cu lățimi predefinite */
.ds-table .col-chk     { width: 36px;  text-align: center; }
.ds-table .col-status  { width: 110px; }
.ds-table .col-date    { width: 100px; white-space: nowrap; }
.ds-table .col-amount  { width: 110px; text-align: right; font-variant-numeric: tabular-nums; }
.ds-table .col-actions { width: 90px;  text-align: right; }

/* State-uri rânduri */
.ds-table tr.row-selected td { background: #EFF6FF; }
.ds-table tr.row-warn     td { background: #FFF7ED; }
.ds-table tr.row-danger   td { background: #FEF2F2; }

/* Compact variant */
.ds-table.compact th, .ds-table.compact td { padding: 5px 12px; font-size: 12px; }

/* Indicator sortare în header */
.ds-th-sort {
    display: inline-flex; align-items: center; gap: 4px;
    cursor: pointer; user-select: none; white-space: nowrap;
}
.ds-sort-icon { font-size: 9px; color: var(--pz-fa); }
.ds-th-sort.asc .ds-sort-icon,
.ds-th-sort.desc .ds-sort-icon { color: var(--pz-bl); }

/* Bara de filtre deasupra tabelului */
.ds-table-bar {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    padding: 9px 14px;
    border-bottom: 1px solid var(--pz-lines);
    background: var(--pz-soft);
}
.ds-table-search { flex: 1; min-width: 180px; position: relative; }
.ds-table-search input {
    width: 100%; min-height: 30px;
    padding: 5px 9px 5px 28px;
    border: 1px solid var(--pz-line); border-radius: var(--pz-rs);
    background: var(--pz-surf); font-family: inherit; font-size: 12px; font-weight: 500;
    outline: none;
}
.ds-table-search input:focus { border-color: var(--pz-bl); }
.ds-table-search-icon {
    position: absolute; left: 8px; top: 50%; transform: translateY(-50%);
    color: var(--pz-fa); font-size: 12px; pointer-events: none;
}
.ds-table-count { font-size: 11.5px; color: var(--pz-mu); font-weight: 600; white-space: nowrap; }

/* Paginare sub tabel */
.ds-pagination {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 8px 14px;
    border-top: 1px solid var(--pz-lines);
    background: var(--pz-soft);
    font-size: 12px; color: var(--pz-mu); flex-wrap: wrap;
}
.ds-page-btns { display: flex; gap: 3px; }
.ds-page-btn {
    min-width: 30px; height: 28px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid var(--pz-line); border-radius: var(--pz-rs);
    background: var(--pz-surf); font-family: inherit;
    font-size: 11.5px; font-weight: 600; color: var(--pz-mu);
    cursor: pointer;
}
.ds-page-btn.active  { background: var(--pz-bl); border-color: var(--pz-bl); color: #fff; }
.ds-page-btn.disabled { opacity: .4; cursor: default; }

/* Stare goală */
.ds-table-empty { padding: 36px 16px; text-align: center; color: var(--pz-fa); }
.ds-table-empty-icon { font-size: 28px; margin-bottom: 8px; display: block; }
.ds-table-empty-text { font-size: 13px; font-weight: 500; color: var(--pz-mu); }
.ds-table-empty-sub  { font-size: 11.5px; margin-top: 4px; }

/* Buton acțiune mic în tabel */
.ds-table-btn {
    display: inline-flex; align-items: center; gap: 4px;
    height: 26px; padding: 0 8px;
    border: 1px solid var(--pz-line); border-radius: var(--pz-rs);
    background: var(--pz-surf); font-family: inherit;
    font-size: 11px; font-weight: 600; color: var(--pz-text);
    cursor: pointer; white-space: nowrap;
}
.ds-table-btn.primary { background: var(--pz-bl); border-color: var(--pz-bl); color: #fff; }

/* ════ 07 HEADERE ════════════════════════════════════════════ */

/* Page title — h1 + optional count pill */
.ds-pt { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ds-pt h1 { font-size: 20px; font-weight: 700; color: var(--pz-title); margin: 0; line-height: 1.2; }
.ds-pt-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 26px; padding: 0 9px;
    border: 1px solid var(--pz-line); border-radius: var(--pz-rs);
    background: var(--pz-surf); color: var(--pz-mu);
    font-size: 12px; font-weight: 700;
}

/* Hero header — titlu + descriere + acțiuni */
.ds-hero {
    background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r);
    padding: 16px 18px;
    display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
    flex-wrap: wrap;
}
.ds-hero-left { flex: 1; min-width: 0; }
.ds-hero-kicker { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--pz-mu); margin-bottom: 5px; }
.ds-hero h1 { font-size: 22px; font-weight: 700; color: var(--pz-title); margin: 0; line-height: 1.2; }
.ds-hero p  { font-size: 13px; color: var(--pz-mu); margin-top: 5px; line-height: 1.45; max-width: 540px; }
.ds-hero-actions { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; flex-shrink: 0; }

/* Entity header — fișă client / entitate */
.ds-entity-h {
    padding: 16px 18px;
    background: var(--pz-soft);
    border-bottom: 1px solid var(--pz-line);
}
.ds-entity-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.ds-entity-name { font-size: 20px; font-weight: 700; color: var(--pz-title); margin: 0; line-height: 1.2; }
.ds-entity-meta { font-size: 12.5px; color: var(--pz-mu); margin-top: 4px; line-height: 1.4; }
.ds-entity-actions { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; flex-shrink: 0; }

/* Topbar toolbar — bara de căutare + filtre */
.ds-toolbar {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    padding: 8px 14px;
    border-bottom: 1px solid var(--pz-lines);
    background: var(--pz-surf);
}
.ds-toolbar-search { flex: 1; min-width: 200px; position: relative; }
.ds-toolbar-search input {
    width: 100%; min-height: 34px;
    padding: 6px 9px 6px 30px;
    border: 1px solid var(--pz-line); border-radius: var(--pz-rs);
    background: var(--pz-surf); font-family: inherit; font-size: 12.5px;
    outline: none;
}
.ds-toolbar-search input:focus { border-color: var(--pz-bl); }
.ds-toolbar-search-ico {
    position: absolute; left: 9px; top: 50%; transform: translateY(-50%);
    color: var(--pz-fa); font-size: 13px; pointer-events: none;
}

/* Dashboard greeting header */
.ds-dash-h {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 8px;
}
.ds-dash-greet { font-size: 16px; font-weight: 600; color: var(--pz-title); line-height: 1.2; }
.ds-dash-date  { font-size: 11.5px; color: var(--pz-mu); margin-top: 1px; }
.ds-live-badge {
    display: flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: var(--pz-rs);
    background: var(--pz-grs); color: var(--pz-gr);
    border: 1px solid var(--pz-grb); font-size: 11px; font-weight: 600;
    white-space: nowrap;
}
.ds-live-dot { width: 6px; height: 6px; border-radius: 50%; background: #22C55E; flex-shrink: 0; }

/* Breadcrumb */
.ds-breadcrumb { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; margin-bottom: 6px; }
.ds-breadcrumb-item { font-size: 11px; font-weight: 600; color: var(--pz-mu); text-decoration: none; }
.ds-breadcrumb-item:hover { color: var(--pz-bl); }
.ds-breadcrumb-sep { font-size: 11px; color: var(--pz-fa); }
.ds-breadcrumb-item.active { color: var(--pz-title); font-weight: 700; cursor: default; }

/* ════ 08 MODALE ════════════════════════════════════════════ */
.ds-mbox { background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); overflow: hidden; display: flex; flex-direction: column; }
.ds-mhead { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 13px 16px; border-bottom: 1px solid var(--pz-line); flex-shrink: 0; }
.ds-mhead h3 { font-size: 15px; font-weight: 700; color: var(--pz-title); margin: 0; }
.ds-mhead p  { font-size: 12px; color: var(--pz-mu); margin-top: 2px; }
.ds-mclose { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--pz-line); border-radius: var(--pz-rs); background: var(--pz-surf); color: var(--pz-mu); font-size: 16px; cursor: pointer; flex-shrink: 0; line-height: 1; }
.ds-mclose:hover { background: var(--pz-res); border-color: var(--pz-reb); color: var(--pz-re); }
.ds-mbody { padding: 16px; overflow-y: auto; flex: 1; }
.ds-mfoot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 12px 16px; border-top: 1px solid var(--pz-lines); background: var(--pz-soft); flex-shrink: 0; }
.ds-drawer-demo { display: flex; height: 260px; overflow: hidden; border: 1px solid var(--pz-line); border-radius: var(--pz-r); background: rgba(15,23,42,.12); }
.ds-drawer-panel { width: 320px; margin-left: auto; background: var(--pz-surf); border-left: 1px solid var(--pz-line); display: flex; flex-direction: column; flex-shrink: 0; }
.ds-size-row { display: flex; align-items: center; gap: 12px; padding: 7px 0; border-bottom: 1px solid var(--pz-lines); }
.ds-size-row:last-child { border-bottom: 0; }
.ds-size-bar { height: 8px; background: var(--pz-bls); border: 1px solid var(--pz-blb); border-radius: 4px; flex-shrink: 0; }
.ds-size-label { font-size: 12px; font-weight: 700; color: var(--pz-title); min-width: 40px; }
.ds-size-val   { font-size: 12px; color: var(--pz-mu); min-width: 90px; }
.ds-size-use   { font-size: 11px; color: var(--pz-fa); }

/* ════ 09 FORMULARE ════════════════════════════════════════════ */
.ds-fsec { border: 1px solid var(--pz-line); border-left: 3px solid var(--pz-bl); border-radius: 0 var(--pz-r) var(--pz-r) 0; padding: 13px 16px; background: var(--pz-surf); margin-bottom: 10px; }
.ds-fsec:last-child { margin-bottom: 0; }
.ds-fsec-title { font-size: 13px; font-weight: 700; color: var(--pz-title); margin-bottom: 11px; padding-bottom: 8px; border-bottom: 1px solid var(--pz-lines); display: flex; align-items: center; gap: 8px; }
.ds-fsec-title::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--pz-bl); flex-shrink: 0; }
.ds-fgrid { display: grid; gap: 10px 14px; }
.ds-fgrid.c1 { grid-template-columns: 1fr; }
.ds-fgrid.c2 { grid-template-columns: repeat(2, minmax(0,1fr)); }
.ds-fgrid.c3 { grid-template-columns: repeat(3, minmax(0,1fr)); }
.ds-fgrid .full { grid-column: 1 / -1; }
.ds-ff { display: grid; gap: 4px; }
.ds-fhint { font-size: 11px; color: var(--pz-fa); font-weight: 500; line-height: 1.4; }
.ds-ferr  { font-size: 11px; color: var(--pz-re); font-weight: 600; display: flex; align-items: center; gap: 4px; }
.ds-input.err, .ds-select.err { border-color: var(--pz-re-acc); background: var(--pz-res); }
.ds-input.ok  { border-color: var(--pz-gr-acc); }
.ds-input:disabled, .ds-select:disabled { opacity: .5; cursor: not-allowed; background: var(--pz-soft); }
.ds-toggle-row { display: flex; align-items: center; gap: 10px; }
.ds-toggle { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
.ds-toggle input { opacity: 0; width: 0; height: 0; }
.ds-toggle-sl { position: absolute; inset: 0; background: var(--pz-line); border-radius: 99px; cursor: pointer; transition: background .18s; }
.ds-toggle-sl::before { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; background: #fff; border-radius: 50%; transition: transform .18s; }
.ds-toggle input:checked + .ds-toggle-sl { background: var(--pz-gr-acc); }
.ds-toggle input:checked + .ds-toggle-sl::before { transform: translateX(20px); }
.ds-toggle-lbl { font-size: 12.5px; font-weight: 600; color: var(--pz-text); }
.ds-toggle-sub { font-size: 11px; color: var(--pz-mu); }

/* ════ 10 NOTIFICĂRI ════════════════════════════════════════════ */
.ds-notice { display: flex; align-items: flex-start; gap: 9px; padding: 10px 13px; border-radius: var(--pz-rs); border: 1px solid; font-size: 12.5px; font-weight: 500; line-height: 1.45; margin-bottom: 6px; }
.ds-notice:last-child { margin-bottom: 0; }
.ds-notice-ico { font-size: 13px; flex-shrink: 0; margin-top: 1px; }
.ds-notice strong { font-weight: 700; }
.ds-notice.ok   { background: var(--pz-grs); border-color: var(--pz-grb); color: var(--pz-gr); }
.ds-notice.err  { background: var(--pz-res); border-color: var(--pz-reb); color: var(--pz-re); }
.ds-notice.warn { background: var(--pz-ors); border-color: var(--pz-orb); color: var(--pz-or); }
.ds-notice.info { background: var(--pz-bls); border-color: var(--pz-blb); color: var(--pz-bld); }
.ds-notice-x { margin-left: auto; flex-shrink: 0; width: 20px; height: 20px; border-radius: 3px; background: rgba(0,0,0,.07); border: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; color: inherit; }
.ds-toast { background: var(--pz-surf); border: 1px solid var(--pz-line); border-radius: var(--pz-r); padding: 10px 13px; display: flex; align-items: center; gap: 9px; font-size: 12.5px; font-weight: 500; width: 270px; }
.ds-toast-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.ds-toast.ok   .ds-toast-dot { background: var(--pz-gr-acc); }
.ds-toast.err  .ds-toast-dot { background: var(--pz-re-acc); }
.ds-toast.warn .ds-toast-dot { background: var(--pz-or-acc); }
.ds-toast.info .ds-toast-dot { background: var(--pz-bl); }
.ds-toast-stack { display: flex; flex-direction: column; gap: 6px; }
.ds-toast-tag { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 3px; background: var(--pz-soft); border: 1px solid var(--pz-line); color: var(--pz-mu); flex-shrink: 0; white-space: nowrap; }
.ds-toast-x { margin-left: auto; flex-shrink: 0; color: var(--pz-fa); font-size: 14px; cursor: pointer; }
.ds-banner { display: flex; align-items: flex-start; gap: 9px; padding: 10px 12px; border-radius: var(--pz-rs); border-left: 3px solid; font-size: 12px; font-weight: 500; line-height: 1.5; }
.ds-banner.ok   { background: var(--pz-grs); border-left-color: var(--pz-gr-acc);  color: var(--pz-gr); }
.ds-banner.err  { background: var(--pz-res); border-left-color: var(--pz-re-acc);  color: var(--pz-re); }
.ds-banner.warn { background: var(--pz-ors); border-left-color: var(--pz-or-acc);  color: var(--pz-or); }
.ds-banner.info { background: var(--pz-bls); border-left-color: var(--pz-bl);      color: var(--pz-bld); }
.ds-banner strong { font-weight: 700; }

/* ════ 11 LOADING ════════════════════════════════════════════ */
@keyframes pz-pulse { 0%,100%{opacity:1} 50%{opacity:.35} }
@keyframes pz-spin   { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }
.ds-skel { background: var(--pz-line); border-radius: 4px; animation: pz-pulse 1.5s ease-in-out infinite; }
.ds-skel-line  { height: 12px; }
.ds-skel-line.fat  { height: 18px; }
.ds-skel-line.thin { height: 10px; }
.ds-skel-circ  { border-radius: 50%; }
.ds-skel-block { border-radius: var(--pz-rs); }
.ds-spin { display: inline-block; width: 18px; height: 18px; border: 2px solid var(--pz-line); border-top-color: var(--pz-bl); border-radius: 50%; animation: pz-spin .7s linear infinite; flex-shrink: 0; }
.ds-spin.sm  { width: 14px; height: 14px; }
.ds-spin.lg  { width: 28px; height: 28px; border-width: 3px; }
.ds-spin.wht { border-color: rgba(255,255,255,.3); border-top-color: #fff; }
.ds-btn.loading { pointer-events: none; opacity: .8; }

/* ════ 12 ICONURI ════════════════════════════════════════════ */

/* Toate icon-urile platformă: fill=none, stroke=currentColor */
.pz-ico svg,
.nav-icon svg,
.icon-action svg {
    fill: none;
    stroke: currentColor;
    stroke-width: 1.75;
    stroke-linecap: round;
    stroke-linejoin: round;
    display: block;
    transition: stroke .15s, color .15s;
}

/* Dimensiuni standard */
.pz-ico { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pz-ico.ico-16 svg { width: 16px; height: 16px; }
.pz-ico.ico-18 svg { width: 18px; height: 18px; }
.pz-ico.ico-20 svg { width: 20px; height: 20px; }
.pz-ico.ico-24 svg { width: 24px; height: 24px; }

/* Buton icon: pătrat, cu border */
.pz-ico-btn {
    width: 30px; height: 30px; min-width: 30px;
    display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid var(--pz-line); border-radius: var(--pz-rs);
    background: var(--pz-surf); color: var(--pz-mu);
    cursor: pointer; text-decoration: none;
    transition: color .15s, border-color .15s, background .15s;
}
.pz-ico-btn svg { width: 15px; height: 15px; }
.pz-ico-btn:hover { color: var(--pz-bl); border-color: var(--pz-blb); background: var(--pz-bls); }
.pz-ico-btn:active { color: var(--pz-bld); }
.pz-ico-btn.danger:hover  { color: var(--pz-re); border-color: var(--pz-reb); background: var(--pz-res); }
.pz-ico-btn.success:hover { color: var(--pz-gr); border-color: var(--pz-grb); background: var(--pz-grs); }
.pz-ico-btn.sm { width: 26px; height: 26px; min-width: 26px; }
.pz-ico-btn.sm svg { width: 13px; height: 13px; }
.pz-ico-btn.lg { width: 36px; height: 36px; min-width: 36px; }
.pz-ico-btn.lg svg { width: 17px; height: 17px; }
.pz-ico-btn.active { color: var(--pz-bl); border-color: var(--pz-blb); background: var(--pz-bls); }

/* Icon+text link */
.pz-ico-link {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--pz-mu); text-decoration: none;
    font-size: 12.5px; font-weight: 600;
    transition: color .15s;
    cursor: pointer;
}
.pz-ico-link svg { width: 15px; height: 15px; }
.pz-ico-link:hover { color: var(--pz-bl); }
.pz-ico-link.danger:hover { color: var(--pz-re); }

/* Demo icon grid */
.ds-ico-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
    gap: 8px;
}
.ds-ico-cell {
    display: flex; flex-direction: column; align-items: center; gap: 7px;
    padding: 12px 8px;
    border: 1px solid var(--pz-lines); border-radius: 6px;
    background: var(--pz-soft);
    cursor: default;
    transition: border-color .15s, background .15s;
}
.ds-ico-cell:hover { border-color: var(--pz-blb); background: var(--pz-bls); }
.ds-ico-cell:hover svg { stroke: var(--pz-bl) !important; }
.ds-ico-cell svg { width: 20px; height: 20px; stroke: var(--pz-mu); fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; transition: stroke .15s; }
.ds-ico-cell-name { font-size: 10px; font-weight: 600; color: var(--pz-mu); text-align: center; font-family: 'Courier New', monospace; }
.ds-ico-cell:hover .ds-ico-cell-name { color: var(--pz-bld); }

/* ── Compatibility aliases ─ */
:root {
    --pzui-bg: var(--pz-bg); --pzui-surface: var(--pz-surf);
    --pzui-line: var(--pz-line); --pzui-line-soft: var(--pz-lines);
    --pzui-title: var(--pz-title); --pzui-text: var(--pz-text);
    --pzui-muted: var(--pz-mu); --pzui-faint: var(--pz-fa);
    --pzui-blue: var(--pz-bl); --pzui-blue-dark: var(--pz-bld);
    --pzui-blue-soft: var(--pz-bls);
    --pzui-green: var(--pz-gr); --pzui-green-soft: var(--pz-grs);
    --pzui-orange: var(--pz-or); --pzui-orange-soft: var(--pz-ors);
    --pzui-red: var(--pz-re); --pzui-red-soft: var(--pz-res);
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('ui_template', true); ?>
    <main class="main">
        <div class="content">
        <div class="ds">

        <!-- ══════════════════════════════════════════════════════
             HEADER
        ══════════════════════════════════════════════════════ -->
        <header class="ds-header">
            <div>
                <div class="ds-header-kicker">Emma CRM · Design System</div>
                <h1>Ghid vizual al platformei</h1>
                <p>Sursa unică de adevăr pentru culori, tipografie, componente și reguli de interfață. Orice pagină nouă din platformă trebuie să respecte acest ghid. Documentul este actualizat continuu.</p>
            </div>
            <div class="ds-header-meta">
                <span class="ds-version">v2.4</span>
                <span class="ds-date">Actualizat mai 2026</span>
            </div>
        </header>

        <!-- Cuprins -->
        <nav class="ds-toc" aria-label="Cuprins">
            <span class="ds-toc-label">Cuprins</span>
            <a class="ds-toc-link" href="#fundatie">01 Fundație</a>
            <a class="ds-toc-link" href="#status">02 Status</a>
            <a class="ds-toc-link" href="#componente">03 Componente</a>
            <a class="ds-toc-link" href="#layout">04 Layout</a>
            <a class="ds-toc-link" href="#reguli">05 Reguli</a>
            <a class="ds-toc-link" href="#tabele">06 Tabele</a>
            <a class="ds-toc-link" href="#headere">07 Headere</a>
            <a class="ds-toc-link" href="#modale">08 Modale</a>
            <a class="ds-toc-link" href="#formulare">09 Formulare</a>
            <a class="ds-toc-link" href="#notificari">10 Notificări</a>
            <a class="ds-toc-link" href="#loading">11 Loading</a>
            <a class="ds-toc-link" href="#iconuri">12 Iconuri</a>
        </nav>

        <!-- ══════════════════════════════════════════════════════
             01 FUNDAȚIE — Culori, tipografie, spațiere
        ══════════════════════════════════════════════════════ -->
        <div class="ds-chapter" id="fundatie">
            <span class="ds-chapter-num">01</span>
            <span class="ds-chapter-title">Fundație — culori, tipografie, spațiere</span>
        </div>

        <!-- Paleta principală -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Paleta de culori</h2>
                    <p>Culorile de bază ale platformei. Orice nouă culoare trebuie adăugată mai întâi aici.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-demo-label">Structura UI</div>
                <div class="ds-grid-6">
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#12345A"></div>
                        <div class="ds-swatch-body">
                            <div class="ds-swatch-name">Brand Navy</div>
                            <div class="ds-swatch-hex">#12345A</div>
                            <div class="ds-swatch-use">Sidebar, header brand</div>
                        </div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#F8FAFC"></div>
                        <div class="ds-swatch-body">
                            <div class="ds-swatch-name">Background</div>
                            <div class="ds-swatch-hex">#F8FAFC</div>
                            <div class="ds-swatch-use">Fundal pagină</div>
                        </div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#FFFFFF;border-bottom:1px solid #E2E8F0"></div>
                        <div class="ds-swatch-body">
                            <div class="ds-swatch-name">Surface</div>
                            <div class="ds-swatch-hex">#FFFFFF</div>
                            <div class="ds-swatch-use">Card / panel</div>
                        </div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#E2E8F0"></div>
                        <div class="ds-swatch-body">
                            <div class="ds-swatch-name">Border</div>
                            <div class="ds-swatch-hex">#E2E8F0</div>
                            <div class="ds-swatch-use">Contur card</div>
                        </div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#F1F5F9"></div>
                        <div class="ds-swatch-body">
                            <div class="ds-swatch-name">Border Soft</div>
                            <div class="ds-swatch-hex">#F1F5F9</div>
                            <div class="ds-swatch-use">Separator intern</div>
                        </div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#2563EB"></div>
                        <div class="ds-swatch-body">
                            <div class="ds-swatch-name">Action Blue</div>
                            <div class="ds-swatch-hex">#2563EB</div>
                            <div class="ds-swatch-use">Buton primar, link</div>
                        </div>
                    </div>
                </div>
                <div class="ds-demo-label" style="margin-top:16px">Text</div>
                <div class="ds-grid-6">
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#0F172A"></div>
                        <div class="ds-swatch-body">
                            <div class="ds-swatch-name">Title</div>
                            <div class="ds-swatch-hex">#0F172A</div>
                            <div class="ds-swatch-use">Titluri, valori KPI</div>
                        </div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#334155"></div>
                        <div class="ds-swatch-body">
                            <div class="ds-swatch-name">Text</div>
                            <div class="ds-swatch-hex">#334155</div>
                            <div class="ds-swatch-use">Corp text normal</div>
                        </div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#64748B"></div>
                        <div class="ds-swatch-body">
                            <div class="ds-swatch-name">Muted</div>
                            <div class="ds-swatch-hex">#64748B</div>
                            <div class="ds-swatch-use">Text secundar</div>
                        </div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#94A3B8"></div>
                        <div class="ds-swatch-body">
                            <div class="ds-swatch-name">Faint</div>
                            <div class="ds-swatch-hex">#94A3B8</div>
                            <div class="ds-swatch-use">Placeholder, hint</div>
                        </div>
                    </div>
                </div>
                <div class="ds-demo-label" style="margin-top:16px">Status (detalii în secțiunea 02)</div>
                <div class="ds-grid-6">
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#EF4444"></div>
                        <div class="ds-swatch-body"><div class="ds-swatch-name">Red Accent</div><div class="ds-swatch-hex">#EF4444</div></div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#FEF2F2"></div>
                        <div class="ds-swatch-body"><div class="ds-swatch-name">Red Soft</div><div class="ds-swatch-hex">#FEF2F2</div></div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#F97316"></div>
                        <div class="ds-swatch-body"><div class="ds-swatch-name">Orange Accent</div><div class="ds-swatch-hex">#F97316</div></div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#FFF7ED"></div>
                        <div class="ds-swatch-body"><div class="ds-swatch-name">Orange Soft</div><div class="ds-swatch-hex">#FFF7ED</div></div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#22C55E"></div>
                        <div class="ds-swatch-body"><div class="ds-swatch-name">Green Accent</div><div class="ds-swatch-hex">#22C55E</div></div>
                    </div>
                    <div class="ds-swatch">
                        <div class="ds-swatch-color" style="background:#F0FDF4"></div>
                        <div class="ds-swatch-body"><div class="ds-swatch-name">Green Soft</div><div class="ds-swatch-hex">#F0FDF4</div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CSS Variables reference -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Variabile CSS — tokeni de design</h2>
                    <p>Nu se folosesc culori hardcodate în cod. Orice valoare trebuie să facă referire la un token de mai jos.</p>
                </div>
                <span class="ds-badge bl">18 tokeni</span>
            </div>
            <div class="ds-card-body">
                <div class="ds-var-grid">
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#F8FAFC"></span><span class="ds-var-name">--pz-bg</span><span class="ds-var-desc">#F8FAFC · fundal pagină</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#fff;border:1px solid #E2E8F0"></span><span class="ds-var-name">--pz-surf</span><span class="ds-var-desc">#FFFFFF · suprafață card</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#E2E8F0"></span><span class="ds-var-name">--pz-line</span><span class="ds-var-desc">#E2E8F0 · contur principal</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#F1F5F9"></span><span class="ds-var-name">--pz-lines</span><span class="ds-var-desc">#F1F5F9 · separator intern</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#0F172A"></span><span class="ds-var-name">--pz-title</span><span class="ds-var-desc">#0F172A · titluri</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#334155"></span><span class="ds-var-name">--pz-text</span><span class="ds-var-desc">#334155 · corp text</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#64748B"></span><span class="ds-var-name">--pz-mu</span><span class="ds-var-desc">#64748B · text secundar</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#94A3B8"></span><span class="ds-var-name">--pz-fa</span><span class="ds-var-desc">#94A3B8 · placeholder</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#2563EB"></span><span class="ds-var-name">--pz-bl / --pz-bld / --pz-bls / --pz-blb</span><span class="ds-var-desc">albastru (4 trepte)</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#22C55E"></span><span class="ds-var-name">--pz-gr-acc / --pz-gr / --pz-grs / --pz-grb</span><span class="ds-var-desc">verde (4 trepte)</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#F97316"></span><span class="ds-var-name">--pz-or-acc / --pz-or / --pz-ors / --pz-orb</span><span class="ds-var-desc">portocaliu (4 trepte)</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#EF4444"></span><span class="ds-var-name">--pz-re-acc / --pz-re / --pz-res / --pz-reb</span><span class="ds-var-desc">roșu (4 trepte)</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:#12345A"></span><span class="ds-var-name">--pz-brand</span><span class="ds-var-desc">#12345A · sidebar navy</span></div>
                    <div class="ds-var-row"><span class="ds-var-dot" style="background:transparent;border:2px solid #E2E8F0"></span><span class="ds-var-name">--pz-r / --pz-rs</span><span class="ds-var-desc">8px card · 4px input/buton</span></div>
                </div>
            </div>
        </div>

        <!-- Tipografie -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Tipografie</h2>
                    <p>Font unic: <strong>Inter</strong> (Google Fonts variable). Fallback: system-ui, -apple-system, sans-serif.</p>
                </div>
                <span class="ds-badge bl">Inter</span>
            </div>
            <div class="ds-card-body">
                <div class="ds-demo" style="margin-bottom:14px">
                    <div style="font-family:'Satoshi',Inter,system-ui,sans-serif;font-size:24px;font-weight:700;color:#0F172A;line-height:1.2">Emma CRM — Interfață operațională</div>
                    <div style="font-family:'Satoshi',Inter,system-ui,sans-serif;font-size:13px;font-weight:500;color:#64748B;margin-top:6px">Clienți · Lucrări · Facturare · Stocuri · Rapoarte</div>
                </div>
                <div class="ds-demo-label">Scara tipografică</div>
                <div class="ds-type-row">
                    <div class="ds-type-meta"><span class="ds-token">22–24px / 700</span><div class="ds-type-size">Titlu pagină / valoare KPI</div></div>
                    <div class="ds-type-sample" style="font-size:24px;font-weight:700;color:#0F172A">31 lucrări</div>
                </div>
                <div class="ds-type-row">
                    <div class="ds-type-meta"><span class="ds-token">16px / 600</span><div class="ds-type-size">Subtitlu / greeting</div></div>
                    <div class="ds-type-sample" style="font-size:16px;font-weight:600;color:#0F172A">Bună, Bentu Marian</div>
                </div>
                <div class="ds-type-row">
                    <div class="ds-type-meta"><span class="ds-token">14px / 700</span><div class="ds-type-size">Header card</div></div>
                    <div class="ds-type-sample" style="font-size:14px;font-weight:700;color:#0F172A">Facturare lunii</div>
                </div>
                <div class="ds-type-row">
                    <div class="ds-type-meta"><span class="ds-token">12.5–13px / 600</span><div class="ds-type-size">Text normal UI</div></div>
                    <div class="ds-type-sample" style="font-size:13px;font-weight:600;color:#334155">SKY CERT GLOBAL S.R.L. · Deratizare · Constanța</div>
                </div>
                <div class="ds-type-row">
                    <div class="ds-type-meta"><span class="ds-token">11–12px / 500</span><div class="ds-type-size">Text secundar / meta</div></div>
                    <div class="ds-type-sample" style="font-size:12px;font-weight:500;color:#64748B">CUI 12345678 · 3 locații · Client activ</div>
                </div>
                <div class="ds-type-row">
                    <div class="ds-type-meta"><span class="ds-token">10–10.5px / 700</span><div class="ds-type-size">Label câmp (uppercase)</div></div>
                    <div class="ds-type-sample" style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748B">Denumire firmă</div>
                </div>
                <div class="ds-type-row">
                    <div class="ds-type-meta"><span class="ds-token">9.5–10px / 700</span><div class="ds-type-size">Caption / chapter label</div></div>
                    <div class="ds-type-sample" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94A3B8">KPI-uri principale</div>
                </div>
            </div>
        </div>

        <!-- Spațiere -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Spațiere & geometrie</h2>
                    <p>Valorile fixe pentru padding, gap și border-radius.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2">
                    <div>
                        <div class="ds-demo-label">Gap & padding</div>
                        <div class="ds-space-row">
                            <div class="ds-space-bar" style="width:14px"></div>
                            <div class="ds-space-name">--pz-gap</div>
                            <div class="ds-space-val">14px</div>
                            <div class="ds-space-use">Gap grid pagină</div>
                        </div>
                        <div class="ds-space-row">
                            <div class="ds-space-bar" style="width:12px"></div>
                            <div class="ds-space-name">Card body</div>
                            <div class="ds-space-val">12–16px</div>
                            <div class="ds-space-use">Padding interior card</div>
                        </div>
                        <div class="ds-space-row">
                            <div class="ds-space-bar" style="width:9px"></div>
                            <div class="ds-space-name">Panel header</div>
                            <div class="ds-space-val">9–10px</div>
                            <div class="ds-space-use">Padding header panel</div>
                        </div>
                        <div class="ds-space-row">
                            <div class="ds-space-bar" style="width:8px"></div>
                            <div class="ds-space-name">Row item</div>
                            <div class="ds-space-val">8px</div>
                            <div class="ds-space-use">Padding rând listă</div>
                        </div>
                        <div class="ds-space-row">
                            <div class="ds-space-bar" style="width:7px"></div>
                            <div class="ds-space-name">Buton normal</div>
                            <div class="ds-space-val">7px 12px</div>
                            <div class="ds-space-use">Padding interior buton</div>
                        </div>
                    </div>
                    <div>
                        <div class="ds-demo-label">Înălțimi & radii</div>
                        <div class="ds-space-row">
                            <div class="ds-space-bar" style="width:34px;height:8px;border-radius:4px"></div>
                            <div class="ds-space-name">Input / btn</div>
                            <div class="ds-space-val">34px</div>
                            <div class="ds-space-use">Înălțime minimă</div>
                        </div>
                        <div class="ds-space-row">
                            <div class="ds-space-bar" style="width:40px;height:8px;border-radius:4px;background:#64748B"></div>
                            <div class="ds-space-name">Rând tabel</div>
                            <div class="ds-space-val">38–40px</div>
                            <div class="ds-space-use">Înălțime rând</div>
                        </div>
                        <div class="ds-space-row">
                            <div class="ds-space-bar" style="width:24px;height:24px;border-radius:50%;background:#2563EB"></div>
                            <div class="ds-space-name">Avatar</div>
                            <div class="ds-space-val">24–28px</div>
                            <div class="ds-space-use">Avatar tehnician</div>
                        </div>
                        <div class="ds-space-row">
                            <div class="ds-space-bar" style="width:20px;height:20px;border-radius:8px;background:#EFF6FF;border:1px solid #BFDBFE"></div>
                            <div class="ds-space-name">--pz-r</div>
                            <div class="ds-space-val">8px</div>
                            <div class="ds-space-use">Radius card / panel</div>
                        </div>
                        <div class="ds-space-row">
                            <div class="ds-space-bar" style="width:20px;height:20px;border-radius:4px;background:#F1F5F9;border:1px solid #E2E8F0"></div>
                            <div class="ds-space-name">--pz-rs</div>
                            <div class="ds-space-val">4px</div>
                            <div class="ds-space-use">Radius input / buton</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             02 STATUS — Semantica culorilor
        ══════════════════════════════════════════════════════ -->
        <div class="ds-chapter" id="status">
            <span class="ds-chapter-num">02</span>
            <span class="ds-chapter-title">Status & semantică — ce culoare înseamnă ce</span>
        </div>

        <div class="ds-note" style="margin-bottom:0">
            <strong>Regulă strictă:</strong> fiecare culoare are un singur înțeles în toată platforma. Roșul înseamnă mereu pericol/urgent, portocaliul mereu atenție, verdele mereu succes, albastrul mereu informație/acțiune. Nu se folosește o culoare în alt context.
        </div>

        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Sistem de status</h2>
                    <p>Fiecare status are 4 trepte de culoare: accent (bar/dot), text, fundal, bordură.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-status-grid">

                    <!-- ROȘU -->
                    <div class="ds-status-block">
                        <div class="ds-status-head">
                            <span class="ds-status-dot" style="background:#EF4444"></span>
                            Roșu — Pericol / Urgent
                        </div>
                        <div class="ds-status-body" style="background:#FEF2F2">
                            <div class="ds-status-when">
                                Sarcini întârziate · Sume restante scadente · Erori sistem · Stoc critic · Facturi expirate
                            </div>
                            <div class="ds-status-tokens">
                                <span class="ds-status-token">#EF4444 accent</span>
                                <span class="ds-status-token">#991B1B text</span>
                                <span class="ds-status-token">#FEF2F2 bg</span>
                                <span class="ds-status-token">#FECACA border</span>
                            </div>
                            <div class="ds-status-examples">
                                <span class="ds-badge re">2 întârziate</span>
                                <span class="ds-badge re">11.953 lei</span>
                                <span class="ds-pill re">Depășit</span>
                            </div>
                        </div>
                    </div>

                    <!-- PORTOCALIU -->
                    <div class="ds-status-block">
                        <div class="ds-status-head">
                            <span class="ds-status-dot" style="background:#F97316"></span>
                            Portocaliu — Atenție / Acțiune
                        </div>
                        <div class="ds-status-body" style="background:#FFF7ED">
                            <div class="ds-status-when">
                                De facturat · De programat · Aproape de termen · Stoc sub minim · Avertisment
                            </div>
                            <div class="ds-status-tokens">
                                <span class="ds-status-token">#F97316 accent</span>
                                <span class="ds-status-token">#9A3412 text</span>
                                <span class="ds-status-token">#FFF7ED bg</span>
                                <span class="ds-status-token">#FED7AA border</span>
                            </div>
                            <div class="ds-status-examples">
                                <span class="ds-badge or">5 intervenții</span>
                                <span class="ds-badge or">4.200 lei</span>
                                <span class="ds-pill or">De facturat</span>
                            </div>
                        </div>
                    </div>

                    <!-- VERDE -->
                    <div class="ds-status-block">
                        <div class="ds-status-head">
                            <span class="ds-status-dot" style="background:#22C55E"></span>
                            Verde — Succes / La zi
                        </div>
                        <div class="ds-status-body" style="background:#F0FDF4">
                            <div class="ds-status-when">
                                Finalizat · Facturat · Plătit · Sincronizat · Echipă activă · Stoc ok
                            </div>
                            <div class="ds-status-tokens">
                                <span class="ds-status-token">#22C55E accent</span>
                                <span class="ds-status-token">#166534 text</span>
                                <span class="ds-status-token">#F0FDF4 bg</span>
                                <span class="ds-status-token">#BBF7D0 border</span>
                            </div>
                            <div class="ds-status-examples">
                                <span class="ds-badge gr">Finalizată</span>
                                <span class="ds-badge gr">Sincronizat</span>
                                <span class="ds-pill gr">84%</span>
                            </div>
                        </div>
                    </div>

                    <!-- ALBASTRU -->
                    <div class="ds-status-block">
                        <div class="ds-status-head">
                            <span class="ds-status-dot" style="background:#2563EB"></span>
                            Albastru — Info / Activ
                        </div>
                        <div class="ds-status-body" style="background:#EFF6FF">
                            <div class="ds-status-when">
                                Lucrare activă · Facturat luna · Buton primar · Link · Acțiune principală
                            </div>
                            <div class="ds-status-tokens">
                                <span class="ds-status-token">#2563EB accent</span>
                                <span class="ds-status-token">#1E3A8A text</span>
                                <span class="ds-status-token">#EFF6FF bg</span>
                                <span class="ds-status-token">#BFDBFE border</span>
                            </div>
                            <div class="ds-status-examples">
                                <span class="ds-badge bl">31 lucrări</span>
                                <span class="ds-badge bl">În curs</span>
                                <span class="ds-pill bl">Calendar →</span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Badges & Pills -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Badges & Pills</h2>
                    <p>Badge = colțuri drepte (3px). Pill = rotunjit complet. Ambele în 5 variante semantice.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2">
                    <div>
                        <div class="ds-demo-label">Badges — .ds-badge (border-radius: 3px)</div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px">
                            <span class="ds-badge bl">Activ</span>
                            <span class="ds-badge gr">Finalizat</span>
                            <span class="ds-badge or">De facturat</span>
                            <span class="ds-badge re">Întârziat</span>
                            <span class="ds-badge neu">Neutru</span>
                            <span class="ds-badge bl">31 lucrări</span>
                            <span class="ds-badge re">11.953 lei</span>
                            <span class="ds-badge gr">84%</span>
                        </div>
                    </div>
                    <div>
                        <div class="ds-demo-label">Pills — .ds-pill (border-radius: 20px)</div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px">
                            <span class="ds-pill bl">Calendar →</span>
                            <span class="ds-pill gr">Sincronizat</span>
                            <span class="ds-pill or">5 intervenții</span>
                            <span class="ds-pill re">2 întârziate</span>
                            <span class="ds-pill neu">128 rezultate</span>
                        </div>
                    </div>
                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Badge vs pill: badge pentru valori numerice, pill pentru statusuri</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Culoarea badge-ului trebuie să corespundă semanticii statusului</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Font-size: 10–10.5px, font-weight: 700</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Nu se folosesc badge-uri decorative fără sens semantic</div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             03 COMPONENTE
        ══════════════════════════════════════════════════════ -->
        <div class="ds-chapter" id="componente">
            <span class="ds-chapter-num">03</span>
            <span class="ds-chapter-title">Componente — butoane, inputuri, carduri, tabele</span>
        </div>

        <!-- Butoane -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Butoane</h2>
                    <p>Înălțime fixă 34px. 5 variante. Radius 4px. Fără shadow.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-demo">
                    <div class="ds-demo-label">Variante</div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
                        <button class="ds-btn primary">Programează</button>
                        <button class="ds-btn secondary">Anulează</button>
                        <button class="ds-btn soft">Calendar</button>
                        <button class="ds-btn danger">Șterge</button>
                        <button class="ds-btn ghost">Mai mult →</button>
                        <button class="ds-btn primary sm">Mic primary</button>
                        <button class="ds-btn secondary sm">Mic secondary</button>
                        <button class="ds-btn primary icon">+</button>
                    </div>
                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>Primary</strong> = o singură acțiune principală per pagină</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>Secondary</strong> = acțiuni alternative / anulare</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>Soft</strong> = navigare, filtre, linkuri vizuale</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>Danger</strong> = acțiuni distructive (ștergere)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>Ghost</strong> = acțiuni secundare discrete</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Verb clar în text: "Programează", "Facturează", nu "Ok"</div>
                </div>
            </div>
        </div>

        <!-- Inputuri -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Inputuri & formulare</h2>
                    <p>Înălțime 34px, border 1px var(--pz-line), focus border var(--pz-bl). Labels uppercase.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2">
                    <div style="display:grid;gap:12px">
                        <label class="ds-field">
                            <span class="ds-label">Denumire firmă</span>
                            <input class="ds-input" value="SKY CERT GLOBAL S.R.L." readonly>
                        </label>
                        <label class="ds-field">
                            <span class="ds-label">CUI</span>
                            <input class="ds-input" placeholder="Ex: 12345678">
                        </label>
                        <label class="ds-field">
                            <span class="ds-label">Tip serviciu</span>
                            <select class="ds-select">
                                <option>Deratizare</option>
                                <option>Dezinsecție</option>
                                <option>Dezinfecție</option>
                            </select>
                        </label>
                    </div>
                    <div style="display:grid;gap:12px">
                        <label class="ds-field">
                            <span class="ds-label">Observații</span>
                            <textarea class="ds-textarea" placeholder="Detalii suplimentare..."></textarea>
                        </label>
                        <div class="ds-note">Flux rapid: CUI → preluare ANAF → completezi doar ce lipsește.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>KPI Cards — Dashboard</h2>
                    <p>Card cu accentuare laterală colorată. Folosit în stripcul de sus al dashboard-ului. 4 pe rând pe desktop, 4 compact pe mobil.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-4">
                    <div class="ds-kpi bl">
                        <div class="ds-kpi-lbl">Lucrări luna</div>
                        <div class="ds-kpi-val">31</div>
                        <div class="ds-kpi-sub">26 finalizate <span class="ds-badge bl" style="font-size:9px">84%</span></div>
                    </div>
                    <div class="ds-kpi or">
                        <div class="ds-kpi-lbl">De facturat</div>
                        <div class="ds-kpi-val">5</div>
                        <div class="ds-kpi-sub">intervenții <span class="ds-badge or" style="font-size:9px">4.200 lei</span></div>
                    </div>
                    <div class="ds-kpi re">
                        <div class="ds-kpi-lbl">Restanțe</div>
                        <div class="ds-kpi-val">35</div>
                        <div class="ds-kpi-sub">beneficiari <span class="ds-badge re" style="font-size:9px">11.953 lei</span></div>
                    </div>
                    <div class="ds-kpi gr">
                        <div class="ds-kpi-lbl">Echipă azi</div>
                        <div class="ds-kpi-val">4 <sub>/ 6</sub></div>
                        <div class="ds-kpi-sub">activi <span class="ds-badge gr" style="font-size:9px">67%</span></div>
                    </div>
                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Border-left: 3px colorat semantic</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Label: 10px uppercase muted</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Valoare: 22px Inter 700 dark</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Sub-valoare: badge semantic + text mic</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Flex-column — sub-valoare la baza cardului</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Drag handle ascuns pe mobil (≤680px)</div>
                </div>
            </div>
        </div>

        <!-- Panel component -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Panel — componentă universală</h2>
                    <p>Folosit pentru alerte, grafice, echipă, financiar. Header + listă iteme + footer pin jos.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2" style="align-items:start">

                    <!-- Panel alertă -->
                    <div class="ds-panel">
                        <div class="ds-panel-head">
                            <span class="ds-drag" title="Drag handle — SortableJS">⠿</span>
                            <div class="ds-panel-hc">
                                <div>
                                    <div class="ds-panel-title">Sarcini urgente</div>
                                    <div class="ds-panel-meta">Acțiuni necesare azi</div>
                                </div>
                                <span class="ds-badge re">2 întârziate</span>
                            </div>
                        </div>
                        <div class="ds-panel-item">
                            <div>
                                <div class="ds-item-n">SKY CERT GLOBAL S.R.L.</div>
                                <div class="ds-item-s">Deratizare · Constanța</div>
                            </div>
                            <span class="ds-item-v re">−3 zile</span>
                        </div>
                        <div class="ds-panel-item">
                            <div>
                                <div class="ds-item-n">EUROPREST TEAM 98</div>
                                <div class="ds-item-s">Dezinsecție rapel · Buc.</div>
                            </div>
                            <span class="ds-item-v re">−1 zi</span>
                        </div>
                        <div class="ds-panel-item warn">
                            <div>
                                <div class="ds-item-n" style="color:var(--pz-or)">5 lucrări de programat</div>
                                <div class="ds-item-s">Fără dată atribuită</div>
                            </div>
                            <span class="ds-item-v or">→</span>
                        </div>
                        <div class="ds-item-spacer"></div>
                        <div class="ds-panel-foot">→ Deschide sarcini · 7 active</div>
                    </div>

                    <!-- Panel financiar -->
                    <div class="ds-panel">
                        <div class="ds-panel-head">
                            <span class="ds-drag" title="Drag handle">⠿</span>
                            <div class="ds-panel-hc">
                                <div>
                                    <div class="ds-panel-title">Facturare lunii</div>
                                    <div class="ds-panel-meta">Mai 2026 · rezumat</div>
                                </div>
                                <span class="ds-badge gr">Sincronizat</span>
                            </div>
                        </div>
                        <div class="ds-panel-item">
                            <span style="font-size:12px;color:var(--pz-mu)">Valoare lucrări</span>
                            <span class="ds-item-v" style="color:var(--pz-bld)">18.850 lei</span>
                        </div>
                        <div class="ds-panel-item">
                            <span style="font-size:12px;color:var(--pz-mu)">Facturat (24 facturi)</span>
                            <span class="ds-item-v" style="color:var(--pz-gr)">11.953 lei</span>
                        </div>
                        <div class="ds-panel-item warn">
                            <span style="font-size:12px;font-weight:600;color:var(--pz-or)">De facturat</span>
                            <span class="ds-item-v or">5 · 4.200 lei</span>
                        </div>
                        <div class="ds-panel-item danger">
                            <span style="font-size:12px;font-weight:600;color:var(--pz-re)">Restanțe noi</span>
                            <span class="ds-item-v re">2 · 2.000 lei</span>
                        </div>
                        <div class="ds-panel-item" style="border-top:2px solid var(--pz-line)">
                            <span style="font-size:12px;font-weight:600;color:var(--pz-title)">Sold real</span>
                            <span style="font-size:15px;font-weight:700;color:var(--pz-title)">9.681 lei</span>
                        </div>
                    </div>

                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Header: drag ⠿ + titlu + badge status</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Iteme: border-bottom soft, flex space-between</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Rând colorat semantic (warn/danger) pentru alertă inline</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Footer albastru: ancorat jos prin flex spacer</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Carduri din același rând = înălțime egală (grid stretch)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Overflow text: ellipsis pe 1 rând, fără wrap forțat</div>
                </div>
            </div>
        </div>

        <!-- Tabel -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Tabel</h2>
                    <p>Header bg #F8FAFC, rows 38–40px, border-bottom soft între rânduri. Fără borduri verticale.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <table class="ds-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Serviciu</th>
                            <th>Dată</th>
                            <th>Valoare</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>SKY CERT GLOBAL S.R.L.</td>
                            <td>Deratizare</td>
                            <td>15.05.2026</td>
                            <td>850 lei</td>
                            <td><span class="ds-badge gr">Finalizată</span></td>
                        </tr>
                        <tr>
                            <td>EUROPREST TEAM 98</td>
                            <td>Dezinsecție rapel</td>
                            <td>14.05.2026</td>
                            <td>1.200 lei</td>
                            <td><span class="ds-badge or">De facturat</span></td>
                        </tr>
                        <tr>
                            <td>MEGA IMAGE NR. 7</td>
                            <td>Dezinfecție</td>
                            <td>13.05.2026</td>
                            <td>640 lei</td>
                            <td><span class="ds-badge re">Întârziată</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tabs -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Tabs & navigare</h2>
                    <p>Border-bottom 2px pe tab activ. Overflow-x scroll pe mobil.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-demo">
                    <div class="ds-tabs">
                        <span class="ds-tab active">Profil</span>
                        <span class="ds-tab">Contacte</span>
                        <span class="ds-tab">Locații</span>
                        <span class="ds-tab">Lucrări</span>
                        <span class="ds-tab">Facturi</span>
                        <span class="ds-tab">Istoric</span>
                    </div>
                    <div style="padding:12px 0 4px;font-size:12px;color:var(--pz-mu)">Conținut tab activ...</div>
                </div>
            </div>
        </div>

        <!-- Bare de progres & Avatare echipă -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Echipă — bare de progres</h2>
                    <p>Grad de ocupare per tehnician. Culoarea barii: verde ≥90%, albastru ≥50%, portocaliu >0%, gri = 0.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div style="display:grid;gap:0">
                    <?php
                    $team = [
                        ['name'=>'Alex',   'color'=>'#2563EB', 'pct'=>80, 'jobs'=>4, 'bar'=>'#2563EB'],
                        ['name'=>'Costi',  'color'=>'#2563EB', 'pct'=>60, 'jobs'=>3, 'bar'=>'#2563EB'],
                        ['name'=>'Dragos', 'color'=>'#22C55E', 'pct'=>100,'jobs'=>5, 'bar'=>'#22C55E'],
                        ['name'=>'Eugen',  'color'=>'#F97316', 'pct'=>40, 'jobs'=>2, 'bar'=>'#F97316'],
                        ['name'=>'George', 'color'=>'#94A3B8', 'pct'=>0,  'jobs'=>0, 'bar'=>'#E2E8F0'],
                    ];
                    foreach ($team as $m): ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--pz-lines)">
                        <div class="ds-avatar" style="background:<?= $m['color'] ?>"><?= substr($m['name'],0,1) ?></div>
                        <div style="font-size:12px;font-weight:600;color:var(--pz-title);min-width:54px"><?= $m['name'] ?></div>
                        <div class="ds-bar-wrap" style="flex:1"><div class="ds-bar" style="width:<?= $m['pct'] ?>%;background:<?= $m['bar'] ?>"></div></div>
                        <div style="font-size:11px;font-weight:700;min-width:30px;text-align:right;color:<?= $m['pct']>=90?'var(--pz-gr)':($m['pct']>=50?'var(--pz-bld)':($m['pct']>0?'var(--pz-or)':'var(--pz-fa)')) ?>"><?= $m['pct']>0 ? $m['pct'].'%' : '—' ?></div>
                        <div style="font-size:11px;color:var(--pz-mu);min-width:54px"><?= $m['jobs'] ?> lucrări</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             04 LAYOUT & RESPONSIVE
        ══════════════════════════════════════════════════════ -->
        <div class="ds-chapter" id="layout">
            <span class="ds-chapter-num">04</span>
            <span class="ds-chapter-title">Layout & responsive — grid, breakpoints, drag & drop</span>
        </div>

        <!-- Breakpoints -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Breakpoints responsive</h2>
                    <p>Comportamentul layout-ului la fiecare punct de rupere.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <table class="ds-bp-table">
                    <thead>
                        <tr>
                            <th>Lățime viewport</th>
                            <th>Context</th>
                            <th>KPI strip</th>
                            <th>Alerte (3 col)</th>
                            <th>Mid / Bot</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="ds-bp-badge">&gt;1100px</span></td>
                            <td>Desktop / laptop</td>
                            <td>4 coloane egale</td>
                            <td>3 coloane egale</td>
                            <td>2 col (1.4:1)</td>
                            <td>Layout complet, drag activ</td>
                        </tr>
                        <tr>
                            <td><span class="ds-bp-badge">≤1100px</span></td>
                            <td>Laptop mic / tabletă</td>
                            <td>4 coloane egale</td>
                            <td>2 coloane</td>
                            <td>1 coloană</td>
                            <td>Alerte 2×2</td>
                        </tr>
                        <tr>
                            <td><span class="ds-bp-badge">≤680px</span></td>
                            <td>Mobil</td>
                            <td>4 col compacte (font mic)</td>
                            <td>1 coloană</td>
                            <td>1 coloană</td>
                            <td>Grip ascuns, padding 8px</td>
                        </tr>
                    </tbody>
                </table>
                <div class="ds-rules" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Gap pagină: <strong>14px</strong></div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Max-width pagină: <strong>1680px</strong></div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Sidebar: <strong>185px</strong> fix</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Grid: <code class="ds-token">minmax(0, 1fr)</code> — nu 1fr simplu</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Touch target minim: <strong>32px</strong></div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Overflow lateral: interzis pe mobil</div>
                </div>
            </div>
        </div>

        <!-- Drag & drop -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Drag & drop (SortableJS)</h2>
                    <p>Reordonarea cardurilor în dashboard. Ordinea se salvează automat în localStorage.</p>
                </div>
                <span class="ds-badge bl">SortableJS 1.15.2</span>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2">
                    <div>
                        <div class="ds-demo-label">Cum funcționează</div>
                        <div class="ds-rules col-2" style="margin-top:4px">
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Librărie: cdnjs.cloudflare.com SortableJS</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Handle: <code class="ds-token">.drag-handle</code> — ⠿ în header</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Animație: 180ms cubic-bezier(.2,1,.1,1)</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Persistență: localStorage per rând</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Key: <code class="ds-token">pz_dash_order_v1_{rowId}</code></div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Drag per rând — nu cross-row</div>
                        </div>
                    </div>
                    <div>
                        <div class="ds-demo-label">State-uri vizuale</div>
                        <div style="display:grid;gap:8px">
                            <div style="padding:8px 12px;border:1.5px dashed var(--pz-bl);border-radius:var(--pz-r);background:var(--pz-bls);opacity:.4;font-size:12px;color:var(--pz-bld)">
                                Ghost: opacity .2, border dashed albastru
                            </div>
                            <div style="padding:8px 12px;border:1px solid var(--pz-line);border-radius:var(--pz-r);outline:2px solid var(--pz-blb);outline-offset:1px;font-size:12px;color:var(--pz-mu)">
                                Chosen: outline 2px solid #BFDBFE
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--pz-lines);border-radius:6px;background:var(--pz-soft)">
                                <span style="color:var(--pz-fa);font-size:16px;cursor:grab">⠿</span>
                                <span style="font-size:12px;color:var(--pz-mu)">Handle: color faint, cursor grab</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             05 REGULI GLOBALE
        ══════════════════════════════════════════════════════ -->
        <div class="ds-chapter" id="reguli">
            <span class="ds-chapter-num">05</span>
            <span class="ds-chapter-title">Reguli globale — principii, conținut, checklist</span>
        </div>

        <!-- Principii de design -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Principii de design</h2>
                    <p>Regulile de bază care ghidează orice decizie de interfață în platformă.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2">
                    <div>
                        <div class="ds-demo-label">Vizual</div>
                        <div class="ds-rules col-2" style="margin-top:4px">
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Fără box-shadow pe carduri (0 umbre)</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Fără gradienți de fundal</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Border: 1px solid #E2E8F0</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Separator intern: 1px solid #F1F5F9</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Radius card: 8px, buton/input: 4px</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Fundal pagină: #F8FAFC (nu alb pur)</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Font unic: Inter — fără mixing</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Iconuri: Tabler Icons outline</div>
                        </div>
                    </div>
                    <div>
                        <div class="ds-demo-label">Comportament</div>
                        <div class="ds-rules col-2" style="margin-top:4px">
                            <div class="ds-rule"><span class="ds-rule-dot"></span>O singură acțiune primară per pagină</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Alertele sar în ochi — nu se îngropă</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Stare goală: text + icon subtil</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Focus vizibil (border-color albastru)</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Hover pe rânduri de tabel: bg soft</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Carduri egale ca înălțime pe același rând</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Loading: skeleton sau spinner discret</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Erori: mesaj clar, culoare roșie</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Conținut & redactare -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Conținut & redactare</h2>
                    <p>Regulile de scriere ale textelor din platformă.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-rules">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Texte scurte — fără explicații evidente</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Acțiuni cu verb clar: "Programează", "Facturează", "Șterge"</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Labels câmpuri: UPPERCASE cu letter-spacing</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Sume: format românesc — 1.234 lei (punct mii, fără zecimale)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Date: dd.mm.yyyy sau "19 mai 2026"</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Ore: HH:MM (ex: 09:00, 14:30)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Stare goală: "Nu există programări astăzi" — nu "No data"</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Diacritice corecte: ă â î ș ț (nu s, t cu virgulă)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Iconuri: completează textul, nu îl înlocuiesc</div>
                </div>
            </div>
        </div>

        <!-- Sidebar & navigare -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Sidebar & navigare</h2>
                    <p>Sidebar navy fix (#12345A). Topbar alb. Active state cu accentuare stângă.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-rules">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Sidebar bg: #12345A (brand navy)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Item activ: bg rgba(255,255,255,.13), border-left 2px #60A5FA</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Text nav: rgba(255,255,255,.78), activ: #fff</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Hover: bg rgba(255,255,255,.08)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Topbar: #FFFFFF, border-bottom 1px #E2E8F0, fără shadow</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Breadcrumbs: text muted, separator ·</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Submeniu: indent 40px, font-size 12.5px</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Logout: border semi-transparent, hover mai deschis</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Fără box-shadow pe sidebar</div>
                </div>
            </div>
        </div>

        <!-- Checklist pagină nouă -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Checklist — pagină nouă</h2>
                    <p>Lista de verificare înainte de a lansa o pagină nouă în platformă.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-note" style="margin-bottom:14px">
                    Orice pagină PHP nouă trebuie să bifeze toate punctele de mai jos înainte de a fi considerată finalizată.
                </div>
                <div class="ds-checklist">
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>Font Inter</strong> — importat din Google Fonts sau moștenit din layout global</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>Tokeni CSS</strong> — orice culoare folosește variabile --pz-*, nu hex hardcodate</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>Semantic status</strong> — roșu/portocaliu/verde/albastru folosite corect</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>Sidebar activ</strong> — parametru corect în render_sidebar('pagina', $isAdmin)</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>$pz_page_title</strong> — setat corect (apare în topbar)</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>Responsive</strong> — testată la 1100px și 680px (nu se rupe)</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>Stare goală</strong> — fiecare secțiune are mesaj când nu există date</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>Fără shadow</strong> — niciun box-shadow pe carduri sau sidebar</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>Fără gradient</strong> — fundal solid, nu gradient</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>Diacritice</strong> — ș ț ă â î corecte (UTF-8)</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>require_login()</strong> — autentificare verificată</div></div>
                    <div class="ds-check-item"><span class="ds-check-icon">□</span><div><strong>htmlspecialchars</strong> — toate valorile din DB sunt escapate la output</div></div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             06 TABELE — structură, coloane, state-uri, reguli
        ══════════════════════════════════════════════════════ -->
        <div class="ds-chapter" id="tabele">
            <span class="ds-chapter-num">06</span>
            <span class="ds-chapter-title">Tabele — structură, tipuri de coloane, state-uri, reguli</span>
        </div>

        <!-- 1. Anatomia completă a unui tabel -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Anatomia unui tabel complet</h2>
                    <p>Structura standard: bară de filtre → header → rânduri → paginare. Toate elementele sunt opționale în funcție de context.</p>
                </div>
            </div>

            <!-- A. Bara de filtre -->
            <div class="ds-table-bar">
                <select style="min-height:30px;border:1px solid var(--pz-line);border-radius:var(--pz-rs);padding:4px 8px;font-family:inherit;font-size:12px;background:var(--pz-surf)">
                    <option>25 / pagină</option>
                    <option>50 / pagină</option>
                    <option>100 / pagină</option>
                </select>
                <div class="ds-table-search">
                    <span class="ds-table-search-icon">🔍</span>
                    <input type="search" placeholder="Caută după client, CUI, serviciu...">
                </div>
                <button class="ds-btn secondary sm">Filtre</button>
                <button class="ds-btn secondary sm">Export</button>
                <span class="ds-table-count">214 rezultate</span>
            </div>

            <!-- B. Tabelul -->    
            <div class="ds-table-wrap">
                <table class="ds-table">
                    <thead>
                        <tr>
                            <th class="col-chk"><input type="checkbox" style="width:14px;height:14px"></th>
                            <th><span class="ds-th-sort">Client <span class="ds-sort-icon">▲</span></span></th>
                            <th>Serviciu</th>
                            <th class="col-date"><span class="ds-th-sort desc">Dată <span class="ds-sort-icon">▼</span></span></th>
                            <th class="col-amount"><span class="ds-th-sort">Valoare <span class="ds-sort-icon">↕</span></span></th>
                            <th class="col-status">Status</th>
                            <th class="col-actions">Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="col-chk"><input type="checkbox" style="width:14px;height:14px"></td>
                            <td>
                                <div style="font-weight:600;color:var(--pz-title)">SKY CERT GLOBAL S.R.L.</div>
                                <div style="font-size:11px;color:var(--pz-mu)">CUI 12345678 · Constanța</div>
                            </td>
                            <td>Deratizare</td>
                            <td class="col-date mono">15.05.2026</td>
                            <td class="col-amount tabnum" style="font-weight:600">850 lei</td>
                            <td class="col-status"><span class="ds-badge gr">Finalizată</span></td>
                            <td class="col-actions">
                                <button class="ds-table-btn">Deschide</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="col-chk"><input type="checkbox" style="width:14px;height:14px"></td>
                            <td>
                                <div style="font-weight:600;color:var(--pz-title)">EUROPREST TEAM 98</div>
                                <div style="font-size:11px;color:var(--pz-mu)">CUI 10135994 · București</div>
                            </td>
                            <td>Dezinsecție rapel</td>
                            <td class="col-date mono">14.05.2026</td>
                            <td class="col-amount tabnum" style="font-weight:600">1.200 lei</td>
                            <td class="col-status"><span class="ds-badge or">De facturat</span></td>
                            <td class="col-actions">
                                <button class="ds-table-btn primary">Facturează</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="col-chk"><input type="checkbox" style="width:14px;height:14px"></td>
                            <td>
                                <div style="font-weight:600;color:var(--pz-title)">MEGA IMAGE NR. 7</div>
                                <div style="font-size:11px;color:var(--pz-mu)">CUI 52652511 · Constanța</div>
                            </td>
                            <td>Dezinfecție</td>
                            <td class="col-date mono">13.05.2026</td>
                            <td class="col-amount tabnum" style="font-weight:600">640 lei</td>
                            <td class="col-status"><span class="ds-badge re">Întârziată</span></td>
                            <td class="col-actions">
                                <button class="ds-table-btn">Deschide</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- C. Paginare -->
            <div class="ds-pagination">
                <span>Afișate 1–3 din 214 rezultate</span>
                <div class="ds-page-btns">
                    <button class="ds-page-btn disabled">←</button>
                    <button class="ds-page-btn active">1</button>
                    <button class="ds-page-btn">2</button>
                    <button class="ds-page-btn">3</button>
                    <span style="padding:0 4px;color:var(--pz-fa)">...</span>
                    <button class="ds-page-btn">9</button>
                    <button class="ds-page-btn">→</button>
                </div>
            </div>
        </div>

        <!-- 2. Tipuri de coloane -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Tipuri de coloane</h2>
                    <p>Fiecare tip de date are reguli clare de formatare și aliniere.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-table-wrap">
                    <table class="ds-table">
                        <thead>
                            <tr>
                                <th>Tip coloană</th>
                                <th>Aliniere</th>
                                <th>Font</th>
                                <th>Lățime</th>
                                <th>Exemplu</th>
                                <th>Regulă</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Text principal</strong></td>
                                <td>Stânga</td>
                                <td>Inter 600 #0F172A</td>
                                <td>flex (1fr)</td>
                                <td style="font-weight:600;color:var(--pz-title)">SKY CERT GLOBAL S.R.L.</td>
                                <td class="muted">Overflow: ellipsis, 1 rând</td>
                            </tr>
                            <tr>
                                <td><strong>Text secundar</strong></td>
                                <td>Stânga</td>
                                <td>Inter 500 #64748B</td>
                                <td>flex (1fr)</td>
                                <td style="color:var(--pz-mu)">CUI 12345678 · Constanța</td>
                                <td class="muted">Sub textul principal, 11px</td>
                            </tr>
                            <tr>
                                <td><strong>Sumă / valoare</strong></td>
                                <td>Dreapta ✓</td>
                                <td>Inter 600 + tabular-nums</td>
                                <td>fix ~110px</td>
                                <td class="right tabnum" style="font-weight:600">11.953 lei</td>
                                <td class="muted">Punct mii, fără zecimale</td>
                            </tr>
                            <tr>
                                <td><strong>Dată</strong></td>
                                <td>Stânga</td>
                                <td>Courier New 12px</td>
                                <td>fix ~100px</td>
                                <td class="mono nowrap">15.05.2026</td>
                                <td class="muted">Monospaced, white-space:nowrap</td>
                            </tr>
                            <tr>
                                <td><strong>Oră</strong></td>
                                <td>Stânga</td>
                                <td>Courier New 12px</td>
                                <td>fix ~70px</td>
                                <td class="mono nowrap">09:30</td>
                                <td class="muted">Format HH:MM</td>
                            </tr>
                            <tr>
                                <td><strong>Status</strong></td>
                                <td>Stânga</td>
                                <td>Badge semantic</td>
                                <td>fix ~110px</td>
                                <td><span class="ds-badge gr">Finalizată</span></td>
                                <td class="muted">Culoare conform sistem status</td>
                            </tr>
                            <tr>
                                <td><strong>Procent</strong></td>
                                <td>Dreapta ✓</td>
                                <td>Inter 600 tabular-nums</td>
                                <td>fix ~70px</td>
                                <td class="right" style="font-weight:600">84%</td>
                                <td class="muted">Fără zecimale dacă nu e necesar</td>
                            </tr>
                            <tr>
                                <td><strong>Acțiuni</strong></td>
                                <td>Dreapta ✓</td>
                                <td>Butoane 26px</td>
                                <td>fix ~90px</td>
                                <td class="right"><button class="ds-table-btn">Deschide</button></td>
                                <td class="muted">Max 2 butoane; al 3-lea = meniu ⋯</td>
                            </tr>
                            <tr>
                                <td><strong>Checkbox</strong></td>
                                <td>Centru</td>
                                <td>—</td>
                                <td>fix 36px</td>
                                <td class="center"><input type="checkbox" style="width:14px;height:14px"></td>
                                <td class="muted">Prima coloană, dacă există selecție</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 3. State-uri rânduri -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>State-uri rânduri</h2>
                    <p>Rândurile pot comunica starea înregistrării prin culoarea de fundal. Se aplică pe <code class="ds-token">tr</code>, nu pe celule individuale.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-table-wrap">
                    <table class="ds-table">
                        <thead>
                            <tr>
                                <th>State</th>
                                <th>Client</th>
                                <th>Dată</th>
                                <th>Valoare</th>
                                <th>Status</th>
                                <th>Când se folosește</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="ds-badge neu">Default</span></td>
                                <td style="font-weight:600;color:var(--pz-title)">SKY CERT GLOBAL</td>
                                <td class="mono">15.05.2026</td>
                                <td class="right tabnum" style="font-weight:600">850 lei</td>
                                <td><span class="ds-badge gr">Finalizată</span></td>
                                <td class="muted" style="font-size:11.5px">Stare normală — bg alb, hover bg soft</td>
                            </tr>
                            <tr class="row-selected">
                                <td><span class="ds-badge bl">Selected</span></td>
                                <td style="font-weight:600;color:var(--pz-title)">EUROPREST TEAM 98</td>
                                <td class="mono">14.05.2026</td>
                                <td class="right tabnum" style="font-weight:600">1.200 lei</td>
                                <td><span class="ds-badge bl">Selectat</span></td>
                                <td class="muted" style="font-size:11.5px">Rând selectat via checkbox</td>
                            </tr>
                            <tr class="row-warn">
                                <td><span class="ds-badge or">Warning</span></td>
                                <td style="font-weight:600;color:var(--pz-title)">MEGA IMAGE NR. 7</td>
                                <td class="mono">13.05.2026</td>
                                <td class="right tabnum" style="font-weight:600">640 lei</td>
                                <td><span class="ds-badge or">De facturat</span></td>
                                <td class="muted" style="font-size:11.5px">Atenție — de facturat, aproape de termen</td>
                            </tr>
                            <tr class="row-danger">
                                <td><span class="ds-badge re">Danger</span></td>
                                <td style="font-weight:600;color:var(--pz-title)">AUTO LOGISTIC S.R.L.</td>
                                <td class="mono">01.05.2026</td>
                                <td class="right tabnum" style="font-weight:600">3.200 lei</td>
                                <td><span class="ds-badge re">Întârziată</span></td>
                                <td class="muted" style="font-size:11.5px">Urgent — depășit termen, eroare</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>State-urile se aplică pe <code class="ds-token">tr</code>, nu pe <code class="ds-token">td</code> individual</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>Selected</strong>: bg #EFF6FF — rând selectat via checkbox</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>Warning</strong>: bg #FFF7ED — atenție, acțiune necesară</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>Danger</strong>: bg #FEF2F2 — pericol, termen depășit</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Hover pe rândul normal: bg var(--pz-soft)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Nu se combină state-uri pe același rând</div>
                </div>
            </div>
        </div>

        <!-- 4. Tabel compact + Stare goală -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Tabel compact & stare goală</h2>
                    <p>Varianta compactă (padding 5px) pentru spații mici. Starea goală se afișează în locul rândurilor.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2" style="align-items:start">

                    <div>
                        <div class="ds-demo-label">Tabel compact — .ds-table.compact</div>
                        <div class="ds-table-wrap">
                            <table class="ds-table compact">
                                <thead>
                                    <tr>
                                        <th>Produs</th>
                                        <th class="right">Stoc</th>
                                        <th class="right">Minim</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="row-danger">
                                        <td style="font-weight:600;color:var(--pz-title)">FOVAL CE 100ml</td>
                                        <td class="right tabnum">38 ml</td>
                                        <td class="right tabnum">100 ml</td>
                                        <td><span class="ds-badge re">Critic</span></td>
                                    </tr>
                                    <tr class="row-warn">
                                        <td style="font-weight:600;color:var(--pz-title)">K-Othrine 50ml</td>
                                        <td class="right tabnum">120 ml</td>
                                        <td class="right tabnum">100 ml</td>
                                        <td><span class="ds-badge or">Sub minim</span></td>
                                    </tr>
                                    <tr>
                                        <td style="font-weight:600;color:var(--pz-title)">Racumin Foam</td>
                                        <td class="right tabnum">840 g</td>
                                        <td class="right tabnum">200 g</td>
                                        <td><span class="ds-badge gr">Ok</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <div class="ds-demo-label">Stare goală</div>
                        <div style="border:1px solid var(--pz-line);border-radius:var(--pz-r);overflow:hidden">
                            <table class="ds-table">
                                <thead>
                                    <tr>
                                        <th>Client</th>
                                        <th>Serviciu</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="3">
                                            <div class="ds-table-empty">
                                                <span class="ds-table-empty-icon">📋</span>
                                                <div class="ds-table-empty-text">Nu există lucrări pentru filtrele selectate</div>
                                                <div class="ds-table-empty-sub">Modificați filtrele sau adăugați o lucrare nouă</div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- 5. Reguli complete pentru tabele -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Reguli complete pentru tabele</h2>
                    <p>Toate regulile de design care se aplică oricărui tabel din platformă.</p>
                </div>
            </div>
            <div class="ds-card-body">

                <div class="ds-demo-label">Structură & aspect</div>
                <div class="ds-rules" style="margin-bottom:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Header <code class="ds-token">th</code>: bg #F8FAFC, text uppercase muted 10.5px, font-weight 700</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Rânduri <code class="ds-token">td</code>: înălțime 38–40px, padding 8px 12px, border-bottom 1px #F1F5F9</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Fără borduri verticale între coloane</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Fără zebra stripes (rânduri alternate) — hover suficient</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Ultimul rând: fără border-bottom</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Tabel compact: padding 5px 12px, font-size 12px</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Hover rând: background var(--pz-soft) = #F8FAFC</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Fără box-shadow pe tabel</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Border-radius 8px pe containerul tabelului (card)</div>
                </div>

                <div class="ds-demo-label">Coloane & aliniere</div>
                <div class="ds-rules" style="margin-bottom:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Text: aliniat stânga</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Numere & sume: aliniat dreapta + tabular-nums</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Acțiuni: aliniate dreapta, ultima coloană</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Status badge: aliniat stânga</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Checkbox: centrat, prima coloană, 36px fix</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Dată: Courier New 12px, white-space:nowrap, 100px fix</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Valori monetare: punct mii, fără zecimale, sufixul "lei"</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Coloane fixe: status 110px, acțiuni 90px, dată 100px</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Coloane flex: text cresc să umple spațiul disponibil</div>
                </div>

                <div class="ds-demo-label">Sortare & filtrare</div>
                <div class="ds-rules" style="margin-bottom:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Sortare: indicator ▲▼ în header, albastru când activ</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Bara de filtre: bg soft, border-bottom, deasupra headerului</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Căutare: flex:1, minim 180px, iconiță lupă la stânga</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Număr rezultate: text muted dreapta barei</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Butoane filtre: secondary sm, după câmpul de căutare</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Select rânduri/pagină: stânga barei</div>
                </div>

                <div class="ds-demo-label">Paginare</div>
                <div class="ds-rules" style="margin-bottom:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Paginarea: sub tabel, bg soft, border-top</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Text stânga: "Afișate X–Y din Z rezultate"</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Butoane dreapta: ← 1 2 3 ... N →</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Pagina activă: bg albastru, text alb</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Butoane dezactivate (prev/next la capăt): opacity .4</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Înălțime buton paginare: 28px, min-width 30px</div>
                </div>

                <div class="ds-demo-label">Stare goală & acțiuni în rând</div>
                <div class="ds-rules">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Stare goală: colspan full, icon + text clar + sub-text</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Mesajul gol: specific ("Nu există lucrări pentru luna mai")</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Max 2 acțiuni vizibile per rând; dacă sunt mai multe: meniu ⋯</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Acțiunea primară: buton primary 26px (ex: Facturează)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Acțiunea secundară: buton normal 26px (ex: Deschide)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Pe mobil: tabel în wrapper cu overflow-x:auto</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Pe mobil: coloanele neesențiale se pot ascunde cu CSS</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Loading: skeleton rows sau spinner centrat în tbody</div>
                </div>

            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             07 HEADERE DE PAGINI — 5 pattern-uri standard
        ══════════════════════════════════════════════════════ -->
        <div class="ds-chapter" id="headere">
            <span class="ds-chapter-num">07</span>
            <span class="ds-chapter-title">Headere de pagini — 5 pattern-uri standard</span>
        </div>

        <div class="ds-note">
            <strong>Cum funcționează topbar-ul:</strong> Se setează <code class="ds-token">$pz_page_title</code>, <code class="ds-token">$pz_page_breadcrumbs</code> și <code class="ds-token">$pz_topbar_opts</code> înainte de <code class="ds-token">render_sidebar()</code>. Funcția randrează automat topbar-ul global. Header-ul de conținut (Pattern A–E mai jos) este separat și stă în zona <code class="ds-token">.content</code>.
        </div>

        <!-- Pattern A: Title simplu -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Pattern A — Titlu simplu</h2>
                    <p>Folosit în: Clienți, Sarcini, Calendar. Titlu h1 + număr de rezultate. Fără descriere.</p>
                </div>
                <span class="ds-badge bl">Cel mai simplu</span>
            </div>
            <div class="ds-card-body">
                <div class="ds-demo">
                    <div class="ds-pt">
                        <h1>Clienți</h1>
                        <span class="ds-pt-count">214</span>
                    </div>
                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>h1: 20px font-weight 700, color --pz-title</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Count pill: border 1px, bg white, text muted</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Padding: 0 (conținutul imediat sub topbar)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Fără descriere — titlul este autoexplicativ</div>
                </div>
            </div>
        </div>

        <!-- Pattern B: Hero cu acțiuni -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Pattern B — Hero cu acțiuni</h2>
                    <p>Folosit în: Setari, Servicii, Utilizatori, pagini de management. Card alb cu titlu + descriere + butoane dreapta.</p>
                </div>
                <span class="ds-badge bl">Standard management</span>
            </div>
            <div class="ds-card-body">
                <div class="ds-demo">
                    <div class="ds-hero">
                        <div class="ds-hero-left">
                            <div class="ds-hero-kicker">Setări · Tehnicieni</div>
                            <h1>Echipa DDD</h1>
                            <p>Gestionează lista de tehnicieni activi, culorile de identificare și accesul în aplicație.</p>
                        </div>
                        <div class="ds-hero-actions">
                            <button class="ds-btn secondary">Import CSV</button>
                            <button class="ds-btn primary">+ Tehnician nou</button>
                        </div>
                    </div>
                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Card alb, border 1px, radius 8px, padding 16-18px</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Kicker: 10.5px uppercase muted — context în platformă</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>h1: 22px font-weight 700</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Descriere: 13px muted, max-width 540px</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Max 3 butoane dreapta: 1 primary + max 2 secondary</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Pe mobil: acțiunile se mută sub titlu (flex-wrap)</div>
                </div>
            </div>
        </div>

        <!-- Pattern C: Cu breadcrumb -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Pattern C — Cu breadcrumb</h2>
                    <p>Folosit în pagini adânc înicuibate (ex: setarea unei integrări). Arată contextul navîgatiei.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-demo">
                    <nav class="ds-breadcrumb" aria-label="Fir de navigare">
                        <a href="#" class="ds-breadcrumb-item">Setări</a>
                        <span class="ds-breadcrumb-sep">›</span>
                        <a href="#" class="ds-breadcrumb-item">Comunicări</a>
                        <span class="ds-breadcrumb-sep">›</span>
                        <span class="ds-breadcrumb-item active">SmartBill</span>
                    </nav>
                    <div class="ds-hero" style="margin-top:8px">
                        <div class="ds-hero-left">
                            <h1>Integrare SmartBill</h1>
                            <p>Conectează platforma cu contul tău SmartBill pentru emitere automată de facturi.</p>
                        </div>
                        <div class="ds-hero-actions">
                            <button class="ds-btn secondary">Înapooi</button>
                            <button class="ds-btn primary">Salvează setari</button>
                        </div>
                    </div>
                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Breadcrumb: deasupra hero-ului, font 11px muted</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Separator: › (mai lizibil decât /)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Pagina curentă: font-weight 700, color title, non-link</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Părinții: link-uri cu hover albastru</div>
                </div>
            </div>
        </div>

        <!-- Pattern D: Topbar toolbar -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Pattern D — Topbar cu toolbar</h2>
                    <p>Folosit în: Clienți, Lucrari, Facturi. Bara globală sub topbar, cu filtre + căutare + butoane.</p>
                </div>
                <span class="ds-badge bl">Pagini cu liste mari</span>
            </div>
            <div class="ds-card-body">
                <div class="ds-demo" style="padding:0;overflow:hidden;border-radius:6px">
                    <div class="ds-toolbar">
                        <select style="min-height:34px;border:1px solid var(--pz-line);border-radius:var(--pz-rs);padding:0 8px;font-family:inherit;font-size:12px;background:var(--pz-surf)">
                            <option>25 / pagină</option>
                        </select>
                        <select style="min-height:34px;border:1px solid var(--pz-line);border-radius:var(--pz-rs);padding:0 8px;font-family:inherit;font-size:12px;background:var(--pz-surf)">
                            <option>Activi</option>
                            <option>Inactivi</option>
                            <option>Toți</option>
                        </select>
                        <button class="ds-btn secondary sm">Filtrează</button>
                        <div class="ds-toolbar-search">
                            <span class="ds-toolbar-search-ico">🔍</span>
                            <input type="search" placeholder="Caută client, CUI, telefon...">
                        </div>
                        <button class="ds-btn secondary sm" title="Reset filtre">↻</button>
                        <button class="ds-btn primary sm">+ Client nou</button>
                    </div>
                    <div style="padding:10px 14px;font-size:12px;color:var(--pz-mu)">Conținutul paginii (tabel, etc.)...</div>
                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Toolbar: bg --pz-soft, border-bottom, padding 8-9px 14px</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Ordinea: [rows/pag] [filtre] [Filtrează] [Search — flex:1] [Reset] [+ Acțiune]</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Butoane în toolbar: sm (34px), nu normal (34px — același dar scurt)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Căutare: flex:1, min-width 200px, icon lupă la 9px stânga</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Reset: icon ↻, tooltip explicativ</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Pe mobil: grid 2 coloane, căutare și buton principal pe full-width</div>
                </div>
            </div>
        </div>

        <!-- Pattern E: Dashboard greeting + Entitate -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Pattern E — Dashboard & Fișă entitate</h2>
                    <p>Două cazuri speciale: header-ul de salut din dashboard și header-ul fișei de client/entitate.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2">

                    <div>
                        <div class="ds-demo-label">E1 — Dashboard greeting</div>
                        <div class="ds-demo">
                            <div class="ds-dash-h">
                                <div>
                                    <div class="ds-dash-greet">Bună, Bentu Marian</div>
                                    <div class="ds-dash-date">Marți · 19 mai 2026</div>
                                </div>
                                <div class="ds-live-badge">
                                    <div class="ds-live-dot"></div>
                                    Date live din platformă
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="ds-demo-label">E2 — Fișă entitate (client, lucrare)</div>
                        <div class="ds-demo" style="padding:0;overflow:hidden;border-radius:6px">
                            <div class="ds-entity-h">
                                <div class="ds-entity-top">
                                    <div>
                                        <h2 class="ds-entity-name">SKY CERT GLOBAL S.R.L.</h2>
                                        <div class="ds-entity-meta">CUI 12345678 · J13/0000/2026 · Client activ</div>
                                    </div>
                                    <div class="ds-entity-actions">
                                        <button class="ds-btn secondary sm">Programează</button>
                                        <button class="ds-btn secondary sm">Factură</button>
                                        <button class="ds-btn primary sm">Editează</button>
                                    </div>
                                </div>
                            </div>
                            <!-- Tabs sub header entitate -->
                            <div class="ds-tabs" style="padding:0 12px">
                                <span class="ds-tab active">Profil</span>
                                <span class="ds-tab">Locații</span>
                                <span class="ds-tab">Lucrări</span>
                                <span class="ds-tab">Facturi</span>
                                <span class="ds-tab">Istoric</span>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>E1 Greeting:</strong> 16px weight 600, data 11.5px muted, badge verde live</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>E2 Entitate:</strong> 20px weight 700, meta 12.5px muted sub titlu</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Fundal header entitate: --pz-soft (#F8FAFC)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Tabs imediat sub header entitate, fără spațiu extra</div>
                </div>
            </div>
        </div>

        <!-- Reguli globale headere -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Reguli complete pentru headere</h2>
                    <p>Reguli care se aplică tuturor pattern-urilor de mai sus.</p>
                </div>
            </div>
            <div class="ds-card-body">

                <div class="ds-demo-label">Topbar global (render_sidebar)</div>
                <div class="ds-rules" style="margin-bottom:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Setat prin <code class="ds-token">$pz_page_title</code> — apare în bara superioară</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Breadcrumbs: <code class="ds-token">$pz_page_breadcrumbs = ['Setări', 'Servicii']</code></div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Căutare: <code class="ds-token">$pz_topbar_opts['placeholder']</code></div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Buton primary: <code class="ds-token">$pz_topbar_opts['primary_label']</code> + <code class="ds-token">primary_href</code></div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Topbar: bg alb, border-bottom 1px #E2E8F0, fără shadow</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Nu se duplică titlul și în topbar și în header-ul de conținut</div>
                </div>

                <div class="ds-demo-label">Alegerea pattern-ului potrivit</div>
                <div class="ds-rules col-2" style="margin-bottom:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>A</strong> (titlu simplu): pagini cu liste — Clienți, Sarcini</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>B</strong> (hero + acțiuni): pagini de management — Setări, Servicii</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>C</strong> (breadcrumb): pagini adânc înicuibate — Setări > Integrare X</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>D</strong> (toolbar): pagini cu liste mari — Clienți, Facturi</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>E1</strong> (greeting): doar dashboard</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span><strong>E2</strong> (entitate): fișe — client, tehnician, lucrare</div>
                </div>

                <div class="ds-demo-label">Tipografie & dimensiuni</div>
                <div class="ds-rules" style="margin-bottom:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>h1 pagina management: 22px font-weight 700</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>h1 listă simplă: 20px font-weight 700</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Kicker/breadcrumb: 10–11px uppercase muted</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Descriere sub titlu: 13px muted, max-width 540px</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Titlu entitate: 20px font-weight 700</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Meta entitate: 12.5px muted, separator ·</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Greeting dashboard: 16px font-weight 600</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Count pill: 12px muted, border 1px, radius 4px</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Live badge: verde soft, dot animabil</div>
                </div>

                <div class="ds-demo-label">Acțiuni în header</div>
                <div class="ds-rules">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Max 3 butoane vizibile: 1 primary + max 2 secondary</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Dacă sunt mai mult de 3: grupare în meniu ⋯ (kebab)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Ordinea: [secondary] [secondary] [primary] (primary mereu la dreapta)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Butonul primary: acțiunea principală a paginii (+ Nou, Salvează)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Pe mobil: flex-wrap, butoanele cad sub titlu, full-width opțional</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Fără butoane icon-only în header fără tooltip</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Acțiunile distructive nu sunt în header — sunt jos în pagină</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Acțiunile aplicate pe o selecție: apar în bara de filtre</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Fundal header: --pz-surf (alb) sau --pz-soft pentru entități</div>
                </div>

            </div>
        </div>

        <!-- ═══ 08 MODALE ═══ -->
        <div class="ds-chapter" id="modale">
            <span class="ds-chapter-num">08</span>
            <span class="ds-chapter-title">Modale & drawer-e — structură, dimensiuni, reguli</span>
        </div>

        <div class="ds-card">
            <div class="ds-card-head">
                <div><h2>Anatomia unui modal</h2><p>Overlay + box cu 3 zone fixe: header (titlu + ×), body (scrollabil), footer (acțiuni).</p></div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2" style="align-items:start">
                    <div>
                        <div class="ds-demo-label">Structura completă</div>
                        <div class="ds-mbox">
                            <div class="ds-mhead">
                                <div><h3>Programare nouă</h3><p>Completează detaliile intervenției</p></div>
                                <button class="ds-mclose">&times;</button>
                            </div>
                            <div class="ds-mbody">
                                <div class="ds-ff" style="margin-bottom:10px">
                                    <span class="ds-label">Client</span>
                                    <select class="ds-select"><option>SKY CERT GLOBAL S.R.L.</option></select>
                                </div>
                                <div class="ds-ff">
                                    <span class="ds-label">Tip serviciu</span>
                                    <select class="ds-select"><option>Deratizare</option></select>
                                </div>
                                <div style="height:30px;display:flex;align-items:flex-end;padding-bottom:4px">
                                    <span style="font-size:11px;color:var(--pz-fa)">&#8595; Body scrollabil</span>
                                </div>
                            </div>
                            <div class="ds-mfoot">
                                <button class="ds-btn secondary sm">Anulează</button>
                                <button class="ds-btn primary sm">Salvează</button>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="ds-demo-label">Overlay — culoare</div>
                        <div style="height:55px;border-radius:6px;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;margin-bottom:12px">
                            <span style="font-size:11.5px;font-weight:600;color:rgba(255,255,255,.85)">rgba(15, 23, 42, 0.45)</span>
                        </div>
                        <div class="ds-rules col-2">
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Header: titlu + subtitle + × dreapta</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Body: scrollabil, padding 16px</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Footer: bg soft, butoane la dreapta</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Overlay: click închide modalul</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Escape key: închide orice modal</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Max-height: calc(100vh − 32px)</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>z-index: peste 1000</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Scroll body, nu pagina</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ds-card">
            <div class="ds-card-head"><div><h2>Dimensiuni & Drawer</h2><p>5 mărimi pentru modal. Drawer = slide-in din dreapta.</p></div></div>
            <div class="ds-card-body">
                <div class="ds-grid-2" style="align-items:start">
                    <div>
                        <div class="ds-demo-label">Dimensiuni modal</div>
                        <div class="ds-size-row"><span class="ds-size-label">xs</span><div class="ds-size-bar" style="width:100px"></div><span class="ds-size-val">max-width: 340px</span><span class="ds-size-use">Confirmare Da/Nu</span></div>
                        <div class="ds-size-row"><span class="ds-size-label">sm</span><div class="ds-size-bar" style="width:140px"></div><span class="ds-size-val">max-width: 480px</span><span class="ds-size-use">Adaugă simplu</span></div>
                        <div class="ds-size-row"><span class="ds-size-label">md</span><div class="ds-size-bar" style="width:190px"></div><span class="ds-size-val">max-width: 640px</span><span class="ds-size-use">Formulare medii</span></div>
                        <div class="ds-size-row"><span class="ds-size-label">lg</span><div class="ds-size-bar" style="width:250px"></div><span class="ds-size-val">max-width: 860px</span><span class="ds-size-use">Client nou, complex</span></div>
                        <div class="ds-size-row"><span class="ds-size-label">xl</span><div class="ds-size-bar" style="width:320px"></div><span class="ds-size-val">max-width: 1080px</span><span class="ds-size-use">Fișă rapidă, preview</span></div>
                    </div>
                    <div>
                        <div class="ds-demo-label">Drawer — slide-in dreapta (360–480px)</div>
                        <div class="ds-drawer-demo">
                            <div style="display:flex;align-items:center;justify-content:center;flex:1;font-size:11px;color:rgba(255,255,255,.4)">Pagina principală</div>
                            <div class="ds-drawer-panel">
                                <div class="ds-mhead"><div><h3>Filtre avansate</h3><p>Restreinge lista</p></div><button class="ds-mclose">&times;</button></div>
                                <div class="ds-mbody" style="display:grid;gap:10px">
                                    <div class="ds-ff"><span class="ds-label">Status</span><select class="ds-select"><option>Activ</option></select></div>
                                    <div class="ds-ff"><span class="ds-label">Județ</span><select class="ds-select"><option>Toate</option></select></div>
                                </div>
                                <div class="ds-mfoot"><button class="ds-btn ghost sm">Reset</button><button class="ds-btn primary sm">Aplică</button></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ 09 FORMULARE ═══ -->
        <div class="ds-chapter" id="formulare">
            <span class="ds-chapter-num">09</span>
            <span class="ds-chapter-title">Formulare — layout, stări, validare, controale</span>
        </div>

        <div class="ds-card">
            <div class="ds-card-head"><div><h2>Layout și secțiuni</h2><p>Formularul se grupează în secțiuni logice cu titlu. Grila: 1, 2 sau 3 coloane.</p></div></div>
            <div class="ds-card-body">
                <div class="ds-fsec">
                    <div class="ds-fsec-title">Date de identificare</div>
                    <div class="ds-fgrid c2">
                        <div class="ds-ff">
                            <span class="ds-label">Denumire firmă *</span>
                            <input class="ds-input" value="SKY CERT GLOBAL S.R.L." readonly>
                        </div>
                        <div class="ds-ff">
                            <span class="ds-label">CUI *</span>
                            <input class="ds-input ok" value="12345678" readonly>
                            <span class="ds-fhint">Preluat automat de la ANAF</span>
                        </div>
                        <div class="ds-ff">
                            <span class="ds-label">Email *</span>
                            <input class="ds-input err" value="email_incorect" readonly>
                            <span class="ds-ferr">⚠ Adresa de email nu este validă</span>
                        </div>
                        <div class="ds-ff">
                            <span class="ds-label">Telefon *</span>
                            <input class="ds-input" placeholder="07xx xxx xxx">
                        </div>
                        <div class="ds-ff full">
                            <span class="ds-label">Observații</span>
                            <textarea class="ds-textarea" placeholder="Detalii suplimentare..."></textarea>
                            <span class="ds-fhint">Opțional. Vizibil doar intern, nu pe documente.</span>
                        </div>
                    </div>
                </div>
                <div class="ds-fsec">
                    <div class="ds-fsec-title">Adresă fiscală</div>
                    <div class="ds-fgrid c3">
                        <div class="ds-ff"><span class="ds-label">Județ *</span><input class="ds-input" value="Constanța" readonly></div>
                        <div class="ds-ff"><span class="ds-label">Oraș *</span><input class="ds-input" value="Constanța" readonly></div>
                        <div class="ds-ff"><span class="ds-label">Cod poștal</span><input class="ds-input" placeholder="900001" disabled><span class="ds-fhint">Câmp dezactivat</span></div>
                        <div class="ds-ff full"><span class="ds-label">Stradă *</span><input class="ds-input" placeholder="Strada, număr, bloc, sc., et., ap."></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ds-card">
            <div class="ds-card-head"><div><h2>Stări câmpuri & controale speciale</h2><p>6 stări vizuale. Toggle switch, checkbox, radio.</p></div></div>
            <div class="ds-card-body">
                <div class="ds-grid-2">
                    <div style="display:grid;gap:10px">
                        <div class="ds-demo-label" style="margin-bottom:0">Stări input</div>
                        <div class="ds-ff"><span class="ds-label">Normal</span><input class="ds-input" placeholder="Placeholder text"><span class="ds-fhint">Border: #E2E8F0</span></div>
                        <div class="ds-ff"><span class="ds-label">Focus</span><input class="ds-input" value="Text activ" style="border-color:var(--pz-bl);outline:0"><span class="ds-fhint">Border albastru</span></div>
                        <div class="ds-ff"><span class="ds-label">Valid</span><input class="ds-input ok" value="contact@firma.ro" readonly><span class="ds-fhint">Border verde</span></div>
                        <div class="ds-ff"><span class="ds-label">Eroare</span><input class="ds-input err" value="email_invalid" readonly><span class="ds-ferr">⚠ Adresa nu este validă</span></div>
                        <div class="ds-ff"><span class="ds-label">Dezactivat</span><input class="ds-input" value="Nu se poate modifica" disabled><span class="ds-fhint">Opacity .45</span></div>
                    </div>
                    <div>
                        <div class="ds-demo-label">Toggle switch</div>
                        <div style="display:grid;gap:12px;margin-bottom:14px">
                            <div class="ds-toggle-row">
                                <label class="ds-toggle"><input type="checkbox" checked><span class="ds-toggle-sl"></span></label>
                                <div><div class="ds-toggle-lbl">Client activ</div><div class="ds-toggle-sub">Primește programari</div></div>
                            </div>
                            <div class="ds-toggle-row">
                                <label class="ds-toggle"><input type="checkbox"><span class="ds-toggle-sl"></span></label>
                                <div><div class="ds-toggle-lbl">SMS dezactivat</div><div class="ds-toggle-sub">Fără notificări</div></div>
                            </div>
                        </div>
                        <div class="ds-demo-label">Checkbox & Radio</div>
                        <div style="display:grid;gap:8px">
                            <label style="display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:500;cursor:pointer"><input type="checkbox" style="width:16px;height:16px;accent-color:var(--pz-bl)" checked><span>Email la programare</span></label>
                            <label style="display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:500;cursor:pointer"><input type="checkbox" style="width:16px;height:16px;accent-color:var(--pz-bl)"><span>SMS cu 24h înainte</span></label>
                            <label style="display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:500;cursor:pointer"><input type="radio" name="tip2" style="width:16px;height:16px;accent-color:var(--pz-bl)" checked><span>Persoană juridică</span></label>
                            <label style="display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:500;cursor:pointer"><input type="radio" name="tip2" style="width:16px;height:16px;accent-color:var(--pz-bl)"><span>Persoană fizică</span></label>
                        </div>
                    </div>
                </div>
                <div class="ds-rules col-2" style="margin-top:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Labels: uppercase 10.5px muted, deasupra câmpului</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Helptext: sub câmp, 11px faint</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Eroarea: sub câmpul invalid, nu în alert global</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Validare la submit, nu la fiecare tastare</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Toggle activ: bg var(--pz-gr-acc) verde</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Checkbox accent-color: var(--pz-bl)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Câmpuri oblig.: marcate cu * în label</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Câmpuri full-width: grid-column 1/-1</div>
                </div>
            </div>
        </div>

        <!-- ═══ 10 NOTIFICĂRI ═══ -->
        <div class="ds-chapter" id="notificari">
            <span class="ds-chapter-num">10</span>
            <span class="ds-chapter-title">Notificări & toast-uri — inline, toast, banner</span>
        </div>

        <div class="ds-card">
            <div class="ds-card-head"><div><h2>Notice-uri inline</h2><p>După submit form (PHP flash). 4 variante semantice.</p></div></div>
            <div class="ds-card-body">
                <div class="ds-notice ok"><span class="ds-notice-ico">✓</span><div><strong>Clientul a fost salvat.</strong> Fișa a fost actualizată cu succes.</div><button class="ds-notice-x">&times;</button></div>
                <div class="ds-notice err"><span class="ds-notice-ico">⚠</span><div><strong>Eroare la salvare.</strong> Verifică câmpurile obligatorii și încearcă din nou.</div><button class="ds-notice-x">&times;</button></div>
                <div class="ds-notice warn"><span class="ds-notice-ico">⚠</span><div><strong>Clientul nu poate fi șters.</strong> Are 3 programari active. Dezactivează-l în loc de ștergere.</div><button class="ds-notice-x">&times;</button></div>
                <div class="ds-notice info"><span class="ds-notice-ico">ℹ</span><div><strong>Sincronizare SmartBill.</strong> Factura va fi trimisă în maxim 30 secunde.</div><button class="ds-notice-x">&times;</button></div>
            </div>
        </div>

        <div class="ds-card">
            <div class="ds-card-head"><div><h2>Toast-uri & banner inline</h2><p>Toast: floating bottom-right, dispare automat. Banner: în interiorul unui card.</p></div></div>
            <div class="ds-card-body">
                <div class="ds-grid-2">
                    <div>
                        <div class="ds-demo-label">Toast-uri stivuite (bottom-right)</div>
                        <div class="ds-toast-stack">
                            <div class="ds-toast ok"><div class="ds-toast-dot"></div><div style="flex:1">Factură #2024-142 trimisă.</div><span class="ds-toast-tag">Acum</span><button class="ds-toast-x">&times;</button></div>
                            <div class="ds-toast warn"><div class="ds-toast-dot"></div><div style="flex:1">SmartBill nu răspunde. Retry în 30s.</div><span class="ds-toast-tag">10s</span><button class="ds-toast-x">&times;</button></div>
                            <div class="ds-toast err"><div class="ds-toast-dot"></div><div style="flex:1">SMS eșuat: număr invalid.</div><span class="ds-toast-tag">35s</span><button class="ds-toast-x">&times;</button></div>
                            <div class="ds-toast info"><div class="ds-toast-dot"></div><div style="flex:1">Raport generat. Descărcare în curs.</div><span class="ds-toast-tag">1m</span><button class="ds-toast-x">&times;</button></div>
                        </div>
                    </div>
                    <div>
                        <div class="ds-demo-label">Banner în card (persistent)</div>
                        <div style="display:grid;gap:7px">
                            <div class="ds-banner ok"><strong>✓ Sincronizat.</strong> SmartBill a primit factura.</div>
                            <div class="ds-banner err"><strong>⚠ Eroare.</strong> Conexiunea cu SmartBill a expirat.</div>
                            <div class="ds-banner warn"><strong>Atenție.</strong> 3 intervenții așteaptă facturare.</div>
                            <div class="ds-banner info"><strong>Info.</strong> Următoarea sincronizare: în 2 ore.</div>
                        </div>
                        <div class="ds-rules col-2" style="margin-top:12px">
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Notice: flash PHP după redirect</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Toast: acțiuni async (AJAX)</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Banner: stare sistem persistentă</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Toast dispare în 5s, pauză la hover</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Max 3 toasturi simultan (LIFO)</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Mesaj specific, nu generic</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Clase PHP: <code class="ds-token">.notice-success/danger/warning</code></div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Erori câmp: sub input, nu notice global</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ 11 LOADING ═══ -->
        <div class="ds-chapter" id="loading">
            <span class="ds-chapter-num">11</span>
            <span class="ds-chapter-title">Stări de loading — skeleton, spinner, disabled</span>
        </div>

        <div class="ds-card">
            <div class="ds-card-head"><div><h2>Skeleton — placeholder conținut</h2><p>Animat puls (opacity 1→0.35→1, 1.5s). Se folosește când știm structura dar nu și datele.</p></div><span class="ds-badge bl">@keyframes pz-pulse</span></div>
            <div class="ds-card-body">
                <div class="ds-grid-2" style="align-items:start">
                    <div>
                        <div class="ds-demo-label">Card skeleton</div>
                        <div style="background:var(--pz-surf);border:1px solid var(--pz-line);border-radius:var(--pz-r);overflow:hidden">
                            <div style="padding:12px 14px;border-bottom:1px solid var(--pz-lines);display:flex;align-items:center;gap:10px">
                                <div class="ds-skel ds-skel-circ" style="width:28px;height:28px"></div>
                                <div style="flex:1;display:grid;gap:6px">
                                    <div class="ds-skel ds-skel-line fat" style="width:55%"></div>
                                    <div class="ds-skel ds-skel-line thin" style="width:35%"></div>
                                </div>
                                <div class="ds-skel ds-skel-block" style="width:60px;height:22px"></div>
                            </div>
                            <div style="padding:12px 14px;display:grid;gap:8px">
                                <div class="ds-skel ds-skel-line" style="width:90%"></div>
                                <div class="ds-skel ds-skel-line" style="width:75%"></div>
                                <div class="ds-skel ds-skel-line thin" style="width:50%"></div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="ds-demo-label">Rânduri tabel skeleton</div>
                        <div style="background:var(--pz-surf);border:1px solid var(--pz-line);border-radius:var(--pz-r);overflow:hidden">
                            <div style="padding:8px 12px;background:var(--pz-soft);border-bottom:1px solid var(--pz-lines);display:flex;gap:12px">
                                <div class="ds-skel ds-skel-block" style="width:30%;height:10px"></div>
                                <div class="ds-skel ds-skel-block" style="width:20%;height:10px"></div>
                                <div class="ds-skel ds-skel-block" style="width:25%;height:10px"></div>
                            </div>
                            <?php for ($i=0;$i<4;$i++): ?>
                            <div style="padding:10px 12px;border-bottom:1px solid var(--pz-lines);display:flex;gap:12px;align-items:center">
                                <div class="ds-skel ds-skel-line" style="width:<?= [38,55,42,50][$i] ?>%;opacity:<?= [1,.9,.85,.75][$i] ?>"></div>
                                <div class="ds-skel ds-skel-line" style="width:18%;opacity:<?= [1,.9,.85,.75][$i] ?>"></div>
                                <div class="ds-skel ds-skel-block" style="width:50px;height:20px;border-radius:3px;opacity:<?= [1,.9,.85,.75][$i] ?>"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ds-card">
            <div class="ds-card-head"><div><h2>Spinner & starea disabled</h2><p>Cerc animat border-top. 3 dimensiuni. Disabled: opacity .45, pointer-events none.</p></div><span class="ds-badge bl">@keyframes pz-spin</span></div>
            <div class="ds-card-body">
                <div class="ds-grid-2">
                    <div>
                        <div class="ds-demo-label">Variante spinner</div>
                        <div style="display:flex;align-items:center;gap:20px;padding:14px;border:1px solid var(--pz-lines);border-radius:6px;background:var(--pz-soft);margin-bottom:12px">
                            <div style="display:flex;flex-direction:column;align-items:center;gap:6px">
                                <div class="ds-spin sm"></div><span style="font-size:10px;color:var(--pz-mu)">sm 14px</span>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:6px">
                                <div class="ds-spin"></div><span style="font-size:10px;color:var(--pz-mu)">normal 18px</span>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:6px">
                                <div class="ds-spin lg"></div><span style="font-size:10px;color:var(--pz-mu)">lg 28px</span>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:8px 12px;border-radius:6px;background:var(--pz-bl)">
                                <div class="ds-spin sm wht"></div><span style="font-size:10px;color:rgba(255,255,255,.7)">alb</span>
                            </div>
                        </div>
                        <div class="ds-demo-label">Buton în loading</div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;padding:12px;border:1px solid var(--pz-lines);border-radius:6px;background:var(--pz-soft)">
                            <button class="ds-btn primary loading" style="display:inline-flex;align-items:center;gap:6px" disabled><div class="ds-spin sm wht"></div>Se salvează...</button>
                            <button class="ds-btn secondary loading" style="display:inline-flex;align-items:center;gap:6px" disabled><div class="ds-spin sm"></div>Se încarcă...</button>
                        </div>
                    </div>
                    <div>
                        <div class="ds-demo-label">Stare disabled</div>
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px;border:1px solid var(--pz-lines);border-radius:6px;background:var(--pz-soft);margin-bottom:12px">
                            <button class="ds-btn primary" disabled style="opacity:.45;cursor:not-allowed">Primar</button>
                            <button class="ds-btn secondary" disabled style="opacity:.45;cursor:not-allowed">Secondary</button>
                            <input class="ds-input" value="Câmp dezactivat" disabled style="max-width:160px">
                            <span class="ds-badge bl" style="opacity:.45">Badge</span>
                        </div>
                        <div class="ds-rules col-2">
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Skeleton: încărcare inițială</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Spinner: acțiuni AJAX</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Disabled: opacity .45</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Nu se ascunde — rămâne în layout</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Buton loading: text schimbat</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Spinner alb pe fundal colorat</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Fără loading &lt;200ms</div>
                            <div class="ds-rule"><span class="ds-rule-dot"></span>Timeout max: 30s</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ 12 ICONURI ═══ -->
        <div class="ds-chapter" id="iconuri">
            <span class="ds-chapter-num">12</span>
            <span class="ds-chapter-title">Iconuri — sistem mononcrom, interacțiune, reguli</span>
        </div>

        <div class="ds-note">
            <strong>Principiu:</strong> toate iconurile sunt liniare (stroke, fill: none), monocolore implicit (<code class="ds-token">var(--pz-mu)</code>), și se colorează la interacțiune via <code class="ds-token">color</code> CSS + <code class="ds-token">currentColor</code>. Nu se folosesc icon-uri cu fill sau multicolore.
        </div>

        <!-- Grila completa de iconuri -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Biblioteca de iconuri</h2>
                    <p>Toate iconurile disponibile. <strong>Trece cu mouse-ul</strong> pentru a vedea interacțiunea. Sursă: <code class="ds-token">app_icon_svg('name')</code></p>
                </div>
                <span class="ds-badge bl">30 iconuri</span>
            </div>
            <div class="ds-card-body">
                <?php
                $allIcons = [
                    /* Navigare principală */
                    'dashboard' => 'dashboard',
                    'calendar'  => 'calendar',
                    'tasks'     => 'tasks',
                    'clients'   => 'clients',
                    'team'      => 'team',
                    'reports'   => 'reports',
                    /* Documente */
                    'documents' => 'documents',
                    'contracts' => 'contracts',
                    'processes' => 'processes',
                    'offers'    => 'offers',
                    'series'    => 'series',
                    'clipboard' => 'clipboard',
                    /* Financiar */
                    'invoice'   => 'invoice',
                    'stock'     => 'stock',
                    /* Setări */
                    'settings'  => 'settings',
                    'services'  => 'services',
                    'users'     => 'users',
                    'company'   => 'company',
                    'design'    => 'design',
                    /* Acțiuni */
                    'plus'      => 'plus',
                    'edit'      => 'edit',
                    'eye'       => 'eye',
                    'search'    => 'search',
                    'check'     => 'check',
                    'more'      => 'more',
                    'logout'    => 'logout',
                    /* Comunicări */
                    'mail'      => 'mail',
                    'phone'     => 'phone',
                    'alert'     => 'alert',
                    'star'      => 'star',
                ];

                // Extrage SVG-ul brut (fără span-ul nav-icon)
                $rawSvgs = [
                    'dashboard' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg>',
                    'calendar'  => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="4"/><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M8 14h3"/><path d="M13 14h3"/><path d="M8 18h3"/></svg>',
                    'tasks'     => '<svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="4"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/><path d="M16.5 15.5l1.2 1.2 2.3-2.7"/></svg>',
                    'clients'   => '<svg viewBox="0 0 24 24"><path d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4"/><circle cx="12" cy="9" r="3"/><path d="M4.5 18.5c.4-2.1 1.7-3.8 3.5-4.8"/><path d="M19.5 18.5c-.4-2.1-1.7-3.8-3.5-4.8"/></svg>',
                    'contracts' => '<svg viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="3"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h3"/><path d="M16 17l1 1 2-2"/></svg>',
                    'documents' => '<svg viewBox="0 0 24 24"><path d="M6 3h9l3 3v15H6z"/><path d="M15 3v4h4"/><path d="M9 10h6"/><path d="M9 14h6"/><path d="M9 18h4"/></svg>',
                    'offers'    => '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="3"/><path d="M8 9h8"/><path d="M8 13h5"/><path d="M16 15.5l2 2 3-4"/><path d="M7 3v4"/><path d="M17 3v4"/></svg>',
                    'processes' => '<svg viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="3"/><path d="M9 7h6"/><path d="M9 11h6"/><path d="M9 15h3"/><path d="M14 17l1.5 1.5L19 15"/></svg>',
                    'series'    => '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="3"/><path d="M8 9h8"/><path d="M8 13h4"/><path d="M15 13h1.5"/><path d="M7 3v4"/><path d="M17 3v4"/></svg>',
                    'services'  => '<svg viewBox="0 0 24 24"><path d="M12 3l1.4 3.2 3.5.4-2.6 2.3.7 3.4-3-1.8-3 1.8.7-3.4-2.6-2.3 3.5-.4L12 3z"/><path d="M5 15h14"/><path d="M7 19h10"/></svg>',
                    'team'      => '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.4-3 2.6-5 5.5-5s5.1 2 5.5 5"/><circle cx="17" cy="10" r="2.5"/><path d="M15.5 15c2.5.2 4.4 1.8 5 4"/></svg>',
                    'reports'   => '<svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="4"/><path d="M8 17V11"/><path d="M12 17V7"/><path d="M16 17v-4"/><path d="M7 17h10"/></svg>',
                    'star'      => '<svg viewBox="0 0 24 24"><path d="M12 3.5l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 16.8l-5.2 2.7 1-5.8-4.2-4.1 5.8-.8L12 3.5Z"/></svg>',
                    'users'     => '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.5-3 2.7-5 5.5-5s5 2 5.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 15c2.4.2 4.2 1.8 5 4"/></svg>',
                    'design'    => '<svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-9 9c0 4.4 3.6 8 8 8h1.5a2 2 0 0 0 0-4H12a1.5 1.5 0 0 1 0-3h1a8 8 0 0 0 8-8c0-1.1-.9-2-2-2h-7z"/><circle cx="7.5" cy="10" r=".8"/><circle cx="10.5" cy="7.5" r=".8"/><circle cx="14" cy="7.5" r=".8"/></svg>',
                    'company'   => '<svg viewBox="0 0 24 24"><path d="M4 21V7a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v14"/><path d="M15 10h3a2 2 0 0 1 2 2v9"/><path d="M8 9h3"/><path d="M8 13h3"/><path d="M8 17h3"/><path d="M3 21h18"/></svg>',
                    'settings'  => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3"/><path d="M12 19v3"/><path d="M2 12h3"/><path d="M19 12h3"/><path d="M4.9 4.9l2.1 2.1"/><path d="M17 17l2.1 2.1"/><path d="M19.1 4.9L17 7"/><path d="M7 17l-2.1 2.1"/></svg>',
                    'invoice'   => '<svg viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.3-2 1.3-2-1.3-2 1.3-2-1.3L6 21V3Z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/></svg>',
                    'stock'     => '<svg viewBox="0 0 24 24"><path d="M4 7l8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/><path d="M12 11v10"/><path d="M20 12l-8 4-8-4"/></svg>',
                    'plus'      => '<svg viewBox="0 0 24 24"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
                    'eye'       => '<svg viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="3"/></svg>',
                    'edit'      => '<svg viewBox="0 0 24 24"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="M13.5 7.5l3 3"/></svg>',
                    'mail'      => '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M4 7l8 6 8-6"/></svg>',
                    'phone'     => '<svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.4 19.4 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.7.6 2.5a2 2 0 0 1-.4 2.1L8 9.6a16 16 0 0 0 6.4 6.4l1.3-1.3a2 2 0 0 1 2.1-.4c.8.3 1.6.5 2.5.6A2 2 0 0 1 22 16.9Z"/></svg>',
                    'search'    => '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>',
                    'more'      => '<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="19" r="1.2"/></svg>',
                    'check'     => '<svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>',
                    'alert'     => '<svg viewBox="0 0 24 24"><path d="M12 4l9 16H3L12 4Z"/><path d="M12 9v5"/><path d="M12 17h.01"/></svg>',
                    'clipboard' => '<svg viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="17" rx="3"/><path d="M9 4.5A3 3 0 0 1 12 2a3 3 0 0 1 3 2.5"/><path d="M9 9h6"/><path d="M9 13h6"/><path d="M9 17h3"/></svg>',
                    'logout'    => '<svg viewBox="0 0 24 24"><path d="M10 4H6.5A2.5 2.5 0 0 0 4 6.5v11A2.5 2.5 0 0 0 6.5 20H10"/><path d="M14 8l4 4-4 4"/><path d="M18 12H9"/></svg>',
                ];
                ?>
                <div class="ds-ico-grid">
                    <?php foreach ($rawSvgs as $name => $svg): ?>
                    <div class="ds-ico-cell" title="app_icon_svg('<?= $name ?>')">
                        <?= $svg ?>
                        <span class="ds-ico-cell-name"><?= $name ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Specificații tehnice -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Specificații tehnice</h2>
                    <p>Regulile SVG care trebuie să se aplice la orice icon nou adăugat.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2">
                    <div>
                        <div class="ds-demo-label">Structura SVG corectă</div>
                        <div style="background:var(--pz-soft);border:1px solid var(--pz-lines);border-radius:6px;padding:12px;font-family:'Courier New',monospace;font-size:11.5px;line-height:1.6;color:var(--pz-bld)">
&lt;<span style="color:var(--pz-re)">svg</span> viewBox="0 0 24 24"&gt;<br>
&nbsp;&nbsp;&lt;<span style="color:var(--pz-re)">path</span> d="M..."/&gt;<br>
&nbsp;&nbsp;&lt;<span style="color:var(--pz-re)">circle</span> cx="12" cy="12" r="3"/&gt;<br>
&lt;/<span style="color:var(--pz-re)">svg</span>&gt;<br>
<br>
<span style="color:var(--pz-mu)">&lt;!-- Via CSS: --&gt;</span><br>
svg { fill: none; stroke: currentColor;<br>
&nbsp;&nbsp;stroke-width: 1.75;<br>
&nbsp;&nbsp;stroke-linecap: round;<br>
&nbsp;&nbsp;stroke-linejoin: round; }
                        </div>
                    </div>
                    <div>
                        <div class="ds-demo-label">Dimensiuni standard</div>
                        <div style="display:grid;gap:8px">
                            <?php
                            $editSvg = '<svg viewBox="0 0 24 24"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="M13.5 7.5l3 3"/></svg>';
                            $sizes = [['14px','Compact — acțiuni tabel'],['16px','UI normal — butoane, liste'],['18px','Standard — nav sidebar'],['20px','Mediu — header'],['24px','Mare — hero, empty state']];
                            foreach ($sizes as [$sz, $use]):
                            ?>
                            <div style="display:flex;align-items:center;gap:12px;padding:6px 10px;border:1px solid var(--pz-lines);border-radius:5px;background:var(--pz-soft)">
                                <svg viewBox="0 0 24 24" style="width:<?= $sz ?>;height:<?= $sz ?>;flex-shrink:0;stroke:var(--pz-mu);fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="M13.5 7.5l3 3"/></svg>
                                <code class="ds-token" style="font-size:10px"><?= $sz ?></code>
                                <span style="font-size:11.5px;color:var(--pz-mu)"><?= $use ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Interacțiune — state-uri si culori -->
        <div class="ds-card">
            <div class="ds-card-head">
                <div>
                    <h2>Interacțiune — state-uri și culori</h2>
                    <p>Mononcrom implicit, se colorează via <code class="ds-token">color</code> CSS. Trece cu mouse-ul pe elemente.</p>
                </div>
            </div>
            <div class="ds-card-body">
                <div class="ds-grid-2">

                    <div>
                        <div class="ds-demo-label">Butoane icon (.pz-ico-btn)</div>
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:14px;border:1px solid var(--pz-lines);border-radius:6px;background:var(--pz-soft)">
                            <div style="display:flex;flex-direction:column;align-items:center;gap:5px">
                                <button class="pz-ico-btn"><?= str_replace('<svg', '<svg width="15" height="15" style="stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"', $rawSvgs['eye']) ?></button>
                                <span style="font-size:9.5px;color:var(--pz-fa)">default</span>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:5px">
                                <button class="pz-ico-btn active"><?= str_replace('<svg', '<svg width="15" height="15" style="stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"', $rawSvgs['eye']) ?></button>
                                <span style="font-size:9.5px;color:var(--pz-fa)">active/albastru</span>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:5px">
                                <button class="pz-ico-btn danger" style="color:var(--pz-re);border-color:var(--pz-reb);background:var(--pz-res)"><?= str_replace('<svg', '<svg width="15" height="15" style="stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"', $rawSvgs['logout']) ?></button>
                                <span style="font-size:9.5px;color:var(--pz-fa)">roșu/danger</span>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:5px">
                                <button class="pz-ico-btn sm"><?= str_replace('<svg', '<svg width="13" height="13" style="stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"', $rawSvgs['edit']) ?></button>
                                <span style="font-size:9.5px;color:var(--pz-fa)">sm (26px)</span>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:5px">
                                <button class="pz-ico-btn"><?= str_replace('<svg', '<svg width="15" height="15" style="stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"', $rawSvgs['edit']) ?></button>
                                <span style="font-size:9.5px;color:var(--pz-fa)">normal (30px)</span>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:5px">
                                <button class="pz-ico-btn lg"><?= str_replace('<svg', '<svg width="17" height="17" style="stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"', $rawSvgs['edit']) ?></button>
                                <span style="font-size:9.5px;color:var(--pz-fa)">lg (36px)</span>
                            </div>
                        </div>

                        <div class="ds-demo-label" style="margin-top:14px">Icon+text links (.pz-ico-link)</div>
                        <div style="display:grid;gap:8px;padding:12px;border:1px solid var(--pz-lines);border-radius:6px;background:var(--pz-soft)">
                            <a class="pz-ico-link"><?= str_replace('<svg', '<svg width="15" height="15" style="stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"', $rawSvgs['calendar']) ?> Programează</a>
                            <a class="pz-ico-link"><?= str_replace('<svg', '<svg width="15" height="15" style="stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"', $rawSvgs['invoice']) ?> Facturează</a>
                            <a class="pz-ico-link"><?= str_replace('<svg', '<svg width="15" height="15" style="stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"', $rawSvgs['edit']) ?> Editează fișa</a>
                            <a class="pz-ico-link danger"><?= str_replace('<svg', '<svg width="15" height="15" style="stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"', $rawSvgs['logout']) ?> Șterge client</a>
                        </div>
                    </div>

                    <div>
                        <div class="ds-demo-label">State-uri de culoare</div>
                        <?php
                        $states = [
                            ['--pz-mu',      '#64748B', 'Default',        'Mononcrom, muted gray'],
                            ['--pz-bl',      '#2563EB', 'Hover primar',   'Blue — acțiune principală'],
                            ['--pz-bld',     '#1E3A8A', 'Activ/pressed',  'Dark blue — taste apăsată'],
                            ['--pz-re',      '#991B1B', 'Danger hover',   'Red — acțiune destructivă'],
                            ['--pz-gr',      '#166534', 'Success',        'Green — confirmat, ok'],
                            ['--pz-or',      '#9A3412', 'Warning',        'Orange — atenție'],
                            ['#fff',         '#FFFFFF', 'Alb (pe fond)',  'Pe fundal colorat (nav sidebar)'],
                            ['rgba(255,255,255,.6)', null, 'Alb muted',  'Pe sidebar, inactiv'],
                        ];
                        ?>
                        <div style="display:grid;gap:7px">
                            <?php foreach ($states as [$token, $hex, $label, $desc]): ?>
                            <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;border:1px solid var(--pz-lines);border-radius:6px;background:<?= ($hex === '#FFFFFF') ? '#12345A' : 'var(--pz-soft)' ?>">
                                <svg viewBox="0 0 24 24" style="width:18px;height:18px;flex-shrink:0;stroke:<?= $token ?>;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.4-3 2.6-5 5.5-5s5.1 2 5.5 5"/><circle cx="17" cy="10" r="2.5"/><path d="M15.5 15c2.5.2 4.4 1.8 5 4"/></svg>
                                <div style="flex:1">
                                    <div style="font-size:12px;font-weight:600;color:<?= ($hex === '#FFFFFF') ? 'rgba(255,255,255,.85)' : 'var(--pz-title)' ?>"><?= $label ?></div>
                                    <div style="font-size:11px;color:<?= ($hex === '#FFFFFF') ? 'rgba(255,255,255,.55)' : 'var(--pz-mu)' ?>"><?= $desc ?></div>
                                </div>
                                <code style="font-family:'Courier New',monospace;font-size:10px;color:<?= ($hex === '#FFFFFF') ? 'rgba(255,255,255,.6)' : 'var(--pz-mu)' ?>"><?= $token ?></code>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Reguli -->
        <div class="ds-card">
            <div class="ds-card-head"><div><h2>Reguli pentru iconuri</h2></div></div>
            <div class="ds-card-body">
                <div class="ds-demo-label">SVG & stil</div>
                <div class="ds-rules" style="margin-bottom:14px">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Toate SVG-urile: <code class="ds-token">fill: none; stroke: currentColor</code></div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>stroke-width: <strong>1.75</strong> (nu 2, nu 1.5)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>stroke-linecap: <strong>round</strong> — capăt rotunjit</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>stroke-linejoin: <strong>round</strong> — colț rotunjit</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>viewBox: <strong>0 0 24 24</strong> — grid 24px</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Fără fill pe forme — icon-urile sunt liniare</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Fără hardcoded culori în SVG</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Nu se adaugă iconuri fără a fi înregistrate în <code class="ds-token">app_icon_svg()</code></div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Tranziție: <code class="ds-token">color .15s</code> pe parent sau <code class="ds-token">stroke .15s</code> pe SVG</div>
                </div>
                <div class="ds-demo-label">Culori & interacțiune</div>
                <div class="ds-rules">
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Default: color <code class="ds-token">var(--pz-mu)</code> (gri muted)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Hover: color <code class="ds-token">var(--pz-bl)</code> (albastru)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Active/Pressed: color <code class="ds-token">var(--pz-bld)</code> (albastru dark)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Danger hover: color <code class="ds-token">var(--pz-re)</code> (roșu)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Pe sidebar: culoare moștenită din nav-item (alb variabil)</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Nu se setează culoarea direct pe SVG — via parent</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Icon-uri în butoane: moștenesc culoarea butonului</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Nu se folosește opacity pentru dezactivare — se folosește culoarea faint</div>
                    <div class="ds-rule"><span class="ds-rule-dot"></span>Icon în nav activ: alb solid (#fff)</div>
                </div>
            </div>
        </div>

        </div><!-- .ds -->
        </div>
    </main>
</div>
</body>
</html>
