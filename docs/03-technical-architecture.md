# 3. Tehnička arhitektura

> Verzija 2. Izmene: dodat `Astrology` domen i ephemeris engine; `Sessions` domen preimenovan u `Consultations`; dodata provera Laravel verzije; dodat zahtev za ažurnim tzdata.

## Arhitektonski pravac

API-first modularni monolit:

- jedna Laravel aplikacija;
- jedan Vue SPA frontend;
- jedna relaciona baza;
- poslovni domeni logički razdvojeni;
- bez microservices arhitekture u početnoj fazi.

Astrološki proračun **ne** uvodi zaseban servis. Ephemeris engine se poziva kao lokalni proces iz istog monolita.

## Backend

- Laravel — poslednja stabilna verzija u trenutku pokretanja projekta;
- PHP 8.3;
- REST API;
- Eloquent ORM;
- Laravel Sanctum;
- Form Requests za validaciju;
- API Resources za odgovore;
- Policies i Gates za autorizaciju;
- Jobs i Queues za sporije operacije;
- Notifications za email i buduće kanale;
- Scheduler za podsetnike i periodične poslove;
- `symfony/process` za poziv ephemeris binarnog fajla.

Sanctum koristi cookie/session autentifikaciju za first-party SPA. Autentifikacioni tokeni se ne čuvaju u `localStorage`.

### Napomena o verziji Laravela

Verzija 1 specifikacije navodila je „Laravel 13". Pre Faze 0 treba proveriti koja je verzija stvarno aktuelna stabilna, koliko traje njen podržani period i da li se poklapa sa planiranim trajanjem razvoja. Fiksiraj tačan broj verzije u `composer.json` tek nakon te provere i upiši ga u ovaj dokument.

## Frontend

- Vue 3;
- Vite;
- Vue Router;
- Pinia;
- Axios;
- Vue I18n;
- Bootstrap ili Tailwind CSS;
- Composition API.

Točak natalne karte se crta kao **inline SVG Vue komponenta**, bez Canvas-a i bez spoljne biblioteke za karte. Razlozi:

- skalira se bez gubitka kvaliteta;
- štampa se i izvozi u PDF;
- preuzima brend boje iz sistema design tokena opisanog u dokumentu 09;
- ostaje pod našom kontrolom, bez licencnih ograničenja treće biblioteke.

Dashboard ne zahteva Nuxt. Marketing sajt ili javne SEO stranice mogu kasnije biti zasebno rešene.

## Baza

- ciljna verzija: MySQL 8.0 ili 8.4 LTS;
- trenutni MySQL 5.7 može služiti samo kao prelazno lokalno okruženje;
- sve izmene šeme idu kroz Laravel migracije;
- razvojna i produkcijska glavna verzija baze treba da budu usklađene;
- JSON kolone se koriste za `payload` izračunate karte, što zahteva MySQL 8.

## Lokalno razvojno okruženje

Postojeći WAMP:

- Apache 2.4.66.3 — odgovara;
- PHP 8.3.29 — kompatibilan, ažurirati na najnoviji dostupan PHP 8.3 patch;
- MySQL 5.7.36 — zameniti MySQL 8 pre produkcije, poželjno pre početnih migracija;
- Git repozitorijum — obavezan izvor istine za kod.

Dodatni alati:

- Composer 2;
- Node.js LTS i npm;
- Mailpit ili ekvivalent za lokalni email;
- Redis opciono u početku;
- ephemeris binarni fajl i datoteke efemerida, dokumentovane u `11-astrology-calculation-module.md`.

Početna Laravel podešavanja mogu koristiti:

```env
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

`SESSION_DRIVER=database` je razlog zašto poslovni entitet nosi naziv `consultations`, a ne `sessions`. Laravel koristi `sessions` tabelu za sopstvene potrebe.

Redis se uvodi kada je potreban veći throughput ili Horizon nadzor.

## Vremenske zone i tzdata

Proračun karte oslanja se na `DateTimeZone` i istorijske offsete iz tzdata baze.

Zahtevi:

- tzdata na produkcionom serveru mora biti ažuran;
- preporučuje se PECL ekstenzija `timezonedb` kako verzija tzdata ne bi zavisila od OS paketa;
- verzija tzdata se beleži uz izračunatu kartu, jer promena istorijskih pravila može promeniti rezultat;
- ažuriranje tzdata je kontrolisana operacija, ne usputna posledica sistemskog update-a.

## Apache

Virtual host mora pokazivati na Laravel `public` direktorijum. `mod_rewrite` mora biti uključen.

```apache
<VirtualHost *:80>
    ServerName astrology-saas.test
    DocumentRoot "C:/wamp64/www/astrology-saas/public"

    <Directory "C:/wamp64/www/astrology-saas/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Multi-tenancy

