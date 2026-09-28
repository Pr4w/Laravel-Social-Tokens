<?php

/*
 * Regression: only a complete, well-formed listing may drive reconciliation.
 *
 * StoreFacebookPages, StoreInstagramAccounts and StoreLinkedInOrganizations
 * flag every Active row of the holder that is missing from the provider's
 * listing (NeedsReconnect + AccountNeedsReconnect). In v1.1.0 the listing can
 * come back incomplete without any error:
 *
 * - FacebookConnector::fetchPages() never checks the HTTP status nor that
 *   `data` is a list. A 4xx without a Graph `error` object (an HTML page from a
 *   proxy or WAF, `{}`, an empty 404) or a 200 without `data` reads as the end
 *   of the list: empty on the first result page, truncated on a later one.
 * - LinkedInConnector::fetchOrganizations() reads a 200 without `elements` the
 *   same way, stops as soon as a page holds fewer than the 100 requested
 *   elements (it ignores paging.links rel=next), and mapOrganizations() drops
 *   an APPROVED element whose `organization~` decoration LinkedIn throttled
 *   (`organization!`), although its `organization` URN carries the id.
 *
 * Desired: any listing page that is not a 2xx carrying the list aborts the
 * store (RuntimeException, nothing stored, nothing flagged); LinkedIn pages to
 * the real end of the list, resolves the organization id from the URN when the
 * decoration is missing, and never reconciles while an APPROVED element stays
 * unresolved. A complete listing still flags what is really gone.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
|--------------------------------------------------------------------------
| Helpers (prefixed: Pest files share one global function namespace)
|--------------------------------------------------------------------------
*/

/**
 * Answer the Graph calls made by StoreFacebookPages / StoreInstagramAccounts.
 * /me/accounts lists $pageIds over two result pages: the first two on page 1
 * (whose paging.next points to page 2), the rest on page 2. Every page carries
 * its linked Instagram Business account "ig-<id>". $broken, when given,
 * answers result page $brokenPage instead.
 *
 * @param  array<int, string>  $pageIds
 */
function incompleteListsGraph(array $pageIds = ['p1', 'p2', 'p3', 'p4'], ?int $brokenPage = null, ?Closure $broken = null): Closure
{
    $page = fn (string $id) => [
        'id' => $id,
        'name' => "Page {$id}",
        'access_token' => "pt-{$id}",
        'picture' => ['data' => ['url' => "{$id}.png"]],
        'instagram_business_account' => ['id' => "ig-{$id}", 'username' => "insta_{$id}"],
    ];

    return function (Request $request) use ($pageIds, $brokenPage, $broken, $page) {
        $url = $request->url();

        if (str_contains($url, '/debug_token')) {
            return Http::response(['data' => ['scopes' => ['pages_manage_posts'], 'granular_scopes' => []]]);
        }

        if (! str_contains($url, '/me/accounts')) {
            return Http::response(['error' => ['message' => "Unexpected Graph call: {$url}", 'type' => 'OAuthException', 'code' => 100]], 400);
        }

        $resultPage = str_contains($url, 'after=CURSOR2') ? 2 : 1;

        if ($resultPage === $brokenPage) {
            return $broken();
        }

        return $resultPage === 1
            ? Http::response([
                'data' => array_map($page, array_slice($pageIds, 0, 2)),
                'paging' => [
                    'cursors' => ['before' => 'CURSOR1', 'after' => 'CURSOR2'],
                    'next' => 'https://graph.facebook.com/v23.0/me/accounts?access_token=long-user-token&fields=id%2Cname&limit=2&after=CURSOR2',
                ],
            ])
            : Http::response([
                'data' => array_map($page, array_slice($pageIds, 2)),
                'paging' => ['cursors' => ['before' => 'CURSOR2', 'after' => 'CURSOR3']],
            ]);
    };
}

/**
 * One organizationAcls element as LinkedIn returns it with the roleAssignee
 * projection: the `organization` URN plus its `organization~` decoration.
 */
function incompleteListsAcl(string $id, string $state = 'APPROVED'): array
{
    return [
        'role' => 'ADMINISTRATOR',
        'state' => $state,
        'organization' => "urn:li:organization:{$id}",
        'organization~' => ['id' => (int) $id, 'localizedName' => "Org {$id}"],
    ];
}

/**
 * The same element when LinkedIn could not decorate it (documented behaviour
 * under decoration rate limiting): a 200, the URN, and `organization!` in
 * place of `organization~`.
 */
