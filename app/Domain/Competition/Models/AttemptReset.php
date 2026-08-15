<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record of one attempt reset (CC-11, ADR-0022). Snapshots the attempt's
 * state at the moment it was voided, plus who reset it and the mandatory reason.
 */
class AttemptReset extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'attempt_id', 'previous_status', 'previous_score', 'previous_grading_status',
        'previous_published_at', 'reason', 'reset_by',
    ];

    protected function casts(): array
    {
        return [
            'attempt_id' => 'integer',
            'previous_score' => 'decimal:2',
            'previous_published_at' => 'datetime',
            'reset_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Attempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resetter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reset_by');
    }
}
