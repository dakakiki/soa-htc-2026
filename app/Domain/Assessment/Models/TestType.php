<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Models;

use Illuminate\Database\Eloquent\Model;

class TestType extends Model
{
    protected $fillable = ['name', 'legacy_id'];

    protected function casts(): array
    {
        return ['legacy_id' => 'integer'];
    }
}
