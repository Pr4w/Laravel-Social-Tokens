<?php

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

/**
 * Resolve a fresh Schedule, as a new artisan process would, and return the
 * expressions of the events whose command mentions $command, or the error
 * the resolution threw.
 *
 * @return array<int, string>|string
 */
function scheduleFrequencyExpressions(string $command): array|string
{
    app()->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);

    try {
        $schedule = app(Schedule::class);
    } catch (Throwable $e) {
        return $e::class.': '.$e->getMessage();
    }

    return collect($schedule->events())
        ->filter(fn (ScheduledEvent $event) => str_contains($event->command ?? '', $command))
        ->map(fn (ScheduledEvent $event) => $event->expression)
        ->values()
        ->all();
}

it('accepts a cron expression', function (string $key, string $command, string $cron) {
    config()->set("social-tokens.{$key}", $cron);

    expect(scheduleFrequencyExpressions($command))->toBe([$cron]);
})->with([
    'dispatcher' => ['dispatch_schedule', 'social-tokens:dispatch-renewals', '*/10 * * * *'],
    'static check' => ['check_static_schedule', 'social-tokens:check-static', '30 4 * * *'],
    'alias' => ['dispatch_schedule', 'social-tokens:dispatch-renewals', '@hourly'],
]);

it('accepts any frequency method that takes no argument', function () {
    config()->set('social-tokens.dispatch_schedule', 'twiceDaily');

    expect(scheduleFrequencyExpressions('social-tokens:dispatch-renewals'))->toBe(['0 1,13 * * *']);
});

it('falls back to the default dispatcher schedule, and logs, for a value it cannot apply', function (mixed $value) {
    Log::spy();
    config()->set('social-tokens.dispatch_schedule', $value);

    expect(scheduleFrequencyExpressions('social-tokens:dispatch-renewals'))->toBe(['*/15 * * * *']);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message, $context) => str_contains($message, 'social-tokens.dispatch_schedule')
            && str_contains($message, 'everyFifteenMinutes'))
        ->atLeast()->once();
})->with([
    'misspelt method' => ['everyTenMinute'],
    'method needing an argument' => ['hourlyAt'],
    'method that is not a frequency' => ['runInBackground'],
    'invalid cron expression' => ['61 * * * *'],
    'not a string' => [true],
    'array' => [['daily']],
]);

it('falls back to the default static check schedule for a value it cannot apply', function () {
    Log::spy();
    config()->set('social-tokens.check_static_schedule', 'dailyAt');

    expect(scheduleFrequencyExpressions('social-tokens:check-static'))->toBe(['0 0 * * *']);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => str_contains($message, 'social-tokens.check_static_schedule'))
        ->atLeast()->once();
});

it('keeps every artisan command working when a schedule value is invalid', function () {
    config()->set('social-tokens.dispatch_schedule', '*/10 * * *'); // four fields
    app()->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);

    // A host's routes/console.php resolves the Schedule during every artisan
    // bootstrap, so a throwing callback would kill queue:work and migrate.
    ScheduleFacade::command('app:perform-posts')->everyMinute();

    expect(Artisan::call('schedule:list'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('app:perform-posts')
        ->toContain('social-tokens:dispatch-renewals');
});
