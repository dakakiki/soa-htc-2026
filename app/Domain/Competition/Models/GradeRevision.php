<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An audit snapshot taken before an essay grade is overwritten (CC-09): the
 * previous points and note, plus the required reason and who made the change.
 */
class GradeRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['attempt_answer_id', 'previous_points', 'previous_note', 'reason', 'graded_by'];

    protected function casts(): array
    {
        return [
            'attempt_answer_id' => 'integer',
            'previous_points' => 'decimal:2',
            'graded_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AttemptAnswer, $this> */
    public function answer(): BelongsTo
    {
        return $this->belongsTo(AttemptAnswer::class, 'attempt_answer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
