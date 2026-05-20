# Plan de implementare — flux financiar nou (după reset de date)

Data: 20 mai 2026  
Mod: doar plan. **Niciun fișier de cod nu a fost atins.**  
Premisă: resetezi datele operaționale + financiare; ștergi documentele de test din SmartBill; nu există e-Factura în SPV. Construim curat, fără migrare.

---

## 1. SQL curat (idempotent, fără date)

Se aplică o singură dată după reset. Conține:

1. CREATE pentru `billing_items` (dacă nu există).
2. INDEX-uri pe `billing_items`.
3. DROP UNIQUE de pe `smartbill_invoices.appointment_id`, dacă există.
4. Index simplu pe `smartbill_invoices.appointment_id` (rămâne coloana, devine informativă).
5. Nimic altceva — nu se șterg coloanele vechi, nu se inserează date, nu se șterg tabele.

```sql
-- 1) Tabel principal pentru pozițiile de facturat
CREATE TABLE IF NOT EXISTS billing_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NULL,
    pv_document_id INT NULL,
    client_id INT NOT NULL,
    client_location_id INT NULL,
    contract_id INT NULL,
    contract_service_id INT NULL,
    service_id INT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    work_date DATE NOT NULL,
    quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000,
    unit VARCHAR(30) NOT NULL DEFAULT 'buc',
    unit_price_net DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    vat_code VARCHAR(40) NOT NULL DEFAULT '21',
    total_net DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'RON',
    source VARCHAR(20) NOT NULL DEFAULT 'appointment',
        -- valori permise: 'appointment', 'manual', 'contract'
    status VARCHAR(20) NOT NULL DEFAULT 'to_review',
        -- valori permise: 'to_review', 'to_invoice', 'invoiced', 'not_billable', 'cancelled'
    not_billable_reason VARCHAR(255) NULL,
    smartbill_invoice_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_billing_items_client_status (client_id, status),
    INDEX idx_billing_items_status_date (status, work_date),
    INDEX idx_billing_items_appointment (appointment_id),
    INDEX idx_billing_items_invoice (smartbill_invoice_id),
    INDEX idx_billing_items_pv (pv_document_id),
    INDEX idx_billing_items_contract (contract_id, contract_service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Elimină constrângerea 1-la-1 din smartbill_invoices (dacă există)
SET @uq_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'smartbill_invoices'
      AND INDEX_NAME = 'uq_smartbill_appointment'
);
SET @sql := IF(@uq_exists > 0,
    'ALTER TABLE smartbill_invoices DROP INDEX uq_smartbill_appointment',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Adaugă index simplu pe smartbill_invoices.appointment_id (dacă nu există)
SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'smartbill_invoices'
      AND INDEX_NAME = 'idx_smartbill_appointment'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE smartbill_invoices ADD INDEX idx_smartbill_appointment (appointment_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**Notă:** SQL-ul rulează în siguranță pe orice DB (cu sau fără tabelele vechi). Va trăi în `lib/billing/billing_lib.php` ca `pz_billing_ensure_schema(PDO $pdo)` care e apelat o singură dată din pagina de billing, exact ca alte module ale tale (`pz_smartbill_ensure_schema`, `pz_flow_ensure_schema` etc.).

**Notă pe pași opționali (nu îi includ acum):** `appointments.billing_*` rămân **neatinse**. Eventual le scoatem într-un sprint viitor, când suntem siguri că nicio interogare nu mai citește din ele.

---

## 2. Fișiere — exact ce ating și ce nu

### 2.1 Fișiere NOI

| Fișier | Conținut |
|---|---|
| `lib/billing/billing_lib.php` | Toate funcțiile `pz_billing_*`. Ensure-schema, CRUD pe `billing_items`, validări, orchestrator emitere factură. Apelează `pz_smartbill_*` doar la final, pentru API. |

### 2.2 Fișiere MODIFICATE — modificări punctuale

| Fișier | Ce se schimbă | De ce |
|---|---|---|
| `calendar.php` | În handler-ul de finalizare programare (linia ~1389 `complete_appointment` și ~1410 `admin_finalize`): după `UPDATE appointments SET status='finalizata'`, se apelează `pz_billing_ensure_item_for_appointment($pdo, $appointmentId)`. **Plus**: în handlerul de salvare (linii 1684 și UPDATE 1730+), valoarea `'nefacturabil'` se mapează la `'not_billable'` doar pentru noile `billing_items`. Coloana `appointments.billing_status` rămâne, dar nu mai e citită de partea financiară. **Plus**: la salvare validăm regulile (valoare/contract/nefacturabil cu motiv), blocăm dacă nu sunt îndeplinite. | Programarea finalizată trebuie să genereze poziție. Validarea „valoare sau motiv nefacturabil" trebuie să blocheze. |
| `work_billing.php` | Înlocuire completă a sursei de date: în loc de `SELECT ... FROM appointments WHERE billing_status = ...`, citim din `billing_items` cu joinuri pe `clients`, `client_locations`, `documents` (pentru PV), `contracts`. **Elimin** complet blocul de „self-healing" (rândurile 134 și 145–157) — UPDATE-urile la deschidere de pagină dispar. **Redenumire eticheta paginii** „Lista lucrări" → „De facturat". Acțiunile POST (`save_amount`, `mark_billed`, `mark_billed_manual`, `mark_not_billable`, `clear_billing`) lucrează pe `billing_items.id`, nu pe `appointments.id`. UI-ul rămâne identic vizual. | Pagina devine sursa „De facturat" curată; opresc orice update automat la load. |
| `invoice.php` | Acceptă input `billing_item_ids[]` (array) în plus față de `appointment_id` (care devine informativ, nu mai e regulă unică). Validare: toate pozițiile trebuie să fie ale aceluiași client. La submit „Emite factură", apelează `pz_billing_issue_invoice($pdo, $itemIds, $opts)` în loc de a apela direct `pz_smartbill_issue_invoice`. Pe succes, redirectează către factura creată. | Decuplez UI-ul de provider. Permit multi-poziție. |
| `smartbill_lib.php` | **Două modificări minore:** (1) în `pz_smartbill_issue_invoice`, elimin blocul care marchează `appointments.billing_status='facturata'` (rândurile ~1865–1877). Marcajul se face acum în `pz_billing_mark_invoiced` pe `billing_items`. (2) Funcția `pz_smartbill_ensure_schema` rămâne neschimbată — nu mai punem `UNIQUE` pe `appointment_id` în CREATE (eliminăm `UNIQUE KEY uq_smartbill_appointment`). Restul fișierului — neatins. | Rolul SmartBill rămâne strict provider extern. |
| `invoices.php` | O singură modificare: înlocuiesc calculul `rowStatus` cu apel la `pz_billing_payment_status($invoice)`. Restul rămâne. | Status plată calculat într-un singur loc. |
| `app_ui.php` | În `render_sidebar`: redenumesc `interventii_facturare` → eticheta nouă „De facturat" (cheia rămâne identică). Elimin intrarea `efactura` din lista `$billingItems`. Nu modific structura generală a sidebar-ului. | Conform cerinței: rename + ascundere e-Factura. |

### 2.3 Fișiere care RĂMÂN NEATINSE (interzis să le modific)

- `efactura.php`
- `efactura_settings.php`
- `efactura_archive.php`
- `efactura_download.php`
- `efactura_oauth_callback.php`
- `cron_efactura_sync.php`
- `anaf_efactura_lib.php`
- `anaf_proxy.php`
- `payment.php` (rămâne neschimbat — doar verific că nu duplică logica de status)
- `payments.php` (idem)
- `recurring_invoices.php` (rămâne — folosește în continuare orchestratorul vechi `pz_smartbill_*`)
- `smartbill_settings.php` (curat deja)
- `smartbill_debug.php`
- `procese_verbale.php` (PV-ul nu declanșează nimic financiar)
- `contracts.php`, `contract_flow_lib.php` (tariful contract e doar input la `pz_billing_ensure_item_for_appointment`)
- `tasks.php`
- `dashboard.php` (în acest sprint nu actualizez query-urile; rămân pe coloanele vechi temporar — în sprintul următor le mut pe `billing_items` cu un mic patch)
- Toate stub-urile RO (`factura.php`, `facturi.php`, etc.)

---

## 3. Funcții din `lib/billing/billing_lib.php`

Semnături concrete:

```php
function pz_billing_ensure_schema(PDO $pdo): void;
    // Rulează SQL-ul de la §1. Idempotent.

