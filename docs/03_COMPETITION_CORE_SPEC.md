# SOA HTC — Specifikacija prve verzije: Competition Core

Status: radni nacrt 0.1  
Datum: 2026-08-11

## 1. Svrha

Competition Core je najmanji produkciono upotrebljiv sistem koji omogućava pripremu sezone, registraciju takmičara, kreiranje i polaganje testova, ocenjivanje, objavu rezultata i osnovne istorijske izveštaje, uz najmanje 10.000 istovremenih aktivnih takmičarskih sesija.

## 2. In scope

- seasons, countries, regions, schools;
- admin, country coordinator i school coordinator uloge;
- sezonske role/scope dodele bez brisanja naloga;
- difficulty categories/levels;
- sezonske student registracije i competitor number;
- Quiz → Exam → Test → Question sadržaj;
- competition i sample režim;
- mapiranje sadržaja prema level-u;
- web pristup bez klasičnog login-a;
- mobilni account API: email, Terms/Privacy, link za postavljanje lozinke i povezivanje sa registracijom;
- attempt, server-side timer, final submit bez autosave-a i jedan pokušaj;
- multiple-choice, fill-the-gap i essay;
- automatsko i ručno ocenjivanje;
- admin objava rezultata;
- audit reset/korekcija/objava;
- osnovni operativni i istorijski reporting;
- localization infrastruktura, inicijalno samo engleski;
- Theme API potreban klijentima može biti minimalan, dok puni Theme admin ekran može do CMS faze.

## 3. Van scope-a prve verzije

- offline mobilno polaganje;
- pouzdano povezivanje iste osobe kroz više sezona;
- puni CMS i uređivanje navigacije;
- redizajn accounting pravila;
- napredna BI analitika i neodobrene ranking formule;
- proizvoljan page builder ili custom CSS;
- sertifikati i široki import/export paket, osim migracionih/admin alata potrebnih za start.

## 4. Akteri i autorizacija

### Administrator

Upravlja master podacima, sezonama, sadržajem, registracijama, pokušajima, ocenjivanjem, objavom i izveštajima. Svaka osetljiva akcija se auditira.

### Country coordinator

Posebna uloga sa country scope-om. Tačne CRUD dozvole ostaju za potvrdu; implementacija ne sme ovu ulogu spojiti sa school coordinator-om.

### School coordinator

Vidi i menja samo dodeljene škole i njihove registracije/rezultate u dozvoljenom sezonskom scope-u. Backend policy važi na svakom endpoint-u.

### Takmičar

Na web-u dobija ograničenu session autentikaciju nakon provere competitor broja, zemlje, datuma rođenja i, u competition režimu, quiz password-a. Na mobile-u koristi verifikovan email nalog povezan sa sezonskom registracijom.

## 5. Funkcionalni epics

### CC-01 — Sezona i organizacija

- Admin kreira/aktivira sezonu i njen `round_number`.
- Country/region/school master podaci ostaju stabilni.
- Uloge i scope se dodeljuju po sezoni.
- Nova sezona ne briše stare naloge ili istorijske dodele.

Acceptance:

- deaktiviranje dodele u novoj sezoni ne menja izveštaje prethodne sezone;
- coordinator API vraća samo dozvoljeni scope.

### CC-02 — Registracija takmičara

- Registracija pripada jednoj sezoni, školi, zemlji i difficulty level-u.
- `competitor_number = round_number || LPAD(legacy/student sequence, 5, '0')` za migrirane podatke i kompatibilno generisanje novih brojeva.
- Broj se čuva kao tekst i globalno je jedinstven.
- Promena level-a nakon attempt-a je ograničena i auditovana.

Acceptance:

- isti učenik može imati različit broj u različitim sezonama;
- broj jednoznačno vodi do registracije i njenih rezultata;
- povezivanje osobe kroz sezone nije uslov.

### CC-03 — Assessment authoring

- Admin kreira uređenu hijerarhiju Quiz → Exam → Test → Question.
- Test ima trajanje, max bodove, status i mapirane level-e.
- Pitanja podržavaju tekst, sliku/audio i bodove.
- Tačni odgovori se nikada ne šalju studentskom klijentu.
- Aktivirana verzija testa ostaje stabilna za započete pokušaje.

Acceptance:

- nekonzistentan test ne može biti aktiviran;
- direktan API zahtev za pogrešan level je odbijen;
- sample rezultat ne ulazi u zvanične rezultate.

### CC-04 — Web identifikacija

- Forma traži competitor number, country i datum rođenja.
- Competition režim dodatno traži quiz password; sample ne.
- Odgovori na neuspešnu proveru ne otkrivaju koji je podatak bio tačan.
- Uspeh kreira kratkotrajnu, opozivu session autentikaciju ograničenu na jednu registraciju.

Acceptance:

- rate limit sprečava masovno pogađanje;
- session ne omogućava pristup drugoj registraciji;
- istekao ili opozvan token je odbijen.

### CC-05 — Mobilni nalog

- Unos emaila i eksplicitno prihvatanje Terms/Privacy.
- Jednokratni, vremenski ograničeni link potvrđuje email i vodi do postavljanja lozinke.
- Čuva se verzija i vreme prihvatanja pravnih dokumenata.
- Roditeljska saglasnost je van aplikacije.
- Nalog se posebnom proverom povezuje sa registracijom.

