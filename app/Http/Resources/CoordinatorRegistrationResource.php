<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Organization\Models\CoordinatorRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One application, as the review queue sees it (ADR-0053).
 *
 * @mixin CoordinatorRegistration
 */
class CoordinatorRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'country_id' => $this->country_id,
            'country' => $this->whenLoaded('country', fn (): array => [
                'id' => $this->country->id,
                'name' => $this->country->name,
            ]),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // The document is described but never linked directly: it lives on the
            // private disk and is fetched through the gated download route.
            'document' => [
                'name' => $this->document_name,
                'mime' => $this->document_mime,
                'size' => $this->document_size,
            ],
            'decline_reason' => $this->decline_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewer' => $this->whenLoaded('reviewer', fn (): ?array => $this->reviewer === null ? null : [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),
            'account_id' => $this->approved_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
