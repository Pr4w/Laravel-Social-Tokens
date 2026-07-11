<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Models\SocialAccount;

/** A Graph fake rich enough for both the Facebook and Instagram routes. */
function fakeMetaGraphForConnection(): void
{
    Http::fake(function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response(['access_token' => 'long-user-token', 'expires_in' => 5183944]),
            str_contains($url, '/debug_token') => Http::response(['data' => ['scopes' => [], 'granular_scopes' => []]]),
            str_contains($url, '/me/accounts') => Http::response(['data' => [[
                'id' => 'page-1', 'name' => 'Page One', 'access_token' => 'pt-1',
                'picture' => ['data' => ['url' => 'p1.png']],
                'instagram_business_account' => ['id' => 'ig-1', 'username' => 'insta_one'],
            ]]]),
            str_contains($url, '/me') => Http::response(['id' => 'user-1']),
            default => Http::response([], 404),
        };
    });
}

beforeEach(fn () => $this->store = app(StoreConnection::class));

it('always returns a collection', function () {
    $result = $this->store->handle('google', socialiteUser(['id' => 'g-1']));

    expect($result)->toBeInstanceOf(Collection::class)->toHaveCount(1)
        ->and($result->first()->provider)->toBe('google');
});

it('routes Facebook to the page fan-out', function () {
    fakeMetaGraphForConnection();

    $result = $this->store->handle('facebook', socialiteUser(['token' => 'short']));

    expect($result)->toHaveCount(1)
        ->and($result->first()->provider)->toBe('facebook')
        ->and(SocialAccount::where('provider', 'facebook')->where('provider_user_id', 'page-1')->exists())->toBeTrue();
});

it('routes Instagram to the account fan-out', function () {
    fakeMetaGraphForConnection();

    $result = $this->store->handle('instagram', socialiteUser(['token' => 'short']));

    // one IG account plus its companion Facebook page
    expect(SocialAccount::where('provider', 'instagram')->where('provider_user_id', 'ig-1')->exists())->toBeTrue()
        ->and(SocialAccount::where('provider', 'facebook')->where('provider_user_id', 'page-1')->exists())->toBeTrue();
});

it('routes other providers to a single stored account', function () {
    $result = $this->store->handle('tiktok', socialiteUser(['id' => 'tt-1', 'refreshToken' => 'r']));

    expect($result)->toHaveCount(1)
        ->and($result->first()->provider)->toBe('tiktok')
        ->and($result->first()->provider_user_id)->toBe('tt-1');
});
