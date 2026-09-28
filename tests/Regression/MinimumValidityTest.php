<?php

/*
 * Regression: a caller cannot ask validAccessTokenFor() for a token that stays
 * valid long enough for the work it is about to do.
 *
 * In v1.1.0, SocialTokens::validAccessTokenFor() only renews when the token
 * expires within a fixed 30 s margin (SocialToken::isAccessTokenExpired()'s
 * default buffer). Any token with 31 s or more left is handed out as-is, even
 * to a job that will hold it for minutes: Instagram/Threads container polling
 * (up to 15 min), a YouTube or TikTok upload, X media/upload followed by the
 * tweet. The token can die halfway through, and the caller has no way to ask
 * for more. The only workaround is to call renewCredential() by hand, which
 * does nothing unless renew_at has passed.
 *
 * Desired API:
 *
 *   validAccessTokenFor(SocialAccount $account, int $minValiditySeconds = 30): string
 *
 * - When expires_at < now() + $minValiditySeconds and the connector can renew
 *   unattended, the credential is renewed synchronously under the
 *   per-credential lock. The double-check under the lock honours the same
 *   minimum: it reuses a renewal another process just finished, and does not
 *   skip a renewal that is still needed just because renew_at is in the future.
 * - The 30 s margin stays the default and the floor: a smaller (or negative)
 *   minimum never hands out a token that expires within 30 s.
 * - A token that already covers the minimum is returned without any call.
 * - A static credential (no expiry) is returned as-is, and so is a renewable
 *   credential whose expiry is unknown (the scheduled job checks it at
 *   renew_at): it is not renewed on every call.
 * - When the connector cannot renew unattended (reauth-only, dead refresh
 *   token), a token that is still valid is returned as-is, not flagged.
 * - When the requested minimum exceeds what the provider issues, the credential
 *   is renewed once and the fresh token is returned (no loop, no exception).
 * - A failed renewal behaves as it does for an expired token: transient
 *   failures throw a transient NeedsReconnectException and leave the credential
 *   untouched; terminal failures flag the credential.
 *
 * Calls pass the minimum positionally so that, on v1.1.0, the extra argument
 * is ignored and each test fails on the behaviour it asserts.
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

beforeEach(function () {
    Http::preventStrayRequests();
    FakeConnector::reset();

    // Whole seconds, so expiries round-trip through the datetime columns intact.
    $this->freezeSecond();
});

/**
 * A usable credential for the given connector.
 *
 * @param  array<string, mixed>  $attributes
 */
function minValidityCredential(string $provider, array $attributes = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid('', true),
        'access_token' => 'current-token',
        'refresh_token' => 'current-refresh',
        'status' => AccountStatus::Active,
    ], $attributes));
}

function minValidityAccount(SocialToken $token): SocialAccount
{
    return SocialAccount::create([
        'provider' => $token->provider,
        'provider_user_id' => 'acct-'.uniqid('', true),
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);
}

function minValidityTokens(): SocialTokens
{
    return app(SocialTokens::class);
}

// API ------------------------------------------------------------------------

it('lets the caller ask for a minimum validity, defaulting to the 30-second margin', function () {
    $parameters = (new ReflectionMethod(SocialTokens::class, 'validAccessTokenFor'))->getParameters();

    // The parameter name is public API (callers may pass it by name); later
    // optional parameters are not ruled out.
    expect(count($parameters))->toBeGreaterThanOrEqual(2)
        ->and($parameters[1]->getName())->toBe('minValiditySeconds')
        ->and((string) $parameters[1]->getType())->toBe('int')
        ->and($parameters[1]->isDefaultValueAvailable())->toBeTrue()
        ->and($parameters[1]->getDefaultValue())->toBe(30);
});

// Renewing to cover the requested minimum --------------------------------------

it('renews a Google token that would expire during a 15-minute job, before its renewal window opens', function () {
    Event::fake([CredentialRenewed::class]);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'fresh-google-token',
            'expires_in' => 3599,
            'token_type' => 'Bearer',
        ]),
    ]);

    // 12 minutes left and renew_at still 2 minutes ahead (Google's 10-minute
    // lead time in v1.1.0): the renewal window has not opened, so neither the
    // dispatcher nor renewCredential()'s double-check considers it due yet.
    $token = minValidityCredential('google', [
        'expires_at' => now()->addMinutes(12),
        'renew_at' => now()->addMinutes(2),
    ]);

    $accessToken = minValidityTokens()->validAccessTokenFor(minValidityAccount($token), 15 * 60);

    expect($accessToken)->toBe('fresh-google-token')
        ->and($token->fresh()->access_token)->toBe('fresh-google-token')
        ->and($token->fresh()->expires_at->greaterThan(now()->addMinutes(15)))->toBeTrue();

    Http::assertSentCount(1);
    Event::assertDispatchedTimes(CredentialRenewed::class, 1);
});

