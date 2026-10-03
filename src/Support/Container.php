<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use LogicException;

/**
 * Tiny lazy service container: each factory runs once, on first use.
 */
final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * @param Closure(self): mixed $factory
     */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (!array_key_exists($id, $this->instances)) {
            $factory = $this->factories[$id] ?? throw new LogicException(sprintf('No service registered for %s', $id));
            $this->instances[$id] = $factory($this);
        }

        $instance = $this->instances[$id];
        if (!$instance instanceof $id) {
            throw new LogicException(sprintf('Service %s has an unexpected type', $id));
        }

        return $instance;
    }
}
