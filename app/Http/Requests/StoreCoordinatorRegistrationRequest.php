<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Organization\Models\CoordinatorRegistration;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The public coordinator registration form (ADR-0053).
 *
 * The field list is the legacy one, unchanged: the applicant's own details, a
 * country, the signed venue approval and a password. Legacy asked for no school
 * name and neither does this — the school's identity is what the signed document
 * establishes, and a name typed into a public form establishes nothing.
 *
 * Two rules differ from legacy on purpose:
 *  - the password floor is 8 rather than 6, which is what the approved design
 *    tells the applicant ("At least 8 characters") and what the rest of the app
 *    already expects;
 *  - the address check covers pending applications as well as accounts, so the
 *    same person cannot fill the queue by submitting the form five times.
 */
class StoreCoordinatorRegistrationRequest extends FormRequest
{
    /** Document types legacy accepted, and the same 5 MB ceiling. */
    public const DOCUMENT_MIMES = 'pdf,doc,docx,xls,xlsx';

    public const DOCUMENT_MAX_KILOBYTES = 5000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'document' => [
                'required', 'file',
                'mimes:'.self::DOCUMENT_MIMES,
                'max:'.self::DOCUMENT_MAX_KILOBYTES,
            ],
        ];
    }

    /**
     * Whether the address is free is asked here rather than with a `unique` rule
     * because it spans two tables and answers with ONE message either way.
     *
     * 🪤 Not `unique:users,email` plus `unique:coordinator_registrations,email`:
     * those two rules answer with two different sentences, and the difference
     * tells an anonymous visitor which of the two an address is in. The form
     * would then be a way of asking "is this person registered with you?".
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $email = (string) $this->input('email');

            if ($email === '') {
                return;
            }

            $taken = User::query()->where('email', $email)->exists()
                || CoordinatorRegistration::query()->pending()->where('email', $email)->exists();

            if ($taken) {
                $validator->errors()->add('email', trans('messages.coordinator_registration.email_in_use'));
            }
        });
    }
}
