<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Logging "tap": one JSON object per line, with secrets redacted.
 */
final class JsonLines
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if (!$monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true));
            }
        }

        $monolog->pushProcessor(new RedactSecretsProcessor());
    }
}
