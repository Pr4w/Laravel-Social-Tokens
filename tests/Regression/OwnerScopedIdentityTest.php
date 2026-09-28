<?php

/*
 * Regression: an account's identity must be scoped to its owner.
 *
 * In v1.1.0 social_accounts is unique on (provider, provider_user_id) and
 * social_tokens on (provider, provider_holder_id), whoever owns them. Every
 * connect action upserts on that global key and then re-associates `ownable`,
 * so when a second owner connects a TikTok account, a Facebook Page, an
 * Instagram account or a LinkedIn organization that a first owner already
 * connected, the single row (and its credential) silently moves to the second
 * owner. The first owner is left with no row and receives no event.
 *
 * Target behaviour (v2.0, breaking): each owner keeps its own account row,
 * backed by its own credential (the grant that owner's user gave), and a
 * connection, reconciliation or revocation for one owner never re-parents,
 * overwrites or kills another owner's row. Reconnecting for the same owner,
 * or without any owner, still updates the existing row in place.
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/**
 * Fake the Graph API. /me/accounts answers per user token (read from the bearer
 * header), so each owner's user sees their own page tokens. An unknown token
 * is rejected loudly instead of silently returning no pages.
 *
 * @param  array<string, array<int, array<string, mixed>>>  $pagesByUserToken
 */
function ownerScopedFakeMeta(array $pagesByUserToken): void
{
    Http::fake(function (Request $request) use ($pagesByUserToken) {
        $url = $request->url();

        if (str_contains($url, '/debug_token')) {
            return Http::response(['data' => [
                'scopes' => ['pages_manage_posts', 'instagram_content_publish'],
                'granular_scopes' => [],
            ]]);
        }

        if (str_contains($url, '/me/accounts')) {
            $userToken = Str::after($request->header('Authorization')[0] ?? '', 'Bearer ');

            return array_key_exists($userToken, $pagesByUserToken)
                ? Http::response(['data' => $pagesByUserToken[$userToken]])
                : Http::response(['error' => ['message' => "Unexpected test token [{$userToken}]", 'code' => 190]], 400);
        }

        return Http::response(['error' => ['message' => "Unexpected Graph call [{$url}]"]], 404);
    });
}

/**
 * Fake LinkedIn's organizationAcls per member token (read from the bearer header).
 *
 * @param  array<string, array<int, string>>  $orgIdsByMemberToken
 */
function ownerScopedFakeLinkedIn(array $orgIdsByMemberToken): void
{
    Http::fake(function (Request $request) use ($orgIdsByMemberToken) {
        $memberToken = Str::after($request->header('Authorization')[0] ?? '', 'Bearer ');

        if (! str_contains($request->url(), 'api.linkedin.com/v2/organizationAcls')
            || ! array_key_exists($memberToken, $orgIdsByMemberToken)) {
            return Http::response(['message' => 'Unexpected LinkedIn call'], 404);
        }

        return Http::response(['elements' => array_map(fn (string $id) => [
            'role' => 'ADMINISTRATOR',
            'state' => 'APPROVED',
            'organization~' => ['id' => $id, 'localizedName' => "Org {$id}"],
        ], $orgIdsByMemberToken[$memberToken])]);
    });
}

/** A Facebook Page as /me/accounts returns it, optionally with its linked IG account. */
function ownerScopedPage(string $pageToken, bool $withInstagram = false): array
{
    return array_filter([
        'id' => 'page-1',
        'name' => 'Shared Page',
        'access_token' => $pageToken,
        'instagram_business_account' => $withInstagram ? ['id' => 'ig-1', 'username' => 'shared_ig'] : null,
    ]);
}

/** The row a given owner holds for an external account, if any. */
function ownerScopedRow(Model $owner, string $provider, string $providerUserId): ?SocialAccount
{
    return SocialAccount::query()
        ->whereMorphedTo('ownable', $owner)
        ->where('provider', $provider)
        ->where('provider_user_id', $providerUserId)
        ->first();
}

