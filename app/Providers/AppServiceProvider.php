<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use App\Http\Middleware\AuthenticateApiKey;
use App\Integrations\Notion\NotionApi;
use App\Integrations\Telegram\TelegramApi;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\Telegram\TelegramChannel;
use App\Notifications\Text\TextSplitter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelegramApi::class, fn (Application $app): TelegramApi => new TelegramApi(
            $app->make(Factory::class),
            (string) config('notifications.channels.telegram.api_base'),
            (string) config('notifications.channels.telegram.bot_token'),
        ));

        $this->app->singleton(NotionApi::class, fn (Application $app): NotionApi => new NotionApi(
            $app->make(Factory::class),
            (string) config('notifications.notion.api_base'),
            (string) config('notifications.notion.token'),
            (string) config('notifications.notion.version'),
        ));

        $this->app->bind(TextSplitter::class, fn (): TextSplitter => new TextSplitter(3500));

        $this->app->singleton(ChannelRegistry::class, fn (Application $app): ChannelRegistry => new ChannelRegistry(
            array_map(fn (string $class) => $app->make($class), self::CHANNELS),
        ));
    }

    /** @return list<class-string<\App\Notifications\Channels\NotificationChannel>> */
    public const CHANNELS = [
        TelegramChannel::class,
    ];

    public function boot(): void
    {
        RateLimiter::for('api-key', fn (Request $request): Limit => Limit::perMinute(max(1, (int) config('notifications.rate_limit_per_minute')))
            ->by('api-key:' . AuthenticateApiKey::keyFrom($request)->id));
    }
}
