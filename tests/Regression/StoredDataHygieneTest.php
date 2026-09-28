<?php

/*
 * Regression: what the connect actions write on the rows must be accurate.
 *
 * In v1.1.0:
 *  - StoreLinkedInOrganizations has no way to record the granted scopes, so the
 *    member credential and every organization row keep scopes = null and
 *    hasScope() is always false for LinkedIn organizations;
 *  - StoreAccountFromSocialite stores Socialite's approvedScopes raw, so a
 *    provider that does not echo `scope` leaves [""] (or []) on the rows,
 *    which reads as "nothing granted" rather than "unknown";
 *  - StoreFacebookPages / StoreInstagramAccounts treat a failed best-effort
 *    debug_token call as "no scopes" and overwrite the stored scopes with [];
 *  - StoreFacebookPages stores a Page listed without an access_token as an
 *    Active account on an Active credential whose token is null, and a later
 *    listing without a token nulls a previously valid page token;
 *  - StoreInstagramAccounts and StoreLinkedInOrganizations replace the whole
 *    `profile` JSON on every reconnect, dropping keys the app added.
 *
 * Target behaviour (v1.2):
 *  - scopes use one convention everywhere: null = unknown, an array = the known
 *    grant. Blank entries are dropped, an empty/unknown result is stored as
 *    null, and an unknown result never erases a list that is already known;
 *  - SocialAccount::scopesKnown() / SocialToken::scopesKnown() expose that;
 *  - StoreLinkedInOrganizations::handle() accepts `?array $scopes` and writes
 *    it on the credential and on every organization row;
 *  - a Page without an access token is never stored as usable, and never
 *    overwrites an existing page token;
 *  - reconnecting merges the package's profile keys into the stored profile.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

/**
 * Fake the Graph API from a mutable state, so a test can change what Meta
 * answers between a first connect and a reconnect:
 *  - pages: the /me/accounts `data` list;
 *  - debug: [status, body] for /debug_token.
 */
function storedDataHygieneFakeMeta(ArrayObject $meta): void
{
    Http::fake(function (Request $request) use ($meta) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response(['access_token' => 'long-user-token', 'expires_in' => 5183944]),
            str_contains($url, '/debug_token') => Http::response($meta['debug'][1], $meta['debug'][0]),
            str_contains($url, '/me/accounts') => Http::response(['data' => $meta['pages']]),
            str_contains($url, '/me') => Http::response(['id' => 'fb-user-1']),
            default => Http::response([], 404),
        };
    });
}

/** A successful debug_token answer granting $scopes to every account. */
function storedDataHygieneDebugToken(array $scopes): array
{
    return [200, ['data' => ['is_valid' => true, 'scopes' => $scopes, 'granular_scopes' => []]]];
}

/**
 * Fake organizationAcls from a mutable [organization id => role] map. Answers
 * like LinkedIn: the whole list on the page at start=0 (paging carries the
 * total and no rel=next link), an empty page past the end.
 */
function storedDataHygieneFakeLinkedIn(ArrayObject $roles): void
{
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => function (Request $request) use ($roles) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $start = (int) ($query['start'] ?? 0);
        $elements = [];

        foreach ($roles as $id => $role) {
            $elements[] = [
                'role' => $role,
                'state' => 'APPROVED',
                'organization' => "urn:li:organization:{$id}",
                'organization~' => ['id' => (string) $id, 'localizedName' => "Org {$id}"],
            ];
        }

        return Http::response([
            'paging' => ['start' => $start, 'count' => (int) ($query['count'] ?? 10), 'total' => count($elements), 'links' => []],
            'elements' => $start === 0 ? $elements : [],
        ]);
    }]);
}

/** The Threads short-to-long exchange StoreAccountFromSocialite performs. */
function storedDataHygieneFakeThreadsExchange(): void
{
    Http::fake(['graph.threads.net/*' => Http::response([
        'access_token' => 'long-threads-token',
        'token_type' => 'bearer',
        'expires_in' => 5183944,
    ])]);
}

function storedDataHygieneAccount(string $provider, string $providerUserId): SocialAccount
{
    return SocialAccount::query()
        ->where('provider', $provider)
        ->where('provider_user_id', $providerUserId)
        ->sole();
}

function storedDataHygieneCredential(string $provider, string $holderId): SocialToken
{
    return SocialToken::query()
        ->where('provider', $provider)
        ->where('provider_holder_id', $holderId)
        ->sole();
}

/*
|--------------------------------------------------------------------------
| LinkedIn organizations record the granted scopes
|--------------------------------------------------------------------------
*/

