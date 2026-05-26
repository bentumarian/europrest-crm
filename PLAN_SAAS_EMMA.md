# PLAN MIGRARE SAAS — EMMA

**Data:** 26 mai 2026
**Status:** decis cu Bentu, sursa de adevăr pentru transformarea în SaaS.
**Context:** EuroPrest/PestZone CRM → produs multi-tenant SaaS sub brandul Emma, găzduit pe VPS.

---

## 0. Sumar executiv

Aplicația curentă (PestZone CRM) este single-tenant: o singură firmă (EuroPrest) folosește o singură instalare PHP + o singură bază de date MySQL pe cPanel/Romarg. Vrem să transformăm produsul în **Emma**, un SaaS multi-tenant unde mai multe firme se pot înscrie, plătesc lunar și își gestionează datele complet separat.

**Decizii arhitecturale luate:**

| Decizie | Varianta aleasă | Motivare scurtă |
|---|---|---|
| Izolare date | Coloană `tenant_id` în tabele (single DB) | Operare simplă, migrație gradual, backup unitar. |
| Routing tenant | Subdomeniu wildcard `*.emma.ro` | Curat, scalabil, cookie scope corect. |
| Layout domenii | `emma.ro` = marketing/signup, `app.emma.ro` + `*.emma.ro` = aplicație | Separare clară marketing vs. produs. |
| Tenant inițial | EuroPrest devine `tenant_id=1` pe `europrest.emma.ro` | Continuitate fără migrare separată. |
| Pricing v1 | Free trial 14 zile + plan unic lunar | Minim viabil, fără feature flags complexe. |
| Hosting | VPS Linux (Hetzner/DigitalOcean/Contabo) | Control complet, costuri rezonabile, scalare facilă. |
| Deploy | "Când e gata" — fără termen fix | Lucrăm sigur, testăm bine, nu forțăm ritm. |

**Principiu director:** facem schimbările în pași mici, fiecare pas testabil independent. Nu rescriem aplicația. Adăugăm un strat de tenant peste structura existentă, fără să spargem nimic din ce funcționează deja la EuroPrest.

---

## 1. Starea actuală (baseline)

### 1.1 Stack tehnic
- PHP 8.x (compatibil 7.4+) pe Apache + cPanel/Romarg.
- MySQL/MariaDB cu `utf8mb4_unicode_ci`.
- Composer pentru `mpdf`, `dompdf`, `phpmailer`, `sendgrid`.
- Deploy via webhook GitHub → `deploy.php` → `git pull` + `cp` în `~/app.pestzone.ro/`.

### 1.2 Auth
- Tabela `users`: admini birou (login email+parolă).
- Tabela `team_members`: tehnicieni (login separat).
- Sesiune PHP cu cookie `PESTZONE_SESSION`.
- Helperi: `is_logged_in()`, `require_login()`, `is_admin()`, `current_user_id()`.

### 1.3 Tabele cu date de business (incomplet, top 25)
Sunt aprox. 50+ tabele. Cele care **TREBUIE** să primească `tenant_id`:

```
clients, client_locations, client_contacts
contracts, contract_services, contract_locations
appointments, appointment_teams, appointment_history
tasks, task_recurrence, task_attachments
team_members, team_member_zones
documents, document_designs, document_templates, document_series
billing_items, smartbill_invoices, smartbill_invoice_items, smartbill_invoice_payments
smartbill_invoice_logs, smartbill_recurring_invoices, smartbill_supplier_invoices
smartbill_settings, anaf_oauth_tokens, anaf_efactura_logs
products, stock_movements, stock_receipts, stock_inventory
services, product_categories
sms_templates, email_templates, sms_log, email_log
reminders, review_requests, feedback
addenda, avize_sanitare
app_settings (key-value, dar key-ul va prefixa tenantul: `tenant.{id}.smartbill.api_key`)
notifications
```

Tabele care **NU** primesc `tenant_id` (sunt globale platformă):
```
users (devine cont global, legat la tenant prin user_tenant_membership)
tenants (nou)
tenant_plans (nou)
tenant_subscriptions (nou)
platform_settings (nou — config Emma global)
```

### 1.4 Branding actual
- Logo: `assets/logo Europrest_2024.jpg` + `assets/MONOGRAMA PEST ZONE_*.png`
- String-uri "PestZone" / "EuroPrest" hard-codate în: `config.php`, `deploy.php`, `anaf_proxy.php`, comentarii la 30+ fișiere.
- Domeniu hard-codat: `app.pestzone.ro` în `anaf_proxy.php` (CORS allowed origin), `deploy.php`, `lib/anaf_efactura_lib.php` (probabil pentru OAuth callback).
- Email default: `office@pestzone.ro` (în `config.php`).

### 1.5 Deploy actual
- Webhook GitHub push pe `main` → `deploy.php` rulează `git pull` + copy în document root.
- Cron-uri cPanel (vezi `README.md` secțiunea Cron-uri): 6 cron-uri cu chei secrete.
- SSL: gestionat de cPanel cu Let's Encrypt automat.
- Backup: manual, mysqldump + zip files.

---

## 2. Arhitectura SaaS Emma — viziune

### 2.1 Diagrama domeniilor

