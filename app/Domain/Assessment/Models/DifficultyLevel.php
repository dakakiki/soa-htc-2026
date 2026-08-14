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

    /**
     * The distinct level short codes in display order (regular stream first, then
     * special, each by position) — e.g. [BH, LH, H1..H5, S1..S5]. Used as the
     * competitor-count columns on the venue/school listings.
     *
     * @return list<string>
     */
    public static function orderedShorts(): array
    {
        return static::query()
            ->join('difficulty_categories', 'difficulty_categories.id', '=', 'difficulty_levels.difficulty_category_id')
            ->where('difficulty_levels.status', 'active')
            ->whereNotNull('difficulty_levels.level_short')
            ->orderBy('difficulty_categories.type')
            ->orderBy('difficulty_levels.position')
            ->pluck('difficulty_levels.level_short')
            ->unique()
            ->values()
            ->all();
    }
}

