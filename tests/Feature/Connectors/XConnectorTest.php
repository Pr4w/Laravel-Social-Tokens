<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Connectors\XConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/*
 * X (Twitter) through OAuth 2.0 (Socialite's `x` driver): 2-hour access tokens,
 * single-use rotating refresh tokens (offline.access), confidential client
 * authenticated with HTTP Basic on api.x.com.
 */

function x(): XConnector
{
    return app(ConnectorRegistry::class)->for('x');
}

function xCredential(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'x',
        'provider_holder_id' => 'x-user-1',
        'access_token' => 'x-access-1',
        'refresh_token' => 'x-refresh-1',
        'status' => AccountStatus::Active,
        'expires_at' => now()->addMinutes(20),
        'renew_at' => now()->subMinutes(10),
    ], $attrs));
}

function xTokenAnswers(int $status, array $body): void
{
    Http::fake(['api.x.com/2/oauth2/token' => Http::response($body, $status)]);
}

it('is a rotating refresh-token provider renewed half an hour ahead', function () {
    expect(x()->renewalStrategy())->toBe(RenewalStrategy::RotatingRefreshToken)
        ->and(x()->leadTime()->totalMinutes)->toBe(30.0)
        ->and(x()->credentialProvider())->toBe('x');
});

it('refreshes with HTTP Basic client authentication on api.x.com', function () {
    xTokenAnswers(200, [
        'token_type' => 'bearer',
        'expires_in' => 7200,
        'access_token' => 'x-access-2',
        'refresh_token' => 'x-refresh-2',
        'scope' => 'tweet.read tweet.write users.read media.write offline.access',
    ]);

    $result = x()->refreshCredential(xCredential());

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('x-access-2')
        ->and($result->refreshToken)->toBe('x-refresh-2') // rotated: must be stored
        ->and($result->expiresAt->timestamp)->toEqualWithDelta(now()->addHours(2)->timestamp, 2)
        ->and($result->profile)->toBe(['scope' => 'tweet.read tweet.write users.read media.write offline.access']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.x.com/2/oauth2/token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('x-id:x-secret'))
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'x-refresh-1'
        && $request['client_id'] === 'x-id'
        && ! isset($request['client_secret'])); // X rejects the secret in the body alone
});

it('renews end to end and stores the rotated refresh token', function () {
    xTokenAnswers(200, ['expires_in' => 7200, 'access_token' => 'x-access-2', 'refresh_token' => 'x-refresh-2', 'scope' => 'tweet.write offline.access']);
    $token = xCredential();

    expect(app(SocialTokens::class)->renewCredential($token)->succeeded())->toBeTrue();

    $fresh = $token->fresh();

    expect($fresh->access_token)->toBe('x-access-2')
        ->and($fresh->refresh_token)->toBe('x-refresh-2')
        ->and($fresh->scopes)->toBe(['tweet.write', 'offline.access'])
        ->and($fresh->renew_at->timestamp)->toEqualWithDelta(now()->addMinutes(90)->timestamp, 2);
});

it('treats the dead refresh token answer as terminal', function () {
    // What X answers for a used, rotated-out, expired or revoked refresh token
    // (and after the same X user authorised the app again elsewhere). It is
    // invalid_request, not invalid_grant.
    xTokenAnswers(400, ['error' => 'invalid_request', 'error_description' => 'Value passed for the token was invalid.']);

    $result = x()->refreshCredential(xCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->reason)->toContain('Value passed for the token was invalid');
});

it('flags the credential once a renewal hits a dead refresh token', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    xTokenAnswers(400, ['error' => 'invalid_request', 'error_description' => 'Value passed for the token was invalid.']);
    $token = xCredential();

    $result = app(SocialTokens::class)->renewCredential($token);
    $token->markNeedsReconnect($result->reason);

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('treats the app-side errors as client failures, never as a dead grant', function (int $status, string $error, string $description) {
    xTokenAnswers($status, ['error' => $error, 'error_description' => $description]);

    $result = x()->refreshCredential(xCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->clientError)->toBeTrue();
})->with([
    // A request the code built wrong mentions the refresh token, but is not about the member.
    'missing parameter' => [400, 'invalid_request', 'Missing required parameter [refresh_token].'],
    'missing basic auth header' => [401, 'unauthorized_client', 'Missing valid authorization header'],
    'app suspended' => [400, 'unauthorized_client', 'Client application is not allowed for this operation.'],
    'bad client' => [401, 'invalid_client', 'Client authentication failed'],
]);

it('retries rate limits and outages', function (int $status, array $body) {
    xTokenAnswers($status, $body);

    $result = x()->refreshCredential(xCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->clientError)->toBeFalse();
})->with([
    'rate limited' => [429, ['error' => 'rate_limited', 'error_description' => 'The request has been rate limited.']],
    'outage' => [503, ['error' => 'temporarily_unavailable']],
    'server error' => [500, []],
]);

it('asks for offline.access when there is no refresh token, without calling X', function () {
    Http::fake();

    $result = x()->refreshCredential(xCredential(['refresh_token' => null]));

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->reason)->toContain('offline.access');

    Http::assertNothingSent();
});

it('revokes the refresh token at X with Basic auth and a token type hint', function () {
    Http::fake(['api.x.com/2/oauth2/revoke' => Http::response(['revoked' => true])]);

    x()->revoke(xCredential());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.x.com/2/oauth2/revoke'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('x-id:x-secret'))
        && $request['token'] === 'x-refresh-1'
        && $request['token_type_hint'] === 'refresh_token');
});

it('falls back to revoking the access token, and never throws', function () {
    Http::fake(['api.x.com/2/oauth2/revoke' => Http::response(['error' => 'invalid_request'], 400)]);

    x()->revoke(xCredential(['refresh_token' => null]));

    Http::assertSent(fn (Request $request) => $request['token'] === 'x-access-1'
        && $request['token_type_hint'] === 'access_token');
});

it('stores an X connection from the Socialite x driver as a renewable credential', function () {
    $account = app(StoreConnection::class)->handle('x', socialiteUser([
        'id' => '1234567890',
        'nickname' => 'vivelavie',
        'token' => 'x-access',
        'refreshToken' => 'x-refresh',
        'expiresIn' => 7200,
        'approvedScopes' => ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'],
    ]))->sole();

    $credential = $account->credential;

    expect($account->provider)->toBe('x')
        ->and($account->provider_user_id)->toBe('1234567890')
        ->and($account->nickname)->toBe('vivelavie')
        ->and($credential->provider)->toBe('x')
        ->and($credential->refresh_token)->toBe('x-refresh')
        ->and($credential->renew_at->timestamp)->toEqualWithDelta(now()->addMinutes(90)->timestamp, 2)
        ->and($account->hasScope('tweet.write'))->toBeTrue();
});

it('is registered in the default config', function () {
    expect(config('social-tokens.connectors.x.driver'))->toBe(XConnector::class);
});
