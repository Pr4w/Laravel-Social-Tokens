<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

beforeEach(function () {
    FakeConnector::reset();
    $this->tokens = app(SocialTokens::class);
});

function fakeCredential(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'fake',
        'provider_holder_id' => 'h-'.uniqid(),
        'access_token' => 'current-token',
        'refresh_token' => 'refresh',
        'status' => AccountStatus::Active,
        'expires_at' => now()->subMinute(), // expired
    ], $attrs));
}

function accountFor(SocialToken $token, array $attrs = []): SocialAccount
{
    return SocialAccount::create(array_merge([
        'provider' => 'fake',
        'provider_user_id' => 'a-'.uniqid(),
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ], $attrs));
}

// renewCredential ----------------------------------------------------------

it('renews an expired credential and applies the result', function () {
    $token = fakeCredential();
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'brand-new', expiresAt: now()->addHour());

    $result = $this->tokens->renewCredential($token);

    expect($result->succeeded())->toBeTrue()
        ->and(FakeConnector::$renewCalls)->toBe(1)
        ->and($token->fresh()->access_token)->toBe('brand-new')
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);
});

it('renews a credential whose renewal window has opened, before it expires', function () {
    Event::fake([CredentialRenewed::class]);
    $token = fakeCredential(['expires_at' => now()->addDays(6), 'renew_at' => now()->subMinute()]);
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'extended', expiresAt: now()->addDays(60));

    $result = $this->tokens->renewCredential($token);

    $fresh = $token->fresh();

    expect($result->succeeded())->toBeTrue()
        ->and(FakeConnector::$renewCalls)->toBe(1)
        ->and($fresh->access_token)->toBe('extended')
        ->and($fresh->expires_at->greaterThan(now()->addDays(59)))->toBeTrue()
        ->and($fresh->renew_at->isFuture())->toBeTrue();

    Event::assertDispatchedTimes(CredentialRenewed::class, 1);
});

it('short-circuits under the lock when another process renewed in the meantime', function () {
    $token = fakeCredential(['expires_at' => now()->addDays(6), 'renew_at' => now()->subMinute()]);

    // Another process renews while this one waits for the lock: $token is now stale.
    SocialToken::find($token->getKey())->update([
        'access_token' => 'renewed-elsewhere',
        'expires_at' => now()->addDays(60),
        'renew_at' => now()->addDays(53),
    ]);

    $result = $this->tokens->renewCredential($token);

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('renewed-elsewhere')
        ->and(FakeConnector::$renewCalls)->toBe(0); // provider never called
});

it('never calls the provider for a static credential', function () {
    $token = fakeCredential(['expires_at' => null, 'renew_at' => null, 'access_token' => 'page-token']);

    $result = $this->tokens->renewCredential($token);

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('page-token')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('returns a transient failure without touching the credential', function () {
    $token = fakeCredential();
    FakeConnector::$nextResult = RenewalResult::transientFailure('provider 500');

    $result = $this->tokens->renewCredential($token);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($token->fresh()->access_token)->toBe('current-token')
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);
});

it('logs an uncatalogued renewal error', function () {
    Log::spy();
    FakeConnector::$nextResult = RenewalResult::unknownFailure('weird', ['error' => 'weird']);

    $this->tokens->renewCredential(fakeCredential());

    Log::shouldHaveReceived('error')->withArgs(
        fn ($message) => str_contains($message, 'Uncatalogued renewal error')
    )->once();
});

it('does not log unknown errors when disabled', function () {
    config()->set('social-tokens.log_unknown_errors', false);
    Log::spy();
    FakeConnector::$nextResult = RenewalResult::unknownFailure('weird');

    $this->tokens->renewCredential(fakeCredential());

    Log::shouldNotHaveReceived('error');
});

// validAccessTokenFor ------------------------------------------------------

it('returns the credential token without renewing when not expired', function () {
    $token = fakeCredential(['expires_at' => now()->addHour(), 'access_token' => 'still-good']);

    expect($this->tokens->validAccessTokenFor(accountFor($token)))->toBe('still-good')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('returns a static credential token as-is (renew_at null, never expires)', function () {
    $token = fakeCredential(['expires_at' => null, 'renew_at' => null, 'access_token' => 'page-token']);

    expect($this->tokens->validAccessTokenFor(accountFor($token)))->toBe('page-token')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('renews the credential synchronously when the token is expired', function () {
    $token = fakeCredential();
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'synced', expiresAt: now()->addHour());

    expect($this->tokens->validAccessTokenFor(accountFor($token)))->toBe('synced');
});

it('throws when the account is not usable', function () {
    $token = fakeCredential();

    $this->tokens->validAccessTokenFor(accountFor($token, ['status' => AccountStatus::NeedsReconnect]));
})->throws(NeedsReconnectException::class);

it('throws when the credential is not usable', function () {
    $token = fakeCredential(['status' => AccountStatus::NeedsReconnect]);

    $this->tokens->validAccessTokenFor(accountFor($token));
})->throws(NeedsReconnectException::class);

it('throws when the account has no credential', function () {
    $account = SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'orphan', 'status' => AccountStatus::Active]);

    $this->tokens->validAccessTokenFor($account);
})->throws(NeedsReconnectException::class);

it('flags the credential when the provider cannot renew unattended', function () {
    FakeConnector::$strategy = RenewalStrategy::ReauthOnly;
    $token = fakeCredential();

    try {
        $this->tokens->validAccessTokenFor(accountFor($token));
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException) {
        expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
            ->and(FakeConnector::$renewCalls)->toBe(0);
    }
});

it('flags the credential when the refresh token has expired', function () {
    $token = fakeCredential(['refresh_expires_at' => now()->subDay()]);

    try {
        $this->tokens->validAccessTokenFor(accountFor($token));
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException) {
        expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    }
});

it('flags the credential on a terminal renewal failure', function () {
    $token = fakeCredential();
    FakeConnector::$nextResult = RenewalResult::terminalFailure('revoked');

    try {
        $this->tokens->validAccessTokenFor(accountFor($token));
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException $e) {
        expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
            ->and($e->transient)->toBeFalse();
    }
});

it('leaves the credential usable on a transient failure but still throws', function () {
    $token = fakeCredential();
    FakeConnector::$nextResult = RenewalResult::transientFailure('temporary');

    try {
        $this->tokens->validAccessTokenFor(accountFor($token));
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException $e) {
        expect($token->fresh()->status)->toBe(AccountStatus::Active) // still retryable
            ->and($e->transient)->toBeTrue()
            ->and($e->getMessage())->toBe('temporary');
    }
});

it('marks an uncatalogued renewal error as transient', function () {
    config()->set('social-tokens.log_unknown_errors', false);
    FakeConnector::$nextResult = RenewalResult::unknownFailure('weird');

    try {
        $this->tokens->validAccessTokenFor(accountFor(fakeCredential()));
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException $e) {
        expect($e->transient)->toBeTrue();
    }
});

it('is not transient when the account or credential is already flagged', function (string $flagged) {
    $token = fakeCredential($flagged === 'credential' ? ['status' => AccountStatus::NeedsReconnect] : []);
    $account = accountFor($token, $flagged === 'account' ? ['status' => AccountStatus::NeedsReconnect] : []);

    try {
        $this->tokens->validAccessTokenFor($account);
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException $e) {
        expect($e->transient)->toBeFalse();
    }
})->with(['account', 'credential']);

it('exposes the connector for a provider', function () {
    expect($this->tokens->connector('fake'))->toBeInstanceOf(FakeConnector::class);
});