```
emma.ro                       → landing/marketing/signup (Nginx serve static + form signup)
app.emma.ro                   → login global, redirect spre tenant după auth
*.emma.ro (wildcard)          → fiecare subdomeniu = un tenant
   ├─ europrest.emma.ro       → primul tenant (DB tenant_id=1)
   ├─ firma2.emma.ro          → tenant 2
   └─ firma3.emma.ro          → tenant 3
admin.emma.ro                 → super-admin Emma (vede toți tenants, billing platformă)
api.emma.ro (opțional v2)     → API public REST dacă va fi nevoie
```

### 2.2 Diagrama fluxului request

```
Request → emma.ro          → Landing page (Nginx static + 1 PHP pentru form signup)
Request → app.emma.ro      → Aplicație: login global, după login redirect la {tenant}.emma.ro
Request → {sub}.emma.ro    → Aplicație: identifică tenant din subdomeniu, scope automat în query-uri
Request → admin.emma.ro    → Aplicație: secțiune super-admin, vede toți tenants
```

### 2.3 Identificarea tenantului per request

Helper-ul `pz_current_tenant()` în `lib/tenant_lib.php` extrage subdomeniul din `$_SERVER['HTTP_HOST']`, caută în tabela `tenants` și returnează `tenant_id` + obiectul tenant. Apoi:

- Toate query-urile de business primesc `WHERE tenant_id = ?` în prepared statement.
- Sesiunea verifică că `$_SESSION['tenant_id']` corespunde subdomeniului — altfel kick to login.
- Funcția `pz_tenant_scope(string $alias = '')` returnează clauza SQL gata de inserat.

### 2.4 Roluri & permisiuni

Roluri existente la nivel tenant (păstrate):
- `admin` — birou, vede tot
- `team` — tehnician, vede doar programările proprii

Roluri noi la nivel platformă:
- `platform_super_admin` — Bentu, vede toți tenants, billing platformă, suspendare conturi
- `platform_support` (opțional v2) — suport tehnic, acces read-only la tenants

### 2.5 Onboarding tenant nou (signup public)

Flow pe `emma.ro/signup`:
1. Formular: nume firmă, email admin, parolă, subdomeniu dorit.
2. Validare: subdomeniu disponibil, email unic, CUI opțional (verificare ANAF).
3. Creare:
   - `INSERT INTO tenants (...) VALUES (...)` cu `status='trial'`, `trial_ends_at = NOW() + 14 days`.
   - `INSERT INTO users (...)` cu rolul `admin` pe tenant.
   - Apel `provision_tenant_defaults($tenantId)`: copie `email_templates`, `sms_templates`, `services` din template default.
4. Trimitere email welcome cu link-ul `https://{subdomeniu}.emma.ro`.
5. Redirect automat la `{subdomeniu}.emma.ro/login.php` cu sesiunea creată.

### 2.6 Pricing & billing platformă

V1 — minim viabil:
- 1 plan: **Emma Pro** — 49 RON / lună / firmă (preț de discutat).
- Trial 14 zile fără card.
- După trial: tenantul primește email-uri reminder (zi -3, -1, +0, +3, +7). La +7 zile fără plată, tenant devine `status='suspended'` — login blocat, datele rămân 30 de zile, apoi soft delete.
- Plată recurentă: Stripe Subscriptions sau Mobilpay (alegere ulterioară, nu blocant pentru deploy).

V2 — extensii (nu acum):
- 3 tier-uri cu limite (utilizatori, programări/lună, e-Factura inclusă).
- Add-on-uri (SMS pachet 1000, e-Factura nelimitat).
- Anual cu discount 15%.

---

## 3. Migrare DB — plan etapizat

### 3.1 Etape

| # | Etapă | Mod | Reversibil? | Vizibilitate user |
|---|---|---|---|---|
| 1 | Creare tabele platformă (`tenants`, `tenant_plans`, `tenant_subscriptions`, `platform_settings`, `user_tenant_membership`) | `CREATE TABLE IF NOT EXISTS` (idempotent) | Da (DROP) | Zero |
| 2 | Adăugare coloană `tenant_id` în tabelele de business, NULL by default | `ALTER TABLE ... ADD COLUMN tenant_id INT NULL AFTER id` | Da (DROP COLUMN) | Zero |
| 3 | Adăugare index `(tenant_id, ...)` pe coloanele cheie | `ALTER TABLE ... ADD INDEX` | Da | Zero |
| 4 | Backfill `tenant_id = 1` pe toate rândurile existente (EuroPrest) | `UPDATE ... SET tenant_id = 1` | Da (UPDATE NULL) | Zero |
| 5 | Trecere `tenant_id` la `NOT NULL` + foreign key către `tenants(id)` | `ALTER TABLE` | Mai greu (DROP FK) | Zero |
| 6 | Activare scope în cod (helper `pz_tenant_scope()` + audit query-uri) | Cod PHP | Da (revert commit) | Mare |
| 7 | Migrare `app_settings` cheile cu prefix `tenant.{id}.` | UPDATE + INSERT | Manual | Zero |

### 3.2 Reguli stricte de migrare

- **Niciun ALTER nu rulează pe producție fără backup mysqldump complet imediat înainte.**
- **Fiecare ALTER e idempotent**: verificare `INFORMATION_SCHEMA.COLUMNS` înainte de adăugare.
- **Coloanele se adaugă, niciodată nu se șterg** (chiar dacă rămân moarte).
- **Backfill-ul** rulează doar după ce am verificat că toate rândurile existente sunt ale EuroPrest.

### 3.3 Lista completă a tabelelor care primesc `tenant_id`

