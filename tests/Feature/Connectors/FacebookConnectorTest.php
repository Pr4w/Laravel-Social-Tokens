<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;

function facebook(): \Pr4w\SocialTokens\Connectors\FacebookConnector
{
    return app(ConnectorRegistry::class)->for('facebook');
}

function facebookAccount(array $attrs = []): SocialAccount
{
    return SocialAccount::create(array_merge([
        'provider' => 'facebook',
        'provider_user_id' => 'page-1',
        'refresh_token' => 'user-token',   // the root user token
        'status' => AccountStatus::Active,
    ], $attrs));
}

/** Fake the two Graph calls renew() makes, keyed by path. */
function fakeGraph(array $extend, array $pages): void
{
    Http::fake(function ($request) use ($extend, $pages) {
        if (str_contains($request->url(), '/oauth/access_token')) {
            return Http::response($extend);
        }

        if (str_contains($request->url(), '/me/accounts')) {
            return Http::response($pages);
        }

        return Http::response([], 404);
    });
}

it('uses the rotating strategy', function () {
    expect(facebook()->renewalStrategy())->toBe(RenewalStrategy::RotatingRefreshToken)
        ->and(facebook()->leadTime()->totalDays)->toBe(7.0);
});

it('extends the user token then re-derives this page token', function () {
    fakeGraph(
        extend: ['access_token' => 'fresh-user-token', 'expires_in' => 5183944],
        pages: ['data' => [
            ['id' => 'page-2', 'access_token' => 'page-2-token'],
            ['id' => 'page-1', 'access_token' => 'page-1-token'],
        ]],
    );

    $result = facebook()->renew(facebookAccount());

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('page-1-token')     // this page's token
        ->and($result->refreshToken)->toBe('fresh-user-token') // rotated user token
        ->and($result->expiresAt)->not->toBeNull();
});

it('is terminal when the page is no longer managed', function () {
    fakeGraph(
        extend: ['access_token' => 'fresh-user-token', 'expires_in' => 5183944],
        pages: ['data' => [['id' => 'some-other-page', 'access_token' => 'x']]],
    );

    expect(facebook()->renew(facebookAccount())->outcome)->toBe(RenewalOutcome::Terminal);
});

it('is terminal without a user token', function () {
    Http::fake();

    expect(facebook()->renew(facebookAccount(['refresh_token' => null]))->outcome)->toBe(RenewalOutcome::Terminal);
    Http::assertNothingSent();
});

it('propagates a terminal error from the token extension', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response([
        'error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'expired'],
    ])]);

    expect(facebook()->renew(facebookAccount())->outcome)->toBe(RenewalOutcome::Terminal);
});

it('extends a user token and returns its expiry', function () {
    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response([
        'access_token' => 'long-user-token', 'expires_in' => 5183944,
    ])]);

    $extended = facebook()->extendUserToken('short-user-token');

    expect($extended)->toBeArray()
        ->and($extended['token'])->toBe('long-user-token')
        ->and($extended['expiresAt'])->not->toBeNull();

    Http::assertSent(fn ($request) => $request['grant_type'] === 'fb_exchange_token'
        && $request['fb_exchange_token'] === 'short-user-token'
        && $request['client_id'] === 'facebook-id');
});

it('fetches pages with the requested fields', function () {
    Http::fake(['graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
        ['id' => 'p1', 'name' => 'Page One', 'access_token' => 't1'],
    ]])]);

    $pages = facebook()->fetchPages('user-token', 'id,name,access_token');

    expect($pages)->toBe([['id' => 'p1', 'name' => 'Page One', 'access_token' => 't1']]);

    Http::assertSent(fn ($request) => $request['fields'] === 'id,name,access_token');
});

it('resolves the facebook user id', function () {
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'user-999'])]);

    expect(facebook()->fetchUserId('user-token'))->toBe('user-999');
});

it('resolves per-account scopes from debug_token, honouring granular target_ids', function () {
    Http::fake(['graph.facebook.com/*/debug_token*' => Http::response(['data' => [
        'scopes' => ['public_profile', 'pages_show_list', 'pages_manage_posts', 'instagram_content_publish'],
        'granular_scopes' => [
            ['scope' => 'pages_manage_posts', 'target_ids' => ['page-1']],
            ['scope' => 'instagram_content_publish', 'target_ids' => ['ig-1']],
            ['scope' => 'pages_show_list'], // untargeted -> applies to all
        ],
    ]])]);

    $scopes = facebook()->grantedScopesByAccount('user-token', ['page-1', 'page-2', 'ig-1']);

    expect($scopes['page-1'])->toBe(['public_profile', 'pages_show_list', 'pages_manage_posts'])
        ->and($scopes['page-2'])->toBe(['public_profile', 'pages_show_list'])
        ->and($scopes['ig-1'])->toBe(['public_profile', 'pages_show_list', 'instagram_content_publish']);

    // App token is the "{id}|{secret}" form — no endpoint call.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/debug_token')
        && $request['access_token'] === 'facebook-id|facebook-secret');
});

it('surfaces a failure from debug_token as a renewal result', function () {
    Http::fake(['graph.facebook.com/*/debug_token*' => Http::response([
        'data' => ['error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'bad']],
    ])]);

    expect(facebook()->grantedScopesByAccount('user-token', ['page-1']))
        ->toBeInstanceOf(RenewalResult::class);
});
