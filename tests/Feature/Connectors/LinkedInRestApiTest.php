<?php

use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Connectors\LinkedInConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;

/*
 * LinkedIn's versioned API (/rest), which replaced the unversioned /v2 the
 * organizations were listed from: a LinkedIn-Version on every call, which
 * LinkedIn supports for at least a year and then refuses with HTTP 426.
 */

function linkedInRestConnector(): LinkedInConnector
{
    return app(ConnectorRegistry::class)->for('linkedin');
}

function linkedInRestCredential(): SocialToken
{
    return SocialToken::create(['provider' => 'linkedin', 'provider_holder_id' => 'member-1', 'access_token' => 'member-token', 'status' => AccountStatus::Active]);
}

/** One approved ADMINISTRATOR grant, decorated the way linkedInRest() reads it. */
function linkedInRestGrant(int $id, ?string $logo = null): array
{
    $organization = ['id' => $id, 'localizedName' => "Org {$id}"];

    if ($logo !== null) {
        $organization['logoV2'] = ['original~' => ['elements' => [['identifiers' => [['identifier' => $logo]]]]]];
    }

    return ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization~' => $organization];
}

/** The LinkedIn-Version of every request sent. */
function linkedInRestVersionsSent(): array
{
    return collect(Http::recorded())->map(fn (array $sent) => $sent[0]->header('LinkedIn-Version')[0] ?? null)->unique()->values()->all();
}

it('sends the configured LinkedIn-Version, or the default one', function (?string $configured, string $sent) {
    config(['social-tokens.connectors.linkedin.api_version' => $configured]);
    Http::fake(linkedInRest(Http::response(['elements' => [linkedInRestGrant(1)]])));

    linkedInRestConnector()->fetchOrganizations('member-token');

    expect(linkedInRestVersionsSent())->toBe([$sent]);
})->with([
    'configured' => ['202611', '202611'],
    'with a patch suffix' => ['202611.01', '202611.01'],
    'missing' => [null, LinkedInConnector::DEFAULT_API_VERSION],
    'empty' => ['', LinkedInConnector::DEFAULT_API_VERSION],
    'not a month' => ['202613', LinkedInConnector::DEFAULT_API_VERSION],
    'malformed' => ['2026-11', LinkedInConnector::DEFAULT_API_VERSION],
]);

/*
 * Each release moves the default forward; this fails once it is old enough
 * for apps on the default to get the daily warning, as a reminder to move it
 * (and the README) to a recent LinkedIn-Version before LinkedIn sunsets it.
 */
it('ships a default LinkedIn-Version that is not yet due for a warning', function () {
    $released = Carbon::createMidnightDate((int) substr(LinkedInConnector::DEFAULT_API_VERSION, 0, 4), (int) substr(LinkedInConnector::DEFAULT_API_VERSION, 4, 2), 1);

    expect($released->addMonths(11)->isFuture())->toBeTrue(
        'LinkedInConnector::DEFAULT_API_VERSION ('.LinkedInConnector::DEFAULT_API_VERSION.') is 11 months old: move it, and the README, to a recent LinkedIn-Version.'
    );
});

it('names the version LinkedIn no longer serves, and says so as critical once a day', function () {
    Log::spy();
    config(['social-tokens.connectors.linkedin.api_version' => '202401']);
    Http::fake(['api.linkedin.com/rest/*' => Http::response(['status' => 426, 'code' => 'NONEXISTENT_VERSION', 'message' => 'Requested version 20240101 is not active'], 426)]);

    $listed = linkedInRestConnector()->fetchOrganizations('member-token');
    $reachable = linkedInRestConnector()->reachableAccountIds(linkedInRestCredential());

    expect($listed)->toBeInstanceOf(RenewalResult::class)
        ->and($listed->reason)->toContain('202401')->toContain('LINKEDIN_API_VERSION')
        ->and($reachable)->toBeInstanceOf(RenewalResult::class)
        ->and($reachable->reason)->toBe($listed->reason);

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, '202401') && str_contains($message, 'HTTP 426'))->once();

    $this->travel(1)->days();
    linkedInRestConnector()->fetchOrganizations('member-token');

    Log::shouldHaveReceived('critical')->twice();
});

