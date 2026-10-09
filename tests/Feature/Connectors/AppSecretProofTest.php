<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Connectors\InstagramConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/*
 * Meta's "Require App Secret" app setting makes every Graph call made with a
 * user or Page token carry appsecret_proof, an HMAC-SHA256 of that token keyed
 * with the app secret: a stolen token is then useless without the secret.
 * The package's own calls (/me at connect and in check-static, /me/accounts)
 * sent none, so turning the setting on broke every Facebook and Instagram
 * connection and the static check.
 */

function appSecretProofFacebook(): FacebookConnector
{
    return app(ConnectorRegistry::class)->for('facebook');
}

function appSecretProofFor(string $token): string
{
    return hash_hmac('sha256', $token, 'facebook-secret');
}

/** The appsecret_proof values of a request's query string. */
function appSecretProofsIn(Request $request): array
{
    preg_match_all('/(?:^|&)appsecret_proof=([^&]*)/', (string) parse_url($request->url(), PHP_URL_QUERY), $all);

    return $all[1];
}

it('signs /me with the proof of the token it sends', function () {
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'user-1'])]);

    expect(appSecretProofFacebook()->fetchUserId('user-token'))->toBe('user-1');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer user-token')
        && $request['appsecret_proof'] === appSecretProofFor('user-token'));
});

it('signs the Page token check', function () {
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'page-1'])]);

    $result = appSecretProofFacebook()->checkCredential(new SocialToken([
        'provider' => 'facebook',
        'access_token' => 'page-token',
        'status' => AccountStatus::Active,
    ]));

    expect($result->outcome)->toBe(RenewalOutcome::Success);

    Http::assertSent(fn (Request $request) => $request['appsecret_proof'] === appSecretProofFor('page-token'));
});

it('signs every page of /me/accounts, adding the proof to paging.next once', function (string $next) {
    Http::fake(fn (Request $request) => str_contains($request->url(), 'after=CURSOR2')
        ? Http::response(['data' => [['id' => 'p2']]])
        : Http::response(['data' => [['id' => 'p1']], 'paging' => ['next' => $next]]));

    expect(appSecretProofFacebook()->fetchPages('user-token'))->toBe([['id' => 'p1'], ['id' => 'p2']]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'after=')
        && $request['appsecret_proof'] === appSecretProofFor('user-token'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'after=CURSOR2')
        && appSecretProofsIn($request) === [appSecretProofFor('user-token')]);
})->with([
    'without a proof' => 'https://graph.facebook.com/v23.0/me/accounts?limit=100&after=CURSOR2',
    'with Meta\'s proof' => 'https://graph.facebook.com/v23.0/me/accounts?limit=100&appsecret_proof='.hash_hmac('sha256', 'user-token', 'facebook-secret').'&after=CURSOR2',
]);

it('signs the connect-time page listing with the extended user token', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'long-user-token', 'expires_in' => 5183944]),
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [['id' => 'p1', 'name' => 'Page', 'access_token' => 'page-token']]]),
        'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['scopes' => []]]),
    ]);

    app(StoreFacebookPages::class)->handle(userToken: 'short-user-token', userId: 'fb-user');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me/accounts')
        && $request['appsecret_proof'] === appSecretProofFor('long-user-token'));
});

it('sends no proof when it is turned off', function (mixed $setting) {
    config(['social-tokens.connectors.facebook.appsecret_proof' => $setting]);
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'user-1'])]);

    appSecretProofFacebook()->fetchUserId('user-token');

    Http::assertSent(fn (Request $request) => ! isset($request['appsecret_proof']));
    expect(appSecretProofFacebook()->appSecretProof('user-token'))->toBeNull();
})->with([false, 'false', 'off', '0']);

it('signs by default when a published config has no such key', function () {
    $facebook = config('social-tokens.connectors.facebook');
    unset($facebook['appsecret_proof']);
    config(['social-tokens.connectors.facebook' => $facebook]);

    expect(appSecretProofFacebook()->appSecretProof('user-token'))->toBe(appSecretProofFor('user-token'));
});

it('sends no proof without an app secret', function () {
    config(['services.facebook.client_secret' => null]);
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'user-1'])]);

    appSecretProofFacebook()->fetchUserId('user-token');

    Http::assertSent(fn (Request $request) => ! isset($request['appsecret_proof']));
});

it('gives apps the proof to sign their own Graph calls, for Facebook and Instagram', function () {
    $instagram = app(ConnectorRegistry::class)->for('instagram');

    expect($instagram)->toBeInstanceOf(InstagramConnector::class)
        ->and(appSecretProofFacebook()->appSecretProof('page-token'))->toBe(appSecretProofFor('page-token'))
        ->and($instagram->appSecretProof('user-token'))->toBe(appSecretProofFor('user-token'));
});
