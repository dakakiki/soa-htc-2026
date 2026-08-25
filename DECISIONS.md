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
- **Ažuriranje (5d):** uslov napredovanja fronta pooštren — sledeći test se
  otključava tek na `published`, ne na `completed`. Vidi **ADR-0021**.

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

## ADR-0020 — Objava rezultata: batch po exam-u/testu, reverzibilno (OD-6 razrešeno)

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Faza 5 Slice 5c. CC-10: takmičar ne vidi bodove dok nije `published`;
  akcija autorizovana, idempotentna, auditovana; nivo objave bio OD-6.
- **Odluka:**
  - Admin objavljuje **batch-om na DVA nivoa: ceo exam/round ILI pojedinačan test**
    (`scope: exam|test`, `id`). Exam scope = svi attempti za testove tog exam-a.
  - Objavljuju se samo attempti koji su **completed i NISU `pending_grading`**
    (auto_graded/graded); pending se preskaču dok se ne ocene (vraća se broj).
  - **Reverzibilno:** `unpublish` vraća `published_at=null` (idempotentno u oba smera).
  - Audit: `attempts.published_at/published_by` + **`publication_batches`**
    (scope_type/scope_id/action/attempts_count/published_by/created_at). Gate `results.manage`.
  - Takmičar vidi **`score`/`max_score`** na dashboard-u samo kad je attempt `published`.
- **Posledica:** vezuje se za 5d (objava round-a = „odobreno" → sledeći round se
  otključava; sad auto). OD-9 (legacy active→status mapiranje) i dalje otvoren (migracija).

---

## ADR-0021 — Admin-approval gate: sledeći test se otključava tek na `published` (proširuje ADR-0017)

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Faza 5 Slice 5d. Po ADR-0017 „front" sekvence napreduje čim je
  prethodni test `completed`, pa se sledeći test/round nudi takmičaru pre nego što
  admin proveri/objavi rezultat. Originalni zahtev sa dashboard-a: sledeći round
  se pojavljuje **tek kad admin objavi** prethodni.
- **Odluka:** U `StudentAvailability::testStatuses()` front napreduje preko
  `completed` testa **samo ako je i `published`** (`attempts.published_at != null`).
  Completed-ali-neobjavljen test sam ostaje `completed`, ali sledeći test ostaje
  `locked` (umesto `next`) — i start (CC-07) vraća **403** dok admin ne objavi.
  - **Granularnost = svaka granica testa** (ne samo između round-ova): važi za
    svaki `test→test` i `exam→exam` prelaz u spljoštenoj sekvenci.
  - Pošto publish preskače `pending_grading` (ADR-0020), gate **prirodno čeka i
    ocenjivanje essay-a** pre otključavanja sledećeg.
  - **Monotono / reverzibilno:** `unpublish` prethodnog re-zaključava sledeći
    (retrakcija pristupa); front se zaustavlja na najranijem neobjavljenom testu.
- **Posledica:** jedna izmena u `testStatuses()` pokriva i prikaz (`/availability`)
  i server-side enforcement (`startableQuizId`). Bez izmene payload-oblika
  (statusi ostaju `locked/next/in_progress/completed`) i bez izmene frontenda.

---

## ADR-0022 — Reset pokušaja: void in-place + generated-column unique (CC-11)

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Faza 5 Slice 5e. CC-11 / PROJECT_CONTEXT §7.2/§7.5: admin odobrava
  novo polaganje → stari attempt postaje `void` (ne briše se), **razlog obavezan**,
  akcija autorizovana + auditovana, novi attempt ima svoj trag. Prepreka:
  `unique(registration_id, test_id)` (ADR-0016) blokira drugi pokušaj ako void red
  ostane u tabeli.
- **Odluka:**
  - **Novi status `AttemptStatus::Void`.** Reset flipuje attempt na `void`, nuluje
    `published_at/published_by`, i snapshot-uje pre-void stanje (status/score/
    grading_status/published_at) + `reason` + `reset_by` u **`attempt_resets`**
    (audit, po uzoru na `grade_revisions` iz 5b).
  - **Uniqueness = generated-column.** `unique(reg, test_id)` → `unique(reg,
    active_test_id)`, gde je `active_test_id` **VIRTUAL** generated `case when
    status='void' then null else test_id end`. Void redovi (NULL) ne kolidiraju;
    **tačno 1 aktivan pokušaj po (reg,test)** ostaje HARD DB-garancija (ADR-0016
    očuvan za aktivne). ⚠️ **VIRTUAL, ne STORED** — STORED tera table-copy koji
    MySQL odbija na tabeli sa FK-ovima (err 1215); VIRTUAL se dodaje in-place i i
    dalje indeksira. Migracija dodaje novi unique PRE dropa starog (leftmost
    `registration_id` pokriva FK indeks).
  - **Reset NE pravi nov attempt** — takmičar ga sam ponovo starta; void (isključen
    iz availability/start/grading/publish preko `Attempt::scopeActive`) sam vraća
    test na `next`. `POST /api/results/attempts/{attempt}/reset` `{reason}` (gated
    `results.manage`, idempotentno: drugi reset void-a → 422).
- **Posledica:** `active()` scope filtrira void u `StudentAvailability::attemptMap`,
  `AttemptController::attemptFor`, `GradingController::index` (publish/overview već
  filtriraju `status=completed`). Backend-only (admin UI za per-takmičar reset =
  zaseban kasniji slice). Edge: reset ranijeg testa ne dira kasnije `completed`
  testove (ciljana admin akcija). **OD-9** (legacy active→status) i dalje otvoren.

---

## ADR-0023 — Osnovni reporting: live-data agregacija, snapshot odložen (CC-12)

- **Status:** Prihvaćeno (2026-08-15); vlasnik proizvoda
- **Kontekst:** Faza 5 Slice 5f. CC-12 traži admin „osnovni pregled" sa filterima
  (season/geo/koordinator/level/quiz-exam-test/težina) i merama (registered,
  started, submitted, published, void; avg/min/max/median; prolaznost tek uz prag).
  „Istorijski snapshot" da kasnija promena master-podataka ne menja stare izveštaje.
- **Odluka:**
  - **Live-data reporting** nad tekućim `registrations`/`attempts` (bez novih
    tabela). **Snapshot je ODLOŽEN** — vezuje se za 3-slojni archive (Layer C
    `archive_test_results`), Faza 6+ (ADR-0013). **Ranking izostavljen** (nema
    formule); **prolaznost izostavljena** (nema definisanog praga).
  - **`GET /api/reports/summary`** (gated **`reports.view`** — NOVA permisija,
    13, odvojena od `results.manage`) → `totals` + opcioni `group_by`
    (`country|region|school|level|quiz|exam|test`) daje redove po dimenziji.
    Logika u `Domain/Competition/Support/ReportSummary`.
  - **Particija mera** (čist rez attempt redova): `started` = ne-void
    (in_progress+completed), `submitted` = completed, `published` = completed &
    `published_at`, `void` = void (5e). `registered` je **registration-level**
    (season/geo/koordinator/level filteri) → **ignoriše content filtere** i
    **null je za content group_by** redove (nije po testu). `avg/min/max/median`
    nad submitted skorovima; **median app-side** (MySQL nema percentil).
  - **Koordinator filter** = razreši `User::allowedSchoolIds()` → `whereIn`
    škole (prazan skup → 0 redova). Season default = `SeasonContext::active()`.
- **Posledica:** samo backend + testovi (admin Reports UI = kasniji slice).
  ⚠️ nova permisija → `db:seed --class=RolePermissionSeeder --force`. Perf:
  score-stat čita submitted redove (skalira s brojem submitted) — revidirati sa
  archive slojem za 10k. Caveat: `group_by=exam` join-uje `exam_test` (test u 2
  exama → dupli broj; retko). **OD-9** (legacy active→status) i dalje otvoren.

---

## ADR-0024 — Objava rezultata scope-ovana po državi/venue (proširuje ADR-0020)

- **Status:** Prihvaćeno (2026-08-16); vlasnik proizvoda
- **Kontekst:** Redizajn Publishing strane. Originalna objava (ADR-0020) otkriva
  ceo test/exam **globalno** — svaki `completed` pokušaj tog testa, bez obzira na
  državu takmičara. Nova strana dobija filtere **country/venue**; pitanje je da li
  oni samo sužavaju prikaz ili **scope-uju samu objavu**. Takmičenja se polažu i
  ocenjuju po državama u različito vreme, pa koordinator hoće da pusti rezultate
  svoje države kad su spremni, a ne da čeka sve.
- **Odluka:**
  - country/venue filteri **scope-uju samu objavu**: publish/unpublish deluje samo
    na pokušaje takmičara iz filtrirane populacije (**season + country + school/
    venue**). Reuse `populationRegistrationIds` — **isti helper kao reset** (ADR-0022).
    Test tako može biti **delimično objavljen** (npr. Srbija objavljena, S. Makedonija
    skrivena dok se ne objavi zasebno) — to je namerno.
  - Publish **lista** (`GET results/overview`) i **akcija** (`POST results/publish`)
    oba scope-ovani preko iste populacije; brojači `completed/published/pending`
    prate filter. **Quiz je OBAVEZAN**; exam/test sužavaju unutar kviza; **bez
    country/venue = cela season populacija** (globalno = ranije ponašanje, kompatibilno).
  - `quiz_id` u publish payload-u je **nullable** → 6 postojećih publish testova
    (bez scope-a) prolazi nepromenjeno (aktivna sezona = round 14, isti brojači).
  - **Audit potpun:** `publication_batches` proširen `country_id`/`school_id` da se
    zabeleži scope SVAKE objave (bez toga bi „objavljeno za Srbiju" bilo neraspoznatljivo
    u auditu).
- **Posledica:** proširuje ADR-0020; objava je sada **per-populacija**, delimično-
  objavljeno stanje po testu je moguće i očekivano. Reset i publishing dele isti
  populacioni helper (konzistentnost). ⚠️ Migracija dodaje 2 kolone na
  `publication_batches` — obična `migrate` (NE `fresh`); bez nove permisije.

---

## ADR-0025 — Master-data migracija: legacy_id_maps + dedup + hash + anonimizacija samo studenti

- **Status:** Prihvaćeno (2026-08-17); vlasnik proizvoda
- **Kontekst:** Faza 6, prvi implementirani slice. Vlasnik promenio redosled: umesto
  „archive-first", prvo se migriraju **persistentni entiteti** (zemlje/regioni/venue/
  admini/country-koordinatori — prenose se kroz sezone) i generiše **50k sintetičkih
  studenata** za load-test 10k; **arhiva (Layer C) se dizajnira TEK POSLE**, iz realnih
  rezultata + poređenja sa starim appom (a ne naslepo unapred).
- **Odluka:**
  - **`legacy_id_maps`** (04 §6) kao kičma linijaže: `(source, source_table, source_pk,
    target_type) → target_id` (unique). Omogućava many-to-one dedup da ostane
    reconcilable i idempotentnost svih import komandi.
  - **Dedup škola:** merge SAMO kad se poklapaju **zemlja + normalizovano ime + neprazan
    grad**; isto ime u istoj zemlji sa drugim/praznim gradom = **zasebne škole** (škola sme
    isti naziv u istoj zemlji u drugom gradu). Škole sa nemapiranom zemljom = **karantin**
    (ne importuju se, u izveštaj). Countries/regions su čisti → 1:1.
  - **Lozinke:** bcrypt hash se **kopira verbatim** (Laravel `hashed` cast preskače
    re-hash za već-hešovanu vrednost) → koordinatori zadržavaju lozinke.
  - **Anonimizacija SAMO studenti** (kad se budu migrirali realni). Useri/koordinatori se
    migriraju sa realnim imenom/emailom — **dev baza ostaje lokalno i NIKAD ne ide na git**
    (svesno, dokumentovano odstupanje od ADR-0004, prihvatljivo jer nema izlaganja PII-ja).
  - **Scope:** admini bez `assignment_schools` (drže `schools.view.all`); country-koordinatori
    scope iz `user_schools` (razrešen kroz `legacy_id_maps`); country-coord bez legacy scope-a
    **ostaje bez scope-a** (ne izmišlja se). School-koordinatori (level 1) se NE migriraju
    (per-sezona). `countries.code` proširen `char(2)→char(3)` (legacy short 2–3 znaka).
- **Posledica:** 4 idempotentne `legacy:import-*` komande + `legacy_id_maps` + model
  `App\Domain\Migration\Models\LegacyIdMap`. Verifikovano reconciliation-om (96 countries,
  126 regions, 3878 schools 0-orphan-FK, 127 staff). Realne registracije/rezultati i
  **OD-8/OD-9** ostaju za kasnije (koriste se sintetički za load-test). Import komande nemaju
  unit-test (operativne). Vidi status u `docs/04 §12`.

---

## ADR-0026 — Legacy mojibake: CP850 double-encoding, reverzija na import-boundary-ju

- **Status:** Prihvaćeno (2026-08-18)
- **Kontekst:** Nazivi škola/koordinatora i sadržaj pitanja prikazivali su se pokvareno
  (npr. `─░.T.├£. Geli┼ƒtirme Vakf─▒…` umesto `İ.T.Ü. Geliştirme Vakfı…`,
  `Kri┼¥evci` umesto `Križevci`). Legacy dump je učitan u MySQL kroz **Windows
  konzolu na code page 850**, pa je svaki bajt originalnog UTF-8 reinterpretiran kao
  CP850 glif i re-snimljen kao UTF-8 (double-encoding). Korupcija je već u `legacy`
  bazi; import ju je verno kopirao.
- **Odluka:**
  - **Codepage je CP850, ne CP437.** Empirijski dokazano na celom skupu: 109 redova
    gde se dva codepage-a razlikuju (`ž/Ž/®/ø/â`) razrešava se ispravno **samo** pod
    CP850 (`Kri┼¥evci`→`Križevci`, CP437 bi dao `Kriŝevci`). CP850 čisti svih **1352**
    pogođena polja u `schools` + 2 u `users` + 1 u `questions`, **0 residual, 0 fail**.
  - **Reverzija = deterministička, na app-boundary-ju:** `iconv('UTF-8','CP850',$s)`
    vrati originalne bajtove → čitaju se kao UTF-8. Legacy baza se NE re-učitava
    (temp/one-off; reverzija je pouzdanija i reproducibilna).
  - **Jedan izvor istine:** `App\Domain\Migration\LegacyText::fix()`. Sigurno +
    idempotentno — dira samo string sa box-drawing/block glifovima (`U+2500–U+259F`)
    i prihvata reverziju samo kad da validan, više-ne-pokvaren UTF-8; sve ostalo
    (već-čist tekst, legit dijakritici van CP850) vraća netaknuto.
  - **Dve odbrane:** (a) `php artisan legacy:fix-encoding [--dry-run]` popravlja
    postojeće podatke in-place (surgical, ne remeti id-jeve/relacije — bezbednije od
    re-importa); (b) `LegacyText::fix()` uklopljen u mappere `legacy:import-schools`,
    `-coordinators`, `-questions` da re-import ne vrati korupciju.
