<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Minimal .env parser: KEY=value lines, optional "export ", # comments and
 * single or double quotes. No variable interpolation on purpose.
 */
final class DotEnv
{
    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                throw new ConfigException(sprintf('Invalid .env line %d: missing "="', $number + 1));
            }

            $key = trim(substr($line, 0, $separator));
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
                throw new ConfigException(sprintf('Invalid .env line %d: bad variable name', $number + 1));
            }

            $values[$key] = self::parseValue(trim(substr($line, $separator + 1)));
        }

        return $values;
    }

    private static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];
        if (($quote === '"' || $quote === "'") && strlen($raw) >= 2 && str_ends_with($raw, $quote)) {
            $inner = substr($raw, 1, -1);

            return $quote === '"' ? stripcslashes($inner) : $inner;
        }

        // Unquoted values may carry a trailing " # comment".
        $comment = strpos($raw, ' #');

        return $comment === false ? $raw : rtrim(substr($raw, 0, $comment));
    }
}
