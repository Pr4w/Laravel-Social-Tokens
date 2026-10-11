<?php

namespace Pr4w\SocialTokens;

use Closure;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\ManagesFrequencies;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Pr4w\SocialTokens\Console\CheckStaticCredentials;
use Pr4w\SocialTokens\Console\DispatchDueRenewals;
use Pr4w\SocialTokens\Console\PruneCredentials;
use Pr4w\SocialTokens\Console\ReencryptTokens;
use Pr4w\SocialTokens\Support\CallbackRoutes;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\Settings;
use ReflectionMethod;
use Throwable;

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
                PruneCredentials::class,
                ReencryptTokens::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerCallbackRoutes();

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

            // Off unless the app chooses a schedule: pruning deletes rows.
            if ($pruneFrequency = $this->frequency('prune_schedule', null)) {
                $pruneFrequency($schedule->command('social-tokens:prune'))
                    ->withoutOverlapping(60)
                    ->onOneServer();
            }
        });
    }

    /**
     * The Meta deauthorize and data deletion callbacks, only when
     * `callbacks.enabled` is on (off by default): a package should not answer
     * on an app's URLs unasked. Cached routes already hold them.
     */
    protected function registerCallbackRoutes(): void
    {
        if (Settings::callbacks()['enabled'] && ! $this->app->routesAreCached()) {
            CallbackRoutes::register();
        }
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
     * entry: a missing dispatcher lets every renewable token expire. An entry
     * off by default ($default null) stays off.
     *
     * @return (Closure(Event): Event)|null
     */
    protected function frequency(string $key, ?string $default): ?Closure
    {
        $value = config("social-tokens.{$key}", $default);

        if (! $value) {
            return null;
        }

        if (is_string($value) && $this->isFrequencyMethod($value)) {
            return fn (Event $event) => $event->{$value}();
        }

        if (is_string($value) && ($cron = $this->cronExpression($value)) !== null) {
            return fn (Event $event) => $event->cron($cron);
        }

        Log::error('[social-tokens] social-tokens.'.$key.' must be a cron expression or a scheduler frequency method that takes no argument; '
            .($default === null ? 'not scheduling it.' : "using the default, {$default}."), [
                'value' => is_scalar($value) ? $value : get_debug_type($value),
            ]);

        return $default === null ? null : fn (Event $event) => $event->{$default}();
    }

    /**
     * A public method of the scheduler that takes no argument and sets how
     * often the command runs. It is tried on a probe first: 'weekdays' or
     * 'mondays' only pick the days, and would run the command every minute
     * of them, so a method that leaves the minute at '*' counts only when
     * every minute, or more often, is what it means. 'daysOfMonth' without
     * its days leaves a field empty, which no cron parser reads.
     */
    protected function isFrequencyMethod(string $name): bool
    {
        if (! method_exists(ManagesFrequencies::class, $name)) {
            return false;
        }

        $method = new ReflectionMethod(ManagesFrequencies::class, $name);

        if (! $method->isPublic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        try {
            $probe = new Event($this->app->make(CacheEventMutex::class), 'social-tokens:probe');
            $probe->{$method->name}();
        } catch (Throwable) {
            return false;
        }

        if ($this->cronExpression($probe->expression) !== $probe->expression) {
            return false;
        }

        return ! str_starts_with($probe->expression, '* ')
            || $method->name === 'everyMinute'
            || $probe->isRepeatable();
    }

    /**
     * The five fields of a cron expression, or null when the value is not one.
     * An alias ('@hourly') is expanded and extra spaces dropped: schedule:list
     * and its timezone conversion split every expression into five fields, and
     * crash on an alias.
     */
    protected function cronExpression(string $value): ?string
    {
        try {
            return (new CronExpression($value))->getExpression();
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
