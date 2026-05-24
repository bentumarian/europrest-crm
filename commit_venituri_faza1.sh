#!/usr/bin/env bash
# Commit Venituri pe surse — Faza 1: schemă + selector pe servicii
set -e

cd "$(dirname "$0")"

git add revenue_lib.php services.php billing_lib.php commit_venituri_faza1.sh

git commit -m "feat(venituri): Faza 1 — schemă revenue_category + selector pe servicii

- revenue_lib.php (helper nou): categorii (ddd / ignifugari / chirii / altele)
  cu label, culori, badge render. Migrație idempotentă pe orice tabelă
  (pz_revenue_ensure_column).
- services.php:
  * Migrație coloană services.revenue_category (default 'ddd')
  * Selector 'Categorie venit' în modal creare + editare
  * Badge colorat pe service-card (Activ / Min / Ordine / [Categorie])
  * Filtru chips deasupra grid-ului: Toate / DDD / Ignifugări / Chirii / Altele
    cu count pe fiecare
- billing_lib.php:
  * Include revenue_lib.php
  * Migrație billing_items.revenue_category + smartbill_invoices.revenue_category
    în pz_billing_ensure_schema (default 'ddd' pentru istoric)
  * pz_billing_appointment_billing_data: LEFT JOIN services pentru
    a aduce service_revenue_category
  * pz_billing_ensure_item_for_appointment: snapshot revenue_category
    din service la INSERT
  * pz_billing_issue_invoice: derivă revenue_category pentru factură:
    dacă toate item-urile au aceeași categorie → folosește-o
    dacă sunt mixte → 'altele'
    dacă options['revenue_category'] e setat → override manual
- Faza 2 (override pe invoice.php) urmează separat
"

git push origin main
echo
echo "Done. La prima încărcare a services.php / work_billing.php se vor adăuga"
echo "automat coloanele lipsă. Toate datele existente rămân 'ddd' (default)."
