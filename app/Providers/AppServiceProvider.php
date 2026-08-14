<?php

namespace App\Providers;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Assessment\Models\DifficultyLevel;
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
use App\Policies\RolePolicy;
use App\Policies\SchoolPolicy;
use App\Policies\SeasonUserAssignmentPolicy;
use App\Policies\SettingPolicy;
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
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Country::class, CountryPolicy::class);
        Gate::policy(Region::class, RegionPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(DifficultyCategory::class, DifficultyCategoryPolicy::class);
        Gate::policy(DifficultyLevel::class, DifficultyLevelPolicy::class);
    }
}
