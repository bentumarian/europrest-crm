# AGENTS.md — Instrucțiuni pentru Codex

## Context proiect

Acest proiect este un CRM web pentru firme de servicii, dezvoltat inițial pentru industria DDD: dezinsecție, dezinfecție și deratizare.

Aplicația este construită în PHP + MySQL/MariaDB și este găzduită pe server cPanel/Romarg.

Aplicația include module pentru:
- dashboard;
- calendar echipe / tehnicieni;
- clienți;
- locații / puncte de lucru;
- contracte;
- sarcini;
- programări;
- procese verbale;
- rapoarte;
- documente;
- serii documente;
- design documente;
- email;
- SMS;
- stocuri;
- produse / biocide / materiale;
- setări.

Scopul aplicației este să fie rapidă, clară, compactă și ușor de folosit atât de birou, cât și de tehnicienii din teren.

---

## Reguli generale obligatorii

- Folosește limba română cu diacritice în interfață, texte, etichete, mesaje, documentație, comentarii și conținut vizibil utilizatorului.
- Salvează fișierele în UTF-8.
- Nu elimina diacriticele decât dacă există o constrângere tehnică reală și explicată.
- Nu rescrie module întregi dacă nu este absolut necesar.
- Nu elimina funcționalități existente fără cerere explicită.
- Nu modifica structura bazei de date fără să propui mai întâi SQL-ul necesar.
- Nu schimba logica de business fără să explici clar impactul.
- Nu modifica designul global fără să verifici pagina de design a proiectului.
- Nu introduce librării noi fără justificare.
- Nu adăuga cod experimental în producție.
- Nu schimba denumiri de câmpuri, coloane sau tabele existente fără motiv tehnic clar.
- Nu inventa flow-uri noi dacă există deja un flow funcțional în aplicație.
- Nu modifica zone necerute doar pentru că par îmbunătățibile.
- Orice modificare trebuie să fie punctuală, sigură și compatibilă cu structura existentă.

---

## Design și identitate vizuală platformă

Platforma are o pagină oficială de design și identitate vizuală:

`https://app.pestzone.ro/style_guide` (fișier local: `style_guide.php`)

Această pagină este sursa principală pentru:
- identitatea vizuală a platformei;
- paleta de culori;
- stilul butoanelor;
- stilul cardurilor;
- layout;
- spațieri;
- formulare;
- tabele;
- sidebar;
- header;
- dashboard;
- calendar;
- iconuri;
- responsive/mobile;
- aspect general al interfeței.

Înainte de orice modificare UI/UX, Codex trebuie să verifice această pagină sau fișierul din proiect care o generează.

Dacă pagina există în cod ca fișier local, Codex trebuie să caute și să consulte fișierul corespunzător, de exemplu:
- `ui_template.php`;
- `ui-template.php`;
- `design.php`;
- `design-system.php`;
- `style-guide.php`;
- `template-ui.php`;
- sau orice fișier/rută care generează pagina `/ui_template`.

Reguli obligatorii pentru design:
- Nu inventa un stil vizual nou.
- Nu schimba paleta de culori fără cerere explicită.
- Nu schimba stilul general al butoanelor, cardurilor, formularelor sau tabelelor fără să respecți pagina de design.
- Orice pagină nouă trebuie să folosească aceeași identitate vizuală.
- Orice pagină existentă modificată trebuie apropiată de stilul din `ui_template`.
- Dacă există conflict între designul vechi al unei pagini și `ui_template`, se respectă `ui_template`.
- Dacă o componentă nu este definită în `ui_template`, se păstrează stilul existent al aplicației și se creează o variantă coerentă cu identitatea vizuală actuală.
- Nu transforma o ajustare mică de UI într-o rescriere completă a paginii.

Scopul este ca aplicația să aibă o interfață unitară, modernă, compactă, premium și ușor de folosit zilnic de birou și de tehnicienii din teren.

---

## Reguli pentru modificări de design