function pz_billing_ensure_item_for_appointment(PDO $pdo, int $appointmentId): array;
    // Verifică dacă programarea are deja billing_item. Dacă nu, îl creează.
    // Preia valoare: prioritate (1) billing_amount manual, (2) contract_services.price.
    // Dacă bifa „nu se facturează" e setată: creează cu status='not_billable'.
    // Returnează ['ok' => bool, 'item_id' => int, 'error' => ?string].

function pz_billing_get_item_by_appointment(PDO $pdo, int $appointmentId): ?array;
    // Citește billing_item pentru o programare (sau null).

function pz_billing_mark_to_invoice(PDO $pdo, int $itemId): array;
    // Trece de la to_review la to_invoice. Nu permite din invoiced/cancelled.

function pz_billing_mark_not_billable(PDO $pdo, int $itemId, string $reason): array;
    // Motiv obligatoriu. Refuză dacă itemul e deja invoiced.

function pz_billing_mark_invoiced(PDO $pdo, array $itemIds, int $smartbillInvoiceId): void;
    // Bulk update status='invoiced' + smartbill_invoice_id. Apelat de orchestrator.

function pz_billing_validate_invoice_selection(PDO $pdo, array $itemIds): array;
    // Verifică: toate există, toate ale aceluiași client, niciuna invoiced/cancelled/not_billable.
    // Returnează ['ok' => bool, 'client_id' => int, 'items' => array, 'error' => ?string].

