<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\ClientKey;
use App\Auth\ClientKeyRegistry;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\RecipientResolver;
use Illuminate\Console\Command;

final class KeysGenerateCommand extends Command
{
    protected $signature = 'keys:generate {name} {--channels=telegram : Comma-separated channels} {--to= : Comma-separated recipient aliases}';

    protected $description = 'Generate an API key and print the API_KEYS line for the .env';

    public function handle(ClientKeyRegistry $keys, ChannelRegistry $channels, RecipientResolver $recipients): int
    {
        $name = (string) $this->argument('name');
        $wantedChannels = $this->list((string) $this->option('channels'));
        $wantedRecipients = $this->list((string) $this->option('to'));

        $errors = [];
        if (preg_match(ClientKeyRegistry::NAME_PATTERN, $name) !== 1) {
            $errors[] = 'Name must have 1 to 64 characters among letters, digits, dot, dash and underscore.';
        }
        if ($keys->has($name)) {
            $errors[] = sprintf('A key named "%s" already exists in API_KEYS.', $name);
        }
        if ($wantedChannels === [] || $wantedRecipients === []) {
            $errors[] = 'At least one channel (--channels) and one recipient (--to) are required.';
        }
        foreach ($wantedChannels as $channel) {
            if (!$channels->has($channel)) {
                $errors[] = sprintf('Unknown channel: %s.', $channel);
                continue;
            }
            foreach (array_diff($wantedRecipients, $recipients->aliases($channel)) as $alias) {
                $errors[] = sprintf('Recipient "%s" is not configured for channel %s.', $alias, $channel);
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $plain = ClientKeyRegistry::generatePlainKey();
        $key = new ClientKey($name, ClientKeyRegistry::hash($plain), $wantedChannels, $wantedRecipients);
        $entries = array_map(fn (ClientKey $existing): string => $existing->toEnvEntry(), $keys->all());
        $entries[] = $key->toEnvEntry();

        $this->line('key: ' . $plain);
        $this->line('env: API_KEYS="' . implode(';', $entries) . '"');
        $this->warn('Give the key to the client app now; only its hash goes to the .env. Replace the API_KEYS line and run php artisan config:cache in production.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function list(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map(fn (string $item): string => strtolower(trim($item)), explode(',', $csv)))));
    }
}
