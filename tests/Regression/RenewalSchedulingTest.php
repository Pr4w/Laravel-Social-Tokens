<?php

/*
 * Regression: which credentials get a renewal scheduled, and which ones the
 * dispatcher actually picks up.
 *
 * In v1.1.0:
 *  - a renewable credential stored without a known expiry (Meta's
 *    fb_exchange_token without expires_in, StoreInstagramAccounts with
 *    extend: false, a Socialite user without expires_in) gets renew_at = null,
 *    so it looks static and is never renewed;
 *  - a provider with no configured connector (no entry, or driver null) is
 *    stored silently as a non-renewable credential, and validAccessTokenFor()
 *    later throws InvalidArgumentException at expiry instead of the
 *    NeedsReconnectException the publishing layer catches;
 *  - the dispatcher queues jobs for credentials whose provider has no
 *    connector; each job throws and, once the token has expired, failed()
 *    flags the credential needs_reconnect;
 *  - the dispatcher keeps renewing credentials whose accounts are all revoked
 *    or flagged;
 *  - the dispatcher pages through dueForRenewal() by OFFSET while the jobs it
 *    dispatches move renew_at forward, so it skips rows once more than 1000
 *    credentials are due in one pass;
 *  - Google's lead time (10 min) is shorter than the default 15 min dispatch
 *    cadence, so the scheduled renewal lands at, or after, the token's expiry.
 *
 * Desired: see each test's name.
 */

use Carbon\CarbonInterval;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

beforeEach(function () {
    Http::preventStrayRequests();
    FakeConnector::reset();

    // Whole seconds, so timestamps round-trip through the datetime columns intact.
    $this->freezeSecond();
});

/**
 * A credential backed by one account per given status (the dispatcher skips
 * credentials no account uses).
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<int, AccountStatus>  $accountStatuses
 */
function renewalSchedulingCredential(string $provider, array $attributes = [], array $accountStatuses = [AccountStatus::Active]): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid('', true),
        'access_token' => 'current-token',
        'refresh_token' => 'current-refresh',
        'status' => AccountStatus::Active,
    ], $attributes));

    foreach ($accountStatuses as $status) {
        SocialAccount::create([
            'provider' => $provider,
            'provider_user_id' => 'acct-'.uniqid('', true),
            'social_token_id' => $token->getKey(),
            'status' => $status,
        ]);
    }

    return $token;
}

/** Drop the cached registry and service so a config change made in a test applies. */
function renewalSchedulingFreshServices(): void
{
    app()->forgetInstance(ConnectorRegistry::class);
    app()->forgetInstance(SocialTokens::class);
}

/**
 * Meta Graph API for the Instagram connect path: one Page with a linked
 * Instagram Business account. $extendBody is what fb_exchange_token answers.
 *
 * @param  array<string, mixed>  $extendBody
 */
function renewalSchedulingFakeMeta(array $extendBody): void
{
    Http::fake(function ($request) use ($extendBody) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response($extendBody),
            str_contains($url, '/debug_token') => Http::response(['data' => ['scopes' => ['instagram_content_publish'], 'granular_scopes' => []]]),
            str_contains($url, '/me/accounts') => Http::response(['data' => [[
                'id' => 'page-1',
                'name' => 'Page One',
                'access_token' => 'page-token-1',
                'instagram_business_account' => ['id' => 'ig-1', 'username' => 'insta_one'],
            ]]]),
            str_contains($url, '/me') => Http::response(['id' => 'user-1']),
            default => Http::response([], 404),
        };
    });
}

function renewalSchedulingMetaUserCredential(): SocialToken
{
    return SocialToken::query()
        ->where('provider', 'facebook')
        ->where('provider_holder_id', 'user-1')
        ->sole();
}

/** The scheduled event that runs the renewal dispatcher. */
function renewalSchedulingDispatchEvent(): ScheduledEvent
{
    return collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'social-tokens:dispatch-renewals'));
}

// Connect time: a renewable credential whose expiry is unknown ---------------

it('schedules a renewable credential stored without an expiry one lead time from now', function () {
    FakeConnector::$lead = CarbonInterval::hours(2);

    $user = socialiteUser(['id' => 'fake-1', 'refreshToken' => 'refresh-1']);
    $user->expiresIn = null; // the provider sent no expires_in

    $credential = app(StoreAccountFromSocialite::class)->handle('fake', $user)->credential;

    expect($credential->expires_at)->toBeNull()
        ->and($credential->renew_at?->toDateTimeString())->toBe(now()->addHours(2)->toDateTimeString());
});

it('keeps a reauth-only credential stored without an expiry unscheduled', function () {
    // Scheduling it would only get it flagged needs_reconnect one lead time
    // later (RenewCredential::warnOrFlag flags a null expiry at once).
    FakeConnector::$strategy = RenewalStrategy::ReauthOnly;

    $user = socialiteUser(['id' => 'fake-1']);
    $user->expiresIn = null;

    $credential = app(StoreAccountFromSocialite::class)->handle('fake', $user)->credential;

    expect($credential->expires_at)->toBeNull()
        ->and($credential->renew_at)->toBeNull();
});

