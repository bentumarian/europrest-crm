# Emma CRM

(fost EuroPrest / PestZone — în tranziție către SaaS Emma)

Aplicatie CRM pentru firma de servicii (clienti, contracte, programari, billing,
e-Factura ANAF, stoc, sarcini, procese verbale). Backend PHP pe shared hosting
(cPanel / Romarg), DB MySQL/MariaDB.

## Cerinte server

- PHP 7.4+ (testat pe 8.x)
- MySQL 5.7+ sau MariaDB 10.3+
- Apache cu `mod_rewrite` si `mod_headers`
- Extensii PHP: PDO, pdo_mysql, mbstring, curl, openssl, json, zip

## Setup pe server nou

1. **Clone repo** in afara document root (ex: `~/repositories/europrest-crm`).
2. **Document root** este `~/app.pestzone.ro/` (sau echivalent). Copiaza
   manual prima data:
   ```
   cp -fR ~/repositories/europrest-crm/*.php ~/app.pestzone.ro/
   cp -fR ~/repositories/europrest-crm/lib ~/app.pestzone.ro/
   cp -f ~/repositories/europrest-crm/.htaccess ~/app.pestzone.ro/
   ```
3. **Configurare locala**: copiaza `config.local.php.example` ca
   `config.local.php` in document root si completeaza:
   - credentiale DB (`db_host`, `db_name`, `db_user`, `db_pass`)
   - `super_admin_user_id` (id-ul utilizatorului care vede zone critice)
   - `github_webhook_secret` (string random — vezi sectiunea Deploy)
   - optional: SendGrid pentru email
4. **DB schema**: ruleaza migratiile din `/migrations/` pe ordinea numerica:
   ```
   migration_billing.sql                       (idempotent, sigur)
   migration_billing_c1_step1_precheck.sql     (read-only, pentru verificare)
   migration_billing_c1_step2_unique.sql       (ALTER — backup inainte!)
   ```
   Le rulezi din phpMyAdmin sau cu `mysql < migration_*.sql`.
5. **Permisiuni**: asigura-te ca `/uploads/`, `/storage/`, `/tmp/` sunt
   scriibile de catre user-ul Apache.

## Auto-deploy via GitHub webhook

`deploy.php` este endpoint-ul webhook care la fiecare push pe `main`:

1. Verifica semnatura HMAC-SHA256 cu `github_webhook_secret`
2. Ruleaza `git pull origin main` in `~/repositories/europrest-crm/`
3. Copiaza `*.php`, `/lib/` si `.htaccess` in document root
4. Logheaza in `~/deploy.log`

Configurare GitHub: Settings → Webhooks → Add webhook:
- Payload URL: `https://app.pestzone.ro/deploy.php`
- Content type: `application/json`
- Secret: acelasi string pus in `config.local.php`
- Events: Just the push event

**Subfoldere NOI** (peste `/lib/`) nu se sincronizeaza automat — daca
adaugi un nou folder (`/api/`, `/migrations/`, etc.), trebuie adaugat
explicit in array-ul de comenzi din `deploy.php`.

## Structura proiect

```
/                        Pagini PHP accesibile prin URL (clients.php, calendar.php, ...)
/lib/                    Biblioteci require-uite (smartbill_lib, notification_lib, ...)
/lib/billing/            Modul billing (billing_lib.php)
/migrations/             Scripturi SQL versionate pentru schimbari de schema DB
/uploads/                Fisiere uploadate de useri (gitignored)
/storage/                Cache & fisiere generate (gitignored)
/vendor/                 Composer dependencies (gitignored, instalat o singura data)
/dompdf/                 PDF renderer (commit-uit ca pachet self-contained)
/assets/                 Logo + iconuri statice
.htaccess                Rewrite rules (URL-uri prietenoase) + securitate
config.php               Bootstrap principal (timezone, DB, helpers)
config.local.php         Secrete (NU in git) — copie din .example
deploy.php               Webhook GitHub pentru auto-deploy
```

## Cron-uri (cPanel → Cron Jobs)

Setate dupa nevoie:

| Script                         | Frecventa sugerata | Comanda |
|--------------------------------|---|---|
| `cron_efactura_sync.php`       | de 2 ori/zi (06:00, 14:00) | `/usr/bin/php ~/app.pestzone.ro/cron_efactura_sync.php` |
| `cron_smartbill_recurring.php` | zilnic 03:00 | `/usr/bin/php ~/app.pestzone.ro/cron_smartbill_recurring.php` |
| `cron_reminder_emails.php`     | zilnic 07:00 | `curl -s 'https://app.pestzone.ro/cron_reminder_emails.php?key=SECRET'` |
| `cron_sms_reminders.php`       | zilnic 07:00 | `/usr/bin/php ~/app.pestzone.ro/cron_sms_reminders.php SECRET` |
| `cron_task_expiry_7_sms.php`   | zilnic 08:00 | `/usr/bin/php ~/app.pestzone.ro/cron_task_expiry_7_sms.php SECRET` |
| `cron_review_requests.php`     | zilnic 19:00 | `/usr/bin/php ~/app.pestzone.ro/cron_review_requests.php` |

Cheile / secretele se seteaza in `app_settings` (tabela DB) prin pagina de setari:
- `reminder_cron_key` (pentru email reminders, GET HTTP)
- `sms_cron_secret` (pentru toate cron-urile SMS)

## Backup

Inainte de orice deploy mare sau migratie:
- DB: `mysqldump -u USER -p DB > backup_$(date +%F).sql` sau export din phpMyAdmin
- Files: snapshot la `~/app.pestzone.ro/` (zip in afara document root)

## Convenții cod

- UTF-8 cu diacritice peste tot (`utf8mb4_unicode_ci` pe DB)
- Prepared statements PDO obligatoriu — niciodata concatenare in queries
- Functiile globale sunt prefixate cu `pz_` sau `app_`
- Aliasuri URL romane (factura, facturi, incasari, etc.) sunt stub-uri PHP
  care fac `require` la fisierul englezesc real
- Toate paginile accesibile URL apeleaza `require_login()` (sau `is_admin()`)
  inainte sa serveasca continut

## Documentatie suplimentara

- `AGENTS.md` — instructiuni pentru AI / pair programming
- `AUDIT_FINANCIAR.md` — audit complet pe fluxul de billing
- `DESIGN_LINE.md` — sistem de design + tokens
- `PLAN_BILLING_RESET.md` — istoric reset billing flow
