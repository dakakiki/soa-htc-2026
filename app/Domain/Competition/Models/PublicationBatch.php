<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record of one publish/unpublish action over a test or exam (CC-10).
 */
class PublicationBatch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['scope_type', 'scope_id', 'country_id', 'school_id', 'action', 'attempts_count', 'published_by'];

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'country_id' => 'integer',
            'school_id' => 'integer',
            'attempts_count' => 'integer',
            'published_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
