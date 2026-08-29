# SOA HTC — Project Context

## 1. Svrha dokumenta

Ovaj dokument definiše početni poslovni i tehnički kontekst za novu verziju SOA HTC aplikacije. Postojeća aplikacija i anonimizovani SQL dump predstavljaju izvor postojećih pravila i podataka, ali nova aplikacija treba da bude zaseban, moderan Laravel + Vue SPA sistem.

Status informacija u dokumentu:

- **Potvrđeno** — pravilo koje je vlasnik proizvoda naveo ili koje se jasno vidi u postojećem kodu i bazi.
- **Predlog** — preporučeno rešenje za novu aplikaciju; može se promeniti pre implementacije.
- **Otvoreno** — potrebna je poslovna ili tehnička odluka.

## 2. Poslovni cilj

SOA HTC je platforma za organizovanje takmičenja i testova iz engleskog jezika. Sistem upravlja školama, koordinatorima, takmičarima, godišnjim kvizovima, rundama takmičenja, testovima, pitanjima, odgovorima, rezultatima i rangiranjem.

Nova verzija treba da:

- sačuva potvrđenu poslovnu logiku starog sistema;
- omogući bezbedan i pouzdan rad velikog broja takmičara;
- obezbedi strogu kontrolu pristupa podacima po ulozi i školi;
- podrži migraciju istorijskih podataka;
- ukloni tehnički dug i bezbednosne rizike iz legacy aplikacije;
- bude API-first sistem sa Vue SPA web klijentom i budućim Android/iOS mobilnim aplikacijama za takmičare;
- omogući da takmičar uradi isti dozvoljeni test sa računara, telefona ili tableta, uz ista poslovna i bezbednosna pravila.
- omogući promenu logotipa i vizuelne teme bez izmene ili novog deployment-a aplikacionog koda;
- obezbedi CMS za javni deo sajta sa stranicama, objavama, kategorijama, rasporedima stranica i upravljivom Public/Admin navigacijom;
- prenese postojeći accounting modul uz funkcionalno isto poslovno ponašanje, bez redizajna pravila u početnom scope-u;
- ugradi višejezičnu infrastrukturu od početka, uz engleski kao jedini inicijalno aktivan jezik i obavezno korišćenje language fajlova umesto hardkodovanog tekstualnog interfejsa.

### 2.1 Kapacitet i sezonski pikovi

**Potvrđeno:** tokom preliminary i semifinal faza može oko 10.000 takmičara, potencijalno i više, da radi testove istovremeno. Novi sistem zato ne sme biti projektovan samo prema prosečnom saobraćaju. Kritični pikovi su identifikacija/pristup quiz-u, gotovo istovremeno pokretanje testa i talas završnih submit zahteva po isteku vremena.

**Ciljni zahtev:** competition core mora biti horizontalno skalabilan i pre produkcije proći load/stress test sa najmanje 10.000 istovremenih aktivnih takmičarskih sesija, uz posebno testiranje start i timeout/submit talasa. Konačni performance SLO i sigurnosna margina biće definisani kada budu poznati infrastruktura, realan raspored početka testova i prosečan broj pitanja/odgovora po submit-u.

## 3. Izvori konteksta

- Postojeći repository: `dakakiki/soa-htc`, grana `master`.
- Postojeći stack: Laravel 7, PHP 7.2.5/8.0, Laravel Mix 5, Vue 2 tooling i Bootstrap 4.
- Anonimizovani dump `soahtc_soa2024_anonimize.sql`: približno 322 MB, 49 tabela.
- Poslovni opis vlasnika proizvoda iz avgusta 2026.

Dump je korišćen za analizu strukture tabela, tipova kolona i veza. Ne treba ga commitovati u Git niti koristiti kao produkcionu bazu.

## 4. Terminologija i hijerarhija takmičenja

| Pojam | Značenje | Primer | Relacija |
| --- | --- | --- | --- |
| Quiz | Najviši nivo jednog ciklusa takmičenja | Takmičenje za 2026. godinu | Sadrži uređenu listu exam-a |
| Exam | Runda/faza unutar quiz-a | Preliminary, Semifinal, Final | Sadrži uređenu listu testova |
| Test | Jedna oblast/provera u okviru exam-a | Reading, Writing, Listening | Sadrži uređenu listu pitanja |
| Question | Pitanje sa bodovima i tipom odgovora | Multiple choice, fill the gap, essay | Ima ponuđene odgovore ili tekstualni unos |
| Difficulty category / level | Klasifikacija nivoa učenika i težine sadržaja | Legacy tabele `difficulty_categories` i `difficulty_category_levels` | Određuje koje exam-e/testove takmičar može da vidi i pokrene |
| Attempt | Jedno pokretanje testa od strane takmičara | Početak testa sa server-side rokom | Čuva status, vreme i odgovore |
| Result | Obračunati rezultat završenog pokušaja | 27.5 poena | Vezuje takmičara, quiz, exam i test |

Potvrđena hijerarhija:

```text
Quiz → Exam → Test → Question → Answer options / submitted answer
```

Redosled exam-a u quiz-u i testova u exam-u je poslovno značajan.

Quiz/test sadržaj može biti dostupan u dva režima:

- **takmičarski režim** — takmičar se identifikuje i unosi lozinku quiz-a;
- **sample režim** — služi za vežbanje i ne zahteva quiz lozinku.

Sample pokušaji i rezultati ne smeju uticati na zvanične takmičarske rezultate, rangiranje ili pravo na sertifikat.

Takmičar sadržaju može pristupati kroz dva klijentska kanala:

- **web na računaru** — bez klasičnog korisničkog naloga/login-a; pristup se odobrava nakon provere više podataka takmičara;
- **Android/iOS aplikacija** — takmičar registruje mobilni nalog pomoću email adrese, obavezno prihvata važeće Terms & Conditions i obaveštenje o privatnosti, a zatim preko jednokratnog email linka postavlja lozinku; nalog se potom bezbedno povezuje sa odgovarajućom sezonskom prijavom.

Izbor klijenta ne menja pravila za quiz password, difficulty level, redosled/dostupnost testova, jedan pokušaj, tajmer, submit, ocenjivanje ili objavljivanje rezultata.

## 5. Korisničke uloge i pristup

### 5.1 Administrator (`user_level = 10` u legacy sistemu)

**Potvrđeno:** najviši nivo pristupa.

Očekivane mogućnosti:

- upravljanje korisnicima, koordinatorima, školama, zemljama i regionima;
- upravljanje quiz/exam/test/question sadržajem;
- upis i izmena takmičara;
- pregled, izvoz, objavljivanje, arhiviranje i kontrolisana korekcija rezultata;
- upravljanje postavkama takmičenja i sistemskim dozvolama;
- pregled audit loga.

### 5.2 Country coordinator (`user_level = 5` u legacy sistemu)

**Potvrđeno:** country coordinator je poseban nivo između administratora i school coordinator-a. Njegov konačni scope i dozvole moraju se potvrditi, ali legacy nivo `5` mora se pravilno migrirati i ne sme se spojiti sa school coordinator u jednu nejasnu ulogu.

Na početku nove sezone postojeći country coordinators ostaju u sistemu, ali postaju neaktivni dok ih administrator ne aktivira za novu sezonu.

### 5.3 School coordinator (`user_level = 1` u legacy sistemu)

**Potvrđeno:** pristupa isključivo školama koje su mu dodeljene i pripadajućim takmičarima i rezultatima.

Očekivane mogućnosti:

- pregled dodeljenih škola;
- upis i održavanje takmičara u okviru dodeljenih škola, prema dozvolama;
- pregled rezultata takmičara iz dodeljenih škola;
- dozvoljeni uvozi/izvozi i dokumenti samo za sopstveni scope.

Svaki API endpoint mora serverski da primeni school scope. Sakrivanje podataka u Vue interfejsu nije autorizacija.

### 5.4 Takmičar (student)

