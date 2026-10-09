<?php

/*
 * Reconciliation follows the external user, not the owner: Meta and LinkedIn
 * grant access per Facebook user or LinkedIn member, not per connection. When
 * the same user is connected under two owners and loses a Page, an Instagram
 * account or an organization, one owner's reconnect flags both owners' rows,
 * each with its own AccountNeedsReconnect (README "Owners", UPGRADE "Behaviour
 * changes to know"). An owner filter on the reconciliation queries would leave
 * the other owner's row active, failing at publish time with a permission
 * error instead of asking for a reconnect.
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/**
 * The Graph API, answering /me/accounts per user token (the bearer header).
 *
 * @param  array<string, array<int, array<string, mixed>>>  $pagesByUserToken
 */
function crossOwnerFakeMeta(array $pagesByUserToken): void
{
    Http::fake(function (Request $request) use ($pagesByUserToken) {
        if (str_contains($request->url(), '/debug_token')) {
            return Http::response(['data' => ['scopes' => ['pages_manage_posts', 'instagram_content_publish'], 'granular_scopes' => []]]);
        }

        $userToken = Str::after($request->header('Authorization')[0] ?? '', 'Bearer ');

        return str_contains($request->url(), '/me/accounts') && array_key_exists($userToken, $pagesByUserToken)
            ? Http::response(['data' => $pagesByUserToken[$userToken]])
            : Http::response(['error' => ['message' => 'Unexpected Graph call', 'code' => 100]], 400);
    });
}

/** A Page as /me/accounts lists it, with its linked Instagram account if given. */
function crossOwnerPage(string $id, string $pageToken, ?string $instagramId = null): array
{
    return array_filter([
        'id' => $id,
        'name' => "Page {$id}",
        'access_token' => $pageToken,
        'instagram_business_account' => $instagramId === null ? null : ['id' => $instagramId, 'username' => "ig_{$instagramId}"],
    ]);
}

/**
 * LinkedIn's organizationAcls per member token (the bearer header).
 *
 * @param  array<string, array<int, string>>  $orgIdsByMemberToken
 */
function crossOwnerFakeLinkedIn(array $orgIdsByMemberToken): void
{
    Http::fake(function (Request $request) use ($orgIdsByMemberToken) {
        $memberToken = Str::after($request->header('Authorization')[0] ?? '', 'Bearer ');

        if (! str_contains($request->url(), 'api.linkedin.com/v2/organizationAcls') || ! array_key_exists($memberToken, $orgIdsByMemberToken)) {
            return Http::response(['message' => 'Unexpected LinkedIn call'], 404);
        }

        return Http::response(['elements' => array_map(fn (string $id) => [
            'role' => 'ADMINISTRATOR',
            'state' => 'APPROVED',
            'organization~' => ['id' => $id, 'localizedName' => "Org {$id}"],
        ], $orgIdsByMemberToken[$memberToken])]);
    });
}

function crossOwnerRow(Model $owner, string $provider, string $providerUserId): SocialAccount
{
    return SocialAccount::ownedBy($owner)->where('provider', $provider)->where('provider_user_id', $providerUserId)->sole();
}

/** Each row's AccountNeedsReconnect, counted per row. */
function crossOwnerFlags(SocialAccount ...$rows): array
{
    return array_map(fn (SocialAccount $row) => Event::dispatched(
        AccountNeedsReconnect::class,
        fn (AccountNeedsReconnect $event) => $event->account->is($row),
    )->count(), $rows);
}

beforeEach(function () {
    $this->alice = Owner::create(['name' => 'alice']);
    $this->bob = Owner::create(['name' => 'bob']);
});

