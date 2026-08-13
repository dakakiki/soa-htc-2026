# SOA HTC — Nalazi o stvarnom ponašanju legacy sistema

Status: radni nacrt 0.1
Datum: 2026-08-13
Izvori: `dakakiki/soa-htc` @ `ac55159` (grana `master`), `soahtc_soa2024_anonimize.sql`

## 1. Svrha dokumenta

Ostali dokumenti opisuju šta nova aplikacija treba da radi. Ovaj dokument beleži šta legacy sistem **stvarno** radi, prema kodu i podacima, i gde se to razlikuje od pretpostavki u `PROJECT_CONTEXT.md`.

Razlog: `12.2` traži accounting „funkcionalno identičan postojećem", `04` traži reconciliation prema legacy izvoru, a `03` definiše acceptance kriterijume koji impliciraju postojeće ponašanje. Sve troje zahteva referentni opis polaznog stanja. Bez njega „isto kao pre" nema merljivo značenje.

Status oznake:

- **Utvrđeno** — vidi se direktno u kodu ili podacima, sa navedenom lokacijom.
- **Posledica** — zaključak koji sledi iz utvrđenog nalaza.
- **Odluka** — pitanje koje ovaj nalaz otvara i koje mora dobiti odgovor.

## 2. Ispravke činjenica iz postojeće dokumentacije

| Tvrdnja | Stvarno stanje | Izvor |
| --- | --- | --- |
| 49 tabela | 51 tabela | `CREATE TABLE` u dump-u |
| `test_results` ima 3,3M redova | 2.124.315 redova; `AUTO_INCREMENT` je 3.331.410 | prebrojani `INSERT` tuple-ovi |
| `competitor_number = round \|\| LPAD(student_id, 5, '0')` | Sekvenca je **šestocifrena**: `'14000001'`, ukupno 8 karaktera | `el_student.student_id` |
| Broj je globalno jedinstven | 4 duplikata u samo ovoj sezoni | vidi 3.2 |
| `date_of_birth` treba profilisati u dump-u | 107.653 od 107.653 redova je `NULL` | `el_student` |
| Dump je anonimizovan | Delimično; deo tabela sadrži prave lične podatke | vidi 3.3 |

Razlika između `AUTO_INCREMENT` i stvarnog broja redova u `test_results` (~36%) nije slučajna; objašnjena je u 4.2.

## 3. Nalazi o podacima

### 3.1 Sezona ne postoji kao kolona

**Utvrđeno:** `el_settings` je tabela bez primarnog ključa, MyISAM, `latin1`, sa jednim redom: `round_number = 14`, `current_school_year = 2026`. Nijedna poslovna tabela nema `season_id`.

**Posledica:** identitet sezone nosi sama baza podataka, a ne zapis u njoj. Jedna legacy baza = jedna sezona. Svaki uvezeni red mora dobiti `season_id` iz source registry-ja iz `04`, jer se on ne može izvesti iz sadržaja.

**Posledica:** naziv fajla (`soa2024`) ne odgovara sadržaju (sezona 2026, round 14). Sezona se određuje iz `el_settings`, ne iz imena izvoza.

### 3.2 Competitor number

**Utvrđeno:** `el_student.student_id` je `varchar(50)`, u praksi uvek 8 karaktera: `14` + šest cifara sa vodećim nulama. `archive_test_results` sadrži i `11070691` — round 11 sa šestocifrenom sekvencom.

**Utvrđeno:** četiri vrednosti se ponavljaju unutar jedne sezone: `14070484`, `14077653`, `14085801`, `14103403`.

**Utvrđeno:** `test_results.student_id`, `test_started.student_id` i `quiz_results.student_id` su tipa `int` i **ne sadrže** competitor number — sadrže `el_student.entry_id`. Competitor number se koristi samo za identifikaciju takmičara na formi.

**Posledica:** pravilo `LPAD(..., 5, '0')` iz `PROJECT_CONTEXT.md` i `04` je netačno i ne sme ući u migracioni kod. Širina sekvence zavisi od broja prijava u sezoni. Sa 107.653 prijava petocifrena sekvenca nije ni moguća.