**Potvrđeno:** takmičar nema administratorski/koordinatorski nalog. Na web-u sa računara pristupa bez klasičnog login-a, kroz javni tok identifikacije pomoću jedinstvenog ID-a, zemlje i datuma rođenja. U budućoj Android/iOS aplikaciji registruje poseban takmičarski nalog pomoću email adrese. Registracija zahteva prihvatanje važećih Terms & Conditions i obaveštenja o privatnosti, nakon čega sistem šalje email sa jednokratnim linkom za potvrdu adrese i prvo postavljanje lozinke. U takmičarskom režimu dodatno je potrebna lozinka quiz-a; u sample režimu quiz lozinka nije potrebna.

Mobilni nalog i sezonska prijava nisu isti entitet. Email nalog predstavlja autentikovani pristup mobilnoj aplikaciji, dok `StudentRegistration` i `competitor_number` predstavljaju konkretnu prijavu za sezonu. Jedan mobilni nalog mora biti eksplicitno i proverljivo povezan sa odgovarajućom sezonskom prijavom pre nego što može da otvori takmičarski sadržaj. Email adresa sama po sebi ne određuje level niti daje pravo na test.

Takmičar može da:

- vidi dostupne exam-e i pripadajuće testove;
- vidi završene testove i rezultate, kada su rezultati dozvoljeni za prikaz;
- jasno vidi sledeći dozvoljeni test;
- pokrene dozvoljeni test;
- odgovara na pitanja do isteka vremena;
- potvrdi završetak i vrati se na pregled.

### 5.5 Nova sezona i životni ciklus koordinatora

Legacy priprema za sledeću sezonu trenutno radi ovako:

- svi korisnici nivoa `1` (school coordinators) se brišu;
- korisnici nivoa `5` (country coordinators) se označavaju kao neaktivni.

**Predlog za novu aplikaciju:** ne brisati korisničke naloge radi pripreme sezone. Razdvojiti trajni identitet korisnika od njegove uloge, dodeljenog scope-a i aktivnosti u konkretnoj sezoni. Sezonska tabela članstva/dodela treba da čuva `season_id`, `user_id`, ulogu, status aktivacije i school/country scope. Time se za novu sezonu deaktiviraju ili ne prenose stare dodele, dok audit i istorijski izveštaji ostaju potpuni.

Legacy brojevi `10`, `5` i `1` mogu se koristiti kao migraciona mapa, ali novi kod treba da koristi imenovane role/enum vrednosti i policies, ne rasute numeričke provere.

### 5.6 Jedinstveni broj sezonske prijave takmičara

**Potvrđeno:** tabela `el_settings` sadrži `round_number`, koji se menja za svaku novu godinu/sezonu. Javni broj takmičara formira se konkatenacijom:

```text
competitor_number = round_number + student_id dopunjen vodećim nulama na 5 cifara
```

Ekvivalentno pravilo je `round_number || LPAD(student_id, 5, '0')`. Time broj takmičara ostaje globalno jedinstven između različitih sezona/rundi čak i kada se lokalni `student_id` ponavlja u odvojenim godišnjim bazama.

**Potvrđeno:** isti učenik ne zadržava isti `competitor_number` u sledećoj sezoni. `student_id` se dodeljuje prilikom prijave i zavisi od trenutka/redosleda registracije. Na primer, ista fizička osoba može imati broj `1300055` u sezoni 2025. i `1400327` u sezoni 2026. Zato `competitor_number` identifikuje jednu konkretnu sezonsku registraciju, a ne trajni identitet osobe.

**Predlog za novu aplikaciju:** sačuvati originalni broj kao tekstualno polje `legacy_competitor_number` ili `competitor_number`, sa jedinstvenim indeksom. Ne koristiti ga kao primarni ključ niti za automatsko povezivanje osobe kroz godine. Novi `students`/`student_registrations` zapisi treba da imaju zasebne interne ID-jeve, dok se javni broj koristi za pristup, migraciju, pretragu i povezivanje legacy rezultata isključivo sa odgovarajućom sezonskom registracijom. Tekstualni tip je važan kako se vodeće nule ne bi izgubile.

`round_number`, sezona i izvorna baza moraju se čuvati uz migracioni zapis. Migraciona provera mora potvrditi da nema duplikata i da se svaki uvezeni rezultat može povezati sa tačnim brojem takmičara.

### 5.7 Nivo učenika i dostupnost sadržaja

**Potvrđeno:** `level` učenika je obavezan poslovni podatak. U legacy bazi njegova definicija i mapiranje postoje kroz tabele `difficulty_categories` i `difficulty_category_levels`. Ovaj podatak nije samo oznaka za izveštavanje: na osnovu nivoa sezonske prijave sistem određuje koje exam-e i testove takmičar sme da vidi i pokrene.

Nova aplikacija mora normalizovati ovaj model i sačuvati pouzdanu legacy ID mapu. Sezonska prijava takmičara mora referencirati tačno definisan nivo, a exam/test sadržaj mora imati eksplicitno mapirane dozvoljene nivoe. Jedan exam ili test može biti dostupan jednom ili više nivoa samo kada to postoji u konfiguraciji.

Pravila dostupnosti moraju biti sprovedena na dva mesta:

- SPA prikazuje samo exam-e/testove dozvoljene za nivo aktuelne sezonske prijave;
- backend ponovo proverava isto pravilo pri listanju, otvaranju i pokretanju testa, nezavisno od frontend-a.

Direktan ili ručno izmenjen API zahtev ne sme omogućiti pristup exam-u/testu drugog nivoa. Promena nivoa nakon započetog ili završenog pokušaja mora biti ograničena, auditovana i ne sme retroaktivno promeniti istorijski rezultat; rezultat/reporting snapshot čuva nivo koji je važio u trenutku pokušaja.

## 6. Glavni tok takmičara

### 6.1 Web pristup sa računara

1. Administrator ili koordinator upisuje takmičara u sistem i vezuje ga za školu, zemlju i nivo/kategoriju.
2. Takmičar otvara javnu stranicu za pristup.
3. Unosi jedinstveni ID, zemlju i datum rođenja. U takmičarskom režimu unosi i lozinku quiz-a; sample režim ne zahteva lozinku.
4. Backend proverava identitet, aktivnost takmičara, aktivni quiz, nivo sezonske prijave iz modela `difficulty_categories` / `difficulty_category_levels`, režim pristupa i, kada je potrebno, quiz lozinku.
5. Nakon uspešne provere kreira se kratkotrajna, ograničena takmičarska sesija.
6. SPA prikazuje samo exam-e/testove mapirane na učenikov nivo, sa statusima: zaključan, sledeći, dostupan, u toku, završen i rezultat objavljen.
7. Takmičar pokreće dozvoljeni test, a backend neposredno pre kreiranja attempt-a ponovo proverava level mapiranje.
8. Backend atomarno kreira attempt i beleži `started_at` i `expires_at`. Server je autoritet za preostalo vreme.
9. Takmičar odgovara na multiple-choice, fill-the-gap i, gde postoji, essay pitanja.
10. Odgovori se ne upisuju tokom rada. Po ručnoj potvrdi frontend šalje kompletan skup odgovora, a backend atomarno završava attempt, čuva odgovore i računa automatski ocenjive stavke.
11. Kada vreme istekne dok je test otvoren, frontend automatski šalje postojeće odgovore, a backend idempotentno završava attempt.
12. Takmičar se vraća na pregled. Rezultat vidi tek kada ga administrator objavi i vidi koji test može sledeći da radi.

### 6.2 Android/iOS pristup