it('flags the Page rows of every owner when their shared Facebook user stops managing the Page', function () {
    crossOwnerFakeMeta([
        'alice-token' => [crossOwnerPage('page-1', 'pt-a1'), crossOwnerPage('page-2', 'pt-a2')],
        'bob-token' => [crossOwnerPage('page-1', 'pt-b1')],
        'alice-token-later' => [crossOwnerPage('page-2', 'pt-a2')],
    ]);

    app(StoreFacebookPages::class)->handle(userToken: 'alice-token', owner: $this->alice, userId: 'fb-shared', extend: false);
    app(StoreFacebookPages::class)->handle(userToken: 'bob-token', owner: $this->bob, userId: 'fb-shared', extend: false);

    Event::fake([AccountNeedsReconnect::class]);
    app(StoreFacebookPages::class)->handle(userToken: 'alice-token-later', owner: $this->alice, userId: 'fb-shared', extend: false);

    $alicePage = crossOwnerRow($this->alice, 'facebook', 'page-1');
    $bobPage = crossOwnerRow($this->bob, 'facebook', 'page-1');

    expect($alicePage->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($bobPage->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($bobPage->last_error)->toBe('Page no longer managed by the connected Facebook user.')
        ->and(crossOwnerRow($this->alice, 'facebook', 'page-2')->status)->toBe(AccountStatus::Active)
        ->and(crossOwnerFlags($alicePage, $bobPage))->toBe([1, 1]);
});

it('flags the Instagram rows of every owner when their shared Facebook user stops managing the account', function () {
    crossOwnerFakeMeta([
        'alice-token' => [crossOwnerPage('page-1', 'pt-a1', 'ig-1'), crossOwnerPage('page-2', 'pt-a2', 'ig-2')],
        'bob-token' => [crossOwnerPage('page-1', 'pt-b1', 'ig-1')],
        'alice-token-later' => [crossOwnerPage('page-2', 'pt-a2', 'ig-2')],
    ]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'alice-token', owner: $this->alice, userId: 'fb-shared', extend: false);
    app(StoreInstagramAccounts::class)->handle(userToken: 'bob-token', owner: $this->bob, userId: 'fb-shared', extend: false);

    Event::fake([AccountNeedsReconnect::class]);
    app(StoreInstagramAccounts::class)->handle(userToken: 'alice-token-later', owner: $this->alice, userId: 'fb-shared', extend: false);

    $aliceInstagram = crossOwnerRow($this->alice, 'instagram', 'ig-1');
    $bobInstagram = crossOwnerRow($this->bob, 'instagram', 'ig-1');

    expect($aliceInstagram->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($bobInstagram->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($bobInstagram->last_error)->toBe('Instagram account no longer managed by the connected Facebook user.')
        ->and(crossOwnerRow($this->alice, 'instagram', 'ig-2')->status)->toBe(AccountStatus::Active)
        ->and(crossOwnerFlags($aliceInstagram, $bobInstagram))->toBe([1, 1]);
});

it('flags the organization rows of every owner when their shared LinkedIn member stops administering it', function () {
    crossOwnerFakeLinkedIn([
        'alice-member-token' => ['42', '43'],
        'bob-member-token' => ['42'],
        'alice-member-token-later' => ['43'],
    ]);

    app(StoreLinkedInOrganizations::class)->handle(accessToken: 'alice-member-token', memberId: 'member-shared', owner: $this->alice);
    app(StoreLinkedInOrganizations::class)->handle(accessToken: 'bob-member-token', memberId: 'member-shared', owner: $this->bob);

    Event::fake([AccountNeedsReconnect::class]);
    app(StoreLinkedInOrganizations::class)->handle(accessToken: 'alice-member-token-later', memberId: 'member-shared', owner: $this->alice);

    $aliceOrganization = crossOwnerRow($this->alice, 'linkedin', '42');
    $bobOrganization = crossOwnerRow($this->bob, 'linkedin', '42');

    expect($aliceOrganization->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($bobOrganization->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($bobOrganization->last_error)->toBe('Organization no longer administered by the connected LinkedIn member.')
        ->and(crossOwnerRow($this->alice, 'linkedin', '43')->status)->toBe(AccountStatus::Active)
        ->and(crossOwnerFlags($aliceOrganization, $bobOrganization))->toBe([1, 1]);
});