function incompleteListsThrottledAcl(string $id): array
{
    return [
        'role' => 'ADMINISTRATOR',
        'state' => 'APPROVED',
        'organization' => "urn:li:organization:{$id}",
        'organization!' => [
            'status' => 429,
            'serviceErrorCode' => 101,
            'message' => 'Resource level throttle limit for calls to this resource is reached.',
        ],
    ];
}

/**
 * Answer organizationAcls like LinkedIn: $elements served from the request's
 * `start`, min(requested count, $cap) at a time, paging.count reporting the
 * size actually used and paging.links holding rel=next while more remain.
 * Past the end, an empty page. $withTotal adds paging.total. $broken, when
 * given, answers the page starting at $brokenStart instead.
 *
 * @param  array<int, array<string, mixed>>  $elements
 */
function incompleteListsAcls(array $elements, int $cap = 100, bool $withTotal = false, ?int $brokenStart = null, ?Closure $broken = null): Closure
{
    return function (Request $request) use ($elements, $cap, $withTotal, $brokenStart, $broken) {
        // Read the URL itself, so a next-link href (no separate query array) works too.
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $start = (int) ($query['start'] ?? 0);
        $count = min((int) ($query['count'] ?? 10), $cap);

        if ($brokenStart !== null && $start === $brokenStart) {
            return $broken();
        }

        $next = $start + $count;
        $paging = [
            'start' => $start,
            'count' => $count,
            'links' => $next < count($elements)
                ? [['rel' => 'next', 'type' => 'application/json', 'href' => "/v2/organizationAcls?count={$count}&q=roleAssignee&start={$next}"]]
                : [],
        ];

        if ($withTotal) {
            $paging['total'] = count($elements);
        }

        return Http::response(['paging' => $paging, 'elements' => array_slice($elements, $start, $count)]);
    };
}

function incompleteListsConnectFacebook(): Collection
{
    return app(StoreFacebookPages::class)->handle(
        userToken: 'long-user-token',
        owner: test()->owner,
        userId: 'fb-user',
        extend: false,
    );
}

function incompleteListsConnectInstagram(): Collection
{
    return app(StoreInstagramAccounts::class)->handle(
        userToken: 'long-user-token',
        owner: test()->owner,
        userId: 'fb-user',
        extend: false,
    );
}

function incompleteListsConnectLinkedIn(): Collection
{
    return app(StoreLinkedInOrganizations::class)->handle(
        accessToken: 'member-token',
        memberId: 'member-1',
        owner: test()->owner,
        expiresAt: now()->addDays(60),
    );
}