- **Posledica:** `LegacyText` (unit-testiran, 15 slučajeva uklj. safety/idempotentnost)
  + `FixLegacyEncoding` komanda. Countries/regions/quizzes/exams/tests/difficulty_categories
  su čisti (ASCII u legacy izvoru). Pretpostavka: nema Cyrillic-origin korupcije (marker
  je Latin-box-glif); ako se pojavi, vidljivo je i rešava se tada.

---

## ADR-0027 — Results sloj: denormalizovani `registration_results` (Layer B) za import/export/grid/reports/arhivu

- **Status:** Prihvaćeno (2026-08-19); vlasnik proizvoda. **IMPLEMENTIRAN KOMPLETNO — Faze 1–5 (2026-08-19/20), suite 252 zeleno** (detalji u „Implementacija" beleškama niže). Razrešava **follow-up #3** (kvalifikaciona logika NF/Q/WF).
- **Kontekst:** Legacy ima **„Import Results"** (xlsx `Student ID | Result | Qualification`) i dva **„Export"** (Results / Results with Answers). Legacy import (`TestDataImportResultsController`) piše u `quiz_results` (`test_result`=score, `active=1`=objavljeno) + denormalizuje `el_student` (skorovi po rundi/tipu + `q_semi/q_quali/q_final`); lookup po vidljivom `student_id` (=naš competitor_number) → interni `entry_id`. Legacy export (`TestResultsExportController`): „Results" iz `quiz_results` (wide per-student, **hardkodovano samo Preliminary+National**), „with Answers" iz `test_results` (po pitanju, jedan test). Sadašnji app grid (`RegistrationResults`) i reports (`ReportSummary`) računaju **uživo** iz `attempts`. Competition rezultati stižu i offline (import), treba export, i na novu sezonu → arhiva.
- **Odluka:**
  - **Layer A ostaje `attempts`/`attempt_answers`** (in-app granularno: odgovori, tajming, grading).
  - Uvodi se **denormalizovani `registration_results` (Layer B)**, red po **(registration_id, test_id)**: denormalizovani `exam_round_id`/`test_type_id`/`quiz_id`, `score`/`max_score`, **`source` enum(attempt|import)**, `published_at`/`published_by`, `season_id`; `unique(registration_id, test_id)`.
  - **`registration_qualifications`** per (registration_id, exam_round_id): `code` **S/Q/F** = napredovanje (legacy `q_semi`/`q_quali`/`q_final`): **S→National**, **Q→Regional Qualifiers**, **F→World final**. Popunjava RQ/WF kolone na gridu.
  - **Upis:** Publish attempt-a → **upsert** u Layer B. **Import** → **direktno** u Layer B (`source='import'`, `published_at=now`) + qualifications; **bez** sintetičkih `attempts`/`quiz_started` (import nema odgovore/tajming). Lookup po **`competitor_number`** (batch), upsert po (reg,test).
  - **Čitanje:** Grid + Reports + **„Export Results (all)"** čitaju **Layer B** (brzo, bez live JOIN-ova; **dinamičke kolone** iz `RegistrationResults::columns()` — ne hardkodovati Preliminary+National kao legacy). **„Export with Answers"** čita **Layer A** (samo in-app; importovani nemaju odgovore).
  - **Competition-scoped:** sample quizzes **nemaju** import/export/arhivu; sample = **auto-published**, prikazuje se odmah, živi samo u `attempts`, van grida (`RegistrationResults` već isključuje `Sample` rundu). Import/export dropdown = **samo competition** (legacy `quizzesActiveCompetition()`).
  - **Arhiva (Layer C):** `season:reset` proširen — pre wipe-a **snapshot Layer B (+qualifications)** u `archive_*` tagovano `round_number`/`season_id`, pa wipe.
  - **Za sada 1 competition quiz** → grid grupiše **po rundi** (spajanje prihvaćeno); razdvajanje po quiz-u = kasnija dorada ako zatreba.
- **Posledica:** nove tabele `registration_results` + `registration_qualifications`; publish upsert; grid/reports read-switch na Layer B (+ testovi); nove strane **Results→Import** i **Results→Export** (all + with-answers, reuse `XlsxWriter` + `reportFilters`); `season:reset` dobija Layer C snapshot; sample auto-publish (mala izmena). **Cena:** score na 2 mesta (`attempts.score` + `registration_results.score`) → sync na publish/unpublish/reset. **Redosled:** (1) migracije, (2) publish→Layer B + grid/reports read-switch + testovi, (3) Import, (4) Export, (5) arhiva. Legacy referenca: `context for the project/legacy-app` (`TestDataImportResultsController`, `TestResultsExportController`). Plan u [[results-import-export-plan]].
- **Implementacija — Roster arhiva (OD-9 posledica) GOTOVA (2026-08-20):** nova `archive_registrations` (denormalizovan roster: `season_id`/`round_number`/`competitor_number`/`name`/`country`/`region`/`venue`/`school_external`/`level`/`grade`/`attendance`/`archived_at`; bez FK, self-contained). `ResetSeasonData` metoda `archiveResults`→`archiveBeforeWipe()` sad snima I **ceo roster (SVE registracije, ne samo one sa rezultatima)** PRE wipe-a → omogućava „prijavljeni po zemlji vs polagali" za prošle sezone (registered = `archive_registrations`, participated = distinct competitor u `archive_registration_results`). Plan/output dobili roster red. Dokazano na dev MySQL (dry-run: 50k roster / 800 rezultata / 3 quals). `SeasonResetTest` +1 (ceo roster uklj. ne-učesnike). Suite 255. **Istorijska migracija (svi `el_student` → `archive_registrations`) = Faza 6.**
- **Implementacija — Faza 5 (Arhiva) GOTOVA (2026-08-20) → ADR-0027 KOMPLETIRAN:** nove tabele `archive_registration_results` + `archive_registration_qualifications` — **potpuno denormalizovane, self-contained** (bez FK; nose `season_id`+`round_number` tagove + snapshot identiteta competitor_number/name/country/region/venue/external/level + labele exam_round/test_type/quiz/test + score/max_score/source/published_at/archived_at), jer se `registrations` brišu a content može da se re-verzioniše → **arhiva se čita bez join-a na config**. `ResetSeasonData` (`season:reset`) proširen: unutar wipe-transakcije **PRE** WIPE petlje set-based `INSERT…SELECT` Layer B → archive (join `registrations`+config za labele, tag `round_number` iz `seasons` po redu), pa wipe; plan (dry-run) + „Done. Applied" prikazuju archived brojeve; docblock ažuriran (ARCHIVE korak, više nije „out of scope"). Arhiva NIJE u `WIPE_TABLES` (trajna). Dokazano na MySQL (`--dry-run` plan ARCHIVE red) + sqlite. `SeasonResetTest` +2 (arhivira pre wipe-a; dry-run ne arhivira). **Napomena:** ADR-0027 Layer B arhiva NADJAČAVA stariji „3 odložene archive-odluke" iz [[faza6-plan]] (te su za širu Faza-6 istorijsku Layer C migraciju). Bump `round_number` i dalje van scope-a. Suite 252 zeleno.
- **Implementacija — Faza 4 (Export) GOTOVA (2026-08-19):** `App\Domain\Competition\Support\ResultExporter` vraća `[headers, rows]`: **`all()`** = wide red-po-takmičaru iz **Layer B** — identitet + **dinamičke kolone iz `RegistrationResults::columns()`** (skor po tipu + „— Total" po rundi + S/Q/F za RQ/WF) + grand Total (reuse `forRegistrations()`, NE hardkodovano kao legacy); **`withAnswers(testId)`** = red-po-takmičaru za jedan test iz **Layer A** (`attempts`/`attempt_answers`) — po pitanju render odgovora (MC opcije preko `question_answers` labela, gap tekstovi, essay) + ✓/✗ marker + score. Endpointi `GET results/export` (populacija + opciono quiz; regovi sa Layer B redom) i `GET results/export-answers` (quiz+exam+test obavezni; completed attempts u populaciji), gated `results.manage`, download preko `XlsxWriter` + `XlsxReader` round-trip u testu. Frontend `ExportPage.vue` (filter card reuse `reportFilters` + 2 dugmeta; with-answers disabled dok nema quiz/exam/test), nav „Export results". `ResultExportTest` (5). **Zamka:** test helper NE sme da se zove `result()` (kolizija sa final `PHPUnit\TestCase::result()`). Suite 250 zeleno, type-check+build zeleni. **SLEDEĆE = Faza 5 (arhiva u `season:reset`).**
- **Implementacija — Faza 3 (Import) GOTOVA (2026-08-19):** dependency-free `App\Support\XlsxReader` (ZipArchive+SimpleXML: shared/inline stringovi, brojevi, gap-kolone; round-trip sa `XlsxWriter`); `App\Domain\Competition\Support\ResultImporter` — parse redova (`Student ID | Result | Qualification`, decimalni zarez ok), batch lookup po `competitor_number`, **precedence:** (reg,test) `source=attempt` → **skip (skipped_conflict)**, `source=import` → **update**, nema → **insert**; upsert u `registration_results` (`source=import`, `published_at=now`, `max_score=null`) + `registration_qualifications` (S→National, Q→Regional Qualifiers, F→World final), sve u transakciji; vraća summary (imported/updated/skipped_conflict/not_found/invalid/qualifications/not_found_numbers). 3 endpointa gated `results.manage`: `GET results/import/options` (competition-only kaskada), `GET results/import/template` (xlsx header), `POST results/import` (validira quiz→exam→test lanac + non-Sample rundu, xlsx ili csv, resolve round/type server-side). Frontend: `ImportPage.vue` (kaskada + upload + template + summary), nav „Import results", `api/results.ts`. **Grid payoff:** `RegistrationResults::forRegistrations()`+`detail()` sad spajaju `registration_qualifications` → S/Q/F pune RQ/WF kolone (grid ćelija + modal badge). Suite 245 zeleno, type-check+build zeleni. **Otvoreno za Fazu 4:** Export (all iz Layer B / with-answers iz Layer A).
- **Implementacija — Faze 1–2 GOTOVE (2026-08-19):** migracije + modeli (`RegistrationResult`/`RegistrationQualification`); `ResultLedger::reconcile(regovi, testIds)` (set-based DELETE `source=attempt` + INSERT…SELECT iz trenutno-objavljenih ne-Sample attempt-a) zakačen na publish/unpublish/single-reset/bulk-reset (sve u transakciji); `RegistrationResults::forRegistrations()`+`detail()` čitaju Layer B; `season:reset` WIPE + `registration_results`/`registration_qualifications`. Suite 235 zeleno. **Dve refinacije ADR-a:** (a) **sample auto-publish keyed na Sample RUNDU** (`AttemptGrader`, ne na `quiz_type`) — ista granica koju grid/ledger koriste za isključivanje, pa je Sample-rezultat uvek i auto-otkriven i van Layer B; ne-Sample runde ostaju skrivene do admin publish-a. (b) **`ReportSummary` OSTAJE Layer A** — funnel (registered/started/submitted/**published**/void) + score-distribucija su nad **submitted** attempt-ima (zaključano `ReportTest`-om: `score.count`=2 uključuje neobjavljeni completed), nemaju Layer-B analog; „reports iz Layer B" iz ADR-a = **grid + Export-all** (dinamičke kolone), ne CC-12 funnel. Kada stigne import (Faza 3), objavljeni importovani rezultati se broje kroz grid/export, ne kroz funnel. **Kolizija (reg,test) import↔attempt** (isti unique) odložena za Fazu 3 (ledger dira samo `source=attempt`). **Grid prikaz S/Q/F u RQ/WF kolonama** čeka import (Faza 3) da popuni `registration_qualifications`.

---

## ADR-0028 — SOA Cert (participation sertifikat): CMS-editabilan sadržaj + chunked PDF + legacy-identičan izgled

- **Status:** Prihvaćeno (2026-08-21); vlasnik proizvoda. **IMPLEMENTIRAN** (`bf76632`, u `main`-u; suite zeleno).
- **Kontekst:** Legacy „SOA Cert" = participation sertifikat po venue-u (DomPDF, blade `pdf_soa_cert_preliminary/semifinal`, chunk 50 strana). Vlasnik traži **izgled identičan legacy-ju** (font/bold/razmak „jako važni") + **editabilan sadržaj** (naslov, telo, potpis, logo, QR) bez deploy-a.
- **Odluka:**
  - `App\Domain\Competition\Support\SoaCertificate` gradi mPDF-friendly body HTML, **1 strana/student**, iz **results Layer B** (Prelim=Reading+UoE, National=Reading+Writing; 0 ako nema objavljenog skora).
  - **Chunk kao legacy (default 50, `config('cert.chunk')`):** mPDF ~0.3s/strana → plan endpoint + chunk render (0-based part), `PdfWriter::fromPages(..., plain:true)` = **bez running header/footer** (sertifikat drži celu stranu).
  - **CMS u Settings → SOA Certificate** (`settings.manage`): rich-text `cert_body` sa `[placeholders]` (`[name][category][round][edition][venue][school][city][country][points]`) + `cert_header_title` (HTML za two-tone) + `cert_signature_text` + upload logo/signature/qr. Prazno → built-in default + „SOA HTC" wordmark.
  - **`[edition]`** = aktivna `Season::round_number` kao **superscript ordinal** (14th/21st…), sufiks (st/nd/rd/th) računat iz broja (11–13→th).
  - **Dizajn identičan legacy-ju** (verifikovano pymupdf side-by-side): crn ram cela strana → header **logo-levo + two-tone „Global Hippo Association" desno** → unutrašnji ram → telo levo `line-height:2.2` **INLINE** (mPDF ignoriše LH iz CSS klase!) + razmak pasosa preko `<br>` → potpis+ime levo / QR desno na dnu.
  - **Reusable `ImageThumb`** (slika + crveni X) = brisanje asset-a sa servera (delete endpoint po polju); obrazac za svako image/file polje (primenjen i na Coordinator image/file).
  - **Editor boje (deljeni `RichTextEditor`, ide svuda):** `@tiptap/extension-text-style` (TextStyle+Color) + color-picker sa theme-swatch-evima; crta `<span style="color">` (mPDF poštuje). Header title kroz editor → PHP skida block tagove da inline header ostane.
- **Posledica:** nove `settings.cert_*` kolone (2 migracije); `SettingsController` cert CRUD + `deleteCertificateAsset`; `RegistrationController` cert plan/chunk; front `CertificateSettingsPage`+`SoaCertificateModal`+`ImageThumb`. Zavisnost `@tiptap/extension-text-style@3.30.1` (pin na `@tiptap/core` 3.30.1). mPDF zamke dokumentovane (height samo `<td>`; `<p>` margine u `<td>` NE rade→`<br>`; **line-height radi SAMO inline**). Legacy ref: `context for the project/legacy-app` (`pdf_soa_cert_*.blade.php`).

