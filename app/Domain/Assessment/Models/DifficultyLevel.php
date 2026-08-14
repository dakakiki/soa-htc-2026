<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DifficultyLevel extends Model
{
    protected $fillable = ['difficulty_category_id', 'name', 'level_short', 'grades', 'position', 'status', 'legacy_id'];

    protected function casts(): array
    {
        return [
            'difficulty_category_id' => 'integer',
            'grades' => 'array',
            'position' => 'integer',
            'legacy_id' => 'integer',
        ];
    }

    /** @return BelongsTo<DifficultyCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DifficultyCategory::class, 'difficulty_category_id');
    }
}
