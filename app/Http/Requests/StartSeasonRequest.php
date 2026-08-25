<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartSeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            /*
             * The round prefixes every competitor number issued in the season
             * (round_number . LPAD(sequence, 6)), so it must be free: reusing one
             * would collide with numbers the archive already holds under it, and
             * the seasons table refuses it anyway. Width is deliberately not
             * constrained — docs/00 records the rule as six digits of sequence at
             * variable total width, and legacy rounds 9 through 13 each issued a
             * different length.
             */
            'round_number' => ['required', 'integer', 'min:1', 'unique:seasons,round_number'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            // Not a preference — the acknowledgement that the outgoing season is
            // about to be archived and wiped. Nothing here is reversible.
            'confirm' => ['accepted'],
        ];
    }
}
