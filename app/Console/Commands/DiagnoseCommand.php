<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class DiagnoseCommand extends Command
{
    public const MIN_PHP = '8.3.0';

    public const REQUIRED_EXTENSIONS = ['curl', 'json', 'mbstring', 'openssl', 'pdo_sqlite'];

    public const REQUIRED_CONFIG = [
        'APP_KEY' => 'app.key',
        'TELEGRAM_BOT_TOKEN' => 'notifications.channels.telegram.bot_token',
        'TELEGRAM_RECIPIENTS' => 'notifications.channels.telegram.recipients',
    ];

    public const HOSTS = ['https://api.telegram.org'];

    protected $signature = 'diagnose {--offline : Skip the connectivity checks}';

    protected $description = 'Check PHP, extensions, writable folders, .env and connectivity';

    private int $failures = 0;

    public function handle(): int
    {
        $this->failures = 0;

        $this->line(sprintf('PHP binary: %s', PHP_BINARY));
        $this->line(sprintf('Timezone: %s', date_default_timezone_get()));

        $this->report(
            version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            sprintf('PHP %s (minimum %s)', PHP_VERSION, self::MIN_PHP),
        );

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $this->report(extension_loaded($extension), sprintf('extension %s', $extension));
        }

        foreach ([storage_path(), storage_path('logs'), database_path()] as $folder) {
            $this->report($this->isWritable($folder), sprintf('writable %s', $folder));
        }

        $envFile = base_path('.env');
        $this->report(is_file($envFile), '.env present');
        foreach (self::REQUIRED_CONFIG as $variable => $key) {
            $value = config($key);
            $this->report(is_string($value) && $value !== '', sprintf('%s set', $variable));
        }

        if (!$this->option('offline')) {
            foreach (self::HOSTS as $host) {
                $this->report($this->reachable($host), sprintf('HTTPS to %s', $host));
            }
        }

        $this->line($this->failures === 0 ? 'All checks passed.' : sprintf('%d check(s) failed.', $this->failures));

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function isWritable(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $probe = $path . '/.diagnose-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            return false;
        }
        unlink($probe);

        return true;
    }

    private function reachable(string $url): bool
    {
        try {
            Http::connectTimeout(5)->timeout(10)->head($url);

            return true;
        } catch (ConnectionException) {
            return false;
        }
    }

    private function report(bool $ok, string $label): void
    {
        if (!$ok) {
            $this->failures++;
        }
        $this->line(sprintf('[%s] %s', $ok ? 'OK' : 'FALHA', $label));
    }
}
