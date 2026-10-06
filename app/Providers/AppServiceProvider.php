<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\ClientKeyRegistry;
use App\Http\Middleware\AuthenticateApiKey;
use App\Integrations\Telegram\TelegramApi;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\NotificationChannel;
use App\Notifications\Channels\Telegram\TelegramChannel;
use App\Notifications\Text\TextSplitter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** @var list<class-string<NotificationChannel>> */
    public const CHANNELS = [
        TelegramChannel::class,
    ];

    public function register(): void
    {
        $this->app->singleton(TelegramApi::class, fn (Application $app): TelegramApi => new TelegramApi(
            $app->make(Factory::class),
            (string) config('notifications.channels.telegram.api_base'),
            (string) config('notifications.channels.telegram.bot_token'),
        ));

        $this->app->singleton(ClientKeyRegistry::class, fn (): ClientKeyRegistry => new ClientKeyRegistry((string) config('notifications.api_keys')));

        $this->app->bind(TextSplitter::class, fn (): TextSplitter => new TextSplitter(3500));

        $this->app->singleton(ChannelRegistry::class, fn (Application $app): ChannelRegistry => new ChannelRegistry(
            array_map(fn (string $class) => $app->make($class), self::CHANNELS),
        ));
    }

    public function boot(): void
    {
        RateLimiter::for('api-key', fn (Request $request): Limit => Limit::perMinute(max(1, (int) config('notifications.rate_limit_per_minute')))
            ->by('api-key:' . AuthenticateApiKey::keyFrom($request)->id));
    }
}
