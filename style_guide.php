<?php
require_once 'config.php';
require_login();
require_once 'app_ui.php';

if (!is_admin()) {
    header('Location: calendar.php');
    exit;
}

$pz_page_title       = 'Ghid de stil';
$pz_page_breadcrumbs = ['Setări', 'Ghid de stil'];
$pz_topbar_opts      = ['placeholder' => 'Caută în ghid...'];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Ghid de stil · <?= h(pz_app_name()) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<?php app_theme_css(); ?>
<style>
/* ═══════════════════════════════════════════════════════════════
   Ghid de stil — stiluri locale, prefixate sg-*
   Tokens (--pz-*, --font) sunt moștenite din app_theme_css.php.
   Această pagină NU redefinește tokens și nu suprascrie tema globală.
═══════════════════════════════════════════════════════════════ */

.sg {
    max-width: 1180px;
    margin: 0 auto;
    padding: 16px;
    display: grid;
    gap: 16px;
    font-family: var(--font, 'Satoshi', 'Inter', system-ui, sans-serif);
    font-size: 13px;
    color: var(--pz-text);
    line-height: 1.5;
}
.sg * { box-sizing: border-box; }
.sg h1 { font-size: 22px; font-weight: 700; color: var(--pz-title); margin: 0; letter-spacing: -.01em; }
.sg h2 { font-size: 16px; font-weight: 700; color: var(--pz-title); margin: 0 0 2px; }
.sg h3 { font-size: 13px; font-weight: 600; color: var(--pz-title); margin: 0 0 6px; }
.sg p  { margin: 0; color: var(--pz-text); }
.sg .mu { color: var(--pz-mu); }
.sg code { font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; font-size: 12px; background: var(--pz-soft); border: 1px solid var(--pz-line); border-radius: 3px; padding: 1px 5px; color: var(--pz-bld); }

/* Page header */
.sg-header {
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    padding: 22px 24px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
}
.sg-header h1 { margin-bottom: 4px; }
.sg-kicker { font-size: 10.5px; font-weight: 700; color: var(--pz-mu); letter-spacing: .08em; text-transform: uppercase; margin-bottom: 6px; }
.sg-meta { display: flex; flex-direction: column; gap: 4px; align-items: flex-end; font-size: 11px; }
.sg-version { background: var(--pz-bls); color: var(--pz-bld); border: 1px solid var(--pz-blb); border-radius: 999px; padding: 2px 10px; font-weight: 600; }
.sg-date { color: var(--pz-mu); }

/* Cuprins */
.sg-toc {
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    padding: 12px 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 4px 16px;
    align-items: center;
    font-size: 12px;
}
.sg-toc-label { font-weight: 700; color: var(--pz-title); margin-right: 8px; }
.sg-toc a { color: var(--pz-text); text-decoration: none; padding: 4px 0; border-bottom: 1px solid transparent; }
.sg-toc a:hover { color: var(--pz-bl); border-bottom-color: var(--pz-bl); }

/* Marker capitol */
.sg-chapter {
    display: flex;
    align-items: baseline;
    gap: 12px;
    margin: 6px 0 -6px;
    padding-top: 6px;
}
.sg-chapter-num { font-size: 11px; font-weight: 700; color: var(--pz-mu); letter-spacing: .12em; }
.sg-chapter-title { font-size: 15px; font-weight: 700; color: var(--pz-title); }

/* Card */
.sg-card {
    background: var(--pz-surf);
    border: 1px solid var(--pz-line);
    border-radius: var(--pz-r);
    padding: 16px 18px;
}
.sg-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding-bottom: 12px;
    margin-bottom: 12px;
    border-bottom: 1px solid var(--pz-lines);
}
.sg-card-head p { color: var(--pz-mu); font-size: 12px; margin-top: 2px; }
.sg-card-head + .sg-section-label { margin-top: 0; }
.sg-section-label { font-size: 10.5px; font-weight: 700; color: var(--pz-mu); letter-spacing: .06em; text-transform: uppercase; margin: 12px 0 8px; }
.sg-card-body { display: grid; gap: 14px; }