/** Run a store action and return what it threw, or null when it returned. */
function incompleteListsAttempt(Closure $store): ?Throwable
{
    try {
        $store();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * The status of each row, keyed by provider_user_id, for readable diffs.
 *
 * @return array<string, string>
 */
function incompleteListsStatuses(string $provider): array
{
    return SocialAccount::query()
        ->where('provider', $provider)
        ->orderBy('provider_user_id')
        ->get()
        ->mapWithKeys(fn (SocialAccount $account) => [$account->provider_user_id => $account->status->value])
        ->all();
}

/** @return array<string, string> Every id mapped to 'active'. */
function incompleteListsAllActive(array $ids): array
{
    sort($ids);

    return array_fill_keys($ids, AccountStatus::Active->value);
}

beforeEach(function () {
    Event::fake([AccountNeedsReconnect::class]);

    $this->owner = Owner::create();

    // One stub for the whole test (Http::fake stubs match first-come); each
    // phase swaps the provider behaviour it reads at request time.
    $this->provider = fn () => Http::response(['error' => 'no provider behaviour set'], 500);
    Http::fake(fn (Request $request) => ($this->provider)($request));
});

/*
|--------------------------------------------------------------------------
| Facebook Pages / Instagram: fetchPages()
|--------------------------------------------------------------------------
*/

dataset('incomplete facebook listing pages', [
    'page 1: HTML 400 from a proxy' => [1, fn () => Http::response('<html><body>Bad Request</body></html>', 400, ['Content-Type' => 'text/html'])],
    'page 1: HTML 403 from a WAF' => [1, fn () => Http::response('<html><body>Access denied</body></html>', 403, ['Content-Type' => 'text/html'])],
    'page 1: empty 404' => [1, fn () => Http::response('', 404)],
    'page 1: {} 401' => [1, fn () => Http::response('{}', 401, ['Content-Type' => 'application/json'])],
    'page 1: 200 HTML page' => [1, fn () => Http::response('<html><body>Captive portal</body></html>', 200, ['Content-Type' => 'text/html'])],
    'page 1: 200 without data' => [1, fn () => Http::response(['paging' => ['cursors' => ['before' => 'a', 'after' => 'b']]])],
    'page 1: 200 with data null' => [1, fn () => Http::response(['data' => null])],
    'page 2: HTML 400 from a proxy' => [2, fn () => Http::response('<html><body>Bad Request</body></html>', 400, ['Content-Type' => 'text/html'])],
    'page 2: 200 {}' => [2, fn () => Http::response('{}', 200, ['Content-Type' => 'application/json'])],
]);

it('aborts the Facebook pages store without flagging anything when a listing page is not a list', function (int $brokenPage, Closure $broken) {
    $this->provider = incompleteListsGraph();
    incompleteListsConnectFacebook();

    $this->provider = incompleteListsGraph(brokenPage: $brokenPage, broken: $broken);
    $thrown = incompleteListsAttempt(fn () => incompleteListsConnectFacebook());

    expect(incompleteListsStatuses('facebook'))->toBe(incompleteListsAllActive(['p1', 'p2', 'p3', 'p4']));
    Event::assertNotDispatched(AccountNeedsReconnect::class);

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain('Could not list Facebook pages');
})->with('incomplete facebook listing pages');

it('aborts the Instagram store without flagging anything when a listing page is not a list', function (int $brokenPage, Closure $broken) {
    $this->provider = incompleteListsGraph();
    incompleteListsConnectInstagram();

    $this->provider = incompleteListsGraph(brokenPage: $brokenPage, broken: $broken);
    $thrown = incompleteListsAttempt(fn () => incompleteListsConnectInstagram());

    expect(incompleteListsStatuses('instagram'))->toBe(incompleteListsAllActive(['ig-p1', 'ig-p2', 'ig-p3', 'ig-p4']))
        ->and(incompleteListsStatuses('facebook'))->toBe(incompleteListsAllActive(['p1', 'p2', 'p3', 'p4']));
    Event::assertNotDispatched(AccountNeedsReconnect::class);

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain('Could not list Facebook pages');
})->with([
    'page 1: HTML 400 from a proxy' => [1, fn () => Http::response('<html><body>Bad Request</body></html>', 400, ['Content-Type' => 'text/html'])],
    'page 2: 200 without data' => [2, fn () => Http::response(['paging' => ['cursors' => ['before' => 'a', 'after' => 'b']]])],
]);

// Guard (already passes on v1.1.0): the fix must not switch reconciliation off.
it('still flags only the Page the user no longer manages after a complete two-page listing', function () {
    $this->provider = incompleteListsGraph(['p1', 'p2', 'p3', 'p4', 'p9']);
    incompleteListsConnectFacebook();

    $this->provider = incompleteListsGraph(['p1', 'p2', 'p3', 'p4']);
    $accounts = incompleteListsConnectFacebook();

    expect($accounts->pluck('provider_user_id')->all())->toBe(['p1', 'p2', 'p3', 'p4'])
        ->and(incompleteListsStatuses('facebook'))->toBe(array_merge(
            incompleteListsAllActive(['p1', 'p2', 'p3', 'p4']),
            ['p9' => AccountStatus::NeedsReconnect->value],
        ));
    Event::assertDispatchedTimes(AccountNeedsReconnect::class, 1);
});

// Guard (already passes on v1.1.0): a well-formed empty list is a real answer.
it('still flags every Page when a well-formed listing says the user manages none', function () {
    $this->provider = incompleteListsGraph();
    incompleteListsConnectFacebook();

    $this->provider = incompleteListsGraph(brokenPage: 1, broken: fn () => Http::response(['data' => []]));
    $accounts = incompleteListsConnectFacebook();

    expect($accounts)->toBeEmpty()
        ->and(incompleteListsStatuses('facebook'))
        ->toBe(array_fill_keys(['p1', 'p2', 'p3', 'p4'], AccountStatus::NeedsReconnect->value));
    Event::assertDispatchedTimes(AccountNeedsReconnect::class, 4);
});

/*
|--------------------------------------------------------------------------
| LinkedIn organizations: fetchOrganizations() / mapOrganizations()
|--------------------------------------------------------------------------
*/

it('aborts the LinkedIn organizations store without flagging anything when a listing page is not a list', function (int $brokenStart, Closure $broken) {
    $elements = array_map(fn (string $id) => incompleteListsAcl($id), ['111', '222', '333', '444']);

    // Two organizations per page, with paging.total, so v1.1.0 does request page 2.
    $this->provider = incompleteListsAcls($elements, cap: 2, withTotal: true);
    incompleteListsConnectLinkedIn();

    $this->provider = incompleteListsAcls($elements, cap: 2, withTotal: true, brokenStart: $brokenStart, broken: $broken);
    $thrown = incompleteListsAttempt(fn () => incompleteListsConnectLinkedIn());

    expect(incompleteListsStatuses('linkedin'))->toBe(incompleteListsAllActive(['111', '222', '333', '444']));
    Event::assertNotDispatched(AccountNeedsReconnect::class);

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain('Could not list LinkedIn organizations');
})->with([
    'page 1: 200 HTML page' => [0, fn () => Http::response('<html><body>Captive portal</body></html>', 200, ['Content-Type' => 'text/html'])],
    'page 1: 200 {}' => [0, fn () => Http::response('{}', 200, ['Content-Type' => 'application/json'])],
    // v1.1.0 does not flag here: array_merge() throws a TypeError out of the action.
    'page 1: 200 with elements null' => [0, fn () => Http::response(['paging' => ['start' => 0, 'count' => 2], 'elements' => null])],
    'page 2: 200 without elements' => [2, fn () => Http::response(['paging' => ['start' => 2, 'count' => 2, 'total' => 4]])],
]);

// Guard (already passes on v1.1.0: fetchOrganizations checks failed()).
it('keeps aborting without flagging anything when LinkedIn answers a non-2xx listing page', function (int $brokenStart, Closure $broken) {
    $elements = array_map(fn (string $id) => incompleteListsAcl($id), ['111', '222', '333', '444']);

    $this->provider = incompleteListsAcls($elements, cap: 2, withTotal: true);
    incompleteListsConnectLinkedIn();

    $this->provider = incompleteListsAcls($elements, cap: 2, withTotal: true, brokenStart: $brokenStart, broken: $broken);
    $thrown = incompleteListsAttempt(fn () => incompleteListsConnectLinkedIn());

    expect(incompleteListsStatuses('linkedin'))->toBe(incompleteListsAllActive(['111', '222', '333', '444']))
        ->and($thrown)->toBeInstanceOf(RuntimeException::class);
    Event::assertNotDispatched(AccountNeedsReconnect::class);
})->with([
    'page 1: HTML 403 from a WAF' => [0, fn () => Http::response('<html><body>Access denied</body></html>', 403, ['Content-Type' => 'text/html'])],
    'page 2: HTML 400 from a proxy' => [2, fn () => Http::response('<html><body>Bad Request</body></html>', 400, ['Content-Type' => 'text/html'])],
]);

it('follows LinkedIn to the last page when it returns fewer organizations than requested', function () {
    $ids = array_map(fn (int $i) => (string) (1000 + $i), range(0, 24));
    $elements = array_map(fn (string $id) => incompleteListsAcl($id), $ids);

    // First connect: LinkedIn honours count=100, all 25 organizations fit one page.
    $this->provider = incompleteListsAcls($elements);
    incompleteListsConnectLinkedIn();

    // Reconnect: LinkedIn now serves 10 per page (paging.count = 10, rel=next
    // links on pages 1 and 2, no paging.total) and an empty page past the end.
    $this->provider = incompleteListsAcls($elements, cap: 10);
    $accounts = incompleteListsConnectLinkedIn();

    expect(incompleteListsStatuses('linkedin'))->toBe(incompleteListsAllActive($ids));
    Event::assertNotDispatched(AccountNeedsReconnect::class);

    expect($accounts->pluck('provider_user_id')->sort()->values()->all())->toBe($ids);
});

it('keeps an organization whose decoration LinkedIn throttled, resolving its id from the URN', function () {
    $this->provider = incompleteListsAcls([incompleteListsAcl('111'), incompleteListsAcl('222')]);
    incompleteListsConnectLinkedIn();

    $this->provider = incompleteListsAcls([incompleteListsAcl('111'), incompleteListsThrottledAcl('222')]);
    $accounts = incompleteListsConnectLinkedIn();

    expect(incompleteListsStatuses('linkedin'))->toBe(incompleteListsAllActive(['111', '222']));
    Event::assertNotDispatched(AccountNeedsReconnect::class);

    $throttled = SocialAccount::where('provider', 'linkedin')->where('provider_user_id', '222')->sole();

    expect($accounts->pluck('provider_user_id')->sort()->values()->all())->toBe(['111', '222'])
        ->and($throttled->last_error)->toBeNull()
        ->and($throttled->profile['organization_urn'])->toBe('urn:li:organization:222')
        // A missing decoration is not a new name: what the last listing stored stays.
        ->and($throttled->name)->toBe('Org 222');
});

it('flags no organization when LinkedIn throttled every decoration', function () {
    $this->provider = incompleteListsAcls([incompleteListsAcl('111'), incompleteListsAcl('222')]);
    incompleteListsConnectLinkedIn();

    $this->provider = incompleteListsAcls([incompleteListsThrottledAcl('111'), incompleteListsThrottledAcl('222')]);
    $accounts = incompleteListsConnectLinkedIn();

    expect(incompleteListsStatuses('linkedin'))->toBe(incompleteListsAllActive(['111', '222']));
    Event::assertNotDispatched(AccountNeedsReconnect::class);

    expect($accounts->pluck('provider_user_id')->sort()->values()->all())->toBe(['111', '222'])
        ->and(SocialAccount::where('provider', 'linkedin')->orderBy('provider_user_id')->pluck('name')->all())
        ->toBe(['Org 111', 'Org 222']);
});

it('flags nothing when an approved grant names no organization it can resolve', function () {
    $this->provider = incompleteListsAcls([incompleteListsAcl('111'), incompleteListsAcl('222')]);
    incompleteListsConnectLinkedIn();

    // An APPROVED grant with neither a decoration nor an organization URN: it
    // may well be 222, so 222 must not be declared "no longer administered".
    // Aborting the store instead is acceptable too; flagging 222 is not.
    $unresolved = incompleteListsThrottledAcl('222');
    unset($unresolved['organization']);

    $this->provider = incompleteListsAcls([incompleteListsAcl('111'), $unresolved]);
    incompleteListsAttempt(fn () => incompleteListsConnectLinkedIn());

    expect(incompleteListsStatuses('linkedin'))->toBe(incompleteListsAllActive(['111', '222']));
    Event::assertNotDispatched(AccountNeedsReconnect::class);
});

// Guard (already passes on v1.1.0): reconciliation still runs on a complete,
// fully resolved listing.
it('still flags an organization missing from a complete, fully decorated listing', function () {
    $this->provider = incompleteListsAcls([incompleteListsAcl('111'), incompleteListsAcl('222')]);
    incompleteListsConnectLinkedIn();

    $this->provider = incompleteListsAcls([incompleteListsAcl('111')]);
    incompleteListsConnectLinkedIn();

    expect(incompleteListsStatuses('linkedin'))->toBe(['111' => 'active', '222' => 'needs_reconnect']);
    Event::assertDispatchedTimes(AccountNeedsReconnect::class, 1);
});

// Guard (already passes on v1.1.0): a well-formed empty list is a real answer
// on LinkedIn too; the fix must not refuse it.
it('still flags every organization when a well-formed listing says the member administers none', function () {
    $this->provider = incompleteListsAcls([incompleteListsAcl('111'), incompleteListsAcl('222')]);
    incompleteListsConnectLinkedIn();

    $this->provider = incompleteListsAcls([]);
    $accounts = incompleteListsConnectLinkedIn();

    expect($accounts)->toBeEmpty()
        ->and(incompleteListsStatuses('linkedin'))->toBe(['111' => 'needs_reconnect', '222' => 'needs_reconnect']);
    Event::assertDispatchedTimes(AccountNeedsReconnect::class, 2);
});

// Guard (already passes on v1.1.0): a grant that is no longer APPROVED is
// resolved and dropped on purpose; it must not count as "unresolved".
it('still flags an organization whose grant is no longer approved', function () {
    $this->provider = incompleteListsAcls([incompleteListsAcl('111'), incompleteListsAcl('222')]);
    incompleteListsConnectLinkedIn();

    $this->provider = incompleteListsAcls([incompleteListsAcl('111'), incompleteListsAcl('222', 'REVOKED')]);
    incompleteListsConnectLinkedIn();

    expect(incompleteListsStatuses('linkedin'))->toBe(['111' => 'active', '222' => 'needs_reconnect']);
    Event::assertDispatchedTimes(AccountNeedsReconnect::class, 1);
});