Toate tabelele din §1.3 secțiunea "TREBUIE". Lista detaliată cu coloanele și indexurile exacte stă în fișierul de migrare (vezi §5 — Schiță SQL).

---

## 4. Rebranding PestZone → Emma

### 4.1 Strategie

**Trei niveluri de schimbare**, de la sigur la sensibil:

1. **Strat configurabil** (sigur, fără atingere logică): introducem constante/setting-uri `APP_NAME`, `APP_DOMAIN`, `APP_SUPPORT_EMAIL`. Toate locurile care afișează "PestZone" / "EuroPrest" în UI le citesc de aici.
2. **Strat de asset-uri** (sigur, vizibil): logo nou Emma în `assets/brand-emma-*.png/svg`. Funcția `app_brand_logo()` deja are fallback inteligent — pune fișiere noi cu nume care conțin "emma" sau "brand" și sunt detectate automat.
3. **Strat hard-coded** (sensibil, ultimul): string-urile "PestZone" / "EuroPrest" din comentarii, error_log, user-agent ANAF, CORS origin. Înlocuim doar după ce nivelele 1 și 2 sunt stabile.

### 4.2 Locuri unde apare "PestZone" / "EuroPrest" și ce facem

| Locație | Tip | Acțiune |
|---|---|---|
| `config.php` (comentarii, error_log prefix) | Backend | Înlocuire "PestZone" → "Emma" în comentarii și `error_log()`. Zero impact funcțional. |
| `config.php` (`sendgrid_from_email`, `sendgrid_from_name`) | Default value | Default rămâne `office@pestzone.ro` în cod, dar pe tenant nou se citește din `tenants.support_email`. Va fi suprascris în `config.local.php` la deploy Emma. |
| `app_brand.php` | Asset loader | Adăugăm parametru `APP_NAME` în comentarii; logica de fallback acceptă deja orice `brand*.png`. |
| `app_topbar.php`, `app_sidebar.php` | UI vizibil | Înlocuim text vizibil "PestZone" → folosim `e(APP_NAME)`. |
| `login.php` | UI vizibil | Title page, header, footer → `APP_NAME`. |
| `forgot_password.php`, `reset_password.php` | UI vizibil | La fel. |
| Email templates DB (`email_templates`) | Date | NU rescriem. Tenantul existent EuroPrest păstrează template-urile lui. Tenantul nou primește template-uri default Emma la signup. |
| `anaf_proxy.php` (CORS `ALLOWED_ORIGIN`) | Backend | Devine configurabil: citește din `platform_settings.app_origin`. |
| `deploy.php` | Backend | Devine irelevant — pe VPS folosim alt mecanism deploy (vezi §6). |
| `lib/anaf_efactura_lib.php` (OAuth callback URL) | Backend | Devine configurabil din `tenant_settings.anaf_oauth_redirect`. |
| `README.md`, `AGENTS.md` | Docs | Update treptat — nu blocant. |
| User-Agent ANAF `'PestZone-ANAF-Proxy/1.0'` | HTTP header | Devine `'Emma-ANAF-Proxy/1.0'` la deploy. |

### 4.3 Logo nou Emma

Plasezi în `assets/`:
- `brand-emma.svg` (varianta principală, color, pentru sidebar dark)
- `brand-emma-white.svg` (varianta albă, pentru mobile header pe fundal albastru)
- `favicon-emma.ico`, `apple-touch-icon-emma.png` (din pachet favicon)

Funcția `app_brand_logo()` din `app_brand.php` îi va găsi automat prin fallback-ul existent (line 60-122). Nu modificăm logica.

### 4.4 Identitatea vizuală

DESIGN_LINE.md rămâne valabil — paleta Stripe-inspired (29 tokens `--pz-*`) NU se schimbă. Logo-ul Emma trebuie să arate bine peste această paletă (albastru `#2563EB` ca primar).

**Decizii vizuale pe care le am nevoie de la Bentu:**
1. Logo Emma — ai deja un design? Vrei să-l facem împreună? Tipul caracteristic (cuvânt-marcă "Emma" + monogramă "E"? Sau doar wordmark?)
2. Slogan / tagline pentru landing emma.ro (opțional)
3. Culoarea primară Emma — păstrăm albastrul `#2563EB` existent (recomandat — coerent cu DESIGN_LINE) sau schimbăm?

---

## 5. Schiță SQL — etapa 1 + 2 (CREATE + ALTER)

**Fișier nou:** `migrations/migration_saas_emma_01_platform_tables.sql`

