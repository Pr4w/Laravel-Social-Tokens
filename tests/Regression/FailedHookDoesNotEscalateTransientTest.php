<?php

/*
 * Regression: RenewCredential after a transient failure.
 *
 * - failed() must not turn a provider outage into needs_reconnect for a
 *   credential that can still be renewed (refresh-token providers: Google,
 *   TikTok, LinkedIn with refresh). It stays Active and is retried later.
 * - After a failed cycle, renew_at is pushed back a little (credential-level
 *   backoff) instead of re-dispatching a job on every dispatcher tick.
 * - The uniqueness lock outlives the job's own retry span.
 * - A credential deleted before its job runs drops the job quietly.
 * - Truly unrenewable credentials are still flagged (guards).
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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
    FakeConnector::reset();

    // LinkedIn is only a refresh-token provider with refresh enabled (MDP).
    config(['social-tokens.connectors.linkedin.refresh_enabled' => true]);
    app()->forgetInstance(ConnectorRegistry::class);
    app()->forgetInstance(SocialTokens::class);
});

afterEach(fn () => FakeConnector::reset());

/** A credential backed by one active account, so the dispatcher considers it. */
function failedHookCredential(string $provider, array $attrs = []): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid('', true),
        'access_token' => 'old-access',
        'refresh_token' => 'still-valid-refresh',
        'status' => AccountStatus::Active,
    ], $attrs));

    SocialAccount::create([
        'provider' => $provider,
        'provider_user_id' => 'acct-'.uniqid('', true),
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

/**
 * Run the renewal job the way a queue worker does: one handle() per try, the
 * configured backoff between tries, then failed() with the last exception.
 * Returns that last exception (null if an attempt succeeded).
 */
function failedHookExhaustRetries(SocialToken $token): ?Throwable
{
    $job = new RenewCredential($token);
    $backoff = $job->backoff();
    $last = null;

    for ($attempt = 1; $attempt <= $job->tries(); $attempt++) {
        try {
            $job->handle(app(SocialTokens::class), app(ConnectorRegistry::class));

            return null;
        } catch (Throwable $e) {
            $last = $e;
        }

        if ($attempt < $job->tries()) {
            test()->travel($backoff[$attempt - 1] ?? end($backoff))->seconds();
        }
    }

    $job->failed($last);

    return $last;
}

/** A database queue with a database failer, so the real CallQueuedHandler runs. */
function failedHookUseDatabaseQueue(): void
{
    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 3600,
            'after_commit' => false,
        ],
        'queue.failed.driver' => 'database-uuids',
        'queue.failed.database' => 'testing',
        'queue.failed.table' => 'failed_jobs',
        'social-tokens.queue.connection' => 'database',
        'social-tokens.queue.queue' => 'default',
    ]);
    app()->forgetInstance('queue.failer');

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}

dataset('refresh-token providers caught late by an outage', [
    'google (1h token, renewed 10 min early)' => ['google', 'oauth2.googleapis.com/*', 10],
    'tiktok (24h token, last minutes of the window)' => ['tiktok', 'open.tiktokapis.com/*', 15],
    'linkedin with refresh enabled' => ['linkedin', 'www.linkedin.com/*', 10],
]);

it('keeps a refresh-token credential active when a provider outage outlasts the job retries', function (string $provider, string $host, int $minutesLeft) {
    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake([$host => Http::response('Service Unavailable', 503)]);

    $token = failedHookCredential($provider, [
        'expires_at' => now()->addMinutes($minutesLeft),
        'renew_at' => now()->subMinute(),
    ]);

    $last = failedHookExhaustRetries($token);

    // Setup sanity: every try failed on the outage (HTTP 503), and the access
    // token expired before the queue gave up.
    expect($last)->not->toBeNull()
        ->and($last->getMessage())->toContain('503')
        ->and($token->fresh()->isAccessTokenExpired(0))->toBeTrue();

    $fresh = $token->fresh();

    // The refresh token was never rejected: the credential can still be renewed.
    expect($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->refresh_token)->toBe('still-valid-refresh');

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
})->with('refresh-token providers caught late by an outage');

