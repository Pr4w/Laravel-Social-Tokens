<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Connectors\InstagramConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

function instagram(): InstagramConnector
{
    return app(ConnectorRegistry::class)->for('instagram');
}

function instagramAccount(array $attrs = []): SocialAccount
{
    return SocialAccount::create(array_merge([
        'provider' => 'instagram',
        'provider_user_id' => 'ig-1',
        'access_token' => 'ig-long-lived',
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('uses the extend-long-lived strategy', function () {
    expect(instagram()->renewalStrategy())->toBe(RenewalStrategy::ExtendLongLived)
        ->and(instagram()->leadTime()->totalDays)->toBe(7.0);
});

it('renews by extending the long lived token via fb_exchange_token', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response([
        'access_token' => 'extended', 'expires_in' => 5183944,
    ])]);

    $result = instagram()->renew(instagramAccount());

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('extended')
        ->and($result->refreshToken)->toBeNull();

    Http::assertSent(fn ($request) => $request['grant_type'] === 'fb_exchange_token'
        && $request['fb_exchange_token'] === 'ig-long-lived'
        && $request['client_id'] === 'facebook-id');   // shares the Meta app
});

it('is terminal without an access token', function () {
    Http::fake();

    expect(instagram()->renew(instagramAccount(['access_token' => null]))->outcome)->toBe(RenewalOutcome::Terminal);
});

it('maps a meta error to terminal', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response([
        'error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'bad token'],
    ])]);

    expect(instagram()->renew(instagramAccount())->outcome)->toBe(RenewalOutcome::Terminal);
});

it('exchanges a short lived token via the same call', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response([
        'access_token' => 'long', 'expires_in' => 5183944,
    ])]);

    $result = instagram()->exchangeForLongLived('short');

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('long');

    Http::assertSent(fn ($request) => $request['fb_exchange_token'] === 'short');
});
