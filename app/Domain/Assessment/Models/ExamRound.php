<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use Illuminate\Database\Eloquent\Model;

class ExamRound extends Model
{
    protected $fillable = ['name', 'active', 'sort_order', 'legacy_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer', 'legacy_id' => 'integer'];
    }
}
