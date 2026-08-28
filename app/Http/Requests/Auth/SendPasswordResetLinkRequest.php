<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SendPasswordResetLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An address, and nothing else.
     *
     * 🪤 No `exists:users,email`. The rule would answer "we can't find a user
     * with that email address" to an anonymous visitor, which turns the form
     * into a way of asking the site who has an account — the same leak the
     * sign-in screen refuses with its one generic message.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
