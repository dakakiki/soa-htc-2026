<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Assessment\Models\TestType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One official, published result per (registration, test) — the results layer
 * (Layer B, ADR-0027). Denormalizes the round/type/quiz/season so the grid,
 * reports and export read without joining `attempts`. Written either by
 * publishing an in-app attempt (`source=attempt`, kept in sync by ResultLedger)
 * or by importing offline results (`source=import`).
 */
class RegistrationResult extends Model
{
    protected $fillable = [
        'registration_id', 'test_id', 'exam_round_id', 'test_type_id', 'quiz_id',
        'season_id', 'score', 'max_score', 'source', 'published_at', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'registration_id' => 'integer',
            'test_id' => 'integer',
            'exam_round_id' => 'integer',
            'test_type_id' => 'integer',
            'quiz_id' => 'integer',
            'season_id' => 'integer',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'published_at' => 'datetime',
            'published_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Registration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /** @return BelongsTo<Test, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    /** @return BelongsTo<ExamRound, $this> */
    public function examRound(): BelongsTo
    {
        return $this->belongsTo(ExamRound::class);
    }

    /** @return BelongsTo<TestType, $this> */
    public function testType(): BelongsTo
    {
        return $this->belongsTo(TestType::class);
    }

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