1. Takmičar instalira aplikaciju i započinje registraciju unosom email adrese.
2. Pre završetka registracije mora eksplicitno prihvatiti važeće Terms & Conditions i potvrditi da je upoznat sa obaveštenjem o privatnosti i svrhom obrade podataka. Prihvatanje nije unapred označeno.
3. Sistem šalje email sa vremenski ograničenim, jednokratnim linkom. Otvaranjem linka takmičar potvrđuje vlasništvo nad adresom i postavlja početnu lozinku.
4. Tek nakon uspešnog postavljanja lozinke nalog postaje aktivan, a prihvatanje dokumenata se auditovano beleži uz njihove verzije i vreme prihvatanja.
5. Nalog se bezbedno povezuje sa tačnom sezonskom prijavom takmičara. Konačna procedura povezivanja mora sprečiti da korisnik prisvoji tuđu prijavu i ostaje otvorena poslovna odluka.
6. Nakon autentikacije aplikacija od API-ja dobija dostupne quiz-eve, exam-e i testove samo za level povezane sezonske prijave.
7. Za takmičarski režim aplikacija zahteva i proverava quiz password; sample sadržaj radi bez quiz password-a.
8. Pokretanje, server-side tajmer, odgovaranje, završni submit, automatski submit po isteku vremena, jedan pokušaj i objavljivanje rezultata koriste isti backend tok kao web klijent.
9. API atomarno sprečava da web i mobilni klijent kreiraju dva aktivna pokušaja za istog takmičara i test.

Kada se Terms & Conditions ili obaveštenje o privatnosti materijalno promene, sistem mora znati koju je verziju nalog prihvatio i, prema definisanom pravilu, zahtevati prihvatanje nove verzije pre nastavka korišćenja aplikacije.

Saglasnost roditelja/staratelja za učešće maloletnog takmičara nije deo aplikacije niti odgovornost ovog sistema. Nju, kada je potrebna, pribavlja i vodi škola van aplikacije. Sistem je ne traži, ne proverava i ne čuva. Ovo ne ukida obavezu aplikacije da transparentno objasni sopstvenu obradu podataka kroz Terms & Conditions i Privacy dokumente niti obavezu da primeni odgovarajuća pravila čuvanja i brisanja podataka.

**Predlog:** prva verzija mobilnog testiranja treba da bude online-only. Offline testiranje, lokalno trajno čuvanje odgovora i kasnija sinhronizacija značajno komplikuju integritet tajmera, jedan pokušaj i zaštitu sadržaja, pa se ne podrazumevaju bez posebne odluke.

## 7. Pravila testiranja koja moraju biti eksplicitna

### 7.1 Tajmer

- Trajanje je definisano na testu.
- Backend čuva početak i krajnji rok; frontend samo prikazuje odbrojavanje.
- Refresh, zatvaranje taba ili promena uređaja ne smeju resetovati vreme.
- Server odbija odgovore posle isteka roka i pokreće idempotentno automatsko završavanje.
- Automatski submit pri isteku vremena čuva odgovore koji se u tom trenutku nalaze u otvorenom browseru.
- Pošto nema automatskog čuvanja, treba eksplicitno definisati ponašanje kada se browser zatvori ili veza prekine pre slanja završnog zahteva.

### 7.2 Pokušaji i redosled

- Dozvoljen je jedan pokušaj po takmičaru i testu.
- Administrator može omogućiti novi pokušaj resetovanjem prethodnog rezultata kroz administraciju/edit takmičara.
- Pokretanje i završavanje moraju biti idempotentni radi zaštite od dvoklika i ponovljenih HTTP zahteva.
- ~~Da li testovi moraju strogo da se rade redom još nije potvrđeno~~ — **razrešeno:** **ADR-0017** (strogo
  redom) i **ADR-0021** (sledeći čeka da admin objavi prethodni), suženo **ADR-0049** na **takmičarske**
  kvizove: u sample kvizu je svaki test otvoren od početka. Dostupnost i dalje određuje isključivo backend
  (`StudentAvailability`), a ne klijent.
- Reset rezultata mora biti autorizovana i auditovana komanda, ne javni `GET` endpoint. U novom sistemu prethodni attempt/result treba označiti kao poništen (`void`) ili arhivirati, umesto fizičkog brisanja audit istorije.

### 7.3 Čuvanje odgovora

- **Potvrđeno:** nema automatskog čuvanja pojedinačnih odgovora tokom testa.
- Kompletan skup odgovora upisuje se tek pri ručnoj potvrdi završetka ili automatskom submit-u po isteku vremena.
- Nastavak na drugom uređaju nije podržan bez dodatnog mehanizma čuvanja nacrta.

### 7.4 Tipovi pitanja

- **Multiple choice:** jedan ili više ponuđenih odgovora, u zavisnosti od konfiguracije.
- **Fill the gap:** tekstualni odgovor i skup dozvoljenih tačnih varijanti.
- **Essay:** čuva tekst za ručno ocenjivanje; ne sme automatski dobijati bodove.
- Pitanje može imati broj bodova, redosled, sliku i audio fajl.

**Otvoreno:** pravila za velika/mala slova, whitespace, interpunkciju, Unicode karaktere i delimične bodove kod fill-the-gap odgovora.

### 7.5 Rezultati

- Sačuvati sirove odgovore odvojeno od zbirnog rezultata.
- Rezultat mora imati status, npr. `pending`, `graded`, `published`, `void`.
- Takmičar vidi rezultat tek kada ga administrator objavi.
- Essay odgovore ocenjuje administrator pre objavljivanja konačnog rezultata.
- Ručne izmene bodova i reset moraju čuvati ko, kada i zašto je izvršio promenu.

## 8. Predložena arhitektura nove aplikacije

### 8.1 Ciljni stack

**Predlog za avgust 2026:**

- Laravel 13, PHP 8.3+;
- Vue 3.5+ sa Composition API i `<script setup>`;
- TypeScript;
- Vite;
- Vue Router;
- Pinia za mali broj zaista globalnih stanja;
- Laravel Sanctum za first-party SPA autentikaciju;
- token-based autentikacija prilagođena first-party Android/iOS aplikacijama, sa bezbednim čuvanjem tokena na uređaju i mogućnošću opoziva sesije;
- MySQL 8.x ili PostgreSQL, uz jednu potvrđenu bazu pre početka migracija;
- Redis za cache, rate limiting, session/queue potrebe i koordinaciju vremenski osetljivih operacija;
- queue worker za izvoz, sertifikate, masovne uvoze i duge obračune;
- PHPUnit ili Pest za backend i Vitest + komponentni/E2E testovi za ključne frontend tokove.
  🔴 **Frontend polovina je NAPUŠTENA** (vlasnik, 2026-08-29): Vitest je bio uveden pa povučen
  istog dana — pravi test je sajt na serveru. Vidi ADR-0074 u `DECISIONS.md` za ono što se time
  gubi i za sve što je usput naučeno, ako se odluka ikad promeni.

Patch/minor verzije treba zaključati tek prilikom inicijalizacije projekta na tadašnje stabilne verzije.

### 8.2 Skaliranje competition core-a

Za potvrđeni pik od 10.000+ istovremenih takmičara predložena arhitektura podrazumeva:

- stateless Laravel API instance iza load balancer-a, bez oslanjanja na lokalni disk ili memoriju jedne instance;
- Redis za distribuirane sesije, cache, rate limiting i kratke koordinacione lock-ove;
- pravilno indeksirane OLTP upite za identifikaciju, dostupnost testa, kreiranje attempt-a i finalni submit;
- jedinstvena ograničenja i kratke transakcije koje garantuju jedan aktivan attempt i idempotentan finish;
- asinhrone queue poslove za sertifikate, izvoze, agregatne statistike, obaveštenja i druge operacije koje nisu deo kritičnog submit odgovora;
- statičke/media resurse preko object storage-a i CDN-a, a ne kroz PHP aplikacione instance;
- izbegavanje skupih zbirnih upita i sinhronog generisanja dokumenata na studentskim endpointima;
- observability za latenciju, greške, DB konekcije, queue lag, Redis i broj aktivnih pokušaja;
- reprezentativan load test sa ramp-up, naglim početkom testa i submit talasom po isteku vremena.

