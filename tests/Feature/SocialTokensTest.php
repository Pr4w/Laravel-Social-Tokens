<?php

use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

beforeEach(function () {
    FakeConnector::reset();
    $this->tokens = app(SocialTokens::class);
});

function fakeAccount(array $attrs = []): SocialAccount
{
    return SocialAccount::create(array_merge([
        'provider' => 'fake',
        'provider_user_id' => 'f-'.uniqid(),
        'access_token' => 'current-token',
        'refresh_token' => 'refresh',
        'status' => AccountStatus::Active,
        'expires_at' => now()->subMinute(), // expired
    ], $attrs));
}

it('renews an expired account and applies the result', function () {
    $account = fakeAccount();
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'brand-new', expiresAt: now()->addHour());

    $result = $this->tokens->renew($account);

    expect($result->succeeded())->toBeTrue()
        ->and(FakeConnector::$renewCalls)->toBe(1)
        ->and($account->fresh()->access_token)->toBe('brand-new')
        ->and($account->fresh()->status)->toBe(AccountStatus::Active);
});

it('short-circuits under the lock when the token was already renewed', function () {
    $account = fakeAccount(['expires_at' => now()->addHour()]); // not expired

    $result = $this->tokens->renew($account);

    expect($result->succeeded())->toBeTrue()
        ->and(FakeConnector::$renewCalls)->toBe(0); // provider never called
});

it('returns a transient failure without touching the account', function () {
    $account = fakeAccount();
    FakeConnector::$nextResult = RenewalResult::transientFailure('provider 500');

    $result = $this->tokens->renew($account);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($account->fresh()->access_token)->toBe('current-token')
        ->and($account->fresh()->status)->toBe(AccountStatus::Active);
});

it('logs an uncatalogued renewal error', function () {
    Log::spy();
    $account = fakeAccount();
    FakeConnector::$nextResult = RenewalResult::unknownFailure('weird', ['error' => 'weird']);

    $this->tokens->renew($account);

    Log::shouldHaveReceived('error')->withArgs(
        fn ($message) => str_contains($message, 'Uncatalogued renewal error')
    )->once();
});

it('does not log unknown errors when disabled', function () {
    config()->set('social-tokens.log_unknown_errors', false);
    Log::spy();
    FakeConnector::$nextResult = RenewalResult::unknownFailure('weird');

    $this->tokens->renew(fakeAccount());

    Log::shouldNotHaveReceived('error');
});

it('returns a valid token without renewing when not expired', function () {
    $account = fakeAccount(['expires_at' => now()->addHour(), 'access_token' => 'still-good']);

    expect($this->tokens->validAccessTokenFor($account))->toBe('still-good')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('renews synchronously when the token is expired', function () {
    $account = fakeAccount();
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'synced', expiresAt: now()->addHour());

    expect($this->tokens->validAccessTokenFor($account))->toBe('synced');
});

it('throws when the account is not usable', function () {
    $account = fakeAccount(['status' => AccountStatus::NeedsReconnect]);

    $this->tokens->validAccessTokenFor($account);
})->throws(NeedsReconnectException::class);

it('flags for reconnect when the provider cannot renew unattended', function () {
    FakeConnector::$strategy = RenewalStrategy::ReauthOnly;
    $account = fakeAccount();

    try {
        $this->tokens->validAccessTokenFor($account);
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException) {
        expect($account->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
            ->and(FakeConnector::$renewCalls)->toBe(0);
    }
});

it('flags for reconnect when the refresh token has expired', function () {
    $account = fakeAccount(['refresh_expires_at' => now()->subDay()]);

    try {
        $this->tokens->validAccessTokenFor($account);
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException) {
        expect($account->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    }
});

it('flags for reconnect on a terminal renewal failure', function () {
    $account = fakeAccount();
    FakeConnector::$nextResult = RenewalResult::terminalFailure('revoked');

    try {
        $this->tokens->validAccessTokenFor($account);
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException) {
        expect($account->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    }
});

it('leaves the account usable on a transient failure but still throws', function () {
    $account = fakeAccount();
    FakeConnector::$nextResult = RenewalResult::transientFailure('temporary');

    try {
        $this->tokens->validAccessTokenFor($account);
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException) {
        expect($account->fresh()->status)->toBe(AccountStatus::Active); // still retryable
    }
});

it('exposes the connector for a provider', function () {
    expect($this->tokens->connector('fake'))->toBeInstanceOf(FakeConnector::class);
});
