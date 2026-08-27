<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IdentifyStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only shape validation here — whether the three factors actually match a
     * registration is checked in the controller and answered uniformly, so a
     * failure never reveals which field was wrong.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'competitor_number' => ['required', 'string', 'max:20'],
            'country_id' => ['required', 'integer'],
            'date_of_birth' => ['required', 'date'],
            /*
             * Which entry the competitor came through. Optional, because
             * identification itself is the same in every stream and a caller
             * that only wants a session should not have to claim one - but the
             * screens always send it, and it is the only way the answer can
             * name a shut stream instead of blaming the person's details.
             */
            'mode' => ['nullable', Rule::in(['sample', 'competition', 'results'])],
        ];
    }
}