Konačan izbor veličine infrastrukture ne treba pogađati unapred: određuje se merenjem nad realističnim podacima i payload-ima. Stara aplikacija je dokaz da je poslovni obim ostvariv, ali nije dovoljan kapacitet plan za novu implementaciju.

### 8.3 Organizacija

Preporučen je monorepo sa Laravel aplikacijom i Vue SPA kodom u istom projektu:

```text
app/
  Domain/
  Http/Controllers/Api/
  Http/Requests/
  Http/Resources/
  Policies/
  Actions/
database/
  migrations/
  seeders/
resources/js/
  api/
  components/
  features/
  pages/
  router/
  stores/
  types/
routes/
  api.php
tests/
```

Kontroleri treba da budu tanki. Validacija ide u Form Request klase, autorizacija u Policies/Gates, poslovne operacije u Actions/Services, a odgovori kroz API Resources.

### 8.4 Autentikacija i autorizacija

- Admin i coordinator koriste standardnu autentikaciju i session cookies preko Sanctum-a.
- Legacy nivoi uloga mapiraju se kao `10 → admin`, `5 → country_coordinator`, `1 → school_coordinator`; u novom domenu koriste se imenovane vrednosti ili referentna tabela.
- Coordinator-school je many-to-many relacija.
- Policies moraju proveravati i ulogu i pripadnost školi.
- Takmičarska sesija treba da bude odvojena od admin/coordinator autentikacije, kratkotrajna i ograničena na jednog takmičara i aktuelni quiz.
- Javni identifikacioni endpoint mora imati rate limiting, generičke poruke greške i zaštitu od enumeracije ID-eva i datuma rođenja.
- Web pristup takmičara ostaje bez klasičnog email login-a: uspešna provera jedinstvenog ID-a, zemlje i datuma rođenja izdaje samo kratkotrajnu, ograničenu takmičarsku sesiju.
- Mobilna aplikacija koristi verifikovan email nalog, lozinku i opozive API tokene/sesije. Pri registraciji sistem šalje jednokratni email link za potvrdu adrese i prvo postavljanje lozinke; taj link nije trajni login metod.
- Token za postavljanje lozinke mora biti kriptografski nasumičan, u bazi sačuvan samo kao hash, vremenski ograničen, jednokratan i poništen nakon upotrebe ili izdavanja novog linka. Endpoint-i moraju imati rate limiting i generičke odgovore koji ne otkrivaju da li email već postoji.
- Prihvatanje pravnih dokumenata beleži se odvojeno od samog naloga, najmanje sa `student_account_id`, tipom i verzijom dokumenta, vremenom prihvatanja i izvorom/kanalom. Izmene dokumenata ne smeju prepisati istorijski zapis prihvatanja.
- Veza mobilnog naloga i `StudentRegistration` mora biti serverski verifikovana, auditovana i zaštićena od account-enumeration i account-takeover napada. Samo poznavanje email adrese nije dokaz vlasništva nad sezonskom prijavom.
- Sve autorizacione provere moraju biti jednake za web i mobilni kanal: aktivna sezona/prijava, difficulty level, quiz režim/password, dostupnost testa i pokušaj proveravaju se na API-ju.
- Jedinstveno DB ograničenje/lock mora sprečiti paralelno pokretanje drugog attempt-a sa drugog uređaja ili klijenta.

### 8.5 Sezonski Theme Settings

**Potvrđeno:** logo i glavne boje se redizajniraju za svaku sezonu/godinu i očekuje se da web i mobilne aplikacije prate aktuelni vizuelni identitet. Administratoru je zato potreban Theme Settings modul koji ne zahteva izmenu izvornog koda.

**Potvrđeno:** postoji samo jedna aktuelna tema. Izmene postaju aktivne odmah kada ih administrator sačuva; nema `draft/published` toka, čuvanja prethodne teme niti rollback-a. Ista tema se primenjuje na javni sajt, studentski/takmičarski interfejs, admin/coordinator panel i Android/iOS aplikacije.

**Predlog:** aktivna tema se dobija kroz keširan API endpoint koji koriste Vue SPA i Android/iOS aplikacije. Uspešno čuvanje mora poništiti odgovarajući keš kako bi nova tema odmah postala dostupna svim klijentima. Promena teme ne sme uticati na pokušaje, rezultate ili druga poslovna pravila.

Administrator uređuje najviše pet osnovnih boja:

| Token | Namena |
| --- | --- |
| `primary` | glavne akcije i primarni brand elementi |
| `secondary` | sekundarne akcije i prateći brand elementi |
| `accent` | naglasci, aktivna stanja i istaknuti detalji |
| `page_background` | pozadina stranice/aplikacionog ekrana |
| `surface` | kartice, forme, modali i druge sadržajne površine |

Boje teksta/ikona na obojenoj podlozi ne treba računati kao dodatne ručno izabrane brand boje. Sistem ih automatski bira između bezbednih svetlih/tamnih foreground vrednosti i ne dozvoljava čuvanje kombinacije koja ne ispunjava dogovoreni WCAG kontrast. Semantičke statusne boje (`success`, `warning`, `error`, `info`) ostaju deo kontrolisanog design system-a i ne menjaju značenje po sezoni.

Theme Settings sadrži uređenu listu primena (component assignments), na primer:

```json
{
  "primary_button": { "enabled": true, "background": "primary", "foreground": "auto" },
  "secondary_button": { "enabled": true, "background": "secondary", "foreground": "auto" },
  "site_background": { "enabled": true, "background": "page_background" },
  "table_header": { "enabled": true, "background": "secondary", "foreground": "auto" },
  "navigation": { "enabled": true, "background": "primary", "foreground": "auto" },
  "card_surface": { "enabled": true, "background": "surface", "foreground": "auto" },
  "link_and_focus": { "enabled": true, "color": "accent" }
}
```

`enabled = false` znači da komponenta koristi stabilnu podrazumevanu vrednost design system-a. Dozvoljene vrednosti moraju dolaziti iz unapred definisane liste tokena i komponenti; administrator ne unosi proizvoljan CSS. Tema obuhvata najmanje desktop i mobile logo varijantu, opcionu ikonu/favicon i alt tekst. Upload mora proveriti tip, veličinu i dimenzije fajla, a klijenti moraju imati ugrađenu fallback temu ako konfiguracija ili asset nije dostupan.

Theme payload treba pretvoriti u zajedničke design tokene. Javni sajt, studentski deo i admin/coordinator panel mapiraju ih na iste CSS custom properties, dok mobilne aplikacije mapiraju isti ugovor na native theme resurse. Mobilna aplikacija kešira poslednju validnu temu, ali periodično proverava da li je tema promenjena kako bi novi dizajn stigao bez obaveznog objavljivanja nove verzije aplikacije kada platforma to dozvoljava.

### 8.6 CMS javnog sajta i navigacije

**Potvrđeno:** javni deo sajta koristi CMS module `page layouts`, `pages`, `categories` i `posts`. Nova aplikacija treba da zadrži ove funkcionalne celine i doda upravljanje navigacijama za Public i Admin deo.

CMS je odvojen od competition core-a: objavljivanje ili izmena javnog sadržaja ne sme uticati na aktivne pokušaje, tajmer, rezultate ili performanse API endpoint-a za testiranje.

Predloženi model sadržaja:

- `PageLayout` definiše dozvoljenu strukturu stranice i raspoložive content zone/slotove; nije proizvoljan izvršni kod niti slobodan Vue/PHP template koji admin unosi kroz CMS;
- `Page` predstavlja javnu stranicu sa naslovom, jedinstvenim slug-om, izabranim layout-om, sadržajem, SEO podacima, statusom i vremenom objave;
- `Category` organizuje objave hijerarhijski ili ravno, prema potvrđenoj potrebi;
- `Post` predstavlja javnu objavu/vest sa naslovom, slug-om, sažetkom, sadržajem, naslovnom slikom, kategorijama, autorom, statusom i vremenom objave;
- sadržaj podržava najmanje `draft` i `published` status kako nedovršena stranica ili objava ne bi bila javno dostupna;
- brisanje kategorije ne sme automatski obrisati njene objave, a promena slug-a treba da podrži redirect sa prethodne javne adrese kada je sadržaj već bio objavljen.