it('schedules the shared Meta user credential when the token extension returns no expires_in', function () {
    renewalSchedulingFakeMeta(['access_token' => 'long-user-token']); // no expires_in

    app(StoreInstagramAccounts::class)->handle(userToken: 'short-user-token', userId: 'user-1');

    // The companion Page token is a genuine static credential and stays unscheduled.
    $pageCredential = SocialAccount::query()
        ->where('provider', 'facebook')
        ->where('provider_user_id', 'page-1')
        ->sole()
        ->credential;
    expect($pageCredential->renew_at)->toBeNull();

    // The user token behind Instagram is renewable: check back one lead time (7 days) from now.
    $userCredential = renewalSchedulingMetaUserCredential();
    expect($userCredential->access_token)->toBe('long-user-token')
        ->and($userCredential->expires_at)->toBeNull()
        ->and($userCredential->renew_at?->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());
});

it('schedules the shared Meta user credential stored with extend: false, and renews it when due', function () {
    config()->set('queue.default', 'sync');
    renewalSchedulingFakeMeta(['access_token' => 'extended-user-token', 'expires_in' => 5183944]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'already-long-token', userId: 'user-1', extend: false);

    $userCredential = renewalSchedulingMetaUserCredential();
    expect($userCredential->expires_at)->toBeNull()
        ->and($userCredential->renew_at?->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());

    // One lead time later the dispatcher extends it and learns the real expiry.
    $this->travel(7)->days();
    $this->travel(1)->minute();

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    $userCredential->refresh();
    expect($userCredential->access_token)->toBe('extended-user-token')
        ->and($userCredential->expires_at?->toDateTimeString())->toBe(now()->addSeconds(5183944)->toDateTimeString());
});

// Connect time: a provider with no connector ---------------------------------

it('refuses to store a connection for a provider that has no connector', function (string $provider) {
    $user = socialiteUser(['id' => "{$provider}-1", 'refreshToken' => 'refresh-1', 'expiresIn' => 7200]);

    expect(fn () => app(StoreConnection::class)->handle($provider, $user))
        ->toThrow(InvalidArgumentException::class, "[{$provider}]");

    expect(SocialToken::query()->count())->toBe(0)
        ->and(SocialAccount::query()->count())->toBe(0);
})->with([
    'no connector entry (the package calls YouTube "google")' => 'youtube',
    'no connector class in the package' => 'twitter',
]);

it('refuses to store a Socialite account for a provider that has no connector', function () {
    $user = socialiteUser(['id' => 'yt-1', 'refreshToken' => 'refresh-1', 'expiresIn' => 3600]);

    expect(fn () => app(StoreAccountFromSocialite::class)->handle('youtube', $user))
        ->toThrow(InvalidArgumentException::class, '[youtube]');

    expect(SocialToken::query()->count())->toBe(0)
        ->and(SocialAccount::query()->count())->toBe(0);
});

it('refuses to store a connection for a provider whose connector driver is disabled', function () {
    config()->set('social-tokens.connectors.threads.driver', null);
    renewalSchedulingFreshServices();

    $user = socialiteUser(['id' => 'threads-1', 'expiresIn' => 3600]);

    expect(fn () => app(StoreConnection::class)->handle('threads', $user, longLived: false))
        ->toThrow(InvalidArgumentException::class, '[threads]');

    expect(SocialToken::query()->count())->toBe(0);
});

it('tells the publishing layer to reconnect, not crash, when an expired credential has no connector', function () {
    // A credential left over from before the connect-time check, or whose
    // connector was removed from the config since.
    $token = renewalSchedulingCredential('twitter', [
        'expires_at' => now()->subMinute(),
        'renew_at' => null,
    ]);
    $account = $token->accounts()->sole();

    // The exception the publishing layer already catches. Whether the
    // credential is also flagged (terminal) or left active (transient) is the
    // fix's choice, so it is not asserted here.
    expect(fn () => app(SocialTokens::class)->validAccessTokenFor($account))
        ->toThrow(NeedsReconnectException::class);
});

// Dispatcher: providers with no connector ------------------------------------

it('does not dispatch renewals for credentials whose provider has no connector', function () {
    Queue::fake();

    config()->set('social-tokens.connectors.threads.driver', null); // disabled after credentials exist
    renewalSchedulingFreshServices();

    $due = ['expires_at' => now()->addHour(), 'renew_at' => now()->subMinute()];

    $configured = renewalSchedulingCredential('fake', $due);
    renewalSchedulingCredential('twitter', $due);  // no connector entry at all
    renewalSchedulingCredential('threads', $due);  // driver null

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    Queue::assertPushed(RenewCredential::class, 1);
    Queue::assertPushed(RenewCredential::class, fn (RenewCredential $job) => $job->token->is($configured));
});

