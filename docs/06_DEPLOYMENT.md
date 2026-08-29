# 06 — Deploying a fresh installation

What a new production database needs before anybody can sign in, and what has to
be uploaded by hand afterwards. Written 2026-08-27, when a fresh install was
found to come up with an empty front page and **no administrator account at all**.
Extended 2026-08-28: the version below prescribed `db:seed --force` without a word
about `APP_ENV`, and the `.env` template it silently relied on shipped
`APP_ENV=local`. Following this page to the letter could therefore open a live
site with an administrator whose password is written down in this repository.

## The order

```bash
[ -f .env ] || cp .env.example .env     # exactly what `composer setup` already did
php artisan key:generate                # first install only — see below
php artisan migrate --force
php artisan db:seed --force
php artisan soahtc:create-admin
php artisan storage:link
```

That is the whole bootstrap, and everything from `migrate` down is safe to repeat
— which matters, because the template leaves the database user blank on purpose,
so a fix-and-run-it-again loop is the ordinary first-install experience.

> ⚠️ **`key:generate` is the one line that is not.** It rewrites `APP_KEY`, and
> every open session and every encrypted cookie was written against the old one.
> It asks first only when `APP_ENV` is exactly `production` — never under
> `--force`, and never on the box that wrongly calls itself `local`. The copy on
> the line above it is guarded for the same reason: a bare `cp` would overwrite a
> `.env` that is in no repository and has no backup step on this page.

### 0. `.env`

`.env.example` is a **production** template: `APP_ENV=production`, `APP_DEBUG=false`,
`SESSION_SECURE_COOKIE=true`, logging at `error`. Copying it without reading it
lands somewhere closed rather than somewhere open. What it deliberately leaves
blank is what it wants from you:

| Key | What it wants |
| --- | --- |
| `APP_KEY` | `php artisan key:generate` writes it. Everything encrypted is encrypted with it |
| `APP_URL` | the site's own address, `https://` included — mail links and the browser session both read it |
| `DB_DATABASE` · `DB_USERNAME` · `DB_PASSWORD` | the database. The user name is blank on purpose: an unconfigured install then fails to connect on the first command, instead of quietly turning out to be pointed at somebody else's data |
| `MAIL_*` | a real mailer. Coordinator registration answers by e-mail, twice, and the log driver swallows both |
| `SANCTUM_STATEFUL_DOMAINS` | the site's host and nothing else — each entry is a host allowed to act as a signed-in user |

> ⚠️ **`APP_ENV` is load-bearing, not decoration.** `MasterDataSeeder` — three
> invented countries, three invented schools, a fake `Season 2026`, a sample
> article and a `Dev Admin` account with every permission — is held back by
> nothing but `app()->environment('local', 'testing')`. Leave `APP_ENV=local` on
> a server and step 2 publishes development fiction onto the live site. Since
> 2026-08-28 that account at least has no password anyone knows (`config/development.php`),
> but the rest still spills. Check `php artisan tinker --execute="echo app()->environment();"`
> before seeding if you are not certain.

### 1. `migrate`

Schema only. Nothing here creates data.

### 2. `db:seed`

Three seeders run on every environment:

| Seeder | What it puts in |
| --- | --- |
| `RolePermissionSeeder` | the roles and the 18 permissions (ADR-0038) |
| `ContentLookupSeeder` | reference lookups — exam rounds, test types |
| `WebsiteSeeder` | the public site: its seven front-page sections, the header and footer, the copy of the sign-in / registration / competitor-entry screens, both navigations, and the cookie-policy page |

`MasterDataSeeder` is **synthetic development data** — invented schools, three
countries, a dev administrator — and refuses to run outside `local`/`testing`.
Nothing in it is needed on production. That refusal reads `APP_ENV` and believes
it, which is why step 0 puts `APP_ENV` first: the seeder cannot tell a production
box that calls itself `local` from a laptop.

Every seeder fills only what is empty, so re-running after a later deploy adds
what is new and leaves an edited page alone.

> ⚠️ `WebsiteSeeder` is skipped under `testing`, and the decision lives in
> `DatabaseSeeder` rather than in the seeder itself. Most layout tests build the
> one section they are about and read `data.blocks.0`, so a pre-filled zone would
> put a seeded hero in front of them. `WebsiteSeederTest` calls it directly.

### 3. `soahtc:create-admin`

Asks for a name, an e-mail address and a password (twice, not echoed). The
password is never an option or an argument — that would put it in the shell
history and in the process list.

**It also opens the first season, and it has to.** Permissions are read from the
user's assignment *in the active season*
(`User::permissionsForActiveSeason()`), and a season is otherwise created only
by the dev seeder, by the legacy archive import, or by a rollover that needs an
existing season and a permission to run. An administrator created without one
could sign in and do nothing — including open the season that would fix it. The
command therefore offers to open it first, and creates nothing if you decline.

It refuses an e-mail that already has an account: resetting somebody's password
from a console command is not its business.

### 4. `storage:link`

