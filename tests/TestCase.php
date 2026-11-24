<?php

namespace Pr4w\SocialTokens\Tests;

use Illuminate\Support\Facades\Http;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Pr4w\SocialTokens\SocialTokensServiceProvider;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

abstract class TestCase extends Orchestra
{
    /**
     * The package migrations (sorted by filename) and the test fixtures',
     * included once per process: each include of a migration file defines a
     * new anonymous class, which PHP never frees, and including them before
     * every test ran the suite out of memory. They hold no state.
     *
     * @var list<object>|null
     */
    private static ?array $migrations = null;

    protected function setUp(): void
    {
        parent::setUp();

        // A request no test faked must never reach a real provider: it would
        // make the suite depend on the network (and on Meta's mood).
        Http::preventStrayRequests();

        self::$migrations ??= [
            ...array_map(fn (string $migration) => include $migration, glob(__DIR__.'/../database/migrations/*.php') ?: []),
            include __DIR__.'/Fixtures/database/migrations/0000_00_00_000000_create_owners_table.php',
        ];

        // Against the in-memory sqlite database, fresh for each test.
        foreach (self::$migrations as $migration) {
            $migration->up();
        }
    }

    protected function getPackageProviders($app): array
    {
        // Socialite for the package's own driver (bluesky); an app gets it by discovery
        return [SocialiteServiceProvider::class, SocialTokensServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Locks used by SocialTokens::renew() need a lock-capable store.
        $app['config']->set('cache.default', 'array');

        // Credentials the connectors read from Socialite's services config.
        foreach (['facebook', 'instagram_login', 'threads', 'tiktok', 'google', 'linkedin', 'x', 'pinterest'] as $service) {
            $app['config']->set("services.{$service}.client_id", "{$service}-id");
            $app['config']->set("services.{$service}.client_secret", "{$service}-secret");
            $app['config']->set("services.{$service}.redirect", "https://app.test/oauth/{$service}/callback");
        }

        // A test-only connector whose behaviour is controlled per test.
        $app['config']->set('social-tokens.connectors.fake', [
            'driver' => FakeConnector::class,
        ]);
    }
}
