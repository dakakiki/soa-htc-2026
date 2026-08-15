<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Exam extends Model
{
    protected $fillable = [
        'title', 'description', 'exam_round_id', 'status', 'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'exam_round_id' => 'integer',
            'legacy_id' => 'integer',
        ];
    }

    /** @return BelongsTo<ExamRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(ExamRound::class, 'exam_round_id');
    }

    /** @return BelongsToMany<DifficultyLevel, $this> */
    public function levels(): BelongsToMany
    {
        return $this->belongsToMany(DifficultyLevel::class, 'difficulty_level_exam');
    }

    /** @return BelongsToMany<Test, $this> */
    public function tests(): BelongsToMany
    {
        return $this->belongsToMany(Test::class, 'exam_test')
            ->withPivot('position')
            ->orderBy('exam_test.position');
    }
}