Every upload in the application — the logo, the photographs, the categories PDF,
the venue form, question images and audio, coordinator documents — is written to
the `public` disk, whose root is `storage/app/public` and whose URL is
`{APP_URL}/storage/…`. That URL is only a file if `public/storage` points at it,
and **nothing in the repository creates that link**: it is in `.gitignore`, and
`composer setup` does not run this command.

Skip it and the failure is quiet rather than loud. `public/.htaccess` sends
anything that is not a file to `index.php`, and the SPA catch-all in
`routes/web.php` answers **every** `/storage/…` address with the application's
own HTML page and a `200`. So the pictures in the table below render as broken
images, and the two download buttons hand the visitor an HTML document named
`.pdf`. Nothing in the log, no error page — just a site that looks unfinished.

The command is idempotent, and worth re-running after any move of the
installation, because the link stores an absolute path.

## What is NOT seeded, and has to be uploaded

The owner's decision of 2026-08-25: **no images or documents in the repository.**
So a fresh site is complete in structure and copy, and bare of pictures. None of
this breaks a page — a section without its photograph simply renders without it,
and a button whose file does not exist is dropped rather than published as a
dead link.

| What | Where it goes | What is missing until then |
| --- | --- | --- |
| Dark logo (SVG) | Settings → Theme → *Logo (dark)* | the header prints the site name in words |
| Hero photograph | Website → Media, then Website → Layout → *Hippo Exams* | the hero has no picture |
| Coordinator band photograph | Website → Media, then Website → Layout → *Coordinator access* | that band has no picture |
| Hippo categories PDF | Website → Media, then the category section's button → *A file to download* | the download button is not shown at all |
| Venue approval form | Website → Media, then Website → Layout → *Register* → button | applicants are not offered the form |

The dark logo can be regenerated from the white SVG by replacing `fill="#fff"`
with `#003758` (palette slot 4) and uploading it — `SvgSanitizer` rewrites it on
the way in.

## Also worth doing before opening the doors

- **`queue:work` must be running.** Without it nothing is graded, publishing
  announces nothing, and a competitor sees no mark — one cause, four symptoms.
- **Replace the cookie policy.** `WebsiteSeeder` creates the page as an
  unmistakable placeholder so the footer link has somewhere to go; the text is
  the venue's to write, from Website → Pages.
- **Name the round in play.** Content → Exam rounds; until one is current the
  status strip shows the season but no round.
- **Publish a sample quiz.** Practice is meant to be available all year (owner,
  2026-08-27); with no active sample quiz the practice entry is shown as closed.

## What only that server can tell you

Everything above builds the site. This section is for the questions no test on a
laptop can answer, because the answer belongs to the machine: its PHP limits, what
sits in front of PHP, its MySQL, its mail. Work through it on a staging copy on
the real server before the site is used in anger.

The local suite runs on **SQLite**; dev and production run on **MySQL**. That is
the one standing reason a green suite is not proof (`CLAUDE.md`).

### The one that has already bitten

**How long the season rollover takes, and whether the server allows it.**

Starting a season archives the roster, wipes the season and opens the next round,
inside one HTTP request. On the dev machine, against 50 004 registrations, that
takes **54 seconds**. `SeasonRollover::archiveAndWipe` raises PHP's own ceiling to
300 (ADR-0065) — but that is the only ceiling the application can raise.

```
php artisan season:rehearse
```

runs the whole rollover against this server's real data inside a transaction and
then rolls it back, printing how long it took. It changes nothing, and it refuses
to start if any table it touches is not InnoDB (a `ROLLBACK` would not restore a
MyISAM table, and the rehearsal would become the real thing).

Compare the number it prints against:

- the **web** `max_execution_time` — the CLI's own limit says nothing about the
  web SAPI, so read it from the FPM pool or a `phpinfo()` page;
- **whatever sits in front of PHP**: nginx `fastcgi_read_timeout`, Apache
  `ProxyTimeout`, mod_fcgid `IPCCommTimeout`. The application cannot touch these.

If the request is cut off anyway, the data is whole — the rollover owns its
transaction and a dropped connection rolls it back. The administrator is left not
knowing that, which is why the limits are worth setting rather than relying on.

### The rest of the checklist

| What | Why it can only be answered here | The check |
| --- | --- | --- |
| **`queue:work` is running** | Without it nothing is graded, publishing announces nothing and no competitor sees a mark — one cause, four symptoms. Needs supervisor or systemd. | Sit a test and watch it reach `graded` |
| **Real SMTP** | Locally `MAIL_MAILER=log`, so no mail has ever actually left. Coordinator registration sends two (ADR-0053), password recovery one (ADR-0063). | Ask for a password link and register a coordinator; then check SPF/DKIM so the three do not land in spam |
| **Exam media over HTTPS** | Pictures and recordings live on the private disk and are fetched with a signed address (ADR-0059). Behind a proxy that terminates TLS, a wrong scheme breaks the signature — and `TrustProxies` is not configured. | Open a question with a picture and a recording as a competitor: the picture must render and the audio must **seek** (Range) |
| **`storage:link`** | Without it every uploaded image comes back as the SPA's HTML page — a broken picture with a 200 status. | Upload a logo in Settings → Theme and see it draw |
| **Upload limits** | The venue approval form is capped at 5 MB (ADR-0053) and the .xlsx imports are larger; `max_input_vars` bounds the long forms. | `upload_max_filesize`, `post_max_size`, `max_input_vars`; then import a real roster |
| **SPA session** | Sanctum's cookie session needs the real host listed, and a secure cookie on HTTPS. | `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN`; sign in, reload, stay signed in |
| **MySQL version and mode** | Dev is **MySQL 8.3 with `ONLY_FULL_GROUP_BY` and `STRICT_TRANS_TABLES` already on**, so everything has been exercised in strict mode. An older MySQL or MariaDB differs in the other direction. | `SELECT VERSION(), @@sql_mode`; then open Reports and Archive, which lean hardest on `GROUP BY` |
| **PDF** | mPDF needs a writable temp directory, and the certificate and attendance register render in chunks. | Generate a certificate and an attendance register for a real venue |
| **Timezone** | An attempt's `expires_at` is computed server-side; a wrong `APP_TIMEZONE` ends exams at the wrong moment. | `APP_TIMEZONE` against the competition's own clock |