/* Grilă utilitară */
.sg-grid-2 { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
.sg-grid-3 { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.sg-grid-4 { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
.sg-grid-6 { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); }

/* Swatch — pentru culori */
.sg-swatch { border: 1px solid var(--pz-line); border-radius: 6px; overflow: hidden; background: var(--pz-surf); }
.sg-swatch-color { height: 50px; }
.sg-swatch-body { padding: 8px 10px; }
.sg-swatch-name { font-weight: 600; font-size: 12px; color: var(--pz-title); }
.sg-swatch-hex { font-family: ui-monospace, Menlo, monospace; font-size: 11px; color: var(--pz-mu); margin-top: 1px; }
.sg-swatch-use { font-size: 11px; color: var(--pz-mu); margin-top: 4px; }

/* Reguli */
.sg-rules { display: grid; gap: 6px; }
.sg-rules.col-2 { grid-template-columns: 1fr 1fr; }
.sg-rule { display: flex; gap: 8px; align-items: flex-start; font-size: 12.5px; color: var(--pz-text); }
.sg-rule::before { content: ""; flex: 0 0 5px; width: 5px; height: 5px; border-radius: 50%; background: var(--pz-bl); margin-top: 7px; }

/* Tokens list */
.sg-token-row { display: grid; grid-template-columns: 22px 1fr auto; gap: 10px; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--pz-lines); font-size: 12px; }
.sg-token-row:last-child { border-bottom: none; }
.sg-token-dot { width: 16px; height: 16px; border-radius: 4px; border: 1px solid var(--pz-line); }
.sg-token-name { font-family: ui-monospace, Menlo, monospace; color: var(--pz-bld); font-weight: 500; }
.sg-token-val { color: var(--pz-mu); font-family: ui-monospace, Menlo, monospace; font-size: 11.5px; }

/* Status block */
.sg-status { border: 1px solid var(--pz-line); border-radius: 6px; overflow: hidden; background: var(--pz-surf); }
.sg-status-head { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: var(--pz-surf); font-weight: 600; font-size: 12px; color: var(--pz-title); border-bottom: 1px solid var(--pz-lines); }
.sg-status-dot { width: 8px; height: 8px; border-radius: 50%; }
.sg-status-body { padding: 10px 12px; font-size: 11.5px; color: var(--pz-text); }
.sg-status-when { font-size: 11px; color: var(--pz-mu); margin-bottom: 8px; }
.sg-status-examples { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }

/* Mini-componente folosite în demo */
.sg-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; border: 1px solid; }
.sg-badge.bl { background: var(--pz-bls); color: var(--pz-bld); border-color: var(--pz-blb); }
.sg-badge.gr { background: var(--pz-grs); color: var(--pz-gr); border-color: var(--pz-grb); }
.sg-badge.or { background: var(--pz-ors); color: var(--pz-or); border-color: var(--pz-orb); }
.sg-badge.re { background: var(--pz-res); color: var(--pz-re); border-color: var(--pz-reb); }
.sg-badge.nu { background: var(--pz-soft); color: var(--pz-title); border-color: var(--pz-line); }
.sg-chip { display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border-radius: 6px; font-size: 12px; background: var(--pz-soft); color: var(--pz-title); border: 1px solid var(--pz-line); }

.sg-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: var(--pz-rs); font-family: inherit; font-size: 12.5px; font-weight: 500; line-height: 1; cursor: pointer; border: 1px solid transparent; transition: background .15s, color .15s, border-color .15s; }
.sg-btn.primary { background: var(--pz-bl); color: #fff; }
.sg-btn.primary:hover { background: var(--pz-bld); }
.sg-btn.secondary { background: var(--pz-surf); color: var(--pz-title); border-color: var(--pz-line); }
.sg-btn.secondary:hover { background: var(--pz-soft); }
.sg-btn.danger { background: #fff; color: var(--pz-re); border-color: var(--pz-reb); }
.sg-btn.sm { padding: 4px 9px; font-size: 12px; }

.sg-input { width: 100%; height: 34px; padding: 0 9px; border-radius: var(--pz-rs); border: 1px solid var(--pz-line); background: var(--pz-surf); color: var(--pz-title); font-family: inherit; font-size: 13px; }
.sg-input:focus { outline: none; border-color: var(--pz-bl); box-shadow: 0 0 0 3px var(--pz-bls); }
.sg-label { display: block; font-size: 11px; font-weight: 500; color: #6B7280; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }

/* Tabel demo */
.sg-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.sg-table thead th { background: var(--pz-soft); color: var(--pz-mu); font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--pz-line); }
.sg-table tbody td { padding: 11px 12px; border-bottom: 1px solid var(--pz-lines); color: var(--pz-text); }
.sg-table tbody tr:last-child td { border-bottom: none; }
.sg-table tbody tr:hover { background: var(--pz-soft); }

/* Checklist */
.sg-check { display: grid; gap: 4px; }
.sg-check-item { display: flex; gap: 10px; align-items: flex-start; padding: 6px 0; border-bottom: 1px solid var(--pz-lines); font-size: 12.5px; }
.sg-check-item:last-child { border-bottom: none; }
.sg-check-box { flex: 0 0 16px; width: 16px; height: 16px; border: 1.5px solid var(--pz-line); border-radius: 3px; margin-top: 1px; }
.sg-check-item strong { color: var(--pz-title); font-weight: 600; }

/* Note / disclaimer */
.sg-note { background: var(--pz-bls); border: 1px solid var(--pz-blb); border-radius: 6px; padding: 10px 14px; color: var(--pz-bld); font-size: 12.5px; line-height: 1.5; }

/* Icon grid */
.sg-icon-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(96px, 1fr)); }
.sg-icon-cell { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 10px 6px; border: 1px solid var(--pz-line); border-radius: 6px; background: var(--pz-surf); font-size: 11px; color: var(--pz-mu); }
.sg-icon-cell svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; color: var(--pz-text); }

