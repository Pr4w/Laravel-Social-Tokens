<?php

use Pr4w\SocialTokens\Connectors\TikTokConnector;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

it('resolves a configured connector', function () {
    $registry = new ConnectorRegistry([
        'tiktok' => ['driver' => TikTokConnector::class],
    ]);

    expect($registry->for('tiktok'))->toBeInstanceOf(TikTokConnector::class);
});

it('caches the resolved instance for the request', function () {
    $registry = new ConnectorRegistry([
        'tiktok' => ['driver' => TikTokConnector::class],
    ]);

    expect($registry->for('tiktok'))->toBe($registry->for('tiktok'));
});

it('throws when the provider is not configured', function () {
    $registry = new ConnectorRegistry([]);

    $registry->for('nope');
})->throws(InvalidArgumentException::class, 'No connector configured for provider [nope].');

it('throws when the driver is missing or not a class', function () {
    $registry = new ConnectorRegistry([
        'tiktok' => ['driver' => null],
        'ghost' => ['driver' => 'Not\\A\\Real\\Class'],
    ]);

    expect(fn () => $registry->for('tiktok'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->for('ghost'))->toThrow(InvalidArgumentException::class);
});

it('reports whether a provider has a driver', function () {
    $registry = new ConnectorRegistry([
        'tiktok' => ['driver' => TikTokConnector::class],
        'empty' => ['driver' => null],
    ]);

    expect($registry->has('tiktok'))->toBeTrue()
        ->and($registry->has('empty'))->toBeFalse()
        ->and($registry->has('missing'))->toBeFalse();
});

it('lists only configured providers', function () {
    $registry = new ConnectorRegistry([
        'tiktok' => ['driver' => TikTokConnector::class],
        'empty' => ['driver' => null],
    ]);

    expect($registry->configured())->toBe(['tiktok']);
});

it('is bound as a singleton seeded from config', function () {
    expect($this->app->make(ConnectorRegistry::class))
        ->toBe($this->app->make(ConnectorRegistry::class))
        ->and($this->app->make(ConnectorRegistry::class)->has('facebook'))->toBeTrue();
});
