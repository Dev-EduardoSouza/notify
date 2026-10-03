<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\ApiKeyManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class KeysCreateCommand extends Command
{
    protected $signature = 'keys:create {name} {--recipients= : Comma-separated recipient aliases}';

    protected $description = 'Create an API key for a client app (the key is shown only once)';

    public function handle(ApiKeyManager $keys): int
    {
        $recipients = explode(',', (string) $this->option('recipients'));

        try {
            $issued = $keys->issue((string) $this->argument('name'), $recipients);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('id: %d', $issued->model->id));
        $this->line(sprintf('name: %s', $issued->model->name));
        $this->line(sprintf('recipients: %s', implode(', ', $issued->model->recipients)));
        $this->line(sprintf('key: %s', $issued->plainKey));
        $this->warn('Store this key now. It cannot be shown again.');

        return self::SUCCESS;
    }
}
