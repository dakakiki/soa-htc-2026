<?php

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Support\QuestionMedia;
use App\Http\Controllers\Api\ArchiveController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AttemptController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Cms\CategoryController as CmsCategoryController;
use App\Http\Controllers\Api\Cms\LayoutController as CmsLayoutController;
use App\Http\Controllers\Api\Cms\MediaController as CmsMediaController;
use App\Http\Controllers\Api\Cms\MenuController as CmsMenuController;
use App\Http\Controllers\Api\Cms\PageController as CmsPageController;
use App\Http\Controllers\Api\Cms\PostController as CmsPostController;
use App\Http\Controllers\Api\CoordinatorController;
use App\Http\Controllers\Api\CoordinatorRegistrationController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DifficultyCategoryController;
use App\Http\Controllers\Api\DifficultyLevelController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamRoundController;
use App\Http\Controllers\Api\GradingController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicContentController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\QuestionMediaController;
use App\Http\Controllers\Api\QuestionTagController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ResultsController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SeasonController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StudentAuthController;
use App\Http\Controllers\Api\StudentAvailabilityController;
use App\Http\Controllers\Api\TestController;
use App\Http\Controllers\Api\TestTypeController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

/**
 * Public liveness probe used by the SPA to confirm API wiring.
 * Returns no business data.
 */
Route::get('/ping', function () {
    return [
        'ok' => true,
        'app' => config('app.name'),
        'laravel' => Application::VERSION,
        'time' => now()->toIso8601String(),
    ];
});

/**
 * Public branding/theme payload — loaded before the SPA mounts (login screen
 * included), so it is intentionally unauthenticated. No business data.
 */
Route::get('/theme', [SettingsController::class, 'theme']);

/*
 * The public website's own reads. Unauthenticated on purpose: every query is
 * narrowed to published content in the controller, so there is nothing here an
 * anonymous reader is not meant to see.
 */
Route::prefix('public')->group(function () {
    Route::get('posts', [PublicContentController::class, 'posts']);
    Route::get('posts/{slug}', [PublicContentController::class, 'post']);
    Route::get('categories', [PublicContentController::class, 'categories']);
    Route::get('pages/{slug}', [PublicContentController::class, 'page']);
    Route::get('menus/{slug}', [PublicContentController::class, 'menu']);
    // Which round is running and whether it can be entered — both derived, so the
    // strip cannot claim a round is live after it closed.
    Route::get('site', [PublicContentController::class, 'site']);
    // The sections of a layout zone (ADR-0043). Switched-off blocks and buttons
    // are filtered server-side, so the page never has to know they existed.
    Route::get('layout/{zone}', [PublicContentController::class, 'layout']);
    // The country list the registration form picks from. Reference data, and the
    // same list the competitor entry screen gets from under its own prefix.
    Route::get('countries', [PublicContentController::class, 'countries']);
    /*
     * Coordinator registration (ADR-0053). The one public endpoint that WRITES:
     * it stores a row and a file, so it is rate limited harder than the reads
     * around it. It creates no account and issues no token — the row waits for a
     * reviewer.
     */
    Route::post('coordinator-registrations', [CoordinatorRegistrationController::class, 'store'])
        ->middleware('throttle:coordinator-registration');
});

/*
 * Admin / coordinator authentication (Sanctum cookie session for the SPA).
 */
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('user', [AuthController::class, 'user'])->middleware('auth:sanctum');

    /*
     * Recovering a forgotten password (ADR-0063). Public, because somebody who
     * cannot sign in is the only person who needs them.
     *
     * 🪤 Two different caps, not one. Asking for a link puts mail into an inbox
     * on nothing but a typed address, so it is capped per address as well as per
     * IP. Spending one sends nothing and is capped like the sign-in screen — put
     * on the same per-address cap, a coordinator who mistyped the new password
     * twice would find the link they are holding refused for the rest of the day.
     */
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:password-reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1');
});

