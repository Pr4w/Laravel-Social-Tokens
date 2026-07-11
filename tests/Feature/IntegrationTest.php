<?php

use Illuminate\Support\Facades\Event;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountRevoked;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

beforeEach(fn () => FakeConnector::reset());

function tokens(): SocialTokens
{
    return app(SocialTokens::class);
}

function dispatchRenewal(SocialToken $token): void
{
    (new RenewCredential($token->fresh()))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
}

// End-to-end -----------------------------------------------------------------

it('connects a provider, renews the credential, and serves the fresh token', function () {
    $accounts = app(StoreConnection::class)->handle('fake', socialiteUser([
        'id' => 'u-1', 'token' => 'initial', 'refreshToken' => 'r', 'expiresIn' => 3600,
    ]));

    $account = $accounts->sole();
    expect($account->credential->access_token)->toBe('initial');

    // Credential falls due, provider will return a fresh token.
    $account->credential->update(['expires_at' => now()->subMinute(), 'renew_at' => now()->subMinute()]);
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'renewed', expiresAt: now()->addHour());

    dispatchRenewal($account->credential);

    expect(tokens()->validAccessTokenFor($account->fresh()))->toBe('renewed');
});

it('renews a shared credential once and serves every account it backs', function () {
    $credential = SocialToken::create([
        'provider' => 'fake', 'provider_holder_id' => 'shared',
        'access_token' => 'old', 'refresh_token' => 'r', 'status' => AccountStatus::Active,
        'expires_at' => now()->subMinute(), 'renew_at' => now()->subMinute(),
    ]);
    $a = SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'a', 'social_token_id' => $credential->getKey(), 'status' => AccountStatus::Active]);
    $b = SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'b', 'social_token_id' => $credential->getKey(), 'status' => AccountStatus::Active]);

    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'fresh', expiresAt: now()->addHour());

    dispatchRenewal($credential);

    expect(tokens()->validAccessTokenFor($a->fresh()))->toBe('fresh')
        ->and(tokens()->validAccessTokenFor($b->fresh()))->toBe('fresh')
        ->and(FakeConnector::$renewCalls)->toBe(1); // one refresh served both accounts
});

// Revoke ---------------------------------------------------------------------

it('revokes a credential on the provider and cascades to its accounts', function () {
    Event::fake([CredentialRevoked::class, AccountRevoked::class]);

    $credential = SocialToken::create(['provider' => 'fake', 'provider_holder_id' => 'h', 'access_token' => 't', 'status' => AccountStatus::Active]);
    $account = SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'a', 'social_token_id' => $credential->getKey(), 'status' => AccountStatus::Active]);

    tokens()->revoke($credential);

    expect(FakeConnector::$revokeCalls)->toBe(1)
        ->and($credential->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($account->fresh()->status)->toBe(AccountStatus::Revoked);

    Event::assertDispatched(CredentialRevoked::class, fn ($e) => $e->token->is($credential));
    Event::assertDispatched(AccountRevoked::class);
});

it('will not serve a token for a revoked credential', function () {
    $credential = SocialToken::create([
        'provider' => 'fake', 'provider_holder_id' => 'h2', 'access_token' => 't',
        'status' => AccountStatus::Revoked, 'expires_at' => now()->addHour(),
    ]);
    $account = SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'a2', 'social_token_id' => $credential->getKey(), 'status' => AccountStatus::Active]);

    tokens()->validAccessTokenFor($account);
})->throws(NeedsReconnectException::class);