beforeEach(function () {
    Http::preventStrayRequests();

    $this->alice = Owner::create(['name' => 'alice']);
    $this->bob = Owner::create(['name' => 'bob']);
});

// Schema ----------------------------------------------------------------------

it('allows two owners to hold a row for the same external account', function () {
    $first = new SocialAccount(['provider' => 'facebook', 'provider_user_id' => 'page-1']);
    $first->ownable()->associate($this->alice)->save();

    // Saved directly (not wrapped in toThrow) so a v1 failure shows which
    // unique index rejected the second owner's row.
    $second = new SocialAccount(['provider' => 'facebook', 'provider_user_id' => 'page-1']);
    $second->ownable()->associate($this->bob)->save();

    expect(SocialAccount::where('provider', 'facebook')->where('provider_user_id', 'page-1')->count())->toBe(2)
        ->and(ownerScopedRow($this->alice, 'facebook', 'page-1')?->is($first))->toBeTrue()
        ->and(ownerScopedRow($this->bob, 'facebook', 'page-1')?->is($second))->toBeTrue();
});

it('still rejects a duplicate row for the same owner and external account', function () {
    $first = new SocialAccount(['provider' => 'facebook', 'provider_user_id' => 'page-1']);
    $first->ownable()->associate($this->alice)->save();

    $duplicate = new SocialAccount(['provider' => 'facebook', 'provider_user_id' => 'page-1']);
    $duplicate->ownable()->associate($this->alice);

    expect(fn () => $duplicate->save())->toThrow(QueryException::class);
});

// 1:1 providers (TikTok via StoreConnection / StoreAccountFromSocialite) ---------

it('does not re-parent the first owner\'s TikTok row when a second owner connects the same account', function () {
    Event::fake([AccountConnected::class]);

    $aliceAccount = app(StoreConnection::class)->handle(
        'tiktok',
        socialiteUser(['id' => 'tt-1', 'token' => 'alice-access', 'refreshToken' => 'alice-refresh']),
        owner: $this->alice,
    )->sole();

    $bobAccount = app(StoreConnection::class)->handle(
        'tiktok',
        socialiteUser(['id' => 'tt-1', 'token' => 'bob-access', 'refreshToken' => 'bob-refresh']),
        owner: $this->bob,
    )->sole();

    expect($bobAccount->is($aliceAccount))->toBeFalse('Bob\'s connection reused Alice\'s TikTok row')
        ->and($aliceAccount->fresh()->ownable->is($this->alice))->toBeTrue('Alice\'s TikTok row was re-parented to Bob')
        ->and(SocialAccount::where('provider', 'tiktok')->where('provider_user_id', 'tt-1')->count())->toBe(2)
        ->and($bobAccount->ownable->is($this->bob))->toBeTrue();

    Event::assertDispatched(AccountConnected::class, fn (AccountConnected $event) => $event->account->is($aliceAccount));
    Event::assertDispatched(AccountConnected::class, fn (AccountConnected $event) => $event->account->is($bobAccount));
});

it('keeps each owner\'s own TikTok refresh token on their own credential', function () {
    app(StoreConnection::class)->handle(
        'tiktok',
        socialiteUser(['id' => 'tt-1', 'token' => 'alice-access', 'refreshToken' => 'alice-refresh']),
        owner: $this->alice,
    );
    app(StoreConnection::class)->handle(
        'tiktok',
        socialiteUser(['id' => 'tt-1', 'token' => 'bob-access', 'refreshToken' => 'bob-refresh']),
        owner: $this->bob,
    );

    $aliceRow = ownerScopedRow($this->alice, 'tiktok', 'tt-1');
    $bobRow = ownerScopedRow($this->bob, 'tiktok', 'tt-1');

    expect($aliceRow)->not->toBeNull('Alice lost her TikTok row when Bob connected the same account')
        ->and($bobRow)->not->toBeNull()
        ->and($aliceRow->social_token_id)->not->toBe($bobRow->social_token_id)
        ->and($aliceRow->credential->access_token)->toBe('alice-access')
        ->and($aliceRow->credential->refresh_token)->toBe('alice-refresh')
        ->and($bobRow->credential->refresh_token)->toBe('bob-refresh');
});

