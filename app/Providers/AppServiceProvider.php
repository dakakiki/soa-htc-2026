<?php

namespace App\Providers;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Competition\Models\Registration;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Models\Setting;
use App\Models\User;
use App\Policies\CountryPolicy;
use App\Policies\DifficultyCategoryPolicy;
use App\Policies\DifficultyLevelPolicy;
use App\Policies\RegionPolicy;
use App\Policies\RegistrationPolicy;
use App\Policies\RolePolicy;
use App\Policies\SchoolPolicy;
use App\Policies\SeasonUserAssignmentPolicy;
use App\Policies\SettingPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Domain models live outside app/Models, so policies are registered explicitly.
        Gate::policy(School::class, SchoolPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(SeasonUserAssignment::class, SeasonUserAssignmentPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Country::class, CountryPolicy::class);
        Gate::policy(Region::class, RegionPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(DifficultyCategory::class, DifficultyCategoryPolicy::class);
        Gate::policy(DifficultyLevel::class, DifficultyLevelPolicy::class);
        Gate::policy(Registration::class, RegistrationPolicy::class);

        // Content hierarchy (lookups + questions) is admin-only and shares one
        // ability rather than a policy per lookup model.
        Gate::define('content.manage', fn (User $user): bool => $user->hasPermission('content.manage'));

        // The public website (pages, posts, categories). Its own ability, not part
        // of content.manage: the competition content and the site's content are
        // different jobs, and the CMS never touches an attempt or a result.
        Gate::define('cms.manage', fn (User $user): bool => $user->hasPermission('cms.manage'));

        // Bulk student file flows (import + attendance update). Separate from the
        // per-user student flags: legacy gave these to admins and country
        // coordinators only, never to a school coordinator.
        Gate::define('students.bulk', fn (User $user): bool => $user->hasPermission('students.bulk'));

        // Deciding public coordinator registrations (ADR-0053). Its own ability
        // rather than part of `coordinators.manage`: managing the coordinators a
        // country already has is routine work a country coordinator does, while
        // letting a stranger in is the decision the signed venue approval exists
        // for. Whoever holds this also sees the applicants' documents.
        Gate::define('coordinators.approve', fn (User $user): bool => $user->hasPermission('coordinators.approve'));

        // Results area: essay grading (5b), publication (5c) and attempt reset (5e).
        Gate::define('results.manage', fn (User $user): bool => $user->hasPermission('results.manage'));

        // Competition reports (5f) — a distinct read permission from results.manage.
        Gate::define('reports.view', fn (User $user): bool => $user->hasPermission('reports.view'));

        // Web identify is guessable down to the competitor's date of birth, so cap
        // attempts per competitor_number (which IP rotation can't dodge) alongside
        // the per-IP cap — otherwise a targeted DOB brute force could take a session.
        //
        // Twenty an hour per number (owner, 2026-08-27): the old ten ran out in a
        // single afternoon of ordinary use — an exam room re-entering, a
        // competitor checking marks — and a cap that stops the honest is worth
        // little against someone with a date-of-birth list. Both caps say WHY
        // they refused; the screen showed "check your details and try again",
        // which sent people to re-read a number that was right all along.
        RateLimiter::for('student-identify', fn (Request $request): array => [
            Limit::perMinute(8)->by('ip:'.$request->ip())
                ->response(fn (Request $r, array $headers) => $this->tooManyAttempts($headers)),
            Limit::perMinutes(60, 20)->by('num:'.(string) $request->input('competitor_number'))
                ->response(fn (Request $r, array $headers) => $this->tooManyAttempts($headers)),
        ]);

        // Coordinator registration (ADR-0053) is the only public endpoint that
        // writes a row AND stores a file, so an unattended script gets a disk
        // bill as well as a queue full of noise. Capped per address as well as
        // per IP: one school behind one NAT is a normal day, one address applying
        // repeatedly is not.
        // Asking for a password link (ADR-0063) posts mail to an address the
        // sender does not have to prove anything about, which is the one public
        // endpoint that can be pointed at somebody else. Three a day per address
        // is more than anybody needs and few enough that the form cannot be used
        // to bury an inbox; the per-IP caps are what stop a list being worked
        // through. The broker's own throttle (one link a minute per address)
        // sits underneath all of it.
        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perDay(40)->by('ip:'.$request->ip()),
            Limit::perDay(3)->by('mail:'.mb_strtolower(trim((string) $request->input('email')))),
        ]);

        RateLimiter::for('coordinator-registration', fn (Request $request): array => [
            Limit::perMinute(3)->by('ip:'.$request->ip()),
            Limit::perDay(20)->by('ip:'.$request->ip()),
            Limit::perDay(3)->by('mail:'.mb_strtolower(trim((string) $request->input('email')))),
        ]);
    }

    /**
     * Why identification was refused when the details were never looked at. A
     * competitor told to "check your details" reads the same right number over
     * and over; told to wait, they wait. The wait comes from `Retry-After`, so
     * the message can never contradict the header the middleware set.
     *
     * @param  array<string, mixed>  $headers
     */
    private function tooManyAttempts(array $headers): JsonResponse
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);

        return response()->json([
            'message' => __('Too many attempts. Please wait :minutes minutes and try again.', [
                'minutes' => max(1, (int) ceil($seconds / 60)),
            ]),
        ], 429, $headers);
    }
}