---

## ADR-0029 — Bulk student import (create) + attendance update: dva odvojena fajl-toka

- **Status:** Prihvaćeno (2026-08-21); vlasnik proizvoda. **IMPLEMENTIRAN** (`3f02b51`, u `main`-u; suite 298 zeleno).
- **Kontekst:** Legacy „Upload Students" = dva fajl-toka: (1) **kreiranje** rostera (`Name | Date Of Birth | School if different | Grade | Category`), (2) **„update"** koji zapravo samo postavlja attendance (`Candidate no | Absent 0/1`). Vlasnik: treba oba; **in-app klik-po-redu za attendance ODBAČEN** („niko neće da klikne 1000 studenata"), mora odvojeno/preko fajla.
- **Odluka:**
  - **Import (create)** — `App\Domain\Competition\Support\RegistrationImporter`; forma **Country + Venue + Category set** + xlsx.
    - **Category set (razrešavanje kategorije):** `difficulty_levels.level_short` (BH/LH/H1–H5, S1–S5) **NIJE jedinstven** — 24 nivoa, svaki short 2× (po varijanti). Varijanta je **country-determined**: `difficulty_categories.countries_all=1` = Default (sve zemlje), `=0` = specifične zemlje preko `difficulty_category_country` pivota. Forma nudi primenljive **regular** kategorije za izabranu zemlju; short (BH vs S1) bira Regular/Special, **upareni** po istoj primenljivosti. (Vlasnik: varijantu „7" zadržati, koristi se.)
    - **Reject-whole-file:** svi redovi validirani pre upisa; jedan loš → **0 kreirano** + `error_count`. UI: opšta poruka + count + dugme koje skine **isti fajl sa dodatom „Error" kolonom** (`errorReport()`) po neispravnom redu → fix + re-upload (extra kolona ignorisana). DOB parsira `dd.mm.yyyy` (+ Excel serial). **Perf: chunked bulk INSERT** (chunk 500) — ~5k studenata ~4s (naspram ~38s sa Eloquent create() po redu); `set_time_limit(300)`.
  - **Attendance update** — `App\Domain\Competition\Support\AttendanceImporter`; **odvojen** fajl `Candidate no | Absent (0/1)`. **Apply-and-report** (NE reject, za razliku od create-import-a): ažurira sve pronađene/validne redove, prijavi `{updated, not_found, invalid, not_found_numbers}` — jedan typo ne blokira markiranje 1000. Upis = **2 bulk UPDATE** (whereIn absent/present) po `competitor_number`. Coordinator **venue-scoped** (tuđi venue → not_found).
- **Posledica:** `RegistrationImporter` (validate/import/errorReport) + `AttendanceImporter`; endpointi `registrations/import` (+ `/template`, `/category-sets`, `/errors`) i `registrations/attendance-import` (+ `/template`); front `RegistrationImportModal` + `AttendanceImportModal`, grupisani sa „Register student" u header-u. Gate `create`=can_student_insert / attendance `update`=can_student_edit. Legacy ref: priloženi `StudentsForImport(1).xlsx` / `StudentsForUpdate.xlsx`. Detalji — memorija `students-import-attendance`.

---

## ADR-0030 — Coordinator bulk import/export: razrešavanje po imenu, bez lozinke u fajlu

- **Status:** Prihvaćeno (2026-08-21); vlasnik proizvoda. **IMPLEMENTIRAN** (suite 305 zeleno).
- **Kontekst:** Coordinators strana imala samo ručni add/edit; vlasnik tražio import/export sa template-om, „kao što je u legacy-ju". Legacy aplikacijski kod NIJE u repou — nalaz izveden iz legacy **modela podataka** (baza `soahtc_legacy`): koordinatori žive u `users` razdvojeni `user_level` (**10**=admin 12, **5**=country coord 115, **1**=school coord 2367 — sezonski, ne migriraju se), opseg venue-a u `user_schools`, polja `name/email/password/country_id/region_id/active/city/address/phone/can_student_*/can_reset_test_results`.
- **Odluka:**
  - **Obim = samo country coordinators.** Bez „Level" kolone — svaki red dobija `country_coordinator` ulogu (admin se ne uvozi masovno; school-coord je sezonski, kao ni u legacy migraciji).
  - **BEZ „Password" kolone.** Provera legacy baze pokazala da svih 115 country-koordinatora IMA bcrypt hash, ali lozinke u čistom tekstu u `.xlsx` su bezbednosni rizik (vlasnik: „ako toga nema u legacy, nemoj ni da kreiraš"). Import kreira nalog sa `Str::random(40)` (hashed cast bcrypt-uje → neupotrebljiva) — **admin postavi lozinku u edit formi** (polje već postoji, prazno = bez promene). Napomena o tome stoji u modalu. Posledica: uvezeni koordinator ne može odmah da se prijavi. Alternativa (auto-generisana + reset link mejlom) odložena — traži podešen SMTP.
  - **Razrešavanje po IMENU, ne po id-u** (admin ne vidi interne id-jeve): Country po imenu; **Region i Venues scoped na državu tog reda** (isto ime u drugoj zemlji ne prolazi); Venues = **imena razdvojena zarezom** (= scope opseg, `assignment->schools()->sync()`); lookup case/whitespace-insensitive.
  - **Reject-whole-file + anotirani fajl** — isti ugovor kao ADR-0029 create-import: svi redovi validirani pre upisa, jedan loš → **0 kreirano** + `error_count`, dugme skine isti fajl sa dodatom **„Error"** kolonom. Detektuje i **duplikat email-a unutar fajla** (ne samo postojeći u bazi). Bez bulk INSERT-a (obimi su mali — koordinator po venue-vlasniku, ne hiljade), pa red-po-red u jednoj transakciji.
  - **Export = isti layout kao template** (bez lozinke) → fajl round-trip-uje: exportuj, doradi, re-importuj. Poštuje **iste filtere kao lista** (deljeni `filteredCoordinators()`), ceo skup a ne jedna strana.
- **Posledica:** `App\Domain\Organization\Support\CoordinatorExporter` (`HEADERS` + `export()`) + `CoordinatorImporter` (`import()`/`errorReport()`); endpointi `coordinators/export`, `coordinators/import` (+ `/template`, `/errors`) — registrovani **pre** `apiResource` da `export` ne bude uhvaćen kao `{coordinator}`; front `CoordinatorImportModal` + Import/Export dugmiće u header-u. Kolone: `Name·Email·Country·Region·Venues·City·Address·Phone·Status·Can add·Can edit·Can delete·Can reset`. Gated `create`/`viewAny` na `User` (`users.manage`). Testovi `CoordinatorImportExportTest` (7). Detalji — memorija `coordinator-import-export`.

---

## ADR-0031 — Admin UI konzistentnost: header akcije, status kontrola, filteri bez dugmadi

- **Status:** Prihvaćeno (2026-08-21); vlasnik proizvoda. **PRIMENJENO kroz ceo admin** (suite 305 zeleno).
- **Kontekst:** Strane su nastajale kroz više faza pa su se razišle: „add" dugmiće u header-u imali dve veličine (`px-4 py-2` bez ikonice vs `px-3 py-1.5` + ikonica), status se na nekim formama unosio `ToggleSwitch`-em a na drugima `ButtonGroup`-om, a filter kartice su negde imale Filter/Reset dugmiće a negde auto-primenu. Vlasnik tražio jedinstven izgled („isto kao Students").
- **Odluka — tri pravila, Students strana je referenca:**
  1. **Header akcije.** Primarna akcija pored naslova = `inline-flex items-center gap-1.5 rounded-md bg-brand-primary px-3 py-1.5 text-sm font-medium` + **ikonica 16px** (`IconPlus` za „add"). Sekundarne akcije (Import/Export/report) = `ExportButton` (brand-accent, `icon` prop) u istom redu, desno. *Ne* važi za dugmiće u dnu formi/modala (Save/Cancel/Edit/Back) — oni ostaju `px-4 py-2` i usklađeni su međusobno.
  2. **Status kontrola zavisi od konteksta.** U **add/edit formama i modalima** status je **`ButtonGroup`** (zeleno aktivno / sivo neaktivno) — vidljiv je i trenutni i alternativni izbor, što je jasnije za polje koje se snima. U **tabelama** ostaje `ToggleSwitch` (inline prekidač = brza izmena jednog reda, uz lak PUT). Ostali boolean-i (attendance, `is_correct`, „All countries") ostaju toggle jer nisu status. Segmentovane kontrole u istom modalu dele istu aktivnu boju (zelena) da ne izgledaju kao dva različita mehanizma.
  3. **Filteri bez dugmadi.** Filter kartica nema Filter/Reset dugmiće: selecti i `SearchSelect` primenjuju se **odmah** (`load(1)`), tekstualna pretraga na **Enter** (`@submit.prevent`). Stanje filtera se sinhronizuje u URL i restauriše na mount-u; **nikad se ne čisti automatski** posle prikaza/exporta (korisnik ih briše ručno).
- **Posledica:** primenjeno na 11 list-strana (Exams/Questions/Quizzes/Tests/Lookup/Coordinators/Difficulty/Locations/Roles/Users/Venues), na content forme (Exam/Question/Quiz/Test) i Difficulty modal, te na Coordinators filter. Nove strane prate ova pravila bez posebnog dogovora. Reusable: `ButtonGroup` (`activeClass` po opciji), `ToggleSwitch`, `ExportButton`.

---

## ADR-0032 — Venues import/export: id-vođen upsert, mapiranje po zaglavlju, duplikat-guard na (country, name, city)

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda. **IMPLEMENTIRAN** (suite 318 zeleno).
- **Kontekst:** Venues strana imala samo ručni add/edit. Vlasnik dostavio legacy „Venues Export" (`23.08.2026_Venues_Export.xlsx`, 3906 redova) kao specifikaciju izgleda, ali **legacy nema import** — ni template ni kod (legacy app nije u repou). Format je zato izveden iz legacy `schools` tabele + tog export-a. Nalaz: legacy kolone `c_name/c_phone/c_email` = **school koordinator (level 1)** vezan preko `user_schools` — potvrđeno na podacima (venue sa samo country-koordinatorom ima te ćelije prazne). Kod nas je taj nivo nemigriran (ADR-0025), pa su kolone prazne dok se ne dodaju.
- **Odluka:**
  - **„Venue ID" vodi operaciju:** prazan → kreira, popunjen → ažurira baš taj venue (nepostojeći id = greška). Poravnavanje po imenu se **ne nudi** — imena se ponavljaju, id je jedini pouzdan ključ. Time export → doradi → import postaje pravi round-trip za masovne ispravke.
  - **Kolone se mapiraju po NAZIVU ZAGLAVLJA, ne po poziciji** (odstupanje od ADR-0029/0030, gde su pozicije fiksne). Razlog: exportovani fajl nosi i računate kolone (`Coordinator*`, BH…S5, Total); mapiranje po imenu ih jednostavno ignoriše, pa se export vraća **bez ikakve dorade**. Kolona koje nema u fajlu se ne dira pri izmeni; kolona koja postoji je merodavna (prazna ćelija briše vrednost).
  - **Duplikat-guard samo za redove sa praznim id-em**, na otisku **(country, name, city)**. Prvobitno je bio (country, name) — vlasnik ispravio („u dva grada može postojati venue sa istim imenom"), i podaci to potvrđuju: **12 grupa / 24 venue-a** duplo po (name, country), a **0** po (name, country, city). Guard hvata i ponovljen red unutar istog fajla; poruka nosi postojeći Venue ID i uputstvo. Namerni blizanci se i dalje dodaju kroz formu.
  - **Export = legacy layout + tri polja koja mu fale** (Hours of English, Venue type, Status) — bez njih bi round-trip tiho gubio podatke. Poštuje iste filtere kao lista (deljeni `filtered()`).
  - **BH…S5 brojevi se stvarno računaju** iz rostera, sabrano **po `level_short`** (isti short postoji u dve varijante kategorija). Do sada su `SchoolResource` i `CoordinatorResource` vraćali fiksne nule, pa je lista prikazivala 0 iako venue ima takmičare; oba sada čitaju `VenueCompetitorCounts`, punjen po strani u kontroleru (jedan grupisan upit, ne po redu).
- **Perf (mereno na dev bazi: 3878 venue-a, 50k registracija):** profilisanje je otkrilo tri uska grla i sva su uklonjena — coordinator export **6080 → 62 ms** (hidratacija 2225 School modela → ravan join), venue import 1000 redova **7658 → 519 ms** (`School::pluck()` je sam trošio 1878 ms → `DB::table`; upis red-po-red → chunked `insert`/`upsert`), fajl grešaka **2369 → 301 ms**. Pravilo koje iz ovoga sledi: **izveštajni/bulk kod ide ravnim query builder-om**, Eloquent hidratacija se plaća po redu. Ostaje poznato: `XlsxWriter` troši ~17µs/ćeliji (1836 ms od 2705 ms na punom exportu 3878 venue-a) — zajednički za sve export-e, optimizacija je backlog.
- **Posledica:** `App\Domain\Organization\Support\SchoolExporter` / `SchoolImporter` / `VenueCompetitorCounts`; endpointi `schools/export`, `schools/import` (+ `/template`, `/errors`) — **pre** `apiResource` da `export` ne bude uhvaćen kao `{school}`; front `VenueImportModal` + Import/Export dugmiće. Template kolone: `Venue ID·Venue·Country·Region·City·Address·Phone·Email·No. Invigilators·Hours of English·Venue type·Status`. Gated `create`/`viewAny` na `School`. Testovi `VenueExportTest` (5) + `VenueImportTest` (13, uključujući „isto ime u drugom gradu MORA da prođe"). Detalji — memorija `venues-import-export`.

---

## ADR-0033 — Ikonične kontrole nose tooltip; bubble se renderuje van svog kontejnera

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda. **PRIMENJENO kroz ceo admin** (suite 324 zeleno).
- **Kontekst:** Popis svih 69 komponenti našao je **26 kontrola bez ijednog tooltipa** (zatvaranje modala, strelice za redosled, brisanje čipa, „clear" u selektu) i još nekoliko koje su imale samo native `title` — vizuelno stran ostatku aplikacije. Uz to, vlasnik je prijavio da tooltip na Quizzes listi (katanac = kviz ima lozinku) nestaje iza zaglavlja tabele.
- **Odluka:**
  - **Svaka kontrola koja prikazuje samo ikonicu dobija `<Tooltip>`** + `aria-label` sa istim tekstom. Isto važi za prekidače u tabelama (nemaju vidljivu labelu). Kontrola koja **ima** vidljivu labelu (status toggle u formi, „Add exam") tooltip dobija samo ako donosi više od same labele — kod Export/Import to je pun opis radnje.
  - **Native `title` se ne koristi** — izgleda drugačije i ne prevodi se. Sve ide kroz zajedničku komponentu.
  - **Bubble se teleportuje u `<body>` i pozicionira fiksnim koordinatama**, umesto apsolutno unutar okidača. Razlog je konkretan: liste su u `overflow-x-auto` kontejnerima (**14 strana**), a `overflow` seče sve što izlazi iz njega — tačno tamo gde tooltip ide. Izlaskom iz kontejnera rešava se i slaganje slojeva prema top baru i sidebar-u. Gasi se na skrol, jer fiksne koordinate zastare čim se nešto pomeri.
  - **Dug tekst se prelama** (`max-w-xs`, centriran) i **vraća se u ekran** ako bi prešao ivicu; kad nema mesta iznad, okreće se ispod. **Zamka:** bubble se mora izmeriti **sa leve ivice** pre pozicioniranja — element sa `position: fixed` dobija samo prostor desno od svoje pozicije, pa merenje na krajnjoj desnoj poziciji prelomi tekst na ~105px umesto dozvoljenih 320px.
- **Posledica:** popravke su gde god je moguće u **deljenim komponentama** (`RowActions`, `OrderableList`, `ExportButton`, `MultiSelect`, `SearchSelect`, `ImageThumb`), pa jedan potez pokriva desetine poziva. Usput: `RichTextEditor` toolbar je imao hardkodiran engleski (`title="Bold"`) — prebačen u i18n. Nove ikonične kontrole prate ovo bez posebnog dogovora.

---

## ADR-0034 — Prikaz pitanja: broj iz redosleda, naslov opcion i rich-text, oznake odgovora kao podešavanje

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda. **IMPLEMENTIRAN** (suite 324 zeleno).
- **Kontekst:** Legacy sadržaj je numeraciju kucao **u tekst**: **1453 od 1699** naslova pitanja su čist broj („60."), a **1388 od 4492** MC odgovora počinje sa `a)` ili `A)`. Time isti podatak živi na dva mesta — u tekstu i u `question_test.position` / redosledu opcija — pa preuređivanje ostavlja pogrešne oznake.
- **Odluka:**
  - **Broj pitanja dolazi iz `question_test.position`**, ne iz naslova. Redosled je oduvek bio ispravan (`Test::questions()` sortira po poziciji; naslov se nigde ne koristi za sortiranje) — pogrešan je bio samo *prikazani* broj.
  - **Naslov postaje opcion i rich-text.** Opcion jer je za većinu pitanja bio samo broj, a bez toga se „60." ne bi ni mogao obrisati kroz formu (`required` + `NOT NULL`). Rich-text zbog formatiranja u samom pitanju. U admin listama i picker-u se prikazuje kao čist tekst (`toPlainText`), jer bi HTML razbio red tabele.
  - **Oznake odgovora su podešavanje po pitanju** (`answer_numbering`: `lower_alpha` / `upper_alpha` / `numeric` / null), renderovano iz **pozicije opcije**. Null je ravnopravan izbor — mnoga pitanja se čitaju kao običan spisak. Gap-filling ga ne nudi (tamo su odgovori prihvaćene reči, ne označen spisak).
  - **Uputstvo se piše na testu** (`tests.description`), koje se do sada slalo studentu ali se **nikad nije prikazivalo**. Zato **nema posebnog tipa stavke za uputstvo** — vlasnik: „imaju test description i tamo mogu da unesu uputstvo".
  - **Postojeći podaci se NE čiste** (vlasnik: „nemoj da čistiš da ne komplikujemo"). Do ručne ispravke student vidi dupli broj — automatski i onaj iz naslova.
- **Zašto se ne preskaču „blokovi sa uputstvom" pri numerisanju:** provera je pokazala da **580 od 1446** brojčanih naslova ne odgovara poziciji, jer su autori uputstvo gurali kao pseudo-pitanje (test #59: pozicije 1, 11, 21 nose „1) Put the verbs…", a 2–10, 12–20, 22–30 se savršeno poklapaju), a legacy brojevi se ponegde i preklapaju između testova istog exama (2–34 i 32–50). Zaključak vlasnika: **unos se ispravlja na njihovoj strani**, pa pozicija i broj postaju isti sami od sebe. Zato nema heuristike koja pogađa šta je uputstvo.
- **Posledica:** migracije `make_question_title_optional` + `add_answer_numbering_to_questions`; enum `App\Domain\Assessment\Enums\AnswerNumbering` (+ `marker()` sa zaštitom preko 26. opcije); `resources/js/utils/answerNumbering.ts` i `richText.ts`; studentska strana renderuje broj iz redosleda, uputstvo testa i oznake opcija. Uz to: pregled pitanja i izmena u novom tabu iz forme testa (test je nesačuvan iza modala).

---

## ADR-0035 — Theme: SVG logo se prihvata ali se prepisuje pri uploadu; 4 slobodna slota palete

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda. **IMPLEMENTIRANO** (suite 326 zeleno).
- **Kontekst:** Vlasnik je pokušao da postavi `SOA-HTC_logo.svg` i dobio 422 („must be a file of type: png, jpg, jpeg, webp"). Zabrana je bila namerna: branding fajlovi se serviraju sa `storage/` na **našem originu**, a SVG otvoren kao dokument izvršava sve što nosi (`<script>`, `onload=`) — same-origin stored XSS. Ali logotip je po prirodi vektor; rasterski logo se mutno skalira na retina ekranima i u sidebar ikonici. Uz to, dolazi javni/CMS deo, gde se koristi kućna paleta (`#fbba00`, `#f39200`, `#97bddd`, `#003758`…) koja se ne poklapa ni sa jednim od 8 semantičkih tokena.
- **Odluka:**
  - **SVG se prihvata, ali se originalni bajtovi nikad ne čuvaju.** `App\Support\SvgSanitizer` parsira upload i **prepisuje** ga po whitelisti (elementi koje logotip realno koristi), pa se na disk piše samo rezultat. Ide napolje: sve van whiteliste (`<script>`, `<foreignObject>`, `<a>`, `<animate>`, strani namespace-i tipa Illustrator `i:`), svaki `on*` atribut, `href` koji nije `#fragment` (na `<image>` još i `data:image/`), `style`/CSS sa `@import`/`expression(`/`javascript:`/`-moz-binding`, i **svaki `url()` koji ne pokazuje u sam dokument**. DOCTYPE se briše, a parsira se bez `LIBXML_NOENT` (entiteti se ne šire — nema billion-laughs) i sa `LIBXML_NONET`.
  - **`<style>` ostaje** iako je najlakše bilo izbaciti ga: Illustrator eksport boje drži baš tamo (`.st0{fill:#003758}`), pa bi brisanje obesmislilo logotip. Uklanja se samo ako sam CSS pokaže napolje.
  - **Fajl koji nije SVG dokument** (pogrešan koreni element, neispravan XML) je **422 na polju**, ne tiho odbacivanje.
  - **4 slobodna slota:** `color_palette_1..4` (default = paleta koju je vlasnik dao). Namerno **bez semantike** — to je kućna paleta koja se podešava na jednom mestu i troši kao `bg-brand-palette-1` (CSS varijable idu na `:root` istim mehanizmom kao ostali tokeni). Alternativa (imenovani tokeni: `danger`, `success`, `page`, `surface`) je odbijena jer vlasnik traži boje **za javni sajt/CMS**, a ne još kontrole nad admin skinom.
  - **Izuzetak: slot 4 nosi pozadinu admin top bara** (odluka vlasnika istog dana). Bar je time tamna traka, pa je njegov sadržaj light-on-dark: naziv aplikacije i e-mail beli, logout dugme `white/10` sa `red-300` ikonicom, donja siva linija uklonjena. **Posledica koju treba znati:** logo se sada bira svetao (vlasnik je postavio beli SVG), a **public/student/login zaglavlja su i dalje bela** → tamo je taj logo nevidljiv dok se i oni ne prebace na tamnu traku ili se ne doda tamna varijanta logotipa.
  - **Iste 4 boje su i u WYSIWYG paleti** (RichTextEditor), pored 5 postojećih semantičkih — autori sadržaja pišu u kućnim bojama.
  - **Site title je rich-text polje** (`settings.site_title`, nullable TEXT, max 1000) prikazano **pored logotipa u top baru**. WYSIWYG jer naslov nosi svoje naglaske i boje (isti obrazac kao `cert_header_title`), render `v-html` po postojećoj konvenciji za admin-authored sadržaj. Ide u **javni** `/api/theme` payload — isti naslov treba i pre logina i kasnije na javnom sajtu. Fallback na `app.name` iz kataloga poruka ostaje, ali samo kad nema **ni** logotipa **ni** naslova.
- **Posledica:** migracija `add_palette_colors_to_settings_table`; `Setting::COLOR_KEYS` sada 12 (payload `/api/theme` isto 12); `SettingsController::storeBrandingFile()` je jedina tačka upisa branding fajla; Theme strana je uvedena u karticu sa Cancel/Save podnožjem po ADR-0031, dobila je `ImageThumb` sa crvenim X (ruta `DELETE settings/theme/assets/{logo|icon}`, isto kao sertifikatski asseti), a pregled logotipa/ikonice stoji na boji top bara jer se beo logo na beloj kartici ne vidi. **Ograničenje:** sanitizer čisti **samo Theme logo/ikonicu** — sertifikatski asseti ostaju rasterski (mPDF ionako slabo vari SVG).

---

## ADR-0036 — Regioni imaju eksplicitan redosled po državi; drag & drop umesto kucanja brojeva

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda. **IMPLEMENTIRANO** (suite 340 zeleno).
- **Kontekst:** Regioni su se svuda sortirali po imenu (`orderBy('name')`), a vlasnik ih u pickerima želi u redosledu koji sam odredi. Legacy podaci to već pokazuju: imena nose ručno kucane prefikse („1- Jawzjan", „2- Balkh") — isti obrazac kao brojevi u naslovima pitanja (ADR-0034), tj. redosled je živeo u tekstu jer nije imao gde drugde.
- **Odluka:**
  - **Nova kolona `regions.position`**, jedinstvena po državi u praksi (nema DB constraint-a — reorder je uvek pun prepis liste). Migracija je **popunjava po abecedi**, pa se na deploy-u ništa vidljivo ne pomera; ime ostaje tie-breaker (`scopeOrdered` = `position`, pa `name`) za redove koje uveze migracija bez pozicije.
  - **Redosled se zadaje drag & drop-om** kroz `OrderableList` — istu komponentu koriste testovi i egzami, pa je pokret isti svuda. Komponenta je dobila `removable` prop: u modalu regiona brisanje je **guarded server delete**, a ne izbacivanje reda iz niza, pa ugrađenu kantu treba ugasiti.
  - **Upis je odložen ~400 ms.** Drag emituje na **svaki red preko kog pokazivač pređe**, pa bi jedno prevlačenje poslalo 5–6 PUT-ova; zatvaranje modala flush-uje ono što je u čekanju, da redosled ne ostane neupisan.
  - **`PUT /api/regions/reorder` prima `country_id` + `ids` u prikazanom redosledu**, i **odbija (422) id koji pripada drugoj državi** — pomešan payload ne sme da se primeni do pola. Ono što payload ne pomene zadržava poziciju, pa zastareo tab ne može da premeša ono što nije ni prikazao. Nov region ide na kraj (`max(position)+1`).
  - **Ruta ide PRE `regions/{region}`**, inače `reorder` bude uhvaćen kao id (ista zamka kao kod `coordinators/export`, ADR-0030).
- **Gde važi:** svi pickeri i filteri idu kroz `/api/regions` (Students, Users, Coordinators, Venues), plus opcije za Reports. **Izuzetak: Archive filteri ostaju abecedni** — tamo su regioni zamrznut tekst iz `archive_registrations` (bez FK), pa bi vezivanje po imenu na današnju konfiguraciju bilo krhko (preimenovani/obrisani regioni) i konceptualno pogrešno: arhiva je snimak godine, ne trenutna postavka.
- **Posledica:** imena sa legacy prefiksima sada duplo prikazuju broj (pozicija + tekst). Kao i kod ADR-0034, **podaci se ne čiste automatski** — ispravka je na strani autora.

---

## ADR-0037 — Profil ulogovanog naloga: server objavljuje koja polja uloga sme da menja

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda. **IMPLEMENTIRANO** (suite 340 zeleno).
- **Kontekst:** Do sada je nalog mogao da menja samo neko drugi, kroz Users/Coordinators ekrane. Trebalo je da svako uređuje svoje podatke, ali ne ista polja: admin sve, country coordinator uz sliku i fajl, school coordinator samo kontakt podatke.
- **Odluka:**
  - **`ProfileController::editableFields()` je jedini izvor istine.** Ista lista (a) ide u payload i forma renderuje po njoj, (b) pravi validaciona pravila u `UpdateProfileRequest`. Polje van liste **nije validirano**, pa ga Laravel i ne prosleđuje — school coordinator koji pošalje `country_id` dobija 200 i nepromenjenu državu. Alternativa (422 na neovlašćeno polje) je odbijena: klijent takvo polje nikad ne prikazuje, pa je greška znak napada, a ne korisničke zabune.
  - **Odvojen kontroler, ne `UserController`.** Tamo je autorizacija „smem li da menjam **drugog**", ovde „koja **moja** polja". Mešanje bi značilo da jedan `UpdateUserRequest` nosi dva različita modela pristupa.
  - **Role, status, permisije i sezonski scope NISU na profilu**, iako je vlasnik za admina rekao „sva polja". Razlog: to su stvari koje se **dodeljuju**, a admin koji sebi postavi `status=inactive` ili obriše svoju rolu zaključava sam sebe bez povratka. Kod već ima istu zaštitu za brisanje sopstvenog naloga (`messages.user.self_delete`).
  - **Promena lozinke traži trenutnu lozinku** (`current_password` rule). `SESSION_LIFETIME` je 30 dana, pa bi bez toga otvorena sesija na tuđoj mašini bila dovoljna za trajno preuzimanje naloga.
  - Ruta je **`requiresAuth` bez permisije** — svako ima profil; uloga odlučuje samo o poljima.
- **Posledica:** `GET/PUT /api/profile` + `DELETE /api/profile/assets/{image|file}` (brisanje odbija asset koji uloga i nema — 403); `session.refresh()` posle snimanja, jer top bar prikazuje e-mail. Top bar: e-mail + ikonica su jedno dugme ka `/profile`.

---

## ADR-0038 — Pristupni model preslikan iz legacy-ja: `user_level` 10/5/1 → permisije + row scope

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda („uvesti pristupe istim funkcijama userima kao što su imali"). **IMPLEMENTIRANO** (suite 353 zeleno).
- **Kontekst:** Legacy app nema middleware po nivou — pristup je efektivno definisan **vidljivošću menija** (`resources/views/dashboard/left.blade.php`), gate-ovima u blade-ovima i `switch ($ulogovan->user_level)` scope-om u kontrolerima. Kod nas su obe koordinatorske role imale samo `schools.view` + `seasons.view`, pa country coordinator nije mogao ni do Coordinators ekrana ni da ispravi venue, a school coordinator je video Venues modul koji u legacy-ju nikad nije imao.
- **Matrica (izvor: legacy meni + view gate-ovi):**

| Funkcija | 10 admin | 5 country | 1 venue |
| --- | :-: | :-: | :-: |
| Students (scope-ovano) · export · attendance PDF · SOA cert | ✅ | ✅ | ✅ |
| Student add/edit/delete | ✅ | po `can_student_*` | po `can_student_*` |
| Student bulk import + attendance update (fajl) | ✅ | ✅ | ❌ |
| Coordinators | ✅ svi | ✅ samo **school** coordinatori, svoja država/venue-i | ❌ |
| Venues — lista i izmena | ✅ | ✅ | ❌ |
| Venue add / delete / status | ✅ | ❌ | ❌ |
| Countries · Regions · Difficulty · Quizzes · Results · Reports · Archive · Settings | ✅ | ❌ | ❌ |

- **Odluka:**
  - **Scope mehanika se ne dira** — `allowedSchoolIds()` (sezonska dodela → venue pivot) radi tačno ono što je legacy radio preko `user_schools`, a `RegistrationPolicy` je već poštovao `can_student_insert/edit/delete`. Menjaju se **dodele permisija i gate-ovi**, ne model.
  - **`schools.edit` se odvaja od `schools.manage`.** Izmena venue-a je scope-ovano pravo (country coordinator ispravlja svoje venue-e), a **dodavanje, brisanje i status ostaju admin**. Status je **field-level**: `UpdateSchoolRequest` mu daje `prohibited` bez `schools.manage`, jer je isti PUT nosilac i imena i statusa (inline toggle u listi).
  - **`students.view` se odvaja od `schools.view`.** `schools.view` ostaje **pristup podacima o venue-ima** (picker i kolone na studentskom ekranu, treba i school coordinator-u), a **Venues stranica počinje od `schools.edit`** — tako nivo 1 zadrži podatke, a izgubi modul, kao u legacy-ju.
  - **`students.bulk`** pokriva oba fajl-toka (import + attendance update). Nije izvedeno iz `can_student_*` flag-ova jer legacy razdvaja: nivo 1 sme da unese studenta pojedinačno, ali nikad fajlom.
  - **`coordinators.manage`** odvojen od `users.manage`. `UserPolicy::manageCoordinator()` propušta admina za bilo koga, a country coordinator-a **samo za school coordinatora iste države**; lista i export se serverski sužavaju (`manageableRoleIds()` + `country_id` clamp), a `CoordinatorScope::validateActorLimits()` obara pokušaj da se kroz payload napravi ravnopravan ili tuđi nalog. **Coordinator import ostaje admin** — importer pravi country coordinatore (ADR-0030), što je iznad plafona nivoa 5.
  - **Reports i Archive ostaju admin-only** (legacy parity, potvrdio vlasnik) iako bi scope-ovani izveštaj po državi bio izvodljiv.
- **Posledica:** 4 nove permisije (`schools.edit`, `students.view`, `students.bulk`, `coordinators.manage`) → katalog ima 17; `PermissionMatrixTest` drži matricu (12 testova) da UI gate i API gate ne odu na različite strane. **Nije preneto jer ne postoji u novom app-u:** legacy `/students/invigilators` (bio je isključivo za nivo 1), portal CMS i Accounting moduli.

---

## ADR-0039 — Dashboard po nivou: sadržaj bira scope, ne rola; karta preko ISO koda

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda (predlog odobren, karta „odmah", karta gradova preskočena). **IMPLEMENTIRANO** (suite 362 zeleno).
- **Kontekst:** Dashboard je bio tri brojača i tri prečice, iste za svakoga. Vlasnik je tražio predlog po nivou pristupa (KPI, tabela, pretraga, rezultati, po mogućstvu karta). Polazna postavka predloga: **dashboard odgovara na jedno pitanje po nivou** — „šta danas traži moju pažnju" — a sve što je samo zanimljivo za gledanje ostaje u Reports.
- **Odluka:**
  - **Šta se prikazuje bira `allowedSchoolIds()`, ne ime role.** Bez ograničenja → svetska karta + tabela zemalja; **više od jednog venue-a** → tabela venue-a; **tačno jedan venue** → sam spisak studenata. Isto pravilo koje zakucava polja u pickerima (ADR-0037/0038), pa country coordinator koji drži jedan venue prirodno dobija roster umesto tabele sa jednim redom, bez ijedne provere role u komponenti.
  - **Payload nosi `null` umesto izostavljenog ključa** za ono što nivo ne dobija (`countries`, `venues_active`, `by_country`, `trend`) — UI grana na podatak, ne na permisiju.
  - **„Waiting on you" vraća samo ne-nulte stavke, i samo one na koje korisnik sme da deluje.** Prazna traka time stvarno znači da ništa ne čeka. Stavke bez odgovarajućeg filtera na ciljnom ekranu (`missing_dob`, `no_coordinator`, `no_city`) prikazuju se **bez linka** — bolje nego bacanje korisnika na nefiltriranu listu od 50.000 redova.
  - **Karta se spaja preko ISO 3166-1.** `countries.code` su olimpijski kodovi iz legacy-ja (`SRB`, `CRO`, `MON` = Crna Gora); dodate su `iso_alpha2` i `iso_numeric` (95/96 mapirano; `WRL/World` je kanta i ostaje van karte, Kosovo dobija user-assigned `XK` bez numeričkog). `world-atlas` geometrija ključuje feature po **numeričkom** kodu, pa nema nikakvog poklapanja po imenu. Geometrija se učitava lenjo (chunk 108 KB) i skidaju je samo nalozi čiji payload nosi zemlje.
  - **Trend su trake, ne linija.** Arhiva nema rundu 12 (nema je u dump-u), a linija bi povukla pravu preko rupe i prikazala je kao rast. Runda u toku je druge boje jer je tekući zbir, ne rezultat.
- **Dva odstupanja od predloga, oba zbog modela podataka:**
  - **„Prisustvo nije uneto" ne postoji kao stanje** — `registrations.attendance` je `NOT NULL DEFAULT 'present'`, pa je svaki student od unosa prisutan. Planirana kartica je postala **„Odsutni"**. Treće stanje bi tražilo nullable kolonu + prilagođavanje attendance importa (ADR-0029).
  - **„Poeni" su zbir objavljenih rezultata sezone** (`registration_results`), ne skor jednog testa: pojedinačan skor traži dimenziju runde/testa da bi značio nešto (isti argument kao za prosek u ADR-0023).
- **⚡ Perf:** naivna verzija je brojala svaki KPI zasebno (**821 ms**) i mapu jednim join-om preko svih prijava (**1.096 ms**). Sada: svi brojevi nad `registrations` idu **jednim prolazom** (uslovne sume), mapa **tri uska grupisana upita**, plus indeks `(season_id, country_id)` koji je nedostajao → **admin 410 ms, koordinatori 12–22 ms**. `registrations.country_id` je denormalizovan i uvek jednak venue-ovoj državi (provereno: 0 neslaganja), pa grupisanje po državi ide bez join-a. Na punom rosteru (300k+) odgovor je keširana sumarna tabela, ne dalje doterivanje upita.
- **Posledica:** migracije `add_iso_codes_to_countries_table` + `add_season_country_index_to_registrations`; nove zavisnosti `d3-geo`, `topojson-client`, `world-atlas`; `WorldChoropleth.vue` (🪤 boje se **ne** vezuju za `prefers-color-scheme` — admin je light-only, karta se na dark-mode mašini izvrne); Quick actions uklonjene sa dashboarda. **Nije rađeno u ovoj rundi:** globalna pretraga (isporučena u **ADR-0040**) i karta gradova (traži `lat`/`lng` + geokodiranje 1.352 grada; vlasnik preskočio). **Predlog** je objavljen kao artifact: <https://claude.ai/code/artifact/46c085cb-e24f-40a8-9d10-94d4f8fdf4e9>

---

## ADR-0040 — Globalna pretraga na dashboardu; „Waiting on you" vodi na filtrirane liste

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda. **IMPLEMENTIRANO.** Dovršava dve stavke koje su u ADR-0039 ostale nerađene.
- **Kontekst:** Studentska lista se namerno ne prikazuje dok korisnik ne filtrira (roster je 50.000 redova), pa je „nađi mi ovog takmičara" bio put od četiri koraka. Uz to, tri stavke iz „Waiting on you" nisu imale odgovarajući filter na ciljnom ekranu, pa su vodile na nefiltriranu listu ili nikuda.
- **Odluka:**
  - **Jedan endpoint `GET /api/search?q=`, grupe se biraju istim gate-om kao ekran na koji vode.** Studenti traže `students.view`, venue-i **`schools.edit`** (ne `schools.view` — Venues ekran počinje odatle, ADR-0038), zemlje samo nalog bez ograničenja scope-a, a osoblje `users.manage` → grupa `users`, inače `coordinators.manage` → grupa `coordinators` (isto sužavanje na svoju državu kao lista koordinatora). **Grupa koju korisnik ne sme da vidi se ne izvršava**, pa prazan rezultat stvarno znači „nema", a ne „nemaš pravo".
  - **Red se vraća kao polja, ne kao tekst.** Tekst, redosled grupa i odredišta su posao SPA (isto pravilo kao za „Waiting on you" u ADR-0039). Server šalje najviše **5 po grupi**, minimum termina je **2 znaka**.
  - **Cifre su takmičarski broj, ostalo je ime.** Isto pravilo koje već koristi studentska lista — prefiks nad unique indeksom umesto `LIKE %…%` preko 50k redova.
  - **Enter bez izabranog reda ne pogađa prvi rezultat**, nego otvara studentsku listu sa istim termom (poslednji red padajuće liste je isti taj „vidi sve"). Strelice biraju red, Esc zatvara; svaki upit u letu se prekida (`AbortController`), pa se prikazuje odgovor na poslednji otkucaj.
  - **Placeholder ne obećava ono što nalog ne dobija** — bez `schools.edit` piše „ime ili takmičarski broj", jer venue grupa za taj nalog i ne postoji.
  - **Nedostajući podaci dobijaju filter na ciljnom ekranu, pod istim imenom parametra `missing`:** `/students?missing=dob`, `/venues?missing=coordinator`, `/venues?missing=city`. Filteri su vidljivi u filter kartici (select, bez dugmadi, `load(1)` odmah — ADR-0031), pa korisnik vidi zašto je lista kratka i može da ga skine.
  - **Link nosi i `status=active`** za venue stavke, jer dashboard broji samo aktivne venue-e — inače bi broj na dashboardu i broj na ekranu bili različiti.
- **⚡ Perf (dev, 50.004 prijave):** po broju **3 ms** (prefiks nad unique indeksom), po imenu **81 ms**, grupa zemalja **86 ms** — uz debounce od 300 ms i najviše 5 redova po grupi to je u redu. Strop je pretraga po imenu: `LIKE %…%` je pun pregled tabele, pa na rosteru od 300k traži fulltext indeks (ili prefiks-pretragu i za ime), ne dalje doterivanje upita.
- **Posledica:** `SearchController` + `GlobalSearch.vue` + `/api/search`; `missing` filter u `RegistrationController::applyFilters()` i `SchoolController::filtered()` (anti-join na `assignment_schools` je isti onaj kojim dashboard broji); `ATTENTION_ROUTES` sada nose query. Od predloga ostaje nerađena samo **karta gradova** (vlasnik preskočio: traži `lat`/`lng` + geokodiranje 1.352 grada).

---

## ADR-0041 — Duplirane legacy zemlje se spajaju deklarativno; seeder traži zemlju po ISO kodu

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda. **IMPLEMENTIRANO** (dev baza: 96 → 95 zemalja).
- **Kontekst:** Legacy baza drži **Tajland dva puta** — `country_id` 43 „Thailand PHI" i 99 „Thailand ICE", jer su dve partnerske organizacije vodile takmičenje u istoj zemlji i svaka je dobila svoj red. To je jedna zemlja sa jednim ISO kodom (764); dashboard karta ih je već sabirala po ISO-u, ali su redovi stajali razdvojeni — dve stavke u svakom pickeru, dva reda u tabeli zemalja. Zasebno: `MasterDataSeeder` je zemlje tražio po `code` sa dvoslovnim vrednostima (`RS`), a posle migracije baza ima olimpijske (`SRB`), pa je svako ponovno pokretanje seeder-a pravilo **duplu Srbiju**.
- **Odluka:**
  - **Spajanje je deklaracija, ne jednokratni SQL.** `App\Domain\Migration\LegacyCountries::MERGES` (`[99 => 43]`) i `NAMES` (`[43 => 'Thailand']`) su jedini izvor istine. Komanda `countries:merge-legacy-duplicates` (uz `--dry-run`) sređuje **postojeću** bazu, a **importeri čitaju istu deklaraciju** preko `LegacyCountries::map()` — pa sledeći uvoz dump-a ne vraća duplikat i ne karantinira 54 venue-a kao „country not mapped". Dodavanje novog para = jedan red u konstanti.
  - **Ime preživelog reda je ime zemlje, ne partnera.** Legacy ime („Thailand PHI") imenuje organizaciju; `NAMES` ga prepisuje i pri spajanju i pri uvozu, inače bi ga `updateOrCreate` vratio.
  - **Arhiva se ne dira.** `archive_*` tabele drže zemlju kao tekst i nikad se ne spajaju na konfiguraciju (ADR-0027), pa istorijske runde zadržavaju partnersko ime pod kojim su odigrane.
  - **Seeder traži zemlju po `iso_alpha2`, a ne po `code`.** ISO kolona je ista pre i posle migracije; `code` nije. Kreirani red i dalje nosi dvoslovni `code` (testovi se oslanjaju na `RS`), ali postojeći red zadržava svoj migrirani (`SRB`).
- **Posledica:** dev baza ima jedan Tajland (107 venue-a, 1.337 prijava, 2 koordinatora — **oba koordinatora sada stoje uz istu zemlju**, a razdvajaju ih venue-i koje drže, ne država); `registrations.country_id` je i posle spajanja svuda jednak venue-ovoj državi (provereno: 0 neslaganja). 🪤 **MySQL odbija `DELETE` čiji podupit čita tabelu iz koje se briše (greška 1093)** — pivot kolizije se prvo pročitaju u PHP pa brišu po listi vrednosti; SQLite (na kome ide test suite) to propušta, pa test ovu razliku ne hvata.

---

## ADR-0042 — CMS: pages, posts i kategorije; javne adrese i server-render meta tagova

- **Status:** Prihvaćeno (2026-08-23); vlasnik proizvoda (obim potvrđen: „posts, categories i pages" + javni deo; navigacija i PageLayout ostaju za sledeću rundu). **IMPLEMENTIRANO.**
- **Kontekst:** Javni deo je bio prazna ljuštura — home stranica je prikazivala API health-check, a header meni je bio hardkodovan uz komentar „CMS stiže u Fazi 8". `PROJECT_CONTEXT` §8.6 definiše `PageLayout` / `Page` / `Category` / `Post` + navigaciju kao zaseban domen. ~~**Legacy dump nema CMS tabele** (29 tabela, sve takmičarske) — nema se šta migrirati, kreće se prazno.~~ ⚠️ **Netačno; ispravljeno u ADR-0043 (2026-08-24):** dump sadrži `pages`, `posts`, `categories`, `modules` i `theme_positions`, sa podacima. Kretanje od nule je i dalje bilo ispravno, ali uvoz je moguć.
- **Odluke:**
  - **`cms.manage` je zasebna permisija, ne deo `content.manage`.** Takmičarski sadržaj (kvizovi, testovi, pitanja) i sadržaj sajta su različiti poslovi, a CMS po §8.6 ne sme da dodirne pokušaj, tajmer ni rezultat. Katalog: 17 → **18** permisija; admin-only, matrica u `PermissionMatrixTest`.
  - **Javna čitanja imaju svoj kontroler bez autentikacije**, a sužava ih `Post::scopeLive()` / `Page::scopeLive()` (objavljeno **i** `published_at <= now()`). Razdvojeno od admin kontrolera namerno: javna strana ne sme da dođe do draft-a doteranim parametrom.
  - **Stranica živi u korenu (`/{slug}`)** jer to čitalac očekuje od sajta. Cena je rezervisana lista (`PublicPaths::RESERVED`) koja preslikava top-level segmente iz `resources/js/router/index.ts` — **novoj top-level ruti mesto je i tamo**. Ukucan slug koji se sudara se odbija (422), a izveden iz naslova se sufiksuje (`dashboard-2`).
  - **Promena slug-a objavljenog sadržaja ostavlja redirect koji pokazuje na ZAPIS, ne na novi slug** — pa lanac preimenovanja (a → b → c) i dalje razrešava iz najstarije adrese u jednom upitu. Draft ne ostavlja ništa: nikad nije ni imao javnu adresu. 301 servira `SpaController`, pre nego što se SPA podigne.
  - **Brisanje kategorije nikad ne briše objave** (§8.6): dok drži ijednu, brisanje se odbija sa 422; pod-kategorije se otkače (`parent_id = null`).
  - **`published_at`:** prazno pri objavi = sada; datum u budućnosti = zakazano; povratak u draft **zadržava** datum, da ponovna objava ne prebaci tekst na vrh liste.
  - **Locale-spreman, ali jednojezičan.** Kolone `locale` + `translation_group` na svakom zapisu umesto zasebnih translations tabela; slug je unique **po locale-u**. Dodavanje jezika je red u tabeli, ne migracija — što §8.7 i traži, bez cene duplog modela sada.
  - **Meta tagovi se renderuju na serveru.** Aplikacija je SPA, pa bi bez toga svaka javna adresa delila jedan `<title>` i ne bi imala nikakav preview kad se link okači u chat ili na mrežu — crawler ne izvršava JavaScript. `SpaController` puni `title` / `description` / canonical / OG / Twitter za stranicu ili objavu, a sve ostalo (admin, studentski deo, nepoznata putanja) dobija podrazumevane vrednosti sajta. **Renderuje se samo `<head>`** — stranicu i dalje crta Vue.
  - 🪤 **Školjka mora da se renderuje i kad baza ćuti.** `SpaController` je sada na putanji SVAKOG ne-API zahteva, pa jedan neuspeo upit znači 500 na svakoj adresi aplikacije — uključujući admin. Meta tagovi su ukras, aplikacija nije: greška pri čitanju pada na podrazumevani `<title>` i školjka se servira. Uhvaćeno testom `ExampleTest`, koji gadja `/` bez migrirane baze — on i ostaje čuvar tog ponašanja.
  - 🪤 **Multipart ne ume da prenese prazan niz.** Post forma šalje sliku, pa ide `FormData`; „nijedna kategorija" se prenosi kao jedan prazan `category_ids[]` (validacija `nullable`, kontroler filtrira). Bez toga se kategorije nikad ne bi mogle skinuti sa objave.
- **Posledica:** pet tabela (`cms_pages`, `cms_posts`, `cms_categories`, `cms_category_post`, `cms_redirects`); grupa **Website** u sidebar-u (Pages / Posts / Categories); javne rute `/news`, `/news/{slug}` i `/{slug}`; `.cms-content` stilovi za tekst iz editora (Tailwind reset briše naslove i liste, a stilovi editora su scoped); demo sadržaj se seeduje **samo u `local`** — test koji broji objavljene tekstove mora da kreće od praznog sajta. Home stranica više ne prikazuje API health-check nego najnovije objave.
- **Dopuna (2026-08-23) — Media biblioteka i editor za članke (vlasnik):**
  - **`cms_media` je biblioteka, ne još jedno polje za upload.** Fajl se okači jednom (upload prima do 20 odjednom) i koristi svuda; `MediaPickerModal` je isti prozor i u editoru i bilo gde drugde. Dimenzije se čuvaju u redu, jer bi ih listanje inače čitalo sa diska po sličici. **Ne prati se gde je fajl upotrebljen** — može biti zalepljen u bilo koje telo kao običan HTML, pa bi brojač upotrebe bio nagađanje; brisanje je odluka urednika, a upozorenje stoji u potvrdi.
  - **Bogatiji editor je opcija, ne novo podrazumevano ponašanje.** `RichTextEditor` dobija `rich` (slike, tabele, H3/H4) i `minHeight`; uključeno je **samo na Page i Post formama**. Ostatak administracije (sertifikat, opis kviza, pitanja) ostaje na istom malom skupu alata — tabela u telu pitanja nema šta da traži, a i mPDF je ne bi ispratio.
  - **Telo stranice/objave ide na `min-h-[32rem]`** — pisanje članka u polju visine tri reda je bilo glavno trenje.
  - **Stranica dobija featured sliku** (ista mehanika kao objava: upload + `ImageThumb` crveni X + zasebna `DELETE …/image` ruta). Slika se koristi na tri mesta: vrh stranice, OG tag i preview linka.
  - **Naslovna slika na karticama je pozadina, ne `<img>`** — kartice su različite širine, pa `cover` iseca umesto da ostavlja praznine.
- **Dopuna (2026-08-23) — slika kao referenca i upravljiva navigacija (vlasnik):**
  - **Featured slika više nije kopija po zapisu nego referenca na biblioteku** (`image_media_id` → `cms_media`, `nullOnDelete`). Sa bibliotekom u kući, ponovni upload iste slike za svaku objavu je čist gubitak, a `image_path` kolona ne ume da kaže da li fajl još postoji — red u `cms_media` ume. Brisanje fajla sada **nulira referencu** umesto da ostavi slomljen `<img>`, a brisanje objave **ne dira** sliku: ona je vlasništvo biblioteke. Zatečeni per-post upload-i su migracijom **usvojeni** u biblioteku, ne bačeni.
  - ⚡ **Time je otpao i multipart.** Post/Page forme opet šalju običan JSON, pa nestaje `_method` spoofing i trik sa praznim `category_ids[]` iz prethodne runde — prazan niz u JSON-u je prosto prazan niz.
  - **Meniji: podaci umesto hardkodovanog niza.** `cms_menus` + `cms_menu_items`; stavka pokazuje na stranicu/objavu/kategoriju **stranim ključem**, pa promena slug-a pomera i link. Samo tip `custom` nosi doslovnu adresu i **jedino on sme van sajta** (validacija: `/`, `#` ili `https?://` — `javascript:` pada).
  - **Naziv stavke se izvodi iz cilja**, a `label` ga pregazi **samo za tu stavku** — stranica zadržava svoj naslov.
  - **Brisanje cilja briše i stavku** (`cascadeOnDelete`): link ka stranici koja više ne postoji je gori od kraćeg menija. Javni endpoint uz to **izbacuje** stavke čiji cilj nije objavljen — meni nije mesto za reklamiranje draft-a.
  - **Stavke se čuvaju kao CELO stablo jednim `PUT`-om.** Prevlačenje jedne stavke menja redosled više redova i eventualno roditelja; jedan replace u transakciji je i jednostavniji i atomičan. Dubina je **dva nivoa** — dovoljno za podmeni, bez rezonovanja o proizvoljnoj dubini.
  - 🪤 **`ContentSlug` je pretpostavljao `locale` kolonu.** Meniji je nemaju, pa je `countries:`-stil upit rušio kreiranje menija **na MySQL-u** — a suite (SQLite) je bio zelen. Sada `locale` sme da bude `null` = globalno jedinstven slug. **Drugi put u istom danu da SQLite sakrije MySQL grešku**; uhvatio je jedino klik kroz pretraživač.
- **Dopuna (2026-08-23) — javni header i footer se crtaju iz CMS-a (vlasnik):** navigacija je preuzeta sa živog `soa-htc.org` (pet stavki u headeru; četiri iz footer bloka „Privacy centre"). Sopstvena cookie policy je **page stavka**, ne apsolutni URL kao na starom sajtu, jer ta stranica sada živi ovde.
  - **Nema hardkodovanog fallback-a.** Prazna navigacija je vidljiv problem koji admin ume da reši; skrivena kopija starih linkova bi ćutke ignorisala sve što se promeni u editoru. `PublicLayout` traži menije po handle-u (`public-header`, `public-footer`); ako ih nema, header nosi samo logo i Login.
  - **Stavka sa decom se crta kao dropdown**, pa podmeni koji editor već ume da napravi ima gde da se prikaže.
  - **Šta je link a šta ruter:** sve što napušta sajt ili traži novi tab je običan `<a>`; sve interno ide kroz ruter da se SPA ne bi ponovo učitavao.
  - 🪤 **Server-render `<head>`-a pokriva samo PRVI zahtev.** Kretanje kroz SPA ne učitava dokument, pa je tab zadržavao naslov prve strane; javni ekrani sada postavljaju `document.title` posle učitavanja (`utils/documentTitle.ts`). Crawler i deljeni link i dalje dobijaju server-renderovanu verziju.
  - **Ostalo neurađeno:** anchor linkovi iz starog sajta (`/#block_Start`, `/#block_Results`, …) pokazuju na sekcije koje nova naslovna strana nema — vode na `/` bez greške. Prebacivanje na prave rute je jedna izmena po stavci u adminu.
- **NIJE rađeno (svesno, van dogovorenog obima):** **`PageLayout` zone** i **admin navigacije** (`admin.top` / `admin.right_sidebar`) — javne (`public.header` / `public.footer`) su vezane, admin sidebar je i dalje data-driven u kodu (ADR-0038). Uz to, otvaranjem javnog dela **postaje vidljiv beli logo na belom javnom zaglavlju** (ranije odloženo pitanje, sada stvarno).
  - **Razrešeno u ADR-0043 (2026-08-24):** zone su isporučene, a beli logo je rešen tamnom varijantom u Theme settings. **Ostaju samo admin navigacije.**

---

## ADR-0043 — Layout javne strane: zone drži kod, sekcije su tipizovani blokovi, dugmad su podlista

- **Status:** Prihvaćeno (2026-08-24); vlasnik proizvoda („jedna zona sa poređanim sekcijama, hero i contact singltoni"). **IMPLEMENTIRANO** istog dana — odluka je doneta pre koda, nad nacrtanim dizajnom, pa je kod pisan po njoj.
- **Kontekst:** Poslednja neurađena stavka Faze 8 (`PageLayout` zone, ADR-0042). Dizajn javne naslovne je zaključan istog dana (pravac „Editorial"), pa su sekcije poznate: hero, upozorenje o duplim prijavama, kategorije, Practice/Results, koordinatori, kontakt, vesti. Vlasnik traži da admin menja **redosled sekcija, sliku, tekst i naslov, vidljivost pojedinačnih dugmadi i njihov stil** — konkretno: da li se u hero-u vidi competition ili sample dugme, i da register link u koordinatorima stoji dok ga admin ne ugasi.
  - ⚠️ **Ispravka nalaza iz ADR-0042:** tvrdnja „legacy dump nema CMS tabele" je **netačna**. Dump sadrži `pages` (5 redova), `posts` (6), `categories` (3), **`modules` (~20)** i **`theme_positions` (15)**. Legacy je imao layout model i on je pregledan pre ove odluke — i u dumpu i u živoj administraciji.
- **Šta legacy jeste pogodio:** `theme_positions` = 15 imenovanih pozicija koje se skoro 1:1 poklapaju sa nacrtanim sekcijama (`Page - Hero`, `Hippo Category`, `Practice & Results`, `Coordinators`, `Register & Login`, `Image devider`, `Footer text`, `Social LNKs`, `Copyright`…); `modules` = sadržaj u poziciji, sa `module_order` i `module_status`. **Ta dva polja su tačno ono što je vlasnik tražio** i zadržavaju se pojmovno. Modul „Register" u produkciji stoji na `status = 0` — mehanizam je u upotrebi, ne teorija.
- **Odluke:**
  - **Zona je registar u kodu, ne red koji admin pravi.** Legacy je dozvoljavao da se pozicija napravi ukucavanjem imena; pozicija bez odgovarajućeg slota u template-u se ne renderuje nigde i ništa to ne prijavljuje. Zone (`public.home`, `public.header`, `public.footer`, `public.top`, kasnije `admin.top`, `admin.right_sidebar`) žive u kodu kao katalog permisija — admin ih ne dodaje, ne preimenuje, ne briše. Time nema siročića, a §8.6 („admin ne unosi proizvoljan Vue/PHP kod") ostaje ispoštovan.
  - **Naslovna je JEDNA zona sa poređanim sekcijama** (`public.home`), ne niz fiksnih imenovanih slotova. „Pomeri više na strani" je onda prevlačenje, a nova sekcija je nov red — ne izmena template-a. Cena je što se strana može poređati besmisleno; nju plaća sledeća odluka.
  - **`hero` i `contact` su singltoni.** Tri hero sekcije nisu izbor nego greška: tip deklariše najveći broj instanci u zoni, a admin UI ne nudi „Dodaj" kad je popunjen.
  - **Blok je tipizovan, ne vreća polja.** Jedan red nosi `zone`, `type`, `position`, `status` i `data` (JSON validiran **po tipu**) + FK na `cms_media` za slike. Legacy je imao jednu tabelu i **jednu formu za sve**: hero je dobijao Category/Post/Page selecte koji mu ne trebaju, social link je dobijao TinyMCE, a svaka nova potreba bila je nova kolona (`back_color`, `back_image`, `file_upload`, `module_image`). Tipovi prate nacrtane sekcije: `hero`, `notice`, `category`, `split_cta`, `coordinators`, `contact`, `news`, `image_band`.
  - **JSON umesto širokih kolona**, jer se blokovi uvek čitaju po zoni i nikad se ne pretražuje unutar payload-a — pa dodavanje polja jednom tipu ne košta migraciju. Validacija mora biti stroga i po tipu; bez nje je JSON rupa kroz koju u bazu ulazi šta god.
  - **Dugmad su tipizovana podlista UNUTAR bloka**, ne zasebni blokovi: `{ label, target, style, status }`. Legacy je imao **jedan URL po modulu**, pa su „Register" i „Login" morali biti dva odvojena modula u poziciji `Register & Login` — dugmad su lebdela odvojeno od sekcije kojoj pripadaju, a hero sa dva dugmeta se nije mogao ni izraziti.
  - **`style` je enum vezan za paletu** (`primary` / `navy` / `amber` / `outline` / `link`), **ne color picker.** Legacy je nudio slobodan `back_color`. Slobodne boje su način na koji dizajniran sajt za pola godine postane šaren; paleta se menja na jednom mestu, u Theme settings.
  - **Cilj linka koristi isto pravilo kao stavka menija** (ADR-0042): strani ključ na stranicu/objavu/kategoriju ili imenovanu rutu, a `custom` nosi doslovnu adresu i jedini sme van sajta, uz validaciju protokola. **Dodaje se tip `file`** — cilj je fajl iz media biblioteke, jer se „Choose Hippo categories" preuzima kao dokument, a ne vodi na stranicu.
  - 🪤 **Vidljivost dugmeta ima DVA nezavisna uslova i oba moraju da važe.** Admin prekidač kaže *sme li se ovo dugme ikada videti*; sezonsko stanje kaže *vidi li se sada*. Van sezone se takmičarski ulaz gasi bez obzira na prekidač, a prekidač ga ne pali nazad. Izvor sezonskog stanja je **da li je neki competition quiz aktivan** (vlasnik, 2026-08-24) — ista provera koja hrani statusnu traku u zaglavlju. Implementacija koja ispoštuje samo jedan uslov je bug.
  - **Redosled se snima kao CELA lista jednim `PUT`-om**, u transakciji — isti obrazac koji već koristi editor menija, iz istog razloga: prevlačenje jedne sekcije menja `position` više redova.
  - ~~**Chrome (`top` / `header` / `footer`) NE dobija builder.**~~ Oblik im se ne menja, menjaju se vrednosti: navigacija je već rešena kroz `cms_menus`, logo i paleta su u Theme settings, statusna traka je dva-tri podešavanja. Builder nad nečim što ima jedan mogući oblik je trošak bez dobitka.
    - ⚠️ **NADJAČANO za `header` i `footer` → ADR-0045 (2026-08-25).** Zaključak „nema builder" je bio tačan, ali je u praksi značio „nema ni polja": koji meni zaglavlje crta bio je hardkodovan, podnožje je u prvoj koloni prikazivalo taj isti meni, a tagline i naslovi kolona bili su u `en.ts`. Sad su to zone sa **jednim zapisom** i formom, i dalje bez liste sekcija. **`public.top` ostaje van** — njegove vrednosti se izvode iz podataka.
- **Admin (`Website → Layout`):** izbor zone, pa vertikalna lista kartica — bedž tipa, naslov, `ToggleSwitch`, ručka za prevlačenje, izmena. **Kompozicija strane se čita odozgo nadole.** Legacy to nije imao: lista je bila ravna i paginirana kroz sve pozicije (hero, cookie consent, footer logo i Facebook u istom nizu, svi sa „Order 1", jer je redosled bio u okviru pozicije), a dugme „Overview" vodilo je na tu istu listu. Izmena bloka je panel sa **samo poljima tog tipa**.
- **Posledica:** tabela `cms_layout_blocks`; registar zona i tipova u PHP-u (tip deklariše polja, dozvoljene zone i najveći broj instanci); stavka **Layout** u sidebar grupi Website pod `cms.manage`; javni `GET /api/public/layout/{zone}` koji izbacuje ugašene blokove i ugašena dugmad. **Uvoz legacy `modules` je moguć i jeftin** (~20 redova: title, body, slika, order, status → blok), ako vlasnik to zatraži.
- **Odgovara na otvoreno pitanje `PROJECT_CONTEXT` §14/20** („unapred programirani template-i sa zonama ili sklapanje od odobrenih blokova?"): **oboje** — template definiše zone i dozvoljene tipove, admin bira redosled, vidljivost i sadržaj unutar njih.
- **Isporučeno (2026-08-24):** tabela `cms_layout_blocks`; `LayoutZones` (zone) i `BlockSchema` (tipovi, polja, validacija, `max` instanci) kao registri u kodu; `LayoutBlock`; `LayoutButtons` (razrešavanje dugmadi) i `EntryWindow` (sezonska kapija); admin `GET /api/cms/layout/zones|{zone}`, `POST …/blocks`, `PUT layout-blocks/{id}`, `DELETE`, `PUT …/order`; javno `GET /api/public/layout/{zone}`; ekran **Website → Layout** (`LayoutPage.vue` + `LayoutBlockEditor.vue` + `LayoutButtonFields.vue`); javna strana crta blokove (`HomePage.vue`, `components/public/*`); seed nacrtane naslovne u `MasterDataSeeder` (samo `local`). Testovi: `CmsLayoutTest` (13), od čega tri čuvaju pravilo o dva uslova.
- **Razlike u odnosu na odluku, i zašto:**
  - 🪤 **Payload u API-ju se zove `content`, kolona ostaje `data`.** `JsonResource` čiji niz već ima ključ `data` Laravel smatra **već omotanim** (`haveDefaultWrapperAndDataIsUnwrapped`), pa bi taj jedini endpoint vraćao odgovor bez `{"data": …}` i SPA bi na njemu pukao. Uhvaćeno testom, ne u pregledu.
  - **`news` je takođe singlton**, uz `hero` i `contact` iz odluke. Dve trake najnovijih vesti na istoj strani nisu izbor nego greška, isto kao dva hero-a.
  - **`EntryWindow` NIJE memoizovan.** Prva verzija je keširala statički; statički keš preživljava zahtev u test procesu i u dugotrajnom workeru, pa bi odgovarao za sezonu koja se u međuvremenu promenila. Upit je indeksirani `EXISTS` nad malom tabelom i poziva se par puta po strani.
  - **Tip bloka je nepromenljiv posle kreiranja.** Promena tipa bi ostavila payload koji novi tip ne ume da pročita, a prevoda između hero-a i trake vesti nema; brisanje i dodavanje je i jasnije i pošteno prema sadržaju.
  - **Dodat `GET /api/public/site`** (runda, školska godina, `competition_open`, `sample_open`) — statusna traka u zaglavlju stoji na svakoj javnoj strani, ne samo na naslovnoj, pa je ne može hraniti odgovor jedne zone.
- **Dopuna (2026-08-24) — paragrafi su rich text (vlasnik):** „sve gde imaš tekst da se unese kao paragraf, treba da stoji opcija sa WYSIWYG editorom jer će unositi tekst koji je bold italic". Svako paragrafsko polje je zato `kind: rich` i u adminu je `RichTextEditor` (mali set alata — bogata varijanta ostaje samo na Page i Post, ADR-0042), a javna strana ga renderuje kao markup.
  - **`.rich-text` ne postavlja ni boju ni veličinu.** Sekcija oko paragrafa je već odlučila o tipografiji; da paragraf resetuje sebe na članak-sivo, dizajn bi se raspao. Zato je to zasebna klasa, a ne `.cms-content`.
  - **`strong` je pinovan na 600**, jer font pipeline isporučuje 400/500/600 — inače bi browser sintetisao bold koji ne postoji u familiji.
  - Limiti su podignuti (400 → 1600, 300 → 1200): ograničenje koje je brojalo reči sada broji i tagove.
- **Dopuna (2026-08-24) — tamna varijanta logotipa (vlasnik):** dizajn B ima svetlo zaglavlje, a brend znak je beo; nijedan CSS filter ne prebojava uploadovanu sliku pošteno. Theme settings zato dobija `logo_dark` (kolona `settings.logo_dark_path`), belo ostaje za navy površine (statusna traka, podnožje). **Dok tamne varijante nema, zaglavlje ispisuje ime sajta rečima** umesto da renderuje nevidljiv znak.

---

## ADR-0044 — Sezona se ne uređuje, sezona se pokreće: jedna forma koja arhivira, briše i otvara narednu rundu

- **Status:** Prihvaćeno (2026-08-25); vlasnik proizvoda („potrebno je u setting dodati admin za sezonu", uz sliku legacy ekrana; zatim: „lista nije potrebna, ne služi ničemu… od momenta potvrde pokreće se nova sezona"). **IMPLEMENTIRANO** istog dana.
- **Kontekst:** Rundu i školsku godinu **nije mogao da promeni niko kroz aplikaciju.** Nije postojala ruta, kontroler ni ekran; jedini red u `seasons` napravio je `MasterDataSeeder` (`round_number = 14`, `Season 2026`), a izmena je značila SQL ili tinker. Statusna traka javne strane, prefiks svakog competitor number-a i ceo scope permisija visili su o tom redu. Otkriveno pri pitanju „gde se u adminu definiše round, godina, start" — odgovor je bio: nigde.
- **Šta je legacy imao (`el_settings`, kartica *Admin Settings*):** tabela bez primarnog ključa, MyISAM, `latin1`, **jedan red**: `round_number = 14`, `current_school_year = 2026`. Uz njih na kartici: checkbox **Reset Student Counter** i prekidač **Maintenance mode**. Polja su se prosto pregazila, u mestu.
- **Odluke:**
  - **Forma pokreće narednu sezonu, ne uređuje tekuću.** Legacy je imao jedan red pa je „nova runda" bila prekucavanje broja; kod nas je sezona **red**, i sve što je vezano za nju (prijave, rezultati, dodele uloga, arhiva) nosi njen `season_id`. Izmena `round_number` u mestu bi ostavila već izdate brojeve pod starim prefiksom, a nove pod novim — jedna sezona sa dva prefiksa, bez ijedne poruke o tome. Zato snimanje forme **otvara novu sezonu**: arhivira tekuću, obriše je i proglasi novu aktivnom, sve u jednoj transakciji.
  - **Nema liste sezona** (vlasnik izričito). Ekran pokazuje samo ono što odluku informiše: koja runda trenutno radi i **šta će pokretanje nove poneti sa sobom** (koliko prijava ide u arhivu, koliko redova se briše, koliko naloga i mesta se gasi). Arhivirane sezone se čitaju kroz Archive modul, gde im je i mesto.
  - 🪤 **„Reset Student Counter" nema ekvivalent i to nije propust.** Brojač takmičara kod nas nije uskladištena vrednost nego **izvedena**: `max(sequence)` u okviru `season_id` (`RegistrationController::createWithNumber`, `RegistrationImporter`). Nova sezona = nov `season_id` = brojač kreće od nule sam od sebe. Legacy je morao da ga resetuje ručno jer je bio globalan; ovde nema šta da se resetuje, pa ni šta da se pogreši. **Ne dodavati dugme koje „resetuje brojač"** — ono bi u našem modelu značilo ponovno izdavanje brojeva koji već postoje.
  - **`round_number` je jedinstven i nepromenljiv.** On je prefiks svakog competitor number-a izdatog u sezoni i pod njim rezultati stoje u arhivi (`archive_registrations.round_number`). Ponovna upotreba se odbija na validaciji i na `unique` indeksu. **Širina broja se namerno ne ograničava** — `docs/00` beleži pravilo kao *šest cifara sekvence, promenljiva ukupna širina*, a legacy runde 9–13 su izdavale različite dužine (`901097`, `1000816`, `11000346`).
  - **Arhiviranje i brisanje su ista logika koju vrti `season:reset`, izvučena u `SeasonRollover`.** Komanda radi arhivu + brisanje i staje; forma nastavlja i otvara narednu rundu. Dve kopije skupa `DELETE`-ova nad živim podacima su loše mesto za drift.
  - 🪤 **Dodele uloga se PRENOSE na novu sezonu, sa rolom i school scope-om.** Vlasnik (2026-08-25): *„nismo pominjali brisanje permisije. one moraju uvek da ostanu iste… reset ne sme da dira permisije jer su to bazične informacije."* **Tačno je i tako i jeste** — ni reset ni prelazak ne diraju katalog permisija, role, veze rola↔permisija, niti brišu ijednu dodelu. Ono što je vezano za sezonu je **na koju sezonu se dodela odnosi**: `season_user_assignments.season_id`, a `User::permissionsForActiveSeason` čita isključivo dodele **aktivne** sezone. Izmereno na čistoj bazi: posle otvaranja nove sezone bez prenosa, katalog ostaje 18 permisija / 4 role / 27 veza / 1 dodela — **ništa obrisano** — a `hasPermission('settings.manage')` postaje `false` i svaki ekran vraća 403, jer ta netaknuta dodela i dalje pokazuje na staru rundu. Prenos je zato ono što čini da „nema nikakve promene": posle prelaska svako ima ista prava kao pre.
    - **PREMEŠTA se, ne kopira** (vlasnik, 2026-08-25: *„prilikom pokretanja nove sezone osveži kompletnu tabelu `season_user_assignments` sa novim ID-jem"*). Prva implementacija je kopirala redove; premeštanje je jednostavnije i tačnije: **`assignment_schools` ide besplatno** jer pokazuje na `season_user_assignment_id` koji se ne menja (2230 redova na dev bazi koje bi kopija morala da duplira i preslika), a **istorija koju bi kopija čuvala ne postoji i ne bi mogla da bude potpuna** — runde 9–13 nose **nula** dodela (legacy nije imao sezonski model), a prelazak briše školske koordinatore, pa bi zaostali zapis bio evidencija kojoj fali pola ljudi. Jedan `UPDATE` umesto 135 + 2230 `INSERT`-a.
    - **Vezano za id sezone koja se zatvara, ne za celu tabelu** — danas je isto (svih 135 redova je r14), ali ne zavisi od toga da u tabeli nikad ne bude druge sezone (npr. draft sa unapred pripremljenim dodelama). Sudara sa `unique (season_id, user_id, role_id)` nema: sezona u koju se ulazi je upravo napravljena i prazna je.
    - Premeštaju se i dodele deaktiviranih naloga: neaktivan nalog ionako ne može da se prijavi, a reaktivacija treba da vrati korisnika sa ulogom, ne bez nje. Bez dodele ostaju samo obrisani školski koordinatori, čiji su redovi kaskadno otišli sa nalogom.
  - **Audit red se piše POSLE brisanja.** `audit_logs` je u listi tabela koje se prazne (nova sezona kreće od čistog traga), pa bi upis pre brisanja bio obrisan istom transakcijom. `season.started` je zato prvi red novog traga.
  - **Potvrda je polje zahteva, ne samo dijalog u pretraživaču.** `confirm: accepted` — server odbija poziv bez nje. Dijalog se može zaobići, HTTP poziv ne.
- **Admin (`Settings → Season`):** leva kolona su vrednosti nove sezone (runda, školska godina, naziv, opcioni datumi), desna traka je posledica — brojevi iz `SeasonRollover::plan()` i rečenica šta ostaje netaknuto. Potvrdno polje stoji tamo gde je legacy imao „Reset Student Counter", ali čuva nešto stvarno.
- **Posledica:** `SeasonRollover` (arhiva + brisanje + prelazak); `GET/POST /api/settings/season` pod `settings.manage`; `SeasonSettingsPage.vue` i stavka **Season** u sidebar grupi Settings; `ResetSeasonData` sveden na prikaz i poziv servisa. Testovi: `SeasonApiTest` (7), od čega jedan čuva pravilo o prenosu dodela.
- **Nije rađeno, svesno:** **Maintenance mode** sa legacy kartice. Nije sezona, i nije isto što i `php artisan down` (koji gasi i administraciju). Ako zatreba, to je zasebna odluka: šta tačno gasi (javnu stranu, studentski ulaz, oboje), ko i dalje ulazi, i šta posetilac vidi.


---

## ADR-0045 — Zaglavlje i podnožje su zone sa jednim zapisom; jedan ekran sa tabovima Home · Header · Footer

- **Status:** Prihvaćeno (2026-08-25); vlasnik proizvoda. **IMPLEMENTIRANO** istog dana. **Ispravlja jednu odluku iz ADR-0043.**
- **Kontekst:** Vlasnik je tražio: u zaglavlju **izbor menija**; u podnožju **WYSIWYG za tekst** i **naslov + izbor menija**, i to više puta („možda ih bude više"). Predložio je nov link u sekciji Website ka ekranu sa tabovima **Home / Header / Footer**.
- **Šta je ADR-0043 pogrešio:** stajalo je da „chrome (`top`/`header`/`footer`) NE dobija builder" jer mu se „oblik ne menja, menjaju se vrednosti: navigacija je već rešena kroz `cms_menus`, logo i paleta su u Theme settings". **To je bilo tačno za logo i za linkove, a netačno za sve ostalo.** Izmereno pre ove odluke: koji meni zaglavlje crta bilo je **hardkodovano** u `PublicLayout.vue` (`public-header`), podnožje je u prvoj koloni prisilno prikazivalo **isti taj meni zaglavlja**, a tagline i oba naslova kolona bili su jezički stringovi u `en.ts`. Ništa od toga admin nije mogao da promeni — samo commit. Odluka je zamenila „nema builder" sa „nema ni polja", što nije isto.
- **Odluke:**
  - **Nov link se NE dodaje.** Ekran `Website → Layout` je već imao biralo zona, skriveno jer je zona bila jedna (`v-if="zones.length > 1"`). Registrovanjem `public.header` i `public.footer` biralo se samo pojavljuje; pretvoreno je u **tabove**. Druga stavka u meniju koja vodi na isti ekran bila bi duplikat.
  - **Dve vrste zona, i razlika je namerna.** Naslovna je **LISTA** — sekcije se dodaju, prevlače i gase, i redosled JESTE strana. Zaglavlje i podnožje su **PODEŠAVANJA** — oblik im drži dizajn, menja se koji meni prikazuju i šta pišu. Zato svaka drži **tačno jedan blok**, a editor prikazuje **formu umesto liste** kojoj nema šta da se doda. `LayoutZones::isChrome()` je ta razlika, i klijent grana po njoj a ne po spisku imena zona.
  - **Ne pravi se nova tabela ni nov endpoint.** Blok je već tipizovan zapis sa validacijom po tipu, `content` payload-om i javnim `GET /api/public/layout/{zone}`. Dva nova tipa (`header`, `footer`) i registar zona dobijaju sve to besplatno. Alternativa — kolone u `settings` + zaseban endpoint + zasebna validacija — bila bi treći način da se čuva isto.
  - **Meni se čuva kao REFERENCA (`menu` → `cms_menus.id`), ne kao kopija linkova** — isto pravilo kao stavka menija u ADR-0042. Preimenovanje ili prepravka menija pomera navigaciju sa njim; obrisan meni ostavlja referencu koja se razrešava u ništa, a ne zastarelu kopiju. Uveden je **nov tip polja `menu`**; opcije stižu uz registar (`data.menus`), pa forma ne traži drugi zahtev.
  - **Kolone podnožja su LISTA (`list`), ne dve fiksne pregrade** — vlasnik: „možda ih bude više". Najviše četiri.
  - 🪤 **Pravilo šta meni sme da prikaže izvučeno je u `PublicMenus`.** Ono je dosad bilo `private` u `PublicContentController`; sad ga koriste i endpoint po slug-u i razrešavanje zona. Druga kopija bila bi očigledan način da se to dvoje ne slože oko toga da li draft stranica sme u navigaciju.
  - **Dugme Login se NE nudi u adminu** (vlasnik: „button u headeru nema smisla jer on zavisi od toga da li je user ulogovan ili ne"). Ono nije vrednost koju admin bira nego posledica stanja sesije; tab Header zato ima tačno jedno polje.
  - **Statusna traka (`public.top`) i dalje nije zona,** iz originalnog razloga: svaka vrednost u njoj izvodi se iz podataka (koja runda, da li je competition kviz aktivan). Nema šta da se podesi.
  - **Nema hardkodovanog fallback-a** (nepromenjeno iz ADR-0042): zona bez zapisa ostavlja prazan chrome. Prazna navigacija je vidljiv problem koji admin ume da reši; skrivena kopija starih linkova ćutke bi ignorisala izmene.
- **Posledica:** `BlockType::Header`/`Footer` (+ `isChrome()`); `LayoutZones::PUBLIC_HEADER`/`PUBLIC_FOOTER` + `isChrome()`; polje `menu` u `BlockSchema` (validacija `exists:cms_menus,id`); `PublicMenus` (razrešavanje menija + `resolvePayload`); `is_chrome` i `menus` u registru; `inline` prop na `LayoutBlockEditor` (forma u strani umesto modala); tabovi u `LayoutPage`; `PublicLayout` čita obe zone umesto ručki i `en.ts`; `MasterDataSeeder::seedChromeLayout()` (local, kao i ostali demo sadržaj) upisuje tačno ono što je kod dosad radio, pa lokalni sajt izgleda isto. Obrisani `public.footer.tagline|services|privacy` iz `en.ts` — to je sadržaj, ne prevod. Testovi: `CmsLayoutTest` 19 (13 + 6 novih).
- **Zatečeni zapisi:** admin koji otvori tab zone bez bloka dobija blok napravljen tom prilikom (jedan `POST`, na klik na tab — ne na učitavanje strane). Time postojeće instalacije ne traže migraciju, a tab je forma od prve posete.
- **Usput provereno, nije bug:** vlasnikova sumnja da je „Privacy centre vidljiv samo ako sam ulogovan". `curl` bez ijednog kolačića vraća sve četiri stavke, a podnožje je snimljeno **odjavljen** na `/login` — obe kolone se vide. Nigde u zaglavlju ni podnožju nema uslova po prijavi osim samog dugmeta Login/Back to dashboard.

---

## ADR-0046 — Svaki ekran sa naslovom i tekstom ima administraciju; javne forme su jedan raspored u kontejneru sajta

- **Status:** Prihvaćeno (2026-08-25); vlasnik proizvoda. **IMPLEMENTIRANO** za `/login`; isti obrazac važi za ekrane koji slede (identifikacija, registracija).
- **Kontekst:** Krenulo se od redizajna prijave u jezik javnog dela (pravac „B Editorial", ADR-0043). Usred rada vlasnik je postavio pravilo koje menja obim svih narednih ekrana: *„svaka stranica koja ima naslov, tekst mora da ima i administraciju."*
- **Odluke:**
  - **Naslovi i pasusi su SADRŽAJ, labele polja i dugmad su INTERFEJS.** Granica je namerna i uska: `E-mail`, `Password`, `Sign in` ostaju u `en.ts`, jer admin koji preimenuje „E-mail" nije uredio stranu nego pokvario formu. Sve iznad forme — nadnaslov, naslov, pasus — ide u sadržaj.
  - **Ekran ulazi kao ZONA, ne kao treći mehanizam.** `public.login` je zona sa jednim zapisom tipa `login` (nadnaslov, naslov, rich pasus), uređena u `Website → Layout` kao četvrti tab. Time ostaje jedno mesto gde se tekst čuva, jedno gde se validira i jedan javni endpoint; alternativa (kolone u `settings` ili nova tabela) bila bi treći način da se čuva ista stvar.
  - **`isChrome()` → `isSingle()`.** Ime je nastalo dan ranije (ADR-0045) kad su jedine takve zone bile zaglavlje i podnožje. Sada se isto ponaša i tekst ekrana koji nije chrome, pa bi staro ime lagalo. Ključ u registru je `is_single`.
  - **Bez skrivenog fallback-a** (nepromenjeno iz ADR-0042/0045): prazna zona ostavlja stranu bez naslova. Forma je i dalje upotrebljiva; seeder puni zonu tačno onim tekstom koji je kod dotad imao, pa se lokalni sajt ne menja.
- **Raspored javne forme — tri pokušaja, dva odbačena isti dan:**
  1. **Panel u boji do desne ivice** (po nacrtu). Odbačen: *„i kada razmislim, slike desno treba izbaciti."*
  2. **Jedna kolona na full-bleed strani.** Odbačen: *„to je višak"* — pola ekrana prazno.
  3. **Usvojeno:** *„title, text levo, forma desno, i sve kao content sajta centrirano."* Izdvojeno u `PublicFormPage` sa dva **imenovana slota**, da ekran ne može slučajno da stavi formu levo a naslov desno.
  - ⚡ **Time je otpala sva računica poravnanja.** Dok je strana bila full-bleed, sadržajnu liniju je trebalo izračunati (`calc(85.7142% − 596px)` preko mreže od 12 kolona, pa `50% − 596px` bez nje) — procentima a ne `vw`, jer `100vw` broji skrol traku pa naslov promaši zaglavlje za sedam piksela na svakoj strani koja se skroluje. Čim strana ide u standardni kontejner, poravnanje dolazi **po konstrukciji** i nema šta da se održava.
- 🪤 **Ostrvo podnožja je imalo SVOJ razmak od ivica** (`px-4 sm:px-8`), umesto kontejnera sajta. Posledica je bila da plava ivica i sadržaj beže jedno od drugog različito u tri opsega: ispod 640 ivica je 8px izvan sadržaja, između 640 i 1240 sadržaj je 8px izvan ivice, iznad 1304 razlika je 24px. Ostrvo je premešteno u isti `max-w-[1240px] px-6` kontejner, pa sada zaglavlje, sadržaj i plava ivica imaju **identično izračunat stil** i ne mogu se raziči ni na jednoj širini. Izmereno posle izmene: 24 → 1164 za sve troje.
- 🪤 **`@` u i18n stringu obara ceo ekran.** vue-i18n čita `@` kao početak povezane poruke; `you@school.org` kao placeholder ne kompajlira, poruka pukne i strana ostane prazna — zaglavlje i podnožje se iscrtaju, sadržaja nema, a greška u konzoli je `SyntaxError: 10` bez pomena stringa. Literal je `"you{'@'}school.org"`. Vredi proveriti pri svakom novom stringu sa adresom.
- **Posledica:** `BlockType::Login` + `isSingle()`; `LayoutZones::PUBLIC_LOGIN`; polja `eyebrow/title/lead` u `BlockSchema`; `is_single` u registru; `PublicFormPage`; `LoginPage` čita `public.login`; `MasterDataSeeder::seedChromeLayout()` seeduje i taj zapis; ostrvo podnožja u kontejneru sajta (`PublicLayout`, važi za sve javne strane). Testovi: `CmsLayoutTest` 20.
- **Ostaje za naredne ekrane:** identifikacija takmičara, spisak testova i test u toku — svaki sa svojom zonom čim nosi naslov i pasus. Registracija koordinatora ide u zasebnu rundu, jer nije redizajn nego nova funkcija (ADR se piše tada).

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
| OD-6 | Nivo objave rezultata (ceo exam/quiz vs. pojedinačni test). | Faza 5 | **Razrešeno → ADR-0020 (batch exam ILI test, reverzibilno)** |
| OD-7 | Fill-the-gap normalizacija (case, razmaci, interpunkcija, dijakritici, varijante) i parcijalni bodovi. | Faza 4/5 | **Razrešeno → ADR-0019 (case+trim+razmaci, sve-ili-ništa, MC single)** |
| OD-8 | Kako se duplirani `competitor_number` (4 u soa2024) tretiraju u migraciji. | Migracija | **Razrešeno (2026-08-20):** zadrži OBA studenta; po koliziji red sa **nižim `entry_id`** (kreiran prvi, nosi rezultate) zadržava originalni broj, red sa **višim `entry_id`** (pogrešna 2. dodela legacy generatora) dobija **prvi slobodan broj = max izdati +1..** (soa2024 max `14110915` → `14110916`–`14110919`). Rezultati se vezuju preko `entry_id` (ne broja) → reasignacija bez gubitka. Implementira migraciona komanda (Faza 6 W1). |
| OD-9 | Mapiranje legacy `active` 0/1 na `pending/graded/published/void`. | Faza 5 / migracija | **Razrešeno (2026-08-20):** NEMA lifecycle mapiranja — `el_student.active` uniformno `1` (svih 107.656), a ADR-0027 arhiva nema `pending/graded/void`, samo objavljen rezultat. Svi migrirani rezultati = objavljeni (`published_at` na migraciji, `source=import`); granularno iz `quiz_results`: `active=1`→arhiva, `active=0`→skip. **Obim: migriraju se SVI studenti (ceo roster), ne samo oni sa rezultatima** → analiza „prijavljeni vs polagali" po zemlji. **Posledica:** arhiva mora snimiti i ROSTER (sve registracije), ne samo Layer B rezultate → potrebna `archive_registrations` (puni je `season:reset` + istorijska migracija). |
| OD-10 | Povezivanje mobilnog naloga sa sezonskom prijavom. | Mobilni tok | Otvoreno |

## Odluke koje tek treba formalizovati (iz `PROJECT_CONTEXT.md` §15)

Student access/session model · mobilni StudentAccount · Terms/Privacy
verzionisanje · Theme Settings model · CMS model · lokalizacijski ugovor ·
Public/Admin navigacija · Android/iOS tehnologija · attempt/timeout state
machine · grading/publication workflow · migracija/cutover · sezonski model ·
istorijska analitika · performance SLO i scaling plan · privatnost/retention/audit ·
deployment/storage/backup.
