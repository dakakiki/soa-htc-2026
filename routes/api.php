<?php

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AttemptController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoordinatorController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DifficultyCategoryController;
use App\Http\Controllers\Api\DifficultyLevelController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamRoundController;
use App\Http\Controllers\Api\GradingController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\QuestionTagController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ResultsController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SchoolController;
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
 * Admin / coordinator authentication (Sanctum cookie session for the SPA).
 */
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('user', [AuthController::class, 'user'])->middleware('auth:sanctum');
});

/*
 * Competitor (student) web identification — no classic login. A short-lived
 * bearer-token session is issued after matching competitor_number + country +
 * date of birth. Identification is rate-limited against guessing.
 */
Route::prefix('student')->group(function () {
    Route::post('identify', [StudentAuthController::class, 'identify'])->middleware('throttle:8,1');
    Route::get('countries', [StudentAuthController::class, 'countries']);
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
    Route::get('countries', [CountryController::class, 'index']);
    Route::post('countries', [CountryController::class, 'store']);
    Route::put('countries/{country}', [CountryController::class, 'update']);
    Route::delete('countries/{country}', [CountryController::class, 'destroy']);
    Route::get('regions', [RegionController::class, 'index']);
    Route::post('regions', [RegionController::class, 'store']);
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
    Route::apiResource('exam-rounds', ExamRoundController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('question-tags', QuestionTagController::class)->only(['index', 'store', 'update', 'destroy']);
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
    Route::apiResource('schools', SchoolController::class);
    Route::apiResource('registrations', RegistrationController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    // Staff users and their season role/scope assignments.
    Route::apiResource('users', UserController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::apiResource('coordinators', CoordinatorController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::post('users/{user}/assignments', [AssignmentController::class, 'store']);
    Route::put('assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy']);

    // Branding/theme (read is public via /theme above; writing is gated).
    // PUT so the SPA can send multipart via POST + _method spoofing (like schools).
    Route::put('settings/theme', [SettingsController::class, 'updateTheme']);

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

    // Reports (5f, CC-12). Gated by reports.view.
    Route::get('reports/filters', [ReportController::class, 'filters']);
    Route::get('reports/summary', [ReportController::class, 'summary']);
});
