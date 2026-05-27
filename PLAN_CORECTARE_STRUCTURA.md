# Plan corectare structură billing — stadiu actual

**Data:** 27 mai 2026
**Status:** plan de revizuire, fără rulare SQL și fără modificări de cod.
**Premise:** `PLAN_BILLING_RESET.md` (20 mai 2026) a fost executat parțial. Acest document inventariază ce s-a făcut, ce a rămas și ce migrare lipsește.

---

## 1. Ce e deja făcut (verificat în cod, 27 mai 2026)

### 1.1 `lib/billing/billing_lib.php` — toate funcțiile planificate există

| Funcție | Linie | Status |
|---|---|---|
| `pz_billing_money` | 30 | ✅ |
| `pz_billing_status_label` | 46 | ✅ |
| `pz_billing_status_class` | 60 | ✅ |
| `pz_billing_ensure_schema` | 78 | ✅ (rulează idempotent la load) |
| `pz_billing_default_vat_code` | 170 | ✅ |
| `pz_billing_appointment_billing_data` | 187 | ✅ |
| `pz_billing_get_item_by_appointment` | 234 | ✅ |
| `pz_billing_ensure_item_for_appointment` | 252 | ✅ |
| `pz_billing_update_amount` | 419 | ✅ |
| `pz_billing_mark_to_invoice` | 467 | ✅ |
| `pz_billing_mark_to_review` | 506 | ✅ |
| `pz_billing_mark_not_billable` | 542 | ✅ |
| `pz_billing_mark_invoiced` | 588 | ✅ |
| `pz_billing_validate_invoice_selection` | 615 | ✅ |
| `pz_billing_calculate_totals` | 689 | ✅ |
| `pz_billing_collect_client_snapshot` | 757 | ✅ |
| `pz_billing_get_invoice_payment_summary` | 821 | ✅ |
| `pz_billing_payment_status` | 888 | ✅ |
| `pz_billing_issue_invoice` | 919 | ✅ |

### 1.2 Fișiere care folosesc deja fluxul nou (`pz_billing_*`)