it('leaves the first owner\'s TikTok account usable when the second owner revokes theirs', function () {
    app(StoreConnection::class)->handle(
        'tiktok',
        socialiteUser(['id' => 'tt-1', 'token' => 'alice-access', 'refreshToken' => 'alice-refresh']),
        owner: $this->alice,
    );
    $bobRow = app(StoreConnection::class)->handle(
        'tiktok',
        socialiteUser(['id' => 'tt-1', 'token' => 'bob-access', 'refreshToken' => 'bob-refresh']),
        owner: $this->bob,
    )->sole();

    Http::fake(['open.tiktokapis.com/v2/oauth/revoke/*' => Http::response([])]);
    app(SocialTokens::class)->revoke($bobRow->credential);

    $aliceRow = ownerScopedRow($this->alice, 'tiktok', 'tt-1');

    expect($aliceRow)->not->toBeNull('Alice lost her TikTok row when Bob connected the same account')
        ->and($aliceRow->status)->toBe(AccountStatus::Active)
        ->and($aliceRow->isUsable())->toBeTrue('Revoking Bob\'s credential killed Alice\'s TikTok account')
        ->and($bobRow->fresh()->status)->toBe(AccountStatus::Revoked);
});

it('updates the owner\'s existing row when the same owner reconnects', function () {
    foreach (['alice-refresh-1', 'alice-refresh-2'] as $refreshToken) {
        app(StoreConnection::class)->handle(
            'tiktok',
            socialiteUser(['id' => 'tt-1', 'token' => 'alice-access', 'refreshToken' => $refreshToken]),
            owner: $this->alice,
        );
    }

    expect(SocialAccount::where('provider', 'tiktok')->where('provider_user_id', 'tt-1')->count())->toBe(1)
        ->and(SocialToken::where('provider', 'tiktok')->count())->toBe(1)
        ->and(ownerScopedRow($this->alice, 'tiktok', 'tt-1')->credential->refresh_token)->toBe('alice-refresh-2');
});

it('keeps a single owner-less row when an account is reconnected without an owner', function () {
    foreach (['refresh-1', 'refresh-2'] as $refreshToken) {
        app(StoreConnection::class)->handle(
            'tiktok',
            socialiteUser(['id' => 'tt-1', 'token' => 'access', 'refreshToken' => $refreshToken]),
        );
    }

    $rows = SocialAccount::where('provider', 'tiktok')->where('provider_user_id', 'tt-1')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->ownable_type)->toBeNull()
        ->and(SocialToken::where('provider', 'tiktok')->count())->toBe(1)
        ->and($rows->first()->credential->refresh_token)->toBe('refresh-2');
});

// Facebook Pages (StoreFacebookPages) -------------------------------------------

it('keeps a separate Facebook Page row for each owner, each with its own page token', function () {
    ownerScopedFakeMeta([
        'alice-user-token' => [ownerScopedPage('pt-alice')],
        'bob-user-token' => [ownerScopedPage('pt-bob')],
    ]);

    app(StoreFacebookPages::class)->handle(userToken: 'alice-user-token', owner: $this->alice, userId: 'fb-alice', extend: false);
    app(StoreFacebookPages::class)->handle(userToken: 'bob-user-token', owner: $this->bob, userId: 'fb-bob', extend: false);

    $aliceRow = ownerScopedRow($this->alice, 'facebook', 'page-1');
    $bobRow = ownerScopedRow($this->bob, 'facebook', 'page-1');

    expect($aliceRow)->not->toBeNull('Alice lost her Facebook Page row when Bob connected the same Page')
        ->and($bobRow)->not->toBeNull()
        ->and($aliceRow->provider_holder_id)->toBe('fb-alice')
        ->and($bobRow->provider_holder_id)->toBe('fb-bob')
        ->and($aliceRow->social_token_id)->not->toBe($bobRow->social_token_id)
        ->and($aliceRow->credential->access_token)->toBe('pt-alice')
        ->and($bobRow->credential->access_token)->toBe('pt-bob');
});

