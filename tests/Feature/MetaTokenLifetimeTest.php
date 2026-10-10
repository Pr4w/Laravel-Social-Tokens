<?php

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialDataAccessExpiring;
use Pr4w\SocialTokens\Events\CredentialExpiringSoon;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

/*
 * Meta gives a Facebook Login user token two clocks, and debug_token reports
 * both: `expires_at` (0 when the token never expires) and
 * `data_access_expires_at` (90 days after the user was last active; only a
 * new login moves it). The package called debug_token at every connection
 * for the scopes and threw both away. A token that never expires came back
 * from fb_exchange_token with "no expiry" and was extended every week for
 * nothing; a lapse of data access was never announced.
 */

/**
 * Fake Graph for a Meta connection or renewal. $exchange is the
 * fb_exchange_token reply, $debug the `data` of debug_token (null: the call
 * fails).
 */
function fakeMetaLifetime(array $exchange = ['access_token' => 'long-user-token'], ?array $debug = []): void
{
    Http::fake(function ($request) use ($exchange, $debug) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response($exchange),
            str_contains($url, '/debug_token') => $debug === null
                ? Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 503)
                : Http::response(['data' => $debug + ['scopes' => ['instagram_content_publish'], 'granular_scopes' => []]]),
            str_contains($url, '/me/accounts') => Http::response(['data' => [[
                'id' => 'page-1', 'name' => 'Page One', 'access_token' => 'page-token',
                'instagram_business_account' => ['id' => 'ig-1', 'username' => 'insta'],
            ]]]),
            str_contains($url, '/me') => Http::response(['id' => 'user-1']),
            default => Http::response([], 404),
        };
    });
}

function metaUserCredential(): SocialToken
{
    return SocialToken::query()->where('provider', 'facebook')->where('provider_holder_id', 'user-1')->sole();
}

/** A Meta user credential an Instagram account posts with. */
function instagramBackedCredential(array $attrs = []): SocialToken
{
    $token = SocialToken::create($attrs + [
        'provider' => 'facebook',
        'provider_holder_id' => 'user-'.uniqid(),
        'access_token' => 'user-token',
        'status' => AccountStatus::Active,
    ]);

    SocialAccount::create([
        'provider' => 'instagram', 'provider_user_id' => 'ig-'.uniqid(), 'provider_holder_id' => $token->provider_holder_id,
        'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active,
    ]);

    return $token;
}

it('stores a never-expiring user token without a renewal date, with its data access deadline', function (string $action) {
    $this->freezeSecond();
    $dataAccess = now()->addDays(90)->startOfSecond();
    fakeMetaLifetime(debug: ['expires_at' => 0, 'data_access_expires_at' => $dataAccess->getTimestamp()]);

    app($action)->handle(userToken: 'short', userId: 'user-1');

    $credential = metaUserCredential();

    expect($credential->expires_at)->toBeNull()
        ->and($credential->renew_at)->toBeNull() // static: check-static watches it, nothing renews it weekly
        ->and($credential->data_access_expires_at->equalTo($dataAccess))->toBeTrue()
        ->and($credential->isDataAccessExpired())->toBeFalse();
})->with([StoreInstagramAccounts::class, StoreFacebookPages::class]);

it('takes the expiry from debug_token when the exchange gives none', function () {
    $this->freezeSecond();
    $expiresAt = now()->addDays(60)->startOfSecond();
    fakeMetaLifetime(debug: ['expires_at' => $expiresAt->getTimestamp()]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'short', userId: 'user-1');

    $credential = metaUserCredential();

    expect($credential->expires_at->equalTo($expiresAt))->toBeTrue()
        ->and($credential->renew_at->equalTo($expiresAt->copy()->subDays(7)))->toBeTrue()
        ->and($credential->data_access_expires_at)->toBeNull();
});

it('keeps renewing a token the exchange says expires, whatever debug_token says', function () {
    $this->freezeSecond();
    fakeMetaLifetime(['access_token' => 'long-user-token', 'expires_in' => 5183944], ['expires_at' => 0]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'short', userId: 'user-1');

    $credential = metaUserCredential();

    expect($credential->expires_at->equalTo(now()->addSeconds(5183944)))->toBeTrue()
        ->and($credential->renew_at)->not->toBeNull();
});

