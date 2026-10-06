<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Auth\ClientKeyRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class KeysGenerateCommandTest extends TestCase
{
    public function testPrintsKeyAndEnvLineWithOnlyTheHash(): void
    {
        config(['notifications.channels.telegram.recipients' => 'eduardo:1']);

        $this->assertSame(0, Artisan::call('keys:generate', ['name' => 'app', '--to' => 'eduardo']));
        $output = Artisan::output();

        self::assertSame(1, preg_match('/^key: (ntf_\S+)$/m', $output, $key));
        self::assertSame(1, preg_match('/^env: API_KEYS="(.+)"$/m', $output, $line));
        self::assertStringNotContainsString($key[1], $line[1]);
        self::assertSame('app', (new ClientKeyRegistry($line[1]))->authenticate($key[1])?->name);
    }

    public function testRejectsUnknownRecipientAndChannel(): void
    {
        config(['notifications.channels.telegram.recipients' => 'eduardo:1']);

        $this->artisan('keys:generate', ['name' => 'app', '--to' => 'maria'])->assertExitCode(1);
        $this->artisan('keys:generate', ['name' => 'app', '--channels' => 'sms', '--to' => 'eduardo'])->assertExitCode(1);
    }
}
