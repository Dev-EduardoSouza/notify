<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Notifications\DeliveryStatus;
use App\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class RetryNotificationsCommand extends Command
{
    public const BATCH_SIZE = 50;

    public const STALE_PENDING_MINUTES = 10;

    protected $signature = 'notifications:retry';

    protected $description = 'Retry deliveries that are due and purge old finished ones';

    public function handle(NotificationService $service): int
    {
        $now = Carbon::now();
        $tally = ['sent' => 0, 'retrying' => 0, 'failed' => 0];

        foreach ($this->dueDeliveries($now) as $delivery) {
            $status = $service->attempt($delivery)->status;
            if ($status === DeliveryStatus::Pending) {
                continue;
            }
            $tally[$status->value]++;
        }

        $purged = Delivery::query()
            ->whereIn('status', [DeliveryStatus::Sent, DeliveryStatus::Failed])
            ->where('updated_at', '<', $now->copy()->subDays((int) config('notifications.delivery_retention_days')))
            ->delete();

        $this->line(sprintf(
            'processed=%d sent=%d retrying=%d failed=%d purged=%d',
            array_sum($tally),
            $tally['sent'],
            $tally['retrying'],
            $tally['failed'],
            $purged,
        ));

        return self::SUCCESS;
    }

    /** @return iterable<Delivery> */
    private function dueDeliveries(Carbon $now): iterable
    {
        return Delivery::query()
            ->where(fn ($query) => $query
                ->where(fn ($due) => $due
                    ->where('status', DeliveryStatus::Retrying)
                    ->where('next_attempt_at', '<=', $now))
                ->orWhere(fn ($stale) => $stale
                    ->where('status', DeliveryStatus::Pending)
                    ->where('updated_at', '<', $now->copy()->subMinutes(self::STALE_PENDING_MINUTES))))
            ->orderBy('next_attempt_at')
            ->limit(self::BATCH_SIZE)
            ->get();
    }
}
