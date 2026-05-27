# Audit dashboard — acuratețe date + persistență perioadă

**Data:** 27 mai 2026
**Mod:** read-only. Nicio modificare aplicată.
**Pagini:** `dashboard.php` (1983 linii)

---

## 1. Selectoare de perioadă (3 separate)

| Selector | Default | URL param | Cards afectate |
|---|---|---|---|
| `period_op` | `month` | `?period_op=` | Operațional: grafic programări, donut status, top servicii, mini-statistici |
| `period_fin` | `month` | `?period_fin=` | Financiar: venituri, facturi, încasări, top clienți, sparkline, **tasks**, **reminders** (vezi §3) |
| `period_team` | `today` | `?period_team=` | Echipă: top tehnicieni, % team activ |

**Opțiuni perioadă** (toate disponibile pentru fiecare selector):
- `today` (Azi)
- `week` (Ultimele 7 zile)
- `month` (Luna curentă — default)
- `last_month` (Luna trecută)
- `3months` (Ultimele 3 luni)
- `6months` (Ultimele 6 luni)
- `year` (Anul curent)

---

## 2. KPI cards — audit per card

### 2.1 `kpi-revenue` — Venituri perioadă

**Variabilă perioadă:** `period_fin` → `$finStart`, `$finEnd`

**Query principal** (linia 261):
```sql
SELECT COUNT(*) AS c, COALESCE(SUM(gross_amount),0) AS s
FROM smartbill_invoices
WHERE invoice_date BETWEEN ? AND ?
  AND TRIM(COALESCE(smartbill_number,'')) <> ''
```

**Verdict:** ✅ Corect. Suma facturilor emise în perioada selectată. Filtrează doar facturi cu număr alocat (`smartbill_number` non-empty). Include comparație cu perioada anterioară (delta %).

**Observație minoră:** include și facturi de tip `storno` (negative) — corect pentru cifra de business netă. Nu exclude `source_type = 'receipt'` ca alte query-uri (`§5 venituri pe categorie`). Mic inconsistent dar acceptabil.

### 2.2 `kpi-invoices` — Facturi emise (% încasate)

**Variabilă perioadă:** `period_fin`

**Date afișate:**
- Număr facturi: `$finIssuedCount` (din kpi-revenue query)
- Procent încasate: `$kpiPaidPct = round(($finPaid / $finIssued) * 100)` (linia 1385)
- Bară colorată: paid (verde) / pending (portocaliu) / restante (roșu)

**Calcul restante** (linia 1386):
```sql
SELECT i.client_name, SUM(GREATEST(0, i.gross_amount - COALESCE(p.paid, 0))) AS remaining_amount
FROM smartbill_invoices i
LEFT JOIN (... GROUP BY smartbill_invoice_id) p
WHERE i.due_date < ? AND i.due_date IS NOT NULL
  AND i.source_type <> 'receipt'
  AND TRIM(COALESCE(i.smartbill_number, '')) <> ''
```

**Verdict:** ✅ Mare în general, dar:

**🐛 Bug logic minor:** `$kpiPendingPct = max(0, 100 - $kpiPaidPct - $kpiRestPctSum)`. Dacă procentele depășesc 100 (ex: restanțe acumulate > total perioada actuală), `pending` poate fi 0 dar bara nu mai e proporțională. Edge case rar.

**🐛 Inconsistență:** `$restanteAmount` calculează restanțe GLOBALE (toate due_date < azi din TOATE timpurile), dar se raportează la `$finIssued` (doar perioada selectată). Dacă selectezi „Azi", `$kpiRestPctSum` poate fi 500% (sau e clamp-at la 100%). **Recomandare:** fie restanțele să fie limitate la perioada actuală, fie ratio-ul să folosească alt numitor (gen total emis în ultimele 12 luni).

### 2.3 `kpi-today` — Programări azi (FIX azi)

