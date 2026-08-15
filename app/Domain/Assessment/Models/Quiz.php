<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use App\Domain\Assessment\Enums\QuizType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Hash;

class Quiz extends Model
{
    protected $table = 'quizzes';

    protected $fillable = [
        'title', 'description', 'quiz_type', 'status', 'legacy_id',
    ];

    /** The bcrypt access code is set explicitly and never serialised. */
    protected $hidden = ['quiz_password'];

    protected function casts(): array
    {
        return [
            'quiz_type' => QuizType::class,
            'legacy_id' => 'integer',
        ];
    }

    /**
     * A competition quiz with an access code is gated (CC-06); sample quizzes and
     * competition quizzes left without a code are open.
     */
    public function requiresPassword(): bool
    {
        return $this->quiz_type === QuizType::Competition && $this->quiz_password !== null;
    }

    /** Verify a candidate access code against the stored bcrypt hash. */
    public function passwordMatches(string $candidate): bool
    {
        return $this->quiz_password !== null && Hash::check($candidate, $this->quiz_password);
    }

    /** @return BelongsToMany<DifficultyLevel, $this> */
    public function levels(): BelongsToMany
    {
        return $this->belongsToMany(DifficultyLevel::class, 'difficulty_level_quiz');
    }

    /** @return BelongsToMany<Exam, $this> */
    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(Exam::class, 'exam_quiz')
            ->withPivot('position')
            ->orderBy('exam_quiz.position');
    }
}
