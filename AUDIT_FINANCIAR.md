# AUDIT MODUL FINANCIAR — EuroPrest / PestZone CRM

Data: 20 mai 2026  
Mod: read-only. Nu s-a modificat niciun fișier și nicio tabelă.  
Scop: hartă a situației actuale + plan de curățare, înainte de orice intervenție.

---

## 1. Hartă tabele existente

### 1.1 Tabele financiare (există efectiv)

| Tabel | Rol actual | Observație |
|---|---|---|
| `smartbill_invoices` | Tabel principal de facturi. Conține: client snapshot, sume, status SmartBill, status e-Factura, PDF, request/response JSON, appointment_id. | **Este folosit ca singura sursă de adevăr pentru facturi**, deși numele sugerează doar oglindă SmartBill. Are coloana `source_type` cu valori `appointment` / `manual` / `receipt`. |
| `smartbill_invoice_items` | Linii factură. | Are `appointment_id` per linie — deci e pregătit structural pentru multi-poziție, dar codul nu îl folosește așa. |
| `smartbill_invoice_payments` | Încasări. | Tabel separat curat. Folosit și pentru chitanțe standalone (când `smartbill_invoice_id` rămâne null). |
| `smartbill_invoice_logs` | Log apeluri SmartBill. | Există. Bun. |
| `smartbill_recurring_invoices` | Facturi recurente (template + cadență). | OK. |
| `smartbill_supplier_invoices` | Facturi de la furnizori (import e-Factura). | Nu intră în fluxul cerut acum. |
| `anaf_oauth_tokens` | Token-uri ANAF e-Factura. | Întreg modul e-Factura există paralel. |
| `anaf_efactura_logs` | Log e-Factura. | Idem. |
| `app_settings` | Setări key-value, inclusiv toate cheile `smartbill.*` și `company.*`. | **Nu există tabel separat `smartbill_settings`.** Setările SmartBill stau aici, sub chei. |

### 1.2 Tabele care NU există, deși ai presupus că ar exista

| Tabel presupus | Realitatea |
|---|---|
| `billing_items` | **Nu există.** Pozițiile „de facturat" sunt stocate direct pe `appointments` în coloane `billing_*`. Acesta este punctul-cheie de defect. |
| `invoices` | Nu există. Rolul îl ține `smartbill_invoices`. |
| `invoice_items` | Nu există. Rolul îl ține `smartbill_invoice_items`. |
| `payments` | Nu există. Rolul îl ține `smartbill_invoice_payments`. |
| `smartbill_settings` | Nu există. Setările stau în `app_settings` (key-value). |

### 1.3 Tabele operaționale care alimentează financiarul

| Tabel | Coloane financiare prezente |
|---|---|
| `appointments` | `billing_amount`, `billing_vat_code`, `billing_status`, `billing_note`, `billing_updated_at`, `billing_updated_by`, `billing_invoice_series`, `billing_invoice_number`, `billing_invoice_date`, `contract_id`, `contract_service_id`, `task_id`, `service_id`, `currency`. **Aici trăiește efectiv „poziția de facturat".** |
| `tasks` | `billing_amount`, `currency` — există dar nu se folosește în fluxul de facturare. |
| `contract_services` | `price`, `currency` — tarif din contract pe locație/serviciu. |
| `contracts` | `estimated_value`, `total_value`. |
| `documents` (cu `document_type='proces_verbal'`) | `subtotal`, `vat_percent`, `vat_amount`, `total_amount`. PV-ul are valoare proprie. |
| `clients`, `client_locations` | Snapshot-uri în factură. |

---

## 2. Hartă fișiere și butoane

### 2.1 Sidebar curent — grupul „Facturare"

Definit în `app_ui.php` (`render_sidebar`, ~rândul 4060). Cinci intrări:

| Etichetă | Fișier | Rol |
|---|---|---|
| Lista lucrări | `work_billing.php` | „De facturat" — lista programărilor finalizate cu acțiuni: salvează valoare, marchează facturată (manual sau bifă), marchează „nu se facturează", emite factură SmartBill. |
| Facturi | `invoices.php` | Listă facturi (citește `smartbill_invoices`). |
| Încasări | `payments.php` | Listă încasări (citește `smartbill_invoice_payments`). |
| Recurente | `recurring_invoices.php` | Facturi recurente (template SmartBill). |
| E-Factura | `efactura.php` | Sincronizare ANAF e-Factura. |