**Variabilă perioadă:** N/A (mereu azi)

**Query** (linia 426):
```sql
SELECT a.id, a.start_time, a.service_type, a.status, c.name, tm.name
FROM appointments a
LEFT JOIN clients c ON c.id=a.client_id
LEFT JOIN team_members tm ON tm.id=a.team_member_id
WHERE a.appointment_date=? AND a.status!='anulata'
ORDER BY a.start_time ASC, a.id ASC LIMIT 6
```

Apoi: `$todayCount = count($todayAppointments)` (linia 1389).

**🐛 BUG CRITIC:** `LIMIT 6` în query → `$todayCount` se calculează din primele 6 programări. **Dacă sunt mai mult de 6 programări astăzi, KPI afișează „X / 6 finalizate" în loc de „X / TOTAL".**

**Reparare necesară:** Separa numărătoarea totală (`COUNT(*)`) de lista pentru afișare (LIMIT 6).

### 2.4 `kpi-due` — De facturat (poziții)

**Variabilă perioadă:** `period_fin`

**Query** (linia 252 — billing_items path) / linia 256 (fallback appointments):
```sql
SELECT COUNT(*) AS c, COALESCE(SUM(total_net),0) AS s
FROM billing_items
WHERE status='to_invoice' AND work_date BETWEEN ? AND ?
```

**Verdict:** ✅ Corect. Folosește `work_date` (data lucrării) — corectă pentru poziții de facturat.

**Observație:** poziții status `to_review` (nou de finalizat) NU sunt incluse. Doar `to_invoice`. Pentru un admin care vrea „toate pozițiile financiare necompletate", lipsesc cele în review. Decizie de design.

---

## 3. Big cards — audit per card

### 3.1 `card-revchart` — Chart venituri 6 luni

**Folosește:** 6 luni FIX (nu period_fin) — `$sixMonthsStart = date('Y-m-01', strtotime('-5 months'))`.

**Verdict:** ✅ Corect. Trend pe 6 luni independent de perioada selectată. Coerent cu logica „evoluție pe termen mediu".

### 3.2 `card-statusdonut` — Distribuție status programări

**Variabilă perioadă:** `period_op` (linia 531).

**Query:**
```sql
SELECT status, COUNT(*) AS c FROM appointments
WHERE appointment_date BETWEEN ? AND ? GROUP BY status
```

**Verdict:** ✅ Corect.

### 3.3 `card-todayappts` — Programări astăzi (listă)

**Folosește:** același `$todayAppointments` cu LIMIT 6 (linia 426).

**Pentru listă, LIMIT 6 e OK** (afișezi 6 itemi în card). Dar reține că `$todayCount` din KPI folosește același array → erorile sunt linked (vezi §2.3).

### 3.4 `card-topclients` — Top clienți după venituri

**Variabilă perioadă:** `period_fin` (linia 618).

**Query:**
```sql
SELECT COALESCE(NULLIF(TRIM(i.client_name), ''), c.name, '-') AS name,
       COALESCE(SUM(i.gross_amount), 0) AS amount,
       COUNT(i.id) AS invoices_count
FROM smartbill_invoices i
LEFT JOIN clients c ON c.id = i.client_id
WHERE i.invoice_date BETWEEN ? AND ?
  AND TRIM(COALESCE(i.smartbill_number, '')) <> ''
GROUP BY name ORDER BY amount DESC LIMIT 5
```

**Verdict:** ✅ Corect. Top 5 clienți după gross facturat în perioadă.

**Inconsistență minoră:** nu exclude `source_type = 'receipt'` (chitanțe standalone). Diferă de query-ul similar din §3.5 venituri categorie care exclude. Recomand uniformizare.

### 3.5 `card-tasks` — Sarcini active

**Variabilă perioadă:** `period_fin` (linia 705 — `WHERE due_date BETWEEN ? AND ? OR due_date < CURDATE()`).

