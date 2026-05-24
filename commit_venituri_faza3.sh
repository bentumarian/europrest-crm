#!/usr/bin/env bash
# Commit Venituri pe surse — Faza 3: card dashboard
set -e

cd "$(dirname "$0")"

git add dashboard.php commit_venituri_faza3.sh

git commit -m "feat(venituri): Faza 3 — card dashboard 'Venituri pe linie de business'

dashboard.php:
- Include revenue_lib.php
- Query nou: sumează gross_amount din smartbill_invoices pe revenue_category,
  filtrat la perioada cardului Financiar (\$finStart..\$finEnd), doar facturi
  emise (smartbill_number != '') și source_type != 'receipt'
- Card nou 'Venituri pe linie de business' între mc-secondary și mc-charts-row:
  * Titlu + perioada Financiar (sincronizat)
  * Cog meniu cu opțiunile de perioadă (modifică period_fin global)
  * Drag handle (intră în SortableJS persistence)
  * Total facturat în antet (font mare, navy)
  * 4 bare orizontale (DDD verde / Ignifugări portocaliu / Chirii albastru /
    Altele gri) cu valoare absolută, procent, nr. facturi
  * Link 'Vezi raportul detaliat →' către rapoarte_venituri.php (Faza 4)
- CSS dedicat .mc-revenue-row + .mc-revenue-bar-row pentru consistență
  cu restul de carduri Mission Control
- row-revenue adăugat în lista SortableJS pentru drag-and-drop

Empty states:
- Dacă schema nu e migrată (revenue_category lipsește) → mesaj informativ
- Dacă nu sunt facturi în perioadă → mesaj 'Nu există facturi emise'
"

git push origin main
echo
echo "Done. Cardul nou apare pe dashboard între Sarcină urgentă/Agenda și grafice."