Când modifici o pagină existentă:
1. Citește mai întâi `ui_template`.
2. Identifică stilurile deja folosite în aplicație.
3. Aplică modificarea doar în zona cerută.
4. Nu rescrie toată pagina pentru o ajustare vizuală mică.
5. Nu schimba structura funcțională a paginii doar pentru design.
6. Dacă este nevoie de CSS nou, încearcă mai întâi să folosești clasele existente.
7. Dacă adaugi clase noi, denumește-le clar și păstrează stilul compatibil cu `ui_template`.
8. Verifică desktop și mobil.
9. Verifică dacă elementele interactive rămân vizibile, apăsabile și aliniate corect.
10. Verifică dacă textele lungi nu rup layout-ul.

---

## Reguli UI/UX

- Interfața trebuie să fie curată, modernă, compactă și eficientă.
- Designul trebuie să fie potrivit pentru utilizare zilnică de birou și teren.
- Prioritatea este funcționalitatea, nu efectele vizuale inutile.
- Butoanele trebuie să fie clare, ușor de înțeles și consecvente.
- Formularele trebuie compactate unde este posibil, fără să devină greu de folosit.
- Textele descriptive inutile trebuie eliminate sau mutate în tooltip/help text.
- Pe mobil, butoanele și cardurile trebuie să rămână ușor de apăsat.
- Nu aglomera interfața cu informații redundante.
- Orice schimbare vizuală trebuie să respecte pagina de design.
- Textul trebuie să fie lizibil, aerisit și potrivit pentru lucru zilnic.
- Informațiile importante trebuie să fie vizibile rapid, fără derulare inutilă.
- Acțiunile principale trebuie să fie ușor de identificat.
- Acțiunile periculoase, precum ștergerea definitivă, trebuie să fie protejate vizual și logic.

---

## Reguli obligatorii pentru verificarea funcționalității

Pe orice pagină modificată, Codex trebuie să verifice funcționalitatea completă a paginii afectate.

Verificarea trebuie să includă:
- toate butoanele vizibile;
- toate linkurile;
- toate formularele;
- toate câmpurile obligatorii;
- toate acțiunile de salvare;
- toate acțiunile de editare;
- toate acțiunile de ștergere;
- toate acțiunile de filtrare și căutare;
- toate taburile, modalele, dropdown-urile și meniurile;
- toate acțiunile AJAX / JavaScript;
- toate redirectările;
- toate mesajele de succes și eroare;
- toate permisiunile relevante pentru roluri diferite;
- comportamentul pe desktop;
- comportamentul pe mobil.

Codex trebuie să verifice flow-ul complet al paginii, nu doar zona modificată vizual.

Exemple:
- Dacă modifică o fișă de programare, trebuie să verifice flow-ul de creare, editare, salvare, finalizare, emitere PV și trimitere email, dacă aceste acțiuni există pe pagină.
- Dacă modifică pagina de clienți, trebuie să verifice creare client, editare client, vizualizare fișă, locații, căutare, paginare și butoanele de acțiune.
- Dacă modifică pagina de contracte, trebuie să verifice creare contract, locații/servicii, salvare, generare sarcini, afișare document și trimitere email.
- Dacă modifică pagina de procese verbale, trebuie să verifice generare, salvare, semnătură, PDF, email și revenire în programare.

Dacă nu poate testa efectiv o acțiune, Codex trebuie să spună clar:
- ce a putut verifica;
- ce nu a putut verifica;
- ce trebuie testat manual de utilizator.

Codex nu are voie să spună că a testat ceva dacă nu a rulat efectiv verificarea.

---

## Reguli pentru butoane și acțiuni

Pentru fiecare pagină pe care lucrează, Codex trebuie să identifice toate butoanele și acțiunile importante înainte de modificare.

