<?php

namespace Pr4w\SocialTokens;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Pr4w\SocialTokens\Console\DispatchDueRenewals;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

class SocialTokensServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/social-tokens.php', 'social-tokens');

        $this->app->singleton(ConnectorRegistry::class, function ($app) {
            return new ConnectorRegistry($app['config']->get('social-tokens.connectors', []));
        });

        $this->app->singleton(SocialTokens::class, function ($app) {
            return new SocialTokens($app->make(ConnectorRegistry::class));
        });

        $this->app->alias(SocialTokens::class, 'social-tokens');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/social-tokens.php' => config_path('social-tokens.php'),
            ], 'social-tokens-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'social-tokens-migrations');

            $this->commands([
                DispatchDueRenewals::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $frequency = config('social-tokens.dispatch_schedule', 'everyFifteenMinutes');

            $schedule->command('social-tokens:dispatch-renewals')
                ->{$frequency}()
                ->withoutOverlapping();
        });
    }
}
