<?php

declare(strict_types=1);

namespace App\Messages;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $api_key_name
 * @property string $recipient
 * @property string|null $text
 * @property OutboxStatus $status
 * @property int $attempts
 * @property CarbonImmutable $next_attempt_at
 * @property string|null $last_error
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $sent_at
 */
final class WhatsAppOutbox extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'whatsapp_outbox';

    protected $fillable = ['api_key_name', 'recipient', 'text', 'status', 'attempts', 'next_attempt_at', 'last_error', 'sent_at'];

    protected function casts(): array
    {
        return [
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
