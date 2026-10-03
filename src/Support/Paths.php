<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves project paths from a single root, so code never depends on the
 * current working directory (cron and the web server use different ones).
 */
final class Paths
{
    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? dirname(__DIR__, 2), '/');
    }

    public function root(string $relative = ''): string
    {
        return $this->join($this->root, $relative);
    }

    public function storage(string $relative = ''): string
    {
        return $this->join($this->root . '/storage', $relative);
    }

    /**
     * Absolute paths are kept as they are; relative ones are anchored at the root.
     */
    public function resolve(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->root($path);
    }

    private function join(string $base, string $relative): string
    {
        $relative = ltrim($relative, '/');

        return $relative === '' ? $base : $base . '/' . $relative;
    }
}
