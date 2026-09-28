<?php

/*
 * Regression: credential status transitions are idempotent and one-way.
 *
 * - renewCredential() re-reads the credential under its lock; a credential
 *   that is no longer usable (revoked, or already flagged needs_reconnect) is
 *   never sent to the provider and never renewed, whatever the caller's
 *   in-memory copy says.
 * - markNeedsReconnect() / markRevoked() fire their event only on an actual
 *   state change, so N stale callers produce ONE CredentialNeedsReconnect (the
 *   README promise), and a revoked credential is never downgraded to
 *   needs_reconnect.
 * - applyRenewal() never moves a revoked credential back to active, and never
 *   stores a token that came back after the credential was revoked.
 * - validAccessTokenFor() does not hand out the cached token of a credential
 *   that was revoked or flagged after the caller loaded it.
 *
 * Provider traffic is TikTok (rotating refresh token) through Http::fake, and
 * LinkedIn without refresh tokens (reauth-only) for the no-HTTP path.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;

beforeEach(function () {
    // LinkedIn without Marketing Developer Platform: no refresh tokens.
    config(['social-tokens.connectors.linkedin.refresh_enabled' => false]);
    app()->forgetInstance(ConnectorRegistry::class);
    app()->forgetInstance(SocialTokens::class);

    Http::preventStrayRequests();
    Event::fake([CredentialNeedsReconnect::class, CredentialRenewed::class, CredentialRevoked::class]);
});

/** A credential backed by $accounts active accounts. */
function idempotentStatusCredential(string $provider, array $attrs = [], int $accounts = 1): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid('', true),
        'access_token' => 'old-access',
        'refresh_token' => 'r1',
        'status' => AccountStatus::Active,
    ], $attrs));

    for ($i = 0; $i < $accounts; $i++) {
        SocialAccount::create([
            'provider' => $provider,
            'provider_user_id' => 'acct-'.uniqid('', true),
            'social_token_id' => $token->getKey(),
            'status' => AccountStatus::Active,
        ]);
    }

    return $token;
}

/** A TikTok credential inside its renewal window; expired when $expired. */
function idempotentStatusTikTokCredential(bool $expired = false, int $accounts = 1): SocialToken
{
    return idempotentStatusCredential('tiktok', [
        'expires_at' => $expired ? now()->subMinutes(5) : now()->addHour(),
        'renew_at' => now()->subHours(3),
        'refresh_expires_at' => now()->addYear(),
    ], $accounts);
}

/** TikTok's token endpoint accepts or rejects (invalid_grant) the refresh; its revoke endpoint answers 200. */
function idempotentStatusFakeTikTok(bool $accepts = true): void
{
    Http::fake([
        'open.tiktokapis.com/v2/oauth/revoke/*' => Http::response([], 200),
        'open.tiktokapis.com/v2/oauth/token/*' => $accepts
            ? Http::response([
                'access_token' => 'a2', 'expires_in' => 86400,
                'refresh_token' => 'r2', 'refresh_expires_in' => 31536000,
                'open_id' => 'oid', 'scope' => 'user.info.basic,video.publish',
            ])
            : Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or expired.'], 400),
    ]);
}

/** Refresh-token requests actually sent to TikTok (revoke calls excluded). */
function idempotentStatusRefreshCalls(): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), '/oauth/token/'))->count();
}

/** Each account loaded on its own, credential included: what N workers hold. */
function idempotentStatusStaleAccounts(SocialToken $token): array
{
    return SocialAccount::query()
        ->where('social_token_id', $token->getKey())
        ->pluck('id')
        ->map(fn ($id) => SocialAccount::with('credential')->findOrFail($id))
        ->all();
}

/** validAccessTokenFor(), returning the exception instead of throwing it. */
function idempotentStatusAskToken(SocialAccount $account): string|NeedsReconnectException
{
    try {
        return app(SocialTokens::class)->validAccessTokenFor($account);
    } catch (NeedsReconnectException $e) {
        return $e;
    }
}

// Under the lock: a non-usable credential is never renewed ------------------