**⚠ Atenție user:** Cardul **NU este fix** cum credeai inițial. Folosește `period_fin`. Sarcinile restante (due_date < azi) apar întotdeauna, dar cele în perioadă viitoare depind de `period_fin`.

**Verdict:** ✅ Tehnic corect, dar **comentariu vs comportament real** — vezi notă.

### 3.6 `card-reminders` — Remindere

**Variabilă perioadă:** `period_fin` (linia 735).

**Aceeași logică ca `card-tasks`** — restante (remind_date < azi) mereu vizibile + perioadă viitoare din period_fin.

**Verdict:** ✅ Tehnic corect.

---

## 4. Cards/widget secundare audit

### 4.1 Venituri pe categorie (linia 354)
- Perioadă: `period_fin`
- Filtrează corect: `source_type <> 'receipt'` ✅
- Categorii: ddd / ignifugari / chirii / altele

### 4.2 Mini cards (statistici compacte)
- `$stockLowCount`, `$stockExpiredCount` — globale (NU depind de perioadă) ✅ corect (stocul curent)
- `$docsIssuedCount`, `$docsByType` — perioada `period_op` ✅
- `$deferredPvCount` — global (PV-uri emise în alb pendente) ✅
- `$activeClients` — perioada `period_op` ✅
- `$totalClients` — global ✅

### 4.3 Top servicii (linia 544)
- Perioada `period_op` ✅

### 4.4 Trend 6 luni (banner navy, linia 558)
- 6 luni FIX (nu period_op) ✅ — coerent cu logica de trend mediu

---

## 5. Probleme prioritizate

### 🔴 Sus — buguri reale

1. **`kpi-today` LIMIT 6** (linia 426 + 1389). Trebuie query separat de COUNT.

### 🟡 Mediu — inconsistențe

2. **Restanțe globale raportate la perioadă curentă** (kpi-invoices §2.2). Decizie de design.
3. **Filtre `source_type` inconsistente** (top clienți include chitanțe, venituri categorie exclude).
4. **Comentariu vs realitate** pentru tasks/reminders — codul zice `period_fin`, comentariile sugerează „filtru vizibil din header" (ambiguu).

### 🟢 Jos — observații

5. **`kpi-revenue` include storno** (corect pentru cifra netă, dar user trebuie să știe).
6. **`kpi-due` exclude `to_review`** (poate fi feature, nu bug).

---

## 6. Persistență perioadă în browser

**Stare curentă:** ZERO persistență. La fiecare reload, perioadele revin la defaults (`month`, `month`, `today`).

**Propunere implementare:**

```javascript
// La load: dacă URL nu are period_X, citește din localStorage
(function() {
    const params = new URLSearchParams(window.location.search);
    const keys = ['period_op', 'period_fin', 'period_team'];
    let needsRedirect = false;
    keys.forEach(k => {
        if (!params.has(k)) {
            const saved = localStorage.getItem('pz_dash_' + k);
            if (saved) {
                params.set(k, saved);
                needsRedirect = true;
            }
        }
    });
    if (needsRedirect) {
        window.location.replace('dashboard.php?' + params.toString());
    }
})();

// La click pe selector: salvează în localStorage
document.querySelectorAll('[data-period-key]').forEach(a => {
    a.addEventListener('click', e => {
        const key = a.dataset.periodKey;
        const value = a.dataset.periodValue;
        localStorage.setItem('pz_dash_' + key, value);
    });
});
```

**Modificări de UI necesare:**
- Link-urile de perioadă să primească `data-period-key` și `data-period-value` (pentru a captura click-uri și salva).

Localizarea selectoarelor de perioadă în UI — TBD, le caut în secțiunea de render.

---

**Acest audit e baza pentru următoarele intervenții. Modificările concrete (fix LIMIT 6, localStorage, etc.) intră în PLAN_CORECTARE_STRUCTURA.md sau commit separat.**
