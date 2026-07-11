<?php

use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Jobs\RenewAccountToken;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

beforeEach(fn () => FakeConnector::reset());

function jobAccount(array $attrs = []): SocialAccount
{
    return SocialAccount::create(array_merge([
        'provider' => 'fake',
        'provider_user_id' => 'j-'.uniqid(),
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'status' => AccountStatus::Active,
        'expires_at' => now()->subMinute(),
    ], $attrs));
}

function runJob(SocialAccount $account): void
{
    (new RenewAccountToken($account))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
}

it('skips accounts that are no longer usable', function () {
    $account = jobAccount(['status' => AccountStatus::NeedsReconnect]);

    runJob($account);

    expect(FakeConnector::$renewCalls)->toBe(0);
});

it('flags reauth-only providers without calling the provider', function () {
    FakeConnector::$strategy = RenewalStrategy::ReauthOnly;
    $account = jobAccount();

    runJob($account);

    expect($account->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($account->fresh()->last_error)->toBe('Provider requires manual re-authorisation.')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('flags an expired refresh token without calling the provider', function () {
    $account = jobAccount(['refresh_expires_at' => now()->subDay()]);

    runJob($account);

    expect($account->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($account->fresh()->last_error)->toBe('Refresh token has expired.')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('renews successfully', function () {
    $account = jobAccount();
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'fresh', expiresAt: now()->addHour());

    runJob($account);

    expect($account->fresh()->status)->toBe(AccountStatus::Active)
        ->and($account->fresh()->access_token)->toBe('fresh');
});

it('flags a terminal failure for reconnection', function () {
    $account = jobAccount();
    FakeConnector::$nextResult = RenewalResult::terminalFailure('revoked');

    runJob($account);

    expect($account->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($account->fresh()->last_error)->toBe('revoked');
});

it('throws on a transient failure so the queue retries', function () {
    $account = jobAccount();
    FakeConnector::$nextResult = RenewalResult::transientFailure('provider 500');

    runJob($account);
})->throws(RuntimeException::class, 'Transient renewal failure');

it('escalates to needs_reconnect after the final attempt when the token has expired', function () {
    $account = jobAccount(['expires_at' => now()->subMinute()]);

    (new RenewAccountToken($account))->failed(new Exception('gave up'));

    expect($account->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($account->fresh()->last_error)->toContain('gave up');
});

it('leaves the account active after failure when the token is still valid', function () {
    $account = jobAccount(['expires_at' => now()->addHour()]);

    (new RenewAccountToken($account))->failed(new Exception('gave up'));

    expect($account->fresh()->status)->toBe(AccountStatus::Active);
});

it('is unique per account', function () {
    $account = jobAccount();

    expect((new RenewAccountToken($account))->uniqueId())->toBe('social-tokens-renew-'.$account->getKey());
});
