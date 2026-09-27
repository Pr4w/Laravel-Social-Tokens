<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/** Fake every Graph endpoint the action touches, keyed by path. */
function fakeFacebookGraph(array $o = []): void
{
    $extend = $o['extend'] ?? ['access_token' => 'long-user-token', 'expires_in' => 5183944];
    $pages = $o['pages'] ?? ['data' => [
        ['id' => 'page-1', 'name' => 'Page One', 'access_token' => 'pt-1', 'picture' => ['data' => ['url' => 'p1.png']]],
        ['id' => 'page-2', 'name' => 'Page Two', 'access_token' => 'pt-2', 'picture' => ['data' => ['url' => 'p2.png']]],
    ]];
    $debug = $o['debug'] ?? ['data' => ['scopes' => ['pages_manage_posts'], 'granular_scopes' => []]];
    $me = $o['me'] ?? ['id' => 'user-1'];

    Http::fake(function ($request) use ($extend, $pages, $debug, $me) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response($extend),
            str_contains($url, '/debug_token') => Http::response($debug),
            str_contains($url, '/me/accounts') => is_callable($pages) ? $pages($request) : Http::response($pages),
            str_contains($url, '/me') => Http::response($me),
            default => Http::response([], 404),
        };
    });
}

beforeEach(fn () => $this->store = app(StoreFacebookPages::class));

it('creates one account and a static page-token credential per page', function () {
    Event::fake([AccountConnected::class]);
    fakeFacebookGraph();
    $owner = Owner::create();

    $accounts = $this->store->handle(userToken: 'short', owner: $owner, userId: 'user-1');

    expect($accounts)->toHaveCount(2);

    $account = SocialAccount::where('provider_user_id', 'page-1')->first();
    expect($account->provider)->toBe('facebook')
        ->and($account->provider_holder_id)->toBe('user-1')     // the Facebook user, for reconciliation
        ->and($account->name)->toBe('Page One')
        ->and($account->avatar)->toBe('p1.png')
        ->and($account->ownable->is($owner))->toBeTrue();

    $credential = $account->credential;
    expect($credential)->not->toBeNull()
        ->and($credential->provider)->toBe('facebook')
        ->and($credential->provider_holder_id)->toBe('page-1')  // the page is the credential holder
        ->and($credential->access_token)->toBe('pt-1')          // page token
        ->and($credential->renew_at)->toBeNull()                // static — never auto-refreshed
        ->and($credential->expires_at)->toBeNull();

    Event::assertDispatchedTimes(AccountConnected::class, 2);
});

it('stores per-account granular scopes on both account and credential', function () {
    fakeFacebookGraph(['debug' => ['data' => [
        'scopes' => ['pages_show_list', 'pages_manage_posts'],
        'granular_scopes' => [['scope' => 'pages_manage_posts', 'target_ids' => ['page-1']]],
    ]]]);

    $this->store->handle(userToken: 'short', userId: 'user-1');

    expect(SocialAccount::where('provider_user_id', 'page-1')->first()->scopes)
        ->toBe(['pages_show_list', 'pages_manage_posts'])
        ->and(SocialAccount::where('provider_user_id', 'page-2')->first()->scopes)
        ->toBe(['pages_show_list']);
});

it('resolves the user id when not supplied', function () {
    fakeFacebookGraph(['me' => ['id' => 'resolved-user']]);

    $this->store->handle(userToken: 'short');

    expect(SocialAccount::where('provider_user_id', 'page-1')->first()->provider_holder_id)
        ->toBe('resolved-user');
});

it('reconciles pages the user no longer manages', function () {
    SocialAccount::create([
        'provider' => 'facebook', 'provider_user_id' => 'dropped-page',
        'provider_holder_id' => 'user-1', 'status' => AccountStatus::Active,
    ]);
    SocialAccount::create([
        'provider' => 'facebook', 'provider_user_id' => 'other-page',
        'provider_holder_id' => 'other-user', 'status' => AccountStatus::Active,
    ]);

    fakeFacebookGraph();
    $this->store->handle(userToken: 'short', userId: 'user-1');

    expect(SocialAccount::where('provider_user_id', 'dropped-page')->first()->status)
        ->toBe(AccountStatus::NeedsReconnect)
        ->and(SocialAccount::where('provider_user_id', 'other-page')->first()->status)
        ->toBe(AccountStatus::Active);
});

it('throws when the token extension fails', function () {
    fakeFacebookGraph(['extend' => ['error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'bad']]]);

    $this->store->handle(userToken: 'short', userId: 'user-1');
})->throws(RuntimeException::class);

it('does not double up credentials on reconnect', function () {
    fakeFacebookGraph();
    $this->store->handle(userToken: 'short', userId: 'user-1');
    $this->store->handle(userToken: 'short', userId: 'user-1');

    expect(SocialToken::where('provider_holder_id', 'page-1')->count())->toBe(1)
        ->and(SocialAccount::where('provider_user_id', 'page-1')->count())->toBe(1);
});

it('stores pages spread over several result pages and flags none of them', function () {
    // An account from the second result page: it must not be taken as dropped.
    SocialAccount::create([
        'provider' => 'facebook', 'provider_user_id' => 'page-26',
        'provider_holder_id' => 'user-1', 'status' => AccountStatus::Active,
    ]);

    fakeFacebookGraph(['pages' => fn ($request) => str_contains($request->url(), 'after=')
        ? Http::response(['data' => [['id' => 'page-26', 'name' => 'Page 26', 'access_token' => 'pt-26']]])
        : Http::response([
            'data' => [['id' => 'page-1', 'name' => 'Page One', 'access_token' => 'pt-1']],
            'paging' => ['next' => 'https://graph.facebook.com/v23.0/me/accounts?after=NEXT'],
        ]),
    ]);

    $accounts = $this->store->handle(userToken: 'short', userId: 'user-1');

    expect($accounts)->toHaveCount(2)
        ->and(SocialAccount::where('provider_user_id', 'page-26')->first()->status)->toBe(AccountStatus::Active);
});

it('flags nothing when a later result page fails', function () {
    $existing = SocialAccount::create([
        'provider' => 'facebook', 'provider_user_id' => 'page-26',
        'provider_holder_id' => 'user-1', 'status' => AccountStatus::Active,
    ]);

    fakeFacebookGraph(['pages' => fn ($request) => str_contains($request->url(), 'after=')
        ? Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 500)
        : Http::response([
            'data' => [['id' => 'page-1', 'name' => 'Page One', 'access_token' => 'pt-1']],
            'paging' => ['next' => 'https://graph.facebook.com/v23.0/me/accounts?after=NEXT'],
        ]),
    ]);

    try {
        $this->store->handle(userToken: 'short', userId: 'user-1');
        $this->fail('expected RuntimeException');
    } catch (RuntimeException) {
        expect($existing->fresh()->status)->toBe(AccountStatus::Active);
    }
});