it('keeps the first owner\'s Facebook Page usable when the second owner loses access to it', function () {
    ownerScopedFakeMeta([
        'alice-user-token' => [ownerScopedPage('pt-alice')],
        'bob-user-token' => [ownerScopedPage('pt-bob')],
        'bob-user-token-after-losing-admin' => [],
    ]);

    app(StoreFacebookPages::class)->handle(userToken: 'alice-user-token', owner: $this->alice, userId: 'fb-alice', extend: false);
    app(StoreFacebookPages::class)->handle(userToken: 'bob-user-token', owner: $this->bob, userId: 'fb-bob', extend: false);

    Event::fake([AccountNeedsReconnect::class]);
    app(StoreFacebookPages::class)->handle(userToken: 'bob-user-token-after-losing-admin', owner: $this->bob, userId: 'fb-bob', extend: false);

    $aliceRow = ownerScopedRow($this->alice, 'facebook', 'page-1');

    expect($aliceRow)->not->toBeNull('Alice lost her Facebook Page row when Bob connected the same Page')
        ->and($aliceRow->status)->toBe(AccountStatus::Active)
        ->and($aliceRow->isUsable())->toBeTrue()
        ->and($aliceRow->credential->access_token)->toBe('pt-alice')
        ->and(ownerScopedRow($this->bob, 'facebook', 'page-1')->status)->toBe(AccountStatus::NeedsReconnect);

    Event::assertNotDispatched(AccountNeedsReconnect::class, fn (AccountNeedsReconnect $event) => $event->account->is($aliceRow));
});

// Instagram (StoreInstagramAccounts) -------------------------------------------

it('keeps a separate Instagram row for each owner, each on their own Meta user credential', function () {
    ownerScopedFakeMeta([
        'alice-user-token' => [ownerScopedPage('pt-alice', withInstagram: true)],
        'bob-user-token' => [ownerScopedPage('pt-bob', withInstagram: true)],
    ]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'alice-user-token', owner: $this->alice, userId: 'fb-alice', extend: false);
    app(StoreInstagramAccounts::class)->handle(userToken: 'bob-user-token', owner: $this->bob, userId: 'fb-bob', extend: false);

    $aliceRow = ownerScopedRow($this->alice, 'instagram', 'ig-1');
    $bobRow = ownerScopedRow($this->bob, 'instagram', 'ig-1');

    expect($aliceRow)->not->toBeNull('Alice lost her Instagram row when Bob connected the same account')
        ->and($bobRow)->not->toBeNull()
        ->and($aliceRow->provider_holder_id)->toBe('fb-alice')
        ->and($aliceRow->credential->provider_holder_id)->toBe('fb-alice')
        ->and($aliceRow->credential->access_token)->toBe('alice-user-token')
        ->and($bobRow->credential->provider_holder_id)->toBe('fb-bob')
        // The companion Page rows are per owner too.
        ->and(ownerScopedRow($this->alice, 'facebook', 'page-1')?->credential->access_token)->toBe('pt-alice')
        ->and(ownerScopedRow($this->bob, 'facebook', 'page-1')?->credential->access_token)->toBe('pt-bob');
});

