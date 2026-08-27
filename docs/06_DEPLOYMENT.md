# 06 — Deploying a fresh installation

What a new production database needs before anybody can sign in, and what has to
be uploaded by hand afterwards. Written 2026-08-27, when a fresh install was
found to come up with an empty front page and **no administrator account at all**.

## The order

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan soahtc:create-admin
```

That is the whole bootstrap. Each step is safe to repeat.

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
Nothing in it is needed on production.

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