it('renews a due TikTok token before a 30-minute upload and keeps the rotated refresh token', function () {
    Http::fake([
        'open.tiktokapis.com/*' => Http::response([
            'access_token' => 'fresh-tiktok-token',
            'expires_in' => 86400,
            'refresh_token' => 'rotated-refresh',
            'refresh_expires_in' => 31536000,
            'open_id' => 'open-id',
            'scope' => 'video.publish',
        ]),
    ]);

    // 5 minutes left: due for renewal (2-hour lead time) but the dispatcher has
    // not passed yet, and it is well outside the fixed 30 s margin.
    $token = minValidityCredential('tiktok', [
        'expires_at' => now()->addMinutes(5),
        'renew_at' => now()->subMinutes(115),
    ]);

    $accessToken = minValidityTokens()->validAccessTokenFor(minValidityAccount($token), 30 * 60);

    expect($accessToken)->toBe('fresh-tiktok-token')
        ->and($token->fresh()->refresh_token)->toBe('rotated-refresh');

    Http::assertSentCount(1);
});

it('reuses a renewal another process already stored instead of refreshing again', function () {
    $token = minValidityCredential('fake', [
        'expires_at' => now()->addMinutes(5),
        'renew_at' => now()->addMinutes(4),
    ]);
    $account = minValidityAccount($token);

    // This request's copy of the credential, about to go stale.
    $account->load('credential');

    // Another worker renews the credential in the meantime.
    SocialToken::find($token->getKey())->update([
        'access_token' => 'renewed-elsewhere',
        'expires_at' => now()->addHour(),
        'renew_at' => now()->addMinutes(45),
    ]);

    $accessToken = minValidityTokens()->validAccessTokenFor($account, 15 * 60);

    expect($accessToken)->toBe('renewed-elsewhere')
        ->and(FakeConnector::$renewCalls)->toBe(0); // no second refresh at the provider
});

it('renews once and hands out the fresh token when the requested minimum exceeds the token lifetime', function () {
    // The provider only ever issues one-hour tokens.
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'one-hour-token', expiresAt: now()->addHour());

    $token = minValidityCredential('fake', [
        'expires_at' => now()->addMinutes(50),
        'renew_at' => now()->addMinutes(35),
    ]);

    $accessToken = minValidityTokens()->validAccessTokenFor(minValidityAccount($token), 2 * 3600);

    expect($accessToken)->toBe('one-hour-token')
        ->and(FakeConnector::$renewCalls)->toBe(1);
});

// Failures while renewing for the minimum ------------------------------------

it('throws a transient error and keeps the credential when a minimum-validity renewal fails transiently', function () {
    FakeConnector::$nextResult = RenewalResult::transientFailure('provider 503');

    $token = minValidityCredential('fake', [
        'expires_at' => now()->addMinutes(5),
        'renew_at' => now()->addMinutes(4),
    ]);

    try {
        $accessToken = minValidityTokens()->validAccessTokenFor(minValidityAccount($token), 15 * 60);
        $this->fail("Expected a transient NeedsReconnectException, got [{$accessToken}], a token that dies within 5 minutes.");
    } catch (NeedsReconnectException $e) {
        expect($e->transient)->toBeTrue()
            ->and(FakeConnector::$renewCalls)->toBe(1)
            ->and($token->fresh()->status)->toBe(AccountStatus::Active)
            ->and($token->fresh()->access_token)->toBe('current-token');
    }
});

