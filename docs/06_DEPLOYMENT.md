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