it('does not call the provider for a credential revoked after it was loaded', function () {
    idempotentStatusFakeTikTok();
    $token = idempotentStatusTikTokCredential();

    $stale = SocialToken::findOrFail($token->getKey());
    app(SocialTokens::class)->revoke(SocialToken::findOrFail($token->getKey()));

    $result = app(SocialTokens::class)->renewCredential($stale);

    expect(idempotentStatusRefreshCalls())->toBe(0)
        ->and($result->succeeded())->toBeFalse()
        ->and($result->outcome)->toBe(RenewalOutcome::Terminal);

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::Revoked)
        ->and($fresh->access_token)->toBe('old-access')
        ->and($fresh->refresh_token)->toBe('r1');

    Event::assertNotDispatched(CredentialRenewed::class);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('does not renew a credential already flagged needs_reconnect from a stale copy', function () {
    idempotentStatusFakeTikTok();
    $token = idempotentStatusTikTokCredential();

    $stale = SocialToken::findOrFail($token->getKey());
    SocialToken::query()->whereKey($token->getKey())->update([
        'status' => AccountStatus::NeedsReconnect->value,
        'last_error' => 'invalid_grant: flagged elsewhere',
    ]);

    $result = app(SocialTokens::class)->renewCredential($stale);

    expect(idempotentStatusRefreshCalls())->toBe(0)
        ->and($result->succeeded())->toBeFalse();

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($fresh->access_token)->toBe('old-access')
        ->and($fresh->last_error)->toBe('invalid_grant: flagged elsewhere');

    Event::assertNotDispatched(CredentialRenewed::class);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

// One CredentialNeedsReconnect per dead credential ----------------------------

it('fires CredentialNeedsReconnect once when several stale posting calls hit a rejected refresh token', function () {
    idempotentStatusFakeTikTok(accepts: false);
    $token = idempotentStatusTikTokCredential(expired: true, accounts: 3);

    // Three publishing workers, each holding its own copy of the credential.
    $outcomes = array_map(idempotentStatusAskToken(...), idempotentStatusStaleAccounts($token));

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);

    expect(idempotentStatusRefreshCalls())->toBe(1)
        ->and($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);

    foreach ($outcomes as $outcome) {
        expect($outcome)->toBeInstanceOf(NeedsReconnectException::class)
            ->and($outcome->transient)->toBeFalse();
    }
});

it('fires CredentialNeedsReconnect once when a posting call follows the job that flagged the credential', function () {
    idempotentStatusFakeTikTok(accepts: false);
    $token = idempotentStatusTikTokCredential(expired: true);

    // The publishing side loaded its account before the renewal job ran.
    [$account] = idempotentStatusStaleAccounts($token);

    (new RenewCredential(SocialToken::findOrFail($token->getKey())))
        ->handle(app(SocialTokens::class), app(ConnectorRegistry::class));

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);

    $outcome = idempotentStatusAskToken($account);

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);

    expect(idempotentStatusRefreshCalls())->toBe(1)
        ->and($outcome)->toBeInstanceOf(NeedsReconnectException::class)
        ->and($outcome->transient)->toBeFalse();
});

it('fires CredentialNeedsReconnect once when stale callers find an expired reauth-only credential', function () {
    Http::fake();

    expect(app(ConnectorRegistry::class)->for('linkedin')->renewalStrategy())->toBe(RenewalStrategy::ReauthOnly);

    // One LinkedIn member token behind a profile and two organizations.
    $token = idempotentStatusCredential('linkedin', [
        'refresh_token' => null,
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subDays(7),
    ], accounts: 3);

    $outcomes = array_map(idempotentStatusAskToken(...), idempotentStatusStaleAccounts($token));

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
    Http::assertNothingSent();

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);

    foreach ($outcomes as $outcome) {
        expect($outcome)->toBeInstanceOf(NeedsReconnectException::class)
            ->and($outcome->transient)->toBeFalse();
    }
});

it('marks a credential needs_reconnect once when it is flagged twice', function (bool $staleCopy) {
    $token = idempotentStatusCredential('tiktok');

    $first = SocialToken::findOrFail($token->getKey());
    $second = $staleCopy ? SocialToken::findOrFail($token->getKey()) : $first;

    $first->markNeedsReconnect('invalid_grant: first');
    $second->markNeedsReconnect('invalid_grant: second');

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);

    // The first (root-cause) reason is kept.
    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($token->fresh()->last_error)->toBe('invalid_grant: first');
})->with([
    'same instance' => [false],
    'stale copy loaded before the first flag' => [true],
]);