it('flags the credential when a minimum-validity renewal is rejected for good', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ], 400),
    ]);

    $token = minValidityCredential('google', [
        'expires_at' => now()->addMinutes(12),
        'renew_at' => now()->addMinutes(2),
    ]);

    try {
        $accessToken = minValidityTokens()->validAccessTokenFor(minValidityAccount($token), 15 * 60);
        $this->fail("Expected a NeedsReconnectException, got [{$accessToken}] without any renewal attempt.");
    } catch (NeedsReconnectException $e) {
        expect($e->transient)->toBeFalse()
            ->and($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);

        Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
    }
});

// Guards: behaviour that must not change -------------------------------------

it('keeps the 30-second default margin when no minimum is asked', function () {
    $token = minValidityCredential('fake', [
        'expires_at' => now()->addMinutes(5),
        'renew_at' => now()->addMinutes(4),
    ]);

    expect(minValidityTokens()->validAccessTokenFor(minValidityAccount($token)))->toBe('current-token')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('does not renew a token that already covers the requested minimum', function () {
    $token = minValidityCredential('fake', [
        'expires_at' => now()->addMinutes(20),
        'renew_at' => now()->addMinutes(5),
    ]);

    expect(minValidityTokens()->validAccessTokenFor(minValidityAccount($token), 15 * 60))->toBe('current-token')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('never hands out a token inside the 30-second margin, even when a smaller minimum is asked', function (int $minValiditySeconds) {
    $token = minValidityCredential('fake', [
        'expires_at' => now()->addSeconds(20),
        'renew_at' => now()->subMinutes(15),
    ]);

    expect(minValidityTokens()->validAccessTokenFor(minValidityAccount($token), $minValiditySeconds))->toBe('renewed-token')
        ->and(FakeConnector::$renewCalls)->toBe(1);
})->with([
    'zero' => 0,
    'ten seconds' => 10,
]);

it('returns a static credential as-is whatever minimum is asked', function () {
    Http::fake();

    $token = minValidityCredential('facebook', [
        'access_token' => 'page-token',
        'refresh_token' => null,
        'expires_at' => null,
        'renew_at' => null,
    ]);

    expect(minValidityTokens()->validAccessTokenFor(minValidityAccount($token), 3600))->toBe('page-token');

    Http::assertNothingSent();
});

it('does not renew a renewable credential of unknown expiry on every call', function () {
    // A renewal that came back without an expiry: expires_at is unknown and the
    // scheduled job checks back at renew_at. Renewing here would hit the
    // provider on every post, since the next renewal may again carry no expiry.
    $token = minValidityCredential('fake', [
        'expires_at' => null,
        'renew_at' => now()->addMinutes(10),
    ]);

    expect(minValidityTokens()->validAccessTokenFor(minValidityAccount($token), 15 * 60))->toBe('current-token')
        ->and(FakeConnector::$renewCalls)->toBe(0);
});

it('hands out a still-valid token without flagging it when the credential cannot be renewed unattended', function (string $blocker) {
    Event::fake([CredentialNeedsReconnect::class]);

    if ($blocker === 'reauth only') {
        FakeConnector::$strategy = RenewalStrategy::ReauthOnly;
    }

    $token = minValidityCredential('fake', array_filter([
        'expires_at' => now()->addMinutes(10),
        'renew_at' => now()->addMinutes(10),
        'refresh_expires_at' => $blocker === 'refresh token expired' ? now()->subDay() : null,
    ]));

    expect(minValidityTokens()->validAccessTokenFor(minValidityAccount($token), 15 * 60))->toBe('current-token')
        ->and(FakeConnector::$renewCalls)->toBe(0)
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
})->with([
    'reauth only',
    'refresh token expired',
]);