it('keeps checking back on an unknown expiry when debug_token fails', function () {
    $this->freezeSecond();
    fakeMetaLifetime(debug: null);

    app(StoreInstagramAccounts::class)->handle(userToken: 'short', userId: 'user-1');

    $credential = metaUserCredential();

    expect($credential->expires_at)->toBeNull()
        ->and($credential->renew_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($credential->data_access_expires_at)->toBeNull();
});

it('stops renewing a token weekly once debug_token says it never expires', function () {
    $this->freezeSecond();
    Log::spy();
    Event::fake([CredentialRenewed::class]);
    $dataAccess = now()->addDays(80)->startOfSecond();
    fakeMetaLifetime(debug: ['expires_at' => 0, 'data_access_expires_at' => $dataAccess->getTimestamp()]);

    // How 1.2 to 2.x stored it: no expiry known, checked back every week.
    $token = instagramBackedCredential(['renew_at' => now()->subMinute()]);

    $result = app(SocialTokens::class)->renewCredential($token);
    $token->refresh();

    expect($result->succeeded())->toBeTrue()
        ->and($result->neverExpires)->toBeTrue()
        ->and($token->access_token)->toBe('long-user-token')
        ->and($token->expires_at)->toBeNull()
        ->and($token->renew_at)->toBeNull()
        ->and($token->data_access_expires_at->equalTo($dataAccess))->toBeTrue();

    Event::assertDispatched(CredentialRenewed::class);
    Log::shouldNotHaveReceived('warning', fn (string $message) => str_contains($message, 'Renewal returned no expiry'));
});

it('keeps the stored data access deadline when debug_token fails after a renewal', function () {
    $this->freezeSecond();
    $dataAccess = now()->addDays(30)->startOfSecond();
    fakeMetaLifetime(['access_token' => 'long-user-token', 'expires_in' => 5183944], null);

    $token = instagramBackedCredential(['renew_at' => now()->subMinute(), 'data_access_expires_at' => $dataAccess]);

    expect(app(SocialTokens::class)->renewCredential($token)->succeeded())->toBeTrue();

    $token->refresh();

    expect($token->expires_at->equalTo(now()->addSeconds(5183944)))->toBeTrue()
        ->and($token->data_access_expires_at->equalTo($dataAccess))->toBeTrue();
});

it('stores debug_token dates as the same instant in an app that is not on UTC', function () {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('Pacific/Kiritimati'); // UTC+14

    try {
        $dataAccess = Carbon::create(2026, 10, 8, 12, 0, 0, 'UTC');
        fakeMetaLifetime(debug: ['expires_at' => 0, 'data_access_expires_at' => $dataAccess->getTimestamp()]);

        app(StoreInstagramAccounts::class)->handle(userToken: 'short', userId: 'user-1');

        expect(metaUserCredential()->data_access_expires_at->getTimestamp())->toBe($dataAccess->getTimestamp());
    } finally {
        date_default_timezone_set($timezone);
    }
});

it('warns once, a lead time ahead, when data access is about to lapse', function () {
    Event::fake([CredentialDataAccessExpiring::class]);

    $soon = instagramBackedCredential(['data_access_expires_at' => now()->addDays(6)]);
    instagramBackedCredential(['data_access_expires_at' => now()->addDays(30)]);
    instagramBackedCredential(); // unknown

    $this->artisan('social-tokens:dispatch-renewals')
        ->expectsOutputToContain('Warned about 1 credential(s)')
        ->assertSuccessful();
    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    Event::assertDispatchedTimes(CredentialDataAccessExpiring::class, 1);
    Event::assertDispatched(CredentialDataAccessExpiring::class, fn ($event) => $event->token->is($soon)
        && $event->dataAccessExpiresAt->equalTo($soon->data_access_expires_at));

    // Warn-only: nothing is flagged, the accounts keep posting.
    expect($soon->fresh()->status)->toBe(AccountStatus::Active)
        ->and($soon->fresh()->data_access_warned_at)->not->toBeNull()
        ->and(SocialAccount::usable()->count())->toBe(3);
});

it('warns about a deadline already passed when it was learnt late', function () {
    Event::fake([CredentialDataAccessExpiring::class]);

    $lapsed = instagramBackedCredential(['data_access_expires_at' => now()->subDay()]);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    expect($lapsed->fresh()->isDataAccessExpired())->toBeTrue();
    Event::assertDispatchedTimes(CredentialDataAccessExpiring::class, 1);
});

it('does not warn for a credential no active account posts with', function () {
    Event::fake([CredentialDataAccessExpiring::class]);

    // A Facebook-only connection: the Pages post with their own tokens.
    SocialToken::create([
        'provider' => 'facebook', 'provider_holder_id' => 'user-1', 'access_token' => 'user-token',
        'data_access_expires_at' => now()->addDay(),
    ]);
    $flagged = instagramBackedCredential(['data_access_expires_at' => now()->addDay()]);
    $flagged->accounts()->update(['status' => AccountStatus::NeedsReconnect->value]);
    instagramBackedCredential(['data_access_expires_at' => now()->addDay(), 'status' => AccountStatus::NeedsReconnect]);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    Event::assertNotDispatched(CredentialDataAccessExpiring::class);
});

it('follows the configured lead, and can be turned off', function () {
    Event::fake([CredentialDataAccessExpiring::class]);
    instagramBackedCredential(['data_access_expires_at' => now()->addDays(10)]);

    config()->set('social-tokens.data_access_warning', ['enabled' => 'false', 'lead_days' => 14]);
    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();
    Event::assertNotDispatched(CredentialDataAccessExpiring::class);

    config()->set('social-tokens.data_access_warning', ['enabled' => true, 'lead_days' => 14]);
    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();
    Event::assertDispatchedTimes(CredentialDataAccessExpiring::class, 1);
});

it('warns again for the next deadline once a new login moved it', function () {
    Event::fake([CredentialDataAccessExpiring::class]);
    $this->freezeSecond();

    $token = instagramBackedCredential(['provider_holder_id' => 'user-1', 'data_access_expires_at' => now()->addDays(3)]);
    $this->artisan('social-tokens:dispatch-renewals');

    // The user logs in again: a reconnect stores the new deadline.
    $next = now()->addDays(90)->startOfSecond();
    fakeMetaLifetime(debug: ['expires_at' => 0, 'data_access_expires_at' => $next->getTimestamp()]);
    app(StoreInstagramAccounts::class)->handle(userToken: 'short', userId: 'user-1');

    expect($token->fresh()->data_access_expires_at->equalTo($next))->toBeTrue()
        ->and($token->fresh()->data_access_warned_at)->toBeNull();

    $this->travel(85)->days();
    $this->artisan('social-tokens:dispatch-renewals');

    Event::assertDispatchedTimes(CredentialDataAccessExpiring::class, 2);
});

it('forgets the old data access deadline when a new login cannot read the new one', function (string $action) {
    $this->freezeSecond();

    // Lapsed and already announced; the user then logs in again, which moves
    // the deadline, but debug_token is down.
    $token = instagramBackedCredential([
        'provider_holder_id' => 'user-1',
        'data_access_expires_at' => now()->subDays(2),
        'data_access_warned_at' => now()->subDays(9),
    ]);
    fakeMetaLifetime(['access_token' => 'long-user-token', 'expires_in' => 5183944], null);

    app($action)->handle(userToken: 'short', userId: 'user-1');

    $token->refresh();

    expect($token->access_token)->toBe('long-user-token')
        ->and($token->data_access_expires_at)->toBeNull() // unknown: the next renewal reads it
        ->and($token->isDataAccessExpired())->toBeFalse()
        ->and($token->data_access_warned_at)->toBeNull();
})->with([StoreInstagramAccounts::class, StoreFacebookPages::class]);

it('forgets the old data access deadline when debug_token gives none for a new login', function () {
    $token = instagramBackedCredential([
        'provider_holder_id' => 'user-1',
        'data_access_expires_at' => now()->subDays(2),
        'data_access_warned_at' => now()->subDays(9),
    ]);
    fakeMetaLifetime(debug: ['expires_at' => now()->addDays(60)->getTimestamp()]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'short', userId: 'user-1');

    expect($token->fresh()->data_access_expires_at)->toBeNull()
        ->and($token->fresh()->data_access_warned_at)->toBeNull();
});

it('keeps the stored data access deadline when a re-sync of the same token cannot read it', function () {
    $this->freezeSecond();
    $dataAccess = now()->addDays(3)->startOfSecond();
    $warnedAt = now()->subDays(4)->startOfSecond();

    // extend: false re-syncs a token the app already holds: no new login.
    $token = instagramBackedCredential([
        'provider_holder_id' => 'user-1',
        'data_access_expires_at' => $dataAccess,
        'data_access_warned_at' => $warnedAt,
    ]);
    fakeMetaLifetime(debug: null);

    app(StoreInstagramAccounts::class)->handle(userToken: 'user-token', userId: 'user-1', extend: false);

    expect($token->fresh()->data_access_expires_at->equalTo($dataAccess))->toBeTrue()
        ->and($token->fresh()->data_access_warned_at->equalTo($warnedAt))->toBeTrue();
});

it('still dispatches due renewals when the data access warning cannot run', function () {
    Queue::fake();
    Event::fake([CredentialDataAccessExpiring::class]);

    $due = instagramBackedCredential(['renew_at' => now()->subMinute()]);

    // The warning's scan fails, as it does on MySQL or PostgreSQL before
    // migration 000009 has run (unknown column).
    DB::connection()->beforeExecuting(function (string $sql) {
        if (str_contains($sql, 'data_access_warned_at') && str_starts_with(strtolower(ltrim($sql)), 'select')) {
            throw new RuntimeException('Unknown column data_access_warned_at');
        }
    });

    $this->artisan('social-tokens:dispatch-renewals')
        ->expectsOutputToContain('Dispatched 1 renewal job(s).')
        ->expectsOutputToContain('Could not check the data access deadlines')
        ->assertFailed();

    Queue::assertPushed(RenewCredential::class, fn (RenewCredential $job) => $job->token->is($due));
});

it('does not re-arm the warning when a renewal reports the same deadline', function () {
    Event::fake([CredentialDataAccessExpiring::class]);
    $this->freezeSecond();
    $dataAccess = now()->addDays(3)->startOfSecond();

    $token = instagramBackedCredential(['data_access_expires_at' => $dataAccess, 'renew_at' => now()->subMinute()]);
    $this->artisan('social-tokens:dispatch-renewals');

    fakeMetaLifetime(debug: ['expires_at' => 0, 'data_access_expires_at' => $dataAccess->getTimestamp()]);
    app(SocialTokens::class)->renewCredential($token->fresh());

    expect($token->fresh()->data_access_warned_at)->not->toBeNull();

    $this->artisan('social-tokens:dispatch-renewals');

    Event::assertDispatchedTimes(CredentialDataAccessExpiring::class, 1);
});

it('lets check-static watch a never-expiring user token', function () {
    $token = instagramBackedCredential(['expires_at' => null, 'renew_at' => null]);
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'user-1'])]);

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/me') && $request->hasHeader('Authorization', 'Bearer user-token'));
    expect($token->fresh()->status)->toBe(AccountStatus::Active);
});