Navigacija je poseban domen i ne treba je hardkodovati u Vue komponentama:

| Navigacija | Namena | Pravila |
| --- | --- | --- |
| `public.header` | glavni meni u headeru javnog sajta | prikazuje samo aktivne stavke i javno dostupan sadržaj |
| `public.footer` | footer navigacija javnog sajta | prikazuje samo aktivne stavke i javno dostupan sadržaj |
| `admin.top` | top navigacija admin/coordinator panela | stavke se filtriraju prema ulozi i permissions; skrivanje linka ne zamenjuje backend autorizaciju |
| `admin.right_sidebar` | navigacija i kontekstualni moduli u desnom sidebaru admin panela | sadržaj i stavke zavise od aktivne stranice, uloge i permissions |

> **Kako je ovo na kraju rešeno:** predlog iznad je iz pripreme; sprovedene odluke su u `DECISIONS.md`
> — **ADR-0043** (zone su registar u kodu, sekcije su tipizovani blokovi), **ADR-0045** (`public.header`
> i `public.footer` su zone sa jednim zapisom, tabovi u `Website → Layout`), **ADR-0046** (svaki ekran
> koji nosi naslov i pasus dobija zonu; labele polja i dugmad ostaju interfejs) i **ADR-0047** (ekran sa
> dva toka dobija zonu po toku — `public.identify.competition` i `public.identify.sample`). `admin.top` i
> `admin.right_sidebar` iz tabele ispod **još nisu urađeni** — admin sidebar je i dalje data-driven u kodu.
>
> **OTVORENO — sezonski gate za stavke menija (2026-08-25):** dugme u layout bloku nosi `gate`
> (`competition`/`sample`) koji `LayoutButtons` proverava kroz `EntryWindow` — *postoji li aktivan kviz tog
> tipa* — pa van sezone ulaz za takmičenje nestane sam. **Stavka menija taj gate nema.** Otkad „Start Quiz"
> i „Sample Exam" u zaglavlju vode na `/student/access/{mode}` a ne na sidra na početnoj, van sezone vode na
> ekran na kojem se takmičar identifikuje i zatekne prazno. Rešenje kad dođe red: isto polje `gate` na
> `cms_menu_items`, provera na istom mestu gde se stavka razrešava (`MenuItem::resolvedHref()` odnosno
> resurs koji objavljuje meni), da postoji **jedno** pravilo o sezoni a ne dva.

`Navigation` ima stabilni ključ/lokaciju, a uređene hijerarhijske `NavigationItem` stavke podržavaju roditelja, redosled, naziv, aktivnost i cilj. Cilj treba prvenstveno da bude interna `Page`, `Post`, kategorija ili imenovana aplikaciona ruta; spoljašnji URL je dozvoljen samo kao eksplicitno označen tip sa validacijom protokola. Admin navigacija sme da referencira sistemske rute, ali njena izmena ne može dodeliti novu permission niti zaobići policy proveru.

Potvrđeni osnovni page shell-ovi su:

- javni deo: `header` → glavna `content` zona → `footer`;
- admin/coordinator deo: `top navigation` + glavna `content` zona + `right sidebar`.

Raspored definiše stabilne zone u aplikacionom shell-u. CMS/navigation konfiguracija određuje sadržaj i redosled dozvoljenih stavki unutar tih zona, ali ne omogućava administratoru da unese proizvoljan Vue/PHP kod.

Public sadržaj i navigacija treba da budu keširani i da ponište samo relevantni keš nakon izmene. Slike i drugi javni asset-i koriste kontrolisan upload, optimizovane varijante i CDN/object storage kada se definiše produkciona infrastruktura.

### 8.7 Višejezičnost i language fajlovi

**Potvrđeno:** višejezičnost je standardna mogućnost nove aplikacije, ali je u prvoj verziji aktivan samo engleski (`en`). Kao i u legacy aplikaciji, sav sistemski/UI tekst mora koristiti language fajlove; korisnički vidljiv tekst ne sme biti hardkodovan u Vue komponentama, Laravel kontrolerima/validatorima, email template-ima ili mobilnim ekranima.

Lokalizacija ima dva odvojena sloja:

1. **Aplikacioni prevodi** — ključevi za navigaciju, forme, dugmad, validacione poruke, statuse, email poruke i drugi sistemski tekst. Laravel, Vue i Android/iOS klijenti mogu imati platformskim alatima prilagođene kataloge, ali moraju koristiti dogovorene stabilne ključeve i isto poslovno značenje.
2. **CMS prevodi** — prevodiva polja za `Page`, `Post`, `Category` i nazive `NavigationItem` stavki. Model i API se od početka projektuju za locale-varijante, iako inicijalno postoji samo engleski sadržaj.

Podrazumevani i inicijalno jedini omogućeni locale je `en`. Sistem mora imati eksplicitnu listu omogućenih locale-a i kontrolisan fallback na engleski kada prevod nedostaje. Dodavanje novog jezika kasnije treba da zahteva unos prevoda i aktiviranje locale-a, a ne promenu šeme ili hardkodovanih komponenti. Slug i SEO pravila za dodatne jezike biće precizirana pre aktiviranja drugog locale-a.

Sadržaj koji administrator unosi kroz CMS nije language fajl. On se čuva kao lokalizovan poslovni sadržaj, dok statične oznake CMS editora i ostatka interfejsa ostaju u language fajlovima.

## 9. Predloženi domen i glavne relacije

| Entitet | Ključne relacije |
| --- | --- |
| User | trajni identitet admina/koordinatora; ima sezonske role/scope dodele i status aktivnosti |
| StudentAccount | autentikovani mobilni identitet takmičara sa verifikovanim emailom; odvojen od admin/coordinator `User` domena i povezan sa jednom ili više dozvoljenih sezonskih prijava prema potvrđenom pravilu |
| LegalDocument / AgreementAcceptance | verzionisani Terms & Conditions i obaveštenje o privatnosti, sa auditovanim prihvatanjem konkretne verzije od strane mobilnog naloga |
| Season | predstavlja jednu takmičarsku godinu/ciklus i grupiše sezonske dodele, quiz-eve i istorijske rezultate |
| ThemeSettings | jedna aktuelna tema sa pet osnovnih boja, mapiranjem komponenti i logo assetima; čuvanje je odmah aktivira na javnom, studentskom, admin/coordinator i mobilnom interfejsu |
| PageLayout | kontrolisana struktura javne CMS stranice sa unapred definisanim zonama/slotovima |
| Page | javna CMS stranica sa layout-om, slug-om, sadržajem, SEO podacima i statusom objave |
| Category | organizuje CMS objave i može imati roditeljsku kategoriju ako se potvrdi hijerarhija |
| Post | javna objava/vest povezana sa kategorijama, autorom, medijima i statusom objave |
| Navigation / NavigationItem | uređeni hijerarhijski meniji za `public.header`, `public.footer`, `admin.top` i `admin.right_sidebar`; nazivi su locale-aware, a admin stavke imaju role/permission vidljivost bez zamene backend autorizacije |
| UserSeasonAssignment | vezuje user-a, season, ulogu, aktivnost i country/school scope |
| School | pripada country/region; ima mnogo students i coordinators |
| Student | interni identitet takmičara u novoj aplikaciji; povezivanje iste fizičke osobe kroz različite sezone nije prioritet prve verzije |
| DifficultyCategory / DifficultyLevel | normalizuje legacy `difficulty_categories` i `difficulty_category_levels`; definiše nivo učenika i mapiranje dostupnog exam/test sadržaja |
| StudentRegistration | pripada season-u; čuva tadašnju školu, zemlju/region, obavezni difficulty level, relevantne koordinatorske dodele i globalno jedinstveni competitor number koji se dodeljuje pri prijavi; može biti bezbedno povezan sa mobilnim StudentAccount nalogom |
| Quiz | ima mnogo exams kroz uređenu pivot relaciju |
| Exam | ima mnogo tests kroz uređenu pivot relaciju; pripada round/type i ima eksplicitno mapirane dozvoljene difficulty nivoe |
| Test | ima mnogo questions kroz uređenu pivot relaciju; ima duration/type i, gde poslovni model zahteva, sopstveno mapiranje dozvoljenih difficulty nivoa |
| Question | ima type, points, media i answer definitions |
| Attempt | pripada student/quiz/exam/test; ima status i vremenske oznake |
| SubmittedAnswer | pripada attempt-u i pitanju; čuva izabranu opciju ili tekst |
| TestResult | pripada attempt-u; čuva zbir, status i publication state |
| ReportingSnapshot / AnalyticsFact | čuva nepromenljive dimenzije i mere potrebne za istorijske izveštaje po sezoni, školi, zemlji, koordinatoru, nivou i težini testa |
| AuditLog | beleži osetljive administratorske i koordinatorske akcije |
| LegacyIdMap | čuva izvorni sistem/godinu, tabelu i stari ID za proverljivu migraciju više istorijskih baza |