```sql
-- ============================================================
-- Migration SaaS Emma — etapa 1: tabele platformă
-- Idempotent. Nu modifică tabele existente.
-- ============================================================

-- 1. Tenants (firme client SaaS)
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) NOT NULL,                    -- subdomeniu: 'europrest', 'firma2'
    legal_name VARCHAR(200) NOT NULL,             -- 'EuroPrest SRL'
    display_name VARCHAR(200) NOT NULL,           -- 'EuroPrest' (afișat în UI)
    cui VARCHAR(20) NULL,                         -- pentru integrare ANAF tenant
    status VARCHAR(20) NOT NULL DEFAULT 'trial',  -- trial, active, past_due, suspended, cancelled
    plan_code VARCHAR(40) NOT NULL DEFAULT 'emma_pro',
    trial_ends_at TIMESTAMP NULL,
    subscription_started_at TIMESTAMP NULL,
    subscription_ends_at TIMESTAMP NULL,
    support_email VARCHAR(160) NULL,
    support_phone VARCHAR(40) NULL,
    primary_color VARCHAR(20) NULL,               -- white-label v2 (NU folosit acum)
    logo_path VARCHAR(255) NULL,                  -- white-label v2 (NU folosit acum)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenants_slug (slug),
    INDEX idx_tenants_status (status),
    INDEX idx_tenants_plan (plan_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Membership user-tenant (un user poate aparține mai multor tenants)
CREATE TABLE IF NOT EXISTS user_tenant_membership (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tenant_id INT NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'admin',    -- admin / team / viewer
    is_default TINYINT(1) NOT NULL DEFAULT 0,     -- la login global redirect la asta
    invited_by INT NULL,
    invited_at TIMESTAMP NULL,
    accepted_at TIMESTAMP NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_membership (user_id, tenant_id),
    INDEX idx_membership_user (user_id, active),
    INDEX idx_membership_tenant (tenant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Planuri SaaS
CREATE TABLE IF NOT EXISTS tenant_plans (
    code VARCHAR(40) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    monthly_price_ron DECIMAL(10,2) NOT NULL DEFAULT 0,
    yearly_price_ron DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_users INT NULL,                            -- NULL = nelimitat
    max_appointments_per_month INT NULL,
    includes_efactura TINYINT(1) NOT NULL DEFAULT 1,
    includes_sms TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed plan unic v1
INSERT IGNORE INTO tenant_plans (code, name, monthly_price_ron, yearly_price_ron, is_active)
VALUES ('emma_pro', 'Emma Pro', 49.00, 499.00, 1);

-- 4. Subscriptions (istoric facturare per tenant)
CREATE TABLE IF NOT EXISTS tenant_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    plan_code VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active', -- active, past_due, cancelled
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    current_period_start TIMESTAMP NULL,
    current_period_end TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    stripe_subscription_id VARCHAR(100) NULL,     -- pentru integrare Stripe v1.5
    INDEX idx_subs_tenant (tenant_id, status),
    INDEX idx_subs_period_end (current_period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Setări platformă (Emma global, NU per tenant)
CREATE TABLE IF NOT EXISTS platform_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed valori platformă inițiale
INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
    ('app.name', 'Emma'),
    ('app.domain', 'emma.ro'),
    ('app.support_email', 'office@emma.ro'),
    ('app.signup_enabled', '1'),
    ('app.trial_days', '14');

-- 6. EuroPrest = tenant inițial
INSERT IGNORE INTO tenants (id, slug, legal_name, display_name, status, plan_code, support_email)
VALUES (1, 'europrest', 'EuroPrest SRL', 'EuroPrest', 'active', 'emma_pro', 'office@pestzone.ro');
```

**Fișier nou:** `migrations/migration_saas_emma_02_tenant_id_columns.sql`

Pentru fiecare tabel de business (vezi lista din §1.3), un bloc idempotent:

```sql
-- Pattern repetat pentru fiecare tabel
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clients'
      AND COLUMN_NAME = 'tenant_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE clients ADD COLUMN tenant_id INT NULL AFTER id, ADD INDEX idx_clients_tenant (tenant_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**Fișier nou:** `migrations/migration_saas_emma_03_backfill_europrest.sql`

```sql
-- Setează tenant_id=1 (EuroPrest) pe TOATE înregistrările existente
-- Rulează DOAR după ce ai confirmat că DB-ul curent are doar date EuroPrest!
UPDATE clients SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE client_locations SET tenant_id = 1 WHERE tenant_id IS NULL;
-- ... etc pentru toate tabelele din lista
```

**Fișier nou:** `migrations/migration_saas_emma_04_not_null_tenant.sql`

```sql
-- DUPĂ backfill, trecere la NOT NULL + foreign key
ALTER TABLE clients MODIFY COLUMN tenant_id INT NOT NULL;
ALTER TABLE clients ADD CONSTRAINT fk_clients_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id);
-- ... etc
```

**IMPORTANT:** Niciuna dintre aceste migrații NU se rulează încă. Sunt schițe în `/migrations/` pentru review. Le rulăm doar când: (a) Bentu aprobă SQL-ul, (b) avem backup proaspăt mysqldump, (c) suntem pe mediu de staging sau am decis că riscul e acceptabil pe producție.

---

## 6. Schiță helper PHP — `lib/tenant_lib.php`

```php
<?php
/*
|--------------------------------------------------------------------------
| lib/tenant_lib.php — multi-tenancy helpers Emma
|--------------------------------------------------------------------------
| Identifică tenantul curent din subdomeniu și oferă scope automat
| pentru toate query-urile de business.
|--------------------------------------------------------------------------
*/

function pz_extract_subdomain(string $host): ?string {
    $host = strtolower(trim($host));
    // Strip port
    $host = preg_replace('/:\d+$/', '', $host);
    // emma.ro, www.emma.ro → null (landing)
    if ($host === 'emma.ro' || $host === 'www.emma.ro') return null;
    // app.emma.ro, admin.emma.ro → tratat separat
    if (in_array($host, ['app.emma.ro', 'admin.emma.ro', 'api.emma.ro'], true)) {
        return $host; // marker special
    }
    // {slug}.emma.ro → slug
    if (preg_match('/^([a-z0-9][a-z0-9-]{0,58}[a-z0-9])\.emma\.ro$/', $host, $m)) {
        return $m[1];
    }
    return null;
}

