<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Connectors\ThreadsConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

function threads(): ThreadsConnector
{
    return app(ConnectorRegistry::class)->for('threads');
}

function threadsCredential(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'threads',
        'provider_holder_id' => 'th-'.uniqid(),
        'access_token' => 'long-lived',
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('uses the extend-long-lived strategy', function () {
    expect(threads()->renewalStrategy())->toBe(RenewalStrategy::ExtendLongLived)
        ->and(threads()->leadTime()->totalDays)->toBe(7.0);
});

it('refreshes the long lived token with no credentials', function () {
    Http::fake(['graph.threads.net/refresh_access_token*' => Http::response([
        'access_token' => 'refreshed', 'expires_in' => 5183944,
    ])]);

    $result = threads()->refreshCredential(threadsCredential());

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('refreshed')
        ->and($result->refreshToken)->toBeNull();

    Http::assertSent(fn ($request) => $request['grant_type'] === 'th_refresh_token'
        && $request['access_token'] === 'long-lived'
        && ! isset($request['client_secret']));
});

it('is terminal without an access token', function () {
    Http::fake();

    expect(threads()->refreshCredential(threadsCredential(['access_token' => null]))->outcome)->toBe(RenewalOutcome::Terminal);
    Http::assertNothingSent();
});

it('maps a meta error to terminal on renewal', function () {
    Http::fake(['graph.threads.net/refresh_access_token*' => Http::response([
        'error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'expired'],
    ])]);

    expect(threads()->refreshCredential(threadsCredential())->outcome)->toBe(RenewalOutcome::Terminal);
});

it('exchanges a short lived token for a long lived one with credentials', function () {
    Http::fake(['graph.threads.net/access_token*' => Http::response([
        'access_token' => 'now-long-lived', 'expires_in' => 5183944,
    ])]);

    $result = threads()->exchangeForLongLived('short-token');

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('now-long-lived');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/access_token')
        && ! str_contains($request->url(), 'refresh_access_token')
        && $request['grant_type'] === 'th_exchange_token'
        && $request['client_id'] === 'threads-id'
        && $request['client_secret'] === 'threads-secret'
        && $request['access_token'] === 'short-token');
});
