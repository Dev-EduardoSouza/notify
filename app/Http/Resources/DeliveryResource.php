<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Delivery */
final class DeliveryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'channel' => $this->channel,
            'recipient' => $this->recipient,
            'format' => $this->format,
            'attempts' => $this->attempts,
            'parts_total' => $this->parts_total,
            'delivered_parts' => $this->delivered_parts,
            'last_error' => $this->last_error,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
        ];
    }
}
