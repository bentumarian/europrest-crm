# Audit design — pagini față de DESIGN_LINE.md

**Data:** 27 mai 2026
**Status:** read-only. Nicio modificare aplicată.
**Referințe:** `DESIGN_LINE.md`, `ui_template.php`, `app_theme_css.php`.

---

## 1. Sumar per pagină

| Pagina | Abateri concrete | Prioritate |
|---|---|---|
| `dashboard.php` | KPI cards folosesc `pz-kpi` custom fără accent-bar 3px stânga; `date('d.m.Y', strtotime(…))` direct în display (linia 319+); header nu e card-white + kicker standard. | Sus |
| `clients.php` | Header `clients-page-title` custom în loc de card + kicker; layout grid custom (`clients-layout`); butoane filtru fără label „Aplică" uniform. | Mediu |
| `calendar.php` | Fișier foarte mare, abaterile principale par în tabele/iconuri acțiuni — verificare ulterioară punctuală. | Mediu |
| `tasks.php` | Status badges cu clase custom (`tone-danger`, `tone-warning`, `tone-success`) fără dot 6px; `date()` inline în PHP; filtre cu UI neunificat. | Mediu |
| `invoices.php` | `date('d.m.Y', strtotime($dateFrom))` (linia 319); status badges `status-pill` custom (linia 246+); tabele conforme. | Mediu |
| `oferte.php` | Helper local `pz_offer_date_ro()` (linia 82) reimplementează formatarea în loc să folosească `pz_date()`. | Jos |
| `procese_verbale.php` | Helper local `pz_pv_date_ro()` similar cu oferte.php; tabele de verificat la iconuri acțiuni. | Mediu |
| `reports.php` | `reports_status_label()` OK cu diacritice (Finalizată, Anulată); KPI/stats fără accent-bar standardizat. | Mediu |
| `work_billing.php` | Filtre `status`/`pv`/`service` cu label neuniform; `ib_pv_label()` custom pentru status; `date('Y-m-d')` în parametri URL. | Mediu |
| `contracts.php` | Helper local `pz_contract_date_ro()` (linia 61) reimplementează `date('d.m.Y')`; status labels custom; header de aliniat la standard card + kicker. | Mediu |

---

## 2. Top 5 probleme transversale

### 2.1 Format dată ne-standardizat (6+ pagini)

`date('d.m.Y', strtotime(...))` direct în PHP în loc de `pz_date()` / `pz_datetime()` / `pz_date_long()` (helperii există în `app_helpers.php`, definiți la liniile 209-233 din `DESIGN_LINE.md`).

Pagini afectate: `invoices.php`, `oferte.php`, `contracts.php`, `procese_verbale.php`, `reports.php`, `work_billing.php`.

Mai grav: `oferte.php`, `procese_verbale.php`, `contracts.php` au fiecare un helper local (`pz_offer_date_ro`, `pz_pv_date_ro`, `pz_contract_date_ro`) care reimplementează același lucru — duplicat de logică.

### 2.2 KPI cards fără accent-bar 3px stânga (dashboard.php)

`DESIGN_LINE.md §3` cere: card alb + border + radius 8px + **accent-bar 3px solid stânga** în culoarea categoriei (Info `#2563EB`, Succes `#16A34A`, Atenție `#9A3412`, Pericol `#DC2626`, Neutru `#94A3B8`).

`pz-kpi` actual omite `border-left: 3px solid <color>`. Impact: KPI-urile nu transmit categoria din prima privire.

### 2.3 Label butoane filtru inconsistent (5+ pagini)

`DESIGN_LINE.md §9` cere: **„Aplică"** (secundar) + **„Resetează"** (ghost). În realitate apar: „Filtrează", „Filtreaza" (fără diacritic), „Filtrare", „Aplică" — mix.

Pagini afectate: `clients.php`, `invoices.php`, `procese_verbale.php`, `work_billing.php`, `tasks.php`.

### 2.4 Header pagină nealinit la standardul card + kicker (6+ pagini)

`DESIGN_LINE.md §2` cere UN singur stil: card alb, border 1px `--pz-line`, radius 8px, padding 22×24, **kicker uppercase 11px / 600 / `--pz-mu` / letter-spacing 0.08em** + H1 22px / 700 + descriere opțional + acțiuni dreapta.

În realitate sunt mai multe variante: `dashboard` (hero + card separat), `clients-page-title` custom, headers fără kicker pe `contracts`, `oferte`, `procese_verbale`.

Kickerele care lipsesc/sunt greșite: Dashboard=OPERAȚIONAL, Clienți=CLIENȚI, Documente/PV/Contracte/Oferte=DOCUMENTE, Financiar (Facturi/Încasări/Lista lucrări)=FINANCIAR, Rapoarte=RAPOARTE.

### 2.5 Status badges fără dot standardizat (4+ pagini)

`DESIGN_LINE.md §10` cere bg soft + border + text dark + padding 2×8 + font 11px / 600, cu **dot 6px solid stânga pentru status critic**.

