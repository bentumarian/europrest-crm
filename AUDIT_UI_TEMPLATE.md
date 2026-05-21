# Audit & plan de refactor — `ui_template.php`

**Data auditului:** 21 mai 2026
**Fișier analizat:** `ui_template.php` (3294 linii, ~206 KB)
**Rol în aplicație:** pagina internă „Design System" (admin-only) — ghid vizual al platformei PestZone CRM.

---

## 1. Ce este de fapt fișierul

În ciuda numelui, `ui_template.php` **nu este un template engine** și **nu este inclus** ca librărie de niciun alt fișier. Este o pagină standalone, accesibilă doar adminilor (`is_admin()` la linia 6), care documentează vizual sistemul de design: culori, tipografie, componente, tabele, modale, formulare, iconuri etc.

Referințe externe:
- `app_sidebar.php` (linia 40, 634) — înregistrare în meniu
- `settings.php` (linia 93) — link în setări
- `dashboard.php` (linia 359) — doar un comentariu
- `ui_template.php` (linia 961) — apelează `render_sidebar('ui_template', true)`

**Concluzie:** fișierul nu exportă nimic (zero funcții PHP definite). Refactorul lui nu poate sparge alte pagini.

## 2. Structura actuală

Trei zone amestecate într-un singur fișier:

| Zonă | Linii | Lățime | Conținut |
|------|------:|------:|---------|
| Bootstrap PHP | 1–14 | 14 | guard `is_admin`, setări topbar |
| `<head>` + `<style>` CSS inline | 15–957 | **933** | tokens, override-uri sidebar/topbar, ~332 selectoare `.ds-*` |
| `<body>` cu cele 12 capitole | 958–3294 | **2335** | markup pur prezentațional |

Cele 12 capitole, ordonate după dimensiune:

```
tabele       424 linii   (cel mai mare, ~18% din body)
componente   315
fundatie     287
headere      269
iconuri      266
status       164
reguli       122
layout       106
loading       97
formulare     94
modale        87
notificari    51
```

Niciun bloc `<script>` în fișier — refactorul nu trebuie să separe JS.

## 3. Probleme identificate

### 3.1 Duplicarea design tokens-urilor (risc de drift)
`ui_template.php` redefinește variabilele `--pz-*` care există deja în `app_theme_css.php`:

- `ui_template.php` definește 29 de variabile `--pz-*`
- `app_theme_css.php` definește 24

Cele suplimentare în pagina DS: `--pz-soft`, `--pz-mu`, `--pz-fa`, `--pz-brand`, `--pz-gap`, `--pz-gr-acc`, `--pz-or-acc`, `--pz-re-acc`. Astăzi valorile coincid, dar nimic nu garantează că rămân sincronizate. Dacă cineva schimbă o nuanță în `app_theme_css.php`, ghidul de design va minți.

### 3.2 Suprascrierea agresivă a sidebar-ului și topbar-ului
Liniile 85–139 conțin un bloc întreg de override-uri pentru `.sidebar`, `.nav-item`, `.app-topbar` etc., cu **37 de `!important`**. Pagina DS reconstruiește sidebar-ul navy peste tema globală — semn că tema globală nu o redă uniform pe această pagină, sau că autorul a vrut un look diferit fix aici. În orice caz: dacă tema globală se schimbă, această pagină nu reflectă realitatea.

### 3.3 CSS și HTML împletite, fără cache-abilitate
Cele 933 de linii de CSS sunt inline într-un `<style>` în PHP. Înseamnă că:
- nu sunt cache-uite de browser între request-uri,
- nu sunt reutilizabile (nici nu trebuie să fie — sunt clase `.ds-*` doar pentru pagina aceasta, dar tot ar fi mai curat ca fișier separat),
- editarea CSS-ului necesită scroll prin 900+ linii pentru a ajunge la HTML.

### 3.4 Capitole foarte inegale ca dimensiune
Capitolul „tabele" (424 linii) e de 8× mai mare ca „notificări" (51 linii). Capitolele lungi (`tabele`, `componente`, `fundatie`, `headere`, `iconuri`) ar putea fi sparte logic.

### 3.5 PHP încorporat în HTML pentru randare dinamică
49 de blocuri `<?php ... ?>` în corpul paginii, 29 de `<?= ... ?>`. Cele mai multe sunt pentru iconuri (`app_icon_svg()`) și loop-uri demo (echipă, breakpoint-uri, state-uri). Trebuie păstrate în PHP, nu pot deveni HTML pur.

### 3.6 Linkurile către resurse externe sunt în pagină
`<link href="https://fonts.googleapis.com/css2?family=Inter...">` (linia 22) — Inter este probabil deja încărcat de tema globală prin `app_theme_css()`. Verificare necesară.

## 4. Plan de refactor pe etape

Obiectiv: să spargem fișierul în piese mai mici, ușor de întreținut, fără să schimbăm comportamentul. La final, `ui_template.php` ar trebui să fie sub 200 de linii și să facă doar orchestrare.

Tot procesul presupune că ai git curat înainte și că rulezi pagina după fiecare etapă pentru a verifica că arată identic.

### Etapa 1 — Extragem CSS-ul într-un fișier separat (cel mai mare câștig, cel mai mic risc)

