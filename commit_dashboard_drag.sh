#!/usr/bin/env bash
# Commit drag-and-drop carduri dashboard + layout adaptiv
set -e

cd "$(dirname "$0")"

git add dashboard.php commit_dashboard_drag.sh

git commit -m "feat(dashboard): drag-and-drop carduri + layout adaptiv

- Carduri mobile pe dashboard (Mission Control)
- Drag handle (grip-vertical) în colțul stânga sus al fiecărui card
- Re-ordonare prin SortableJS pe 4 rânduri:
  * row-rings (Operațional / Financiar / Echipă)
  * row-mini (4 mini stat cards)
  * row-secondary (Sarcină urgentă + Agenda)
  * row-charts (Donut status + Top servicii)
- Ordinea se salvează în localStorage per rând (pz_dash_order_v2_*)
- Layout adaptiv: grid auto-fit + minmax (cardurile se strâng/se lățesc
  după spațiu, nu mai sunt fixe 3 coloane)
- Banner navy 'Trend 6 luni' rămâne static (nu e drag-able)
- Click prevent pe drag handle din .mc-mini (link-uri) ca să nu navige
"

git push origin main
echo
echo "Done. Reîncarcă /dashboard.php cu Ctrl+Shift+R pe app.pestzone.ro"
