<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

function google(): \Pr4w\SocialTokens\Connectors\GoogleConnector
{
    return app(ConnectorRegistry::class)->for('google');
}

function googleAccount(array $attrs = []): SocialAccount
{
    return SocialAccount::create(array_merge([
        'provider' => 'google',
        'provider_user_id' => 'g-1',
        'refresh_token' => 'refresh-1',
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('uses the stable refresh strategy', function () {
    expect(google()->renewalStrategy())->toBe(RenewalStrategy::StableRefreshToken)
        ->and(google()->leadTime()->totalMinutes)->toBe(10.0);
});

it('renews without returning a new refresh token', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'new-access',
        'expires_in' => 3600,
        'scope' => 'https://www.googleapis.com/auth/youtube.upload',
    ])]);

    $result = google()->renew(googleAccount());

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('new-access')
        ->and($result->refreshToken)->toBeNull()
        ->and($result->profile)->toBe(['scope' => 'https://www.googleapis.com/auth/youtube.upload']);

    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
        && $request['client_id'] === 'google-id'
        && $request['refresh_token'] === 'refresh-1');
});

it('is terminal without a refresh token', function () {
    Http::fake();

    expect(google()->renew(googleAccount(['refresh_token' => null]))->outcome)->toBe(RenewalOutcome::Terminal);
    Http::assertNothingSent();
});

it('maps invalid_grant to terminal', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(google()->renew(googleAccount())->outcome)->toBe(RenewalOutcome::Terminal);
});

it('maps an unknown oauth error to unknown/transient', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'temporarily_unavailable'], 400)]);

    $result = google()->renew(googleAccount());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeTrue();
});

it('revokes using the refresh token when present', function () {
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response('')]);

    google()->revoke(googleAccount(['access_token' => 'a', 'refresh_token' => 'r']));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/revoke') && $request['token'] === 'r');
});
