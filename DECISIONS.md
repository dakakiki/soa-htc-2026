# SOA HTC — Odluke (ADR sažetak)

Ovaj fajl vodi arhitektonske i poslovne odluke kao ADR zapise (Architecture
Decision Record). Prioritet dokumenata: `docs/00_LEGACY_BEHAVIOR_FINDINGS.md`
ima prednost nad ostalima svuda gde se razlikuju — on opisuje **izmereno**
stanje legacy koda i podataka, ostali opisuju **nameru**.

Status vrednosti: `Prihvaćeno` · `Predlog` · `Otvoreno` · `Zamenjeno`.

---

## ADR-0001 — Baza podataka: MySQL 8.3.0

- **Status:** Prihvaćeno (2026-08-13)
- **Kontekst:** `Prompt.txt` traži da se MySQL/PostgreSQL odluči pre prve
  migracije, jer određuje implementaciju „jedan aktivni attempt". Dev okruženje
  je WAMP sa MySQL 8.3.0.
- **Odluka:** MySQL 8.3.0.
- **Posledica:** „Jedan aktivan attempt po registraciji i testu" implementira se
  kao *generated column* + `UNIQUE` indeks (u PostgreSQL bi to bio *partial
  unique index*). Konkretan oblik definiše se u Fazi 4.
- **Napomena:** MySQL 8.3 je innovation release (ne-LTS). 8.4 LTS je dostupan u
  WAMP-u; verzije se finalno zaključavaju pri postavci staging/produkcije.

## ADR-0002 — Storage engine: InnoDB (forsiran u aplikaciji)

- **Status:** Prihvaćeno (2026-08-13)
- **Kontekst:** WAMP MySQL server ima `default_storage_engine = MyISAM`. Legacy
  baza koristi mešane MyISAM/InnoDB engine-e (vidi `00`, §3.5), što znači da nad
  korisničkim i drugim MyISAM tabelama nije bilo transakcija ni rollback-a.
- **Odluka:** `config/database.php` forsira `'engine' => 'InnoDB'` za `mysql` i
  `mariadb` konekcije. Sve migracije prave InnoDB tabele nezavisno od server
  defaulta.
- **Posledica:** Transakcije i strani ključevi rade svuda. Prenosivo na svako
  okruženje bez ručne izmene server konfiguracije. Globalni WAMP `my.ini` default
  je opciono pitanje higijene, ne uslov.
- **Verifikacija:** `migrate:fresh` — sve tabele `soa_htc_dev` su InnoDB.

## ADR-0003 — Prioritet isporuke: Competition Core prvi

- **Status:** Prihvaćeno (2026-08-13)
- **Kontekst:** `01_DEVELOPMENT_ROADMAP.md` §1 definiše redosled isporuke.
- **Odluka:** Competition Core → Sertifikati i import/export → CMS → Accounting.
  Faze se prate redom; nema prelaska na sledeću bez testabilnog izlaza prethodne.
- **Posledica:** Faza 4 (attempt engine) se **ne započinje** dok se ne potvrde:
  ponašanje pri gubitku browsera bez autosave-a, sudbina retake-a na 0, i
  fill-the-gap normalizacija (vidi Otvorene odluke dole).

## ADR-0004 — Topologija repozitorijuma i izolacija PII-ja

- **Status:** Prihvaćeno (2026-08-13)
- **Kontekst:** Razvoj je na WAMP-u; vhost `dev.lcl.soa-htc.wrk` servira iz
  `c:/wamp64/www/soa-htc.org__08.2026/public`. Anonimizovani dump je zapravo
  produkcioni PII (vidi `00`, §3.3).
- **Odluka:** Laravel aplikacija živi u WAMP vhost folderu; to je git repo
  (`dakakiki/soa-htc-2026`). Legacy SQL dump/ZIP se **fizički drži van repo
  stabla**; `.gitignore` dodatno blokira `*.sql`, `*.zip`, `*.sqlite`.
- **Posledica:** Dump se ne commituje, ne koristi kao seed, niti kopira u dev
  bazu. Za razvoj/testove pravi se anonimizovan/sintetički seed.

