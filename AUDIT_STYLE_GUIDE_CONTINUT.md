# Spot-check de conținut — `style_guide.php`

**Data:** 21 mai 2026
**Metodă:** parcurgere capăt-la-capăt a celor 12 capitole, comparație cu codul real din proiect.

## TL;DR

Pagina e bine structurată în general, dar conține **trei tipuri de probleme**:

1. **Duplicări** între capitole (tabelul apare în cap 03 și 06; status-ul în cap 01 și 02).
2. **Cod mort** (aliasurile `--pzui-*`).
3. **Documentație fantomă** — descrie comportamente care nu există în restul aplicației sau pentru care pagina e singura instanță.

Pierdere de spațiu estimată dacă curățăm: **~150-200 linii** dintr-un total de ~2335 linii de markup (sub 10%, nu e un munte). Dar redundanțele creează risc de drift între ce e documentat și ce e implementat — ăsta e câștigul real.

---

## Probleme concrete

### 1. Duplicare: Tabel apare în două capitole

**Cap 03 — Componente** are o secțiune `<h2>Tabel</h2>` (linii 1671–1716) cu un demo simplu: 5 coloane, 3 rânduri de date demo, fără filtre, fără paginare.

**Cap 06 — Tabele** este dedicat în întregime tabelelor (424 linii) cu 5 sub-secțiuni:
- Anatomia unui tabel complet (cu filtre + paginare)
- Tipuri de coloane
- State-uri rânduri
- Tabel compact & stare goală
- Reguli complete

**Recomandare:** șterge secțiunea „Tabel" din cap 03 (~45 linii) și pune o trimitere către cap 06: *„Vezi capitolul 06 Tabele pentru toate variantele."* Sau, dacă vrei să păstrezi un teaser, redu la 1 frază + screenshot mic.

### 2. Cifre incorecte în documentație

Cap 01, secțiunea „Variabile CSS — tokeni de design" (linia 1138) afișează:

```html
<span class="ds-badge bl">18 tokeni</span>
```

Realitatea: fișierul definește **29 de tokens `--pz-*`** (verificat cu grep). Diferența vine din faptul că secțiunea grupează variantele de culoare (`--pz-bl/bld/bls/blb` ca o singură intrare), dar atunci ar trebui să spună „13 grupuri" sau „29 tokens (13 grupuri)".

**Recomandare:** corectează cifra sau șterge badge-ul. Nu are valoare ca informație inexactă.

### 3. Cod mort: aliasurile `--pzui-*`

La linia 945, blocul „Compatibility aliases":

```css
:root {
    --pzui-bg: var(--pz-bg); --pzui-surface: var(--pz-surf);
    --pzui-line: var(--pz-line); --pzui-line-soft: var(--pz-lines);
    /* ... 13 aliasuri total ... */
}
```

**Verificare în codebase:** zero utilizări `var(--pzui-*)` în orice alt fișier PHP din proiect. Sunt definite doar pentru ele însele.

**Recomandare:** șterge complet blocul (12 linii). Dacă a fost păstrat din precauție pentru cod istoric, faptul că nu mai există grep-rezultate spune că tranziția s-a încheiat de mult.

### 4. Documentație pentru o funcționalitate fără demo activ

Cap 04 — „Drag & drop (SortableJS)" (linii 1837–1876) descrie:
- librăria (SortableJS 1.15.2 de pe cdnjs)
- handle-ul (`.drag-handle`)
- animația (180ms cubic-bezier)
- persistența în localStorage cu cheia `pz_dash_order_v1_{rowId}`

Dar pagina `style_guide.php` **nu include SortableJS** și nu există un demo funcțional — doar text și 3 cutii care arată state-urile vizuale. Dacă cineva citește ghidul și vrea să vadă cum se simte drag-and-drop, trebuie să meargă pe dashboard.

**Recomandare:** fie adaugi un demo funcțional (include `<script>` SortableJS), fie muți conținutul în Cap 05 (Reguli) ca paragraf, fie marchezi clar că e doar referință teoretică. Cum e acum, ocupă spațiu de capitol întreg pentru un text descriptiv.

### 5. Inconsistență logică: pagina luptă cu propriile reguli

Cap 05 — „Sidebar & navigare" listează 9 reguli:
- Sidebar bg #12345A
- Item activ bg rgba(255,255,255,.13), border-left 2px #60A5FA
- Topbar alb, border-bottom 1px #E2E8F0, fără shadow
- ... etc.

