<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\AnswerNumbering;
use App\Domain\Assessment\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    protected $fillable = [
        'title', 'description', 'question_type', 'answer_numbering', 'points',
        'question_tag_id', 'image_path', 'audio_path', 'status', 'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'question_type' => QuestionType::class,
            'answer_numbering' => AnswerNumbering::class,
            'points' => 'decimal:2',
            'question_tag_id' => 'integer',
            'legacy_id' => 'integer',
        ];
    }

    /** @return HasMany<QuestionAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(QuestionAnswer::class)->orderBy('position');
    }

    /** @return BelongsTo<QuestionTag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(QuestionTag::class, 'question_tag_id');
    }

    /** @return BelongsToMany<DifficultyLevel, $this> */
    public function levels(): BelongsToMany
    {
        return $this->belongsToMany(DifficultyLevel::class, 'difficulty_level_question');
    }
}
