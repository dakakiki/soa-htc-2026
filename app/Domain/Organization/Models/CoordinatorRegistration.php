<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Enums\CoordinatorRegistrationStatus;
use App\Domain\Organization\Support\CoordinatorApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One application to become a school coordinator (ADR-0053).
 *
 * Not an account. {@see CoordinatorApproval}
 * turns an approved application into a user; until then this row is all there is,
 * and the applicant cannot sign in with it.
 *
 * @property CoordinatorRegistrationStatus $status
 */
class CoordinatorRegistration extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'address', 'city', 'country_id', 'password',
        'document_path', 'document_name', 'document_mime', 'document_size',
        'status', 'reviewed_by', 'reviewed_at', 'decline_reason', 'approved_user_id',
    ];

    /**
     * The password is already hashed when it arrives and is never read back —
     * hiding it keeps it out of every resource, log line and `toArray()` by
     * default rather than by each caller remembering to strip it.
     */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'country_id' => 'integer',
            'document_size' => 'integer',
            'status' => CoordinatorRegistrationStatus::class,
            'reviewed_at' => 'datetime',
            'reviewed_by' => 'integer',
            'approved_user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_user_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CoordinatorRegistrationStatus::Pending);
    }
}