// Re-syncing the token the app holds ---------------------------------------------

/**
 * A Meta user credential whose renewal Meta did not extend: the renewal
 * parked renew_at on the expiry and warned the user once.
 */
function nonExtendedMetaCredential(CarbonInterface $expiresAt): SocialToken
{
    $token = instagramBackedCredential([
        'provider_holder_id' => 'user-1',
        'expires_at' => $expiresAt,
        'renew_at' => $expiresAt->copy()->subDays(7),
    ]);

    // Meta answers with the lifetime the token has left: not extended.
    fakeMetaLifetime(
        exchange: ['access_token' => 'user-token', 'expires_in' => (int) now()->diffInSeconds($expiresAt, true)],
        debug: ['expires_at' => $expiresAt->getTimestamp()],
    );

    expect(app(SocialTokens::class)->renewCredential($token)->succeeded())->toBeTrue()
        ->and($token->fresh()->renew_at->equalTo($expiresAt))->toBeTrue();

    return $token->fresh();
}

it('keeps a renewal parked on the expiry when a re-sync stores the same token, so the user is not warned again', function (string $action) {
    $this->freezeSecond();
    Event::fake([CredentialExpiringSoon::class]);
    $expiresAt = now()->addDays(3);
    $token = nonExtendedMetaCredential($expiresAt);
    $renewedAt = $token->last_renewed_at;

    Event::assertDispatchedTimes(CredentialExpiringSoon::class, 1);

    $this->travel(1)->hours();

    // A re-sync with the stored token, to pick up a new Page: debug_token
    // reports the same expiry, give or take the second the exchange took.
    fakeMetaLifetime(debug: ['expires_at' => $expiresAt->getTimestamp() + 1]);
    app($action)->handle(userToken: 'user-token', userId: 'user-1', extend: false);

    expect($token->fresh()->renew_at->equalTo($expiresAt))->toBeTrue()
        ->and($token->fresh()->expires_at->equalTo($expiresAt))->toBeTrue()
        ->and($token->fresh()->last_renewed_at->equalTo($renewedAt))->toBeTrue();

    Queue::fake();
    $this->artisan('social-tokens:dispatch-renewals')->expectsOutputToContain('Dispatched 0 renewal job(s).');

    Queue::assertNothingPushed();
    Event::assertDispatchedTimes(CredentialExpiringSoon::class, 1);
})->with([StoreInstagramAccounts::class, StoreFacebookPages::class]);