it('warns once a day while the version is over 11 months old', function () {
    Log::spy();
    config(['social-tokens.connectors.linkedin.api_version' => now()->subMonths(12)->format('Ym')]);
    Http::fake(linkedInRest(Http::response(['elements' => [linkedInRestGrant(1)]])));

    linkedInRestConnector()->fetchOrganizations('member-token');
    linkedInRestConnector()->fetchOrganizations('member-token');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'over 11 months old')
        && str_contains($message, 'LINKEDIN_API_VERSION'))->once();

    $this->travel(1)->days();
    linkedInRestConnector()->fetchOrganizations('member-token');

    Log::shouldHaveReceived('warning')->twice();
});

it('does not warn about a version younger than 11 months', function () {
    Log::spy();
    config(['social-tokens.connectors.linkedin.api_version' => now()->subMonths(10)->format('Ym')]);
    Http::fake(linkedInRest(Http::response(['elements' => [linkedInRestGrant(1)]])));

    linkedInRestConnector()->fetchOrganizations('member-token');

    Log::shouldNotHaveReceived('warning');
});

/*
 * LinkedIn orders the grants differently from one call to the next, and
 * pages with start/count: a later page can serve a grant an earlier page
 * served, and skip another in its place. The skipped organization would read
 * as no longer administered.
 */
it('refuses a listing whose pages serve a grant twice', function () {
    Http::fake(linkedInRest(fn (Request $request) => Http::response((int) $request['start'] === 0
        ? ['paging' => ['start' => 0, 'count' => 2, 'links' => [['rel' => 'next']]], 'elements' => [linkedInRestGrant(1), linkedInRestGrant(2)]]
        : ['paging' => ['start' => 2, 'count' => 2, 'links' => []], 'elements' => [linkedInRestGrant(2)]])));

    $grants = linkedInRestConnector()->fetchOrganizationGrants('member-token');

    expect($grants)->toBeInstanceOf(RenewalResult::class)
        ->and($grants->reason)->toContain('twice');
});

it('lists the grants alone for the reconciliation, without their names', function () {
    Http::fake(linkedInRest(Http::response(['elements' => [linkedInRestGrant(1, 'https://media.licdn.com/1.png'), linkedInRestGrant(2)]])));

    expect(linkedInRestConnector()->reachableAccountIds(linkedInRestCredential()))->toBe(['1', '2']);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/rest/organizations?') || str_contains($request->url(), '/rest/images'));
});

it('reads names and logos 50 organizations at a time', function () {
    $grants = array_map(fn (int $id) => linkedInRestGrant($id, "https://media.licdn.com/{$id}.png"), range(1, 51));
    Http::fake(linkedInRest(Http::response(['elements' => $grants])));

    $organizations = linkedInRestConnector()->fetchOrganizations('member-token');

    expect($organizations)->toHaveCount(51)
        ->and($organizations[50])->toMatchArray(['id' => '51', 'name' => 'Org 51', 'logo' => 'https://media.licdn.com/51.png']);

    $batches = fn (string $path) => collect(Http::recorded())
        ->map(fn (array $sent) => $sent[0]->url())
        ->filter(fn (string $url) => str_contains($url, $path))
        ->map(fn (string $url) => count(explode(',', urldecode($url))))
        ->values()->all();

    expect($batches('/rest/organizations?'))->toBe([50, 1])
        ->and($batches('/rest/images?'))->toBe([50, 1]);
});

it('keeps the stored logo when LinkedIn gives no URL for it, and clears one the organization removed', function () {
    $phase = 1;
    Http::fake(['api.linkedin.com/rest/images*' => function () use (&$phase) {
        return $phase === 2 ? Http::response(['message' => 'Internal error'], 500) : null;
    }]);
    Http::fake(linkedInRest(function () use (&$phase) {
        return Http::response(['elements' => $phase === 1
            ? [linkedInRestGrant(1, 'https://media.licdn.com/1.png'), linkedInRestGrant(2, 'https://media.licdn.com/2.png')]
            // /rest/images now fails, and organization 2 no longer has a logo
            : [linkedInRestGrant(1, 'https://media.licdn.com/1-new.png'), linkedInRestGrant(2)]]);
    }));

    $store = app(StoreLinkedInOrganizations::class);
    $store->handle(accessToken: 'member-token', memberId: 'member-1');
    $phase = 2;
    $store->handle(accessToken: 'member-token', memberId: 'member-1');

    $accounts = SocialAccount::where('provider', 'linkedin')->orderBy('provider_user_id')->get();

    expect($accounts->pluck('avatar', 'provider_user_id')->all())->toBe([1 => 'https://media.licdn.com/1.png', 2 => null])
        ->and($accounts->every(fn (SocialAccount $account) => $account->status === AccountStatus::Active))->toBeTrue();
});
