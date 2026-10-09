<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Events\AccountRevoked;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * disconnect() is how a user removes ONE Page, Instagram account or LinkedIn
 * organization while its siblings keep posting. The fan-out actions store
 * every target the provider lists and wrote `active` over each row, so the
 * next reconnect of the same login (to refresh the token, to add a Page, or
 * forced by Meta's 90-day data access and LinkedIn's 60-day re-auth) brought
 * the removed target back: usable, returned, announced by AccountConnected.
 */

/** What the Graph fake answers: the user's Pages (each linked to an Instagram account) and their token. */
class ReconnectGraph
{
    /** @var list<string> */
    public static array $pages = [];

    public static string $token = '';
}

beforeEach(function () {
    $this->owner = Owner::create();

    Http::fake(function ($request) {
        $url = $request->url();
        $token = ReconnectGraph::$token;

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response(['access_token' => $token, 'expires_in' => 5183944]),
            str_contains($url, '/debug_token') => Http::response(['data' => ['scopes' => [], 'granular_scopes' => []]]),
            str_contains($url, '/me/accounts') => Http::response(['data' => array_map(fn (string $page) => [
                'id' => $page, 'name' => $page, 'access_token' => "{$page}-{$token}",
                'instagram_business_account' => ['id' => "ig-{$page}", 'username' => "ig-{$page}"],
            ], ReconnectGraph::$pages)]),
            str_contains($url, 'api.linkedin.com/v2/organizationAcls') => Http::response(['elements' => [
                ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization~' => ['id' => '111', 'localizedName' => 'Acme']],
                ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization~' => ['id' => '222', 'localizedName' => 'Globex']],
            ]]),
            default => Http::response([], 404),
        };
    });
});

function reconnectGraph(array $pages = ['page-1', 'page-2'], string $token = 'long-user-token'): void
{
    ReconnectGraph::$pages = $pages;
    ReconnectGraph::$token = $token;
}

function reconnect(string $provider, Owner $owner, bool $restore = false)
{
    return app(StoreConnection::class)->handle($provider, socialiteUser(['id' => 'user-1', 'token' => 'short']), owner: $owner, restoreDisconnected: $restore);
}

function reconnectAccount(string $id, Owner $owner): SocialAccount
{
    return SocialAccount::query()->ownedBy($owner)->where('provider_user_id', $id)->sole();
}

it('keeps a disconnected Facebook Page out of a reconnect of the same login', function () {
    reconnectGraph();
    reconnect('facebook', $this->owner);
    app(SocialTokens::class)->disconnect(reconnectAccount('page-2', $this->owner));

    Event::fake([AccountConnected::class]);
    reconnectGraph(token: 'new-user-token');
    $accounts = reconnect('facebook', $this->owner);

    $removed = reconnectAccount('page-2', $this->owner);

    expect($accounts->pluck('provider_user_id')->all())->toBe(['page-1'])
        ->and($removed->status)->toBe(AccountStatus::Revoked)
        ->and($removed->wasDisconnected())->toBeTrue()
        ->and($removed->credential->status)->toBe(AccountStatus::Revoked)
        ->and($removed->credential->access_token)->toBeNull()
        ->and(SocialAccount::usable()->pluck('provider_user_id')->all())->toBe(['page-1'])
        ->and(reconnectAccount('page-1', $this->owner)->credential->access_token)->toBe('page-1-new-user-token');

    Event::assertDispatchedTimes(AccountConnected::class, 1);
    Event::assertNotDispatched(AccountConnected::class, fn (AccountConnected $event) => $event->account->provider_user_id === 'page-2');
});

it('restores a disconnected Page when the reconnect asks for it', function () {
    reconnectGraph();
    reconnect('facebook', $this->owner);
    app(SocialTokens::class)->disconnect(reconnectAccount('page-2', $this->owner));

    Event::fake([AccountConnected::class]);
    $accounts = reconnect('facebook', $this->owner, restore: true);

    $restored = reconnectAccount('page-2', $this->owner);

    expect($accounts->pluck('provider_user_id')->all())->toBe(['page-1', 'page-2'])
        ->and($restored->status)->toBe(AccountStatus::Active)
        ->and($restored->wasDisconnected())->toBeFalse()
        ->and($restored->disconnected_at)->toBeNull()
        ->and($restored->credential->status)->toBe(AccountStatus::Active)
        ->and($restored->credential->access_token)->toBe('page-2-long-user-token');

    Event::assertDispatchedTimes(AccountConnected::class, 2);
});

it('keeps a disconnected Instagram account out of a reconnect, and its companion Page in', function () {
    reconnectGraph();
    reconnect('instagram', $this->owner);
    app(SocialTokens::class)->disconnect(reconnectAccount('ig-page-2', $this->owner));

    $accounts = reconnect('instagram', $this->owner);

    expect($accounts->pluck('provider_user_id')->sort()->values()->all())->toBe(['ig-page-1', 'page-1', 'page-2'])
        ->and(reconnectAccount('ig-page-2', $this->owner)->status)->toBe(AccountStatus::Revoked)
        ->and(reconnectAccount('ig-page-1', $this->owner)->isUsable())->toBeTrue()
        ->and(reconnectAccount('page-2', $this->owner)->isUsable())->toBeTrue();
});

