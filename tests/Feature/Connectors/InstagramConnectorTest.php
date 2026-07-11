<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Connectors\InstagramConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

function instagram(): InstagramConnector
{
    return app(ConnectorRegistry::class)->for('instagram');
}

function instagramCredential(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'facebook', // Instagram shares the Meta credential
        'provider_holder_id' => 'ig-user-'.uniqid(),
        'access_token' => 'ig-long-lived',
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('uses the extend-long-lived strategy and shares the Meta credential', function () {
    expect(instagram()->renewalStrategy())->toBe(RenewalStrategy::ExtendLongLived)
        ->and(instagram()->leadTime()->totalDays)->toBe(7.0)
        ->and(instagram()->credentialProvider())->toBe('facebook');
});

it('refreshes by extending the long lived token via fb_exchange_token', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response([
        'access_token' => 'extended', 'expires_in' => 5183944,
    ])]);

    $result = instagram()->refreshCredential(instagramCredential());

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('extended')
        ->and($result->refreshToken)->toBeNull();

    Http::assertSent(fn ($request) => $request['grant_type'] === 'fb_exchange_token'
        && $request['fb_exchange_token'] === 'ig-long-lived'
        && $request['client_id'] === 'facebook-id');   // shares the Meta app
});

it('is terminal without an access token', function () {
    Http::fake();

    expect(instagram()->refreshCredential(instagramCredential(['access_token' => null]))->outcome)->toBe(RenewalOutcome::Terminal);
});

it('maps a meta error to terminal', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response([
        'error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'bad token'],
    ])]);

    expect(instagram()->refreshCredential(instagramCredential())->outcome)->toBe(RenewalOutcome::Terminal);
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
