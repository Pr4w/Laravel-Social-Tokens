<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Pr4w\SocialTokens\Connectors\TikTokConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
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

it('only counts a provider whose driver resolves to a connector', function () {
    Log::spy();

    $registry = new ConnectorRegistry([
        'tiktok' => ['driver' => TikTokConnector::class],
        'renamed' => ['driver' => 'App\\Connectors\\OldName'],
        'not-a-connector' => ['driver' => stdClass::class],
        'number' => ['driver' => 42],
        'empty' => ['driver' => null],
    ]);

    expect($registry->has('tiktok'))->toBeTrue()
        ->and($registry->has('renamed'))->toBeFalse()
        ->and($registry->has('not-a-connector'))->toBeFalse()
        ->and($registry->has('number'))->toBeFalse()
        ->and($registry->has('empty'))->toBeFalse()
        ->and($registry->configured())->toBe(['tiktok']);

    // Asked again: logged once per provider, not on every call.
    $registry->has('renamed');
    $registry->configured();

    Log::shouldHaveReceived('error')->times(3);
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $context['provider'] === 'renamed'
        && $context['driver'] === 'App\\Connectors\\OldName')->once();
});

it('still refuses to resolve a driver that is not a connector', function () {
    $registry = new ConnectorRegistry([
        'not-a-connector' => ['driver' => stdClass::class],
        'number' => ['driver' => 42],
    ]);

    expect(fn () => $registry->for('not-a-connector'))->toThrow(InvalidArgumentException::class, 'must implement ProviderConnector')
        ->and(fn () => $registry->for('number'))->toThrow(InvalidArgumentException::class, 'No connector driver implemented');
});

/** Point the `fake` provider at a class that does not exist, as a stale config would. */
function registryBreakFakeDriver(): void
{
    config()->set('social-tokens.connectors.fake.driver', 'App\\Connectors\\RenamedConnector');
    app()->forgetInstance(ConnectorRegistry::class);
    app()->forgetInstance(SocialTokens::class);
}

function registryCredential(array $attrs = []): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => 'fake',
        'provider_holder_id' => 'h-'.uniqid(),
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'status' => AccountStatus::Active,
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subHour(),
    ], $attrs));

    SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'a-'.uniqid(), 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);

    return $token;
}

it('treats a driver that does not resolve like a missing connector', function () {
    registryBreakFakeDriver();
    Queue::fake();

    $token = registryCredential();
    $account = $token->accounts()->sole();

    // The publishing layer gets the documented transient exception.
    $thrown = null;

    try {
        app(SocialTokens::class)->validAccessTokenFor($account);
    } catch (NeedsReconnectException $e) {
        $thrown = $e;
    }

    expect($thrown?->transient)->toBeTrue();

    // The dispatcher skips the provider instead of queueing doomed jobs.
    $this->artisan('social-tokens:dispatch-renewals')
        ->expectsOutputToContain('Skipped due credentials for provider [fake]')
        ->assertSuccessful();

    Queue::assertNothingPushed();

    // A job queued before the config changed ends quietly, and failed() does
    // not flag the expired credential.
    (new RenewCredential($token))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
    (new RenewCredential($token))->failed(new RuntimeException('gave up'));

    expect($token->fresh()->status)->toBe(AccountStatus::Active);
});

it('disconnects an account whose provider driver does not resolve', function () {
    registryBreakFakeDriver();

    $token = registryCredential(['expires_at' => now()->addHour(), 'renew_at' => now()->addMinutes(30)]);
    $account = $token->accounts()->sole();

    app(SocialTokens::class)->disconnect($account);

    expect($account->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($token->fresh()->status)->toBe(AccountStatus::Revoked);
});
