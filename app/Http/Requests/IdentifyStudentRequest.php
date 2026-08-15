<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
