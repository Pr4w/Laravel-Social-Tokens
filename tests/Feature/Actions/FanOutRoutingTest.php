<?php

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\User as SocialiteUser;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Connectors\InstagramConnector;
use Pr4w\SocialTokens\Contracts\FansOutConnections;
use Pr4w\SocialTokens\Contracts\StoresConnections;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

/*
 * StoreConnection decided which action stores a provider with instanceof
 * checks on FacebookConnector and InstagramConnector. It dropped `longLived`
 * on those two paths (the Meta actions call it `extend`), and the lower-level
 * StoreAccountFromSocialite took a fan-out provider as a 1:1 one: a
 * Facebook user id stored as an Instagram account, the shared Meta user
 * credential overwritten with Socialite's short-lived token.
 */

/** A connector whose connections another action stores. */
class FanOutRoutingConnector extends FakeConnector implements FansOutConnections
{
    public static string $action = FanOutRoutingAction::class;

    public function connectionAction(): string
    {
        return static::$action;
    }
}

class FanOutRoutingAction implements StoresConnections
{
    /** @var list<array<string, mixed>> */
    public static array $calls = [];

    public function storeConnection(
        string $provider,
        #[SensitiveParameter] SocialiteUser $user,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        bool $longLived = true,
        ?CarbonInterface $refreshExpiresAt = null,
        bool $restoreDisconnected = false,
    ): Collection {
        static::$calls[] = ['provider' => $provider, 'user' => $user->getId(), 'longLived' => $longLived, 'restoreDisconnected' => $restoreDisconnected];

        return collect();
    }
}

class NotAConnectionAction {}

function fakeRoutingGraph(): void
{
    Http::fake(function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response(['access_token' => 'extended-token', 'expires_in' => 5183944]),
            str_contains($url, '/debug_token') => Http::response(['data' => ['scopes' => [], 'granular_scopes' => []]]),
            str_contains($url, '/me/accounts') => Http::response(['data' => [[
                'id' => 'page-1', 'name' => 'Page One', 'access_token' => 'pt-1',
                'instagram_business_account' => ['id' => 'ig-1', 'username' => 'insta_one'],
            ]]]),
            default => Http::response([], 404),
        };
    });
}

beforeEach(function () {
    FanOutRoutingAction::$calls = [];
    FanOutRoutingConnector::$action = FanOutRoutingAction::class;
    config(['social-tokens.connectors.fanout' => ['driver' => FanOutRoutingConnector::class]]);
});

it('names the action that stores the Meta connectors\' connections', function () {
    expect(new FacebookConnector([], 'facebook'))->toBeInstanceOf(FansOutConnections::class)
        ->and((new FacebookConnector([], 'facebook'))->connectionAction())->toBe(StoreFacebookPages::class)
        ->and((new InstagramConnector([], 'instagram'))->connectionAction())->toBe(StoreInstagramAccounts::class);
});

it('honours longLived: false on the Meta fan-outs', function (string $provider) {
    fakeRoutingGraph();

    $accounts = app(StoreConnection::class)->handle($provider, socialiteUser(['id' => 'user-1', 'token' => 'already-long']), longLived: false);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/oauth/access_token'));

    $user = SocialToken::query()->where('provider', 'facebook')->where('provider_holder_id', 'user-1')->sole();

    expect($accounts)->not->toBeEmpty()
        ->and($user->access_token)->toBe('already-long');
})->with(['facebook', 'instagram']);

it('still extends the Meta user token by default', function (string $provider) {
    fakeRoutingGraph();

    app(StoreConnection::class)->handle($provider, socialiteUser(['id' => 'user-1', 'token' => 'short']));

    expect(SocialToken::query()->where('provider_holder_id', 'user-1')->sole()->access_token)->toBe('extended-token');
})->with(['facebook', 'instagram']);

it('routes a connector of your own to the action it names, with every option', function () {
    $result = app(StoreConnection::class)->handle('fanout', socialiteUser(['id' => 'ext-9']), longLived: false, restoreDisconnected: true);

    expect($result)->toBeEmpty()
        ->and(FanOutRoutingAction::$calls)->toBe([['provider' => 'fanout', 'user' => 'ext-9', 'longLived' => false, 'restoreDisconnected' => true]])
        ->and(SocialAccount::query()->count())->toBe(0);
});

it('refuses a connection action that cannot store connections', function () {
    FanOutRoutingConnector::$action = NotAConnectionAction::class;

    expect(fn () => app(StoreConnection::class)->handle('fanout', socialiteUser()))
        ->toThrow(LogicException::class, NotAConnectionAction::class);
});

it('refuses a fan-out provider in StoreAccountFromSocialite, before writing anything', function (string $provider, string $action) {
    Http::fake();

    expect(fn () => app(StoreAccountFromSocialite::class)->handle($provider, socialiteUser(['id' => 'user-1'])))
        ->toThrow(InvalidArgumentException::class, $action);

    expect(SocialAccount::query()->count())->toBe(0)
        ->and(SocialToken::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    ['facebook', 'StoreFacebookPages'],
    ['instagram', 'StoreInstagramAccounts'],
    ['fanout', 'FanOutRoutingAction'],
]);

it('stores a 1:1 provider through StoreAccountFromSocialite as before', function () {
    $accounts = app(StoreConnection::class)->handle('tiktok', socialiteUser(['id' => 'tt-1', 'refreshToken' => 'r']));

    expect($accounts->sole()->provider_user_id)->toBe('tt-1');
});
