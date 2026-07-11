<?php

use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

beforeEach(fn () => FakeConnector::reset());

function jobCredential(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'fake',
        'provider_holder_id' => 'h-'.uniqid(),
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'status' => AccountStatus::Active,
        'expires_at' => now()->subMinute(),
    ], $attrs));
}

function runJob(SocialToken $token): void
{
    (new RenewCredential($token))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
}

it('skips credentials that are no longer usable', function () {
    $token = jobCredential(['status' => AccountStatus::NeedsReconnect]);

    runJob($token);

    expect(FakeConnector::$renewCalls)->toBe(0);
});

it('flags reauth-only providers without calling the provider', function () {
    FakeConnector::$strategy = RenewalStrategy::ReauthOnly;
    $token = jobCredential();

    runJob($token);

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($token->fresh()->last_error)->toBe('Provider requires manual re-authorisation.')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('flags an expired refresh token without calling the provider', function () {
    $token = jobCredential(['refresh_expires_at' => now()->subDay()]);

    runJob($token);

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($token->fresh()->last_error)->toBe('Refresh token has expired.')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('renews successfully', function () {
    $token = jobCredential();
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'fresh', expiresAt: now()->addHour());

    runJob($token);

    expect($token->fresh()->status)->toBe(AccountStatus::Active)
        ->and($token->fresh()->access_token)->toBe('fresh');
});

it('flags a terminal failure for reconnection', function () {
    $token = jobCredential();
    FakeConnector::$nextResult = RenewalResult::terminalFailure('revoked');

    runJob($token);

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($token->fresh()->last_error)->toBe('revoked');
});

it('throws on a transient failure so the queue retries', function () {
    $token = jobCredential();
    FakeConnector::$nextResult = RenewalResult::transientFailure('provider 500');

    runJob($token);
})->throws(RuntimeException::class, 'Transient renewal failure');

it('escalates to needs_reconnect after the final attempt when the token has expired', function () {
    $token = jobCredential(['expires_at' => now()->subMinute()]);

    (new RenewCredential($token))->failed(new Exception('gave up'));

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($token->fresh()->last_error)->toContain('gave up');
});

it('leaves the credential active after failure when the token is still valid', function () {
    $token = jobCredential(['expires_at' => now()->addHour()]);

    (new RenewCredential($token))->failed(new Exception('gave up'));

    expect($token->fresh()->status)->toBe(AccountStatus::Active);
});

it('is unique per credential', function () {
    $token = jobCredential();

    expect((new RenewCredential($token))->uniqueId())->toBe('social-tokens-renew-'.$token->getKey());
});