it('keeps the stored expiry and schedule when a re-sync of the same token cannot read debug_token', function () {
    $this->freezeSecond();
    $token = instagramBackedCredential([
        'provider_holder_id' => 'user-1',
        'expires_at' => now()->addDays(40),
        'renew_at' => now()->addDays(33),
    ]);
    fakeMetaLifetime(debug: null);

    app(StoreInstagramAccounts::class)->handle(userToken: 'user-token', userId: 'user-1', extend: false);

    expect($token->fresh()->expires_at->equalTo(now()->addDays(40)))->toBeTrue()
        ->and($token->fresh()->renew_at->equalTo(now()->addDays(33)))->toBeTrue();
});

it('reschedules a re-synced token whose expiry debug_token reports moved, or that the dispatcher unscheduled', function (?string $renewAt, int $debugExpiryDays) {
    $this->freezeSecond();
    $token = instagramBackedCredential([
        'provider_holder_id' => 'user-1',
        'expires_at' => now()->addDays(3),
        'renew_at' => $renewAt === null ? null : now()->addDays(3),
    ]);
    fakeMetaLifetime(debug: ['expires_at' => now()->addDays($debugExpiryDays)->getTimestamp()]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'user-token', userId: 'user-1', extend: false);

    expect($token->fresh()->expires_at->equalTo(now()->addDays($debugExpiryDays)))->toBeTrue()
        ->and($token->fresh()->renew_at->equalTo(now()->addDays($debugExpiryDays - 7)))->toBeTrue();
})->with([
    'extended' => ['parked', 60],
    'unscheduled' => [null, 3],
]);

it('schedules a re-sync with another token than the stored one as a new token', function () {
    $this->freezeSecond();
    $token = nonExtendedMetaCredential(now()->addDays(3));
    fakeMetaLifetime(debug: ['expires_at' => now()->addDays(3)->getTimestamp()]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'another-user-token', userId: 'user-1', extend: false);

    expect($token->fresh()->renew_at->equalTo(now()->addDays(3)->subDays(7)))->toBeTrue()
        ->and($token->fresh()->last_renewed_at->equalTo(now()))->toBeTrue();
});
