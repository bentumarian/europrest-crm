-- ============================================================
-- C1 - Race condition billing_items: PAS 1 PRE-CHECK
-- ============================================================
-- READ-ONLY. Doar SELECT. Nu modifică nimic, nu inserează,
-- nu șterge, nu face ALTER. Sigur de rulat pe producție.
--
-- Scop: vedem câte duplicate avem în billing_items pe
-- appointment_id înainte să adăugăm UNIQUE constraint.
--
-- Rulează pe baza ta de date și trimite-mi rezultatele
-- celor 4 query-uri de mai jos.
-- ============================================================

-- ------------------------------------------------------------
-- Q1: Câte appointment_id-uri au DUPLICATE billing_items?
-- ------------------------------------------------------------
-- Așteptăm 0 sau un număr mic. Dacă e mare (>5), discutăm.
SELECT
    COUNT(*) AS appointments_cu_duplicate,
    SUM(cnt) AS total_duplicate_items,
    SUM(cnt - 1) AS de_sters_la_cleanup
FROM (
    SELECT appointment_id, COUNT(*) AS cnt
    FROM billing_items
    WHERE appointment_id IS NOT NULL
    GROUP BY appointment_id
    HAVING COUNT(*) > 1
) t;

-- ------------------------------------------------------------
-- Q2: Detaliu duplicate — care sunt și în ce status
-- ------------------------------------------------------------
-- Dacă Q1 raportează 0, Q2 va întoarce 0 rânduri.
-- Dacă raportează duplicate, vrem să vedem fiecare grup
-- și mai ales dacă există status='invoiced' (cazul periculos).
SELECT
    bi.appointment_id,
    bi.id AS billing_item_id,
    bi.status,
    bi.total_net,
    bi.smartbill_invoice_id,
    bi.created_at,
    bi.created_by
FROM billing_items bi
INNER JOIN (
    SELECT appointment_id
    FROM billing_items
    WHERE appointment_id IS NOT NULL
    GROUP BY appointment_id
    HAVING COUNT(*) > 1
) dup ON dup.appointment_id = bi.appointment_id
ORDER BY bi.appointment_id, bi.id;

-- ------------------------------------------------------------
-- Q3: CRITIC — există duplicate cu status='invoiced'?
-- ------------------------------------------------------------
-- Dacă rezultatul e > 0, înseamnă că deja s-au facturat
-- duplicate. Nu putem face DELETE simplu pe acelea —
-- discutăm caz cu caz înainte de cleanup.
SELECT
    COUNT(*) AS duplicate_invoiced,
    GROUP_CONCAT(DISTINCT bi.appointment_id) AS appointment_ids_afectate
FROM billing_items bi
INNER JOIN (
    SELECT appointment_id
    FROM billing_items
    WHERE appointment_id IS NOT NULL
    GROUP BY appointment_id
    HAVING COUNT(*) > 1
) dup ON dup.appointment_id = bi.appointment_id
WHERE bi.status = 'invoiced';

-- ------------------------------------------------------------
-- Q4: Sanity check — câte billing_items avem în total
-- ------------------------------------------------------------
-- Doar ca să avem context: ce procent reprezintă duplicate-le
-- din totalul billing_items.
SELECT
    COUNT(*) AS total_billing_items,
    SUM(CASE WHEN appointment_id IS NULL THEN 1 ELSE 0 END) AS items_manual_sau_contract,
    SUM(CASE WHEN appointment_id IS NOT NULL THEN 1 ELSE 0 END) AS items_din_appointment,
    SUM(CASE WHEN status = 'to_review' THEN 1 ELSE 0 END) AS to_review,
    SUM(CASE WHEN status = 'to_invoice' THEN 1 ELSE 0 END) AS to_invoice,
    SUM(CASE WHEN status = 'invoiced' THEN 1 ELSE 0 END) AS invoiced,
    SUM(CASE WHEN status = 'not_billable' THEN 1 ELSE 0 END) AS not_billable,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
FROM billing_items;
