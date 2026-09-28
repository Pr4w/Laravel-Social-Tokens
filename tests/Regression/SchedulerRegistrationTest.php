<?php

/*
 * Regression: the schedule the package registers on the host application.
 *
 * SocialTokensServiceProvider::boot() adds its two commands to the host's
 * Schedule from an afterResolving(Schedule::class) callback, so that callback
 * runs inside every `php artisan schedule:run`, `schedule:list` and
 * `schedule:work` of the host application.
 *
 * In v1.1.0:
 *  - `dispatch_schedule` cannot be disabled. Unlike `check_static_schedule`, it
 *    has no null guard, so `dispatch_schedule => null` reaches
 *    `->{$frequency}()` and throws Error "Method name must be a string" while
 *    the Schedule is being resolved. The whole host scheduler dies with it
 *    (the application's own posting commands included), not just the package
 *    command;
 *  - neither event uses onOneServer(), so an app deployed on several servers
 *    can run the dispatcher and the daily Meta check once per server
 *    (withoutOverlapping() only stops two runs from overlapping; once the
 *    first server has finished, the next one runs the event again);
 *  - check-static runs in the foreground, so its N sequential provider calls
 *    hold up the events that run after it in the same scheduler tick, the
 *    host's own routes/console.php events included.
 *
 * These tests change the config, then resolve a fresh Schedule, as a new
 * `php artisan schedule:run` process would. They therefore expect the config
 * to be read inside the afterResolving callback (Laravel's documented pattern
 * for packages, and what v1.1.0 already does), not once in boot().
 *
 * Desired:
 *  - `dispatch_schedule => null` (or false) disables the dispatcher schedule,
 *    exactly like `check_static_schedule => null` disables the static check;
 *    the command itself stays registered so the host can schedule it itself;
 *  - both events keep withoutOverlapping() and add onOneServer();
 *  - check-static also runs in the background.
 */

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

/**
 * Drop the resolved Schedule so the next resolution runs the package's
 * afterResolving callback again against the current config, as a fresh
 * `php artisan schedule:run` process would.
 */
function schedulerRegistrationForgetSchedule(): void
{
    app()->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);
}

function schedulerRegistrationFreshSchedule(): Schedule
{
    schedulerRegistrationForgetSchedule();

    return app(Schedule::class);
}

/**
 * Run $callback and return the message of whatever it throws, or null.
 *
 * (Pest's not->toThrow(Throwable::class) cannot be used here: Throwable is an
 * interface, so Pest treats it as a message to look for and passes.)
 */
function schedulerRegistrationErrorFrom(Closure $callback): ?string
{
    try {
        $callback();
    } catch (Throwable $e) {
        return get_class($e).': '.$e->getMessage();
    }

    return null;
}

/**
 * The scheduled events whose command mentions $command.
 *
 * @return array<int, ScheduledEvent>
 */
function schedulerRegistrationEvents(Schedule $schedule, string $command): array
{
    return collect($schedule->events())
        ->filter(fn (ScheduledEvent $event) => str_contains($event->command ?? '', $command))
        ->values()
        ->all();
}

it('disables the renewal dispatcher schedule, and nothing else, when dispatch_schedule is disabled', function (mixed $disabled) {
    config()->set('social-tokens.dispatch_schedule', $disabled);

    expect(schedulerRegistrationErrorFrom(fn () => schedulerRegistrationFreshSchedule()))
        ->toBeNull();

    $schedule = app(Schedule::class);

    expect(schedulerRegistrationEvents($schedule, 'social-tokens:dispatch-renewals'))->toBeEmpty()
        ->and(schedulerRegistrationEvents($schedule, 'social-tokens:check-static'))->toHaveCount(1)
        // The host can still schedule the dispatcher itself.
        ->and(array_keys(app(Kernel::class)->all()))->toContain('social-tokens:dispatch-renewals');
})->with([
    'null' => [null],
    'false' => [false],
]);

it('keeps the host application schedule running when dispatch_schedule is null', function () {
    config()->set('social-tokens.dispatch_schedule', null);
    schedulerRegistrationForgetSchedule();

    // What a host's routes/console.php does, followed by `php artisan schedule:list`.
    $run = function () {
        ScheduleFacade::command('app:perform-posts')->everyMinute();

        return Artisan::call('schedule:list');
    };

    expect(schedulerRegistrationErrorFrom($run))->toBeNull();

    expect(Artisan::output())
        ->toContain('app:perform-posts')
        ->toContain('social-tokens:check-static')
        ->not->toContain('social-tokens:dispatch-renewals');
});

it('runs the renewal dispatcher on a single server, without overlapping', function () {
    $events = schedulerRegistrationEvents(
        schedulerRegistrationFreshSchedule(),
        'social-tokens:dispatch-renewals',
    );

    expect($events)->toHaveCount(1);

    [$event] = $events;

    expect($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue('dispatch-renewals can run on every server of a multi-server deployment.');
});

it('runs the daily static credential check on a single server, in the background, without overlapping', function () {
    $events = schedulerRegistrationEvents(
        schedulerRegistrationFreshSchedule(),
        'social-tokens:check-static',
    );

    expect($events)->toHaveCount(1);

    [$event] = $events;

    expect($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue('check-static can run on every server of a multi-server deployment.')
        ->and($event->runInBackground)->toBeTrue('check-static holds up the events that run after it in the same scheduler tick.');
});

it('honours a custom dispatch_schedule frequency', function () {
    config()->set('social-tokens.dispatch_schedule', 'everyFiveMinutes');

    $events = schedulerRegistrationEvents(
        schedulerRegistrationFreshSchedule(),
        'social-tokens:dispatch-renewals',
    );

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/5 * * * *');
});

it('disables only the static check when check_static_schedule is null', function () {
    config()->set('social-tokens.check_static_schedule', null);

    $schedule = schedulerRegistrationFreshSchedule();

    expect(schedulerRegistrationEvents($schedule, 'social-tokens:check-static'))->toBeEmpty()
        ->and(schedulerRegistrationEvents($schedule, 'social-tokens:dispatch-renewals'))->toHaveCount(1);
});
