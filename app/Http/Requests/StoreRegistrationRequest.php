<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can_student_insert ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'school_id' => ['required', 'integer', 'exists:schools,id', $this->schoolInScope()],
            'school_external' => ['nullable', 'string', 'max:200'],
            'difficulty_level_id' => ['required', 'integer', 'exists:difficulty_levels,id'],
            'name' => ['required', 'string', 'max:250'],
            'date_of_birth' => ['nullable', 'date'],
            'grade' => ['required', 'integer', 'min:1', 'max:13'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }

    /** A coordinator may only register students for schools within their scope. */
    protected function schoolInScope(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $allowed = $this->user()?->allowedSchoolIds();
            if ($allowed !== null && ! $allowed->contains((int) $value)) {
                $fail('You cannot register students for this school.');
            }
        };
    }
}