Pentru fiecare buton, trebuie verificat:
- dacă este afișat corect;
- dacă are text clar;
- dacă are link sau handler corect;
- dacă declanșează acțiunea corectă;
- dacă respectă permisiunile utilizatorului;
- dacă afișează mesaj corect după acțiune;
- dacă nu produce erori PHP, JS sau SQL;
- dacă nu întrerupe flow-ul paginii;
- dacă funcționează și pe mobil.

Nu este permis ca o modificare vizuală să rupă funcționalitatea unui buton existent.

Nu este permis ca un buton să rămână vizibil dacă acțiunea lui nu mai este disponibilă.

Nu este permis ca un buton să fie mutat într-o zonă unde utilizatorul nu îl mai găsește ușor.

---

## Reguli pentru fluxuri de lucru

Înainte de orice modificare importantă, Codex trebuie să înțeleagă flow-ul funcțional al paginii.

Trebuie urmărită logica:
- de unde vine utilizatorul;
- ce trebuie să completeze;
- ce acțiune principală are;
- ce se salvează în baza de date;
- ce se afișează după salvare;
- ce pagină sau modal se deschide după acțiune;
- ce se întâmplă în caz de eroare;
- ce se întâmplă pe mobil;
- ce roluri au voie să facă acțiunea.

Dacă o modificare poate afecta flow-ul existent, Codex trebuie să explice riscul înainte să modifice.

---

## Reguli pentru calendar și programări

- Calendarul afișează echipele/tehnicienii pe coloane.
- Termenul folosit în interfață este „Tehnician”, nu „Echipă responsabilă”.
- Termenul pentru suport este „Tehnician suplimentar”.
- În câmpuri, liste și afișări se folosește tot termenul „Tehnician”.
- Orele se afișează în format european 00–24.
- Programările se fac din 30 în 30 de minute.
- Minutele permise sunt doar `00` și `30`.
- Nu permite programări la minute intermediare.
- Programările finalizate trebuie afișate vizual diferit, dar fără să încarce calendarul.
- În vizualizarea lunară, evenimentele trebuie să fie compacte.
- Tehnicienii suplimentari trebuie să vadă programarea în calendarul lor, dar nu trebuie să poată modifica programarea dacă nu au drepturi.
- Conflictele de programare trebuie verificate la salvare.
- Dacă există conflict, formularul trebuie să rămână deschis și să afișeze eroarea clar, fără să piardă datele completate.
- Calendarul trebuie să rămână ușor de folosit pe mobil.

---

## Reguli pentru procese verbale

- Biroul poate emite proces verbal chiar dacă lucrarea nu este finalizată.
- Tehnicianul poate emite proces verbal doar după finalizarea lucrării.
- Tehnicianul nu poate emite proces verbal dacă procesul verbal a fost deja emis.
- Emiterea PV nu este obligatorie pentru tehnician, deoarece PV-ul poate fi emis anterior de birou.
- După emiterea procesului verbal, trebuie să existe opțiunea de trimitere pe email.
- Semnătura clientului se face în interfață, prin canvas/touch.
- Semnătura trebuie introdusă înainte de salvarea/emiterea PV, atunci când flow-ul cere semnătură.
- După semnare, canvasul de semnătură trebuie ascuns pentru a reduce aglomerarea.
- Documentele trebuie generate cât mai fidel față de previzualizare.
- PV-ul trebuie să poată folosi datele programării: client, locație, adresă, reprezentant, oră, servicii.
- Dacă PV-ul este trimis pe email, se atașează PDF-ul generat.
- Dacă nu există email client, interfața trebuie să ceară completarea unei adrese de email sau să blocheze trimiterea cu mesaj clar.
- Trimiterea pe email trebuie logată dacă există sistem de loguri/comunicări.

---

## Reguli pentru contracte, sarcini și programări

Fluxul corect este:

contract → sarcini → programări → lucrare finalizată → proces verbal → email / raportare / facturare externă

