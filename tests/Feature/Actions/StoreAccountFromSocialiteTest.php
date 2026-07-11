<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

beforeEach(fn () => $this->store = app(StoreAccountFromSocialite::class));

it('stores a single account with mapped fields and owner', function () {
    Event::fake([AccountConnected::class]);
    $owner = Owner::create();

    $account = $this->store->handle('google', socialiteUser([
        'id' => 'g-1',
        'name' => 'Grace',
        'token' => 'goog-access',
        'refreshToken' => 'goog-refresh',
        'expiresIn' => 3600,
        'approvedScopes' => ['openid', 'youtube.upload'],
    ]), $owner);

    expect($account->provider)->toBe('google')
        ->and($account->provider_user_id)->toBe('g-1')
        ->and($account->access_token)->toBe('goog-access')
        ->and($account->refresh_token)->toBe('goog-refresh')
        ->and($account->scopes)->toBe(['openid', 'youtube.upload'])
        ->and($account->status)->toBe(AccountStatus::Active)
        ->and($account->expires_at)->not->toBeNull()
        ->and($account->renew_at)->not->toBeNull()
        ->and($account->ownable->is($owner))->toBeTrue();

    Event::assertDispatched(AccountConnected::class);
});

it('computes renew_at from the connector lead time', function () {
    // Google lead time is 10 minutes.
    $account = $this->store->handle('google', socialiteUser(['expiresIn' => 3600]));

    expect($account->renew_at->timestamp)
        ->toEqualWithDelta($account->expires_at->copy()->subMinutes(10)->timestamp, 2);
});

it('reads refresh_expires_in from the raw token response', function () {
    $account = $this->store->handle('tiktok', socialiteUser([
        'refreshToken' => 'tt-refresh',
        'accessTokenResponseBody' => ['refresh_expires_in' => 100],
    ]));

    expect($account->refresh_expires_at)->not->toBeNull()
        ->and($account->refresh_expires_at->timestamp)->toEqualWithDelta(now()->addSeconds(100)->timestamp, 2);
});

it('upgrades a short-lived token via the connector exchange', function () {
    Http::fake(['graph.threads.net/access_token*' => Http::response([
        'access_token' => 'long-threads-token',
        'expires_in' => 5183944,
    ])]);

    $account = $this->store->handle('threads', socialiteUser(['token' => 'short-threads-token']));

    expect($account->access_token)->toBe('long-threads-token')
        ->and($account->expires_at->timestamp)->toEqualWithDelta(now()->addSeconds(5183944)->timestamp, 5);

    Http::assertSent(fn ($request) => $request['grant_type'] === 'th_exchange_token');
});

it('skips the exchange when longLived is false', function () {
    Http::fake();

    $account = $this->store->handle('threads', socialiteUser(['token' => 'short-threads-token']), longLived: false);

    expect($account->access_token)->toBe('short-threads-token');
    Http::assertNothingSent();
});

it('throws when the long-lived exchange fails', function () {
    Http::fake(['graph.threads.net/access_token*' => Http::response([
        'error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'bad'],
    ])]);

    $this->store->handle('threads', socialiteUser(['token' => 'short']));
})->throws(RuntimeException::class);

it('updates an existing account rather than duplicating it', function () {
    $this->store->handle('google', socialiteUser(['id' => 'g-1', 'token' => 'first']));
    $this->store->handle('google', socialiteUser(['id' => 'g-1', 'token' => 'second']));

    expect(SocialAccount::where('provider', 'google')->count())->toBe(1)
        ->and(SocialAccount::where('provider', 'google')->first()->access_token)->toBe('second');
});
