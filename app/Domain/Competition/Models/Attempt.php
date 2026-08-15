<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Enums\GradingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attempt extends Model
{
    /**
     * Slack after the deadline for the client's auto-submit to arrive (OD-5,
     * ADR-0018). Within it the attempt is still submittable; past it the server
     * finalizes the attempt and refuses to record further answers.
     */
    public const SUBMIT_GRACE_SECONDS = 60;

    protected $fillable = [
        'registration_id', 'test_id', 'quiz_id', 'status',
        'score', 'max_score', 'grading_status', 'published_at', 'published_by',
        'started_at', 'expires_at', 'submitted_at', 'channel',
    ];

    protected function casts(): array
    {
        return [
            'registration_id' => 'integer',
            'test_id' => 'integer',
            'quiz_id' => 'integer',
            'status' => AttemptStatus::class,
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'grading_status' => GradingStatus::class,
            'published_at' => 'datetime',
            'published_by' => 'integer',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /** True once the deadline plus the grace window has passed. */
    public function isPastGrace(): bool
    {
        return now()->getTimestamp() > $this->expires_at->getTimestamp() + self::SUBMIT_GRACE_SECONDS;
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

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return HasMany<AttemptAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class);
    }
}