Reguli:
- Contractul definește clientul, locațiile, serviciile, frecvențele și tarifele.
- Contractul nu trebuie să genereze direct programări în teren.
- Contractul generează sarcini pentru birou.
- Biroul confirmă sarcinile cu clientul.
- După confirmare, sarcina se transformă în programare pentru tehnician.
- Dacă există tarif în contract pentru locația și serviciul selectat, acesta se preia automat.
- Dacă nu există tarif în contract, valoarea lucrării fără TVA devine obligatorie.
- Valoarea poate lipsi doar dacă se bifează explicit „Nu se facturează” și se completează motivul.
- Obiectivul biroului este să golească lista/calendarul de sarcini neprogramate.
- O sarcină confirmată și programată trebuie să dispară din backlog-ul biroului și să apară în calendarul echipei/tehnicianului.
- Contractele pot avea locații diferite, servicii diferite, frecvențe diferite și zile preferate diferite.
- Generarea sarcinilor trebuie să țină cont de locație, serviciu, frecvență și regulile contractului.

---

## Reguli pentru clienți și locații

- Clienții pot fi persoane juridice sau persoane fizice.
- Pentru persoane juridice, datele ANAF pot fi preluate automat unde există integrare.
- Telefonul nu se preia din ANAF și trebuie completat separat.
- Clientul poate avea una sau mai multe locații / puncte de lucru.
- Prima locație poate prelua adresa sediului clientului dacă utilizatorul apasă butonul dedicat.
- Locația / punctul de lucru este importantă pentru programări, contracte, sarcini și PV.
- În programare trebuie preluate automat adresa, persoana de contact și telefonul locației, dacă există.
- Fișa clientului trebuie să rămână clară, compactă și ușor de folosit.
- Ștergerea definitivă trebuie protejată și blocată dacă există programări, sarcini, contracte sau documente asociate.

---

## Reguli pentru documente și PDF

- Documentele principale sunt: oferte, contracte și procese verbale.
- Documentele trebuie să respecte template-urile configurate în aplicație.
- Headerul, footerul, logo-ul și stilurile documentelor trebuie păstrate conform setărilor din aplicație.
- PDF-ul generat trebuie să fie cât mai apropiat de previzualizarea din aplicație.
- Nu folosi mecanisme diferite de generare PDF pentru același document fără motiv clar.
- Evită duplicarea logicii de randare document.
- Dacă modifici template-ul sau generarea PDF, verifică:
  - previzualizarea;
  - descărcarea PDF;
  - atașarea PDF la email;
  - aspectul PDF primit pe email.
- Nu mări fonturile, spațierile sau marginile fără cerere explicită.
- Documentele trebuie să rămână compacte și profesionale.

---

## Reguli pentru email și SMS

- Emailurile se trimit prin integrarea existentă, dacă este configurată.
- Nu introduce alt serviciu de email fără cerere explicită.
- Emailurile pentru PV, contracte și oferte trebuie să atașeze PDF-ul corect.
- Textul emailului trebuie să fie potrivit tipului de document trimis.
- Dacă modifici trimiterea pe email, verifică:
  - destinatarul;
  - subiectul;
  - corpul mesajului;
  - atașamentul;
  - mesajul de succes/eroare;
  - logarea trimiterii, dacă există.
- SMS-urile trebuie să folosească integrarea existentă.
- Nu trimite SMS/email automat fără o regulă clară sau fără acțiune explicită.

---

## Reguli pentru stocuri, biocide și materiale

- Produsele pot fi biocide, materiale sau alte consumabile.
- Pentru biocide sunt importante: denumire, aviz, lot, valabilitate, unitate de consum și cantitate.
- Consumul de produse trebuie legat de PV acolo unde este posibil.
- Fișa de magazie trebuie să poată calcula stoc inițial, intrări, consum și stoc final.
- Nu modifica logica de stoc fără să verifici impactul asupra PV și rapoartelor.
- Dacă un PV consumă produse, verifică dacă lotul, cantitatea și produsul sunt salvate corect.

---

## Reguli pentru facturare