## ADR-0005 — Nepregovaračke ispravke iz dokumenta `00`

- **Status:** Prihvaćeno kao obavezujuće (2026-08-13)
- **Odluka:** Sledeće se tretira kao tačno, i nadjačava suprotne tvrdnje u
  `PROJECT_CONTEXT.md` / `02` / `04`:
  1. `competitor_number` = `round_number` + **šestocifrena** sekvenca
     (npr. `'14000001'`), promenljive širine po sezoni, čuva se kao `varchar`,
     **ne** parsira po fiksnim pozicijama. Pravilo `LPAD(..., 5, '0')` je NETAČNO
     (`PROJECT_CONTEXT.md` §5.6 i §10 se ispravljaju).
  2. Unutrašnji ključ za povezivanje rezultata je `el_student.entry_id`
     (`int`/`bigint`), **ne** `competitor_number`. Migracija rezultata ide preko
     `entry_id` unutar jedne izvorne baze.
  3. Server-side tajmer je **nova** funkcionalnost. Legacy `test_started` ima
     samo `created_at`; `finishTest` ne proverava proteklo vreme.
  4. Nivoi se u legacy sistemu mapiraju na **sva četiri** nivoa hijerarhije
     (quiz, exam, test, question) kao CSV u `varchar(11)` (rizik tihe truncacije).
  5. Broj „10.000 istovremenih sesija" nije potvrđen. Izmeren pik: ~1.820
     startova u 45 min. Infrastruktura se ne dimenzioniše na 10.000 bez
     eksplicitne potvrde porekla tog broja.

## ADR-0006 — Raspored koda: modularni monolit `app/Domain/*`

- **Status:** Prihvaćeno (2026-08-13)
- **Kontekst:** `02` §4 i `PROJECT_CONTEXT.md` §8.3 traže domenske module sa tankim
  kontrolerima i logikom van Eloquent modela.