Za novu šemu treba koristiti strane ključeve, jedinstvene indekse, konzistentan `utf8mb4`, prave `date/datetime` tipove i InnoDB ako se koristi MySQL.

## 10. Nalazi iz legacy baze

Dump sadrži 49 tabela. Ključne grupe su:

- korisnici i pristup: `users`, `user_schools`, `user_activity_log`;
- organizacija: `schools`, `regions`, `el_country`;
- takmičari: `el_student`, privremene/import tabele, `difficulty_categories` i `difficulty_category_levels`;
- sadržaj testiranja: `quizzes`, `quiz_exams`, `exams`, `exam_tests`, `tests`, pitanja i odgovori;
- izvršavanje: `quiz_started`, `test_started`, `test_results`, `quiz_results`, `exam_results`;
- operativno: arhiva, statistika, accounting, CMS i Telescope tabele.

**Potvrđeno:** poslovni podaci iz referentnih/master tabela kao što su `el_country`, `schools` i `regions` moraju ostati sačuvani i migrirani u novu aplikaciju. Nova šema i nazivi tabela mogu biti normalizovani, ali nijedan postojeći entitet, identitet ili veza ne sme se izgubiti. Za proveru migracije treba čuvati stabilnu mapu legacy ID → novi ID.

Pored aktuelnog dump-a postoje baze sa rezultatima prethodnih godina. One treba da budu deo planirane istorijske migracije kako bi aplikacija omogućila višegodišnju istoriju i statistiku, umesto da svaka sezona ostane izolovana baza.

Legacy `el_settings.round_number` i `el_student.student_id` zajedno definišu globalno jedinstveni broj sezonske registracije: `round_number || LPAD(student_id, 5, '0')`. Taj broj je primarni migracioni ključ za povezivanje registracije takmičara sa rezultatima iz odgovarajuće sezone i mora ostati nepromenjen nakon uvoza. Ne sme se koristiti kao trajni identifikator osobe niti kao ključ za spajanje zapisa istog učenika iz različitih sezona, jer učenik svake godine dobija novi broj prema trenutku/redosledu prijave.

Važni migracioni problemi:

- nisu definisani SQL foreign keys u dump-u;
- koriste se mešani MyISAM/InnoDB engine-i;
- koriste se `latin1`, `utf8mb3` i `utf8mb4`;
- mnoge kolone imaju nevažeći `0000-00-00 00:00:00` datum;
- datum rođenja je `varchar`, umesto `date`;
- difficulty level i njegova mapiranja moraju se migrirati iz `difficulty_categories`, `difficulty_category_levels` i svih legacy CSV referenci bez gubitka veze između učenika i dozvoljenog exam/test sadržaja;
- postoje duplirani/legacy koncepti: `users` i `el_user`, kao i privremene tabele;
- rezultati postoje na više mesta i deo zbirnih vrednosti se upisuje direktno u student zapis;
- `test_results` ima više od 3,3 miliona redova prema AUTO_INCREMENT vrednosti, pa migracija mora biti chunkovana i merena;
- Telescope tabela pokazuje veoma veliki obim i ne treba je migrirati kao poslovni podatak.

## 11. Nalazi iz legacy koda i rizici

Postojeći kod potvrđuje opisani studentski tok, uključujući identifikaciju, session podatke, pokretanje testa, multiple choice, fill-the-gap, essay, obračun i povratak na pregled.

Rizici koji se ne smeju preneti u novu verziju:

- čitanje identifikatora student/quiz/exam/test direktno iz request-a bez dovoljne objektne autorizacije;
- oslanjanje na frontend tajmer ili samo na vreme kreiranja reda;
- neatomarno kreiranje pokušaja, odgovora i rezultata;
- javne debug i destruktivne rute, uključujući brisanje podataka preko `GET` zahteva;
- ruta za održavanje/cache operacije dostupna iz aplikacije;
- automatsko tretiranje essay odgovora kao uspešnog odgovora;
- case-sensitive fill-the-gap provera bez formalnog poslovnog pravila;
- poslovna logika i veliki SQL upiti direktno u modelima i kontrolerima;
- nejasne numeričke vrednosti za uloge, tipove i statuse;
- nedostatak deklarativnih foreign key ograničenja i jedinstvenih indeksa.

## 12. Strategija: rewrite umesto višestrukog framework upgrade-a

**Preporuka:** napraviti novu aplikaciju i postepeno preneti potvrđene module i podatke. Laravel 7 → 13 i Vue 2 → 3 uključuju više velikih prelaza, a postojeća šema i kontroleri zahtevaju značajan redizajn bez obzira na framework.

Predložene faze:

1. Zaključavanje poslovnih pravila i rečnika podataka.
2. Nova baza, autentikacija, uloge, schools i students.
3. Quiz/exam/test/question authoring.
4. Takmičarski pristup, attempt state machine, tajmer i odgovori.
5. Ocenjivanje, administratorsko objavljivanje rezultata i pregled.
6. Import/export i sertifikati.
7. Migracioni ETL sa mapama starih i novih ID-eva, dry-run izveštajima i reconciliation proverama.
8. Uvoz istorijskih baza po sezoni, sa deduplikacijom stabilnih entiteta (country/region/school), mapiranjem registracija preko globalno jedinstvenog competitor number-a i statističkim reconciliation proverama.
9. Paralelno acceptance i load/stress testiranje na kopiji anonimizovanih podataka.
10. Kontrolisani cutover i read-only arhiva stare aplikacije/baza.
11. CMS (`page layouts`, `pages`, `categories`, `posts`, Public/Admin navigation) i funkcionalno identičan prenos accounting modula nakon stabilizacije sistema takmičenja.

Prioritet isporuke je: competition core → sertifikati i import/export → CMS i accounting. Ranking ostaje deo competition core-a kada se potvrde pravila rangiranja.

Migracija treba da bude ponovljiva komanda, ne jednokratni ručni SQL. Svaka grupa podataka mora imati broj ulaznih, uspešnih, preskočenih i neuspešnih redova.

Istorijske baze ne treba odmah fizički spajati bez prethodnog profilisanja. Za svaki izvor treba evidentirati sezonu, poreklo, kolizije ID-jeva, duplikate škola/takmičara i kvalitet podataka. Statistika se zatim gradi nad normalizovanim podacima sa eksplicitnim `season_id`, uz mogućnost praćenja svakog reda do izvora.

### 12.1 Prioritet istorijske analitike

