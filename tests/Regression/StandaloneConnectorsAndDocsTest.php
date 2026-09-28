<?php

/*
 * Regression: using the renewal service outside the package's tables, and
 * disconnecting ONE account without touching the accounts that share its
 * credential.
 *
 * - SocialTokens::renewCredential() is the locked, double-checked renewal path
 *   over the package's social_tokens table. Given a SocialToken that was never
 *   saved, v1.1.0 "renewed" it anyway: refresh() re-read nothing, every unsaved
 *   token shared the lock key "social-tokens:renew:", and applyRenewal()->save()
 *   INSERTED it into social_tokens. It must refuse such a token up front: no
 *   provider call, no row, no CredentialRenewed. Connectors stay usable on their
 *   own, without the tables (guard).
 * - The README ("To disconnect an account") recommended
 *   revoke($account->credential), which revokes the credential AND every account
 *   it backs: all the Instagram accounts of the same Facebook user, every
 *   LinkedIn organization plus the member's personal profile. The proposed
 *   SocialTokens::disconnect(SocialAccount) removes that one account, and only
 *   revokes the credential (provider revoke included) once no connected account
 *   is left on it. revoke() keeps its "disconnect the whole login" meaning
 *   (guard), and SocialAccount::markRevoked() stays account-level (guard).
 *
 * Provider traffic is TikTok through Http::fake; Meta and LinkedIn need no HTTP
 * here (their provider-side revoke is a no-op).
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountRevoked;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        'open.tiktokapis.com/v2/oauth/revoke/*' => Http::response([], 200),
        'open.tiktokapis.com/v2/oauth/token/*' => Http::response([
            'access_token' => 'tt-renewed', 'expires_in' => 86400,
            'refresh_token' => 'tt-refresh-2', 'refresh_expires_in' => 31536000,
            'open_id' => 'tt-1', 'scope' => 'user.info.basic,video.publish',
        ]),
    ]);

    Event::fake([AccountRevoked::class, CredentialRenewed::class, CredentialRevoked::class]);
});

function standaloneDocsTokens(): SocialTokens
{
    return app(SocialTokens::class);
}

/** A TikTok credential built in memory and never saved, expired and due: what an app with its own tables would hand over. */
function standaloneDocsUnsavedTikTok(): SocialToken
{
    return new SocialToken([
        'provider' => 'tiktok',
        'provider_holder_id' => 'tt-1',
        'access_token' => 'tt-old',
        'refresh_token' => 'tt-refresh-1',
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subHour(),
        'status' => AccountStatus::Active,
    ]);
}

/**
 * A saved credential backing one active account per id in $accountIds.
 *
 * @param  array<int, string>  $accountIds
 */
function standaloneDocsCredential(string $provider, string $accountProvider, array $accountIds, array $attrs = []): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid('', true),
        'access_token' => "{$provider}-access",
        'expires_at' => now()->addDays(50),
        'renew_at' => now()->addDays(43),
        'status' => AccountStatus::Active,
    ], $attrs));

    foreach ($accountIds as $id) {
        SocialAccount::create([
            'provider' => $accountProvider,
            'provider_user_id' => $id,
            'provider_holder_id' => $token->provider_holder_id,
            'social_token_id' => $token->getKey(),
            'status' => AccountStatus::Active,
        ]);
    }

    return $token;
}

/** Three Instagram accounts of one Facebook user, sharing its Meta user credential (stored under "facebook"). */
function standaloneDocsMetaCredential(array $accountIds = ['ig-1', 'ig-2', 'ig-3']): SocialToken
{
    return standaloneDocsCredential('facebook', 'instagram', $accountIds, [
        'provider_holder_id' => 'fb-user-1',
        'access_token' => 'meta-user-token',
    ]);
}

/** A LinkedIn member credential backing the personal profile and two organizations. */
function standaloneDocsLinkedInCredential(): SocialToken
{
    return standaloneDocsCredential('linkedin', 'linkedin', ['member-1', 'org-111', 'org-222'], [
        'provider_holder_id' => 'member-1',
        'access_token' => 'member-token',
    ]);
}

