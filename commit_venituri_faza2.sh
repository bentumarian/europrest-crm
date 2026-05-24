#!/usr/bin/env bash
# Commit Venituri pe surse — Faza 2: override categorie pe factură + filtru pe listă
set -e

cd "$(dirname "$0")"

git add invoice.php invoices.php commit_venituri_faza2.sh

git commit -m "feat(venituri): Faza 2 — override categorie pe factură + filtru pe listă

invoice.php:
- Include revenue_lib.php
- Câmp 'Categorie venit' în formularul de editare (lângă 'Limba')
  cu populare din loadedInvoice (default 'ddd')
- POST handler manual: propagă revenue_category în UPDATE și INSERT
  smartbill_invoices
- Acțiune nouă POST 'update_revenue_category':
  schimbă categoria oricând pentru o factură existentă (folosit pt. chirii)
- Panou 'Categorie venit' pe pagina facturii (vizibil mereu, sub acțiuni):
  arată badge curent + selector + buton 'Schimbă categoria'
- Alerte revenue_updated / revenue_error

invoices.php:
- Include revenue_lib.php
- Filtru categorie venit în WHERE (param ?cat=ddd/ignifugari/chirii/altele/all)
- Chips de filtrare deasupra tabelului cu cele 5 opțiuni (Toate + 4 cat)
  păstrând restul filtrelor din URL
- Badge categorie afișat sub CIF/CNP în coloana Client
"

git push origin main
echo
echo "Done. Pe pagina facturii ai acum:"
echo "  - selector inline pe formularul de editare"
echo "  - panou de override după emitere (poți marca o factură ca 'chirii' oricând)"
echo "  - filtru de linie de business pe lista de facturi"
