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

    /**
     * The quiz's exams, in the order the ROUNDS run (ADR-0055). Everything that
     * lists a quiz's exams reads this one relation — the competitor's own list,
     * Check results and Publishing — so ordering it here is what makes all of
     * them agree with what Exam rounds says. Position inside the quiz is only
     * the tiebreak between two exams of the same round, and an exam with no
     * round at all falls to the end rather than jumping to the front on a NULL.
     *
     * @return BelongsToMany<Exam, $this>
     */
    public function exams(): BelongsToMany
    {
        $roundOrder = '(select sort_order from exam_rounds where exam_rounds.id = exams.exam_round_id)';

        return $this->belongsToMany(Exam::class, 'exam_quiz')
            ->withPivot('position')
            ->orderByRaw("{$roundOrder} is null")
            ->orderByRaw($roundOrder)
            ->orderBy('exam_quiz.position');
    }
}