function pz_billing_calculate_totals(array $items, string $defaultVatCode = '21'): array;
    // Returnează ['net' => float, 'vat' => float, 'gross' => float, 'lines' => [...]]

function pz_billing_payment_status(array $smartbillInvoice): string;
    // Returnează: 'unpaid' | 'partially_paid' | 'paid'.
    // Sursa: smartbill_invoices.gross_amount + suma încasărilor.

function pz_billing_issue_invoice(PDO $pdo, array $billingItemIds, array $options = []): array;
    // Orchestrator complet:
    //   1. validate_invoice_selection
    //   2. calculate_totals
    //   3. INSERT în smartbill_invoices (status='draft', source_type='manual')
    //   4. INSERT în smartbill_invoice_items (câte o linie per billing_item)
    //   5. dacă SmartBill activ + opțiunea 'send_to_smartbill' = true:
    //         apel pz_smartbill_issue_invoice($pdo, $invoiceId)
    //      pe succes: mark_invoiced; pe eroare: rămâne draft, log eroare, păstrează posibilitatea de retry
    //   6. dacă SmartBill inactiv sau opțional: mark_invoiced direct (factura rămâne locală).
    // Returnează ['ok' => bool, 'invoice_id' => int, 'error' => ?string].
```

---

## 4. Flux end-to-end (după implementare)

```
[1] Tehnician/admin apasă „Finalizează" pe programare
    ↓
calendar.php — UPDATE appointments SET status='finalizata'
    ↓
calendar.php — pz_billing_ensure_item_for_appointment($pdo, $apptId)
    ↓
billing_items: 1 rând nou, status='to_review' (sau 'not_billable')
    ↓
[2] Pagina „De facturat" (work_billing.php)
    Listează din billing_items WHERE status IN ('to_review','to_invoice')
    Acțiuni: editează valoare, marchează to_invoice, marchează not_billable, emite factură
    ↓
[3] Selectez 1..N poziții ale aceluiași client → „Emite factură"
    ↓
invoice.php — primește billing_item_ids[]
    ↓
pz_billing_issue_invoice($pdo, $itemIds)
    ├── pz_billing_validate_invoice_selection — verifică același client
    ├── pz_billing_calculate_totals
    ├── INSERT smartbill_invoices (draft)
    ├── INSERT smartbill_invoice_items (N linii)
    ├── pz_smartbill_issue_invoice — apel API SmartBill
    │     ├── pe succes: smartbill_invoices.smartbill_status='issued' + serie + nr
    │     └── pe eroare: rămâne draft + log + retry posibil
    └── pz_billing_mark_invoiced(itemIds, invoiceId)
    ↓
