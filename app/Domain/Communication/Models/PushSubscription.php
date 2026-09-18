<?php

declare(strict_types=1);

namespace App\Domain\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser on one device, and its permission to be notified.
 *
 * 🔴 A person is not a subscription. A coordinator with a phone and a tablet
 * has two, each with its own endpoint and its own keys, and a notification goes
 * to every one of them — which is also why `message_deliveries` keeps ONE row
 * per person per channel and this table is counted underneath it.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'user_agent', 'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * 🪤 Uniqueness lives on this hash rather than on the endpoint itself: the
     * endpoint is TEXT because there is no length in the spec to trust, and
     * MySQL will not index a TEXT column whole. Re-subscribing the same browser
     * has to update the row it already has — otherwise a coordinator who turns
     * notifications off and on again collects a second row, and every message
     * after that arrives twice.
     */
    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
