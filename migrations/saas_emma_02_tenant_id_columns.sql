-- ============================================================
-- Migration SaaS Emma — Etapa 2: coloana tenant_id (NULL deocamdată)
-- ============================================================
-- IDEMPOTENT: verifică existența coloanei înainte de ALTER.
-- NU șterge coloane.
-- NU populează date (asta face etapa 3 — backfill).
-- ============================================================
-- Status: REVIEW ONLY — NU rula pe producție fără backup mysqldump
--         proaspăt și fără aprobarea Bentu.
-- ============================================================
-- Pattern repetat per tabel:
--   1. Verifică dacă coloana tenant_id există.
--   2. Dacă nu, ALTER TABLE ADD COLUMN + ADD INDEX.
--   3. NU adăugăm încă FOREIGN KEY (face etapa 4, după backfill).
-- ============================================================

DELIMITER $$
DROP PROCEDURE IF EXISTS saas_emma_add_tenant_col $$
CREATE PROCEDURE saas_emma_add_tenant_col(IN tbl VARCHAR(64))
BEGIN
    DECLARE col_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO col_exists
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = tbl
       AND COLUMN_NAME = 'tenant_id';

    IF col_exists = 0 THEN
        SET @sql = CONCAT(
            'ALTER TABLE `', tbl, '` ',
            'ADD COLUMN tenant_id INT NULL AFTER id, ',
            'ADD INDEX idx_', tbl, '_tenant (tenant_id)'
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$
DELIMITER ;


-- ------------------------------------------------------------
-- Aplicare pe tabelele de business
-- ------------------------------------------------------------
-- ORDINE: tabelele "root" întâi, apoi cele cu FK către ele.
-- ATENȚIE: dacă vreun tabel din listă NU EXISTĂ în DB-ul curent,
--          procedura va eșua silent (nu blochează restul).
--          Verifică la final cu query-ul de audit (vezi §AUDIT mai jos).

-- Clienți
CALL saas_emma_add_tenant_col('clients');
CALL saas_emma_add_tenant_col('client_locations');
CALL saas_emma_add_tenant_col('client_contacts');

-- Contracte
CALL saas_emma_add_tenant_col('contracts');
CALL saas_emma_add_tenant_col('contract_services');
CALL saas_emma_add_tenant_col('contract_locations');
CALL saas_emma_add_tenant_col('contract_history');

-- Programări & calendar
CALL saas_emma_add_tenant_col('appointments');
CALL saas_emma_add_tenant_col('appointment_teams');
CALL saas_emma_add_tenant_col('appointment_history');

-- Sarcini
CALL saas_emma_add_tenant_col('tasks');
CALL saas_emma_add_tenant_col('task_recurrence');
CALL saas_emma_add_tenant_col('task_attachments');

-- Echipă & utilizatori
CALL saas_emma_add_tenant_col('team_members');
CALL saas_emma_add_tenant_col('team_member_zones');

-- Documente
CALL saas_emma_add_tenant_col('documents');
CALL saas_emma_add_tenant_col('document_designs');
CALL saas_emma_add_tenant_col('document_templates');
CALL saas_emma_add_tenant_col('document_series');

-- Billing
CALL saas_emma_add_tenant_col('billing_items');
CALL saas_emma_add_tenant_col('smartbill_invoices');
CALL saas_emma_add_tenant_col('smartbill_invoice_items');
CALL saas_emma_add_tenant_col('smartbill_invoice_payments');
CALL saas_emma_add_tenant_col('smartbill_invoice_logs');
CALL saas_emma_add_tenant_col('smartbill_recurring_invoices');
CALL saas_emma_add_tenant_col('smartbill_supplier_invoices');

-- e-Factura ANAF
CALL saas_emma_add_tenant_col('anaf_oauth_tokens');
CALL saas_emma_add_tenant_col('anaf_efactura_logs');

-- Stoc
CALL saas_emma_add_tenant_col('products');
CALL saas_emma_add_tenant_col('product_categories');
CALL saas_emma_add_tenant_col('services');
CALL saas_emma_add_tenant_col('stock_movements');
CALL saas_emma_add_tenant_col('stock_receipts');
CALL saas_emma_add_tenant_col('stock_inventory');

-- Notificări & comunicare
CALL saas_emma_add_tenant_col('sms_templates');
CALL saas_emma_add_tenant_col('email_templates');
CALL saas_emma_add_tenant_col('sms_log');
CALL saas_emma_add_tenant_col('email_log');
CALL saas_emma_add_tenant_col('reminders');
CALL saas_emma_add_tenant_col('notifications');

-- Reviews & feedback
CALL saas_emma_add_tenant_col('review_requests');
CALL saas_emma_add_tenant_col('feedback');

-- Diverse
CALL saas_emma_add_tenant_col('addenda');
CALL saas_emma_add_tenant_col('avize_sanitare');

-- Curățare procedură
DROP PROCEDURE IF EXISTS saas_emma_add_tenant_col;


-- ============================================================
-- AUDIT — query pentru verificare după rulare
-- ============================================================
-- Listă tabele care au primit `tenant_id` (sortate descrescător după dată)
-- Rulează ca query separat pentru verificare manuală.
--
-- SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT
--   FROM INFORMATION_SCHEMA.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND COLUMN_NAME = 'tenant_id'
--  ORDER BY TABLE_NAME;
--
-- Listă tabele de business care încă NU au tenant_id (potențial uitate):
--
-- SELECT t.TABLE_NAME
--   FROM INFORMATION_SCHEMA.TABLES t
--  WHERE t.TABLE_SCHEMA = DATABASE()
--    AND t.TABLE_TYPE = 'BASE TABLE'
--    AND t.TABLE_NAME NOT IN (
--        'tenants', 'tenant_plans', 'tenant_subscriptions',
--        'user_tenant_membership', 'platform_settings',
--        'users', 'app_settings'
--    )
--    AND NOT EXISTS (
--        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS c
--         WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA
--           AND c.TABLE_NAME = t.TABLE_NAME
--           AND c.COLUMN_NAME = 'tenant_id'
--    )
--  ORDER BY t.TABLE_NAME;
-- ============================================================
-- ROLLBACK (pentru referință):
--   ALTER TABLE <nume> DROP COLUMN tenant_id, DROP INDEX idx_<nume>_tenant;
-- ============================================================