function pz_current_tenant(PDO $pdo): ?array {
    static $cache = null;
    if ($cache !== null) return $cache ?: null;

    $sub = pz_extract_subdomain($_SERVER['HTTP_HOST'] ?? '');
    if (!$sub || in_array($sub, ['app.emma.ro', 'admin.emma.ro', 'api.emma.ro'], true)) {
        $cache = false; return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? LIMIT 1");
    $stmt->execute([$sub]);
    $row = $stmt->fetch();
    if (!$row) { $cache = false; return null; }

    $cache = $row;
    return $row;
}

function pz_current_tenant_id(PDO $pdo): ?int {
    $t = pz_current_tenant($pdo);
    return $t ? (int)$t['id'] : null;
}

function pz_require_tenant(PDO $pdo): array {
    $t = pz_current_tenant($pdo);
    if (!$t) {
        http_response_code(404);
        die('Subdomeniu invalid sau inactiv.');
    }
    if (!in_array($t['status'], ['trial', 'active', 'past_due'], true)) {
        http_response_code(403);
        die('Acest cont este suspendat. Contactează ' .
            htmlspecialchars(pz_platform_setting('app.support_email') ?: 'office@emma.ro') . '.');
    }
    return $t;
}

function pz_platform_setting(string $key, ?string $default = null): ?string {
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = $pdo->query("SELECT setting_key, setting_value FROM platform_settings")->fetchAll();
            foreach ($rows as $r) $cache[$r['setting_key']] = $r['setting_value'];
        } catch (Throwable $e) { /* tabel încă nu există */ }
    }
    return $cache[$key] ?? $default;
}
```

**Punctul de integrare:** în `config.php`, după `$pdo` și înainte de `require_login()`, adăugăm:

```php
require_once __DIR__ . '/lib/tenant_lib.php';
$current_tenant = pz_require_tenant($pdo); // throws 404 dacă subdomeniul nu există
define('CURRENT_TENANT_ID', (int)$current_tenant['id']);
```

**Apoi**, în query-uri, peste tot:

```php
// ÎNAINTE:
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);

// DUPĂ:
$stmt = $pdo->prepare("SELECT * FROM clients WHERE tenant_id = ? AND id = ?");
$stmt->execute([CURRENT_TENANT_ID, $id]);
```

**Audit-ul query-urilor** este partea cea mai lungă. ~200+ query-uri în ~100 fișiere. Strategie:
1. Grep `FROM clients`, `FROM contracts`, etc. — listă completă.
2. Pentru fiecare query: adaugă filtru tenant.
3. Test smoke după fiecare modul (clienți, contracte, billing).

---

## 7. Deploy pe VPS

### 7.1 Recomandare VPS (provider + specs)

**Recomandare principală: Hetzner Cloud (Germania, Finlanda)**
- **Specs minime (start, 5-10 tenants):** CX22 — 2 vCPU AMD, 4 GB RAM, 40 GB SSD NVMe, 20 TB trafic — **€4.51/lună** (~22 RON).
- **Recomandat (10-50 tenants, comfort):** CPX21 — 3 vCPU AMD, 4 GB RAM, 80 GB SSD — **€7.55/lună** (~37 RON).
- **Sweet spot (50-200 tenants):** CPX31 — 4 vCPU, 8 GB RAM, 160 GB SSD — **€13.10/lună** (~65 RON).
- Backup automat: +20% pe lună. Snapshots: gratuit.
- Datacenter: **Nuremberg** sau **Helsinki** (latency RO ~30-50ms — acceptabil).

**Alternative:**
| Provider | Echivalent | Preț/lună | Observații |
|---|---|---|---|
| DigitalOcean | Premium AMD 2vCPU/4GB/80GB | $24 (~110 RON) | Mai scump, mai recunoscut internațional. |
| Contabo | VPS S 4 vCPU / 8 GB / 200 GB SSD | €6.99 (~35 RON) | Cel mai ieftin, dar overcommit cunoscut. OK pentru start. |
| Romarg | VPS-2 (2 vCPU / 4 GB / 80 GB) | ~50 RON | Sediu local, suport limba română, dar mai scump per spec. |
| Hostico | VPS Linux 4 GB | ~40 RON | Provider RO, comparabil cu Romarg. |

**Recomandarea mea:** **Hetzner CPX21** (€7.55/lună). E cel mai bun raport preț/performanță și ai o margine sănătoasă pentru creștere. Configurat corect, duce 50+ tenants activi cu trafic normal CRM (sub 1000 request-uri/zi/tenant).

### 7.2 OS + Stack

- **OS:** Ubuntu 24.04 LTS (suport până 2029, totul recent).
- **Web server:** Nginx 1.24+ (reverse proxy + serve static).
- **PHP:** PHP 8.3 cu PHP-FPM (pool dedicat).
- **Database:** MariaDB 11.x (drop-in MySQL, mai bun pe utf8mb4).
- **SSL:** Certbot + Let's Encrypt cu wildcard `*.emma.ro` via DNS challenge (necesită API DNS provider).
- **Firewall:** ufw (open 22, 80, 443 doar; 22 doar de la IP-ul tău).
- **Securitate:** fail2ban, SSH key-only (parola disabled), root login disabled, user dedicat `emma`.
- **Backup:** snapshot Hetzner zilnic + cron mysqldump zilnic în `/var/backups/emma/db/` + upload Backblaze B2 (gratuit pentru primii 10 GB).

### 7.3 Structura folder pe VPS

```
/srv/emma/
├── repo/                          ← git clone, doar pull, NIMIC nu se modifică direct aici
│   └── (toate fișierele PHP)
├── current/                       ← symlink către releases/{timestamp}
├── releases/
│   ├── 2026-05-27_14-30-00/      ← deploy cu data
│   ├── 2026-05-28_09-15-00/
│   └── ... (păstrăm ultimele 5)
├── shared/
│   ├── config.local.php          ← secrete, NU în git
│   ├── uploads/                  ← fișiere user
│   ├── storage/                  ← cache, generate
│   └── tmp/
└── logs/
    ├── nginx/
    └── php-fpm/