- Aplicația nu emite facturi intern.
- Facturarea se gestionează extern.
- Nu reintroduce integrarea Oblio.
- Oblio este abandonat complet în acest proiect.
- CRM-ul poate pregăti date pentru facturare sau poate marca intervenții ca:
  - De facturat;
  - Facturată;
  - Nu se facturează.
- Valorile financiare nu trebuie afișate inutil tehnicienilor din teren.
- Dacă se modifică flow-ul de facturare, trebuie păstrată separarea dintre teren și birou.

---

## Reguli pentru cod

- Păstrează codul simplu și ușor de urmărit.
- Nu introduce abstracții complicate fără nevoie.
- Nu duplica funcții existente.
- Înainte să creezi o funcție nouă, verifică dacă există deja una similară.
- Respectă structura actuală a fișierelor.
- Nu schimba numele variabilelor existente dacă nu este necesar.
- Nu amesteca logica PHP cu HTML mai mult decât este deja cazul în proiect.
- Nu modifica simultan mai multe module fără motiv clar.
- Nu lăsa cod mort, console.log inutil sau comentarii temporare.
- Nu ascunde erori importante.
- Nu suprascrie validări existente.
- Nu slăbi securitatea pentru a face o funcție să meargă mai repede.

---

## Reguli pentru baza de date

- Nu modifica tabelele existente fără să explici impactul.
- Pentru orice modificare de structură, propune mai întâi SQL-ul.
- Nu șterge coloane.
- Nu redenumi coloane fără migrare clară.
- Nu presupune că o coloană există; verifică fișierele relevante.
- Dacă adaugi o coloană nouă, explică unde este folosită.
- Dacă adaugi un tabel nou, explică rolul lui și relațiile cu tabelele existente.
- Nu schimba date existente fără backup sau fără instrucțiuni clare.
- Nu face operațiuni destructive fără confirmare explicită.

---

## Reguli pentru securitate și roluri

- Respectă rolurile existente: administrator, birou, tehnician/operator sau alte roluri definite în aplicație.
- Nu oferi tehnicienilor acces la funcții de birou dacă nu este cerut explicit.
- Nu afișa valori financiare către teren dacă flow-ul actual le ascunde.
- Nu elimina verificările de autentificare.
- Nu elimina verificările de permisiuni.
- Nu expune date sensibile în URL, HTML sau JavaScript dacă nu este necesar.
- Nu afișa erori SQL brute către utilizator.
- Acțiunile de ștergere trebuie protejate.

---

## Reguli pentru intervenții pe cod

Înainte de modificare:
1. Citește fișierele relevante.
2. Citește pagina/fișierul `ui_template` dacă modificarea atinge UI.
3. Explică pe scurt ce ai înțeles.
4. Spune ce fișiere urmează să modifici.
5. Confirmă dacă modificarea atinge baza de date.
6. Identifică butoanele și flow-urile afectate.
7. Nu modifica alte zone necerute.

După modificare:
1. Prezintă pe scurt ce ai schimbat.
2. Spune ce fișiere au fost modificate.
3. Spune ce trebuie testat.
4. Menționează dacă există riscuri.
5. Arată clar dacă a fost afectată baza de date.
6. Menționează ce butoane și flow-uri ai verificat.
7. Menționează ce nu ai putut verifica.
8. Nu pretinde că ai testat dacă nu ai rulat efectiv testul.

---

## Checklist obligatoriu după fiecare modificare

După fiecare modificare, Codex trebuie să verifice sau să ceară verificarea următoarelor:

### 1. Verificare vizuală
- Pagina se încarcă fără erori.
- Layout-ul este corect pe desktop.
- Layout-ul este corect pe mobil.
- Stilul respectă `ui_template`.
- Nu apar texte tăiate, suprapuse sau aliniate greșit.

### 2. Verificare funcțională
- Butoanele principale funcționează.
- Butoanele secundare funcționează.
- Linkurile duc unde trebuie.
- Formularele salvează corect.
- Căutarea și filtrele funcționează, dacă există.
- Modalele se deschid și se închid corect.
- Dropdown-urile funcționează.
- Validările apar corect.
- Mesajele de succes/eroare apar corect.

