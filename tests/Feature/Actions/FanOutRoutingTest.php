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
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;
use Pr4w\SocialTokens\Tests\Fixtures\UpgradeGuide;

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

/**
 * 2.x's InstagramConnector docblock invited this for the Instagram API with
 * Instagram Login: a subclass storing one account per connection, under
 * provider 'instagram', through StoreAccountFromSocialite. In 3.0 it
 * inherits FansOutConnections, and naming StoreAccountFromSocialite as its
 * connection action, as the upgrade guide said, was refused all the same.
 */
class OneAccountInstagramConnector extends InstagramConnector
{
    public function connectionAction(): string
    {
        return StoreAccountFromSocialite::class;
    }

    public function credentialProvider(): string
    {
        return 'instagram';
    }

    public function exchangeForLongLived(#[SensitiveParameter] string $accessToken): ?RenewalResult
    {
        return RenewalResult::success(accessToken: "long-{$accessToken}", expiresAt: now()->addDays(60));
    }
}

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

it('stores one account per connection for a Meta connector subclass that names StoreAccountFromSocialite', function (string $via) {
    Http::preventStrayRequests();
    config(['social-tokens.connectors.instagram' => ['driver' => OneAccountInstagramConnector::class]]);
    $user = socialiteUser(['id' => 'ig-user-1', 'token' => 'short']);

    $account = $via === 'StoreConnection'
        ? app(StoreConnection::class)->handle('instagram', $user)->sole()
        : app(StoreAccountFromSocialite::class)->handle('instagram', $user);

    expect($account->provider)->toBe('instagram')
        ->and($account->provider_user_id)->toBe('ig-user-1')
        ->and($account->credential->provider)->toBe('instagram')
        ->and($account->credential->access_token)->toBe('long-short')
        ->and($account->credential->renew_at)->not->toBeNull();
})->with(['StoreConnection', 'StoreAccountFromSocialite']);

it('moves the rows of a 2.x Instagram Login subclass to instagram_login with the UPGRADE snippet', function () {
    $login = SocialAccount::factory()->forProvider('instagram')->credential(['provider' => 'instagram'])->create();
    $throughPage = SocialAccount::factory()->forProvider('instagram')->heldBy('fb-1')->create();

    UpgradeGuide::run('#### Instagram Login through a subclass of `InstagramConnector`', "update(['provider' => 'instagram_login'])");

    expect($login->fresh()->provider)->toBe('instagram_login')
        ->and($login->fresh()->credential->provider)->toBe('instagram_login')
        ->and($throughPage->fresh()->provider)->toBe('instagram')
        ->and($throughPage->fresh()->credential->provider)->toBe('facebook');
});

it('enters a Meta action bound in the container through storeConnection(), not handle(), as UPGRADE says', function () {
    fakeRoutingGraph();
    $pages = new class(app(ConnectorRegistry::class)) extends StoreFacebookPages
    {
        public array $entered = [];

        public function handle(#[SensitiveParameter] string $userToken, ?Model $owner = null, ?Model $connectedBy = null, ?string $userId = null, bool $extend = true, bool $restoreDisconnected = false): Collection
        {
            $this->entered[] = 'handle';

            return parent::handle($userToken, $owner, $connectedBy, $userId, $extend, $restoreDisconnected);
        }

        public function storeConnection(string $provider, #[SensitiveParameter] SocialiteUser $user, ?Model $owner = null, ?Model $connectedBy = null, bool $longLived = true, ?CarbonInterface $refreshExpiresAt = null, bool $restoreDisconnected = false): Collection
        {
            $this->entered[] = 'storeConnection';

            return parent::storeConnection($provider, $user, $owner, $connectedBy, $longLived, $refreshExpiresAt, $restoreDisconnected);
        }
    };
    app()->instance(StoreFacebookPages::class, $pages);

    app(StoreConnection::class)->handle('facebook', socialiteUser(['id' => 'user-1']));

    expect($pages->entered)->toBe(['storeConnection']);
});

it('stores a 1:1 provider through StoreAccountFromSocialite as before', function () {
    $accounts = app(StoreConnection::class)->handle('tiktok', socialiteUser(['id' => 'tt-1', 'refreshToken' => 'r']));

    expect($accounts->sole()->provider_user_id)->toBe('tt-1');
});