/var/backups/emma/db/             ← dumpuri MySQL
/var/backups/emma/files/          ← snapshots uploads
```

### 7.4 Nginx config (schiță)

```nginx
# /etc/nginx/sites-available/emma.ro
server {
    listen 80;
    listen [::]:80;
    server_name emma.ro www.emma.ro;
    return 301 https://emma.ro$request_uri;
}

server {
    listen 443 ssl http2;
    server_name emma.ro www.emma.ro;
    root /srv/emma/landing;        # landing static
    index index.html;
    ssl_certificate /etc/letsencrypt/live/emma.ro/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/emma.ro/privkey.pem;
    # ... HSTS, security headers
}

# Vhost wildcard pentru aplicație (toate subdomeniile)
server {
    listen 443 ssl http2;
    server_name *.emma.ro;
    root /srv/emma/current;        # symlink la release activ
    index index.php;
    ssl_certificate /etc/letsencrypt/live/emma.ro/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/emma.ro/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm-emma.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(ht|git|env) { deny all; }
}
```

### 7.5 Deploy script (înlocuiește `deploy.php`)

Pe VPS: `/srv/emma/bin/deploy.sh` — rulat manual via SSH sau via webhook GitHub:

```bash
#!/bin/bash
set -e
cd /srv/emma/repo
git pull origin main
TS=$(date +%Y-%m-%d_%H-%M-%S)
mkdir -p /srv/emma/releases/$TS
cp -R /srv/emma/repo/. /srv/emma/releases/$TS/
ln -sf /srv/emma/shared/config.local.php /srv/emma/releases/$TS/config.local.php
ln -sf /srv/emma/shared/uploads /srv/emma/releases/$TS/uploads
ln -sf /srv/emma/shared/storage /srv/emma/releases/$TS/storage
ln -sfn /srv/emma/releases/$TS /srv/emma/current
sudo systemctl reload php8.3-fpm
# păstrează doar ultimele 5 release-uri
cd /srv/emma/releases && ls -t | tail -n +6 | xargs -r rm -rf
```

### 7.6 Cron-uri pe VPS

Migrarea cron-urilor din `README.md` în `/etc/cron.d/emma`:

```cron
SHELL=/bin/bash
MAILTO=bentumarian@gmail.com

0 6,14 * * * emma /usr/bin/php /srv/emma/current/cron_efactura_sync.php
0 3 * * *   emma /usr/bin/php /srv/emma/current/cron_smartbill_recurring.php
0 7 * * *   emma /usr/bin/curl -s 'https://app.emma.ro/cron_reminder_emails.php?key=SECRET'
0 7 * * *   emma /usr/bin/php /srv/emma/current/cron_sms_reminders.php SECRET
0 8 * * *   emma /usr/bin/php /srv/emma/current/cron_task_expiry_7_sms.php SECRET
0 19 * * *  emma /usr/bin/php /srv/emma/current/cron_review_requests.php

