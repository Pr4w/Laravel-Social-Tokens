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

it('accepts a cron expression, stored as its five fields', function (string $key, string $command, string $cron, string $expected) {
    config()->set("social-tokens.{$key}", $cron);

    expect(scheduleFrequencyExpressions($command))->toBe([$expected]);
})->with([
    'dispatcher' => ['dispatch_schedule', 'social-tokens:dispatch-renewals', '*/10 * * * *', '*/10 * * * *'],
    'static check' => ['check_static_schedule', 'social-tokens:check-static', '30 4 * * *', '30 4 * * *'],
    // schedule:list and its timezone conversion split the expression into five fields.
    'alias' => ['dispatch_schedule', 'social-tokens:dispatch-renewals', '@hourly', '0 * * * *'],
    'extra spaces' => ['check_static_schedule', 'social-tokens:check-static', ' 30  4 * * * ', '30 4 * * *'],
]);

it('accepts any frequency method that takes no argument', function (string $method, string $expected) {
    config()->set('social-tokens.dispatch_schedule', $method);

    expect(scheduleFrequencyExpressions('social-tokens:dispatch-renewals'))->toBe([$expected]);
})->with([
    'twiceDaily' => ['twiceDaily', '0 1,13 * * *'],
    'everyMinute' => ['everyMinute', '* * * * *'],
    'sub-minute' => ['everyThirtySeconds', '* * * * *'],
]);

it('lists the schedule when a value is a cron alias', function (array $config) {
    config()->set($config);
    app()->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);

    expect(Artisan::call('schedule:list'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('social-tokens:dispatch-renewals')
        ->toContain('social-tokens:check-static');
})->with([
    'static check alias' => [['social-tokens.check_static_schedule' => '@daily']],
    'dispatcher alias in another timezone' => [[
        'social-tokens.dispatch_schedule' => '@hourly',
        'app.timezone' => 'UTC',
        'app.schedule_timezone' => 'Europe/Paris',
    ]],
]);

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
    // They only pick the days: the command would run every minute of them.
    'weekdays' => ['weekdays'],
    'mondays' => ['mondays'],
    'weekends' => ['weekends'],
    'method whose argument is a list' => ['daysOfMonth'], // no days: an empty field
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
