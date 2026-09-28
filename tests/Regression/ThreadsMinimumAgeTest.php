<?php

/*
 * Regression: a Threads long-lived token can only be extended with
 * th_refresh_token once it is at least 24 hours old (Meta docs, repeated in the
 * ThreadsConnector docblock). v1.1.0 has no such guard: last_renewed_at is
 * written by SocialToken::applyRenewal() but never read, and a connect does not
 * record when the token was issued at all. A Threads credential that becomes due
 * within 24h of its last renewal or (re)connect — an import or the app setting
 * renew_at early, a th_refresh_token answer that did not extend the expiry, a
 * connect-time expiry fallback — is sent to Meta anyway. The exact error Meta
 * returns for a too-young token is unverified; if it maps to a terminal code
 * (190), the credential is flagged needs_reconnect for nothing.
 *
 * Desired: renewing a Threads credential whose current token was issued (by a
 * renewal or by a connect/reconnect) less than 24h ago does not call the
 * provider and returns a known transient result, leaving the credential
 * untouched and usable. Once the token is 24h old, renewal proceeds as before.
 * A credential whose token age is unknown (last_renewed_at null on a row that
 * was not connected through the package, e.g. backfilled from 1.0.x) keeps
 * v1.1.0's behaviour and is renewed.
 *
 * Every test goes through SocialTokens::renewCredential() or the renewal job,
 * so the guard may live in ThreadsConnector::refreshCredential() or in
 * SocialTokens (e.g. a per-connector minimum token age); either passes.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

beforeEach(function () {
    Http::preventStrayRequests();

    // Whole seconds, so timestamps round-trip through the datetime columns intact.
    $this->freezeSecond();
});

/**
 * A still-valid Threads credential whose renewal window has opened, backing
 * one Threads account.
 *
 * @param  array<string, mixed>  $attrs
 */
function threadsMinAgeCredential(array $attrs = []): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => 'threads',
        'provider_holder_id' => 'th-'.uniqid(),
        'access_token' => 'current-threads-token',
        'status' => AccountStatus::Active,
        'expires_at' => now()->addDays(5),
        'renew_at' => now()->subMinute(), // due
    ], $attrs));

    SocialAccount::create([
        'provider' => 'threads',
        'provider_user_id' => $token->provider_holder_id,
        'provider_holder_id' => $token->provider_holder_id,
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

/**
 * Fake both Threads token endpoints: the connect-time th_exchange_token and the
 * th_refresh_token renewal (successful unless a body is given).
 *
 * @param  array<string, mixed>|null  $refreshBody
 */
function threadsMinAgeFakeEndpoints(?array $refreshBody = null, int $refreshStatus = 200): void
{
    Http::fake([
        'graph.threads.net/refresh_access_token*' => Http::response(
            $refreshBody ?? ['access_token' => 'refreshed-threads-token', 'token_type' => 'bearer', 'expires_in' => 5183944],
            $refreshStatus,
        ),
        'graph.threads.net/access_token*' => Http::response([
            'access_token' => 'connected-threads-token',
            'token_type' => 'bearer',
            'expires_in' => 5183944,
        ]),
    ]);
}

function threadsMinAgeAssertNoRefreshSent(): void
{
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'refresh_access_token'));
}

function threadsMinAgeRefreshCount(): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), 'refresh_access_token'))->count();
}

/**
 * Connect (or reconnect) a Threads account through the package's OAuth entry
 * point and return its credential.
 */
function threadsMinAgeConnect(string $userId = 'th-user-1'): SocialToken
{
    /** @var Collection<int, SocialAccount> $accounts */
    $accounts = app(StoreConnection::class)->handle('threads', socialiteUser([
        'id' => $userId,
        'token' => 'short-lived-threads-token',
        'expiresIn' => 3600, // the short-lived token; th_exchange_token replaces it
    ]));

    return $accounts->sole()->credential;
}

it('does not call th_refresh_token for a Threads credential renewed less than 24h ago', function () {
    threadsMinAgeFakeEndpoints();
    $token = threadsMinAgeCredential(['last_renewed_at' => now()->subHours(3)]);

    $result = app(SocialTokens::class)->renewCredential($token);

    threadsMinAgeAssertNoRefreshSent();

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->succeeded())->toBeFalse();
});

it('reports a Threads token renewed 23h ago as a known transient condition, not an uncatalogued error', function () {
    threadsMinAgeFakeEndpoints();
    $token = threadsMinAgeCredential(['last_renewed_at' => now()->subHours(23)]);

    $result = app(SocialTokens::class)->renewCredential($token);

    threadsMinAgeAssertNoRefreshSent();

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeFalse() // a known condition: no "Uncatalogued renewal error" log
        ->and($result->reason)->not->toBeEmpty();
});