**Posledica:** broj se ne sme parsirati po fiksnim pozicijama. Round i sekvenca se ne mogu pouzdano razdvojiti bez poznavanja `round_number` izvorne baze.

**Posledica:** unutrašnji ključ za povezivanje rezultata je `entry_id`, ne competitor number. Migracija rezultata mora ići preko `entry_id` unutar jedne izvorne baze, a competitor number ostaje samo javni identifikator prijave.

**Odluka:** kako se tretiraju duplirani brojevi — kao defekt izvora sa ručnim review-om, ili kao dozvoljena vrednost bez unique ograničenja na uvezenim istorijskim podacima.

### 3.3 Anonimizacija nije potpuna

**Utvrđeno:** `el_student.name` i `users` jesu anonimizovani. Ali:

- `archive_test_results.student_name` sadrži prava imena takmičara (111.429 redova);
- `schools` sadrži prave email adrese, telefone i adrese škola;
- `invigilators.name` sadrži prava imena.

**Posledica:** fajl je produkcioni PII i ne sme se koristiti u development okruženju, deliti nekontrolisano, niti commitovati. Pravilo iz `04` („PII se ne kopira u development bez anonimizacije") trenutno nije ispunjeno.

**Posledica:** pošto je `date_of_birth` u potpunosti obrisan, iz ovog izvora se ne mogu izvesti formati datuma rođenja niti testirati identifikacioni tok. `04`, korak 1 („analiza formata datuma rođenja") zahteva drugi izvoz.

### 3.4 Indeksi

**Utvrđeno:** jedini sekundarni indeksi u celoj bazi su na `telescope_entries`, `telescope_entries_tags`, `password_resets`, `modules` i `users.email`. Poslovne tabele — `test_results` (2,1M redova), `test_started`, `quiz_started`, `quiz_results`, `el_student`, `user_schools`, `schools` — imaju isključivo `PRIMARY KEY`.

**Posledica:** svaki upit po `student_id`, `quiz_id`, `test_id` je full scan. Ovo je najverovatnije glavni uzrok postojećih problema sa performansama i objašnjava zašto je legacy sistem osetljiv na istovremeno opterećenje.

**Posledica:** ETL mora chunkovati po primarnom ključu, ne po poslovnom ključu, jer filtriranje po poslovnim kolonama nema podršku indeksa.

### 3.5 Nema deklarisanih stranih ključeva; mešani engine

**Utvrđeno:** nijedan `FOREIGN KEY` u dump-u. MyISAM koriste `users`, `el_country`, `el_settings`, `migrations`, `password_resets` i telescope tabele; ostale su InnoDB. Charset je mešan: `latin1` (`el_country`, `el_settings`), `utf8mb3` (`el_student`, `el_user`), `utf8mb4` (ostalo).

**Posledica:** `users` na MyISAM znači da nijedna operacija nad korisnicima nije transakciona i da ta tabela nije bila obuhvaćena rollback-om ni u jednom postojećem toku.

### 3.6 Mapiranje nivoa je CSV lista u koloni od 11 karaktera

**Utvrđeno:** `quizzes.difficulty_level`, `exams.difficulty_level`, `tests.difficulty_level` i `test_questions.difficulty_level` su svi `varchar(11)` sa vrednostima oblika `'5,12,16,21,'` (sa završnim zarezom).

**Utvrđeno:** distinktne vrednosti u `test_questions`: `'5,12,16,21,'`, `'8,26,19,24,'`, `'7,14,18,23,'`, `'6,13,17,22,'` (svi tačno 11 karaktera), `'2,3,9,10,'`, `'20,25,'`, `'4,11,'`, `'3,10,'`, `'2,9,'`.

**Utvrđeno:** provera pripadnosti radi se SQL-om `LOWER(difficulty_level) LIKE '5,%' OR LIKE '%,5,%'` (`app/Student.php`, `getQuizForStudent`).

**Posledica:** grupa od četiri dvocifrena nivoa zauzima 12 karaktera i biva tiho odsečena. Trenutno svaka grupa ima bar jedan jednocifreni nivo, pa problem još nije nastupio, ali je jedan novi nivo dovoljan da nastane.

**Posledica:** ovo delimično odgovara na otvoreno pitanje 9 iz `PROJECT_CONTEXT.md`: legacy mapira nivoe na **sva četiri** nivoa hijerarhije, uključujući pojedinačno pitanje. Predlog iz `02` (`exam_allowed_levels`, `test_allowed_levels`) pokriva dva od četiri.

**Odluka:** da li nova šema zadržava mapiranje na nivou quiz-a i pojedinačnog pitanja, ili se ono svesno ukida uz migraciju postojećih vrednosti na exam/test nivo.

**Odluka:** pre migracije proveriti da li je neka vrednost već odsečena (poslednji karakter nije zarez, a lista je duga 11).

### 3.7 Preklapanje termina „round"

**Utvrđeno:** četiri različita značenja iste reči:

| Mesto | Značenje | Vrednosti |
| --- | --- | --- |
| `el_settings.round_number` | sezona/godina | 14 |
| `exams.exam_round` → `exam_rounds` | faza takmičenja | Preliminary round, National round, Regional Qualifiers, World final, Sample |
| `country_rounds` | posebna tabela faza | Preliminary, Semifinal |
| `el_country.round` | po zemlji | brojčano |

**Posledica:** `exam_rounds` id 2 je „National round", ne „Semifinal", iako kod tu vrednost koristi za `semi_read`/`semi_write` kolone (vidi 4.5). Dokumentacija koristi termin „semifinal" koji u referentnim podacima ne postoji pod tim imenom.

**Odluka:** rečnik pojmova mora razdvojiti sezonu, fazu i eventualnu country-specific fazu pre nego što se napiše bilo koji ETL.

### 3.8 Referentne vrednosti

**Utvrđeno:**

- `quiz_types`: `1 = Sample`, `2 = Competition`.
- `test_types`: `2 = Reading`, `3 = Writing`, `5 = Speaking`, `6 = Use of English`.
- `test_replies_for_question_types`: `1 = Multiple choice`, `2 = Gap-filling`, `5 = Essay`.
- `difficulty_category_levels`: 26 nivoa; `el_student.level` sadrži vrednosti 2–26 i 10 `NULL` redova.

**Utvrđeno:** deo sample kvizova (`quizzes.id` 3, 4, 6, 7) **ima** sačuvan `quiz_password` hash, iako sample tok lozinku nikada ne proverava.

**Posledica:** postojanje lozinke na sample kvizu nije dokaz da se ona primenjuje. Migracija ne sme izvoditi režim pristupa iz prisustva lozinke, već iz `quiz_type`.

### 3.9 Stvarni obim po tabelama

| Tabela | Redova | `AUTO_INCREMENT` |
| --- | --- | --- |
| `test_results` | 2.124.315 | 3.331.410 |
| `quiz_results` | 174.563 | 221.021 |
| `quiz_started` | 130.470 | 153.037 |
| `archive_test_results` | 111.429 | 176.965 |
| `el_student` | 107.653 | 111.178 |
| `test_started` | 83.931 | 132.249 |
| `user_activity_log` | 50.422 | — |
| `el_student_tmp` | 27.293 | — |
| `user_schools` | 8.459 | 111.215 |
| `test_replies_for_questions` | 4.898 | 5.009 |
| `test_questions` | 1.699 | 1.713 |
| `el_user` | 1.574 | — |

**Posledica:** razlika između broja redova i `AUTO_INCREMENT` vrednosti nije šum. Kod `user_schools` je odnos 8.459 : 111.215, kod `test_started` 83.931 : 132.249. Brisanje je bilo redovna operacija, ne izuzetak.

## 4. Nalazi o ponašanju iz koda

### 4.1 Identifikacija takmičara

**Utvrđeno** (`StartController@startQuiz`, `Student::getQuizForStudent`):

- datum rođenja se sastavlja iz osam odvojenih input polja u string `DD.MM.YYYY` i poredi se kao tekst (`where('s.date_of_birth', $dateOfBirth)`);
- pretraga takmičara je `where student_id = ? OR entry_id = ?` — prijava je moguća i internim `entry_id`;
- quiz se bira `->first()` bez `ORDER BY` i **bez provere `quizzes.active`**;
- lozinka se proverava `Hash::check` nad `quiz_password`;
- sample tok (`SampleStartController@startSampleQuiz`) traži iste podatke ali ne proverava lozinku.

**Posledica:** poređenje datuma kao stringa znači da svako odstupanje u formatu tiho vraća „podaci nisu ispravni". Ovo je verovatan izvor postojećih prijava korisnika.

**Posledica:** mogućnost prijave preko `entry_id` je paralelan identifikacioni prostor koji nije dokumentovan i koji treba svesno ukinuti u novoj aplikaciji.

**Posledica:** ako se nivou učenika mapira više kvizova, sistem bira nedeterministički i može otvoriti neaktivan quiz.

### 4.2 Retake na rezultatu 0 — nedokumentovano pravilo

**Utvrđeno** (`StartController@startTest`): pre kreiranja pokušaja kod proverava postoji li `quiz_results` red za dati `quiz/exam/student/test`. Ako postoji **i `test_result == 0`**, kod:

1. briše sve odgovarajuće redove iz `test_results`;
2. briše red iz `quiz_results`;
3. nastavlja kao da test nikada nije rađen.

**Posledica:** pravilo „jedan pokušaj po takmičaru i testu" iz `PROJECT_CONTEXT.md` 7.2 ne važi. Svaki test ocenjen sa 0 poena može se ponoviti neograničeno, bez intervencije administratora i bez traga.

**Posledica:** ovo objašnjava rupu od ~1,2 miliona ID vrednosti u `test_results`. Nulti rezultati iz istorije su fizički obrisani i ne mogu se rekonstruisati.

**Posledica:** reconciliation iz `04` mora ovo tretirati kao poznatu i objašnjenu razliku, inače će se prikazati kao neobjašnjeni gubitak podataka.

**Odluka:** da li se pravilo zadržava. Ako se ukida, treba potvrditi šta se dešava takmičaru koji legitimno dobije 0 poena — u novom sistemu on ostaje bez mogućnosti ponavljanja koju je do sada imao.

### 4.3 Tajmer ne postoji na serveru

**Utvrđeno:** `test_started` ima kolone `quiz_started_id`, `exam_id`, `student_id`, `test_id`, `created_at`, `updated_at`. Nema `expires_at`, nema statusa, nema `submitted_at`.

**Utvrđeno:** `StartController@finishTest` ne proverava proteklo vreme. Trajanje (`tests.duration`) koristi se samo za prikaz odbrojavanja u pregledaču.

**Posledica:** tvrdnja iz `PROJECT_CONTEXT.md` 7.1 da „server odbija odgovore posle isteka roka" opisuje željeno, ne postojeće ponašanje. To je nova funkcionalnost, ne migracija postojećeg pravila.

**Posledica:** u legacy podacima ne postoji `expires_at` niti status pokušaja koji bi se migrirao. Statusi u novoj šemi moraju se izvesti: `test_started` bez odgovarajućeg `quiz_results` reda = započet i nezavršen; sa redom = završen. Pravilo treba zapisati u `04`, korak 6.

**Posledica:** 83.931 `test_started` naspram 174.563 `quiz_results` redova pokazuje da odnos nije 1:1 i da izvođenje statusa nije trivijalno. Ovo zahteva posebnu analizu pre migracije.

### 4.4 Nema autorizacije na pokretanju i završavanju testa

**Utvrđeno** (`StartController@startTest`, `@finishTest`): `student_id`, `level`, `quiz_id`, `exam_id` i `test_id` čitaju se direktno iz POST parametara `s`, `sl`, `q`, `e`, `t`. Nema provere da li se poklapaju sa sesijom, da li je test mapiran na nivo takmičara, niti da li takmičar sme da pristupi tom quiz-u. Nivo se proverava samo jednom, pri identifikaciji.

**Utvrđeno:** jedina zaštita u `finishTest` je provera da već ne postoji `quiz_results` red. Ona nije atomarna i ne štiti od paralelnih zahteva.

**Posledica:** izmenjenim zahtevom moguće je upisati rezultat u ime drugog takmičara ili polagati test drugog nivoa. Acceptance kriterijumi iz `03` (CC-03, CC-07) tiču se ranjivosti koja je u produkciji trenutno otvorena, što bi trebalo tretirati kao prioritet nezavisan od dinamike rewrite-a.

### 4.5 Obračun i upis rezultata

**Utvrđeno** (`StartController@finishTest`):

- **Multiple choice:** dohvata se izabrani red iz `test_replies_for_questions`; ako je `correct_answer == 1`, dodaju se puni poeni pitanja. Nema podrške za više tačnih odgovora ni za parcijalne poene.
- **Gap-filling:** dozvoljene varijante su u jednom polju `answers`, razdvojene znakom `|`. Provera je `in_array($uneto, explode('|', $answers))` — poređenje je egzaktno, case-sensitive, bez `trim`-a i bez normalizacije. Uzima se samo **prvi** reply red za pitanje (`->first()`).
- **Essay:** dodaje 0 poena, ali uvećava brojač tačnih odgovora.
- Upis ide u tri koraka bez transakcije: `insert` u `test_results`, `insert` u `quiz_results`, pa `update` denormalizovane kolone u `el_student`.

**Utvrđeno** (`QuizResults::addResultToStudentTable`): mapiranje rezultata na kolonu je hardkodovano kombinacijom `exams.exam_round` i `tests.test_type`:

| `exam_round` | `test_type` | Kolona u `el_student` |
| --- | --- | --- |
| 1 (Preliminary) | 2 (Reading) | `read_mark` |
| 1 (Preliminary) | 6 (Use of English) | `use_mark` |
| 2 (National) | 2 (Reading) | `semi_read` |
| 2 (National) | 3 (Writing) | `semi_write` |

**Posledica:** za bilo koju drugu kombinaciju (Regional Qualifiers, World final, Speaking) promenljiva `$mark` ostaje nedefinisana i `update` se poziva sa neispravnim ključem. Rezultati tih faza ne stižu u `el_student`.

**Posledica:** izostanak transakcije znači da je moguć delimičan upis — odgovori bez rezultata ili rezultat bez ažuriranog student zapisa. Reconciliation mora eksplicitno tražiti takve slučajeve.

**Posledica:** ovo su četiri characterization testa koja moraju postojati pre bilo kakvog ponovnog obračuna istorijskih rezultata. Ako nova aplikacija normalizuje fill-the-gap poređenje (otvoreno pitanje 5), ponovni obračun starih podataka daće **drugačije** rezultate od objavljenih.

### 4.6 Objavljivanje rezultata

**Utvrđeno:** `quiz_results.active` se pri upisu postavlja na `0`, a `TestDataPublishResultsController` ga menja na `1`. To je publikacioni flag.

**Posledica:** legacy ima dva stanja (`0`/`1`), ne četiri (`pending`, `graded`, `published`, `void`) iz `PROJECT_CONTEXT.md` 7.5. Migracija mora definisati mapiranje, uključujući pitanje kako se razlikuje „nije ocenjeno" od „ocenjeno ali neobjavljeno" za essay pitanja.

### 4.7 Nezaštićene i destruktivne rute

**Utvrđeno** (`routes/web.php`, izvan `auth` grupe):

- `GET /delete-exam-data/{studentID}` → `DeleteTestDataController@deleteExamData` briše `test_results`, `test_started`, `exam_started`, `exam_results`, `quiz_results` i `quiz_started` za prosleđeni ID. Bez autentikacije, bez potvrde, bez transakcije.
- `GET /qwert-student-first`, `/qwert-get-student-quiz`, `/qwert-quiz` → `DBtest_Controller`, debug rute.

**Utvrđeno:** kontroler referencira tabelu `exam_started` koja **ne postoji** u dump-u. Prva dva brisanja se izvrše, zatim upit puca.

**Posledica:** ruta u praksi pravi delimično uništenje podataka — odgovori i zapis o pokretanju nestaju, dok rezultat ostaje. To je verovatan izvor orphan `quiz_results` redova koje treba očekivati u migraciji.

**Utvrđeno:** unutar `auth` grupe postoji `GET /clearall` koji poziva `Artisan::call` za `cache:clear`, `view:clear`, `config:cache`, `route:clear`.

**Posledica:** repozitorijum je javno dostupan, pa su ove rute poznate svakom ko pogleda izvorni kod. Ovo bi trebalo zatvoriti u postojećoj aplikaciji odmah, nezavisno od plana rewrite-a.

## 5. Izmereni profil opterećenja

`test_started.created_at` je jedini pouzdan trag stvarne dinamike polaganja. Merenje nad 83.931 redom, period 2025-11-07 do 2026-05-11:

| Mera | Vrednost |
| --- | --- |
| najviše startova u jednom minutu | 235 (2026-05-09 04:10) |
| najviše startova u jednom danu | 9.330 (2026-05-09) |
| najviše startova u bilo kom 45-minutnom prozoru | 1.820 (2026-05-09 05:22) |

**Posledica:** pošto najduži test traje 50 minuta, gornja granica istovremeno aktivnih sesija u ovoj sezoni je reda veličine **1.800–2.000**, ne 10.000. Cela `05_PERFORMANCE_AND_LOAD_STRATEGY.md`, SLO tabela i release gate dimenzionisani su prema broju koji ovi podaci ne potvrđuju.

**Ograničenja merenja:** `test_started` je izgubio ~37% redova brisanjem (4.2, 4.7), pa su stvarni brojevi bili nešto viši. Merenje pokriva jednu sezonu; druge godine nisu proverene. Broj 10.000 može se odnositi na prijavljene takmičare (107.653 u sezoni), na drugu godinu ili na očekivani rast.

**Odluka:** pre nego što se potvrdi arhitektura sa više instanci, load balancer-om i read replicom, izmeriti isti profil na svim istorijskim bazama i potvrditi sa vlasnikom proizvoda na šta se odnosi broj 10.000. Ovo je jeftina provera koja može značajno promeniti obim `05`.

**Predlog:** zadržati 10.000 kao ciljnu marginu kapaciteta, ali release gate prve verzije vezati za izmereni pik uvećan za dogovoreni faktor sigurnosti, a ne za nepotvrđenu vrednost.

## 6. Šta ovo menja u ostalim dokumentima

| Dokument | Potrebna izmena |
| --- | --- |
| `PROJECT_CONTEXT.md` 5.6, 10 | ispraviti pravilo competitor number-a (šest cifara, promenljiva širina, `entry_id` kao unutrašnji ključ) |
| `PROJECT_CONTEXT.md` 7.1 | označiti server-side tajmer kao novu funkcionalnost, ne kao postojeće pravilo |
| `PROJECT_CONTEXT.md` 7.2 | dodati retake na rezultatu 0 kao potvrđeno postojeće ponašanje i otvorenu odluku |
| `PROJECT_CONTEXT.md` 7.5 | mapirati legacy `active` 0/1 na predložene četiri statusa |
| `PROJECT_CONTEXT.md` 14, pitanje 9 | delimično zatvoreno: legacy mapira nivoe na sva četiri nivoa hijerarhije |
| `02`, 5.3 | dodati mapiranje nivoa na quiz i pitanje, ili eksplicitno obrazložiti ukidanje |
| `03`, CC-09 | dodati mapiranje `exam_round` × `test_type` → kolona kao characterization zahtev |
| `04`, korak 1 | dodati proveru truncation-a `difficulty_level`, profilisanje duplikata competitor broja i traženje izvora sa očuvanim `date_of_birth` |
| `04`, korak 6 | dodati pravilo izvođenja statusa pokušaja i očekivane orphan `quiz_results` redove |
| `04`, 2 | zabeležiti da postojeći „anonimizovani" izvoz sadrži PII |
| `05`, 2–4 | kalibrisati scenarije prema izmerenom profilu; potvrditi poreklo broja 10.000 |

## 7. Sledeći korak

Pre nego što se napiše prvi red migracionog koda:

1. potvrditi sa vlasnikom proizvoda tri stvari: retake na rezultatu 0, značenje broja 10.000 i sudbinu mapiranja nivoa na pojedinačno pitanje;
2. tražiti izvoz sa očuvanim formatom `date_of_birth` i bez PII u `archive_test_results`, `schools` i `invigilators`;
3. zatvoriti nezaštićene rute u postojećoj produkciji;
4. profilisati preostale istorijske baze istim postupkom i uporediti šeme, jer se sve gore navedeno odnosi na jedan izvor.
