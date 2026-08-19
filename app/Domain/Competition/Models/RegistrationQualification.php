<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Domain\Assessment\Models\ExamRound;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A competitor's advancement code (S/Q/F) for one exam round — the results layer
 * (Layer B, ADR-0027). Fills the Regional Qualifiers / World final grid columns
 * (legacy `q_semi`/`q_quali`/`q_final`): S→National, Q→Regional Qualifiers,
 * F→World final. Populated by the results import.
 */
class RegistrationQualification extends Model
{
    protected $fillable = [
        'registration_id', 'exam_round_id', 'season_id',
        'code', 'source', 'published_at', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'registration_id' => 'integer',
            'exam_round_id' => 'integer',
            'season_id' => 'integer',
            'published_at' => 'datetime',
            'published_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Registration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /** @return BelongsTo<ExamRound, $this> */
    public function examRound(): BelongsTo
    {
        return $this->belongsTo(ExamRound::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