În realitate: `tone-danger`, `tone-warning`, `tone-success`, `status-pill`, `status-paid` — clase fragmentate, fără dot.

Pagini afectate: `invoices.php` (linia 246+), `tasks.php`, `reports.php`, `work_billing.php`.

---

## 3. Quick wins (sub 30 minute, impact pe 3+ pagini)

### Quick win 1 — `pz_date()` peste tot (impact: 6 pagini)

Helperul există deja în `app_helpers.php`. Înlocuire mecanică:
- `date('d.m.Y', strtotime(...))` → `pz_date(...)`
- `date('d.m.Y, H:i', ...)` → `pz_datetime(...)`
- Helperii locali `pz_offer_date_ro`, `pz_pv_date_ro`, `pz_contract_date_ro` → eliminat, redirecționat la `pz_date()`.

Timp estimat: 20-30 min toate paginile.

### Quick win 2 — label „Aplică" / „Resetează" uniform (impact: 5+ pagini)

Search global pentru „Filtrează", „Filtreaza", „Filtrare" în butoane → înlocuire „Aplică". „Resetare", „Reset" → „Resetează".

Plus: butoanele să fie secundar + ghost, nu primary. Timp estimat: 15-20 min.

### Quick win 3 — accent-bar la KPI (impact: dashboard + posibil reports)

CSS în `app_theme_css.php`:
```css
.pz-kpi { border-left-width: 3px; border-left-style: solid; }
.pz-kpi.tone-info    { border-left-color: #2563EB; }
.pz-kpi.tone-success { border-left-color: #16A34A; }
.pz-kpi.tone-warn    { border-left-color: #9A3412; }
.pz-kpi.tone-danger  { border-left-color: #DC2626; }
.pz-kpi.tone-neutral { border-left-color: #94A3B8; }
```

În HTML: adăugat clasa `tone-*` pe fiecare card. Timp estimat: 15 min CSS + 10 min markup.

### Quick win 4 — corectări lingvistice globale (impact: peste tot)

Search-and-replace global:
- „Filtreaza" → „Filtrează"
- „Intarziate" → „Întârziate"
- „RANDURI" → „RÂNDURI"
- „finalizata" → „finalizată" (cu atenție la cheile DB care rămân pe `finalizata`)
- „statusuri si actiuni" → „statusuri și acțiuni"

Verificare manuală că nu atingem cheile DB. Timp estimat: 20-30 min.

---

## 4. Recomandare ordine de atac

### Sesiunea 1 — Quick wins lingvistice + format dată (risc scăzut, vizibilitate mare)

Aliniat cu `DESIGN_LINE.md §18` (sesiunea 1). Concret:
1. Înlocuire `date('d.m.Y'...)` cu `pz_date()` în toate paginile listate.
2. Eliminare helperii locali duplicați (`pz_offer_date_ro`, `pz_pv_date_ro`, `pz_contract_date_ro`).
3. Search-and-replace diacritice (fără atingerea cheilor DB).
4. Standardizare label „Aplică" + „Resetează" pe filtre.

Timp total: ~2 ore. Risc: foarte scăzut. Vizibilitate: mare (date corecte peste tot).

### Sesiunea 2 — Dashboard + KPI cards (impact mare)

1. Refactor header dashboard la card + kicker „OPERAȚIONAL".
2. Adăugat accent-bar 3px la `pz-kpi` cu toate variantele de tonalitate.
3. Verificat că reports.php folosește același pattern dacă are KPI.

Timp total: 1-2 ore. Risc: scăzut. Vizibilitate: foarte mare (dashboard e first impression).

### Sesiunea 3 — Headers pagini majore (clients, contracts, oferte, procese_verbale)

Conversia la `card + kicker + H1 + descriere + acțiuni`. Pagină cu pagină, screenshot înainte/după.

Timp total: 2-3 ore. Risc: mediu (UI vizibil). Vizibilitate: foarte mare.

### Sesiuni 4+ — Status badges cu dot, butoane tabel cu iconuri pure, sub-tabs cu underline

Aliniat cu `DESIGN_LINE.md §18` (sesiunile 3-6). Lucrăm pagină cu pagină, prioritate pe modulele cu trafic mare (financiar, calendar, clients).

---

## 5. Ce NU am verificat în detaliu

- `calendar.php` (3463 linii) — fișierul e prea mare pentru un audit superficial; abaterile principale sunt probabil în iconurile coloanei „Acțiuni" și modale.
- `style_guide.php` — pagina de demonstrație, ar trebui audited în paralel pentru a confirma că e in-sync cu DESIGN_LINE.md.
- Mobile responsive — necesită deschiderea paginilor în browser cu DevTools. Audit-ul actual e static-textual.
- Iconurile lipsă din `app_icons.php` — DESIGN_LINE.md §15 listează setul standardizat; nu am verificat ce e prezent vs lipsă.

---

**Acest audit este punctul de plecare pentru sesiunile de aliniere design conform `DESIGN_LINE.md §18`. Modificările se logează aici cu data.**