/*
 * Competitor (student) web identification — no classic login. A short-lived
 * bearer-token session is issued after matching competitor_number + country +
 * date of birth. Identification is rate-limited against guessing.
 */
Route::prefix('student')->group(function () {
    Route::post('identify', [StudentAuthController::class, 'identify'])->middleware('throttle:student-identify');
    Route::get('countries', [StudentAuthController::class, 'countries']);

    /*
     * A question's picture and recording, for the competitor looking at them.
     *
     * 🪤 Outside `student.session` on purpose, and it is the one route here that
     * is. `<img src>` and `<audio src>` cannot send the bearer token the
     * competitor authenticates with, so the address carries its own proof: it is
     * SIGNED, minted inside the attempt payload (which IS behind
     * `student.session`), and it expires with the attempt. Moving this line into
     * the group below would return a 401 to every picture on the exam screen.
     */
    Route::get('questions/{question}/media/{kind}', [QuestionMediaController::class, 'student'])
        ->whereNumber('question')
        ->whereIn('kind', QuestionMedia::kinds())
        ->middleware('signed')
        ->name('student.questions.media');

    Route::middleware('student.session')->group(function () {
        Route::get('me', [StudentAuthController::class, 'me']);
        Route::post('logout', [StudentAuthController::class, 'logout']);

        // Level-gated assessment list (CC-06) and the competition password gate.
        Route::get('availability', [StudentAvailabilityController::class, 'index']);
        Route::post('quizzes/{quiz}/unlock', [StudentAvailabilityController::class, 'unlock'])
            ->whereNumber('quiz')
            ->middleware('throttle:8,1');

        // Attempt engine (Faza 4): start / resume / submit a test.
        Route::post('tests/{test}/start', [AttemptController::class, 'start'])->whereNumber('test');
        Route::get('attempts/{attempt}', [AttemptController::class, 'show'])->whereNumber('attempt');
        Route::post('attempts/{attempt}/submit', [AttemptController::class, 'submit'])->whereNumber('attempt');
    });
});

