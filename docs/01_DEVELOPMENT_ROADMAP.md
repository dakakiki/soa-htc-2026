# SOA HTC — Fazni plan razvoja

Status: radni nacrt 0.1  
Datum: 2026-08-11  
Izvor: `PROJECT_CONTEXT.md`

## 1. Cilj plana

Plan vodi novi, API-first Laravel + Vue sistem od potvrđivanja legacy ponašanja do produkcionog prelaska. Prioritet isporuke je:

1. Competition Core.
2. Sertifikati i import/export.
3. CMS i navigacija.
4. Accounting, funkcionalno identičan legacy sistemu.

Mobilni Android/iOS klijent koristi isti API i ista pravila kao web. Njegov puni razvoj može ići posle stabilizacije Competition Core API-ja, ali API ugovor i mobilna autentikacija moraju biti predviđeni od početka.

## 2. Principi isporuke

- Nova aplikacija je rewrite, ne višestruki upgrade legacy projekta.
- Potvrđena poslovna pravila imaju prednost nad tehničkim pretpostavkama.
- Svaka faza mora imati testabilan izlaz i jasan uslov završetka.
- Migracije su ponovljive i proverljive; ručno prepravljanje produkcionih podataka nije proces migracije.
- Competition Core se odvaja od CMS-a, računovodstva i teške analitike.
- Performanse, autorizacija, audit i observability nisu završna faza; ulaze u svaki vertikalni slice.
- Engleski je jedini početni locale, ali sav UI i sistemske poruke koriste language fajlove.

## 3. Predložene faze

### Faza 0 — Discovery i zaključavanje osnova

Glavne aktivnosti:

- popis ekrana, ruta, tabela, komandi, import/export formata i accounting tokova u legacy sistemu;
- profilisanje aktuelne i istorijskih baza;
- data dictionary za ključne legacy tabele i novu ciljnu šemu;
- potvrda otvorenih pravila koja blokiraju Competition Core;
- ADR odluke: baza, hosting topologija, queue/cache, object storage i mobilna tehnologija;
- početni performance SLO i realni load-test scenario.

Izlazi:

- potvrđen scope prve verzije;
- `DECISIONS.md` sa početnim ADR odlukama;
- inventar legacy modula i podataka;
- matrica uloga i dozvola;
- početni API i data model baseline.

Uslov završetka:

- nema otvorenog pitanja koje sprečava kreiranje pokušaja, završni submit, ocenjivanje ili objavu rezultata.

### Faza 1 — Tehnička osnova i master podaci

Glavne aktivnosti:

- Laravel API, Vue 3 SPA, TypeScript, test framework i CI pipeline;
- okruženja local/test/staging/production;
- administrativna autentikacija, role/permission model i audit log;
- seasons, countries, regions, schools i difficulty levels;
- trajni korisnici i sezonske role/scope dodele;
- storage za slike/audio i osnovni antivirus/content-type validation tok;
- localization infrastruktura sa `en` fallback-om;
- health checks, strukturisani logovi, metrike i tracing correlation ID.

Izlazi:

- admin može da upravlja master podacima i sezonskim dodelama;
- school coordinator ne može pristupiti školi van svog scope-a;
- referentni podaci mogu da se uvezu dry-run migracijom.

### Faza 2 — Takmičarski sadržaj i konfiguracija

Glavne aktivnosti:

- Quiz → Exam → Test → Question → Answer option model;
- redosled i statusi sadržaja;
- multiple-choice, fill-the-gap i essay pitanja;
- media upload i bezbedna distribucija slike/audio sadržaja;
- mapiranje exam/test sadržaja na difficulty level;
- competition i sample režim;
- quiz password i raspoloživost sadržaja;
- validacija kompletnosti testa pre aktivacije.

Izlazi:

- admin može da pripremi test, pregleda ga i aktivira;
- sistem ne dozvoljava objavu nekonzistentne konfiguracije;
- API vraća samo sadržaj dozvoljen za level registracije.

**Dopuna (2026-08-23) — ADR-0034:** numeracija se ne unosi u tekst. Broj pitanja dolazi iz njegovog mesta u testu, oznake odgovora (`a) b) c)` / `A) B) C)` / `1) 2) 3)`, ili nijedna) su podešavanje po pitanju, a uputstvo se piše u opisu testa umesto da se gura u prvo pitanje. Naslov pitanja je zato opcion i rich-text. **Zatečeni sadržaj se ispravlja na strani autora** — legacy unos je numeraciju držao u tekstu (1453 naslova su čist broj) i mestimično je vezivao za pogrešnu poziciju.

### Faza 3 — Registracije i studentski pristup

Glavne aktivnosti:

