<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Competition\Enums\AttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attempt extends Model
{
    protected $fillable = [
        'registration_id', 'test_id', 'quiz_id', 'status',
        'started_at', 'expires_at', 'submitted_at', 'channel',
    ];

    protected function casts(): array
    {
        return [
            'registration_id' => 'integer',
            'test_id' => 'integer',
            'quiz_id' => 'integer',
            'status' => AttemptStatus::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
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