it('records the granted scopes on the LinkedIn member credential and on every organization row', function () {
    storedDataHygieneFakeLinkedIn(new ArrayObject(['111' => 'ADMINISTRATOR', '222' => 'ADMINISTRATOR']));
    $granted = ['w_organization_social', 'r_organization_social'];

    app(StoreLinkedInOrganizations::class)->handle(
        accessToken: 'member-token',
        memberId: 'member-1',
        expiresAt: now()->addDays(60),
        scopes: $granted,
    );

    expect(storedDataHygieneCredential('linkedin', 'member-1')->scopes)->toBe($granted);

    foreach (['111', '222'] as $organizationId) {
        $organization = storedDataHygieneAccount('linkedin', $organizationId);

        expect($organization->scopes)->toBe($granted)
            ->and($organization->hasScope('w_organization_social'))->toBeTrue()
            ->and($organization->missingScopes($granted))->toBe([]);
    }
});

it('refreshes LinkedIn scopes on reconnect only when the new grant is known', function () {
    storedDataHygieneFakeLinkedIn(new ArrayObject(['111' => 'ADMINISTRATOR']));
    $store = app(StoreLinkedInOrganizations::class);
    $full = ['w_organization_social', 'r_organization_social', 'rw_organization_admin'];

    $store->handle(accessToken: 'token-1', memberId: 'member-1', expiresAt: now()->addDays(60), scopes: $full);

    // A reconnect that does not say what was granted leaves the known list alone.
    $store->handle(accessToken: 'token-2', memberId: 'member-1', expiresAt: now()->addDays(60));

    expect(storedDataHygieneCredential('linkedin', 'member-1')->scopes)->toBe($full)
        ->and(storedDataHygieneAccount('linkedin', '111')->scopes)->toBe($full);

    // A reconnect with a narrower grant replaces the stale list.
    $store->handle(accessToken: 'token-3', memberId: 'member-1', expiresAt: now()->addDays(60), scopes: ['w_organization_social']);

    $organization = storedDataHygieneAccount('linkedin', '111');

    expect(storedDataHygieneCredential('linkedin', 'member-1')->scopes)->toBe(['w_organization_social'])
        ->and($organization->scopes)->toBe(['w_organization_social'])
        ->and($organization->hasScope('rw_organization_admin'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Scopes: null means unknown, and the stored list is normalised
|--------------------------------------------------------------------------
*/

it('tells unknown scopes apart from an empty grant', function () {
    $credential = SocialToken::create([
        'provider' => 'threads',
        'provider_holder_id' => 'th-1',
        'access_token' => 'token',
        'status' => AccountStatus::Active,
    ]);
    $unknown = SocialAccount::create([
        'provider' => 'threads',
        'provider_user_id' => 'th-1',
        'social_token_id' => $credential->getKey(),
        'scopes' => null,
        'status' => AccountStatus::Active,
    ]);
    $none = SocialAccount::create([
        'provider' => 'threads',
        'provider_user_id' => 'th-2',
        'social_token_id' => $credential->getKey(),
        'scopes' => [],
        'status' => AccountStatus::Active,
    ]);

    expect($unknown->scopesKnown())->toBeFalse()
        ->and($none->scopesKnown())->toBeTrue()
        ->and($credential->scopesKnown())->toBeFalse()
        // The existing helpers keep their meaning: nothing is granted either way.
        ->and($unknown->grantedScopes())->toBe([])
        ->and($none->grantedScopes())->toBe([]);
});

it('stores unknown scopes as null when the provider does not echo them', function (array $approvedScopes) {
    storedDataHygieneFakeThreadsExchange();

    $account = app(StoreConnection::class)
        ->handle('threads', socialiteUser(['id' => 'th-1', 'approvedScopes' => $approvedScopes]))
        ->sole();

    expect($account->fresh()->scopes)->toBeNull()
        ->and(storedDataHygieneCredential('threads', 'th-1')->scopes)->toBeNull();
})->with([
    'Laravel base provider (explode on a missing scope)' => [explode(',', '')],
    'SocialiteProviders manager (missing scope)' => [[]],
]);

it('drops blank, padded and duplicate entries from the granted scopes', function () {
    $account = app(StoreAccountFromSocialite::class)->handle('tiktok', socialiteUser([
        'id' => 'tt-1',
        'approvedScopes' => ['user.info.basic', '', ' video.publish ', 'user.info.basic'],
    ]));

    expect($account->fresh()->scopes)->toBe(['user.info.basic', 'video.publish'])
        ->and(storedDataHygieneCredential('tiktok', 'tt-1')->scopes)->toBe(['user.info.basic', 'video.publish']);
});

it('keeps the recorded scopes when a reconnect does not report any', function () {
    storedDataHygieneFakeThreadsExchange();
    $store = app(StoreConnection::class);
    $known = ['threads_basic', 'threads_content_publish'];

    $account = $store->handle('threads', socialiteUser(['id' => 'th-1', 'approvedScopes' => []]))->sole();

    // Threads never echoes `scope`: the app records what it knows itself.
    $account->forceFill(['scopes' => $known])->save();
    $account->credential->forceFill(['scopes' => $known])->save();

    $store->handle('threads', socialiteUser(['id' => 'th-1', 'approvedScopes' => explode(',', '')]));

    expect(storedDataHygieneAccount('threads', 'th-1')->scopes)->toBe($known)
        ->and(storedDataHygieneCredential('threads', 'th-1')->scopes)->toBe($known);
});

/*
|--------------------------------------------------------------------------
| A failed best-effort debug_token never erases known scopes
|--------------------------------------------------------------------------
*/

it('keeps the stored Page scopes when debug_token fails on reconnect', function (int $status, array $body) {
    $granted = ['pages_show_list', 'pages_manage_posts'];
    $meta = new ArrayObject([
        'pages' => [['id' => 'page-1', 'name' => 'Page One', 'access_token' => 'page-token-1']],
        'debug' => storedDataHygieneDebugToken($granted),
    ]);
    storedDataHygieneFakeMeta($meta);
    $store = app(StoreFacebookPages::class);

    $store->handle(userToken: 'user-token', userId: 'fb-user-1');

    expect(storedDataHygieneAccount('facebook', 'page-1')->scopes)->toBe($granted);

    $meta['debug'] = [$status, $body];
    $store->handle(userToken: 'user-token', userId: 'fb-user-1');

    $page = storedDataHygieneAccount('facebook', 'page-1');

    expect($page->scopes)->toBe($granted)
        ->and($page->hasScope('pages_manage_posts'))->toBeTrue()
        ->and(storedDataHygieneCredential('facebook', 'page-1')->scopes)->toBe($granted)
        // Scopes are best effort: the reconnect itself still succeeds.
        ->and($page->status)->toBe(AccountStatus::Active);
})->with([
    'HTTP 500' => [500, ['error' => ['message' => 'An unexpected error has occurred.', 'code' => 2]]],
    'HTTP 400 with a top-level error' => [400, ['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]]],
    'HTTP 200 without data' => [200, []],
]);

it('keeps the stored Instagram and companion Page scopes when debug_token fails on reconnect', function () {
    $granted = ['pages_manage_posts', 'instagram_basic', 'instagram_content_publish'];
    $meta = new ArrayObject([
        'pages' => [[
            'id' => 'page-1',
            'name' => 'Page One',
            'access_token' => 'page-token-1',
            'instagram_business_account' => ['id' => 'ig-1', 'username' => 'acme'],
        ]],
        'debug' => storedDataHygieneDebugToken($granted),
    ]);
    storedDataHygieneFakeMeta($meta);
    $store = app(StoreInstagramAccounts::class);

    $store->handle(userToken: 'user-token', userId: 'fb-user-1');

    expect(storedDataHygieneAccount('instagram', 'ig-1')->scopes)->toBe($granted);

    $meta['debug'] = [500, ['error' => ['message' => 'An unexpected error has occurred.', 'code' => 2]]];
    $store->handle(userToken: 'user-token', userId: 'fb-user-1');

    expect(storedDataHygieneAccount('instagram', 'ig-1')->scopes)->toBe($granted)
        ->and(storedDataHygieneAccount('facebook', 'page-1')->scopes)->toBe($granted)
        ->and(storedDataHygieneCredential('facebook', 'page-1')->scopes)->toBe($granted);
});

it('stores unknown scopes, not an empty grant, when debug_token fails on a first connect', function () {
    storedDataHygieneFakeMeta(new ArrayObject([
        'pages' => [['id' => 'page-1', 'name' => 'Page One', 'access_token' => 'page-token-1']],
        'debug' => [500, ['error' => ['message' => 'An unexpected error has occurred.', 'code' => 2]]],
    ]));

    app(StoreFacebookPages::class)->handle(userToken: 'user-token', userId: 'fb-user-1');

    expect(storedDataHygieneAccount('facebook', 'page-1')->scopes)->toBeNull()
        ->and(storedDataHygieneCredential('facebook', 'page-1')->scopes)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| A Page listed without an access token
|--------------------------------------------------------------------------
*/

it('does not store a Page listed without an access token as a usable account', function () {
    Event::fake([AccountConnected::class]);
    storedDataHygieneFakeMeta(new ArrayObject([
        'pages' => [
            ['id' => 'page-ok', 'name' => 'With token', 'access_token' => 'page-token-ok'],
            ['id' => 'page-no-token', 'name' => 'Without token'],
        ],
        'debug' => storedDataHygieneDebugToken(['pages_manage_posts']),
    ]));

    $accounts = app(StoreFacebookPages::class)->handle(userToken: 'user-token', userId: 'fb-user-1');

    expect(SocialAccount::query()->usable()->pluck('provider_user_id')->all())->toBe(['page-ok'])
        ->and($accounts->filter(fn (SocialAccount $account) => $account->isUsable())->pluck('provider_user_id')->values()->all())
        ->toBe(['page-ok'])
        ->and(SocialToken::all()->filter(
            fn (SocialToken $token) => $token->status === AccountStatus::Active && blank($token->access_token)
        ))->toBeEmpty();

    Event::assertNotDispatched(
        AccountConnected::class,
        fn (AccountConnected $event) => $event->account->provider_user_id === 'page-no-token',
    );
});

it('never replaces a valid Page token with null when a later connection lists the Page without one', function () {
    $meta = new ArrayObject([
        'pages' => [['id' => 'page-shared', 'name' => 'Shared', 'access_token' => 'page-token-from-a']],
        'debug' => storedDataHygieneDebugToken(['pages_manage_posts']),
    ]);
    storedDataHygieneFakeMeta($meta);
    $store = app(StoreFacebookPages::class);

    $store->handle(userToken: 'user-a-token', userId: 'fb-user-a');

    // A co-admin whose role gives Meta no page token for this Page connects it.
    $meta['pages'] = [['id' => 'page-shared', 'name' => 'Shared']];
    $store->handle(userToken: 'user-b-token', userId: 'fb-user-b');

    $credential = storedDataHygieneCredential('facebook', 'page-shared');

    expect($credential->access_token)->toBe('page-token-from-a')
        ->and($credential->status)->toBe(AccountStatus::Active)
        ->and(storedDataHygieneAccount('facebook', 'page-shared')->isUsable())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Reconnecting merges the package's profile keys
|--------------------------------------------------------------------------
*/

it('keeps app-added profile keys on a LinkedIn organization when reconnecting', function () {
    $roles = new ArrayObject(['111' => 'ADMINISTRATOR']);
    storedDataHygieneFakeLinkedIn($roles);
    $store = app(StoreLinkedInOrganizations::class);

    $store->handle(accessToken: 'token-1', memberId: 'member-1', expiresAt: now()->addDays(60));

    $organization = storedDataHygieneAccount('linkedin', '111');
    $organization->profile = array_merge($organization->profile, ['database_id' => 'db-1']);
    $organization->save();

    $roles['111'] = 'CONTENT_ADMINISTRATOR';
    $store->handle(accessToken: 'token-2', memberId: 'member-1', expiresAt: now()->addDays(60));

    // Key by key rather than the whole array: a later version may add package keys.
    expect(storedDataHygieneAccount('linkedin', '111')->profile)
        ->toHaveKey('database_id', 'db-1')                 // app keys survive
        ->toHaveKey('role', 'CONTENT_ADMINISTRATOR')       // package keys are refreshed
        ->toHaveKey('organization_urn', 'urn:li:organization:111');
});

it('keeps app-added profile keys on Instagram accounts and companion Pages when reconnecting', function () {
    storedDataHygieneFakeMeta(new ArrayObject([
        'pages' => [[
            'id' => 'page-1',
            'name' => 'Page One',
            'access_token' => 'page-token-1',
            'instagram_business_account' => ['id' => 'ig-1', 'username' => 'acme'],
        ]],
        'debug' => storedDataHygieneDebugToken(['instagram_content_publish', 'pages_manage_posts']),
    ]));
    $store = app(StoreInstagramAccounts::class);

    $store->handle(userToken: 'user-token', userId: 'fb-user-1');

    $instagram = storedDataHygieneAccount('instagram', 'ig-1');
    $instagram->profile = array_merge($instagram->profile, ['database_id' => 'db-ig', 'fb_page_id' => 'stale']);
    $instagram->save();

    $page = storedDataHygieneAccount('facebook', 'page-1');
    $page->profile = array_merge($page->profile, ['database_id' => 'db-page']);
    $page->save();

    $store->handle(userToken: 'user-token', userId: 'fb-user-1');

    expect(storedDataHygieneAccount('instagram', 'ig-1')->profile)
        ->toHaveKey('database_id', 'db-ig')
        ->toHaveKey('fb_page_id', 'page-1') // the package's own key wins
        ->and(storedDataHygieneAccount('facebook', 'page-1')->profile)
        ->toHaveKey('database_id', 'db-page')
        ->toHaveKey('fb_page_id', 'page-1')
        ->toHaveKey('ig_account_id', 'ig-1');
});