[4] Pagina „Facturi" (invoices.php)
    Listează din smartbill_invoices. Status plată calculat cu pz_billing_payment_status.
    ↓
[5] Pagina „Plată" (payment.php)
    Adaug încasare → INSERT smartbill_invoice_payments
    ↓
[6] Calcul status plată
    pz_billing_payment_status returnează unpaid/partially_paid/paid
    pe baza sumei încasărilor vs gross_amount
```

---

## 5. Ce NU implementez în acest sprint

- Migrare istorică (NU). Pleci de la zero.
- Modificări la `efactura*.php` / `anaf_efactura_lib.php` (NU).
- Modificări la cronuri ANAF (NU).
- Ștergerea coloanelor `appointments.billing_*` (NU — rămân moarte temporar).
- Modificări la `payment.php` / `payments.php` (NU — doar verific că nu calculează status separat).
- Modificări la sistemul de recurente (NU — `recurring_invoices.php` rămâne ca este).
- Modificări la `dashboard.php` (NU — query-urile vechi rămân; pot să-ți zică cifre 0 după reset, până când ai facturi noi).
- Modificări la design / paletă / CSS global (NU).
- Tabel nou pentru setări SmartBill (NU — rămân în `app_settings`).

---

## 6. Ordine de execuție (după aprobarea ta)

1. Adaug `pz_billing_ensure_schema` în `lib/billing/billing_lib.php`.  
2. Adaug restul funcțiilor `pz_billing_*`.  
3. Modific `app_ui.php` — rename + ascund e-Factura din sidebar.  
4. Modific `smartbill_lib.php` — elimin UNIQUE din CREATE, elimin update-ul către `appointments` din `pz_smartbill_issue_invoice`.  
5. Modific `calendar.php` — apel `pz_billing_ensure_item_for_appointment` la finalizare + validare.  
6. Modific `work_billing.php` — sursă nouă, elimin self-healing.  
7. Modific `invoice.php` — acceptă `billing_item_ids[]`, apelează orchestratorul.  
8. Modific `invoices.php` — folosesc `pz_billing_payment_status`.  
9. Rulez SQL-ul pe baza ta de date după reset.  
10. Trec prin cele 25 de teste manuale din specificația ta.

---

## 7. Întrebări care îmi blochează scrisul de cod

1. Confirmi numele `lib/billing/billing_lib.php`? Sau preferi în rădăcina proiectului (ex. `billing_lib.php`) cum sunt celelalte lib-uri (`smartbill_lib.php`, `notification_lib.php`)?

2. La finalizarea programării în `calendar.php`, dacă valoarea lipsește și nu e bifat „nu se facturează", **blochez salvarea** sau **permit, dar creez `billing_item` cu status `to_review` și `total_net=0`** (utilizatorul completează ulterior din „De facturat")? Specificația ta zice „blochează", dar în UI poate ar fi mai practic să permit cu warning. Aleg ce zici tu.

3. Status implicit la creare din programare: `to_review` (cere verificare ulterioară) sau `to_invoice` (gata de facturat, dacă valoarea e completă)?

4. Pentru poziții cu `source='manual'` (intervenții fără programare în calendar): le permit să fie create din pagina „De facturat" (buton „Adaugă poziție") sau direct din `invoice.php` (item nou ad-hoc inline)? Mai simplu e doar din `invoice.php`.

5. Confirmi că `appointments.billing_*` rămân pe loc, dar nemodificate de codul nou? (Vor avea valori vechi rămase; coloanele nu te deranjează vizual nicăieri în UI?)

6. Sidebar: scot complet intrarea „E-Factura" sau o las cu badge gri „dezactivat"? Cea mai simplă variantă e să o scot complet (codul `efactura.php` rămâne accesibil prin URL direct dacă vrei să-l revizitezi manual).

7. Pentru recurente (`recurring_invoices.php`): rămân pe vechiul flux SmartBill (template direct, fără `billing_items`)? În specificația ta nu apare cerință explicită aici. Cel mai sigur — neatins.

Răspund la fiecare punct și pornesc.
