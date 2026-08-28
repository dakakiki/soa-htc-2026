<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Support\PasswordRecovery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Recovering a forgotten password (ADR-0063).
 *
 * Both endpoints are public and unauthenticated — a person who cannot sign in is
 * the only person who needs them. They are rate limited on the route, and they
 * are as deaf as they can be made: the first one answers the same sentence to
 * every address in the world, and the second refuses every kind of bad token
 * with one message.
 */
class PasswordResetController extends Controller
{
    /**
     * Ask for a link.
     *
     * The answer never changes. Not "we have sent it" — which would be a lie for
     * an address with no account behind it — but that a link is on its way if
     * there is anywhere for it to go. The screen says the same thing, and the
     * sentence is written so that it is true either way.
     */
    public function sendLink(SendPasswordResetLinkRequest $request): JsonResponse
    {
        // The status is deliberately dropped on the floor. See PasswordRecovery.
        PasswordRecovery::offer($request->string('email')->trim()->value());

        return response()->json(['data' => ['sent' => true]]);
    }

    /**
     * Spend the link, and choose the password.
     *
     * Unlike asking for one, this has to be able to fail out loud: somebody
     * holding a link that has expired needs to be told to ask for another, and a
     * screen that pretended to succeed would leave them signing in with a
     * password that was never set.
     *
     * 🪤 One message for every refusal, and it is attached to `email` rather
     * than `token` so that a screen showing errors under fields cannot end up
     * pointing at a field the reader did not fill in. The broker distinguishes an
     * unknown address from a bad token; passing that difference on would make
     * this screen the enumeration form the other one refuses to be.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();

                PasswordRecovery::signOutEverywhere($user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [trans('messages.password_reset.link_spent')],
            ]);
        }

        // No session is opened here. The next screen is the sign-in screen, with
        // the new password typed into it — which is the one proof that the reader
        // has actually recorded it somewhere rather than invented it and moved on.
        return response()->json(['data' => ['reset' => true]]);
    }
}
