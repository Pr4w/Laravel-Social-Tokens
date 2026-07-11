<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

function tiktok(): \Pr4w\SocialTokens\Connectors\TikTokConnector
{
    return app(ConnectorRegistry::class)->for('tiktok');
}

function tiktokAccount(array $attrs = []): SocialAccount
{
    return SocialAccount::create(array_merge([
        'provider' => 'tiktok',
        'provider_user_id' => 'tt-1',
        'refresh_token' => 'refresh-1',
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('declares its strategy and lead time', function () {
    expect(tiktok()->renewalStrategy())->toBe(RenewalStrategy::RotatingRefreshToken)
        ->and(tiktok()->leadTime()->totalHours)->toBe(2.0);
});

it('rotates the refresh token on a successful renewal', function () {
    Http::fake(['open.tiktokapis.com/*' => Http::response([
        'access_token' => 'new-access',
        'expires_in' => 86400,
        'refresh_token' => 'new-refresh',
        'refresh_expires_in' => 31536000,
        'open_id' => 'open-123',
        'scope' => 'video.publish',
    ])]);

    $result = tiktok()->renew(tiktokAccount());

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('new-access')
        ->and($result->refreshToken)->toBe('new-refresh')
        ->and($result->expiresAt)->not->toBeNull()
        ->and($result->refreshExpiresAt)->not->toBeNull()
        ->and($result->profile)->toBe(['open_id' => 'open-123', 'scope' => 'video.publish']);

    Http::assertSent(fn ($request) => $request['client_key'] === 'tiktok-id'
        && $request['client_secret'] === 'tiktok-secret'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-1');
});

it('returns terminal without calling the provider when the refresh token is missing', function () {
    Http::fake();

    $result = tiktok()->renew(tiktokAccount(['refresh_token' => null]));

    expect($result->outcome)->toBe(RenewalOutcome::Terminal);
    Http::assertNothingSent();
});

it('maps a known error to terminal', function () {
    Http::fake(['open.tiktokapis.com/*' => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'Refresh token invalid',
    ])]);

    expect(tiktok()->renew(tiktokAccount())->outcome)->toBe(RenewalOutcome::Terminal);
});

it('maps an unrecognised error to unknown/transient', function () {
    Http::fake(['open.tiktokapis.com/*' => Http::response([
        'error' => 'server_hiccup',
        'error_description' => 'try later',
    ])]);

    $result = tiktok()->renew(tiktokAccount());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeTrue();
});

it('treats a 5xx as transient', function () {
    Http::fake(['open.tiktokapis.com/*' => Http::response('boom', 503)]);

    expect(tiktok()->renew(tiktokAccount())->outcome)->toBe(RenewalOutcome::Transient);
});

it('treats a 429 as transient', function () {
    Http::fake(['open.tiktokapis.com/*' => Http::response('slow down', 429)]);

    expect(tiktok()->renew(tiktokAccount())->outcome)->toBe(RenewalOutcome::Transient);
});

it('treats a connection error as transient', function () {
    Http::fake(fn () => throw new ConnectionException('network down'));

    expect(tiktok()->renew(tiktokAccount())->outcome)->toBe(RenewalOutcome::Transient);
});

it('flags a malformed success body as unknown', function () {
    Http::fake(['open.tiktokapis.com/*' => Http::response(['unexpected' => true])]);

    $result = tiktok()->renew(tiktokAccount());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeTrue()
        ->and($result->reason)->toContain('Malformed');
});

it('revokes the token best-effort', function () {
    Http::fake(['open.tiktokapis.com/*' => Http::response(['ok' => true])]);

    tiktok()->revoke(tiktokAccount(['access_token' => 'live-token']));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/revoke/')
        && $request['token'] === 'live-token');
});

it('does not attempt a revoke without an access token', function () {
    Http::fake();

    tiktok()->revoke(tiktokAccount(['access_token' => null]));

    Http::assertNothingSent();
});
