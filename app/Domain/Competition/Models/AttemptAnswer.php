<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Domain\Assessment\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttemptAnswer extends Model
{
    protected $fillable = ['attempt_id', 'question_id', 'response', 'is_correct', 'awarded_points', 'graded_by', 'graded_at', 'grade_note'];

    protected function casts(): array
    {
        return [
            'attempt_id' => 'integer',
            'question_id' => 'integer',
            'response' => 'array',
            'is_correct' => 'boolean',
            'awarded_points' => 'decimal:2',
            'graded_by' => 'integer',
            'graded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Attempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** The staff member who last graded this answer (essays). @return BelongsTo<User, $this> */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /** @return HasMany<GradeRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(GradeRevision::class);
    }
}