În același fișier, la liniile 85–139, sunt **37 de `!important`** care **suprascriu tema globală** pentru a forța exact aceste reguli pe pagina de stil. Adică:

> *„Iată cum trebuie să arate sidebar-ul. Tema globală nu îl arată așa, dar îl forțăm noi cu `!important` doar aici."*

Asta e un mesaj contradictoriu. Două opțiuni:

- **A.** Dacă regula e globală: mută definițiile (fără `!important`) în `app_theme_css.php` și șterge override-urile.
- **B.** Dacă pagina DS arată un sidebar diferit intenționat: nu mai listează regulile ca „globale" în cap 05.

Cea mai probabilă realitate: A. Dar trebuie verificat vizual pe alte pagini ale aplicației.

### 6. Posibil typo

Linia 2659 (Cap 07, „Alegerea pattern-ului potrivit"):
> *„(breadcrumb): pagini adânc **înicuibate** — Setări > Integrare X"*

Probabil voia să spună „imbricate" sau „adânc cuibărite". „Înicuibate" nu e un cuvânt în română.

### 7. Capitole foarte scurte vs. foarte lungi

| Capitol | Linii | Observație |
|---------|------:|------------|
| 10 notificări | 51 | doar 2 sub-secțiuni — ar putea fi unit cu cap 11 sau cap 09 |
| 08 modale | 87 | 2 sub-secțiuni — concis, ok |
| 09 formulare | 94 | 2 sub-secțiuni — concis, ok |
| 11 loading | 97 | 2 sub-secțiuni — concis, ok |
| 04 layout | 106 | 2 sub-secțiuni, dintre care una (drag&drop) e teoretică |
| ... | | |
| 06 tabele | **424** | 5 sub-secțiuni — disproporționat de mare |

**Recomandare:** rezonabil ca tabelele să fie tratate separat (sunt cea mai folosită componentă în CRM). Dar dacă vrei echilibru, scoate „Reguli complete" din capitolele 06 și 07 într-un cap unic „05 Reguli" (deja există) sau le păstrezi ca acum.

### 8. Lipsa unui demo viu pentru iconuri lipsă

Cap 12 — secțiunea „Biblioteca de iconuri" definește local un array `$allIcons` cu cele documentate. `app_icons.php` are **45 de iconuri** definite, dar nu e clar din ghid câte din ele sunt acoperite vizual. Dacă cineva adaugă un icon nou în `app_icons.php`, ghidul nu îl afișează automat.

**Recomandare ușoară:** în loc de array hardcodat, iterează prin toate cheile returnate de `app_icons.php`:

```php
foreach (array_keys(get_all_icons()) as $name) { ... }
```

Așa biblioteca e auto-actualizată.

## Ce să elimini, ce să rescrii, ce să lași în pace

### Elimină (estimat ~80–100 linii)
- Secțiunea „Tabel" din Cap 03 (~45 linii) → trimitere la Cap 06
- Aliasurile `--pzui-*` (12 linii)
- Badge-ul „18 tokeni" sau corectare la „13 grupuri (29 tokens)" (1 linie)
- Override-urile sidebar/topbar cu `!important` (55 linii) — *dacă* decizi opțiunea A din pct. 5

### Rescrie
- Cap 04 „Drag & drop" — fie cu demo viu, fie scurtat la o referință
- Cap 12 secțiunea „Biblioteca de iconuri" — auto-populare din `app_icons.php`
- Typo „înicuibate" → „imbricate"

### Lasă în pace
- Cap 05 „Checklist pagină nouă" — e cu adevărat util ca referință înainte de a lansa o pagină nouă
- Cap 06 „Reguli complete pentru tabele" — bine documentat, e cea mai folosită componentă
- Cap 07 „5 pattern-uri de header" — clar și exhaustiv
- Cap 02 „Sistem de status" — sistemul semantic e cu adevărat singura sursă de adevăr pentru ce culoare înseamnă ce

## Concluzie

Conținutul **nu e mult degeaba** — majoritatea capitolelor au valoare. Problemele sunt punctuale: o duplicare clară (tabel), niște cod mort (aliasuri), o cifră greșită, un typo, și o inconsistență de design (sidebar override-urile vs. reguli).

Dacă timpul e limitat, cel mai mare câștig vine din **trei mișcări** rapide:
1. Șterge secțiunea „Tabel" din Cap 03 (5 min)
2. Șterge aliasurile `--pzui-*` (2 min)
3. Decide ce facem cu override-urile de sidebar (etapa 3 din auditul anterior)

Restul sunt curățenie cosmetică pe care o poți face în ritm propriu.
