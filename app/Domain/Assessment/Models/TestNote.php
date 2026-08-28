<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note between the questions of a test — a task heading, an instruction, a
 * passage the next few questions are about.
 *
 * Deliberately NOT a question. It is never answered, never graded, never
 * numbered, and `ContentCompleteness` does not count it towards a test having
 * something in it (owner, 2026-08-28: "that is not a question you take into
 * account when validating the test, it is only a note accompanying some
 * questions").
 *
 * See the migration for what `before_position` means and why a note is anchored
 * before a question rather than placed among them.
 */
class TestNote extends Model
{
    protected $fillable = ['test_id', 'before_position', 'sort_order', 'body'];

    /** @return BelongsTo<Test, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    protected function casts(): array
    {
        return [
            'before_position' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
