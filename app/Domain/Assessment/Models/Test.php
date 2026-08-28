<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Test extends Model
{
    protected $fillable = [
        'title', 'description', 'test_type_id', 'duration', 'status', 'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'test_type_id' => 'integer',
            'duration' => 'integer',
            'legacy_id' => 'integer',
        ];
    }

    /** @return BelongsTo<TestType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TestType::class, 'test_type_id');
    }

    /** @return BelongsToMany<DifficultyLevel, $this> */
    public function levels(): BelongsToMany
    {
        return $this->belongsToMany(DifficultyLevel::class, 'difficulty_level_test');
    }

    /** @return BelongsToMany<Question, $this> */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'question_test')
            ->withPivot('position')
            ->orderBy('question_test.position');
    }

    /**
     * Headings and instructions between the questions. Not questions: never
     * answered, never graded, never numbered. {@see TestNote}
     *
     * @return HasMany<TestNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(TestNote::class)
            ->orderBy('before_position')
            ->orderBy('sort_order');
    }
}