Setări SmartBill: `smartbill_settings.php`, accesibil din meniul Setări.

### 2.2 Stub-uri RO → EN (redirect-only)

Există fișiere mici, doar require către omologul englezesc. Nu mai sunt dubluri reale, doar alias-uri.

| RO | EN |
|---|---|
| `factura.php` | `invoice.php` |
| `facturare.php` | `invoices.php` |
| `facturi.php` | `invoices.php` |
| `facturi_pdf.php` | `invoice_pdf.php` |
| `facturi_recurente.php` | `recurring_invoices.php` |
| `incasare.php` | `payment.php` |
| `incasari.php` | `payments.php` |
| `interventii_facturare.php` | `work_billing.php` |

Stilul „stub" este OK — îl păstrăm exact așa, doar redirect.

### 2.3 Fișiere financiare reale (cu cod)

| Fișier | Linii / mărime | Rol | Stare |
|---|---|---|---|
| `smartbill_lib.php` | 2.038 linii, 91 KB, 44 funcții | Centrul logic. Schema, validări, payload SmartBill, emitere, încasare, anulare, storno, recurente, sync, log. | Mult prea încărcat. Conține logica de UI-orchestrare (auto-update `appointments.billing_status`) + apel API SmartBill + reguli de business. Trebuie spart. |
| `work_billing.php` | 51 KB | „Lista lucrări" — listă programări finalizate cu acțiuni de facturare. | Stabil ca UI, dar are două defecte mari (vezi §3). |
| `invoice.php` | 103 KB | Pagina detaliu / creare / editare factură. Conține și HTML și JS în același fișier. | Funcționează, dar e fișier-monstru. UI „Emite factură" face direct apel SmartBill. |
| `invoices.php` | 17 KB | Listă facturi. Status compus în PHP (issued / paid / partial / overdue / unpaid / draft / error). | OK ca listare, dar logica de status e duplicată față de cea din `smartbill_lib.php`. |
| `payment.php` | 27 KB | Pagină plată — creare încasare. | OK. |
| `payments.php` | 16 KB | Listă încasări. | OK. |
| `recurring_invoices.php` | 10 KB | Listă recurente + acțiuni generate/pause/resume. | OK. |
| `smartbill_settings.php` | 11 KB | Form setări (citește/scrie chei `smartbill.*` în `app_settings`). | Curat. **Setările sunt corect izolate.** |
| `smartbill_debug.php` | 11 KB | Read-only, vizualizare ultimele apeluri SmartBill din log. | Util, păstrat. |
| `efactura.php` | 26 KB | Pagină e-Factura ANAF. | Ieșire din scop pentru curățare. |
| `anaf_efactura_lib.php` | 38 KB | Lib e-Factura. | Idem. |
| `efactura_settings.php`, `efactura_archive.php`, `efactura_download.php`, `efactura_oauth_callback.php`, `cron_efactura_sync.php` | mici-medii | Suport e-Factura. | Idem. |

### 2.4 Cron-uri legate

- `cron_smartbill_recurring.php` → generează facturile recurente scadente.
- `cron_efactura_sync.php` → sincronizare ANAF.

---

## 3. Unde se rupe logica (defecte concrete)

### 3.1 [CRITIC] Cuplaj rigid programare ↔ factură

`smartbill_invoices` are:
```sql
UNIQUE KEY uq_smartbill_appointment (appointment_id)
```

Plus în `invoice.php` (linia ~624):
```php
$stmt = $pdo->prepare("SELECT id FROM smartbill_invoices WHERE appointment_id = ? LIMIT 1");
```

Consecință: **o programare ⇔ o factură (1-la-1)**. Imposibil să emiți o factură pentru mai multe programări ale aceluiași client (Flux 4 din specificația ta). Codul a luat scurtătura „factura este programarea".

Asta încalcă regula ta de bază: „Nu lega direct programarea de factură fără strat intermediar."

### 3.2 [CRITIC] Lipsește stratul intermediar „billing_item"

