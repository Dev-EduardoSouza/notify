<?php

declare(strict_types=1);

namespace Tests\Unit\Notion;

use App\Integrations\Notion\NotionBlockParser;
use PHPUnit\Framework\TestCase;

final class NotionBlockParserTest extends TestCase
{
    public function testParsesSupportedTypesAndCountsIgnoredOnes(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../../dev/stubs/fixtures/notion.json'), true);
        $blocks = $fixture['children']['1a2b3c4d-0000-4000-8000-000000000004'];

        $page = (new NotionBlockParser())->parse($blocks);

        self::assertSame(
            "Post 1: deploy sem Docker\n\n"
            . "Subi um microsserviço em hospedagem compartilhada.\n\n"
            . "• SQLite com WAL\n"
            . "1. Cron único com schedule:run\n\n"
            . "> Simplicidade primeiro.\n\n"
            . "💡 Revisar antes de publicar\n\n"
            . "☐ Adicionar imagem\n"
            . "☑ Revisar texto\n\n"
            . "Post 2\n\n"
            . "Subtítulo",
            $page->text,
        );
        self::assertSame(['image' => 1, 'table' => 1, 'toggle' => 1], $page->ignoredBlocks);
        self::assertSame(3, $page->ignoredCount());
    }

    public function testParsesAnonymizedRealCoworkDraft(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/notion-cowork-draft.json'), true);

        $page = (new NotionBlockParser())->parse($fixture['results']);

        self::assertSame([], $page->ignoredBlocks);
        self::assertStringStartsWith("heading_2 1.1\n\nparagraph 2.1\n\nheading_2 3.1", $page->text);
        self::assertStringContainsString('paragraph 8.1paragraph 8.2', $page->text);
        self::assertStringEndsWith("• bulleted_list_item 29.1\n• bulleted_list_item 30.1", $page->text);
        self::assertSame(23, substr_count($page->text, "\n\n"));
    }

    public function testNumberingRestartsAfterOtherBlocks(): void
    {
        $page = (new NotionBlockParser())->parse([
            $this->block('numbered_list_item', 'a'),
            $this->block('numbered_list_item', 'b'),
            $this->block('paragraph', 'meio'),
            $this->block('numbered_list_item', 'c'),
        ]);

        self::assertSame("1. a\n2. b\n\nmeio\n\n1. c", $page->text);
    }

    public function testJoinsRichTextPiecesAndSkipsEmptyParagraphs(): void
    {
        $block = $this->block('paragraph', 'Olá, ');
        $block['paragraph']['rich_text'][] = ['plain_text' => 'mundo', 'annotations' => ['bold' => true]];

        $page = (new NotionBlockParser())->parse([$block, $this->block('paragraph', ''), $this->block('heading_2', 'Fim')]);

        self::assertSame("Olá, mundo\n\nFim", $page->text);
        self::assertSame([], $page->ignoredBlocks);
    }

    public function testLoadsNestedChildrenWithIndent(): void
    {
        $parent = array_merge($this->block('bulleted_list_item', 'pai'), ['id' => 'p1', 'has_children' => true]);
        $loader = fn (string $id): array => $id === 'p1' ? [$this->block('bulleted_list_item', 'filho')] : [];

        $page = (new NotionBlockParser())->parse([$parent], $loader);

        self::assertSame("• pai\n   • filho", $page->text);
    }

    /** @return array<string, mixed> */
    private function block(string $type, string $text): array
    {
        return [
            'object' => 'block',
            'type' => $type,
            'has_children' => false,
            $type => ['rich_text' => $text === '' ? [] : [['plain_text' => $text]]],
        ];
    }
}