# Backup zilnic
0 2 * * *   root /srv/emma/bin/backup_db.sh
```

**Important:** cron-urile rulează acum **per-tenant** sau **multi-tenant**? Decizie de design:
- **Opțiunea A** (recomandat): cron-ul iterează prin `SELECT id FROM tenants WHERE status IN ('trial','active')` și apelează handler-ul pentru fiecare. Un singur cron, scalează cu N tenants.
- **Opțiunea B**: cron per-tenant pe subdomeniu. Mai complex, mai puțin scalabil.

### 7.7 SSL wildcard `*.emma.ro` — pasul cel mai delicat

Let's Encrypt cere DNS-01 challenge pentru wildcard. Asta înseamnă că Certbot trebuie să poată crea TXT record-uri pe DNS-ul `emma.ro`.

**Provider DNS decis:** Cloudflare (gratuit, API stabil). Domeniul rămâne înregistrat la **RoTLD** (registrar), doar nameserver-ele se schimbă.

**Pașii concreți (de făcut înainte de deploy):**
1. Creezi cont gratuit pe `cloudflare.com`.
2. "Add a Site" → introduci `emma.ro` → planul Free.
3. Cloudflare scanează DNS-ul curent (poate fi gol dacă domeniul e nou). Confirmă.
4. Cloudflare îți afișează 2 nameservere personale (ex: `xena.ns.cloudflare.com`, `alex.ns.cloudflare.com`). Notează-le.
5. Te loghezi în panoul **RoTLD** (rotld.ro) → secțiunea ta de domenii → `emma.ro` → "Modifică DNS" sau "Servere de nume".
6. Înlocuiești nameserverele curente cu cele de la Cloudflare. Salvezi.
7. Aștepți propagarea (4-24 ore — RoTLD propagă mai lent decât registrar-ii internaționali).
8. Verifică propagare: `dig NS emma.ro +short` trebuie să returneze cele 2 nameservere Cloudflare.
9. Înapoi în Cloudflare → adaugi A records:
   - `@` (sau `emma.ro`) → IP_VPS
   - `*` (wildcard) → IP_VPS
   - `www` → IP_VPS (CNAME spre @ sau A separat)
10. Generezi API token Cloudflare cu permisiune **DNS Edit** pe zona `emma.ro` (Account → API Tokens → Create Token → template "Edit zone DNS"). Salvezi token-ul pe VPS în `/etc/letsencrypt/cloudflare.ini` cu chmod 600.
11. Pe VPS: instalezi `python3-certbot-dns-cloudflare`, apoi:
    `certbot certonly --dns-cloudflare --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini -d emma.ro -d '*.emma.ro'`
12. Certbot creează automat TXT record `_acme-challenge.emma.ro`, validează, primește certificat wildcard.
13. Renew automat via systemd timer `certbot.timer` (deja activat la install). Zero intervenție manuală.

### 7.8 Variabile de configurat la deploy

În `config.local.php` pe VPS:
```php
return [
    'app_env' => 'production',
    'app_debug' => false,
    'db_host' => 'localhost',
    'db_name' => 'emma',
    'db_user' => 'emma_app',
    'db_pass' => '<parolă-puternică>',
    'platform_super_admin_user_id' => 1,
    'sendgrid_api_key' => 'SG.xxx',
    'sendgrid_from_email' => 'noreply@emma.ro',
    'sendgrid_from_name' => 'Emma',
    'app_domain' => 'emma.ro',
    'app_origin' => 'https://app.emma.ro',
];
```

---

## 8. Plan de acțiune — ordinea pașilor

### Faza 0 — acum, fără VPS (ZIUA 1, azi)
- [x] Document master (PLAN_SAAS_EMMA.md) — ai în mână.
- [ ] Schițe SQL migrare în `/migrations/` (review-only, nu rulăm încă).
- [ ] Schiță `lib/tenant_lib.php` (review-only).
- [ ] Rebranding faza 1: introducere `pz_app_name()` helper + folosire în topbar/sidebar/login.

### Faza 1 — cumpărare VPS (ZIUA 2-3)
- [ ] Cumpăr Hetzner CPX21 + ofer credențiale Bentu.
- [ ] Setup OS (Ubuntu 24.04), user `emma`, SSH key, ufw, fail2ban.
- [ ] Install stack: nginx, php8.3-fpm, mariadb, certbot.
- [ ] Configurare DNS la Cloudflare (wildcard A record).
- [ ] Generare SSL wildcard `*.emma.ro`.

### Faza 2 — deploy versiune actuală pe VPS (ZIUA 4)
- [ ] Clone repo în `/srv/emma/repo`.
- [ ] Configurare `config.local.php` pe VPS.
- [ ] Migrare DB EuroPrest pe VPS (mysqldump + import).
- [ ] Test pe `europrest.emma.ro` (înainte de switch DNS principal).
- [ ] Switch DNS `app.pestzone.ro` → continuă să funcționeze pe vechiul cPanel până la final.

### Faza 3 — multi-tenant (ZIUA 5-7)
- [ ] Rulare migrare SQL etapa 1 (tabele platformă).
- [ ] Rulare migrare SQL etapa 2 (coloane tenant_id NULL).
- [ ] Backfill etapa 3 (tenant_id=1 peste tot).
- [ ] Activare `pz_require_tenant()` în config.php.
- [ ] Audit query-uri: clienți → contracte → programări → billing → stoc (5 sesiuni).
- [ ] Etapa 4 (NOT NULL + FK).
- [ ] Test complet pe `europrest.emma.ro`.

### Faza 4 — signup + tenant nou (ZIUA 8-10)
- [ ] Landing emma.ro static (HTML simplu, 1 pagină).
- [ ] Form signup pe emma.ro/signup.
- [ ] Provisioning tenant nou (creare subdomeniu, defaults).
- [ ] Email welcome via SendGrid.
- [ ] Test cu un tenant "demo" creat manual.

### Faza 5 — billing platformă (ZIUA 11-14)
- [ ] Integrare Stripe Checkout pentru subscription Emma Pro.
- [ ] Webhook Stripe → update `tenant_subscriptions`.
- [ ] Cron `cron_emma_trial_expiry.php`: trimite reminders + suspendă conturile expirate.
- [ ] Pagină super-admin pe `admin.emma.ro` cu lista tenants + status.

### Faza 6 — cutover EuroPrest (ZIUA 15)
- [ ] Anunț EuroPrest 2 zile înainte.
- [ ] Final mysqldump pe cPanel.
- [ ] Import pe VPS.
- [ ] Schimb DNS `app.pestzone.ro` → redirect 301 spre `europrest.emma.ro` (sau păstrat ca alias).
- [ ] Monitorizare 48h.

---

## 9. Riscuri și mitigări

| Risc | Probabilitate | Impact | Mitigare |
|---|---|---|---|
| Query nemigrat scapă fără filtru tenant_id → un tenant vede datele altuia | Medie | **Foarte mare** | (1) Audit manual sistematic. (2) Log toate query-urile fără filtru. (3) Test E2E pe 2 tenants. (4) Funcție `pz_query_scoped()` care wrapează PDO și forțează filtrul. |
| Backfill tenant_id=1 e greșit (în DB sunt deja date altcuiva) | Foarte mică | Mare | Verificare COUNT(DISTINCT) pe coloane care indicau cumva ownership (ex: `created_by`) înainte de UPDATE. |
| ALTER TABLE pe producție produce lock care îngheață aplicația 5+ minute | Medie | Mediu | (1) Backup proaspăt înainte. (2) Mentenanță anunțată EuroPrest pe ferestre de 30 min. (3) Tabelele mari (appointments, documents) — folosim `pt-online-schema-change` din Percona Toolkit dacă durează mult. |
| SSL wildcard expiră și aplicația cade | Mică | Foarte mare | (1) Certbot renew automat. (2) Cron de monitorizare expirare la -14 zile. (3) Alert Uptime Robot. |
| VPS cade (provider issue) | Mică | Foarte mare | Backup zilnic off-site (Backblaze B2). Disaster recovery: spin nou VPS în <2h. |
| Pierdere domeniu emma.ro (auto-renew fail) | Foarte mică | Catastrofic | Auto-renew + alert -30 zile + plată în avans pe 5 ani. |
| Un tenant face SQL injection și vede alte tenants | Mică | Mare | Audit pentru raw queries. Forțăm peste tot prepared statements. Pen-test la final. |
| Cron-uri pe VPS nu rulează (uitate la migrare) | Medie | Mediu | Lista din §7.6 verificată după deploy. Test manual fiecare cron. Log per cron. |
| Performance: peste 50 tenants, query-urile cu JOIN devin lente | Medie | Mediu | Index compozit `(tenant_id, ...)` pe coloanele cheie. Profilare regulată. Cache aplicativ unde se poate. |

---

## 10. Întrebări deschise (pentru deciziile următoare)

1. **Logo Emma** — ai design? Sau îl proiectăm împreună (wordmark vs. monogramă)?
2. **Slogan landing emma.ro** — ai unul? Sugestie: "CRM-ul echipei tale de teren. Programări, intervenții, facturare — într-un singur loc."
3. **Email tranzactional Emma** — păstrăm SendGrid (deja integrat)? Alt provider?
4. ~~**DNS provider pentru emma.ro**~~ — **DECIS 26 mai 2026:** domeniul rămâne înregistrat la RoTLD (registrar), dar nameserver-ele se mută la Cloudflare pentru gestionarea DNS (API stabil, SSL wildcard automat via DNS-01 challenge). Pașii în §7.7.
5. **Stripe vs. plăți alternative** — Stripe pentru abonamente RON funcționează ok din RO; alternative: Mobilpay/Netopia. Decidem când ajungem la Faza 5.
6. **Roluri suplimentare per tenant** — în plus față de `admin` și `team`, vrei și `viewer` (read-only, ex. contabilul firmei)?
7. **White-label v2** — vrei ca tenants să-și pună propriul logo + culoare pe app-ul lor (gen `firma1.emma.ro` să arate ca brandul firma1)? Coloanele `tenants.logo_path` și `tenants.primary_color` sunt deja pregătite în schemă, dar nu folosite în v1.
8. **Anularea contractului EuroPrest cu Romarg cPanel** — când îl tăiem? După 30 de zile de funcționare stabilă pe VPS recomand.

---

## 11. Convenții pentru implementare

În spiritul `AGENTS.md`:

- **Modificări mici și sigure.** Nu rescriem module întregi. Adăugăm strat de tenant peste structura existentă.
- **Idempotență.** Orice script SQL e idempotent (CREATE TABLE IF NOT EXISTS, ADD COLUMN doar dacă nu există).
- **Backup obligatoriu** înainte de orice ALTER pe producție.
- **Rollback plan** pentru fiecare migrare (DROP COLUMN, DROP TABLE).
- **UI vizibil = limba română cu diacritice.** Toate stringurile noi (signup, onboarding, billing) respectă regulile UI/UX existente.
- **DESIGN_LINE.md** rămâne sursa de adevăr pe vizual. Paginile noi (signup, onboarding, admin platformă) folosesc aceleași tokens și componente.
- **Niciodată secrete în git.** `config.local.php` rămâne ignorat. Pe VPS stă în `/srv/emma/shared/`.

---

## 12. Sumar livrabile sesiunea curentă (ZIUA 1)

| Livrabil | Locație | Stare |
|---|---|---|
| Acest document master | `PLAN_SAAS_EMMA.md` | În scriere |
| Schiță SQL etapa 1 (tabele platformă) | `migrations/saas_emma_01_platform_tables.sql` | Următor |
| Schiță SQL etapa 2 (tenant_id columns) | `migrations/saas_emma_02_tenant_id_columns.sql` | Următor |
| Schiță helper PHP tenant | `lib/tenant_lib.php` (draft, neactivat) | Următor |
| Rebranding faza 1 (helper `pz_app_name`) | `app_helpers.php` patch | Următor |
| Recomandare VPS detaliată | În §7.1 | Făcut |

**Nu se face azi:**
- Rularea efectivă a migrațiilor (nu avem aprobarea + backup proaspăt).
- Activarea `pz_require_tenant()` în `config.php` (necesită întâi migrațiile rulate).
- Audit-ul query-urilor (face parte din Faza 3).
- Configurarea VPS (Bentu nu l-a cumpărat încă).

---

**Acest document înlocuiește orice plan informal anterior pentru SaaS-ul Emma. Orice modificare la arhitectură se actualizează aici cu data și motivul.**
