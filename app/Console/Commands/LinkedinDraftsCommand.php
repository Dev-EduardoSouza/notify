<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Integrations\Notion\NotionApi;
use App\Integrations\Notion\NotionBlockParser;
use App\Integrations\Notion\ParsedPage;
use App\Logging\RedactSecretsProcessor;
use App\Notifications\DeliveryStatus;
use App\Notifications\NotificationRequest;
use App\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class LinkedinDraftsCommand extends Command
{
    protected $signature = 'jobs:linkedin-drafts';

    protected $description = 'Send today\'s LinkedIn draft from Notion to the configured recipient';

    private NotionApi $notion;

    private NotionBlockParser $parser;

    private NotificationService $notifications;

    public function handle(NotionApi $notion, NotionBlockParser $parser, NotificationService $notifications): int
    {
        $this->notion = $notion;
        $this->parser = $parser;
        $this->notifications = $notifications;

        $today = Carbon::now($this->timezone())->toDateString();

        try {
            $draft = $this->todaysDraft($today);
            $request = $draft === null
                ? $this->request($this->missingDraftText($today), 'linkedin-missing:' . $today)
                : $this->request($this->draftText($draft), 'linkedin-draft:' . $draft['id']);

            $result = $this->notifications->dispatch($request);
        } catch (Throwable $e) {
            return $this->reportFailure($e, $today);
        }

        $delivery = $result->delivery;
        $this->line(sprintf(
            '%s delivery=%s status=%s replayed=%s',
            $draft === null ? 'no-draft' : 'draft',
            $delivery->id,
            $delivery->status->value,
            $result->replayed ? 'yes' : 'no',
        ));

        return $delivery->status === DeliveryStatus::Failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{id: string, title: string, created: Carbon}|null */
    private function todaysDraft(string $today): ?array
    {
        $drafts = [];
        foreach ($this->notion->children($this->parentPageId()) as $block) {
            if (($block['type'] ?? null) !== 'child_page' || !is_string($block['id'] ?? null) || !is_string($block['created_time'] ?? null)) {
                continue;
            }
            $created = Carbon::parse($block['created_time'])->setTimezone($this->timezone());
            if ($created->toDateString() !== $today) {
                continue;
            }
            $page = is_array($block['child_page'] ?? null) ? $block['child_page'] : [];
            $drafts[] = [
                'id' => $block['id'],
                'title' => is_string($page['title'] ?? null) && $page['title'] !== '' ? $page['title'] : 'Rascunhos de LinkedIn',
                'created' => $created,
            ];
        }

        usort($drafts, fn (array $a, array $b): int => $b['created'] <=> $a['created']);

        return $drafts[0] ?? null;
    }

    /** @param array{id: string, title: string, created: Carbon} $draft */
    private function draftText(array $draft): string
    {
        $page = $this->parser->parse(
            $this->notion->children($draft['id']),
            fn (string $id): array => $this->notion->children($id),
        );

        $sections = [$draft['title']];
        if ($page->text !== '') {
            $sections[] = $page->text;
        }
        if ($page->ignoredCount() > 0) {
            $sections[] = $this->ignoredNotice($page);
        }
        $sections[] = 'https://www.notion.so/' . str_replace('-', '', $draft['id']);

        return implode("\n\n", $sections);
    }

    private function ignoredNotice(ParsedPage $page): string
    {
        $types = implode(', ', array_map(
            fn (string $type, int $count): string => sprintf('%s (%d)', $type, $count),
            array_keys($page->ignoredBlocks),
            $page->ignoredBlocks,
        ));

        return sprintf('⚠️ %d bloco(s) não suportado(s) ficaram de fora: %s. Veja a página completa no link.', $page->ignoredCount(), $types);
    }

    private function missingDraftText(string $today): string
    {
        return sprintf(
            'Hoje (%s) não encontrei rascunho novo em "Posts LinkedIn". A tarefa do Cowork parece não ter rodado; confira em Scheduled no Cowork.',
            Carbon::parse($today)->format('d/m'),
        );
    }

    private function reportFailure(Throwable $e, string $today): int
    {
        $reason = RedactSecretsProcessor::scrub($e->getMessage());
        Log::error('jobs:linkedin-drafts failed', ['exception' => $e]);
        $this->error('jobs:linkedin-drafts failed: ' . $reason);

        try {
            $this->notifications->dispatch($this->request(
                '⚠️ O envio do rascunho de LinkedIn falhou hoje: ' . $reason,
                'linkedin-error:' . $today . ':' . hash('sha256', $reason),
            ));
        } catch (Throwable $alertFailure) {
            Log::error('jobs:linkedin-drafts alert failed', ['exception' => $alertFailure]);
        }

        return self::FAILURE;
    }

    private function request(string $text, string $idempotencyKey): NotificationRequest
    {
        return NotificationRequest::forSystem(
            (string) config('notifications.linkedin_drafts.channel'),
            (string) config('notifications.linkedin_drafts.recipient'),
            $text,
            $idempotencyKey,
        );
    }

    private function parentPageId(): string
    {
        $id = config('notifications.linkedin_drafts.parent_page_id');
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('LINKEDIN_PARENT_PAGE_ID is not set.');
        }

        return $id;
    }

    private function timezone(): string
    {
        return (string) config('notifications.linkedin_drafts.timezone');
    }
}
