<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A round of the competition: Preliminary, National, World final … and Sample,
 * which is practice.
 *
 * 🪤 `is_sample` is what marks the practice round, NOT the name. Three places in
 * the results domain turn on it, and a name is something an administrator can
 * retype. It is deliberately not editable through the API: this is structure.
 */
class ExamRound extends Model
{
    protected $fillable = ['name', 'active', 'sort_order', 'is_current', 'is_sample', 'legacy_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer', 'is_current' => 'boolean', 'is_sample' => 'boolean', 'legacy_id' => 'integer'];
    }
}