it('leaves a too-young Threads credential untouched and usable', function () {
    Event::fake([CredentialRenewed::class, CredentialNeedsReconnect::class]);
    threadsMinAgeFakeEndpoints();
    $renewedAt = now()->subHours(3);
    $token = threadsMinAgeCredential(['last_renewed_at' => $renewedAt]);
    $before = $token->fresh();

    app(SocialTokens::class)->renewCredential($token);

    $after = $token->fresh();

    // renew_at is deliberately not checked: a fix may push it to the 24h mark
    // so the job and the dispatcher stop retrying until then.
    expect($after->access_token)->toBe('current-threads-token')
        ->and($after->status)->toBe(AccountStatus::Active)
        ->and($after->expires_at->equalTo($before->expires_at))->toBeTrue()
        ->and($after->last_renewed_at->equalTo($renewedAt))->toBeTrue()
        ->and($after->last_error)->toBeNull();

    Event::assertNotDispatched(CredentialRenewed::class);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('does not flag a Threads credential for reconnection because it was renewed less than 24h ago', function () {
    // Worst case: Meta answers the too-young refresh with an invalid-token error.
    // The exact error is unverified; the credential must not be cut off either way.
    Event::fake([CredentialNeedsReconnect::class]);
    threadsMinAgeFakeEndpoints(['error' => [
        'message' => 'Error validating access token.',
        'type' => 'OAuthException',
        'code' => 190,
    ]], 400);
    $token = threadsMinAgeCredential(['last_renewed_at' => now()->subHours(2)]);

    // A transient failure makes the job throw so the queue retries it.
    rescue(fn () => RenewCredential::dispatchSync($token), null, false);

    expect($token->fresh()->status)->toBe(AccountStatus::Active);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
    threadsMinAgeAssertNoRefreshSent();
});

it('does not call th_refresh_token for a Threads account connected less than 24h ago', function () {
    threadsMinAgeFakeEndpoints();
    $token = threadsMinAgeConnect();

    // The credential becomes due right away (e.g. an import or the app
    // scheduling an early renewal).
    $token->update(['renew_at' => now()->subMinute()]);
    $this->travel(30)->minutes();

    $result = app(SocialTokens::class)->renewCredential($token);

    threadsMinAgeAssertNoRefreshSent();

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($token->fresh()->access_token)->toBe('connected-threads-token');
});

it('counts the Threads token age from the last reconnect, not from an older renewal', function () {
    threadsMinAgeFakeEndpoints();

    // A credential renewed 10 days ago, then reconnected by the user just now:
    // the token it holds is brand new, whatever last_renewed_at said before.
    $existing = threadsMinAgeCredential([
        'provider_holder_id' => 'th-user-1',
        'access_token' => 'old-threads-token',
        'expires_at' => now()->addDays(50),
        'renew_at' => now()->addDays(43),
        'last_renewed_at' => now()->subDays(10),
    ]);

    $token = threadsMinAgeConnect('th-user-1');

    expect($token->is($existing))->toBeTrue(); // same row, updated in place

    $token->update(['renew_at' => now()->subMinute()]);
    $this->travel(30)->minutes();

    $result = app(SocialTokens::class)->renewCredential($token);

    threadsMinAgeAssertNoRefreshSent();

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($token->fresh()->access_token)->toBe('connected-threads-token');
});

it('renews the Threads credential on the first job run after the token turns 24h old', function () {
    threadsMinAgeFakeEndpoints();
    $token = threadsMinAgeCredential(['last_renewed_at' => now()->subHours(20)]);

    rescue(fn () => RenewCredential::dispatchSync($token), null, false);

    expect(threadsMinAgeRefreshCount())->toBe(0);

    // The token is now 25h old: the next run goes through.
    $this->travel(5)->hours();
    RenewCredential::dispatchSync($token->fresh());

    $fresh = $token->fresh();

    expect(threadsMinAgeRefreshCount())->toBe(1)
        ->and($fresh->access_token)->toBe('refreshed-threads-token')
        ->and($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->last_renewed_at->equalTo(now()))->toBeTrue();
});

it('still renews a Threads credential renewed more than 24h ago', function () {
    threadsMinAgeFakeEndpoints();
    $token = threadsMinAgeCredential(['last_renewed_at' => now()->subHours(25)]);

    $result = app(SocialTokens::class)->renewCredential($token);

    $fresh = $token->fresh();

    expect($result->succeeded())->toBeTrue()
        ->and(threadsMinAgeRefreshCount())->toBe(1)
        ->and($fresh->access_token)->toBe('refreshed-threads-token')
        ->and($fresh->last_renewed_at->equalTo(now()))->toBeTrue()
        ->and($fresh->isDueForRenewal())->toBeFalse();
});

it('still renews a Threads account connected more than 24h ago', function () {
    threadsMinAgeFakeEndpoints();
    $token = threadsMinAgeConnect();

    $this->travel(25)->hours();
    $token->update(['renew_at' => now()->subMinute()]);

    $result = app(SocialTokens::class)->renewCredential($token);

    expect($result->succeeded())->toBeTrue()
        ->and(threadsMinAgeRefreshCount())->toBe(1)
        ->and($token->fresh()->access_token)->toBe('refreshed-threads-token');
});

it('still renews a Threads credential whose token age is unknown', function () {
    // No last_renewed_at: a row created outside the package's connect flow
    // (backfilled from 1.0.x, imported by the app). Its age cannot be known,
    // so v1.1.0's behaviour is kept rather than blocking it for 24h.
    threadsMinAgeFakeEndpoints();
    $token = threadsMinAgeCredential(['last_renewed_at' => null]);

    $result = app(SocialTokens::class)->renewCredential($token);

    expect($result->succeeded())->toBeTrue()
        ->and(threadsMinAgeRefreshCount())->toBe(1)
        ->and($token->fresh()->access_token)->toBe('refreshed-threads-token');
});
