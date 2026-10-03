<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Notifications\Text\TextSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextSplitterTest extends TestCase
{
    public function testShortTextIsOnePart(): void
    {
        self::assertSame(['Olá, mundo'], (new TextSplitter(3500))->split('Olá, mundo'));
    }

    public function testEmptyTextHasNoParts(): void
    {
        self::assertSame([], (new TextSplitter(3500))->split(''));
    }

    public function testPrefersBlankLineThenLineBreakThenSpace(): void
    {
        $splitter = new TextSplitter(12);

        self::assertSame(["aaaaaaa\n\n", 'bb cc dd'], $splitter->split("aaaaaaa\n\nbb cc dd"));
        self::assertSame(["aaa\n\nbbb\n", 'ccc ddd'], $splitter->split("aaa\n\nbbb\nccc ddd"));
        self::assertSame(["aaaa\nbbbb ", 'cccc'], $splitter->split("aaaa\nbbbb cccc"));
        self::assertSame(['aaaa bbbb ', 'cccc'], $splitter->split('aaaa bbbb cccc'));
    }

    public function testHardCutWhenNoSeparator(): void
    {
        self::assertSame(['ãããã', 'ãããã', 'ãã'], (new TextSplitter(4))->split(str_repeat('ã', 10)));
    }

    public function testEmojiCountsAsTwoUnitsAndIsNeverBroken(): void
    {
        $parts = (new TextSplitter(5))->split('😀😀😀a');

        self::assertSame(['😀😀', '😀a'], $parts);
    }

    /** @return iterable<string, array{string}> */
    public static function longTexts(): iterable
    {
        $words = ['ação', 'café', 'notificação', '😀', '🚀🔥', 'Ω', 'texto', "\n", "\n\n", 'emoji👨‍👩‍👧'];
        mt_srand(42);
        $text = '';
        while (mb_strlen($text) < 20000) {
            $text .= $words[mt_rand(0, count($words) - 1)] . ' ';
        }

        yield 'mixed 20k' => [mb_substr($text, 0, 20000)];
        yield 'only emoji 20k' => [str_repeat('😀', 20000)];
        yield 'no separators 20k' => [str_repeat('é', 20000)];
    }

    #[DataProvider('longTexts')]
    public function testLongTextsRespectLimitAndRebuildExactly(string $text): void
    {
        $parts = (new TextSplitter(3500))->split($text);

        self::assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            self::assertLessThanOrEqual(3500, TextSplitter::utf16Length($part));
            self::assertTrue(mb_check_encoding($part, 'UTF-8'));
        }
        self::assertSame($text, implode('', $parts));
    }
}
