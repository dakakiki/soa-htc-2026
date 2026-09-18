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
        'message_id', 'user_id', 'channel', 'status', 'sent_at', 'error', 'read_at', 'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'user_id' => 'integer',
            'channel' => MessageChannel::class,
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
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
     * What stands in the coordinator's inbox: delivered in the app, and not put
     * away. Read or unread — a notice does not leave the screen for having been
     * read, only for being dismissed.
     *
     * @param  Builder<MessageDelivery>  $query
     */
    public function scopeInInboxOf(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId)
            ->where('channel', MessageChannel::App)
            // 🔴 The MAIL channel is not a notice. A row there says an
            // address was handed something, which is a fact about a mail
            // server rather than about this person; their mail is in their
            // mail.
            ->whereNull('dismissed_at');
    }

    /**
     * What the BELL counts, which is a narrower question than the one above and
     * was the same one until 2026-09-18.
     *
     * 🔴 The number beside the bell has to measure what its name says. It
     * counted everything not dismissed — so following a notification, reading
     * the message and leaving it in the inbox changed nothing, and the count
     * stayed up for ever unless the person destroyed the message with × to get
     * rid of it (owner, on a phone: *„poruka je ostala ne procitana"*).
     *
     * @param  Builder<MessageDelivery>  $query
     */
    public function scopeUnreadBy(Builder $query, int $userId): void
    {
        $query->inInboxOf($userId)->whereNull('read_at');
    }
}