Sursa pentru lista „De facturat" nu este un tabel `billing_items`, ci coloanele `billing_*` adăugate pe `appointments`. Practic, programarea ține trei roluri simultan:
1. eveniment operațional în calendar;
2. poziție de facturat (prin `billing_*`);
3. ancoră fiscală (legată de o factură via FK invers).

Asta face imposibilă:
- gruparea de poziții de la programări diferite pe aceeași factură;
- emiterea de poziții care nu vin dintr-o programare (de exemplu o intervenție ad-hoc fără programare în calendar);
- păstrarea unei poziții într-un status financiar diferit de cel operațional.

### 3.3 [CRITIC] Două denumiri pentru același status

În `calendar.php`, linia 1684:
```php
$billingStatus = $notInvoiceable ? 'nefacturabil' : 'de_facturat';
```

În `work_billing.php`, linia 134:
```php
$pdo->exec("UPDATE appointments SET billing_status = 'de_facturat'
            WHERE billing_status NOT IN ('de_facturat','facturata','nu_se_factureaza')");
```

Efect: orice programare salvată cu `billing_status = 'nefacturabil'` din calendar este **silent-overwriten la `'de_facturat'`** prima dată când cineva intră pe pagina „Lista lucrări". Bifa „Nu se facturează" se pierde fără mesaj.

Valoarea canonică trebuie să fie una singură: `nu_se_factureaza` (cum cere și specificația ta).

### 3.4 [MAJOR] Statusuri amestecate pe trei niveluri

Pe aceeași entitate suprapunem trei dimensiuni:

| Plan | Coloană | Valori | Observație |
|---|---|---|---|
| Operațional (programare) | `appointments.status` | `confirmata`, `finalizata`, `anulata`, ... | OK ca plan separat. |
| Financiar (poziție) | `appointments.billing_status` | `de_facturat`, `facturata`, `nu_se_factureaza` (+ ilegal `nefacturabil`) | Locația greșită (pe `appointments`), valori incomplete (lipsește `to_review`, `cancelled`). |
| Document SmartBill | `smartbill_invoices.smartbill_status` | `draft`, `issued`, `error` | OK pe partea de provider. |
| Plată | derivat în PHP | `paid`, `partial`, `unpaid`, `overdue` | Calculat în două locuri diferite cu reguli ușor diferite. |
| ANAF | `smartbill_invoices.efactura_status` | text liber | În alt plan. |

Codul calculează „statusul rândului" în două locuri:
- `invoices.php` (listare) — propria logică derivată;
- `smartbill_lib.php::pz_smartbill_payment_status` — logică similară dar pe baza încasărilor.

Sunt apropiate, dar nu identice. Risc de divergență.

### 3.5 [MAJOR] Auto-sincronizare ascunsă

În `work_billing.php`, linia 145:
```sql
UPDATE appointments a
INNER JOIN smartbill_invoices si ON si.appointment_id = a.id
SET a.billing_status = 'facturata', a.billing_updated_at = NOW()
WHERE a.status = 'finalizata'
  AND a.billing_status <> 'facturata'
  AND si.smartbill_number IS NOT NULL
```
Această cerere se execută **la fiecare deschidere** a paginii „Lista lucrări". Funcționează ca self-healing, dar:
- ascunde de unde vine schimbarea de status (nu apare cine/când a marcat ca facturată);
- nu setează `billing_updated_by`;
- nu loghează nimic;
- amestecă logica de citire cu logica de scriere.

### 3.6 [MAJOR] Logica de emitere factură este în pagina UI

În `invoice.php` (rânduri 459–500), butonul „Emite factură" cheamă direct `pz_smartbill_issue_invoice($pdo, $invoiceId)`. Funcția trăiește în `smartbill_lib.php` și face și update local și apel API. Punct unic, deci nu sunt două locuri unde se creează factură — bun. Dar nu există interfață `InvoiceProviderInterface`. Dacă mâine se schimbă providerul, se rescrie pagina.

### 3.7 [MEDIU] Valoarea lucrării poate trăi în mai multe locuri

| Locație | Câmp | Folosire |
|---|---|---|
| `appointments` | `billing_amount` | Sursa folosită activ. |
| `tasks` | `billing_amount` | Setat de generare sarcini din contract, dar **nu** se preia activ în programare. |
| `contract_services` | `price` | Tariful contractual. |
| `documents` (PV) | `subtotal`, `total_amount` | Calculat în pagina PV, independent. |
| `smartbill_invoices` | `net_amount`, `gross_amount` | Final pe factură. |