- `calendar.php` (finalizare programare → creare poziție)
- `calendar_post_handler.php`
- `work_billing.php` (pagina „De facturat")
- `invoice.php` (acceptă `billing_item_ids[]`)
- `invoices.php` (citește status plată din `pz_billing_payment_status`)

### 1.3 Schema runtime

`pz_billing_ensure_schema()` rulează la fiecare load de pagină billing și creează idempotent `billing_items` + ajustează `smartbill_invoices`. **Funcționează**, dar schema trăia exclusiv în PHP, fără echivalent versionat în `/migrations/`.

---

## 2. Ce am corectat azi

### 2.1 Migrare standalone versionată

**Fișier nou:** `migrations/billing_items_schema.sql` (157 linii).

Extras complet schema `billing_items` + ajustările pe `smartbill_invoices` din `pz_billing_ensure_schema()` într-un fișier SQL idempotent. Avantaje:

1. **Vizibilitate:** schema apare în `/migrations/` lângă celelalte (alături de `migration_billing.sql`, `saas_emma_*.sql`). Cineva care explorează DB-ul găsește definiția aici.
2. **Reproductibilitate pe VPS Emma:** pe noul stack (PLAN_SAAS_EMMA.md §3.1), pipeline-ul de migrare rulează fișierele `/migrations/*.sql` în ordine. Schema billing intră firesc în sequence, fără să depindă de un load de pagină pentru a se materializa.
3. **Audit-friendly:** la onboarding sau review, planul SaaS Emma poate referenția acest fișier ca sursă canonică, nu o funcție PHP.

Runtime-ul `pz_billing_ensure_schema()` rămâne neschimbat — face același lucru, e double-coverage benignă. Pe pipeline VPS putem decide ulterior dacă îl scoatem din load (după ce confirmăm că migrarea s-a aplicat).

### 2.2 Coloana `revenue_category` adăugată în migrare

Schema runtime are deja un pas care apelează `pz_revenue_ensure_column()` pentru a adăuga coloana `revenue_category` pe `billing_items` și `smartbill_invoices`. Am inclus și acest pas în migrarea SQL standalone, pentru ca un environment proaspăt rulat doar din `/migrations/` să fie complet sincronizat cu runtime-ul.

---

## 3. Gap-uri rămase (nu rezolvate acum — discuție)

### 3.1 Fișiere care încă citesc din `appointments.billing_*`

Aceste fișiere nu au fost migrate în Faza inițială:

| Fișier | Ce citește din `appointments.billing_*` | Risc |
|---|---|---|
| `dashboard.php` | KPI-uri financiare (linia 256: `SUM(billing_amount) WHERE billing_status='de_facturat'`) | După reset financiar, dashboard-ul afișează cifre vechi rămase pe appointments — neactualizate de fluxul nou. Comentariul din cod (linia 250) recunoaște explicit: „Preferăm billing_items… Fallback la appointments.billing_status pentru instalări vechi". |
| `tasks.php` | Citește `tasks.billing_amount` (linia 137 + UI 514, 2472) — coloană separată de `appointments.billing_*`. Nu intră în fluxul de facturare nou. | Mic — taskurile nu produc poziții de facturat în fluxul actual. Coloana e folosită ca câmp informativ pe task, nu ca sursă financiară. |
| `task_recurrence.php` | Probabil propagă `billing_amount` la generarea task-urilor recurente. | De verificat — dacă rămâne câmp informativ pe task, OK. |

**Recomandare:** migrare `dashboard.php` la `billing_items` într-un sprint mic separat. Patch punctual pe blocul de KPI „Financiar" — înlocuire `SUM(appointments.billing_amount)` cu `SUM(billing_items.total_net)`. Restul (tasks, task_recurrence) — nu sunt în fluxul de facturare, deci nu blochează.

### 3.2 Coloanele „moarte" pe `appointments`

`appointments.billing_amount`, `billing_status`, `billing_note`, `billing_updated_at`, `billing_updated_by`, `billing_invoice_series`, `billing_invoice_number`, `billing_invoice_date` rămân pe loc, dar nu mai sunt scrise de fluxul nou. Conform `PLAN_BILLING_RESET.md §5`, nu le ștergem acum.

**Riscul:** confuzie viitoare cine citește schema. Mitigare: comentariu de DB sau view de readme.

**Recomandare:** las pe loc câteva sprinturi, apoi adăugăm o migrare `migrations/billing_appointments_columns_drop.sql` separată, după ce dashboard.php e migrat și avem confirmare 1 lună fără citiri din ele (poate cu un log scurt).

### 3.3 Lipsă FK pe `billing_items`

Tabela `billing_items` nu are FK-uri către `clients`, `contracts`, `smartbill_invoices`. Doar indecși.

**Motivare actuală:** simplitate + idempotență la `CREATE TABLE IF NOT EXISTS`. FK-urile depind de existența tabelelor referite, care nu e garantată la rularea schemei runtime.

**Recomandare:** acceptabil pentru v1 single-tenant. La etapa 4 SaaS Emma (când adăugăm `tenant_id NOT NULL + FK`), introducem și FK-urile către tabelele de referință, într-o migrare unică `migrations/saas_emma_04_not_null_tenant.sql` (cea menționată în `PLAN_SAAS_EMMA.md §5`).

### 3.4 `billing_items.tenant_id` — pregătit, dar nu rulat

`saas_emma_02_tenant_id_columns.sql` (linia 82) include explicit `CALL saas_emma_add_tenant_col('billing_items')` — adică planul SaaS Emma deja prevede coloana. **Nu trebuie acțiune separată acum**, doar confirmare că ordinea de rulare e:

1. `billing_items_schema.sql` (creează tabela)
2. `saas_emma_01_platform_tables.sql` (creează tabele platformă)
3. `saas_emma_02_tenant_id_columns.sql` (adaugă `tenant_id NULL` pe toate, inclusiv `billing_items`)
4. `saas_emma_03_backfill_europrest.sql` (TBD — backfill `tenant_id = 1`)
5. `saas_emma_04_not_null_tenant.sql` (TBD — NOT NULL + FK)

---

## 4. Ce NU am făcut intenționat

- **Nu am rulat nimic** pe DB. Doar fișier nou în `/migrations/`.
- **Nu am modificat** `pz_billing_ensure_schema()`. Rulează în continuare la load — backup runtime.
- **Nu am migrat `dashboard.php`** la `billing_items`. E separat, justifică un sprint propriu cu test.
- **Nu am scris `saas_emma_03_backfill`** și `saas_emma_04_not_null_tenant`. Erau pe lista TBD din SaaS Emma și depind de Faza 2 SaaS deja rulată (`saas_emma_02` aplicat) — încă neaplicată.
- **Nu am atins coloanele moarte** de pe `appointments`.

---

## 5. Comandă pentru testare (când vrei să rulezi)

```sh
# Backup mysqldump obligatoriu înainte
mysqldump -u USER -p DB > backup_$(date +%F_%H-%M).sql

# Verifică ce ar face migrarea (dry-run via INFORMATION_SCHEMA):
mysql -u USER -p DB -e "
  SELECT 'billing_items' AS tabel,
         IF(COUNT(*)>0,'există','LIPSEȘTE') AS stare
    FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_items'
  UNION ALL
  SELECT 'smartbill_invoices.uq_smartbill_appointment',
         IF(COUNT(*)>0,'există (va fi șters)','OK (deja eliminat)')
    FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'smartbill_invoices'
     AND INDEX_NAME = 'uq_smartbill_appointment'
  UNION ALL
  SELECT 'billing_items.revenue_category',
         IF(COUNT(*)>0,'există','LIPSEȘTE')
    FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'billing_items'
     AND COLUMN_NAME = 'revenue_category';
"

# Aplică migrarea
mysql -u USER -p DB < migrations/billing_items_schema.sql

# Verifică post-migrare
mysql -u USER -p DB -e "SHOW CREATE TABLE billing_items\\G"
mysql -u USER -p DB -e "SHOW INDEX FROM smartbill_invoices WHERE Key_name LIKE '%appointment%'"
```

---

## 6. Următorii pași sugerați (în ordinea recomandată)

1. **Acum:** review fișier `migrations/billing_items_schema.sql` + acest plan. Aprobare sau ajustare.
2. **Sprint mic:** migrare `dashboard.php` la `billing_items` (1-2 query-uri în blocul KPI Financiar). Patch < 50 linii.
3. **Înainte de SaaS Faza 3:** confirm că `billing_items_schema.sql` rulează curat pe staging. Apoi îl includem în pipeline-ul Emma.
4. **Eventual:** scriere `saas_emma_03_backfill_europrest.sql` cu lista completă de UPDATE-uri. Este task SaaS, separat de cel actual.

---

**Acest document completează `PLAN_BILLING_RESET.md` cu starea actuală 27 mai 2026 și lipsa migrării standalone (acum rezolvată). Modificările viitoare se loghează aici cu data.**
