<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Messages name the offending variable and never include its value.
 */
final class ConfigException extends RuntimeException
{
}
