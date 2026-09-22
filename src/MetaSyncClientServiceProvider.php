<?php

namespace MetaSyncClient;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MetaSyncClient\Commands\PullCommand;
use MetaSyncClient\Commands\PushCommand;
use MetaSyncClient\Commands\SyncCommand;
use MetaSyncClient\Commands\WebhookCommand;
use MetaSyncClient\Http\IndexNowKeyController;
use MetaSyncClient\Http\WebhookController;
use MetaSyncClient\Middleware\HandleRedirects;

class MetaSyncClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/metasync-client.php', 'metasync-client');

        $this->app->singleton(ApiClient::class);
        $this->app->singleton(SyncService::class);
        $this->app->singleton(MetaResolver::class);
        $this->app->singleton(IndexNowKey::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/metasync-client.php' => config_path('metasync-client.php'),
        ], 'metasync-client-config');

        Route::post((string) config('metasync-client.webhook_path'), WebhookController::class)
            ->middleware('api')
            ->name('metasync.webhook');

        // IndexNow ownership check: MetaSync issues 32 hex character keys, so
        // the pattern never shadows robots.txt, security.txt and the like.
        if (config('metasync-client.indexnow_enabled')) {
            Route::get('{key}.txt', IndexNowKeyController::class)
                ->where('key', '[a-f0-9]{32}')
                ->name('metasync.indexnow-key');
        }

        Blade::directive('metasyncHead', fn (): string => "<?php echo app(\MetaSyncClient\MetaResolver::class)->head(); ?>");

        if (config('metasync-client.redirects_enabled')) {
            $this->app->make(Kernel::class)->pushMiddleware(HandleRedirects::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                PushCommand::class,
                PullCommand::class,
                SyncCommand::class,
                WebhookCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config('metasync-client.schedule_pull')) {
                $schedule->command('metasync:pull')
                    ->cron((string) config('metasync-client.schedule_cron'))
                    ->withoutOverlapping();
            }
        });
    }
}
