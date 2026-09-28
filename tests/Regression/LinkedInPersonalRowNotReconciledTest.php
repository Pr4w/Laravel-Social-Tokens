<?php

/*
 * Regression: organization reconciliation must never touch the member's
 * personal LinkedIn row.
 *
 * The personal profile is stored with StoreAccountFromSocialite (or
 * StoreConnection, which delegates to it) under the 'linkedin' provider. That
 * row carries provider_holder_id = member id, the same holder id
 * StoreLinkedInOrganizations uses to scope its reconciliation. In v1.1.0 the
 * reconciliation therefore flags the personal row NeedsReconnect ("Organization
 * no longer administered...") and fires AccountNeedsReconnect, and after that
 * SocialTokens::validAccessTokenFor() refuses to post as the member.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\SocialTokens;

/** Store the member's personal LinkedIn row through a real package action. */
function linkedInPersonalRowStorePersonal(string $via = 'StoreAccountFromSocialite'): SocialAccount
{
    $user = socialiteUser([
        'id' => 'member-1',
        'name' => 'Member One',
        'token' => 'member-token',
        'expiresIn' => 60 * 24 * 3600, // LinkedIn access tokens live 60 days
        'approvedScopes' => ['openid', 'profile', 'w_member_social'],
    ]);

    return $via === 'StoreConnection'
        ? app(StoreConnection::class)->handle('linkedin', $user)->sole()
        : app(StoreAccountFromSocialite::class)->handle('linkedin', $user);
}

/**
 * Store the member's organizations through the real action. The organizationAcls
 * fake installed in beforeEach answers with the ids given here.
 *
 * @param  array<int, string>  $organizationIds
 */
function linkedInPersonalRowStoreOrganizations(array $organizationIds): void
{
    test()->administeredOrganizationIds = $organizationIds;

    app(StoreLinkedInOrganizations::class)->handle(
        accessToken: 'member-token',
        memberId: 'member-1',
        expiresAt: now()->addDays(60),
    );
}

/** The organization row stored for this organization id. */
function linkedInPersonalRowOrganization(string $organizationId): SocialAccount
{
    return SocialAccount::query()->where('provider_user_id', $organizationId)->sole();
}

/** The personal row can post: its own status, its effective status, and the token API agree. */
function linkedInPersonalRowAssertUsable(SocialAccount $personal): void
{
    $fresh = $personal->fresh();

    expect($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->last_error)->toBeNull()
        ->and($fresh->effectiveStatus())->toBe(AccountStatus::Active)
        ->and(app(SocialTokens::class)->validAccessTokenFor($fresh))->toBe('member-token');
}

beforeEach(function () {
    Event::fake([AccountNeedsReconnect::class]);

    // One stub for the whole test: Http::fake() stubs are matched first-come,
    // so a second Http::fake() in the same test would never be reached. The
    // closure reads the ids at request time instead, and honours start/count so
    // any pagination strategy (paging.total, short page, empty page) terminates.
    $this->administeredOrganizationIds = [];

    Http::fake([
        'api.linkedin.com/*organizationAcls*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $ids = $this->administeredOrganizationIds;
            $page = array_slice($ids, (int) ($query['start'] ?? 0), (int) ($query['count'] ?? 100));

            return Http::response([
                'paging' => ['start' => (int) ($query['start'] ?? 0), 'count' => count($page), 'total' => count($ids)],
                'elements' => array_map(fn (string $id) => [
                    'role' => 'ADMINISTRATOR',
                    'state' => 'APPROVED',
                    'roleAssignee' => 'urn:li:person:member-1',
                    'organization' => "urn:li:organization:{$id}",
                    'organization~' => ['id' => (int) $id, 'localizedName' => "Org {$id}"],
                ], $page),
            ]);
        },
    ]);
});

it('keeps the personal LinkedIn row usable when storing organizations', function (string $via) {
    $personal = linkedInPersonalRowStorePersonal($via);

    linkedInPersonalRowStoreOrganizations(['42']);

    linkedInPersonalRowAssertUsable($personal);
    Event::assertNotDispatched(AccountNeedsReconnect::class);
})->with(['StoreAccountFromSocialite', 'StoreConnection']);

it('keeps the personal LinkedIn row usable when the member administers no organizations', function () {
    $personal = linkedInPersonalRowStorePersonal();

    linkedInPersonalRowStoreOrganizations([]);

    linkedInPersonalRowAssertUsable($personal);
    Event::assertNotDispatched(AccountNeedsReconnect::class);
});

it('fires no AccountNeedsReconnect for the personal row when organizations are re-stored after it', function () {
    // Organizations first, then the personal profile, then a later reconnect
    // of the organizations (same member, same org list).
    linkedInPersonalRowStoreOrganizations(['42']);
    $personal = linkedInPersonalRowStorePersonal();
    linkedInPersonalRowStoreOrganizations(['42']);

    linkedInPersonalRowAssertUsable($personal);
    Event::assertNotDispatched(AccountNeedsReconnect::class);
});

it('flags only the dropped organization, never the personal row, in the same reconciliation', function () {
    $personal = linkedInPersonalRowStorePersonal();
    linkedInPersonalRowStoreOrganizations(['111', '222']);

    // The member no longer administers 222.
    linkedInPersonalRowStoreOrganizations(['111']);

    expect(linkedInPersonalRowOrganization('222')->status)
        ->toBe(AccountStatus::NeedsReconnect)
        ->and(linkedInPersonalRowOrganization('111')->status)
        ->toBe(AccountStatus::Active);

    linkedInPersonalRowAssertUsable($personal);
    Event::assertDispatchedTimes(AccountNeedsReconnect::class, 1);
    Event::assertDispatched(
        AccountNeedsReconnect::class,
        fn (AccountNeedsReconnect $event) => $event->account->provider_user_id === '222',
    );
});

// Guard (already passes on v1.1.0): the fix must not switch reconciliation off.
// A member who administers no organization anymore still gets every
// organization row flagged.
it('still flags every organization row when the member administers no organizations anymore', function () {
    linkedInPersonalRowStorePersonal();
    linkedInPersonalRowStoreOrganizations(['111', '222']);

    linkedInPersonalRowStoreOrganizations([]);

    foreach (['111', '222'] as $organizationId) {
        expect(linkedInPersonalRowOrganization($organizationId)->status)->toBe(AccountStatus::NeedsReconnect);

        Event::assertDispatched(
            AccountNeedsReconnect::class,
            fn (AccountNeedsReconnect $event) => $event->account->provider_user_id === $organizationId,
        );
    }
});