- sezonska registracija i `competitor_number`;
- web identifikacija bez klasičnog login-a;
- kratkotrajna studentska web sesija sa ograničenim scope-om;
- mobilni StudentAccount: email, Terms/Privacy acceptance i jednokratni link za postavljanje lozinke;
- bezbedno povezivanje mobilnog naloga sa sezonskom registracijom;
- rate limiting i zaštita od enumeracije takmičara/email adresa;
- studentski dashboard sa statusima dostupnosti.

Izlazi:

- web i mobile API tok dolaze do iste dozvoljene liste testova;
- email nalog sam po sebi ne daje level niti pristup testu;
- ručno izmenjen zahtev ne može otvoriti tuđu registraciju ili pogrešan level.

### Faza 4 — Attempt engine i završavanje testa

Glavne aktivnosti:

- attempt state machine;
- atomarni i idempotentni start;
- server-side `started_at`/`expires_at`;
- završni submit kompletnog skupa odgovora bez autosave-a;
- idempotentni ručni i timeout submit;
- automatsko ocenjivanje podržanih tipova;
- zaštita od dva attempt-a sa weba i mobile-a;
- auditovani reset koji poništava prethodni attempt bez brisanja istorije.

Izlazi:

- jedan aktivni attempt po registraciji i testu;
- refresh ne resetuje vreme;
- ponovljen submit ne duplira odgovore ili rezultat;
- istekao attempt ne prihvata zakašnjeli neautorizovani upis.

### Faza 5 — Ocenjivanje, objava i Competition Core izveštaji

Glavne aktivnosti:

- queue za obračun i pomoćne asinhrone poslove;
- administratorsko ocenjivanje essay odgovora;
- rezultat sa statusima `pending`, `graded`, `published`, `void`;
- pojedinačna ili grupna objava prema potvrđenom pravilu;
- osnovni pregled po sezoni, školi, zemlji, koordinatoru, level-u, exam-u/testu i težini;
- audit korekcija bodova i objavljivanja;
- ranking tek nakon potvrde formule.

Izlazi:

- takmičar ne vidi rezultat pre objave;
- administrator može završiti ručno ocenjivanje i objaviti rezultat;
- istorijski rezultat čuva tadašnji reporting kontekst.

**Dopuna (2026-08-23) — ADR-0039:** izveštajni sloj je dobio i **dashboard po nivou pristupa**. Sadržaj bira *scope*, ne rola: bez ograničenja → svetska karta (spajanje preko ISO 3166-1) + tabela zemalja; više od jednog venue-a → tabela venue-a; tačno jedan venue → sam spisak studenata. Uz KPI trake ide traka **„Waiting on you"** koja vraća samo ne-nulte stavke i samo one na koje korisnik sme da deluje, pa prazna traka znači da ništa ne čeka. Trend prijava po rundama crta **trake, ne liniju**, jer arhiva nema rundu 12.

**Dopuna (2026-08-23) — ADR-0040:** dashboard je dobio i **globalnu pretragu** (takmičarski broj, student, venue, zemlja, osoblje) — svaka grupa rezultata prošla je kroz isti gate i isti row scope kao ekran na koji vodi. Uz to, stavke iz „Waiting on you" vode na **filtrirane** liste: `?missing=dob` na studentima, `?missing=coordinator` i `?missing=city` na venue-ima, pa se broj na dashboardu i broj na ekranu poklapaju.

### Faza 6 — Migracija, performanse i produkciona spremnost

Glavne aktivnosti:

- ponovljivi ETL aktuelne i istorijskih baza;
- reconciliation izveštaji i uzorkovanje rezultata;
- characterization testovi legacy ponašanja;
- load/stress/soak/failure testovi za 10.000+ sesija;
- capacity plan, autoscaling pragovi, runbook i incident procedure;
- backup/restore proba i disaster-recovery test;
- security review i test kritičnih autorizacionih granica;
- kontrolisani cutover rehearsal.

Uslov završetka:

- migracioni zbir je usaglašen;
- performance SLO je ispunjen sa sigurnosnom marginom;
- rollback/cutover postupak je proban na staging okruženju.

**Implementacioni status (2026-08-20):**

