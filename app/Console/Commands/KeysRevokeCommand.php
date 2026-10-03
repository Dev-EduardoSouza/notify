<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\ApiKeyManager;
use Illuminate\Console\Command;

final class KeysRevokeCommand extends Command
{
    protected $signature = 'keys:revoke {id}';

    protected $description = 'Revoke an API key';

    public function handle(ApiKeyManager $keys): int
    {
        $key = $keys->revoke((int) $this->argument('id'));
        if ($key === null) {
            $this->error('API key not found.');

            return self::FAILURE;
        }

        $this->line(sprintf('Key %d (%s) revoked at %s.', $key->id, $key->name, $key->revoked_at?->toDateTimeString()));

        return self::SUCCESS;
    }
}
