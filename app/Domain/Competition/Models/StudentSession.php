<?php

declare(strict_types=1);

namespace App\Domain\Competition\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSession extends Model
{
    /** How long an issued session stays valid, in minutes. */
    public const LIFETIME_MINUTES = 180;

    protected $fillable = [
        'registration_id', 'token_hash', 'expires_at', 'revoked_at', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'registration_id' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Registration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /** @param  Builder<StudentSession>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
