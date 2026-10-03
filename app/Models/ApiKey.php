<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $key_hash
 * @property string $key_prefix
 * @property list<string> $recipients
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ApiKey extends Model
{
    protected $guarded = [];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function allowsRecipient(string $alias): bool
    {
        return in_array($alias, $this->recipients, true);
    }

    public function clientRef(): string
    {
        return 'key:' . $this->id;
    }
}
