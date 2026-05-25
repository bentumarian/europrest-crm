-- ============================================================
-- Migration billing — flux financiar nou (post-reset).
-- Idempotent: poate fi rulat de mai multe ori fără efecte secundare.
-- Nu populează date vechi. Nu șterge tabele sau coloane.
-- ============================================================

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

-- 2) Elimină UNIQUE de pe smartbill_invoices.appointment_id, dacă tabela și indexul există.
SET @tbl_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'smartbill_invoices'
);
SET @uq_exists := IF(@tbl_exists > 0, (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'smartbill_invoices'
      AND INDEX_NAME = 'uq_smartbill_appointment'
), 0);
SET @sql := IF(@uq_exists > 0,
    'ALTER TABLE smartbill_invoices DROP INDEX uq_smartbill_appointment',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Adaugă index simplu pe smartbill_invoices.appointment_id, dacă lipsește.
SET @idx_exists := IF(@tbl_exists > 0, (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'smartbill_invoices'
      AND INDEX_NAME = 'idx_smartbill_appointment'
), 1);
SET @sql := IF(@tbl_exists > 0 AND @idx_exists = 0,
    'ALTER TABLE smartbill_invoices ADD INDEX idx_smartbill_appointment (appointment_id)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- Notă: coloanele vechi `appointments.billing_*` rămân pe loc.
-- Sursa nouă este `billing_items`. Codul nou nu mai citește
-- coloanele vechi pentru fluxul financiar, dar nici nu le șterge.
-- ============================================================