function standaloneDocsAccount(string $providerUserId): SocialAccount
{
    return SocialAccount::query()->where('provider_user_id', $providerUserId)->firstOrFail();
}

/** The account is still connected: its own row, its effective status and the posting path all agree. */
function standaloneDocsAssertStillUsable(string $providerUserId, string $expectedToken): void
{
    $account = standaloneDocsAccount($providerUserId);

    expect($account->status)->toBe(AccountStatus::Active, "{$providerUserId} was revoked along with its sibling")
        ->and($account->effectiveStatus())->toBe(AccountStatus::Active, "{$providerUserId} can no longer post")
        ->and(standaloneDocsTokens()->validAccessTokenFor($account))->toBe($expectedToken);
}

// renewCredential() only works on saved credentials ---------------------------

it('refuses to renew a credential that was never saved', function () {
    expect(fn () => standaloneDocsTokens()->renewCredential(standaloneDocsUnsavedTikTok()))
        ->toThrow(LogicException::class);
});

it('never inserts an unsaved credential into the tokens table nor calls the provider for it', function () {
    $token = standaloneDocsUnsavedTikTok();

    try {
        standaloneDocsTokens()->renewCredential($token);
    } catch (Throwable) {
        // How it refuses is asserted above; this test only checks nothing leaked.
    }

    expect(SocialToken::query()->count())->toBe(0, 'renewCredential() inserted an unsaved credential into social_tokens')
        ->and($token->exists)->toBeFalse();

    Http::assertNothingSent();
    Event::assertNotDispatched(CredentialRenewed::class);
});

it('refreshes an unsaved credential through its connector without touching the database', function () {
    $token = standaloneDocsUnsavedTikTok();

    $result = standaloneDocsTokens()->connector('tiktok')->refreshCredential($token);

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('tt-renewed')
        ->and($result->refreshToken)->toBe('tt-refresh-2')
        ->and($token->exists)->toBeFalse()
        ->and(SocialToken::query()->count())->toBe(0);

    Event::assertNotDispatched(CredentialRenewed::class);
});

it('still renews a saved credential through renewCredential', function () {
    $credential = standaloneDocsCredential('tiktok', 'tiktok', ['tt-1'], [
        'provider_holder_id' => 'tt-1',
        'access_token' => 'tt-old',
        'refresh_token' => 'tt-refresh-1',
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subHour(),
        'refresh_expires_at' => now()->addYear(),
    ]);

    $result = standaloneDocsTokens()->renewCredential($credential);

    expect($result->succeeded())->toBeTrue()
        ->and($credential->fresh()->access_token)->toBe('tt-renewed')
        ->and($credential->fresh()->refresh_token)->toBe('tt-refresh-2')
        ->and(SocialToken::query()->count())->toBe(1);

    Event::assertDispatchedTimes(CredentialRenewed::class, 1);
});

// Disconnecting ONE account ---------------------------------------------------

it('disconnects one Instagram account without revoking the accounts sharing its Meta credential', function () {
    $credential = standaloneDocsMetaCredential();

    standaloneDocsTokens()->disconnect(standaloneDocsAccount('ig-1'));

    $removed = standaloneDocsAccount('ig-1');

    expect($removed->status)->toBe(AccountStatus::Revoked)
        ->and(fn () => standaloneDocsTokens()->validAccessTokenFor($removed))->toThrow(NeedsReconnectException::class);

    standaloneDocsAssertStillUsable('ig-2', 'meta-user-token');
    standaloneDocsAssertStillUsable('ig-3', 'meta-user-token');

    expect($credential->fresh()->status)->toBe(AccountStatus::Active);

    Event::assertDispatchedTimes(AccountRevoked::class, 1);
    Event::assertDispatched(AccountRevoked::class, fn (AccountRevoked $event) => $event->account->provider_user_id === 'ig-1');
    Event::assertNotDispatched(CredentialRevoked::class);
    Http::assertNothingSent();
});

