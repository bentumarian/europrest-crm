-- ============================================================
-- C1 - Race condition billing_items: PAS 2 ADAUGĂ UNIQUE (v2)
-- ============================================================
-- Modifică schema. Trebuie făcut BACKUP înainte de rulare.
--
-- Versiunea aceasta NU folosește INFORMATION_SCHEMA, pentru
-- că pe shared hosting Romarg utilizatorul DB nu are acces
-- acolo.
--
-- Pre-condiție: rezultatele pre-check Q1+Q3 = 0 (confirmat).
--
-- Rulează cele 3 query-uri unul după altul.
-- ============================================================

-- ------------------------------------------------------------
-- PAS A: Vezi indexurile actuale de pe billing_items
-- ------------------------------------------------------------
-- Așteptăm să NU apară un index 'uq_billing_items_appointment'.
-- Dacă apare deja, sărim peste PAS B (ALTER-ul ar pica cu eroare
-- "Duplicate key name", ceea ce e inofensiv).
SHOW INDEX FROM billing_items;

-- ------------------------------------------------------------
-- PAS B: Adaugă UNIQUE constraint
-- ------------------------------------------------------------
-- Dacă pică cu "Duplicate key name 'uq_billing_items_appointment'",
-- înseamnă că UNIQUE-ul există deja - nu e o problemă, sari peste.
-- Dacă pică cu "Duplicate entry ... for key", înseamnă că au apărut
-- între timp duplicate - oprește-te și anunță-mă.
ALTER TABLE billing_items
    ADD UNIQUE KEY uq_billing_items_appointment (appointment_id);

-- ------------------------------------------------------------
-- PAS C: Verificare post-ALTER
-- ------------------------------------------------------------
-- Așteptăm să vedem indexul în lista de mai jos.
-- Coloana Key_name trebuie să conțină 'uq_billing_items_appointment'.
-- Coloana Non_unique trebuie să fie 0 (înseamnă că e UNIQUE).
SHOW INDEX FROM billing_items WHERE Key_name = 'uq_billing_items_appointment';

-- ============================================================
-- ROLLBACK (DOAR DACĂ E NEVOIE — NU se execută automat)
-- ============================================================
-- Dacă după ALTER ai probleme și vrei să revii, decomentează
-- linia de mai jos și rulează-o.
--
-- ALTER TABLE billing_items DROP INDEX uq_billing_items_appointment;
--
-- Notă: dacă faci rollback, race-condition-ul revine. Nu lăsa
-- rollback-ul activ în producție decât pe perioada investigării.
-- ============================================================