/*
 * Authenticated staff API. Object-level authorization is enforced per endpoint
 * via policies; listing is scoped server-side.
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show']);

    // One search box over students, venues, countries and staff. Each group is
    // gated and scoped exactly like the screen it leads to (see SearchController).
    Route::get('search', [SearchController::class, 'index']);

    // Self-service profile of the signed-in account (no permission gate: the
    // role decides which fields are editable, see ProfileController).
    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::delete('profile/assets/{asset}', [ProfileController::class, 'deleteAsset']);

    /*
     * The website's content (ADR-0042). One permission, `cms.manage`, gates the
     * lot; the public reads go through /api/public above and never touch these.
     */
    Route::prefix('cms')->group(function () {
        Route::apiResource('categories', CmsCategoryController::class)
            ->parameters(['categories' => 'category'])->names('cms.categories');
        Route::apiResource('posts', CmsPostController::class)
            ->parameters(['posts' => 'post'])->names('cms.posts');
        Route::apiResource('pages', CmsPageController::class)
            ->parameters(['pages' => 'page'])->names('cms.pages');
        // Navigation. Items are saved as one tree (see MenuController), and the
        // target picker is its own endpoint so the form can search server-side.
        Route::get('menu-targets', [CmsMenuController::class, 'targets']);
        Route::put('menus/{menu}/items', [CmsMenuController::class, 'saveItems'])->whereNumber('menu');
        Route::apiResource('menus', CmsMenuController::class)
            ->parameters(['menus' => 'menu'])->names('cms.menus');

        /*
         * Page layout (ADR-0043). Zones come from code, so there is no endpoint
         * that creates one; `layout/zones` publishes the registry the editor
         * builds its forms from. Order is saved as the whole list, as for menus.
         */
        Route::get('layout/zones', [CmsLayoutController::class, 'zones']);
        Route::get('layout/{zone}', [CmsLayoutController::class, 'index']);
        Route::post('layout/{zone}/blocks', [CmsLayoutController::class, 'store']);
        Route::put('layout/{zone}/order', [CmsLayoutController::class, 'saveOrder']);
        Route::put('layout-blocks/{block}', [CmsLayoutController::class, 'update'])->whereNumber('block');
        Route::delete('layout-blocks/{block}', [CmsLayoutController::class, 'destroy'])->whereNumber('block');

        // The media library: uploaded once, referenced from anywhere.
        Route::apiResource('media', CmsMediaController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['media' => 'media'])->names('cms.media');
    });

    Route::get('countries', [CountryController::class, 'index']);
    Route::post('countries', [CountryController::class, 'store']);
    Route::put('countries/{country}', [CountryController::class, 'update']);
    Route::delete('countries/{country}', [CountryController::class, 'destroy']);
    Route::get('regions', [RegionController::class, 'index']);
    Route::post('regions', [RegionController::class, 'store']);
    // Before `regions/{region}`: otherwise "reorder" is parsed as a region id.
    Route::put('regions/reorder', [RegionController::class, 'reorder']);
    Route::put('regions/{region}', [RegionController::class, 'update']);
    Route::delete('regions/{region}', [RegionController::class, 'destroy']);
    // Difficulty configuration (admin-only; categories + their grade→level maps).
    Route::apiResource('difficulty-categories', DifficultyCategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['difficulty-categories' => 'difficulty_category']);
    Route::apiResource('difficulty-levels', DifficultyLevelController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['difficulty-levels' => 'difficulty_level']);

    // Competitor-count column headers for the venue/school listings (level shorts).
    Route::get('difficulty-level-columns', fn () => ['data' => DifficultyLevel::orderedShorts()]);

    // Content configuration lookups (admin-only; gated in the controllers).
    Route::apiResource('test-types', TestTypeController::class)->only(['index', 'store', 'update', 'destroy']);
    // Before the resource, or `reorder` is read as a round id.
    Route::put('exam-rounds/reorder', [ExamRoundController::class, 'reorder']);
    Route::apiResource('exam-rounds', ExamRoundController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('question-tags', QuestionTagController::class)->only(['index', 'store', 'update', 'destroy']);
    // A question's picture and recording live on the private disk and come out
    // only here. The staff SPA reaches this with the session cookie, which the
    // browser attaches to `<img src>` on its own.
    Route::get('questions/{question}/media/{kind}', [QuestionMediaController::class, 'show'])
        ->whereNumber('question')
        ->whereIn('kind', QuestionMedia::kinds())
        ->name('questions.media');
    Route::apiResource('questions', QuestionController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::get('tests/{test}/preview', [TestController::class, 'preview']);
    Route::apiResource('tests', TestController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::apiResource('exams', ExamController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::apiResource('quizzes', QuizController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    // Difficulty levels as pickable options for content forms (id + short + category).
    Route::get('difficulty-level-options', fn () => ['data' => DifficultyLevel::query()
        ->join('difficulty_categories', 'difficulty_categories.id', '=', 'difficulty_levels.difficulty_category_id')
        ->orderBy('difficulty_categories.type')->orderBy('difficulty_categories.id')->orderBy('difficulty_levels.position')
        ->get(['difficulty_levels.id', 'difficulty_levels.level_short', 'difficulty_levels.name', 'difficulty_levels.grades', 'difficulty_categories.name as category_name', 'difficulty_categories.type as category_type'])]);

    Route::get('permissions', [PermissionController::class, 'index']);
    Route::apiResource('roles', RoleController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    // Venues export + bulk import (.xlsx) — registered before the apiResource so
    // "schools/export" isn't captured as a {school} show.
    Route::get('schools/export', [SchoolController::class, 'export']);
    Route::get('schools/import/template', [SchoolController::class, 'importTemplate']);
    Route::post('schools/import', [SchoolController::class, 'import']);
    Route::post('schools/import/errors', [SchoolController::class, 'importErrors']);

    Route::apiResource('schools', SchoolController::class);
    // Filtered students roster export (.xlsx) + printable attendance register (PDF).
    // Declared before the resource so the static paths aren't caught by {registration}.
    /*
     * The coordinator registration queue (ADR-0053). Gated on `coordinators.approve`
     * inside the controller, the document download included — seeing the signed
     * venue approval and deciding on it are one job, not two permissions.
     *
     * 🪤 `coordinator-registrations`, well clear of `registrations/*` below, which
     * is the competitor roster and a different thing entirely.
     */
    Route::prefix('coordinator-registrations')->group(function () {
        Route::get('/', [CoordinatorRegistrationController::class, 'index']);
        // Before the wildcard: `pending-count` is not an id.
        Route::get('pending-count', [CoordinatorRegistrationController::class, 'pendingCount']);
        Route::get('{registration}', [CoordinatorRegistrationController::class, 'show'])->whereNumber('registration');
        Route::get('{registration}/document', [CoordinatorRegistrationController::class, 'document'])->whereNumber('registration');
        Route::post('{registration}/approve', [CoordinatorRegistrationController::class, 'approve'])->whereNumber('registration');
        Route::post('{registration}/decline', [CoordinatorRegistrationController::class, 'decline'])->whereNumber('registration');
        Route::delete('{registration}', [CoordinatorRegistrationController::class, 'destroy'])->whereNumber('registration');
    });

    Route::get('registrations/export', [RegistrationController::class, 'export']);
    Route::get('registrations/attendance-report', [RegistrationController::class, 'attendanceReport']);
    // Bulk student import (.xlsx) — "Upload Students": template, per-country category
    // sets, and the create-import itself.
    Route::get('registrations/import/template', [RegistrationController::class, 'importTemplate']);
    Route::get('registrations/import/category-sets', [RegistrationController::class, 'importCategorySets']);
    Route::post('registrations/import', [RegistrationController::class, 'import']);
    Route::post('registrations/import/errors', [RegistrationController::class, 'importErrors']);
    // Bulk attendance update (.xlsx) — the separate "update students" flow.
    Route::get('registrations/attendance-import/template', [RegistrationController::class, 'attendanceImportTemplate']);
    Route::post('registrations/attendance-import', [RegistrationController::class, 'attendanceImport']);
    Route::get('registrations/soa-certificate/plan', [RegistrationController::class, 'soaCertificatePlan']);
    Route::get('registrations/soa-certificate', [RegistrationController::class, 'soaCertificate']);
    // Results grid: column definition + one competitor's per-round breakdown.
    // Declared before the resource so the static path isn't caught by {registration}.
    Route::get('registrations/result-columns', [RegistrationController::class, 'resultColumns']);
    Route::get('registrations/{registration}/results', [RegistrationController::class, 'results']);
    Route::apiResource('registrations', RegistrationController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    // Staff users and their season role/scope assignments.
    Route::apiResource('users', UserController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    /*
     * "Send a link" instead of typing a password for somebody (ADR-0063). One
     * endpoint for both screens: the Coordinators screen calls it too, because a
     * coordinator IS a user and a second route over the same table would be a
     * second place for the permission to be got wrong. Who may use it is the
     * policy's answer, and it is the same one as for editing the account.
     */
    Route::post('users/{user}/password-reset-link', [UserController::class, 'sendPasswordResetLink'])
        ->whereNumber('user')
        // 🪤 NOT `throttle:password-reset`. That limiter keys its per-address cap
        // on `email` in the body, which this request does not carry — every
        // administrator in the country would share one bucket keyed on the empty
        // string, and the third link of the day anywhere would be refused. The
        // broker's own one-a-minute-per-address throttle is what actually caps
        // this one; the rest is the same belt the sign-in screen wears.
        ->middleware('throttle:10,1');
    // Coordinators export (.xlsx) + bulk import (.xlsx) — registered before the
    // apiResource so "coordinators/export" isn't captured as a {coordinator} show.
    Route::get('coordinators/export', [CoordinatorController::class, 'export']);
    Route::get('coordinators/import/template', [CoordinatorController::class, 'importTemplate']);
    Route::post('coordinators/import', [CoordinatorController::class, 'import']);
    Route::post('coordinators/import/errors', [CoordinatorController::class, 'importErrors']);

    Route::apiResource('coordinators', CoordinatorController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::delete('coordinators/{coordinator}/assets/{asset}', [CoordinatorController::class, 'deleteAsset']);
    Route::post('users/{user}/assignments', [AssignmentController::class, 'store']);
    Route::put('assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy']);

    // Branding/theme (read is public via /theme above; writing is gated).
    // PUT so the SPA can send multipart via POST + _method spoofing (like schools).
    Route::put('settings/theme', [SettingsController::class, 'updateTheme']);
    Route::delete('settings/theme/assets/{asset}', [SettingsController::class, 'deleteThemeAsset']);
    // Season admin: the active round + what starting the next one would clear.
    // POST archives, wipes and opens the new round in one transaction.
    Route::get('settings/season', [SeasonController::class, 'show']);
    Route::post('settings/season', [SeasonController::class, 'store']);
    // Certificate content admin (body template + logo/signature/QR uploads).
    Route::get('settings/certificate', [SettingsController::class, 'certificate']);
    Route::put('settings/certificate', [SettingsController::class, 'updateCertificate']);
    Route::delete('settings/certificate/assets/{asset}', [SettingsController::class, 'deleteCertificateAsset']);

    // Results: essay grading (5b), publication (5c), attempt reset (5e). Gated by results.manage.
    Route::get('grading/attempts', [GradingController::class, 'index']);
    Route::get('grading/attempts/{attempt}', [GradingController::class, 'show'])->whereNumber('attempt');
    Route::put('grading/attempts/{attempt}/answers/{answer}', [GradingController::class, 'gradeEssay'])->whereNumber(['attempt', 'answer']);
    Route::get('results/overview', [ResultsController::class, 'overview']);
    Route::post('results/publish', [ResultsController::class, 'publish']);
    Route::get('results/reset-candidates', [ResultsController::class, 'resetCandidates']);
    Route::post('results/attempts/bulk-reset', [ResultsController::class, 'bulkReset']);
    Route::post('results/reset-export', [ResultsController::class, 'resetExport']);
    Route::post('results/attempts/{attempt}/reset', [ResultsController::class, 'reset'])->whereNumber('attempt');
    // Results import (offline results → Layer B, ADR-0027). Gated by results.manage.
    Route::get('results/import/options', [ResultsController::class, 'importOptions']);
    Route::get('results/import/template', [ResultsController::class, 'importTemplate']);
    Route::post('results/import', [ResultsController::class, 'import']);
    // Results export (all from Layer B, with-answers from Layer A). Gated by results.manage.
    Route::get('results/export', [ResultsController::class, 'exportResults']);
    Route::get('results/export-answers', [ResultsController::class, 'exportResultsWithAnswers']);

    // Results archive (Layer C, ADR-0027). Read-only, gated by reports.view.
    Route::get('archive/rounds', [ArchiveController::class, 'rounds']);
    Route::get('archive/summary', [ArchiveController::class, 'summary']);

    // Reports (5f, CC-12). Gated by reports.view.
    Route::get('reports/filters', [ReportController::class, 'filters']);
    Route::get('reports/summary', [ReportController::class, 'summary']);
    Route::get('reports/matrix', [ReportController::class, 'matrix']);
    Route::get('reports/export-pdf', [ReportController::class, 'exportPdf']);
});