it('retries a refresh-token credential on a later dispatcher run once the provider is back', function () {
    $providerUp = false;
    Http::fake(['oauth2.googleapis.com/*' => function () use (&$providerUp) {
        return $providerUp
            ? Http::response(['access_token' => 'recovered-access', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/youtube.upload'])
            : Http::response('backend error', 503);
    }]);

    $token = failedHookCredential('google', [
        'expires_at' => now()->addMinutes(10),
        'renew_at' => now()->subMinute(),
    ]);

    failedHookExhaustRetries($token);

    $fresh = $token->fresh();

    // Still active, with renew_at pushed back a little. The access token has
    // already expired, so the next attempt must come soon (within the hour),
    // not at some far date.
    expect($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->renew_at->isFuture())->toBeTrue()
        ->and($fresh->renew_at->lessThanOrEqualTo(now()->addHour()))->toBeTrue();

    // The provider recovers; the next dispatcher run after renew_at retries it.
    $providerUp = true;
    $this->travelTo($fresh->renew_at->copy()->addMinute());
    Queue::fake();

    Artisan::call('social-tokens:dispatch-renewals');

    Queue::assertPushed(RenewCredential::class, fn (RenewCredential $job) => $job->token->is($token));

    Queue::pushed(RenewCredential::class)->first()->handle(app(SocialTokens::class), app(ConnectorRegistry::class));

    $renewed = $token->fresh();

    expect($renewed->status)->toBe(AccountStatus::Active)
        ->and($renewed->access_token)->toBe('recovered-access')
        ->and($renewed->renew_at->isFuture())->toBeTrue()
        ->and($renewed->last_error)->toBeNull();
});

it('keeps publishing callers on the retry path after a renewal cycle gave up', function () {
    $providerUp = false;
    Http::fake(['oauth2.googleapis.com/*' => function () use (&$providerUp) {
        return $providerUp
            ? Http::response(['access_token' => 'recovered-access', 'expires_in' => 3599])
            : Http::response('backend error', 503);
    }]);

    $token = failedHookCredential('google', [
        'expires_at' => now()->addMinutes(10),
        'renew_at' => now()->subMinute(),
    ]);
    $account = $token->accounts()->firstOrFail();

    failedHookExhaustRetries($token);
    $sentDuringCycle = count(Http::recorded());
    expect($sentDuringCycle)->toBeGreaterThan(0);

    // Provider still down: the caller is told to retry later (transient), and
    // the provider is actually asked again rather than refused up front.
    $caught = null;

    try {
        app(SocialTokens::class)->validAccessTokenFor($account->fresh());
    } catch (NeedsReconnectException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(NeedsReconnectException::class)
        ->and($caught->transient)->toBeTrue()
        ->and(count(Http::recorded()))->toBeGreaterThan($sentDuringCycle);

    // Provider back: the same call now renews and returns a token.
    $providerUp = true;

    expect(app(SocialTokens::class)->validAccessTokenFor($account->fresh()))->toBe('recovered-access')
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);
});

it('backs off renew_at after a failed cycle instead of re-dispatching on every tick', function () {
    Http::fake(['graph.facebook.com/*' => Http::response('Service Unavailable', 503)]);

    // Meta user token, 7 days left: well inside its lead window.
    $token = failedHookCredential('facebook', [
        'refresh_token' => null,
        'expires_at' => now()->addDays(7),
        'renew_at' => now()->subMinute(),
    ]);

    $last = failedHookExhaustRetries($token);

    expect($last)->not->toBeNull()
        ->and($last->getMessage())->toContain('503');

    $fresh = $token->fresh();

    // Pushed back, but by a small fraction of the ~7 days left, so many more
    // attempts still fit before the token expires. (The exact backoff is the
    // implementation's choice; the fix proposes min(60 min, remaining / 4).)
    expect($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->renew_at->isFuture())->toBeTrue()
        ->and($fresh->renew_at->lessThanOrEqualTo(now()->addDay()))->toBeTrue()
        ->and($fresh->renew_at->lessThan($fresh->expires_at))->toBeTrue();

    Queue::fake();

    // The next dispatcher tick (it runs every 15 min, so within that) leaves it
    // alone...
    $this->travel(14)->minutes();
    Artisan::call('social-tokens:dispatch-renewals');
    Queue::assertNothingPushed();

    // ...and a run after the new renew_at retries it.
    $this->travelTo($fresh->renew_at->copy()->addMinute());
    Artisan::call('social-tokens:dispatch-renewals');
    Queue::assertPushed(RenewCredential::class, 1);
});

it('does not queue a second renewal while the first one is still retrying', function (array $backoff) {
    config(['social-tokens.queue.backoff' => $backoff]);
    Queue::fake();

    $token = failedHookCredential('tiktok', [
        'expires_at' => now()->addHours(2),
        'renew_at' => now()->subMinute(),
    ]);

    Artisan::call('social-tokens:dispatch-renewals');
    Queue::assertPushed(RenewCredential::class, 1);

    // One minute before the first job's final try is due, it is still pending:
    // a dispatcher run at that moment must not stack a second job.
    $job = new RenewCredential($token);
    $span = 0;

    for ($attempt = 1; $attempt < $job->tries(); $attempt++) {
        $span += $backoff[$attempt - 1] ?? end($backoff);
    }

    $this->travel($span - 60)->seconds();
    Artisan::call('social-tokens:dispatch-renewals');

    Queue::assertPushed(RenewCredential::class, 1);
})->with([
    'default backoff' => [[60, 300, 900]],
    'longer configured backoff' => [[120, 600, 1800]],
]);

it('drops a queued renewal quietly when its credential was deleted before it ran', function () {
    failedHookUseDatabaseQueue();

    $failures = [];
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failures) {
        $failures[] = $event->exception::class;
    });

    $token = failedHookCredential('tiktok', [
        'expires_at' => now()->addHours(2),
        'renew_at' => now()->subMinute(),
    ]);

    RenewCredential::dispatch($token);
    expect(DB::table('jobs')->count())->toBe(1);

    // The app removes the account and its credential before the worker runs.
    $token->accounts()->delete();
    $token->delete();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default', '--sleep' => 0]);

    expect($failures)->toBe([])
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('still flags a credential that cannot be renewed once its access token has expired', function (RenewalStrategy $strategy, bool $refreshExpired) {
    Event::fake([CredentialNeedsReconnect::class]);
    FakeConnector::$strategy = $strategy;

    $token = failedHookCredential('fake', [
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subMinutes(20),
        'refresh_expires_at' => $refreshExpired ? now()->subDay() : null,
    ]);

    (new RenewCredential($token))->failed(new RuntimeException('Transient renewal failure: Provider returned HTTP 503'));

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
})->with([
    'extend-long-lived (Meta): an expired token cannot be extended' => [RenewalStrategy::ExtendLongLived, false],
    'reauth-only (LinkedIn without refresh)' => [RenewalStrategy::ReauthOnly, false],
    'refresh token past its own expiry' => [RenewalStrategy::RotatingRefreshToken, true],
]);

it('leaves a credential alone in failed() once it is no longer usable or was deleted', function () {
    Event::fake([CredentialNeedsReconnect::class]);

    $revoked = failedHookCredential('fake', ['status' => AccountStatus::Revoked, 'expires_at' => now()->subMinute()]);
    (new RenewCredential($revoked))->failed(new RuntimeException('gave up'));

    $deleted = failedHookCredential('fake', ['expires_at' => now()->subMinute()]);
    $job = new RenewCredential($deleted);
    $deleted->accounts()->delete();
    $deleted->delete();
    $job->failed(new RuntimeException('gave up'));

    expect($revoked->fresh()->status)->toBe(AccountStatus::Revoked);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});
