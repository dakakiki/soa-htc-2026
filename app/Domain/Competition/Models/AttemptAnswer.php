<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Domain\Assessment\Models\Question;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptAnswer extends Model
{
    protected $fillable = ['attempt_id', 'question_id', 'response'];

    protected function casts(): array
    {
        return [
            'attempt_id' => 'integer',
            'question_id' => 'integer',
            'response' => 'array',
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
}
