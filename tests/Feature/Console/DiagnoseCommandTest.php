<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class DiagnoseCommandTest extends TestCase
{
    public function testFailsWhenStorageIsMissing(): void
    {
        $this->app->useStoragePath('/nonexistent/storage');

        $this->artisan('diagnose', ['--offline' => true])
            ->expectsOutput('[FALHA] writable /nonexistent/storage')
            ->assertExitCode(1);
    }

    public function testFailsWhenRequiredConfigIsEmpty(): void
    {
        config(['notifications.channels.telegram.bot_token' => '']);

        $this->artisan('diagnose', ['--offline' => true])
            ->expectsOutput('[FALHA] TELEGRAM_BOT_TOKEN set')
            ->assertExitCode(1);
    }

    public function testNeverPrintsSecretValues(): void
    {
        config(['notifications.channels.telegram.bot_token' => '123:SECRET']);

        $this->artisan('diagnose', ['--offline' => true])
            ->doesntExpectOutputToContain('123:SECRET');
    }
}
