<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class WhatsAppOutboxTableTest extends TestCase
{
    use RefreshDatabase;

    public function testTableHasTheAgreedColumnsAndIndex(): void
    {
        self::assertSame(
            ['id', 'api_key_name', 'recipient', 'text', 'status', 'attempts', 'next_attempt_at', 'last_error', 'created_at', 'sent_at'],
            Schema::getColumnListing('whatsapp_outbox'),
        );

        $indexes = array_map(fn (array $index): array => $index['columns'], Schema::getIndexes('whatsapp_outbox'));
        self::assertContains(['status', 'next_attempt_at'], $indexes);
    }
}
