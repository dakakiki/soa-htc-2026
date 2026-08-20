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
