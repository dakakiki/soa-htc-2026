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

- **Status:** Prihvaćeno (2026-08-13)
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

---

## Otvorene odluke (blokiraju odgovarajuće module — ne pretpostavljati)

Voditi ovde; premestiti u ADR čim vlasnik proizvoda potvrdi. Izvor: `00` §7,
`PROJECT_CONTEXT.md` §14, i „Odluka" sekcije u `00`.

| # | Pitanje | Blokira | Status |
| --- | --- | --- | --- |
| OD-1 | Retake na rezultatu 0 — zadržati legacy ponašanje (neograničeno ponavljanje uz brisanje) ili ukinuti? | Faza 4 | Otvoreno |
| OD-2 | Na šta se odnosi broj „10.000" (prijave / druga sezona / očekivani rast)? | Faza 6 / `05` | Otvoreno |
| OD-3 | Zadržati mapiranje nivoa na quiz i pojedinačno pitanje, ili svesti na exam/test? | Faza 2/4 | Otvoreno |
| OD-4 | Redosled testova i uslov otključavanja sledećeg. | Faza 4 | Otvoreno |
| OD-5 | Ponašanje pri gubitku browsera/mreže bez autosave-a. | Faza 4 | Otvoreno |
| OD-6 | Nivo objave rezultata (ceo exam/quiz vs. pojedinačni test). | Faza 5 | Otvoreno |
| OD-7 | Fill-the-gap normalizacija (case, razmaci, interpunkcija, dijakritici, varijante) i parcijalni bodovi. | Faza 4/5 | Otvoreno |
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
