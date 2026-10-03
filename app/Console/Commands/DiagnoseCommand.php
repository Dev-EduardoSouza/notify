<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * First thing to run on a new server. Works without a .env, so it can
 * explain what is missing instead of failing on it.
 */
final class DiagnoseCommand extends Command
{
    public const MIN_PHP = '8.3.0';

    public const REQUIRED_EXTENSIONS = ['curl', 'json', 'mbstring', 'openssl', 'pdo_sqlite'];

    /**
     * Env variable => config key that must end up non-empty.
     */
    public const REQUIRED_CONFIG = [
        'APP_KEY' => 'app.key',
        'TELEGRAM_BOT_TOKEN' => 'notifications.channels.telegram.bot_token',
        'TELEGRAM_RECIPIENTS' => 'notifications.channels.telegram.recipients',
        'NOTION_TOKEN' => 'notifications.notion.token',
        'LINKEDIN_PARENT_PAGE_ID' => 'notifications.linkedin_drafts.parent_page_id',
    ];

    public const HOSTS = ['https://api.telegram.org', 'https://api.notion.com'];

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

        // SQLite needs to write next to the database file (journal/WAL), not only to the file.
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

    /**
     * Probes with a real file write: is_writable() lies under some ACL setups.
     */
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

    /**
     * Any HTTP answer counts: the goal is DNS + TLS + network, not an API call.
     */
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
