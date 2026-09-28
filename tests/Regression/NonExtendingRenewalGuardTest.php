<?php

/*
 * Regression: a renewal that does not move the expiry past the lead time must
 * not be treated as a plain success.
 *
 * Meta's fb_exchange_token (long-lived user token behind Instagram) and Threads'
 * th_refresh_token may answer "success" with only the REMAINING lifetime of a
 * still-valid token, i.e. the same absolute expiry. In v1.1.0,
 * SocialToken::applyRenewal() then sets renew_at = expires_at - leadTime(),
 * which is already in the past: the credential stays due, every 15-minute
 * dispatcher tick calls the provider again and fires CredentialRenewed, no
 * CredentialExpiringSoon is ever sent, and the token still dies at its expiry.
 *
 * Desired: such a renewal is recognised as "not extended". The new token is
 * kept, renew_at moves to expires_at (so the next ticks skip it and the pass at
 * expiry flags it), CredentialExpiringSoon fires once, and the expires_in the
 * provider returned is logged (context key "expires_in") so the real Meta
 * behaviour can be settled from production logs. Renewals that do extend the
 * token keep the normal lead-time window and raise no warning.
 */

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialExpiringSoon;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

beforeEach(function () {
    // Run RenewCredential inline, the way a worker would pick it up right away.
    config()->set('queue.default', 'sync');

    Http::preventStrayRequests();
    FakeConnector::reset();

    // Whole seconds, so expiries round-trip through the datetime columns intact.
    $this->freezeSecond();
});

/**
 * A still-valid credential whose renewal window has opened, backing one account
 * (the dispatcher skips credentials no account uses).
 */
function nonExtendingGuardCredential(string $provider, string $accountProvider, CarbonInterface $expiresAt): SocialToken
{
    $token = SocialToken::create([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid(),
        'access_token' => 'current-token',
        'status' => AccountStatus::Active,
        'expires_at' => $expiresAt,
        'renew_at' => now()->subMinute(),
    ]);

    SocialAccount::create([
        'provider' => $accountProvider,
        'provider_user_id' => 'acct-'.uniqid('', true),
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

/**
 * A Meta endpoint that "extends" a token by returning only its remaining
 * lifetime (same absolute expiry), then answers 190 once the token has expired.
 */
function fakeNonExtendingMetaEndpoint(string $host, CarbonInterface $expiresAt): void
{
    Http::fake([$host => function () use ($expiresAt) {
        if (now()->greaterThanOrEqualTo($expiresAt)) {
            return Http::response(['error' => [
                'code' => 190,
                'type' => 'OAuthException',
                'message' => 'Error validating access token: Session has expired.',
            ]], 400);
        }

        return Http::response([
            'access_token' => 'same-lifetime-token',
            'token_type' => 'bearer',
            'expires_in' => (int) now()->diffInSeconds($expiresAt),
        ]);
    }]);
}

dataset('meta credentials renewed without extension', [
    'Meta user token behind Instagram (fb_exchange_token)' => ['facebook', 'instagram', 'graph.facebook.com/*'],
    'Threads token (th_refresh_token)' => ['threads', 'threads', 'graph.threads.net/*'],
]);

it('does not call the provider again on the next ticks when a renewal did not extend the expiry', function (string $provider, string $accountProvider, string $host) {
    $expiresAt = now()->addDays(6);
    fakeNonExtendingMetaEndpoint($host, $expiresAt);
    $token = nonExtendingGuardCredential($provider, $accountProvider, $expiresAt);

    // The first tick renews (however many calls the connector makes to do so)...
    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();
    $sentByFirstTick = count(Http::recorded());

    expect($sentByFirstTick)->toBeGreaterThan(0);

    // ...and the next ticks (every 15 minutes, for the rest of the hour) leave it alone.
    for ($tick = 0; $tick < 3; $tick++) {
        $this->travel(15)->minutes();
        $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();
    }

    expect(count(Http::recorded()))->toBe($sentByFirstTick, 'the provider was called again on a later tick')
        ->and($token->fresh()->isDueForRenewal())->toBeFalse()
        ->and(SocialToken::query()->dueForRenewal()->count())->toBe(0);
})->with('meta credentials renewed without extension');

it('warns once with CredentialExpiringSoon when a renewal did not extend the expiry', function (string $provider, string $accountProvider, string $host) {
    Event::fake([CredentialExpiringSoon::class, CredentialRenewed::class]);
    $expiresAt = now()->addDays(6);
    fakeNonExtendingMetaEndpoint($host, $expiresAt);
    $token = nonExtendingGuardCredential($provider, $accountProvider, $expiresAt);

    for ($tick = 0; $tick < 4; $tick++) {
        $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();
        $this->travel(15)->minutes();
    }

    Event::assertDispatchedTimes(CredentialExpiringSoon::class, 1);
    Event::assertDispatched(CredentialExpiringSoon::class, fn (CredentialExpiringSoon $event) => $event->token->is($token)
        && $event->expiresAt->equalTo($expiresAt)
        && filled($event->reason));

    // The token string was replaced once; the provider was not "renewed" again on every tick.
    expect(count(Event::dispatched(CredentialRenewed::class)))->toBeLessThanOrEqual(1);
})->with('meta credentials renewed without extension');

it('moves renew_at to the expiry and keeps the credential usable when a renewal did not extend it', function (string $provider, string $accountProvider, string $host) {
    $expiresAt = now()->addDays(6);
    fakeNonExtendingMetaEndpoint($host, $expiresAt);
    $token = nonExtendingGuardCredential($provider, $accountProvider, $expiresAt);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    $fresh = $token->fresh();

    expect($fresh->expires_at?->toDateTimeString())->toBe($expiresAt->toDateTimeString()) // the known expiry is kept
        ->and($fresh->renew_at?->toDateTimeString())->toBe($expiresAt->toDateTimeString()) // next pass lands at expiry
        ->and($fresh->isDueForRenewal())->toBeFalse()
        ->and($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->access_token)->toBe('same-lifetime-token');

    // Nothing is cut off early: the account still posts until the token dies.
    $account = SocialAccount::query()->where('social_token_id', $token->getKey())->firstOrFail();

    expect(app(SocialTokens::class)->validAccessTokenFor($account))->toBe('same-lifetime-token');
})->with('meta credentials renewed without extension');

it('logs the expires_in the provider returned when a renewal did not extend the expiry', function (string $provider, string $accountProvider, string $host) {
    config()->set('logging.default', 'null');
    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged) {
        $logged[] = $message;
    });

    $expiresAt = now()->addDays(6);
    $returnedExpiresIn = (int) now()->diffInSeconds($expiresAt);
    fakeNonExtendingMetaEndpoint($host, $expiresAt);
    nonExtendingGuardCredential($provider, $accountProvider, $expiresAt);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    $withExpiresIn = collect($logged)->filter(fn (MessageLogged $message) => isset($message->context['expires_in'])
        && abs((int) $message->context['expires_in'] - $returnedExpiresIn) <= 5);

    expect($withExpiresIn->isNotEmpty())->toBeTrue(
        "No log entry carried the expires_in ({$returnedExpiresIn}s) returned by {$provider}."
    );
})->with('meta credentials renewed without extension');

it('still flags the credential for reconnection once the non-extended token actually expires', function (string $provider, string $accountProvider, string $host) {
    Event::fake([CredentialNeedsReconnect::class]);
    $expiresAt = now()->addDays(6);
    fakeNonExtendingMetaEndpoint($host, $expiresAt);
    $token = nonExtendingGuardCredential($provider, $accountProvider, $expiresAt);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    // The pass right after the real expiry: Meta now answers 190.
    $this->travelTo($expiresAt->copy()->addMinutes(5));
    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);

    $account = SocialAccount::query()->where('social_token_id', $token->getKey())->firstOrFail();

    expect(fn () => app(SocialTokens::class)->validAccessTokenFor($account))
        ->toThrow(NeedsReconnectException::class);
})->with('meta credentials renewed without extension');

