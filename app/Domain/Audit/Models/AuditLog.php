<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only business audit trail. Rows are never updated or deleted through
 * the application; only `created_at` is tracked.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'actor_label', 'action',
        'subject_type', 'subject_id',
        'before', 'after', 'reason', 'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
