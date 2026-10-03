<?php

declare(strict_types=1);

namespace App\Notifications\Text;

use InvalidArgumentException;

/**
 * Splits text into parts that fit a message limit measured in UTF-16 code
 * units, which is how Telegram counts (an emoji outside the BMP counts as 2).
 *
 * Cuts prefer a blank line, then a line break, then a space, as long as the
 * cut lands in the second half of the window (no tiny messages); otherwise the
 * text is cut hard, always between code points.
 * Separators stay at the end of the part, so implode('', $parts) === $text.
 */
final class TextSplitter
{
    private const SEPARATORS = ["\n\n", "\n", ' '];

    public function __construct(private readonly int $limit = 3500)
    {
        if ($limit < 2) {
            throw new InvalidArgumentException('The limit must allow at least one surrogate pair.');
        }
    }

    /**
     * @return list<string>
     */
    public function split(string $text): array
    {
        $chars = mb_str_split($text, 1, 'UTF-8');
        $parts = [];

        while ($chars !== []) {
            $fit = $this->countFitting($chars);
            if ($fit === count($chars)) {
                $parts[] = implode('', $chars);
                break;
            }

            $cut = $this->findCut(array_slice($chars, 0, $fit)) ?? $fit;
            $parts[] = implode('', array_slice($chars, 0, $cut));
            $chars = array_slice($chars, $cut);
        }

        return $parts;
    }

    public static function utf16Length(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * How many leading code points fit in the limit.
     *
     * @param list<string> $chars
     */
    private function countFitting(array $chars): int
    {
        $units = 0;
        foreach ($chars as $index => $char) {
            // 4-byte UTF-8 sequences are the code points above U+FFFF: two UTF-16 units.
            $units += strlen($char) === 4 ? 2 : 1;
            if ($units > $this->limit) {
                return $index;
            }
        }

        return count($chars);
    }

    /**
     * Position right after the last preferred separator in the window, if any.
     *
     * @param list<string> $window
     */
    private function findCut(array $window): ?int
    {
        $joined = implode('', $window);
        $minimum = intdiv(count($window), 2);

        foreach (self::SEPARATORS as $separator) {
            $position = mb_strrpos($joined, $separator, 0, 'UTF-8');
            if ($position === false) {
                continue;
            }
            $cut = $position + mb_strlen($separator, 'UTF-8');
            if ($cut >= $minimum) {
                return $cut;
            }
        }

        return null;
    }
}
