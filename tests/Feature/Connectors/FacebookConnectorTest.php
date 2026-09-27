<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Facebook's refreshCredential (single-phase extend) is covered by
 * RefreshCredentialTest. This file covers the Graph helper methods the
 * Instagram/Facebook actions rely on.
 */
function facebook(): FacebookConnector
{
    return app(ConnectorRegistry::class)->for('facebook');
}

it('uses the extend-long-lived strategy and a 7 day lead time', function () {
    expect(facebook()->renewalStrategy())->toBe(RenewalStrategy::ExtendLongLived)
        ->and(facebook()->leadTime()->totalDays)->toBe(7.0);
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

it('follows paging.next until every page is listed', function () {
    Http::fake(function ($request) {
        return str_contains($request->url(), 'after=CURSOR2')
            ? Http::response(['data' => [['id' => 'p2']]]) // last page: no paging.next
            : Http::response(['data' => [['id' => 'p1']], 'paging' => [
                'next' => 'https://graph.facebook.com/v23.0/me/accounts?limit=100&after=CURSOR2',
            ]]);
    });

    expect(facebook()->fetchPages('user-token'))->toBe([['id' => 'p1'], ['id' => 'p2']]);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => ($request['limit'] ?? null) == 100 && ! str_contains($request->url(), 'after='));
});

it('fails instead of returning a partial list when a later page errors', function () {
    Http::fake(function ($request) {
        return str_contains($request->url(), 'after=')
            ? Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 500)
            : Http::response(['data' => [['id' => 'p1']], 'paging' => ['next' => 'https://graph.facebook.com/v23.0/me/accounts?after=X']]);
    });

    expect(facebook()->fetchPages('user-token'))->toBeInstanceOf(RenewalResult::class);
});

it('gives up with a failure rather than paginating forever', function () {
    Http::fake(fn () => Http::response(['data' => [['id' => 'p']], 'paging' => ['next' => 'https://graph.facebook.com/v23.0/me/accounts?after=again']]));

    $result = facebook()->fetchPages('user-token');

    expect($result)->toBeInstanceOf(RenewalResult::class)
        ->and($result->unknown)->toBeTrue();

    Http::assertSentCount(20);
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
