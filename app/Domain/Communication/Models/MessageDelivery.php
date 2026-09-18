<?php

declare(strict_types=1);

namespace App\Domain\Communication\Models;

use App\Domain\Communication\Enums\MessageChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to one message, for one person, on one channel.
 *
 * This is the half that can be wrong in ways the message itself cannot: a mail
 * server refuses an address, a push endpoint has expired, a coordinator puts
 * the in-app notice away. The message says what was intended; this says what
 * came of it.
 */
class MessageDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'message_id', 'user_id', 'channel', 'status', 'sent_at', 'error', 'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'user_id' => 'integer',
            'channel' => MessageChannel::class,
            'sent_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Everything this person was ever sent in the app, put away or not — their
     * inbox.
     *
     * 🔴 The mail channel is deliberately not in it. A row there says an address
     * was handed a message, which is a fact about a mail server rather than
     * about this person, and putting it in a list headed "your notices" would
     * invite them to read it as one they can act on. Their mail is in their mail.
     *
     * @param  Builder<MessageDelivery>  $query
     */
    public function scopeInApp(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId)->where('channel', MessageChannel::App);
    }

    /**
     * What the coordinator should still see in front of them: delivered in the
     * app, and not yet put away.
     *
     * @param  Builder<MessageDelivery>  $query
     */
    public function scopeWaitingInApp(Builder $query, int $userId): void
    {
        $query->inApp($userId)->whereNull('dismissed_at');
    }
}