**Potvrđeno:** prioritet višegodišnje istorije nije povezivanje iste fizičke osobe kroz sezone. Prioritet je agregatna analiza učešća i rezultata po organizacionim, takmičarskim i vremenskim dimenzijama. `competitor_number` zato ostaje ključ jedne sezonske prijave i dovoljan je za povezivanje registracije sa njenim rezultatima u istorijskom uvozu.

Prva verzija analitike treba najmanje da podrži filtriranje i grupisanje po:

- sezoni/godini i round-u;
- zemlji i regionu;
- školi;
- country i school coordinator-u koji su bili relevantni u toj sezoni;
- nivou/kategoriji učenika;
- quiz-u, exam-u, fazi takmičenja i testu;
- tipu testa/predmeta i difficulty level-u.

Osnovne mere su:

- broj prijavljenih takmičara;
- broj takmičara koji su započeli i završili test;
- broj i procenat objavljenih/važećih rezultata;
- prosečan, minimalan, maksimalan i medijalni rezultat;
- raspodela rezultata i prolaznost kada postoji definisan prag;
- poređenje sa prethodnim sezonama i rangiranje škola/zemalja kada poslovna pravila to dozvole.

Istorijski izveštaj mora koristiti kontekst koji je važio u trenutku sezonske prijave ili rezultata. Kasnija promena naziva škole, zemlje, regiona, nivoa ili dodeljenog koordinatora ne sme retroaktivno promeniti ranije izveštaje. Zato treba čuvati sezonske veze i, gde je potrebno, reporting snapshot sa originalnim legacy ID-jevima i nazivima. Škole, zemlje i regioni se deduplikuju kao stabilni master podaci, ali se njihove istorijske veze i nazivi ne prepisuju bez auditovanog pravila.

Za operativne ekrane dovoljni su indeksirani upiti nad normalizovanom bazom. Skuplje višegodišnje preglede treba graditi preko unapred izračunatih agregata/materialized reporting tabela koje se osvežavaju asinhrono, kako analitika ne bi opterećivala kritični tok testiranja tokom pikova od 10.000+ takmičara.

### 12.2 Accounting — compatibility scope

**Potvrđeno:** accounting deo za sada treba da ostane funkcionalno identičan postojećem sistemu. U početnoj migraciji se ne menjaju njegova poslovna pravila, tokovi, obračuni, statusi, dozvole, prikazi ni izlazni izveštaji. Postojeći kod, šema i reprezentativni legacy podaci predstavljaju referentno ponašanje koje nova implementacija mora da reprodukuje.

Tehnička implementacija sme da se prilagodi novoj Laravel/Vue arhitekturi, standardima bezbednosti, autorizaciji, auditovanju i novoj šemi baze, ali bez promene korisnički vidljivog rezultata ili značenja podataka. Migracija mora sačuvati sve accounting zapise i veze sa školama, zemljama, koordinatorima, sezonama i drugim povezanim entitetima koji postoje u legacy sistemu.

Pre prenosa treba napraviti inventar accounting ekrana, akcija, tabela, statusa, formula, izveštaja i role/scope ograničenja. Reprezentativni legacy slučajevi treba da postanu characterization/compatibility testovi kako bi se potvrdilo da novi modul za iste ulaze daje iste rezultate. Svaka naknadna poslovna izmena accounting-a rešava se tokom razvoja kao zaseban potvrđen zahtev; ne treba je uvoditi prećutno u okviru tehničkog rewrite-a.

## 13. Minimalni acceptance kriterijumi

- Admin može da upravlja školama, koordinatorima, takmičarima i kompletnom hijerarhijom testa.
- Koordinator nikakvim API zahtevom ne može pristupiti školi/takmičaru van dodeljenog scope-a.
- Validan takmičar može pristupiti samo dozvoljenom aktivnom quiz-u.
- Web takmičar može pristupiti bez klasičnog login-a tek nakon uspešne provere jedinstvenog ID-a, zemlje i datuma rođenja.
- Mobilni nalog postaje aktivan tek nakon prihvatanja važećih pravnih dokumenata, potvrde email adrese preko jednokratnog vremenski ograničenog linka i postavljanja lozinke.
- Sistem može dokazivo prikazati koju verziju Terms & Conditions i obaveštenja o privatnosti je nalog prihvatio i kada, bez prepisivanja istorije pri promeni dokumenata.
- Mobilni takmičar može pristupiti kroz verifikovan email nalog tek nakon bezbednog povezivanja sa tačnom sezonskom prijavom.
- Validan takmičar vidi i može pokrenuti samo exam-e/testove eksplicitno mapirane na difficulty level njegove sezonske prijave; backend odbija pokušaj pristupa sadržaju drugog nivoa.
- Takmičarski režim zahteva važeću quiz lozinku, dok sample režim radi bez nje i ne utiče na zvanične rezultate.
- Pokretanje testa kreira tačno jedan aktivan attempt i server-side rok.
- Refresh ne resetuje tajmer; pre završnog submit-a server nema odgovore jer autosave nije predviđen.
- Isti finish zahtev poslat više puta ne kreira duple odgovore ili rezultate.
- Istovremeni start zahtevi sa web-a i mobilne aplikacije ne mogu kreirati dva aktivna attempt-a za istog takmičara i test.
- Multiple-choice i fill-the-gap obračun prate potvrđena pravila; essay čeka ručno ocenjivanje.
- Zaključani test ne može da se pokrene ručno izmenjenim API zahtevom.
- Rezultat se prikazuje takmičaru tek nakon administratorskog objavljivanja.
- Istek vremena automatski završava pokušaj i, dok je stranica aktivna, šalje trenutno unesene odgovore.
- Drugi pokušaj nije moguć dok administrator ne izvrši autorizovani, auditovani reset prethodnog rezultata.
- Reset, ručno bodovanje, objavljivanje i izvoz su autorizovani i auditovani.
- Migracioni zbir za ključne entitete i rezultate je usaglašen sa legacy izvorom.
- Podaci iz `el_country`, `schools`, `regions` i njihovih veza ostaju očuvani i proverljivo mapirani.
- Nova sezona može da se pripremi bez fizičkog brisanja user naloga ili gubitka prethodnih role/scope dodela.
- Administrator može da sačuva logo i temu sa najviše pet osnovnih boja bez izmene/deployment-a koda; nova tema odmah postaje aktivna.
- Theme Settings dozvoljava samo unapred definisane design tokene i primene komponenti; onemogućena primena koristi stabilni fallback design system-a.
- Sistem ne dozvoljava čuvanje teme koja ne zadovoljava dogovoreni kontrast, a javni, studentski, admin/coordinator i mobilni klijenti koriste istu aktuelnu temu i bezbednu fallback temu.
- Izmena teme ne menja istorijske podatke ili stanje aktivnog attempt-a i ne zahteva čuvanje prethodne teme.
- Administrator može da kreira i uređuje page layouts, javne stranice, kategorije i objave, a samo objavljen sadržaj je dostupan javnosti.
- Public navigacija podržava uređene i hijerarhijske stavke ka CMS sadržaju, aplikacionim rutama i validiranim spoljnim URL-ovima.
- Admin navigacija prikazuje stavke prema ulozi/permissions, ali direktan API ili URL pristup i dalje mora biti odbijen backend policy proverom kada korisnik nema dozvolu.
- Javni aplikacioni shell ima header, glavnu content zonu i footer, sa odvojeno upravljivim `public.header` i `public.footer` navigacijama.
- Admin/coordinator shell ima top navigaciju, glavnu content zonu i desni sidebar, sa odvojeno upravljivim `admin.top` i `admin.right_sidebar` lokacijama.
- Engleski (`en`) je inicijalno jedini aktivan locale, ali sav sistemski tekst u web, backend/email i mobilnim klijentima koristi language fajlove umesto hardkodovanih poruka.
- `Page`, `Post`, `Category` i nazivi navigacionih stavki podržavaju locale-varijante bez buduće promene osnovne šeme; nedostajući prevod kontrolisano pada na engleski.
- Izmena ili objavljivanje CMS sadržaja i navigacije ne utiče na aktivne pokušaje niti opterećuje competition core tokom takmičarskih pikova.
- Accounting modul za reprezentativne legacy slučajeve zadržava iste tokove, formule, statuse, dozvole i rezultate, a svi postojeći accounting podaci i njihove veze migrirani su bez gubitka.
- Svaka promena accounting poslovnog ponašanja dokumentovana je i odobrena kao poseban zahtev, a ne uvedena kao sporedni efekat rewrite-a.
- Legacy nivoi `10`, `5` i `1` mapirani su na admin, country coordinator i school coordinator uloge.
- Istorijski rezultati se mogu prikazati i agregirati po sezoni bez mešanja sa aktuelnim pokušajima.
- Izveštaji mogu prikazati broj učesnika i rezultate po sezoni, školi, zemlji/regionu, tadašnjem koordinatoru, nivou učenika, exam/test hijerarhiji i težini testa.
- Promena master podataka ili koordinatorske dodele u novoj sezoni ne menja istorijske statistike prethodnih sezona.
- Svaki legacy competitor number se reprodukuje pravilom `round_number || LPAD(student_id, 5, '0')`, jedinstven je u zbiru svih uvezenih sezona i vodi do tačne sezonske registracije i njenih rezultata.
- Reprezentativan load test potvrđuje najmanje 10.000 istovremenih aktivnih takmičarskih sesija i kontrolisan start/submit talas prema dogovorenom SLO-u.

