<?php

declare(strict_types=1);

namespace App\Config;

use App\Support\Paths;

final class Config
{
    /**
     * Keys whose values are never printed or logged.
     */
    private const SECRET_PATTERN = '/(TOKEN|SECRET|PASSWORD|KEY|RECIPIENTS)/';

    /**
     * @param array<string, string> $values
     */
    public function __construct(
        private readonly array $values,
        public readonly Paths $paths,
    ) {
    }

    /**
     * Loads <root>/.env (if present) and lets real environment variables override it.
     */
    public static function load(Paths $paths): self
    {
        $file = $paths->root('.env');
        $values = [];

        if (is_file($file)) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new ConfigException('Could not read .env');
            }
            $values = DotEnv::parse($contents);
        }

        foreach (array_keys($values) as $key) {
            $override = getenv($key);
            if ($override !== false) {
                $values[$key] = $override;
            }
        }

        return new self($values, $paths);
    }

    public static function envFileExists(Paths $paths): bool
    {
        return is_file($paths->root('.env'));
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]) && $this->values[$key] !== '';
    }

    public function string(string $key, ?string $default = null): string
    {
        if ($this->has($key)) {
            return $this->values[$key];
        }
        if ($default !== null) {
            return $default;
        }

        throw new ConfigException(sprintf('Missing required configuration: %s', $key));
    }

    public function int(string $key, ?int $default = null): int
    {
        if (!$this->has($key)) {
            return $default ?? throw new ConfigException(sprintf('Missing required configuration: %s', $key));
        }

        $value = filter_var($this->values[$key], FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new ConfigException(sprintf('Configuration %s must be an integer', $key));
        }

        return $value;
    }

    public function bool(string $key, bool $default): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = filter_var($this->values[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new ConfigException(sprintf('Configuration %s must be true or false', $key));
        }

        return $value;
    }

    /**
     * @param list<string> $keys
     */
    public function assertPresent(array $keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $key): bool => !$this->has($key)));
        if ($missing !== []) {
            throw new ConfigException(sprintf('Missing required configuration: %s', implode(', ', $missing)));
        }
    }

    /**
     * @return array<string, string>
     */
    public function masked(): array
    {
        $masked = [];
        foreach ($this->values as $key => $value) {
            $masked[$key] = $value !== '' && preg_match(self::SECRET_PATTERN, $key) === 1 ? '***' : $value;
        }
        ksort($masked);

        return $masked;
    }
}
