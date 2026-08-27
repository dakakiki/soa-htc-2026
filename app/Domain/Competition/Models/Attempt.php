<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Enums\GradingStatus;
use App\Domain\Competition\Jobs\GradeAttempt;
use Illuminate\Database\Eloquent\Builder;
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
        // `is_practice` is stamped at creation and never changes: it is what
        // keeps a repeatable sample run out of the contest's one-attempt unique.
        'registration_id', 'test_id', 'quiz_id', 'is_practice', 'status',
        'score', 'max_score', 'grading_status', 'published_at', 'published_by',
        'started_at', 'expires_at', 'submitted_at', 'channel',
    ];

    protected function casts(): array
    {
        return [
            'registration_id' => 'integer',
            'test_id' => 'integer',
            'quiz_id' => 'integer',
            'is_practice' => 'boolean',
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

    /**
     * Close an open attempt as of $at and send it to grading. Two things end an
     * attempt without the competitor pressing Hand in: the clock running out,
     * and giving the contest stream up (see StudentAvailabilityController).
     * Either way what was already answered still counts — what stops is the
     * chance to answer more. A no-op on an attempt that is not open, so a
     * repeated call cannot overwrite a submission or grade it twice.
     */
    public function complete(\DateTimeInterface $at): void
    {
        if ($this->status !== AttemptStatus::InProgress) {
            return;
        }

        $this->update([
            'status' => AttemptStatus::Completed,
            'submitted_at' => $at,
            'grading_status' => GradingStatus::Queued,
        ]);
        GradeAttempt::dispatch($this);
    }

    /** True once the deadline plus the grace window has passed. */
    public function isPastGrace(): bool
    {
        return now()->getTimestamp() > $this->expires_at->getTimestamp() + self::SUBMIT_GRACE_SECONDS;
    }

    /**
     * Attempts that still count: everything a competitor has not had reset. Voided
     * attempts are kept for audit but excluded from availability, grading, start
     * eligibility and publication (CC-11, ADR-0022).
     *
     * @param  Builder<Attempt>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', AttemptStatus::Void->value);
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

    /** @return HasMany<AttemptReset, $this> */
    public function resets(): HasMany
    {
        return $this->hasMany(AttemptReset::class);
    }
}