it('lets a renewal job for a provider without a connector end without throwing or flagging the credential', function () {
    // A job queued before the connector was removed, run on an expired token:
    // the worst case, since failed() flags expired credentials.
    config()->set('queue.default', 'sync');
    Event::fake([CredentialNeedsReconnect::class]);

    $token = renewalSchedulingCredential('twitter', [
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subHour(),
    ]);

    $thrown = null;

    try {
        RenewCredential::dispatch($token);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown?->getMessage())->toBeNull()
        ->and($token->fresh()->status)->toBe(AccountStatus::Active)
        ->and($token->fresh()->last_error)->toBeNull();

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

// Dispatcher: credentials no active account posts with -----------------------

it('does not renew a credential whose accounts are all revoked or flagged', function () {
    Queue::fake();

    $due = ['expires_at' => now()->addHour(), 'renew_at' => now()->subMinute()];

    $stillUsed = renewalSchedulingCredential('fake', $due, [AccountStatus::Active, AccountStatus::Revoked]);
    renewalSchedulingCredential('fake', $due, [AccountStatus::Revoked]);
    renewalSchedulingCredential('fake', $due, [AccountStatus::NeedsReconnect]);
    renewalSchedulingCredential('fake', $due, [AccountStatus::Revoked, AccountStatus::NeedsReconnect]);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    Queue::assertPushed(RenewCredential::class, 1);
    Queue::assertPushed(RenewCredential::class, fn (RenewCredential $job) => $job->token->is($stillUsed));
});

// Dispatcher: many credentials due in one pass ---------------------------------

it('renews every due credential in one pass when more than a thousand are due', function () {
    // Jobs run inline, as a fast worker would, while the dispatcher is still
    // walking the due credentials. Each renewal moves renew_at 45 min ahead.
    config()->set('queue.default', 'sync');

    $total = 1100;
    $timestamp = now()->toDateTimeString();

    // Bulk inserts keep the setup fast; the token columns use the model's
    // "encrypted" cast, so they are encrypted the same way here.
    $accessToken = Crypt::encryptString('current-token');
    $refreshToken = Crypt::encryptString('current-refresh');

    $tokens = [];
    for ($i = 0; $i < $total; $i++) {
        $tokens[] = [
            'provider' => 'fake',
            'provider_holder_id' => "holder-{$i}",
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'status' => AccountStatus::Active->value,
            'expires_at' => now()->addMinutes(10)->toDateTimeString(),
            'renew_at' => now()->subMinute()->toDateTimeString(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    foreach (array_chunk($tokens, 200) as $chunk) {
        DB::table((new SocialToken)->getTable())->insert($chunk);
    }

    $accounts = SocialToken::query()->pluck('id')->map(fn (int $id) => [
        'provider' => 'fake',
        'provider_user_id' => "acct-{$id}",
        'social_token_id' => $id,
        'status' => AccountStatus::Active->value,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ])->all();

    foreach (array_chunk($accounts, 200) as $chunk) {
        DB::table((new SocialAccount)->getTable())->insert($chunk);
    }

    expect(SocialToken::query()->dueForRenewal()->count())->toBe($total);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    expect(SocialToken::query()->dueForRenewal()->count())->toBe(0)
        ->and(FakeConnector::$renewCalls)->toBe($total);
});

// Dispatcher cadence vs lead time ---------------------------------------------

it('renews a Google credential before it expires on the default dispatch schedule', function () {
    config()->set('queue.default', 'sync');
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-google-token', 'expires_in' => 3599]),
    ]);

    $dispatcher = renewalSchedulingDispatchEvent();
    $start = Carbon::parse('2026-01-05 10:00:00');

    // Connected just after a scheduler tick, with a one-hour token (Google
    // answers expires_in 3599), through the package's own connect path.
    $this->travelTo($start->copy()->addSeconds(30));
    $user = socialiteUser(['id' => 'google-1', 'token' => 'first-google-token', 'refreshToken' => 'google-refresh', 'expiresIn' => 3599]);
    $credential = app(StoreAccountFromSocialite::class)->handle('google', $user)->credential;

    // Seconds of validity the token still had when each scheduled renewal ran
    // (negative: it had already expired).
    $margins = [];

    for ($minute = 1; $minute <= 6 * 60; $minute++) {
        $this->travelTo($start->copy()->addMinutes($minute));

        if (! $dispatcher->isDue(app())) {
            continue;
        }

        $before = $credential->fresh();
        $calls = count(Http::recorded());

        $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

        if (count(Http::recorded()) > $calls) {
            $margins[now()->format('H:i')] = $before->expires_at->getTimestamp() - now()->getTimestamp();
        }
    }

    expect(count($margins))->toBeGreaterThanOrEqual(5);

    // At least a minute of headroom for queue latency: the scheduled renewal,
    // not the synchronous 30s fallback in validAccessTokenFor(), keeps it alive.
    expect(min($margins))->toBeGreaterThan(60, 'Validity left at each scheduled renewal: '.json_encode($margins));
});
