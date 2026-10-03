<?php

declare(strict_types=1);

namespace App\Integrations\Notion;

use Closure;

final class NotionBlockParser
{
    public const MAX_DEPTH = 3;

    private const LIST_TYPES = ['bulleted_list_item', 'numbered_list_item', 'to_do'];

    private const SUPPORTED_TYPES = [
        'paragraph', 'heading_1', 'heading_2', 'heading_3', 'quote', 'callout',
        'bulleted_list_item', 'numbered_list_item', 'to_do',
    ];

    /** @var array<string, int> */
    private array $ignored = [];

    /**
     * @param list<array<string, mixed>> $blocks
     * @param (Closure(string): list<array<string, mixed>>)|null $loadChildren
     */
    public function parse(array $blocks, ?Closure $loadChildren = null): ParsedPage
    {
        $this->ignored = [];
        $segments = $this->segments($blocks, $loadChildren, 0);

        $text = '';
        $previousIsList = false;
        foreach ($segments as [$isList, $line]) {
            if ($text !== '') {
                $text .= $isList && $previousIsList ? "\n" : "\n\n";
            }
            $text .= $line;
            $previousIsList = $isList;
        }

        ksort($this->ignored);

        return new ParsedPage($text, $this->ignored);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param (Closure(string): list<array<string, mixed>>)|null $loadChildren
     * @return list<array{bool, string}>
     */
    private function segments(array $blocks, ?Closure $loadChildren, int $depth): array
    {
        $segments = [];
        $number = 0;
        $indent = str_repeat('   ', $depth);

        foreach ($blocks as $block) {
            $type = is_string($block['type'] ?? null) ? $block['type'] : 'unknown';

            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                $this->ignored[$type] = ($this->ignored[$type] ?? 0) + 1;
                $number = 0;
                continue;
            }

            $body = is_array($block[$type] ?? null) ? $block[$type] : [];
            $number = $type === 'numbered_list_item' ? $number + 1 : 0;
            $text = $this->richText($body);

            if ($text !== '') {
                $segments[] = [in_array($type, self::LIST_TYPES, true), $indent . $this->decorate($type, $body, $text, $number)];
            }

            if (($block['has_children'] ?? false) === true && $loadChildren !== null && is_string($block['id'] ?? null)) {
                if ($depth + 1 >= self::MAX_DEPTH) {
                    $this->ignored['nested'] = ($this->ignored['nested'] ?? 0) + 1;
                    continue;
                }
                array_push($segments, ...$this->segments($loadChildren($block['id']), $loadChildren, $depth + 1));
            }
        }

        return $segments;
    }

    /** @param array<mixed> $body */
    private function richText(array $body): string
    {
        $parts = is_array($body['rich_text'] ?? null) ? $body['rich_text'] : [];
        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['plain_text'] ?? null)) {
                $text .= $part['plain_text'];
            }
        }

        return trim($text);
    }

    /** @param array<mixed> $body */
    private function decorate(string $type, array $body, string $text, int $number): string
    {
        return match ($type) {
            'bulleted_list_item' => '• ' . $text,
            'numbered_list_item' => $number . '. ' . $text,
            'to_do' => (($body['checked'] ?? false) === true ? '☑ ' : '☐ ') . $text,
            'quote' => '> ' . $text,
            'callout' => $this->calloutIcon($body) . $text,
            default => $text,
        };
    }

    /** @param array<mixed> $body */
    private function calloutIcon(array $body): string
    {
        $icon = is_array($body['icon'] ?? null) ? $body['icon'] : [];

        return is_string($icon['emoji'] ?? null) ? $icon['emoji'] . ' ' : '';
    }
}