Acceptance:

- token se ne čuva u čistom obliku i ne može se upotrebiti dva puta;
- neaktivan/nepovezan account ne može pokrenuti test;
- web i mobile ne mogu paralelno otvoriti dva validna attempt-a.

### CC-06 — Lista dostupnih testova

Backend računa status iz:

- aktivne sezone i quiz-a;
- competition/sample režima;
- registracionog level-a;
- configured redosleda/dostupnosti;
- postojećeg attempt/result statusa;
- eventualnog reset/void stanja.

Klijent prikazuje backend status, ali ne donosi konačnu odluku o autorizaciji.

### CC-07 — Start testa

- Server ponovo proverava sva pravila neposredno pre starta.
- Start je atomaran i idempotentan.
- Attempt dobija `started_at`, `expires_at`, test version i channel.
- Klijent dobija pitanja bez rešenja i server-derived preostalo vreme.

Acceptance:

- dvoklik/retry vraća isti attempt;
- jedan attempt sa weba blokira novi sa mobile-a;
- refresh vraća isti attempt i ne menja rok.

### CC-08 — Odgovaranje i završni submit

- Nema autosave-a.
- Klijent drži odgovore lokalno dok ne pošalje kompletan payload.
- Ručna potvrda ili timeout šalju isti idempotentni finish endpoint.
- Server validira pitanja i tipove prema attempt test snapshot-u.
- Posle uspeha odgovor i početni rezultat su trajno sačuvani.

Acceptance:

- retry posle mrežne greške ne pravi duplikate;
- nepoznato pitanje/opcija u payload-u je odbijeno;
- server ne prihvata izmene završenog attempt-a;
- ponašanje kada browser nestane pre submit-a ostaje otvoreno i mora biti potvrđeno.

### CC-09 — Ocenjivanje

- Multiple-choice se ocenjuje prema konfiguraciji.
- Fill-the-gap se ocenjuje prema formalno potvrđenoj normalizaciji.
- Essay ostaje `pending_grading` do admin ocene.
- Ručna ocena čuva grader-a, vreme, poene i belešku.

Acceptance:

- essay ne dobija automatske poene;
- total je zbir proverljivih komponenti;
- korekcija čuva prethodnu vrednost i razlog.

### CC-10 — Objavljivanje rezultata

- Takmičar ne vidi bodove dok rezultat nije `published`.
- Admin vidi pending/graded rezultate prema scope-u.
- Publication akcija je autorizovana, idempotentna i auditovana.
- Nivo objave (po rezultat/test/exam/quiz) treba potvrditi.

### CC-11 — Reset pokušaja

- Admin može odobriti novo polaganje.
- Stari attempt/result postaje `void`; ne briše se.
- Razlog je obavezan.
- Novi attempt dobija sopstveni audit trag.

### CC-12 — Osnovni reporting

Filteri:

- season/year/round;
- country/region/school;
- coordinator u toj sezoni;
- student level;
- quiz/exam/test i težina.

Mere:

- registered, started, submitted, published, void;
- average/min/max/median kada je izvodljivo;
- prolaznost tek kada je prag definisan.

Istorijski snapshot sprečava da kasnija promena master podataka promeni stare izveštaje.

## 6. Nefunkcionalni zahtevi

### Performanse

- najmanje 10.000 istovremenih aktivnih sesija;
- kontrolisan start i timeout/submit talas;
- horizontalno skaliranje API i worker sloja;
- reporting/CMS ne ugrožavaju attempt tok.

### Pouzdanost

- idempotency za start, finish, publish i reset;
- transakcioni integritet attempt/answers/result;
- backup/restore i recovery procedure testirane;
- jasna obrada nepoznatog ishoda kada klijent izgubi mrežu tokom submit-a.

### Bezbednost

- object-level authorization na svakom endpoint-u;
- rate limiting i anti-enumeration;
- hash password-a i jednokratnih tokena;
- tačni odgovori i admin podaci nikada nisu deo student payload-a;
- audit osetljivih komandi;
- najniže potrebne DB/storage dozvole.

### Pristupačnost i lokalizacija

- sav tekst iz language fajlova, početno `en`;
- WCAG-orijentisan kontrast i tastaturna navigacija;
- server ne zavisi od prevedenog labela za poslovne odluke.

## 7. Ključni API resursi

Predložene grupe, bez zaključavanja tačnih URL-ova:

- admin auth/users/roles/assignments;
- seasons/countries/regions/schools/levels;
- registrations;
- quizzes/exams/tests/questions;
- student access session i mobile account;
- available assessments;
- attempts: start, state, finish;
- grading/publication/void;
- reports;
- legal documents/acceptances;
- current theme/localization metadata.

API koristi verzionisani ugovor, eksplicitne DTO-e i machine-readable error codes. Web i mobile dele ista poslovna pravila.

## 8. Release gate

Prva verzija ne ide u produkciju dok nisu zatvoreni:

- kritični otvoreni business rules;
- authorization testovi za sve uloge;
- reconciliation aktuelne baze;
- 10.000+ load test sa definisanim SLO-om;
- failure test za ponovljen start/submit i prekid mreže;
- backup/restore proba;
- monitoring, alerti i takmičarski runbook.