Koristi se jedna baza i `workspace_id` na svim tenant podacima.

Jedan korisnik može kasnije pripadati većem broju workspace-ova preko `workspace_user` pivot tabele.

Izolacija se primenjuje kroz:

- aktivni workspace u zahtevu;
- middleware;
- query scopes;
- Laravel Policies;
- service/action sloj;
- automatske testove koji potvrđuju zabranu pristupa drugom workspace-u.

Izračunate karte su tenant podatak i podležu istim pravilima.

## Organizacija backend domena

```text
Accounts
Workspaces
Clients
AstrologyMethods
Astrology          <-- novo: proračun karata
Consultations      <-- ranije Sessions
Notes
Attachments
Services
Appointments
Payments
Tasks
Notifications
```

Ne mora svaki domen odmah biti poseban Composer paket. Cilj je jasna organizacija bez nepotrebne infrastrukture.

### Struktura `Astrology` domena

```text
Astrology/
├── Contracts/
│   ├── EphemerisEngine.php
│   └── Geocoder.php
├── Engines/
│   ├── SwissEphemerisEngine.php
│   └── FakeEngine.php
├── Services/
│   ├── ChartService.php
│   ├── AspectCalculator.php
│   └── HouseSystemResolver.php
├── ValueObjects/
│   ├── ChartRequest.php
│   ├── ChartResult.php
│   └── PlanetPosition.php
└── Support/
    └── JulianDay.php
```

Interfejs je namerno uzak da bi engine ostao zamenljiv:

```php
interface EphemerisEngine
{
    public function calculate(ChartRequest $request): ChartResult;

    public function version(): string;
}
```

Nijedan kontroler, model ni Vue komponenta ne sme direktno zvati ephemeris biblioteku. Sav pristup ide kroz `EphemerisEngine`.

`FakeEngine` vraća unapred definisane vrednosti i koristi se u testovima, tako da CI ne zavisi od prisustva binarnog fajla ni od licence.

## Storage fajlova

Fajlovi se ne čuvaju kao javni `/public/uploads` resursi.

Zahtevi:

- private local storage u razvoju;
- S3-compatible private storage u produkciji;
- autorizovan download;
- privremeni potpisani URL-ovi;
- dozvoljena lista MIME tipova;
- provera stvarnog MIME tipa;
- ograničenje veličine;
- interno generisano ime;
- originalni naziv samo kao metapodatak;
- audit podatak o uploaderu.

Datoteke efemerida nisu korisnički sadržaj i ne idu u ovaj storage. One se isporučuju uz aplikaciju i verzionišu zajedno sa deployom.

## Višejezičnost i vreme

English je podrazumevani jezik. Frontend, backend validacije, emailovi, notifikacije i sistemski statusi koriste prevodne ključeve.

Čuvaju se:

```text
user.locale
workspace.default_locale
client.preferred_locale
```

Nazivi znakova, planeta i aspekata su prevodivi kroz iste prevodne ključeve. Astrološki simboli se prikazuju kao Unicode ili SVG putanje, ne kao slike sa tekstom.

Vremena se čuvaju u UTC-u. Originalna vremenska zona termina ili podatka rođenja čuva se posebno kada je potrebna za tačnu rekonstrukciju unosa.

## Git workflow

Za mali tim:

```text
main
feature/client-management
feature/astrology-positions
feature/natal-chart
feature/consultations
feature/file-uploads
```

Svaka funkcionalnost treba da sadrži povezane migracije, backend testove, frontend izmene i dokumentaciju. Tajne i lokalni `.env` se ne commit-uju; održava se ažuran `.env.example`.

## Namerno izostavljena infrastruktura

U početku ne uvoditi:

- microservices, uključujući i zaseban servis za proračun karata;
- Kubernetes;
- Elasticsearch;
- GraphQL;
- posebnu bazu po korisniku;
- event sourcing;
- native mobilnu aplikaciju;
- kompleksan permission framework bez stvarne potrebe;
- spoljni API za proračun karata koji bi uveo zavisnost od treće strane i mrežno kašnjenje.