### 3. Verificare flow
- Flow-ul de creare funcționează.
- Flow-ul de editare funcționează.
- Flow-ul de ștergere funcționează, dacă există.
- Flow-ul de revenire/înapoi funcționează.
- Flow-ul nu pierde datele completate inutil.
- Redirectările sunt corecte.

### 4. Verificare tehnică
- Nu există erori PHP.
- Nu există erori JavaScript în consolă.
- Nu există erori SQL.
- Nu există warning-uri vizibile.
- Nu au fost rupte include-uri, rute sau dependențe.
- Nu au fost introduse funcții duplicate.

### 5. Verificare roluri
- Administratorul vede acțiunile potrivite.
- Biroul vede acțiunile potrivite.
- Tehnicianul vede doar acțiunile permise.
- Acțiunile interzise nu sunt accesibile prin URL direct.

---

## Reguli pentru taskuri Codex

Taskurile trebuie făcute punctual.

Exemple bune:
- „Modifică doar etichetele din fișa de programare.”
- „Schimbă afișarea orelor în format 00–24.”
- „Adaugă validare pentru minute doar 00 sau 30.”
- „Aplică stilul din pagina de design pe clients.php.”
- „Verifică toate butoanele din fișa de programare după modificare.”
- „Modifică doar butonul de emitere PV, fără să schimbi restul flow-ului.”

Exemple de evitat:
- „Refă tot modulul.”
- „Optimizează aplicația.”
- „Repară tot calendarul.”
- „Fă designul mai bun.”
- „Curăță tot codul.”
- „Rescrie pagina de la zero.”

Dacă cererea este prea largă, Codex trebuie să propună pași mici și siguri.

---

## Format recomandat pentru răspunsurile Codex

Când primește un task, Codex trebuie să răspundă structurat:

### Ce am înțeles
Explică pe scurt cerința.

### Fișiere relevante
Listează fișierele pe care le-a verificat sau urmează să le verifice.

### Impact bază de date
Spune clar dacă este sau nu nevoie de modificare SQL.

### Plan de modificare
Descrie pașii, fără detalii inutile.

### După implementare
Prezintă:
- ce a modificat;
- ce fișiere a modificat;
- ce butoane a verificat;
- ce flow-uri a verificat;
- ce trebuie testat manual;
- ce riscuri există.

---

## Reguli speciale pentru cPanel / server

- Proiectul rulează pe cPanel/Romarg.
- Nu presupune acces SSH permanent.
- Nu presupune că pot fi rulate comenzi Composer pe server fără confirmare.
- Nu introduce dependențe care cer configurări complexe fără justificare.
- Codul trebuie să funcționeze în mediul actual PHP + MySQL/MariaDB.
- Dacă este nevoie de upload manual de fișiere, spune exact ce fișiere trebuie urcate.
- Dacă este nevoie de SQL, furnizează scriptul separat și clar.
- Nu cere schimbări de server dacă există o soluție simplă în cod.

---

## Prioritate reguli

Ordinea de prioritate este:

1. Cerința explicită a utilizatorului.
2. Acest fișier `AGENTS.md`.
3. Pagina oficială de design `https://app.pestzone.ro/ui_template` și fișierul local care o generează.
4. Structura existentă a aplicației.
5. Cele mai bune practici generale.

Dacă există conflict între reguli, Codex trebuie să explice conflictul și să aleagă varianta cea mai sigură pentru proiect.

---

## Principiul principal

Codex trebuie să se comporte ca un programator atent într-un proiect aflat deja în producție:

- modifică puțin și sigur;
- verifică înainte să schimbe;
- respectă designul existent;
- verifică toate butoanele și flow-urile paginii afectate;
- nu strică funcționalități existente;
- explică ce a făcut;
- spune sincer ce nu a putut testa.
