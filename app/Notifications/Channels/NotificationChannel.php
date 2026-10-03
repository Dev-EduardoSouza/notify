<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

/**
 * A delivery channel (Telegram today; WhatsApp or e-mail later).
 *
 * Adding a channel means implementing this interface and registering it in
 * ChannelRegistry. Nothing in the core changes.
 */
interface NotificationChannel
{
    /**
     * Name used in the API ("telegram") and in the config key notifications.channels.<name>.
     */
    public function name(): string;

    /**
     * Formats this channel accepts, e.g. ["plain", "html"].
     *
     * @return list<string>
     */
    public function formats(): array;

    /**
     * Sends the message starting at part $fromPart (0-based), so a retry resumes
     * where the previous attempt stopped. $onPartDelivered is called with the
     * number of parts delivered so far after each part, letting the caller
     * persist progress even if a later part fails.
     *
     * @param callable(int $deliveredParts): void $onPartDelivered
     */
    public function send(OutgoingMessage $message, int $fromPart, callable $onPartDelivered): SendResult;
}