it('keeps the lead-time window and raises no warning when a renewal does extend the expiry', function (string $provider, string $accountProvider, string $host) {
    Event::fake([CredentialExpiringSoon::class, CredentialRenewed::class]);
    Http::fake([$host => Http::response([
        'access_token' => 'extended-token',
        'token_type' => 'bearer',
        'expires_in' => 60 * 86400,
    ])]);
    $renewedAt = now();
    $newExpiry = $renewedAt->copy()->addDays(60);
    $lead = app(SocialTokens::class)->connector($provider)->leadTime();
    $token = nonExtendingGuardCredential($provider, $accountProvider, now()->addDays(6));

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();
    $sentByFirstTick = count(Http::recorded());

    $this->travel(15)->minutes();
    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    $fresh = $token->fresh();

    expect(count(Http::recorded()))->toBe($sentByFirstTick)
        ->and($fresh->expires_at?->toDateTimeString())->toBe($newExpiry->toDateTimeString())
        // The usual window: one lead time before the new expiry.
        ->and($fresh->renew_at?->toDateTimeString())->toBe($newExpiry->copy()->sub($lead)->toDateTimeString())
        ->and($fresh->access_token)->toBe('extended-token');

    Event::assertNotDispatched(CredentialExpiringSoon::class);
    Event::assertDispatchedTimes(CredentialRenewed::class, 1);
})->with('meta credentials renewed without extension');

it('does not call any provider again when its renewal lands inside the lead time', function () {
    // Provider-agnostic guard: a refresh-token provider whose new access token
    // expires sooner than its lead time (lifetime < lead) must not loop either.
    // Only "not on the very next tick" is required: where exactly the next
    // renewal lands for such a provider (at expiry, halfway, after a backoff)
    // is left to the fix.
    FakeConnector::$lead = CarbonInterval::hours(2);
    FakeConnector::$nextResult = RenewalResult::success(
        accessToken: 'short-lived-token',
        expiresAt: now()->addHour(),
        refreshToken: 'next-refresh',
    );
    $token = nonExtendingGuardCredential('fake', 'fake', now()->addMinutes(90));

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    expect(FakeConnector::$renewCalls)->toBe(1)
        ->and($token->fresh()->isDueForRenewal())->toBeFalse('the renewal left the credential due');

    $this->travel(15)->minutes();
    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    expect(FakeConnector::$renewCalls)->toBe(1)
        ->and($token->fresh()->access_token)->toBe('short-lived-token');
});
