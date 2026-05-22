# Linia vizuală PestZone CRM — versiune finală

**Data:** 22 mai 2026
**Status:** decisă cu Bentu, sursa de adevăr pentru toate paginile.
**Referință cod:** `app_theme_css.php`, `style_guide.php`.

## Direcția generală

**Inspirație:** Stripe Dashboard — profesional, financiar, dens-dar-aerisit, fără țipăt vizual.

**Trei principii:**

1. **Consistență înainte de toate** — același element (card, buton, header) arată identic pe orice pagină.
2. **Contrast subtil dar prezent** — borduri vizibile, shadow-uri foarte fine, accent-uri colorate doar unde transmite informație.
3. **Densitate cu spațiu** — informația e prezentată dens (tabele compacte, KPI cu trend), dar cu pauze vizuale între secțiuni.

## 1. Paleta și tokens

Păstrăm paleta din `app_theme_css.php` (29 tokens `--pz-*`). Adăugări minime:

```css
--pz-bl-hov: #1D4ED8;       /* albastru hover */
--pz-bl-act: #1E40AF;       /* albastru pressed */
--pz-shadow-card: 0 1px 2px rgba(15, 23, 42, .04);
```

Notă pe `--pz-mu` (#64748B): borderline WCAG AA pe text 12px. Pentru text mic (labels, meta), folosim `--pz-text` (#334155). `--pz-mu` doar pentru text 13px+.

## 2. Header pagină — UN singur stil

Card alb, border 1px `--pz-line`, radius 8px, padding 22×24:

- **Kicker** uppercase 11px / 600 / `--pz-mu` / letter-spacing 0.08em — context modul
- **H1** 22px / 700 / `--pz-title` — numele paginii sau entității
- **Descriere** 13px / 400 / `--pz-mu` — opțional, o frază
- **Acțiuni** dreapta sus, vertical-aligned cu titlul

**Kickere standardizate pe module:**

| Modul | Kicker |
|------|--------|
| Dashboard | `OPERAȚIONAL` |
| Clienți | `CLIENȚI` |
| Calendar | `OPERAȚIONAL` |
| Sarcini | `OPERAȚIONAL` |
| Documente (PV, Contracte, Oferte) | `DOCUMENTE` |
| Facturi / Încasări / Lista lucrări | `FINANCIAR` |
| Rapoarte | `RAPOARTE` |
| Reminders | `OPERAȚIONAL` |
| Setări | `ADMINISTRARE PLATFORMĂ` |
| Gestiune (stoc) | `GESTIUNE` |

## 3. KPI cards standardizate

Card alb, border 1px `--pz-line`, radius 8px, padding 16×18:

- **Accent-bar 3px solid stânga** în culoarea categoriei
- Kicker uppercase 11px / 600 / `--pz-mu`
- Valoare 26px / 700 / `--pz-title`, `font-feature-settings: "tnum"`
- Subtitlu/trend 12px / 400 / `--pz-text` (loc pentru „+12 față de luna trecută")

**Culori accent-bar:**

| Categorie | Culoare | Folosință |
|-----------|---------|-----------|
| Info | `#2563EB` | Lucrări azi, Facturat, Total poziții |
| Succes | `#16A34A` | Finalizate, Încasat, Facturate |
| Atenție | `#9A3412` | Sarcini deschise, De facturat, De verificat |
| Pericol | `#DC2626` | Termen depășit, Sold neachitat, Întârziate |
| Neutru | `#94A3B8` | Clienți/Tehnicieni, count-uri totale |

## 4. Carduri obișnuite

- Card alb, border 1px `--pz-line`, radius 8px
- **Card-head**: padding 14×18, border-bottom 1px `--pz-lines`, h2 + descriere opțional + acțiuni dreapta
- **Card-body**: padding 16×18
- Shadow subtil `--pz-shadow-card`
- Fără gradient, fără heavy shadow

## 5. Butoane în tabele/liste = iconuri pure 28×28

- 28×28px, radius 4px
- bg transparent, hover bg `--pz-soft`
- color `--pz-mu` → hover `--pz-title`
- Icon SVG 16px, centrat
- **Tooltip obligatoriu** pe hover (`title=` HTML sau micro-componentă)

**Setul standardizat:**

| Acțiune | Icon |
|---------|------|
| Vezi | `eye` |
| Editează | `pencil` |
| PDF / Document | `file-text` |
| Trimite email | `send` |
| Descarcă | `download` |
| Storno / Anulare | `rotate-ccw` |
| Șterge | `trash-2` (color `--pz-re` pe hover) |
| Duplică | `copy` |
| Mai multe | `more-vertical` |

## 6. Butoane normale (3 niveluri)

**Primar** — 1 pe pagină.

```css
bg: var(--pz-bl); color: white;
padding: 7px 12px; radius: 4px; font: 12.5px/500;
hover: bg var(--pz-bl-hov);
active: bg var(--pz-bl-act);
```

**Secundar** — restul.

```css
bg: white; border: 1px solid var(--pz-line); color: var(--pz-title);
hover: bg var(--pz-soft);
```

**Pericol** — doar ireversibil.

```css
bg: white; border: 1px solid var(--pz-reb); color: var(--pz-re);
hover: bg var(--pz-res);
```

**Ghost** — pentru „Resetează filtre", „Vezi tot".

```css
color: var(--pz-bl); fără border; hover: underline;
```

**Sm modifier**: padding 5×10, font 12px.

## 7. Acțiunea „+" standardizată

Buton primar 32×32 (sau 28 sm), doar icon `plus`, tooltip pe hover. Plasare: dreapta sus în header-card pagină.

**Tooltipuri standardizate:**

| Pagina | Tooltip |
|--------|---------|
| Clienți | „Adaugă client" |
| Contracte | „Contract nou" |
| Facturi | „Emite factură" |
| Calendar | „Programare nouă" |
| Sarcini | „Sarcină nouă" |
| Procese verbale | „PV nou" |
| Oferte | „Ofertă nouă" |
| Locații | „Adaugă locație" |

## 8. Sub-tabs (intra-pagină)

Pentru navigare între sub-secțiuni ale aceluiași modul (ex: Facturi / Încasări / Lista lucrări):

- Container border-bottom 1px `--pz-line`
- Item activ: color `--pz-bl`, border-bottom 2px `--pz-bl`, padding 10×14
- Item inactiv: color `--pz-text`, hover color `--pz-title`
- Font 13px / 500

**Nu mai folosim butoane primary pentru tabs.**

## 9. Filtre — un singur model

Toate filtrele pe un singur rând, în card propriu:

- Card cu padding 12×14, bg `--pz-surf`, border 1px `--pz-line`
- Inputs cu label uppercase deasupra (sau placeholder dacă spațiu limitat)
- Buton „**Aplică**" primary, „**Resetează**" ghost
- Pe mobil: drawer „Filtre"

**Standardizare label**: alege „Aplică" pe toate paginile (acum e mix „Filtrează" / „Aplică" / „Filtreaza").

## 10. Status badges cu dot

```css
bg: var(--pz-Xs);
border: 1px solid var(--pz-Xb);
color: var(--pz-Xd);
padding: 2px 8px; radius: 4px;
font: 11px/600;
```

**Dot 6px solid stânga** pentru status critic:

| Status | Dot color |
|--------|-----------|
| Activ / Plătit / Finalizat / Emis / Confirmată | `--pz-gr-acc` |
| Draft / De verificat / Pending / Netrimisă | `--pz-or-acc` |
| Întârziat / Restant / Anulat / Storno | `--pz-re-acc` |
| Inactiv / N/A | `--pz-mu` |

## 11. Tabele standardizate

- **Header `th`**: bg `--pz-soft`, uppercase 11px / 700, color `--pz-mu`, padding 10×12
- **Rândurile `td`**: padding 11×12, font 13px, border-bottom 1px `--pz-lines`
- **Hover row**: bg `--pz-soft`
- **Coloana Acțiuni**: ultima, dreapta, doar iconuri 28×28
- **Sume**: dreapta, tabular-nums, sufix „RON" sau „lei"
- Niciodată borduri verticale, niciodată zebra stripes
- Ultimul rând fără border-bottom

## 12. Format dată — UN singur format

Standard: `dd.mm.yyyy` (ex: `22.05.2026`).

**Helper PHP nou** în `app_helpers.php`:

```php
if (!function_exists('pz_date')) {
    function pz_date($ts): string {
        if (!$ts) return '—';
        $t = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
        return $t ? date('d.m.Y', $t) : '—';
    }
}
if (!function_exists('pz_datetime')) {
    function pz_datetime($ts): string {
        if (!$ts) return '—';
        $t = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
        return $t ? date('d.m.Y, H:i', $t) : '—';
    }
}
if (!function_exists('pz_date_long')) {
    function pz_date_long($ts): string {
        if (!$ts) return '—';
        $t = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
        if (!$t) return '—';
        $months = ['ianuarie','februarie','martie','aprilie','mai','iunie',
                   'iulie','august','septembrie','octombrie','noiembrie','decembrie'];
        return date('j', $t) . ' ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
    }
}
```

**Aplicare progresivă**: înlocuim manual `date(...)` și formatări inline cu `pz_date()` / `pz_datetime()`. Datepicker-urile HTML5 nu se schimbă acum (browser-ul decide după locale).

## 13. Spațiere

- Între secțiuni majore: 24px
- Între cards într-un grid: 16px
- Card padding intern: 16-18px
- Form gap între câmpuri: 14px
- Element gap intern: 8px

## 14. Tipografie

| Element | Size | Weight | Color | Notă |
|--------|-----:|------:|------|------|
| H1 pagină | 22px | 700 | `--pz-title` | 1 per pagină |
| H2 card | 16px | 600 | `--pz-title` | titlu de card |
| H3 sub-secțiune | 13px | 600 | `--pz-title` | rar |
| Kicker uppercase | 11px | 600 | `--pz-mu` | letter-spacing 0.08em |
| Label form | 11px | 500 | `--pz-text` | uppercase |
| Corp text | 13px | 400 | `--pz-text` | default |
| Meta tabel | 12px | 400 | `--pz-mu` | dată, CUI, telefon |
| Stat number | 26px | 700 | `--pz-title` | tabular-nums |

## 15. Iconuri — convenții

- Inline cu text (buton normal): 14-16px
- Icon button standalone: 16-18px într-un 28×28 container
- Tile / KPI icon: 20-24px
- Sidebar item: 18px
- Toate vin din `app_icons.php` (SVG inline)

**Iconuri lipsă de adăugat în `app_icons.php`** (dacă nu sunt deja):

- `plus`, `eye`, `pencil`, `file-text`, `send`, `download`, `rotate-ccw`, `trash-2`, `copy`, `more-vertical`

## 16. Mobile responsive

- Sidebar: drawer cu overlay (deja există)
- Header card: acțiunile se mută sub titlu (flex-wrap)
- Tabele: scroll orizontal, primul col `position: sticky`
- Filtre: drawer dedicat (buton „Filtre" în header)
- KPI grid: 1 sau 2 coloane (de la 4 desktop)

## 17. Probleme lingvistice de corectat global

Greșeli observate în aplicație, de corectat la prima sesiune:

- „Filtreaza" → „Filtrează" (mai multe locuri)
- „Intarziate" → „Întârziate"
- „RANDURI" → „RÂNDURI"
- „finalizata" / „confirmata" / „Încasată" → cu diacritice
- „Caută client" placeholder duplicat (topbar + filtru pe Clienți)
- „Contacte" în sidebar vs „Clienți" pe pagină → unificăm la „Clienți"
- „statusuri si actiuni" → cu diacritice

## 18. Plan de aplicare (6 sesiuni)

| Sesiune | Conținut | Risc | Vizibilitate |
|---------|---------|------|-------------|
| 1 | Quick wins lingvistice (diacritice, denumiri, format date helper) | scăzut | mare |
| 2 | Header pagină standardizat + KPI cards cu accent-bar | scăzut | foarte mare |
| 3 | Butoane tabel → iconuri pure peste tot | mediu (testare flow-uri) | foarte mare |
| 4 | Buton „+" simplu cu tooltip pe toate listele | scăzut | mediu |
| 5 | Status badges cu dot + sub-tabs cu underline | scăzut | mediu |
| 6 | Polish: hover states, focus states, micro-spațieri, transitions 150ms | scăzut | mic |

## 19. Reguli de aur

1. **Un singur primar pe pagină.** Dacă vezi 2 butoane albastre, unul e greșit.
2. **Un singur format de dată.** `pz_date()` sau `pz_datetime()`, nimic altceva.
3. **Un singur stil de header.** Card alb cu kicker, fără excepții.
4. **Un singur stil de KPI.** Border + accent-bar + kicker + valoare + trend.
5. **Tabel = iconuri.** Niciodată butoane text în coloana Acțiuni.
6. **Diacritice obligatorii.** Fără excepție.
7. **Numai 1 acțiune `+` per pagină listă, doar icon.**
8. **Sub-tabs cu underline, nu butoane primary.**
9. **Status critic = dot solid + bg soft + border + text dark.**
10. **Mobile-first review după fiecare modificare.**

---

**Notă pentru implementare:** linia asta înlocuiește orice ghid anterior. Atunci când lucrezi pe o pagină, consultă acest document + `style_guide.php` (care va fi actualizat să oglindească aceste reguli).
