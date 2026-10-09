<?php

namespace Pr4w\SocialTokens;

use Closure;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\ManagesFrequencies;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Pr4w\SocialTokens\Console\CheckStaticCredentials;
use Pr4w\SocialTokens\Console\DispatchDueRenewals;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use ReflectionMethod;

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
                CheckStaticCredentials::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            // Read here, not in boot(): each schedule:run resolves a fresh
            // Schedule. A falsy frequency disables that entry (the command stays
            // available to schedule yourself).
            if ($frequency = $this->frequency('dispatch_schedule', 'everyFifteenMinutes')) {
                $frequency($schedule->command('social-tokens:dispatch-renewals'))
                    ->withoutOverlapping(10) // it only dispatches unique jobs: never hold the mutex for a day
                    ->onOneServer();
            }

            if ($checkFrequency = $this->frequency('check_static_schedule', 'daily')) {
                $checkFrequency($schedule->command('social-tokens:check-static'))
                    ->withoutOverlapping(120)
                    ->onOneServer()
                    ->runInBackground(); // N sequential provider calls must not delay the app's own tasks
            }
        });
    }

    /**
     * How to time a scheduled entry from its config value: a cron expression,
     * or a frequency method of the scheduler that takes no argument
     * ('everyFiveMinutes', 'hourly'). Null when the entry is disabled.
     *
     * Never throws. The host resolves its Schedule during every artisan
     * bootstrap as soon as routes/console.php or withSchedule() uses it, so an
     * exception here would stop queue:work and migrate too. A value that cannot
     * be applied logs an error and keeps the default rather than dropping the
     * entry: a missing dispatcher lets every renewable token expire.
     *
     * @return (Closure(Event): Event)|null
     */
    protected function frequency(string $key, string $default): ?Closure
    {
        $value = config("social-tokens.{$key}", $default);

        if (! $value) {
            return null;
        }

        if (is_string($value) && $this->isFrequencyMethod($value)) {
            return fn (Event $event) => $event->{$value}();
        }

        if (is_string($value) && CronExpression::isValidExpression($value)) {
            return fn (Event $event) => $event->cron($value);
        }

        Log::error("[social-tokens] social-tokens.{$key} must be a cron expression or a scheduler frequency method that takes no argument; using the default, {$default}.", [
            'value' => is_scalar($value) ? $value : get_debug_type($value),
        ]);

        return fn (Event $event) => $event->{$default}();
    }

    protected function isFrequencyMethod(string $name): bool
    {
        if (! method_exists(ManagesFrequencies::class, $name)) {
            return false;
        }

        $method = new ReflectionMethod(ManagesFrequencies::class, $name);

        return $method->isPublic() && $method->getNumberOfRequiredParameters() === 0;
    }
}
