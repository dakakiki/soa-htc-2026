<?php

declare(strict_types=1);

namespace App\Domain\Communication\Models;

use App\Domain\Communication\Enums\MessageChannel;
use App\Domain\Communication\Enums\MessageStatus;
use App\Domain\Communication\Support\Audience;
use App\Domain\Organization\Models\Season;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One text the administration wrote for coordinators.
 */
class Message extends Model
{
    protected $fillable = [
        'season_id', 'subject', 'body', 'audience',
        'channels', 'status', 'send_at', 'sent_at', 'recipients_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'season_id' => 'integer',
            'created_by' => 'integer',
            'recipients_count' => 'integer',
            'audience' => 'array',
            'channels' => 'array',
            'status' => MessageStatus::class,
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<MessageDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(MessageDelivery::class);
    }

    /** The four lists, normalised: an empty one narrows nothing. */
    public function audience(): Audience
    {
        return Audience::fromArray($this->audience);
    }

    /**
     * The channels as enums. Anything unknown in the stored list is dropped
     * rather than thrown: a channel removed from the code should not make an
     * old message unreadable.
     *
     * @return list<MessageChannel>
     */
    public function channelCases(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?MessageChannel => MessageChannel::tryFrom($value),
            $this->channels ?? [],
        )));
    }

    public function hasChannel(MessageChannel $channel): bool
    {
        return in_array($channel->value, $this->channels ?? [], true);
    }

    /**
     * A message that has gone out is a record, not a draft: its text, its
     * audience and its channels are settled and only the deliveries move.
     */
    public function isSent(): bool
    {
        return $this->status === MessageStatus::Sent;
    }

    /**
     * Due to go: scheduled, and the clock has passed it.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', MessageStatus::Scheduled)
            ->whereNotNull('send_at')
            ->where('send_at', '<=', now());
    }
}
