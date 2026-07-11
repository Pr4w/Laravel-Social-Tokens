<?php

use Illuminate\Console\Scheduling\Schedule;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

it('merges the package config', function () {
    expect(config('social-tokens.table'))->toBe('social_accounts')
        ->and(config('social-tokens.connectors.tiktok.driver'))->not->toBeNull();
});

it('binds SocialTokens as a singleton with an alias', function () {
    expect(app(SocialTokens::class))->toBeInstanceOf(SocialTokens::class)
        ->and(app(SocialTokens::class))->toBe(app(SocialTokens::class))
        ->and(app('social-tokens'))->toBe(app(SocialTokens::class));
});

it('binds the connector registry as a singleton', function () {
    expect(app(ConnectorRegistry::class))->toBe(app(ConnectorRegistry::class));
});

it('registers the dispatch command', function () {
    expect(array_keys($this->app[\Illuminate\Contracts\Console\Kernel::class]->all()))
        ->toContain('social-tokens:dispatch-renewals');
});

it('schedules the dispatch command', function () {
    $schedule = app(Schedule::class);

    $scheduled = collect($schedule->events())->contains(
        fn ($event) => str_contains($event->command ?? '', 'social-tokens:dispatch-renewals')
    );

    expect($scheduled)->toBeTrue();
});
