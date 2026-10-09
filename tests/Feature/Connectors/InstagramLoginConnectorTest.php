<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Connectors\InstagramLoginConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/*
 * An Instagram professional account with no Facebook Page connects through
 * the "Instagram API with Instagram Login": graph.instagram.com,
 * ig_exchange_token at connect, ig_refresh_token to extend the 60-day token.
 * The only Instagram path used to be Facebook Login, through the Pages a
 * Facebook user manages: such an account could not be connected, and an
 * Instagram Login token sent there was refused by graph.facebook.com.
 */

function instagramLogin(): InstagramLoginConnector
{
    return app(ConnectorRegistry::class)->for('instagram_login');
}

function instagramLoginCredential(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'instagram_login',
        'provider_holder_id' => 'igl-'.uniqid(),
        'access_token' => 'long-lived',
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('extends its own long-lived token, under its own key', function () {
    expect(instagramLogin()->renewalStrategy())->toBe(RenewalStrategy::ExtendLongLived)
        ->and(instagramLogin()->leadTime()->totalDays)->toBe(7.0)
        ->and(instagramLogin()->credentialProvider())->toBe('instagram_login');
});

it('connects an Instagram account with no Facebook Page as one account', function () {
    $this->freezeSecond();
    Http::fake(['graph.instagram.com/access_token*' => Http::response([
        'access_token' => 'IGAA-long', 'token_type' => 'bearer', 'expires_in' => 5183944,
    ])]);

    $accounts = app(StoreConnection::class)->handle('instagram_login', socialiteUser([
        'id' => '17841400000000001', 'nickname' => 'creator', 'token' => 'IGAA-short', 'expiresIn' => 3600,
    ]));

    $account = $accounts->sole();
    $credential = $account->credential;

    expect($account->provider)->toBe('instagram_login')
        ->and($account->provider_user_id)->toBe('17841400000000001')
        ->and($credential->provider)->toBe('instagram_login')
        ->and($credential->provider_holder_id)->toBe('17841400000000001')
        ->and($credential->access_token)->toBe('IGAA-long')
        ->and($credential->expires_at->equalTo(now()->addSeconds(5183944)))->toBeTrue()
        ->and($credential->renew_at->equalTo(now()->addSeconds(5183944)->subDays(7)))->toBeTrue();

    // No Facebook Page fan-out, and the Instagram app's secret.
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com'));
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.instagram.com/access_token?')
        && $request['grant_type'] === 'ig_exchange_token'
        && $request['client_secret'] === 'instagram_login-secret'
        && $request['access_token'] === 'IGAA-short'
        && ! isset($request['client_id']));
});

it('refuses to store the short-lived token when the exchange fails', function () {
    Http::fake(['graph.instagram.com/*' => Http::response([
        'error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'Invalid OAuth access token.'],
    ], 400)]);

    expect(fn () => app(StoreConnection::class)->handle('instagram_login', socialiteUser(['token' => 'IGAA-short'])))
        ->toThrow(RuntimeException::class, 'Could not obtain a long-lived [instagram_login] token');

    expect(SocialToken::count())->toBe(0);
});

it('extends the token with ig_refresh_token and the token alone', function () {
    Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response([
        'access_token' => 'IGAA-extended', 'token_type' => 'bearer', 'expires_in' => 5183944,
    ])]);

    $result = instagramLogin()->refreshCredential(instagramLoginCredential(['last_renewed_at' => now()->subDays(53)]));

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('IGAA-extended')
        ->and($result->refreshToken)->toBeNull()
        ->and($result->expiresAt)->not->toBeNull();

    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'ig_refresh_token'
        && $request['access_token'] === 'long-lived'
        && ! isset($request['client_secret']));
});

it('does not ask for a refresh before the token is 24 hours old', function () {
    Http::fake();

    $result = instagramLogin()->refreshCredential(instagramLoginCredential(['last_renewed_at' => now()->subHours(2)]));

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->reason)->toBe('Instagram token is less than 24h old and cannot be refreshed yet.');

    Http::assertNothingSent();
});

it('flags a dead token as terminal', function () {
    Http::fake(['graph.instagram.com/*' => Http::response([
        'error' => ['type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460, 'message' => 'Session invalidated.'],
    ], 400)]);

    $token = instagramLoginCredential(['renew_at' => now()->subMinute(), 'expires_at' => now()->addDays(6)]);

    expect(app(SocialTokens::class)->renewCredential($token)->outcome)->toBe(RenewalOutcome::Terminal)
        ->and(instagramLogin()->refreshCredential(instagramLoginCredential(['access_token' => null]))->outcome)->toBe(RenewalOutcome::Terminal);
});

it('renews through the package and stores the extended token', function () {
    $this->freezeSecond();
    Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'IGAA-extended', 'expires_in' => 5183944])]);

    $token = instagramLoginCredential(['renew_at' => now()->subMinute(), 'expires_at' => now()->addDays(6)]);

    expect(app(SocialTokens::class)->renewCredential($token)->succeeded())->toBeTrue();

    $token->refresh();

    expect($token->access_token)->toBe('IGAA-extended')
        ->and($token->renew_at->equalTo(now()->addSeconds(5183944)->subDays(7)))->toBeTrue();
});

it('checks a token on graph.instagram.com/me', function () {
    Http::fake(['graph.instagram.com/*/me*' => Http::response(['id' => '17841400000000001'])]);

    expect(instagramLogin()->checkCredential(instagramLoginCredential())->succeeded())->toBeTrue();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.instagram.com/v23.0/me?')
        && $request['access_token'] === 'long-lived');
});

it('reads a session error on /me as definitive', function () {
    Http::fake(['graph.instagram.com/*/me*' => Http::response([
        'error' => ['type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460, 'message' => 'Session invalidated.'],
    ], 400)]);

    $result = instagramLogin()->checkCredential(instagramLoginCredential());

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->context['definitive'])->toBeTrue();
});

it('reads its app credentials from the services entry it is pointed at', function () {
    config()->set('services.instagram.client_secret', 'socialite-instagram-secret');
    config()->set('social-tokens.connectors.instagram_login.credentials', 'instagram');
    app()->forgetInstance(ConnectorRegistry::class);
    Http::fake(['graph.instagram.com/*' => Http::response(['access_token' => 'IGAA-long', 'expires_in' => 60])]);

    instagramLogin()->exchangeForLongLived('IGAA-short');

    Http::assertSent(fn (Request $request) => $request['client_secret'] === 'socialite-instagram-secret');
});

it('keeps its accounts apart from the Facebook Login ones', function () {
    Http::fake(['graph.instagram.com/*' => Http::response(['access_token' => 'IGAA-long', 'expires_in' => 5183944])]);

    app(StoreConnection::class)->handle('instagram_login', socialiteUser(['id' => 'ig-1', 'token' => 'IGAA-short']));

    expect(SocialAccount::where('provider', 'instagram')->exists())->toBeFalse()
        ->and(SocialToken::where('provider', 'facebook')->exists())->toBeFalse();
});

it('keeps the app secret out of the reason when graph.instagram.com cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: timed out for https://graph.instagram.com/access_token?grant_type=ig_exchange_token&client_secret=instagram_login-secret&access_token=IGAA-short'
    ));

    $result = instagramLogin()->exchangeForLongLived('IGAA-short');

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->reason)->not->toContain('instagram_login-secret')
        ->and($result->reason)->not->toContain('IGAA-short');
});
