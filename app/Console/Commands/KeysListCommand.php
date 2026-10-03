<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

final class KeysListCommand extends Command
{
    protected $signature = 'keys:list';

    protected $description = 'List API keys without revealing them';

    public function handle(): int
    {
        $rows = ApiKey::query()->orderBy('id')->get()->map(fn (ApiKey $key): array => [
            $key->id,
            $key->name,
            $key->key_prefix . '…',
            implode(', ', $key->recipients),
            $key->created_at->toDateTimeString(),
            $key->last_used_at?->toDateTimeString() ?? '-',
            $key->isRevoked() ? 'revoked' : 'active',
        ]);

        $this->table(['id', 'name', 'prefix', 'recipients', 'created', 'last used', 'status'], $rows->all());

        return self::SUCCESS;
    }
}
