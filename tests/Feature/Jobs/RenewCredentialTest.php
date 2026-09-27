<?php

use Illuminate\Support\Facades\Event;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Events\CredentialExpiringSoon;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
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

it('warns a reauth-only credential ahead of expiry but keeps it usable', function () {
    Event::fake([CredentialExpiringSoon::class, CredentialNeedsReconnect::class]);
    FakeConnector::$strategy = RenewalStrategy::ReauthOnly;
    $token = jobCredential(['expires_at' => now()->addDays(4), 'renew_at' => now()->subMinute()]);
    $account = SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'a-'.uniqid(), 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);

    runJob($token);

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->renew_at->equalTo($fresh->expires_at))->toBeTrue() // next pass lands at expiry
        ->and(FakeConnector::$renewCalls)->toBe(0)
        ->and(app(SocialTokens::class)->validAccessTokenFor($account))->toBe('token'); // still posts

    Event::assertDispatchedTimes(CredentialExpiringSoon::class, 1);
    Event::assertDispatched(CredentialExpiringSoon::class, fn ($event) => $event->token->is($token)
        && $event->expiresAt->equalTo($fresh->expires_at));
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('does not warn twice before expiry', function () {
    Event::fake([CredentialExpiringSoon::class]);
    FakeConnector::$strategy = RenewalStrategy::ReauthOnly;
    $token = jobCredential(['expires_at' => now()->addDays(4), 'renew_at' => now()->subMinute()]);

    runJob($token);
    runJob($token); // e.g. a duplicate dispatch

    Event::assertDispatchedTimes(CredentialExpiringSoon::class, 1);
});

it('flags a reauth-only credential once it has actually expired', function () {
    FakeConnector::$strategy = RenewalStrategy::ReauthOnly;
    $token = jobCredential(['expires_at' => now()->addDays(4), 'renew_at' => now()->subMinute()]);

    runJob($token);
    $this->travelTo(now()->addDays(4)->addMinute());
    runJob($token);

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($token->fresh()->last_error)->toBe('Provider requires manual re-authorisation.');
});

it('warns rather than flags when the refresh token died but the access token still works', function () {
    Event::fake([CredentialExpiringSoon::class]);
    $token = jobCredential([
        'expires_at' => now()->addDays(4),
        'renew_at' => now()->subMinute(),
        'refresh_expires_at' => now()->subDay(),
    ]);

    runJob($token);

    expect($token->fresh()->status)->toBe(AccountStatus::Active)
        ->and(FakeConnector::$renewCalls)->toBe(0);

    Event::assertDispatched(CredentialExpiringSoon::class, fn ($event) => $event->reason === 'Refresh token has expired.');
});

it('renews successfully', function () {
    $token = jobCredential();
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'fresh', expiresAt: now()->addHour());

    runJob($token);

    expect($token->fresh()->status)->toBe(AccountStatus::Active)
        ->and($token->fresh()->access_token)->toBe('fresh');
});

it('renews a credential that is due but not yet expired', function () {
    $token = jobCredential(['expires_at' => now()->addDays(6), 'renew_at' => now()->subMinute()]);
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'extended', expiresAt: now()->addDays(60));

    runJob($token);

    expect(FakeConnector::$renewCalls)->toBe(1)
        ->and($token->fresh()->access_token)->toBe('extended')
        ->and($token->fresh()->renew_at->isFuture())->toBeTrue()
        ->and(SocialToken::query()->dueForRenewal()->count())->toBe(0);
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
