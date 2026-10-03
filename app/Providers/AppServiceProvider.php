<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integrations\Notion\NotionApi;
use App\Integrations\Telegram\TelegramApi;
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
    }

    public function boot(): void
    {
        //
    }
}