## When somebody says it broke

Every request is given a name. It comes back in the **`X-Request-Id`** response
header and it goes into the context of every line that request writes to the log —
the exception handler's report included (ADR-0070).

So the exchange with whoever is reporting the problem is short: ask them for the
id, then

```bash
grep <id> storage/logs/laravel-YYYY-MM-DD.log
```

and you have that request and nothing else. Each line also carries the path, the
IP, and — once a guard has resolved one — the account that was acting. A line
written before sign-in names nobody rather than naming the wrong person.

Runs from the command line get a name too, prefixed `cli-`, with the command
beside it. So the same grep works whether the trouble came through a browser or
through `season:reset`.

Two things worth knowing before you go looking:

- **`LOG_LEVEL` is `error` in the production template.** That is the right
  default, but it means an `info` line you expect to find is not there. Lower it
  temporarily if you are chasing something and it went quiet.
- **The plain format is the default and JSON is not.** These logs are read with a
  text editor here, and JSON is worse for that. The day a log collector arrives,
  `LOG_STACK=structured` in `.env` turns every line into one JSON object carrying
  the same context — and nothing else has to change.

## What the trail keeps, and what it drops

`audit_logs` records the **authority** surface only (ADR-0071): who created,
changed or deleted a role and its permissions, who was granted or refused a
season assignment and over which venues, the account lifecycle, and an
administrator sending somebody a password link.

It deliberately does **not** record the competition. Fifty thousand competitors
identifying, starting and handing in would bury all of the above, and `attempts`,
`attempt_answers` and `student_sessions` already hold it in fuller form.

🪤 Starting a season clears the competitor-scoped part of the trail and **keeps
the authority part** (ADR-0068, ADR-0071) — because *"who granted them that, last
season?"* is a question asked after a rollover, not before one. An account row
survives even though the rollover deletes school coordinators: the id is nulled,
the name beside it is not.

There is no screen for it yet. It is read with `grep`, or with SQL against
`audit_logs`, until somebody actually needs one.

## Continuous integration

`.github/workflows/ci.yml` runs on every pull request and on every push to
`main`: the whole suite **twice** — once on SQLite, once on MySQL 8.3 — plus
`pint --test`, `npm run type-check` and `npm run build`. Around two and a half
minutes in total.

The matrix is the point. Everything here is written on SQLite locally and ships
on MySQL, and the first run of this workflow immediately turned up four defects
that had been passing locally for months (ADR-0069). Do not merge on a red run,
and do not assume a green local run says the same thing.

The same second opinion is available without waiting for CI:

```bash
DB_CONNECTION=mysql DB_DATABASE=soa_htc_test DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= php artisan test
```

## Setting up for development

The template is aimed at a server, so a laptop turns a handful of lines around
after `composer setup` has copied it:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://dev.lcl.soa-htc.wrk      # whichever vhost you serve it from
LOG_LEVEL=debug
DB_DATABASE=soa_htc_dev
DB_USERNAME=root                        # WAMP's MySQL, no password
SESSION_SECURE_COOKIE=false             # a plain-HTTP host cannot sign you in with this on
MAIL_MAILER=log
SANCTUM_STATEFUL_DOMAINS=dev.lcl.soa-htc.wrk,localhost,127.0.0.1
```

Then `php artisan migrate:fresh --seed` builds the invented world — three
countries, three schools, both difficulty sets, an active `Season 2026`, one
sample page and one sample article, and the `admin@soahtc.test` administrator.
`php artisan storage:link` is needed here too, once, for the same reason as on a
server: without it every image you upload comes back as the SPA's HTML page.

**That account's password is printed when the account is created, and only
then**: `firstOrCreate` leaves an existing one alone, so a reseed onto a database
that already has it says so rather than inventing a password that would not work.
To keep the same one across every `migrate:fresh`, name it in your own `.env`:

```dotenv
DEV_ADMIN_PASSWORD=password
```

It is absent from `.env.example` on purpose, and must stay absent — a test says
so (`FreshInstallSafetyTest`). That file is what gets copied onto servers, and a
password travelling with it is exactly the hole this page was rewritten to close.
