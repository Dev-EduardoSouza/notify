<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\DeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $client_ref
 * @property int|null $api_key_id
 * @property string|null $idempotency_key
 * @property string $request_hash
 * @property string $channel
 * @property string $recipient
 * @property string $format
 * @property string|null $text
 * @property int $parts_total
 * @property int $delivered_parts
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Delivery extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * Same defaults as the migration, so a freshly created model has them without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'format' => 'plain',
        'parts_total' => 0,
        'delivered_parts' => 0,
        'attempts' => 0,
    ];

    protected $hidden = ['text', 'request_hash'];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'parts_total' => 'integer',
            'delivered_parts' => 'integer',
            'attempts' => 'integer',
            'api_key_id' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
