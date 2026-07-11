<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

function connector(string $provider): ProviderConnector
{
    return app(ConnectorRegistry::class)->for($provider);
}

function credential(string $provider, array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => 'h-'.uniqid(),
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('declares which connector refreshes each credential', function () {
    expect(connector('facebook')->credentialProvider())->toBe('facebook')
        ->and(connector('instagram')->credentialProvider())->toBe('facebook') // shared Meta token
        ->and(connector('threads')->credentialProvider())->toBe('threads')
        ->and(connector('tiktok')->credentialProvider())->toBe('tiktok')
        ->and(connector('google')->credentialProvider())->toBe('google')
        ->and(connector('linkedin')->credentialProvider())->toBe('linkedin');
});

it('refreshes a tiktok credential and rotates its refresh token', function () {
    Http::fake(['open.tiktokapis.com/*' => Http::response([
        'access_token' => 'new-access', 'expires_in' => 86400,
        'refresh_token' => 'new-refresh', 'refresh_expires_in' => 31536000,
    ])]);

    $result = connector('tiktok')->refreshCredential(credential('tiktok', ['refresh_token' => 'old-refresh']));

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('new-access')
        ->and($result->refreshToken)->toBe('new-refresh');
});

it('refreshes a google credential via the stable refresh token', function () {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'g-new', 'expires_in' => 3600])]);

    $result = connector('google')->refreshCredential(credential('google', ['refresh_token' => 'g-refresh']));

    expect($result->succeeded())->toBeTrue()->and($result->accessToken)->toBe('g-new');
});

it('refreshes a linkedin credential', function () {
    Http::fake(['linkedin.com/*' => Http::response(['access_token' => 'li-new', 'expires_in' => 5184000])]);

    $result = connector('linkedin')->refreshCredential(credential('linkedin', ['refresh_token' => 'li-refresh']));

    expect($result->succeeded())->toBeTrue()->and($result->accessToken)->toBe('li-new');
});

it('refreshes a threads credential by extending the long lived token', function () {
    Http::fake(['graph.threads.net/refresh_access_token*' => Http::response(['access_token' => 'th-new', 'expires_in' => 5183944])]);

    $result = connector('threads')->refreshCredential(credential('threads', ['access_token' => 'th-old']));

    expect($result->succeeded())->toBeTrue()->and($result->accessToken)->toBe('th-new');
});

it('refreshes a meta credential by extending the user token (no page derivation)', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'fresh-user', 'expires_in' => 5183944])]);

    $result = connector('facebook')->refreshCredential(credential('facebook', ['access_token' => 'user-token']));

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('fresh-user')
        ->and($result->expiresAt)->not->toBeNull();

    // Single-phase: it must NOT call /me/accounts to derive page tokens.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/accounts'));
});

it('lets the instagram connector refresh the shared meta credential too', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'fresh-user', 'expires_in' => 5183944])]);

    $result = connector('instagram')->refreshCredential(credential('facebook', ['access_token' => 'user-token']));

    expect($result->succeeded())->toBeTrue()->and($result->accessToken)->toBe('fresh-user');
});

it('is terminal when the credential has no token to refresh', function () {
    Http::fake();

    expect(connector('tiktok')->refreshCredential(credential('tiktok'))->outcome)->toBe(RenewalOutcome::Terminal)
        ->and(connector('threads')->refreshCredential(credential('threads'))->outcome)->toBe(RenewalOutcome::Terminal)
        ->and(connector('facebook')->refreshCredential(credential('facebook'))->outcome)->toBe(RenewalOutcome::Terminal);

    Http::assertNothingSent();
});