it('keeps the first owner\'s Instagram account usable when the second owner loses access to it', function () {
    ownerScopedFakeMeta([
        'alice-user-token' => [ownerScopedPage('pt-alice', withInstagram: true)],
        'bob-user-token' => [ownerScopedPage('pt-bob', withInstagram: true)],
        'bob-user-token-after-losing-admin' => [],
    ]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'alice-user-token', owner: $this->alice, userId: 'fb-alice', extend: false);
    app(StoreInstagramAccounts::class)->handle(userToken: 'bob-user-token', owner: $this->bob, userId: 'fb-bob', extend: false);

    Event::fake([AccountNeedsReconnect::class]);
    app(StoreInstagramAccounts::class)->handle(userToken: 'bob-user-token-after-losing-admin', owner: $this->bob, userId: 'fb-bob', extend: false);

    $aliceRow = ownerScopedRow($this->alice, 'instagram', 'ig-1');

    expect($aliceRow)->not->toBeNull('Alice lost her Instagram row when Bob connected the same account')
        ->and($aliceRow->status)->toBe(AccountStatus::Active)
        ->and($aliceRow->isUsable())->toBeTrue()
        ->and(ownerScopedRow($this->bob, 'instagram', 'ig-1')->status)->toBe(AccountStatus::NeedsReconnect);

    Event::assertNotDispatched(AccountNeedsReconnect::class, fn (AccountNeedsReconnect $event) => $event->account->is($aliceRow));
});

// LinkedIn organizations (StoreLinkedInOrganizations) ----------------------------

it('keeps a separate LinkedIn organization row for each owner, each on their own member credential', function () {
    ownerScopedFakeLinkedIn([
        'alice-member-token' => ['42'],
        'bob-member-token' => ['42'],
    ]);

    app(StoreLinkedInOrganizations::class)->handle(accessToken: 'alice-member-token', memberId: 'member-alice', owner: $this->alice);
    app(StoreLinkedInOrganizations::class)->handle(accessToken: 'bob-member-token', memberId: 'member-bob', owner: $this->bob);

    $aliceRow = ownerScopedRow($this->alice, 'linkedin', '42');
    $bobRow = ownerScopedRow($this->bob, 'linkedin', '42');

    expect($aliceRow)->not->toBeNull('Alice lost her LinkedIn organization row when Bob connected the same organization')
        ->and($bobRow)->not->toBeNull()
        ->and($aliceRow->provider_holder_id)->toBe('member-alice')
        ->and($aliceRow->credential->access_token)->toBe('alice-member-token')
        ->and($bobRow->provider_holder_id)->toBe('member-bob')
        ->and($bobRow->credential->access_token)->toBe('bob-member-token');
});

it('keeps the first owner\'s LinkedIn organization usable when the second owner\'s member loses admin rights', function () {
    ownerScopedFakeLinkedIn([
        'alice-member-token' => ['42'],
        'bob-member-token' => ['42'],
        'bob-member-token-after-losing-admin' => [],
    ]);

    app(StoreLinkedInOrganizations::class)->handle(accessToken: 'alice-member-token', memberId: 'member-alice', owner: $this->alice);
    app(StoreLinkedInOrganizations::class)->handle(accessToken: 'bob-member-token', memberId: 'member-bob', owner: $this->bob);

    Event::fake([AccountNeedsReconnect::class]);
    app(StoreLinkedInOrganizations::class)->handle(accessToken: 'bob-member-token-after-losing-admin', memberId: 'member-bob', owner: $this->bob);

    $aliceRow = ownerScopedRow($this->alice, 'linkedin', '42');

    expect($aliceRow)->not->toBeNull('Alice lost her LinkedIn organization row when Bob connected the same organization')
        ->and($aliceRow->status)->toBe(AccountStatus::Active)
        ->and($aliceRow->isUsable())->toBeTrue()
        ->and(ownerScopedRow($this->bob, 'linkedin', '42')->status)->toBe(AccountStatus::NeedsReconnect);

    Event::assertNotDispatched(AccountNeedsReconnect::class, fn (AccountNeedsReconnect $event) => $event->account->is($aliceRow));
});