- **Urađeno:** master-data ETL (`docs/04` §12), 50k sintetičkih registracija; **lokalni load-test** (k6, ~500 konkurentnih VU čisto — READ COMMITTED izolacija + deadlock-retry + odloženo grading kroz queue; detalji u `docs/05`); **istorijska arhiva rundi 9–13** (`docs/04` §13). Aplikacija je funkcionalno kompletna (Faze 2–5 + results/publishing/reporting + arhiva).
- **Sledeći koraci (pred-produkcija):**
  1. **Security review + QA prolaz** — *[urađeno 2026-08-20]* pregled 5 dimenzija (autorizacija/scope, studentski pristup i sesija, injection/upload, izloženost podataka/config, QA nad kritičnim admin tokovima). Jezgro čisto (objektna autorizacija, studentski tokovi, grading, SQL sloj, migracija). **Popravljeno:** coordinator `file_upload` + theme logo ograničeni na raster/pdf (stored-XSS); `ResultLedger::reconcile` više ne obara publish/reset (500) kad za isti test postoje i uvezeni i in-app rezultat (published attempt supersede-uje import); results/reports populacija stegnuta na scope pozivaoca + guard na single-attempt reset (bezbedna delegacija `results.manage`, ADR-0024); per-`competitor_number` throttle na `identify` (pristup testu = provera podataka, nije nalog). Suite **263** zelen. **Odloženo (backlog):** `ResultLedger` multi-round dedup, mrtva `can_reset_test_results` permisija, admin-ekvivalencija `users.manage`/`roles.manage`, scope-clamp i za reports/archive *reads*, blank imena u `season:reset` arhivi, prod-hardening (`.env.example` `APP_DEBUG`, `SESSION_SECURE_COOKIE`, security headeri, `LIBXML_NONET`).
  2. **Validacija arhive vs stari legacy app** — uporediti r9–r13 (registered/participated po zemlji) sa brojevima iz starog sistema. *[planirano]*
  3. **Pravi 10k load na prod-like infra** — Linux + php-fpm/nginx + LB. **Moguće tek nakon preseljenja aplikacije na server** (WAMP/Windows dev čisto ~500 VU); vezano za OD-2 (šta je „10k") i hosting odluku. *[čeka produkciono okruženje]*
  4. *(sitno)* level-mapping cleanup za uvezene runde (~49–51% NULL — vidi `docs/04` §13).

### Faza 7 — Sertifikati i import/export

- potvrda pravila generisanja i dostupnosti sertifikata;
- verzionisani template-i i ponovljiva generacija;
- definisani CSV/XLSX import/export ugovori;
- validacija, dry-run, error report i audit izvoza;
- queue obrada velikih dokumenata.

**Implementacioni status (2026-08-21):** najveći deo faze je isporučen kroz tri ADR-a.

- **Sertifikati:** SOA Cert (participation) — CMS-editabilan sadržaj + chunked PDF, legacy-identičan izgled (**ADR-0028**). Attendance register (PDF) uz njega.
- **Import/export ugovori (.xlsx):** ustaljen zajednički obrazac — **template** (header + hint red) · **reject-whole-file** validacija · **anotirani „Error" fajl** koji korisnik popravi i ponovo pošalje · **export istog layout-a** (round-trip). Nosi ga dependency-free `App\Support\XlsxWriter`/`XlsxReader`.
  - **Rezultati** — import/export + arhiva (**ADR-0027**).
  - **Studenti** — bulk create + odvojen attendance update (apply-and-report umesto reject) (**ADR-0029**).
  - **Koordinatori** — bulk create + export; razrešavanje po *imenu* (country/region/venue), bez lozinke u fajlu (**ADR-0030**).
  - **Venue-i** — bulk create **i update** u istom fajlu (prazan „Venue ID" dodaje, popunjen menja); kolone se čitaju po nazivu zaglavlja pa se exportovani fajl vraća bez dorade (**ADR-0032**).
- **Ostaje:** verzionisanje template-a i audit izvoza; queue obrada velikih dokumenata (za sada sinhrono uz chunking — SOA Cert deli PDF na delove, student import ide chunked bulk INSERT); dry-run kao korisnička opcija (postoji samo interno kroz „Error" fajl).

### Faza 8 — CMS, navigacija i Theme Settings

- PageLayout, Page, Category i Post;
- locale-aware sadržaj, inicijalno samo `en`;
- `public.header`, `public.footer`, `admin.top`, `admin.right_sidebar`;
- role-aware admin navigacija uz obaveznu backend autorizaciju;
- pet theme tokena, logo i mapiranje na komponente;
- tema važi odmah nakon čuvanja, bez istorije i rollback-a;
- ista tema za public, student, admin/coordinator i mobile.

**Stanje (2026-08-23) — Theme i navigacija su urađeni pre same faze:**

- **Theme (ADR-0035):** ne pet nego **12 tokena** — 8 semantičkih plus **4 slobodna slota palete** za javni/CMS deo; slot 4 nosi pozadinu admin top bara. SVG logo se prihvata, ali se pri uploadu prepisuje kroz whitelist sanitizer (na disk ide samo očišćena verzija). Uz to rich-text **site title** pored logotipa, već u javnom `/api/theme` payload-u.
- **CMS — stranice, objave, kategorije (ADR-0042, 2026-08-23):** isporučeno je **tri od četiri** celine iz §8.6. `Page` / `Post` / `Category` sa draft+published, zakazanom objavom, SEO poljima i redirect-om posle promene slug-a; javne rute `/news`, `/news/{slug}` i stranica u korenu (`/{slug}`, uz rezervisanu listu aplikacionih putanja). Sopstvena permisija **`cms.manage`** (katalog 17 → 18), model je locale-spreman (`locale` + `translation_group`) iako je aktivan samo `en`. Meta tagovi javnih adresa se renderuju na **serveru** pre nego što se SPA podigne — SPA inače nema šta da pokaže crawler-u. **Dopunjeno u istom danu:** media biblioteka + editor za članke (slike, tabele, H3/H4), featured slika kao **referenca** na biblioteku, i **upravljiva navigacija** — `cms_menus`/`cms_menu_items`, dva nivoa, drag & drop, a `public.header`/`public.footer` se crtaju iz CMS-a (navigacija preuzeta sa živog sajta). **Ostaje:** `PageLayout` zone i admin navigacije (`admin.top`/`admin.right_sidebar`).
- **Layout javne strane (ADR-0043, 2026-08-24):** četvrta celina iz §8.6 je isporučena, čime otpada i otvoreno pitanje §14/20. **Zone su registar u kodu** (`LayoutZones`), ne redovi koje admin pravi — legacy je dozvoljavao poziciju napravljenu ukucavanjem imena, koja se onda nije renderovala nigde. Naslovna je **jedna zona sa poređanim sekcijama** (`public.home`); sekcija je **tipizovan blok** čija polja deklariše `BlockSchema`, iz koje se grade i admin forma i validacija. **Dugmad su podlista unutar bloka**, sa sopstvenim prekidačem i stilom vezanim za paletu (ne color picker). 🪤 **Vidljivost dugmeta ima dva nezavisna uslova** — admin prekidač i sezonsku kapiju (`EntryWindow`: postoji li aktivan quiz tog tipa) — i oba moraju da važe; van sezone takmičarski ulaz nestaje bez obzira na prekidač. Ekran **Website → Layout** prikazuje kompoziciju strane odozgo nadole, sa prevlačenjem i prekidačem po sekciji. Uz to: javna naslovna je prerađena po dizajnu („Editorial"), zaglavlje je dobilo navigaciju na malim ekranima (ranije se stavke skrivaju bez zamene), a Theme settings **tamnu varijantu logotipa** za svetle površine.
- **Ostaje iz §8.6:** admin navigacije (`admin.top` / `admin.right_sidebar`) — javne se crtaju iz CMS menija, admin sidebar je i dalje data-driven u kodu (ADR-0038).
- **Navigacija i autorizacija (ADR-0038):** legacy `user_level` 10/5/1 preslikan u permisije + row scope; matricu drži `PermissionMatrixTest`. Public deo se nije dirao dok se nije otvorio CMS (vlasnik, 2026-08-23); **sada je otvoren (ADR-0042)**, pa je beo logo na belim public zaglavljima postao stvarna stavka, a ne odloženo pitanje.

### Faza 9 — Accounting compatibility

- inventar formula, statusa, ekrana, izveštaja i dozvola;
- characterization testovi reprezentativnih legacy slučajeva;
- tehnički rewrite bez promene poslovnih rezultata;
- migracija i reconciliation accounting podataka;
- svaka poslovna promena ide kao zaseban odobren zahtev.

## 4. Paralelni radni tokovi

Ovi tokovi traju kroz više faza:

| Tok | Počinje | Završava se kada |
| --- | --- | --- |
| Bezbednost i autorizacija | Faza 1 | svi kritični endpoint-i imaju policy i negativne testove |
| Migracija | Faza 0 | produkcioni reconciliation je potpisan |
| Performanse | Faza 0 | produkcioni capacity plan i runbook su potvrđeni |
| QA automatizacija | Faza 1 | kritični tokovi imaju integration/E2E/regression pokrivenost |
| Observability | Faza 1 | SLO metrike, alerti i dashboard-i rade u produkciji |
| Mobilni API ugovor | Faza 1 | web i mobile koriste isti stabilan Competition Core API |

## 5. Prioritetna pitanja

Pre Faze 4 treba potvrditi:

1. redosled testova i uslov otključavanja;
2. ponašanje posle prekida browsera/mreže bez autosave-a;
3. nivo na kom se objavljuju rezultati;
4. fill-the-gap normalizacija i parcijalni bodovi;
5. tačno mapiranje level-a na exam, test ili oba;
6. način povezivanja mobilnog naloga sa registracijom.

Pre Faze 6 treba potvrditi:

1. termin i oblik start/submit talasa;
2. performance SLO i ciljni hosting;
3. dostupnost svih istorijskih baza;
4. cutover period i prihvatljiv read-only prozor.

## 6. Definicija završenog Competition Core-a

Competition Core je spreman za produkciju tek kada su zajedno ispunjeni funkcionalni, migracioni, bezbednosni i performance kriterijumi. Završena funkcionalnost bez dokaza da podnosi 10.000+ aktivnih sesija nije završena isporuka.