Crează `assets/ui_template.css` cu liniile 25–957 (fără tag-urile `<style>`). În `ui_template.php` înlocuiește blocul `<style>...</style>` cu:

```html
<link rel="stylesheet" href="assets/ui_template.css?v=<?= filemtime(__DIR__.'/assets/ui_template.css') ?>">
```

Rezultat: `ui_template.php` scade de la 3294 la ~2360 linii, CSS-ul devine cache-uibil.

### Etapa 2 — Eliminăm redefinirea tokens-urilor

În `assets/ui_template.css`, ștergem definițiile `--pz-*` care există deja în `app_theme_css.php`. Păstrăm doar cele specifice paginii DS (`--pz-soft`, `--pz-mu`, `--pz-fa`, `--pz-gap`, `*-acc`). Le mutăm pe acestea în `app_theme_css.php` (la nivel global) **sau** le prefixăm cu `--ds-*` ca să se vadă că aparțin paginii DS.

Rezultat: o singură sursă de adevăr pentru paleta de culori. Riscul de drift dispare.

### Etapa 3 — Decidem soarta override-urilor de sidebar/topbar

Două opțiuni:

- **A.** Dacă vrem ca sidebar-ul navy să fie *standardul global*, mutăm regulile (fără `!important`) în `app_theme_css.php` și ștergem blocul din DS.
- **B.** Dacă navy este *doar pentru pagina DS*, păstrăm overrides-urile dar le punem într-un `assets/ui_template-sidebar.css` separat, ca să nu poluăm CSS-ul paginii DS în sine.

Recomandare: **opțiunea A** — pare să fie aspectul intenționat al întregii platforme (judecând după restul UI-ului din proiect), iar paginile celelalte deja par să-l aibă. De verificat pe staging.

### Etapa 4 — Spargem cele 12 capitole în partial-uri PHP

Crează un folder `views/design_system/` cu un fișier per capitol:

```
views/design_system/
  _toc.php
  01-fundatie.php
  02-status.php
  03-componente.php
  04-layout.php
  05-reguli.php
  06-tabele.php
  07-headere.php
  08-modale.php
  09-formulare.php
  10-notificari.php
  11-loading.php
  12-iconuri.php
```

`ui_template.php` devine doar shell-ul paginii:

```php
<?php require_once 'config.php'; require_login();
require_once 'app_ui.php';
if (!is_admin()) { header('Location: calendar.php'); exit; }
// ... vars topbar ... ?>
<!DOCTYPE html>
<html lang="ro">
<head>...</head>
<body>
<div class="layout">
    <?php render_sidebar('ui_template', true); ?>
    <main class="main"><div class="content"><div class="ds">
        <?php include 'views/design_system/_header.php'; ?>
        <?php include 'views/design_system/_toc.php'; ?>
        <?php
          foreach ([
            '01-fundatie','02-status','03-componente','04-layout',
            '05-reguli','06-tabele','07-headere','08-modale',
            '09-formulare','10-notificari','11-loading','12-iconuri',
          ] as $chapter) {
              include "views/design_system/{$chapter}.php";
          }
        ?>
    </div></div></main>
</div>
</body>
</html>
```

Rezultat: `ui_template.php` sub 50 de linii, fiecare capitol între 50 și 425 de linii — mult mai ușor de navigat.

### Etapa 5 (opțional) — Spargem capitolele uriașe

Pentru `06-tabele.php` (424 linii) și `03-componente.php` (315 linii), dacă tot vom interveni des, le mai putem sparge într-un sub-folder:

```
views/design_system/03-componente/
  buttons.php
  inputs.php
  kpi.php
  panel.php
  table.php
  tabs.php
  ...
```

Doar dacă editezi des aceste secțiuni. Altfel, e overkill.

### Etapa 6 — Verificarea finală

După fiecare etapă: deschide `ui_template.php` în browser cu un cont admin și compară cu un screenshot pre-refactor. Diferențe vizuale = bug introdus.

## 5. Estimare efort

| Etapă | Efort | Risc | Câștig |
|------|------|------|--------|
| 1. Extracție CSS | 15 min | scăzut | mare (cache + lizibilitate) |
| 2. Deduplicare tokens | 20 min | scăzut | mediu (single source of truth) |
| 3. Sidebar overrides | 30–60 min | mediu (poate afecta alte pagini dacă alegi A) | mare (corectitudine) |
| 4. Spargere în partial-uri | 45 min | scăzut | foarte mare (navigare, editare paralelă) |
| 5. Sub-spargere capitole mari | 30 min | scăzut | mic (doar dacă editezi des) |
| 6. Verificare vizuală | 15 min | — | — |

**Total estimat:** 2–3 ore pentru etapele 1, 2, 4 și 6. Etapele 3 și 5 — opționale, în funcție de scop.

## 6. Recomandare

Începe cu etapele **1 → 4 → 2 → 6** (în ordinea asta — extracția CSS-ului întâi, partial-urile imediat după, apoi curățarea tokens-urilor pe CSS-ul deja izolat). Sări etapa 3 până când e clar dacă sidebar-ul navy ar trebui să fie global sau e o ciudățenie a paginii DS — verifică pe staging cum arată sidebar-ul în restul aplicației.

După etapele 1–4 ai un fișier `ui_template.php` curat, ușor de citit, fără să fi schimbat un pixel din ce vede utilizatorul.
