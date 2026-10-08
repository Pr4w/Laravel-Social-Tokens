<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/*
 * The refresh flow shared by every OAuth 2.0 connector (OAuth2Connector):
 * TikTok, Google, LinkedIn (with refresh tokens) and X.
 */

beforeEach(function () {
    config()->set('social-tokens.connectors.linkedin.refresh_enabled', true);
    app()->forgetInstance(ConnectorRegistry::class);
});

function oauth2Credential(string $provider): SocialToken
{
    return SocialToken::create([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid(),
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'status' => AccountStatus::Active,
    ]);
}

dataset('oauth2 token endpoints', [
    'tiktok' => ['tiktok', 'open.tiktokapis.com/*'],
    'google' => ['google', 'oauth2.googleapis.com/*'],
    'linkedin' => ['linkedin', 'www.linkedin.com/*'],
    'x' => ['x', 'api.x.com/*'],
]);

it('does not crash on an error sent as an object', function (string $provider, string $host) {
    Http::fake([$host => Http::response(['error' => ['code' => 'weird', 'message' => 'Something odd']], 400)]);

    $result = app(ConnectorRegistry::class)->for($provider)->refreshCredential(oauth2Credential($provider));

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeTrue()
        ->and($result->reason)->toBe('weird: Something odd');
})->with('oauth2 token endpoints');

it('treats a success without an access token as malformed, without logging the body', function (string $provider, string $host) {
    Http::fake([$host => Http::response(['refresh_token' => 'secret-refresh', 'expires_in' => 3600])]);

    $result = app(ConnectorRegistry::class)->for($provider)->refreshCredential(oauth2Credential($provider));

    expect($result->unknown)->toBeTrue()
        ->and(json_encode($result->context))->not->toContain('secret-refresh');
})->with('oauth2 token endpoints');

it('keeps the error code and description in the result context', function (string $provider, string $host) {
    Http::fake([$host => Http::response(['error' => 'invalid_grant', 'error_description' => 'gone'], 400)]);

    $result = app(ConnectorRegistry::class)->for($provider)->refreshCredential(oauth2Credential($provider));

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->context)->toMatchArray(['error' => 'invalid_grant', 'error_description' => 'gone', 'status' => 400]);
})->with('oauth2 token endpoints');

it('treats TikTok unauthorized_client as an app-client failure, like the other providers', function () {
    Http::fake(['open.tiktokapis.com/*' => Http::response(['error' => 'unauthorized_client', 'error_description' => 'Client is not authorized'], 401)]);

    $result = app(ConnectorRegistry::class)->for('tiktok')->refreshCredential(oauth2Credential('tiktok'));

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->clientError)->toBeTrue();
});