- **Odluka:** Novi kod ide u `app/Domain/<Context>/{Models,Enums,Actions,...}`
  (Organization, Assessment, Identity, Audit, …), autoload preko default `App\` PSR-4.
  `App\Models\User` ostaje na standardnoj lokaciji zbog auth/factory konvencija.
- **Posledica:** Kontroleri tanki; validacija u Form Request, autorizacija u Policy,
  logika u Action/Service, izlaz u API Resource.

## ADR-0007 — Uloge kao enum + sezonske dodele (bez spatie/permission)

- **Status:** Delimično zamenjeno ADR-0008 (2026-08-13) — sezonske dodele i „bez
  spatie" ostaju; fiksni enum uloga zamenjen RBAC modelom sa permisijama.
- **Kontekst:** `02` §5.1 opisuje `season_user_assignments` sa ulogom i scope-om;
  legacy priprema sezone briše naloge, što nova app ne sme (PROJECT_CONTEXT §5.5).
- **Odluka:** `Role` je PHP enum (`admin`/`country_coordinator`/`school_coordinator`)
  sa mapom na legacy `10/5/1`. Sezonska aktivnost/scope se čuva u
  `season_user_assignments` (+ `assignment_schools`, `assignment_countries`), a ne
  brisanjem naloga. Bez `spatie/laravel-permission` — globalne role tog paketa ne
  odgovaraju sezonski ograničenom scope-u. Autorizacija ide kroz Policies.
- **Posledica:** Nova sezona deaktivira/ne prenosi stare dodele; audit i istorija
  ostaju potpuni. `audit_logs` je append-only (samo `created_at`).
- **Localization:** `config/localization.php` drži eksplicitnu listu locale-a
  (`['en']`) + fallback; sav tekst kroz `lang/` fajlove.

## ADR-0008 — RBAC: role sa permisijama (admin-upravljive)

- **Status:** Prihvaćeno (2026-08-13); amandman na ADR-0007
- **Kontekst:** Fiksne 3 role (+student) kao enum ne dozvoljavaju kombinovanje
  pristupa niti nove role bez izmene koda. `02` §5.1 predviđa `roles/permissions`.
- **Odluka:** RBAC — tabele `roles`, `permissions`, `permission_role`.
  `season_user_assignments.role_id` → FK na `roles`. Autorizacija proverava
  **permisiju** (`schools.view`, `schools.manage`, …) kroz Policies, ne ime role.
  `SystemRole` enum (admin/country_coordinator/school_coordinator/student) služi
  samo za seed sistemskih uloga i legacy `10/5/1` mapu. Admin kasnije pravi
  custom (ne-system) role i dodeljuje permisije kroz UI.
- **Scope ≠ permisije:** koje škole/zemlje ostaje strukturno u dodelama
  (`allowedSchoolIds`); permisija `schools.view.all` zaobilazi scope.
- **Posledica:** `User::hasPermission()` je jedina tačka provere; nove role/
  kombinacije bez izmene koda. `RolePermissionSeeder` (svuda) seed-uje katalog
  permisija i sistemske role idempotentno.

## ADR-0009 — User zemlja/region + scope koordinatora po školama

- **Status:** Prihvaćeno (2026-08-13); potvrđeno legacy formom (User LVL 10=admin,
  5/1=koordinatori) i vlasnikom proizvoda
- **Odluka:**
  - Svaki user ima **obaveznu zemlju** (`users.country_id`) i **opcioni region**
    (`users.region_id`). Region pripada zemlji.
  - Scope koordinatora je **po školama** (ne po celoj zemlji):
    `school_coordinator` = **tačno 1 škola**, `country_coordinator` = **1..X škola**.
  - Škole koje se mogu vezati su **samo iz korisnikove zemlje**; UI kaskadira
    Country → Region → Schools.
  - `assignment_countries` **uklonjena** — scope je isključivo `assignment_schools`.
- **Posledica:** `User::allowedSchoolIds()` koristi samo vezane škole;
  `schools.view.all` (admin) zaobilazi scope. Kardinalnost i „škola iz iste
  zemlje" se validiraju u `StoreAssignmentRequest`/`UpdateAssignmentRequest`.
- **Napomena:** legacy per-user toggles (add/edit/delete students, reset results,
  status) mapiraju se na permisije/role u kasnijim fazama (student management).

## ADR-0010 — Admin shell: left sidebar + tanak top bar

- **Status:** Prihvaćeno (2026-08-13); vlasnik proizvoda
- **Kontekst:** Navigacija ima duboku grupisanu hijerarhiju (Students, Coordinators,
  Venues, Countries, Quizzes[9 pod-stavki], Portal content, Accounting, Settings).
  `PROJECT_CONTEXT.md` §8.6 je pretpostavljao `admin.top` + `admin.right_sidebar`.
- **Odluka:** Admin/coordinator shell = **tanak top bar** (logo, aktivna sezona,
  user + logout) + **left sidebar** (primarna grupisana nav) + content. Opcioni
  right sidebar za kontekstualne module ostaje mogućnost (§8.6). Top bar **nema
  globalni search** — svaka celina ima svoj per-lista search; globalni „quick find"
  (competitor_number/ime/venue) dolazi kad postoje registracije.
- **Ponašanje:** sidebar je **data-driven i permission-aware** (stavka/grupa se
  prikazuje samo uz permisiju). Na breakpoint-u (< `lg`) sidebar prelazi u
  **icon-rail** (samo ikone, label kroz tooltip); grupe su collapsible.

---

## ADR-0011 — Round pripada Exams sloju, ne Tests

- **Status:** Prihvaćeno (2026-08-14); vlasnik proizvoda
- **Kontekst:** Faza 2 Slice 2 (Tests). Legacy `tests` ima kolonu `round` (int),
  ali je u svih 59 legacy testova **NULL**; round/tip se realno drže na legacy
  `exams` (`exam_round`, `exam_type`). Naš lookup `exam_rounds` već postoji.
- **Odluka:** `tests` tabela **nema** round kolonu. Test se za round vezuje preko
  Exams sloja (budući `exam_tests` pivot). Iz legacy `tests` se **ne prenose**
  `round`, `test_index`, `test_password` (poslednja dva su uvek NULL — legacy
  online-exam feature). Test nosi: `title`, `description`, `test_type_id` (FK →
  `test_types` preko `legacy_id`), `duration`, `status`, `legacy_id`, + OD-3 level
  pivot (`difficulty_level_test`) i uređeni `question_test` pivot (`position`).
- **Posledica:** Migracija `legacy:import-tests` mapira `test_type` 2/3/6 →
  Reading/Writing/Use of English, difficulty CSV → pivot (isti `legToOur` kao
  Questions), i reconcile-uje `question_id` preko `Question.legacy_id`.

---

## ADR-0012 — Sve 4 legacy difficulty kategorije (bez re-mapiranja sadržaja)

- **Status:** Prihvaćeno (2026-08-14); vlasnik proizvoda
- **Kontekst:** Legacy ima 4 difficulty kategorije: Regular Default + **Regular 7** i
  Special default + **Special 7**. „…7" su country-scoped varijante (12/15 zemalja) sa
  **istim oznakama nivoa** (H2 je H2) ali **različitim grade→level pragovima**. Raniji
  slice (OD-3) je kolapsirao sve varijante na 2 Default kategorije.
- **Odluka:** Uvezene su i „…7" varijante (`legacy:import-difficulty-categories`,
  idempotentno po `legacy_id`) kao zasebne šeme sa svojim nivoima/pragovima — **ali
  bez re-mapiranja** questions/tests (i dalje ciljaju Default nivoe). Sada 4 kategorije
  / 24 nivoa; Tests filter/forma prikazuju 4 optgroup-a.
- **Posledica / otvoreno:** country-scope „…7" kategorija je **prazan** — dev `countries`
  nemaju `legacy_id`, pa se legacy country_id ne može mapirati dok se zemlje ne migriraju
  (Faza 7–8). „…7" nivoi zasad nemaju vezanih testova (očekivano). OD-3 kolaps ostaje na
  snazi za sadržaj; ovo je samo dopuna reference kategorija.

---

## ADR-0013 — Registracioni model: samo roster (bez rezultata/round-flagova)

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Legacy `el_student` je denormalizovan — spaja registraciju (ime, škola,
  zemlja, level, razred) SA rezultatima (read/use/score marks, semi_*, q_semi/q_quali/
  q_final round-flagovi, absent). Faza 3 (registracije) je odvojena od Faze 4–5 (attempt/
  ocenjivanje/results) po 3-slojnom modelu rezultata.
- **Odluka:** `registrations` tabela nosi **samo roster**: season_id, competitor_number
  (round+6cifara=8), school_id (Venue), `school_external` (free-text „School" kad polaže
  drugde), country_id (izveden), difficulty_level_id, name, dob, grade, status. Level se
  bira **filtrirano po grade-u** (nivoi čiji `grades` sadrže razred; `difficulty-level-options`
  sada vraća `grades`). Country→Venue cascade (obavezan izbor zemlje pa škole po zemlji).
  **NE nosi** ocene ni round pass-flagove — to je results sloj (Faza 4–5).
- **Odloženo za Faza 4–5 (vlasnik naglasio 2026-08-15, radi količine/provere):**
  round pass-flagovi (Semifinal/Qualifiers/Final prošao?) + ocene po **exam_round → test-type**;
  **export odgovora studenata po ispitu** (šta je čekirao/uneo) → **provera** → tek onda
  **import finalnih rezultata** (ili zadržati već upisano); **admin odobrava prikaz rezultata**
  kada su svi postavljeni (vezano za OD-6 publish gate).

---

## ADR-0014 — Zone-based layout: public/admin/student shell odvojen od auth stanja

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Dotadašnji `App.vue` je birao chrome po `session.isAuthenticated`:
  ulogovan → admin header + `AppSidebar` obavija SVE rute, uključujući `/`. Zbog
  toga admin na javnoj stranici vidi admin navigaciju. `PROJECT_CONTEXT.md` §8.6
  i prijemni zahtevi (:584–585) traže DVA odvojena aplikaciona shell-a (public:
  header→content→footer; admin: top+content+right sidebar), a §8 (:319, :333)
  tretira javni sajt, studentski interfejs i admin panel kao tri odvojena
  interfejsa koja dele samo temu. Faza 3 Slice 3c uvodi prvu public/student oblast.
- **Odluka:** Layout se bira po `route.meta.zone ∈ {public, admin, student}`,
  NE po auth stanju. `App.vue` je tanak switch nad tri layout komponente
  (`resources/js/layouts/`):
  - `PublicLayout` — header (logo + public nav + Login + uslovni „Back to
    dashboard" za ulogovanog admina) → content → footer;
  - `AdminLayout` — postojeći tanak top bar + left `AppSidebar` (ADR-0010,
    nepromenjen);
  - `StudentLayout` — minimalni shell za takmičarsku sesiju.
  **Default zona je `admin`** (fail-safe: ruta bez `meta.zone` ide u zaštićeni
  shell; eksplicitno se taguju samo `public`/`student` rute). Ulogovan admin na
  `public` ruti dobija čist public shell + samo „Back to dashboard" link; nijedan
  admin element se ne renderuje van `admin` zone.
- **Odnos prema ADR-0010:** ADR-0010 (admin shell) ostaje na snazi; ovaj ADR ga
  ne menja, samo ograničava njegov doseg na `zone: admin`.
- **Obim sada vs. Faza 8:** Uvodi se shell SKELET i zone-routing sada. Public nav
  je privremeno hardkodovan; CMS-driven navigacije (`public.header`,
  `public.footer`) i upravljanje sadržajem ostaju Faza 8. `StudentLayout` dobija
  sadržaj i student-session guard u Slice 3c-2.
- **Posledica:** Vidljivost shell-a NIJE bezbednosna granica — svaka zaštićena
  ruta i dalje nosi `requiresAuth`/`permission` (admin), odn. student-session
  guard (3c-2); backend policy/autorizacija ostaje jedini merodavni sloj.

---

## ADR-0015 — Dostupnost sadržaja (CC-06): level gate + session-scoped unlock

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Faza 3 Slice 3c-1. CC-06 (`03` §CC-06) traži da backend računa
  dostupne testove iz: aktivne sezone/quiz-a, sample/competition moda,
  registracionog nivoa, konfigurisanog redosleda, attempt/result statusa i
  reset/void stanja. Attempt/result slojevi (Faza 4–5) još ne postoje.
  `PROJECT_CONTEXT.md` §5.7 zahteva level gate na listanju i pri pristupu.
- **Odluka:**
  - `GET /api/student/availability` (iza `student.session`) vraća stablo
    quiz→exam→test **filtrirano po `difficulty_level_id` registracije na svakom
    sloju** (ne samo quiz), redosled preko `exam_quiz.position` /
    `exam_test.position`. Server je autoritet; klijent samo prikazuje statuse.
  - Statusi testa su zasad **`available` / `locked`**. Attempt-izvedeni statusi
    (`in_progress`/`completed`/`published`) i progresivno test-po-test
    otključavanje (**OD-4, otvoreno**) NISU ovde — dolaze u Fazi 4–5.
  - Competition quiz sa lozinkom je `locked` dok sesija ne položi lozinku;
    `POST /api/student/quizzes/{quiz}/unlock` (`Hash::check`, `throttle:8,1`).
    **Uniformna 422 greška** za sve slučajeve (quiz van nivoa / nije gejtovan /
    pogrešna lozinka) — ne otkriva postojanje ni nivo. Sample i competition-bez-
    lozinke su otvoreni.
  - **Unlock se pamti u pivotu `student_session_quiz`** (session-scoped,
    `unlocked_at`, cascade sa sesijom). `student_sessions` ostaje quiz-agnostična
    (nastavak ADR-0013 / Slice 3b linije).
- **Posledica:** `Quiz::requiresPassword()/passwordMatches()`; availability logika
  u `Domain/Competition/Support/StudentAvailability`. Start testa (Faza 4) mora
  **ponovo** proveriti level + unlock neposredno pre kreiranja attempt-a (CC-07);
  ova lista nije autorizacioni dokaz.

---

## ADR-0016 — Attempt: jedan pokušaj po (registraciji, testu) (OD-1 razrešeno)

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Faza 4 Slice 4a. Legacy je dozvoljavao neograničeno ponavljanje
  (naročito na rezultatu 0, uz brisanje prethodnog). OD-1 je bio otvoren.
- **Odluka:** Attempt pripada **REGISTRACIJI** (ne 180min student sesiji — attempt
  je nadživljava), unique **`(registration_id, test_id)`**. Po `completed`/isteku
  attempt je terminalno zaključan — **bez retake-a**. Start je **idempotentan**:
  ponovljeni start vraća otvoreni attempt; završeni → 409. Eventualni budući
  retake ide kroz zaseban auditovan tok, ne menja ovo.
- **Posledica:** legacy „retake na rezultatu 0" se **ukida**. `attempts` +
  `attempt_answers` (JSON `response`, upis samo pri submit-u, negrejdovan — grading
  je Faza 5 / ADR-0013).

---

## ADR-0017 — Redosled testova: strogo sekvencijalno (OD-4 razrešeno)

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Faza 4 Slice 4a. OD-4 (moraju li testovi strogo redom) bio otvoren.
- **Odluka:** Unutar **otključanog** quiz-a, testovi se rade po **spljoštenoj
  sekvenci** `exam_quiz.position` → `exam_test.position`. Sledeći test je `next`
  (jedini startabilan) tek kad je prethodni `completed`. **Bez min-praga** —
  samo završetak otključava sledeći. Start (CC-07) ponovo proverava eligibilnost
  server-side (`StudentAvailability::startableQuizId`); forge zahtev za zaključan/
  van-reda test → 403.
- **Posledica:** `StudentAvailability` statusi testa = **`locked`/`next`/
  `in_progress`/`completed`** (raniji `available` iz 3c-1 zamenjen sa `next`).
  Grading/publish statusi (`published`) ostaju results sloj (Faza 5).

---

## ADR-0018 — Timeout/gubitak mreže: server-owned clock + grejs (OD-5 razrešeno)

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Faza 4 Slice 4c. Odgovori se NE snimaju tokom rada (bez autosave-a,
  §6.1); ako browser/mreža otkažu, nema sačuvanih odgovora. OD-5 je bio otvoren.
- **Odluka:** Server je autoritet za vreme (`attempts.expires_at`). **Grejs prozor
  `Attempt::SUBMIT_GRACE_SECONDS = 60`** posle isteka da klijentski auto-submit
  stigne. Politika:
  - unutar roka → normalno;
  - `rok < now ≤ rok+grejs` → attempt i dalje „otvoren" (`remaining_seconds=0`),
    submit **upisuje** odgovore (auto-submit sleće);
  - `now > rok+grejs` → attempt se **finalizuje na svaki pristup** (start/show →
    `completed`, `submitted_at = expires_at`), a submit **NE upisuje** odgovore
    (propušteno). Izgubljen browser bez submit-a = **timeout = prazan/parcijalan**
    (samo ono što je submit stigao pre roka).
  - `start` na finalizovanom/završenom attempt-u → **409** (klijent → dashboard).
  - Batch: **`php artisan attempts:finalize-expired`** zatvara zaostale
    in_progress attempte (za studente koji se ne vrate; cron/Faza 5).
- **Posledica:** napuštanje i povratak **ne daje dodatno vreme** (`submitted_at`
  se pečatira na stvarni rok). Auto-submit po isteku ostaje idempotentan (CC-07).

---

## ADR-0019 — Auto-ocenjivanje: normalizacija, sve-ili-ništa, MC single (OD-7 razrešeno)

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Faza 5 Slice 5a. CC-09 traži auto-ocenjivanje MC/gap i essay
  `pending_grading`. OD-7 (gap normalizacija + parcijalni poeni) bio otvoren.
- **Odluka:**
  - **MC = tačno jedan tačan odgovor** (radio na klijentu); tačno kada je skup
    izabranih == skup tačnih (praktično jedan id). Više-tačnih se ne koristi.
  - **Gap normalizacija:** `mb_strtolower` + `trim` + collapse razmaka (`\s+`→` `).
    **BEZ** skidanja interpunkcije/dijakritike. Prihvatljivi po gap-u = pipe-lista
    u `question_answers.text` (po `position`); tačan gap = normalizovani unos ∈
    normalizovane opcije.
  - **Parcijalni poeni: sve-ili-ništa PO PITANJU.** Puni poeni samo ako su svi
    delovi tačni (svi gapovi / tačan MC), inače 0.
  - **Trenutak:** ocenjuje se pri **završetku attempt-a** (submit i timeout/finalize;
    CC-08). `AttemptGrader::grade()` idempotentan.
  - **Essay:** bez auto-poena; ako test ima ijedan essay → attempt `pending_grading`
    (score = auto-deo, essay 0 dok admin ne oceni u 5b), inače `auto_graded`.
    `max_score` = zbir svih poena; nedovršeno auto-pitanje = 0.
- **Posledica:** `attempts.score/max_score/grading_status` + `attempt_answers.
  is_correct/awarded_points`; `GradingStatus` enum; `Domain/Competition/Support/AttemptGrader`.

---

## Otvorene odluke (blokiraju odgovarajuće module — ne pretpostavljati)

Voditi ovde; premestiti u ADR čim vlasnik proizvoda potvrdi. Izvor: `00` §7,
`PROJECT_CONTEXT.md` §14, i „Odluka" sekcije u `00`.

| # | Pitanje | Blokira | Status |
| --- | --- | --- | --- |
| OD-1 | Retake na rezultatu 0 — zadržati legacy ponašanje (neograničeno ponavljanje uz brisanje) ili ukinuti? | Faza 4 | **Razrešeno → ADR-0016 (jedan pokušaj)** |
| OD-2 | Na šta se odnosi broj „10.000" (prijave / druga sezona / očekivani rast)? | Faza 6 / `05` | Otvoreno |
| OD-3 | Zadržati mapiranje nivoa na quiz i pojedinačno pitanje, ili svesti na exam/test? | Faza 2/4 | Otvoreno |
| OD-4 | Redosled testova i uslov otključavanja sledećeg. | Faza 4 | **Razrešeno → ADR-0017 (strogo redom)** |
| OD-5 | Ponašanje pri gubitku browsera/mreže bez autosave-a. | Faza 4 | **Razrešeno → ADR-0018 (grejs + finalize)** |
| OD-6 | Nivo objave rezultata (ceo exam/quiz vs. pojedinačni test). | Faza 5 | Otvoreno |
| OD-7 | Fill-the-gap normalizacija (case, razmaci, interpunkcija, dijakritici, varijante) i parcijalni bodovi. | Faza 4/5 | **Razrešeno → ADR-0019 (case+trim+razmaci, sve-ili-ništa, MC single)** |
| OD-8 | Kako se duplirani `competitor_number` (4 u ovoj sezoni) tretiraju u migraciji. | Migracija | Otvoreno |
| OD-9 | Mapiranje legacy `active` 0/1 na `pending/graded/published/void`. | Faza 5 / migracija | Otvoreno |
| OD-10 | Povezivanje mobilnog naloga sa sezonskom prijavom. | Mobilni tok | Otvoreno |

## Odluke koje tek treba formalizovati (iz `PROJECT_CONTEXT.md` §15)

Student access/session model · mobilni StudentAccount · Terms/Privacy
verzionisanje · Theme Settings model · CMS model · lokalizacijski ugovor ·
Public/Admin navigacija · Android/iOS tehnologija · attempt/timeout state
machine · grading/publication workflow · migracija/cutover · sezonski model ·
istorijska analitika · performance SLO i scaling plan · privatnost/retention/audit ·
deployment/storage/backup.
