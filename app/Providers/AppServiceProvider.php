<?php

namespace App\Providers;

use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use App\Policies\SchoolPolicy;
use App\Policies\SeasonUserAssignmentPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
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
    }
}