it('fires CredentialRevoked once when two stale copies revoke the same credential', function () {
    $token = idempotentStatusCredential('tiktok');

    $first = SocialToken::findOrFail($token->getKey());
    $second = SocialToken::findOrFail($token->getKey());

    $first->markRevoked();
    $second->markRevoked();

    Event::assertDispatchedTimes(CredentialRevoked::class, 1);

    expect($token->fresh()->status)->toBe(AccountStatus::Revoked);
});

it('does not downgrade a revoked credential to needs_reconnect', function () {
    idempotentStatusFakeTikTok();
    $token = idempotentStatusCredential('tiktok');

    // A worker holds a copy from before the user disconnected.
    $stale = SocialToken::findOrFail($token->getKey());
    app(SocialTokens::class)->revoke(SocialToken::findOrFail($token->getKey()));

    $stale->markNeedsReconnect('invalid_grant: late terminal failure');

    expect($token->fresh()->status)->toBe(AccountStatus::Revoked);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

// A revoked credential stays revoked ---------------------------------------

it('does not renew a revoked credential for a posting call that loaded it before the revoke', function () {
    idempotentStatusFakeTikTok();
    $token = idempotentStatusCredential('tiktok', [
        'expires_at' => now()->addMinutes(30),
        'renew_at' => now()->addMinutes(15),
        'refresh_expires_at' => now()->addYear(),
    ]);

    // A long publishing job loads its account, then the token expires while
    // it runs and the user disconnects from another request.
    [$account] = idempotentStatusStaleAccounts($token);
    $this->travel(40)->minutes();
    app(SocialTokens::class)->revoke(SocialToken::findOrFail($token->getKey()));

    $outcome = idempotentStatusAskToken($account);

    expect($outcome)->toBeInstanceOf(NeedsReconnectException::class)
        ->and($outcome->transient)->toBeFalse()
        ->and(idempotentStatusRefreshCalls())->toBe(0);

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::Revoked)
        ->and($fresh->access_token)->toBe('old-access')
        ->and($fresh->refresh_token)->toBe('r1');

    Event::assertNotDispatched(CredentialRenewed::class);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('keeps a revoked credential revoked when a stale renewal is rejected by the provider', function () {
    idempotentStatusFakeTikTok(accepts: false);
    $token = idempotentStatusCredential('tiktok', [
        'expires_at' => now()->addMinutes(30),
        'renew_at' => now()->addMinutes(15),
        'refresh_expires_at' => now()->addYear(),
    ]);

    [$account] = idempotentStatusStaleAccounts($token);
    $this->travel(40)->minutes();
    app(SocialTokens::class)->revoke(SocialToken::findOrFail($token->getKey()));

    $outcome = idempotentStatusAskToken($account);

    // Whether or not the provider is still asked (a fix should not ask it), a
    // rejected refresh must not downgrade the credential: no "please reconnect"
    // notification for a user who disconnected on purpose.
    expect($token->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($outcome)->toBeInstanceOf(NeedsReconnectException::class)
        ->and($outcome->transient)->toBeFalse();

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('never moves a revoked credential back to active when applying a renewal', function () {
    $token = idempotentStatusCredential('tiktok', ['status' => AccountStatus::Revoked]);
    $revoked = SocialToken::findOrFail($token->getKey());

    $revoked->applyRenewal(
        RenewalResult::success(accessToken: 'a2', expiresAt: now()->addDay(), refreshToken: 'r2'),
        app(ConnectorRegistry::class)->for('tiktok'),
    );

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::Revoked)
        ->and($fresh->access_token)->toBe('old-access')
        ->and($fresh->refresh_token)->toBe('r1');

    Event::assertNotDispatched(CredentialRenewed::class);
});

it('does not store or hand out a token renewed while the credential was being revoked', function () {
    $token = idempotentStatusTikTokCredential(expired: true);
    [$account] = idempotentStatusStaleAccounts($token);

    // The user disconnects while the refresh request is in flight: the revoke
    // is written by another process between the lock's re-read and the reply.
    // The revoke endpoint is faked too, so a fix may revoke the discarded token
    // at the provider (best effort) without tripping preventStrayRequests().
    Http::fake([
        'open.tiktokapis.com/v2/oauth/revoke/*' => Http::response([], 200),
        'open.tiktokapis.com/v2/oauth/token/*' => function () use ($token) {
            SocialToken::findOrFail($token->getKey())->markRevoked();

            return Http::response([
                'access_token' => 'a2', 'expires_in' => 86400,
                'refresh_token' => 'r2', 'refresh_expires_in' => 31536000,
            ]);
        },
    ]);

    $outcome = idempotentStatusAskToken($account);

    expect(idempotentStatusRefreshCalls())->toBe(1)
        ->and($outcome)->toBeInstanceOf(NeedsReconnectException::class)
        ->and($outcome->transient)->toBeFalse();

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::Revoked)
        ->and($fresh->access_token)->toBe('old-access')
        ->and($fresh->refresh_token)->toBe('r1');

    Event::assertNotDispatched(CredentialRenewed::class);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('does not hand out the cached token of a credential disabled after the account was loaded', function (string $how) {
    idempotentStatusFakeTikTok();
    $token = idempotentStatusTikTokCredential();
    $token->update(['expires_at' => now()->addHours(23), 'renew_at' => now()->addHours(21)]); // valid, not due

    [$account] = idempotentStatusStaleAccounts($token);

    match ($how) {
        'revoked' => app(SocialTokens::class)->revoke(SocialToken::findOrFail($token->getKey())),
        'flagged' => SocialToken::findOrFail($token->getKey())->markNeedsReconnect('Token rejected by the provider.'),
    };

    $outcome = idempotentStatusAskToken($account);

    expect($outcome)->toBeInstanceOf(NeedsReconnectException::class)
        ->and($outcome->transient)->toBeFalse()
        ->and(idempotentStatusRefreshCalls())->toBe(0);
})->with([
    'revoked by the user' => ['revoked'],
    'flagged needs_reconnect by another process' => ['flagged'],
]);

// Guards: the normal paths keep working -------------------------------------

it('still renews an active credential once when two stale copies ask in turn', function () {
    idempotentStatusFakeTikTok();
    $token = idempotentStatusTikTokCredential();

    $a = SocialToken::findOrFail($token->getKey());
    $b = SocialToken::findOrFail($token->getKey());

    $first = app(SocialTokens::class)->renewCredential($a);
    $second = app(SocialTokens::class)->renewCredential($b);

    expect(idempotentStatusRefreshCalls())->toBe(1)
        ->and($first->succeeded())->toBeTrue()
        ->and($second->succeeded())->toBeTrue()
        ->and($second->accessToken)->toBe('a2')
        ->and($token->fresh()->status)->toBe(AccountStatus::Active)
        ->and($token->fresh()->refresh_token)->toBe('r2');

    Event::assertDispatchedTimes(CredentialRenewed::class, 1);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('still flags an active credential once when the provider rejects its refresh token', function () {
    idempotentStatusFakeTikTok(accepts: false);
    $token = idempotentStatusTikTokCredential(expired: true);

    $outcome = idempotentStatusAskToken($token->accounts()->firstOrFail());

    expect($outcome)->toBeInstanceOf(NeedsReconnectException::class)
        ->and($outcome->transient)->toBeFalse()
        ->and($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and(idempotentStatusRefreshCalls())->toBe(1);

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('flags a credential again after the user reconnected it', function () {
    $token = idempotentStatusCredential('tiktok');

    SocialToken::findOrFail($token->getKey())->markNeedsReconnect('invalid_grant: first');

    // The user reconnects: the credential is active again with a new token.
    SocialToken::findOrFail($token->getKey())->forceFill([
        'status' => AccountStatus::Active,
        'access_token' => 'reconnected-access',
        'last_error' => null,
    ])->save();

    SocialToken::findOrFail($token->getKey())->markNeedsReconnect('invalid_grant: second');

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 2);

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($token->fresh()->last_error)->toBe('invalid_grant: second');
});

it('refuses a revoked account loaded after the revoke without calling the provider', function () {
    idempotentStatusFakeTikTok();
    $token = idempotentStatusTikTokCredential(expired: true);

    app(SocialTokens::class)->revoke(SocialToken::findOrFail($token->getKey()));

    $outcome = idempotentStatusAskToken(SocialAccount::with('credential')->where('social_token_id', $token->getKey())->firstOrFail());

    expect($outcome)->toBeInstanceOf(NeedsReconnectException::class)
        ->and($outcome->transient)->toBeFalse()
        ->and(idempotentStatusRefreshCalls())->toBe(0)
        ->and($token->fresh()->status)->toBe(AccountStatus::Revoked);
});