it('disconnects one LinkedIn organization without touching the personal profile or the other organizations', function () {
    $credential = standaloneDocsLinkedInCredential();

    standaloneDocsTokens()->disconnect(standaloneDocsAccount('org-111'));

    expect(standaloneDocsAccount('org-111')->status)->toBe(AccountStatus::Revoked);

    standaloneDocsAssertStillUsable('member-1', 'member-token');
    standaloneDocsAssertStillUsable('org-222', 'member-token');

    expect($credential->fresh()->status)->toBe(AccountStatus::Active);

    Event::assertDispatchedTimes(AccountRevoked::class, 1);
    Event::assertNotDispatched(CredentialRevoked::class);
});

it('revokes the credential at the provider when its only account is disconnected', function () {
    Queue::fake();

    $credential = standaloneDocsCredential('tiktok', 'tiktok', ['tt-1'], [
        'provider_holder_id' => 'tt-1',
        'access_token' => 'tt-access',
        'refresh_token' => 'tt-refresh-1',
        'expires_at' => now()->addHour(),
        'renew_at' => now()->subMinute(), // due: the dispatcher would pick it up
        'refresh_expires_at' => now()->addYear(),
    ]);

    standaloneDocsTokens()->disconnect(standaloneDocsAccount('tt-1'));

    expect(standaloneDocsAccount('tt-1')->status)->toBe(AccountStatus::Revoked)
        ->and($credential->fresh()->status)->toBe(AccountStatus::Revoked);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'open.tiktokapis.com/v2/oauth/revoke/')
        && $request['token'] === 'tt-access');
    Event::assertDispatchedTimes(CredentialRevoked::class, 1);
    Event::assertDispatchedTimes(AccountRevoked::class, 1);

    // A credential nobody posts with any more is not kept alive.
    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();
    Queue::assertNotPushed(RenewCredential::class);
});

it('revokes a shared credential only once the last of its accounts is disconnected', function () {
    $credential = standaloneDocsMetaCredential(['ig-1', 'ig-2']);

    standaloneDocsTokens()->disconnect(standaloneDocsAccount('ig-1'));

    expect($credential->fresh()->status)->toBe(AccountStatus::Active);
    standaloneDocsAssertStillUsable('ig-2', 'meta-user-token');
    Event::assertNotDispatched(CredentialRevoked::class);

    standaloneDocsTokens()->disconnect(standaloneDocsAccount('ig-2'));

    expect(standaloneDocsAccount('ig-2')->status)->toBe(AccountStatus::Revoked)
        ->and($credential->fresh()->status)->toBe(AccountStatus::Revoked);

    Event::assertDispatchedTimes(CredentialRevoked::class, 1);

    // One AccountRevoked per account removed: revoking the credential when the
    // last account goes must not announce ig-1 (already gone) a second time.
    foreach (['ig-1', 'ig-2'] as $id) {
        expect(Event::dispatched(AccountRevoked::class, fn (AccountRevoked $event) => $event->account->provider_user_id === $id))
            ->toHaveCount(1, "AccountRevoked was not fired exactly once for {$id}");
    }
});

it('flags only the account itself when an account is marked revoked', function () {
    $credential = standaloneDocsMetaCredential();

    standaloneDocsAccount('ig-1')->markRevoked();

    expect(fn () => standaloneDocsTokens()->validAccessTokenFor(standaloneDocsAccount('ig-1')))
        ->toThrow(NeedsReconnectException::class);

    standaloneDocsAssertStillUsable('ig-2', 'meta-user-token');
    standaloneDocsAssertStillUsable('ig-3', 'meta-user-token');

    expect($credential->fresh()->status)->toBe(AccountStatus::Active);
    Event::assertNotDispatched(CredentialRevoked::class);
});

it('still revokes the whole login, the credential and every account it backs, through revoke()', function () {
    $credential = standaloneDocsLinkedInCredential();

    standaloneDocsTokens()->revoke($credential);

    expect($credential->fresh()->status)->toBe(AccountStatus::Revoked);

    foreach (['member-1', 'org-111', 'org-222'] as $id) {
        expect(standaloneDocsAccount($id)->effectiveStatus())->toBe(AccountStatus::Revoked);
    }

    Event::assertDispatchedTimes(CredentialRevoked::class, 1);
});
