<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Delivery;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class LinkedinDraftsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFT_ID = 'aaaaaaaa-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'notifications.channels.telegram.api_base' => 'https://tg.test',
            'notifications.channels.telegram.bot_token' => '1:TOKEN',
            'notifications.channels.telegram.recipients' => 'eduardo:42',
            'notifications.notion.api_base' => 'https://notion.test',
            'notifications.notion.token' => 'ntn_secret',
            'notifications.linkedin_drafts.parent_page_id' => 'parent',
            'notifications.linkedin_drafts.recipient' => 'eduardo',
            'notifications.linkedin_drafts.channel' => 'telegram',
            'notifications.linkedin_drafts.timezone' => 'America/Bahia',
        ]);
        Carbon::setTestNow('2026-10-05 14:55:00');
        Sleep::fake();
    }

    public function testSendsNewestDraftCreatedTodayInBahia(): void
    {
        $this->fakeApis([
            $this->page('aaaaaaaa-0000-4000-8000-000000000009', 'Madrugada UTC', '2026-10-05T02:00:00.000Z'),
            $this->page('aaaaaaaa-0000-4000-8000-000000000002', 'Primeira versão', '2026-10-05T12:00:00.000Z'),
            $this->page(self::DRAFT_ID, 'Posts LinkedIn — 2026-10-05', '2026-10-05T14:30:00.000Z'),
        ]);

        $this->artisan('jobs:linkedin-drafts')->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'tg.test')
            && $r['text'] === "Posts LinkedIn — 2026-10-05\n\nTexto do rascunho\n\nhttps://www.notion.so/aaaaaaaa000040008000000000000001");
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '000000000002/children'));
    }

    public function testSecondRunDoesNotResend(): void
    {
        $this->fakeApis([$this->page(self::DRAFT_ID, 'Rascunho', '2026-10-05T14:30:00.000Z')]);

        $this->artisan('jobs:linkedin-drafts')->assertExitCode(0);
        $this->artisan('jobs:linkedin-drafts')->expectsOutputToContain('replayed=yes')->assertExitCode(0);

        self::assertSame(1, Delivery::count());
        Http::assertSentCount(5);
    }

    public function testWarnsOnceWhenThereIsNoDraftToday(): void
    {
        $this->fakeApis([$this->page(self::DRAFT_ID, 'Antigo', '2026-10-05T02:00:00.000Z')]);

        $this->artisan('jobs:linkedin-drafts')->expectsOutputToContain('no-draft');
        $this->artisan('jobs:linkedin-drafts');

        $warnings = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'tg.test'));
        self::assertCount(1, $warnings);
        self::assertStringStartsWith('Hoje (05/10) não encontrei rascunho novo', $warnings->first()[0]['text']);
    }

    public function testMentionsIgnoredBlocks(): void
    {
        $this->fakeApis(
            [$this->page(self::DRAFT_ID, 'Rascunho', '2026-10-05T14:30:00.000Z')],
            [['type' => 'paragraph', 'has_children' => false, 'paragraph' => ['rich_text' => [['plain_text' => 'Oi']]]], ['type' => 'image', 'has_children' => false]],
        );

        $this->artisan('jobs:linkedin-drafts');

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'tg.test')
            && str_contains($r['text'], '⚠️ 1 bloco(s) não suportado(s) ficaram de fora: image (1).'));
    }

    public function testNotionFailureSendsAlertWithoutSecrets(): void
    {
        Http::fake([
            'notion.test/*' => Http::response(['code' => 'unauthorized', 'message' => 'API token is invalid.'], 401),
            'tg.test/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('jobs:linkedin-drafts')->assertExitCode(1);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'tg.test')
            && $r['text'] === '⚠️ O envio do rascunho de LinkedIn falhou hoje: notion: HTTP 401 unauthorized API token is invalid.');
    }

    public function testIsScheduledMondayAndThursdayAt1155InBahia(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $e): bool => str_contains((string) $e->command, 'jobs:linkedin-drafts'));

        self::assertNotNull($event);
        self::assertSame('55 11 * * 1,4', $event->expression);
        self::assertSame('America/Bahia', $event->timezone);
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @param list<array<string, mixed>>|null $draftBlocks
     */
    private function fakeApis(array $pages, ?array $draftBlocks = null): void
    {
        $draftBlocks ??= [['type' => 'paragraph', 'has_children' => false, 'paragraph' => ['rich_text' => [['plain_text' => 'Texto do rascunho']]]]];

        Http::fake([
            'notion.test/v1/blocks/parent/*' => Http::response(['results' => $pages, 'has_more' => false, 'next_cursor' => null]),
            'notion.test/v1/blocks/*' => Http::response(['results' => $draftBlocks, 'has_more' => false, 'next_cursor' => null]),
            'tg.test/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    /** @return array<string, mixed> */
    private function page(string $id, string $title, string $createdTime): array
    {
        return ['object' => 'block', 'id' => $id, 'type' => 'child_page', 'created_time' => $createdTime, 'has_children' => true, 'child_page' => ['title' => $title]];
    }
}
