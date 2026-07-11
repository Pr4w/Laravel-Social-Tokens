<?php

namespace Pr4w\SocialTokens\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Pr4w\SocialTokens\SocialTokensServiceProvider;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run the package migrations (and the test fixtures) against the
        // in-memory sqlite database by invoking each migration directly.
        (include __DIR__.'/../database/migrations/2025_01_01_000000_create_social_accounts_table.php')->up();
        (include __DIR__.'/../database/migrations/2025_01_01_000001_add_provider_holder_id_to_social_accounts.php')->up();
        (include __DIR__.'/Fixtures/database/migrations/0000_00_00_000000_create_owners_table.php')->up();
    }

    protected function getPackageProviders($app): array
    {
        return [SocialTokensServiceProvider::class];
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
        foreach (['facebook', 'threads', 'tiktok', 'google', 'linkedin'] as $service) {
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