## 14. Otvorena pitanja za vlasnika proizvoda

Ova pitanja ne blokiraju izradu tehničkog skeleton-a, ali moraju biti rešena pre finalizacije odgovarajućih modula:

1. ~~Da li testovi moraju strogo redom i da li se sledeći otključava završetkom prethodnog ili i minimalnim rezultatom?~~
   **Razrešeno:** ADR-0017 (strogo redom) + ADR-0021 (otključava ga **objava** prethodnog, ne sam završetak),
   a ADR-0049 to ograničava na takmičarske kvizove.
2. Pošto nema autosave-a, šta se dešava ako se browser zatvori ili internet prekine pre ručne potvrde/automatskog submit-a: gubitak odgovora, povratak u isti attempt ili administratorski reset?
3. Da li administrator pre objavljivanja rezultata objavljuje ceo exam/quiz odjednom ili može pojedinačni rezultat/test?
4. Da li essay podržava parcijalne bodove i da li je potrebna posebna potvrda ocene pre objave?
5. Kako normalizovati fill-the-gap: case, razmaci, interpunkcija, dijakritici i više dozvoljenih varijanti?
6. Odloženo, nije prioritet prve verzije: da li je kasnije potrebno povezivanje različitih sezonskih prijava iste fizičke osobe i po kom pouzdanom pravilu?
7. Koja su pravila generisanja i dostupnosti sertifikata?
8. Koji formati i entiteti ulaze u prvi import/export paket?
9. Da li se dozvoljeni level mapira na exam, na pojedinačni test ili na oba nivoa, i može li jedan exam/test pripadati većem broju level-a?
10. Koji je tačan scope country coordinator-a: jedna država, više država, regioni, škole i koje administrativne akcije?
11. Da li se početak preliminary/semifinal testa zakazuje za sve u isti trenutak i koliko traje očekivani start/submit talas?
12. Koje dodatne mere i pravila ulaze u naprednu analitiku: prag prolaznosti, formula rangiranja, percentili, poređenje škola različite veličine i izvoz izveštaja?
13. Koja su pravila i rokovi čuvanja/brisanja ličnih podataka takmičara i istorijskih rezultata? Saglasnost roditelja/staratelja pribavlja škola van aplikacije i nije deo ovog sistema.
14. Kako se mobilni email nalog prvi put povezuje sa sezonskom prijavom: proverom postojećih identifikacionih podataka, jednokratnim kodom koji izdaje koordinator/admin ili drugim postupkom?
15. Kako rade zaboravljena lozinka, promena email adrese, opoziv sesija i eventualna dodatna autentikacija, nakon potvrđene odluke da se inicijalna lozinka postavlja preko email linka?
16. Da li jedan email može pripadati samo jednom mobilnom nalogu/takmičaru ili jedan nalog može biti povezan sa više sezonskih prijava istog takmičara?
17. Da li mobilno polaganje mora raditi samo online ili se kasnije očekuje offline režim; šta se dešava pri prekidu mreže tokom testa bez autosave-a?
18. Da li isti mobilni nalog treba da zadrži pregled svojih ranijih sezonskih registracija, iako povezivanje iste osobe kroz godine nije prioritet analitike?
19. Koji konkretni logo formati, dimenzije i varijante su obavezni: desktop, mobile/app icon, favicon, svetla i tamna varijanta?
20. ~~Da li `PageLayout` koristi unapred programirane template-e sa definisanim content zonama ili administrator treba da sklapa stranicu od odobrenih content blokova?~~ **Razrešeno — ADR-0043 (2026-08-24): oboje.** Template definiše zone (registar u kodu) i tipove blokova koje zona prima; administrator bira redosled, vidljivost i sadržaj unutar njih, ali ne može da izmisli ni zonu ni tip.
21. Kada se bude dodavao prvi jezik posle engleskog, da li svaki locale dobija sopstveni URL prefiks i lokalizovan slug ili se zadržava zajednički slug?
22. Koji konkretni kontekstualni moduli, pored navigacionih linkova, treba da budu dozvoljeni u admin `right_sidebar` zoni?

## 15. Odluke koje treba voditi odvojeno

Pre implementacije treba napraviti `DECISIONS.md` (ADR sažetak) za:

- konačnu bazu (MySQL ili PostgreSQL);
- first-release scope;
- student access/session model;
- mobilni StudentAccount model, registraciju preko email linka za postavljanje lozinke, account recovery i povezivanje sa StudentRegistration;
- verzionisanje Terms & Conditions/Privacy dokumenata, audit prihvatanja i pravila ponovnog prihvatanja;
- model jedne aktuelne teme, ugovor design tokena, pravila kontrasta, trenutno aktiviranje nakon čuvanja i deljenje teme između javnog, studentskog, admin/coordinator i mobilnih klijenata;
- CMS model za page layouts, stranice, kategorije i objave, uključujući status objave, slug/redirect, SEO i media pravila;
- lokalizacijski ugovor za Laravel, Vue i Android/iOS language fajlove, listu omogućenih locale-a, engleski fallback i locale-aware CMS sadržaj;
- model Public/Admin navigacije, dozvoljene tipove ciljeva, potvrđene lokacije (`public.header`, `public.footer`, `admin.top`, `admin.right_sidebar`), hijerarhiju i role/permission vidljivost;
- izbor Android/iOS tehnologije i release/distribution proces za App Store i Google Play;
- online-only naspram eventualnog offline mobilnog testiranja i ponašanje pri promeni uređaja/mreže;
- attempt i timeout state machine;
- grading i publication workflow;
- način migracije i cutover-a;
- model sezone, sezonskih uloga/dodela i pripreme nove godine;
- model istorijske analitike, reporting snapshot-a/agregata i deduplikaciju stabilnih school/country/region podataka;
- eventualni identity matching iste osobe kroz sezone kao odloženu, zasebnu odluku;
- performance SLO, load-test scenario i produkcioni scaling plan za 10.000+ istovremenih takmičara;
- pravila privatnosti, retention-a i audita;
- deployment okruženje, storage za slike/audio i backup/restore plan.

---

Ovaj dokument je početni source of truth. Kada odgovor na otvoreno pitanje bude potvrđen, treba ga premestiti u odgovarajuću sekciju kao potvrđeno pravilo i zabeležiti odluku u `DECISIONS.md`.
