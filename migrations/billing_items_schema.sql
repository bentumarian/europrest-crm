-- ============================================================
-- Migration: billing_items schema (post-reset financiar)
-- ============================================================
-- Scop:
--   Extragerea schemei `billing_items` din runtime-ul
--   `lib/billing/billing_lib.php::pz_billing_ensure_schema()`
--   într-un fișier de migrare versionat, pentru:
--     - claritate (schema vizibilă în /migrations/)
--     - reproductibilitate pe staging / VPS Emma
--     - audit prezentat în PLAN_BILLING_RESET.md §1
--
-- Status:
--   - IDEMPOTENT: poate fi rulat de N ori fără efect secundar.
--   - SAFE: nu șterge coloane, nu modifică date existente,
--           doar adaugă (CREATE / ADD INDEX), eventual elimină
--           UNIQUE-ul învechit de pe smartbill_invoices.appointment_id.
--
-- Notă:
--   Runtime-ul `pz_billing_ensure_schema()` continuă să facă același
--   lucru la fiecare load de pagină billing. Acest fișier NU îl
--   înlocuiește — îl dublează ca sursă canonică versionată.
--   Pe VPS Emma (deploy fără shared-hosting), acest fișier va fi
--   rulat o singură dată din pipeline-ul de migrare (vezi
--   PLAN_SAAS_EMMA.md §3.1).
-- ============================================================

-- 1) Tabel principal pentru pozițiile de facturat.
--    Sursa unică de adevăr — înlocuiește citirile din
--    appointments.billing_* care există încă pentru fallback.
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
        -- valori permise: 'to_review', 'to_invoice', 'invoiced',
        --                 'not_billable', 'cancelled'
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


-- 2) Eliminăm constrângerea 1-la-1 din smartbill_invoices
--    (dacă există). De acum o factură poate conține mai multe
--    poziții de la mai multe programări — relația devine 1:N.
--    Coloana appointment_id rămâne pentru compatibilitate,
--    dar nu mai e cheie unică.
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


-- 3) Adaugă index simplu pe smartbill_invoices.appointment_id
--    (dacă lipsește) — pentru regăsire rapidă a facturilor
--    asociate unei programări (use case retrocompatibil).
SET @tbl_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'smartbill_invoices'
);
SET @idx_exists := IF(@tbl_exists > 0, (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'smartbill_invoices'
      AND INDEX_NAME = 'idx_smartbill_appointment'
), 1);
SET @sql := IF(@tbl_exists > 0 AND @idx_exists = 0,
    'ALTER TABLE smartbill_invoices ADD INDEX idx_smartbill_appointment (appointment_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- 4) Coloana revenue_category (categorie de venit) — pentru
--    pz_revenue_lib (DDD / ignifugari / chirii / altele).
--    Se aplică pe billing_items și smartbill_invoices dacă există.
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'billing_items'
      AND COLUMN_NAME = 'revenue_category'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE billing_items ADD COLUMN revenue_category VARCHAR(30) NOT NULL DEFAULT 'ddd'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'smartbill_invoices'
      AND COLUMN_NAME = 'revenue_category'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE smartbill_invoices ADD COLUMN revenue_category VARCHAR(30) NOT NULL DEFAULT 'ddd'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ============================================================
-- VERIFICARE — query-uri de control
-- ============================================================
-- Rulează după migrare pentru confirmare:
--
-- 1. Structura billing_items:
--    SHOW CREATE TABLE billing_items;
--
-- 2. Indexurile pe smartbill_invoices:
--    SHOW INDEX FROM smartbill_invoices;
--    -- trebuie: NO uq_smartbill_appointment, DA idx_smartbill_appointment
--
-- 3. Coloane noi:
--    SELECT TABLE_NAME, COLUMN_NAME, COLUMN_DEFAULT
--      FROM INFORMATION_SCHEMA.COLUMNS
--     WHERE TABLE_SCHEMA = DATABASE()
--       AND COLUMN_NAME = 'revenue_category';
-- ============================================================
-- ROLLBACK (pentru referință, dacă vrei să revii):
--   DROP TABLE billing_items;
--   ALTER TABLE smartbill_invoices DROP INDEX idx_smartbill_appointment;
--   ALTER TABLE smartbill_invoices DROP COLUMN revenue_category;
--   -- (UNIQUE-ul uq_smartbill_appointment NU se restaurează — era
--   --  problema pe care această migrare o rezolvă.)
-- ============================================================