Riscul: un PV cu valoare diferită față de programare, plus un tarif de contract diferit, fără regulă clară care câștigă.

### 3.8 [MEDIU] E-Factura este modul paralel întreg

`anaf_efactura_lib.php`, `efactura*.php`, `cron_efactura_sync.php`, plus tabelele `anaf_oauth_tokens`, `anaf_efactura_logs` și coloanele `efactura_*` din `smartbill_invoices`. Funcționează ca modul de sine stătător. Conform Pasului 8 din specificația ta („Nu avem nevoie de e-Factura în CRM acum"), trebuie izolat sau ascuns, nu integrat în fluxul nou.

### 3.9 [MINOR] Oblio

Verificat: **nu există referințe Oblio în cod** (grep `oblio` → 0 rezultate). Confirmat curat. Nimic de eliminat aici.

### 3.10 [MINOR] Naming inconsistent

- Stub-urile RO (`factura.php` etc.) sunt deja `require` simple — OK.
- Etichetele din sidebar folosesc diacritice (corect).
- Constantele de status sunt în română, fără diacritice, dar amestecate (`facturata` vs `nefacturabil` vs `nu_se_factureaza`).

---

## 4. Ce găsim când căutăm după cuvintele-cheie

| Termen | Apariții relevante |
|---|---|
| `invoice` | `invoice.php`, `invoices.php`, `invoice_pdf.php`, `smartbill_invoices`. |
| `factura` | Stub-urile RO + texte UI. |
| `billing` | Doar pe `appointments.billing_*` și `work_billing.php`. |
| `payment` / `incasare` | `payment.php`, `payments.php`, `smartbill_invoice_payments`. |
| `smartbill` | `smartbill_lib.php`, `smartbill_settings.php`, `smartbill_debug.php`, plus chei `smartbill.*` în `app_settings`. |
| `oblio` | **0 referințe.** Curat. |
| `not_billable` / `nu_se_factureaza` / `nefacturabil` | Trei variante coexistente (vezi 3.3). |
| `pv` / `proces_verbal` | În `procese_verbale.php` + `documents.document_type='proces_verbal'`. PV-ul nu declanșează nimic financiar — programarea finalizată este declanșatorul. **Aici codul este deja conform regulii tale.** |
| `finalizeaza` / `finaliz` | Două puncte: `calendar.php:1389` (tehnician), `calendar.php:1410` (`admin_finalize`). Nu creează poziție de facturat — există deja una implicită pe appointment. |

---

## 5. Ce funcționează deja bine (păstrăm)

1. PV-ul **nu** este declanșatorul financiar. `documents` (PV) este suport, nu trigger. Conform specificației tale.
2. Setările SmartBill sunt deja izolate într-o pagină dedicată și citesc/scriu `app_settings` cu chei `smartbill.*`. Nimic dezordonat.
3. Există un log SmartBill (`smartbill_invoice_logs`) cu request/response. Acoperă cerința de log API.
4. Stub-urile RO → EN sunt redirect-only — nu duplică logica.
5. Nu există Oblio. Curat.
6. Există funcție centrală unică `pz_smartbill_issue_invoice()` — un singur punct de emitere.
7. `app_settings` ca tabel key-value este simplu și extensibil.
8. Plățile au tabel separat (`smartbill_invoice_payments`), nu sunt amestecate în `smartbill_invoices`.

---

## 6. Plan de curățare propus

Înainte de orice cod, fixăm regulile. Mai jos sunt propunerile; nu execut nimic până nu confirmi fiecare bloc.

### Etapa A — Stratul intermediar `billing_items` (CRITIC)

**Propunere:** introducem tabela `billing_items` care devine sursa „De facturat".

```sql
CREATE TABLE billing_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NULL,           -- legătură opțională cu programarea
    pv_document_id INT NULL,           -- legătură opțională cu PV (doar suport)
    client_id INT NOT NULL,
    client_location_id INT NULL,
    contract_id INT NULL,
    contract_service_id INT NULL,
    service_id INT NULL,
    description VARCHAR(255) NOT NULL,
    work_date DATE NOT NULL,
    quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000,
    unit VARCHAR(30) NOT NULL DEFAULT 'buc',
    unit_price_net DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    vat_code VARCHAR(40) NOT NULL DEFAULT '21',
    total_net DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'RON',
    source VARCHAR(20) NOT NULL DEFAULT 'appointment', -- appointment | manual | contract
    status VARCHAR(20) NOT NULL DEFAULT 'to_review',   -- to_review | to_invoice | invoiced | not_billable | cancelled
    not_billable_reason VARCHAR(255) NULL,
    smartbill_invoice_id INT NULL,     -- când e facturat
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_billing_client (client_id, status),
    INDEX idx_billing_status_date (status, work_date),
    INDEX idx_billing_appointment (appointment_id),
    INDEX idx_billing_invoice (smartbill_invoice_id)
);
```

**Reguli:**
- O programare finalizată generează (sau actualizează) **un** `billing_item` cu `source='appointment'`.
- Pot exista și `source='manual'` (fără programare) și `source='contract'` (intervenție planificată din contract).
- O factură poate avea N `billing_items`. Un `billing_item` poate aparține unei singure facturi.
- Coloanele `billing_*` de pe `appointments` rămân pentru compatibilitate la migrare, dar **nu mai sunt sursă**. Le marcăm `@deprecated` în cod și le omitem din UI.

### Etapa B — Curățare statusuri

| Plan | Tabel.coloană | Set canonic |
|---|---|---|
| Operațional | `appointments.status` | `confirmata`, `finalizata`, `anulata`, plus existente. **Neschimbat.** |
| Financiar | `billing_items.status` | `to_review`, `to_invoice`, `invoiced`, `not_billable`, `cancelled`. |
| Document SmartBill | `smartbill_invoices.smartbill_status` | `draft`, `issued`, `error`, `cancelled`, `storno`. |
| Plată | derivat din suma încasărilor | `unpaid`, `partially_paid`, `paid`. Calculat dintr-o singură funcție. |
| ANAF | `smartbill_invoices.efactura_status` | Rămâne, dar ascuns din UI principal. |

**Acțiuni concrete:**
- Eliminăm `'nefacturabil'` din `calendar.php` și mapăm la `'not_billable'` pe `billing_items`.
- Eliminăm `UPDATE appointments SET billing_status = 'de_facturat' WHERE ...` la deschidere de pagină. După migrare, `appointments.billing_status` nu mai este sursă.
- Eliminăm și auto-sync-ul ascuns la `'facturata'` din `work_billing.php` — se rezolvă natural din `billing_items.status='invoiced'`.

### Etapa C — Migrare 1-la-1 → N-la-1

```sql
ALTER TABLE smartbill_invoices DROP INDEX uq_smartbill_appointment;
ALTER TABLE smartbill_invoices ADD INDEX idx_smartbill_appointment (appointment_id);
```

Apoi `appointment_id` din `smartbill_invoices` devine doar informativ (sau îl scoatem complet, păstrând legătura prin `billing_items.smartbill_invoice_id`). Mutăm relația „factură ⇔ programare" în `billing_items.smartbill_invoice_id`.

### Etapa D — Refactor SmartBill ca provider extern

Spargere `smartbill_lib.php` în două:
- `lib/billing/billing_lib.php` — logica internă CRM (creare poziții, marcare status, citire);
- `lib/providers/smartbill_provider.php` — strict apel API + maparea local ⇄ extern.

Sau, dacă vrei minim invaziv, păstrăm `smartbill_lib.php` dar îl spargem în secțiuni clare și introducem o funcție `pz_billing_issue_invoice()` care orchestrează: scrie local → cheamă provider → marchează rezultatul.

Apelurile din UI (`invoice.php`, `recurring_invoices.php`) folosesc doar `pz_billing_*`. Nu apelează direct `pz_smartbill_*`.

### Etapa E — UI

**Fără schimbări mari de design.** Sidebar-ul rămâne identic.

| Pagină | Schimbare propusă |
|---|---|
| `work_billing.php` (Lista lucrări) | Sursa devine `billing_items`. Filtre: `to_review` / `to_invoice` / `invoiced` / `not_billable`. Acțiuni: salvează valoare, marchează `not_billable` cu motiv obligatoriu, emite factură (pentru una sau mai multe poziții selectate, același client). |
| `invoices.php` | Neschimbat ca structură. |
| `invoice.php` | Acceptă lista de `billing_item_id`-uri ca input (în loc de un singur `appointment_id`). Pe „Emite", marchează toate ca `invoiced`. |
| `payment.php` / `payments.php` | Neschimbate. |
| `smartbill_settings.php` | Neschimbat. Eventual ascundem opțiunile e-Factura. |
| `efactura.php` | Lăsat în vie, dar scos din sidebar până când îl vrei activ. |

### Etapa F — E-Factura

Decizie ta. Trei variante:
1. **Ascundem din sidebar**, păstrăm fișierele și tabele (cel mai sigur).
2. **Dezactivăm cron-ul** și ștergem doar legăturile vizibile (mediu).
3. **Eliminăm complet** modul și tabele (cel mai agresiv, ireversibil — nu recomand acum).

Recomand 1.

---

## 7. Fluxul final, după curățare

```
PROGRAMARE FINALIZATĂ ──► BILLING_ITEM (status to_review / to_invoice / not_billable)
                                  │
                                  ▼
                          SMARTBILL_INVOICE (status draft → issued)
                                  │
                                  ▼
                          SMARTBILL_INVOICE_PAYMENTS (1..N)
                                  │
                                  ▼
                         Status plată derivat: unpaid / partial / paid
```

Reguli:
- PV-ul rămâne suport (linkat de `billing_item.pv_document_id`), niciodată declanșator.
- Contractul oferă tarif (citit la creare/actualizare `billing_item`), niciodată direct factură.
- Un `billing_item` cu `status='invoiced'` nu se mai poate edita.

---

## 8. Ce NU intră în plan (conform Pasului 8 din specificație)

- Reguli contabile complexe;
- e-Factura activă în UI principal;
- Stornare automată avansată (păstrăm storno-ul manual existent);
- Sincronizare bancară;
- Reconciliere automată;
- Mai mulți provideri simultani.

---

## 9. Ce livrez în pasul următor (după aprobare)

1. **SQL de migrare** — patch idempotent care:
   - creează `billing_items`;
   - populează `billing_items` din `appointments` existente (status `finalizata` + `billing_*`);
   - normalizează `'nefacturabil'` → `'not_billable'`;
   - elimină `UNIQUE` de pe `smartbill_invoices.appointment_id`.
2. **Fișiere noi:** `lib/billing/billing_lib.php` (funcții de listare, creare poziție, schimbare status, emitere prin provider).
3. **Modificări PHP punctuale:**
   - `work_billing.php` — schimb sursa de la `appointments` la `billing_items`. UI rămâne.
   - `calendar.php` — pe finalizare creează/actualizează `billing_item`; mapează `'nefacturabil'` → `'not_billable'`.
   - `invoice.php` — acceptă listă de poziții (`billing_item_ids[]`).
   - `smartbill_lib.php::pz_smartbill_issue_invoice` — primește lista de poziții, le marchează `invoiced`.
4. **Modificări sidebar:** eventual ascund „E-Factura" (la confirmarea ta).
5. **Test manual scriptat** pe cele 10 cazuri din Pasul 9 al specificației tale.

---

## 10. Întrebări pentru tine înainte să atac codul

1. Confirmi că `billing_items` este OK ca tabel intermediar (nu reușim altfel fără să spargem regula „nu lega direct programarea de factură")?
2. Pentru programări **deja facturate** (cu `smartbill_invoices.appointment_id` setat), creăm un `billing_item` corespondent cu `status='invoiced'` și legat la factura existentă, sau doar pe cele neîncă facturate?
3. Statusurile intermediare: păstrăm `to_review` (pas suplimentar de verificare) sau intrăm direct în `to_invoice` ca în comportamentul actual? Specificația ta menționează ambele.
4. E-Factura: ascundem din sidebar și păstrăm dezactivat, sau lăsăm vizibil?
5. Confirmi că NU mai atingem deloc `efactura*.php` / `anaf_efactura_lib.php` în acest sprint?
6. Confirmi că prefixurile de funcție rămân (`pz_billing_*` pentru CRM, `pz_smartbill_*` pentru provider extern)?

Răspund punct cu punct la întrebări și apoi propun SQL-ul de migrare. Nimic în cod până la confirmare.