/* Responsive */
@media (max-width: 720px) {
    .sg { padding: 12px; }
    .sg-header { flex-direction: column; align-items: flex-start; gap: 8px; }
    .sg-rules.col-2 { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="layout">
    <?php render_sidebar('style_guide', true); ?>
    <main class="main">
        <div class="content">
        <?php pz_page_header([
            'back'     => ['href' => 'settings.php', 'label' => 'Înapoi la setări'],
            'kicker'   => 'Emma CRM · Identitate vizuală',
            'title'    => 'Ghid de stil',
            'subtitle' => 'Tokens de culoare, tipografie, sidebar & topbar, header-uri de pagină, tabele, formulare, stări. Doar ce e adevărat și esențial pentru o pagină nouă — fără demo-uri inutile.',
            'meta'     => [
                ['label' => 'Versiune', 'value' => 'v3.0'],
                ['label' => 'Actualizat', 'value' => 'Mai 2026'],
            ],
        ]); ?>
        <div class="sg">

        <!-- ─── Cuprins ─── -->
        <nav class="sg-toc" aria-label="Cuprins">
            <span class="sg-toc-label">Cuprins</span>
            <a href="#tokens">01 Tokens</a>
            <a href="#tipografie">02 Tipografie</a>
            <a href="#chrome">03 Sidebar &amp; Topbar</a>
            <a href="#headere">04 Header pagină</a>
            <a href="#status">05 Status semantic</a>
            <a href="#componente">06 Componente</a>
            <a href="#tabele">07 Tabele</a>
            <a href="#formulare">08 Formulare</a>
            <a href="#stari">09 Stări</a>
            <a href="#reguli">10 Reguli</a>
            <a href="#iconuri">11 Iconuri</a>
        </nav>

        <div class="sg-note">
            <strong>Sursa de adevăr</strong> pentru toate tokens (culori, raze, fonturi) este <code>app_theme_css.php</code>. Această pagină <em>citește</em> tokens — nu le redefinește. Dacă schimbi un token, schimbi automat ce vezi aici.
        </div>

        <!-- ══════════════════════════════════════════════════════
             01 TOKENS
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="tokens">
            <span class="sg-chapter-num">01</span>
            <span class="sg-chapter-title">Tokens — sursa unică de adevăr</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head">
                <div>
                    <h2>Paletă structurală</h2>
                    <p>Fundalurile, conturile și textul. Folosite pe orice ecran.</p>
                </div>
            </div>
            <div class="sg-card-body">
                <div class="sg-grid-6">
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#12345A"></div><div class="sg-swatch-body"><div class="sg-swatch-name">Brand Navy</div><div class="sg-swatch-hex">#12345A</div><div class="sg-swatch-use">Sidebar</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#F8FAFC"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-bg</div><div class="sg-swatch-hex">#F8FAFC</div><div class="sg-swatch-use">Fundal pagină</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#FFFFFF;border-bottom:1px solid #E2E8F0"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-surf</div><div class="sg-swatch-hex">#FFFFFF</div><div class="sg-swatch-use">Card / panel</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#E2E8F0"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-line</div><div class="sg-swatch-hex">#E2E8F0</div><div class="sg-swatch-use">Contur card</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#F1F5F9"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-lines</div><div class="sg-swatch-hex">#F1F5F9</div><div class="sg-swatch-use">Separator intern</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#2563EB"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-bl</div><div class="sg-swatch-hex">#2563EB</div><div class="sg-swatch-use">Buton primar / link</div></div></div>
                </div>

                <div class="sg-section-label">Text</div>
                <div class="sg-grid-4">
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#0F172A"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-title</div><div class="sg-swatch-hex">#0F172A</div><div class="sg-swatch-use">Titluri</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#334155"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-text</div><div class="sg-swatch-hex">#334155</div><div class="sg-swatch-use">Corp text</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#64748B"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-mu</div><div class="sg-swatch-hex">#64748B</div><div class="sg-swatch-use">Text secundar</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#94A3B8"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-fa</div><div class="sg-swatch-hex">#94A3B8</div><div class="sg-swatch-use">Placeholder</div></div></div>
                </div>

                <div class="sg-section-label">Status (vezi capitolul 05)</div>
                <div class="sg-grid-4">
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#2563EB"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-bl / bld / bls / blb</div><div class="sg-swatch-hex">Albastru — Info, acțiune</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#166534"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-gr / grs / grb</div><div class="sg-swatch-hex">Verde — Succes, plătit</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#9A3412"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-or / ors / orb</div><div class="sg-swatch-hex">Portocaliu — Atenție</div></div></div>
                    <div class="sg-swatch"><div class="sg-swatch-color" style="background:#991B1B"></div><div class="sg-swatch-body"><div class="sg-swatch-name">--pz-re / res / reb</div><div class="sg-swatch-hex">Roșu — Pericol</div></div></div>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head">
                <div>
                    <h2>Listă completă tokens</h2>
                    <p>Definiți în <code>app_theme_css.php</code>. Orice culoare nouă trebuie adăugată acolo, nu hardcodată.</p>
                </div>
                <span class="sg-badge bl">29 tokens</span>
            </div>
            <div class="sg-card-body">
                <div class="sg-grid-2">
                    <div>
                        <div class="sg-section-label">Suprafețe &amp; linii</div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#F8FAFC"></span><span class="sg-token-name">--pz-bg</span><span class="sg-token-val">#F8FAFC</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#FFFFFF;border-color:#E2E8F0"></span><span class="sg-token-name">--pz-surf</span><span class="sg-token-val">#FFFFFF</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#F8FAFC"></span><span class="sg-token-name">--pz-soft</span><span class="sg-token-val">#F8FAFC</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#E2E8F0"></span><span class="sg-token-name">--pz-line</span><span class="sg-token-val">#E2E8F0</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#F1F5F9"></span><span class="sg-token-name">--pz-lines</span><span class="sg-token-val">#F1F5F9</span></div>
                        <div class="sg-section-label">Text</div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#0F172A"></span><span class="sg-token-name">--pz-title</span><span class="sg-token-val">#0F172A</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#334155"></span><span class="sg-token-name">--pz-text</span><span class="sg-token-val">#334155</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#64748B"></span><span class="sg-token-name">--pz-mu</span><span class="sg-token-val">#64748B</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#94A3B8"></span><span class="sg-token-name">--pz-fa</span><span class="sg-token-val">#94A3B8</span></div>
                        <div class="sg-section-label">Geometrie</div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:transparent;border:2px solid #E2E8F0"></span><span class="sg-token-name">--pz-r</span><span class="sg-token-val">8px · card</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:transparent;border:1px solid #E2E8F0"></span><span class="sg-token-name">--pz-rs</span><span class="sg-token-val">4px · input/buton</span></div>
                    </div>
                    <div>
                        <div class="sg-section-label">Albastru — info / acțiune</div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#2563EB"></span><span class="sg-token-name">--pz-bl</span><span class="sg-token-val">#2563EB · primar</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#1E3A8A"></span><span class="sg-token-name">--pz-bld</span><span class="sg-token-val">#1E3A8A · text pe info</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#EFF6FF"></span><span class="sg-token-name">--pz-bls</span><span class="sg-token-val">#EFF6FF · fundal info</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#BFDBFE"></span><span class="sg-token-name">--pz-blb</span><span class="sg-token-val">#BFDBFE · bordură info</span></div>
                        <div class="sg-section-label">Verde / Portocaliu / Roșu</div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#166534"></span><span class="sg-token-name">--pz-gr / grs / grb</span><span class="sg-token-val">verde — succes</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#9A3412"></span><span class="sg-token-name">--pz-or / ors / orb</span><span class="sg-token-val">portocaliu — atenție</span></div>
                        <div class="sg-token-row"><span class="sg-token-dot" style="background:#991B1B"></span><span class="sg-token-name">--pz-re / res / reb</span><span class="sg-token-val">roșu — pericol</span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             02 TIPOGRAFIE
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="tipografie">
            <span class="sg-chapter-num">02</span>
            <span class="sg-chapter-title">Tipografie</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head">
                <div>
                    <h2>Inter — font unic al platformei</h2>
                    <p>Încărcat global din <code>app_theme_css.php</code> (Google Fonts cu subset latin-ext). Variabila: <code>var(--font)</code>.</p>
                </div>
                <span class="sg-badge bl">Inter 400 / 500 / 600 / 700</span>
            </div>
            <div class="sg-card-body">
                <div class="sg-grid-2">
                    <div>
                        <div class="sg-section-label">Scala observată în platformă</div>
                        <div style="display:grid;gap:10px">
                            <div><span class="mu" style="font-size:11px">22–24px · 700</span><div style="font-size:22px;font-weight:700;color:var(--pz-title)">H1 — Titlu pagină</div></div>
                            <div><span class="mu" style="font-size:11px">16–18px · 700</span><div style="font-size:16px;font-weight:700;color:var(--pz-title)">H2 — Titlu secțiune / card</div></div>
                            <div><span class="mu" style="font-size:11px">13px · 600</span><div style="font-size:13px;font-weight:600;color:var(--pz-title)">H3 — Sub-secțiune</div></div>
                            <div><span class="mu" style="font-size:11px">13px · 400</span><div style="font-size:13px;color:var(--pz-text)">Corp text — paragraf, descrieri</div></div>
                            <div><span class="mu" style="font-size:11px">12px · 400</span><div style="font-size:12px;color:var(--pz-text)">Tabel / dens — listări, conținut tabular</div></div>
                            <div><span class="mu" style="font-size:11px">11px · 500 · uppercase</span><div style="font-size:11px;font-weight:500;color:var(--pz-mu);text-transform:uppercase">LABEL · header tabel</div></div>
                        </div>
                    </div>
                    <div>
                        <div class="sg-section-label">Reguli tipografice</div>
                        <div class="sg-rules">
                            <div class="sg-rule">Nu se folosește font-weight 300 sau 800 — doar 400/500/600/700</div>
                            <div class="sg-rule">Titluri (h1, h2, h3): culoare <code>--pz-title</code></div>
                            <div class="sg-rule">Corp text: culoare <code>--pz-text</code></div>
                            <div class="sg-rule">Etichete (label, kicker, table header): uppercase 10.5–11px / weight 500–700 / culoare <code>--pz-mu</code></div>
                            <div class="sg-rule">Numere monetare și date: tabular-nums, font-feature-settings „tnum"</div>
                            <div class="sg-rule">Niciun text decorativ sub 10px sau cu <code>letter-spacing</code> agresiv</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             03 SIDEBAR & TOPBAR
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="chrome">
            <span class="sg-chapter-num">03</span>
            <span class="sg-chapter-title">Sidebar &amp; Topbar — chrome-ul global</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head">
                <div>
                    <h2>Sidebar navy</h2>
                    <p>Fix pe stânga, navy <code>#12345A</code>. Randat prin <code>render_sidebar('cheie_pagină', $isAdmin)</code>.</p>
                </div>
            </div>
            <div class="sg-card-body">
                <div class="sg-rules col-2">
                    <div class="sg-rule">Fundal: <code>#12345A</code> (Brand Navy)</div>
                    <div class="sg-rule">Lățime: ~240px fixă</div>
                    <div class="sg-rule">Border-right: <code>1px #0E2A49</code></div>
                    <div class="sg-rule">Fără box-shadow</div>
                    <div class="sg-rule">Text item: <code>rgba(255,255,255,.78)</code></div>
                    <div class="sg-rule">Hover: bg <code>rgba(255,255,255,.08)</code></div>
                    <div class="sg-rule">Activ: bg <code>rgba(255,255,255,.13)</code></div>
                    <div class="sg-rule">Border activ: 2px albastru <code>#60A5FA</code> la stânga</div>
                    <div class="sg-rule">Submeniu: indent 40px, font-size 12.5px</div>
                    <div class="sg-rule">Iconuri: stroke-width 1.75, culoare moștenită</div>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head">
                <div>
                    <h2>Topbar</h2>
                    <p>Alb, sub sidebar. Conține titlu/breadcrumb, căutare globală (Cmd+K), shortcut-uri (Calendar, Sarcini), notificări, „Adaugă nou".</p>
                </div>
            </div>
            <div class="sg-card-body">
                <div class="sg-rules col-2">
                    <div class="sg-rule">Fundal: alb <code>#FFFFFF</code></div>
                    <div class="sg-rule">Border-bottom: <code>1px var(--pz-line)</code></div>
                    <div class="sg-rule">Fără box-shadow</div>
                    <div class="sg-rule">Titlu: <code>$pz_page_title</code> (PHP)</div>
                    <div class="sg-rule">Breadcrumbs: <code>$pz_page_breadcrumbs = ['Setări','Servicii']</code></div>
                    <div class="sg-rule">Placeholder căutare: <code>$pz_topbar_opts['placeholder']</code></div>
                    <div class="sg-rule">Acțiune primară opțională: <code>$pz_topbar_opts['primary_label']</code> + <code>primary_href</code></div>
                    <div class="sg-rule">Titlul nu se duplică în topbar și în <code>&lt;h1&gt;</code> al paginii</div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             04 HEADER DE PAGINĂ
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="headere">
            <span class="sg-chapter-num">04</span>
            <span class="sg-chapter-title">Header-uri de pagină — 4 pattern-uri reale</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head">
                <div>
                    <h2>A · Listă (titlu + count + acțiune primară)</h2>
                    <p>Folosit pe pagini cu listare: Clienți, Facturi, Sarcini.</p>
                </div>
            </div>
            <div class="sg-card-body">
                <div style="border:1px dashed var(--pz-line);border-radius:6px;padding:14px;background:var(--pz-soft)">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                        <div style="display:flex;align-items:baseline;gap:10px">
                            <h1 style="font-size:22px">Clienți</h1>
                            <span class="sg-chip">685</span>
                        </div>
                        <div style="display:flex;gap:6px">
                            <button class="sg-btn secondary sm">Filtre</button>
                            <button class="sg-btn primary sm">+ Client nou</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head">
                <div>
                    <h2>B · Dosar entitate (entity header)</h2>
                    <p>Fișa de client/tehnician/lucrare. Card alb cu nume mare, status chip, sub-titlu cu contact, butoane de acțiune contextuale.</p>
                </div>
            </div>
            <div class="sg-card-body">
                <div style="background:var(--pz-surf);border:1px solid var(--pz-line);border-radius:var(--pz-r);padding:18px">
                    <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap">
                        <div>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
                                <h1 style="font-size:24px">SKY CERT GLOBAL S.R.L.</h1>
                                <span class="sg-chip">Activ</span>
                            </div>
                            <div class="mu" style="font-size:12px">Bazaracai Endiz · +40762720954 · contact@example.ro</div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button class="sg-btn secondary sm">Listă contacte</button>
                            <button class="sg-btn secondary sm">Editează</button>
                            <button class="sg-btn primary sm">+ Contract</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head">
                <div>
                    <h2>C · Setări secundare (titlu + descriere)</h2>
                    <p>Pagini de configurare adâncă: integrare ANAF, șabloane, etc.</p>
                </div>
            </div>
            <div class="sg-card-body">
                <div style="border:1px dashed var(--pz-line);border-radius:6px;padding:14px;background:var(--pz-soft)">
                    <div class="mu" style="font-size:11.5px;margin-bottom:4px">Setări &nbsp;·&nbsp; Integrare</div>
                    <h1 style="font-size:22px">SmartBill</h1>
                    <p class="mu" style="margin-top:4px;font-size:12.5px">Conectează contul SmartBill pentru facturare automată și sincronizare.</p>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head">
                <div>
                    <h2>D · Dashboard (greeting)</h2>
                    <p>Doar pe dashboard. Salut + dată în loc de titlu pagină.</p>
                </div>
            </div>
            <div class="sg-card-body">
                <div style="border:1px dashed var(--pz-line);border-radius:6px;padding:14px;background:var(--pz-soft)">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:14px;flex-wrap:wrap">
                        <div>
                            <h1 style="font-size:22px">Bună dimineața, Bentu</h1>
                            <p class="mu" style="margin-top:4px;font-size:12.5px">Ai 4 sarcini scadente azi și 2 PV-uri de emis.</p>
                        </div>
                        <div class="mu" style="font-size:12px">Joi, 21 mai 2026</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             05 STATUS SEMANTIC
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="status">
            <span class="sg-chapter-num">05</span>
            <span class="sg-chapter-title">Status semantic — ce culoare înseamnă ce</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head">
                <div>
                    <h2>Cinci tonuri, fiecare cu o intenție clară</h2>
                    <p>Nu se folosesc culori în afara acestor 5 familii (plus navy pentru brand).</p>
                </div>
            </div>
            <div class="sg-card-body">
                <div class="sg-grid-2">
                    <div class="sg-status">
                        <div class="sg-status-head"><span class="sg-status-dot" style="background:#EF4444"></span>Roșu · Pericol / Întârziat</div>
                        <div class="sg-status-body">
                            <div class="sg-status-when">Sarcini întârziate · Facturi restante · Stoc critic · Erori sistem · PV-uri expirate</div>
                            <div class="sg-status-examples"><span class="sg-badge re">2 întârziate</span> <span class="sg-badge re">11.953 lei</span></div>
                        </div>
                    </div>
                    <div class="sg-status">
                        <div class="sg-status-head"><span class="sg-status-dot" style="background:#F97316"></span>Portocaliu · Atenție / Acțiune</div>
                        <div class="sg-status-body">
                            <div class="sg-status-when">De facturat · De programat · Aproape de termen · Stoc sub minim · Reminders</div>
                            <div class="sg-status-examples"><span class="sg-badge or">De facturat</span> <span class="sg-badge or">3 zile rămase</span></div>
                        </div>
                    </div>
                    <div class="sg-status">
                        <div class="sg-status-head"><span class="sg-status-dot" style="background:#22C55E"></span>Verde · Finalizat / Plătit</div>
                        <div class="sg-status-body">
                            <div class="sg-status-when">Lucrări finalizate · Facturi încasate · PV-uri emise · Confirmări succes</div>
                            <div class="sg-status-examples"><span class="sg-badge gr">Plătit</span> <span class="sg-badge gr">Finalizată</span></div>
                        </div>
                    </div>
                    <div class="sg-status">
                        <div class="sg-status-head"><span class="sg-status-dot" style="background:#2563EB"></span>Albastru · Info / Acțiune primară</div>
                        <div class="sg-status-body">
                            <div class="sg-status-when">Butoane primare · Link-uri · Tab activ · Notificări informative · Conturi noi</div>
                            <div class="sg-status-examples"><span class="sg-badge bl">Nou</span> <span class="sg-badge bl">În progres</span></div>
                        </div>
                    </div>
                    <div class="sg-status">
                        <div class="sg-status-head"><span class="sg-status-dot" style="background:#94A3B8"></span>Neutru · Identitate / Default</div>
                        <div class="sg-status-body">
                            <div class="sg-status-when">Chip-uri de identitate (Activ, Tip persoană) · Count-uri · Etichete neutre</div>
                            <div class="sg-status-examples"><span class="sg-chip">Activ</span> <span class="sg-badge nu">PJ</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             06 COMPONENTE
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="componente">
            <span class="sg-chapter-num">06</span>
            <span class="sg-chapter-title">Componente vizuale</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Card / Panel</h2><p>Recipient standard pentru orice conținut grupat.</p></div></div>
            <div class="sg-card-body">
                <div class="sg-rules">
                    <div class="sg-rule">Fundal <code>--pz-surf</code> (alb), border <code>1px --pz-line</code>, radius <code>--pz-r</code> (8px)</div>
                    <div class="sg-rule">Padding intern: 16–18px</div>
                    <div class="sg-rule">Header card: <code>&lt;h2&gt;</code> + paragraf scurt opțional + acțiune dreapta (badge/buton)</div>
                    <div class="sg-rule">Separator între head și body: <code>border-bottom 1px --pz-lines</code></div>
                    <div class="sg-rule">Fără box-shadow. Fără gradient.</div>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Butoane</h2><p>Trei intenții: primar (acțiunea principală), secundar (acțiuni alternative), pericol (ștergere / anulare ireversibilă).</p></div></div>
            <div class="sg-card-body">
                <div style="display:flex;gap:8px;flex-wrap:wrap;padding:8px 0">
                    <button class="sg-btn primary">+ Client nou</button>
                    <button class="sg-btn secondary">Anulează</button>
                    <button class="sg-btn danger">Șterge</button>
                    <button class="sg-btn primary sm">+ Adaugă</button>
                    <button class="sg-btn secondary sm">Filtre</button>
                </div>
                <div class="sg-rules">
                    <div class="sg-rule">Primar: <code>bg --pz-bl</code>, text alb, padding <code>7px 11–12px</code>, radius <code>--pz-rs</code> (4px), font 12.5px / 500</div>
                    <div class="sg-rule">Secundar: bg surface, border 1px <code>--pz-line</code>, text <code>--pz-title</code></div>
                    <div class="sg-rule">Pericol: bg alb, text <code>--pz-re</code>, border <code>--pz-reb</code></div>
                    <div class="sg-rule">Mic (<code>.sm</code>): padding <code>4–5px 9–10px</code>, font 12px</div>
                    <div class="sg-rule">O singură acțiune primară pe pagină. Restul sunt secundare.</div>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Badge, Pill &amp; Chip</h2><p>Pentru status, count-uri sau identitate.</p></div></div>
            <div class="sg-card-body">
                <div class="sg-section-label">Badges semantice (status)</div>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <span class="sg-badge bl">Info</span>
                    <span class="sg-badge gr">Plătit</span>
                    <span class="sg-badge or">De facturat</span>
                    <span class="sg-badge re">Întârziat</span>
                </div>

                <div class="sg-section-label">Chip neutru (identitate, count)</div>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <span class="sg-chip">Activ</span>
                    <span class="sg-chip">685</span>
                    <span class="sg-chip">PJ</span>
                </div>

                <div class="sg-rules">
                    <div class="sg-rule">Badge: radius 4px, padding 2px 8px, font 11px / 600, border 1px</div>
                    <div class="sg-rule">Chip: radius 6px, padding 2px 10px, font 12px, fundal soft</div>
                    <div class="sg-rule">Nu se mixează badge-ul colorat (status) cu chip-ul neutru (identitate) într-un context</div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             07 TABELE
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="tabele">
            <span class="sg-chapter-num">07</span>
            <span class="sg-chapter-title">Tabele</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Anatomie</h2><p>Pattern-ul real folosit pe pagina <code>clients.php</code> și similare.</p></div></div>
            <div class="sg-card-body">
                <table class="sg-table">
                    <thead>
                        <tr><th>ID</th><th>Denumire</th><th>Tip</th><th>Reprezentant</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>8</td><td>A&amp;E KADER S.R.L.</td><td><span class="sg-badge nu">PJ</span></td><td>Bazaracai Endiz</td><td><span class="sg-chip">Activ</span></td></tr>
                        <tr><td>9</td><td>SKY CERT GLOBAL</td><td><span class="sg-badge nu">PJ</span></td><td>—</td><td><span class="sg-chip">Activ</span></td></tr>
                        <tr><td>10</td><td>Popescu Ion</td><td><span class="sg-badge nu">PF</span></td><td>—</td><td><span class="sg-chip">Activ</span></td></tr>
                    </tbody>
                </table>
                <div class="sg-section-label" style="margin-top:14px">Reguli măsurate în platformă</div>
                <div class="sg-rules col-2">
                    <div class="sg-rule">Header <code>th</code>: bg <code>--pz-soft</code>, text uppercase 10.5px / 700, culoare <code>--pz-mu</code></div>
                    <div class="sg-rule">Rânduri <code>td</code>: padding <code>11px 12px</code>, înălțime efectivă 38–51px (în funcție de conținut)</div>
                    <div class="sg-rule">Border-bottom între rânduri: <code>1px --pz-lines</code></div>
                    <div class="sg-rule">Hover rând: bg <code>--pz-soft</code></div>
                    <div class="sg-rule">Fără borduri verticale, fără zebra stripes</div>
                    <div class="sg-rule">Ultimul rând fără border-bottom</div>
                    <div class="sg-rule">Tabel wrap-uit în card (border + radius 8px)</div>
                    <div class="sg-rule">Coloana ID: tabular-nums, aliniat stânga</div>
                    <div class="sg-rule">Sumele monetare: aliniate dreapta, tabular-nums, sufix „lei"</div>
                    <div class="sg-rule">Coloana Acțiuni: ultima, aliniată dreapta, doar icon-buttons</div>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Bara de filtre &amp; paginare</h2><p>Pattern uniform deasupra/sub tabel.</p></div></div>
            <div class="sg-card-body">
                <div class="sg-rules col-2">
                    <div class="sg-rule">Deasupra tabel: select „rânduri/pagină" + căutare + filtre + count rezultate</div>
                    <div class="sg-rule">Sub tabel: „Afișate X–Y din Z" + butoane paginare</div>
                    <div class="sg-rule">Selectul de paginație: 20 / 50 / 100 (default 20)</div>
                    <div class="sg-rule">Resetare filtre: link „Resetare filtre" în dreapta sau sub bara de filtre</div>
                    <div class="sg-rule">URL conține parametrii filtrului (pentru bookmark și partajare)</div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             08 FORMULARE & MODALE
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="formulare">
            <span class="sg-chapter-num">08</span>
            <span class="sg-chapter-title">Formulare &amp; modale</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Câmpuri</h2><p>Măsuri exacte observate pe formularul „Editează client".</p></div></div>
            <div class="sg-card-body">
                <div class="sg-grid-2">
                    <div>
                        <label class="sg-label">CUI firmă</label>
                        <input class="sg-input" type="text" placeholder="Ex: 14837428">
                    </div>
                    <div>
                        <label class="sg-label">Denumire</label>
                        <input class="sg-input" type="text" value="A&amp;E KADER S.R.L.">
                    </div>
                </div>
                <div class="sg-rules col-2" style="margin-top:14px">
                    <div class="sg-rule">Label: 11px / 500 / uppercase / culoare <code>#6B7280</code> / margin-bottom 6px</div>
                    <div class="sg-rule">Input: înălțime 34px, padding <code>0 9px</code>, font 13px, radius <code>--pz-rs</code></div>
                    <div class="sg-rule">Border default: 1px <code>--pz-line</code>; focus: <code>--pz-bl</code> + glow albastru soft</div>
                    <div class="sg-rule">Eroare: border <code>--pz-re-acc / --pz-reb</code> + mesaj 11px sub câmp</div>
                    <div class="sg-rule">Formularul se grupează în secțiuni de 1, 2 sau 3 coloane</div>
                    <div class="sg-rule">Câmp obligatoriu: asterisc <code>*</code> roșu după label</div>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Modal &amp; Drawer</h2><p>Overlay închis cu × în dreapta. Body scrollabil. Footer fix cu acțiuni.</p></div></div>
            <div class="sg-card-body">
                <div class="sg-rules col-2">
                    <div class="sg-rule">Overlay: bg <code>rgba(16,36,62,.46)</code> (navy soft) — apare peste tot conținutul</div>
                    <div class="sg-rule">Container: bg alb, radius <code>--pz-r</code>, lățime max ~640px (formular standard)</div>
                    <div class="sg-rule">Header: titlu 18px / 700 + buton închidere ×</div>
                    <div class="sg-rule">Footer: butoane aliniate dreapta, primarul ultimul</div>
                    <div class="sg-rule">Drawer (variant lateral): slide din dreapta, lățime fixă, full-height</div>
                    <div class="sg-rule">Închidere: click overlay, ESC, sau ×. Nu se închide la click în interior.</div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             09 STĂRI
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="stari">
            <span class="sg-chapter-num">09</span>
            <span class="sg-chapter-title">Stări — empty, loading, disabled</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Stare goală (empty state)</h2><p>Apare în orice secțiune care nu are date. Important: mesaj scurt + (opțional) acțiune.</p></div></div>
            <div class="sg-card-body">
                <div style="padding:24px;text-align:center;border:1px dashed var(--pz-line);border-radius:6px;background:var(--pz-soft);color:var(--pz-mu)">
                    Nu există sarcini pentru acest client. <a href="#" style="color:var(--pz-bl);text-decoration:none">+ Adaugă sarcină</a>
                </div>
                <div class="sg-rules" style="margin-top:12px">
                    <div class="sg-rule">Mesaj la o singură linie când e posibil</div>
                    <div class="sg-rule">Culoare text: <code>--pz-mu</code></div>
                    <div class="sg-rule">Acțiunea principală e inline ca link albastru (sau buton mic dacă lipsa de date e blocantă)</div>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Loading &amp; disabled</h2><p>Două principii: feedback vizual rapid + nu blochezi utilizatorul fără semnal.</p></div></div>
            <div class="sg-card-body">
                <div class="sg-rules col-2">
                    <div class="sg-rule">Skeleton: bloc gri animat (puls 1.5s, opacity 1 → .4 → 1) când știm structura dar nu și datele</div>
                    <div class="sg-rule">Spinner: cerc albastru rotind, doar pentru așteptări sub 3s</div>
                    <div class="sg-rule">Disabled: opacity .45, <code>pointer-events:none</code></div>
                    <div class="sg-rule">Buton în loading: text înlocuit cu spinner, păstrează lățimea</div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             10 REGULI
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="reguli">
            <span class="sg-chapter-num">10</span>
            <span class="sg-chapter-title">Reguli globale &amp; checklist pagină nouă</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Principii vizuale</h2><p>Reguli scurte. Fiecare pagină nouă le respectă.</p></div></div>
            <div class="sg-card-body">
                <div class="sg-rules col-2">
                    <div class="sg-rule">Fără box-shadow pe carduri, pe sidebar sau pe butoane</div>
                    <div class="sg-rule">Fără gradient — fundaluri solide</div>
                    <div class="sg-rule">Densitate medie: padding 16–18px pe card, gap 12–16px între carduri</div>
                    <div class="sg-rule">Culorile vin doar din tokens <code>--pz-*</code> — fără valori hardcodate</div>
                    <div class="sg-rule">Iconuri din <code>app_icon_svg('name')</code> — fără SVG-uri inline noi</div>
                    <div class="sg-rule">Diacritice corecte (ș ț ă â î) — UTF-8</div>
                    <div class="sg-rule">Plurale folosite consistent (Clienți, Facturi, Sarcini)</div>
                    <div class="sg-rule">Toate sumele afișate cu sufix „lei" și fără zecimale</div>
                </div>
            </div>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Checklist — pagină PHP nouă</h2><p>Înainte de a o livra, bifează:</p></div></div>
            <div class="sg-card-body">
                <div class="sg-check">
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>require_login()</strong> apelat în primele linii</div></div>
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>app_theme_css()</strong> inclus în <code>&lt;head&gt;</code> — moștenește font, tokens, reset</div></div>
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>render_sidebar('cheie_pagină', $isAdmin)</strong> — cu cheia adăugată în <code>app_sidebar.php</code></div></div>
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>$pz_page_title</strong> + <strong>$pz_page_breadcrumbs</strong> setate înainte de includere</div></div>
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>Iconuri</strong> doar prin <code>app_icon_svg()</code></div></div>
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>Tokens</strong> — orice culoare folosește <code>var(--pz-*)</code>, nu hex</div></div>
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>Status semantic</strong> — roșu/portocaliu/verde/albastru/neutru folosite cu intenția corectă</div></div>
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>Stări goale</strong> — fiecare secțiune are mesaj când nu există date</div></div>
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>htmlspecialchars()</strong> — orice valoare din DB e escapată la output</div></div>
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>Responsive</strong> — testată la 1100px și 680px (nu se rupe)</div></div>
                    <div class="sg-check-item"><span class="sg-check-box"></span><div><strong>Fără shadow / fără gradient</strong></div></div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             11 ICONURI
        ══════════════════════════════════════════════════════ -->
        <div class="sg-chapter" id="iconuri">
            <span class="sg-chapter-num">11</span>
            <span class="sg-chapter-title">Iconuri</span>
        </div>

        <div class="sg-card">
            <div class="sg-card-head"><div><h2>Bibliotecă — apel <code>app_icon_svg('nume')</code></h2><p>SVG monocrom, stroke <code>currentColor</code>. Toate iconurile sunt definite în <code>app_icons.php</code> — adaugă acolo înainte de a folosi un nume nou.</p></div></div>
            <div class="sg-card-body">
                <?php
                /*
                 * Lista de mai jos e curată — include majoritatea iconurilor folosite în platformă.
                 * Dacă adaugi unul nou în app_icons.php, adaugă numele aici pentru documentare.
                 */
                $iconList = [
                    'dashboard', 'calendar', 'tasks', 'clients', 'team', 'reports',
                    'documents', 'contracts', 'processes', 'offers', 'series', 'invoice',
                    'stock', 'services', 'settings', 'users', 'company', 'design',
                    'plus', 'edit', 'eye', 'mail', 'phone', 'search', 'more',
                ];
                ?>
                <div class="sg-icon-grid">
                    <?php foreach ($iconList as $name): ?>
                        <div class="sg-icon-cell">
                            <?= app_icon_svg($name) ?>
                            <span><?= htmlspecialchars($name) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="sg-section-label" style="margin-top:16px">Reguli</div>
                <div class="sg-rules col-2">
                    <div class="sg-rule">viewBox: <code>0 0 24 24</code></div>
                    <div class="sg-rule">stroke-width: <strong>1.75</strong> (consistent peste tot)</div>
                    <div class="sg-rule">stroke-linecap / stroke-linejoin: <strong>round</strong></div>
                    <div class="sg-rule">fill: <code>none</code> — iconuri liniare</div>
                    <div class="sg-rule">Culoarea moștenită prin <code>stroke: currentColor</code></div>
                    <div class="sg-rule">Default: <code>--pz-mu</code> · Hover: <code>--pz-bl</code> · Pe sidebar: alb variabil</div>
                    <div class="sg-rule">Dimensiuni: 14px (inline), 16px (button), 20–22px (nav), 24px (display)</div>
                    <div class="sg-rule">Nu se setează culoarea direct pe SVG — întotdeauna via parent</div>
                </div>
            </div>
        </div>

        </div><!-- .sg -->
        </div>
    </main>
</div>
</body>
</html>
