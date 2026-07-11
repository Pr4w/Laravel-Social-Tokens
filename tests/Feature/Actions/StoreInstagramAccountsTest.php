<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

function fakeInstagramGraph(array $o = []): void
{
    $extend = $o['extend'] ?? ['access_token' => 'long-user-token', 'expires_in' => 5183944];
    $pages = $o['pages'] ?? ['data' => [
        [
            'id' => 'page-1', 'name' => 'Page One', 'access_token' => 'pt-1',
            'instagram_business_account' => ['id' => 'ig-1', 'username' => 'insta_one', 'profile_picture_url' => 'ig1.png'],
        ],
        ['id' => 'page-2', 'name' => 'Page Two', 'access_token' => 'pt-2'], // no linked IG
    ]];
    $debug = $o['debug'] ?? ['data' => ['scopes' => ['instagram_content_publish'], 'granular_scopes' => []]];

    Http::fake(function ($request) use ($extend, $pages, $debug) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response($extend),
            str_contains($url, '/debug_token') => Http::response($debug),
            str_contains($url, '/me/accounts') => Http::response($pages),
            str_contains($url, '/me') => Http::response(['id' => 'user-1']),
            default => Http::response([], 404),
        };
    });
}

beforeEach(fn () => $this->store = app(StoreInstagramAccounts::class));

it('creates an instagram account on a renewable credential and a static companion page', function () {
    fakeInstagramGraph();

    $accounts = $this->store->handle(userToken: 'short', userId: 'user-1');

    expect($accounts)->toHaveCount(2); // 1 IG + 1 linked page

    $ig = SocialAccount::where('provider', 'instagram')->first();
    expect($ig->provider_user_id)->toBe('ig-1')
        ->and($ig->provider_holder_id)->toBe('user-1')
        ->and($ig->name)->toBe('insta_one')
        ->and($ig->profile['fb_page_id'])->toBe('page-1');

    // IG posts with the shared renewable Meta user credential.
    $credential = $ig->credential;
    expect($credential->provider)->toBe('facebook')
        ->and($credential->provider_holder_id)->toBe('user-1')
        ->and($credential->access_token)->toBe('long-user-token')
        ->and($credential->renew_at)->not->toBeNull();     // renewable

    // The companion Facebook page posts with a static page-token credential.
    $fb = SocialAccount::where('provider', 'facebook')->first();
    expect($fb->provider_user_id)->toBe('page-1')
        ->and($fb->credential->access_token)->toBe('pt-1')
        ->and($fb->credential->renew_at)->toBeNull();       // static
});

it('shares one Meta credential across multiple instagram accounts', function () {
    fakeInstagramGraph(['pages' => ['data' => [
        ['id' => 'p1', 'access_token' => 't1', 'instagram_business_account' => ['id' => 'ig-1', 'username' => 'one']],
        ['id' => 'p2', 'access_token' => 't2', 'instagram_business_account' => ['id' => 'ig-2', 'username' => 'two']],
    ]]]);

    $this->store->handle(userToken: 'short', userId: 'user-1');

    $igCredentialIds = SocialAccount::where('provider', 'instagram')->pluck('social_token_id')->unique();

    expect($igCredentialIds)->toHaveCount(1) // both IG accounts share one credential
        ->and(SocialToken::where('provider', 'facebook')->where('provider_holder_id', 'user-1')->count())->toBe(1);
});

it('skips pages without a linked instagram account', function () {
    fakeInstagramGraph();

    $this->store->handle(userToken: 'short', userId: 'user-1');

    expect(SocialAccount::where('provider_user_id', 'page-2')->exists())->toBeFalse();
});

it('omits companion pages when withLinkedPages is false', function () {
    fakeInstagramGraph();

    $accounts = $this->store->handle(userToken: 'short', userId: 'user-1', withLinkedPages: false);

    expect($accounts)->toHaveCount(1)
        ->and(SocialAccount::where('provider', 'facebook')->exists())->toBeFalse();
});

it('reconciles instagram accounts the user no longer manages', function () {
    SocialAccount::create([
        'provider' => 'instagram', 'provider_user_id' => 'dropped-ig',
        'provider_holder_id' => 'user-1', 'status' => AccountStatus::Active,
    ]);

    fakeInstagramGraph();
    $this->store->handle(userToken: 'short', userId: 'user-1');

    expect(SocialAccount::where('provider_user_id', 'dropped-ig')->first()->status)
        ->toBe(AccountStatus::NeedsReconnect);
});

it('throws when the pages listing fails', function () {
    fakeInstagramGraph(['pages' => ['error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'bad']]]);

    $this->store->handle(userToken: 'short', userId: 'user-1');
})->throws(RuntimeException::class);
