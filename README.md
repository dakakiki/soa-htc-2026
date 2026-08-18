<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Console commands

### `season:reset` — start-of-season cleanup

Run once when a new season begins (or to clear a testing round). It resets the
season-transactional data and normalises accounts and venues to a clean baseline,
while leaving everything that persists across seasons untouched.

| Action | Targets |
| --- | --- |
| **Wipe** (delete rows) | `registrations`, the full attempt chain (`attempts`, `attempt_answers`, `attempt_resets`, `grade_revisions`), student sessions (`student_sessions`, `student_session_quiz`), `publication_batches`, and the `audit_logs` trail |
| **Delete** (accounts) | users who are **school coordinators** in the active season — their school scope cascades away |
| **Deactivate** (`status = inactive`) | every remaining non-admin user (e.g. country coordinators) and **all schools** — records are kept, never deleted |
| **Keep untouched** | content library (quizzes / exams / tests / questions), countries & regions, difficulty, roles & permissions, lookups, seasons, settings |

Competitors ("students") are `registrations` rows, so they are wiped. **Admin
accounts are never touched.** Schools and coordinators arrive via the legacy import,
so schools are only deactivated (never deleted); real coordinators are re-enrolled per season.

Preview the counts without writing anything:

```bash
php artisan season:reset --dry-run
```

Apply it — all writes run in a single transaction; a confirmation is required unless `--force` is passed:

```bash
php artisan season:reset --force
```

**Out of scope (Phase 6):** archiving the previous season's results before the wipe,
and bumping the season round in settings. This command only deletes/deactivates data.

### `legacy:fix-encoding` — repair legacy mojibake

The legacy dump was loaded through a Windows **CP850** console, so imported text
(school / coordinator names, question content) was double-encoded — e.g.
`İ.T.Ü. Geliştirme Vakfı` was stored as `─░.T.├£. Geli┼ƒtirme Vakf─▒`, and
`Križevci` as `Kri┼¥evci`. This command reverses the CP850 mojibake **in place**
across the imported tables (`schools`, `users`, `questions`, …).

It is idempotent and safe to re-run: only strings carrying the tell-tale
box-drawing glyphs are touched, and a reversal is applied only when it yields
valid, no-longer-corrupted UTF-8. The same reversal
(`App\Domain\Migration\LegacyText`) is wired into the `legacy:import-*` mappers,
so a fresh re-import stays clean without this command. See **ADR-0026** in
`DECISIONS.md`.

Preview what would change (nothing is written):

```bash
php artisan legacy:fix-encoding --dry-run
```

Apply the repair:

```bash
php artisan legacy:fix-encoding
```

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