it('stores no Meta user credential when every target listed was disconnected', function () {
    reconnectGraph(['page-1']);
    reconnect('facebook', $this->owner);
    app(SocialTokens::class)->disconnect(reconnectAccount('page-1', $this->owner));

    $accounts = reconnect('facebook', $this->owner);

    $user = SocialToken::query()->ownedBy($this->owner)->where('provider_holder_id', 'user-1')->sole();

    expect($accounts)->toBeEmpty()
        ->and($user->status)->toBe(AccountStatus::Revoked)
        ->and($user->access_token)->toBeNull();
});

it('keeps a disconnected LinkedIn organization out of a re-sync', function () {
    $sync = fn (bool $restore = false) => app(StoreLinkedInOrganizations::class)->handle(
        accessToken: 'member-token', memberId: 'member-1', owner: $this->owner, expiresAt: now()->addDays(60), restoreDisconnected: $restore,
    );

    $sync();
    app(SocialTokens::class)->disconnect(reconnectAccount('222', $this->owner));

    expect($sync()->pluck('provider_user_id')->all())->toBe(['111'])
        ->and(reconnectAccount('222', $this->owner)->status)->toBe(AccountStatus::Revoked);

    expect($sync(restore: true)->pluck('provider_user_id')->all())->toBe(['111', '222'])
        ->and(reconnectAccount('222', $this->owner)->isUsable())->toBeTrue();
});

it('brings back the accounts a deauthorization revoked, which the user did not disconnect', function () {
    reconnectGraph();
    reconnect('facebook', $this->owner);
    app(SocialTokens::class)->revokeExternalUser('facebook', 'user-1');

    $accounts = reconnect('facebook', $this->owner);

    expect($accounts->pluck('provider_user_id')->all())->toBe(['page-1', 'page-2'])
        ->and(reconnectAccount('page-2', $this->owner)->isUsable())->toBeTrue();
});

it('keeps out an account disconnected after a deauthorization revoked it', function () {
    reconnectGraph();
    reconnect('facebook', $this->owner);
    app(SocialTokens::class)->revokeExternalUser('facebook', 'user-1');

    Event::fake([AccountRevoked::class]);
    app(SocialTokens::class)->disconnect(reconnectAccount('page-2', $this->owner));
    Event::assertNotDispatched(AccountRevoked::class); // already revoked

    expect(reconnect('facebook', $this->owner)->pluck('provider_user_id')->all())->toBe(['page-1']);
});

it('reconnects a disconnected 1:1 account, which is the account the user reconnects', function () {
    $account = app(StoreConnection::class)->handle('tiktok', socialiteUser(['id' => 'tt-1', 'refreshToken' => 'r1']), owner: $this->owner)->sole();
    app(SocialTokens::class)->disconnect($account);

    $again = app(StoreConnection::class)->handle('tiktok', socialiteUser(['id' => 'tt-1', 'token' => 'a2', 'refreshToken' => 'r2']), owner: $this->owner)->sole();

    expect($again->is($account))->toBeTrue()
        ->and($again->fresh()->status)->toBe(AccountStatus::Active)
        ->and($again->fresh()->wasDisconnected())->toBeFalse()
        ->and($again->fresh()->disconnected_at)->toBeNull()
        ->and($again->fresh()->credential->access_token)->toBe('a2');
});

it('records when the user disconnected an account, once', function () {
    $account = app(StoreConnection::class)->handle('tiktok', socialiteUser(['id' => 'tt-1']), owner: $this->owner)->sole();

    Event::fake([AccountRevoked::class]);
    $this->freezeSecond();
    app(SocialTokens::class)->disconnect($account);
    $at = $account->fresh()->disconnected_at;

    $this->travel(1)->hour();
    app(SocialTokens::class)->disconnect($account->fresh());

    expect($at)->not->toBeNull()
        ->and($account->fresh()->disconnected_at->equalTo($at))->toBeTrue()
        ->and($account->disconnected_at)->not->toBeNull();

    Event::assertDispatchedTimes(AccountRevoked::class, 1);
});

it('does not take an account flagged or revoked by the package for a disconnected one', function () {
    $account = app(StoreConnection::class)->handle('tiktok', socialiteUser(['id' => 'tt-1']), owner: $this->owner)->sole();

    $account->markRevoked();

    expect($account->fresh()->wasDisconnected())->toBeFalse()
        ->and($account->fresh()->disconnected_at)->toBeNull();
});

it('adds the disconnected_at column once, and lists it under pretend', function () {
    $migration = include __DIR__.'/../../../database/migrations/2025_01_01_000011_add_disconnected_at_to_social_accounts.php';

    $migration->up(); // already run by the test case: a second run changes nothing

    expect(Schema::hasColumn('social_accounts', 'disconnected_at'))->toBeTrue();

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('social_accounts', 'disconnected_at'))->toBeFalse()
        ->and(array_column(DB::pretend(fn () => $migration->up()), 'query'))
        ->toContain('alter table "social_accounts" add column "disconnected_at" datetime');

    $migration->up();

    expect(Schema::hasColumn('social_accounts', 'disconnected_at'))->toBeTrue();
});
