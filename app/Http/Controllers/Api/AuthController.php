<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Session (cookie) login for the first-party SPA via Sanctum.
     */
    public function login(LoginRequest $request): UserResource
    {
        $key = Str::transliterate(Str::lower($request->string('email').'|'.$request->ip()));

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])],
            ]);
        }

        if (! Auth::guard('web')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            RateLimiter::hit($key);

            // Generic message: never reveal whether the email exists.
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return UserResource::make(Auth::user());
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }

    public function user(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
